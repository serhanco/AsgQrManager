<?php
/**
 * Share — imzalı, süreli indirme bağlantıları.
 *
 * Ajans / matbaa / ofis çalışanı panele girmeden QR dosyalarını indirebilir.
 * Bağlantı HMAC ile imzalıdır, süresi dolunca çalışmaz. Ayarlar sayfasındaki
 * "tüm paylaşım bağlantılarını iptal et" gizli anahtarı yeniler ve hepsini geçersiz kılar.
 */
class Share {

    public static function sign(string $slug, int $expires): string {
        return hash_hmac('sha256', $slug . '|' . $expires, Settings::shareSecret());
    }

    public static function url(string $slug, int $ttlDays): string {
        $exp = time() + max(1, $ttlDays) * 86400;
        return BASE_URL . '/share?s=' . rawurlencode($slug) . '&e=' . $exp . '&sig=' . self::sign($slug, $exp);
    }

    public static function verify(string $slug, int $expires, string $sig): bool {
        if ($expires < time()) return false;
        return hash_equals(self::sign($slug, $expires), $sig);
    }

    // ─── Süresiz (sabit) paylaşım bağlantısı ─────────────────────────────────
    // Yalnızca seçilen QR'larda açılır. QR'a özel rastgele anahtar (links.share_key) + gizli anahtarla imzalanır:
    // QR'da iptal edilince (anahtar silinir) ya da Ayarlar'dan "tümünü iptal et" denince (gizli anahtar yenilenir) çalışmaz.

    /** Şema hazır mı (015 migration) */
    public static function permanentAvailable(PDO $pdo): bool {
        static $ok = null;
        if ($ok === null) {
            try { $pdo->query('SELECT share_key FROM links LIMIT 0'); $ok = true; } catch (\Throwable $e) { $ok = false; }
        }
        return $ok;
    }

    private static function signPermanent(string $slug, string $key): string {
        return hash_hmac('sha256', $slug . '|perm|' . $key, Settings::shareSecret());
    }

    /** QR'ın sabit paylaşım bağlantısı (açık değilse null) */
    public static function permanentUrl(array $link): ?string {
        $key = (string)($link['share_key'] ?? '');
        if ($key === '') return null;
        return BASE_URL . '/share?s=' . rawurlencode($link['slug']) . '&p=' . self::signPermanent($link['slug'], $key);
    }

    public static function verifyPermanent(array $link, string $sig): bool {
        $key = (string)($link['share_key'] ?? '');
        return $key !== '' && $sig !== '' && hash_equals(self::signPermanent($link['slug'], $key), $sig);
    }

    /** Sabit bağlantıyı açar (zaten açıksa aynı bağlantı kalır). @return bool yeni açıldıysa true */
    public static function enablePermanent(PDO $pdo, array $link, array $actor): bool {
        if (!empty($link['share_key'])) return false;
        $pdo->prepare('UPDATE links SET share_key = ? WHERE id = ? AND share_key IS NULL')->execute([bin2hex(random_bytes(16)), (int)$link['id']]);
        LinkEvents::log($pdo, (int)$link['id'], $link['slug'], $actor, 'share_perm');
        return true;
    }

    /** Sabit bağlantıyı iptal eder (daha önce verilen bağlantı çalışmaz). @return bool kapandıysa true */
    public static function disablePermanent(PDO $pdo, array $link, array $actor): bool {
        if (empty($link['share_key'])) return false;
        $pdo->prepare('UPDATE links SET share_key = NULL WHERE id = ?')->execute([(int)$link['id']]);
        LinkEvents::log($pdo, (int)$link['id'], $link['slug'], $actor, 'share_perm_off');
        return true;
    }

    /** Dosya adı için güvenli kısa ad */
    public static function fileBase(array $link): string {
        $t = trim((string)($link['title'] ?? ''));
        $t = preg_replace('/[^A-Za-z0-9_\-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t) ?: '');
        $t = trim($t, '-');
        return 'qr-' . $link['slug'] . ($t !== '' ? '-' . substr($t, 0, 40) : '');
    }

    /** İzin verilen çıktı seçenekleri (qr-view ve share aynı doğrulamayı kullanır) */
    public const PNG_SIZES = [600, 1200, 2000, 3000];
    public const PDF_MM    = [30, 50, 100, 200];   // PDF / EPS / AI çizim alanı
    /** Milimetre ölçülü vektör formatlar */
    public const VECTOR_MM_FORMATS = ['pdf', 'eps', 'ai'];
    /** Tüm uç noktaların kabul ettiği formatlar */
    public const FORMATS = ['svg', 'png', 'pdf', 'eps', 'ai'];

    /** QR'ın bağlı olduğu şablon (yoksa ya da okunamazsa null) */
    public static function templateOf(array $link): ?array {
        if (empty($link['template_id'])) return null;
        try { return QrTemplates::find(getPdo(), (int)$link['template_id']); } catch (\Throwable $e) { return null; }
    }

    /** Standart baskı ölçülerinin tipik kullanım alanı (buton altındaki kısa ipucu) */
    public const MM_HINTS = [
        30  => 'Kartvizit, yaka kartı, sticker',
        50  => 'Broşür, masa kartı',
        100 => 'Afiş, poster',
        200 => 'Roll-up, büyük pano',
    ];

    /** Bir ölçünün ipucu: QR'ın şablonunun ölçüsüyse şablon adı, değilse standart kullanım alanı */
    public static function mmHint(int $mm, ?array $tpl = null): string {
        if ($tpl && (int)($tpl['print_mm'] ?? 0) === $mm && !empty($tpl['name'])) return (string)$tpl['name'] . ' şablonu';
        return self::MM_HINTS[$mm] ?? '';
    }

    /** Bu QR için sunulan vektörel ölçüler (mm): standart ölçüler + şablonun önerdiği baskı boyutu */
    public static function mmOptions(array $link): array {
        $mm = self::PDF_MM;
        $tpl = self::templateOf($link);
        if ($tpl && !empty($tpl['print_mm'])) $mm[] = (int)$tpl['print_mm'];
        $mm = array_values(array_unique($mm));
        sort($mm);
        return $mm;
    }

    /**
     * İstenen formatı üretir.
     * @return array{data:string,mime:string,ext:string,name:string}
     */
    public static function render(array $link, string $fmt, int $size = 0, bool $transparent = false, ?string $logoOverride = null): array {
        $logo = $logoOverride !== null ? $logoOverride : LinkService::effectiveLogo($link);
        if ($logo !== '' && !validateLogoFile($logo)) $logo = '';
        $ecc   = $logo !== '' ? 'H' : 'M';
        $style = LinkService::effectiveStyle($link);
        $style['transparent'] = $transparent;

        if ($fmt === 'png') {
            if (!in_array($size, self::PNG_SIZES, true)) $size = 1200;
        } elseif (in_array($fmt, self::VECTOR_MM_FORMATS, true)) {
            if (!in_array($size, self::mmOptions($link), true)) $size = 100;
        } else {
            $fmt = 'svg';
            $size = 0;
        }

        $r = QrRenderer::getOrGenerate(BASE_URL . '/r/' . $link['slug'], $link['slug'], $fmt, $ecc, $size, 4, $logo ?: null, $style);
        $suffix = ($fmt === 'png' ? '-' . $size . 'px' : ($size > 0 && $fmt !== 'png' ? '-' . $size . 'mm' : ''))
                . ($transparent ? '-seffaf' : '');
        return ['data' => $r['data'], 'mime' => $r['mime'], 'ext' => $fmt, 'name' => self::fileBase($link) . $suffix . '.' . $fmt];
    }
}
