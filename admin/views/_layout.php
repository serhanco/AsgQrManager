<?php
/**
 * _layout.php — Admin paneli ortak layout başlangıcı
 * include etmeden önce $pageTitle ve $activePage değişkenlerini ayarlayın.
 */
if (!defined('ROOT_DIR')) exit;
$base = BASE_URL;
$csrf = Auth::csrfToken();

// Menü: [bölüm => [[sayfa, ikon, etiket, görünür mü]]]
$canEditNav = Access::can('links.edit');
$isAdminNav = Access::isAdmin();
$nav = [
    'Genel' => [
        ['dashboard', 'dashboard', 'Gösterge Paneli', true],
    ],
    'QR Kodlar' => [
        ['links',  'qr',     'Tüm QR\'lar',     true],
        ['create', 'plus',   'Yeni QR',         $canEditNav],
        ['bulk',   'layers', 'Toplu Oluştur',   $canEditNav],
        ['tags',   'tag',    'Etiketler',       $isAdminNav],
    ],
    'Raporlama' => [
        ['report',  'chart',   'Raporlar',            true],
        ['history', 'history', 'Değişiklik Geçmişi',  true],
    ],
    'Sistem' => [
        ['users',      'users',    'Kullanıcılar',      $isAdminNav],
        ['settings',   'settings', 'Ayarlar',           $isAdminNav],
        ['api-tokens', 'code',     'API',               $isAdminNav],
        ['logs',       'file',     'Log Görüntüleyici', $isAdminNav],
    ],
];
$navActive = in_array($activePage ?? '', ['api-tokens', 'api-docs'], true) ? 'api-tokens' : ($activePage ?? '');

$userName  = ($_SESSION['display_name'] ?? '') ?: ($_SESSION['username'] ?? '');
$initials  = mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/[\s._-]+/u', trim($userName)) ?: ['?'], 0, 2))));
$roleLabel = Access::ROLES[Access::role()] ?? '';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="base-url" content="<?= e($base) ?>">
<meta name="csrf"     content="<?= e($csrf) ?>">
<meta name="theme-color" content="#1C2561">
<title><?= e($pageTitle ?? 'QR Yöneticisi') ?> — ACIBADEM QR Code Manager</title>
<script>/* tema seçimi ilk boyamadan önce: açık / koyu / sistem */
try { var t = localStorage.getItem('qrm-theme'); if (t === 'light' || t === 'dark') document.documentElement.dataset.theme = t; } catch (e) {}
</script>
<link rel="icon" type="image/svg+xml" href="<?= $base ?>/assets/icon.svg">
<link rel="preload" href="<?= $base ?>/assets/fonts/plus-jakarta-sans-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= $base ?>/assets/style.css?v=<?= @filemtime(ROOT_DIR . '/assets/style.css') ?: 1 ?>">
</head>
<body>
<div class="sidebar-backdrop"></div>

<div class="layout">
<!-- Kenar menü -->
<nav class="sidebar" aria-label="Ana menü">
  <a class="sidebar-brand" href="<?= $base ?>/admin/dashboard">
    <img src="<?= $base ?>/assets/icon.svg" alt="">
    <span class="brand-text"><span class="brand-name">ACIBADEM</span><span class="brand-product">QR Code Manager</span></span>
  </a>
  <div class="sidebar-scroll">
    <ul class="sidebar-nav">
      <?php foreach ($nav as $section => $items):
          $items = array_filter($items, fn($i) => $i[3]);
          if (!$items) continue; ?>
        <li class="sidebar-section"><?= e($section) ?></li>
        <?php foreach ($items as [$page, $ic, $label]): ?>
          <li><a href="<?= $base ?>/admin/<?= $page ?>" <?= $navActive === $page ? 'class="active" aria-current="page"' : '' ?>>
            <span class="nav-icon"><?= icon($ic) ?></span> <?= e($label) ?>
          </a></li>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if ($isAdminNav): ?>
      <li>
        <form method="POST" action="<?= $base ?>/admin/clear-cache"
              data-confirm="Tüm QR önbelleğini silmek istediğinizden emin misiniz? QR kodlar yeni isteklerde güncel domain ile yeniden üretilecektir.">
          <?= Auth::csrfField() ?>
          <button type="submit" class="sidebar-btn">
            <span class="nav-icon"><?= icon('eraser') ?></span> Önbelleği Temizle
          </button>
        </form>
      </li>
      <?php endif; ?>
    </ul>
  </div>
  <div class="sidebar-foot">
    <a href="<?= $base ?>/admin/account" class="avatar" title="Hesabım"><?= e($initials ?: '?') ?></a>
    <a href="<?= $base ?>/admin/account" class="who" style="text-decoration:none">
      <strong><?= e($userName) ?></strong><span><?= e($roleLabel) ?></span>
    </a>
    <a class="icon-btn" href="<?= $base ?>/admin/logout" title="Çıkış yap"><?= icon('logout', '', 'Çıkış yap') ?></a>
  </div>
</nav>

<!-- Üst çubuk -->
<header class="topbar">
  <button class="icon-btn hamburger" type="button" aria-label="Menüyü aç"><?= icon('menu') ?></button>
  <div class="topbar-title"><?= e($pageTitle ?? '') ?></div>
  <div class="topbar-spacer"></div>
  <div class="topbar-actions">
    <?php if ($canEditNav && ($activePage ?? '') !== 'create'): ?>
      <a class="btn btn-primary btn-sm" href="<?= $base ?>/admin/create"><?= icon('plus') ?><span class="btn-label-hide">Yeni QR</span></a>
    <?php endif; ?>
    <button class="icon-btn theme-toggle" type="button" title="Açık / koyu tema">
      <?= icon('sun', 'icon-sun', 'Koyu temaya geç') ?><?= icon('moon', 'icon-moon', 'Açık temaya geç') ?>
    </button>
  </div>
</header>

<!-- İçerik -->
<main class="main">
<?php
// Flash mesajları
foreach (['success','danger','warning','info'] as $flashType) {
    $msg = flash($flashType);
    if ($msg) {
        echo '<div class="alert alert-' . $flashType . '" role="' . ($flashType === 'danger' ? 'alert' : 'status') . '"><div>' . e($msg) . '</div></div>';
    }
}
?>
