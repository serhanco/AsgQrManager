<?php
/**
 * Auth — Oturum yönetimi, CSRF, brute-force koruması
 */

require_once __DIR__ . '/Migrator.php';

class Auth {

    // ─── Başlatma ─────────────────────────────────────────────────────────────

    /** İstek HTTPS üzerinden mi geldi? (Cloudflare/proxy arkasında X-Forwarded-Proto da bakılır) */
    public static function isHttps(): bool {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
        if (($_SERVER['SERVER_PORT'] ?? 0) == 443) return true;
        return strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public static function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(defined('SESSION_NAME') ? SESSION_NAME : 'qrm_session');
            ini_set('session.use_strict_mode', '1');

            $cookieParams = session_get_cookie_params();
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => $cookieParams['domain'] ?? '',
                // HTTPS algılandığında çerez yalnızca şifreli bağlantıda gönderilir.
                // Proxy HTTPS'i hiç bildirmiyorsa eskisi gibi çalışır (giriş kilitlenmez).
                'secure'   => self::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    // ─── Giriş kontrolü ──────────────────────────────────────────────────────

    public static function isLoggedIn(): bool {
        return !empty($_SESSION['user_id']);
    }

    public static function requireLogin(): void {
        if (!self::isLoggedIn()) {
            $target = urlencode($_SERVER['REQUEST_URI'] ?? '');
            header('Location: ' . BASE_URL . '/admin/login?redirect=' . $target);
            exit;
        }
        self::ensureSchema();
    }

    /**
     * Kod güncellendiğinde bekleyen (yalnızca eklemeli) veritabanı adımlarını uygular.
     * Oturum başına kod sürümü değişince bir kez kontrol edilir.
     */
    private static function ensureSchema(): void {
        $ver = Migrator::version();
        if (($_SESSION['schema_ver'] ?? null) === $ver) return;

        $res = Migrator::run(getPdo());
        if ($res['error']) {
            flash('danger', $res['error']);
            return; // sonraki istekte tekrar denenir
        }
        $_SESSION['schema_ver'] = $ver;
        if ($res['applied']) {
            flash('info', 'Veritabanı güncellendi (' . count($res['applied']) . ' adım, mevcut kayıtlar korundu).'
                . ($res['backup'] ? ' Yedek: ' . basename($res['backup']) : ''));
        }
    }

    /** İşlemi yapan kullanıcı (değişiklik geçmişi için) */
    public static function actor(): array {
        return ['id' => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
                'name' => (string)($_SESSION['username'] ?? 'bilinmiyor')];
    }

    public static function login(int $userId, string $username): void {
        session_regenerate_id(true);
        $_SESSION['user_id']  = $userId;
        $_SESSION['username'] = $username;
        unset($_SESSION['login_attempts'], $_SESSION['lockout_until']);
    }

    public static function logout(): void {
        $_SESSION = [];
        session_destroy();
    }

    // ─── CSRF ─────────────────────────────────────────────────────────────────

    public static function csrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function csrfField(): string {
        $token = self::csrfToken();
        $name  = defined('CSRF_TOKEN_NAME') ? CSRF_TOKEN_NAME : '_csrf';
        return '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($token) . '">';
    }

    public static function verifyCsrf(): bool {
        $name  = defined('CSRF_TOKEN_NAME') ? CSRF_TOKEN_NAME : '_csrf';
        $token = $_POST[$name] ?? '';
        if (empty($token) || empty($_SESSION['csrf_token'])) return false;
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function requireCsrf(): void {
        if (!self::verifyCsrf()) {
            http_response_code(403);
            die('CSRF doğrulaması başarısız.');
        }
    }

    // ─── Brute-force (IP bazlı, veritabanında) ───────────────────────────────
    // Eskiden sayaç oturumdaydı: çerezi silen saldırgan kilidi sıfırlayabiliyordu.

    private static function throttleReady(): bool {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            getPdo()->exec("CREATE TABLE IF NOT EXISTS `login_attempts` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `ip` VARCHAR(45) NOT NULL,
                `username` VARCHAR(64) NULL,
                `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_attempt_ip` (`ip`, `attempted_at`),
                KEY `idx_attempt_user` (`username`, `attempted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $ok = true;
        } catch (\Throwable $e) {
            $ok = false;
        }
        return $ok;
    }

    private static function bruteConfig(): array {
        return [
            defined('BRUTE_MAX_ATTEMPTS') ? (int)BRUTE_MAX_ATTEMPTS : 5,
            defined('BRUTE_LOCKOUT_SEC')  ? (int)BRUTE_LOCKOUT_SEC  : 300,
        ];
    }

    /** @return int kilidin bitmesine kalan saniye (0 = kilit yok) */
    private static function dbLockRemaining(?string $username): int {
        [$max, $lockout] = self::bruteConfig();
        $pdo = getPdo();
        $ip  = getClientIp();

        // IP başına: son $lockout saniyede $max hata → son $max. hatadan itibaren $lockout saniye kilit
        $st = $pdo->prepare(
            'SELECT UNIX_TIMESTAMP(attempted_at) FROM login_attempts
             WHERE ip = ? AND attempted_at > (NOW() - INTERVAL ? SECOND)
             ORDER BY attempted_at DESC LIMIT ?'
        );
        $st->bindValue(1, $ip);
        $st->bindValue(2, $lockout, \PDO::PARAM_INT);
        $st->bindValue(3, $max, \PDO::PARAM_INT);
        $st->execute();
        $times = $st->fetchAll(\PDO::FETCH_COLUMN);
        $remaining = 0;
        if (count($times) >= $max) {
            $remaining = max(0, (int)$times[0] + $lockout - time());
        }

        // Kullanıcı adı başına (dağıtık deneme): yüksek eşik, hesabı kilitleme riski düşük tutulur
        if ($username !== null && $username !== '') {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM login_attempts
                 WHERE username = ? AND attempted_at > (NOW() - INTERVAL ? SECOND)'
            );
            $st->execute([mb_substr($username, 0, 64), $lockout]);
            if ((int)$st->fetchColumn() >= $max * 4) $remaining = max($remaining, $lockout);
        }
        return $remaining;
    }

    public static function checkBruteForce(?string $username = null): bool {
        if (self::throttleReady()) {
            try {
                $rem = self::dbLockRemaining($username);
                $_SESSION['lockout_remaining'] = $rem;
                return $rem > 0;
            } catch (\Throwable $e) {
                // aşağıdaki oturum tabanlı yedek devreye girer
            }
        }
        if (!empty($_SESSION['lockout_until']) && time() < $_SESSION['lockout_until']) return true;
        return false;
    }

    public static function recordFailedAttempt(?string $username = null): void {
        [$max, $lockout] = self::bruteConfig();
        if (self::throttleReady()) {
            try {
                getPdo()->prepare('INSERT INTO login_attempts (ip, username) VALUES (?, ?)')
                    ->execute([getClientIp(), $username !== null ? mb_substr($username, 0, 64) : null]);
                // eski kayıtları temizle (1 gün)
                if (random_int(1, 20) === 1) {
                    getPdo()->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
                }
                sleep(1);
                return;
            } catch (\Throwable $e) {
                // yedek
            }
        }
        $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
        if ($_SESSION['login_attempts'] >= $max) $_SESSION['lockout_until'] = time() + $lockout;
        sleep(1);
    }

    public static function lockoutRemaining(): int {
        if (!empty($_SESSION['lockout_remaining'])) return (int)$_SESSION['lockout_remaining'];
        if (empty($_SESSION['lockout_until'])) return 0;
        return max(0, $_SESSION['lockout_until'] - time());
    }

    /** Başarılı girişte bu IP'nin hata kayıtlarını sıfırla */
    public static function clearFailedAttempts(): void {
        unset($_SESSION['lockout_remaining']);
        if (!self::throttleReady()) return;
        try {
            getPdo()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([getClientIp()]);
        } catch (\Throwable $e) {}
    }

    // ─── DB tabanlı kullanıcı doğrulama ──────────────────────────────────────

    public static function verifyUser(\PDO $pdo, string $username, string $password): ?array {
        $stmt = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([trim($username)]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$user) return null;
        if (!password_verify($password, $user['password_hash'])) return null;
        return $user;
    }
}
