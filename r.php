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
require_once __DIR__ . '/lib/Settings.php';
require_once __DIR__ . '/lib/LinkService.php';
require_once __DIR__ . '/lib/Redirector.php';

Logger::registerHandlers();

// Arama motorları kısa yönlendirme adreslerini indekslemesin
header('X-Robots-Tag: noindex, nofollow');

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
    $infoState = 'unknown';
    include __DIR__ . '/admin/views/info.php';
    exit;
}

// ─── Veritabanı sorgusu ───────────────────────────────────────────────────────
// SELECT * : migration henüz çalışmamış eski şemada da çalışır (yeni alanlar yoksa NULL sayılır)
try {
    $pdo  = getPdo();
    $stmt = $pdo->prepare('SELECT * FROM links WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $link = $stmt->fetch();
} catch (\Throwable $e) {
    Logger::error('Yönlendirme DB hatası: ' . $e->getMessage(), $e->getFile() . ':' . $e->getLine());
    http_response_code(503);
    echo 'Geçici hata oluştu.';
    exit;
}

if (!$link) {
    http_response_code(404);
    $infoState = 'unknown';
    include __DIR__ . '/admin/views/info.php';
    exit;
}

// ─── Hedefi belirle ───────────────────────────────────────────────────────────
// Aktif + zamanında ise hedef; pasif/arşiv/başlamamış/bitmiş ise yedek hedef ya da markalı bilgi sayfası
try {
    $decision = Redirector::resolve($link);
} catch (\Throwable $e) {
    // Beklenmedik bir hata olsa bile aktif linkin hedefi çalışmalı
    Logger::error('Redirector hatası: ' . $e->getMessage());
    $decision = !empty($link['is_active']) && empty($link['archived_at'])
        ? ['action' => 'redirect', 'url' => $link['target_url'], 'outcome' => 'ok', 'state' => 'live']
        : ['action' => 'notfound', 'url' => null, 'outcome' => 'dead', 'state' => 'inactive'];
}

// ─── Scan sayacı & loglama (best-effort) ─────────────────────────────────────
$isHead = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD';
try {
    $ua    = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $isBot = isBotUserAgent($ua);

    // Sayaç yalnızca gerçek ziyaretçinin normal okutmasında artar (bot önizlemesi/HEAD sayılmaz)
    if (!$isHead && !$isBot && $decision['outcome'] === 'ok'
        && defined('SCAN_COUNT_ENABLED') && SCAN_COUNT_ENABLED) {
        $pdo->prepare('UPDATE links SET scan_count = scan_count + 1 WHERE id = ?')
            ->execute([$link['id']]);
    }

    if (!$isHead && Settings::analyticsEnabled()) {
        $country = strtoupper($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '');
        if (!preg_match('/^[A-Z]{2}$/', $country) || in_array($country, ['XX', 'T1'], true)) $country = null;

        Logger::logScan(
            $pdo,
            (int)$link['id'],
            $slug,
            $decision['url'] ?? $link['target_url'],
            maskIp(getClientIp(), Settings::get('ip_mode')),
            $ua,
            $_SERVER['HTTP_REFERER'] ?? '',
            ['country' => $country, 'is_bot' => $isBot, 'outcome' => $decision['outcome']]
        );
    }
} catch (\Throwable $e) {
    // Loglama hatası yönlendirmeyi engellememeli
    Logger::warning('Scan log hatası: ' . $e->getMessage());
}

// ─── Yanıt ───────────────────────────────────────────────────────────────────
header('Cache-Control: no-store, no-cache, must-revalidate, proxy-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($decision['action'] === 'notfound') {
    // Yedek hedef yoksa çıplak 404 yerine markalı bilgi sayfası (durum kodu 404 kalır)
    http_response_code(404);
    $infoState = $decision['state'];
    include __DIR__ . '/admin/views/info.php';
    exit;
}

header('Location: ' . $decision['url'], true, 302);
exit;
