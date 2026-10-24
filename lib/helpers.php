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
    // Proxy/CDN arkasında değilseniz config.php'ye define('TRUST_PROXY_HEADERS', false) ekleyin;
    // aksi halde istemci bu başlıkları sahte gönderip IP kaydını yanıltabilir.
    $trust = !defined('TRUST_PROXY_HEADERS') || TRUST_PROXY_HEADERS;
    $keys  = $trust ? ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] : ['REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

/**
 * KVKK: IP adresini ayara göre maskeler.
 * full = olduğu gibi · mask = IPv4 son bayt / IPv6 son 80 bit sıfır · none = saklama
 */
function maskIp(string $ip, string $mode): ?string {
    if ($mode === 'none') return null;
    if ($mode !== 'mask') return $ip;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $p = explode('.', $ip);
        return $p[0] . '.' . $p[1] . '.' . $p[2] . '.0';
    }
    $bin = @inet_pton($ip);
    if ($bin === false) return null;
    return inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10)) ?: null;
}

/**
 * Link önizleme botları, tarayıcı botları ve boş User-Agent okutma sayılmaz.
 */
function isBotUserAgent(string $ua): bool {
    if (trim($ua) === '') return true;
    // "bot" kelimesi tek başına aranmaz: bazı telefon markaları (ör. CUBOT) gerçek kullanıcıdır.
    return (bool)preg_match(
        '/bot\\/|googlebot|bingbot|yandexbot|baiduspider|duckduckbot|applebot|ahrefsbot|semrushbot|petalbot|bytespider|'
        . 'crawl|spider|slurp|facebookexternalhit|facebot|whatsapp\\/|telegrambot|preview|embedly|pinterest|'
        . 'headless|lighthouse|curl\\/|wget|python-requests|libwww|go-http-client|'
        . 'uptime|pingdom|skypeuripreview|linkedinbot|slackbot|discordbot|twitterbot/i',
        $ua
    );
}

/**
 * User-Agent'tan cihaz / işletim sistemi / tarayıcı ailesi çıkarır (rapor için).
 * @return array{device:string,os:string,browser:string}
 */
function parseUserAgent(string $ua): array {
    $device = 'Masaüstü';
    if (preg_match('/ipad|tablet|(android(?!.*mobile))/i', $ua)) $device = 'Tablet';
    elseif (preg_match('/mobile|iphone|ipod|android/i', $ua))     $device = 'Mobil';

    $os = 'Diğer';
    foreach ([
        '/iphone|ipad|ipod|cpu iphone os|cpu os/i' => 'iOS',
        '/android/i'                               => 'Android',
        '/windows/i'                               => 'Windows',
        '/mac os x|macintosh/i'                    => 'macOS',
        '/cros/i'                                  => 'ChromeOS',
        '/linux/i'                                 => 'Linux',
    ] as $re => $name) {
        if (preg_match($re, $ua)) { $os = $name; break; }
    }

    $browser = 'Diğer';
    foreach ([
        '/edg(e|a|ios)?\//i'            => 'Edge',
        '/opr\/|opera/i'                => 'Opera',
        '/samsungbrowser/i'             => 'Samsung Internet',
        '/yabrowser/i'                  => 'Yandex',
        '/firefox|fxios/i'              => 'Firefox',
        '/chrome|crios/i'               => 'Chrome',
        '/safari/i'                     => 'Safari',
    ] as $re => $name) {
        if (preg_match($re, $ua)) { $browser = $name; break; }
    }
    return ['device' => $device, 'os' => $os, 'browser' => $browser];
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
 * Analytics açma/kapama. Artık config.php yeniden yazılmaz; ayar settings tablosunda tutulur.
 */
function toggleAnalytics(bool $enabled): bool {
    try {
        Settings::set('analytics_enabled', $enabled ? '1' : '0');
        return true;
    } catch (\Throwable $e) {
        Logger::error('Analytics ayarı kaydedilemedi: ' . $e->getMessage());
        return false;
    }
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
         WHERE scanned_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND is_bot = 0 AND (outcome IS NULL OR outcome = \'ok\')
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

    $row = $pdo->query('SELECT COUNT(*) AS total, SUM(is_active) AS active, SUM(scan_count) AS scans FROM links WHERE archived_at IS NULL')->fetch();
    $stats['total_links']  = (int)($row['total']  ?? 0);
    $stats['active_links'] = (int)($row['active'] ?? 0);
    $stats['total_scans']  = (int)($row['scans']  ?? 0);
    $stats['inactive_links'] = $stats['total_links'] - $stats['active_links'];

    $row2 = $pdo->query("SELECT COUNT(*) AS today FROM scans WHERE DATE(scanned_at) = CURDATE() AND is_bot = 0 AND (outcome IS NULL OR outcome = 'ok')")->fetch();
    $stats['today_scans'] = (int)($row2['today'] ?? 0);

    return $stats;
}

/**
 * En çok taranan linkleri döndürür.
 */
function getTopLinks(PDO $pdo, int $limit = 10): array {
    $stmt = $pdo->prepare(
        'SELECT id, slug, title, target_url, scan_count, is_active FROM links WHERE archived_at IS NULL ORDER BY scan_count DESC LIMIT ?'
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

/**
 * CSV'de formül enjeksiyonunu engeller (Excel'de = + - @ ile başlayan hücre formül sayılır).
 */
function csvCell(mixed $v): mixed {
    if (!is_string($v) || $v === '') return $v;
    return in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $v : $v;
}

/**
 * KVKK: $days günden eski okutma kayıtlarındaki IP adreslerini maskeler (satırlar silinmez,
 * raporlar etkilenmez). IPv4 son bayt, IPv6 ilk 3 grup dışı sıfırlanır.
 * @return int etkilenen satır sayısı
 */
function anonymizeOldScans(PDO $pdo, int $days): int {
    if ($days < 1) return 0;
    $n = $pdo->prepare(
        "UPDATE scans SET ip = CASE
            WHEN ip LIKE '%:%' THEN CONCAT(SUBSTRING_INDEX(ip, ':', 3), '::')
            ELSE CONCAT(SUBSTRING_INDEX(ip, '.', 3), '.0') END
         WHERE ip IS NOT NULL
           AND scanned_at < (NOW() - INTERVAL ? DAY)
           AND ip NOT LIKE '%.0' AND ip NOT LIKE '%::'"
    );
    $n->execute([$days]);
    return $n->rowCount();
}

/** Günde en fazla bir kez, ayarlıysa eski IP'leri anonimleştirir (dashboard açılışında çağrılır). */
function maybeAnonymizeScans(PDO $pdo): void {
    $days = (int)Settings::get('ip_anonymize_days');
    if ($days < 1 || Settings::get('last_anonymize', '') === date('Y-m-d')) return;
    try {
        $c = anonymizeOldScans($pdo, $days);
        Settings::set('last_anonymize', date('Y-m-d'));
        if ($c > 0) Logger::info("KVKK: {$c} okutma kaydının IP adresi anonimleştirildi (>{$days} gün).");
    } catch (\Throwable $e) {
        Logger::warning('IP anonimleştirme hatası: ' . $e->getMessage());
    }
}

/**
 * Bilgi (ⓘ) simgesi: üzerine gelince veya klavye ile odaklanınca açıklama gösterir.
 * Metin kaçışlıdır; HTML kabul etmez.
 */
function tip(string $text): string {
    return '<span class="tip" tabindex="0" role="note" aria-label="' . e($text) . '" data-tip="' . e($text) . '">ⓘ</span>';
}
