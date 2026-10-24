<?php
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();

$pdo  = getPdo();
$slug = preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['slug'] ?? '');
if ($slug === '') redirect(BASE_URL . '/admin/links');

$link = LinkService::findBySlug($pdo, $slug);
if (!$link) {
    flash('danger', 'Link bulunamadı.');
    redirect(BASE_URL . '/admin/links');
}

// Kayıtlı logoyu geçici olarak değiştirmek için ?logo= (boş = logosuz). Kayıt değişmez.
$logoOverride = null;
if (array_key_exists('logo', $_GET)) {
    $lg = (string)$_GET['logo'];
    $logoOverride = ($lg !== '' && validateLogoFile($lg)) ? $lg : '';
}
$logoFile = $logoOverride ?? LinkService::effectiveLogo($link);

// ─── Dosya uç noktası: ?fmt=svg|png|pdf|eps|ai (önizleme veya indirme) ───────────────
if (!empty($_GET['fmt'])) {
    $fmt = in_array($_GET['fmt'], Share::FORMATS, true) ? $_GET['fmt'] : 'svg';
    try {
        $r = Share::render($link, $fmt, (int)($_GET['size'] ?? 0), !empty($_GET['transparent']), $logoOverride);
    } catch (\Throwable $e) {
        Logger::error('QR üretim hatası: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'QR üretilemedi: ' . $e->getMessage();
        exit;
    }
    header('Content-Type: ' . $r['mime']);
    if (!empty($_GET['dl'])) header('Content-Disposition: attachment; filename="' . $r['name'] . '"');
    header('Cache-Control: private, max-age=300');
    echo $r['data'];
    exit;
}

// ─── Baskı paketi (ZIP): SVG + PDF + EPS + AI + PNG + saydam PNG + kısa adres ─────────────
if (!empty($_GET['zip'])) {
    if (!class_exists('ZipArchive')) {
        flash('danger', 'Sunucuda ZIP desteği (ZipArchive) yok. Dosyaları tek tek indirin.');
        redirect(BASE_URL . '/admin/qr-view?slug=' . urlencode($slug));
    }
    $tmp = tempnam(sys_get_temp_dir(), 'qrzip');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    try {
        foreach ([['svg', 0, false], ['pdf', 100, false], ['eps', 100, false], ['ai', 100, false], ['png', 2000, false], ['png', 2000, true]] as [$fmt, $size, $tr]) {
            $r = Share::render($link, $fmt, $size, $tr, $logoOverride);
            $zip->addFromString($r['name'], $r['data']);
        }
    } catch (\Throwable $e) {
        Logger::error('Baskı paketi hatası: ' . $e->getMessage());
    }
    $zip->addFromString('OKU-BENI.txt',
        "QR kısa adresi: " . BASE_URL . "/r/{$slug}\r\n" .
        "Başlık: " . ($link['title'] ?? '-') . "\r\n\r\n" .
        "SVG, PDF, EPS ve AI vektöreldir (baskı için önerilir). EPS ve AI CMYK renklidir; matbaa/ajans yazılımlarında doğrudan açılır.\r\n" .
        "PNG dosyaları dijital kullanım içindir.\r\n" .
        "QR'ı küçültürken en az 2 x 2 cm kullanın, kenar boşluğunu (beyaz çerçeve) kesmeyin.\r\n" .
        "Basımdan önce gerçek bir telefonla okutarak test edin.\r\n");
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . Share::fileBase($link) . '-baski-paketi.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

// ─── Sayfa görünümü ───────────────────────────────────────────────────────────
$logoSupport = QrRenderer::logoSupport();
$logos       = listLogos();
$shortUrl    = BASE_URL . '/r/' . $slug;

try {
    $svgInline = Share::render($link, 'svg', 0, false, $logoOverride)['data'];
} catch (\Throwable $e) {
    $svgInline = '<p class="text-muted">QR üretilemedi: ' . e($e->getMessage()) . '</p>';
}

// İmzalı paylaşım bağlantısı (isteğe bağlı)
$shareUrl = null;
$shareDays = (int)($_GET['share_days'] ?? 0);
if ($shareDays > 0 && in_array($shareDays, [1, 7, 14, 30, 90], true)) {
    $shareUrl = Share::url($slug, $shareDays);
    LinkEvents::log($pdo, (int)$link['id'], $slug, Auth::actor(), 'share', ['gün' => $shareDays]);
}

$state = Redirector::state($link)['state'];
$style = LinkService::effectiveStyle($link);

function qrDownloadUrl(string $slug, string $fmt, int $size, string $logoQs, string $extra = ''): string {
    return BASE_URL . '/admin/qr-view?slug=' . urlencode($slug) . '&fmt=' . $fmt . '&size=' . $size . $logoQs . '&dl=1' . $extra;
}
$logoQs = $logoOverride !== null ? '&logo=' . urlencode($logoOverride) : '';

$pageTitle  = 'QR Görüntüle — ' . $slug;
$activePage = 'links';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title">📱 QR — <code><?= e($slug) ?></code></h1>
  <div class="btn-group">
    <a href="<?= BASE_URL ?>/admin/edit?id=<?= $link['id'] ?>" class="btn btn-ghost btn-sm">✏️ Düzenle</a>
    <a href="<?= BASE_URL ?>/admin/report?id=<?= $link['id'] ?>" class="btn btn-ghost btn-sm">📊 Rapor</a>
    <a href="<?= BASE_URL ?>/admin/links" class="btn btn-ghost btn-sm">← Geri</a>
  </div>
</div>

<div class="alert alert-info">
  🔗 Kısa URL: <strong class="font-mono"><?= e($shortUrl) ?></strong>
  <button class="copy-btn" data-copy="<?= e($shortUrl) ?>">📋</button>
  &nbsp;→&nbsp; <span class="text-muted text-sm"><?= e($link['target_url']) ?></span>
</div>

<div class="qr-grid">

<!-- Sol: önizleme + indirme + paylaşım -->
<div>
  <div class="card">
    <div class="card-title mb-3">📱 QR Önizleme</div>
    <div class="qr-preview-box"><?= $svgInline ?></div>

    <?php if (!empty($logos)): ?>
    <details class="mt-4" <?= $logoOverride !== null ? 'open' : '' ?>>
      <summary style="cursor:pointer;font-size:.85rem;color:var(--text-muted);padding:.4rem 0;user-select:none">
        ⚙️ Bu indirme için logoyu değiştir (kayıtlı ayar değişmez)
      </summary>
      <div class="mt-3">
        <div class="form-check mb-2">
          <input type="checkbox" id="use-logo-page-cb" <?= $logoFile ? 'checked' : '' ?>
                 onchange="if(!this.checked)location.href='<?= BASE_URL ?>/admin/qr-view?slug=<?= urlencode($slug) ?>&logo='">
          <span style="font-size:.85rem">Logo ekle</span>
        </div>
        <div class="logo-gallery" id="view-logo-gallery">
          <?php foreach ($logos as $lf): ?>
            <div class="logo-item <?= $logoFile === $lf ? 'selected' : '' ?>" style="cursor:pointer"
                 onclick="location.href='<?= BASE_URL ?>/admin/qr-view?slug=<?= urlencode($slug) ?>&logo=' + encodeURIComponent('<?= addslashes($lf) ?>')">
              <img src="<?= BASE_URL ?>/logo/<?= rawurlencode($lf) ?>" alt="<?= e($lf) ?>" loading="lazy">
              <span><?= e($lf) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </details>
    <?php endif; ?>
    <?php if (!$logoSupport['supported'] && $logoFile): ?>
      <div class="alert alert-warning mt-3">⚠️ GD veya Imagick eklentisi bulunamadı. PNG, logo olmadan üretildi.</div>
    <?php endif; ?>
  </div>

  <!-- İndirme -->
  <div class="card mt-4">
    <div class="card-title mb-3">⬇️ İndir</div>

    <div class="dl-group">
      <div class="dl-label">Vektörel — baskı için önerilir</div>
      <div class="btn-group">
        <a class="btn btn-success dl-link" data-tr="1" href="<?= qrDownloadUrl($slug, 'svg', 0, $logoQs) ?>">SVG</a>
        <?php foreach (Share::PDF_MM as $mm): ?>
          <a class="btn btn-primary dl-link" data-tr="1" href="<?= qrDownloadUrl($slug, 'pdf', $mm, $logoQs) ?>">PDF <?= $mm ?> mm</a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="dl-group">
      <div class="dl-label">Matbaa / ajans — Illustrator, CorelDRAW, InDesign (CMYK, vektörel)<?= tip('EPS ve AI dosyaları vektöreldir; 100 mm çizim alanıyla gelir ve kalite kaybı olmadan istenen ölçüye büyütülüp küçültülebilir. Renkler CMYK yazılır, siyah yalnızca K (%100 siyah) kanalındadır.') ?></div>
      <div class="btn-group">
        <a class="btn btn-primary dl-link" data-tr="1" href="<?= qrDownloadUrl($slug, 'eps', 100, $logoQs) ?>">EPS</a>
        <a class="btn btn-primary dl-link" data-tr="1" href="<?= qrDownloadUrl($slug, 'ai', 100, $logoQs) ?>">AI (Illustrator)</a>
      </div>
    </div>

    <div class="dl-group">
      <div class="dl-label">Görsel — dijital kullanım, sunum, sosyal medya</div>
      <div class="btn-group">
        <?php foreach (Share::PNG_SIZES as $px): ?>
          <a class="btn btn-ghost dl-link" data-tr="1" href="<?= qrDownloadUrl($slug, 'png', $px, $logoQs) ?>">PNG <?= $px ?> px</a>
        <?php endforeach; ?>
      </div>
    </div>

    <label class="form-check mt-3">
      <input type="checkbox" id="dl-transparent">
      <span>Şeffaf arka plan (koyu zemine veya renkli tasarıma yerleştirmek için)</span>
    </label>

    <div class="mt-3">
      <a class="btn btn-primary" href="<?= BASE_URL ?>/admin/qr-view?slug=<?= urlencode($slug) ?>&zip=1<?= $logoQs ?>">📦 Baskı paketi (ZIP: SVG + PDF + PNG)</a>
    </div>
  </div>

  <!-- Paylaşım -->
  <div class="card mt-4">
    <div class="card-title mb-3">📤 Paylaş</div>
    <div class="btn-group">
      <button type="button" class="btn btn-ghost" id="share-native" style="display:none">📲 Paylaş…</button>
      <button type="button" class="btn btn-ghost" id="copy-image">🖼 QR görselini kopyala</button>
      <button type="button" class="btn btn-ghost copy-btn-lg" data-copy="<?= e($shortUrl) ?>">🔗 Kısa linki kopyala</button>
      <a class="btn btn-ghost" target="_blank" rel="noopener"
         href="https://wa.me/?text=<?= rawurlencode(($link['title'] ?: $slug) . ' — ' . $shortUrl) ?>">WhatsApp</a>
      <a class="btn btn-ghost"
         href="mailto:?subject=<?= rawurlencode('QR: ' . ($link['title'] ?: $slug)) ?>&body=<?= rawurlencode($shortUrl) ?>">E-posta</a>
    </div>

    <div class="mt-4">
      <div class="dl-label">Ajans / matbaa için indirme bağlantısı</div>
      <p class="text-xs text-muted mb-2">Panele giriş yapmadan SVG, PDF ve PNG indirebilen, süresi dolunca kapanan özel bağlantı.</p>
      <form method="GET" action="" class="btn-group">
        <input type="hidden" name="slug" value="<?= e($slug) ?>">
        <?php if ($logoOverride !== null): ?><input type="hidden" name="logo" value="<?= e($logoOverride) ?>"><?php endif; ?>
        <select class="form-control" name="share_days" style="max-width:150px">
          <?php foreach ([1 => '1 gün', 7 => '7 gün', 14 => '14 gün', 30 => '30 gün', 90 => '90 gün'] as $d => $lbl): ?>
            <option value="<?= $d ?>" <?= (int)Settings::get('share_ttl_days') === $d ? 'selected' : '' ?>><?= $lbl ?> geçerli</option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-primary" type="submit">Bağlantı oluştur</button>
      </form>
      <?php if ($shareUrl): ?>
        <div class="share-box mt-3">
          <input class="form-control font-mono text-xs" type="text" readonly value="<?= e($shareUrl) ?>" onclick="this.select()">
          <button class="copy-btn" data-copy="<?= e($shareUrl) ?>" title="Kopyala">📋</button>
        </div>
        <p class="text-xs text-muted mt-1">Bu bağlantıyla yalnızca QR dosyaları indirilir; hedef adres ve panel görünmez.
          Tüm paylaşım bağlantılarını <a href="<?= BASE_URL ?>/admin/settings">Ayarlar</a>'dan iptal edebilirsiniz.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Sağ: link bilgisi -->
<div class="card">
  <div class="card-title mb-3">📋 Link Bilgisi</div>
  <div class="text-sm info-list">
    <div><div class="info-k">Hedef URL</div><div style="word-break:break-all;font-weight:500"><?= e($link['target_url']) ?></div></div>
    <div><div class="info-k">Durum</div>
      <?php $lbl = ['live' => ['● Aktif', 'badge-success'], 'inactive' => ['○ Pasif', 'badge-danger'], 'scheduled' => ['⏳ Başlamadı', 'badge-muted'],
                    'expired' => ['⌛ Süresi doldu', 'badge-muted'], 'archived' => ['🗄 Arşivde', 'badge-muted']][$state]; ?>
      <span class="badge <?= $lbl[1] ?>"><?= $lbl[0] ?></span></div>
    <?php if (!empty($link['category'])): ?>
      <div><div class="info-k">Tür</div><?= e(LinkService::CATEGORIES[$link['category']] ?? $link['category']) ?></div>
    <?php endif; ?>
    <?php if (!empty($link['tags'])): ?>
      <div><div class="info-k">Etiketler</div><?php foreach (explode(',', $link['tags']) as $t): ?><a class="tag-chip" href="<?= BASE_URL ?>/admin/links?tag=<?= urlencode($t) ?>" title="Bu etiketli tüm QR'ları göster">#<?= e($t) ?></a><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if (!empty($link['starts_at']) || !empty($link['ends_at'])): ?>
      <div><div class="info-k">Zamanlama</div>
        <?= e(substr($link['starts_at'] ?? '—', 0, 16)) ?> → <?= e(substr($link['ends_at'] ?? '—', 0, 16)) ?></div>
    <?php endif; ?>
    <div><div class="info-k">Görünüm</div>
      <span class="swatch" style="background:<?= e($style['fg']) ?>"></span><span class="swatch" style="background:<?= e($style['bg']) ?>"></span>
      <span class="text-xs text-muted"><?= e(LinkService::SHAPES[$style['shape']]) ?></span></div>
    <div><div class="info-k">Toplam okutma</div><div style="font-size:1.4rem;font-weight:700"><?= formatCount((int)$link['scan_count']) ?></div></div>
    <?php if ($link['logo']): ?>
      <div><div class="info-k">Kayıtlı logo</div>
        <div style="display:flex;align-items:center;gap:.5rem">
          <img src="<?= BASE_URL ?>/logo/<?= rawurlencode($link['logo']) ?>" alt="" style="width:40px;height:40px;object-fit:contain;border-radius:4px;background:var(--surface2);padding:4px">
          <span class="text-xs text-muted"><?= e($link['logo']) ?></span></div></div>
    <?php endif; ?>
    <div><div class="info-k">Oluşturulma</div><?= e(substr($link['created_at'], 0, 10)) ?></div>
    <?php if (!empty($link['notes'])): ?>
      <div><div class="info-k">Not</div><div style="white-space:pre-wrap"><?= e($link['notes']) ?></div></div>
    <?php endif; ?>
  </div>
  <div style="margin-top:1.5rem">
    <a href="<?= BASE_URL ?>/admin/edit?id=<?= $link['id'] ?>" class="btn btn-primary btn-sm" style="width:100%">✏️ Linki Düzenle</a>
  </div>
</div>

</div>

<script>
(function () {
  const cb = document.getElementById('dl-transparent');
  const links = document.querySelectorAll('.dl-link');
  const orig = new Map();
  links.forEach(a => orig.set(a, a.getAttribute('href')));
  cb.addEventListener('change', () => links.forEach(a => {
    a.setAttribute('href', orig.get(a) + (cb.checked ? '&transparent=1' : ''));
  }));

  const pngUrl = '<?= qrDownloadUrl($slug, 'png', 1200, $logoQs) ?>'.replace('&dl=1', '');
  const shortUrl = <?= json_encode($shortUrl) ?>;
  const title = <?= json_encode($link['title'] ?: $slug) ?>;

  // QR görselini panoya kopyala (e-posta / sunum / mesajlaşmaya yapıştırmak için)
  document.getElementById('copy-image').addEventListener('click', async (e) => {
    const btn = e.currentTarget, t = btn.textContent;
    try {
      const blob = await (await fetch(pngUrl, {credentials: 'same-origin'})).blob();
      await navigator.clipboard.write([new ClipboardItem({'image/png': blob})]);
      btn.textContent = '✓ Kopyalandı';
    } catch (err) { btn.textContent = 'Tarayıcı desteklemiyor — PNG indirin'; }
    setTimeout(() => btn.textContent = t, 2200);
  });
  document.querySelector('.copy-btn-lg').addEventListener('click', (e) => {
    const b = e.currentTarget, t = b.textContent;
    navigator.clipboard.writeText(b.dataset.copy).then(() => { b.textContent = '✓ Kopyalandı'; setTimeout(() => b.textContent = t, 1800); });
  });

  // Telefon / destekleyen tarayıcılarda sistem paylaşım menüsü (PNG dosyasıyla)
  if (navigator.share) {
    const b = document.getElementById('share-native');
    b.style.display = '';
    b.addEventListener('click', async () => {
      try {
        const blob = await (await fetch(pngUrl, {credentials: 'same-origin'})).blob();
        const file = new File([blob], 'qr-<?= e($slug) ?>.png', {type: 'image/png'});
        const data = {title: title, text: shortUrl, url: shortUrl};
        if (navigator.canShare && navigator.canShare({files: [file]})) data.files = [file];
        await navigator.share(data);
      } catch (err) {}
    });
  }
})();
</script>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
