<?php
/**
 * Notifications — zamanlanmış görevlerin ürettiği uyarılar (bitiş yaklaşan, hedefi kırık, okutması duran QR…).
 *
 * Her uyarının tekil bir anahtarı vardır; aynı durum için ikinci kez uyarı oluşmaz (aynı e-posta tekrar gitmez).
 * Yönetici tüm uyarıları, editör yalnızca görebildiği QR'larınkini görür. Tablo yoksa (migration bekliyor) boş döner.
 */
class Notifications {

    /** tür => [etiket, ikon, önem] */
    public const KINDS = [
        'expiry' => ['Bitiş yaklaşıyor', 'clock', 'warning'],
        'broken' => ['Hedef adres açılmıyor', 'alert', 'danger'],
        'silent' => ['Okutma durdu / düştü', 'trending', 'info'],
        'system' => ['Sistem', 'settings', 'warning'],
    ];

    private static ?bool $available = null;

    public static function available(PDO $pdo): bool {
        if (self::$available === null) {
            try { $pdo->query('SELECT 1 FROM notifications LIMIT 1'); self::$available = true; }
            catch (\Throwable $e) { self::$available = false; }
        }
        return self::$available;
    }

    public static function reset(): void { self::$available = null; }

    /** Yeni uyarı; aynı anahtarla daha önce oluşturulduysa (çözülmüş olsa da) false */
    public static function raise(PDO $pdo, string $kind, ?int $linkId, string $key, string $title, string $body = ''): bool {
        $st = $pdo->prepare('INSERT IGNORE INTO notifications (kind, link_id, dedupe_key, title, body) VALUES (?,?,?,?,?)');
        $st->execute([$kind, $linkId, mb_substr($key, 0, 190), mb_substr($title, 0, 255), $body !== '' ? $body : null]);
        return $st->rowCount() > 0;
    }

    /** Bir QR'ın bu türdeki açık uyarılarını kapatır (ör. hedef yeniden açıldı) */
    public static function resolveFor(PDO $pdo, string $kind, int $linkId, string $by = 'otomatik'): int {
        $st = $pdo->prepare('UPDATE notifications SET resolved_at = NOW(), resolved_by = ? WHERE kind = ? AND link_id = ? AND resolved_at IS NULL');
        $st->execute([$by, $kind, $linkId]);
        return $st->rowCount();
    }

    public static function find(PDO $pdo, int $id): ?array {
        $st = $pdo->prepare('SELECT * FROM notifications WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Kullanıcı bu uyarıyı görebilir mi: yönetici hepsini, editör görebildiği QR'larınkini */
    public static function canSee(PDO $pdo, array $n): bool {
        if (Access::isAdmin()) return true;
        if (empty($n['link_id'])) return false;
        $l = LinkService::find($pdo, (int)$n['link_id']);
        return $l && Access::canSeeLink($l);
    }

    public static function resolve(PDO $pdo, array $n, array $actor): void {
        $pdo->prepare('UPDATE notifications SET resolved_at = NOW(), resolved_by = ? WHERE id = ? AND resolved_at IS NULL')
            ->execute([mb_substr($actor['name'] ?? '', 0, 64), (int)$n['id']]);
    }

    /** @return array{0:string,1:array} Kullanıcı kapsamı koşulu (n. tablo takma adı) */
    private static function scopeSql(): array {
        if (Access::isAdmin()) return ['', []];
        [$sql, $args] = Access::linkIdFilter('n.link_id');
        return [' AND n.link_id IS NOT NULL' . $sql, $args];
    }

    /**
     * @param bool $open true: açık uyarılar, false: çözülenler
     * @return array<int,array> uyarılar + QR'ın slug ve başlığı
     */
    public static function list(PDO $pdo, bool $open = true, int $limit = 100, ?string $kind = null): array {
        if (!self::available($pdo)) return [];
        [$sc, $args] = self::scopeSql();
        $where = 'n.resolved_at IS ' . ($open ? 'NULL' : 'NOT NULL') . $sc;
        if ($kind !== null && isset(self::KINDS[$kind])) { $where .= ' AND n.kind = ?'; $args[] = $kind; }
        $st = $pdo->prepare("SELECT n.*, l.slug, l.title link_title FROM notifications n LEFT JOIN links l ON l.id = n.link_id
                             WHERE {$where} ORDER BY " . ($open ? 'n.created_at' : 'n.resolved_at') . " DESC, n.id DESC LIMIT " . max(1, min(500, $limit)));
        $st->execute($args);
        return $st->fetchAll();
    }

    public static function openCount(PDO $pdo): int {
        if (!self::available($pdo)) return 0;
        [$sc, $args] = self::scopeSql();
        $st = $pdo->prepare("SELECT COUNT(*) FROM notifications n WHERE n.resolved_at IS NULL{$sc}");
        $st->execute($args);
        return (int)$st->fetchColumn();
    }
}
