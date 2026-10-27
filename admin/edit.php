<?php
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();

$pdo = getPdo();
$id  = (int)($_GET['id'] ?? 0);
$link = Auth::requireLinkAccess(LinkService::find($pdo, $id), true);

$logos       = listLogos();
$logoSupport = QrRenderer::logoSupport();
$errors      = [];

/** DB satırı → form değerleri */
function linkToForm(array $l): array {
    $local = fn($v) => $v ? str_replace(' ', 'T', substr($v, 0, 16)) : '';
    $custom = ($l['fg_color'] ?? '') !== '' || ($l['bg_color'] ?? '') !== '' || ($l['shape'] ?? '') !== '' || ($l['design'] ?? '') !== '';
    $dx = QrDesign::decode($l['design'] ?? null);
    return [
        'target_url' => $l['target_url'], 'title' => $l['title'] ?? '', 'category' => $l['category'] ?? '',
        'tags' => $l['tags'] ?? '', 'notes' => $l['notes'] ?? '', 'is_active' => $l['is_active'],
        'starts_at' => $local($l['starts_at'] ?? null), 'ends_at' => $local($l['ends_at'] ?? null),
        'after_end_url' => $l['after_end_url'] ?? '', 'fallback_url' => $l['fallback_url'] ?? '',
        'utm_enabled' => $l['utm_enabled'] ?? 0, 'utm_campaign' => $l['utm_campaign'] ?? '',
        'style_mode' => !empty($l['template_id']) ? 'template' : ($custom ? 'custom' : 'brand'),
        'template_id' => $l['template_id'] ?? '', 'tpl_frame_text' => $l['frame_text'] ?? '',
        'fg_color' => $l['fg_color'] ?? '', 'bg_color' => $l['bg_color'] ?? '', 'shape' => $l['shape'] ?? '',
        'eye' => $dx['eye'], 'eye_color_on' => $dx['eye_color'] ? '1' : '', 'eye_color' => $dx['eye_color'] ?? '',
        'gradient' => $dx['gradient'], 'fg2' => $dx['fg2'] ?? '',
        'frame' => $dx['frame'], 'frame_color' => $dx['frame_color'] ?? '', 'frame_text' => $dx['frame_text'] ?? '',
        'logo_file' => $l['logo'] ?? '',
    ];
}
$f = linkToForm($link);
$qrType = VCard::isVcard($link) ? 'vcard' : 'link';
$vcardRow = $qrType === 'vcard' ? VCard::find($pdo, (int)$link['id']) : null;
$vcardHasPhoto = !empty($vcardRow['has_photo']);
if ($vcardRow) $f['vc'] = array_intersect_key($vcardRow, VCard::FIELDS);
$canToggle  = Access::canToggleLink($link);
$canRequest = Access::canRequestToggle($link);
$pendingReq = LinkRequests::pendingFor($pdo, (int)$link['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();

    $input = LinkService::fromForm($_POST, true);
    // Aktif/pasif yalnızca yetkili kullanıcı formdan değiştirir; editör talep kartını kullanır
    if (!$canToggle) unset($input['is_active']);
    $vc = $photo = null; $vErrors = [];
    if ($qrType === 'vcard') {
        [$vc, $vErrors] = VCard::normalize(VCard::fromPost($_POST));
        [$photo, $pErr] = VCard::photoFromUpload($_FILES['vc_photo'] ?? null);
        if ($pErr) $vErrors[] = $pErr;
        if (!$photo && !empty($_POST['vc_photo_remove'])) $photo = false;
        $input['target_url']  = VCard::fallbackTarget($vc);
        $input['utm_enabled'] = 0;
        if (trim($input['title']) === '') $input['title'] = VCard::fullName($vc);
    }
    [$clean, $errors] = LinkService::normalize($input, $link);
    $errors = array_merge($vErrors, $errors);

    if (!$errors) {
        $wasActive = (int)$link['is_active'];
        $changes   = LinkService::update($pdo, $link, $clean, Auth::actor());
        if ($qrType === 'vcard') {
            $vcDiff = VCard::diff($vcardRow, $vc);
            if ($photo !== null) $vcDiff['Fotoğraf'] = [$vcardHasPhoto ? 'var' : null, $photo ? 'yeni' : null];
            VCard::save($pdo, (int)$id, $vc, $photo);
            if ($vcDiff) {
                LinkEvents::log($pdo, (int)$id, $link['slug'], Auth::actor(), 'vcard', $vcDiff);
                $changes += $vcDiff;
            }
        }
        if (array_key_exists('is_active', $changes)) {
            // Aktif/pasif değişimi geçmişte ayrıca görünsün
            LinkEvents::log($pdo, (int)$id, $link['slug'], Auth::actor(), $clean['is_active'] ? 'activate' : 'deactivate');
        }
        flash('success', $changes ? 'QR başarıyla güncellendi.' : 'Değişiklik yok.');
        if ($w = LinkService::designWarnings(LinkService::find($pdo, (int)$id) ?? $link)) flash('warning', 'Okunabilirlik: ' . implode(' ', $w));
        redirect(BASE_URL . '/admin/edit?id=' . $id);
    }
    $f = $_POST;
    $f['logo_file'] = !empty($_POST['use_logo']) ? ($_POST['logo_file'] ?? '') : '';
    $f['is_active'] = $canToggle ? (!empty($_POST['is_active']) ? 1 : 0) : (int)$link['is_active'];
}

$state = Redirector::state($link)['state'];
$stateLabels = ['live' => ['● Aktif', 'badge-success'], 'inactive' => ['○ Pasif', 'badge-danger'],
                'scheduled' => ['◷ Başlamadı', 'badge-muted'], 'expired' => ['◌ Süresi doldu', 'badge-muted'],
                'archived' => ['▪ Arşivde', 'badge-muted']];

$events = $pdo->prepare('SELECT * FROM link_events WHERE link_id = ? ORDER BY id DESC LIMIT 15');
$events->execute([$id]);
$events = $events->fetchAll();

$mode        = 'edit';
$previewSlug = $link['slug'];
$formActions = '<div class="btn-group mt-4"><button class="btn btn-primary" type="submit">' . icon('save') . ' Kaydet</button>'
             . '<a href="' . e(BASE_URL) . '/admin/links" class="btn btn-ghost">İptal</a></div>';

$pageTitle  = $qrType === 'vcard' ? 'Kartvizit Düzenle' : 'QR Düzenle';
$activePage = 'links';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title"><?= icon($qrType === 'vcard' ? 'contact' : 'pencil') ?> <?= e($pageTitle) ?></h1>
  <div class="btn-group">
    <a href="<?= BASE_URL ?>/admin/qr-view?slug=<?= urlencode($link['slug']) ?>" class="btn btn-ghost"><?= icon('qr') ?> QR / İndir / Paylaş</a>
    <a href="<?= BASE_URL ?>/admin/report?id=<?= $id ?>" class="btn btn-ghost"><?= icon('chart') ?> Rapor</a>
    <a href="<?= BASE_URL ?>/admin/links" class="btn btn-ghost"><?= icon('arrow-left') ?> Geri</a>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="alert alert-info">
  <?= icon('lock') ?> QR kodu sabit kalır. Kısa ad: <strong class="font-mono"><?= e($link['slug']) ?></strong>
  &nbsp;→&nbsp; <span class="font-mono text-xs"><?= e(BASE_URL . '/r/' . $link['slug']) ?></span>
  <button class="copy-btn" data-copy="<?= e(BASE_URL . '/r/' . $link['slug']) ?>" style="vertical-align:middle"><?= icon('copy') ?></button>
  &nbsp; <span class="badge <?= $stateLabels[$state][1] ?>"><?= $stateLabels[$state][0] ?></span>
  &nbsp; <span class="text-muted text-sm"><?= formatCount((int)$link['scan_count']) ?> okutma</span>
</div>

<form method="POST" action="" enctype="multipart/form-data">
  <?= Auth::csrfField() ?>
  <?php include __DIR__ . '/views/_link_form.php'; ?>
</form>

<?php if ($canRequest || $pendingReq): ?>
<!-- Editör: aktif/pasif değişikliği yönetici onayıyla -->
<div class="card mt-4" id="durum-talebi">
  <div class="card-title mb-2"><?= icon('power') ?> <?= (int)$link['is_active'] ? 'Pasife alma' : 'Yeniden açma' ?></div>
  <?php if ($pendingReq): ?>
    <p class="text-sm mb-3"><span class="badge badge-warning"><?= icon('clock') ?> Onay bekliyor</span>
      <?= e(LinkRequests::KINDS[$pendingReq['kind']] ?? $pendingReq['kind']) ?> talebi <?= e(substr($pendingReq['created_at'], 0, 16)) ?> tarihinde
      <?= e($pendingReq['requester'] ?: '—') ?> tarafından gönderildi. Yönetici onaylayana kadar QR olduğu gibi çalışır.</p>
    <?php if (Access::isAdmin()): ?>
      <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>/admin/requests"><?= icon('check-circle') ?> Taleplere git</a>
    <?php elseif ((int)($pendingReq['requested_by'] ?? 0) === Access::userId()): ?>
      <form method="POST" action="<?= BASE_URL ?>/admin/requests">
        <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= (int)$pendingReq['id'] ?>"><input type="hidden" name="action" value="cancel">
        <input type="hidden" name="back" value="<?= e(BASE_PATH . '/admin/edit?id=' . $id) ?>">
        <button class="btn btn-ghost btn-sm" type="submit"><?= icon('x') ?> Talebi geri çek</button>
      </form>
    <?php endif; ?>
  <?php else: ?>
    <p class="text-sm text-muted mb-3">
      <?= (int)$link['is_active']
          ? 'QR\'ı pasife almak yönetici onayı gerektirir. Onaylanınca okutan kişi yedek hedefe veya bilgi sayfasına yönlenir.'
          : 'QR\'ı yeniden açmak yönetici onayı gerektirir.' ?>
    </p>
    <form method="POST" action="<?= BASE_URL ?>/admin/toggle">
      <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="back" value="<?= e(BASE_PATH . '/admin/edit?id=' . $id) ?>">
      <div class="form-group">
        <label class="form-label" for="req-note">Gerekçe <span class="hint">Yönetici görür (isteğe bağlı)</span></label>
        <input class="form-control" id="req-note" type="text" name="note" maxlength="500" placeholder="ör. Etkinlik bitti, afişler toplandı">
      </div>
      <button class="btn btn-ghost" type="submit"><?= icon('power') ?> <?= (int)$link['is_active'] ? 'Pasife alma talebi gönder' : 'Yeniden açma talebi gönder' ?></button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Arşivle / Geri al -->
<?php if (Access::can('links.archive')): ?>
<div class="card mt-4">
  <div class="card-title mb-2"><?= icon('archive') ?> Arşiv</div>
  <?php if (!empty($link['archived_at'])): ?>
    <p class="text-sm text-muted mb-3">Bu QR <?= e(substr($link['archived_at'], 0, 16)) ?> tarihinde arşivlendi. Okutulduğunda yedek hedefe gider (yoksa 404). Kayıt ve okutma geçmişi saklanıyor.</p>
    <form method="POST" action="<?= BASE_URL ?>/admin/restore" style="display:inline">
      <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn btn-ghost" type="submit"><?= icon('restore') ?> Arşivden çıkar</button>
    </form>
    <?php if ((int)$link['scan_count'] === 0): ?>
      <form method="POST" action="<?= BASE_URL ?>/admin/delete" style="display:inline"
            data-confirm="Bu kayıt KALICI olarak silinecek (hiç okutulmamış). Emin misiniz?">
        <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="purge" value="1">
        <button class="btn btn-ghost" type="submit" style="color:var(--danger)"><?= icon('trash') ?> Kalıcı sil</button>
      </form>
    <?php endif; ?>
  <?php else: ?>
    <p class="text-sm text-muted mb-3">QR'ı silmek yerine arşivleyin: basılı materyal sahada kalsa bile geçmiş ve ayarlar korunur, istediğiniz zaman geri açabilirsiniz.</p>
    <form method="POST" action="<?= BASE_URL ?>/admin/delete" data-confirm="Bu QR arşivlenecek ve okutulduğunda hedefe gitmeyecek. Devam edilsin mi?">
      <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn btn-ghost" type="submit"><?= icon('archive') ?> Arşivle</button>
    </form>
  <?php endif; ?>
</div>

<?php endif; ?>

<!-- Değişiklik geçmişi -->
<div class="card mt-4">
  <div class="card-title mb-2"><?= icon('history') ?> Değişiklik Geçmişi</div>
  <?php if (!$events): ?>
    <p class="text-sm text-muted">Bu link için kayıtlı değişiklik yok (geçmiş, güncellemeden sonra tutulmaya başlar).</p>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Zaman</th><th>Kullanıcı</th><th>İşlem</th><th>Ayrıntı</th></tr></thead>
      <tbody>
      <?php foreach ($events as $ev): include __DIR__ . '/views/_event_row.php'; endforeach; ?>
      </tbody>
    </table></div>
    <p class="text-xs text-muted mt-2"><a href="<?= BASE_URL ?>/admin/history?slug=<?= urlencode($link['slug']) ?>">Tüm geçmişi gör →</a></p>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
