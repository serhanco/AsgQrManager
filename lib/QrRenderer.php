<?php
/**
 * QrRenderer — SVG / PNG / PDF QR kod üretici + logo yerleştirme + marka stili
 */

require_once __DIR__ . '/phpqrcode.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/LinkService.php';
require_once __DIR__ . '/Redirector.php';
require_once __DIR__ . '/QrPdf.php';

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
        return ['fg' => $fg, 'bg' => $bg, 'shape' => $shape, 'transparent' => !empty($style['transparent'])];
    }

    /** Varsayılan (siyah/beyaz/kare/opak) stil mi? Cache anahtarı eski dosyalarla uyumlu kalsın diye. */
    private static function isDefaultStyle(array $s): bool {
        return $s['fg'] === '#000000' && $s['bg'] === '#FFFFFF' && $s['shape'] === 'square' && !$s['transparent'];
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
        $render = $st['shape'] === 'square' ? 'crispEdges' : 'geometricPrecision';

        $svg  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg"'
              . ' xmlns:xlink="http://www.w3.org/1999/xlink"'
              . " viewBox=\"0 0 {$vbSize} {$vbSize}\""
              . " shape-rendering=\"{$render}\">\n";

        if (!$st['transparent']) {
            $svg .= "<rect width=\"{$vbSize}\" height=\"{$vbSize}\" fill=\"{$bgEsc}\"/>\n";
        }

        // Modüller — tek path ile (performans)
        $pathD = '';
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($matrix[$r][$c] !== 1) continue;
                $x = ($c + $margin) * $cell;
                $y = ($r + $margin) * $cell;
                $shape = $st['shape'];
                // Bulucu desenler her zaman kare: okuyucuların kilitlendiği noktalar
                if ($shape !== 'square' && self::isFinder($r, $c, $size)) $shape = 'square';

                if ($shape === 'dots') {
                    $rad = 4.6;
                    $cx = $x + $cell / 2;
                    $cy = $y + $cell / 2;
                    $d2 = $rad * 2;
                    $pathD .= sprintf('M%s,%sa%s,%s 0 1,0 %s,0a%s,%s 0 1,0 -%s,0z',
                        $cx - $rad, $cy, $rad, $rad, $d2, $rad, $rad, $d2);
                } elseif ($shape === 'rounded') {
                    $rad = 3; $w = $cell - 2 * $rad;
                    $pathD .= sprintf('M%s,%sh%sa%s,%s 0 0 1 %s,%sv%sa%s,%s 0 0 1 -%s,%sh-%sa%s,%s 0 0 1 -%s,-%sv-%sa%s,%s 0 0 1 %s,-%sz',
                        $x + $rad, $y, $w, $rad, $rad, $rad, $rad, $w, $rad, $rad, $rad, $rad, $w, $rad, $rad, $rad, $rad, $w, $rad, $rad, $rad, $rad);
                } else {
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

        $svg .= "</svg>\n";
        return $svg;
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
        $cell    = (int)max(1, round($pxSize / $total));
        $imgSize = $total * $cell;

        if (extension_loaded('gd')) {
            return self::toPngGd($matrix, $size, $cell, $imgSize, $margin, $logoPath, $st);
        } elseif (extension_loaded('imagick')) {
            return self::toPngImagick($matrix, $size, $cell, $imgSize, $margin, $logoPath, $st);
        }
        throw new RuntimeException('GD veya Imagick PHP eklentisi bulunamadı. PNG üretilemedi.');
    }

    private static function toPngGd(array $matrix, int $size, int $cell, int $imgSize, int $margin, ?string $logoPath, array $st): string {
        // Şekilli modüllerde kenar yumuşatma için büyük çizip küçültürüz (bellek: 2000px → 4000px çizim)
        $ss = ($st["shape"] === "square") ? 1 : ($imgSize <= 1200 ? 3 : ($imgSize <= 2100 ? 2 : 1));
        $cs = $cell * $ss;            // büyük çizimdeki hücre
        $big = $imgSize * $ss;

        $img = imagecreatetruecolor($big, $big);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        [$fr, $fg, $fb] = self::rgb($st['fg']);
        [$br, $bg, $bb] = self::rgb($st['bg']);
        $bgCol = $st['transparent'] ? imagecolorallocatealpha($img, $br, $bg, $bb, 127) : imagecolorallocate($img, $br, $bg, $bb);
        $fgCol = imagecolorallocate($img, $fr, $fg, $fb);
        imagefilledrectangle($img, 0, 0, $big - 1, $big - 1, $bgCol);

        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($matrix[$r][$c] !== 1) continue;
                $x = ($c + $margin) * $cs;
                $y = ($r + $margin) * $cs;
                $shape = $st['shape'];
                if ($shape !== 'square' && self::isFinder($r, $c, $size)) $shape = 'square';

                if ($shape === 'dots') {
                    $d = (int)round($cs * 0.92);
                    imagefilledellipse($img, $x + intdiv($cs, 2), $y + intdiv($cs, 2), $d, $d, $fgCol);
                } elseif ($shape === 'rounded') {
                    self::gdRoundedRect($img, $x, $y, $x + $cs - 1, $y + $cs - 1, (int)round($cs * 0.3), $fgCol);
                } else {
                    imagefilledrectangle($img, $x, $y, $x + $cs - 1, $y + $cs - 1, $fgCol);
                }
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
        $draw->setFillColor($st['fg']);

        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($matrix[$r][$c] !== 1) continue;
                $x = ($c + $margin) * $cell;
                $y = ($r + $margin) * $cell;
                $shape = $st['shape'];
                if ($shape !== 'square' && self::isFinder($r, $c, $size)) $shape = 'square';
                if ($shape === 'dots') {
                    $draw->circle($x + $cell / 2, $y + $cell / 2, $x + $cell / 2 + $cell * 0.46, $y + $cell / 2);
                } elseif ($shape === 'rounded') {
                    $draw->roundRectangle($x, $y, $x + $cell - 1, $y + $cell - 1, $cell * 0.3, $cell * 0.3);
                } else {
                    $draw->rectangle($x, $y, $x + $cell - 1, $y + $cell - 1);
                }
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
        string $type,   // 'svg' | 'png' | 'pdf'
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
        }
        $hash = md5($key);
        $dir  = CACHE_DIR . '/' . date('Y') . '/' . date('m');
        return "{$dir}/{$slug}_{$hash}.{$type}";
    }

    /**
     * Önce cache'e bakar; varsa döndürür, yoksa üretir + kaydeder.
     * $size: PNG için piksel, PDF için milimetre; SVG'de yok sayılır.
     *
     * @return array ['data'=>string, 'mime'=>string, 'cached'=>bool]
     */
    public static function getOrGenerate(
        string  $url,
        string  $slug,
        string  $type,      // 'svg' | 'png' | 'pdf'
        string  $ecc   = 'M',
        int     $size  = 600,
        int     $margin = 4,
        ?string $logoFile = null,  // logo/ altındaki dosya adı
        array   $style = []
    ): array {
        $logoPath = ($logoFile && $logoFile !== '') ? LOGO_DIR . '/' . basename($logoFile) : null;
        $logoKey  = $logoFile ?? '';
        $mime = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'pdf' => 'application/pdf'][$type] ?? 'application/octet-stream';
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
        $pattern = CACHE_DIR . '/*/*/' . $slug . '_*.{svg,png,pdf}';
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
                    if (in_array(strtolower($item->getExtension()), ['svg', 'png', 'pdf', 'tmp'])) {
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
