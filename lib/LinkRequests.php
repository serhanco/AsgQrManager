<?php
/**
 * LinkRequests — editörün aktif/pasif değişikliği talepleri ve yöneticinin onayı.
 *
 * Editör kendi QR'ını doğrudan pasife alamaz; talep oluşturur. Yönetici onaylarsa durum değişir,
 * reddederse QR olduğu gibi kalır. Her adım QR'ın değişiklik geçmişine yazılır.
 * Tablo henüz yoksa (migration bekliyor) okuma işlevleri boş döner, panel bozulmaz.
 */
class LinkRequests {

    public const KINDS = [
        'deactivate' => 'Pasife alma',
        'activate'   => 'Yeniden açma',
    ];

    public const STATUSES = [
        'pending'   => 'Bekliyor',
        'approved'  => 'Onaylandı',
        'rejected'  => 'Reddedildi',
        'cancelled' => 'Geri çekildi',
    ];

    /** QR'ın mevcut durumuna göre talep türü */
    public static function kindFor(array $link): string {
        return (int)$link['is_active'] ? 'deactivate' : 'activate';
    }

    /**
     * Talep oluşturur. Aynı QR için bekleyen talep varsa yenisini açmaz.
     * @return array{0:?int,1:?string} [talep id, hata]
     */
    public static function create(PDO $pdo, array $link, array $actor, string $kind, string $note = ''): array {
        if (!isset(self::KINDS[$kind])) return [null, 'Geçersiz talep türü.'];
        if (!empty($link['archived_at'])) return [null, 'Arşivdeki QR için talep oluşturulamaz.'];
        if ($kind !== self::kindFor($link)) return [null, $kind === 'deactivate' ? 'QR zaten pasif.' : 'QR zaten aktif.'];
        if (self::pendingFor($pdo, (int)$link['id'])) return [null, 'Bu QR için zaten onay bekleyen bir talep var.'];
        $note = mb_substr(trim($note), 0, 500);
        $pdo->prepare('INSERT INTO link_requests (link_id, kind, note, requested_by, requester) VALUES (?,?,?,?,?)')
            ->execute([(int)$link['id'], $kind, $note !== '' ? $note : null, $actor['id'] ?? null, mb_substr($actor['name'] ?? '', 0, 64)]);
        $id = (int)$pdo->lastInsertId();
        LinkEvents::log($pdo, (int)$link['id'], $link['slug'], $actor, 'request', ['talep' => [null, self::KINDS[$kind] . ($note !== '' ? " — {$note}" : '')]]);
        return [$id, null];
    }

    public static function find(PDO $pdo, int $id): ?array {
        try {
            $st = $pdo->prepare('SELECT * FROM link_requests WHERE id = ?');
            $st->execute([$id]);
            return $st->fetch() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** QR'ın bekleyen talebi (yoksa null) */
    public static function pendingFor(PDO $pdo, int $linkId): ?array {
        try {
            $st = $pdo->prepare("SELECT * FROM link_requests WHERE link_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
            $st->execute([$linkId]);
            return $st->fetch() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Verilen QR'lardan bekleyen talebi olanlar: link_id => talep */
    public static function pendingMap(PDO $pdo, array $linkIds): array {
        $linkIds = array_values(array_unique(array_map('intval', $linkIds)));
        if (!$linkIds) return [];
        try {
            $st = $pdo->prepare("SELECT * FROM link_requests WHERE status = 'pending' AND link_id IN ("
                . implode(',', array_fill(0, count($linkIds), '?')) . ')');
            $st->execute($linkIds);
            $out = [];
            foreach ($st->fetchAll() as $r) $out[(int)$r['link_id']] = $r;
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function pendingCount(PDO $pdo): int {
        try {
            return (int)$pdo->query("SELECT COUNT(*) FROM link_requests WHERE status = 'pending'")->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Talepler ve QR bilgileri; $status null ise tümü, $requestedBy verilirse yalnızca o kullanıcının talepleri */
    public static function listing(PDO $pdo, ?string $status, ?int $requestedBy = null, int $limit = 100): array {
        try {
            $cond = []; $args = [];
            if ($status)              { $cond[] = 'r.status = ?';       $args[] = $status; }
            if ($requestedBy !== null) { $cond[] = 'r.requested_by = ?'; $args[] = $requestedBy; }
            $where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';
            $st = $pdo->prepare("SELECT r.*, l.slug, l.title, l.target_url, l.is_active, l.archived_at
                                 FROM link_requests r LEFT JOIN links l ON l.id = r.link_id
                                 {$where} ORDER BY r.status = 'pending' DESC, r.id DESC LIMIT " . max(1, $limit));
            $st->execute($args);
            return $st->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Yönetici kararı. Onayda QR durumu talep edilen yöne çekilir.
     * @return ?string hata
     */
    public static function decide(PDO $pdo, array $req, bool $approve, array $actor, string $note = ''): ?string {
        if ($req['status'] !== 'pending') return 'Bu talep zaten sonuçlanmış.';
        $link = LinkService::find($pdo, (int)$req['link_id']);
        $note = mb_substr(trim($note), 0, 500);
        $status = $approve ? 'approved' : 'rejected';
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("UPDATE link_requests SET status = ?, decided_by = ?, decision_note = ?, decided_at = NOW()
                                  WHERE id = ? AND status = 'pending'");
            $upd->execute([$status, mb_substr($actor['name'] ?? '', 0, 64), $note !== '' ? $note : null, (int)$req['id']]);
            if ($upd->rowCount() === 0) { $pdo->rollBack(); return 'Bu talep zaten sonuçlanmış.'; }
            if ($approve && $link && empty($link['archived_at'])) {
                $new = $req['kind'] === 'activate' ? 1 : 0;
                if ((int)$link['is_active'] !== $new) {
                    $pdo->prepare('UPDATE links SET is_active = ?, updated_at = NOW() WHERE id = ?')->execute([$new, (int)$link['id']]);
                    LinkEvents::log($pdo, (int)$link['id'], $link['slug'], $actor, $new ? 'activate' : 'deactivate',
                                    ['onay' => [null, ($req['requester'] ?: 'editör') . ' talebi']]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        if ($link) {
            LinkEvents::log($pdo, (int)$link['id'], $link['slug'], $actor, $approve ? 'request_ok' : 'request_no',
                            ['talep' => [null, (self::KINDS[$req['kind']] ?? $req['kind']) . ($note !== '' ? " — {$note}" : '')]]);
        }
        return null;
    }

    /** Editörün kendi bekleyen talebini geri çekmesi */
    public static function cancel(PDO $pdo, array $req, array $actor): ?string {
        if ($req['status'] !== 'pending') return 'Bu talep zaten sonuçlanmış.';
        $pdo->prepare("UPDATE link_requests SET status = 'cancelled', decided_by = ?, decided_at = NOW() WHERE id = ? AND status = 'pending'")
            ->execute([mb_substr($actor['name'] ?? '', 0, 64), (int)$req['id']]);
        $link = LinkService::find($pdo, (int)$req['link_id']);
        if ($link) LinkEvents::log($pdo, (int)$link['id'], $link['slug'], $actor, 'request_cancel', []);
        return null;
    }
}
