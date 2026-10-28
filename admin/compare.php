<?php
/**
 * compare.php — 2-5 QR'ı aynı tarih aralığında yan yana karşılaştırır
 * (ör. aynı etkinlikteki afiş, broşür ve roll-up). Yalnızca kullanıcının görebildiği QR'lar.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();

const COMPARE_MAX = 5;
const COMPARE_COLORS = ['#3B82F6', '#F97316', '#22C55E', '#A855F7', '#06B6D4'];

$pdo = getPdo();

// ids=1,2,3 ya da ids[]=1&ids[]=2 (formdaki boş seçimler atlanır)
$raw = $_GET['ids'] ?? [];
$raw = is_array($raw) ? $raw : explode(',', (string)$raw);
$links = [];
foreach ($raw as $v) {
    $id = (int)$v;
    if ($id <= 0 || isset($links[$id]) || count($links) >= COMPARE_MAX) continue;
    $l = LinkService::find($pdo, $id);
    if ($l && Access::canSeeLink($l)) $links[$id] = $l;
}

[$range, $from, $to] = Stats::rangeFromRequest($_GET, $links ? min(array_map(fn($l) => substr($l['created_at'], 0, 10), $links)) : '2000-01-01');
$data = count($links) >= 2 ? Stats::compare($pdo, array_keys($links), $from, $to) : [];
$labels = $data ? array_keys(reset($data)['daily']) : [];

// Seçim listesi: görebildiği QR'lar, en çok okutulan önce
[$where, $params] = LinkService::buildFilter([]);
$st = $pdo->prepare("SELECT id, slug, title FROM links {$where} ORDER BY scan_count DESC, id DESC LIMIT 500");
$st->execute($params);
$options = $st->fetchAll();
foreach ($links as $l) {
    if (!in_array((int)$l['id'], array_map('intval', array_column($options, 'id')), true)) $options[] = $l;
}

$pageTitle  = 'QR Karşılaştır';
$activePage = 'report';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title"><?= icon('layers') ?> QR Karşılaştır</h1>
  <div class="btn-group">
    <a class="btn btn-ghost" href="<?= BASE_URL ?>/admin/report"><?= icon('arrow-left') ?> Rapor</a>
  </div>
</div>

<p class="text-sm text-muted mb-3">Aynı etkinlikteki farklı materyalleri (afiş, broşür, roll-up…) ya da farklı ofislerin QR'larını aynı tarih aralığında yan yana görün. En az 2, en fazla <?= COMPARE_MAX ?> QR seçin.</p>

<form method="GET" class="card mb-4">
  <div class="compare-pick">
    <?php $sel = array_keys($links); for ($i = 0; $i < COMPARE_MAX; $i++): $cur = $sel[$i] ?? 0; ?>
      <div class="form-group">
        <label class="form-label" for="cmp-<?= $i ?>"><span class="compare-legend" style="background:<?= COMPARE_COLORS[$i] ?>"></span><?= $i + 1 ?>. QR<?= $i >= 2 ? ' <span class="hint">isteğe bağlı</span>' : '' ?></label>
        <select class="form-control" id="cmp-<?= $i ?>" name="ids[]">
          <option value="">Seçin…</option>
          <?php foreach ($options as $o): ?>
            <option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === $cur ? 'selected' : '' ?>><?= e($o['slug']) ?><?= $o['title'] ? ' — ' . e(mb_strimwidth($o['title'], 0, 50, '…')) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endfor; ?>
  </div>
  <div class="search-bar">
    <select class="form-control" name="range" style="max-width:190px">
      <?php foreach (Stats::RANGES as $k => $lbl): ?>
        <option value="<?= $k ?>" <?= $range === (string)$k ? 'selected' : '' ?>><?= $lbl ?></option>
      <?php endforeach; ?>
    </select>
    <input class="form-control" type="date" name="from" value="<?= e($from) ?>" style="max-width:160px" aria-label="Başlangıç">
    <input class="form-control" type="date" name="to" value="<?= e($to) ?>" style="max-width:160px" aria-label="Bitiş">
    <button class="btn btn-primary" type="submit">Karşılaştır</button>
    <span class="text-xs text-muted"><?= e($from) ?> – <?= e($to) ?></span>
  </div>
</form>

<?php if (!$data): ?>
  <div class="card"><p class="text-muted">Karşılaştırmak için en az 2 QR seçin.</p></div>
<?php else: $i = 0; $color = []; foreach ($links as $id => $l) $color[$id] = COMPARE_COLORS[$i++];
  $best = max(array_column($data, 'total')); ?>

<div class="card mb-4">
  <div class="card-header"><span class="card-title"><?= icon('trending') ?> Günlük okutma</span>
    <span class="chart-legend text-xs">
      <?php foreach ($links as $id => $l): ?><span><span class="compare-legend" style="background:<?= $color[$id] ?>"></span><?= e($l['slug']) ?></span><?php endforeach; ?>
    </span>
  </div>
  <div class="chart-wrap" style="height:240px"><canvas id="compare-chart" class="chart-canvas"></canvas></div>
</div>

<div class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>QR</th><th>Okutma</th><th>Tahmini tekil</th><th>Mobil</th><th>En çok ülke</th><th>En yoğun saat</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($links as $id => $l): $d = $data[$id]; $peak = max($d['hours']) ? array_search(max($d['hours']), $d['hours'], true) : null; ?>
      <tr>
        <td><span class="compare-legend" style="background:<?= $color[$id] ?>"></span><span class="col-slug"><?= e($l['slug']) ?></span>
          <span class="text-muted text-sm"><?= e($l['title'] ?: '') ?></span></td>
        <td class="fw-bold"><?= formatCount($d['total']) ?><?= $d['total'] === $best && $best > 0 ? ' <span class="text-xs" title="En çok okutulan">★</span>' : '' ?></td>
        <td><?= formatCount($d['unique']) ?></td>
        <td><?= $d['total'] ? '%' . $d['mobile'] : '—' ?></td>
        <td><?= $d['country'] && $d['country'] !== '??' ? e($d['country']) : '—' ?></td>
        <td><?= $peak !== null ? sprintf('%02d:00', $peak) : '—' ?></td>
        <td><a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/admin/report?id=<?= $id ?>&range=<?= e($range) ?><?= $range === 'custom' ? '&from=' . e($from) . '&to=' . e($to) : '' ?>">Rapor</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="text-xs text-muted mt-2">Bot önizlemeleri ve pasif/yedek yönlendirmeler sayılmaz. Saatler sunucu saatine göredir.</p>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => window.drawMultiChart('compare-chart', <?= json_encode($labels) ?>, <?= json_encode(array_values(array_map(
    fn($id) => ['name' => $links[$id]['slug'], 'data' => array_values($data[$id]['daily']), 'color' => $color[$id]], array_keys($links)))) ?>));
</script>
<?php endif; ?>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
