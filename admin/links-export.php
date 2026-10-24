<?php
/**
 * links-export.php — Link envanterini CSV olarak indirir (arşiv / yedek / raporlama).
 * links.php ile aynı filtre parametrelerini kabul eder.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();

$pdo = getPdo();
[$where, $params] = LinkService::buildFilter([
    'q' => $_GET['q'] ?? '', 'status' => $_GET['status'] ?? '', 'category' => $_GET['category'] ?? '', 'tag' => $_GET['tag'] ?? '',
]);
$st = $pdo->prepare("SELECT * FROM links {$where} ORDER BY id");
$st->execute($params);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="qr-linkler-' . date('Ymd-His') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // Excel için UTF-8 BOM
fputcsv($out, ['slug', 'kisa_url', 'baslik', 'hedef_url', 'durum', 'tur', 'etiketler', 'okutma', 'baslangic', 'bitis',
               'bitis_sonrasi_url', 'yedek_url', 'utm', 'olusturma', 'guncelleme', 'not'], ';');
foreach ($st as $l) {
    $label = ['live' => 'aktif', 'inactive' => 'pasif', 'scheduled' => 'zamanlı', 'expired' => 'süresi dolmuş', 'archived' => 'arşiv'];
    fputcsv($out, array_map('csvCell', [
        $l['slug'], BASE_URL . '/r/' . $l['slug'], $l['title'], $l['target_url'],
        $label[Redirector::state($l)['state']] ?? '', $l['category'] ?? '', $l['tags'] ?? '', $l['scan_count'],
        $l['starts_at'] ?? '', $l['ends_at'] ?? '', $l['after_end_url'] ?? '', $l['fallback_url'] ?? '',
        !empty($l['utm_enabled']) ? 'evet' : '', $l['created_at'], $l['updated_at'], $l['notes'] ?? '',
    ]), ';');
}
fclose($out);
