<?php
/**
 * r.php — QR Yönlendirme Uç Noktası
 *
 * Apache mod_rewrite: /qr/r/{slug} → bu dosya
 * Fallback:           /qr/r.php?c={slug}
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Logger.php';
require_once __DIR__ . '/lib/helpers.php';

Logger::registerHandlers();

// ─── Slug'ı al ────────────────────────────────────────────────────────────────
// PATH_INFO: /r/AbC1234  →  /AbC1234
$slug = '';
if (!empty($_GET['c'])) {
    $slug = $_GET['c'];
} elseif (!empty($_SERVER['PATH_INFO'])) {
    $slug = ltrim($_SERVER['PATH_INFO'], '/');
} elseif (!empty($_SERVER['REQUEST_URI'])) {
    // /qr/r/AbC1234 şeklinden çıkar
    $uri   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $parts = explode('/', trim($uri, '/'));
    $slug  = end($parts);
}

// Güvenlik: sadece base62 karakterleri
$slug = preg_replace('/[^A-Za-z0-9_-]/', '', $slug);

if ($slug === '') {
    http_response_code(404);
    include __DIR__ . '/admin/views/404.php';
    exit;
}

// ─── Veritabanı sorgusu ───────────────────────────────────────────────────────
try {
    $pdo  = getPdo();
    $stmt = $pdo->prepare('SELECT id, target_url, is_active, scan_count FROM links WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $link = $stmt->fetch();
} catch (\Throwable $e) {
    Logger::error('Yönlendirme DB hatası: ' . $e->getMessage(), $e->getFile() . ':' . $e->getLine());
    http_response_code(503);
    echo 'Geçici hata oluştu.';
    exit;
}

if (!$link || !$link['is_active']) {
    http_response_code(404);
    include __DIR__ . '/admin/views/404.php';
    exit;
}

// ─── Scan sayacı & loglama (best-effort) ─────────────────────────────────────
try {
    if (defined('SCAN_COUNT_ENABLED') && SCAN_COUNT_ENABLED) {
        $pdo->prepare('UPDATE links SET scan_count = scan_count + 1 WHERE id = ?')
            ->execute([$link['id']]);
    }

    if (defined('ANALYTICS_ENABLED') && ANALYTICS_ENABLED) {
        Logger::logScan(
            $pdo,
            (int)$link['id'],
            $slug,
            $link['target_url'],
            getClientIp(),
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_REFERER']    ?? ''
        );
    }
} catch (\Throwable $e) {
    // Loglama hatası yönlendirmeyi engellememeli
    Logger::warning('Scan log hatası: ' . $e->getMessage());
}

// ─── 302 Yönlendirme ─────────────────────────────────────────────────────────
header('Cache-Control: no-store, no-cache, must-revalidate, proxy-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Location: ' . $link['target_url'], true, 302);
exit;
