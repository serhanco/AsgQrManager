<?php
/**
 * log-download.php — Log dosyası indirme
 * Güvenlik: path traversal koruması, sadece giriş yapmış kullanıcı
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Logger.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/helpers.php';

Logger::registerHandlers();
Auth::startSession();
Auth::requireLogin();

$reqType = strtolower($_GET['type'] ?? 'scans');
if ($reqType === 'info') $reqType = 'app';
$type    = in_array($reqType, ['scans', 'app']) ? $reqType : 'scans';
$baseDir = $type === 'scans' ? LOG_SCAN_DIR : LOG_APP_DIR;

$year = preg_replace('/\D/', '', $_GET['year'] ?? '');
$mon  = preg_replace('/\D/', '', $_GET['mon']  ?? '');
$file = basename($_GET['file'] ?? '');

if (!$year || !$mon || !$file) {
    http_response_code(400);
    exit('Geçersiz istek.');
}

$relPath  = "{$year}/{$mon}/{$file}";
$fullPath = $baseDir . '/' . $relPath;
$real     = realpath($fullPath);
$baseReal = realpath($baseDir);

// Paylaşımlı hosting'de realpath symlink nedeniyle false dönebilir; normalize fallback
$normBase = rtrim(str_replace('\\', '/', $baseReal ?: $baseDir), '/');
$normFull = str_replace('\\', '/', $fullPath);
$safe = is_dir($normBase)
     && str_starts_with($normFull, $normBase . '/')
     && !str_contains($normFull, '/../')
     && !str_contains($normFull, '/./');

$resolved = $real ?: $fullPath;

if (!$safe || !is_file($resolved)) {
    http_response_code(403);
    exit('Erişim reddedildi.');
}

$ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
if ($ext !== 'txt') {
    http_response_code(403);
    exit('Yalnızca .txt dosyaları indirilebilir.');
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . basename($resolved) . '"');
header('Content-Length: ' . filesize($resolved));
readfile($resolved);
exit;
