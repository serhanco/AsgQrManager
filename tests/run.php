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
// Yalnızca komut satırı: Git senkronuyla sunucuya kopyalansa bile web'den çalıştırılamaz.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

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
    ok((int)$pdo->query("SELECT COUNT(*) FROM users WHERE role <> 'admin' OR is_active <> 1")->fetchColumn() === 0, 'mevcut kullanıcılar Yönetici ve etkin kaldı');
    ok((int)$pdo->query('SELECT COUNT(*) FROM links WHERE created_by IS NOT NULL')->fetchColumn() === 0, 'eski QR\'lara sahip atanmadı (yalnızca yönetici kapsamında)');

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

// ─── Roller ve kapsam ─────────────────────────────────────────────────────────
section('Roller ve kapsam');
$apdo = getPdo();
$apdo->exec("DELETE FROM user_scopes WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'acctest%')");
$apdo->exec("DELETE FROM users WHERE username LIKE 'acctest%'");
$apdo->exec("DELETE FROM links WHERE slug LIKE 'acctest%'");
$apdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)')->execute(['acctest-old', password_hash('eski-sifre', PASSWORD_DEFAULT)]);
$old = $apdo->query("SELECT * FROM users WHERE username = 'acctest-old'")->fetch();
ok($old['role'] === 'admin' && (int)$old['is_active'] === 1, 'rol belirtilmeden eklenen (eski) hesap Yönetici ve etkin');

$adminActor = ['id' => (int)$old['id'], 'name' => 'acctest-old'];
ok(UserService::passwordError('kisa1') !== null && UserService::passwordError('sadeceharfler') !== null, 'zayıf şifre reddedilir');
ok(UserService::passwordError(UserService::tempPassword()) === null, 'geçici şifre kurala uyar');
[$edId, $e] = UserService::create($apdo, ['username' => 'acctest-ed', 'role' => 'editor', 'password' => 'Gecici12345',
    'scope_tags' => 'ttAlmanya', 'scope_categories' => ['event', 'yok']], $adminActor);
ok($edId && !$e, 'editör oluşturulur');
[$x, $e] = UserService::create($apdo, ['username' => 'acctest-ed', 'role' => 'editor', 'password' => 'Gecici12345'], $adminActor);
ok($x === null && $e, 'aynı kullanıcı adı reddedilir');
[$viId, $e] = UserService::create($apdo, ['username' => 'acctest-vi', 'role' => 'viewer', 'password' => 'Gecici12345', 'scope_all' => 1], $adminActor);
$ed = UserService::find($apdo, $edId);
ok((int)$ed['must_change_password'] === 1, 'yeni kullanıcı ilk girişte şifre değiştirmeli');
ok($ed['scope']['tags'] === ['ttAlmanya'] && $ed['scope']['categories'] === ['event'], 'kapsam kaydedilir, geçersiz tür atılır');

$mkA = fn(string $slug, array $extra, array $actor) => LinkService::create($apdo, ['target_url' => 'https://acibadem.world'] + $extra, $slug, $actor);
$L1 = $mkA('acctest1', ['tags' => 'ttalmanya,Poster'], $adminActor);
$L2 = $mkA('acctest2', ['category' => 'event'], $adminActor);
$L3 = $mkA('acctest3', ['category' => 'office', 'tags' => 'ttDubai'], $adminActor);
$L4 = $mkA('acctest4', [], ['id' => $edId, 'name' => 'acctest-ed']);
ok((int)$L4['created_by'] === $edId, 'QR sahibi kaydedilir');

Access::setUser($ed, $apdo);
ok(Access::canSeeLink($L1) && Access::canSeeLink($L2) && Access::canSeeLink($L4), 'editör: etiket, tür ve kendi QR\'ı görünür (etiket harf duyarsız)');
ok(!Access::canSeeLink($L3), 'editör: kapsam dışı QR görünmez');
[$w, $p] = LinkService::buildFilter(['q' => 'acctest']);
$st = $apdo->prepare("SELECT slug FROM links {$w} ORDER BY slug"); $st->execute($p);
ok($st->fetchAll(PDO::FETCH_COLUMN) === ['acctest1', 'acctest2', 'acctest4'], 'liste filtresi kapsamla aynı');
ok(Access::can('links.edit') && !Access::can('links.archive') && !Access::can('admin'), 'editör: düzenler, arşivleyemez, yönetemez');
$apdo->prepare("INSERT INTO scans (link_id, ip, user_agent, scanned_at) VALUES (?, '1.1.1.0', 'Mozilla', NOW()), (?, '1.1.1.0', 'Mozilla', NOW())")
     ->execute([$L1['id'], $L3['id']]);
$today = date('Y-m-d');
ok(Stats::summary($apdo, null, $today, $today)['total'] === 1, 'editör raporu kapsam dışı okutmayı saymaz');

Access::setUser(UserService::find($apdo, $viId), $apdo);
ok(Access::canSeeLink($L3) && !Access::can('links.edit'), 'izleyici (tüm QR\'lar): görür, düzenleyemez');
$tokV = ApiAuth::create($apdo, 'acctest', 'read,write', 'test', $viId);
$rowV = ApiAuth::verify($apdo, $tokV['token']);
ok(ApiAuth::applyContext($apdo, $rowV) && ApiAuth::can($rowV, 'read') && !ApiAuth::can($rowV, 'write'), 'izleyiciye bağlı API anahtarı yazamaz');

Access::setUser($old, $apdo);
ok(Stats::summary($apdo, null, $today, $today)['total'] >= 2 && Access::canSeeLink($L3), 'yönetici her şeyi görür');

$apdo->exec("UPDATE users SET role = 'editor' WHERE role = 'admin' AND username <> 'acctest-old'");
$errs = UserService::update($apdo, $old, ['role' => 'editor', 'is_active' => 1], ['id' => $viId, 'name' => 'x']);
ok((bool)$errs, 'son etkin yönetici düşürülemez');
$apdo->exec("UPDATE users SET role = 'admin' WHERE username NOT LIKE 'acctest%'");
ok((bool)UserService::update($apdo, $old, ['role' => 'admin', 'is_active' => 0], $adminActor), 'kendi hesabını devre dışı bırakamaz');
ok(!UserService::update($apdo, $ed, ['role' => 'editor', 'is_active' => 0], $adminActor), 'editör devre dışı bırakılır');
ok(Auth::verifyUser($apdo, 'acctest-ed', 'Gecici12345') === null, 'devre dışı hesap giriş yapamaz');
$tokE = ApiAuth::verify($apdo, ApiAuth::create($apdo, 'acctest', 'read', 'test', $edId)['token']);
ok(!ApiAuth::applyContext($apdo, $tokE), 'devre dışı kullanıcının API anahtarı çalışmaz');
ok(UserService::changeOwnPassword($apdo, $viId, 'yanlis', 'YeniSifre123', 'YeniSifre123') !== null, 'yanlış mevcut şifreyle değiştirilemez');
ok(UserService::changeOwnPassword($apdo, $viId, 'Gecici12345', 'YeniSifre123', 'YeniSifre123') === null
   && (int)UserService::find($apdo, $viId)['must_change_password'] === 0, 'kendi şifresini değiştirir, zorunluluk kalkar');

Access::setUser(null);
$apdo->exec("DELETE FROM api_tokens WHERE name = 'acctest'");
$apdo->exec("DELETE FROM scans WHERE link_id IN (SELECT id FROM links WHERE slug LIKE 'acctest%')");
$apdo->exec("DELETE FROM link_events WHERE slug LIKE 'acctest%' OR username LIKE 'acctest%'");
$apdo->exec("DELETE FROM links WHERE slug LIKE 'acctest%'");
$apdo->exec("DELETE FROM user_scopes WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'acctest%')");
$apdo->exec("DELETE FROM users WHERE username LIKE 'acctest%'");

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
foreach (['svg' => '<svg', 'png' => "\x89PNG", 'pdf' => '%PDF-', 'eps' => '%!PS-Adobe-3.0 EPSF-3.0', 'ai' => '%PDF-'] as $fmt => $magic) {
    $r = Share::render($link, $fmt, $fmt === 'png' ? 600 : ($fmt === 'svg' ? 0 : 50));
    ok(str_starts_with(ltrim($r['data']), $magic) || str_contains(substr($r['data'], 0, 200), $magic), strtoupper($fmt) . ' üretilir');
}
$ai  = Share::render($link, 'ai', 50)['data'];
$eps = Share::render($link, 'eps', 50)['data'];
ok(str_contains($ai, " k\n") && !str_contains($ai, " rg\n"), 'AI renkleri CMYK');
ok(str_contains($eps, '%%BoundingBox: 0 0 142 142') && str_ends_with($eps, "%%EOF\n"), 'EPS sınır kutusu 50 mm');
ok(QrPdf::color('#000000', true) === '0 0 0 1 k', 'siyah yalnızca K kanalında');
ok(str_ends_with(Share::render($link, 'eps', 100)['name'], '-100mm.eps'), 'EPS dosya adı');
QrRenderer::clearCache('testqr1');

// ─── Okunabilirlik: üretilen QR gerçekten okunuyor mu? (zbarimg varsa) ─────────
section('QR okunabilirlik');
$zbar = trim((string)shell_exec('command -v zbarimg 2>/dev/null'));
if ($zbar === '') {
    echo "  - zbarimg yok, atlandı (CI'da çalışır; yerelde: apt install zbar-tools)\n";
} else {
    $logoFile = is_file(LOGO_DIR . '/acibadem-logo-1.png') ? 'acibadem-logo-1.png' : null;
    $tmpBase = tempnam(sys_get_temp_dir(), 'qrdec');
    $tmpPng = $tmpBase . '.png';
    $combos = 0; $bad = [];
    foreach (array_keys(LinkService::SHAPES) as $shape) {
        foreach ([['#000000', '#FFFFFF'], ['#0B2A5B', '#FFFFFF']] as [$fg, $bg]) {
            foreach (array_unique([null, $logoFile]) as $logo) {
                $slug = 'okuma' . (++$combos);
                $l = ['slug' => $slug, 'title' => 'T', 'target_url' => 'https://acibadem.world', 'logo' => $logo,
                      'fg_color' => $fg, 'bg_color' => $bg, 'shape' => $shape];
                file_put_contents($tmpPng, Share::render($l, 'png', 600)['data']);
                $got = trim((string)shell_exec(escapeshellarg($zbar) . ' --raw -q ' . escapeshellarg($tmpPng) . ' 2>/dev/null'));
                if ($got !== BASE_URL . '/r/' . $slug) $bad[] = "{$shape} {$fg}" . ($logo ? ' +logo' : '') . " → '{$got}'";
                QrRenderer::clearCache($slug);
            }
        }
    }
    @unlink($tmpPng); @unlink($tmpBase);
    ok(!$bad, "{$combos} stil/logo birleşiminin tamamı okunuyor" . ($bad ? ': ' . implode('; ', $bad) : ''));
}

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
