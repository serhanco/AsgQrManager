<?php
/**
 * QrFrame — QR'ın çevresine kurumsal çerçeve ve çağrı metni ("Okutun, ofisimizi bulun").
 *
 * Metin yazı tipi dosyasından vektör yola çevrilir (lib/fonts/plus-jakarta-sans-bold.json, SIL OFL);
 * böylece SVG, PDF, EPS ve AI'da yazı tipi gerekmeden aynı görünür, PNG'de aynı yollar taranır.
 * QR'ın kendisi ve sessiz alanı (kenar boşluğu) olduğu gibi kalır; çerçeve onun dışına eklenir.
 *
 * Birim: modül; (0,0) çerçeveli görselin sol üst köşesi.
 */
class QrFrame {

    public const FRAMES = [
        'none'   => 'Yok',
        'bottom' => 'Altta yazı',
        'top'    => 'Üstte yazı',
    ];

    public const MAX_TEXT = 32;

    private static ?array $font = null;

    public static function font(): array {
        if (self::$font === null) {
            $f = json_decode((string)file_get_contents(__DIR__ . '/fonts/plus-jakarta-sans-bold.json'), true);
            self::$font = is_array($f) ? $f : ['unitsPerEm' => 1000, 'capHeight' => 700, 'glyphs' => []];
        }
        return self::$font;
    }

    /** Yazı tipinde olmayan karakterler (boşsa metin çizilebilir) */
    public static function unsupported(string $text): array {
        $g = self::font()['glyphs'];
        $bad = [];
        foreach (mb_str_split($text) as $ch) if (!isset($g[$ch])) $bad[$ch] = true;
        return array_keys($bad);
    }

    /** Metni temizler: tek satır, en fazla MAX_TEXT karakter, yazı tipinde olmayanlar atılır */
    public static function cleanText(?string $text): string {
        $t = trim(preg_replace('/\s+/u', ' ', (string)$text));
        $t = mb_substr($t, 0, self::MAX_TEXT);
        $g = self::font()['glyphs'];
        return trim(preg_replace('/ {2,}/', ' ', implode('', array_filter(mb_str_split($t), fn($ch) => isset($g[$ch])))));
    }

    public static function enabled(array $st): bool {
        return ($st['frame'] ?? 'none') !== 'none';
    }

    /** Çerçeve rengi üzerinde okunur yazı rengi */
    public static function textColor(string $frameHex): string {
        $h = ltrim($frameHex, '#');
        $lin = function (int $i) use ($h) {
            $v = hexdec(substr($h, $i, 2)) / 255;
            return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        };
        $l = 0.2126 * $lin(0) + 0.7152 * $lin(2) + 0.0722 * $lin(4);
        return $l > 0.4 ? '#111111' : '#FFFFFF';
    }

    /**
     * Çerçeveli görselin yerleşimi.
     * @param int $total QR'ın kenar boşluğu dahil modül sayısı
     * @return array{w:float,h:float,ox:float,oy:float,r:float,qr_r:float,color:string,text_color:string,paths:array}
     */
    public static function layout(int $total, array $st): array {
        $b    = max(1.5, $total * 0.045);           // QR çevresindeki çerçeve kalınlığı
        $band = max(6.0, $total * 0.2);             // yazı bandı
        $w    = $total + 2 * $b;
        $top  = $st['frame'] === 'top';
        $h    = $total + $b + $band;
        $ox   = $b;
        $oy   = $top ? $band : $b;
        $color = $st['frame_color'] ?: $st['fg'];

        $paths = [];
        $text = (string)($st['frame_text'] ?? '');
        if ($text !== '') {
            $font = self::font();
            $g = $font['glyphs'];
            $units = 0;
            foreach (mb_str_split($text) as $ch) $units += $g[$ch][0] ?? 0;
            $scale = $band * 0.36 / $font['capHeight'];         // büyük harf yüksekliği bandın ~%36'sı
            $maxW  = $w - 4 * $b;
            if ($units * $scale > $maxW) $scale = $maxW / $units;
            $x = ($w - $units * $scale) / 2;
            $bandTop = $top ? 0 : $total + $b;
            $base = $bandTop + ($top ? ($band + $b * 0.4) / 2 : ($band - $b * 0.4) / 2) + $font['capHeight'] * $scale / 2;
            foreach (mb_str_split($text) as $ch) {
                [$adv, $d] = $g[$ch];
                if ($d !== '') $paths[] = self::transform($d, $x, $base, $scale);
                $x += $adv * $scale;
            }
        }
        return [
            'w' => $w, 'h' => $h, 'ox' => $ox, 'oy' => $oy,
            'r' => $b * 1.6, 'qr_r' => $b * 0.6,
            'color' => $color, 'text_color' => self::textColor($color),
            'paths' => $paths,
        ];
    }

    /**
     * Glif yolunu (yazı tipi birimi, y yukarı) modül koordinatına çevirir.
     * @return array<int,array> ['M',x,y] | ['L',x,y] | ['Q',cx,cy,x,y] | ['Z']
     */
    private static function transform(string $d, float $x0, float $base, float $s): array {
        $tok = explode(' ', $d);
        $out = [];
        $n = count($tok);
        for ($i = 0; $i < $n;) {
            $c = $tok[$i++];
            $pt = function () use (&$i, $tok, $x0, $base, $s) {
                $x = $x0 + (float)$tok[$i++] * $s;
                $y = $base - (float)$tok[$i++] * $s;
                return [$x, $y];
            };
            if ($c === 'M' || $c === 'L') { $out[] = [$c, ...$pt()]; }
            elseif ($c === 'Q') { $a = $pt(); $b = $pt(); $out[] = ['Q', ...$a, ...$b]; }
            elseif ($c === 'Z') { $out[] = ['Z']; }
        }
        return $out;
    }

    // ─── Çıktılar ────────────────────────────────────────────────────────────

    /** SVG öğeleri (çerçeve + QR zemini + yazı). $k: modül başına birim */
    public static function svg(array $L, float $k, string $qrBg, int $total): string {
        $n = fn(float $v) => rtrim(rtrim(sprintf('%.2f', $v * $k), '0'), '.') ?: '0';
        $out = '<rect width="' . $n($L['w']) . '" height="' . $n($L['h']) . '" rx="' . $n($L['r']) . '" fill="' . $L['color'] . "\"/>\n"
             . '<rect x="' . $n($L['ox']) . '" y="' . $n($L['oy']) . '" width="' . $n($total) . '" height="' . $n($total)
             . '" rx="' . $n($L['qr_r']) . '" fill="' . $qrBg . "\"/>\n";
        if ($L['paths']) {
            $d = '';
            foreach ($L['paths'] as $p) {
                foreach ($p as $seg) {
                    $d .= match ($seg[0]) {
                        'M' => 'M' . $n($seg[1]) . ',' . $n($seg[2]),
                        'L' => 'L' . $n($seg[1]) . ',' . $n($seg[2]),
                        'Q' => 'Q' . $n($seg[1]) . ',' . $n($seg[2]) . ' ' . $n($seg[3]) . ',' . $n($seg[4]),
                        default => 'Z',
                    };
                }
            }
            $out .= '<path fill="' . $L['text_color'] . '" d="' . $d . "\"/>\n";
        }
        return $out;
    }

    /** PDF / EPS komutları. $u: modül başına pt, $H: sayfa yüksekliği (pt) */
    public static function pdf(array $L, float $u, float $H, string $qrBg, int $total, bool $cmyk): string {
        $n = fn(float $v) => rtrim(rtrim(sprintf('%.3f', $v), '0'), '.') ?: '0';
        $X = fn(float $x) => $n($x * $u);
        $Y = fn(float $y) => $n($H - $y * $u);
        $c = QrPdf::color($L['color'], $cmyk) . "\n" . self::pdfRoundRect(0, 0, $L['w'] * $u, $L['h'] * $u, $L['r'] * $u, $H, $n) . "f\n"
           . QrPdf::color($qrBg, $cmyk) . "\n" . self::pdfRoundRect($L['ox'] * $u, $L['oy'] * $u, $total * $u, $total * $u, $L['qr_r'] * $u, $H, $n) . "f\n";
        if ($L['paths']) {
            $c .= QrPdf::color($L['text_color'], $cmyk) . "\n";
            foreach ($L['paths'] as $p) {
                $cur = [0, 0];
                foreach ($p as $seg) {
                    if ($seg[0] === 'M' || $seg[0] === 'L') {
                        $c .= $X($seg[1]) . ' ' . $Y($seg[2]) . ($seg[0] === 'M' ? ' m ' : ' l ');
                        $cur = [$seg[1], $seg[2]];
                    } elseif ($seg[0] === 'Q') {
                        // İkinci dereceden eğri → kübik (PDF'te yalnızca kübik var)
                        [$x0, $y0] = $cur; [, $qx, $qy, $x, $y] = $seg;
                        $c1 = [$x0 + 2 / 3 * ($qx - $x0), $y0 + 2 / 3 * ($qy - $y0)];
                        $c2 = [$x + 2 / 3 * ($qx - $x), $y + 2 / 3 * ($qy - $y)];
                        $c .= $X($c1[0]) . ' ' . $Y($c1[1]) . ' ' . $X($c2[0]) . ' ' . $Y($c2[1]) . ' ' . $X($x) . ' ' . $Y($y) . ' c ';
                        $cur = [$x, $y];
                    } else {
                        $c .= "h\n";
                    }
                }
            }
            $c .= "f\n";
        }
        return $c;
    }

    /** Sol üst köşesi (x,y) olan yuvarlak köşeli dikdörtgen (pt; y aşağı) → PDF yolu */
    private static function pdfRoundRect(float $x, float $y, float $w, float $h, float $r, float $H, callable $n): string {
        $k = 0.5523 * $r;
        $x2 = $x + $w; $yb = $H - ($y + $h); $yt = $H - $y;
        return "{$n($x + $r)} {$n($yb)} m {$n($x2 - $r)} {$n($yb)} l "
            . "{$n($x2 - $r + $k)} {$n($yb)} {$n($x2)} {$n($yb + $r - $k)} {$n($x2)} {$n($yb + $r)} c "
            . "{$n($x2)} {$n($yt - $r)} l "
            . "{$n($x2)} {$n($yt - $r + $k)} {$n($x2 - $r + $k)} {$n($yt)} {$n($x2 - $r)} {$n($yt)} c "
            . "{$n($x + $r)} {$n($yt)} l "
            . "{$n($x + $r - $k)} {$n($yt)} {$n($x)} {$n($yt - $r + $k)} {$n($x)} {$n($yt - $r)} c "
            . "{$n($x)} {$n($yb + $r)} l "
            . "{$n($x)} {$n($yb + $r - $k)} {$n($x + $r - $k)} {$n($yb)} {$n($x + $r)} {$n($yb)} c h\n";
    }

    /**
     * GD: çerçeveyi ve yazıyı çizer (QR sonra üstüne kopyalanır). $s: modül başına piksel.
     * Yazı, glif yollarından sıfırdan farklı sarım kuralıyla taranır (delikli harfler doğru çıkar).
     */
    public static function gd(\GdImage $img, array $L, float $s, string $qrBg, int $total, bool $transparent): void {
        $rgb = fn(string $h) => [hexdec(substr($h, 1, 2)), hexdec(substr($h, 3, 2)), hexdec(substr($h, 5, 2))];
        imagealphablending($img, false);
        imagefill($img, 0, 0, $transparent ? imagecolorallocatealpha($img, 255, 255, 255, 127) : imagecolorallocate($img, 255, 255, 255));
        [$r, $g, $b] = $rgb($L['color']);
        self::gdRound($img, 0, 0, (int)round($L['w'] * $s) - 1, (int)round($L['h'] * $s) - 1, (int)round($L['r'] * $s), imagecolorallocate($img, $r, $g, $b));
        [$r, $g, $b] = $rgb($qrBg);
        $qx = (int)round($L['ox'] * $s); $qy = (int)round($L['oy'] * $s); $qs = (int)round($total * $s);
        self::gdRound($img, $qx, $qy, $qx + $qs - 1, $qy + $qs - 1, (int)round($L['qr_r'] * $s), imagecolorallocate($img, $r, $g, $b));
        if (!$L['paths']) return;

        // Yolları kenarlara ayır (eğriler düz parçalara bölünür)
        $edges = [];
        foreach ($L['paths'] as $p) {
            $start = $cur = null;
            foreach ($p as $seg) {
                if ($seg[0] === 'M') { $start = $cur = [$seg[1] * $s, $seg[2] * $s]; continue; }
                if ($seg[0] === 'L') { $to = [$seg[1] * $s, $seg[2] * $s]; $edges[] = [$cur, $to]; $cur = $to; continue; }
                if ($seg[0] === 'Q') {
                    $q = [$seg[1] * $s, $seg[2] * $s]; $to = [$seg[3] * $s, $seg[4] * $s];
                    $steps = 8;
                    for ($i = 1; $i <= $steps; $i++) {
                        $t = $i / $steps; $m = 1 - $t;
                        $pt = [$m * $m * $cur[0] + 2 * $m * $t * $q[0] + $t * $t * $to[0], $m * $m * $cur[1] + 2 * $m * $t * $q[1] + $t * $t * $to[1]];
                        $edges[] = [$i === 1 ? $cur : $prev, $pt];
                        $prev = $pt;
                    }
                    $cur = $to; continue;
                }
                if ($start && $cur && ($cur[0] !== $start[0] || $cur[1] !== $start[1])) $edges[] = [$cur, $start];
                $cur = $start;
            }
        }
        [$r, $g, $b] = $rgb($L['text_color']);
        $col = imagecolorallocate($img, $r, $g, $b);
        $minY = PHP_INT_MAX; $maxY = 0;
        foreach ($edges as [$a, $c]) { $minY = min($minY, $a[1], $c[1]); $maxY = max($maxY, $a[1], $c[1]); }
        for ($y = (int)floor($minY); $y <= (int)ceil($maxY); $y++) {
            $sy = $y + 0.5; $xs = [];
            foreach ($edges as [$a, $c]) {
                if ($a[1] === $c[1]) continue;
                $dir = $c[1] > $a[1] ? 1 : -1;
                [$lo, $hi] = $dir > 0 ? [$a, $c] : [$c, $a];
                if ($sy < $lo[1] || $sy >= $hi[1]) continue;
                $xs[] = [$lo[0] + ($sy - $lo[1]) * ($hi[0] - $lo[0]) / ($hi[1] - $lo[1]), $dir];
            }
            if (!$xs) continue;
            usort($xs, fn($p, $q) => $p[0] <=> $q[0]);
            $wind = 0;
            foreach ($xs as $i => [$x, $dir]) {
                $prevWind = $wind; $wind += $dir;
                if ($prevWind === 0 && $wind !== 0) $from = $x;
                elseif ($prevWind !== 0 && $wind === 0) imageline($img, (int)round($from), $y, (int)round($x) - 1, $y, $col);
            }
        }
    }

    private static function gdRound(\GdImage $img, int $x1, int $y1, int $x2, int $y2, int $rad, int $col): void {
        $rad = max(0, min($rad, intdiv(min($x2 - $x1, $y2 - $y1), 2)));
        imagefilledrectangle($img, $x1 + $rad, $y1, $x2 - $rad, $y2, $col);
        imagefilledrectangle($img, $x1, $y1 + $rad, $x2, $y2 - $rad, $col);
        $d = $rad * 2;
        foreach ([[$x1 + $rad, $y1 + $rad], [$x2 - $rad, $y1 + $rad], [$x1 + $rad, $y2 - $rad], [$x2 - $rad, $y2 - $rad]] as [$cx, $cy]) {
            imagefilledellipse($img, $cx, $cy, $d, $d, $col);
        }
    }
}
