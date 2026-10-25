<?php
/**
 * bulk.php — CSV ile toplu QR oluşturma (kongre, ofis listesi vb.)
 *
 * Akış: CSV yükle / yapıştır → "Doğrula" (hiçbir şey kaydedilmez) → "Oluştur".
 * Oluşturulan QR'ların SVG + PNG + PDF dosyaları tek ZIP olarak indirilebilir.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();
Auth::requirePerm('links.edit');

const BULK_MAX_ROWS = 500;
const BULK_COLUMNS  = ['target_url', 'title', 'slug', 'category', 'tags', 'notes', 'starts_at', 'ends_at', 'fallback_url'];

$pdo = getPdo();

// ─── Örnek şablon ─────────────────────────────────────────────────────────────
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="toplu-qr-sablon.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, BULK_COLUMNS, ';');
    fputcsv($out, ['https://acibadem.world/ofisler/istanbul', 'İstanbul Ofisi', 'ofis-ist', 'office', 'ofis,istanbul', '', '', '', ''], ';');
    fputcsv($out, ['https://acibadem.world/etkinlikler/kongre-2026', 'Kongre 2026', '', 'event', 'kongre', 'Stant girişi', '2026-11-01 08:00', '2026-11-05 18:00', 'https://acibadem.world'], ';');
    fclose($out);
    exit;
}

// ─── Son toplu işlemin ZIP'i ──────────────────────────────────────────────────
if (isset($_GET['zip'])) {
    $slugs = $_SESSION['bulk_slugs'] ?? [];
    if (!$slugs || !class_exists('ZipArchive')) {
        flash('danger', !$slugs ? 'İndirilecek toplu işlem bulunamadı.' : 'Sunucuda ZIP desteği (ZipArchive) yok.');
        redirect(BASE_URL . '/admin/bulk');
    }
    @set_time_limit(300);
    $tmp = tempnam(sys_get_temp_dir(), 'qrbulk');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $index = "slug;baslik;kisa_adres;hedef\r\n";
    foreach ($slugs as $slug) {
        $link = LinkService::findBySlug($pdo, $slug);
        if (!$link) continue;
        $dir = Share::fileBase($link) . '/';
        try {
            foreach ([['svg', 0], ['pdf', 100], ['eps', 100], ['png', 1200]] as [$fmt, $size]) {
                $r = Share::render($link, $fmt, $size);
                $zip->addFromString($dir . $r['name'], $r['data']);
            }
        } catch (\Throwable $e) {
            Logger::warning("Toplu ZIP: {$slug} atlandı: " . $e->getMessage());
        }
        $index .= implode(';', [$slug, str_replace(';', ',', (string)csvCell((string)$link['title'])), BASE_URL . '/r/' . $slug, $link['target_url']]) . "\r\n";
    }
    $zip->addFromString('liste.csv', "\xEF\xBB\xBF" . $index);
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="toplu-qr-' . date('Ymd-His') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

// ─── CSV ayrıştırma ───────────────────────────────────────────────────────────
/** @return array{0:array[],1:string[]} [satırlar (başlık anahtarlı), hatalar] */
function bulkParse(string $text): array {
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($text === '') return [[], ['CSV boş.']];

    $first = strtok($text, "\n");
    $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');

    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    $header = fgetcsv($fh, 0, $delim, '"', '');
    $header = array_map(fn($h) => strtolower(trim((string)$h)), $header ?: []);
    if (!in_array('target_url', $header, true)) {
        // Başlık satırı yoksa: tek sütun URL listesi kabul et
        if (count($header) >= 1 && preg_match('#^https?://#i', $header[0] ?? '')) {
            rewind($fh);
            $header = ['target_url', 'title'];
        } else {
            return [[], ['İlk satır başlık olmalı ve "target_url" sütununu içermeli. Şablonu indirip kullanabilirsiniz.']];
        }
    }
    $rows = [];
    $errors = [];
    $n = 1;
    while (($cells = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        $n++;
        if ($cells === [null] || count(array_filter($cells, fn($c) => trim((string)$c) !== '')) === 0) continue;
        $row = ['_line' => $n];
        foreach ($header as $i => $h) {
            if (in_array($h, BULK_COLUMNS, true)) $row[$h] = trim((string)($cells[$i] ?? ''));
        }
        $rows[] = $row;
        if (count($rows) > BULK_MAX_ROWS) {
            $errors[] = 'En fazla ' . BULK_MAX_ROWS . ' satır işlenebilir; dosyayı bölün.';
            array_pop($rows);
            break;
        }
    }
    fclose($fh);
    if (!$rows && !$errors) $errors[] = 'Veri satırı bulunamadı.';
    return [$rows, $errors];
}

// ─── İşleme ───────────────────────────────────────────────────────────────────
$results   = null;
$fatal     = [];
$dryRun    = true;
$csvText   = '';
$defaults  = ['category' => '', 'tags' => '', 'logo' => Settings::get('brand_logo')];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $dryRun = ($_POST['mode'] ?? 'validate') !== 'create';
    $csvText = (string)($_POST['csv_text'] ?? '');
    if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        if ($_FILES['csv_file']['size'] > 1024 * 1024) $fatal[] = 'CSV dosyası en fazla 1 MB olabilir.';
        else $csvText = (string)file_get_contents($_FILES['csv_file']['tmp_name']);
    }
    // Excel (Windows-1254) ile kaydedilmiş dosyaları UTF-8'e çevir
    if ($csvText !== '' && !mb_check_encoding($csvText, 'UTF-8')) {
        $csvText = mb_convert_encoding($csvText, 'UTF-8', 'Windows-1254');
    }
    $defaults['category'] = (string)($_POST['default_category'] ?? '');
    $defaults['tags']     = (string)($_POST['default_tags'] ?? '');
    $defaults['logo']     = !empty($_POST['use_logo']) ? Settings::get('brand_logo') : '';

    if (!$fatal) {
        [$rows, $perr] = bulkParse($csvText);
        $fatal = array_merge($fatal, $perr);
    }

    if (!$fatal) {
        $results = [];
        $seenSlugs = [];
        $created = [];
        $actor = Auth::actor();
        foreach ($rows as $row) {
            $in = [
                'target_url' => $row['target_url'] ?? '',
                'title'      => $row['title'] ?? '',
                'category'   => ($row['category'] ?? '') !== '' ? $row['category'] : $defaults['category'],
                'tags'       => trim(($row['tags'] ?? '') . ',' . $defaults['tags'], ','),
                'notes'      => $row['notes'] ?? '',
                'fallback_url' => $row['fallback_url'] ?? '',
                'starts_at'  => $row['starts_at'] ?? '',
                'ends_at'    => $row['ends_at'] ?? '',
                'is_active'  => 1,
            ];
            if ($defaults['logo'] !== '') $in['logo'] = $defaults['logo'];
            $slug = sanitizeCustomSlug($row['slug'] ?? '');

            $res = ['line' => $row['_line'], 'title' => $in['title'] !== '' ? $in['title'] : $in['target_url'], 'slug' => $slug, 'status' => 'ok', 'msg' => ''];
            [$clean, $errs] = LinkService::normalize($in);
            if ($slug !== '') {
                if (isset($seenSlugs[$slug])) $errs[] = "'{$slug}' dosyada birden fazla kez geçiyor.";
                $seenSlugs[$slug] = true;
                if (!$errs) {
                    $chk = $pdo->prepare('SELECT id FROM links WHERE slug = ? LIMIT 1');
                    $chk->execute([$slug]);
                    if ($chk->fetch()) $errs[] = "'{$slug}' kısa adı zaten kullanılıyor.";
                }
            }
            if ($errs) {
                $res['status'] = 'error';
                $res['msg'] = implode(' ', $errs);
            } elseif (!$dryRun) {
                try {
                    $link = LinkService::create($pdo, $clean, $slug !== '' ? $slug : null, $actor);
                    $res['slug'] = $link['slug'];
                    $res['msg']  = BASE_URL . '/r/' . $link['slug'];
                    $created[] = $link['slug'];
                } catch (\Throwable $e) {
                    $res['status'] = 'error';
                    $res['msg'] = $e instanceof RuntimeException ? $e->getMessage() : 'Kaydedilemedi.';
                    if (!($e instanceof RuntimeException)) Logger::error('Toplu oluşturma hatası: ' . $e->getMessage());
                }
            } else {
                $res['msg'] = $slug !== '' ? 'Oluşturulabilir' : 'Oluşturulabilir (kısa ad otomatik verilecek)';
            }
            $results[] = $res;
        }
        if (!$dryRun) {
            $_SESSION['bulk_slugs'] = $created;
            if ($created) Logger::info('Toplu oluşturma: ' . count($created) . ' link by=' . $actor['name']);
        }
    }
}

$okCount  = $results ? count(array_filter($results, fn($r) => $r['status'] === 'ok')) : 0;
$errCount = $results ? count($results) - $okCount : 0;

$pageTitle  = 'Toplu QR Oluştur';
$activePage = 'bulk';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title"><?= icon('layers') ?> Toplu QR Oluştur</h1>
  <div class="btn-group">
    <a href="<?= BASE_URL ?>/admin/bulk?template=1" class="btn btn-ghost"><?= icon('download') ?> CSV şablonu</a>
    <a href="<?= BASE_URL ?>/admin/links" class="btn btn-ghost"><?= icon('arrow-left') ?> Linkler</a>
  </div>
</div>

<?php foreach ($fatal as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

<?php if ($results !== null): ?>
<div class="card mb-4">
  <div class="card-header">
    <span class="card-title"><?= $dryRun ? icon('search') . ' Doğrulama sonucu (henüz kaydedilmedi)' : icon('check-circle') . ' Toplu oluşturma tamamlandı' ?></span>
    <span class="text-sm">
      <span class="badge badge-success"><?= $okCount ?> <?= $dryRun ? 'uygun' : 'oluşturuldu' ?></span>
      <?php if ($errCount): ?><span class="badge badge-danger"><?= $errCount ?> hatalı</span><?php endif; ?>
    </span>
  </div>
  <?php if (!$dryRun && $okCount && class_exists('ZipArchive')): ?>
    <p style="margin:.5rem 0 1rem"><a class="btn btn-primary" href="<?= BASE_URL ?>/admin/bulk?zip=1"><?= icon('package') ?> Tüm QR'ları indir (ZIP: SVG + PDF + PNG)</a></p>
  <?php endif; ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Satır</th><th>Başlık / URL</th><th>Kısa ad</th><th>Sonuç</th></tr></thead>
      <tbody>
      <?php foreach ($results as $r): ?>
        <tr>
          <td class="text-muted"><?= (int)$r['line'] ?></td>
          <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($r['title']) ?></td>
          <td class="col-slug"><?= e($r['slug'] ?: '—') ?></td>
          <td>
            <?php if ($r['status'] === 'ok'): ?>
              <span class="badge badge-success">✓</span>
              <?php if (!$dryRun && $r['slug']): ?>
                <a href="<?= BASE_URL ?>/admin/qr-view?slug=<?= urlencode($r['slug']) ?>"><?= e($r['msg']) ?></a>
              <?php else: ?><span class="text-sm"><?= e($r['msg']) ?></span><?php endif; ?>
            <?php else: ?>
              <span class="badge badge-danger">✗</span> <span class="text-sm"><?= e($r['msg']) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><span class="card-title">CSV yükle veya yapıştır</span></div>
  <p class="text-sm text-muted" style="margin-bottom:1rem">
    Sütunlar: <code><?= e(implode(', ', BULK_COLUMNS)) ?></code> — yalnızca <code>target_url</code> zorunludur.
    Ayraç virgül, noktalı virgül veya sekme olabilir; Excel'den "CSV" olarak kaydedebilirsiniz.
    Tarihler <code>2026-11-01 08:00</code> biçiminde yazılır. En fazla <?= BULK_MAX_ROWS ?> satır.
    Önce <strong>Doğrula</strong> ile kontrol edin; hiçbir kayıt oluşturulmaz.
  </p>
  <form method="POST" enctype="multipart/form-data">
    <?= Auth::csrfField() ?>
    <div class="form-group">
      <label class="form-label">CSV dosyası</label>
      <input class="form-control" type="file" name="csv_file" accept=".csv,.txt,text/csv">
    </div>
    <div class="form-group">
      <label class="form-label">veya yapıştırın</label>
      <textarea class="form-control" name="csv_text" rows="8" placeholder="target_url;title;slug&#10;https://acibadem.world/ofisler/istanbul;İstanbul Ofisi;ofis-ist"><?= e($csvText) ?></textarea>
    </div>
    <div class="form-row" style="display:flex;gap:1rem;flex-wrap:wrap">
      <div class="form-group" style="flex:1;min-width:180px">
        <label class="form-label">Varsayılan tür (CSV'de boşsa)</label>
        <select class="form-control" name="default_category">
          <option value="">—</option>
          <?php foreach (LinkService::CATEGORIES as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= $defaults['category'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:1;min-width:180px">
        <label class="form-label">Tüm satırlara etiket ekle</label>
        <input class="form-control" type="text" name="default_tags" value="<?= e($defaults['tags']) ?>" placeholder="kongre-2026">
      </div>
    </div>
    <?php if (Settings::get('brand_logo')): ?>
    <label style="display:flex;gap:.5rem;align-items:center;margin-bottom:1rem">
      <input type="checkbox" name="use_logo" value="1" checked> Marka logosunu QR ortasına yerleştir
    </label>
    <?php endif; ?>
    <div class="btn-group">
      <button class="btn btn-ghost" type="submit" name="mode" value="validate"><?= icon('search') ?> Doğrula</button>
      <button class="btn btn-primary" type="submit" name="mode" value="create"
              onclick="return confirm('Geçerli satırlar için QR oluşturulsun mu? Hatalı satırlar atlanır.')"><?= icon('sparkles') ?> Oluştur</button>
    </div>
  </form>
</div>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
