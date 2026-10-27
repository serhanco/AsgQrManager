<?php
/**
 * requests.php — aktif/pasif talepleri.
 * Yönetici: bekleyen talepleri onaylar / reddeder. Editör: kendi taleplerini görür, bekleyeni geri çeker.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();
Auth::requirePerm('links.edit');

$pdo     = getPdo();
$isAdmin = Access::isAdmin();
$actor   = Auth::actor();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $req    = LinkRequests::find($pdo, (int)($_POST['id'] ?? 0));
    $action = $_POST['action'] ?? '';
    if (!$req) {
        flash('danger', 'Talep bulunamadı.');
    } elseif ($action === 'cancel') {
        if ((int)($req['requested_by'] ?? 0) !== Access::userId() && !$isAdmin) Auth::forbidden();
        if ($err = LinkRequests::cancel($pdo, $req, $actor)) flash('warning', $err);
        else flash('success', 'Talep geri çekildi.');
    } elseif ($action === 'approve' || $action === 'reject') {
        if (!$isAdmin) Auth::forbidden();
        $err = LinkRequests::decide($pdo, $req, $action === 'approve', $actor, (string)($_POST['note'] ?? ''));
        if ($err) flash('warning', $err);
        else flash('success', $action === 'approve' ? 'Talep onaylandı, QR durumu güncellendi.' : 'Talep reddedildi, QR olduğu gibi kaldı.');
    }
    $back = (string)($_POST['back'] ?? '');
    if ($back !== '' && !str_contains($back, "\n") && str_starts_with($back, BASE_PATH . '/admin/edit')) {
        redirect(BASE_URL . substr($back, strlen(BASE_PATH)));
    }
    redirect(BASE_URL . '/admin/requests');
}

$mine    = $isAdmin ? null : Access::userId();
$pending = LinkRequests::listing($pdo, 'pending', $mine);
$recent  = array_values(array_filter(LinkRequests::listing($pdo, null, $mine, 50), fn($r) => $r['status'] !== 'pending'));

$statusBadge = ['pending' => 'badge-warning', 'approved' => 'badge-success', 'rejected' => 'badge-danger', 'cancelled' => 'badge-muted'];

$pageTitle  = $isAdmin ? 'Onay Bekleyenler' : 'Taleplerim';
$activePage = 'requests';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= icon('check-circle') ?> <?= e($pageTitle) ?></h1>
    <p class="page-sub"><?= $isAdmin
        ? 'Editörlerin kendi QR\'ları için gönderdiği pasife alma ve yeniden açma talepleri.'
        : 'Kendi QR\'larınız için gönderdiğiniz pasife alma ve yeniden açma talepleri. Yeni talep, QR\'ın düzenleme sayfasından gönderilir.' ?></p>
  </div>
</div>

<div class="card mb-4" style="padding:0">
  <div class="card-header" style="padding:1.1rem 1.25rem .25rem"><span class="card-title"><?= icon('clock') ?> Bekleyen (<?= count($pending) ?>)</span></div>
  <?php if (!$pending): ?>
    <p class="text-sm text-muted" style="padding:.5rem 1.25rem 1.25rem">Onay bekleyen talep yok.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>QR</th><th>Talep</th><th>Gönderen</th><th>Gerekçe</th><th>Tarih</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pending as $r): ?>
      <tr>
        <td><a class="font-mono fw-bold" href="<?= BASE_URL ?>/admin/edit?id=<?= (int)$r['link_id'] ?>"><?= e($r['slug'] ?? '#' . $r['link_id']) ?></a>
            <div class="text-xs text-muted"><?= e(mb_strimwidth($r['title'] ?: ($r['target_url'] ?? ''), 0, 50, '…')) ?></div></td>
        <td><span class="badge <?= $r['kind'] === 'deactivate' ? 'badge-danger' : 'badge-success' ?>"><?= e(LinkRequests::KINDS[$r['kind']] ?? $r['kind']) ?></span></td>
        <td class="text-sm"><?= e($r['requester'] ?: '—') ?></td>
        <td class="text-sm"><?= $r['note'] ? e($r['note']) : '<span class="text-muted">—</span>' ?></td>
        <td class="text-sm text-muted"><?= e(substr($r['created_at'], 0, 16)) ?></td>
        <td>
          <div class="btn-group" style="flex-wrap:nowrap">
          <?php if ($isAdmin): ?>
            <form method="POST" data-confirm="<?= e(($r['slug'] ?? '') . ': ' . (LinkRequests::KINDS[$r['kind']] ?? '') . ' onaylansın mı? QR durumu hemen değişir.') ?>">
              <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="approve">
              <button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?> Onayla</button>
            </form>
            <form method="POST">
              <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="reject">
              <button class="btn btn-ghost btn-sm" type="submit"><?= icon('x') ?> Reddet</button>
            </form>
          <?php else: ?>
            <form method="POST">
              <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="cancel">
              <button class="btn btn-ghost btn-sm" type="submit"><?= icon('x') ?> Geri çek</button>
            </form>
          <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php if ($recent): ?>
<div class="card" style="padding:0">
  <div class="card-header" style="padding:1.1rem 1.25rem .25rem"><span class="card-title"><?= icon('history') ?> Sonuçlananlar</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>QR</th><th>Talep</th><th>Gönderen</th><th>Sonuç</th><th>Karar veren</th><th>Tarih</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr>
        <td><span class="font-mono"><?= e($r['slug'] ?? '#' . $r['link_id']) ?></span></td>
        <td class="text-sm"><?= e(LinkRequests::KINDS[$r['kind']] ?? $r['kind']) ?></td>
        <td class="text-sm"><?= e($r['requester'] ?: '—') ?></td>
        <td><span class="badge <?= $statusBadge[$r['status']] ?? 'badge-muted' ?>"><?= e(LinkRequests::STATUSES[$r['status']] ?? $r['status']) ?></span></td>
        <td class="text-sm"><?= e($r['decided_by'] ?? '—') ?></td>
        <td class="text-sm text-muted"><?= e(substr($r['decided_at'] ?? $r['created_at'], 0, 16)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
