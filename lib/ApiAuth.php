<?php
/**
 * ApiAuth — API anahtarı üretme ve doğrulama.
 * Anahtarın kendisi saklanmaz, yalnızca SHA-256 özeti tutulur; oluşturulurken bir kez gösterilir.
 */
class ApiAuth {

    /**
     * @param ?int $userId anahtar bu kullanıcının rolü ve kapsamıyla çalışır (null = tam yetki)
     * @return array{token:string,id:int}
     */
    public static function create(PDO $pdo, string $name, string $scopes, string $createdBy, ?int $userId = null): array {
        $token = 'qrm_' . bin2hex(random_bytes(24));
        $pdo->prepare('INSERT INTO api_tokens (name, token_hash, token_prefix, scopes, created_by, user_id) VALUES (?,?,?,?,?,?)')
            ->execute([mb_substr($name, 0, 100), hash('sha256', $token), substr($token, 0, 12),
                       $scopes === 'read' ? 'read' : 'read,write', $createdBy, $userId]);
        return ['token' => $token, 'id' => (int)$pdo->lastInsertId()];
    }

    /** @return array|null token satırı (iptal edilmemişse) */
    public static function verify(PDO $pdo, string $token): ?array {
        if (!preg_match('/^qrm_[0-9a-f]{48}$/', $token)) return null;
        $st = $pdo->prepare('SELECT * FROM api_tokens WHERE token_hash = ? AND revoked_at IS NULL LIMIT 1');
        $st->execute([hash('sha256', $token)]);
        $row = $st->fetch();
        if (!$row) return null;
        // son kullanım (en fazla dakikada bir yaz)
        if (empty($row['last_used_at']) || strtotime($row['last_used_at']) < time() - 60) {
            $pdo->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?')->execute([$row['id']]);
        }
        return $row;
    }

    public static function can(array $tokenRow, string $scope): bool {
        if (!in_array($scope, explode(',', $tokenRow['scopes']), true)) return false;
        // Kullanıcıya bağlı anahtar, kullanıcının rolünü aşamaz
        return $scope !== 'write' || Access::can('links.edit');
    }

    /**
     * Anahtarın yetki bağlamını kurar. Kullanıcıya bağlı anahtarda kullanıcı devre dışıysa false döner.
     */
    public static function applyContext(PDO $pdo, array $tokenRow): bool {
        if (empty($tokenRow['user_id'])) { Access::setSystem(); return true; }
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $st->execute([(int)$tokenRow['user_id']]);
        $u = $st->fetch();
        if (!$u || !(int)($u['is_active'] ?? 1)) return false;
        Access::setUser($u, $pdo);
        return true;
    }
}
