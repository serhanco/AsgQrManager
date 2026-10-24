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
     *               daily:array<string,int>,hours:int[],devices:array,os:array,browsers:array,countries:array,referrers:array}
     */
    public static function summary(PDO $pdo, ?int $linkId, string $from, string $to, ?string $tag = null): array {
        $fromTs = $from . ' 00:00:00';
        $toTs   = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        $linkSql = $linkId ? ' AND s.link_id = ?' : '';
        $args = $linkId ? [$fromTs, $toTs, $linkId] : [$fromTs, $toTs];
        // Etiket süzgeci: o etikete sahip tüm QR'ların birleşik raporu
        if (!$linkId && $tag !== null && $tag !== '') {
            $linkSql .= ' AND s.link_id IN (SELECT id FROM links WHERE FIND_IN_SET(?, tags) > 0)';
            $args[] = $tag;
        }
        $ok   = "s.is_bot = 0 AND (s.outcome IS NULL OR s.outcome = 'ok') AND s.scanned_at >= ? AND s.scanned_at < ?" . $linkSql;
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

        $hours = array_fill(0, 24, 0);
        foreach ($q("SELECT HOUR(s.scanned_at) h, COUNT(*) c FROM scans s WHERE {$ok} GROUP BY h") as $r) $hours[(int)$r['h']] = (int)$r['c'];

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
            'daily' => $daily, 'hours' => $hours, 'devices' => $dev, 'os' => $os, 'browsers' => $br,
            'countries' => $countries, 'referrers' => array_slice($refs, 0, 10, true),
        ];
    }
}
