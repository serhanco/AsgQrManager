<?php
/**
 * helpers.php — Yardımcı fonksiyonlar
 */

/**
 * PDO bağlantısı döndürür (singleton).
 */
function getPdo(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    return $pdo;
}

/**
 * Base62 rastgele slug üretir.
 */
function generateSlug(int $length = 7): string {
    $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $result = '';
    for ($i = 0; $i < $length; $i++) {
        $result .= $chars[random_int(0, 61)];
    }
    return $result;
}

/**
 * Benzersiz slug üretir (DB'ye karşı kontrol eder).
 */
function generateUniqueSlug(PDO $pdo, int $length = 7, int $maxTry = 10): string {
    for ($i = 0; $i < $maxTry; $i++) {
        $slug = generateSlug($length);
        $stmt = $pdo->prepare('SELECT id FROM links WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        if (!$stmt->fetch()) return $slug;
    }
    // Son çare: daha uzun slug
    return generateSlug($length + 2);
}

/**
 * URL güvenlik kontrolü — sadece http:// veya https:// kabul eder.
 */
function isValidUrl(string $url): bool {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
    return in_array($scheme, ['http', 'https'], true);
}

/**
 * XSS koruması için escape.
 */
function e(mixed $val): string {
    return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Sayıyı insan okunabilir formata çevirir (1234 → 1.2K).
 */
function formatCount(int $n): string {
    if ($n >= 1_000_000) return round($n / 1_000_000, 1) . 'M';
    if ($n >= 1_000)     return round($n / 1_000, 1) . 'K';
    return (string)$n;
}

/**
 * İstemci IP adresini güvenle alır.
 */
function getClientIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

/**
 * Logo dizinindeki geçerli logo dosyalarını döndürür.
 * @return string[]  Dosya adları (logo/ altında)
 */
function listLogos(): array {
    $allowed = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
    $files   = [];
    if (!is_dir(LOGO_DIR)) return $files;
    foreach (scandir(LOGO_DIR) as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed, true)) {
            $files[] = $f;
        }
    }
    sort($files);
    return $files;
}

/**
 * Logo dosya adını güvenle doğrular (path traversal koruması).
 */
function validateLogoFile(?string $name): bool {
    if ($name === null || $name === '') return false;
    if (!preg_match('/^[a-zA-Z0-9_\-]+\.(png|jpg|jpeg|svg|webp)$/i', $name)) return false;
    $full = LOGO_DIR . '/' . $name;
    return file_exists($full);
}

/**
 * Özel slug girişini temizler ve doğrular.
 */
function sanitizeCustomSlug(string $slug): string {
    return preg_replace('/[^A-Za-z0-9_-]/', '', trim($slug));
}

/**
 * Config'deki ANALYTICS_ENABLED değerini dinamik olarak değiştirir (runtime + config.php).
 * NOT: config.php'yi yeniden yazar. Dikkatli kullanın.
 */
function toggleAnalytics(bool $enabled): bool {
    $configPath = ROOT_DIR . '/config.php';
    $content    = file_get_contents($configPath);
    $val        = $enabled ? 'true' : 'false';
    $content    = preg_replace(
        "/define\('ANALYTICS_ENABLED',\s*(true|false)\)/",
        "define('ANALYTICS_ENABLED', {$val})",
        $content
    );
    return file_put_contents($configPath, $content) !== false;
}

/**
 * Sayfalama için offset hesaplar.
 */
function paginate(int $total, int $perPage, int $page): array {
    $totalPages  = (int)ceil($total / $perPage);
    $currentPage = max(1, min($page, $totalPages));
    $offset      = ($currentPage - 1) * $perPage;
    return [
        'total'       => $total,
        'perPage'     => $perPage,
        'currentPage' => $currentPage,
        'totalPages'  => $totalPages,
        'offset'      => $offset,
    ];
}

/**
 * Dashboard için son 30 günün günlük tarama istatistiklerini çeker.
 * @return array  [['date'=>'YYYY-MM-DD', 'count'=>int], ...]
 */
function getDailyScanStats(PDO $pdo, int $days = 30): array {
    $stmt = $pdo->prepare(
        'SELECT DATE(scanned_at) AS `date`, COUNT(*) AS `count`
         FROM scans
         WHERE scanned_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         GROUP BY DATE(scanned_at)
         ORDER BY `date` ASC'
    );
    $stmt->execute([$days]);
    $rows = $stmt->fetchAll();

    // Boş günleri de doldur
    $map  = array_column($rows, 'count', 'date');
    $result = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $result[] = ['date' => $d, 'count' => (int)($map[$d] ?? 0)];
    }
    return $result;
}

/**
 * Dashboard özet istatistiklerini döndürür.
 */
function getDashboardStats(PDO $pdo): array {
    $stats = [];

    $row = $pdo->query('SELECT COUNT(*) AS total, SUM(is_active) AS active, SUM(scan_count) AS scans FROM links')->fetch();
    $stats['total_links']  = (int)($row['total']  ?? 0);
    $stats['active_links'] = (int)($row['active'] ?? 0);
    $stats['total_scans']  = (int)($row['scans']  ?? 0);
    $stats['inactive_links'] = $stats['total_links'] - $stats['active_links'];

    $row2 = $pdo->query('SELECT COUNT(*) AS today FROM scans WHERE DATE(scanned_at) = CURDATE()')->fetch();
    $stats['today_scans'] = (int)($row2['today'] ?? 0);

    return $stats;
}

/**
 * En çok taranan linkleri döndürür.
 */
function getTopLinks(PDO $pdo, int $limit = 10): array {
    $stmt = $pdo->prepare(
        'SELECT id, slug, title, target_url, scan_count, is_active FROM links ORDER BY scan_count DESC LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Yönlendirme: header + exit.
 */
function redirect(string $url, int $code = 302): never {
    header('Location: ' . $url, true, $code);
    exit;
}

/**
 * Flash mesajı yaz/oku.
 */
function flash(string $key, ?string $msg = null): ?string {
    if ($msg !== null) {
        $_SESSION['flash'][$key] = $msg;
        return null;
    }
    $val = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $val;
}
