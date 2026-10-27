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
$vcardReady  = VCard::available($pdo);
$qrType      = $vcardReady && (($_POST['qr_type'] ?? $_GET['type'] ?? '') === 'vcard') ? 'vcard' : 'link';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();

    $input = LinkService::fromForm($_POST, false);
    $vc = $photo = null; $vErrors = [];
    if ($qrType === 'vcard') {
        // Kartvizit: hedef adres kişinin web sitesi (kayıt okunamazsa QR oraya gider), UTM yok
        [$vc, $vErrors] = VCard::normalize(VCard::fromPost($_POST));
        [$photo, $pErr] = VCard::photoFromUpload($_FILES['vc_photo'] ?? null);
        if ($pErr) $vErrors[] = $pErr;
        $input['target_url']  = VCard::fallbackTarget($vc);
        $input['utm_enabled'] = 0;
        if (trim($input['title']) === '') $input['title'] = VCard::fullName($vc);
    }
    [$clean, $errors] = LinkService::normalize($input);
    $errors = array_merge($vErrors, $errors);
    $customSlug = sanitizeCustomSlug($_POST['custom_slug'] ?? '');

    if (!$errors) {
        try {
            if ($qrType === 'vcard') {
                $pdo->beginTransaction();
                $link = LinkService::create($pdo, $clean + ['qr_type' => 'vcard'], $customSlug, Auth::actor());
                VCard::save($pdo, (int)$link['id'], $vc, $photo);
                $pdo->commit();
            } else {
                $link = LinkService::create($pdo, $clean, $customSlug, Auth::actor());
            }
            // QR'ı hemen üret ve cache'e yaz
            try {
                Share::render($link, 'svg');
            } catch (\Throwable $e) {
                Logger::warning('İlk QR üretimi başarısız: ' . $e->getMessage());
            }
            flash('success', ($qrType === 'vcard' ? 'Kartvizit QR oluşturuldu!' : 'QR oluşturuldu!') . " Kısa adres: {$link['slug']}");
            if ($w = LinkService::designWarnings($link)) flash('warning', 'Okunabilirlik: ' . implode(' ', $w));
            if (empty($link['tags'])) {
                flash('info', 'İpucu: Bu QR’a etiket (ülke, etkinlik, materyal…) eklerseniz raporlarda gruplayabilirsiniz. Düzenle sayfasından ekleyebilirsiniz.');
            }
            redirect(BASE_URL . '/admin/qr-view?slug=' . urlencode($link['slug']));
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
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

<?php if ($vcardReady): ?>
<div class="type-switch mb-4" role="tablist" aria-label="QR türü">
  <a role="tab" aria-selected="<?= $qrType === 'link' ? 'true' : 'false' ?>" class="<?= $qrType === 'link' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/create">
    <span class="type-ic"><?= icon('link') ?></span><span><strong>Link</strong><small>QR bir web sayfasına yönlendirir</small></span>
  </a>
  <a role="tab" aria-selected="<?= $qrType === 'vcard' ? 'true' : 'false' ?>" class="<?= $qrType === 'vcard' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/create?type=vcard">
    <span class="type-ic"><?= icon('contact') ?></span><span><strong>Kartvizit</strong><small>Kişi sayfası ve "Rehbere ekle"</small></span>
  </a>
</div>
<?php endif; ?>

<form method="POST" action="" id="create-form" enctype="multipart/form-data">
  <?= Auth::csrfField() ?>
  <input type="hidden" name="qr_type" value="<?= e($qrType) ?>">
  <?php include __DIR__ . '/views/_link_form.php'; ?>
</form>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
