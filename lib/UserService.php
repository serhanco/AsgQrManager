<?php
/**
 * UserService — panel kullanıcıları: oluşturma, rol/kapsam güncelleme, şifre işlemleri.
 * Kullanıcılar SİLİNMEZ, devre dışı bırakılır (değişiklik geçmişindeki adları anlamlı kalsın).
 */
class UserService {

    public const MIN_PASSWORD = 10;

    public static function passwordError(string $pw): ?string {
        if (mb_strlen($pw) < self::MIN_PASSWORD) return 'Şifre en az ' . self::MIN_PASSWORD . ' karakter olmalı.';
        if (!preg_match('/\pL/u', $pw) || !preg_match('/\d/', $pw)) return 'Şifre en az bir harf ve bir rakam içermeli.';
        return null;
    }

    /** Okunması kolay geçici şifre (karışan karakterler yok) */
    public static function tempPassword(): string {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $pw = '';
            for ($i = 0; $i < 12; $i++) $pw .= $chars[random_int(0, strlen($chars) - 1)];
        } while (self::passwordError($pw) !== null);
        return $pw;
    }

    /** @return array<int,array> kullanıcılar + 'scope' anahtarı */
    public static function all(PDO $pdo): array {
        $rows = $pdo->query('SELECT * FROM users ORDER BY is_active DESC, role = \'admin\' DESC, username')->fetchAll();
        foreach ($rows as &$r) $r['scope'] = Access::loadScope($pdo, (int)$r['id']);
        unset($r);
        return $rows;
    }

    public static function find(PDO $pdo, int $id): ?array {
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $u = $st->fetch();
        if (!$u) return null;
        $u['scope'] = Access::loadScope($pdo, $id);
        return $u;
    }

    /**
     * Formdan gelen kapsamı temizler. Etiketler mevcut yazıma çevrilir (İstanbul / istanbul tek etiket).
     * @return array{all:bool,tags:string[],categories:string[]}
     */
    public static function scopeFromInput(array $in, ?PDO $pdo = null): array {
        $tags = LinkService::normalizeTags((string)($in['scope_tags'] ?? ''), LinkService::canonicalMap($pdo));
        $cats = array_values(array_filter((array)($in['scope_categories'] ?? []), fn($c) => isset(LinkService::CATEGORIES[$c])));
        return ['all' => !empty($in['scope_all']), 'tags' => $tags ? explode(',', $tags) : [], 'categories' => $cats];
    }

    /** Etkin yönetici sayısı (son yöneticiyi düşürmeyi engellemek için) */
    private static function activeAdmins(PDO $pdo): int {
        return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
    }

    /**
     * @param array $in username, display_name, email, role, password, scope_*
     * @return array{0:?int,1:string[]} [yeni id, hatalar]
     */
    public static function create(PDO $pdo, array $in, array $actor): array {
        $errors = [];
        $username = trim((string)($in['username'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $username)) $errors[] = 'Kullanıcı adı 3-64 karakter olmalı; harf, rakam ve . _ @ - kullanılabilir.';
        $role = (string)($in['role'] ?? '');
        if (!isset(Access::ROLES[$role])) $errors[] = 'Geçersiz rol.';
        $email = trim((string)($in['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-posta adresi geçersiz.';
        $pw = (string)($in['password'] ?? '');
        if ($err = self::passwordError($pw)) $errors[] = $err;
        if (!$errors) {
            $chk = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
            $chk->execute([$username]);
            if ($chk->fetch()) $errors[] = "'{$username}' kullanıcı adı zaten var.";
        }
        if ($errors) return [null, $errors];

        $pdo->prepare('INSERT INTO users (username, password_hash, role, display_name, email, is_active, must_change_password, created_by)
                       VALUES (?,?,?,?,?,1,1,?)')
            ->execute([$username, password_hash($pw, PASSWORD_DEFAULT), $role,
                       mb_substr(trim((string)($in['display_name'] ?? '')), 0, 100) ?: null, $email ?: null, $actor['name']]);
        $id = (int)$pdo->lastInsertId();
        if ($role !== 'admin') Access::saveScope($pdo, $id, self::scopeFromInput($in, $pdo));
        LinkEvents::log($pdo, null, null, $actor, 'settings', ['kullanıcı' => [null, "oluşturuldu: {$username} (" . Access::ROLES[$role] . ')']]);
        return [$id, []];
    }

    /** @return string[] hatalar */
    public static function update(PDO $pdo, array $user, array $in, array $actor): array {
        $errors = [];
        $role = (string)($in['role'] ?? $user['role']);
        if (!isset(Access::ROLES[$role])) $errors[] = 'Geçersiz rol.';
        $active = !empty($in['is_active']) ? 1 : 0;
        $email = trim((string)($in['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-posta adresi geçersiz.';

        $isSelf = (int)$user['id'] === (int)($actor['id'] ?? 0);
        if ($isSelf && (!$active || $role !== 'admin')) $errors[] = 'Kendi hesabınızın rolünü düşüremez veya devre dışı bırakamazsınız.';
        $wasActiveAdmin = $user['role'] === 'admin' && (int)$user['is_active'] === 1;
        if ($wasActiveAdmin && ($role !== 'admin' || !$active) && self::activeAdmins($pdo) <= 1) {
            $errors[] = 'Son etkin yönetici düşürülemez; önce başka bir yönetici ekleyin.';
        }
        if ($errors) return $errors;

        $pdo->prepare('UPDATE users SET role = ?, is_active = ?, display_name = ?, email = ? WHERE id = ?')
            ->execute([$role, $active, mb_substr(trim((string)($in['display_name'] ?? '')), 0, 100) ?: null, $email ?: null, (int)$user['id']]);
        Access::saveScope($pdo, (int)$user['id'], $role === 'admin' ? [] : self::scopeFromInput($in, $pdo));

        $changes = [];
        if ($role !== $user['role']) $changes['rol'] = [Access::ROLES[$user['role']] ?? $user['role'], Access::ROLES[$role]];
        if ($active !== (int)$user['is_active']) $changes['durum'] = [$user['is_active'] ? 'etkin' : 'devre dışı', $active ? 'etkin' : 'devre dışı'];
        $changes['kullanıcı'] = [null, $user['username']];
        LinkEvents::log($pdo, null, null, $actor, 'settings', $changes);
        return [];
    }

    /** Yöneticinin şifre sıfırlaması: kullanıcı ilk girişte değiştirmek zorunda */
    public static function resetPassword(PDO $pdo, array $user, string $pw, array $actor): ?string {
        if ($err = self::passwordError($pw)) return $err;
        $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?')
            ->execute([password_hash($pw, PASSWORD_DEFAULT), (int)$user['id']]);
        LinkEvents::log($pdo, null, null, $actor, 'settings', ['kullanıcı' => [null, "şifre sıfırlandı: {$user['username']}"]]);
        return null;
    }

    /** Kullanıcının kendi şifresini değiştirmesi */
    public static function changeOwnPassword(PDO $pdo, int $userId, string $current, string $new, string $repeat): ?string {
        $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$userId]);
        $hash = (string)$st->fetchColumn();
        if (!password_verify($current, $hash)) return 'Mevcut şifre hatalı.';
        if ($new !== $repeat) return 'Yeni şifreler birbirini tutmuyor.';
        if ($new === $current) return 'Yeni şifre eskisiyle aynı olamaz.';
        if ($err = self::passwordError($new)) return $err;
        $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
        return null;
    }
}
