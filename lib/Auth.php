<?php
/**
 * Auth — Oturum yönetimi, CSRF, brute-force koruması
 */

class Auth {

    // ─── Başlatma ─────────────────────────────────────────────────────────────

    public static function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(defined('SESSION_NAME') ? SESSION_NAME : 'qrm_session');
            
            $cookieParams = session_get_cookie_params();
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => $cookieParams['domain'] ?? '',
                'secure'   => false, // Bazı Cloudflare/proxy kurulumlarında HTTPS çerezi reddedilmesini önler
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

    // ─── Brute-force ─────────────────────────────────────────────────────────

    public static function checkBruteForce(): bool {
        $max     = defined('BRUTE_MAX_ATTEMPTS') ? BRUTE_MAX_ATTEMPTS : 5;
        $lockout = defined('BRUTE_LOCKOUT_SEC')  ? BRUTE_LOCKOUT_SEC  : 300;

        if (!empty($_SESSION['lockout_until']) && time() < $_SESSION['lockout_until']) {
            return true; // kilitli
        }
        return false;
    }

    public static function recordFailedAttempt(): void {
        $max     = defined('BRUTE_MAX_ATTEMPTS') ? BRUTE_MAX_ATTEMPTS : 5;
        $lockout = defined('BRUTE_LOCKOUT_SEC')  ? BRUTE_LOCKOUT_SEC  : 300;

        $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
        if ($_SESSION['login_attempts'] >= $max) {
            $_SESSION['lockout_until'] = time() + $lockout;
        }
        sleep(2); // gecikme
    }

    public static function lockoutRemaining(): int {
        if (empty($_SESSION['lockout_until'])) return 0;
        return max(0, $_SESSION['lockout_until'] - time());
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
