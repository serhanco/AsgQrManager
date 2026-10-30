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

    ok((int)$pdo->query('SELECT COUNT(*) FROM links WHERE template_id IS NOT NULL OR design IS NOT NULL OR frame_text IS NOT NULL')->fetchColumn() === 0, 'eski QR\'lara şablon / tasarım atanmadı (görünümleri aynı)');
    ok((int)$pdo->query('SELECT COUNT(*) FROM qr_templates')->fetchColumn() === count(QrTemplates::STARTERS), 'başlangıç şablonları eklendi');

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
[$x, $e] = UserService::create($apdo, ['username' => 'acctest-vi', 'role' => 'viewer', 'password' => 'Gecici12345'], $adminActor);
ok($x === null && $e, 'Rapor izleyici yeni kullanıcıya atanamaz');
// Eski (önceden oluşturulmuş) Rapor izleyici hesabı korunur ve çalışır
$apdo->prepare("INSERT INTO users (username, password_hash, role, must_change_password) VALUES ('acctest-vi', ?, 'viewer', 1)")
     ->execute([password_hash('Gecici12345', PASSWORD_DEFAULT)]);
$viId = (int)$apdo->lastInsertId();
Access::saveScope($apdo, $viId, ['all' => true]);
ok(!UserService::update($apdo, UserService::find($apdo, $viId), ['role' => 'viewer', 'is_active' => 1, 'scope_all' => 1], $adminActor), 'mevcut izleyici rolüyle güncellenebilir');
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
ok(Access::canEditLink($L4) && !Access::canEditLink($L1) && !Access::canEditLink($L2), 'editör: yalnızca kendi QR\'ını düzenler, kapsamdakileri yalnızca görür');
ok(!Access::canToggleLink($L4) && Access::canRequestToggle($L4) && !Access::canRequestToggle($L1), 'editör: kendi QR\'ını doğrudan kapatamaz, talep gönderir');
$edActor = ['id' => $edId, 'name' => 'acctest-ed'];
[$rq, $err] = LinkRequests::create($apdo, $L4, $edActor, 'activate');
ok($rq === null && $err, 'aktif QR için yeniden açma talebi reddedilir');
[$rq, $err] = LinkRequests::create($apdo, $L4, $edActor, 'deactivate', 'etkinlik bitti');
ok($rq && !$err && (int)LinkService::find($apdo, (int)$L4['id'])['is_active'] === 1, 'pasife alma talebi oluşur, QR aktif kalır');
[$rq2, $err] = LinkRequests::create($apdo, $L4, $edActor, 'deactivate');
ok($rq2 === null && $err, 'aynı QR için ikinci bekleyen talep açılmaz');
ok(isset(LinkRequests::pendingMap($apdo, [$L4['id'], $L1['id']])[(int)$L4['id']]) && LinkRequests::pendingCount($apdo) >= 1, 'bekleyen talep listede');
ok(LinkRequests::decide($apdo, LinkRequests::find($apdo, $rq), false, $adminActor, 'kalsın') === null
   && (int)LinkService::find($apdo, (int)$L4['id'])['is_active'] === 1, 'reddedilen talep QR\'ı değiştirmez');
ok(LinkRequests::decide($apdo, LinkRequests::find($apdo, $rq), true, $adminActor) !== null, 'sonuçlanmış talep tekrar karara bağlanamaz');
[$rq, $err] = LinkRequests::create($apdo, $L4, $edActor, 'deactivate');
ok(LinkRequests::decide($apdo, LinkRequests::find($apdo, $rq), true, $adminActor) === null
   && (int)LinkService::find($apdo, (int)$L4['id'])['is_active'] === 0, 'onaylanan talep QR\'ı pasife alır');
$L4 = LinkService::find($apdo, (int)$L4['id']);
[$rq, $err] = LinkRequests::create($apdo, $L4, $edActor, 'activate');
ok($rq && LinkRequests::cancel($apdo, LinkRequests::find($apdo, $rq), $edActor) === null
   && LinkRequests::find($apdo, $rq)['status'] === 'cancelled' && !LinkRequests::pendingFor($apdo, (int)$L4['id']), 'editör talebini geri çeker');
$apdo->exec("DELETE FROM link_requests WHERE link_id IN (SELECT id FROM links WHERE slug LIKE 'acctest%')");
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

section('Kartvizit');
$vpdo = getPdo();
ok(VCard::available($vpdo), 'şema hazır (qr_type + vcards)');
ok((int)$vpdo->query("SELECT COUNT(*) FROM links WHERE qr_type <> 'link'")->fetchColumn() === (int)$vpdo->query("SELECT COUNT(*) FROM links l JOIN vcards v ON v.link_id = l.id")->fetchColumn(),
   'mevcut QR\'ların hepsi link türünde kalır');
[$vc, $ve] = VCard::normalize(['first_name' => '', 'last_name' => '', 'email' => 'yok']);
ok(count($ve) === 2, 'ad yoksa ve e-posta geçersizse hata');
[$vc, $ve] = VCard::normalize(['first_name' => 'Çağla', 'last_name' => 'Şimşek; Öz', 'title' => 'Koordinatör, Uluslararası',
    'phone_mobile' => '+90 532 111 22 33', 'email' => 'cagla@example.com', 'website' => 'acibadem.world/de',
    'note' => str_repeat('Uzun açıklama ğüşiöç ', 8)]);
ok(!$ve && $vc['org'] === VCard::DEFAULT_ORG && $vc['website'] === 'https://acibadem.world/de', 'kurum varsayılanı ve web adresine https eklenir');
ok(VCard::fallbackTarget($vc) === 'https://acibadem.world/de' && VCard::fallbackTarget(['website' => '']) === VCard::DEFAULT_WEBSITE, 'yedek hedef web sitesi');
$vcf = VCard::toVcf($vc, 'https://qr.example/r/x', ["\xFF\xD8" . str_repeat('x', 300), 'image/jpeg']);
$lines = explode("\r\n", rtrim($vcf, "\r\n"));
ok($lines[0] === 'BEGIN:VCARD' && end($lines) === 'END:VCARD' && str_contains($vcf, "N:Şimşek\; Öz;Çağla;;;"), 'vCard 3.0 başlık ve kaçışlı ad');
ok(str_contains($vcf, 'TITLE:Koordinatör\\, Uluslararası') && str_contains($vcf, 'TEL;TYPE=CELL,VOICE:+90 532 111 22 33'), 'ünvan ve telefon');
ok(max(array_map('strlen', $lines)) <= 75 && mb_check_encoding($vcf, 'UTF-8'), 'satırlar 75 baytta katlanır, UTF-8 bozulmaz');
ok(str_contains(str_replace("\r\n ", '', $vcf), 'PHOTO;ENCODING=b;TYPE=JPEG:' . base64_encode("\xFF\xD8" . str_repeat('x', 300))), 'fotoğraf base64 olarak eklenir');
ok(VCard::fileName($vc) === 'cagla-simsek-oz.vcf', 'dosya adı ASCII');

$vpdo->exec("DELETE FROM vcards WHERE link_id IN (SELECT id FROM links WHERE slug LIKE 'vctest%')");
$vpdo->exec("DELETE FROM links WHERE slug LIKE 'vctest%'");
$vl = LinkService::create($vpdo, LinkService::normalize(['target_url' => VCard::fallbackTarget($vc), 'title' => VCard::fullName($vc)])[0] + ['qr_type' => 'vcard'], 'vctest1', ['id' => null, 'name' => 'test']);
VCard::save($vpdo, (int)$vl['id'], $vc);
$row = VCard::find($vpdo, (int)$vl['id']);
ok(VCard::isVcard($vl) && $row['first_name'] === 'Çağla' && !$row['has_photo'], 'kartvizit kaydedilir ve okunur');
VCard::save($vpdo, (int)$vl['id'], ['title' => 'Müdür'] + $vc, ["\xFF\xD8abc", 'image/jpeg']);
$row2 = VCard::find($vpdo, (int)$vl['id']);
ok($row2['title'] === 'Müdür' && $row2['has_photo'] && VCard::photo($vpdo, (int)$vl['id'])[0] === "\xFF\xD8abc", 'bilgi sonradan değişir, fotoğraf eklenir');
ok(array_keys(VCard::diff($row, $row2)) === ['Ünvan'], 'değişen alan geçmiş için bulunur');
VCard::save($vpdo, (int)$vl['id'], $vc, false);
ok(!VCard::find($vpdo, (int)$vl['id'])['has_photo'], 'fotoğraf kaldırılır');
VCard::countSave($vpdo, (int)$vl['id']);
ok((int)VCard::find($vpdo, (int)$vl['id'])['save_count'] === 1, 'rehbere ekleme sayılır');
ok(Redirector::resolve($vl)['outcome'] === 'ok' && Redirector::resolve($vl)['url'] === 'https://acibadem.world/de', 'kayıt okunamazsa QR web sitesine gider');
[$wv, $pv] = LinkService::buildFilter(['q' => 'vctest', 'type' => 'vcard']);
$stv = $vpdo->prepare("SELECT slug FROM links {$wv}"); $stv->execute($pv);
ok($stv->fetchAll(PDO::FETCH_COLUMN) === ['vctest1'], 'listede türe göre süzülür');
ok(LinkService::present($vl)['type'] === 'vcard', 'API çıktısında tür');

$renderVc = function (string $lang) use ($vl, $vpdo): string {
    $_GET = ['lang' => $lang]; $link = $vl; $vcard = VCard::find($vpdo, (int)$vl['id']);
    ob_start(); include __DIR__ . '/../admin/views/vcard.php'; return ob_get_clean();
};
$h = $renderVc('tr');
ok(str_contains($h, 'Çağla Şimşek; Öz') && str_contains($h, 'Rehbere ekle') && str_contains($h, '/r/vctest1?vcf=1'), 'kişi sayfası: ad ve "Rehbere ekle"');
ok(str_contains($h, 'href="tel:+905321112233"') && str_contains($h, 'https://wa.me/905321112233'), 'kişi sayfası: ara ve WhatsApp');
ok(str_contains($renderVc('en'), 'Add to contacts') && str_contains($h, 'noindex'), 'kişi sayfası: EN ve arama motoruna kapalı');
$_GET = [];
$vpdo->exec("DELETE FROM vcards WHERE link_id IN (SELECT id FROM links WHERE slug LIKE 'vctest%')");
$vpdo->exec("DELETE FROM link_events WHERE slug LIKE 'vctest%'");
$vpdo->exec("DELETE FROM links WHERE slug LIKE 'vctest%'");


// ─── QR tasarımı ve şablonlar ────────────────────────────────────────────────
section('Tasarım ve şablonlar');
$tpdo = getPdo();
QrTemplates::reset();
Access::setUser(null);
$def = QrRenderer::normalizeStyle([]);
ok(!QrDesign::hasExtras($def) && $def['eye'] === 'square' && $def['gradient'] === 'none', 'varsayılan stilde ek seçenek yok');
$oldKey = CACHE_DIR . '/' . date('Y') . '/' . date('m') . '/abc_' . md5('M|600|4||#0B2A5B|#FFFFFF|dots|o') . '.png';
ok(QrRenderer::cachePath('abc', 'png', 'M', 600, 4, '', ['fg' => '#0B2A5B', 'shape' => 'dots']) === $oldKey, 'eski özel stillerin önbellek anahtarı değişmedi');
ok(QrRenderer::cachePath('abc', 'png', 'M', 600, 4, '', ['fg' => '#0B2A5B', 'shape' => 'dots', 'eye' => 'circle']) !== $oldKey, 'köşe biçimi ayrı önbelleğe yazılır');
ok(QrDesign::normalizeExtras(['gradient' => 'linear'])['gradient'] === 'none', 'ikinci renk yoksa geçiş uygulanmaz');
ok(QrDesign::encode(['eye' => 'square', 'gradient' => 'none']) === null, 'varsayılan tasarım sütuna yazılmaz');
ok(QrDesign::decode(QrDesign::encode(['eye' => 'circle', 'gradient' => 'radial', 'fg2' => '#0a3a86']))['fg2'] === '#0A3A86', 'tasarım JSON gidip gelir');

$st = fn(array $s) => QrRenderer::normalizeStyle($s);
[$e, $w] = QrDesign::check($st(['fg' => '#1C2561']));
ok(!$e && !$w, 'lacivert / beyaz: hata ve uyarı yok');
[$e, $w] = QrDesign::check($st(['fg' => '#FFFFFF', 'bg' => '#1C2561']));
ok(!$e && count($w) === 1 && str_contains($w[0], 'Ters renk'), 'ters renk kaydedilir, uyarı verir');
[$e, $w] = QrDesign::check($st(['fg' => '#8A8A8A']));
ok(!$e && $w, 'düşük kontrast (3,4:1) uyarı verir');
[$e] = QrDesign::check($st(['fg' => '#CCCCCC']));
ok((bool)$e, 'çok düşük kontrast reddedilir');
[$e] = QrDesign::check($st(['fg' => '#1C2561', 'bg' => '#808080', 'eye_color' => '#FFFFFF']));
ok((bool)$e, 'zeminden hem koyu hem açık renk reddedilir');
[$e] = QrDesign::check($st(['fg' => '#1C2561', 'gradient' => 'linear', 'fg2' => '#EEEEEE']));
ok((bool)$e, 'geçişin açık ucu da denetlenir');

$tpls = QrTemplates::all($tpdo);
$names = array_column($tpls, 'name');
ok(!array_diff(['Kartvizit', 'Roll-up', 'Yaka Kartı', 'Masa Kartı', 'Sticker'], $names), 'başlangıç şablonları kullanımda');
$allOk = true;
foreach ($tpls as $t) if (QrDesign::check($t['style'] + ['transparent' => false])[0]) $allOk = false;
ok($allOk, 'başlangıç şablonlarının hepsi okunabilirlik kuralından geçer');

[$c, $e, $w] = QrTemplates::normalize(['name' => 'ttKongre', 'usage' => 'poster', 'print_mm' => '120', 'fg' => '#0A3A86', 'eye' => 'circle']);
ok(!$e && $c['print_mm'] === 120, 'şablon formu doğrulanır');
[, $e] = QrTemplates::normalize(['name' => '', 'usage' => 'yok', 'print_mm' => '5', 'fg' => '#DDDDDD']);
ok(count($e) >= 4, 'boş ad, geçersiz kullanım, boyut ve kontrast reddedilir');
$tid = QrTemplates::create($tpdo, $c, ['id' => null, 'name' => 'test']);
$copyId = QrTemplates::copy($tpdo, QrTemplates::find($tpdo, $tid), ['id' => null, 'name' => 'test']);
ok(QrTemplates::find($tpdo, $copyId)['name'] === 'ttKongre (kopya)' && QrTemplates::find($tpdo, $copyId)['style']['eye'] === 'circle', 'şablon kopyalanır');

$tpdo->exec("DELETE FROM links WHERE slug LIKE 'tpltest%'");
$admin = ['id' => null, 'name' => 'test'];
[$cl, $e] = LinkService::normalize(['target_url' => 'https://acibadem.world', 'template_id' => (string)$tid]);
$TL = LinkService::create($tpdo, $cl, 'tpltest1', $admin);
ok(!$e && (int)$TL['template_id'] === $tid && LinkService::effectiveStyle($TL)['eye'] === 'circle', 'QR şablona bağlanır, görünümü şablondan gelir');
ok(in_array(120, Share::mmOptions($TL), true) && str_contains(Share::render($TL, 'eps', 120)['data'], '%%BoundingBox: 0 0 341 341'), 'şablonun baskı boyutu indirme seçeneklerinde');
QrTemplates::update($tpdo, $tid, array_merge($c, ['design' => json_encode(['fg' => '#1C2561', 'bg' => '#FFFFFF', 'shape' => 'rounded', 'eye' => 'rounded'])]));
ok(LinkService::effectiveStyle(LinkService::find($tpdo, (int)$TL['id']))['eye'] === 'rounded', 'şablon düzenlenince bağlı QR yeni tasarımı alır');
QrTemplates::setArchived($tpdo, $tid, true);
[, $e] = LinkService::normalize(['target_url' => 'https://acibadem.world', 'template_id' => (string)$tid]);
ok((bool)$e, 'arşivdeki şablon yeni QR\'da seçilemez');
ok(LinkService::effectiveStyle(LinkService::find($tpdo, (int)$TL['id']))['eye'] === 'rounded', 'arşivlenen şablona bağlı QR görünümünü korur');
[, $e] = LinkService::normalize(['template_id' => (string)$tid, 'title' => 'x'], LinkService::find($tpdo, (int)$TL['id']));
ok(!$e, 'bağlı olduğu arşivli şablonla düzenlenebilir');

[$cl, $e] = LinkService::normalize(['target_url' => 'https://acibadem.world', 'fg_color' => '#FFFFFF', 'bg_color' => '#1C2561', 'shape' => 'rounded',
    'design' => ['eye' => 'circle', 'gradient' => 'none']]);
$CL = LinkService::create($tpdo, $cl, 'tpltest2', $admin);
ok(!$e && QrDesign::decode($CL['design'])['eye'] === 'circle', 'yönetici ters renkli özel görünüm kaydeder');
ok(count(LinkService::designWarnings($CL)) === 1, 'kaydedilen QR için uyarı gösterilir');
[, $e] = LinkService::normalize(['target_url' => 'https://acibadem.world', 'design' => ['eye' => 'yildiz']]);
ok((bool)$e, 'geçersiz köşe biçimi reddedilir');

$tpdo->exec("DELETE FROM users WHERE username = 'tpltest-ed'");
$tpdo->prepare("INSERT INTO users (username, password_hash, role) VALUES ('tpltest-ed', ?, 'editor')")->execute([password_hash('x', PASSWORD_DEFAULT)]);
Access::setUser($tpdo->query("SELECT * FROM users WHERE username = 'tpltest-ed'")->fetch(), $tpdo);
[, $e] = LinkService::normalize(['target_url' => 'https://acibadem.world', 'fg_color' => '#0A3A86', 'bg_color' => '#FFFFFF']);
ok((bool)$e, 'editör özel renk seçemez');
[, $e] = LinkService::normalize(['target_url' => 'https://acibadem.world', 'design' => ['eye' => 'circle']]);
ok((bool)$e, 'editör özel köşe biçimi seçemez');
[, $e] = LinkService::normalize(['target_url' => 'https://acibadem.world', 'template_id' => (string)$tpls[0]['id']]);
ok(!$e, 'editör şablon seçer');
[, $e] = LinkService::normalize(['fg_color' => $CL['fg_color'], 'bg_color' => $CL['bg_color'], 'shape' => 'rounded', 'design' => QrDesign::decode($CL['design'])], $CL);
ok(!$e, 'editörün QR\'ındaki mevcut özel görünüm değiştirilmeden korunur');
[, $e] = LinkService::normalize(['fg_color' => '', 'bg_color' => '', 'shape' => '', 'design' => []], $CL);
ok(!$e, 'editör özel görünümden marka varsayılanına dönebilir');
Access::setUser(null);

// Okunabilirlik: şablonlar ve yeni seçenekler gerçekten okunuyor mu?
$zbar = trim((string)shell_exec('command -v zbarimg 2>/dev/null'));
if ($zbar === '' || !extension_loaded('gd')) {
    echo "  - zbarimg/GD yok, okutma testi atlandı\n";
} else {
    $tmpPng = sys_get_temp_dir() . '/qrdesign-' . getmypid() . '.png';
    $logoFile = is_file(LOGO_DIR . '/acibadem-logo-1.png') ? 'acibadem-logo-1.png' : null;
    $cases = [];
    foreach ($tpls as $t) $cases['şablon ' . $t['name']] = ['template_id' => $t['id']];
    foreach (array_keys(QrDesign::EYES) as $eye) foreach (array_keys(LinkService::SHAPES) as $shape) {
        $cases["{$shape}+{$eye}"] = ['fg_color' => '#1C2561', 'bg_color' => '#FFFFFF', 'shape' => $shape, 'design' => QrDesign::encode(['eye' => $eye, 'eye_color' => '#0A3A86'])];
    }
    $cases['radyal geçiş + logo'] = ['fg_color' => '#0A3A86', 'shape' => 'dots', 'logo' => $logoFile, 'design' => QrDesign::encode(['eye' => 'circle', 'gradient' => 'radial', 'fg2' => '#1C2561'])];
    $cases['ters renk'] = ['fg_color' => '#FFFFFF', 'bg_color' => '#1C2561', 'shape' => 'rounded', 'design' => QrDesign::encode(['eye' => 'rounded']), 'invert' => true];
    $bad = []; $n = 0;
    foreach ($cases as $label => $c) {
        $slug = 'tplokuma' . (++$n);
        $l = ['slug' => $slug, 'title' => 'T', 'target_url' => 'https://acibadem.world', 'logo' => $c['logo'] ?? null] + $c + ['fg_color' => null, 'bg_color' => null, 'shape' => null];
        $png = Share::render($l, 'png', 600)['data'];
        if (!empty($c['invert'])) {  // zbarimg ters rengi okumaz; telefon kameraları okur. Test için renkler çevrilir.
            $im = imagecreatefromstring($png); imagefilter($im, IMG_FILTER_NEGATE); imagepng($im, $tmpPng); imagedestroy($im);
        } else {
            file_put_contents($tmpPng, $png);
        }
        $got = trim((string)shell_exec(escapeshellarg($zbar) . ' --raw -q ' . escapeshellarg($tmpPng) . ' 2>/dev/null'));
        if ($got !== BASE_URL . '/r/' . $slug) $bad[] = $label;
        QrRenderer::clearCache($slug);
    }
    @unlink($tmpPng);
    ok(!$bad, count($cases) . ' tasarımın tamamı okunuyor' . ($bad ? ': ' . implode('; ', $bad) : ''));
}
foreach (['svg' => '<svg', 'pdf' => '%PDF-', 'eps' => '%!PS-Adobe', 'ai' => '%PDF-'] as $fmt => $magic) {
    $r = Share::render($CL, $fmt, $fmt === 'svg' ? 0 : 50)['data'];
    ok(str_contains(substr($r, 0, 200), $magic) && (($fmt === 'svg') ? str_contains($r, 'evenodd') : str_contains($r, "f*\n")), strtoupper($fmt) . ': köşeler halka olarak çizilir');
}
$gl = $CL; $gl['design'] = QrDesign::encode(['gradient' => 'linear', 'fg2' => '#0A3A86']); $gl['fg_color'] = '#1C2561'; $gl['bg_color'] = '#FFFFFF';
ok(str_contains(Share::render($gl, 'svg')['data'], 'linearGradient') && substr_count(Share::render($gl, 'pdf', 50)['data'], ' rg') > 5, 'geçiş SVG\'de gradyan, PDF\'te renk basamakları');
// Çerçeve ve çağrı metni
ok(QrFrame::cleanText("  Okutun,\n ofisimizi   bulun ✓ ") === 'Okutun, ofisimizi bulun', 'çerçeve yazısı tek satıra indirilir, desteklenmeyen karakter atılır');
ok(!QrFrame::unsupported('ŞİMDİ OKUT · Ğüçİış') && QrFrame::unsupported('日本') === ['日', '本'], 'Türkçe karakterler yazı tipinde var');
ok((bool)QrDesign::frameErrors(['frame' => 'bottom', 'frame_text' => '']) && !QrDesign::frameErrors(['frame' => 'bottom', 'frame_text' => 'Scan me'])
    && (bool)QrDesign::frameErrors(['frame' => 'top', 'frame_text' => 'QR 日本']), 'çerçevede yazı zorunlu, desteklenmeyen karakter reddedilir');
ok(QrFrame::textColor('#1C2561') === '#FFFFFF' && QrFrame::textColor('#60C2EE') === '#111111', 'yazı rengi çerçeve rengine göre seçilir');
$fl = $CL; $fl['fg_color'] = '#1C2561'; $fl['bg_color'] = '#FFFFFF';
$fl['design'] = QrDesign::encode(['eye' => 'rounded', 'frame' => 'bottom', 'frame_color' => '#1C2561', 'frame_text' => 'Okutun, ofisimizi bulun']);
$fsvg = Share::render($fl, 'svg')['data'];
ok(str_contains($fsvg, '<g transform="translate(') && !str_contains($fsvg, '<text'), 'SVG: QR çerçeve içine kaydırılır, yazı vektör yol (yazı tipi gerekmez)');
$fpng = Share::render($fl, 'png', 600)['data'];
[$pw, $ph] = getimagesizefromstring($fpng);
ok(abs($pw - 600) <= 10 && $ph > $pw, "PNG: genişlik istenen ölçüde, yazı bandıyla uzar ({$pw}×{$ph})");
$feps = Share::render($fl, 'eps', 50)['data'];
ok(preg_match('/%%BoundingBox: 0 0 142 (\d+)/', $feps, $mm) && (int)$mm[1] > 142, 'EPS: sınır kutusu çerçeveyle uzar');
ok(preg_match('#/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]#', Share::render($fl, 'pdf', 50)['data'], $mb) && (float)$mb[2] > (float)$mb[1], 'PDF: sayfa çerçeveyle uzar');
if ($zbar !== '' && extension_loaded('gd')) {
    $bad = [];
    foreach (['bottom' => '#1C2561', 'top' => '#60C2EE'] as $fr => $fc) {
        $fl['design'] = QrDesign::encode(['eye' => 'circle', 'frame' => $fr, 'frame_color' => $fc, 'frame_text' => 'ŞİMDİ OKUTUN']);
        QrRenderer::clearCache($fl['slug']);
        $tmpPng = sys_get_temp_dir() . '/qrframe-' . getmypid() . '.png';
        file_put_contents($tmpPng, Share::render($fl, 'png', 600)['data']);
        $got = trim((string)shell_exec(escapeshellarg($zbar) . ' --raw -q ' . escapeshellarg($tmpPng) . ' 2>/dev/null'));
        if ($got !== BASE_URL . '/r/' . $fl['slug']) $bad[] = $fr;
        @unlink($tmpPng);
    }
    ok(!$bad, 'çerçeveli QR (altta ve üstte yazı) okunuyor' . ($bad ? ': ' . implode(', ', $bad) : ''));
}
[, $e] = LinkService::normalize(['target_url' => 'https://acibadem.world', 'design' => ['frame' => 'bottom', 'frame_text' => '']]);
ok((bool)$e, 'yazısız çerçeve kaydedilmez');

// Şablonlu QR'da QR'a özel çerçeve yazısı
[$fc] = QrTemplates::normalize(['name' => 'ttCerceve', 'usage' => 'rollup', 'fg' => '#1C2561', 'frame' => 'bottom', 'frame_text' => 'Okutun, ofisimizi bulun']);
$ftid = QrTemplates::create($tpdo, $fc, ['id' => null, 'name' => 'test']);
$tpdo->exec("DELETE FROM users WHERE username = 'tplfr-ed'");
$tpdo->prepare("INSERT INTO users (username, password_hash, role) VALUES ('tplfr-ed', ?, 'editor')")->execute([password_hash('x', PASSWORD_DEFAULT)]);
$frEd = $tpdo->query("SELECT * FROM users WHERE username = 'tplfr-ed'")->fetch();
Access::setUser($frEd, $tpdo);
[$cl, $e] = LinkService::normalize(['target_url' => 'https://acibadem.world', 'template_id' => (string)$ftid, 'frame_text' => 'Almanya ofisimizi bulun']);
ok(!$e, 'editör şablonlu QR\'a kendi çerçeve yazısını yazar');
$FT = LinkService::create($tpdo, $cl, 'tpltest3', ['id' => (int)$frEd['id'], 'name' => 'tplfr-ed']);
$fst = LinkService::effectiveStyle($FT);
ok($fst['frame_text'] === 'Almanya ofisimizi bulun' && $fst['frame'] === 'bottom' && $fst['fg'] === '#1C2561', 'şablonun tasarımı sabit, yazı QR\'a özel');
[$cl2, $e] = LinkService::normalize(['frame_text' => ''], $FT);
LinkService::update($tpdo, $FT, $cl2, ['id' => (int)$frEd['id'], 'name' => 'tplfr-ed']);
ok(!$e && LinkService::effectiveStyle(LinkService::find($tpdo, (int)$FT['id']))['frame_text'] === 'Okutun, ofisimizi bulun', 'yazı boşsa şablonun yazısı kullanılır');
[, $e] = LinkService::normalize(['frame_text' => 'QR 日本'], $FT);
ok((bool)$e, 'QR\'a özel yazıda desteklenmeyen karakter reddedilir');
Access::setUser(null);
$tpl1 = LinkService::find($tpdo, (int)$TL['id']);
ok(!QrFrame::enabled(LinkService::effectiveStyle(['frame_text' => 'X'] + $tpl1)), 'çerçevesiz şablonda QR\'a özel yazı çerçeve eklemez');
$tpdo->exec("DELETE FROM users WHERE username = 'tplfr-ed'");
$tpdo->prepare('DELETE FROM qr_templates WHERE id = ?')->execute([$ftid]);

// Kategoriye göre varsayılan şablon
$prevCat = Settings::has('category_templates') ? Settings::get('category_templates') : null;
QrTemplates::setCategoryDefaults($tpdo, ['event' => (string)$copyId, 'office' => (string)$tid, 'hq' => '999999']);
ok(QrTemplates::categoryDefaults() === ['event' => $copyId], 'kategori varsayılanı kaydedilir; arşivdeki ve olmayan şablon atanmaz');
[$cl] = LinkService::normalize(['target_url' => 'https://acibadem.world', 'category' => 'event']);
$CD = LinkService::create($tpdo, $cl, 'tpltest4', $admin);
ok((int)$CD['template_id'] === $copyId, 'görünüm belirtilmeyen yeni QR (CSV/API) kategorinin şablonunu alır');
[$cl] = LinkService::normalize(['target_url' => 'https://acibadem.world', 'category' => 'event', 'template_id' => '']);
ok(LinkService::create($tpdo, $cl, 'tpltest5', $admin)['template_id'] === null, 'formda marka varsayılanı seçilirse şablon uygulanmaz');
[$cl] = LinkService::normalize(['target_url' => 'https://acibadem.world', 'category' => 'office']);
ok(LinkService::create($tpdo, $cl, 'tpltest6', $admin)['template_id'] === null, 'varsayılanı olmayan kategori marka varsayılanıyla kalır');
QrTemplates::setArchived($tpdo, $copyId, true);
ok(QrTemplates::defaultFor($tpdo, 'event') === null, 'varsayılan şablon arşivlenince uygulanmaz');
if ($prevCat === null) $tpdo->exec("DELETE FROM settings WHERE skey = 'category_templates'");
else Settings::set('category_templates', $prevCat);

foreach (['tpltest1', 'tpltest2', 'tpltest3', 'tpltest4', 'tpltest5', 'tpltest6'] as $sl) QrRenderer::clearCache($sl);
$tpdo->exec("DELETE FROM link_events WHERE slug LIKE 'tpltest%'");
$tpdo->exec("DELETE FROM links WHERE slug LIKE 'tpltest%'");
$tpdo->exec("DELETE FROM users WHERE username = 'tpltest-ed'");
$tpdo->prepare('DELETE FROM qr_templates WHERE id IN (?, ?)')->execute([$tid, $copyId]);
QrTemplates::reset();

// ─── Faz 4: dönem karşılaştırması, ısı haritası, sahibine göre, hedef işaretleri, karşılaştırma ───
echo "\nDetaylı raporlar\n";
$rp = getPdo();
Access::setUser(null);
$rp->exec("DELETE FROM link_events WHERE slug LIKE 'rptest%'");
$rp->exec("DELETE FROM scans WHERE link_id IN (SELECT id FROM links WHERE slug LIKE 'rptest%')");
$rp->exec("DELETE FROM links WHERE slug LIKE 'rptest%'");
$rpAdmin = $rp->query("SELECT id, username FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetch();
$rpActor = ['id' => $rpAdmin ? (int)$rpAdmin['id'] : null, 'name' => $rpAdmin['username'] ?? 'test'];
[$cl] = LinkService::normalize(['target_url' => 'https://acibadem.world/a']);
$R1 = LinkService::create($rp, $cl, 'rptest1', $rpActor);
[$cl] = LinkService::normalize(['target_url' => 'https://acibadem.world/b']);
$R2 = LinkService::create($rp, $cl, 'rptest2', $rpActor);
// 2026-03-02 Pazartesi 10:xx: R1 3 okutma, R2 1; önceki hafta (2026-02-23) R1 1 okutma; bir bot
$ins = $rp->prepare("INSERT INTO scans (link_id, ip, user_agent, scanned_at, is_bot) VALUES (?, ?, ?, ?, ?)");
foreach ([[$R1, '1.1.1.1', '2026-03-02 10:05:00', 0], [$R1, '1.1.1.2', '2026-03-02 10:30:00', 0], [$R1, '1.1.1.3', '2026-03-04 18:00:00', 0],
          [$R2, '2.2.2.2', '2026-03-03 09:00:00', 0], [$R1, '1.1.1.9', '2026-02-23 11:00:00', 0], [$R1, '9.9.9.9', '2026-03-02 10:10:00', 1]] as [$L, $ip, $at, $bot]) {
    $ins->execute([$L['id'], $ip, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile', $at, $bot]);
}
ok(Stats::previousRange('2026-03-02', '2026-03-08') === ['2026-02-23', '2026-03-01'], 'önceki dönem aynı uzunlukta ve hemen önce');
$S = Stats::summary($rp, (int)$R1['id'], '2026-03-02', '2026-03-08');
ok($S['total'] === 3 && $S['heat'][0][10] === 2 && $S['heat'][2][18] === 1 && $S['hours'][10] === 2, 'gün × saat ısı haritası (Pazartesi 10:00 = 2)');
$P = Stats::totals($rp, (int)$R1['id'], '2026-02-23', '2026-03-01');
ok($P['total'] === 1 && Stats::change(3, 1) === 200 && Stats::change(3, 0) === null, 'önceki döneme göre değişim yüzdesi');
ok(Stats::dailyCounts($rp, (int)$R1['id'], '2026-03-01', '2026-03-03') === [0, 2, 0], 'günlük seri (boş günler 0, bot sayılmaz)');
$own = Stats::byOwner($rp, '2026-03-02', '2026-03-08');
$mine = array_values(array_filter($own, fn($o) => $o['owner'] === ($rpAdmin['username'] ?? '')));
ok(!$rpAdmin || ($mine && $mine[0]['links'] >= 2 && $mine[0]['total'] >= 4), 'sahibine göre okutma ve QR sayısı');
LinkService::update($rp, $R1, ['target_url' => 'https://acibadem.world/yeni'], $rpActor);
$rp->prepare("UPDATE link_events SET created_at = '2026-03-03 12:00:00' WHERE slug = 'rptest1' AND action = 'update'")->execute();
$tc = Stats::targetChanges($rp, (int)$R1['id'], '2026-03-01', '2026-03-08');
ok(count($tc) === 1 && $tc[0]['date'] === '2026-03-03' && $tc[0]['to'] === 'https://acibadem.world/yeni', 'hedef değişikliği grafikte işaretlenir');
ok(Stats::targetChanges($rp, (int)$R1['id'], '2026-03-05', '2026-03-08') === [], 'aralık dışındaki değişiklik işaretlenmez');
$C = Stats::compare($rp, [(int)$R1['id'], (int)$R2['id']], '2026-03-02', '2026-03-08');
ok($C[(int)$R1['id']]['total'] === 3 && $C[(int)$R2['id']]['total'] === 1 && $C[(int)$R1['id']]['mobile'] === 100
   && count($C[(int)$R2['id']]['daily']) === 7, 'QR karşılaştırma: toplam, mobil oranı, ortak gün ekseni');
[$rg, $f, $t] = Stats::rangeFromRequest(['range' => 'custom', 'from' => '2026-03-08', 'to' => '2026-03-02']);
ok($rg === 'custom' && $f === '2026-03-02' && $t === '2026-03-08', 'özel aralık ters girilirse düzeltilir');

// Excel ve yazdırılabilir rapor
$S1 = Stats::summary($rp, (int)$R1['id'], '2026-03-02', '2026-03-08');
$RX = ['scope' => 'QR: rptest1', 'fileKey' => 'rptest1', 'from' => '2026-03-02', 'to' => '2026-03-08', 'prevFrom' => '2026-02-23', 'prevTo' => '2026-03-01',
       'total' => $S1['total'], 'unique' => $S1['unique'], 'prevTotal' => 1, 'prevUnique' => 1, 'bots' => $S1['bots'], 'redirected' => 0,
       'daily' => $S1['daily'], 'prevDaily' => array_fill(0, 7, 0), 'markers' => [1], 'devices' => $S1['devices'], 'os' => $S1['os'],
       'browsers' => $S1['browsers'], 'countries' => $S1['countries'], 'referrers' => $S1['referrers'], 'heat' => $S1['heat'],
       'links' => [['slug' => 'rptest1', 'title' => '=1+1 <b>&', 'category' => 'event', 'tags' => 'a,b', 'c' => 3, 'u' => 3]],
       'categories' => ['Etkinlik' => 3], 'tags' => [], 'owners' => [], 'changes' => $tc, 'by' => 'test', 'at' => '01.01.2026 10:00'];
$sheets = ReportExport::sheets($RX);
$names = array_column($sheets, 'name');
ok($names[0] === 'Özet' && in_array("QR'lar", $names, true) && in_array('Gün x saat', $names, true) && in_array('Hedef değişiklikleri', $names, true),
   'Excel raporu sayfaları (özet, QR\'lar, gün × saat, hedef değişiklikleri)');
ok(in_array(['Değişim (okutma)', '+%200'], $sheets[0]['rows'], true), 'Excel özetinde önceki döneme göre değişim');
if (Xlsx::available()) {
    $bin = Xlsx::build(array_merge($sheets, [['name' => 'Özet', 'rows' => [['a'], [1]]], ['name' => 'a/b:c*?[d]', 'rows' => [['x']]]]));
    $tmpx = tempnam(sys_get_temp_dir(), 'tx');
    file_put_contents($tmpx, $bin);
    $zx = new ZipArchive();
    $okZip = $zx->open($tmpx) === true;
    $wbx = $okZip ? $zx->getFromName('xl/workbook.xml') : '';
    $qrIdx = array_search("QR'lar", $names, true) + 1;
    $sh = $okZip ? $zx->getFromName("xl/worksheets/sheet{$qrIdx}.xml") : '';
    $allXml = true;
    for ($i = 0; $okZip && $i < $zx->numFiles; $i++) {
        $nm = $zx->getNameIndex($i);
        if (str_ends_with($nm, '.xml') || str_ends_with($nm, '.rels')) $allXml = $allXml && @simplexml_load_string($zx->getFromIndex($i)) !== false;
    }
    if ($okZip) $zx->close();
    @unlink($tmpx);
    ok($okZip && $allXml, 'Excel dosyası geçerli ZIP ve XML');
    ok(str_contains($wbx, 'name="Özet 2"') && str_contains($wbx, 'name="a b c   d"'), 'aynı ve geçersiz sayfa adları düzeltilir');
    ok(str_contains($sh, '<v>3</v>') && str_contains($sh, '=1+1 &lt;b&gt;&amp;') && !str_contains($sh, '<f>'), 'sayılar sayı, metinler kaçışlı metin (formül yok)');
} else {
    echo "  - ZipArchive yok, Excel testleri atlandı\n";
}
$svg = ReportExport::chartSvg($S1['daily'], array_fill(0, 7, 1), [1]);
ok(@simplexml_load_string($svg) !== false && substr_count($svg, '<path') === 3 && str_contains($svg, 'stroke-dasharray="3 3"'), 'yazdırılabilir rapor grafiği (SVG, önceki dönem ve işaret)');
ok(ReportExport::peak($S1['heat']) === ['Pazartesi', 10, 2] && ReportExport::changeText(3, 0) === '' && ReportExport::changeText(2, 4) === '-%50', 'en yoğun dilim ve değişim metni');

$rp->exec("DELETE FROM link_events WHERE slug LIKE 'rptest%'");
$rp->exec("DELETE FROM scans WHERE link_id IN (SELECT id FROM links WHERE slug LIKE 'rptest%')");
$rp->exec("DELETE FROM links WHERE slug LIKE 'rptest%'");

echo "\n" . ($fail ? "BAŞARISIZ" : "TAMAM") . " — {$pass} geçti, {$fail} kaldı\n";
exit($fail ? 1 : 0);
