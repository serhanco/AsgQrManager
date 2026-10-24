<?php
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/admin/links');
Auth::requireCsrf();

$pdo  = getPdo();
$link = LinkService::find($pdo, (int)($_POST['id'] ?? 0));

if ($link) {
    $new = $link['is_active'] ? 0 : 1;
    $pdo->prepare('UPDATE links SET is_active=?, updated_at=NOW() WHERE id=?')->execute([$new, (int)$link['id']]);
    LinkEvents::log($pdo, (int)$link['id'], $link['slug'], Auth::actor(), $new ? 'activate' : 'deactivate');
}

// Kullanıcıyı geldiği filtreye geri döndür (aynı site içi adres)
$back = $_POST['back'] ?? '';
if ($back !== '' && str_starts_with($back, BASE_PATH . '/admin/links')) {
    redirect(BASE_URL . substr($back, strlen(BASE_PATH)));
}
redirect(BASE_URL . '/admin/links');
