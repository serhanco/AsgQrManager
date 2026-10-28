<?php
/**
 * Stats — okutma istatistikleri (rapor sayfası ve API ortak kullanır).
 *
 * "Okutma" = bot olmayan ve hedefe giden (outcome boş veya 'ok') istek.
 * Bot önizlemeleri ve pasif/yedek yönlendirmeler ayrı sayılır.
 */
class Stats {

    /**
     * @param string $from Y-m-d (dahil)   @param string $to Y-m-d (dahil)   @param ?string $tag yalnızca bu etiketli QR'lar (linkId yoksa)
     * @return array{total:int,unique:int,first_at:?string,last_at:?string,bots:int,redirected:int,
     *               daily:array<string,int>,hours:int[],heat:int[][],devices:array,os:array,browsers:array,countries:array,referrers:array}
     */
    public static function summary(PDO $pdo, ?int $linkId, string $from, string $to, ?string $tag = null): array {
        [$ok, $args, $linkSql, $fromTs, $toTs] = self::filter($linkId, $from, $to, $tag);
        $q = function (string $sql) use ($pdo, $args) { $st = $pdo->prepare($sql); $st->execute($args); return $st; };

        $tot = $q("SELECT COUNT(*) c, COUNT(DISTINCT CONCAT(IFNULL(s.ip,''),'|',IFNULL(s.user_agent,''))) u,
                          MIN(s.scanned_at) f, MAX(s.scanned_at) l FROM scans s WHERE {$ok}")->fetch();
        $other = $q("SELECT IFNULL(SUM(s.is_bot = 1),0) bots,
                            IFNULL(SUM(s.is_bot = 0 AND s.outcome IN ('fallback','after_end','dead')),0) redirected
                     FROM scans s WHERE s.scanned_at >= ? AND s.scanned_at < ?{$linkSql}")->fetch();

        // Günlük seri (en fazla 366 gün), boş günler 0
        $map = $q("SELECT DATE(s.scanned_at) d, COUNT(*) c FROM scans s WHERE {$ok} GROUP BY d")->fetchAll(PDO::FETCH_KEY_PAIR);
        $d0 = new DateTimeImmutable($from);
        $d1 = new DateTimeImmutable($to);
        if ($d0 < $d1->modify('-365 days')) $d0 = $d1->modify('-365 days');
        $daily = [];
        for ($d = $d0; $d <= $d1; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $daily[$k] = (int)($map[$k] ?? 0);
        }

        // Gün × saat (0 = Pazartesi); saat dağılımı bundan toplanır
        $heat = array_fill(0, 7, array_fill(0, 24, 0));
        $hours = array_fill(0, 24, 0);
        foreach ($q("SELECT WEEKDAY(s.scanned_at) w, HOUR(s.scanned_at) h, COUNT(*) c FROM scans s WHERE {$ok} GROUP BY w, h") as $r) {
            $heat[(int)$r['w']][(int)$r['h']] = (int)$r['c'];
            $hours[(int)$r['h']] += (int)$r['c'];
        }

        $dev = $os = $br = [];
        foreach ($q("SELECT s.user_agent ua, COUNT(*) c FROM scans s WHERE {$ok} GROUP BY s.user_agent") as $r) {
            $p = parseUserAgent((string)$r['ua']);
            $dev[$p['device']]  = ($dev[$p['device']]  ?? 0) + (int)$r['c'];
            $os[$p['os']]       = ($os[$p['os']]       ?? 0) + (int)$r['c'];
            $br[$p['browser']]  = ($br[$p['browser']]  ?? 0) + (int)$r['c'];
        }
        arsort($dev); arsort($os); arsort($br);

        $countries = array_map('intval', $q("SELECT IFNULL(s.country,'??') k, COUNT(*) c FROM scans s WHERE {$ok} GROUP BY k ORDER BY c DESC LIMIT 15")
            ->fetchAll(PDO::FETCH_KEY_PAIR));

        $refs = [];
        foreach ($q("SELECT s.referer r, COUNT(*) c FROM scans s WHERE {$ok} GROUP BY s.referer") as $r) {
            $h = $r['r'] ? (parse_url($r['r'], PHP_URL_HOST) ?: 'diğer') : 'doğrudan / bilinmiyor';
            $refs[$h] = ($refs[$h] ?? 0) + (int)$r['c'];
        }
        arsort($refs);

        return [
            'total' => (int)$tot['c'], 'unique' => (int)$tot['u'], 'first_at' => $tot['f'], 'last_at' => $tot['l'],
            'bots' => (int)$other['bots'], 'redirected' => (int)$other['redirected'],
            'daily' => $daily, 'hours' => $hours, 'heat' => $heat, 'devices' => $dev, 'os' => $os, 'browsers' => $br,
            'countries' => $countries, 'referrers' => array_slice($refs, 0, 10, true),
        ];
    }

    public const RANGES = ['7' => 'Son 7 gün', '30' => 'Son 30 gün', '90' => 'Son 90 gün', '365' => 'Son 1 yıl', 'all' => 'Tüm zamanlar', 'custom' => 'Özel aralık'];

    /**
     * İstekteki tarih aralığı (range=7|30|90|365|all|custom, from, to).
     * @param string $allFrom "Tüm zamanlar" için başlangıç
     * @return array{0:string,1:string,2:string} [range, from, to] (Y-m-d)
     */
    public static function rangeFromRequest(array $get, string $allFrom = '2000-01-01'): array {
        $today = (new DateTimeImmutable('now', Settings::timezone()))->format('Y-m-d');
        $range = (string)($get['range'] ?? '30');
        $valid = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
        if (in_array($range, ['7', '30', '90', '365'], true)) {
            return [$range, (new DateTimeImmutable($today))->modify('-' . ((int)$range - 1) . ' days')->format('Y-m-d'), $today];
        }
        if ($range === 'all') return ['all', $allFrom, $today];
        $from = $valid($get['from'] ?? '') ? $get['from'] : (new DateTimeImmutable($today))->modify('-29 days')->format('Y-m-d');
        $to   = $valid($get['to'] ?? '')   ? $get['to']   : $today;
        if ($from > $to) [$from, $to] = [$to, $from];
        return ['custom', $from, $to];
    }

    /**
     * Ortak süzgeç: geçerli okutma koşulu, parametreler ve QR/etiket/kapsam koşulu.
     * @return array{0:string,1:array,2:string,3:string,4:string} [ok koşulu, parametreler, QR koşulu, başlangıç, bitiş]
     */
    private static function filter(?int $linkId, string $from, string $to, ?string $tag = null): array {
        $fromTs = $from . ' 00:00:00';
        $toTs   = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        $linkSql = $linkId ? ' AND s.link_id = ?' : '';
        $args = $linkId ? [$fromTs, $toTs, $linkId] : [$fromTs, $toTs];
        // Etiket süzgeci: o etikete sahip tüm QR'ların birleşik raporu
        if (!$linkId && $tag !== null && $tag !== '') {
            $linkSql .= ' AND s.link_id IN (SELECT id FROM links WHERE FIND_IN_SET(?, tags) > 0)';
            $args[] = $tag;
        }
        // Kullanıcı kapsamı (Yönetici için boş)
        [$scopeSql, $scopeArgs] = Access::linkIdFilter('s.link_id');
        $linkSql .= $scopeSql;
        array_push($args, ...$scopeArgs);
        $ok = "s.is_bot = 0 AND (s.outcome IS NULL OR s.outcome = 'ok') AND s.scanned_at >= ? AND s.scanned_at < ?" . $linkSql;
        return [$ok, $args, $linkSql, $fromTs, $toTs];
    }

    /** @return array{total:int,unique:int} Yalnızca toplamlar (dönem karşılaştırması için) */
    public static function totals(PDO $pdo, ?int $linkId, string $from, string $to, ?string $tag = null): array {
        [$ok, $args] = self::filter($linkId, $from, $to, $tag);
        $st = $pdo->prepare("SELECT COUNT(*) c, COUNT(DISTINCT CONCAT(IFNULL(s.ip,''),'|',IFNULL(s.user_agent,''))) u FROM scans s WHERE {$ok}");
        $st->execute($args);
        $r = $st->fetch();
        return ['total' => (int)$r['c'], 'unique' => (int)$r['u']];
    }

    /** @return int[] Günlük okutma sayıları (boş günler 0), tarih sırasıyla */
    public static function dailyCounts(PDO $pdo, ?int $linkId, string $from, string $to, ?string $tag = null): array {
        [$ok, $args] = self::filter($linkId, $from, $to, $tag);
        $st = $pdo->prepare("SELECT DATE(s.scanned_at) d, COUNT(*) c FROM scans s WHERE {$ok} GROUP BY d");
        $st->execute($args);
        $map = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        $out = [];
        for ($d = new DateTimeImmutable($from), $end = new DateTimeImmutable($to); $d <= $end; $d = $d->modify('+1 day')) {
            $out[] = (int)($map[$d->format('Y-m-d')] ?? 0);
        }
        return $out;
    }

    /** Aynı uzunlukta, hemen önceki dönem: [başlangıç, bitiş] (Y-m-d) */
    public static function previousRange(string $from, string $to): array {
        $f = new DateTimeImmutable($from);
        $days = (int)$f->diff(new DateTimeImmutable($to))->days + 1;
        return [$f->modify("-{$days} days")->format('Y-m-d'), $f->modify('-1 day')->format('Y-m-d')];
    }

    /** Önceki döneme göre değişim yüzdesi; önceki dönem 0 ise null */
    public static function change(int $now, int $prev): ?int {
        return $prev > 0 ? (int)round(($now - $prev) / $prev * 100) : null;
    }

    /**
     * Sahibine (QR'ı oluşturan kullanıcı) göre okutmalar.
     * @return array<int,array{owner:string,links:int,total:int}>
     */
    public static function byOwner(PDO $pdo, string $from, string $to, ?string $tag = null): array {
        try { $pdo->query('SELECT created_by FROM links LIMIT 1'); } catch (\Throwable $e) { return []; }
        [$ok, $args] = self::filter(null, $from, $to, $tag);
        $st = $pdo->prepare("SELECT l.created_by uid, u.username, COUNT(DISTINCT l.id) n, COUNT(*) c
                             FROM scans s JOIN links l ON l.id = s.link_id LEFT JOIN users u ON u.id = l.created_by
                             WHERE {$ok} GROUP BY l.created_by, u.username ORDER BY c DESC");
        $st->execute($args);
        $out = [];
        foreach ($st as $r) {
            $name = $r['username'] ?? ($r['uid'] ? 'Silinmiş kullanıcı' : 'Bilinmiyor (eski QR)');
            $out[] = ['owner' => $name, 'links' => (int)$r['n'], 'total' => (int)$r['c']];
        }
        return $out;
    }

    /**
     * Hedef adresi değişiklikleri (rapor grafiğindeki işaretler).
     * @return array<int,array{date:string,at:string,from:?string,to:?string,user:?string}>
     */
    public static function targetChanges(PDO $pdo, int $linkId, string $from, string $to): array {
        $st = $pdo->prepare("SELECT created_at, username, details FROM link_events
                             WHERE link_id = ? AND action <> 'create' AND details LIKE '%\"target_url\"%'
                               AND created_at >= ? AND created_at < ? ORDER BY created_at");
        $st->execute([$linkId, $from . ' 00:00:00', (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00']);
        $out = [];
        foreach ($st as $r) {
            $d = json_decode((string)$r['details'], true);
            $t = $d['target_url'] ?? null;
            if (!is_array($t) || count($t) !== 2) continue;
            $out[] = ['date' => substr($r['created_at'], 0, 10), 'at' => $r['created_at'], 'from' => $t[0], 'to' => $t[1], 'user' => $r['username']];
        }
        return $out;
    }

    /**
     * 2-5 QR'ı yan yana: günlük seri, toplam, tekil, en çok okutulan ülke ve cihaz.
     * @param int[] $ids  (kapsam dışındakiler çağıran tarafından elenmiş olmalı)
     * @return array<int,array{daily:array<string,int>,total:int,unique:int,country:?string,device:?string,mobile:int}>
     */
    public static function compare(PDO $pdo, array $ids, string $from, string $to): array {
        $out = [];
        foreach ($ids as $id) {
            $S = self::summary($pdo, (int)$id, $from, $to);
            $mobile = ($S['devices']['Mobil'] ?? 0) + ($S['devices']['Tablet'] ?? 0);
            $out[(int)$id] = [
                'daily' => $S['daily'], 'total' => $S['total'], 'unique' => $S['unique'],
                'country' => array_key_first($S['countries']), 'device' => array_key_first($S['devices']),
                'mobile' => $S['total'] ? (int)round($mobile / $S['total'] * 100) : 0,
                'hours' => $S['hours'],
            ];
        }
        return $out;
    }
}
