<?php
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/admin/links');
Auth::requireCsrf();

$pdo  = getPdo();
$link = Auth::requireLinkAccess(LinkService::find($pdo, (int)($_POST['id'] ?? 0)), true);

if (Access::canToggleLink($link)) {
    $new = $link['is_active'] ? 0 : 1;
    $pdo->prepare('UPDATE links SET is_active=?, updated_at=NOW() WHERE id=?')->execute([$new, (int)$link['id']]);
    LinkEvents::log($pdo, (int)$link['id'], $link['slug'], Auth::actor(), $new ? 'activate' : 'deactivate');
} elseif (Access::canRequestToggle($link)) {
    // Editör: doğrudan değiştiremez, yönetici onayına talep gönderir
    [$reqId, $err] = LinkRequests::create($pdo, $link, Auth::actor(), LinkRequests::kindFor($link), (string)($_POST['note'] ?? ''));
    if ($err) flash('warning', $err);
    else flash('success', "'{$link['slug']}' için " . mb_strtolower(LinkRequests::KINDS[LinkRequests::kindFor($link)]) . ' talebi yöneticiye gönderildi. Onaylanana kadar QR olduğu gibi çalışır.');
} else {
    Auth::forbidden();
}

// Kullanıcıyı geldiği filtreye geri döndür (aynı site içi adres)
$back = $_POST['back'] ?? '';
if ($back !== '' && !str_contains($back, "\n") && (str_starts_with($back, BASE_PATH . '/admin/links') || str_starts_with($back, BASE_PATH . '/admin/edit'))) {
    redirect(BASE_URL . substr($back, strlen(BASE_PATH)));
}
redirect(BASE_URL . '/admin/links');
