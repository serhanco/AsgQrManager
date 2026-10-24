<?php
/**
 * info-preview.php — Pasif/süresi dolmuş QR'lar için markalı bilgi sayfasının yönetici önizlemesi.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();

$infoState = (string)($_GET['state'] ?? 'inactive');
header('X-Robots-Tag: noindex, nofollow');
include __DIR__ . '/views/info.php';
