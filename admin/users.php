<?php
/**
 * users.php — kullanıcılar: ekle, rol ve kapsam ata, devre dışı bırak, şifre sıfırla (yalnızca Yönetici).
 * Kullanıcılar silinmez; devre dışı bırakılan hesap hemen oturumdan düşer.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();
Auth::requirePerm('admin');

$pdo     = getPdo();
$actor   = Auth::actor();
$errors  = [];
$tempPw  = null; // yeni oluşturulan / sıfırlanan şifre (bir kez gösterilir)
$editId  = (int)($_GET['id'] ?? 0);
$editing = $editId ? UserService::find($pdo, $editId) : null;
if ($editId && !$editing) {
    flash('danger', 'Kullanıcı bulunamadı.');
    redirect(BASE_URL . '/admin/users');
}
$f = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $pw = UserService::tempPassword();
        [$newId, $errors] = UserService::create($pdo, ['password' => $pw] + $_POST, $actor);
        if (!$errors) {
            $tempPw = ['user' => trim($_POST['username']), 'pw' => $pw];
            $f = [];
        }
    } elseif ($action === 'update' && $editing) {
        $errors = UserService::update($pdo, $editing, $_POST, $actor);
        if (!$errors) {
            flash('success', "'{$editing['username']}' güncellendi.");
            redirect(BASE_URL . '/admin/users');
        }
    } elseif ($action === 'reset' && $editing) {
        $pw = UserService::tempPassword();
        if ($err = UserService::resetPassword($pdo, $editing, $pw, $actor)) $errors[] = $err;
        else $tempPw = ['user' => $editing['username'], 'pw' => $pw];
    }
}

$users      = UserService::all($pdo);
$tagOptions = LinkService::allTags($pdo);

/** Form alanlarının değeri: önce gönderilen form, sonra düzenlenen kullanıcı */
$val = function (string $k, $default = '') use ($f, $editing) {
    if (array_key_exists($k, $f)) return $f[$k];
    return $editing[$k] ?? $default;
};
$scope = $editing && !isset($f['action']) ? $editing['scope'] : UserService::scopeFromInput($f);

$pageTitle  = 'Kullanıcılar';
$activePage = 'users';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title"><?= icon('users') ?> Kullanıcılar</h1>
  <?php if ($editing): ?><a class="btn btn-ghost" href="<?= BASE_URL ?>/admin/users"><?= icon('arrow-left') ?> Tüm kullanıcılar</a><?php endif; ?>
</div>

<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

<?php if ($tempPw): ?>
<div class="alert alert-warning">
  <strong><?= e($tempPw['user']) ?> için geçici şifre. Şimdi kopyalayın, bir daha gösterilmeyecek.</strong>
  <div class="share-box mt-2">
    <input class="form-control font-mono" type="text" readonly value="<?= e($tempPw['pw']) ?>" onclick="this.select()">
    <button class="copy-btn" data-copy="<?= e($tempPw['pw']) ?>" title="Kopyala"><?= icon('copy') ?></button>
  </div>
  <p class="text-xs mt-2">Kullanıcı ilk girişte bu şifreyi kendi şifresiyle değiştirmek zorunda. Şifreyi e-postayla değil, ayrı bir kanaldan iletin.</p>
</div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-title mb-2">Roller</div>
  <div class="table-wrap"><table class="compact">
    <tbody>
    <?php foreach (Access::ROLES as $r => $label): ?>
      <tr><td class="fw-bold" style="white-space:nowrap"><?= e($label) ?></td><td class="text-sm"><?= e(Access::ROLE_HELP[$r]) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="text-xs text-muted mt-2">Kapsam, Editör ve Rapor izleyicinin hangi QR'ları göreceğini belirler: seçilen türler ve etiketler + kendi oluşturduğu QR'lar.</p>
</div>

<form method="POST" class="card mb-4" action="<?= BASE_URL ?>/admin/users<?= $editing ? '?id=' . (int)$editing['id'] : '' ?>">
  <?= Auth::csrfField() ?>
  <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
  <div class="card-title mb-3"><?= $editing ? icon('pencil') . ' ' . e($editing['username']) . ' düzenle' : icon('plus') . ' Yeni kullanıcı' ?></div>

  <div class="form-row">
    <div class="form-group">
      <label class="form-label" for="u-username">Kullanıcı adı</label>
      <?php if ($editing): ?>
        <input class="form-control" id="u-username" type="text" value="<?= e($editing['username']) ?>" disabled>
      <?php else: ?>
        <input class="form-control" id="u-username" type="text" name="username" maxlength="64" required autocomplete="off"
               value="<?= e($val('username')) ?>" placeholder="ad.soyad">
      <?php endif; ?>
    </div>
    <div class="form-group">
      <label class="form-label" for="u-display">Ad Soyad</label>
      <input class="form-control" id="u-display" type="text" name="display_name" maxlength="100" value="<?= e($val('display_name')) ?>">
    </div>
    <div class="form-group">
      <label class="form-label" for="u-email">E-posta <span class="text-xs text-muted">(raporlar ve uyarılar için)</span></label>
      <input class="form-control" id="u-email" type="email" name="email" maxlength="190" value="<?= e($val('email')) ?>">
    </div>
  </div>

  <div class="form-row">
    <div class="form-group">
      <label class="form-label" for="u-role">Rol</label>
      <select class="form-control" id="u-role" name="role">
        <?php foreach (Access::ROLES as $r => $label): ?>
          <option value="<?= e($r) ?>" <?= $val('role', 'editor') === $r ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($editing): ?>
    <div class="form-group" style="align-self:end">
      <input type="hidden" name="is_active" value="0">
      <label class="form-check">
        <input type="checkbox" name="is_active" value="1" <?= (int)$val('is_active', 1) ? 'checked' : '' ?>>
        <span>Hesap etkin</span>
      </label>
    </div>
    <?php endif; ?>
  </div>

  <fieldset class="form-group" id="scope-box" style="border:1px solid var(--border);border-radius:.5rem;padding:.75rem 1rem">
    <legend class="form-label" style="padding:0 .25rem">Kapsam <?= tip('Yönetici her zaman tüm QR\'ları görür. Editör ve Rapor izleyici yalnızca burada seçilenleri ve kendi oluşturduklarını görür.') ?></legend>
    <label class="form-check mb-2">
      <input type="checkbox" name="scope_all" value="1" <?= !empty($scope['all']) ? 'checked' : '' ?>>
      <span>Tüm QR'lar</span>
    </label>
    <div class="text-sm mb-2">Türler:
      <?php foreach (LinkService::CATEGORIES as $c => $label): ?>
        <label class="form-check" style="display:inline-flex;margin-right:1rem">
          <input type="checkbox" name="scope_categories[]" value="<?= e($c) ?>" <?= in_array($c, $scope['categories'], true) ? 'checked' : '' ?>>
          <span><?= e($label) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <label class="form-label" for="u-tags">Etiketler <span class="text-xs text-muted">(virgülle ayırın; ör. Almanya, Kongre-2027)</span></label>
    <input class="form-control" id="u-tags" type="text" name="scope_tags" list="scope-tag-list" maxlength="255"
           value="<?= e(implode(', ', $scope['tags'])) ?>">
    <datalist id="scope-tag-list"><?php foreach ($tagOptions as $t): ?><option value="<?= e($t['tag']) ?>"><?php endforeach; ?></datalist>
  </fieldset>

  <div class="btn-group mt-2">
    <button class="btn btn-primary" type="submit"><?= $editing ? icon('save') . ' Kaydet' : 'Kullanıcı oluştur (geçici şifre üretilir)' ?></button>
    <?php if ($editing): ?><a class="btn btn-ghost" href="<?= BASE_URL ?>/admin/users">İptal</a><?php endif; ?>
  </div>
</form>

<?php if ($editing): ?>
<form method="POST" class="card mb-4" action="<?= BASE_URL ?>/admin/users?id=<?= (int)$editing['id'] ?>"
      data-confirm="<?= e($editing['username']) ?> için yeni geçici şifre üretilecek; eski şifresi çalışmayacak. Devam edilsin mi?">
  <?= Auth::csrfField() ?><input type="hidden" name="action" value="reset">
  <div class="card-title mb-2"><?= icon('key') ?> Şifre sıfırla</div>
  <p class="text-sm text-muted mb-3">Yeni bir geçici şifre üretilir; kullanıcı ilk girişte değiştirmek zorunda kalır.</p>
  <button class="btn btn-ghost" type="submit">Geçici şifre üret</button>
</form>
<?php endif; ?>

<div class="card" style="padding:0">
  <div class="table-wrap"><table>
    <thead><tr><th>Kullanıcı</th><th>Rol</th><th>Kapsam</th><th>Son giriş</th><th>Durum</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $role = $u['role'] ?? 'admin'; ?>
      <tr>
        <td><span class="fw-bold"><?= e($u['username']) ?></span>
          <?php if (!empty($u['display_name'])): ?><br><span class="text-xs text-muted"><?= e($u['display_name']) ?></span><?php endif; ?></td>
        <td class="text-sm"><?= e(Access::ROLES[$role] ?? $role) ?></td>
        <td class="text-sm"><?= e(Access::describeScope($role, $u['scope'])) ?></td>
        <td class="text-sm text-muted"><?= !empty($u['last_login_at']) ? e(substr($u['last_login_at'], 0, 16)) : 'hiç' ?></td>
        <td><?php if (!(int)($u['is_active'] ?? 1)): ?><span class="badge badge-muted">Devre dışı</span>
            <?php elseif (!empty($u['must_change_password'])): ?><span class="badge badge-muted" title="İlk girişte şifresini değiştirecek">Şifre bekliyor</span>
            <?php else: ?><span class="badge badge-success">Etkin</span><?php endif; ?></td>
        <td><a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/admin/users?id=<?= (int)$u['id'] ?>" title="Düzenle"><?= icon('pencil', '', 'Düzenle') ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<script>
(function () {
  // Yönetici rolünde kapsam anlamsız: kutuyu soluklaştır
  const role = document.getElementById('u-role'), box = document.getElementById('scope-box');
  const sync = () => { box.style.opacity = role.value === 'admin' ? .45 : 1; box.disabled = role.value === 'admin'; };
  role.addEventListener('change', sync); sync();
})();
</script>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
