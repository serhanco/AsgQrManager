<?php
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();

$pdo        = getPdo();
maybeAnonymizeScans($pdo);
$stats      = getDashboardStats($pdo);
$topLinks   = getTopLinks($pdo, 8);
$scanData   = getDailyScanStats($pdo, 30);
$chartLabels = array_column($scanData, 'date');
$chartValues = array_column($scanData, 'count');

$analyticsEnabled = Settings::analyticsEnabled();
$untagged = (int)$pdo->query("SELECT COUNT(*) FROM links WHERE archived_at IS NULL AND (tags IS NULL OR tags = '')")->fetchColumn();

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';

include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title">📊 Dashboard</h1>
  <div class="btn-group">
    <a href="<?= BASE_URL ?>/admin/report" class="btn btn-ghost btn-sm">📈 Raporlar</a>
    <span class="text-sm text-muted">Analytics:&nbsp;</span>
    <label class="toggle" title="Tarama analitiğini aç/kapat">
      <input type="checkbox" id="analytics-toggle" <?= $analyticsEnabled ? 'checked' : '' ?>>
      <span class="toggle-slider"></span>
    </label>
    <span class="text-sm <?= $analyticsEnabled ? 'text-success' : 'text-muted' ?>">
      <?= $analyticsEnabled ? 'Açık' : 'Kapalı' ?>
    </span>
  </div>
</div>

<?php if ($stats['total_links'] >= 3 && $untagged >= max(2, (int)ceil($stats['total_links'] / 2))): ?>
<div class="nudge">
  <div class="nudge-icon">🏷</div>
  <div>
    <div class="nudge-title"><?= $untagged ?> QR’ın etiketi yok</div>
    <p>Ülke, etkinlik adı veya materyal gibi etiketler verirseniz raporlarda “kongre-2027” ya da “tüm posterler” gibi gruplara göre sonuç görebilirsiniz.</p>
    <div class="btn-group">
      <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>/admin/links?tag=__none">Etiketsizleri göster</a>
      <a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/admin/tags">Etiket rehberi</a>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- İstatistik Kartları -->
<div class="stat-grid">
  <div class="stat-card accent">
    <div class="stat-icon">🔗</div>
    <div class="stat-label">Toplam Link</div>
    <div class="stat-value"><?= $stats['total_links'] ?></div>
  </div>
  <div class="stat-card success">
    <div class="stat-icon">✅</div>
    <div class="stat-label">Aktif</div>
    <div class="stat-value"><?= $stats['active_links'] ?></div>
  </div>
  <div class="stat-card warning">
    <div class="stat-icon">📈</div>
    <div class="stat-label">Toplam Tarama</div>
    <div class="stat-value"><?= formatCount($stats['total_scans']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">📅</div>
    <div class="stat-label">Bugün</div>
    <div class="stat-value"><?= $stats['today_scans'] ?></div>
  </div>
</div>

<!-- Grafik -->
<div class="card mb-4">
  <div class="card-header">
    <span class="card-title">📉 Son 30 Gün — Günlük Taramalar</span>
  </div>
  <div class="chart-wrap" style="height:180px">
    <canvas id="scan-chart" class="chart-canvas"></canvas>
  </div>
</div>

<!-- Popüler Linkler -->
<div class="card">
  <div class="card-header">
    <span class="card-title">🏆 En Çok Taranan Linkler</span>
    <a href="<?= BASE_URL ?>/admin/links" class="btn btn-ghost btn-sm">Tümünü Gör</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Slug</th>
          <th>Başlık / Hedef</th>
          <th>Tarama</th>
          <th>Durum</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($topLinks)): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:2rem">Henüz link yok</td></tr>
        <?php else: foreach ($topLinks as $i => $link): ?>
          <tr>
            <td class="text-muted text-sm"><?= $i + 1 ?></td>
            <td class="col-slug"><?= e($link['slug']) ?></td>
            <td>
              <div class="fw-bold" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= e($link['title'] ?: '—') ?>
              </div>
              <div class="text-muted text-xs col-url"><?= e($link['target_url']) ?></div>
            </td>
            <td class="fw-bold"><?= formatCount((int)$link['scan_count']) ?></td>
            <td>
              <span class="badge <?= $link['is_active'] ? 'badge-success' : 'badge-danger' ?>">
                <?= $link['is_active'] ? 'Aktif' : 'Pasif' ?>
              </span>
            </td>
            <td>
              <a href="<?= BASE_URL ?>/admin/qr-view?slug=<?= urlencode($link['slug']) ?>" class="btn btn-ghost btn-sm">QR</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function() {
  const labels = <?= json_encode($chartLabels) ?>;
  const values = <?= json_encode($chartValues) ?>;
  // RAF'tan sonra çiz (canvas boyutları hazır olsun)
  requestAnimationFrame(() => window.drawScanChart('scan-chart', labels, values));
})();
</script>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
