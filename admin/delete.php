<?php
/**
 * delete.php — QR'lar SİLİNMEZ, arşivlenir.
 * Kalıcı silme yalnızca arşivlenmiş ve hiç okutulmamış kayıtlar için (purge=1).
 */
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();
Auth::requirePerm('links.archive');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/admin/links');
}
Auth::requireCsrf();

$pdo  = getPdo();
$link = Auth::requireLinkAccess(LinkService::find($pdo, (int)($_POST['id'] ?? 0)));

if (!$link) {
    flash('danger', 'Link bulunamadı.');
} elseif (!empty($_POST['purge'])) {
    if (LinkService::purge($pdo, $link, Auth::actor())) {
        flash('success', 'Kayıt kalıcı olarak silindi.');
    } else {
        flash('danger', 'Kalıcı silme yapılamadı: yalnızca arşivlenmiş ve hiç okutulmamış kayıtlar silinebilir.');
    }
} else {
    LinkService::archive($pdo, $link, Auth::actor());
    flash('success', "'{$link['slug']}' arşivlendi. Geri almak için Linkler → Arşiv filtresini kullanın.");
}

redirect(BASE_URL . '/admin/links');
