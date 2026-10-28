<?php
/**
 * report.php — QR bazında veya tüm QR'lar için okutma raporu (+ CSV).
 * Bot önizlemeleri ve pasif/yedek yönlendirmeler "okutma" sayılmaz; ayrıca gösterilir.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();

$pdo  = getPdo();
$id   = (int)($_GET['id'] ?? 0);
$link = $id ? LinkService::find($pdo, $id) : null;
if ($id) $link = Auth::requireLinkAccess($link);

$tag = $link ? '' : trim((string)($_GET['tag'] ?? ''));
if ($tag !== '' && mb_strlen($tag) > 30) $tag = '';

// ─── Tarih aralığı ────────────────────────────────────────────────────────────
[$range, $from, $to] = Stats::rangeFromRequest($_GET, $link ? substr($link['created_at'], 0, 10) : '2000-01-01');
$fromTs = $from . ' 00:00:00';
$toTs   = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

$linkSql = $link ? ' AND s.link_id = ?' : '';
$base    = ['ok' => "s.is_bot = 0 AND (s.outcome IS NULL OR s.outcome = 'ok') AND s.scanned_at >= ? AND s.scanned_at < ?" . $linkSql];
$args    = [$fromTs, $toTs];
if ($link) $args[] = $link['id'];
if ($tag !== '') {
    $base['ok'] .= ' AND s.link_id IN (SELECT id FROM links WHERE FIND_IN_SET(?, tags) > 0)';
    $args[] = $tag;
}
// Kullanıcı kapsamı: yalnızca görebildiği QR'ların okutmaları
[$scopeSql, $scopeArgs] = Access::linkIdFilter('s.link_id');
$base['ok'] .= $scopeSql;
array_push($args, ...$scopeArgs);

$q = function (string $sql, array $a) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st; };

// ─── CSV ──────────────────────────────────────────────────────────────────────
$csv = $_GET['csv'] ?? '';
if ($csv !== '') {
    header('Content-Type: text/csv; charset=UTF-8');
    $name = 'rapor-' . ($link ? $link['slug'] : ($tag !== '' ? 'etiket-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $tag) : 'tum-qr')) . '-' . $from . '_' . $to . '-' . $csv;
    header('Content-Disposition: attachment; filename="' . $name . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    if ($csv === 'daily') {
        fputcsv($out, ['tarih', 'okutma'], ';');
        foreach ($q("SELECT DATE(s.scanned_at) d, COUNT(*) c FROM scans s WHERE {$base['ok']} GROUP BY d ORDER BY d", $args) as $r) {
            fputcsv($out, [$r['d'], $r['c']], ';');
        }
    } elseif ($csv === 'links') {
        fputcsv($out, ['slug', 'baslik', 'tur', 'okutma', 'tahmini_tekil'], ';');
        $rows = $q("SELECT l.slug, l.title, l.category, COUNT(*) c, COUNT(DISTINCT CONCAT(IFNULL(s.ip,''),'|',IFNULL(s.user_agent,''))) u
                    FROM scans s JOIN links l ON l.id = s.link_id WHERE {$base['ok']} GROUP BY l.id ORDER BY c DESC", $args);
        foreach ($rows as $r) fputcsv($out, array_map('csvCell', [$r['slug'], $r['title'], $r['category'] ? (LinkService::CATEGORIES[$r['category']] ?? $r['category']) : '', $r['c'], $r['u']]), ';');
    } else { // raw — kişisel veri (IP, ham User-Agent) içermez
        fputcsv($out, ['zaman', 'slug', 'ulke', 'cihaz', 'isletim_sistemi', 'tarayici', 'kaynak_site'], ';');
        $rows = $q("SELECT s.scanned_at, l.slug, s.country, s.user_agent, s.referer
                    FROM scans s JOIN links l ON l.id = s.link_id WHERE {$base['ok']} ORDER BY s.scanned_at", $args);
        foreach ($rows as $r) {
            $ua = parseUserAgent((string)$r['user_agent']);
            $host = $r['referer'] ? (parse_url($r['referer'], PHP_URL_HOST) ?: '') : '';
            fputcsv($out, array_map('csvCell', [$r['scanned_at'], $r['slug'], $r['country'] ?? '', $ua['device'], $ua['os'], $ua['browser'], $host]), ';');
        }
    }
    fclose($out);
    exit;
}

// ─── Özet ─────────────────────────────────────────────────────────────────────
$S = Stats::summary($pdo, $link ? (int)$link['id'] : null, $from, $to, $tag);
$daily = $S['daily']; $hours = $S['hours'];
$dev = $S['devices']; $os = $S['os']; $br = $S['browsers']; $countries = $S['countries']; $refs = $S['referrers'];
$tot = ['c' => $S['total'], 'u' => $S['unique']];
$other = ['bots' => $S['bots'], 'redirected' => $S['redirected']];
$heat = $S['heat'];

// Dönem karşılaştırması: aynı uzunlukta hemen önceki dönem ("Tüm zamanlar" ve 1 yıldan uzun aralıkta yok)
$prev = $prevDaily = null;
if ($range !== 'all' && count($daily) === (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1) {
    [$pFrom, $pTo] = Stats::previousRange($from, $to);
    $prev = Stats::totals($pdo, $link ? (int)$link['id'] : null, $pFrom, $pTo, $tag);
    $prevDaily = Stats::dailyCounts($pdo, $link ? (int)$link['id'] : null, $pFrom, $pTo, $tag);
}
$delta = function (int $now, ?int $before) use ($prev): string {
    if ($prev === null) return '';
    $c = Stats::change($now, (int)$before);
    if ($c === null) return '<div class="stat-sub">önceki dönem: ' . formatCount((int)$before) . '</div>';
    $cls = $c > 0 ? 'delta-up' : ($c < 0 ? 'delta-down' : '');
    return '<div class="stat-sub"><span class="delta ' . $cls . '">' . ($c > 0 ? '▲ %' . $c : ($c < 0 ? '▼ %' . abs($c) : '= %0')) . '</span> önceki döneme göre (' . formatCount((int)$before) . ')</div>';
};

// Hedef değişikliği işaretleri (QR raporu)
$changes = $link ? Stats::targetChanges($pdo, (int)$link['id'], array_key_first($daily), $to) : [];
$dayIdx = array_flip(array_keys($daily));
$markers = array_values(array_filter(array_map(fn($c) => isset($dayIdx[$c['date']]) ? ['i' => $dayIdx[$c['date']], 'label' => $c['date']] : null, $changes)));

// Sahibine göre (genel rapor, yalnızca yönetici: editör zaten yalnızca kendi kapsamını görür)
$perOwner = (!$link && Access::isAdmin()) ? Stats::byOwner($pdo, $from, $to, $tag) : [];

// QR bazlı tablo (yalnızca genel raporda) ve türlere göre dağılım
$perLink = $perCat = [];
if (!$link) {
    $perLink = $q("SELECT l.id, l.slug, l.title, l.category, COUNT(*) c
                   FROM scans s JOIN links l ON l.id = s.link_id WHERE {$base['ok']} GROUP BY l.id ORDER BY c DESC LIMIT 25", $args)->fetchAll();
    foreach ($q("SELECT IFNULL(l.category,'') k, COUNT(*) c FROM scans s JOIN links l ON l.id = s.link_id WHERE {$base['ok']} GROUP BY k ORDER BY c DESC", $args) as $r) {
        $perCat[$r['k'] === '' ? 'Kategorisiz' : (LinkService::CATEGORIES[$r['k']] ?? $r['k'])] = (int)$r['c'];
    }
}

// Etikete göre dağılım (genel raporda): bir okutma, QR'ının her etiketine sayılır
$perTag = [];
if (!$link && $tag === '') {
    $canon = [];
    foreach ($q("SELECT l.tags t, COUNT(*) c FROM scans s JOIN links l ON l.id = s.link_id WHERE {$base['ok']} AND l.tags IS NOT NULL AND l.tags <> '' GROUP BY l.id", $args) as $r) {
        foreach (explode(',', $r['t']) as $t) {
            $k = LinkService::tagKey($t);
            $canon[$k] ??= $t;
            $perTag[$canon[$k]] = ($perTag[$canon[$k]] ?? 0) + (int)$r['c'];
        }
    }
    arsort($perTag);
    $perTag = array_slice($perTag, 0, 15, true);
}
$allTagList = LinkService::allTags($pdo);

// ─── Excel (çok sayfalı) ve yazdırılabilir / PDF rapor ───────────────────────
$export = (string)($_GET['export'] ?? '');
if ($export === 'xlsx' || $export === 'print') {
    $actor = Auth::actor();
    $R = [
        'scope' => $link ? 'QR: ' . $link['slug'] . ($link['title'] ? ' — ' . $link['title'] : '')
                 : ($tag !== '' ? '#' . $tag . ' etiketli QR\'lar' : (Access::isAdmin() ? 'Tüm QR\'lar' : 'Yetkili olduğunuz QR\'lar')),
        'fileKey' => $link ? $link['slug'] : ($tag !== '' ? 'etiket-' . $tag : 'tum-qr'),
        'from' => array_key_first($daily) ?? $from, 'to' => $to,
        'prevFrom' => $pFrom ?? null, 'prevTo' => $pTo ?? null,
        'total' => (int)$tot['c'], 'unique' => (int)$tot['u'],
        'prevTotal' => $prev['total'] ?? null, 'prevUnique' => $prev['unique'] ?? null,
        'bots' => (int)$other['bots'], 'redirected' => (int)$other['redirected'],
        'daily' => $daily, 'prevDaily' => $prevDaily, 'markers' => array_column($markers, 'i'),
        'devices' => $dev, 'os' => $os, 'browsers' => $br, 'countries' => $countries, 'referrers' => $refs, 'heat' => $heat,
        'links' => $link ? [] : $q("SELECT l.slug, l.title, l.category, l.tags, COUNT(*) c, COUNT(DISTINCT CONCAT(IFNULL(s.ip,''),'|',IFNULL(s.user_agent,''))) u
                                    FROM scans s JOIN links l ON l.id = s.link_id WHERE {$base['ok']} GROUP BY l.id ORDER BY c DESC", $args)->fetchAll(),
        'categories' => $perCat, 'tags' => $perTag, 'owners' => $perOwner, 'changes' => $changes,
        'by' => $actor['name'] ?? '', 'at' => (new DateTimeImmutable('now', Settings::timezone()))->format('d.m.Y H:i'),
    ];
    if ($export === 'xlsx') {
        if (!Xlsx::available()) {
            flash('danger', 'Sunucuda Excel için gereken ZIP desteği (ZipArchive) yok. CSV indirebilirsiniz.');
            redirect(BASE_URL . '/admin/report?' . http_build_query(array_diff_key($_GET, ['export' => 1])));
        }
        $data = Xlsx::build(ReportExport::sheets($R));
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . ReportExport::fileName($R, 'xlsx') . '"');
        header('Content-Length: ' . strlen($data));
        echo $data;
        exit;
    }
    include __DIR__ . '/views/report_print.php';
    exit;
}

$flag = function (string $cc): string {
    if (!preg_match('/^[A-Z]{2}$/', $cc)) return '🌐';
    return mb_chr(0x1F1E6 + ord($cc[0]) - 65) . mb_chr(0x1F1E6 + ord($cc[1]) - 65);
};
function reportBars(array $data, int $total, ?callable $label = null): void {
    if (!$data) { echo '<p class="text-sm text-muted">Veri yok.</p>'; return; }
    $max = max($data);
    foreach ($data as $k => $v) {
        $pct = $total > 0 ? round($v / $total * 100) : 0;
        $w = $max > 0 ? round($v / $max * 100) : 0;
        echo '<div class="bar-row"><div class="bar-label">' . ($label ? $label((string)$k) : e($k)) . '</div>'
           . '<div class="bar-track"><div class="bar-fill" style="width:' . $w . '%"></div></div>'
           . '<div class="bar-val">' . formatCount((int)$v) . ' <span class="text-muted text-xs">%' . $pct . '</span></div></div>';
    }
}

$qsBase = ['id' => $id ?: null, 'tag' => $tag ?: null, 'range' => $range, 'from' => $range === 'custom' ? $from : null, 'to' => $range === 'custom' ? $to : null];
$qs = fn(array $o = []) => '?' . http_build_query(array_filter(array_merge($qsBase, $o), fn($v) => $v !== null && $v !== ''));

$total = (int)$tot['c'];
$pageTitle  = 'Rapor';
$activePage = 'report';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title"><?= icon('chart') ?> Rapor<?= $link ? ' — <code>' . e($link['slug']) . '</code>' : ($tag !== '' ? ' — <span class="tag-chip" style="font-size:1rem">#' . e($tag) . '</span>' : ' — Tüm QR\'lar') ?></h1>
  <div class="btn-group">
    <a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/admin/report<?= $qs(['csv' => 'daily']) ?>"><?= icon('download') ?> Günlük CSV</a>
    <a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/admin/report<?= $qs(['csv' => $link ? 'raw' : 'links']) ?>"><?= icon('download') ?> <?= $link ? 'Okutma listesi CSV' : 'QR bazlı CSV' ?></a>
    <a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/admin/report<?= $qs(['export' => 'xlsx']) ?>" title="Özet, günlük seri, QR'lar, kategori, etiket, ülke, cihaz ve gün × saat ayrı sayfalarda"><?= icon('download') ?> Excel</a>
    <a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/admin/report<?= $qs(['export' => 'print']) ?>" target="_blank" rel="noopener" title="Yönetime gönderilecek antetli özet; tarayıcıdan PDF olarak kaydedin"><?= icon('file') ?> PDF / Yazdır</a>
    <a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/admin/compare<?= $link ? '?ids=' . (int)$link['id'] : '' ?>"><?= icon('layers') ?> Karşılaştır</a>
    <?php if ($link && Access::canEditLink($link)): ?><a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/admin/edit?id=<?= $link['id'] ?>"><?= icon('pencil') ?> Düzenle</a><?php endif; ?>
  </div>
</div>

<?php if ($tag !== ''): ?>
<div class="alert alert-info">
  <strong>#<?= e($tag) ?></strong> etiketli tüm QR’ların birleşik raporu.
  <a href="<?= BASE_URL ?>/admin/links?tag=<?= urlencode($tag) ?>">QR’ları listele</a> ·
  <a href="<?= BASE_URL ?>/admin/report<?= $qs(['tag' => null]) ?>">Etiket süzgecini kaldır</a>
</div>
<?php endif; ?>

<?php if ($link): ?>
<div class="alert alert-info">
  <strong><?= e($link['title'] ?: $link['slug']) ?></strong> — <span class="font-mono text-xs"><?= e(BASE_URL . '/r/' . $link['slug']) ?></span>
  → <span class="text-muted text-sm"><?= e($link['target_url']) ?></span>
</div>
<?php endif; ?>

<form method="GET" class="search-bar mb-4">
  <?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
  <?php if (!$link): ?>
  <select class="form-control" name="tag" style="max-width:200px" onchange="this.form.submit()" title="Yalnızca bu etikete sahip QR'ların birleşik raporu">
    <option value="">Tüm etiketler</option>
    <?php foreach ($allTagList as $t): ?>
      <option value="<?= e($t['tag']) ?>" <?= LinkService::tagKey($tag) === LinkService::tagKey($t['tag']) && $tag !== '' ? 'selected' : '' ?>>#<?= e($t['tag']) ?> (<?= (int)$t['count'] ?>)</option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
  <select class="form-control" name="range" id="range-sel" style="max-width:190px">
    <?php foreach (Stats::RANGES as $k => $lbl): ?>
      <option value="<?= $k ?>" <?= $range === (string)$k ? 'selected' : '' ?>><?= $lbl ?></option>
    <?php endforeach; ?>
  </select>
  <input class="form-control" type="date" name="from" value="<?= e($from) ?>" style="max-width:160px">
  <input class="form-control" type="date" name="to" value="<?= e($to) ?>" style="max-width:160px">
  <button class="btn btn-ghost" type="submit">Uygula</button>
  <span class="text-xs text-muted"><?= e($from) ?> – <?= e($to) ?></span>
</form>

<div class="stat-grid">
  <div class="stat-card accent"><div class="stat-icon"><?= icon('trending') ?></div><div class="stat-label">Okutma</div><div class="stat-value"><?= formatCount($total) ?></div><?= $delta($total, $prev['total'] ?? null) ?></div>
  <div class="stat-card success"><div class="stat-icon"><?= icon('users') ?></div><div class="stat-label">Tahmini tekil ziyaretçi</div><div class="stat-value"><?= formatCount((int)$tot['u']) ?></div><?= $delta((int)$tot['u'], $prev['unique'] ?? null) ?></div>
  <div class="stat-card"><div class="stat-icon"><?= icon('repeat') ?></div><div class="stat-label">Yedek hedefe giden</div><div class="stat-value"><?= (int)($other['redirected'] ?? 0) ?></div></div>
  <div class="stat-card warning"><div class="stat-icon"><?= icon('bot') ?></div><div class="stat-label">Bot / önizleme (sayılmadı)</div><div class="stat-value"><?= (int)($other['bots'] ?? 0) ?></div></div>
  <?php if ($link && VCard::isVcard($link) && ($vcRep = VCard::find($pdo, (int)$link['id']))): ?>
  <div class="stat-card sky"><div class="stat-icon"><?= icon('contact') ?></div><div class="stat-label">Rehbere ekleme <?= tip('Kişi sayfasındaki “Rehbere ekle” düğmesine basılma sayısı (tüm zamanlar).') ?></div><div class="stat-value"><?= formatCount((int)$vcRep['save_count']) ?></div>
    <?php if ((int)$link['scan_count'] > 0): ?><div class="stat-sub">okutmaların %<?= round(100 * (int)$vcRep['save_count'] / (int)$link['scan_count']) ?>'i</div><?php endif; ?></div>
  <?php endif; ?>
</div>

<div class="card mb-4">
  <div class="card-header"><span class="card-title"><?= icon('trending') ?> Günlük okutma</span>
    <span class="chart-legend text-xs text-muted">
      <span><i class="lg-line"></i> Bu dönem</span>
      <?php if ($prevDaily): ?><span><i class="lg-dash"></i> Önceki dönem (<?= e($pFrom) ?> – <?= e($pTo) ?>)</span><?php endif; ?>
      <?php if ($markers): ?><span><i class="lg-mark"></i> Hedef değişti</span><?php endif; ?>
    </span>
  </div>
  <div class="chart-wrap" style="height:200px"><canvas id="report-chart" class="chart-canvas"></canvas></div>
  <?php if ($changes): ?>
  <ul class="target-changes text-xs mt-2">
    <?php foreach ($changes as $c): ?>
      <li><span class="lg-mark"></span> <strong><?= e(date('d.m.Y H:i', strtotime($c['at']))) ?></strong> hedef değişti<?= $c['user'] ? ' (' . e($c['user']) . ')' : '' ?>:
        <span class="text-muted"><?= e(mb_strimwidth((string)$c['from'], 0, 60, '…')) ?></span> → <?= e(mb_strimwidth((string)$c['to'], 0, 60, '…')) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>

<div class="report-grid">
  <div class="card"><div class="card-title mb-3"><?= icon('qr') ?> Cihaz</div><?php reportBars($dev, $total); ?></div>
  <div class="card"><div class="card-title mb-3"><?= icon('monitor') ?> İşletim sistemi</div><?php reportBars($os, $total); ?></div>
  <div class="card"><div class="card-title mb-3"><?= icon('globe') ?> Tarayıcı</div><?php reportBars($br, $total); ?></div>
  <div class="card"><div class="card-title mb-3"><?= icon('globe') ?> Ülke
      <?php if (!$countries || array_keys($countries) === ['??']): ?><span class="text-xs text-muted">(ülke bilgisi Cloudflare üzerinden gelir)</span><?php endif; ?></div>
    <?php reportBars($countries, $total, fn($k) => $flag($k) . ' ' . ($k === '??' ? 'Bilinmiyor' : e($k))); ?></div>
  <div class="card"><div class="card-title mb-3"><?= icon('link') ?> Kaynak</div><?php reportBars($refs, $total); ?></div>
  <div class="card"><div class="card-title mb-3"><?= icon('clock') ?> Saat dağılımı</div>
    <div class="hour-chart">
      <?php $hm = max($hours) ?: 1; foreach ($hours as $h => $c): ?>
        <div class="hour-col" title="<?= sprintf('%02d:00 — %d okutma', $h, $c) ?>">
          <div class="hour-bar" style="height:<?= round($c / $hm * 100) ?>%"></div><div class="hour-lbl"><?= $h % 3 === 0 ? sprintf('%02d', $h) : '' ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="text-xs text-muted mt-2">Saatler sunucu saatine göredir.</p>
  </div>
</div>

<?php $heatMax = max(array_map('max', $heat)) ?: 1; ?>
<div class="card mt-4">
  <div class="card-title mb-3"><?= icon('clock') ?> Gün × saat <?= tip('Hangi gün ve saatte okutulduğunu gösterir; koyu hücre daha çok okutma demektir. Etkinlik saatlerini ve stand yoğunluğunu görmek için.') ?></div>
  <?php if (!$total): ?><p class="text-sm text-muted">Veri yok.</p><?php else: ?>
  <div class="heatmap" role="table" aria-label="Gün ve saate göre okutma">
    <div class="hm-row hm-head" role="row"><span></span><?php for ($h = 0; $h < 24; $h++): ?><span class="hm-h"><?= $h % 3 === 0 ? sprintf('%02d', $h) : '' ?></span><?php endfor; ?></div>
    <?php foreach (['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'] as $w => $dayName): ?>
      <div class="hm-row" role="row"><span class="hm-day"><?= ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'][$w] ?></span>
        <?php foreach ($heat[$w] as $h => $c): $a = $c ? 0.12 + 0.88 * $c / $heatMax : 0; ?>
          <span class="hm-cell" style="<?= $c ? '--a:' . round($a, 2) : '' ?>" title="<?= e($dayName) ?> <?= sprintf('%02d:00', $h) ?> — <?= $c ?> okutma"></span>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="text-xs text-muted mt-2">Saatler sunucu saatine göredir. En yoğun: <?php
    $bw = $bh = 0; foreach ($heat as $w => $row) foreach ($row as $h => $c) if ($c > $heat[$bw][$bh]) { $bw = $w; $bh = $h; }
    echo e(['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'][$bw]) . ' ' . sprintf('%02d:00-%02d:00', $bh, ($bh + 1) % 24) . ' (' . $heat[$bw][$bh] . ')'; ?></p>
  <?php endif; ?>
</div>

<?php if (!$link): ?>
<div class="report-grid mt-4">
  <div class="card" style="grid-column:1/-1">
    <div class="card-title mb-3"><?= icon('award') ?> QR bazında (ilk 25)</div>
    <div class="table-wrap"><table>
      <thead><tr><th>QR</th><th>Kategori</th><th>Okutma</th><th></th></tr></thead>
      <tbody>
      <?php if (!$perLink): ?><tr><td colspan="4" class="text-muted" style="padding:1.5rem;text-align:center">Bu aralıkta okutma yok.</td></tr><?php endif; ?>
      <?php foreach ($perLink as $r): ?>
        <tr>
          <td><span class="col-slug"><?= e($r['slug']) ?></span> <span class="text-muted text-sm"><?= e($r['title'] ?: '') ?></span></td>
          <td class="text-sm"><?= e($r['category'] ? (LinkService::CATEGORIES[$r['category']] ?? '') : '—') ?></td>
          <td class="fw-bold"><?= formatCount((int)$r['c']) ?></td>
          <td><a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/admin/report?id=<?= $r['id'] ?>&range=<?= e($range) ?><?= $range === 'custom' ? '&from=' . e($from) . '&to=' . e($to) : '' ?>">Rapor</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card"><div class="card-title mb-3"><?= icon('folder') ?> Kategoriye göre <?= tip('Kategori: QR’ın neye ait olduğu (tek seçim). Etiketler ise kesişen gruplardır; bir QR birden çok etikete girebilir.') ?></div><?php reportBars($perCat, $total); ?></div>
  <div class="card"><div class="card-title mb-3"><?= icon('tag') ?> Etikete göre <?= tip('Bir okutma, QR’ın her etiketine ayrı sayılır; bu yüzden etiket toplamları genel toplamı aşabilir. Birleşik rapor için etiket adına tıklayın.') ?></div>
    <?php if (!$perTag): ?>
      <p class="text-sm text-muted">Bu aralıkta etiketli QR’dan okutma yok. QR’lara etiket vererek ülke, etkinlik veya materyale göre karşılaştırma yapabilirsiniz.
        <a href="<?= BASE_URL ?>/admin/links?tag=__none">Etiketsizleri göster</a></p>
    <?php else: reportBars($perTag, $total, fn($k) => '<a href="' . BASE_URL . '/admin/report' . $qs(['tag' => $k]) . '">#' . e($k) . '</a>'); endif; ?>
  </div>
  <?php if ($perOwner): ?>
  <div class="card"><div class="card-title mb-3"><?= icon('users') ?> Sahibine göre <?= tip('QR’ı oluşturan kullanıcıya göre okutmalar. Eski QR’ların sahibi kayıtlı değildir.') ?></div>
    <div class="table-wrap"><table class="compact">
      <thead><tr><th>Kullanıcı</th><th>QR</th><th>Okutma</th><th>%</th></tr></thead>
      <tbody>
      <?php foreach ($perOwner as $o): ?>
        <tr><td><?= e($o['owner']) ?></td><td><?= $o['links'] ?></td><td class="fw-bold"><?= formatCount($o['total']) ?></td><td class="text-muted"><?= $total ? round($o['total'] / $total * 100) : 0 ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
(function () {
  document.addEventListener('DOMContentLoaded', () => window.drawScanChart('report-chart', <?= json_encode(array_keys($daily)) ?>, <?= json_encode(array_values($daily)) ?>,
    { prev: <?= json_encode($prevDaily) ?>, markers: <?= json_encode($markers) ?> }));
  document.getElementById('range-sel').addEventListener('change', function () { if (this.value !== 'custom') this.form.submit(); });
})();
</script>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
