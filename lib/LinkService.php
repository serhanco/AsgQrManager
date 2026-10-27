<?php
require_once __DIR__ . "/Access.php";
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
        'utm_enabled', 'utm_campaign', 'fg_color', 'bg_color', 'shape', 'template_id', 'design', 'frame_text',
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

    /**
     * Link satırı + marka varsayılanlarından etkin QR stilini çıkarır.
     * Öncelik: bağlı şablon → bu QR'a özel görünüm (fg/bg/shape + design) → marka varsayılanı.
     */
    public static function effectiveStyle(array $link): array {
        if (!empty($link['template_id'])) {
            try {
                $tpl = QrTemplates::find(getPdo(), (int)$link['template_id']);
                if ($tpl) {
                    // Şablonun tasarımı sabit; çerçeve yazısı QR'a özel girildiyse o kullanılır
                    $st = $tpl['style'];
                    if (QrFrame::enabled($st) && ($t = QrFrame::cleanText($link['frame_text'] ?? '')) !== '') $st['frame_text'] = $t;
                    return $st;
                }
            } catch (\Throwable $e) { /* şablon okunamazsa aşağıdaki stil kullanılır */ }
        }
        $fg = self::normalizeHex($link['fg_color'] ?? null) ?? self::normalizeHex(Settings::get('brand_fg')) ?? '#000000';
        $bg = self::normalizeHex($link['bg_color'] ?? null) ?? self::normalizeHex(Settings::get('brand_bg')) ?? '#FFFFFF';
        $shape = $link['shape'] ?? '';
        if (!isset(self::SHAPES[$shape])) {
            $shape = Settings::get('brand_shape');
            if (!isset(self::SHAPES[$shape])) $shape = 'square';
        }
        return ['fg' => $fg, 'bg' => $bg, 'shape' => $shape] + QrDesign::decode($link['design'] ?? null);
    }

    /** Kaydedilmiş QR'ın okunabilirlik uyarıları (kaydı engellemez; panelde gösterilir) */
    public static function designWarnings(array $link): array {
        return QrDesign::check(self::effectiveStyle($link))[1];
    }

    /** Link logosu yoksa marka varsayılan logosu kullanılmaz (açıkça seçilmediyse logo yok). */
    public static function effectiveLogo(array $link): string {
        $l = (string)($link['logo'] ?? '');
        return ($l !== '' && validateLogoFile($l)) ? $l : '';
    }

    // ─── Etiket / tarih / UTM ────────────────────────────────────────────────

    /** Etiket karşılaştırma anahtarı: büyük/küçük harf ve Türkçe aksan farkını yok sayar (İstanbul = istanbul = Istanbul). */
    public static function tagKey(string $t): string {
        $t = strtr($t, ['İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ç' => 'c', 'ç' => 'c', 'Ğ' => 'g', 'ğ' => 'g',
                        'Ö' => 'o', 'ö' => 'o', 'Ş' => 's', 'ş' => 's', 'Ü' => 'u', 'ü' => 'u']);
        return mb_strtolower($t);
    }

    public const MAX_TAGS = 10;

    /**
     * "Londra, Kongre 2027;  londra" → "Londra,Kongre 2027" (en fazla MAX_TAGS etiket).
     * $canonical verilirse (tagKey => yazım) var olan etiketin yazımı kullanılır; böylece
     * "Istanbul" ile "İstanbul" raporlarda ikiye bölünmez.
     */
    public static function normalizeTags(?string $raw, array $canonical = []): ?string {
        $out = [];
        foreach (preg_split('/[,;\n]+/', (string)$raw) as $t) {
            $t = trim(preg_replace('/\s+/u', ' ', $t));
            $t = preg_replace('/[^\p{L}\p{N} _\-.]/u', '', $t);
            if ($t === '' || mb_strlen($t) > 30) continue;
            $key = self::tagKey($t);
            $spelled = $canonical[$key] ?? $t;
            // links.tags VARCHAR(255): sığmayan etiketler sessizce atlanır
            if (!isset($out[$key]) && strlen(implode(',', array_merge($out, [$spelled]))) > 255) break;
            $out[$key] ??= $spelled;
            if (count($out) >= self::MAX_TAGS) break;
        }
        return $out ? implode(',', array_values($out)) : null;
    }

    /**
     * Sistemdeki etiketler: tagKey => ['tag' => görünen yazım, 'count' => tüm QR, 'active' => arşivsiz QR, 'scans' => okutma].
     * Görünen yazım, en çok kullanılan yazımdır. Sayıya göre azalan sıralıdır.
     */
    public static function allTags(PDO $pdo): array {
        [$scopeSql, $scopeParams] = Access::linkScopeSql();
        $st = $pdo->prepare("SELECT tags, archived_at IS NULL AS live, scan_count FROM links WHERE tags IS NOT NULL AND tags <> ''"
            . ($scopeSql !== '' ? " AND {$scopeSql}" : ''));
        $st->execute($scopeParams);
        $rows = $st->fetchAll();
        $spell = $res = [];
        foreach ($rows as $r) {
            foreach (explode(',', $r['tags']) as $t) {
                if ($t === '') continue;
                $k = self::tagKey($t);
                $spell[$k][$t] = ($spell[$k][$t] ?? 0) + 1;
                $res[$k]['count'] = ($res[$k]['count'] ?? 0) + 1;
                $res[$k]['active'] = ($res[$k]['active'] ?? 0) + (int)$r['live'];
                $res[$k]['scans'] = ($res[$k]['scans'] ?? 0) + (int)$r['scan_count'];
            }
        }
        foreach ($res as $k => &$v) {
            arsort($spell[$k]);
            $v['tag'] = (string)array_key_first($spell[$k]);
        }
        unset($v);
        uasort($res, fn($a, $b) => [$b['active'], $b['count'], $a['tag']] <=> [$a['active'], $a['count'], $b['tag']]);
        return $res;
    }

    /** tagKey => yazım haritası (normalizeTags için). Veritabanı erişilemezse boş döner. */
    public static function canonicalMap(?PDO $pdo = null): array {
        try {
            $map = [];
            foreach (self::allTags($pdo ?? getPdo()) as $k => $v) $map[$k] = $v['tag'];
            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Seçili QR'lara etiket ekler / çıkarır; her değişiklik geçmişe yazılır.
     * @param int[] $ids   @param string[] $add   @param string[] $remove
     * @return int değişen QR sayısı
     */
    public static function bulkTags(PDO $pdo, array $ids, array $add, array $remove, array $actor): int {
        $map = self::canonicalMap($pdo);
        $addKeys = [];
        foreach (explode(',', (string)self::normalizeTags(implode(',', $add), $map)) as $t) if ($t !== '') $addKeys[self::tagKey($t)] = $t;
        $rmKeys = array_map([self::class, 'tagKey'], array_filter(array_map('trim', $remove), fn($t) => $t !== ''));
        $n = 0;
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $link = self::find($pdo, $id);
            if (!$link) continue;
            $cur = [];
            foreach (explode(',', (string)$link['tags']) as $t) if ($t !== '') $cur[self::tagKey($t)] = $t;
            foreach ($rmKeys as $k) unset($cur[$k]);
            foreach ($addKeys as $k => $t) $cur[$k] ??= $t;
            $new = self::normalizeTags(implode(',', $cur), $map);
            if (self::update($pdo, $link, ['tags' => $new], $actor, 'tags')) $n++;
        }
        return $n;
    }

    /**
     * Bir etiketi tüm QR'larda yeniden adlandırır; $to zaten varsa birleştirir. $to boşsa etiketi kaldırır.
     * QR'ların kendisine (kısa ad, hedef, okutma) dokunulmaz.
     * @return int değişen QR sayısı
     */
    public static function renameTag(PDO $pdo, string $from, string $to, array $actor): int {
        $fromKey = self::tagKey(trim($from));
        $ids = [];
        foreach ($pdo->query("SELECT id, tags FROM links WHERE tags IS NOT NULL AND tags <> ''")->fetchAll() as $r) {
            foreach (explode(',', $r['tags']) as $t) {
                if (self::tagKey($t) === $fromKey) { $ids[] = (int)$r['id']; break; }
            }
        }
        // Aynı etiketin yazımını düzeltmek (poster → Poster) serbest; farklı bir etikete geçiş mevcut yazımı korur (birleştirme)
        $canon = self::canonicalMap($pdo);
        unset($canon[$fromKey]);
        $to = trim((string)self::normalizeTags($to, $canon));
        $n = 0;
        foreach ($ids as $id) {
            $link = self::find($pdo, $id);
            $cur = [];
            foreach (explode(',', (string)$link['tags']) as $t) {
                if ($t === '') continue;
                if (self::tagKey($t) === $fromKey) { if ($to !== '') foreach (explode(',', $to) as $x) $cur[self::tagKey($x)] ??= $x; continue; }
                $cur[self::tagKey($t)] ??= $t;
            }
            if (self::update($pdo, $link, ['tags' => self::normalizeTags(implode(',', array_values($cur)))], $actor, 'tags')) $n++;
        }
        return $n;
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

        $mode = (string)($post['style_mode'] ?? 'brand');
        $in['fg_color'] = $in['bg_color'] = $in['shape'] = $in['template_id'] = '';
        $in['design'] = [];
        if ($mode === 'custom') {
            $in['fg_color'] = $post['fg_color'] ?? '';
            $in['bg_color'] = $post['bg_color'] ?? '';
            $in['shape']    = $post['shape']    ?? '';
            $in['design']   = QrTemplates::designInput([
                'eye' => $post['eye'] ?? '', 'eye_color' => !empty($post['eye_color_on']) ? ($post['eye_color'] ?? '') : '',
                'gradient' => $post['gradient'] ?? '', 'fg2' => ($post['gradient'] ?? 'none') !== 'none' ? ($post['fg2'] ?? '') : '',
                'frame' => $post['frame'] ?? '', 'frame_color' => $post['frame_color'] ?? '', 'frame_text' => $post['frame_text'] ?? '',
            ]);
        } elseif ($mode === 'template') {
            $in['template_id'] = $post['template_id'] ?? '';
        }
        // QR'a özel çerçeve yazısı yalnızca şablonla birlikte anlamlı; diğer görünümlerde temizlenir
        $in['frame_text'] = $mode === 'template' ? trim((string)($post['tpl_frame_text'] ?? '')) : '';
        if (!QrTemplates::linkTextAvailable(getPdo())) unset($in['frame_text']);
        // Şablon tablosu henüz yoksa (migration bekliyor) yeni sütunlara yazılmaz
        if (!QrTemplates::available(getPdo())) unset($in['template_id'], $in['design']);
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

        if ($has('tags'))  $clean['tags']  = self::normalizeTags(is_array($in['tags']) ? implode(',', $in['tags']) : (string)$in['tags'], self::canonicalMap());
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

        // Renkler
        foreach (['fg_color' => 'QR rengi', 'bg_color' => 'Arka plan rengi'] as $k => $label) {
            if (!$has($k)) continue;
            $raw = trim((string)$in[$k]);
            if ($raw === '') { $clean[$k] = null; continue; }
            $hex = self::normalizeHex($raw);
            if ($hex === null) $errors[] = "{$label} geçersiz (örn. #1B3A6B).";
            else $clean[$k] = $hex;
        }

        // Köşe biçimi / rengi ve renk geçişi (design: {eye, eye_color, gradient, fg2})
        if ($has('design')) {
            $d = is_array($in['design']) ? $in['design'] : (is_string($in['design']) ? (json_decode($in['design'], true) ?: []) : []);
            foreach (['eye_color' => 'Köşe rengi', 'fg2' => 'Geçiş rengi'] as $k => $label) {
                if (trim((string)($d[$k] ?? '')) !== '' && self::normalizeHex((string)$d[$k]) === null) $errors[] = "{$label} geçersiz (örn. #0A3A86).";
            }
            if (($d['eye'] ?? '') !== '' && !isset(QrDesign::EYES[$d['eye']])) $errors[] = 'Geçersiz köşe biçimi.';
            if (($d['gradient'] ?? '') !== '' && !isset(QrDesign::GRADIENTS[$d['gradient']])) $errors[] = 'Geçersiz renk geçişi.';
            elseif (($d['gradient'] ?? 'none') !== 'none' && self::normalizeHex($d['fg2'] ?? '') === null) $errors[] = 'Renk geçişi için ikinci rengi seçin.';
            $errors = array_merge($errors, QrDesign::frameErrors($d));
            $clean['design'] = QrDesign::encode($d);
        }

        if ($has('template_id')) {
            $tid = trim((string)$in['template_id']);
            if ($tid === '' || $tid === '0') $clean['template_id'] = null;
            else {
                $tpl = ctype_digit($tid) ? QrTemplates::find(getPdo(), (int)$tid) : null;
                $unchanged = $tpl && (int)($existing['template_id'] ?? 0) === (int)$tid;
                if (!$tpl || (!empty($tpl['archived_at']) && !$unchanged)) $errors[] = 'Seçilen şablon bulunamadı ya da arşivde.';
                else $clean['template_id'] = (int)$tid;
            }
        }

        if ($has('shape')) {
            $sh = trim((string)$in['shape']);
            if ($sh !== '' && !isset(self::SHAPES[$sh])) $errors[] = 'Geçersiz QR şekli.';
            else $clean['shape'] = $sh === '' ? null : $sh;
        }

        // QR'a özel çerçeve yazısı (şablonlu QR): editör de yazabilir
        if ($has('frame_text')) {
            $raw = trim(preg_replace('/\s+/u', ' ', (string)$in['frame_text']));
            if (mb_strlen($raw) > QrFrame::MAX_TEXT) $errors[] = 'Çerçeve yazısı en fazla ' . QrFrame::MAX_TEXT . ' karakter olabilir.';
            elseif ($bad = QrFrame::unsupported($raw)) $errors[] = 'Çerçeve yazısında kullanılamayan karakter: ' . implode(' ', $bad);
            else $clean['frame_text'] = $raw === '' ? null : $raw;
        }

        $styleKeys = ['fg_color', 'bg_color', 'shape', 'design'];
        if (array_intersect_key($clean, array_flip($styleKeys))) {
            // Okunabilirlik: kaydedilecek özel görünüm denetlenir (şablonlar kendi formunda denetlenir)
            $row = [];
            foreach ($styleKeys as $k) $row[$k] = array_key_exists($k, $clean) ? $clean[$k] : ($existing[$k] ?? null);
            $custom = $row['fg_color'] !== null || $row['bg_color'] !== null || $row['shape'] !== null || $row['design'] !== null;
            if ($custom && empty($clean['template_id'] ?? $existing['template_id'] ?? null)) {
                $errors = array_merge($errors, QrDesign::check(self::effectiveStyle($row))[0]);
            }
            // Özel görünüm yalnızca yöneticiye açık; editör şablon ya da marka varsayılanını seçer.
            // Editörün kendi QR'ında önceden kalan özel görünüm değiştirilmedikçe korunur.
            $changed = false;
            foreach ($styleKeys as $k) {
                if (array_key_exists($k, $clean) && $clean[$k] !== null && $clean[$k] !== ($existing[$k] ?? null)) $changed = true;
            }
            if ($changed && !Access::can('design.custom')) {
                $errors[] = 'Özel renk ve biçim yalnızca yöneticiye açık. Bir şablon ya da marka varsayılanını seçin.';
            }
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
        // Görünüm belirtilmemişse (toplu CSV, API) kategorinin varsayılan şablonu uygulanır.
        // Panel formu görünümü her zaman açıkça gönderir; orada seçim formda yapılır.
        if (!array_intersect_key($clean, array_flip(['template_id', 'fg_color', 'bg_color', 'shape', 'design']))
            && ($tpl = QrTemplates::defaultFor($pdo, $clean['category'] ?? null))) {
            $clean['template_id'] = $tpl['id'];
        }
        if (!empty($actor['id']) && self::hasColumn($pdo, 'created_by')) $clean['created_by'] = (int)$actor['id'];
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
        // QR türü: çağıran yalnızca şema hazırsa (VCard::available) geçirir
        $type = $f['type'] ?? '';
        if (in_array($type, ['link', 'vcard'], true)) { $where[] = 'qr_type = ?'; $params[] = $type; }
        if ($tag === '__none') { $where[] = "(tags IS NULL OR tags = '')"; }
        elseif ($tag !== '')   { $where[] = 'FIND_IN_SET(?, tags) > 0'; $params[] = $tag; }
        if ($search !== '') {
            $where[] = '(slug LIKE ? OR title LIKE ? OR target_url LIKE ? OR tags LIKE ? OR notes LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        // Kullanıcı kapsamı (Yönetici ve "tüm QR'lar" için boş)
        [$scopeSql, $scopeParams] = Access::linkScopeSql();
        if ($scopeSql !== '') { $where[] = $scopeSql; array_push($params, ...$scopeParams); }
        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    // ─── Okuma ───────────────────────────────────────────────────────────────

    /** links tablosunda sütun var mı (istek başına önbellekli; migration öncesi uyumluluk için) */
    private static function hasColumn(PDO $pdo, string $col): bool {
        static $cols = null;
        if ($cols === null) {
            $cols = [];
            foreach ($pdo->query('SHOW COLUMNS FROM links')->fetchAll() as $c) $cols[$c['Field']] = true;
        }
        return isset($cols[$col]);
    }

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
            'type'          => $link['qr_type'] ?? 'link',
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
            'template_id'   => !empty($link['template_id']) ? (int)$link['template_id'] : null,
            'frame_text'    => $link['frame_text'] ?? null,
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
            'purge' => 'Kalıcı silindi', 'tags' => 'Etiket değişti',
            'request' => 'Onay talebi', 'request_ok' => 'Talep onaylandı', 'request_no' => 'Talep reddedildi',
            'request_cancel' => 'Talep geri çekildi', 'vcard' => 'Kartvizit güncellendi',
        ][$a] ?? $a;
    }
}
