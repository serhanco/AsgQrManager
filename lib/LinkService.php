<?php
/**
 * LinkService — link doğrulama, oluşturma, güncelleme ve arşivleme.
 * Yönetim paneli, toplu yükleme ve REST API aynı kuralları kullanır.
 *
 * Silme politikası: QR kayıtları fiziksel olarak SİLİNMEZ, arşivlenir.
 * Kalıcı silme yalnızca hiç okutulmamış ve arşivlenmiş kayıtlar için mümkündür.
 */
class LinkService {

    public const CATEGORIES = [
        'hq'       => 'Genel Merkez',
        'office'   => 'Ofis',
        'event'    => 'Etkinlik',
        'campaign' => 'Kampanya',
        'other'    => 'Diğer',
    ];

    public const SHAPES = [
        'square'  => 'Kare (klasik)',
        'rounded' => 'Yuvarlatılmış kare',
        'dots'    => 'Nokta',
    ];

    /** Güncellenebilir sütunlar (yalnızca bu liste SQL'e girer) */
    private const COLUMNS = [
        'target_url', 'title', 'is_active', 'logo', 'category', 'tags', 'notes',
        'fallback_url', 'starts_at', 'ends_at', 'after_end_url',
        'utm_enabled', 'utm_campaign', 'fg_color', 'bg_color', 'shape',
    ];

    // ─── Renk yardımcıları ───────────────────────────────────────────────────

    /** '#abc' / 'aabbcc' / '#AABBCC' → '#AABBCC' ; geçersizse null */
    public static function normalizeHex(?string $c): ?string {
        $c = strtoupper(trim((string)$c));
        $c = ltrim($c, '#');
        if (preg_match('/^[0-9A-F]{3}$/', $c)) {
            $c = $c[0].$c[0].$c[1].$c[1].$c[2].$c[2];
        }
        return preg_match('/^[0-9A-F]{6}$/', $c) ? '#' . $c : null;
    }

    private static function luminance(string $hex): float {
        $hex = ltrim($hex, '#');
        $ch = [];
        foreach ([0, 2, 4] as $i) {
            $v = hexdec(substr($hex, $i, 2)) / 255;
            $ch[] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $ch[0] + 0.7152 * $ch[1] + 0.0722 * $ch[2];
    }

    public static function contrastRatio(string $a, string $b): float {
        $l1 = self::luminance($a);
        $l2 = self::luminance($b);
        [$hi, $lo] = $l1 >= $l2 ? [$l1, $l2] : [$l2, $l1];
        return ($hi + 0.05) / ($lo + 0.05);
    }

    /** QR okunabilirliği için renk çiftini doğrular. Hata metni ya da null döner. */
    public static function colorError(string $fg, string $bg): ?string {
        if (self::luminance($fg) >= self::luminance($bg)) {
            return 'QR rengi (ön plan) arka plandan daha koyu olmalı; ters (açık üzerine koyu değil) renkler bazı okuyucularda taranmaz.';
        }
        $ratio = self::contrastRatio($fg, $bg);
        if ($ratio < 4.0) {
            return sprintf('Renk kontrastı çok düşük (%.1f:1). Okunabilirlik için en az 4:1 gerekir.', $ratio);
        }
        return null;
    }

    /** Link satırı + marka varsayılanlarından etkin QR stilini çıkarır. */
    public static function effectiveStyle(array $link): array {
        $fg = self::normalizeHex($link['fg_color'] ?? null) ?? self::normalizeHex(Settings::get('brand_fg')) ?? '#000000';
        $bg = self::normalizeHex($link['bg_color'] ?? null) ?? self::normalizeHex(Settings::get('brand_bg')) ?? '#FFFFFF';
        $shape = $link['shape'] ?? '';
        if (!isset(self::SHAPES[$shape])) {
            $shape = Settings::get('brand_shape');
            if (!isset(self::SHAPES[$shape])) $shape = 'square';
        }
        return ['fg' => $fg, 'bg' => $bg, 'shape' => $shape];
    }

    /** Link logosu yoksa marka varsayılan logosu kullanılmaz (açıkça seçilmediyse logo yok). */
    public static function effectiveLogo(array $link): string {
        $l = (string)($link['logo'] ?? '');
        return ($l !== '' && validateLogoFile($l)) ? $l : '';
    }

    // ─── Etiket / tarih / UTM ────────────────────────────────────────────────

    /** "Londra, Kongre 2027;  londra" → "Londra,Kongre 2027" (en fazla 10 etiket) */
    public static function normalizeTags(?string $raw): ?string {
        $out = [];
        foreach (preg_split('/[,;\n]+/', (string)$raw) as $t) {
            $t = trim(preg_replace('/\s+/u', ' ', $t));
            $t = preg_replace('/[^\p{L}\p{N} _\-.]/u', '', $t);
            if ($t === '' || mb_strlen($t) > 30) continue;
            $out[mb_strtolower($t)] ??= $t;
            if (count($out) >= 10) break;
        }
        return $out ? implode(',', array_values($out)) : null;
    }

    /** datetime-local ("2026-11-05T18:30") veya "2026-11-05 18:30" → "Y-m-d H:i:s"; boş → null; geçersiz → false */
    public static function parseLocalDateTime(?string $in): string|null|false {
        $in = trim((string)$in);
        if ($in === '') return null;
        $in = str_replace('T', ' ', $in);
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $fmt) {
            $d = DateTime::createFromFormat('!' . $fmt, $in);
            if ($d && $d->format($fmt) === $in) return $d->format('Y-m-d H:i:s');
        }
        return false;
    }

    /** Hedef adrese UTM parametrelerini ekler (hedefte zaten varsa dokunmaz). */
    public static function applyUtm(string $url, array $link): string {
        if (empty($link['utm_enabled'])) return $url;
        $params = [
            'utm_source'   => Settings::get('utm_source') ?: 'qr',
            'utm_medium'   => Settings::get('utm_medium') ?: 'print',
            'utm_campaign' => ($link['utm_campaign'] ?? '') !== '' && $link['utm_campaign'] !== null
                                ? $link['utm_campaign'] : ($link['slug'] ?? ''),
        ];
        $fragment = '';
        if (($pos = strpos($url, '#')) !== false) {
            $fragment = substr($url, $pos);
            $url = substr($url, 0, $pos);
        }
        $query = parse_url($url, PHP_URL_QUERY) ?? '';
        parse_str($query, $existing);
        $add = [];
        foreach ($params as $k => $v) {
            if ($v !== '' && !array_key_exists($k, $existing)) $add[$k] = $v;
        }
        if (!$add) return $url . $fragment;
        $sep = ($query === '' && !str_contains($url, '?')) ? '?' : (str_ends_with($url, '?') || str_ends_with($url, '&') ? '' : '&');
        return $url . $sep . http_build_query($add) . $fragment;
    }

    // ─── Form → girdi ────────────────────────────────────────────────────────

    /**
     * Yönetim paneli formunu (POST) normalize() girdisine çevirir.
     * Onay kutuları işaretli değilse gönderilmez; bu yüzden burada açıkça 0/1 yapılır.
     */
    public static function fromForm(array $post, bool $isEdit): array {
        $in = [
            'target_url'    => $post['target_url']    ?? '',
            'title'         => $post['title']         ?? '',
            'category'      => $post['category']      ?? '',
            'tags'          => $post['tags']          ?? '',
            'notes'         => $post['notes']         ?? '',
            'fallback_url'  => $post['fallback_url']  ?? '',
            'after_end_url' => $post['after_end_url'] ?? '',
            'starts_at'     => $post['starts_at']     ?? '',
            'ends_at'       => $post['ends_at']       ?? '',
            'utm_enabled'   => !empty($post['utm_enabled']) ? 1 : 0,
            'utm_campaign'  => $post['utm_campaign']  ?? '',
            'logo'          => !empty($post['use_logo']) ? trim((string)($post['logo_file'] ?? '')) : '',
        ];
        if ($isEdit) $in['is_active'] = !empty($post['is_active']) ? 1 : 0;

        if (($post['style_mode'] ?? 'brand') === 'custom') {
            $in['fg_color'] = $post['fg_color'] ?? '';
            $in['bg_color'] = $post['bg_color'] ?? '';
            $in['shape']    = $post['shape']    ?? '';
        } else { // marka varsayılanı
            $in['fg_color'] = $in['bg_color'] = $in['shape'] = '';
        }
        return $in;
    }

    // ─── Doğrulama ───────────────────────────────────────────────────────────

    /**
     * Girdiyi doğrular ve temizler.
     * $existing verilmişse yalnızca girdide bulunan anahtarlar güncellenir (kısmi güncelleme).
     *
     * @return array{0:array,1:string[]} [temiz alanlar, hata mesajları]
     */
    public static function normalize(array $in, ?array $existing = null): array {
        $clean  = [];
        $errors = [];
        $has    = fn(string $k) => array_key_exists($k, $in);
        $merged = fn(string $k) => $has($k) ? $in[$k] : ($existing[$k] ?? null);

        // Hedef URL
        if ($has('target_url') || !$existing) {
            $u = trim((string)($in['target_url'] ?? ''));
            if (!isValidUrl($u)) $errors[] = 'Geçerli bir hedef URL girin (http:// veya https:// ile başlamalı).';
            else $clean['target_url'] = $u;
        }

        if ($has('title')) {
            $t = trim((string)$in['title']);
            if (mb_strlen($t) > 255) $errors[] = 'Başlık en fazla 255 karakter olabilir.';
            $clean['title'] = $t === '' ? null : $t;
        }

        if ($has('is_active')) $clean['is_active'] = filter_var($in['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

        if ($has('logo')) {
            $l = trim((string)$in['logo']);
            if ($l !== '' && !validateLogoFile($l)) $errors[] = 'Seçilen logo geçersiz.';
            else $clean['logo'] = $l === '' ? null : $l;
        }

        if ($has('category')) {
            $c = trim((string)$in['category']);
            if ($c !== '' && !isset(self::CATEGORIES[$c])) $errors[] = 'Geçersiz kategori.';
            else $clean['category'] = $c === '' ? null : $c;
        }

        if ($has('tags'))  $clean['tags']  = self::normalizeTags(is_array($in['tags']) ? implode(',', $in['tags']) : (string)$in['tags']);
        if ($has('notes')) {
            $n = trim((string)$in['notes']);
            $clean['notes'] = $n === '' ? null : mb_substr($n, 0, 2000);
        }

        foreach (['fallback_url' => 'Yedek hedef', 'after_end_url' => 'Bitiş sonrası hedef'] as $k => $label) {
            if (!$has($k)) continue;
            $u = trim((string)$in[$k]);
            if ($u !== '' && !isValidUrl($u)) $errors[] = "{$label} geçerli bir http(s) adresi olmalı.";
            else $clean[$k] = $u === '' ? null : $u;
        }

        foreach (['starts_at' => 'Başlangıç', 'ends_at' => 'Bitiş'] as $k => $label) {
            if (!$has($k)) continue;
            $d = self::parseLocalDateTime($in[$k]);
            if ($d === false) $errors[] = "{$label} tarihi geçersiz.";
            else $clean[$k] = $d;
        }
        $s = array_key_exists('starts_at', $clean) ? $clean['starts_at'] : ($existing['starts_at'] ?? null);
        $e = array_key_exists('ends_at', $clean)   ? $clean['ends_at']   : ($existing['ends_at'] ?? null);
        if ($s && $e && $e <= $s) $errors[] = 'Bitiş tarihi başlangıçtan sonra olmalı.';

        if ($has('utm_enabled')) $clean['utm_enabled'] = filter_var($in['utm_enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        if ($has('utm_campaign')) {
            $c = trim((string)$in['utm_campaign']);
            if ($c !== '' && !preg_match('/^[A-Za-z0-9_\-.]{1,100}$/', $c)) {
                $errors[] = 'UTM kampanya adı yalnızca harf, rakam, - _ . içerebilir.';
            } else {
                $clean['utm_campaign'] = $c === '' ? null : $c;
            }
        }

        // Renkler (ikisi birlikte denetlenir)
        foreach (['fg_color' => 'QR rengi', 'bg_color' => 'Arka plan rengi'] as $k => $label) {
            if (!$has($k)) continue;
            $raw = trim((string)$in[$k]);
            if ($raw === '') { $clean[$k] = null; continue; }
            $hex = self::normalizeHex($raw);
            if ($hex === null) $errors[] = "{$label} geçersiz (örn. #1B3A6B).";
            else $clean[$k] = $hex;
        }
        if ($has('fg_color') || $has('bg_color')) {
            $fg = array_key_exists('fg_color', $clean) ? $clean['fg_color'] : ($existing['fg_color'] ?? null);
            $bg = array_key_exists('bg_color', $clean) ? $clean['bg_color'] : ($existing['bg_color'] ?? null);
            $fgEff = self::normalizeHex($fg) ?? self::normalizeHex(Settings::get('brand_fg')) ?? '#000000';
            $bgEff = self::normalizeHex($bg) ?? self::normalizeHex(Settings::get('brand_bg')) ?? '#FFFFFF';
            if (($err = self::colorError($fgEff, $bgEff)) !== null) $errors[] = $err;
        }

        if ($has('shape')) {
            $sh = trim((string)$in['shape']);
            if ($sh !== '' && !isset(self::SHAPES[$sh])) $errors[] = 'Geçersiz QR şekli.';
            else $clean['shape'] = $sh === '' ? null : $sh;
        }

        return [$clean, $errors];
    }

    // ─── Yazma işlemleri ─────────────────────────────────────────────────────

    /**
     * @param array{id:?int,name:string} $actor
     * @throws RuntimeException slug çakışması vb.
     */
    public static function create(PDO $pdo, array $clean, ?string $customSlug, array $actor): array {
        if ($customSlug !== null && $customSlug !== '') {
            $customSlug = sanitizeCustomSlug($customSlug);
            if (strlen($customSlug) < 3 || strlen($customSlug) > 16) {
                throw new RuntimeException('Özel slug 3–16 karakter arasında olmalı.');
            }
            $chk = $pdo->prepare('SELECT id FROM links WHERE slug = ? LIMIT 1');
            $chk->execute([$customSlug]);
            if ($chk->fetch()) throw new RuntimeException("'{$customSlug}' slugı zaten kullanılıyor.");
            $slug = $customSlug;
        } else {
            $slug = generateUniqueSlug($pdo, defined('SLUG_LENGTH') ? SLUG_LENGTH : 7);
        }

        $clean += ['is_active' => 1];
        $cols = array_keys($clean);
        $sql  = 'INSERT INTO links (slug, ' . implode(',', array_map(fn($c) => "`$c`", $cols)) . ') VALUES (?' . str_repeat(',?', count($cols)) . ')';
        $pdo->prepare($sql)->execute(array_merge([$slug], array_values($clean)));
        $id = (int)$pdo->lastInsertId();

        LinkEvents::log($pdo, $id, $slug, $actor, 'create', ['target_url' => $clean['target_url'] ?? null, 'title' => $clean['title'] ?? null]);
        Logger::info("Yeni link oluşturuldu: slug={$slug} by={$actor['name']}");
        return self::find($pdo, $id);
    }

    /** @return array değişen alanlar [alan => [eski, yeni]] */
    public static function update(PDO $pdo, array $link, array $clean, array $actor, string $action = 'update'): array {
        $changes = [];
        foreach ($clean as $col => $val) {
            if (!in_array($col, self::COLUMNS, true)) continue;
            if ((string)($link[$col] ?? '') !== (string)($val ?? '')) $changes[$col] = [$link[$col] ?? null, $val];
        }
        if (!$changes) return [];

        $sets = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($changes)));
        $vals = array_map(fn($c) => $c[1], array_values($changes));
        $pdo->prepare("UPDATE links SET {$sets}, updated_at = NOW() WHERE id = ?")->execute(array_merge($vals, [(int)$link['id']]));

        QrRenderer::clearCache($link['slug']);
        LinkEvents::log($pdo, (int)$link['id'], $link['slug'], $actor, $action, $changes);
        return $changes;
    }

    public static function archive(PDO $pdo, array $link, array $actor): void {
        $pdo->prepare('UPDATE links SET archived_at = NOW(), updated_at = NOW() WHERE id = ? AND archived_at IS NULL')
            ->execute([(int)$link['id']]);
        LinkEvents::log($pdo, (int)$link['id'], $link['slug'], $actor, 'archive');
    }

    public static function restore(PDO $pdo, array $link, array $actor): void {
        $pdo->prepare('UPDATE links SET archived_at = NULL, updated_at = NOW() WHERE id = ?')->execute([(int)$link['id']]);
        LinkEvents::log($pdo, (int)$link['id'], $link['slug'], $actor, 'restore');
    }

    /** Yalnızca arşivlenmiş ve hiç okutulmamış kayıt kalıcı silinebilir. */
    public static function purge(PDO $pdo, array $link, array $actor): bool {
        if (empty($link['archived_at'])) return false;
        $n = $pdo->prepare('SELECT COUNT(*) FROM scans WHERE link_id = ?');
        $n->execute([(int)$link['id']]);
        if ((int)$n->fetchColumn() > 0 || (int)$link['scan_count'] > 0) return false;
        LinkEvents::log($pdo, (int)$link['id'], $link['slug'], $actor, 'purge', ['target_url' => $link['target_url']]);
        $pdo->prepare('DELETE FROM links WHERE id = ?')->execute([(int)$link['id']]);
        QrRenderer::clearCache($link['slug']);
        return true;
    }

    // ─── Listeleme filtresi ──────────────────────────────────────────────────

    public const STATUSES = ['' => 'Tümü (arşiv hariç)', 'live' => 'Aktif', 'inactive' => 'Pasif',
                             'scheduled' => 'Zamanlı (başlamadı)', 'expired' => 'Süresi dolmuş', 'archived' => 'Arşiv'];

    /**
     * Liste / dışa aktarım / API için ortak WHERE üretir.
     * @param array{q?:string,status?:string,category?:string,tag?:string} $f
     * @return array{0:string,1:array} ["WHERE ...", parametreler]
     */
    public static function buildFilter(array $f): array {
        $status = $f['status'] ?? '';
        if (!isset(self::STATUSES[$status])) $status = '';
        $category = $f['category'] ?? '';
        $tag      = trim($f['tag'] ?? '');
        $search   = trim($f['q'] ?? '');
        $now = (new DateTimeImmutable('now'))->setTimezone(Settings::timezone())->format('Y-m-d H:i:s');

        $where = []; $params = [];
        switch ($status) {
            case 'archived': $where[] = 'archived_at IS NOT NULL'; break;
            case 'inactive': $where[] = 'archived_at IS NULL AND is_active = 0'; break;
            case 'live':
                $where[] = 'archived_at IS NULL AND is_active = 1 AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at > ?)';
                array_push($params, $now, $now); break;
            case 'scheduled':
                $where[] = 'archived_at IS NULL AND is_active = 1 AND starts_at > ?'; $params[] = $now; break;
            case 'expired':
                $where[] = 'archived_at IS NULL AND is_active = 1 AND ends_at <= ? AND (starts_at IS NULL OR starts_at <= ?)';
                array_push($params, $now, $now); break;
            default: $where[] = 'archived_at IS NULL';
        }
        if ($category !== '' && isset(self::CATEGORIES[$category])) { $where[] = 'category = ?'; $params[] = $category; }
        if ($tag !== '')    { $where[] = 'FIND_IN_SET(?, tags) > 0'; $params[] = $tag; }
        if ($search !== '') {
            $where[] = '(slug LIKE ? OR title LIKE ? OR target_url LIKE ? OR tags LIKE ? OR notes LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    // ─── Okuma ───────────────────────────────────────────────────────────────

    public static function find(PDO $pdo, int $id): ?array {
        $st = $pdo->prepare('SELECT * FROM links WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function findBySlug(PDO $pdo, string $slug): ?array {
        $st = $pdo->prepare('SELECT * FROM links WHERE slug = ? LIMIT 1');
        $st->execute([$slug]);
        return $st->fetch() ?: null;
    }

    /** Linkin anlık durumu: archived | inactive | scheduled | expired | live */
    public static function status(array $link, ?DateTimeImmutable $now = null): string {
        return Redirector::state($link, $now)['state'];
    }

    /** API / dışa aktarım için sade temsil */
    public static function present(array $link): array {
        $style = self::effectiveStyle($link);
        return [
            'slug'          => $link['slug'],
            'short_url'     => BASE_URL . '/r/' . $link['slug'],
            'title'         => $link['title'],
            'target_url'    => $link['target_url'],
            'status'        => self::status($link),
            'is_active'     => (bool)$link['is_active'],
            'archived'      => !empty($link['archived_at']),
            'category'      => $link['category'] ?? null,
            'tags'          => ($link['tags'] ?? '') !== '' ? explode(',', $link['tags']) : [],
            'notes'         => $link['notes'] ?? null,
            'fallback_url'  => $link['fallback_url'] ?? null,
            'starts_at'     => $link['starts_at'] ?? null,
            'ends_at'       => $link['ends_at'] ?? null,
            'after_end_url' => $link['after_end_url'] ?? null,
            'utm_enabled'   => !empty($link['utm_enabled']),
            'utm_campaign'  => $link['utm_campaign'] ?? null,
            'logo'          => $link['logo'],
            'style'         => $style,
            'scan_count'    => (int)$link['scan_count'],
            'created_at'    => $link['created_at'],
            'updated_at'    => $link['updated_at'],
        ];
    }
}

/**
 * LinkEvents — değişiklik geçmişi (kim, ne zaman, neyi değiştirdi)
 */
class LinkEvents {

    /** @param array{id:?int,name:string} $actor */
    public static function log(PDO $pdo, ?int $linkId, ?string $slug, array $actor, string $action, array $details = []): void {
        try {
            $pdo->prepare(
                'INSERT INTO link_events (link_id, slug, user_id, username, action, details) VALUES (?,?,?,?,?,?)'
            )->execute([
                $linkId, $slug, $actor['id'] ?? null, mb_substr($actor['name'] ?? '', 0, 64), $action,
                $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]);
        } catch (\Throwable $e) {
            // Geçmiş yazılamıyorsa asıl işlem engellenmemeli
            Logger::warning('Link geçmişi yazılamadı: ' . $e->getMessage());
        }
    }

    /** Geçmiş satırının ayrıntı hücresi (HTML, kaçışlı) */
    public static function detailsHtml(array $ev): string {
        $d = !empty($ev['details']) ? json_decode($ev['details'], true) : null;
        if (!is_array($d) || !$d) return '—';
        $parts = [];
        foreach ($d as $field => $val) {
            if (is_array($val) && count($val) === 2) {
                $parts[] = '<span class="font-mono">' . e($field) . '</span>: '
                         . '<span class="text-muted">' . e(mb_strimwidth((string)($val[0] ?? '∅'), 0, 60, '…')) . '</span> → '
                         . '<strong>' . e(mb_strimwidth((string)($val[1] ?? '∅'), 0, 60, '…')) . '</strong>';
            } else {
                $parts[] = '<span class="font-mono">' . e($field) . '</span>: ' . e(mb_strimwidth((string)$val, 0, 80, '…'));
            }
        }
        return implode('<br>', $parts);
    }

    public static function actionLabel(string $a): string {
        return [
            'create' => 'Oluşturuldu', 'update' => 'Güncellendi', 'activate' => 'Aktif edildi',
            'deactivate' => 'Pasife alındı', 'archive' => 'Arşivlendi', 'restore' => 'Arşivden çıkarıldı',
            'purge' => 'Kalıcı silindi',
        ][$a] ?? $a;
    }
}
