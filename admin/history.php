<?php
/**
 * history.php — Değişiklik geçmişi: kim, ne zaman, hangi QR'da neyi değiştirdi.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();

$pdo  = getPdo();
$slug = preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['slug'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

$where = $slug !== '' ? 'WHERE slug = ?' : '';
$args  = $slug !== '' ? [$slug] : [];

$cnt = $pdo->prepare("SELECT COUNT(*) FROM link_events {$where}");
$cnt->execute($args);
$total = (int)$cnt->fetchColumn();
$paged = paginate($total, 50, $page);

$st = $pdo->prepare("SELECT * FROM link_events {$where} ORDER BY id DESC LIMIT ? OFFSET ?");
$i = 1;
foreach ($args as $a) $st->bindValue($i++, $a);
$st->bindValue($i++, (int)$paged['perPage'], PDO::PARAM_INT);
$st->bindValue($i++, (int)$paged['offset'], PDO::PARAM_INT);
$st->execute();
$events = $st->fetchAll();

$pageTitle  = 'Değişiklik Geçmişi';
$activePage = 'history';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title">🕘 Değişiklik Geçmişi<?= $slug !== '' ? ' — <code>' . e($slug) . '</code>' : '' ?></h1>
  <?php if ($slug !== ''): ?><a href="<?= BASE_URL ?>/admin/history" class="btn btn-ghost">Tümünü göster</a><?php endif; ?>
</div>

<div class="card" style="padding:0">
  <div class="table-wrap"><table>
    <thead><tr><th>Zaman</th><th>Kullanıcı</th><th>İşlem</th><th>Ayrıntı</th><th>QR</th></tr></thead>
    <tbody>
    <?php if (!$events): ?>
      <tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--text-muted)">Kayıt yok.</td></tr>
    <?php else: foreach ($events as $ev): ?>
      <tr>
        <td class="text-sm text-muted" style="white-space:nowrap"><?= e(substr($ev['created_at'], 0, 16)) ?></td>
        <td class="text-sm"><?= e($ev['username'] ?: '—') ?></td>
        <td class="text-sm fw-bold"><?= e(LinkEvents::actionLabel($ev['action'])) ?></td>
        <td class="text-xs"><?= LinkEvents::detailsHtml($ev) ?></td>
        <td class="text-sm"><?php if ($ev['slug']): ?><a href="<?= BASE_URL ?>/admin/history?slug=<?= urlencode($ev['slug']) ?>" class="font-mono"><?= e($ev['slug']) ?></a><?php endif; ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<?php if ($paged['totalPages'] > 1): ?>
<div class="pagination">
  <?php for ($p = 1; $p <= $paged['totalPages']; $p++): ?>
    <a href="?page=<?= $p ?><?= $slug !== '' ? '&slug=' . urlencode($slug) : '' ?>" class="page-btn <?= $p === $paged['currentPage'] ? 'active' : '' ?>"><?= $p ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
