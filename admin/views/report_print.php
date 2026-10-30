<?php
/**
 * report_print.php — yönetime gönderilecek antetli rapor (A4, yazdır / PDF olarak kaydet).
 * report.php içinden $R ile çağrılır; panel düzeni ve JavaScript grafikleri kullanılmaz.
 */
if (!defined('ROOT_DIR')) exit;
$pct = fn(int $v) => $R['total'] > 0 ? round($v / $R['total'] * 100) : 0;
$table = function (string $title, array $data, string $label, ?callable $fmt = null, int $limit = 8) use ($pct): void {
    echo '<section class="box"><h3>' . e($title) . '</h3>';
    if (!$data) { echo '<p class="muted">Veri yok.</p></section>'; return; }
    echo '<table><thead><tr><th>' . e($label) . '</th><th class="n">Okutma</th><th class="n">%</th></tr></thead><tbody>';
    foreach (array_slice($data, 0, $limit, true) as $k => $v) {
        echo '<tr><td>' . e($fmt ? $fmt((string)$k) : (string)$k) . '</td><td class="n">' . formatCount((int)$v) . '</td><td class="n">' . $pct((int)$v) . '</td></tr>';
    }
    echo '</tbody></table></section>';
};
$delta = function (int $now, ?int $prev): string {
    $t = ReportExport::changeText($now, $prev);
    if ($t === '') return $prev !== null ? '<div class="sub">önceki dönem: ' . formatCount($prev) . '</div>' : '';
    $cls = $t[0] === '+' ? 'up' : ($t[0] === '-' ? 'down' : '');
    return '<div class="sub"><b class="' . $cls . '">' . e($t) . '</b> önceki döneme göre (' . formatCount($prev) . ')</div>';
};
$peak = ReportExport::peak($R['heat']);
$heatMax = max(array_map('max', $R['heat'])) ?: 1;
?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e('QR raporu — ' . $R['scope'] . ' — ' . $R['from'] . '_' . $R['to']) ?></title>
<style>
@font-face { font-family: 'Plus Jakarta Sans'; font-weight: 400 800; src: url(<?= BASE_URL ?>/assets/fonts/plus-jakarta-sans-latin-ext.woff2) format('woff2');
  unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1E00-1E9F; }
@font-face { font-family: 'Plus Jakarta Sans'; font-weight: 400 800; src: url(<?= BASE_URL ?>/assets/fonts/plus-jakarta-sans-latin.woff2) format('woff2');
  unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+2000-206F, U+20AC, U+2122, U+2212; }
@page { size: A4; margin: 14mm 12mm; }
* { box-sizing: border-box; }
body { margin: 0; background: #EEF1F7; color: #0E1838; font: 12px/1.45 'Plus Jakarta Sans', Arial, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.page { max-width: 210mm; margin: 16px auto; background: #fff; padding: 14mm 12mm; box-shadow: 0 6px 24px rgba(16,24,56,.12); }
.toolbar { max-width: 210mm; margin: 16px auto 0; display: flex; gap: 8px; justify-content: flex-end; padding: 0 4px; }
.toolbar button, .toolbar a { font: inherit; font-weight: 700; padding: 8px 14px; border-radius: 8px; border: 1px solid #CDD5E5; background: #fff; color: #1C2561; cursor: pointer; text-decoration: none; }
.toolbar button { background: #1F4BA8; border-color: #1F4BA8; color: #fff; }
header.head { display: flex; align-items: center; gap: 12px; padding-bottom: 10px; border-bottom: 3px solid #1C2561; }
header.head img { width: 40px; height: 40px; }
.brand b { display: block; letter-spacing: .28em; font-size: 11px; color: #1C2561; }
.brand span { font-size: 15px; font-weight: 700; color: #0A3A86; }
.meta { margin-left: auto; text-align: right; font-size: 10.5px; color: #5D6886; }
h1 { font-size: 20px; margin: 14px 0 2px; color: #1C2561; }
h2 { font-size: 13px; margin: 18px 0 8px; color: #1C2561; text-transform: uppercase; letter-spacing: .06em; }
h3 { font-size: 12px; margin: 0 0 6px; color: #1C2561; }
.muted, .sub { color: #5D6886; font-size: 10.5px; }
.kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-top: 12px; }
.kpi { border: 1px solid #E2E7F1; border-radius: 10px; padding: 10px; }
.kpi .v { font-size: 20px; font-weight: 800; color: #1C2561; }
.kpi .l { font-size: 10.5px; color: #5D6886; }
.up { color: #15803D; } .down { color: #DC2626; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.box { border: 1px solid #E2E7F1; border-radius: 10px; padding: 10px; break-inside: avoid; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 3px 4px; border-bottom: 1px solid #EEF1F7; vertical-align: top; }
th { font-size: 10px; color: #5D6886; text-transform: uppercase; letter-spacing: .04em; }
.n { text-align: right; white-space: nowrap; }
.heat td { padding: 0; border: 0; } .heat td span { display: block; height: 11px; margin: 1px; border-radius: 2px; background: #EEF1F7; }
.heat th { font-size: 8.5px; padding: 0 1px; text-transform: none; letter-spacing: 0; }
.legend { display: flex; gap: 14px; font-size: 10.5px; color: #5D6886; margin-top: 4px; }
.legend i { display: inline-block; width: 16px; border-top: 2px solid #1F4BA8; vertical-align: middle; margin-right: 4px; }
.legend i.d { border-top: 2px dashed #5D6886; } .legend i.m { border-top: 2px dashed #B45309; }
.changes li { margin-bottom: 3px; overflow-wrap: anywhere; }
footer { margin-top: 16px; padding-top: 8px; border-top: 1px solid #E2E7F1; font-size: 9.5px; color: #5D6886; }
@media (max-width: 640px) { .page { padding: 16px; margin: 8px; } .kpis, .grid { grid-template-columns: 1fr 1fr; } .grid { grid-template-columns: 1fr; } .heatwrap { overflow-x: auto; } }
@media print { body { background: #fff; } .page { margin: 0; padding: 0; box-shadow: none; max-width: none; } .toolbar { display: none; } h2 { break-after: avoid; } }
</style>
</head>
<body>
<div class="toolbar">
  <a href="javascript:history.back()">Geri</a>
  <button type="button" onclick="window.print()">Yazdır / PDF olarak kaydet</button>
</div>
<main class="page">
  <header class="head">
    <img src="<?= BASE_URL ?>/assets/icon.svg" alt="">
    <div class="brand"><b>ACIBADEM</b><span>QR Code Manager</span></div>
    <div class="meta">Okutma raporu<br><?= e($R['at']) ?><?= $R['by'] !== '' ? ' · ' . e($R['by']) : '' ?></div>
  </header>

  <h1><?= e($R['scope']) ?></h1>
  <div class="muted"><?= e(date('d.m.Y', strtotime($R['from']))) ?> – <?= e(date('d.m.Y', strtotime($R['to']))) ?>
    <?php if ($R['prevTotal'] !== null): ?> · önceki dönem <?= e(date('d.m.Y', strtotime($R['prevFrom']))) ?> – <?= e(date('d.m.Y', strtotime($R['prevTo']))) ?><?php endif; ?></div>

  <div class="kpis">
    <div class="kpi"><div class="l">Okutma</div><div class="v"><?= formatCount($R['total']) ?></div><?= $delta($R['total'], $R['prevTotal']) ?></div>
    <div class="kpi"><div class="l">Tahmini tekil ziyaretçi</div><div class="v"><?= formatCount($R['unique']) ?></div><?= $delta($R['unique'], $R['prevUnique']) ?></div>
    <div class="kpi"><div class="l">En yoğun zaman</div><div class="v" style="font-size:14px"><?= $peak ? e($peak[0]) . '<br>' . sprintf('%02d:00-%02d:00', $peak[1], ($peak[1] + 1) % 24) : '—' ?></div></div>
    <div class="kpi"><div class="l">Mobil cihaz</div><div class="v"><?= $R['total'] ? '%' . $pct(($R['devices']['Mobil'] ?? 0) + ($R['devices']['Tablet'] ?? 0)) : '—' ?></div>
      <div class="sub">Bot/önizleme <?= (int)$R['bots'] ?>, yedek hedef <?= (int)$R['redirected'] ?> (sayılmadı)</div></div>
  </div>

  <h2>Günlük okutma</h2>
  <?= ReportExport::chartSvg($R['daily'], $R['prevDaily'], $R['markers']) ?>
  <div class="legend"><span><i></i>Bu dönem</span><?php if ($R['prevDaily']): ?><span><i class="d"></i>Önceki dönem</span><?php endif; ?><?php if ($R['markers']): ?><span><i class="m"></i>Hedef değişti</span><?php endif; ?></div>
  <?php if ($R['changes']): ?>
  <ul class="changes muted">
    <?php foreach ($R['changes'] as $c): ?><li><?= e(date('d.m.Y H:i', strtotime($c['at']))) ?> hedef değişti<?= $c['user'] ? ' (' . e($c['user']) . ')' : '' ?>: <?= e((string)$c['from']) ?> → <?= e((string)$c['to']) ?></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <?php if ($R['links']): ?>
  <h2>En çok okutulan QR'lar</h2>
  <section class="box"><table>
    <thead><tr><th>QR</th><th>Kategori</th><th class="n">Okutma</th><th class="n">Tekil</th><th class="n">%</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($R['links'], 0, 15) as $l): ?>
      <tr><td><b><?= e($l['slug']) ?></b> <?= e((string)$l['title']) ?></td><td><?= e($l['category'] ? (LinkService::CATEGORIES[$l['category']] ?? '') : '—') ?></td>
        <td class="n"><?= formatCount((int)$l['c']) ?></td><td class="n"><?= formatCount((int)$l['u']) ?></td><td class="n"><?= $pct((int)$l['c']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table><?php if (count($R['links']) > 15): ?><p class="muted">İlk 15 gösteriliyor; tamamı Excel raporunda (<?= count($R['links']) ?> QR).</p><?php endif; ?></section>
  <?php endif; ?>

  <h2>Dağılımlar</h2>
  <div class="grid">
    <?php if ($R['categories']) $table('Kategoriye göre', $R['categories'], 'Kategori'); ?>
    <?php if ($R['tags']) $table('Etikete göre', $R['tags'], 'Etiket', fn($k) => '#' . $k); ?>
    <?php if ($R['owners']): ?>
      <section class="box"><h3>Sahibine göre</h3><table><thead><tr><th>Kullanıcı</th><th class="n">QR</th><th class="n">Okutma</th><th class="n">%</th></tr></thead><tbody>
        <?php foreach (array_slice($R['owners'], 0, 8) as $o): ?><tr><td><?= e($o['owner']) ?></td><td class="n"><?= $o['links'] ?></td><td class="n"><?= formatCount($o['total']) ?></td><td class="n"><?= $pct($o['total']) ?></td></tr><?php endforeach; ?>
      </tbody></table></section>
    <?php endif; ?>
    <?php $table('Ülke', $R['countries'], 'Ülke', fn($k) => $k === '??' ? 'Bilinmiyor' : $k); ?>
    <?php $table('Cihaz', $R['devices'], 'Cihaz'); ?>
    <?php $table('İşletim sistemi', $R['os'], 'Sistem'); ?>
    <?php $table('Kaynak', $R['referrers'], 'Kaynak', null, 6); ?>
  </div>

  <h2>Gün × saat</h2>
  <div class="heatwrap"><table class="heat">
    <thead><tr><th></th><?php for ($h = 0; $h < 24; $h++): ?><th><?= $h % 3 === 0 ? sprintf('%02d', $h) : '' ?></th><?php endfor; ?></tr></thead>
    <tbody>
    <?php foreach ($R['heat'] as $w => $row): ?>
      <tr><th style="text-align:left;padding-right:6px"><?= e(ReportExport::DAYS[$w]) ?></th>
        <?php foreach ($row as $c): ?><td><span<?= $c ? ' style="background:rgba(31,75,168,' . round(0.12 + 0.88 * $c / $heatMax, 2) . ')"' : '' ?> title="<?= $c ?>"></span></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <footer>Bot önizlemeleri ve pasif/yedek yönlendirmeler okutma sayılmaz. Tekil ziyaretçi IP ve tarayıcı bilgisinden tahmin edilir. Saatler sunucu saatine göredir.
    Acıbadem QR Code Manager · <?= e(BASE_URL) ?></footer>
</main>
</body>
</html>
