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

    /**
     * @param int $mm  Sayfa kenarı (milimetre), 20-1000 arası
     * @param array $style normalizeStyle() çıktısı: fg, bg, shape, transparent
     */
    public static function render(array $matrix, int $margin, int $mm, ?string $logoPath, array $style): string {
        $mm    = max(20, min(1000, $mm));
        $W     = $mm * 72 / 25.4;               // pt
        $size  = count($matrix);
        $total = $size + $margin * 2;
        $u     = $W / $total;                    // modül kenarı (pt)
        $n     = fn(float $v) => self::n($v);
        $k     = 0.5523;                         // daire/yay için Bezier katsayısı

        $c = '';
        [$br, $bg, $bb] = self::rgb($style['bg']);
        [$fr, $fg, $fb] = self::rgb($style['fg']);

        if (!$style['transparent']) {
            $c .= "{$br} {$bg} {$bb} rg\n0 0 {$n($W)} {$n($W)} re f\n";
        }

        $c .= "{$fr} {$fg} {$fb} rg\n";
        for ($r = 0; $r < $size; $r++) {
            for ($col = 0; $col < $size; $col++) {
                if ($matrix[$r][$col] !== 1) continue;
                $x = ($col + $margin) * $u;
                $y = $W - ($r + $margin + 1) * $u;   // PDF'de y yukarı doğru artar
                $shape = $style['shape'];
                if ($shape !== 'square' && QrRenderer::isFinder($r, $col, $size)) $shape = 'square';

                if ($shape === 'dots') {
                    $rad = $u * 0.46; $cx = $x + $u / 2; $cy = $y + $u / 2; $q = $rad * $k;
                    $c .= "{$n($cx + $rad)} {$n($cy)} m "
                        . "{$n($cx + $rad)} {$n($cy + $q)} {$n($cx + $q)} {$n($cy + $rad)} {$n($cx)} {$n($cy + $rad)} c "
                        . "{$n($cx - $q)} {$n($cy + $rad)} {$n($cx - $rad)} {$n($cy + $q)} {$n($cx - $rad)} {$n($cy)} c "
                        . "{$n($cx - $rad)} {$n($cy - $q)} {$n($cx - $q)} {$n($cy - $rad)} {$n($cx)} {$n($cy - $rad)} c "
                        . "{$n($cx + $q)} {$n($cy - $rad)} {$n($cx + $rad)} {$n($cy - $q)} {$n($cx + $rad)} {$n($cy)} c h\n";
                } elseif ($shape === 'rounded') {
                    $rad = $u * 0.3; $q = $rad * (1 - $k);
                    $x2 = $x + $u; $y2 = $y + $u;
                    $c .= "{$n($x + $rad)} {$n($y)} m {$n($x2 - $rad)} {$n($y)} l "
                        . "{$n($x2 - $q)} {$n($y)} {$n($x2)} {$n($y + $q)} {$n($x2)} {$n($y + $rad)} c "
                        . "{$n($x2)} {$n($y2 - $rad)} l "
                        . "{$n($x2)} {$n($y2 - $q)} {$n($x2 - $q)} {$n($y2)} {$n($x2 - $rad)} {$n($y2)} c "
                        . "{$n($x + $rad)} {$n($y2)} l "
                        . "{$n($x + $q)} {$n($y2)} {$n($x)} {$n($y2 - $q)} {$n($x)} {$n($y2 - $rad)} c "
                        . "{$n($x)} {$n($y + $rad)} l "
                        . "{$n($x)} {$n($y + $q)} {$n($x + $q)} {$n($y)} {$n($x + $rad)} {$n($y)} c h\n";
                } else {
                    $c .= "{$n($x)} {$n($y)} {$n($u)} {$n($u)} re\n";
                }
            }
        }
        $c .= "f\n";

        // Logo
        $imageObj = '';
        $xobject  = '';
        if ($logoPath && file_exists($logoPath)) {
            $jpg = QrRenderer::logoAsJpeg($logoPath, $style['transparent'] ? '#FFFFFF' : $style['bg']);
            if ($jpg !== null) {
                [$data, $iw, $ih] = $jpg;
                $area = $W * 0.15;
                if ($iw >= $ih) { $dw = $area; $dh = $area * $ih / $iw; } else { $dh = $area; $dw = $area * $iw / $ih; }
                $pad = $u;
                $bx = ($W - $dw) / 2 - $pad;  $by = ($W - $dh) / 2 - $pad;
                $bw = $dw + 2 * $pad;         $bh = $dh + 2 * $pad;
                [$sr, $sg, $sb] = $style['transparent'] ? ['1', '1', '1'] : [$br, $bg, $bb];
                $c .= "{$sr} {$sg} {$sb} rg\n{$n($bx)} {$n($by)} {$n($bw)} {$n($bh)} re f\n";
                $c .= "q {$n($dw)} 0 0 {$n($dh)} {$n(($W - $dw) / 2)} {$n(($W - $dh) / 2)} cm /Im1 Do Q\n";
                $imageObj = "<< /Type /XObject /Subtype /Image /Width {$iw} /Height {$ih} /ColorSpace /DeviceRGB "
                          . "/BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($data) . " >>\nstream\n{$data}\nendstream";
                $xobject = ' /XObject << /Im1 5 0 R >>';
            }
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
