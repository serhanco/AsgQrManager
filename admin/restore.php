<?php
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();
Auth::requirePerm('links.archive');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/admin/links');
Auth::requireCsrf();

$pdo  = getPdo();
$link = Auth::requireLinkAccess(LinkService::find($pdo, (int)($_POST['id'] ?? 0)), true);
if ($link) {
    LinkService::restore($pdo, $link, Auth::actor());
    flash('success', "'{$link['slug']}' arşivden çıkarıldı.");
    redirect(BASE_URL . '/admin/edit?id=' . (int)$link['id']);
}
flash('danger', 'QR bulunamadı.');
redirect(BASE_URL . '/admin/links');
