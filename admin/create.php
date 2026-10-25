<?php
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();
Auth::requirePerm('links.edit');

$pdo         = getPdo();
$logos       = listLogos();
$logoSupport = QrRenderer::logoSupport();
$errors      = [];
$f           = ['logo_file' => Settings::get('brand_logo')]; // marka varsayılan logosu (varsa)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();

    $input = LinkService::fromForm($_POST, false);
    [$clean, $errors] = LinkService::normalize($input);
    $customSlug = sanitizeCustomSlug($_POST['custom_slug'] ?? '');

    if (!$errors) {
        try {
            $link = LinkService::create($pdo, $clean, $customSlug, Auth::actor());
            // QR'ı hemen üret ve cache'e yaz
            try {
                Share::render($link, 'svg');
            } catch (\Throwable $e) {
                Logger::warning('İlk QR üretimi başarısız: ' . $e->getMessage());
            }
            flash('success', "Link oluşturuldu! Kısa adres: {$link['slug']}");
            if (empty($link['tags'])) {
                flash('info', 'İpucu: Bu QR’a etiket (ülke, etkinlik, materyal…) eklerseniz raporlarda gruplayabilirsiniz. Düzenle sayfasından ekleyebilirsiniz.');
            }
            redirect(BASE_URL . '/admin/qr-view?slug=' . urlencode($link['slug']));
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
    $f = $_POST;
    $f['logo_file'] = !empty($_POST['use_logo']) ? ($_POST['logo_file'] ?? '') : '';
}

$mode       = 'create';
$previewSlug = '';
$formActions = '<button class="btn btn-primary mt-4" type="submit" style="width:100%;padding:.75rem">' . icon('qr') . ' Oluştur ve QR Üret</button>';

$pageTitle  = 'Yeni QR Oluştur';
$activePage = 'create';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title"><?= icon('plus') ?> Yeni QR Oluştur</h1>
  <div class="btn-group">
    <a href="<?= BASE_URL ?>/admin/bulk" class="btn btn-ghost"><?= icon('layers') ?> Toplu Oluştur (CSV)</a>
    <a href="<?= BASE_URL ?>/admin/links" class="btn btn-ghost"><?= icon('arrow-left') ?> Geri</a>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="POST" action="" id="create-form">
  <?= Auth::csrfField() ?>
  <?php include __DIR__ . '/views/_link_form.php'; ?>
</form>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
