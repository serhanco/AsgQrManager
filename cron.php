<?php
/**
 * cron.php — zamanlanmış görevler. Yalnızca komut satırından çalışır (web'den kapalı).
 *
 * Hostinger hPanel > Gelişmiş > Cron Jobs: her saat (ya da günde bir) şu komut:
 *   /usr/bin/php /home/KULLANICI/public_html/cron.php
 *
 * Seçenekler:
 *   --list          görevleri ve son çalışmalarını göster
 *   --job=broken    yalnızca bu görev (dönemi dolmamış olsa da --force ile)
 *   --force         dönemi dolmamış görevleri de çalıştır
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/lib/bootstrap.php';

$opts = getopt('', ['list', 'job:', 'force']);
$pdo = getPdo();

// Şema güncel değilse önce eklemeli migration'lar (panel girişindekiyle aynı)
$mig = Migrator::run($pdo);
if ($mig['error']) {
    fwrite(STDERR, "Veritabanı güncellemesi: {$mig['error']}\n");
    exit(1);
}
Jobs::reset();
if (!Jobs::available($pdo)) {
    fwrite(STDERR, "Görev tabloları yok.\n");
    exit(1);
}
Settings::set('cron_last_seen', (new DateTimeImmutable('now', Settings::timezone()))->format('Y-m-d H:i:s'));

if (isset($opts['list'])) {
    $last = Jobs::lastRuns($pdo);
    foreach (Jobs::JOBS as $k => [$name]) {
        $p = Jobs::period($k);
        $r = $last[$k] ?? null;
        printf("%-8s %-28s %-9s %-6s %s\n", $k, $name, $p ? Jobs::PERIODS[$p] : 'kapalı', Jobs::enabled($k) ? 'açık' : 'kapalı',
            $r ? "{$r['started_at']} {$r['status']} — {$r['message']}" : 'hiç çalışmadı');
    }
    exit(0);
}

$job = isset($opts['job']) ? (string)$opts['job'] : null;
if ($job !== null && !isset(Jobs::JOBS[$job])) {
    fwrite(STDERR, "Bilinmeyen görev: {$job}\n");
    exit(2);
}

// Aynı anda iki cron çalışmasın
if ((int)$pdo->query("SELECT GET_LOCK('qrm_cron', 0)")->fetchColumn() !== 1) {
    echo "Başka bir çalışma sürüyor.\n";
    exit(0);
}
try {
    $res = Jobs::runDue($pdo, 'cron', $job, isset($opts['force']));
} finally {
    $pdo->query("SELECT RELEASE_LOCK('qrm_cron')");
}
foreach ($res as $k => $r) echo "{$k}: {$r['status']} — {$r['message']}\n";
if (!$res) echo "Zamanı gelen görev yok.\n";
exit(in_array('error', array_column($res, 'status'), true) ? 1 : 0);
