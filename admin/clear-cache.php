<?php
/**
 * clear-cache.php — Tüm QR önbelleğini siler
 */
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/admin/dashboard');
}
Auth::requireCsrf();

$deleted = QrRenderer::clearAllCache();
Logger::info("Tüm QR önbelleği temizlendi ({$deleted} dosya silindi).");
flash('success', "Tüm QR önbelleği başarıyla temizlendi ({$deleted} dosya silindi). QR kodlar güncel domain ile yeniden üretilecektir.");

$referer = $_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/admin/dashboard');
if (!str_starts_with($referer, BASE_URL)) {
    $referer = BASE_URL . '/admin/dashboard';
}
redirect($referer);
