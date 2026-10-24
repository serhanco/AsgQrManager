<?php
/**
 * tests/run.php — bağımlılıksız test çalıştırıcı.
 *
 *   php tests/run.php                      → mantık testleri (config.php'deki TEST veritabanı gerekir)
 *   php tests/run.php --dump=prod.sql      → ayrıca üretim dökümünü yükleyip migration'ın
 *                                            hiçbir kaydı kaybettirmediğini doğrular
 *
 * GÜVENLİK: Veritabanı adında "test" geçmiyorsa çalışmayı reddeder; canlı veritabanında ASLA çalıştırmayın.
 * (Canlıya gönderilmez: deploy.yml tests/** dosyalarını hariç tutar.)
 */
require_once __DIR__ . '/../lib/bootstrap.php';

if (stripos(DB_NAME, 'test') === false) {
    fwrite(STDERR, "Reddedildi: DB_NAME ('" . DB_NAME . "') bir test veritabanı değil.\n");
    exit(2);
}

$pass = 0; $fail = 0;
function ok(bool $cond, string $name): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $name\n"; } else { $fail++; echo "  ✗ $name\n"; }
}
function section(string $s): void { echo "\n$s\n"; }

$dump = null;
foreach ($argv as $a) if (str_starts_with($a, '--dump=')) $dump = substr($a, 7);

// ─── Üretim dökümü + migration ────────────────────────────────────────────────
if ($dump) {
    section('Migration — üretim kopyası');
    $reset = getPdo();
    $reset->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($reset->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $reset->exec("DROP TABLE `{$t}`");
    $reset->exec('SET FOREIGN_KEY_CHECKS=1');
    $load = sprintf('mysql -h%s -P%d -u%s %s %s < %s 2>&1', escapeshellarg(DB_HOST), DB_PORT, escapeshellarg(DB_USER),
        DB_PASS !== '' ? '-p' . escapeshellarg(DB_PASS) : '', escapeshellarg(DB_NAME), escapeshellarg($dump));
    shell_exec($load);

    $pdo = getPdo();
    $before = [
        'links' => (int)$pdo->query('SELECT COUNT(*) FROM links')->fetchColumn(),
        'scans' => (int)$pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn(),
        'sum'   => (int)$pdo->query('SELECT COALESCE(SUM(scan_count),0) FROM links')->fetchColumn(),
        'slugs' => $pdo->query('SELECT id, slug, target_url, is_active FROM links ORDER BY id')->fetchAll(),
    ];
    ok($before['links'] > 0, "döküm yüklendi ({$before['links']} link, {$before['scans']} okutma)");

    ok(count(Migrator::pending($pdo)) > 0, 'eski şemada bekleyen migration var');
    Migrator::run($pdo);
    ok(count(Migrator::pending($pdo)) === 0, 'migration sonrası bekleyen yok');
    Migrator::run($pdo); // idempotent
    ok(count(Migrator::pending($pdo)) === 0, 'ikinci çalıştırma zararsız (idempotent)');

    ok((int)$pdo->query('SELECT COUNT(*) FROM links')->fetchColumn() === $before['links'], 'link sayısı korundu');
    ok((int)$pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn() === $before['scans'], 'okutma kayıtları korundu');
    ok((int)$pdo->query('SELECT SUM(scan_count) FROM links')->fetchColumn() === $before['sum'], 'okutma sayaçları korundu');
    $after = $pdo->query('SELECT id, slug, target_url, is_active FROM links ORDER BY id')->fetchAll();
    ok($after == $before['slugs'], 'kısa ad / hedef URL / aktiflik birebir aynı');
    ok((int)$pdo->query('SELECT COUNT(*) FROM links WHERE archived_at IS NOT NULL')->fetchColumn() === 0, 'hiçbir eski link arşivlenmedi');

    // Her eski kısa ad hâlâ aynı yere yönlendiriyor mu?
    $bad = 0;
    foreach ($pdo->query('SELECT * FROM links')->fetchAll() as $l) {
        $r = Redirector::resolve($l);
        $expected = $l['is_active'] ? $l['target_url'] : null;
        if ($l['is_active'] && ($r['action'] !== 'redirect' || $r['url'] !== $expected)) $bad++;
    }
    ok($bad === 0, 'aktif eski linklerin tamamı eski hedefe yönleniyor');
}

$pdo = getPdo();
if (Migrator::pending($pdo)) Migrator::run($pdo);

// ─── Redirector ───────────────────────────────────────────────────────────────
section('Redirector');
$tz  = Settings::timezone();
$now = new DateTimeImmutable('2026-06-15 12:00:00', $tz);
$base = ['target_url' => 'https://acibadem.world/a', 'is_active' => 1, 'slug' => 'x'];
$res = fn(array $o) => Redirector::resolve($o + $base, $now);

ok($res([])['url'] === 'https://acibadem.world/a' && $res([])['outcome'] === 'ok', 'aktif link hedefe gider');
ok($res(['is_active' => 0])['action'] === 'notfound' || $res(['is_active' => 0])['outcome'] === 'fallback', 'pasif link hedefe GİTMEZ');
ok($res(['archived_at' => '2026-01-01 00:00:00'])['outcome'] !== 'ok', 'arşivli link hedefe GİTMEZ');
ok($res(['starts_at' => '2026-07-01 00:00:00'])['state'] === 'scheduled', 'başlangıcı gelmemiş: zamanlanmış');
ok($res(['ends_at' => '2026-06-01 00:00:00'])['state'] === 'expired', 'bitişi geçmiş: süresi dolmuş');
ok($res(['ends_at' => '2026-06-01 00:00:00', 'after_end_url' => 'https://acibadem.world/b'])['url'] === 'https://acibadem.world/b', 'bitiş sonrası adrese gider');
ok($res(['is_active' => 0, 'fallback_url' => 'https://acibadem.world/f'])['url'] === 'https://acibadem.world/f', 'yedek hedef kullanılır');
ok($res(['utm_enabled' => 1, 'utm_campaign' => 'k'])['url'] === 'https://acibadem.world/a?utm_source=qr&utm_medium=print&utm_campaign=k', 'UTM eklenir');
ok($res(['target_url' => 'https://a.com/?x=1', 'utm_enabled' => 1])['url'] === 'https://a.com/?x=1&utm_source=qr&utm_medium=print&utm_campaign=x', 'UTM mevcut sorguyu korur');
ok($res(['target_url' => 'https://a.com/?utm_source=bayi', 'utm_enabled' => 1])['url'] === 'https://a.com/?utm_source=bayi&utm_medium=print&utm_campaign=x', 'UTM var olan parametrenin üstüne yazmaz');
ok(Redirector::resolve(['target_url' => 'https://a.com', 'is_active' => 1, 'slug' => 's'], $now)['url'] === 'https://a.com', 'eski satır (yeni sütunsuz) çalışır');

// ─── LinkService ──────────────────────────────────────────────────────────────
section('LinkService');
ok(LinkService::normalizeHex('abc') === '#AABBCC', 'kısa hex genişler');
ok(LinkService::normalizeHex('zzz') === null, 'geçersiz hex reddedilir');
ok(LinkService::colorError('#000000', '#FFFFFF') === null, 'siyah/beyaz geçerli');
ok(LinkService::colorError('#0B2A5B', '#FFFFFF') === null, 'lacivert/beyaz geçerli');
ok(LinkService::colorError('#CCCCCC', '#FFFFFF') !== null, 'düşük kontrast reddedilir');
ok(LinkService::colorError('#FFFFFF', '#000000') !== null, 'ters (açık üstüne koyu değil) renk reddedilir');
[$c, $e] = LinkService::normalize(['target_url' => 'javascript:alert(1)']);
ok(count($e) > 0, 'javascript: URL reddedilir');
[$c, $e] = LinkService::normalize(['target_url' => 'https://acibadem.world', 'tags' => ' Ofis, ofis ,Dubai,, ']);
ok(!$e && $c['tags'] === 'Ofis,Dubai', 'etiketler temizlenir ve tekilleştirilir');
[$c, $e] = LinkService::normalize(['target_url' => 'https://a.com', 'starts_at' => '2026-02-01 10:00', 'ends_at' => '2026-01-01 10:00']);
ok(count($e) > 0, 'bitiş başlangıçtan önce olamaz');
[$c, $e] = LinkService::normalize(['category' => 'nope', 'target_url' => 'https://a.com']);
ok(count($e) > 0, 'geçersiz kategori reddedilir');

// ─── Etiketler ────────────────────────────────────────────────────────────────
section('Etiketler');
ok(LinkService::tagKey('İstanbul') === LinkService::tagKey('istanbul') && LinkService::tagKey('Istanbul') === LinkService::tagKey('İSTANBUL'), 'İstanbul / istanbul / Istanbul aynı etiket sayılır');
ok(LinkService::normalizeTags('Afiş, AFİŞ, afis') === 'Afiş', 'aksan/harf farkı tek etikete iner');
ok(LinkService::normalizeTags('istanbul, kongre', ['istanbul' => 'İstanbul']) === 'İstanbul,kongre', 'mevcut etiketin yazımı korunur');
ok(LinkService::normalizeTags(implode(',', range(1, 15))) === implode(',', range(1, 10)), 'en fazla 10 etiket');
$long = LinkService::normalizeTags(implode(',', array_map(fn($i) => str_repeat(chr(97 + $i), 29), range(0, 9))));
ok(strlen((string)$long) <= 255, 'toplam uzunluk 255 karakteri aşmaz');

$tpdo = getPdo();
$tpdo->exec("DELETE FROM links WHERE slug LIKE 'tagtest%'");
$actor = ['id' => null, 'name' => 'test'];
$mk = fn(string $slug, string $tags) => LinkService::create($tpdo, ['target_url' => 'https://acibadem.world', 'tags' => $tags ?: null], $slug, $actor);
$l1 = $mk('tagtest1', 'ttUK,ttPoster'); $l2 = $mk('tagtest2', 'ttPoster'); $l3 = $mk('tagtest3', '');
ok(LinkService::bulkTags($tpdo, [$l2['id'], $l3['id']], ['ttuk', 'ttKongre-2027'], [], $actor) === 2, 'toplu etiket ekleme iki QR\'ı günceller');
ok(LinkService::find($tpdo, $l3['id'])['tags'] === 'ttUK,ttKongre-2027', 'mevcut yazım (ttUK) kullanıldı');
ok(LinkService::bulkTags($tpdo, [$l1['id']], ['ttuk'], [], $actor) === 0, 'zaten ekli etiket değişiklik sayılmaz');
ok(LinkService::bulkTags($tpdo, [$l1['id']], [], ['ttposter'], $actor) === 1 && LinkService::find($tpdo, $l1['id'])['tags'] === 'ttUK', 'toplu etiket çıkarma (büyük/küçük harf duyarsız)');
ok(LinkService::renameTag($tpdo, 'ttkongre-2027', 'ttKongre 2027', $actor) === 2 && LinkService::find($tpdo, $l3['id'])['tags'] === 'ttUK,ttKongre 2027', 'yeniden adlandırma');
ok(LinkService::renameTag($tpdo, 'ttPoster', 'ttUK', $actor) >= 1 && LinkService::find($tpdo, $l2['id'])['tags'] === 'ttUK,ttKongre 2027', 'birleştirme çift etiket üretmez');
ok(LinkService::renameTag($tpdo, 'ttKongre 2027', '', $actor) === 2 && LinkService::find($tpdo, $l3['id'])['tags'] === 'ttUK', 'etiket kaldırma');
ok(count($tpdo->query("SELECT 1 FROM link_events WHERE action='tags' AND slug LIKE 'tagtest%'")->fetchAll()) >= 4, 'etiket değişiklikleri geçmişe yazıldı');
[$w, $p] = LinkService::buildFilter(['tag' => '__none']);
ok(str_contains($w, "tags IS NULL") && $p === [], 'etiketsiz filtresi');
$tpdo->exec("DELETE FROM link_events WHERE slug LIKE 'tagtest%'");
$tpdo->exec("DELETE FROM links WHERE slug LIKE 'tagtest%'");

// ─── Yardımcılar ──────────────────────────────────────────────────────────────
section('Yardımcılar');
ok(maskIp('203.0.113.77', 'mask') === '203.0.113.0', 'IPv4 maskelenir');
ok(maskIp('203.0.113.77', 'none') === null, 'ip_mode=none IP saklamaz');
ok(maskIp('203.0.113.77', 'full') === '203.0.113.77', 'ip_mode=full olduğu gibi');
ok(isBotUserAgent('Mozilla/5.0 (compatible; Googlebot/2.1)'), 'Googlebot bot sayılır');
ok(isBotUserAgent('WhatsApp/2.23'), 'WhatsApp önizlemesi bot sayılır');
ok(!isBotUserAgent('Mozilla/5.0 (Linux; Android 11; CUBOT KINGKONG) AppleWebKit/537.36 Chrome/90 Mobile Safari/537.36'), 'CUBOT telefon bot DEĞİL');
ok(!isBotUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1'), 'iPhone bot DEĞİL');
ok(csvCell('=1+1') === "'=1+1" && csvCell('normal') === 'normal', 'CSV formül enjeksiyonu engellenir');

// ─── Paylaşım imzası ──────────────────────────────────────────────────────────
section('Share');
$exp = time() + 3600;
$sig = Share::sign('abc', $exp);
ok(Share::verify('abc', $exp, $sig), 'geçerli imza');
ok(!Share::verify('abd', $exp, $sig), 'başka slug ile imza geçmez');
ok(!Share::verify('abc', $exp + 1, $sig), 'süre değiştirilirse imza geçmez');
ok(!Share::verify('abc', time() - 5, Share::sign('abc', time() - 5)), 'süresi dolmuş bağlantı geçmez');

// ─── QR çıktıları ─────────────────────────────────────────────────────────────
section('QR çıktıları');
$link = ['slug' => 'testqr1', 'title' => 'Test', 'target_url' => 'https://acibadem.world', 'logo' => null,
         'fg_color' => '#0B2A5B', 'bg_color' => '#FFFFFF', 'shape' => 'dots'];
foreach (['svg' => '<svg', 'png' => "\x89PNG", 'pdf' => '%PDF-'] as $fmt => $magic) {
    $r = Share::render($link, $fmt, $fmt === 'png' ? 600 : ($fmt === 'pdf' ? 50 : 0));
    ok(str_starts_with(ltrim($r['data']), $magic) || str_contains(substr($r['data'], 0, 200), $magic), strtoupper($fmt) . ' üretilir');
}
QrRenderer::clearCache('testqr1');

// ─── Bilgi sayfası ────────────────────────────────────────────────────────────
section('Bilgi sayfası');
$renderInfo = function (string $state, string $lang): string {
    $_GET = ['lang' => $lang]; $infoState = $state;
    ob_start(); include __DIR__ . '/../admin/views/info.php'; return ob_get_clean();
};
$h = $renderInfo('expired', 'tr');
ok(str_contains($h, 'Bu etkinlik sona erdi') && str_contains($h, 'href="https://acibadem.world/"'), 'TR: süresi dolmuş metni + ACIBADEM.WORLD butonu');
ok(!str_contains($h, 'http-equiv="refresh"'), 'varsayılan: otomatik geçiş yok');
ok(str_contains($renderInfo('inactive', 'en'), 'This QR code is no longer active'), 'EN: pasif metni');
ok(str_contains($renderInfo('<script>', 'xx'), 'Link not found'), 'bilinmeyen durum/dil güvenli varsayılana düşer');
$_GET = [];

echo "\n" . ($fail ? "BAŞARISIZ" : "TAMAM") . " — {$pass} geçti, {$fail} kaldı\n";
exit($fail ? 1 : 0);
