<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Logger.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Icons.php';

Logger::registerHandlers();
Auth::startSession();

$error  = '';
$locked = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();

    if (Auth::checkBruteForce(trim($_POST['username'] ?? ''))) {
        $locked = true;
        $rem    = Auth::lockoutRemaining();
        $error  = "Çok fazla başarısız deneme. {$rem} saniye bekleyin.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $pdo  = getPdo();
        $user = Auth::verifyUser($pdo, $username, $password);

        if ($user) {
            Auth::clearFailedAttempts();
            Auth::login((int)$user['id'], $user['username']);
            $redirect = filter_var($_GET['redirect'] ?? '', FILTER_SANITIZE_URL);
            // Güvenlik: aynı site içinde kalmalı
            if (!$redirect || !str_starts_with($redirect, BASE_PATH)) {
                $redirect = BASE_URL . '/admin/dashboard';
            } else {
                $redirect = BASE_URL . preg_replace('#^' . preg_quote(BASE_PATH) . '#', '', $redirect);
            }
            redirect($redirect);
        } else {
            Auth::recordFailedAttempt($username);
            $error = 'Kullanıcı adı veya şifre hatalı.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#1C2561">
<title>Giriş — ACIBADEM QR Code Manager</title>
<script>try { var t = localStorage.getItem('qrm-theme'); if (t === 'light' || t === 'dark') document.documentElement.dataset.theme = t; } catch (e) {}</script>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/icon.svg">
<link rel="preload" href="<?= BASE_URL ?>/assets/fonts/plus-jakarta-sans-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css?v=<?= @filemtime(ROOT_DIR . '/assets/style.css') ?: 1 ?>">
</head>
<body>
<div class="login-page">
  <!-- Marka paneli (geniş ekran) -->
  <section class="login-brand" aria-hidden="true">
    <div class="mark">
      <img src="<?= BASE_URL ?>/assets/icon.svg" alt="">
      <div><div class="brand-name">ACIBADEM</div><div class="brand-product">QR Code Manager</div></div>
    </div>
    <div>
      <h2>Basılı her QR, <em>doğru</em> sayfaya.</h2>
      <p>Ofis, etkinlik ve kampanya QR kodlarını tek yerden yönetin. Kod sabit kalır; hedefi istediğiniz an değiştirin.</p>
      <ul class="features">
        <li><?= icon('repeat') ?> Hedef adresi baskıyı yenilemeden güncelleme</li>
        <li><?= icon('chart') ?> Ülke, cihaz ve güne göre okutma raporları</li>
        <li><?= icon('palette') ?> Kurumsal renk ve logoyla vektörel çıktı</li>
      </ul>
    </div>
    <div class="foot">© <?= date('Y') ?> Acıbadem · ACIBADEM.WORLD</div>
    <svg class="login-art" viewBox="0 0 100 100" fill="none" stroke="#60C2EE" stroke-width="4" stroke-linecap="round">
      <path d="M30 10H16a6 6 0 0 0-6 6v14M70 10h14a6 6 0 0 1 6 6v14M10 70v14a6 6 0 0 0 6 6h14M90 70v14a6 6 0 0 1-6 6H70"/>
      <path d="M50 28 30 72M50 28l20 44" stroke="#fff"/>
    </svg>
  </section>

  <!-- Giriş formu -->
  <section class="login-form-side">
    <div class="login-card">
      <div class="mobile-mark"><img src="<?= BASE_URL ?>/assets/icon.svg" alt=""><span>ACIBADEM</span></div>
      <h1 class="login-title">Hoş geldiniz</h1>
      <p class="login-sub">QR Code Manager yönetim paneline giriş yapın.</p>

      <?php if ($error): ?>
        <div class="alert alert-danger" role="alert"><div><?= e($error) ?></div></div>
      <?php endif; ?>
      <?php if ($msg = flash('danger')): ?>
        <div class="alert alert-danger" role="alert"><div><?= e($msg) ?></div></div>
      <?php endif; ?>

      <form method="POST" action="">
        <?= Auth::csrfField() ?>

        <div class="form-group">
          <label class="form-label" for="username">Kullanıcı adı</label>
          <input class="form-control" type="text" id="username" name="username"
                 value="<?= e($_POST['username'] ?? '') ?>"
                 autocomplete="username" autocapitalize="none" spellcheck="false" autofocus required
                 <?= $locked ? 'disabled' : '' ?>>
        </div>

        <div class="form-group">
          <label class="form-label" for="password">Şifre</label>
          <div class="pw-field">
            <input class="form-control" type="password" id="password" name="password"
                   autocomplete="current-password" required
                   <?= $locked ? 'disabled' : '' ?>>
            <button class="pw-toggle" type="button" aria-label="Şifreyi göster" aria-pressed="false"><?= icon('eye') ?></button>
          </div>
        </div>

        <button class="btn btn-primary" type="submit" <?= $locked ? 'disabled' : '' ?>>
          Giriş yap <?= icon('arrow-right') ?>
        </button>
      </form>
      <p class="login-help"><?php if (Auth::isHttps()): ?><?= icon('lock') ?> Bağlantı şifreli. <?php endif; ?>Şifrenizi unuttuysanız yöneticinizden geçici şifre isteyin.</p>
    </div>
  </section>
</div>
<script>
document.querySelector('.pw-toggle').addEventListener('click', function () {
  var i = document.getElementById('password'), show = i.type === 'password';
  i.type = show ? 'text' : 'password';
  this.setAttribute('aria-pressed', show); this.setAttribute('aria-label', show ? 'Şifreyi gizle' : 'Şifreyi göster');
});
</script>
</body>
</html>
