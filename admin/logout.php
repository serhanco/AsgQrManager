<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Auth.php';

Auth::startSession();
Auth::logout();
header('Location: ' . BASE_URL . '/admin/login.php');
exit;
