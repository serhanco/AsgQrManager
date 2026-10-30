<?php
/**
 * notifications.php — zamanlanmış görevlerin ürettiği uyarılar (bitiş yaklaşan, hedefi kırık, okutması duran QR).
 * Yönetici hepsini, editör görebildiği QR'larınkini görür; QR'ı düzenleyebilen uyarıyı kapatabilir.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();

$pdo = getPdo();
if (!Notifications::available($pdo)) {
    flash('warning', 'Uyarılar için veritabanı güncellemesi bekleniyor. Çıkış yapıp yeniden giriş yapın.');
    redirect(BASE_URL . '/admin/dashboard');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $ids = array_map('intval', (array)($_POST['ids'] ?? [$_POST['id'] ?? 0]));
    $done = 0;
    foreach ($ids as $id) {
        $n = Notifications::find($pdo, $id);
        if (!$n || !Notifications::canSee($pdo, $n)) continue;
        $link = $n['link_id'] ? LinkService::find($pdo, (int)$n['link_id']) : null;
        if (!Access::isAdmin() && !($link && Access::canEditLink($link))) continue;
        Notifications::resolve($pdo, $n, Auth::actor());
        $done++;
    }
    flash($done ? 'success' : 'warning', $done ? "{$done} uyarı kapatıldı." : 'Kapatılabilecek uyarı bulunamadı.');
    redirect(BASE_URL . '/admin/notifications');
}

$kind = isset(Notifications::KINDS[$_GET['kind'] ?? '']) ? $_GET['kind'] : null;
$open = Notifications::list($pdo, true, 200, $kind);
$closed = Notifications::list($pdo, false, 30, $kind);
$cronSeen = Settings::get('cron_last_seen', '');
$sev = ['danger' => 'badge-danger', 'warning' => 'badge-warning', 'info' => 'badge-info'];

$pageTitle  = 'Uyarılar';
$activePage = 'notifications';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= icon('bell') ?> Uyarılar</h1>
    <p class="page-sub">Otomatik kontroller her gün çalışır: bitişi yaklaşan, hedef adresi açılmayan ve okutması duran QR'lar burada listelenir.
      Aynı durum için ikinci kez uyarı oluşmaz; sorun düzelince kapatın (kırık hedef düzelince kendiliğinden kapanır).</p>
  </div>
</div>

<?php if ($cronSeen === '' && Access::isAdmin()): ?>
  <div class="alert alert-warning"><?= icon('alert') ?> Zamanlanmış görev (cron) henüz hiç çalışmadı; uyarılar oluşmaz.
    <a href="<?= BASE_URL ?>/admin/settings#automation">Ayarlar &gt; Otomasyon</a> bölümündeki komutu Hostinger'da Cron Jobs'a ekleyin.</div>
<?php endif; ?>

<div class="search-bar mb-3">
  <a class="btn btn-sm <?= $kind === null ? 'btn-primary' : 'btn-ghost' ?>" href="<?= BASE_URL ?>/admin/notifications">Tümü</a>
  <?php foreach (Notifications::KINDS as $k => [$label, $ic]): if ($k === 'system') continue; ?>
    <a class="btn btn-sm <?= $kind === $k ? 'btn-primary' : 'btn-ghost' ?>" href="<?= BASE_URL ?>/admin/notifications?kind=<?= $k ?>"><?= icon($ic) ?> <?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<form method="POST" class="card mb-4" style="padding:0">
  <?= Auth::csrfField() ?>
  <div class="card-header" style="padding:1.1rem 1.25rem .25rem"><span class="card-title"><?= icon('bell') ?> Açık (<?= count($open) ?>)</span>
    <?php if ($open): ?><button class="btn btn-ghost btn-sm" type="submit"><?= icon('check') ?> Seçilenleri kapat</button><?php endif; ?></div>
  <?php if (!$open): ?>
    <p class="text-muted" style="padding:1rem 1.25rem 1.5rem">Açık uyarı yok.</p>
  <?php else: ?>
  <ul class="alert-list">
    <?php foreach ($open as $n): [$label, $ic, $s] = Notifications::KINDS[$n['kind']] ?? [$n['kind'], 'info', 'info']; ?>
      <li class="alert-item sev-<?= e($s) ?>">
        <input type="checkbox" name="ids[]" value="<?= (int)$n['id'] ?>" aria-label="Seç">
        <span class="alert-ic"><?= icon($ic) ?></span>
        <div class="alert-body">
          <div class="text-xs"><span class="badge <?= $sev[$s] ?? 'badge-muted' ?>"><?= e($label) ?></span> <span class="text-muted"><?= e(date('d.m.Y H:i', strtotime($n['created_at']))) ?></span></div>
          <div class="fw-bold"><?= e($n['title']) ?></div>
          <?php if ($n['body']): ?><div class="text-sm text-muted"><?= e($n['body']) ?></div><?php endif; ?>
        </div>
        <div class="btn-group">
          <?php if ($n['slug']): ?>
            <a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/admin/qr-view?slug=<?= urlencode($n['slug']) ?>">QR</a>
            <a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/admin/report?id=<?= (int)$n['link_id'] ?>">Rapor</a>
          <?php endif; ?>
          <button class="btn btn-ghost btn-sm" type="submit" name="id" value="<?= (int)$n['id'] ?>" formnovalidate onclick="this.form.querySelectorAll('input[name=\'ids[]\']').forEach(c => c.checked = false)">Kapat</button>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</form>

<?php if ($closed): ?>
<details class="card">
  <summary class="card-title" style="cursor:pointer"><?= icon('check') ?> Son kapatılanlar</summary>
  <div class="table-wrap mt-2"><table class="compact">
    <tbody>
    <?php foreach ($closed as $n): ?>
      <tr><td class="text-sm"><?= e($n['title']) ?></td>
        <td class="text-xs text-muted" style="white-space:nowrap"><?= e(date('d.m.Y', strtotime($n['resolved_at']))) ?> · <?= e((string)$n['resolved_by']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</details>
<?php endif; ?>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
