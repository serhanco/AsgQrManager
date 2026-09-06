<?php
/**
 * QR Manager — Örnek Konfigürasyon Dosyası
 * Bu dosyayı config.php olarak kopyalayıp kendi ayarlarınızı yapın.
 */

// ─── Veritabanı ───────────────────────────────────────────────────────────────
define('DB_HOST',     'localhost');
define('DB_PORT',     3306);
define('DB_NAME',     'qrmanager');
define('DB_USER',     'root');
define('DB_PASS',     '');
define('DB_CHARSET',  'utf8mb4');

// Fiziksel kök (bu dosyanın bulunduğu klasör)
define('ROOT_DIR',  __DIR__);

// ─── Otomatik URL ve Klasör Algılama ─────────────────────────────────────────
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443 || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = '';

// Script filename ve SCRIPT_NAME ilişkisinden alt klasörü bul
$scriptFilename = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
$scriptName     = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$rootDir        = str_replace('\\', '/', ROOT_DIR);

if ($scriptFilename && $scriptName && str_starts_with($scriptFilename, $rootDir)) {
    $subFromRoot = substr(dirname($scriptFilename), strlen($rootDir)); // örn: /admin veya boş
    $scriptDir = dirname($scriptName);
    if ($subFromRoot !== '' && str_ends_with($scriptDir, $subFromRoot)) {
        $basePath = substr($scriptDir, 0, -strlen($subFromRoot));
    } else {
        $basePath = $scriptDir;
    }
} elseif (!empty($_SERVER['DOCUMENT_ROOT'])) {
    $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']), '/');
    if (str_starts_with($rootDir, $docRoot)) {
        $basePath = substr($rootDir, strlen($docRoot));
    }
}

$basePath = '/' . trim(str_replace('\\', '/', $basePath), '/');
if ($basePath === '/') $basePath = '';
$autoBaseUrl = $scheme . '://' . $host . $basePath;

// ─── Uygulama URL & Yol ──────────────────────────────────────────────────────
// Manuel belirlemek isterseniz 'https://domain.com/qr' şeklinde yazın.
// Boş (veya null) bırakılırsa bulunduğu dizine göre OTOMATİK çalışır.
define('CUSTOM_BASE_URL', null); 

define('BASE_URL',  CUSTOM_BASE_URL ?: $autoBaseUrl);
define('BASE_PATH', CUSTOM_BASE_URL ? parse_url(CUSTOM_BASE_URL, PHP_URL_PATH) ?? '' : $basePath);

// ─── Klasörler (fiziksel yol) ─────────────────────────────────────────────────
define('CACHE_DIR',    ROOT_DIR . '/cache/qr');
define('LOG_SCAN_DIR', ROOT_DIR . '/logs/scans');
define('LOG_APP_DIR',  ROOT_DIR . '/logs/app');
define('LOGO_DIR',     ROOT_DIR . '/logo');

// ─── Özellik Bayrakları ───────────────────────────────────────────────────────
// true → tarama DB + dosyaya loglanır; false → hiçbir şey yazılmaz
define('ANALYTICS_ENABLED',   true);
// true → scan_count artar; false → artmaz
define('SCAN_COUNT_ENABLED',  true);

// ─── Loglama ─────────────────────────────────────────────────────────────────
// DEBUG | INFO | WARNING | ERROR
define('LOG_LEVEL', 'DEBUG');

// ─── QR Varsayılanları ────────────────────────────────────────────────────────
define('QR_DEFAULT_ECC',    'M');   // L / M / Q / H
define('QR_DEFAULT_SIZE',   600);   // px (PNG)
define('QR_DEFAULT_MARGIN', 4);     // modül (quiet zone)

// ─── Güvenlik ─────────────────────────────────────────────────────────────────
define('SESSION_NAME',        'qrm_session');
define('CSRF_TOKEN_NAME',     '_csrf');
define('BRUTE_MAX_ATTEMPTS',  5);
define('BRUTE_LOCKOUT_SEC',   300);  // 5 dakika

// ─── Sayfalama ────────────────────────────────────────────────────────────────
define('LINKS_PER_PAGE', 20);

// ─── Slug ─────────────────────────────────────────────────────────────────────
define('SLUG_LENGTH', 7);
