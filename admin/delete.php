<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Logger.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/QrRenderer.php';

Logger::registerHandlers();
Auth::startSession();
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/admin/links');
}
Auth::requireCsrf();

$pdo = getPdo();
$id  = (int)($_POST['id'] ?? 0);

$stmt = $pdo->prepare('SELECT slug FROM links WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$link = $stmt->fetch();

if ($link) {
    QrRenderer::clearCache($link['slug']);
    $pdo->prepare('DELETE FROM links WHERE id = ?')->execute([$id]);
    Logger::info("Link silindi: id={$id} slug={$link['slug']}");
    flash('success', 'Link silindi.');
} else {
    flash('danger', 'Link bulunamadı.');
}

redirect(BASE_URL . '/admin/links');
