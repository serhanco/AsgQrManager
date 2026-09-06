<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Logger.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/QrRenderer.php';

Logger::registerHandlers();
Auth::startSession();
Auth::requireLogin();

$pdo  = getPdo();
$slug = preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['slug'] ?? '');

if ($slug === '') redirect(BASE_URL . '/admin/links.php');

$stmt = $pdo->prepare('SELECT * FROM links WHERE slug = ? LIMIT 1');
$stmt->execute([$slug]);
$link = $stmt->fetch();

if (!$link) {
    flash('danger', 'Link bulunamadı.');
    redirect(BASE_URL . '/admin/links.php');
}

// Parametreler
$fmtRaw = $_GET['fmt']  ?? '';
$fmt    = in_array($fmtRaw, ['svg','png']) ? $fmtRaw : 'svg';

$eccRaw = strtoupper($_GET['ecc'] ?? '');
$ecc    = in_array($eccRaw, ['L','M','Q','H']) ? $eccRaw : ($link['logo'] ? 'H' : 'M');

$sizeRaw = (int)($_GET['size'] ?? 0);
$size    = in_array($sizeRaw, [600, 1200, 2000]) ? $sizeRaw : 600;

$margin = max(0, min(10, (int)($_GET['margin'] ?? 4)));
$logoFile = $_GET['logo'] ?? $link['logo'] ?? '';

// Logo doğrulama
if ($logoFile && !validateLogoFile($logoFile)) $logoFile = '';
if ($logoFile) $ecc = 'H';

// Doğrudan download modu
$download = !empty($_GET['dl']);

$qrUrl = BASE_URL . '/r/' . $slug;

// Sadece SVG/PNG döndür (AJAX önizleme veya download)
if (!empty($_GET['fmt'])) {
    try {
        $result = QrRenderer::getOrGenerate($qrUrl, $slug, $fmt, $ecc, $size, $margin, $logoFile ?: null);
    } catch (\Throwable $e) {
        Logger::error('QR üretim hatası: ' . $e->getMessage());
        http_response_code(500);
        if ($fmt === 'svg') {
            header('Content-Type: image/svg+xml');
            echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><text y="50" x="10" font-size="12" fill="red">Hata</text></svg>';
        } else {
            header('Content-Type: image/png');
            // 1px boş PNG
            echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==');
        }
        exit;
    }

    header('Content-Type: ' . $result['mime']);
    if ($download) {
        header('Content-Disposition: attachment; filename="qr-' . $slug . '.' . $fmt . '"');
    }
    header('Cache-Control: public, max-age=3600');
    echo $result['data'];
    exit;
}

// Sayfa görünümü
$logoSupport = QrRenderer::logoSupport();
$logos       = listLogos();

// SVG önizleme için üret
try {
    $svgResult = QrRenderer::getOrGenerate($qrUrl, $slug, 'svg', $ecc, 600, $margin, $logoFile ?: null);
    $svgInline = $svgResult['data'];
} catch (\Throwable $e) {
    $svgInline = '<p class="text-muted">QR üretilemedi: ' . e($e->getMessage()) . '</p>';
}

$pageTitle  = 'QR Görüntüle — ' . $slug;
$activePage = 'links';
include __DIR__ . '/views/_layout.php';

// Önizleme sorgu dizesi
function previewUrl(string $base, string $slug, string $fmt, string $ecc, int $size, int $margin, string $logo): string {
    return $base . '/admin/qr-view.php?slug=' . urlencode($slug)
         . '&fmt=' . $fmt . '&ecc=' . $ecc . '&size=' . $size
         . '&margin=' . $margin . '&logo=' . urlencode($logo);
}
?>

<div class="page-header">
  <h1 class="page-title">📱 QR Görüntüle — <code><?= e($slug) ?></code></h1>
  <div class="btn-group">
    <a href="<?= BASE_URL ?>/admin/edit.php?id=<?= $link['id'] ?>" class="btn btn-ghost btn-sm">✏️ Düzenle</a>
    <a href="<?= BASE_URL ?>/admin/links.php" class="btn btn-ghost btn-sm">← Geri</a>
  </div>
</div>

<!-- Kısa URL bilgisi -->
<div class="alert alert-info">
  🔗 Kısa URL: <strong class="font-mono"><?= e(BASE_URL . '/r/' . $slug) ?></strong>
  <button class="copy-btn" data-copy="<?= e(BASE_URL . '/r/' . $slug) ?>">📋</button>
  &nbsp;→&nbsp; <span class="text-muted text-sm"><?= e($link['target_url']) ?></span>
</div>

<div style="display:grid;grid-template-columns:1fr 320px;gap:1.5rem;align-items:start">

<!-- Sol: QR Önizleme + İndirme -->
<div class="card">
  <div class="card-title mb-3">📱 QR Önizleme (SVG)</div>
  <div class="qr-preview-box">
    <?= $svgInline ?>
  </div>

  <!-- İndirme Ayarları (katlanabilir) -->
  <details class="mt-4" <?= ($ecc !== ($link['logo'] ? 'H' : 'M') || $margin !== 4 || $logoFile) ? 'open' : '' ?>>
    <summary style="cursor:pointer;font-size:.85rem;color:var(--text-muted);padding:.4rem 0;user-select:none">
      ⚙️ İndirme Seçenekleri
    </summary>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-top:.75rem">
      <div>
        <label class="form-label" style="font-size:.8rem">Hata Düzeltme</label>
        <select class="form-control" style="font-size:.85rem"
                onchange="location.href=<?= json_encode(BASE_URL . '/admin/qr-view.php?slug=' . $slug . '&ecc=') ?>+this.value+'&margin=<?= $margin ?>&logo=<?= urlencode($logoFile) ?>'">
          <?php foreach (['L','M','Q','H'] as $lvl): ?>
            <option value="<?= $lvl ?>" <?= $ecc === $lvl ? 'selected' : '' ?>><?= $lvl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label" style="font-size:.8rem">Kenar Boşluğu</label>
        <select class="form-control" style="font-size:.85rem"
                onchange="location.href=<?= json_encode(BASE_URL . '/admin/qr-view.php?slug=' . $slug . '&ecc=' . $ecc . '&margin=') ?>+this.value+'&logo=<?= urlencode($logoFile) ?>'">
          <?php foreach (range(0, 8) as $m): ?>
            <option value="<?= $m ?>" <?= $margin === $m ? 'selected' : '' ?>><?= $m ?> modül</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <?php if (!empty($logos)): ?>
    <div class="mt-3">
      <label class="form-label" style="font-size:.8rem">Logo</label>
      <div class="form-check mb-2">
        <input type="checkbox" id="use-logo-page-cb" <?= $logoFile ? 'checked' : '' ?>
               onchange="if(!this.checked)location.href=<?= json_encode(BASE_URL . '/admin/qr-view.php?slug=' . $slug . '&ecc=' . $ecc . '&margin=' . $margin . '&logo=') ?>+''">
        <span style="font-size:.85rem">Logo Ekle</span>
      </div>
      <div class="logo-gallery" id="view-logo-gallery">
        <?php foreach ($logos as $lf): ?>
          <div class="logo-item <?= $logoFile === $lf ? 'selected' : '' ?>"
               style="cursor:pointer"
               onclick="location.href=<?= json_encode(BASE_URL . '/admin/qr-view.php?slug=' . $slug . '&ecc=H&margin=' . $margin . '&logo=') ?>+encodeURIComponent('<?= addslashes(e($lf)) ?>')">
            <img src="<?= BASE_URL ?>/logo/<?= rawurlencode($lf) ?>" alt="<?= e($lf) ?>" loading="lazy">
            <span><?= e($lf) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </details>

  <!-- İndirme butonları -->
  <div class="btn-group mt-3" style="justify-content:center;flex-wrap:wrap">
    <a href="<?= previewUrl(BASE_URL, $slug, 'svg', $ecc, 600, $margin, $logoFile) ?>&dl=1"
       class="btn btn-success" download="qr-<?= e($slug) ?>.svg">
      ⬇️ SVG İndir
    </a>
    <a href="<?= previewUrl(BASE_URL, $slug, 'png', $ecc, 600, $margin, $logoFile) ?>&dl=1"
       class="btn btn-primary" download="qr-<?= e($slug) ?>.png">
      🖼 PNG 600px
    </a>
    <a href="<?= previewUrl(BASE_URL, $slug, 'png', $ecc, 1200, $margin, $logoFile) ?>&dl=1"
       class="btn btn-ghost" download="qr-<?= e($slug) ?>-1200.png">
      🖼 PNG 1200px
    </a>
    <a href="<?= previewUrl(BASE_URL, $slug, 'png', $ecc, 2000, $margin, $logoFile) ?>&dl=1"
       class="btn btn-ghost" download="qr-<?= e($slug) ?>-2000.png">
      🖼 PNG 2000px
    </a>
  </div>

  <?php if (!$logoSupport['supported'] && $logoFile): ?>
    <div class="alert alert-warning mt-3">
      ⚠️ GD veya Imagick eklentisi bulunamadı. PNG, logo olmadan üretildi.
    </div>
  <?php endif; ?>
</div>

<!-- Sağ: Salt-okunur Link Bilgisi -->
<div class="card">
  <div class="card-title mb-3">📋 Link Bilgisi</div>

  <div class="text-sm" style="display:flex;flex-direction:column;gap:.9rem">

    <div>
      <div class="text-muted mb-1" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Hedef URL</div>
      <div style="word-break:break-all;font-weight:500"><?= e($link['target_url']) ?></div>
    </div>

    <div>
      <div class="text-muted mb-1" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Durum</div>
      <span class="badge <?= $link['is_active'] ? 'badge-success' : 'badge-danger' ?>">
        <?= $link['is_active'] ? '● Aktif' : '○ Pasif' ?>
      </span>
    </div>

    <div>
      <div class="text-muted mb-1" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Toplam Tarama</div>
      <div style="font-size:1.4rem;font-weight:700"><?= formatCount((int)$link['scan_count']) ?></div>
    </div>

    <?php if ($link['logo']): ?>
    <div>
      <div class="text-muted mb-1" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Kayıtlı Logo</div>
      <div style="display:flex;align-items:center;gap:.5rem">
        <img src="<?= BASE_URL ?>/logo/<?= rawurlencode($link['logo']) ?>"
             alt="<?= e($link['logo']) ?>"
             style="width:40px;height:40px;object-fit:contain;border-radius:4px;background:var(--bg-secondary);padding:4px">
        <span class="text-xs text-muted"><?= e($link['logo']) ?></span>
      </div>
    </div>
    <?php endif; ?>

    <div>
      <div class="text-muted mb-1" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Oluşturulma</div>
      <div><?= e(substr($link['created_at'], 0, 10)) ?></div>
    </div>

    <?php if (!empty($link['updated_at'])): ?>
    <div>
      <div class="text-muted mb-1" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Son Güncelleme</div>
      <div><?= e(substr($link['updated_at'], 0, 10)) ?></div>
    </div>
    <?php endif; ?>

  </div>

  <div style="margin-top:1.5rem">
    <a href="<?= BASE_URL ?>/admin/edit.php?id=<?= $link['id'] ?>" class="btn btn-primary btn-sm" style="width:100%">
      ✏️ Linki Düzenle
    </a>
  </div>
</div>

</div>

<?php include __DIR__ . '/views/_layout_end.php'; ?>


