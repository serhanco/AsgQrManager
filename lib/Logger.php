<?php
/**
 * Logger — Uygulama ve tarama log yöneticisi
 *
 * Uygulama logları:  logs/app/{YYYY}/{MM}/app-{YYYY-MM-DD}.txt
 * Tarama logları:    logs/scans/{YYYY}/{MM}/scans-{YYYY-MM-DD}.txt
 */

class Logger {

    const LEVELS = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3];

    private static bool $handlersRegistered = false;

    // ─── Global hata yakalama ─────────────────────────────────────────────────

    public static function registerHandlers(): void {
        if (self::$handlersRegistered) return;
        self::$handlersRegistered = true;

        set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
            if (!(error_reporting() & $errno)) return false;
            $level = match(true) {
                in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR]) => 'ERROR',
                in_array($errno, [E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING]) => 'WARNING',
                default => 'DEBUG',
            };
            self::log($level, "[PHP#{$errno}] {$errstr}", "{$errfile}:{$errline}");
            return false; // PHP'nin kendi işlemesine de izin ver
        });

        set_exception_handler(function(\Throwable $e): void {
            self::log('ERROR', get_class($e) . ': ' . $e->getMessage(), $e->getFile() . ':' . $e->getLine(), $e->getTraceAsString());
            if (!headers_sent()) {
                http_response_code(500);
            }
            include ROOT_DIR . '/admin/views/error.php';
            exit(1);
        });

        register_shutdown_function(function(): void {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                self::log('ERROR', "[FATAL] {$err['message']}", "{$err['file']}:{$err['line']}");
            }
        });
    }

    // ─── Seviye yardımcıları ──────────────────────────────────────────────────

    public static function debug(string $message, string $context = ''): void   { self::log('DEBUG',   $message, $context); }
    public static function info(string $message, string $context = ''): void    { self::log('INFO',    $message, $context); }
    public static function warning(string $message, string $context = ''): void { self::log('WARNING', $message, $context); }
    public static function error(string $message, string $context = ''): void   { self::log('ERROR',   $message, $context); }

    // ─── Çekirdek log yazıcı ─────────────────────────────────────────────────

    public static function log(string $level, string $message, string $context = '', string $trace = ''): void {
        $configLevel = defined('LOG_LEVEL') ? LOG_LEVEL : 'WARNING';
        if ((self::LEVELS[$level] ?? 0) < (self::LEVELS[$configLevel] ?? 0)) return;

        $now    = date('Y-m-d H:i:s');
        $yyyy   = date('Y');
        $mm     = date('m');
        $today  = date('Y-m-d');
        $dir    = (defined('LOG_APP_DIR') ? LOG_APP_DIR : sys_get_temp_dir()) . "/{$yyyy}/{$mm}";
        $file   = "{$dir}/app-{$today}.txt";

        $line = "[{$now}] [{$level}] {$message}";
        if ($context !== '') $line .= " | {$context}";
        if ($trace !== '')   $line .= "\n" . $trace;
        $line .= "\n";

        self::writeToFile($file, $dir, $line);
    }

    // ─── Tarama logu ─────────────────────────────────────────────────────────

    /**
     * Tarama kaydı hem DB'ye hem dosyaya yazar.
     * ANALYTICS_ENABLED false ise hiçbiri yazılmaz.
     * SCAN_COUNT_ENABLED kontrolü caller tarafında yapılmalı.
     */
    public static function logScan(
        \PDO $pdo,
        int  $linkId,
        string $slug,
        string $targetUrl,
        string $ip,
        string $userAgent,
        string $referer
    ): void {
        if (!defined('ANALYTICS_ENABLED') || !ANALYTICS_ENABLED) return;

        $now   = date('Y-m-d H:i:s');
        $yyyy  = date('Y');
        $mm    = date('m');
        $today = date('Y-m-d');
        $dir   = (defined('LOG_SCAN_DIR') ? LOG_SCAN_DIR : sys_get_temp_dir()) . "/{$yyyy}/{$mm}";
        $file  = "{$dir}/scans-{$today}.txt";

        // DB'ye yaz
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO scans (link_id, scanned_at, ip, user_agent, referer) VALUES (?,?,?,?,?)'
            );
            $stmt->execute([$linkId, $now, $ip, substr($userAgent, 0, 512), substr($referer, 0, 512)]);
        } catch (\Throwable $e) {
            self::warning('Scan DB yazma hatası: ' . $e->getMessage());
        }

        // Dosyaya yaz
        $ipSafe = htmlspecialchars($ip, ENT_NOQUOTES);
        $line = implode("\t", [$now, $slug, $targetUrl, $ip, substr($userAgent, 0, 200), substr($referer, 0, 200)]) . "\n";
        self::writeToFile($file, $dir, $line);
    }

    // ─── Dosya yazdırma (klasör oluşturur) ────────────────────────────────────

    private static function writeToFile(string $file, string $dir, string $content): void {
        try {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($file, $content, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Log sistemi kendisi fail etmemeli; sessizce geç
        }
    }

    // ─── Log dosyalarını listele ──────────────────────────────────────────────

    /**
     * Belirli bir log kök dizini altındaki dosyaları yıl/ay/gün yapısıyla döndürür.
     * @param string $baseDir  LOG_SCAN_DIR veya LOG_APP_DIR
     * @return array  ['YYYY' => ['MM' => ['dosya_adi.txt', ...]]]
     */
    public static function listLogFiles(string $baseDir): array {
        $result = [];
        if (!is_dir($baseDir)) return $result;

        $years = glob($baseDir . '/[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR) ?: [];
        rsort($years);
        foreach ($years as $yearDir) {
            $y    = basename($yearDir);
            $mons = glob($yearDir . '/[0-9][0-9]', GLOB_ONLYDIR) ?: [];
            rsort($mons);
            foreach ($mons as $monDir) {
                $m     = basename($monDir);
                $files = glob($monDir . '/*.txt') ?: [];
                rsort($files);
                foreach ($files as $f) {
                    $result[$y][$m][] = basename($f);
                }
            }
        }
        return $result;
    }

    /**
     * Log dosyasını güvenle okur (path traversal koruması).
     * @param string $baseDir  İzin verilen kök dizin
     * @param string $relPath  YYYY/MM/dosya.txt şeklinde relative yol
     * @param int    $maxBytes Son kaç byte okunacak
     * @return string|null
     */
    public static function readLogFile(string $baseDir, string $relPath, int $maxBytes = 524288): ?string {
        // Sadece alfanümerik, tire, nokta, eğik çizgi
        if (!preg_match('#^[0-9]{4}/[0-9]{2}/[a-zA-Z0-9_\-]+\.txt$#', $relPath)) {
            return null;
        }
        $fullPath = $baseDir . '/' . $relPath;
        $real     = realpath($fullPath);
        $baseReal = realpath($baseDir);
        if ($real === false || $baseReal === false) return null;
        if (strncmp($real, $baseReal, strlen($baseReal)) !== 0) return null;
        if (!is_file($real) || !is_readable($real)) return null;

        $size = filesize($real);
        if ($size <= $maxBytes) {
            return file_get_contents($real);
        }
        // Büyük dosya: son $maxBytes byte
        $fh = fopen($real, 'rb');
        fseek($fh, -$maxBytes, SEEK_END);
        $data = fread($fh, $maxBytes);
        fclose($fh);
        // İlk satırı at (kırık olabilir)
        $nl = strpos($data, "\n");
        return ($nl !== false) ? substr($data, $nl + 1) : $data;
    }
}
