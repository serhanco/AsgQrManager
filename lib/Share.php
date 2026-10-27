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
