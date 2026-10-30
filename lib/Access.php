<?php
/**
 * Access — roller, yetkiler ve kullanıcı kapsamı.
 *
 * Roller:  admin  (Yönetici)      her şey
 *          editor (Editör)        QR oluşturur; yalnızca kendi oluşturduğu QR'ları düzenler. Pasife alma / yeniden
 *                                 açma yönetici onayına düşen bir talep oluşturur (LinkRequests). Kapsamındaki diğer
 *                                 QR'ları ve raporlarını yalnızca görür.
 *          viewer (Rapor izleyici) eski kayıtlar için korunur; yeni kullanıcıya atanmaz (ASSIGNABLE_ROLES).
 *
 * Kapsam (user_scopes): all | tag:<etiket> | category:<tür>. Görme ve rapor kapsamıdır; kullanıcı ayrıca kendi
 * oluşturduğu QR'ları her zaman görür.
 * Bağlam yoksa (komut satırı, r.php) kısıtlama uygulanmaz; panel ve API her istekte bağlamı kurar.
 */
class Access {

    public const ROLES = [
        'admin'  => 'Yönetici',
        'editor' => 'Editör',
        'viewer' => 'Rapor izleyici',
    ];

    /** Kullanıcı formunda sunulan roller (Rapor izleyici yalnızca mevcut hesaplarda kalır) */
    public const ASSIGNABLE_ROLES = ['admin', 'editor'];

    public const ROLE_HELP = [
        'admin'  => 'Tüm QR\'lar, kullanıcılar, ayarlar, API anahtarları, arşivleme; pasife alma taleplerini onaylar.',
        'editor' => 'QR oluşturur ve yalnızca kendi oluşturduğu QR\'ları düzenler. Pasife alma yönetici onayıyla olur. Kapsamındaki diğer QR\'ları ve raporlarını görür.',
        'viewer' => 'Kapsamındaki QR\'ları ve raporlarını görür, QR indirir. Değişiklik yapamaz.',
    ];

    /** Yetki → izin verilen roller */
    private const PERMS = [
        'links.view'    => ['admin', 'editor', 'viewer'],
        'links.edit'    => ['admin', 'editor'],
        'links.archive' => ['admin'],
        'links.toggle'  => ['admin'],   // onaysız aç/kapat; editör talep oluşturur
        'design.custom' => ['admin'],   // QR'a özel renk/biçim; editör şablon ya da marka varsayılanını seçer
        'admin'         => ['admin'],
    ];

    /** @var array{id:?int,role:string,scope:array{all:bool,tags:string[],categories:string[]}}|null */
    private static ?array $ctx = null;

    // ─── Bağlam ──────────────────────────────────────────────────────────────

    /** Bir kullanıcı satırı için bağlamı kurar (eski şemada rol yoksa Yönetici sayılır). */
    public static function setUser(?array $user, ?PDO $pdo = null): void {
        if ($user === null) { self::$ctx = null; return; }
        $role = $user['role'] ?? 'admin';
        if (!isset(self::ROLES[$role])) $role = 'viewer'; // bilinmeyen rol: en dar yetki
        $scope = ['all' => $role === 'admin', 'tags' => [], 'categories' => []];
        if ($role !== 'admin' && $pdo && !empty($user['id'])) $scope = self::loadScope($pdo, (int)$user['id']);
        self::$ctx = ['id' => isset($user['id']) ? (int)$user['id'] : null, 'role' => $role, 'scope' => $scope];
    }

    /** API anahtarı kullanıcıya bağlı değilse: tam yetkili sistem bağlamı */
    public static function setSystem(): void {
        self::$ctx = ['id' => null, 'role' => 'admin', 'scope' => ['all' => true, 'tags' => [], 'categories' => []]];
    }

    /** Geçerli bağlamı saklayıp geri yüklemek için (ör. rapor e-postası başka kullanıcı adına hazırlanırken) */
    public static function context(): ?array { return self::$ctx; }
    public static function restore(?array $ctx): void { self::$ctx = $ctx; }

    public static function role(): string { return self::$ctx['role'] ?? 'admin'; }
    public static function userId(): ?int { return self::$ctx['id'] ?? null; }
    public static function isAdmin(): bool { return self::role() === 'admin'; }

    public static function can(string $perm): bool {
        if (self::$ctx === null) return true;
        return in_array(self::$ctx['role'], self::PERMS[$perm] ?? [], true);
    }

    /** @return array{all:bool,tags:string[],categories:string[]} */
    public static function loadScope(PDO $pdo, int $userId): array {
        $scope = ['all' => false, 'tags' => [], 'categories' => []];
        try {
            $st = $pdo->prepare('SELECT kind, value FROM user_scopes WHERE user_id = ? ORDER BY id');
            $st->execute([$userId]);
            foreach ($st->fetchAll() as $r) {
                if ($r['kind'] === 'all') $scope['all'] = true;
                elseif ($r['kind'] === 'tag' && $r['value'] !== '') $scope['tags'][] = $r['value'];
                elseif ($r['kind'] === 'category' && isset(LinkService::CATEGORIES[$r['value']])) $scope['categories'][] = $r['value'];
            }
        } catch (\Throwable $e) {
            // tablo henüz yoksa (migration bekliyor) kapsam boş: yalnızca kendi QR'ları
        }
        return $scope;
    }

    /** @param array{all?:bool,tags?:string[],categories?:string[]} $scope */
    public static function saveScope(PDO $pdo, int $userId, array $scope): void {
        $pdo->prepare('DELETE FROM user_scopes WHERE user_id = ?')->execute([$userId]);
        $ins = $pdo->prepare('INSERT INTO user_scopes (user_id, kind, value) VALUES (?,?,?)');
        if (!empty($scope['all'])) $ins->execute([$userId, 'all', '']);
        foreach (array_unique($scope['categories'] ?? []) as $c) {
            if (isset(LinkService::CATEGORIES[$c])) $ins->execute([$userId, 'category', $c]);
        }
        foreach ($scope['tags'] ?? [] as $t) $ins->execute([$userId, 'tag', mb_substr($t, 0, 100)]);
    }

    /** Kapsamın okunur özeti (kullanıcı listesi için) */
    public static function describeScope(string $role, array $scope): string {
        if ($role === 'admin' || !empty($scope['all'])) return 'Tüm QR\'lar';
        $parts = [];
        foreach ($scope['categories'] as $c) $parts[] = LinkService::CATEGORIES[$c] ?? $c;
        foreach ($scope['tags'] as $t) $parts[] = '#' . $t;
        return $parts ? implode(', ', $parts) . ' + kendi QR\'ları' : 'Yalnızca kendi QR\'ları';
    }

    // ─── Kapsam ──────────────────────────────────────────────────────────────

    /** Bağlamın kısıtsız olup olmadığı */
    private static function unrestricted(): bool {
        return self::$ctx === null || self::$ctx['role'] === 'admin' || self::$ctx['scope']['all'];
    }

    /**
     * `links` tablosu için WHERE parçası (takma adsız sütunlar). Kısıt yoksa ['', []].
     * @return array{0:string,1:array}
     */
    public static function linkScopeSql(): array {
        if (self::unrestricted()) return ['', []];
        $or = []; $params = [];
        if (self::$ctx['id'] !== null) { $or[] = 'created_by = ?'; $params[] = self::$ctx['id']; }
        foreach (self::$ctx['scope']['tags'] as $t) { $or[] = 'FIND_IN_SET(?, tags) > 0'; $params[] = $t; }
        if (self::$ctx['scope']['categories']) {
            $or[] = 'category IN (' . implode(',', array_fill(0, count(self::$ctx['scope']['categories']), '?')) . ')';
            array_push($params, ...self::$ctx['scope']['categories']);
        }
        if (!$or) return ['1 = 0', []];
        return ['(' . implode(' OR ', $or) . ')', $params];
    }

    /**
     * Başka bir tablodaki link_id sütunu için " AND col IN (...)" parçası. Kısıt yoksa ['', []].
     * @return array{0:string,1:array}
     */
    public static function linkIdFilter(string $column): array {
        [$sql, $params] = self::linkScopeSql();
        if ($sql === '') return ['', []];
        return [" AND {$column} IN (SELECT id FROM links WHERE {$sql})", $params];
    }

    public static function canSeeLink(array $link): bool {
        if (self::unrestricted()) return true;
        if (self::$ctx['id'] !== null && (int)($link['created_by'] ?? 0) === self::$ctx['id']) return true;
        if (!empty($link['category']) && in_array($link['category'], self::$ctx['scope']['categories'], true)) return true;
        if (!empty($link['tags']) && self::$ctx['scope']['tags']) {
            $mine = array_map([LinkService::class, 'tagKey'], self::$ctx['scope']['tags']);
            foreach (explode(',', $link['tags']) as $t) {
                if (in_array(LinkService::tagKey($t), $mine, true)) return true;
            }
        }
        return false;
    }

    /** Düzenleme: yönetici kapsamındaki her QR'ı, editör yalnızca kendi oluşturduğunu */
    public static function canEditLink(array $link): bool {
        if (!self::can('links.edit') || !self::canSeeLink($link)) return false;
        if (self::$ctx === null || self::$ctx['role'] === 'admin') return true;
        return self::isOwner($link);
    }

    /** Aktif/pasif durumunu onaysız değiştirebilir mi */
    public static function canToggleLink(array $link): bool {
        return self::can('links.toggle') && self::canSeeLink($link);
    }

    /** Aktif/pasif değişikliği için yönetici onayına talep gönderebilir mi */
    public static function canRequestToggle(array $link): bool {
        return !self::can('links.toggle') && self::canEditLink($link) && empty($link['archived_at']);
    }

    public static function isOwner(array $link): bool {
        return self::$ctx !== null && self::$ctx['id'] !== null && (int)($link['created_by'] ?? 0) === self::$ctx['id'];
    }
}
