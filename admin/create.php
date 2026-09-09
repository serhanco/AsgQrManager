<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Logger.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/QrRenderer.php';

Logger::registerHandlers();
Auth::startSession();
Auth::requireLogin();

$pdo    = getPdo();
$logos  = listLogos();
$errors = [];
$created = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();

    $targetUrl   = trim($_POST['target_url'] ?? '');
    $title       = trim($_POST['title']      ?? '');
    $customSlug  = sanitizeCustomSlug($_POST['custom_slug'] ?? '');
    $useLogo     = !empty($_POST['use_logo']);
    $logoFile    = $useLogo ? (trim($_POST['logo_file'] ?? '')) : '';
    $ecc         = 'M';
    $margin      = max(0, min(10, (int)($_POST['margin'] ?? 4)));

    // Doğrulama
    if (!isValidUrl($targetUrl)) {
        $errors[] = 'Geçerli bir hedef URL girin (http:// veya https:// ile başlamalı).';
    }
    if ($useLogo && $logoFile && !validateLogoFile($logoFile)) {
        $errors[] = 'Seçilen logo geçersiz.';
        $logoFile = '';
    }
    if ($useLogo && $logoFile) {
        // Logo varken ECC otomatik H
        $ecc = 'H';
    }

    // Slug
    if ($customSlug !== '') {
        if (strlen($customSlug) < 3 || strlen($customSlug) > 16) {
            $errors[] = 'Özel slug 3–16 karakter arasında olmalı.';
        } else {
            // Benzersizlik
            $chk = $pdo->prepare('SELECT id FROM links WHERE slug = ? LIMIT 1');
            $chk->execute([$customSlug]);
            if ($chk->fetch()) {
                $errors[] = "'{$customSlug}' slugı zaten kullanılıyor.";
            }
        }
        $slug = $customSlug;
    } else {
        $slug = generateUniqueSlug($pdo, SLUG_LENGTH);
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare(
            'INSERT INTO links (slug, title, target_url, is_active, logo) VALUES (?, ?, ?, 1, ?)'
        );
        $stmt->execute([$slug, $title ?: null, $targetUrl, $logoFile ?: null]);
        $newId = (int)$pdo->lastInsertId();

        // QR'ı hemen üret ve cache'e yaz
        $qrUrl = BASE_URL . '/r/' . $slug;
        try {
            QrRenderer::getOrGenerate($qrUrl, $slug, 'svg', $ecc, 600, $margin, $logoFile ?: null);
        } catch (\Throwable $e) {
            Logger::warning('İlk QR üretimi başarısız: ' . $e->getMessage());
        }

        Logger::info("Yeni link oluşturuldu: slug={$slug} url={$targetUrl}");
        flash('success', "Link oluşturuldu! Slug: {$slug}");
        redirect(BASE_URL . '/admin/qr-view.php?slug=' . urlencode($slug));
    }
}

$logoSupport = QrRenderer::logoSupport();
$pageTitle   = 'Yeni Link Oluştur';
$activePage  = 'create';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title">➕ Yeni Link Oluştur</h1>
  <a href="<?= BASE_URL ?>/admin/links.php" class="btn btn-ghost">← Geri</a>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start">

<!-- Form -->
<form method="POST" action="" id="create-form">
  <?= Auth::csrfField() ?>

  <div class="card">
    <div class="card-title mb-4">🔗 Bağlantı Bilgileri</div>

    <div class="form-group">
      <label class="form-label" for="target_url">
        Hedef URL <span class="hint">* Zorunlu</span>
      </label>
      <input class="form-control" type="url" id="target_url" name="target_url"
             placeholder="https://example.com/uzun-sayfa-adresi"
             value="<?= e($_POST['target_url'] ?? '') ?>" required>
    </div>

    <div class="form-group">
      <label class="form-label" for="title">
        Başlık <span class="hint">İsteğe bağlı</span>
      </label>
      <input class="form-control" type="text" id="title" name="title"
             placeholder="Ürün kataloğu QR'ı"
             value="<?= e($_POST['title'] ?? '') ?>" maxlength="255">
    </div>

    <div class="form-group">
      <label class="form-label" for="custom_slug">
        Özel Slug <span class="hint">Boş bırakırsanız otomatik üretilir (A-Z a-z 0-9 _ -)</span>
      </label>
      <input class="form-control" type="text" id="custom_slug" name="custom_slug"
             placeholder="brosur-2025"
             value="<?= e($_POST['custom_slug'] ?? '') ?>"
             pattern="[A-Za-z0-9_-]{0,16}" maxlength="16">
    </div>
  </div>

  <div class="card mt-4">
    <div class="card-title mb-4">⚙️ QR Seçenekleri</div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="margin-input">Kenar Boşluğu (modül)</label>
        <input class="form-control" type="number" id="margin-input" name="margin"
               min="0" max="10" value="<?= (int)($_POST['margin'] ?? 4) ?>">
      </div>
    </div>

    <!-- Logo seçimi -->
    <div class="form-group">
      <label class="form-check">
        <input type="checkbox" id="use-logo-checkbox" name="use_logo" value="1"
               <?= !empty($_POST['use_logo']) ? 'checked' : '' ?>>
        <span>Logo Ekle</span>
        <?php if (!$logoSupport['supported']): ?>
          <span class="badge badge-muted" title="GD veya Imagick bulunamadı">⚠️ PNG logosuz</span>
        <?php endif; ?>
      </label>
    </div>

    <input type="hidden" id="logo-file-input" name="logo_file" value="<?= e($_POST['logo_file'] ?? '') ?>">

    <div class="logo-gallery-wrap" style="display:none">
      <div class="form-label mb-2">Logo seçin:</div>
      <?php if (empty($logos)): ?>
        <p class="text-sm text-muted">
          Henüz logo yok. <code>logo/</code> klasörüne PNG/SVG/JPG/WEBP dosyaları ekleyin.
        </p>
      <?php else: ?>
        <div class="logo-gallery">
          <?php foreach ($logos as $logoFile): ?>
            <div class="logo-item <?= ($_POST['logo_file'] ?? '') === $logoFile ? 'selected' : '' ?>"
                 data-file="<?= e($logoFile) ?>">
              <img src="<?= BASE_URL ?>/logo/<?= rawurlencode($logoFile) ?>"
                   alt="<?= e($logoFile) ?>" loading="lazy">
              <span><?= e($logoFile) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <button class="btn btn-primary mt-4" type="submit" style="width:100%;padding:.75rem">
    🚀 Oluştur ve QR Üret
  </button>
</form>

<!-- Sağ: Önizleme bilgisi -->
<div>
  <div class="card">
    <div class="card-title mb-2">📱 QR Önizleme</div>
    <p class="text-sm text-muted mb-4">
      Formu doldurup <strong>Oluştur</strong>'a tıkladıktan sonra QR önizleme ekranına geçeceksiniz.
    </p>
    <div class="qr-preview-box">
      <span style="font-size:4rem">📱</span>
    </div>
  </div>
  <div class="card mt-4">
    <div class="card-title mb-2">ℹ️ Bilgi</div>
    <ul class="text-sm" style="padding-left:1rem;color:var(--text-muted);line-height:2">
      <li>QR kod, sabit bir kısa URL kodlar.</li>
      <li>Hedef URL istediğiniz zaman değiştirilebilir.</li>
      <li>Logo eklendiğinde ECC otomatik H'ye yükseltilir.</li>
      <li>SVG çıktısı sonsuz ölçeklenebilir (baskıya uygun).</li>
    </ul>
  </div>
</div>

</div>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
