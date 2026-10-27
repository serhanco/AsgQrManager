<?php
/**
 * QrPdf — bağımlılıksız, VEKTÖREL tek sayfa PDF (matbaa / ajans için).
 * Modüller PDF yollarıyla çizilir; istenen boyutta kayıpsız ölçeklenir.
 * Logo varsa GD ile düz JPEG'e çevrilip gömülür.
 */
class QrPdf {

    private static function n(float $v): string {
        return rtrim(rtrim(sprintf('%.3f', $v), '0'), '.') ?: '0';
    }

    /** @return string[] r g b (0-1 aralığı) */
    private static function rgb(string $hex): array {
        $hex = ltrim($hex, '#');
        return array_map(fn($i) => self::n(hexdec(substr($hex, $i, 2)) / 255), [0, 2, 4]);
    }

    /** Renk operatörü: PDF/EPS içinde "r g b rg" ya da (matbaa için) "c m y k k" */
    public static function color(string $hex, bool $cmyk): string {
        [$r, $g, $b] = self::rgb($hex);
        if (!$cmyk) return "{$r} {$g} {$b} rg";
        $r = (float)$r; $g = (float)$g; $b = (float)$b;
        $k = 1 - max($r, $g, $b);
        if ($k >= 1) return '0 0 0 1 k';           // saf siyah → yalnızca K (zengin siyah değil)
        $f = fn(float $v) => self::n((1 - $v - $k) / (1 - $k));
        return "{$f($r)} {$f($g)} {$f($b)} " . self::n($k) . ' k';
    }

    /**
     * PDF ve EPS'in ortak çizim komutları (m, l, c, h, re, f, rg/k).
     * Logo görselinin kendisi çizilmez; yalnızca altındaki boşluk çizilir ve konumu döner.
     *
     * @param float $W Sayfa kenarı (pt)
     * @return array{0:string,1:?array} [komutlar, logo: [jpeg, iw, ih, x, y, dw, dh] | null]
     */
    public static function drawing(array $matrix, int $margin, float $W, ?string $logoPath, array $style, bool $cmyk = false, bool $fillPerRow = false): array {
        $size  = count($matrix);
        $total = $size + $margin * 2;
        $u     = $W / $total;                    // modül kenarı (pt)
        $n     = fn(float $v) => self::n($v);
        $k     = 0.5523;                         // daire/yay için Bezier katsayısı

        $c = '';
        if (!$style['transparent']) {
            $c .= self::color($style['bg'], $cmyk) . "\n0 0 {$n($W)} {$n($W)} re f\n";
        }

        $plan = QrDesign::layout($matrix, $margin, $style);
        $path = function (array $p) use ($u, $W, $n, $k): string {
            if ($p[0] === 'circle') {
                $rad = $p[3] * $u; $cx = $p[1] * $u; $cy = $W - $p[2] * $u; $q = $rad * $k;
                return "{$n($cx + $rad)} {$n($cy)} m "
                    . "{$n($cx + $rad)} {$n($cy + $q)} {$n($cx + $q)} {$n($cy + $rad)} {$n($cx)} {$n($cy + $rad)} c "
                    . "{$n($cx - $q)} {$n($cy + $rad)} {$n($cx - $rad)} {$n($cy + $q)} {$n($cx - $rad)} {$n($cy)} c "
                    . "{$n($cx - $rad)} {$n($cy - $q)} {$n($cx - $q)} {$n($cy - $rad)} {$n($cx)} {$n($cy - $rad)} c "
                    . "{$n($cx + $q)} {$n($cy - $rad)} {$n($cx + $rad)} {$n($cy - $q)} {$n($cx + $rad)} {$n($cy)} c h\n";
            }
            $x = $p[1] * $u; $w = $p[3] * $u; $h = $p[4] * $u;
            $y = $W - ($p[2] + $p[4]) * $u;      // PDF'de y yukarı doğru artar
            if ($p[5] <= 0) return "{$n($x)} {$n($y)} {$n($w)} {$n($h)} re\n";
            $rad = $p[5] * $u; $q = $rad * (1 - $k);
            $x2 = $x + $w; $y2 = $y + $h;
            return "{$n($x + $rad)} {$n($y)} m {$n($x2 - $rad)} {$n($y)} l "
                . "{$n($x2 - $q)} {$n($y)} {$n($x2)} {$n($y + $q)} {$n($x2)} {$n($y + $rad)} c "
                . "{$n($x2)} {$n($y2 - $rad)} l "
                . "{$n($x2)} {$n($y2 - $q)} {$n($x2 - $q)} {$n($y2)} {$n($x2 - $rad)} {$n($y2)} c "
                . "{$n($x + $rad)} {$n($y2)} l "
                . "{$n($x + $q)} {$n($y2)} {$n($x)} {$n($y2 - $q)} {$n($x)} {$n($y2 - $rad)} c "
                . "{$n($x)} {$n($y + $rad)} l "
                . "{$n($x)} {$n($y + $q)} {$n($x + $q)} {$n($y)} {$n($x + $rad)} {$n($y)} c h\n";
        };

        foreach (QrDesign::colorGroups($plan, $style) as $g) {
            $c .= self::color($g['color'], $cmyk) . "\n";
            $i = 0;
            foreach ($g['modules'] as $p) {
                $c .= $path($p);
                // EPS: eski RIP'lerin yol sınırına takılmamak için parça parça doldur
                if ($fillPerRow && ++$i % 150 === 0) $c .= "f\n";
            }
            if ($g['modules']) $c .= "f\n";
            foreach ($g['eyes'] as $e) {
                // Halka: dış + iç yol, çift-tek kuralıyla (iç kısım boş kalır)
                $c .= $path($e['ring'][0]) . $path($e['ring'][1]) . "f*\n" . $path($e['dot']) . "f\n";
            }
        }

        // Logo zemini
        $logo = null;
        if ($logoPath && file_exists($logoPath)) {
            $jpg = QrRenderer::logoAsJpeg($logoPath, $style['transparent'] ? '#FFFFFF' : $style['bg']);
            if ($jpg !== null) {
                [$data, $iw, $ih] = $jpg;
                $area = $W * 0.15;
                if ($iw >= $ih) { $dw = $area; $dh = $area * $ih / $iw; } else { $dh = $area; $dw = $area * $iw / $ih; }
                $pad = $u;
                $bx = ($W - $dw) / 2 - $pad;  $by = ($W - $dh) / 2 - $pad;
                $bw = $dw + 2 * $pad;         $bh = $dh + 2 * $pad;
                $c .= self::color($style['transparent'] ? '#FFFFFF' : $style['bg'], $cmyk) . "\n{$n($bx)} {$n($by)} {$n($bw)} {$n($bh)} re f\n";
                $logo = [$data, $iw, $ih, ($W - $dw) / 2, ($W - $dh) / 2, $dw, $dh];
            }
        }
        return [$c, $logo];
    }

    /**
     * @param int $mm  Sayfa kenarı (milimetre), 20-1000 arası
     * @param array $style normalizeStyle() çıktısı: fg, bg, shape, transparent
     * @param bool $cmyk Renkleri CMYK yaz (AI / matbaa çıktısı); varsayılan PDF RGB kalır
     */
    public static function render(array $matrix, int $margin, int $mm, ?string $logoPath, array $style, bool $cmyk = false): string {
        $mm = max(20, min(1000, $mm));
        $W  = $mm * 72 / 25.4;                   // pt
        $n  = fn(float $v) => self::n($v);

        [$c, $logo] = self::drawing($matrix, $margin, $W, $logoPath, $style, $cmyk);

        $imageObj = '';
        $xobject  = '';
        if ($logo !== null) {
            [$data, $iw, $ih, $lx, $ly, $dw, $dh] = $logo;
            $c .= "q {$n($dw)} 0 0 {$n($dh)} {$n($lx)} {$n($ly)} cm /Im1 Do Q\n";
            $imageObj = "<< /Type /XObject /Subtype /Image /Width {$iw} /Height {$ih} /ColorSpace /DeviceRGB "
                      . "/BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($data) . " >>\nstream\n{$data}\nendstream";
            $xobject = ' /XObject << /Im1 5 0 R >>';
        }

        $objs = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$n($W)} {$n($W)}] /Contents 4 0 R /Resources <<{$xobject} >> >>",
            4 => '<< /Length ' . strlen($c) . " >>\nstream\n{$c}endstream",
        ];
        if ($imageObj !== '') $objs[5] = $imageObj;

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = count($objs) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        foreach ($offsets as $off) $pdf .= sprintf("%010d 00000 n \n", $off);
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R /Info << /Producer (ACIBADEM QR Code Manager) >> >>\nstartxref\n{$xref}\n%%EOF\n";
        return $pdf;
    }
}
