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

$type    = in_array($_GET['type'] ?? 'scans', ['scans', 'app']) ? $_GET['type'] : 'scans';
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

if (!$real || !$baseReal || !str_starts_with($real, $baseReal) || !is_file($real)) {
    http_response_code(403);
    exit('Erişim reddedildi.');
}

$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
if ($ext !== 'txt') {
    http_response_code(403);
    exit('Yalnızca .txt dosyaları indirilebilir.');
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . basename($real) . '"');
header('Content-Length: ' . filesize($real));
readfile($real);
exit;
