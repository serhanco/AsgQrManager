<?php
/**
 * QrEps — bağımlılıksız, VEKTÖREL EPS (Encapsulated PostScript, Level 2) çıktısı.
 * Matbaa / ajans yazılımları (Illustrator, CorelDRAW, InDesign, QuarkXPress) için.
 * Çizim QrPdf ile aynıdır; renkler CMYK yazılır (siyah → yalnızca K).
 * Logo varsa düz JPEG olarak gömülür (7-bit temiz, hex kodlu).
 */
class QrEps {

    private static function n(float $v): string {
        return rtrim(rtrim(sprintf('%.3f', $v), '0'), '.') ?: '0';
    }

    /**
     * @param int $mm  Çizim alanı kenarı (milimetre), 20-1000 arası
     * @param array $style normalizeStyle() çıktısı: fg, bg, shape, transparent
     */
    public static function render(array $matrix, int $margin, int $mm, ?string $logoPath, array $style, string $title = 'QR'): string {
        $mm = max(20, min(1000, $mm));
        $W  = $mm * 72 / 25.4;                   // pt
        $n  = fn(float $v) => self::n($v);

        [$c, $logo] = QrPdf::drawing($matrix, $margin, $W, $logoPath, $style, true, true);

        if ($logo !== null) {
            [$data, $iw, $ih, $lx, $ly, $dw, $dh] = $logo;
            // Veri, işaretçiye kadar okunur; görüntü bitince kalan kısım flushfile ile atlanır
            $c .= "gsave\n{$n($lx)} {$n($ly)} translate {$n($dw)} {$n($dh)} scale\n/DeviceRGB setcolorspace\n"
                . "/QRMlogo currentfile 0 (%QRMEndLogo) /SubFileDecode filter def\n"
                . "{ << /ImageType 1 /Width {$iw} /Height {$ih} /BitsPerComponent 8 /Decode [0 1 0 1 0 1]\n"
                . "   /ImageMatrix [{$iw} 0 0 -{$ih} 0 {$ih}]\n"
                . "   /DataSource QRMlogo /ASCIIHexDecode filter /DCTDecode filter >> image QRMlogo flushfile } exec\n"
                . chunk_split(bin2hex($data), 78, "\n") . ">\n%QRMEndLogo\ngrestore\n";
        }

        $bb    = (int)ceil($W);
        $title = preg_replace('/[^\x20-\x7E]/', '', $title);
        return "%!PS-Adobe-3.0 EPSF-3.0\n"
            . "%%BoundingBox: 0 0 {$bb} {$bb}\n"
            . "%%HiResBoundingBox: 0 0 {$n($W)} {$n($W)}\n"
            . "%%Title: ({$title})\n"
            . "%%Creator: ACIBADEM QR Code Manager\n"
            . "%%CreationDate: " . gmdate('Y-m-d H:i:s') . " UTC\n"
            . "%%LanguageLevel: 2\n"
            . "%%DocumentData: Clean7Bit\n"
            . "%%Pages: 1\n"
            . "%%EndComments\n"
            . "%%BeginProlog\n"
            . "/QRMdict 20 dict def\nQRMdict begin\n"
            . "/m {moveto} bind def /l {lineto} bind def /c {curveto} bind def /h {closepath} bind def\n"
            . "/re {4 2 roll moveto 1 index 0 rlineto 0 exch rlineto neg 0 rlineto closepath} bind def\n"
            . "/f {fill} bind def /f* {eofill} bind def /k {setcmykcolor} bind def /rg {setrgbcolor} bind def\n"
            . "end\n"
            . "%%EndProlog\n"
            . "%%Page: 1 1\n"
            . "QRMdict begin\ngsave\n"
            . $c
            . "grestore\nend\nshowpage\n"
            . "%%Trailer\n%%EOF\n";
    }
}
