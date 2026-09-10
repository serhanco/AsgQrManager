<?php
/**
 * index.php — Giriş noktası
 * Giriş yapmışsa dashboard'a, değilse login'e yönlendirir.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Logger.php';
require_once __DIR__ . '/lib/Auth.php';

Logger::registerHandlers();
Auth::startSession();

if (Auth::isLoggedIn()) {
    header('Location: ' . BASE_URL . '/admin/dashboard');
} else {
    header('Location: ' . BASE_URL . '/admin/login');
}
exit;
