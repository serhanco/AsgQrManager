<?php
/**
 * _layout.php — Admin paneli ortak layout başlangıcı
 * include etmeden önce $pageTitle ve $activePage değişkenlerini ayarlayın.
 */
if (!defined('ROOT_DIR')) exit;
$base = BASE_URL;
$csrf = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="base-url" content="<?= e($base) ?>">
<meta name="csrf"     content="<?= e($csrf) ?>">
<title><?= e($pageTitle ?? 'QR Yöneticisi') ?> — ACIBADEM QR Code Manager</title>
<link rel="icon" type="image/svg+xml" href="<?= $base ?>/assets/icon.svg">
<link rel="stylesheet" href="<?= $base ?>/assets/style.css">
</head>
<body>
<div class="sidebar-backdrop"></div>

<div class="layout">
<!-- Topbar -->
<header class="topbar">
  <button class="hamburger" aria-label="Menü">☰</button>
  <a class="topbar-brand" href="<?= $base ?>/admin/dashboard" style="gap: .75rem;">
    <span class="logo-icon" style="display: flex; align-items: center;"><img src="<?= $base ?>/assets/icon.svg" alt="Logo" style="height: 2.4rem; width: auto;"></span>
    <span style="font-size: 1.1rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%;">ACIBADEM QR Code Manager</span>
  </a>
  <div class="topbar-spacer"></div>
  <div class="topbar-user">
    <span>👤 <?= e($_SESSION['username'] ?? '') ?></span>
    <a href="<?= $base ?>/admin/logout">Çıkış</a>
  </div>
</header>

<!-- Sidebar -->
<nav class="sidebar">
  <ul class="sidebar-nav">
    <li class="sidebar-section">Genel</li>
    <li><a href="<?= $base ?>/admin/dashboard" <?= $activePage==='dashboard'?'class="active"':'' ?>>
      <span class="nav-icon">📊</span> Dashboard
    </a></li>
    <li class="sidebar-section">Bağlantılar</li>
    <li><a href="<?= $base ?>/admin/links" <?= $activePage==='links'?'class="active"':'' ?>>
      <span class="nav-icon">🔗</span> Tüm Linkler
    </a></li>
    <li><a href="<?= $base ?>/admin/create" <?= $activePage==='create'?'class="active"':'' ?>>
      <span class="nav-icon">➕</span> Yeni Link
    </a></li>
    <li class="sidebar-section">Sistem</li>
    <li><a href="<?= $base ?>/admin/logs" <?= $activePage==='logs'?'class="active"':'' ?>>
      <span class="nav-icon">📋</span> Log Görüntüleyici
    </a></li>
  </ul>
</nav>

<!-- İçerik -->
<main class="main">
<?php
// Flash mesajları
foreach (['success','danger','warning','info'] as $type) {
    $msg = flash($type);
    if ($msg) {
        echo '<div class="alert alert-' . $type . '">' . e($msg) . '</div>';
    }
}
?>
