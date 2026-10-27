<?php
/**
 * templates.php — kurumsal QR şablonları: ekle, düzenle, kopyala, arşivle (yalnızca Yönetici).
 * Şablon silinmez; arşivlenen şablon yeni QR'larda seçilemez, ona bağlı QR'lar aynı görünümle kalır.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();
Auth::requirePerm('admin');

$pdo   = getPdo();
$actor = Auth::actor();
$errors = $warnings = [];

if (!QrTemplates::available($pdo)) {
    flash('warning', 'Şablonlar için veritabanı güncellemesi bekleniyor. Çıkış yapıp yeniden giriş yapın.');
    redirect(BASE_URL . '/admin/dashboard');
}

$editId  = (int)($_GET['id'] ?? 0);
$isNew   = isset($_GET['new']);
$editing = $editId ? QrTemplates::find($pdo, $editId) : null;
if ($editId && !$editing) {
    flash('danger', 'Şablon bulunamadı.');
    redirect(BASE_URL . '/admin/templates');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $action = $_POST['action'] ?? '';
    $target = QrTemplates::find($pdo, (int)($_POST['id'] ?? 0));

    if ($action === 'save') {
        $in = $_POST;
        $in['fg'] = $_POST['fg_color'] ?? '';
        $in['bg'] = $_POST['bg_color'] ?? '';
        if (empty($_POST['eye_color_on'])) $in['eye_color'] = '';
        if (($_POST['gradient'] ?? 'none') === 'none') $in['fg2'] = '';
        [$clean, $errors, $warnings] = QrTemplates::normalize($in);
        if (!$errors) {
            if ($editing) {
                QrTemplates::update($pdo, (int)$editing['id'], $clean);
                Logger::info("Şablon güncellendi: #{$editing['id']} {$clean['name']} ({$actor['name']})");
                $id = (int)$editing['id'];
            } else {
                $id = QrTemplates::create($pdo, $clean, $actor);
                Logger::info("Şablon oluşturuldu: #{$id} {$clean['name']} ({$actor['name']})");
            }
            flash('success', "'{$clean['name']}' kaydedildi.");
            if ($warnings) flash('warning', 'Okunabilirlik: ' . implode(' ', $warnings));
            redirect(BASE_URL . '/admin/templates' . ($warnings ? '?id=' . $id : ''));
        }
    } elseif ($target && $action === 'copy') {
        $id = QrTemplates::copy($pdo, $target, $actor);
        flash('success', "'{$target['name']}' kopyalandı. Kopyayı düzenleyebilirsiniz.");
        redirect(BASE_URL . '/admin/templates?id=' . $id);
    } elseif ($target && in_array($action, ['archive', 'restore'], true)) {
        QrTemplates::setArchived($pdo, (int)$target['id'], $action === 'archive');
        Logger::info('Şablon ' . ($action === 'archive' ? 'arşivlendi' : 'arşivden alındı') . ": #{$target['id']} ({$actor['name']})");
        flash('success', $action === 'archive'
            ? "'{$target['name']}' arşivlendi. Yeni QR'larda seçilemez; bağlı QR'lar aynı görünümle kalır."
            : "'{$target['name']}' yeniden kullanımda.");
        redirect(BASE_URL . '/admin/templates');
    }
}

$active   = QrTemplates::all($pdo, false);
$archived = QrTemplates::all($pdo, true);
$counts   = QrTemplates::usageCounts($pdo);
$showForm = $editing || $isNew || $errors;

// Form değerleri: önce gönderilen form, sonra düzenlenen şablon
$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? [
    'name' => $_POST['name'] ?? '', 'usage' => $_POST['usage'] ?? 'other', 'description' => $_POST['description'] ?? '',
    'print_mm' => $_POST['print_mm'] ?? '',
] : ($editing ?: ['name' => '', 'usage' => 'other', 'description' => '', 'print_mm' => '']);
$d = $_SERVER['REQUEST_METHOD'] === 'POST' ? [
    'fg' => $_POST['fg_color'] ?? '', 'bg' => $_POST['bg_color'] ?? '', 'shape' => $_POST['shape'] ?? '', 'eye' => $_POST['eye'] ?? '',
    'eye_color' => !empty($_POST['eye_color_on']) ? ($_POST['eye_color'] ?? '') : '', 'gradient' => $_POST['gradient'] ?? 'none', 'fg2' => $_POST['fg2'] ?? '',
] : ($editing['style'] ?? QrTemplates::styleOf(['fg' => '#1C2561']));

$preview = fn(int $id) => BASE_URL . '/admin/qr-preview?mode=template&tpl=' . $id . '&slug=sablon';

$pageTitle  = 'QR Şablonları';
$activePage = 'templates';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title"><?= icon('palette') ?> QR Şablonları</h1>
  <div class="btn-group">
    <?php if ($showForm): ?>
      <a class="btn btn-ghost" href="<?= BASE_URL ?>/admin/templates"><?= icon('arrow-left') ?> Tüm şablonlar</a>
    <?php else: ?>
      <a class="btn btn-primary" href="<?= BASE_URL ?>/admin/templates?new=1"><?= icon('plus') ?> Yeni şablon</a>
    <?php endif; ?>
  </div>
</div>

<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

<?php if ($showForm): ?>
<form method="POST" action="<?= BASE_URL ?>/admin/templates<?= $editing ? '?id=' . (int)$editing['id'] : '?new=1' ?>">
  <?= Auth::csrfField() ?>
  <input type="hidden" name="action" value="save">
  <div class="form-layout">
  <div class="form-main">
    <?php if ($editing && ($n = $counts[(int)$editing['id']] ?? 0) > 0): ?>
      <div class="alert alert-info"><?= icon('info') ?> Bu şablonu <?= $n ?> QR kullanıyor. Kaydettiğinizde bu QR'ların indirilen görseli yeni tasarımla üretilir; kısa adresleri ve basılmış materyaller etkilenmez.</div>
    <?php endif; ?>
    <div class="card">
      <div class="card-title mb-3"><?= $editing ? icon('pencil') . ' ' . e($editing['name']) . ' düzenle' : icon('plus') . ' Yeni şablon' ?></div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="t-name">Şablon adı</label>
          <input class="form-control" id="t-name" type="text" name="name" maxlength="80" required value="<?= e($src['name']) ?>" placeholder="Kongre Roll-up">
        </div>
        <div class="form-group">
          <label class="form-label" for="t-usage">Kullanım alanı</label>
          <select class="form-control" id="t-usage" name="usage">
            <?php foreach (QrTemplates::USAGES as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= $src['usage'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="t-mm">Önerilen baskı boyutu <span class="hint">mm</span></label>
          <input class="form-control" id="t-mm" type="number" name="print_mm" min="<?= QrTemplates::MIN_MM ?>" max="<?= QrTemplates::MAX_MM ?>"
                 value="<?= e((string)($src['print_mm'] ?? '')) ?>" placeholder="ör. 30">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label" for="t-desc">Kullanım notu <span class="hint">QR oluşturan kişiye gösterilir</span></label>
        <input class="form-control" id="t-desc" type="text" name="description" maxlength="300" value="<?= e((string)($src['description'] ?? '')) ?>"
               placeholder="ör. 3 cm basın; kesim payı için kenarda boşluk bırakın.">
      </div>
    </div>

    <div class="card mt-4">
      <div class="card-title mb-2"><?= icon('palette') ?> Tasarım</div>
      <?php include __DIR__ . '/views/_design_fields.php'; ?>
    </div>

    <div class="btn-group mt-4">
      <button class="btn btn-primary" type="submit"><?= icon('save') ?> Kaydet</button>
      <a href="<?= BASE_URL ?>/admin/templates" class="btn btn-ghost">İptal</a>
    </div>
  </div>

  <aside class="form-side">
    <div class="card sticky-card">
      <div class="card-title mb-2"><?= icon('qr') ?> Önizleme</div>
      <div class="qr-preview-box" style="min-height:240px">
        <img id="tpl-preview" alt="Şablon önizleme" style="width:100%;max-width:300px;height:auto">
      </div>
      <p class="text-xs text-muted mt-2">Logo, QR oluşturulurken ayrıca seçilir. Baskı öncesi gerçek telefonla test edin.</p>
    </div>
  </aside>
  </div>
</form>
<script>
(function () {
  const img = document.getElementById('tpl-preview');
  let t = null;
  function refresh() {
    clearTimeout(t);
    t = setTimeout(() => {
      const v = window.qrDesign.values();
      img.src = <?= json_encode(BASE_URL . '/admin/qr-preview?') ?> + new URLSearchParams(Object.assign({ mode: 'custom', slug: 'sablon' }, v)).toString();
    }, 150);
  }
  document.addEventListener('qr-design-change', refresh);
  refresh();
})();
</script>

<?php else: ?>

<p class="text-sm text-muted mb-3">Editörler QR oluştururken bu şablonlardan birini ya da marka varsayılanını seçer; özel renk ve biçim yalnızca yöneticiye açıktır.
  Şablonu düzenlemek ona bağlı QR'ların indirilen görselini günceller, kısa adresleri değiştirmez.</p>

<?php if (!$active): ?>
  <div class="card"><p class="text-muted">Kullanımda şablon yok. <a href="<?= BASE_URL ?>/admin/templates?new=1">Yeni şablon ekleyin</a>.</p></div>
<?php endif; ?>

<div class="tpl-grid">
<?php foreach ($active as $t): $n = $counts[$t['id']] ?? 0; ?>
  <div class="card tpl-card">
    <img src="<?= e($preview($t['id'])) ?>" alt="<?= e($t['name']) ?> önizleme" loading="lazy" class="tpl-thumb">
    <div class="tpl-body">
      <div class="fw-bold"><?= e($t['name']) ?></div>
      <div class="text-xs text-muted">
        <?= e(QrTemplates::USAGES[$t['usage']] ?? $t['usage']) ?>
        <?php if ($t['print_mm']): ?> · <?= (int)$t['print_mm'] ?> mm<?php endif; ?>
        · <?= $n ? $n . ' QR' : 'kullanılmıyor' ?>
      </div>
      <?php if ($t['description']): ?><p class="text-xs mt-2"><?= e($t['description']) ?></p><?php endif; ?>
    </div>
    <div class="btn-group tpl-actions">
      <a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/admin/templates?id=<?= $t['id'] ?>"><?= icon('pencil') ?> Düzenle</a>
      <form method="POST" style="display:inline"><?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= $t['id'] ?>">
        <button class="btn btn-ghost btn-sm" name="action" value="copy" type="submit"><?= icon('copy') ?> Kopyala</button></form>
      <form method="POST" style="display:inline" data-confirm="'<?= e($t['name']) ?>' arşivlensin mi? Yeni QR'larda seçilemez; bağlı <?= $n ?> QR aynı görünümle kalır.">
        <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= $t['id'] ?>">
        <button class="btn btn-ghost btn-sm" name="action" value="archive" type="submit"><?= icon('archive') ?> Arşivle</button></form>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php if ($archived): ?>
<details class="card mt-4">
  <summary class="card-title" style="cursor:pointer"><?= icon('archive') ?> Arşivdeki şablonlar (<?= count($archived) ?>)</summary>
  <div class="table-wrap mt-2"><table class="compact">
    <tbody>
    <?php foreach ($archived as $t): ?>
      <tr>
        <td><img src="<?= e($preview($t['id'])) ?>" alt="" width="40" height="40" loading="lazy"></td>
        <td><span class="fw-bold"><?= e($t['name']) ?></span><br><span class="text-xs text-muted"><?= e(QrTemplates::USAGES[$t['usage']] ?? $t['usage']) ?> · <?= ($counts[$t['id']] ?? 0) ?> QR</span></td>
        <td style="text-align:right">
          <form method="POST" style="display:inline"><?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= $t['id'] ?>">
            <button class="btn btn-ghost btn-sm" name="action" value="restore" type="submit"><?= icon('restore') ?> Kullanıma al</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</details>
<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
