<?php
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();

$pdo = getPdo();

// ─── Filtreler ────────────────────────────────────────────────────────────────
$search   = trim($_GET['q'] ?? '');
$status   = $_GET['status'] ?? '';
$category = $_GET['category'] ?? '';
$tag      = trim($_GET['tag'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));

$statuses = LinkService::STATUSES;
if (!isset($statuses[$status])) $status = '';
if ($category !== '' && !isset(LinkService::CATEGORIES[$category])) $category = '';

[$whereSql, $params] = LinkService::buildFilter(['q' => $search, 'status' => $status, 'category' => $category, 'tag' => $tag]);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM links {$whereSql}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$paged   = paginate($total, LINKS_PER_PAGE, $page);
$listStmt = $pdo->prepare(
    "SELECT * FROM links {$whereSql} ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?"
);
$i = 1;
foreach ($params as $p) $listStmt->bindValue($i++, $p, PDO::PARAM_STR);
$listStmt->bindValue($i++, (int)$paged['perPage'], PDO::PARAM_INT);
$listStmt->bindValue($i++, (int)$paged['offset'],  PDO::PARAM_INT);
$listStmt->execute();
$links = $listStmt->fetchAll();

// Etiket listesi (filtre için)
$allTags = [];
foreach ($pdo->query("SELECT tags FROM links WHERE tags IS NOT NULL AND tags <> '' AND archived_at IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    foreach (explode(',', $t) as $x) $allTags[mb_strtolower($x)] = $x;
}
ksort($allTags);

$qs = fn(array $over = []) => '?' . http_build_query(array_filter(
    array_merge(['q' => $search, 'status' => $status, 'category' => $category, 'tag' => $tag], $over),
    fn($v) => $v !== '' && $v !== null
));

$stateLabels = ['live' => ['● Aktif', 'badge-success'], 'inactive' => ['○ Pasif', 'badge-danger'],
                'scheduled' => ['⏳ Başlamadı', 'badge-muted'], 'expired' => ['⌛ Süresi doldu', 'badge-muted'],
                'archived' => ['🗄 Arşivde', 'badge-muted']];

$pageTitle  = 'Tüm Linkler';
$activePage = 'links';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title">🔗 Tüm Linkler</h1>
  <div class="btn-group">
    <a href="<?= BASE_URL ?>/admin/links-export<?= $qs() ?>" class="btn btn-ghost">⬇️ CSV</a>
    <a href="<?= BASE_URL ?>/admin/bulk" class="btn btn-ghost">📑 Toplu</a>
    <a href="<?= BASE_URL ?>/admin/create" class="btn btn-primary">➕ Yeni Link</a>
  </div>
</div>

<!-- Arama + filtre -->
<form class="search-bar mb-4" method="GET" action="" style="flex-wrap:wrap">
  <input class="form-control" type="search" name="q" placeholder="Kısa ad, başlık, URL, etiket veya not…" value="<?= e($search) ?>">
  <select class="form-control" name="status" style="max-width:190px" onchange="this.form.submit()">
    <?php foreach ($statuses as $k => $label): ?>
      <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="form-control" name="category" style="max-width:160px" onchange="this.form.submit()">
    <option value="">Tüm türler</option>
    <?php foreach (LinkService::CATEGORIES as $k => $label): ?>
      <option value="<?= e($k) ?>" <?= $category === $k ? 'selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($allTags): ?>
  <select class="form-control" name="tag" style="max-width:160px" onchange="this.form.submit()">
    <option value="">Tüm etiketler</option>
    <?php foreach ($allTags as $t): ?>
      <option value="<?= e($t) ?>" <?= mb_strtolower($tag) === mb_strtolower($t) ? 'selected' : '' ?>><?= e($t) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
  <button class="btn btn-ghost" type="submit">🔍 Ara</button>
  <?php if ($search || $status || $category || $tag): ?>
    <a href="<?= BASE_URL ?>/admin/links" class="btn btn-ghost">✕ Temizle</a>
  <?php endif; ?>
</form>

<div class="card" style="padding:0">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Kısa ad</th>
          <th>Başlık / Hedef URL</th>
          <th>Okutma</th>
          <th>Durum</th>
          <th>Oluşturulma</th>
          <th>İşlemler</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($links)): ?>
          <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--text-muted)">Hiç link bulunamadı.</td></tr>
        <?php else: foreach ($links as $link):
            $state = Redirector::state($link)['state']; ?>
          <tr>
            <td>
              <div class="copy-wrap">
                <span class="col-slug"><?= e($link['slug']) ?></span>
                <button class="copy-btn" data-copy="<?= e(BASE_URL . '/r/' . $link['slug']) ?>" title="Kısa URL'yi kopyala">📋</button>
              </div>
            </td>
            <td>
              <div style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600">
                <?= e($link['title'] ?: '—') ?>
                <?php if (!empty($link['category'])): ?>
                  <span class="badge badge-muted" style="font-weight:500"><?= e(LinkService::CATEGORIES[$link['category']] ?? $link['category']) ?></span>
                <?php endif; ?>
              </div>
              <div class="text-muted text-xs col-url" style="max-width:260px"><?= e($link['target_url']) ?></div>
              <?php if (!empty($link['tags'])): foreach (explode(',', $link['tags']) as $t): ?>
                <a class="tag-chip" href="<?= BASE_URL ?>/admin/links<?= $qs(['tag' => $t, 'page' => null]) ?>">#<?= e($t) ?></a>
              <?php endforeach; endif; ?>
              <?php if (!empty($link['ends_at'])): ?>
                <div class="text-xs text-muted">⏱ Bitiş: <?= e(substr($link['ends_at'], 0, 16)) ?></div>
              <?php endif; ?>
            </td>
            <td class="fw-bold"><?= formatCount((int)$link['scan_count']) ?></td>
            <td>
              <?php if ($state === 'archived'): ?>
                <span class="badge badge-muted">🗄 Arşivde</span>
              <?php elseif ($state === 'live' || $state === 'inactive'): ?>
                <form method="POST" action="<?= BASE_URL ?>/admin/toggle" style="display:inline">
                  <?= Auth::csrfField() ?>
                  <input type="hidden" name="id" value="<?= $link['id'] ?>">
                  <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
                  <button type="submit" class="badge <?= $stateLabels[$state][1] ?>"
                          style="cursor:pointer;border:none;font-family:inherit"
                          title="<?= $state === 'live' ? 'Pasife Al' : 'Aktife Al' ?>"><?= $stateLabels[$state][0] ?></button>
                </form>
              <?php else: ?>
                <span class="badge <?= $stateLabels[$state][1] ?>"><?= $stateLabels[$state][0] ?></span>
              <?php endif; ?>
            </td>
            <td class="text-sm text-muted"><?= e(substr($link['created_at'], 0, 10)) ?></td>
            <td>
              <div class="btn-group">
                <a href="<?= BASE_URL ?>/admin/qr-view?slug=<?= urlencode($link['slug']) ?>" class="btn btn-ghost btn-sm" title="QR / İndir / Paylaş">📱</a>
                <a href="<?= BASE_URL ?>/admin/report?id=<?= $link['id'] ?>" class="btn btn-ghost btn-sm" title="Rapor">📊</a>
                <a href="<?= BASE_URL ?>/admin/edit?id=<?= $link['id'] ?>" class="btn btn-ghost btn-sm" title="Düzenle">✏️</a>
                <?php if ($state === 'archived'): ?>
                  <form method="POST" action="<?= BASE_URL ?>/admin/restore">
                    <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= $link['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm" title="Arşivden çıkar">♻️</button>
                  </form>
                <?php else: ?>
                  <form method="POST" action="<?= BASE_URL ?>/admin/delete"
                        data-confirm="Bu QR arşivlenecek ve okutulduğunda hedefe gitmeyecek (geçmişi korunur, geri alınabilir). Devam edilsin mi?">
                    <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= $link['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm" title="Arşivle">🗄</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($paged['totalPages'] > 1): ?>
<div class="pagination">
  <?php for ($p = 1; $p <= $paged['totalPages']; $p++): ?>
    <a href="<?= $qs(['page' => $p]) ?>" class="page-btn <?= $p === $paged['currentPage'] ? 'active' : '' ?>"><?= $p ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<p class="text-sm text-muted mt-2">Toplam <?= $total ?> link</p>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
