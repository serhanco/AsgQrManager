<?php
/**
 * analytics-toggle.php — Analytics açma/kapama (AJAX)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Logger.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/helpers.php';

Logger::registerHandlers();
Auth::startSession();

header('Content-Type: application/json');

if (!Auth::isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'Yetkisiz']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Auth::verifyCsrf()) {
    echo json_encode(['ok' => false, 'error' => 'CSRF hatası']);
    exit;
}

$enabled = !empty($_POST['enabled']) && $_POST['enabled'] === '1';

if (toggleAnalytics($enabled)) {
    Logger::info('Analytics ' . ($enabled ? 'açıldı' : 'kapatıldı'));
    echo json_encode(['ok' => true, 'enabled' => $enabled]);
} else {
    echo json_encode(['ok' => false, 'error' => 'config.php yazılamadı']);
}
