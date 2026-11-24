<?php
/**
 * share-permanent.php — Seçilen QR'lar için süresiz (sabit) paylaşım bağlantısını açar / iptal eder (POST).
 * Tekli: id, toplu: ids[]; mode = on | off. QR'ın kendisine (kısa ad, hedef, okutma) dokunulmaz.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();
Auth::requirePerm('links.edit');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/admin/links');
Auth::requireCsrf();

$back = (string)($_POST['back'] ?? '');
$okBack = !str_contains($back, "\n") && array_filter(['/admin/links', '/admin/qr-view'], fn($p) => str_starts_with($back, BASE_PATH . $p));
$target = $okBack ? BASE_URL . substr($back, strlen(BASE_PATH)) : BASE_URL . '/admin/links';

$on  = ($_POST['mode'] ?? 'on') !== 'off';
$ids = (array)($_POST['ids'] ?? []);
if (isset($_POST['id'])) $ids[] = $_POST['id'];
$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
$pdo = getPdo();

if (!Share::permanentAvailable($pdo)) {
    flash('danger', 'Veritabanı güncellemesi bekleniyor; çıkış yapıp yeniden giriş yapın.');
} elseif (!$ids) {
    flash('warning', 'Önce listeden en az bir QR seçin.');
} elseif (count($ids) > 500) {
    flash('danger', 'Tek seferde en fazla 500 QR işlenebilir.');
} else {
    $n = 0; $skipped = 0; $already = 0;
    foreach ($ids as $id) {
        $l = LinkService::find($pdo, $id);
        if (!$l || !Access::canEditLink($l) || !empty($l['archived_at'])) { $skipped++; continue; }
        $changed = $on ? Share::enablePermanent($pdo, $l, Auth::actor()) : Share::disablePermanent($pdo, $l, Auth::actor());
        $changed ? $n++ : $already++;
    }
    if ($on) {
        $msg = $n ? "{$n} QR için süresiz paylaşım bağlantısı açıldı. Bağlantıyı QR'ın sayfasında (QR / İndir / Paylaş) veya CSV dışa aktarımında bulabilirsiniz." : 'Yeni bağlantı açılmadı.';
        if ($already) $msg .= " {$already} QR'da zaten açıktı (bağlantısı değişmedi).";
    } else {
        $msg = $n ? "{$n} QR'ın süresiz paylaşım bağlantısı iptal edildi; daha önce gönderilen bağlantı artık çalışmaz." : 'İptal edilecek süresiz bağlantı yoktu.';
    }
    if ($skipped) $msg .= " {$skipped} QR atlandı (yetki yok veya arşivde).";
    flash($n ? 'success' : 'warning', $msg);
}
redirect($target);
