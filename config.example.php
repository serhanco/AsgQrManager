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

// ─── Uygulama URL & Yol ──────────────────────────────────────────────────────
// Sonda / OLMADAN (Örn: https://example.com/qr veya http://localhost/QrManager)
define('BASE_URL',  'http://localhost/QrManager');
// Alt klasör yolu (Apache RewriteBase ile eşleşmeli, kök dizindeyse boş bırakın veya '/')
define('BASE_PATH', '/QrManager');
// Fiziksel kök (bu dosyanın bulunduğu klasör)
define('ROOT_DIR',  __DIR__);

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
