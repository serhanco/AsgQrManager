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

// Kullanıcı kapsamına göre süzülmüş sorgu yardımcıları
[$scopeSql, $scopeArgs] = Access::linkScopeSql();
$scopeAnd = $scopeSql !== '' ? " AND {$scopeSql}" : '';
[$idSql, $idArgs] = Access::linkIdFilter('link_id');
$one = function (string $sql, array $args = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($args); return $st; };

$untagged = (int)$one("SELECT COUNT(*) FROM links WHERE archived_at IS NULL AND (tags IS NULL OR tags = '')" . $scopeAnd, $scopeArgs)->fetchColumn();

// Son 30 gün ve önceki 30 gün (değişim yüzdesi için)
$okScan = "is_bot = 0 AND (outcome IS NULL OR outcome = 'ok')";
$last30 = array_sum($chartValues);
$prev30 = (int)$one("SELECT COUNT(*) FROM scans WHERE {$okScan} AND scanned_at >= DATE_SUB(CURDATE(), INTERVAL 59 DAY)
                     AND scanned_at < DATE_SUB(CURDATE(), INTERVAL 29 DAY)" . $idSql, $idArgs)->fetchColumn();
$trend = $prev30 > 0 ? (int)round(($last30 - $prev30) / $prev30 * 100) : null;

// Dikkat isteyenler
$now  = (new DateTimeImmutable('now'))->setTimezone(Settings::timezone());
$soon = $one("SELECT id, slug, title, ends_at FROM links WHERE archived_at IS NULL AND is_active = 1 AND ends_at > ? AND ends_at <= ?{$scopeAnd} ORDER BY ends_at LIMIT 5",
             array_merge([$now->format('Y-m-d H:i:s'), $now->modify('+7 days')->format('Y-m-d H:i:s')], $scopeArgs))->fetchAll();
$silent = $one("SELECT l.id, l.slug, l.title FROM links l WHERE l.archived_at IS NULL AND l.is_active = 1
                AND l.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
                AND NOT EXISTS (SELECT 1 FROM scans s WHERE s.link_id = l.id AND s.scanned_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY))"
                . ($scopeSql !== '' ? ' AND l.id IN (SELECT id FROM links WHERE ' . $scopeSql . ')' : '') . ' ORDER BY l.id DESC LIMIT 5', $scopeArgs)->fetchAll();
$maxTop = $topLinks ? max(1, (int)$topLinks[0]['scan_count']) : 1;

$hour = (int)$now->format('G');
$greet = $hour < 6 ? 'İyi geceler' : ($hour < 12 ? 'Günaydın' : ($hour < 18 ? 'İyi günler' : 'İyi akşamlar'));
$firstName = explode(' ', trim(($_SESSION['display_name'] ?? '') ?: ($_SESSION['username'] ?? '')))[0];
$months = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
$days   = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
$today  = $now->format('j') . ' ' . $months[(int)$now->format('n')] . ' ' . $now->format('Y') . ', ' . $days[(int)$now->format('w')];

$pageTitle  = 'Gösterge Paneli';
$activePage = 'dashboard';

include __DIR__ . '/views/_layout.php';
?>

<section class="hero">
  <div>
    <h1><?= e($greet) ?><?= $firstName !== '' ? ', ' . e($firstName) : '' ?></h1>
    <p><?= e($today) ?> · Son 30 günde <strong style="color:#fff"><?= number_format($last30, 0, ',', '.') ?></strong> okutma</p>
  </div>
  <div class="btn-group">
    <a href="<?= BASE_URL ?>/admin/report" class="btn btn-ghost"><?= icon('chart') ?> Raporlar</a>
    <?php if (Access::can('links.edit')): ?>
      <a href="<?= BASE_URL ?>/admin/create" class="btn btn-primary"><?= icon('plus') ?> Yeni QR</a>
    <?php endif; ?>
  </div>
</section>

<?php if ($stats['total_links'] >= 3 && $untagged >= max(2, (int)ceil($stats['total_links'] / 2))): ?>
<div class="nudge">
  <div class="nudge-icon"><?= icon('tag') ?></div>
  <div>
    <div class="nudge-title"><?= $untagged ?> QR’ın etiketi yok</div>
    <p>Ülke, etkinlik adı veya materyal gibi etiketler verirseniz raporlarda “kongre-2027” ya da “tüm posterler” gibi gruplara göre sonuç görebilirsiniz.</p>
    <div class="btn-group">
      <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>/admin/links?tag=__none">Etiketsizleri göster</a>
      <?php if (Access::isAdmin()): ?><a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/admin/tags">Etiket rehberi</a><?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- İstatistik kartları -->
<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-icon"><?= icon('qr') ?></div>
    <div class="stat-label">Aktif QR</div>
    <div class="stat-value"><?= $stats['active_links'] ?></div>
    <div class="stat-sub">toplam <?= $stats['total_links'] ?> · <?= $stats['inactive_links'] ?> pasif</div>
  </div>
  <div class="stat-card sky">
    <div class="stat-icon"><?= icon('trending') ?></div>
    <div class="stat-label">Son 30 gün</div>
    <div class="stat-value"><?= formatCount($last30) ?></div>
    <div class="stat-sub"><?php if ($trend === null): ?>önceki dönem verisi yok<?php else: ?>
      <span style="color:<?= $trend >= 0 ? 'var(--success)' : 'var(--danger)' ?>;font-weight:700"><?= $trend >= 0 ? '▲' : '▼' ?> %<?= abs($trend) ?></span> önceki 30 güne göre<?php endif; ?></div>
  </div>
  <div class="stat-card success">
    <div class="stat-icon"><?= icon('calendar') ?></div>
    <div class="stat-label">Bugün</div>
    <div class="stat-value"><?= $stats['today_scans'] ?></div>
    <div class="stat-sub">okutma</div>
  </div>
  <div class="stat-card warning">
    <div class="stat-icon"><?= icon('award') ?></div>
    <div class="stat-label">Toplam okutma</div>
    <div class="stat-value"><?= formatCount($stats['total_scans']) ?></div>
    <div class="stat-sub">tüm zamanlar</div>
  </div>
</div>

<div class="dash-grid">
  <!-- Grafik -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title"><?= icon('trending') ?> Günlük okutmalar</div>
        <div class="card-sub">Son 30 gün · bot ve link önizlemeleri hariç</div>
      </div>
      <?php if (Access::isAdmin()): ?>
      <label class="toggle-wrap text-sm text-muted" title="Okutma analitiğini aç/kapat">
        Analitik
        <span class="toggle">
          <input type="checkbox" id="analytics-toggle" <?= $analyticsEnabled ? 'checked' : '' ?>>
          <span class="toggle-slider"></span>
        </span>
        <span class="<?= $analyticsEnabled ? 'text-success' : 'text-muted' ?>"><?= $analyticsEnabled ? 'Açık' : 'Kapalı' ?></span>
      </label>
      <?php endif; ?>
    </div>
    <div class="chart-wrap">
      <canvas id="scan-chart" class="chart-canvas" role="img" aria-label="Son 30 günün günlük okutma grafiği"></canvas>
    </div>
  </div>

  <!-- Dikkat isteyenler -->
  <div class="card">
    <div class="card-header"><div class="card-title"><?= icon('alert') ?> Dikkat isteyenler</div></div>
    <ul class="attention-list">
      <?php $pendingReqCount = Access::isAdmin() ? LinkRequests::pendingCount($pdo) : 0;
      if ($pendingReqCount > 0): ?>
        <li><span class="dot warn"><?= icon('check-circle') ?></span>
          <div><a href="<?= BASE_URL ?>/admin/requests" class="fw-bold"><?= $pendingReqCount ?> talep onay bekliyor</a>
          <small>Editörlerin pasife alma / yeniden açma talepleri</small></div></li>
      <?php endif; ?>
      <?php foreach ($soon as $l): ?>
        <li><span class="dot warn"><?= icon('clock') ?></span>
          <div><a href="<?= BASE_URL ?>/admin/qr-view?slug=<?= urlencode($l['slug']) ?>" class="fw-bold"><?= e($l['title'] ?: $l['slug']) ?></a>
          <small>Bitiş <?= e(substr($l['ends_at'], 0, 16)) ?> · ardından bitiş sonrası adrese geçer</small></div></li>
      <?php endforeach; ?>
      <?php if ($stats['inactive_links'] > 0): ?>
        <li><span class="dot off"><?= icon('power') ?></span>
          <div><a href="<?= BASE_URL ?>/admin/links?status=inactive" class="fw-bold"><?= $stats['inactive_links'] ?> pasif QR</a>
          <small>Okutulunca yedek hedefe veya bilgi sayfasına gider</small></div></li>
      <?php endif; ?>
      <?php foreach ($silent as $l): ?>
        <li><span class="dot info"><?= icon('info') ?></span>
          <div><a href="<?= BASE_URL ?>/admin/report?id=<?= (int)$l['id'] ?>" class="fw-bold"><?= e($l['title'] ?: $l['slug']) ?></a>
          <small>Son 30 günde hiç okutulmadı</small></div></li>
      <?php endforeach; ?>
      <?php if (!$soon && !$silent && $stats['inactive_links'] === 0 && !$pendingReqCount): ?>
        <li><span class="dot ok"><?= icon('check') ?></span><div>Her şey yolunda<small>Yakında biten, pasif veya sessiz QR yok.</small></div></li>
      <?php endif; ?>
    </ul>
  </div>
</div>

<!-- En çok okutulanlar -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><?= icon('award') ?> En çok okutulan QR'lar</div>
    <a href="<?= BASE_URL ?>/admin/links" class="btn btn-ghost btn-sm">Tümünü gör <?= icon('arrow-right') ?></a>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Kısa ad</th>
          <th>Başlık / hedef</th>
          <th>Okutma</th>
          <th>Durum</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($topLinks)): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:2rem">Henüz QR yok</td></tr>
        <?php else: foreach ($topLinks as $i => $link): ?>
          <tr>
            <td><span class="rank <?= $i < 3 ? 'top' : '' ?>"><?= $i + 1 ?></span></td>
            <td class="col-slug"><?= e($link['slug']) ?></td>
            <td>
              <div class="fw-bold" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= e($link['title'] ?: '—') ?>
              </div>
              <div class="text-muted text-xs col-url"><?= e($link['target_url']) ?></div>
            </td>
            <td class="fw-bold" style="min-width:130px"><?= formatCount((int)$link['scan_count']) ?>
              <div class="mini-bar"><span style="width:<?= round((int)$link['scan_count'] / $maxTop * 100) ?>%"></span></div></td>
            <td>
              <span class="badge <?= $link['is_active'] ? 'badge-success' : 'badge-danger' ?>">
                <?= $link['is_active'] ? '● Aktif' : '○ Pasif' ?>
              </span>
            </td>
            <td style="text-align:right">
              <a href="<?= BASE_URL ?>/admin/qr-view?slug=<?= urlencode($link['slug']) ?>" class="btn btn-ghost btn-sm"><?= icon('qr') ?> QR</a>
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
  document.addEventListener('DOMContentLoaded', () => window.drawScanChart('scan-chart', labels, values));
})();
</script>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
