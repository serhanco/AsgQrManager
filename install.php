<?php
/**
 * install.php — QR Manager Kurulum Sihirbazı
 *
 * GÜVENLİK: Kurulum tamamlandıktan sonra bu dosyayı SİLİN veya yeniden adlandırın!
 * .htaccess'de yalnızca localhost'a izin verilmiştir.
 */

// Basit IP kısıtlaması (ek güvenlik katmanı)
$allowedIps = ['127.0.0.1', '::1', '::ffff:127.0.0.1'];
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowedIps, true)) {
    http_response_code(403);
    exit('Bu sayfaya yalnızca localhost üzerinden erişilebilir.');
}

require_once __DIR__ . '/config.php';

$step    = (int)($_POST['step'] ?? 1);
$errors  = [];
$success = [];

// ─── Adım 2: Kurulumu yap ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $adminUser = trim($_POST['admin_user'] ?? '');
    $adminPass = $_POST['admin_pass'] ?? '';
    $adminPass2= $_POST['admin_pass2'] ?? '';

    if (strlen($adminUser) < 3)  $errors[] = 'Kullanıcı adı en az 3 karakter olmalı.';
    if (strlen($adminPass) < 8)  $errors[] = 'Şifre en az 8 karakter olmalı.';
    if ($adminPass !== $adminPass2) $errors[] = 'Şifreler eşleşmiyor.';

    if (empty($errors)) {
        // DB bağlantısı — mevcut DB'ye doğrudan bağlan (CREATE DATABASE YOK)
        // Paylaşımlı hosting'de veritabanı zaten hosting panelinden oluşturulmuş olmalı.
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
            );
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $success[] = '✅ Veritabanına bağlanıldı: ' . DB_NAME;
        } catch (\Throwable $e) {
            $errors[] = 'DB bağlantı hatası: ' . $e->getMessage();
        }

        if (empty($errors)) {
            // SQL şeması çalıştır
            try {
                $sql = file_get_contents(__DIR__ . '/schema.sql');
                // Yorumları atla, satırlar ile çalış
                $statements = array_filter(
                    array_map('trim', explode(';', preg_replace('/--[^\n]*\n/', "\n", $sql))),
                    fn($s) => strlen($s) > 5
                );
                foreach ($statements as $stmt) {
                    $pdo->exec($stmt);
                }
                $success[] = '✅ Tablolar oluşturuldu.';
            } catch (\Throwable $e) {
                $errors[] = 'Şema hatası: ' . $e->getMessage();
            }

            // Admin kullanıcısı ekle
            if (empty($errors)) {
                try {
                    $hash = password_hash($adminPass, PASSWORD_BCRYPT);
                    $st   = $pdo->prepare('INSERT IGNORE INTO users (username, password_hash) VALUES (?,?)');
                    $st->execute([$adminUser, $hash]);
                    $success[] = '✅ Admin kullanıcısı oluşturuldu.';
                } catch (\Throwable $e) {
                    $errors[] = 'Kullanıcı hatası: ' . $e->getMessage();
                }
            }
        }

        // Klasörler
        $dirs = [
            ROOT_DIR . '/cache/qr',
            ROOT_DIR . '/logs/scans',
            ROOT_DIR . '/logs/app',
            ROOT_DIR . '/logo',
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                if (mkdir($dir, 0755, true)) {
                    $success[] = "✅ Klasör oluşturuldu: " . str_replace(ROOT_DIR, '.', $dir);
                } else {
                    $errors[] = "Klasör oluşturulamadı: {$dir}";
                }
            } else {
                $success[] = "✅ Klasör mevcut: " . str_replace(ROOT_DIR, '.', $dir);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>QR Manager — Kurulum</title>
<style>
  :root{--bg:#f1f5f9;--card:#fff;--text:#0f172a;--muted:#64748b;--accent:#6366f1;--border:#e2e8f0;--success:#22c55e;--danger:#ef4444}
  @media(prefers-color-scheme:dark){:root{--bg:#0f172a;--card:#1e293b;--text:#f1f5f9;--muted:#94a3b8;--border:#334155}}
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);color:var(--text);font-family:system-ui,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem}
  .card{background:var(--card);border:1px solid var(--border);border-radius:1rem;padding:2rem;width:100%;max-width:520px}
  h1{font-size:1.5rem;font-weight:700;margin-bottom:.25rem;display:flex;align-items:center;gap:.5rem}
  p{color:var(--muted);margin-bottom:1.25rem;font-size:.9rem}
  label{display:block;font-size:.875rem;font-weight:600;margin-bottom:.35rem}
  input{display:block;width:100%;padding:.55rem .85rem;background:var(--bg);border:1px solid var(--border);border-radius:.375rem;color:var(--text);font-size:.9rem;margin-bottom:1rem}
  button{background:var(--accent);color:#fff;border:none;border-radius:.375rem;padding:.65rem 1.5rem;font-size:.9rem;font-weight:600;cursor:pointer;width:100%}
  .msg{padding:.75rem;border-radius:.375rem;margin-bottom:.5rem;font-size:.85rem}
  .msg-ok{background:color-mix(in srgb,var(--success) 12%,var(--card));color:var(--success)}
  .msg-err{background:color-mix(in srgb,var(--danger) 12%,var(--card));color:var(--danger)}
  .warn{background:color-mix(in srgb,#f59e0b 12%,var(--card));color:#b45309;padding:1rem;border-radius:.5rem;margin-bottom:1.25rem;font-size:.85rem}
</style>
</head>
<body>
<div class="card">
  <h1>📱 QR Manager</h1>
  <p>Kurulum Sihirbazı — <code>install.php</code></p>

  <div class="warn">
    ⚠️ Bu dosya <strong>sadece kurulum sırasında</strong> çalıştırılmalıdır.
    Kurulum sonrası bu dosyayı sunucudan silin!
  </div>

  <?php foreach ($errors as $err): ?>
    <div class="msg msg-err"><?= htmlspecialchars($err) ?></div>
  <?php endforeach; ?>

  <?php if (!empty($success) && empty($errors)): ?>
    <?php foreach ($success as $s): ?>
      <div class="msg msg-ok"><?= htmlspecialchars($s) ?></div>
    <?php endforeach; ?>
    <div class="msg msg-ok" style="margin-top:.75rem;font-weight:700">
      🎉 Kurulum tamamlandı!
    </div>
    <p style="margin-top:1rem">
      ➡️ <a href="<?= htmlspecialchars(BASE_URL . '/admin/login.php') ?>" style="color:var(--accent)">
        Admin paneline git
      </a>
    </p>
    <p style="color:var(--danger);margin-top:.75rem;font-size:.85rem">
      ⚠️ install.php dosyasını sunucudan silin: <code>rm install.php</code>
    </p>
  <?php else: ?>
    <form method="POST" action="">
      <input type="hidden" name="step" value="2">

      <div>
        <label>Veritabanı Sunucusu</label>
        <input type="text" disabled value="<?= htmlspecialchars(DB_HOST . ':' . DB_PORT) ?>">
      </div>
      <div>
        <label>Veritabanı Adı</label>
        <input type="text" disabled value="<?= htmlspecialchars(DB_NAME) ?>">
      </div>
      <div>
        <label>Admin Kullanıcı Adı *</label>
        <input type="text" name="admin_user" value="<?= htmlspecialchars($_POST['admin_user'] ?? 'admin') ?>" required minlength="3">
      </div>
      <div>
        <label>Admin Şifresi *</label>
        <input type="password" name="admin_pass" required minlength="8">
      </div>
      <div>
        <label>Şifre Tekrar *</label>
        <input type="password" name="admin_pass2" required minlength="8">
      </div>
      <button type="submit">🚀 Kurulumu Başlat</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
