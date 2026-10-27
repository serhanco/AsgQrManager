<?php
/**
 * QrTemplates — kurumsal QR tasarım şablonları.
 *
 * Şablonu yönetici oluşturur, kopyalar, düzenler ve arşivler (kalıcı silme yok). Editör yalnızca seçer.
 * Şablon düzenlenince ona bağlı tüm QR'ların görseli yeni tasarımla üretilir; QR'ın içindeki adres değişmez.
 * Arşivlenen şablon yeni QR'larda seçilemez; ona bağlı QR'lar aynı görünümle çalışmaya devam eder.
 * Tablo henüz yoksa (migration bekliyor) okuma işlevleri boş döner, panel ve indirmeler bozulmaz.
 */
class QrTemplates {

    public const USAGES = [
        'kartvizit' => 'Kartvizit',
        'rollup'    => 'Roll-up',
        'yaka'      => 'Yaka kartı',
        'masa'      => 'Masa kartı',
        'sticker'   => 'Sticker',
        'poster'    => 'Afiş / poster',
        'brosur'    => 'Broşür',
        'other'     => 'Diğer',
    ];

    /** Baskı boyutu sınırları (mm) */
    public const MIN_MM = 15;
    public const MAX_MM = 1000;

    /** İlk kurulumda eklenen şablonlar (013 migration) */
    public const STARTERS = [
        ['name' => 'Kartvizit', 'usage' => 'kartvizit', 'print_mm' => 20,
         'description' => 'Kartvizit arkası. En az 2 cm basın; daha küçüğü telefonla zor okunur.',
         'design' => ['fg' => '#1C2561', 'bg' => '#FFFFFF', 'shape' => 'rounded', 'eye' => 'rounded', 'eye_color' => '#0A3A86']],
        ['name' => 'Roll-up', 'usage' => 'rollup', 'print_mm' => 200,
         'description' => '1-3 metreden okutulur. 18-20 cm basın, göz hizasının altına yerleştirin.',
         'design' => ['fg' => '#1C2561', 'bg' => '#FFFFFF', 'shape' => 'dots', 'eye' => 'circle', 'eye_color' => '#1C2561', 'gradient' => 'linear', 'fg2' => '#0A3A86']],
        ['name' => 'Yaka Kartı', 'usage' => 'yaka', 'print_mm' => 25,
         'description' => 'Etkinlik yaka kartı. 2,5 cm yeterli; parlak laminasyonda yansımaya dikkat edin.',
         'design' => ['fg' => '#1C2561', 'bg' => '#FFFFFF', 'shape' => 'square', 'eye' => 'rounded']],
        ['name' => 'Masa Kartı', 'usage' => 'masa', 'print_mm' => 40,
         'description' => 'Masa üstü kart ve stant. 4 cm, 30-60 cm mesafeden rahat okunur.',
         'design' => ['fg' => '#0A3A86', 'bg' => '#FFFFFF', 'shape' => 'rounded', 'eye' => 'rounded', 'gradient' => 'radial', 'fg2' => '#1C2561']],
        ['name' => 'Sticker', 'usage' => 'sticker', 'print_mm' => 30,
         'description' => 'Yapıştırma etiket. 3 cm; kesim payı için QR çevresinde boşluk bırakın.',
         'design' => ['fg' => '#0A3A86', 'bg' => '#FFFFFF', 'shape' => 'dots', 'eye' => 'circle', 'eye_color' => '#1C2561']],
    ];

    private static ?bool $available = null;
    /** @var array<int,?array> */
    private static array $cache = [];

    public static function available(PDO $pdo): bool {
        if (self::$available === null) {
            try { $pdo->query('SELECT 1 FROM qr_templates LIMIT 1'); self::$available = true; }
            catch (\Throwable $e) { self::$available = false; }
        }
        return self::$available;
    }

    private static ?bool $linkText = null;

    /** links.frame_text sütunu var mı (014 migration) */
    public static function linkTextAvailable(PDO $pdo): bool {
        if (self::$linkText === null) {
            try { $pdo->query('SELECT frame_text FROM links LIMIT 1'); self::$linkText = true; }
            catch (\Throwable $e) { self::$linkText = false; }
        }
        return self::$linkText;
    }

    /** Testler ve migration sonrası için önbelleği sıfırlar */
    public static function reset(): void { self::$available = self::$linkText = null; self::$cache = []; }

    private static function hydrate(array $r): array {
        $r['id'] = (int)$r['id'];
        $d = json_decode((string)$r['design'], true);
        $r['style'] = self::styleOf(is_array($d) ? $d : []);
        return $r;
    }

    /** Şablon tasarımı → QR stili (fg, bg, shape, eye, eye_color, gradient, fg2, frame…) */
    public static function styleOf(array $d): array {
        $shape = (string)($d['shape'] ?? 'square');
        return [
            'fg'    => LinkService::normalizeHex($d['fg'] ?? null) ?? '#000000',
            'bg'    => LinkService::normalizeHex($d['bg'] ?? null) ?? '#FFFFFF',
            'shape' => isset(LinkService::SHAPES[$shape]) ? $shape : 'square',
        ] + QrDesign::normalizeExtras($d);
    }

    public static function find(PDO $pdo, int $id): ?array {
        if ($id <= 0 || !self::available($pdo)) return null;
        if (!array_key_exists($id, self::$cache)) {
            $st = $pdo->prepare('SELECT * FROM qr_templates WHERE id = ?');
            $st->execute([$id]);
            $r = $st->fetch();
            self::$cache[$id] = $r ? self::hydrate($r) : null;
        }
        return self::$cache[$id];
    }

    /** @return array[] Şablonlar (sıralı); $archived: null = hepsi, false = yalnızca kullanımda olanlar */
    public static function all(PDO $pdo, ?bool $archived = false): array {
        if (!self::available($pdo)) return [];
        $where = $archived === null ? '' : ($archived ? 'WHERE archived_at IS NOT NULL' : 'WHERE archived_at IS NULL');
        $rows = $pdo->query("SELECT * FROM qr_templates {$where} ORDER BY sort_order, id")->fetchAll();
        return array_map(fn($r) => self::$cache[(int)$r['id']] = self::hydrate($r), $rows);
    }

    /** @return array<int,int> şablon id => bağlı QR sayısı (arşivdekiler hariç) */
    public static function usageCounts(PDO $pdo): array {
        if (!self::available($pdo)) return [];
        $rows = $pdo->query('SELECT template_id, COUNT(*) FROM links WHERE template_id IS NOT NULL AND archived_at IS NULL GROUP BY template_id')
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_map('intval', $rows);
    }

    // ─── Kategori varsayılanları ──────────────────────────────────────────────

    /** @return array<string,int> kategori => şablon id (Ayarlar'da 'category_templates' JSON olarak saklanır) */
    public static function categoryDefaults(): array {
        $map = json_decode(Settings::get('category_templates', '{}'), true);
        $out = [];
        foreach (is_array($map) ? $map : [] as $cat => $id) {
            if (isset(LinkService::CATEGORIES[$cat]) && (int)$id > 0) $out[$cat] = (int)$id;
        }
        return $out;
    }

    /** Kategorinin varsayılan şablonu; atanmamışsa ya da şablon arşivdeyse null (marka varsayılanı) */
    public static function defaultFor(PDO $pdo, ?string $category): ?array {
        $id = self::categoryDefaults()[(string)$category] ?? 0;
        $tpl = $id ? self::find($pdo, $id) : null;
        return $tpl && empty($tpl['archived_at']) ? $tpl : null;
    }

    /** @param array<string,mixed> $in kategori => şablon id ('' = marka varsayılanı) */
    public static function setCategoryDefaults(PDO $pdo, array $in): void {
        $map = [];
        foreach (LinkService::CATEGORIES as $cat => $_) {
            $id = (int)($in[$cat] ?? 0);
            if ($id > 0 && ($t = self::find($pdo, $id)) && empty($t['archived_at'])) $map[$cat] = $id;
        }
        Settings::set('category_templates', json_encode($map ?: new \stdClass()));
    }

    // ─── Form ────────────────────────────────────────────────────────────────

    /** Form / API girdisinden tasarım alanları (fg, bg, shape, eye, eye_color, gradient, fg2) */
    public static function designInput(array $in): array {
        $out = [];
        foreach (['fg', 'bg', 'shape', 'eye', 'eye_color', 'gradient', 'fg2', 'frame', 'frame_color', 'frame_text'] as $k) {
            if (isset($in[$k]) && is_scalar($in[$k])) $out[$k] = trim((string)$in[$k]);
        }
        return $out;
    }

    /**
     * Şablon formunu doğrular.
     * @return array{0:array,1:string[],2:string[]} [temiz alanlar, hatalar, okunabilirlik uyarıları]
     */
    public static function normalize(array $in): array {
        $errors = [];
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) $errors[] = 'Şablon adı 1-80 karakter olmalı.';
        $usage = (string)($in['usage'] ?? 'other');
        if (!isset(self::USAGES[$usage])) $errors[] = 'Geçersiz kullanım alanı.';
        $desc = trim((string)($in['description'] ?? ''));
        $mmRaw = trim((string)($in['print_mm'] ?? ''));
        $mm = null;
        if ($mmRaw !== '') {
            $mm = (int)$mmRaw;
            if ((string)$mm !== $mmRaw || $mm < self::MIN_MM || $mm > self::MAX_MM) {
                $errors[] = sprintf('Baskı boyutu %d-%d mm arasında bir tam sayı olmalı.', self::MIN_MM, self::MAX_MM);
            }
        }
        $d = self::designInput($in);
        foreach (['fg' => 'QR rengi', 'bg' => 'Arka plan', 'eye_color' => 'Köşe rengi', 'fg2' => 'Geçiş rengi', 'frame_color' => 'Çerçeve rengi'] as $k => $label) {
            if (($d[$k] ?? '') !== '' && LinkService::normalizeHex($d[$k]) === null) $errors[] = "{$label} geçersiz (örn. #1C2561).";
        }
        if (($d['gradient'] ?? 'none') !== 'none' && LinkService::normalizeHex($d['fg2'] ?? '') === null) {
            $errors[] = 'Renk geçişi için ikinci rengi seçin.';
        }
        $errors = array_merge($errors, QrDesign::frameErrors($d));
        $style = self::styleOf($d);
        [$dErr, $warnings] = QrDesign::check($style);
        $errors = array_merge($errors, $dErr);
        $clean = [
            'name' => $name, 'usage' => $usage,
            'description' => $desc === '' ? null : mb_substr($desc, 0, 300),
            'design' => json_encode(array_filter($style, fn($v) => $v !== null), JSON_UNESCAPED_SLASHES),
            'print_mm' => $mm,
        ];
        return [$clean, $errors, $warnings];
    }

    // ─── Yazma ───────────────────────────────────────────────────────────────

    public static function create(PDO $pdo, array $clean, array $actor): int {
        $order = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM qr_templates')->fetchColumn();
        $pdo->prepare('INSERT INTO qr_templates (name, `usage`, description, design, print_mm, sort_order, created_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$clean['name'], $clean['usage'], $clean['description'], $clean['design'], $clean['print_mm'], $order, mb_substr($actor['name'] ?? '', 0, 64)]);
        self::reset();
        return (int)$pdo->lastInsertId();
    }

    public static function update(PDO $pdo, int $id, array $clean): void {
        $pdo->prepare('UPDATE qr_templates SET name = ?, `usage` = ?, description = ?, design = ?, print_mm = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$clean['name'], $clean['usage'], $clean['description'], $clean['design'], $clean['print_mm'], $id]);
        self::reset();
    }

    public static function copy(PDO $pdo, array $tpl, array $actor): int {
        return self::create($pdo, [
            'name' => mb_substr($tpl['name'] . ' (kopya)', 0, 80), 'usage' => $tpl['usage'], 'description' => $tpl['description'],
            'design' => $tpl['design'], 'print_mm' => $tpl['print_mm'] !== null ? (int)$tpl['print_mm'] : null,
        ], $actor);
    }

    public static function setArchived(PDO $pdo, int $id, bool $archived): void {
        $pdo->prepare('UPDATE qr_templates SET archived_at = ' . ($archived ? 'NOW()' : 'NULL') . ', updated_at = NOW() WHERE id = ?')->execute([$id]);
        self::reset();
    }
}
