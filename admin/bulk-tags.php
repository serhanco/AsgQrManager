<?php
/**
 * bulk-tags.php — Liste sayfasında seçilen QR'lara toplu etiket ekle / çıkar (POST).
 */
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();
Auth::requirePerm('links.edit');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/admin/links');
Auth::requireCsrf();

$back = (string)($_POST['back'] ?? '');
$back = (str_starts_with($back, BASE_PATH . '/admin/links') && !str_contains($back, "\n")) ? $back : BASE_PATH . '/admin/links';
$target = preg_replace('#^' . preg_quote(BASE_PATH, '#') . '#', BASE_URL, $back);

$ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
$add = array_filter(array_map('trim', preg_split('/[,;]+/', (string)($_POST['add'] ?? ''))), fn($t) => $t !== '');
$rm  = array_filter(array_map('trim', preg_split('/[,;]+/', (string)($_POST['remove'] ?? ''))), fn($t) => $t !== '');

if (!$ids) {
    flash('warning', 'Önce listeden en az bir QR seçin.');
} elseif (!$add && !$rm) {
    flash('warning', 'Eklenecek veya çıkarılacak bir etiket yazın.');
} elseif (count($ids) > 500) {
    flash('danger', 'Tek seferde en fazla 500 QR işlenebilir.');
} else {
    // Yalnızca kullanıcının düzenleyebildiği QR'lar
    $pdo = getPdo();
    $ids = array_values(array_filter($ids, fn($id) => ($l = LinkService::find($pdo, $id)) && Access::canEditLink($l)));
    $n = $ids ? LinkService::bulkTags($pdo, $ids, $add, $rm, Auth::actor()) : 0;
    $parts = [];
    if ($add) $parts[] = count($add) . ' etiket eklendi';
    if ($rm)  $parts[] = count($rm) . ' etiket çıkarıldı';
    flash('success', implode(', ', $parts) . " — {$n} QR güncellendi" . ($n < count($ids) ? ' (diğerlerinde değişiklik gerekmedi)' : '') . '.');
}
redirect($target);
