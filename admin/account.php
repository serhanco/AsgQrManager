<?php
/**
 * account.php — Hesabım: rol ve kapsamı görme, şifre değiştirme (tüm roller).
 * Geçici şifreyle giren kullanıcı buraya yönlendirilir ve şifresini değiştirmeden başka sayfaya geçemez.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();

$pdo  = getPdo();
$user = UserService::find($pdo, (int)$_SESSION['user_id']);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $error = UserService::changeOwnPassword($pdo, (int)$user['id'], (string)($_POST['current'] ?? ''),
                                            (string)($_POST['new'] ?? ''), (string)($_POST['repeat'] ?? ''));
    if (!$error) {
        session_regenerate_id(true);
        LinkEvents::log($pdo, null, null, Auth::actor(), 'settings', ['kullanıcı' => [null, 'kendi şifresini değiştirdi']]);
        flash('success', 'Şifreniz değiştirildi.');
        redirect(BASE_URL . '/admin/dashboard');
    }
}

$role = $user['role'] ?? 'admin';
$pageTitle  = 'Hesabım';
$activePage = 'account';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title"><?= icon('user') ?> Hesabım</h1>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card mb-4">
  <div class="table-wrap"><table class="compact"><tbody>
    <tr><td class="text-muted">Kullanıcı adı</td><td class="fw-bold"><?= e($user['username']) ?></td></tr>
    <?php if (!empty($user['display_name'])): ?><tr><td class="text-muted">Ad Soyad</td><td><?= e($user['display_name']) ?></td></tr><?php endif; ?>
    <tr><td class="text-muted">Rol</td><td><?= e(Access::ROLES[$role] ?? $role) ?> <span class="text-xs text-muted">— <?= e(Access::ROLE_HELP[$role] ?? '') ?></span></td></tr>
    <tr><td class="text-muted">Kapsam</td><td><?= e(Access::describeScope($role, $user['scope'])) ?></td></tr>
  </tbody></table></div>
</div>

<form method="POST" class="card" style="max-width:520px">
  <?= Auth::csrfField() ?>
  <div class="card-title mb-3"><?= icon('key') ?> Şifre değiştir</div>
  <div class="form-group">
    <label class="form-label" for="pw-current">Mevcut şifre</label>
    <input class="form-control" id="pw-current" type="password" name="current" required autocomplete="current-password">
  </div>
  <div class="form-group">
    <label class="form-label" for="pw-new">Yeni şifre <span class="text-xs text-muted">(en az <?= UserService::MIN_PASSWORD ?> karakter, harf ve rakam)</span></label>
    <input class="form-control" id="pw-new" type="password" name="new" required minlength="<?= UserService::MIN_PASSWORD ?>" autocomplete="new-password">
  </div>
  <div class="form-group">
    <label class="form-label" for="pw-repeat">Yeni şifre (tekrar)</label>
    <input class="form-control" id="pw-repeat" type="password" name="repeat" required minlength="<?= UserService::MIN_PASSWORD ?>" autocomplete="new-password">
  </div>
  <button class="btn btn-primary" type="submit">Şifreyi değiştir</button>
</form>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
