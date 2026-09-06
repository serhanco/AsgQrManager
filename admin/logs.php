<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Logger.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/helpers.php';

Logger::registerHandlers();
Auth::startSession();
Auth::requireLogin();

// Log türü: 'scans' | 'app'
$type    = in_array($_GET['type'] ?? 'scans', ['scans', 'app']) ? $_GET['type'] : 'scans';
$baseDir = $type === 'scans' ? LOG_SCAN_DIR : LOG_APP_DIR;

// Dosya listesi
$tree    = Logger::listLogFiles($baseDir);

// Seçili yıl/ay/dosya
$selYear = preg_replace('/\D/', '', $_GET['year'] ?? '');
$selMon  = preg_replace('/\D/', '', $_GET['mon']  ?? '');
$selFile = basename($_GET['file'] ?? '');

$logContent = null;
$logError   = null;
$fileSize   = null;

if ($selYear && $selMon && $selFile) {
    $relPath    = "{$selYear}/{$selMon}/{$selFile}";
    $fullPath   = $baseDir . '/' . $relPath;
    $realBase   = realpath($baseDir);
    $realFull   = realpath($fullPath);

    // Path traversal koruması (Logger::readLogFile zaten yapar ama burada da gösterim)
    if ($realFull && $realBase && str_starts_with($realFull, $realBase)) {
        $fileSize   = is_file($realFull) ? filesize($realFull) : 0;
        $logContent = Logger::readLogFile($baseDir, $relPath);
        if ($logContent === null) $logError = 'Dosya okunamadı veya izin reddedildi.';
    } else {
        $logError = 'Geçersiz dosya yolu.';
    }
}

$pageTitle  = 'Log Görüntüleyici';
$activePage = 'logs';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title">📋 Log Görüntüleyici</h1>
  <div class="btn-group">
    <a href="?type=scans" class="btn <?= $type==='scans' ? 'btn-primary' : 'btn-ghost' ?> btn-sm">
      📊 Tarama Logları
    </a>
    <a href="?type=app" class="btn <?= $type==='app' ? 'btn-primary' : 'btn-ghost' ?> btn-sm">
      ⚠️ Uygulama Logları
    </a>
  </div>
</div>

<div style="display:grid;grid-template-columns:220px 1fr;gap:1.25rem;align-items:start">

<!-- Sol: Dosya ağacı -->
<div class="card" style="padding:1rem">
  <div class="card-title mb-3 text-sm">📁 Dosyalar</div>
  <?php if (empty($tree)): ?>
    <p class="text-sm text-muted">Henüz log yok.</p>
  <?php else: ?>
    <?php foreach ($tree as $year => $months): ?>
      <div style="margin-bottom:.5rem">
        <div class="fw-bold text-sm mb-1">📅 <?= e($year) ?></div>
        <?php foreach ($months as $mon => $files): ?>
          <div style="margin-left:.75rem;margin-bottom:.25rem">
            <div class="text-muted text-xs mb-1">📂 <?= e($year) ?>-<?= e($mon) ?></div>
            <?php foreach ($files as $file): ?>
              <?php
                $isActive = ($selYear==$year && $selMon==$mon && $selFile==$file);
                $href = '?type=' . $type . '&year=' . $year . '&mon=' . $mon . '&file=' . urlencode($file);
              ?>
              <a href="<?= $href ?>"
                 class="text-xs d-block"
                 style="padding:.2rem .5rem;border-radius:4px;<?= $isActive ? 'background:var(--accent);color:#fff;text-decoration:none' : 'color:var(--text-muted)' ?>">
                <?= e($file) ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Sağ: İçerik -->
<div>
  <?php if ($logError): ?>
    <div class="alert alert-danger"><?= e($logError) ?></div>
  <?php elseif ($logContent !== null): ?>
    <div class="card" style="padding:1rem">
      <div class="card-header" style="margin-bottom:.75rem">
        <span class="card-title text-sm">
          <?= e("{$selYear}/{$selMon}/{$selFile}") ?>
          <span class="text-muted text-xs">(<?= $fileSize ? number_format($fileSize/1024, 1) . ' KB' : '0 KB' ?>)</span>
        </span>
        <div class="btn-group">
          <input class="form-control form-control" type="search" id="log-search"
                 placeholder="Filtre…" style="max-width:180px;padding:.3rem .6rem;font-size:.8rem">
          <a href="<?= BASE_URL ?>/admin/log-download.php?type=<?= $type ?>&year=<?= $selYear ?>&mon=<?= $selMon ?>&file=<?= urlencode($selFile) ?>"
             class="btn btn-ghost btn-sm">⬇️ İndir</a>
        </div>
      </div>
      <pre class="log-viewer"><?= htmlspecialchars($logContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
      <?php if ($fileSize > 524288): ?>
        <p class="text-xs text-muted mt-2">⚠️ Dosya büyük; yalnızca son 512 KB gösteriliyor.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="card" style="display:flex;align-items:center;justify-content:center;min-height:200px;color:var(--text-muted)">
      <div style="text-align:center">
        <div style="font-size:3rem;margin-bottom:1rem">📋</div>
        <p>Soldaki listeden bir dosya seçin.</p>
      </div>
    </div>
  <?php endif; ?>
</div>

</div>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
