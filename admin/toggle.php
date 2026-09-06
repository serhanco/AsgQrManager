<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Logger.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/helpers.php';

Logger::registerHandlers();
Auth::startSession();
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/admin/links.php');
Auth::requireCsrf();

$pdo = getPdo();
$id  = (int)($_POST['id'] ?? 0);

$stmt = $pdo->prepare('SELECT id, is_active FROM links WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$link = $stmt->fetch();

if ($link) {
    $newStatus = $link['is_active'] ? 0 : 1;
    $pdo->prepare('UPDATE links SET is_active=?, updated_at=NOW() WHERE id=?')
        ->execute([$newStatus, $id]);
}

redirect(BASE_URL . '/admin/links.php' . (isset($_SERVER['HTTP_REFERER']) ? '' : ''));
