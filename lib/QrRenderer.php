<?php
/**
 * QrRenderer — SVG / PNG / PDF / EPS / AI QR kod üretici + logo yerleştirme + marka stili
 */

require_once __DIR__ . '/phpqrcode.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/LinkService.php';
require_once __DIR__ . '/Redirector.php';
require_once __DIR__ . '/QrPdf.php';
require_once __DIR__ . '/QrEps.php';
require_once __DIR__ . '/QrDesign.php';
require_once __DIR__ . '/QrFrame.php';

class QrRenderer {

    /**
     * Verilen URL için QR matrisini encode eder.
     * Kanıtlanmış phpqrcode kütüphanesini (Dominik Dzienia / libqrencode) kullanır.
     */
    public static function buildMatrix(string $url, string $ecc = 'M'): array {
        $eccMap = ['L' => QR_ECLEVEL_L, 'M' => QR_ECLEVEL_M, 'Q' => QR_ECLEVEL_Q, 'H' => QR_ECLEVEL_H];
        $level  = $eccMap[strtoupper($ecc)] ?? QR_ECLEVEL_M;

        // raw() returns array of strings where each char encodes module state
        $rawData = QRcode::raw($url, false, $level);

        // Convert to 2D int matrix (0=light, 1=dark)
        $matrix = [];
        foreach ($rawData as $row) {
            $line = [];
            $len  = strlen($row);
            for ($i = 0; $i < $len; $i++) {
                $line[] = (ord($row[$i]) & 1) ? 1 : 0;
            }
            $matrix[] = $line;
        }
        return $matrix;
    }

    // ─── Stil ─────────────────────────────────────────────────────────────────

    /** Eksik stil alanlarını varsayılanla doldurur. */
    public static function normalizeStyle(array $style): array {
        $fg = LinkService::normalizeHex($style['fg'] ?? null) ?? '#000000';
        $bg = LinkService::normalizeHex($style['bg'] ?? null) ?? '#FFFFFF';
        $shape = $style['shape'] ?? 'square';
        if (!isset(LinkService::SHAPES[$shape])) $shape = 'square';
        return ['fg' => $fg, 'bg' => $bg, 'shape' => $shape, 'transparent' => !empty($style['transparent'])]
             + QrDesign::normalizeExtras($style);
    }

    /** Varsayılan (siyah/beyaz/kare/opak) stil mi? Cache anahtarı eski dosyalarla uyumlu kalsın diye. */
    private static function isDefaultStyle(array $s): bool {
        return $s['fg'] === '#000000' && $s['bg'] === '#FFFFFF' && $s['shape'] === 'square' && !$s['transparent']
            && !QrDesign::hasExtras($s);
    }

    /** @return int[] [r,g,b] */
    private static function rgb(string $hex): array {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /** (r,c) modülü üç bulucu (finder) desenin 7x7 alanında mı? */
    public static function isFinder(int $r, int $c, int $size): bool {
        return ($r < 7 && $c < 7) || ($r < 7 && $c >= $size - 7) || ($r >= $size - 7 && $c < 7);
    }

    // ─── SVG ──────────────────────────────────────────────────────────────────

    /**
     * 2D matrisden SVG string üretir.
     * @param array  $matrix   buildMatrix() çıktısı
     * @param int    $margin   Quiet zone modül sayısı
     * @param string $logoPath Logo dosya yolu (null=logo yok)
     * @param array  $style    fg, bg, shape (square|rounded|dots), transparent
     */
    public static function toSvg(array $matrix, int $margin = 4, ?string $logoPath = null, array $style = []): string {
        $st    = self::normalizeStyle($style);
        $size  = count($matrix);
        $total = $size + $margin * 2;
        $cell  = 10; // viewBox birimi — SVG ölçeklenebilir olduğu için px değil
        $vbSize = $total * $cell;

        $fgEsc = htmlspecialchars($st['fg'], ENT_XML1);
        $bgEsc = htmlspecialchars($st['bg'], ENT_XML1);
        $render = ($st['shape'] === 'square' && $st['eye'] === 'square') ? 'crispEdges' : 'geometricPrecision';

        $svg  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg"'
              . ' xmlns:xlink="http://www.w3.org/1999/xlink"'
              . " viewBox=\"0 0 {$vbSize} {$vbSize}\""
              . " shape-rendering=\"{$render}\">\n";

        // Çerçeve: görsel büyür, QR (sessiz alanıyla birlikte) içine kaydırılarak çizilir
        $frame = QrFrame::enabled($st) ? QrFrame::layout($total, $st) : null;
        if ($frame) {
            $svg = str_replace("viewBox=\"0 0 {$vbSize} {$vbSize}\"",
                'viewBox="0 0 ' . self::num($frame['w'] * $cell) . ' ' . self::num($frame['h'] * $cell) . '"', $svg);
            $svg .= QrFrame::svg($frame, $cell, $bgEsc, $total)
                  . '<g transform="translate(' . self::num($frame['ox'] * $cell) . ',' . self::num($frame['oy'] * $cell) . ")\">\n";
        }

        $ink = $fgEsc;
        if ($st['gradient'] !== 'none') {
            $fg2 = htmlspecialchars($st['fg2'], ENT_XML1);
            $stops = "<stop offset=\"0\" stop-color=\"{$fgEsc}\"/><stop offset=\"1\" stop-color=\"{$fg2}\"/>";
            $a = $margin * $cell; $b = ($margin + $size) * $cell;
            $svg .= $st['gradient'] === 'radial'
                ? '<defs><radialGradient id="qrg" gradientUnits="userSpaceOnUse" cx="' . ($vbSize / 2) . '" cy="' . ($vbSize / 2)
                  . '" r="' . self::num($size * $cell * 0.7071) . "\">{$stops}</radialGradient></defs>\n"
                : "<defs><linearGradient id=\"qrg\" gradientUnits=\"userSpaceOnUse\" x1=\"{$a}\" y1=\"{$a}\" x2=\"{$b}\" y2=\"{$b}\">{$stops}</linearGradient></defs>\n";
            $ink = 'url(#qrg)';
        }

        if (!$st['transparent']) {
            $svg .= "<rect width=\"{$vbSize}\" height=\"{$vbSize}\" fill=\"{$bgEsc}\"/>\n";
        }

        // Modüller — tek path ile (performans)
        $plan = QrDesign::layout($matrix, $margin, $st);
        $pathD = '';
        foreach ($plan['modules'] as $p) $pathD .= self::svgPath($p, $cell);
        $svg .= "<path fill=\"{$ink}\" d=\"{$pathD}\"/>\n";

        // Gözler (köşe biçimi ya da rengi seçildiyse ayrı çizilir)
        if ($plan['eyes']) {
            $eyeFill = $st['eye_color'] ? htmlspecialchars($st['eye_color'], ENT_XML1) : $ink;
            $d = '';
            foreach ($plan['eyes'] as $e) {
                $d .= self::svgPath($e['ring'][0], $cell) . self::svgPath($e['ring'][1], $cell) . self::svgPath($e['dot'], $cell);
            }
            $svg .= "<path fill=\"{$eyeFill}\" fill-rule=\"evenodd\" d=\"{$d}\"/>\n";
        }

        // Logo (varsa)
        if ($logoPath !== null && file_exists($logoPath)) {
            $logoB64 = self::logoToBase64($logoPath);
            if ($logoB64 !== null) {
                [$logoMime, $logoData] = $logoB64;
                // Logo alanı: QR'ın %15'i (ECC H ile güvenli okunabilirlik)
                $logoSize = (int)round($vbSize * 0.15);
                $safePad  = $cell; // arka plan renginde çerçeve
                $logoX = (int)round(($vbSize - $logoSize) / 2);
                $logoY = $logoX;

                $safeX = $logoX - $safePad;
                $safeS = $logoSize + $safePad * 2;
                $safeFill = $st['transparent'] ? '#FFFFFF' : $bgEsc;
                $svg .= "<rect x=\"{$safeX}\" y=\"{$safeX}\" width=\"{$safeS}\" height=\"{$safeS}\" fill=\"{$safeFill}\" rx=\"{$safePad}\"/>\n";

                $svg .= "<image x=\"{$logoX}\" y=\"{$logoY}\" width=\"{$logoSize}\" height=\"{$logoSize}\""
                      . " preserveAspectRatio=\"xMidYMid meet\""
                      . " href=\"data:{$logoMime};base64,{$logoData}\"/>\n";
            }
        }

        if ($frame) $svg .= "</g>\n";
        $svg .= "</svg>\n";
        return $svg;
    }

    private static function num(float $v): string {
        return rtrim(rtrim(sprintf('%.2f', $v), '0'), '.') ?: '0';
    }

    /** QrDesign şekli → SVG yol komutu ($k: modül başına birim) */
    private static function svgPath(array $p, float $k): string {
        $n = fn(float $v) => self::num($v * $k);
        if ($p[0] === 'circle') {
            [, $cx, $cy, $r] = $p;
            return "M{$n($cx - $r)},{$n($cy)}a{$n($r)},{$n($r)} 0 1,0 {$n(2 * $r)},0a{$n($r)},{$n($r)} 0 1,0 -{$n(2 * $r)},0z";
        }
        [, $x, $y, $w, $h, $r] = $p;
        if ($r <= 0) return "M{$n($x)},{$n($y)}h{$n($w)}v{$n($h)}h-{$n($w)}z";
        $a = "a{$n($r)},{$n($r)} 0 0 1";
        return "M{$n($x + $r)},{$n($y)}h{$n($w - 2 * $r)}{$a} {$n($r)},{$n($r)}v{$n($h - 2 * $r)}{$a} -{$n($r)},{$n($r)}"
             . "h-{$n($w - 2 * $r)}{$a} -{$n($r)},-{$n($r)}v-{$n($h - 2 * $r)}{$a} {$n($r)},-{$n($r)}z";
    }

    // ─── PNG ──────────────────────────────────────────────────────────────────

    /**
     * 2D matrisden PNG binary verir. GD tercihli, Imagick fallback.
     * @throws RuntimeException GD ve Imagick ikisi de yoksa
     */
    public static function toPng(array $matrix, int $pxSize = 600, int $margin = 4, ?string $logoPath = null, array $style = []): string {
        $st      = self::normalizeStyle($style);
        $size    = count($matrix);
        $total   = $size + $margin * 2;
        $frame   = QrFrame::enabled($st) ? QrFrame::layout($total, $st) : null;
        // Çerçeveli görselde istenen genişlik tüm görselin genişliğidir
        $cell    = (int)max(1, round($pxSize / ($frame ? $frame['w'] : $total)));
        $imgSize = $total * $cell;

        if (extension_loaded('gd')) {
            $png = self::toPngGd($matrix, $size, $cell, $imgSize, $margin, $logoPath, $st);
            return $frame ? self::framePngGd($png, $frame, $cell, $total, $st) : $png;
        } elseif (extension_loaded('imagick')) {
            return self::toPngImagick($matrix, $size, $cell, $imgSize, $margin, $logoPath, $st);
        }
        throw new RuntimeException('GD veya Imagick PHP eklentisi bulunamadı. PNG üretilemedi.');
    }

    private static function toPngGd(array $matrix, int $size, int $cell, int $imgSize, int $margin, ?string $logoPath, array $st): string {
        // Şekilli modüllerde kenar yumuşatma için büyük çizip küçültürüz (bellek: 2000px → 4000px çizim)
        $smooth = $st['shape'] !== 'square' || $st['eye'] !== 'square';
        $ss = !$smooth ? 1 : ($imgSize <= 1200 ? 3 : ($imgSize <= 2100 ? 2 : 1));
        $cs = $cell * $ss;            // büyük çizimdeki hücre
        $big = $imgSize * $ss;

        $img = imagecreatetruecolor($big, $big);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        [$br, $bg, $bb] = self::rgb($st['bg']);
        $bgCol = $st['transparent'] ? imagecolorallocatealpha($img, $br, $bg, $bb, 127) : imagecolorallocate($img, $br, $bg, $bb);
        imagefilledrectangle($img, 0, 0, $big - 1, $big - 1, $bgCol);

        $px = fn(float $v) => (int)round($v * $cs);
        $draw = function (array $p, int $col) use ($img, $px, $cs) {
            if ($p[0] === 'circle') {
                $d = (int)round($p[3] * 2 * $cs);
                imagefilledellipse($img, $px($p[1]), $px($p[2]), $d, $d, $col);
                return;
            }
            [, $x, $y, $w, $h, $r] = $p;
            $x1 = $px($x); $y1 = $px($y); $x2 = $px($x + $w) - 1; $y2 = $px($y + $h) - 1;
            if ($r <= 0) imagefilledrectangle($img, $x1, $y1, $x2, $y2, $col);
            else self::gdRoundedRect($img, $x1, $y1, $x2, $y2, (int)round($r * $cs), $col);
        };

        $plan = QrDesign::layout($matrix, $margin, $st);
        foreach (QrDesign::colorGroups($plan, $st) as $g) {
            [$r, $gg, $b] = self::rgb($g['color']);
            $col = imagecolorallocate($img, $r, $gg, $b);
            foreach ($g['modules'] as $p) $draw($p, $col);
            foreach ($g['eyes'] as $e) {
                $draw($e['ring'][0], $col);
                $draw($e['ring'][1], $bgCol);   // halkanın içi zemin rengi (saydamsa saydam)
                $draw($e['dot'], $col);
            }
        }

        if ($ss > 1) {
            $small = imagecreatetruecolor($imgSize, $imgSize);
            imagealphablending($small, false);
            imagesavealpha($small, true);
            imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
            imagecopyresampled($small, $img, 0, 0, 0, 0, $imgSize, $imgSize, $big, $big);
            imagedestroy($img);
            $img = $small;
        }

        if ($logoPath && file_exists($logoPath)) {
            self::embedLogoGd($img, $imgSize, $logoPath, $st);
        }

        ob_start();
        imagepng($img, null, 6);
        $png = ob_get_clean();
        imagedestroy($img);
        return $png;
    }

    /** QR PNG'sini çerçeveli tuvale yerleştirir (çerçeve ve yazı kenar yumuşatmalı çizilir) */
    private static function framePngGd(string $qrPng, array $L, int $cell, int $total, array $st): string {
        $W = (int)round($L['w'] * $cell); $H = (int)round($L['h'] * $cell);
        $ss = $W <= 1200 ? 3 : ($W <= 2100 ? 2 : 1);
        $big = imagecreatetruecolor($W * $ss, $H * $ss);
        imagesavealpha($big, true);
        QrFrame::gd($big, $L, $cell * $ss, $st['bg'], $total, $st['transparent']);
        $img = imagecreatetruecolor($W, $H);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagecopyresampled($img, $big, 0, 0, 0, 0, $W, $H, $W * $ss, $H * $ss);
        imagedestroy($big);
        $qr = imagecreatefromstring($qrPng);
        imagealphablending($img, true);
        imagecopy($img, $qr, (int)round($L['ox'] * $cell), (int)round($L['oy'] * $cell), 0, 0, imagesx($qr), imagesy($qr));
        imagedestroy($qr);
        ob_start();
        imagepng($img, null, 6);
        $png = ob_get_clean();
        imagedestroy($img);
        return $png;
    }

    private static function gdRoundedRect(\GdImage $img, int $x1, int $y1, int $x2, int $y2, int $rad, int $col): void {
        $rad = max(0, min($rad, intdiv(min($x2 - $x1, $y2 - $y1), 2)));
        imagefilledrectangle($img, $x1 + $rad, $y1, $x2 - $rad, $y2, $col);
        imagefilledrectangle($img, $x1, $y1 + $rad, $x2, $y2 - $rad, $col);
        $d = $rad * 2;
        imagefilledellipse($img, $x1 + $rad, $y1 + $rad, $d, $d, $col);
        imagefilledellipse($img, $x2 - $rad, $y1 + $rad, $d, $d, $col);
        imagefilledellipse($img, $x1 + $rad, $y2 - $rad, $d, $d, $col);
        imagefilledellipse($img, $x2 - $rad, $y2 - $rad, $d, $d, $col);
    }

    private static function loadLogoGd(string $logoPath): \GdImage|false {
        $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        return match($ext) {
            'png'  => @imagecreatefrompng($logoPath),
            'jpg', 'jpeg' => @imagecreatefromjpeg($logoPath),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($logoPath) : false,
            default => false,
        };
    }

    private static function embedLogoGd(\GdImage $img, int $imgSize, string $logoPath, array $st): void {
        $logo = self::loadLogoGd($logoPath);
        if ($logo === false) return;

        $lw = imagesx($logo);
        $lh = imagesy($logo);
        $maxLogo = (int)($imgSize * 0.15);
        if ($lw > $lh) { $dstW = $maxLogo; $dstH = (int)round($lh * $maxLogo / $lw); }
        else           { $dstH = $maxLogo; $dstW = (int)round($lw * $maxLogo / $lh); }
        $safePad = (int)max(4, $imgSize * 0.015);
        $safeW = $dstW + $safePad * 2;
        $safeH = $dstH + $safePad * 2;
        $safeX = (int)(($imgSize - $safeW) / 2);
        $safeY = (int)(($imgSize - $safeH) / 2);

        // Logo çerçevesi: arka plan renginde (saydam PNG'de beyaz) opak alan
        [$r, $g, $b] = $st['transparent'] ? [255, 255, 255] : self::rgb($st['bg']);
        imagealphablending($img, false);
        imagefilledrectangle($img, $safeX, $safeY, $safeX + $safeW, $safeY + $safeH, imagecolorallocate($img, $r, $g, $b));

        imagealphablending($img, true);
        imagecopyresampled($img, $logo, $safeX + $safePad, $safeY + $safePad, 0, 0, $dstW, $dstH, $lw, $lh);
        imagedestroy($logo);
    }

    private static function toPngImagick(array $matrix, int $size, int $cell, int $imgSize, int $margin, ?string $logoPath, array $st): string {
        $imagick = new Imagick();
        $imagick->newImage($imgSize, $imgSize, new ImagickPixel($st['transparent'] ? 'transparent' : $st['bg']));
        $imagick->setImageFormat('png');
        $draw = new ImagickDraw();
        $prim = function (array $p) use ($draw, $cell) {
            if ($p[0] === 'circle') {
                $draw->circle($p[1] * $cell, $p[2] * $cell, ($p[1] + $p[3]) * $cell, $p[2] * $cell);
                return;
            }
            [, $x, $y, $w, $h, $r] = $p;
            if ($r <= 0) $draw->rectangle($x * $cell, $y * $cell, ($x + $w) * $cell - 1, ($y + $h) * $cell - 1);
            else $draw->roundRectangle($x * $cell, $y * $cell, ($x + $w) * $cell - 1, ($y + $h) * $cell - 1, $r * $cell, $r * $cell);
        };
        $plan = QrDesign::layout($matrix, $margin, $st);
        // Yedek yol (GD yoksa): göz halkasının içi zemin rengiyle (saydam zeminde beyaz) doldurulur
        $hole = $st['transparent'] ? '#FFFFFF' : $st['bg'];
        foreach (QrDesign::colorGroups($plan, $st) as $g) {
            $draw->setFillColor($g['color']);
            foreach ($g['modules'] as $p) $prim($p);
            foreach ($g['eyes'] as $e) {
                $prim($e['ring'][0]);
                $draw->setFillColor($hole); $prim($e['ring'][1]);
                $draw->setFillColor($g['color']); $prim($e['dot']);
            }
        }
        $imagick->drawImage($draw);

        if ($logoPath && file_exists($logoPath)) {
            $maxLogo = (int)($imgSize * 0.15);
            $safePad = max(4, (int)($imgSize * 0.015));
            $logoImg = new Imagick($logoPath);
            $lw = $logoImg->getImageWidth();
            $lh = $logoImg->getImageHeight();
            if ($lw > $lh) { $dstW = $maxLogo; $dstH = (int)round($lh * $maxLogo / $lw); }
            else           { $dstH = $maxLogo; $dstW = (int)round($lw * $maxLogo / $lh); }
            $logoImg->resizeImage($dstW, $dstH, Imagick::FILTER_LANCZOS, 1);
            $safeX = (int)(($imgSize - $dstW - $safePad * 2) / 2);
            $safeY = (int)(($imgSize - $dstH - $safePad * 2) / 2);
            $d2 = new ImagickDraw();
            $d2->setFillColor($st['transparent'] ? '#FFFFFF' : $st['bg']);
            $d2->rectangle($safeX, $safeY, $safeX + $dstW + $safePad * 2, $safeY + $dstH + $safePad * 2);
            $imagick->drawImage($d2);
            $imagick->compositeImage($logoImg, Imagick::COMPOSITE_OVER, $safeX + $safePad, $safeY + $safePad);
            $logoImg->destroy();
        }

        $png = $imagick->getImageBlob();
        $imagick->destroy();
        return $png;
    }

    /**
     * Logoyu düz JPEG'e çevirir (PDF gömme için). Saydam alan $bgHex ile doldurulur.
     * @return array{0:string,1:int,2:int}|null [jpeg verisi, genişlik, yükseklik]
     */
    public static function logoAsJpeg(string $logoPath, string $bgHex, int $maxPx = 600): ?array {
        if (!extension_loaded('gd')) return null;
        $logo = self::loadLogoGd($logoPath);
        if ($logo === false) return null;
        $lw = imagesx($logo); $lh = imagesy($logo);
        $scale = min(1, $maxPx / max($lw, $lh));
        $w = max(1, (int)round($lw * $scale)); $h = max(1, (int)round($lh * $scale));
        $dst = imagecreatetruecolor($w, $h);
        [$r, $g, $b] = self::rgb($bgHex);
        imagefill($dst, 0, 0, imagecolorallocate($dst, $r, $g, $b));
        imagecopyresampled($dst, $logo, 0, 0, 0, 0, $w, $h, $lw, $lh);
        ob_start();
        imagejpeg($dst, null, 92);
        $jpg = ob_get_clean();
        imagedestroy($logo); imagedestroy($dst);
        return [$jpg, $w, $h];
    }

    // ─── Cache ────────────────────────────────────────────────────────────────

    /**
     * Cache dosya yolunu hesaplar (yıl/ay yapısı).
     * Varsayılan stilde anahtar eski sürümle aynıdır (mevcut cache dosyaları geçerli kalır).
     */
    public static function cachePath(
        string $slug,
        string $type,   // 'svg' | 'png' | 'pdf' | 'eps' | 'ai'
        string $ecc,
        int    $size,
        int    $margin,
        string $logo,
        array  $style = []
    ): string {
        $st   = self::normalizeStyle($style);
        $key  = "{$ecc}|{$size}|{$margin}|{$logo}";
        if (!self::isDefaultStyle($st)) {
            $key .= '|' . $st['fg'] . '|' . $st['bg'] . '|' . $st['shape'] . '|' . ($st['transparent'] ? 't' : 'o');
            // Göz / geçiş seçenekleri yalnızca kullanıldığında anahtara girer (eski önbellek dosyaları geçerli kalır)
            if (QrDesign::hasExtras($st)) {
                $key .= '|' . $st['eye'] . '|' . ($st['eye_color'] ?? '') . '|' . $st['gradient'] . '|' . ($st['fg2'] ?? '');
                if (QrFrame::enabled($st)) $key .= '|' . $st['frame'] . '|' . ($st['frame_color'] ?? '') . '|' . ($st['frame_text'] ?? '');
            }
        }
        $hash = md5($key);
        $dir  = CACHE_DIR . '/' . date('Y') . '/' . date('m');
        return "{$dir}/{$slug}_{$hash}.{$type}";
    }

    /**
     * Önce cache'e bakar; varsa döndürür, yoksa üretir + kaydeder.
     * $size: PNG için piksel, PDF/EPS/AI için milimetre; SVG'de yok sayılır.
     *
     * @return array ['data'=>string, 'mime'=>string, 'cached'=>bool]
     */
    public static function getOrGenerate(
        string  $url,
        string  $slug,
        string  $type,      // 'svg' | 'png' | 'pdf' | 'eps' | 'ai'
        string  $ecc   = 'M',
        int     $size  = 600,
        int     $margin = 4,
        ?string $logoFile = null,  // logo/ altındaki dosya adı
        array   $style = []
    ): array {
        $logoPath = ($logoFile && $logoFile !== '') ? LOGO_DIR . '/' . basename($logoFile) : null;
        $logoKey  = $logoFile ?? '';
        $mime = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'pdf' => 'application/pdf',
                 'eps' => 'application/postscript', 'ai' => 'application/illustrator'][$type] ?? 'application/octet-stream';
        $st   = self::normalizeStyle($style);

        $cachePath = self::cachePath($slug, $type, $ecc, $size, $margin, $logoKey, $st);
        $cacheDir  = dirname($cachePath);
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

        if (file_exists($cachePath)) {
            return ['data' => file_get_contents($cachePath), 'mime' => $mime, 'cached' => true];
        }

        $matrix = self::buildMatrix($url, $ecc);
        $data = match($type) {
            'svg'   => self::toSvg($matrix, $margin, $logoPath, $st),
            'png'   => self::toPng($matrix, $size, $margin, $logoPath, $st),
            'pdf'   => QrPdf::render($matrix, $margin, $size, $logoPath, $st),
            'eps'   => QrEps::render($matrix, $margin, $size, $logoPath, $st, $slug),
            // AI: Illustrator'ın kendi PDF uyumlu biçimi; CMYK PDF, .ai uzantısıyla doğrudan açılır
            'ai'    => QrPdf::render($matrix, $margin, $size, $logoPath, $st, true),
            default => throw new InvalidArgumentException('Desteklenmeyen format: ' . $type),
        };

        // Cache'e yaz (hata üretimi engellemesin)
        try {
            file_put_contents($cachePath, $data);
        } catch (\Throwable $e) {
            Logger::warning('QR cache yazılamadı: ' . $e->getMessage());
        }

        return ['data' => $data, 'mime' => $mime, 'cached' => false];
    }

    /**
     * Belirli bir slug için üretilmiş tüm cache dosyalarını siler.
     */
    public static function clearCache(string $slug): void {
        $pattern = CACHE_DIR . '/*/*/' . $slug . '_*.{svg,png,pdf,eps,ai}';
        foreach (glob($pattern, GLOB_BRACE) ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Tüm QR önbelleğini temizler.
     * @return int Silinen dosya sayısı
     */
    public static function clearAllCache(): int {
        if (!is_dir(CACHE_DIR)) return 0;
        $count = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(CACHE_DIR, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                if ($item->isFile()) {
                    if (in_array(strtolower($item->getExtension()), ['svg', 'png', 'pdf', 'eps', 'ai', 'tmp'])) {
                        if (@unlink($item->getRealPath())) $count++;
                    }
                } elseif ($item->isDir()) {
                    @rmdir($item->getRealPath());
                }
            }
        } catch (\Throwable $e) {
            Logger::error('clearAllCache hatası: ' . $e->getMessage());
        }
        return $count;
    }

    // ─── Yardımcı ─────────────────────────────────────────────────────────────

    private static function logoToBase64(string $path): ?array {
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match($ext) {
            'png'  => 'image/png',
            'jpg','jpeg' => 'image/jpeg',
            'svg'  => 'image/svg+xml',
            'webp' => 'image/webp',
            default => null,
        };
        if ($mime === null) return null;
        $data = @file_get_contents($path);
        if ($data === false) return null;
        return [$mime, base64_encode($data)];
    }

    /**
     * Sunucudaki logo desteğini döndürür.
     * @return array ['gd'=>bool, 'imagick'=>bool, 'supported'=>bool]
     */
    public static function logoSupport(): array {
        $gd      = extension_loaded('gd');
        $imagick = extension_loaded('imagick');
        return ['gd' => $gd, 'imagick' => $imagick, 'supported' => $gd || $imagick];
    }
}
