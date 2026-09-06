<?php
/**
 * QrRenderer — SVG ve PNG QR kod üretici + logo yerleştirme
 */

require_once __DIR__ . '/phpqrcode.php';
require_once __DIR__ . '/Logger.php';

class QrRenderer {

    /**
     * Verilen URL için QR matrisini encode eder.
     */
    public static function buildMatrix(string $url, string $ecc = 'M'): array {
        $qr = new QRcode();
        return $qr->encode($url, strtoupper($ecc));
    }

    // ─── SVG ──────────────────────────────────────────────────────────────────

    /**
     * 2D matrisden SVG string üretir.
     * @param array  $matrix   QRcode->encode() çıktısı
     * @param int    $margin   Quiet zone modül sayısı
     * @param string $logoPath Logo dosya yolu (null=logo yok)
     * @param string $fgColor  Modül rengi
     * @param string $bgColor  Arka plan rengi
     */
    public static function toSvg(
        array  $matrix,
        int    $margin   = 4,
        ?string $logoPath = null,
        string  $fgColor  = '#000000',
        string  $bgColor  = '#ffffff'
    ): string {
        $size = count($matrix);
        $total = $size + $margin * 2;
        $cell  = 10; // viewBox birimi — SVG ölçeklenebilir olduğu için px değil

        $vbSize = $total * $cell;

        $svg  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg"'
              . ' xmlns:xlink="http://www.w3.org/1999/xlink"'
              . " viewBox=\"0 0 {$vbSize} {$vbSize}\""
              . ' shape-rendering="crispEdges">' . "\n";

        // Arka plan
        $fgEsc = htmlspecialchars($fgColor, ENT_XML1);
        $bgEsc = htmlspecialchars($bgColor, ENT_XML1);
        $svg .= "<rect width=\"{$vbSize}\" height=\"{$vbSize}\" fill=\"{$bgEsc}\"/>\n";

        // Modüller — tek path ile (performans)
        $pathD = '';
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($matrix[$r][$c] === 1) {
                    $x = ($c + $margin) * $cell;
                    $y = ($r + $margin) * $cell;
                    $pathD .= "M{$x},{$y}h{$cell}v{$cell}h-{$cell}z";
                }
            }
        }
        $svg .= "<path fill=\"{$fgEsc}\" d=\"{$pathD}\"/>\n";

        // Logo (varsa)
        if ($logoPath !== null && file_exists($logoPath)) {
            $logoB64 = self::logoToBase64($logoPath);
            if ($logoB64 !== null) {
                [$logoMime, $logoData] = $logoB64;
                // Logo alanı: QR'ın %25'i (HEC H ile okunabilir)
                $logoAreaPct = 0.25;
                $logoSize    = (int)round($vbSize * $logoAreaPct);
                $safePad     = $cell; // beyaz çerçeve
                $logoX = (int)round(($vbSize - $logoSize) / 2);
                $logoY = (int)round(($vbSize - $logoSize) / 2);

                // Beyaz safe area
                $safeX = $logoX - $safePad;
                $safeY = $logoY - $safePad;
                $safeS = $logoSize + $safePad * 2;
                $svg .= "<rect x=\"{$safeX}\" y=\"{$safeY}\" width=\"{$safeS}\" height=\"{$safeS}\" fill=\"{$bgEsc}\" rx=\"{$safePad}\"/>\n";

                $svg .= "<image x=\"{$logoX}\" y=\"{$logoY}\" width=\"{$logoSize}\" height=\"{$logoSize}\""
                      . " preserveAspectRatio=\"xMidYMid meet\""
                      . " href=\"data:{$logoMime};base64,{$logoData}\"/>\n";
            }
        }

        $svg .= "</svg>\n";
        return $svg;
    }

    // ─── PNG ──────────────────────────────────────────────────────────────────

    /**
     * 2D matrisden PNG binary verir.
     * GD tercihli, Imagick fallback.
     *
     * @return string  Raw PNG binary
     * @throws RuntimeException GD ve Imagick ikisi de yoksa
     */
    public static function toPng(
        array  $matrix,
        int    $pxSize   = 600,
        int    $margin   = 4,
        ?string $logoPath = null
    ): string {
        $size  = count($matrix);
        $total = $size + $margin * 2;
        $cell  = (int)max(1, round($pxSize / $total));
        $imgSize = $total * $cell;

        if (extension_loaded('gd')) {
            return self::toPngGd($matrix, $size, $total, $cell, $imgSize, $margin, $logoPath);
        } elseif (extension_loaded('imagick')) {
            return self::toPngImagick($matrix, $size, $total, $cell, $imgSize, $margin, $logoPath);
        } else {
            throw new RuntimeException('GD veya Imagick PHP eklentisi bulunamadı. PNG üretilemedi.');
        }
    }

    private static function toPngGd(
        array $matrix, int $size, int $total, int $cell, int $imgSize,
        int $margin, ?string $logoPath
    ): string {
        $img = imagecreatetruecolor($imgSize, $imgSize);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $white);

        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($matrix[$r][$c] === 1) {
                    $x = ($c + $margin) * $cell;
                    $y = ($r + $margin) * $cell;
                    imagefilledrectangle($img, $x, $y, $x + $cell - 1, $y + $cell - 1, $black);
                }
            }
        }

        // Logo yerleştir
        if ($logoPath && file_exists($logoPath)) {
            self::embedLogoGd($img, $imgSize, $logoPath);
        }

        ob_start();
        imagepng($img, null, 6); // sıkıştırma 6
        $png = ob_get_clean();
        imagedestroy($img);
        return $png;
    }

    private static function embedLogoGd(\GdImage $img, int $imgSize, string $logoPath): void {
        $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $logo = match($ext) {
            'png'  => @imagecreatefrompng($logoPath),
            'jpg', 'jpeg' => @imagecreatefromjpeg($logoPath),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($logoPath) : false,
            default => false,
        };
        if ($logo === false) return;

        $lw = imagesx($logo);
        $lh = imagesy($logo);
        $maxLogo = (int)($imgSize * 0.25);
        // Oranı koru
        if ($lw > $lh) {
            $dstW = $maxLogo;
            $dstH = (int)round($lh * $maxLogo / $lw);
        } else {
            $dstH = $maxLogo;
            $dstW = (int)round($lw * $maxLogo / $lh);
        }
        $safePad = (int)max(4, $imgSize * 0.015);
        $safeW = $dstW + $safePad * 2;
        $safeH = $dstH + $safePad * 2;
        $safeX = (int)(($imgSize - $safeW) / 2);
        $safeY = (int)(($imgSize - $safeH) / 2);

        // Beyaz safe area
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, $safeX, $safeY, $safeX + $safeW, $safeY + $safeH, $white);

        // Logo kopyala
        $logoX = $safeX + $safePad;
        $logoY = $safeY + $safePad;
        imagecopyresampled($img, $logo, $logoX, $logoY, 0, 0, $dstW, $dstH, $lw, $lh);
        imagedestroy($logo);
    }

    private static function toPngImagick(
        array $matrix, int $size, int $total, int $cell, int $imgSize,
        int $margin, ?string $logoPath
    ): string {
        $imagick = new Imagick();
        $imagick->newImage($imgSize, $imgSize, new ImagickPixel('white'));
        $imagick->setImageFormat('png');
        $draw = new ImagickDraw();
        $draw->setFillColor('black');

        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($matrix[$r][$c] === 1) {
                    $x = ($c + $margin) * $cell;
                    $y = ($r + $margin) * $cell;
                    $draw->rectangle($x, $y, $x + $cell - 1, $y + $cell - 1);
                }
            }
        }
        $imagick->drawImage($draw);

        // Logo
        if ($logoPath && file_exists($logoPath)) {
            $maxLogo = (int)($imgSize * 0.25);
            $safePad = max(4, (int)($imgSize * 0.015));
            $logoImg = new Imagick($logoPath);
            $lw = $logoImg->getImageWidth();
            $lh = $logoImg->getImageHeight();
            if ($lw > $lh) {
                $dstW = $maxLogo; $dstH = (int)round($lh * $maxLogo / $lw);
            } else {
                $dstH = $maxLogo; $dstW = (int)round($lw * $maxLogo / $lh);
            }
            $logoImg->resizeImage($dstW, $dstH, Imagick::FILTER_LANCZOS, 1);
            $safeX = (int)(($imgSize - $dstW - $safePad*2) / 2);
            $safeY = (int)(($imgSize - $dstH - $safePad*2) / 2);
            $d2 = new ImagickDraw();
            $d2->setFillColor('white');
            $d2->rectangle($safeX, $safeY, $safeX + $dstW + $safePad*2, $safeY + $dstH + $safePad*2);
            $imagick->drawImage($d2);
            $imagick->compositeImage($logoImg, Imagick::COMPOSITE_OVER, $safeX + $safePad, $safeY + $safePad);
            $logoImg->destroy();
        }

        $png = $imagick->getImageBlob();
        $imagick->destroy();
        return $png;
    }

    // ─── Cache ────────────────────────────────────────────────────────────────

    /**
     * Cache dosya yolunu hesaplar (yıl/ay yapısı).
     */
    public static function cachePath(
        string $slug,
        string $type,   // 'svg' | 'png'
        string $ecc,
        int    $size,
        int    $margin,
        string $logo
    ): string {
        $hash = md5("{$ecc}|{$size}|{$margin}|{$logo}");
        $yyyy = date('Y');
        $mm   = date('m');
        $dir  = CACHE_DIR . "/{$yyyy}/{$mm}";
        return "{$dir}/{$slug}_{$hash}.{$type}";
    }

    /**
     * Önce cache'e bakar; varsa döndürür, yoksa üretir + kaydeder.
     *
     * @return array ['data'=>string, 'mime'=>string, 'cached'=>bool]
     */
    public static function getOrGenerate(
        string  $url,
        string  $slug,
        string  $type,      // 'svg' | 'png'
        string  $ecc   = 'M',
        int     $size  = 600,
        int     $margin = 4,
        ?string $logoFile = null  // logo/ altındaki dosya adı
    ): array {
        $logoPath = ($logoFile && $logoFile !== '') ? LOGO_DIR . '/' . basename($logoFile) : null;
        $logoKey  = $logoFile ?? '';

        $cachePath = self::cachePath($slug, $type, $ecc, $size, $margin, $logoKey);
        $cacheDir  = dirname($cachePath);

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        if (file_exists($cachePath)) {
            return [
                'data'   => file_get_contents($cachePath),
                'mime'   => $type === 'svg' ? 'image/svg+xml' : 'image/png',
                'cached' => true,
            ];
        }

        // Üret
        $matrix = self::buildMatrix($url, $ecc);
        if ($type === 'svg') {
            $data = self::toSvg($matrix, $margin, $logoPath);
            $mime = 'image/svg+xml';
        } else {
            $data = self::toPng($matrix, $size, $margin, $logoPath);
            $mime = 'image/png';
        }

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
        // cache/qr altındaki tüm yıl/ay klasörlerine bak
        $pattern = CACHE_DIR . '/*/*/' . $slug . '_*.{svg,png}';
        foreach (glob($pattern, GLOB_BRACE) as $file) {
            @unlink($file);
        }
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
