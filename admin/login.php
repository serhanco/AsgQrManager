<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Logger.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/helpers.php';

Logger::registerHandlers();
Auth::startSession();

$error  = '';
$locked = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();

    if (Auth::checkBruteForce()) {
        $locked = true;
        $rem    = Auth::lockoutRemaining();
        $error  = "Çok fazla başarısız deneme. {$rem} saniye bekleyin.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $pdo  = getPdo();
        $user = Auth::verifyUser($pdo, $username, $password);

        if ($user) {
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
            Auth::recordFailedAttempt();
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
<title>Giriş — ACIBADEM QR Code Manager</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/icon.svg">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <div class="login-logo"><img src="<?= BASE_URL ?>/assets/icon.svg" alt="ACIBADEM QR Code Manager Logo" style="height: 7rem; width: auto;"></div>
    <h1 class="login-title">ACIBADEM QR Code Manager</h1>
    <p class="login-sub">Yönetim Paneli</p>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <?= Auth::csrfField() ?>

      <div class="form-group">
        <label class="form-label" for="username">Kullanıcı Adı</label>
        <input class="form-control" type="text" id="username" name="username"
               value="<?= e($_POST['username'] ?? '') ?>"
               autocomplete="username" autofocus required
               <?= $locked ? 'disabled' : '' ?>>
      </div>

      <div class="form-group">
        <label class="form-label" for="password">Şifre</label>
        <input class="form-control" type="password" id="password" name="password"
               autocomplete="current-password" required
               <?= $locked ? 'disabled' : '' ?>>
      </div>

      <button class="btn btn-primary" style="width:100%" type="submit" <?= $locked ? 'disabled' : '' ?>>
        Giriş Yap
      </button>
    </form>
  </div>
</div>
</body>
</html>
