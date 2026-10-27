<?php
/**
 * share.php — İmzalı, süreli QR indirme sayfası (giriş gerektirmez).
 * Panelden oluşturulan bağlantıyla ajans / matbaa / ofis çalışanı QR dosyalarını indirir.
 * Hedef adres ve yönetim paneli görünmez.
 */
require_once __DIR__ . '/lib/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');

$slug = preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['s'] ?? '');
$exp  = (int)($_GET['e'] ?? 0);
$sig  = (string)($_GET['sig'] ?? '');

function shareDeny(string $msg): never {
    http_response_code(403);
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Bağlantı geçersiz</title><link rel="stylesheet" href="' . e(BASE_URL) . '/assets/style.css"></head><body>'
       . '<div class="login-page"><div class="login-card"><h1 class="login-title">Bağlantı kullanılamıyor</h1>'
       . '<p class="login-sub">' . e($msg) . '</p></div></div></body></html>';
    exit;
}

if ($slug === '' || !Share::verify($slug, $exp, $sig)) {
    shareDeny('Bu indirme bağlantısının süresi dolmuş ya da bağlantı geçersiz. Lütfen yeni bir bağlantı isteyin.');
}

$link = LinkService::findBySlug(getPdo(), $slug);
if (!$link || !empty($link['archived_at'])) shareDeny('Bu QR artık mevcut değil.');

// ─── İndirme ──────────────────────────────────────────────────────────────────
if (!empty($_GET['fmt'])) {
    $fmt = in_array($_GET['fmt'], Share::FORMATS, true) ? $_GET['fmt'] : 'svg';
    try {
        $r = Share::render($link, $fmt, (int)($_GET['size'] ?? 0), !empty($_GET['transparent']));
    } catch (\Throwable $e) {
        Logger::error('Paylaşım indirme hatası: ' . $e->getMessage());
        http_response_code(500);
        exit('QR üretilemedi.');
    }
    header('Content-Type: ' . $r['mime']);
    header('Content-Disposition: ' . (!empty($_GET['inline']) ? 'inline' : 'attachment') . '; filename="' . $r['name'] . '"');
    header('Cache-Control: private, max-age=300');
    echo $r['data'];
    exit;
}

// ─── Sayfa ────────────────────────────────────────────────────────────────────
$base = BASE_URL . '/share?s=' . rawurlencode($slug) . '&e=' . $exp . '&sig=' . $sig;
$svg  = Share::render($link, 'svg')['data'];
$days = max(0, (int)ceil(($exp - time()) / 86400));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>QR İndir — ACIBADEM QR Code Manager</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/icon.svg">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
</head>
<body>
<div class="login-page" style="align-items:flex-start;padding-top:2rem">
  <div class="login-card" style="max-width:560px">
    <div class="login-logo"><img src="<?= BASE_URL ?>/assets/icon.svg" alt="" style="height:4.5rem;width:auto;margin:0 auto"></div>
    <h1 class="login-title"><?= e($link['title'] ?: $slug) ?></h1>
    <p class="login-sub">QR dosyalarını indirin · bağlantı <?= $days ?> gün daha geçerli</p>

    <div class="qr-preview-box" style="margin-bottom:1rem"><?= $svg ?></div>

    <div class="text-sm" style="margin-bottom:.35rem;font-weight:600">Vektörel — baskı için önerilir</div>
    <div class="btn-group" style="margin-bottom:1rem">
      <a class="btn btn-success dl" href="<?= e($base) ?>&fmt=svg">SVG</a>
      <?php foreach (Share::mmOptions($link) as $mm): ?>
        <a class="btn btn-primary dl" href="<?= e($base) ?>&fmt=pdf&size=<?= $mm ?>">PDF <?= $mm ?> mm</a>
      <?php endforeach; ?>
      <a class="btn btn-primary dl" href="<?= e($base) ?>&fmt=eps&size=100">EPS (CMYK)</a>
      <a class="btn btn-primary dl" href="<?= e($base) ?>&fmt=ai&size=100">AI (Illustrator)</a>
    </div>

    <div class="text-sm" style="margin-bottom:.35rem;font-weight:600">Görsel (PNG)</div>
    <div class="btn-group" style="margin-bottom:1rem">
      <?php foreach (Share::PNG_SIZES as $px): ?>
        <a class="btn btn-ghost dl" href="<?= e($base) ?>&fmt=png&size=<?= $px ?>">PNG <?= $px ?> px</a>
      <?php endforeach; ?>
    </div>

    <label class="form-check" style="margin-bottom:1rem">
      <input type="checkbox" id="tr"><span>Şeffaf arka plan</span>
    </label>

    <p class="text-xs text-muted">QR'ı en az 2 × 2 cm basın, çevresindeki beyaz boşluğu kesmeyin ve basımdan önce telefonla okutarak test edin.</p>
  </div>
</div>
<script>
const cb = document.getElementById('tr');
const links = [...document.querySelectorAll('a.dl')];
const orig = links.map(a => a.getAttribute('href'));
cb.addEventListener('change', () => links.forEach((a, i) => a.setAttribute('href', orig[i] + (cb.checked ? '&transparent=1' : ''))));
</script>
</body>
</html>
