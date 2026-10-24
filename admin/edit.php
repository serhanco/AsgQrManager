<?php
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();

$pdo = getPdo();
$id  = (int)($_GET['id'] ?? 0);
$link = LinkService::find($pdo, $id);

if (!$link) {
    flash('danger', 'Link bulunamadı.');
    redirect(BASE_URL . '/admin/links');
}

$logos       = listLogos();
$logoSupport = QrRenderer::logoSupport();
$errors      = [];

/** DB satırı → form değerleri */
function linkToForm(array $l): array {
    $local = fn($v) => $v ? str_replace(' ', 'T', substr($v, 0, 16)) : '';
    $custom = ($l['fg_color'] ?? '') !== '' || ($l['bg_color'] ?? '') !== '' || ($l['shape'] ?? '') !== '';
    return [
        'target_url' => $l['target_url'], 'title' => $l['title'] ?? '', 'category' => $l['category'] ?? '',
        'tags' => $l['tags'] ?? '', 'notes' => $l['notes'] ?? '', 'is_active' => $l['is_active'],
        'starts_at' => $local($l['starts_at'] ?? null), 'ends_at' => $local($l['ends_at'] ?? null),
        'after_end_url' => $l['after_end_url'] ?? '', 'fallback_url' => $l['fallback_url'] ?? '',
        'utm_enabled' => $l['utm_enabled'] ?? 0, 'utm_campaign' => $l['utm_campaign'] ?? '',
        'ref_type' => $l['ref_type'] ?? '', 'ref_id' => $l['ref_id'] ?? '', 'ref_variant' => $l['ref_variant'] ?? '',
        'style_mode' => $custom ? 'custom' : 'brand',
        'fg_color' => $l['fg_color'] ?? '', 'bg_color' => $l['bg_color'] ?? '', 'shape' => $l['shape'] ?? '',
        'logo_file' => $l['logo'] ?? '',
    ];
}
$f = linkToForm($link);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();

    $input = LinkService::fromForm($_POST, true);
    [$clean, $errors] = LinkService::normalize($input, $link);

    if (!$errors) {
        try {
            $changes = LinkService::update($pdo, $link, $clean, Auth::actor());
            if (array_key_exists('is_active', $changes)) {
                // Aktif/pasif değişimi geçmişte ayrıca görünsün
                LinkEvents::log($pdo, (int)$id, $link['slug'], Auth::actor(), $clean['is_active'] ? 'activate' : 'deactivate');
            }
            flash('success', $changes ? 'Link başarıyla güncellendi.' : 'Değişiklik yok.');
            redirect(BASE_URL . '/admin/edit?id=' . $id);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
    $f = $_POST;
    $f['logo_file'] = !empty($_POST['use_logo']) ? ($_POST['logo_file'] ?? '') : '';
    $f['is_active'] = !empty($_POST['is_active']) ? 1 : 0;
}

$state = Redirector::state($link)['state'];
$stateLabels = ['live' => ['● Aktif', 'badge-success'], 'inactive' => ['○ Pasif', 'badge-danger'],
                'scheduled' => ['⏳ Başlamadı', 'badge-muted'], 'expired' => ['⌛ Süresi doldu', 'badge-muted'],
                'archived' => ['🗄 Arşivde', 'badge-muted']];

$events = $pdo->prepare('SELECT * FROM link_events WHERE link_id = ? ORDER BY id DESC LIMIT 15');
$events->execute([$id]);
$events = $events->fetchAll();

$mode        = 'edit';
$previewSlug = $link['slug'];
$formActions = '<div class="btn-group mt-4"><button class="btn btn-primary" type="submit">💾 Kaydet</button>'
             . '<a href="' . e(BASE_URL) . '/admin/links" class="btn btn-ghost">İptal</a></div>';

$pageTitle  = 'Link Düzenle';
$activePage = 'links';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title">✏️ Link Düzenle</h1>
  <div class="btn-group">
    <a href="<?= BASE_URL ?>/admin/qr-view?slug=<?= urlencode($link['slug']) ?>" class="btn btn-ghost">📱 QR / İndir / Paylaş</a>
    <a href="<?= BASE_URL ?>/admin/report?id=<?= $id ?>" class="btn btn-ghost">📊 Rapor</a>
    <a href="<?= BASE_URL ?>/admin/links" class="btn btn-ghost">← Geri</a>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="alert alert-info">
  🔒 QR kodu sabit kalır. Kısa ad: <strong class="font-mono"><?= e($link['slug']) ?></strong>
  &nbsp;→&nbsp; <span class="font-mono text-xs"><?= e(BASE_URL . '/r/' . $link['slug']) ?></span>
  <button class="copy-btn" data-copy="<?= e(BASE_URL . '/r/' . $link['slug']) ?>" style="vertical-align:middle">📋</button>
  &nbsp; <span class="badge <?= $stateLabels[$state][1] ?>"><?= $stateLabels[$state][0] ?></span>
  &nbsp; <span class="text-muted text-sm"><?= formatCount((int)$link['scan_count']) ?> okutma</span>
</div>

<form method="POST" action="">
  <?= Auth::csrfField() ?>
  <?php include __DIR__ . '/views/_link_form.php'; ?>
</form>

<!-- Arşivle / Geri al -->
<div class="card mt-4">
  <div class="card-title mb-2">🗄 Arşiv</div>
  <?php if (!empty($link['archived_at'])): ?>
    <p class="text-sm text-muted mb-3">Bu QR <?= e(substr($link['archived_at'], 0, 16)) ?> tarihinde arşivlendi. Okutulduğunda yedek hedefe gider (yoksa 404). Kayıt ve okutma geçmişi saklanıyor.</p>
    <form method="POST" action="<?= BASE_URL ?>/admin/restore" style="display:inline">
      <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn btn-ghost" type="submit">♻️ Arşivden çıkar</button>
    </form>
    <?php if ((int)$link['scan_count'] === 0): ?>
      <form method="POST" action="<?= BASE_URL ?>/admin/delete" style="display:inline"
            data-confirm="Bu kayıt KALICI olarak silinecek (hiç okutulmamış). Emin misiniz?">
        <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="purge" value="1">
        <button class="btn btn-ghost" type="submit" style="color:var(--danger)">🗑 Kalıcı sil</button>
      </form>
    <?php endif; ?>
  <?php else: ?>
    <p class="text-sm text-muted mb-3">QR'ı silmek yerine arşivleyin: basılı materyal sahada kalsa bile geçmiş ve ayarlar korunur, istediğiniz zaman geri açabilirsiniz.</p>
    <form method="POST" action="<?= BASE_URL ?>/admin/delete" data-confirm="Bu QR arşivlenecek ve okutulduğunda hedefe gitmeyecek. Devam edilsin mi?">
      <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn btn-ghost" type="submit">🗄 Arşivle</button>
    </form>
  <?php endif; ?>
</div>

<!-- Değişiklik geçmişi -->
<div class="card mt-4">
  <div class="card-title mb-2">🕘 Değişiklik Geçmişi</div>
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
