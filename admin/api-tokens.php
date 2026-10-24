<?php
/**
 * api-tokens.php — API anahtarlarını oluştur / iptal et.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();

$pdo = getPdo();
$newToken = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $errors[] = 'Anahtar için bir ad girin (örn. "ACIBADEM.WORLD portalı").';
        } else {
            $r = ApiAuth::create($pdo, $name, $_POST['scopes'] ?? 'read,write', Auth::actor()['name']);
            $newToken = $r['token'];
            LinkEvents::log($pdo, null, null, Auth::actor(), 'settings', ['api_anahtarı' => [null, "oluşturuldu: {$name}"]]);
        }
    } elseif ($action === 'revoke') {
        $pdo->prepare('UPDATE api_tokens SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL')->execute([(int)($_POST['id'] ?? 0)]);
        LinkEvents::log($pdo, null, null, Auth::actor(), 'settings', ['api_anahtarı' => ['etkin', 'iptal edildi #' . (int)$_POST['id']]]);
        flash('success', 'Anahtar iptal edildi.');
        redirect(BASE_URL . '/admin/api-tokens');
    }
}

$tokens = $pdo->query('SELECT * FROM api_tokens ORDER BY id DESC')->fetchAll();

$pageTitle  = 'API Anahtarları';
$activePage = 'api-tokens';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title">🔌 API Anahtarları</h1>
  <a class="btn btn-ghost" href="<?= BASE_URL ?>/admin/api-docs">📖 Dokümantasyon</a>
</div>

<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

<?php if ($newToken): ?>
<div class="alert alert-warning">
  <strong>Yeni anahtar oluşturuldu. Şimdi kopyalayın — bir daha gösterilmeyecek.</strong>
  <div class="share-box mt-2">
    <input class="form-control font-mono" type="text" readonly value="<?= e($newToken) ?>" onclick="this.select()">
    <button class="copy-btn" data-copy="<?= e($newToken) ?>" title="Kopyala">📋</button>
  </div>
  <p class="text-xs mt-2">Anahtarı yalnızca sunucudan sunucuya (backend) kullanın; tarayıcı kodunda veya herkese açık dosyalarda paylaşmayın.</p>
</div>
<?php endif; ?>

<form method="POST" class="card mb-4">
  <?= Auth::csrfField() ?><input type="hidden" name="action" value="create">
  <div class="card-title mb-3">➕ Yeni anahtar</div>
  <div class="form-row">
    <div class="form-group"><label class="form-label">Ad</label>
      <input class="form-control" type="text" name="name" maxlength="100" placeholder="ACIBADEM.WORLD portalı" required></div>
    <div class="form-group"><label class="form-label">Yetki</label>
      <select class="form-control" name="scopes">
        <option value="read,write">Okuma + yazma (QR oluştur / güncelle)</option>
        <option value="read">Yalnızca okuma (liste, istatistik, QR indirme)</option>
      </select></div>
  </div>
  <button class="btn btn-primary" type="submit">Anahtar oluştur</button>
</form>

<div class="card" style="padding:0">
  <div class="table-wrap"><table>
    <thead><tr><th>Ad</th><th>Önek</th><th>Yetki</th><th>Oluşturan</th><th>Son kullanım</th><th>Durum</th><th></th></tr></thead>
    <tbody>
    <?php if (!$tokens): ?><tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">Henüz anahtar yok.</td></tr><?php endif; ?>
    <?php foreach ($tokens as $t): ?>
      <tr>
        <td class="fw-bold"><?= e($t['name']) ?></td>
        <td class="font-mono text-xs"><?= e($t['token_prefix']) ?>…</td>
        <td class="text-sm"><?= $t['scopes'] === 'read' ? 'Okuma' : 'Okuma + yazma' ?></td>
        <td class="text-sm"><?= e($t['created_by'] ?: '—') ?><br><span class="text-xs text-muted"><?= e(substr($t['created_at'], 0, 10)) ?></span></td>
        <td class="text-sm text-muted"><?= $t['last_used_at'] ? e(substr($t['last_used_at'], 0, 16)) : 'hiç' ?></td>
        <td><?= $t['revoked_at'] ? '<span class="badge badge-muted">İptal</span>' : '<span class="badge badge-success">Etkin</span>' ?></td>
        <td><?php if (!$t['revoked_at']): ?>
          <form method="POST" data-confirm="Bu anahtarı kullanan sistemler erişimini kaybedecek. İptal edilsin mi?">
            <?= Auth::csrfField() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= $t['id'] ?>">
            <button class="btn btn-ghost btn-sm" type="submit" style="color:var(--danger)">İptal et</button>
          </form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
