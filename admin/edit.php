<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Logger.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/QrRenderer.php';

Logger::registerHandlers();
Auth::startSession();
Auth::requireLogin();

$pdo = getPdo();
$id  = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM links WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$link = $stmt->fetch();

if (!$link) {
    flash('danger', 'Link bulunamadı.');
    redirect(BASE_URL . '/admin/links');
}

$logos  = listLogos();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();

    $targetUrl = trim($_POST['target_url'] ?? '');
    $title     = trim($_POST['title']      ?? '');
    $isActive  = isset($_POST['is_active']) ? 1 : 0;
    $useLogo   = !empty($_POST['use_logo']);
    $logoFile  = $useLogo ? trim($_POST['logo_file'] ?? '') : '';

    if (!isValidUrl($targetUrl)) {
        $errors[] = 'Geçerli bir URL girin (http:// veya https://).';
    }
    if ($useLogo && $logoFile && !validateLogoFile($logoFile)) {
        $errors[] = 'Seçilen logo geçersiz.';
        $logoFile = '';
    }

    if (empty($errors)) {
        $upd = $pdo->prepare(
            'UPDATE links SET target_url=?, title=?, is_active=?, logo=?, updated_at=NOW() WHERE id=?'
        );
        $upd->execute([$targetUrl, $title ?: null, $isActive, $logoFile ?: null, $id]);

        // Eski cache'leri temizle (yeni URL / logo değişmiş olabilir)
        QrRenderer::clearCache($link['slug']);

        Logger::info("Link güncellendi: id={$id} slug={$link['slug']}");
        flash('success', 'Link başarıyla güncellendi.');
        redirect(BASE_URL . '/admin/edit?id=' . $id);
    }
    // Form değerlerini yenile
    $link['target_url'] = $targetUrl;
    $link['title']      = $title;
    $link['is_active']  = $isActive;
    $link['logo']       = $logoFile;
}

$pageTitle  = 'Link Düzenle';
$activePage = 'links';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title">✏️ Link Düzenle</h1>
  <div class="btn-group">
    <a href="<?= BASE_URL ?>/admin/qr-view?slug=<?= urlencode($link['slug']) ?>"
       class="btn btn-ghost">📱 QR Görüntüle</a>
    <a href="<?= BASE_URL ?>/admin/links" class="btn btn-ghost">← Geri</a>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<!-- Slug bilgisi (salt okunur) -->
<div class="alert alert-info">
  🔒 QR kodu sabit kalır. Slug: <strong class="font-mono"><?= e($link['slug']) ?></strong>
  &nbsp;→&nbsp;
  <span class="font-mono text-xs"><?= e(BASE_URL . '/r/' . $link['slug']) ?></span>
  <button class="copy-btn" data-copy="<?= e(BASE_URL . '/r/' . $link['slug']) ?>" style="vertical-align:middle">📋</button>
</div>

<form method="POST" action="">
  <?= Auth::csrfField() ?>

  <div class="card">
    <div class="form-group">
      <label class="form-label" for="target_url">Hedef URL *</label>
      <input class="form-control" type="url" id="target_url" name="target_url"
             value="<?= e($link['target_url']) ?>" required>
      <small class="text-muted text-xs">Bu adresi değiştirdiğinizde aynı QR yeni sayfaya yönlendirir.</small>
    </div>

    <div class="form-group">
      <label class="form-label" for="title">Başlık</label>
      <input class="form-control" type="text" id="title" name="title"
             value="<?= e($link['title'] ?? '') ?>" maxlength="255">
    </div>

    <div class="form-group">
      <label class="form-check">
        <input type="checkbox" name="is_active" value="1" <?= $link['is_active'] ? 'checked' : '' ?>>
        <span>Aktif (QR bu sayfaya yönlendirsin)</span>
      </label>
    </div>
  </div>

  <!-- Logo seçimi -->
  <div class="card mt-4">
    <div class="card-title mb-3">🖼 Logo</div>
    <div class="form-group">
      <label class="form-check">
        <input type="checkbox" id="use-logo-checkbox" name="use_logo" value="1"
               <?= !empty($link['logo']) ? 'checked' : '' ?>>
        <span>Logo Kullan</span>
      </label>
    </div>
    <input type="hidden" id="logo-file-input" name="logo_file" value="<?= e($link['logo'] ?? '') ?>">

    <div class="logo-gallery-wrap" style="<?= empty($link['logo']) ? 'display:none' : '' ?>">
      <?php if (empty($logos)): ?>
        <p class="text-sm text-muted">logo/ klasörü boş.</p>
      <?php else: ?>
        <div class="logo-gallery">
          <?php foreach ($logos as $lf): ?>
            <div class="logo-item <?= $link['logo'] === $lf ? 'selected' : '' ?>"
                 data-file="<?= e($lf) ?>">
              <img src="<?= BASE_URL ?>/logo/<?= rawurlencode($lf) ?>" alt="<?= e($lf) ?>" loading="lazy">
              <span><?= e($lf) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="btn-group mt-4">
    <button class="btn btn-primary" type="submit">💾 Kaydet</button>
    <a href="<?= BASE_URL ?>/admin/links" class="btn btn-ghost">İptal</a>
  </div>
</form>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
