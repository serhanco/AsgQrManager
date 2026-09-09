<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Logger.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/helpers.php';

Logger::registerHandlers();
Auth::startSession();
Auth::requireLogin();

$pdo = getPdo();

// Arama + sayfalama
$search  = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));

$where  = '';
$params = [];
if ($search !== '') {
    $where  = 'WHERE slug LIKE ? OR title LIKE ? OR target_url LIKE ?';
    $like   = '%' . $search . '%';
    $params = [$like, $like, $like];
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM links {$where}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$paged   = paginate($total, LINKS_PER_PAGE, $page);
$offset  = (int)$paged['offset'];
$perPage = (int)$paged['perPage'];

$listStmt = $pdo->prepare(
    "SELECT id, slug, title, target_url, is_active, scan_count, logo, created_at
     FROM links {$where}
     ORDER BY created_at DESC LIMIT ? OFFSET ?"
);

$bindIdx = 1;
foreach ($params as $param) {
    $listStmt->bindValue($bindIdx++, $param, PDO::PARAM_STR);
}
$listStmt->bindValue($bindIdx++, $perPage, PDO::PARAM_INT);
$listStmt->bindValue($bindIdx++, $offset,  PDO::PARAM_INT);
$listStmt->execute();
$links = $listStmt->fetchAll();

$pageTitle  = 'Tüm Linkler';
$activePage = 'links';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title">🔗 Tüm Linkler</h1>
  <a href="<?= BASE_URL ?>/admin/create" class="btn btn-primary">➕ Yeni Link</a>
</div>

<!-- Arama -->
<form class="search-bar mb-4" method="GET" action="">
  <input class="form-control" type="search" name="q" placeholder="Slug, başlık veya URL ara…"
         value="<?= e($search) ?>">
  <button class="btn btn-ghost" type="submit">🔍 Ara</button>
  <?php if ($search): ?>
    <a href="<?= BASE_URL ?>/admin/links" class="btn btn-ghost">✕ Temizle</a>
  <?php endif; ?>
</form>

<div class="card" style="padding:0">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Slug</th>
          <th>Başlık / Hedef URL</th>
          <th>Tarama</th>
          <th>Durum</th>
          <th>Oluşturulma</th>
          <th>İşlemler</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($links)): ?>
          <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--text-muted)">
            Hiç link bulunamadı.
          </td></tr>
        <?php else: foreach ($links as $link): ?>
          <tr>
            <td>
              <div class="copy-wrap">
                <span class="col-slug"><?= e($link['slug']) ?></span>
                <button class="copy-btn" data-copy="<?= e(BASE_URL . '/r/' . $link['slug']) ?>"
                        title="Kısa URL'yi kopyala">📋</button>
              </div>
            </td>
            <td>
              <div style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600">
                <?= e($link['title'] ?: '—') ?>
              </div>
              <div class="text-muted text-xs col-url" style="max-width:220px">
                <?= e($link['target_url']) ?>
              </div>
            </td>
            <td class="fw-bold"><?= formatCount((int)$link['scan_count']) ?></td>
            <td>
              <!-- Aktif/Pasif toggle (form POST) -->
              <form method="POST" action="<?= BASE_URL ?>/admin/toggle" style="display:inline">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="id" value="<?= $link['id'] ?>">
                <button type="submit" class="badge <?= $link['is_active'] ? 'badge-success' : 'badge-danger' ?>"
                        style="cursor:pointer;border:none;font-family:inherit"
                        title="<?= $link['is_active'] ? 'Pasife Al' : 'Aktife Al' ?>">
                  <?= $link['is_active'] ? '● Aktif' : '○ Pasif' ?>
                </button>
              </form>
            </td>
            <td class="text-sm text-muted"><?= e(substr($link['created_at'], 0, 10)) ?></td>
            <td>
              <div class="btn-group">
                <a href="<?= BASE_URL ?>/admin/qr-view?slug=<?= urlencode($link['slug']) ?>"
                   class="btn btn-ghost btn-sm" title="QR Görüntüle">📱</a>
                <a href="<?= BASE_URL ?>/admin/edit?id=<?= $link['id'] ?>"
                   class="btn btn-ghost btn-sm" title="Düzenle">✏️</a>
                <form method="POST" action="<?= BASE_URL ?>/admin/delete"
                      data-confirm="Bu linki silmek istediğinizden emin misiniz?">
                  <?= Auth::csrfField() ?>
                  <input type="hidden" name="id" value="<?= $link['id'] ?>">
                  <button type="submit" class="btn btn-ghost btn-sm" title="Sil"
                          style="color:var(--danger)">🗑</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Sayfalama -->
<?php if ($paged['totalPages'] > 1): ?>
<div class="pagination">
  <?php for ($p = 1; $p <= $paged['totalPages']; $p++): ?>
    <a href="?page=<?= $p ?>&q=<?= urlencode($search) ?>"
       class="page-btn <?= $p === $paged['currentPage'] ? 'active' : '' ?>">
      <?= $p ?>
    </a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<p class="text-sm text-muted mt-2">
  Toplam <?= $total ?> link<?php if ($search): ?> (&ldquo;<?= e($search) ?>&rdquo; için)<?php endif; ?>
</p>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
