<?php
/**
 * Jobs — zamanlanmış görevler (Hostinger Cron Jobs ile cron.php üzerinden çalışır).
 *
 * Her görevin bir sıklığı vardır (günlük, haftalık, aylık); cron.php saatlik ya da günlük çalıştırılabilir,
 * görev yalnızca dönemi dolunca çalışır. Her görev Ayarlar > Otomasyon'dan açılıp kapatılır.
 * Görevler QR kayıtlarını değiştirmez ve silmez; yalnızca uyarı üretir, e-posta gönderir ya da yedek alır.
 */
class Jobs {

    /** anahtar => [ad, açıklama, sıklık, varsayılan açık mı] */
    public const JOBS = [
        'expiry'  => ['Bitiş uyarısı', '3 gün içinde biten QR\'lar için sahibine ve yöneticiye uyarı (bitince hangi adrese gideceği yazılır).', 'daily', true],
        'broken'  => ['Kırık hedef kontrolü', 'Aktif QR\'ların hedef adresleri açılıyor mu (404, 5xx, alan adı/bağlantı hatası). Düzelince uyarı kendiliğinden kapanır.', 'daily', true],
        'silent'  => ['Sessiz QR uyarısı', 'Okutması duran ya da sert düşen QR\'lar ve hiç okutulmayan etkinlik QR\'ları.', 'weekly', true],
        'backup'  => ['Veritabanı yedeği', 'QR, kartvizit, şablon, kullanıcı ve ayar tablolarının yedeği (logs/backups, son 14 kopya; okutma kayıtları hariç).', 'daily', true],
        'cleanup' => ['Temizlik', 'Eski görev kayıtları, giriş denemeleri ve 1 yıldan eski kapanmış uyarılar silinir. QR ve okutma kayıtlarına dokunulmaz.', 'weekly', true],
        'digest'  => ['Uyarı e-postası', 'Yeni uyarılar QR sahibine ve yöneticilere tek e-postada gider (E-posta açıksa).', 'daily', true],
        'report'  => ['Periyodik rapor e-postası', 'Geçen hafta / ayın özeti ve Excel raporu: yöneticiye tüm QR\'lar, editöre kendi QR\'ları (E-posta açıksa).', 'report', true],
    ];

    public const PERIODS = ['daily' => 'Günlük', 'weekly' => 'Haftalık', 'monthly' => 'Aylık'];
    public const BACKUP_KEEP = 14;
    public const EXPIRY_DAYS = 3;

    private static ?bool $available = null;

    public static function available(PDO $pdo): bool {
        if (self::$available === null) {
            try { $pdo->query('SELECT 1 FROM job_runs LIMIT 1'); self::$available = Notifications::available($pdo); }
            catch (\Throwable $e) { self::$available = false; }
        }
        return self::$available;
    }

    public static function reset(): void { self::$available = null; Notifications::reset(); }

    public static function enabled(string $job): bool {
        return Settings::get('job_' . $job, self::JOBS[$job][3] ? '1' : '0') === '1';
    }

    /** Görevin sıklığı; rapor e-postası Ayarlar'daki seçime bağlı (kapalı / haftalık / aylık) */
    public static function period(string $job): ?string {
        $p = self::JOBS[$job][2];
        if ($p !== 'report') return $p;
        $f = Settings::get('report_frequency', 'off');
        return in_array($f, ['weekly', 'monthly'], true) ? $f : null;
    }

    private static function now(): DateTimeImmutable {
        return new DateTimeImmutable('now', Settings::timezone());
    }

    /** Dönem anahtarı: aynı dönemde görev bir kez başarıyla çalışır */
    public static function periodKey(string $period, DateTimeImmutable $t): string {
        return match ($period) { 'weekly' => $t->format('o-\WW'), 'monthly' => $t->format('Y-m'), default => $t->format('Y-m-d') };
    }

    /** @return array<string,array> görev => son çalışma satırı */
    public static function lastRuns(PDO $pdo): array {
        if (!self::available($pdo)) return [];
        $rows = $pdo->query('SELECT r.* FROM job_runs r JOIN (SELECT job, MAX(id) id FROM job_runs GROUP BY job) m ON m.id = r.id')->fetchAll();
        return array_column($rows, null, 'job');
    }

    public static function lastOk(PDO $pdo, string $job): ?string {
        $st = $pdo->prepare("SELECT MAX(started_at) FROM job_runs WHERE job = ? AND status IN ('ok','skipped')");
        $st->execute([$job]);
        return $st->fetchColumn() ?: null;
    }

    public static function isDue(PDO $pdo, string $job, ?DateTimeImmutable $now = null): bool {
        $p = self::period($job);
        if ($p === null || !self::enabled($job)) return false;
        $last = self::lastOk($pdo, $job);
        if ($last === null) return true;
        $now ??= self::now();
        return self::periodKey($p, new DateTimeImmutable($last, Settings::timezone())) !== self::periodKey($p, $now);
    }

    /**
     * Zamanı gelen görevleri çalıştırır.
     * @param ?string $only yalnızca bu görev   @param bool $force dönemi dolmamış olsa da çalıştır
     * @return array<string,array{status:string,items:int,message:string}>
     */
    public static function runDue(PDO $pdo, string $trigger = 'cron', ?string $only = null, bool $force = false): array {
        $out = [];
        foreach (array_keys(self::JOBS) as $job) {
            if ($only !== null && $job !== $only) continue;
            if (!$force && !self::isDue($pdo, $job)) continue;
            $out[$job] = self::run($pdo, $job, $trigger);
        }
        return $out;
    }

    /** @return array{status:string,items:int,message:string} */
    public static function run(PDO $pdo, string $job, string $trigger = 'cron'): array {
        $pdo->prepare('INSERT INTO job_runs (job, started_at, trigger_by) VALUES (?, ?, ?)')
            ->execute([$job, self::now()->format('Y-m-d H:i:s'), mb_substr($trigger, 0, 64)]);
        $runId = (int)$pdo->lastInsertId();
        $t0 = microtime(true);
        try {
            $r = self::{'job' . ucfirst($job)}($pdo);
            $res = ['status' => $r['status'] ?? 'ok', 'items' => (int)($r['items'] ?? 0), 'message' => (string)($r['message'] ?? '')];
        } catch (\Throwable $e) {
            Logger::error("Görev hatası ({$job}): " . $e->getMessage(), $e->getFile() . ':' . $e->getLine());
            $res = ['status' => 'error', 'items' => 0, 'message' => $e->getMessage()];
        }
        $res['message'] = trim($res['message'] . sprintf(' (%.1f sn)', microtime(true) - $t0));
        $pdo->prepare('UPDATE job_runs SET finished_at = ?, status = ?, items = ?, message = ? WHERE id = ?')
            ->execute([self::now()->format('Y-m-d H:i:s'), $res['status'], $res['items'], mb_substr($res['message'], 0, 500), $runId]);
        return $res;
    }

    // ─── Ortak ───────────────────────────────────────────────────────────────

    /** Yayında (aktif, arşivde değil, başlamış, bitmemiş) QR'lar için SQL koşulu */
    private static function liveSql(string $now): array {
        return ["archived_at IS NULL AND is_active = 1 AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at > ?)", [$now, $now]];
    }

    private static function hasColumn(PDO $pdo, string $col): bool {
        try { $pdo->query("SELECT `{$col}` FROM links LIMIT 1"); return true; } catch (\Throwable $e) { return false; }
    }

    private static function label(array $l): string {
        return trim((string)($l['title'] ?? '')) !== '' ? $l['title'] . ' (' . $l['slug'] . ')' : (string)$l['slug'];
    }

    // ─── Görevler ────────────────────────────────────────────────────────────

    /** 5.2 — 3 gün içinde bitecek QR'lar */
    private static function jobExpiry(PDO $pdo): array {
        $now = self::now();
        $st = $pdo->prepare('SELECT * FROM links WHERE archived_at IS NULL AND is_active = 1 AND ends_at > ? AND ends_at <= ?');
        $st->execute([$now->format('Y-m-d H:i:s'), $now->modify('+' . self::EXPIRY_DAYS . ' days')->format('Y-m-d H:i:s')]);
        $n = 0;
        foreach ($st as $l) {
            $after = $l['after_end_url'] ?: ($l['fallback_url'] ?: (Settings::get('fallback_url') ?: 'bilgi sayfası (“bu QR artık aktif değil”)'));
            $when = (new DateTimeImmutable($l['ends_at']))->format('d.m.Y H:i');
            $n += (int)Notifications::raise($pdo, 'expiry', (int)$l['id'], 'expiry:' . $l['id'] . ':' . $l['ends_at'],
                self::label($l) . " {$when} tarihinde bitiyor",
                "Bitince okutanlar şuraya gidecek: {$after}. Süreyi uzatmak için QR'ı düzenleyip bitiş tarihini değiştirin.");
        }
        return ['items' => $n, 'message' => "{$n} yeni uyarı"];
    }

    /** 5.3 — hedef adres kontrolü (paralel, 10 sn zaman aşımı) */
    private static function jobBroken(PDO $pdo): array {
        if (!function_exists('curl_multi_init')) return ['status' => 'skipped', 'message' => 'Sunucuda cURL yok.'];
        $now = self::now()->format('Y-m-d H:i:s');
        [$live, $args] = self::liveSql($now);
        if (self::hasColumn($pdo, 'qr_type')) $live .= " AND (qr_type IS NULL OR qr_type = 'link')";
        $st = $pdo->prepare("SELECT id, slug, title, target_url FROM links WHERE {$live} ORDER BY id LIMIT 500");
        $st->execute($args);
        $links = $st->fetchAll();
        $urls = array_unique(array_column($links, 'target_url'));
        $res = self::checkUrls($urls);
        // İlk denemede başarısız olanlar bir kez daha denenir (geçici hata uyarısı olmasın)
        $retry = array_keys(array_filter($res, fn($r) => !$r['ok']));
        if ($retry) $res = array_merge($res, self::checkUrls($retry));

        // Hiçbir adres açılmıyorsa sorun büyük olasılıkla sunucunun dış bağlantısındadır: QR başına uyarı yerine tek sistem uyarısı
        $netFail = count($urls) >= 3 && !array_filter($res, fn($r) => $r['ok'] || $r['code'] > 0);
        if ($netFail) {
            $n = (int)Notifications::raise($pdo, 'system', null, 'netfail:' . self::now()->format('Y-m-d'), 'Hedef adres kontrolü yapılamadı',
                'Sunucu hiçbir hedef adrese bağlanamadı (' . (reset($res)['why'] ?? '') . '). QR\'lar etkilenmemiş olabilir; sorun sürerse barındırma desteğine danışın.');
            return ['status' => 'error', 'items' => $n, 'message' => count($urls) . ' adresin hiçbirine bağlanılamadı (sunucu dış bağlantısı?)'];
        }
        $raised = $fixed = $bad = 0;
        foreach ($links as $l) {
            $r = $res[$l['target_url']] ?? null;
            if (!$r) continue;
            if ($r['ok']) { $fixed += Notifications::resolveFor($pdo, 'broken', (int)$l['id']); continue; }
            $bad++;
            $raised += (int)Notifications::raise($pdo, 'broken', (int)$l['id'], 'broken:' . $l['id'] . ':' . md5($l['target_url']),
                self::label($l) . ' — hedef adres açılmıyor (' . $r['why'] . ')',
                "Hedef: {$l['target_url']}. Okutanlar hata sayfası görüyor olabilir. Adresi düzeltin ya da QR'ı yedek hedefe yönlendirin.");
        }
        return ['items' => $raised, 'message' => count($urls) . " adres kontrol edildi, {$bad} sorunlu, {$raised} yeni uyarı, {$fixed} düzeldi"];
    }

    /**
     * @param string[] $urls
     * @return array<string,array{ok:bool,code:int,why:string}>
     */
    public static function checkUrls(array $urls, int $timeout = 10): array {
        $out = [];
        foreach (array_chunk(array_values($urls), 10) as $chunk) {
            $mh = curl_multi_init();
            $hs = [];
            foreach ($chunk as $u) {
                $h = curl_init($u);
                curl_setopt_array($h, [
                    CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => $timeout,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; AcibademQRManager-LinkCheck/1.0)',
                    CURLOPT_HTTPHEADER => ['Accept: text/html,*/*;q=0.8'],
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    // Gövdenin yalnızca ilk parçası okunur; durum kodu yeterli
                    CURLOPT_WRITEFUNCTION => fn($ch, $d) => -1,
                ]);
                curl_multi_add_handle($mh, $h);
                $hs[$u] = $h;
            }
            do {
                $s = curl_multi_exec($mh, $running);
                if ($running) curl_multi_select($mh, 1.0);
            } while ($running && $s === CURLM_OK);
            while ($info = curl_multi_info_read($mh)) {
                foreach ($hs as $u => $h) if ($h === $info['handle']) $out[$u]['errno'] = $info['result'];
            }
            foreach ($hs as $u => $h) {
                $code = (int)curl_getinfo($h, CURLINFO_RESPONSE_CODE);
                $errno = (int)($out[$u]['errno'] ?? curl_errno($h));
                // 23 = yazma durduruldu (bilerek); 401/403/429 genelde bot korumasıdır, kırık sayılmaz
                if ($code > 0 && ($code < 400 || in_array($code, [401, 403, 405, 429], true))) {
                    $out[$u] = ['ok' => true, 'code' => $code, 'why' => ''];
                } elseif ($code >= 400) {
                    $out[$u] = ['ok' => false, 'code' => $code, 'why' => 'HTTP ' . $code];
                } else {
                    $why = match ($errno) { 6 => 'alan adı bulunamadı', 7 => 'bağlantı kurulamadı', 28 => 'zaman aşımı', 35, 51, 60 => 'SSL sertifikası hatası', 52, 56 => 'bağlantı kesildi', default => 'bağlantı hatası #' . $errno };
                    $out[$u] = ['ok' => false, 'code' => 0, 'why' => $why];
                }
                curl_multi_remove_handle($mh, $h);
                curl_close($h);
            }
            curl_multi_close($mh);
        }
        return $out;
    }

    /** 5.4 — okutması duran / sert düşen QR ve hiç okutulmayan etkinlik QR'ı (haftalık) */
    private static function jobSilent(PDO $pdo): array {
        $now = self::now();
        $nowS = $now->format('Y-m-d H:i:s');
        $week = $now->format('o-\WW');
        [$live, $args] = self::liveSql($nowS);
        $d7 = $now->modify('-7 days')->format('Y-m-d H:i:s');
        $d35 = $now->modify('-35 days')->format('Y-m-d H:i:s');
        $st = $pdo->prepare("SELECT l.id, l.slug, l.title, l.category, l.created_at, l.scan_count,
                    (SELECT COUNT(*) FROM scans s WHERE s.link_id = l.id AND s.is_bot = 0 AND s.scanned_at >= ?) w1,
                    (SELECT COUNT(*) FROM scans s WHERE s.link_id = l.id AND s.is_bot = 0 AND s.scanned_at >= ? AND s.scanned_at < ?) w4
                 FROM links l WHERE {$live} AND l.created_at <= ?");
        $st->execute(array_merge([$d7, $d35, $d7], $args, [$now->modify('-14 days')->format('Y-m-d H:i:s')]));
        $n = 0;
        foreach ($st as $l) {
            $avg = (int)$l['w4'] / 4;
            if ((int)$l['w1'] === 0 && (int)$l['w4'] >= 10) {
                $title = self::label($l) . ' — son 7 günde hiç okutulmadı';
                $body = "Önceki 4 haftada {$l['w4']} okutma vardı. Materyal kaldırılmış ya da QR zarar görmüş olabilir.";
            } elseif ($avg >= 20 && (int)$l['w1'] < $avg * 0.25) {
                $title = self::label($l) . ' — okutma sert düştü';
                $body = sprintf('Son 7 gün: %d, önceki 4 haftanın haftalık ortalaması: %d.', $l['w1'], round($avg));
            } elseif ($l['category'] === 'event' && (int)$l['scan_count'] === 0) {
                $title = self::label($l) . ' — etkinlik QR\'ı hiç okutulmadı';
                $body = 'QR 14 günden uzun süredir yayında ama hiç okutulmadı. Basıldığını ve doğru yerde olduğunu kontrol edin.';
            } else {
                continue;
            }
            $n += (int)Notifications::raise($pdo, 'silent', (int)$l['id'], "silent:{$l['id']}:{$week}", $title, $body);
        }
        return ['items' => $n, 'message' => "{$n} yeni uyarı"];
    }

    /** 5.7 — tablo yedeği (okutmalar hariç), gzip, son 14 kopya */
    private static function jobBackup(PDO $pdo): array {
        $dir = ROOT_DIR . '/logs/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return ['status' => 'error', 'message' => 'logs/backups klasörü oluşturulamadı.'];
        $tables = ['links', 'vcards', 'qr_templates', 'users', 'user_scopes', 'settings', 'link_requests', 'api_tokens'];
        $sql = '-- Acıbadem QR Code Manager: gecelik yedek (' . date('c') . ")\n-- Geri yükleme: önce tabloyu boşaltmadan, yalnızca eksik kayıtlar için kullanın (INSERT IGNORE).\n";
        $rows = 0;
        foreach ($tables as $t) {
            try { $res = $pdo->query("SELECT * FROM `{$t}`"); } catch (\Throwable $e) { continue; }
            $sql .= "\n-- {$t}\n";
            foreach ($res as $row) {
                $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
                $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row));
                $sql .= "INSERT IGNORE INTO `{$t}` ($cols) VALUES ($vals);\n";
                $rows++;
            }
        }
        $gz = function_exists('gzencode');
        $file = $dir . '/daily-' . self::now()->format('Ymd-His') . '.sql' . ($gz ? '.gz' : '');
        if (@file_put_contents($file, $gz ? gzencode($sql, 6) : $sql) === false) return ['status' => 'error', 'message' => 'Yedek dosyası yazılamadı.'];
        @chmod($file, 0600);
        $old = array_merge(glob($dir . '/daily-*.sql') ?: [], glob($dir . '/daily-*.sql.gz') ?: []);
        sort($old);
        foreach (array_slice($old, 0, max(0, count($old) - self::BACKUP_KEEP)) as $f) @unlink($f);
        return ['items' => $rows, 'message' => basename($file) . ", {$rows} kayıt"];
    }

    /** 5.8 — eski görev kayıtları, giriş denemeleri, kapanmış eski uyarılar */
    private static function jobCleanup(PDO $pdo): array {
        $n = $pdo->exec("DELETE FROM job_runs WHERE started_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
        try { $n += $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"); } catch (\Throwable $e) {}
        $n += $pdo->exec("DELETE FROM notifications WHERE resolved_at IS NOT NULL AND resolved_at < DATE_SUB(NOW(), INTERVAL 365 DAY)");
        return ['items' => (int)$n, 'message' => "{$n} eski kayıt silindi"];
    }

    // ─── E-posta ─────────────────────────────────────────────────────────────

    /** @return array<int,array> e-postası olan aktif kullanıcılar */
    private static function recipients(PDO $pdo): array {
        try {
            return $pdo->query("SELECT * FROM users WHERE is_active = 1 AND email IS NOT NULL AND email <> '' AND role IN ('admin','editor')")->fetchAll();
        } catch (\Throwable $e) { return []; }
    }

    /** Ek alıcılar (Ayarlar > E-posta), yalnızca yönetici kopyası alır */
    public static function extraRecipients(): array {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', Settings::get('mail_extra_to', '')) ?: []),
            fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
    }

    private static function mailLayout(string $title, string $inner): string {
        return '<!doctype html><html lang="tr"><body style="margin:0;background:#F3F5FA;font-family:Arial,sans-serif;color:#0E1838">'
             . '<div style="max-width:640px;margin:0 auto;padding:24px">'
             . '<div style="font-size:11px;letter-spacing:.28em;color:#1C2561;font-weight:bold">ACIBADEM</div>'
             . '<div style="font-size:15px;font-weight:bold;color:#0A3A86;margin-bottom:16px">QR Code Manager</div>'
             . '<div style="background:#fff;border-radius:12px;padding:20px;border:1px solid #E2E7F1">'
             . '<h1 style="font-size:18px;margin:0 0 12px;color:#1C2561">' . e($title) . '</h1>' . $inner . '</div>'
             . '<p style="font-size:11px;color:#5D6886;margin-top:12px">Bu e-posta otomatik gönderildi. Ayarlar &gt; E-posta bölümünden kapatılabilir.</p>'
             . '</div></body></html>';
    }

    /** 5.2-5.4 e-postası — yeni uyarılar: yöneticiye hepsi, editöre kendi QR'larınınki */
    private static function jobDigest(PDO $pdo): array {
        if (!Mailer::enabled()) return ['status' => 'skipped', 'message' => 'E-posta kapalı'];
        $items = $pdo->query('SELECT n.*, l.slug, l.created_by FROM notifications n LEFT JOIN links l ON l.id = n.link_id
                              WHERE n.emailed_at IS NULL AND n.resolved_at IS NULL ORDER BY n.kind, n.created_at')->fetchAll();
        if (!$items) return ['status' => 'ok', 'message' => 'Yeni uyarı yok'];
        $sent = 0; $errs = [];
        $send = function (array $to, array $list) use (&$sent, &$errs) {
            $rows = '';
            foreach ($list as $n) {
                [$label, , $sev] = Notifications::KINDS[$n['kind']] ?? [$n['kind'], '', 'info'];
                $color = ['danger' => '#DC2626', 'warning' => '#B45309'][$sev] ?? '#1F4BA8';
                $url = siteUrl() . '/admin/' . ($n['link_id'] ? 'qr-view?slug=' . urlencode((string)$n['slug']) : 'notifications');
                $rows .= '<div style="border-left:3px solid ' . $color . ';padding:6px 10px;margin:0 0 10px">'
                       . '<div style="font-size:11px;color:' . $color . ';font-weight:bold">' . e($label) . '</div>'
                       . '<div style="font-weight:bold"><a href="' . e($url) . '" style="color:#0E1838">' . e($n['title']) . '</a></div>'
                       . ($n['body'] ? '<div style="font-size:13px;color:#5D6886">' . e($n['body']) . '</div>' : '') . '</div>';
            }
            $html = self::mailLayout(count($list) . ' yeni QR uyarısı', $rows
                  . '<p><a href="' . e(siteUrl() . '/admin/notifications') . '" style="background:#1F4BA8;color:#fff;padding:8px 14px;border-radius:8px;text-decoration:none">Tüm uyarılar</a></p>');
            $err = Mailer::send($to, 'QR uyarıları: ' . count($list) . ' yeni', $html);
            $err === null ? $sent++ : $errs[] = $err;
        };
        $admins = [];
        foreach (self::recipients($pdo) as $u) {
            if ($u['role'] === 'admin') { $admins[] = $u['email']; continue; }
            $own = array_filter($items, fn($n) => (int)($n['created_by'] ?? 0) === (int)$u['id']);
            if ($own) $send([$u['email']], array_values($own));
        }
        $admins = array_merge($admins, self::extraRecipients());
        if ($admins) $send($admins, $items);
        // Hiçbiri gidemediyse (SMTP hatası) işaretlenmez; ertesi gün yeniden denenir
        if ($sent || !$errs) {
            $ids = implode(',', array_map('intval', array_column($items, 'id')));
            $pdo->exec("UPDATE notifications SET emailed_at = NOW() WHERE id IN ({$ids})");
        }
        return ['status' => $errs ? 'error' : 'ok', 'items' => $sent, 'message' => count($items) . " uyarı, {$sent} e-posta" . ($errs ? '; hata: ' . $errs[0] : '')];
    }

    /** Rapor dönemi: geçen hafta (Pzt-Paz) ya da geçen ay; [from, to, başlık] */
    public static function reportRange(string $freq, ?DateTimeImmutable $now = null): array {
        $now ??= self::now();
        if ($freq === 'monthly') {
            $first = $now->modify('first day of last month');
            return [$first->format('Y-m-01'), $first->format('Y-m-t'), self::monthName((int)$first->format('n')) . ' ' . $first->format('Y')];
        }
        $mon = $now->modify('monday last week');
        return [$mon->format('Y-m-d'), $mon->modify('+6 days')->format('Y-m-d'), $mon->format('d.m') . ' – ' . $mon->modify('+6 days')->format('d.m.Y') . ' haftası'];
    }

    private static function monthName(int $m): string {
        return ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'][$m - 1];
    }

    /** 5.1 — periyodik rapor e-postası (özet + Excel eki) */
    private static function jobReport(PDO $pdo): array {
        $freq = self::period('report');
        if ($freq === null) return ['status' => 'skipped', 'message' => 'Rapor e-postası kapalı'];
        if (!Mailer::enabled()) return ['status' => 'skipped', 'message' => 'E-posta kapalı'];
        [$from, $to, $label] = self::reportRange($freq);
        $sent = 0; $errs = [];
        $sendFor = function (?array $user, array $to_) use ($pdo, $from, $to, $label, &$sent, &$errs) {
            $saved = Access::context();
            Access::setUser($user, $pdo);
            try {
                $scope = $user && $user['role'] !== 'admin' ? 'QR\'larınız' : 'Tüm QR\'lar';
                $R = ReportExport::collect($pdo, $from, $to, $scope, 'otomatik rapor');
                $html = self::mailLayout('QR raporu — ' . $label, self::reportHtml($R));
                $att = Xlsx::available() ? [['name' => ReportExport::fileName($R, 'xlsx'), 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'data' => Xlsx::build(ReportExport::sheets($R))]] : [];
                $err = Mailer::send($to_, 'QR raporu: ' . $label, $html, '', $att);
                $err === null ? $sent++ : $errs[] = $err;
            } finally {
                Access::restore($saved);
            }
        };
        $admins = [];
        foreach (self::recipients($pdo) as $u) {
            if ($u['role'] === 'admin') { $admins[] = $u['email']; continue; }
            if (Settings::get('report_editors', '1') === '1') $sendFor($u, [$u['email']]);
        }
        $admins = array_merge($admins, self::extraRecipients());
        if ($admins) $sendFor(null, $admins);
        if (!$sent && !$errs) return ['status' => 'skipped', 'message' => 'E-posta adresi olan kullanıcı yok'];
        return ['status' => $errs ? 'error' : 'ok', 'items' => $sent, 'message' => "{$label}: {$sent} e-posta" . ($errs ? '; hata: ' . $errs[0] : '')];
    }

    public static function reportHtml(array $R): string {
        $kpi = function (string $l, int $v, ?int $p) {
            $c = ReportExport::changeText($v, $p);
            $col = $c !== '' && $c[0] === '+' ? '#15803D' : ($c !== '' && $c[0] === '-' ? '#DC2626' : '#5D6886');
            return '<td style="padding:10px;border:1px solid #E2E7F1;border-radius:8px;width:50%"><div style="font-size:12px;color:#5D6886">' . e($l) . '</div>'
                 . '<div style="font-size:22px;font-weight:bold;color:#1C2561">' . formatCount($v) . '</div>'
                 . ($c !== '' ? '<div style="font-size:12px;color:' . $col . '">' . e($c) . ' önceki döneme göre (' . formatCount((int)$p) . ')</div>' : '') . '</td>';
        };
        $h = '<p style="color:#5D6886;margin:0 0 12px">' . e($R['scope']) . ' · ' . e(date('d.m.Y', strtotime($R['from']))) . ' – ' . e(date('d.m.Y', strtotime($R['to']))) . '</p>'
           . '<table style="width:100%;border-collapse:separate;border-spacing:6px"><tr>' . $kpi('Okutma', $R['total'], $R['prevTotal']) . $kpi('Tahmini tekil ziyaretçi', $R['unique'], $R['prevUnique']) . '</tr></table>';
        if ($R['links']) {
            $h .= '<h2 style="font-size:14px;color:#1C2561;margin:16px 0 6px">En çok okutulan QR\'lar</h2><table style="width:100%;border-collapse:collapse;font-size:13px">';
            foreach (array_slice($R['links'], 0, 5) as $l) {
                $h .= '<tr><td style="padding:4px 0;border-bottom:1px solid #EEF1F7"><b>' . e($l['slug']) . '</b> ' . e((string)$l['title']) . '</td>'
                    . '<td style="text-align:right;border-bottom:1px solid #EEF1F7">' . formatCount((int)$l['c']) . '</td></tr>';
            }
            $h .= '</table>';
        }
        if ($p = ReportExport::peak($R['heat'])) $h .= sprintf('<p style="font-size:13px">En yoğun zaman: <b>%s %02d:00-%02d:00</b></p>', e($p[0]), $p[1], ($p[1] + 1) % 24);
        return $h . '<p style="font-size:13px">Ayrıntılar ekteki Excel dosyasında. <a href="' . e(siteUrl() . '/admin/report?range=custom&from=' . $R['from'] . '&to=' . $R['to']) . '">Panelde aç</a></p>';
    }

    /** Cron komutu (Hostinger hPanel > Gelişmiş > Cron Jobs) */
    public static function cronCommand(): string {
        return '/usr/bin/php ' . ROOT_DIR . '/cron.php';
    }
}
