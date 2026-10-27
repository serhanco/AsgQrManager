<?php
/**
 * QrDesign — QR görünümünün ek seçenekleri (köşe biçimi/rengi, renk geçişi), okunabilirlik denetimi
 * ve tüm çıktı formatlarının (SVG, PNG, PDF, EPS) paylaştığı çizim planı.
 *
 * Stil anahtarları (QrRenderer::normalizeStyle çıktısı):
 *   fg, bg, shape, transparent              — v2'den beri
 *   eye       square | rounded | circle     — köşedeki üç büyük karenin (göz) biçimi
 *   eye_color #RRGGBB | null                — göz rengi (boşsa QR rengi)
 *   gradient  none | linear | radial        — QR renginden fg2'ye geçiş
 *   fg2       #RRGGBB | null                — geçişin ikinci rengi
 * Ek seçeneklerin hepsi varsayılandaysa çıktı v2 ile aynıdır.
 */
class QrDesign {

    public const EYES = [
        'square'  => 'Kare (klasik)',
        'rounded' => 'Yuvarlatılmış',
        'circle'  => 'Daire',
    ];

    public const GRADIENTS = [
        'none'   => 'Yok (tek renk)',
        'linear' => 'Çapraz geçiş',
        'radial' => 'Merkezden dışa',
    ];

    /** Bunun altındaki kontrast kaydedilmez (QR neredeyse görünmez) */
    public const MIN_CONTRAST  = 2.5;
    /** Bunun altındaki kontrast uyarı verir */
    public const WARN_CONTRAST = 4.0;

    /** Gradyanın raster/PDF çıktısında kaç renk basamağına bölündüğü (modül ölçeğinde fark edilmez) */
    private const STEPS = 24;

    /** Ek alanları doğrular; geçersiz değer varsayılana döner. */
    public static function normalizeExtras(array $s): array {
        $eye = (string)($s['eye'] ?? 'square');
        $grad = (string)($s['gradient'] ?? 'none');
        $fg2 = LinkService::normalizeHex($s['fg2'] ?? null);
        if (!isset(self::GRADIENTS[$grad]) || $fg2 === null) $grad = 'none';
        return [
            'eye'       => isset(self::EYES[$eye]) ? $eye : 'square',
            'eye_color' => LinkService::normalizeHex($s['eye_color'] ?? null),
            'gradient'  => $grad,
            'fg2'       => $grad === 'none' ? null : $fg2,
        ];
    }

    public static function hasExtras(array $st): bool {
        return ($st['eye'] ?? 'square') !== 'square' || !empty($st['eye_color']) || ($st['gradient'] ?? 'none') !== 'none';
    }

    /** links.design / qr_templates.design sütunu (JSON) → ek alanlar */
    public static function decode(?string $json): array {
        $d = $json ? json_decode($json, true) : null;
        return self::normalizeExtras(is_array($d) ? $d : []);
    }

    /** Ek alanlar → JSON (hepsi varsayılansa null: sütun boş kalır, QR v2 gibi çizilir) */
    public static function encode(array $extras): ?string {
        $x = self::normalizeExtras($extras);
        if (!self::hasExtras($x)) return null;
        return json_encode(array_filter($x, fn($v) => $v !== null), JSON_UNESCAPED_SLASHES);
    }

    // ─── Okunabilirlik ───────────────────────────────────────────────────────

    /** QR'da kullanılan koyu renkler (QR rengi, geçiş rengi, göz rengi) */
    private static function inks(array $st): array {
        $inks = ['QR rengi' => $st['fg']];
        if (($st['gradient'] ?? 'none') !== 'none' && !empty($st['fg2'])) $inks['Geçiş rengi'] = $st['fg2'];
        if (!empty($st['eye_color'])) $inks['Köşe rengi'] = $st['eye_color'];
        return $inks;
    }

    /**
     * Tasarımın okunabilirliğini denetler. Hatalar kaydı engeller; uyarılar yalnızca gösterilir.
     * @return array{0:string[],1:string[]} [hatalar, uyarılar]
     */
    public static function check(array $st): array {
        $errors = $warnings = [];
        $bg = $st['bg'];
        $light = $dark = 0;
        foreach (self::inks($st) as $label => $c) {
            $ratio = LinkService::contrastRatio($c, $bg);
            $inverted = self::lum($c) >= self::lum($bg);
            $inverted ? $light++ : $dark++;
            if ($ratio < self::MIN_CONTRAST) {
                $errors[] = sprintf('%s ile arka plan arasındaki kontrast çok düşük (%.1f:1). QR okunmaz; en az %.1f:1 olmalı.', $label, $ratio, self::MIN_CONTRAST);
            } elseif ($ratio < self::WARN_CONTRAST) {
                $warnings[] = sprintf('%s ile arka plan arasındaki kontrast düşük (%.1f:1). Baskıdan önce farklı telefonlarla deneyin; %.0f:1 ve üstü önerilir.', $label, $ratio, self::WARN_CONTRAST);
            }
        }
        if ($light && $dark) {
            $errors[] = 'Renklerin bir kısmı arka plandan koyu, bir kısmı açık. QR\'ın tamamı ya zeminden koyu ya da zeminden açık olmalı.';
        } elseif ($light) {
            $warnings[] = 'Ters renk: QR arka plandan açık. iPhone ve güncel Android kameraları okur; bazı eski okuyucu uygulamaları okumayabilir.';
        }
        if (!empty($st['transparent']) && $light) {
            $warnings[] = 'Saydam zeminde ters renkli QR yalnızca koyu bir yüzeye basılırsa okunur.';
        }
        return [$errors, $warnings];
    }

    private static function lum(string $hex): float {
        $hex = ltrim($hex, '#');
        $ch = [];
        foreach ([0, 2, 4] as $i) {
            $v = hexdec(substr($hex, $i, 2)) / 255;
            $ch[] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $ch[0] + 0.7152 * $ch[1] + 0.0722 * $ch[2];
    }

    // ─── Çizim planı ─────────────────────────────────────────────────────────

    /**
     * Matrisi formatlardan bağımsız şekillere çevirir. Birim: modül; (0,0) görselin sol üst köşesi (kenar boşluğu dahil).
     *
     * Şekiller:  ['rect', x, y, w, h, r]  (r: köşe yarıçapı, 0 = keskin)
     *            ['circle', cx, cy, r]
     * Gözler:    ['ring' => [dış, iç boşluk], 'dot' => şekil, 'cx' => , 'cy' => ]
     *
     * @return array{modules:array,eyes:array,size:int,margin:int}
     */
    public static function layout(array $matrix, int $margin, array $st): array {
        $size = count($matrix);
        $customEyes = $st['eye'] !== 'square' || !empty($st['eye_color']);
        $modules = [];
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($matrix[$r][$c] !== 1) continue;
                $finder = QrRenderer::isFinder($r, $c, $size);
                if ($finder && $customEyes) continue;          // gözler ayrı çizilir
                $x = $c + $margin; $y = $r + $margin;
                // Bulucu desenler (gözler) kare modüllerle çizilir: okuyucuların kilitlendiği noktalar
                $shape = $finder ? 'square' : $st['shape'];
                $modules[] = match ($shape) {
                    'dots'    => ['circle', $x + 0.5, $y + 0.5, 0.46],
                    'rounded' => ['rect', $x, $y, 1, 1, 0.3],
                    default   => ['rect', $x, $y, 1, 1, 0],
                };
            }
        }

        $eyes = [];
        if ($customEyes) {
            foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$r, $c]) {
                $x = $c + $margin; $y = $r + $margin;
                $eyes[] = match ($st['eye']) {
                    'circle'  => ['ring' => [['circle', $x + 3.5, $y + 3.5, 3.5], ['circle', $x + 3.5, $y + 3.5, 2.5]],
                                  'dot'  => ['circle', $x + 3.5, $y + 3.5, 1.5]],
                    'rounded' => ['ring' => [['rect', $x, $y, 7, 7, 2], ['rect', $x + 1, $y + 1, 5, 5, 1.3]],
                                  'dot'  => ['rect', $x + 2, $y + 2, 3, 3, 0.9]],
                    default   => ['ring' => [['rect', $x, $y, 7, 7, 0], ['rect', $x + 1, $y + 1, 5, 5, 0]],
                                  'dot'  => ['rect', $x + 2, $y + 2, 3, 3, 0]],
                } + ['cx' => $x + 3.5, 'cy' => $y + 3.5];
            }
        }
        return ['modules' => $modules, 'eyes' => $eyes, 'size' => $size, 'margin' => $margin];
    }

    /** Şeklin merkezi (gradyan rengi için) */
    public static function center(array $p): array {
        return $p[0] === 'circle' ? [$p[1], $p[2]] : [$p[1] + $p[3] / 2, $p[2] + $p[4] / 2];
    }

    /** Gradyanda (x,y) noktasının 0-1 konumu */
    public static function gradientT(array $st, float $x, float $y, int $size, int $margin): float {
        $u = ($x - $margin) / $size; $v = ($y - $margin) / $size;
        $t = $st['gradient'] === 'radial'
            ? sqrt(($u - 0.5) ** 2 + ($v - 0.5) ** 2) / 0.7071
            : ($u + $v) / 2;
        return max(0.0, min(1.0, $t));
    }

    public static function mix(string $a, string $b, float $t): string {
        $a = ltrim($a, '#'); $b = ltrim($b, '#');
        $out = '#';
        foreach ([0, 2, 4] as $i) {
            $va = hexdec(substr($a, $i, 2)); $vb = hexdec(substr($b, $i, 2));
            $out .= sprintf('%02X', (int)round($va + ($vb - $va) * $t));
        }
        return $out;
    }

    /**
     * Raster ve PDF/EPS için şekilleri renk gruplarına ayırır (gradyan basamaklı uygulanır).
     * @return array<int,array{color:string,modules:array,eyes:array}>
     */
    public static function colorGroups(array $plan, array $st): array {
        $groups = [];
        $add = function (string $color, string $kind, $item) use (&$groups) {
            $groups[$color] ??= ['color' => $color, 'modules' => [], 'eyes' => []];
            $groups[$color][$kind][] = $item;
        };
        $grad = $st['gradient'] !== 'none' && !empty($st['fg2']);
        $at = function (float $x, float $y) use ($st, $plan, $grad): string {
            if (!$grad) return $st['fg'];
            $t = self::gradientT($st, $x, $y, $plan['size'], $plan['margin']);
            return self::mix($st['fg'], $st['fg2'], round($t * (self::STEPS - 1)) / (self::STEPS - 1));
        };
        foreach ($plan['modules'] as $p) {
            [$cx, $cy] = self::center($p);
            $add($at($cx, $cy), 'modules', $p);
        }
        foreach ($plan['eyes'] as $e) {
            $add($st['eye_color'] ?: $at($e['cx'], $e['cy']), 'eyes', $e);
        }
        return array_values($groups);
    }
}
