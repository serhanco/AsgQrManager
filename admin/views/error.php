<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Hata — QR Yöneticisi</title>
<style>
  :root{--bg:#f8fafc;--card:#fff;--text:#1a1a2e;--muted:#64748b;--accent:#ef4444;--border:#e2e8f0}
  @media(prefers-color-scheme:dark){:root{--bg:#0f172a;--card:#1e293b;--text:#f1f5f9;--muted:#94a3b8;--border:#334155}}
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem}
  .card{background:var(--card);border:1px solid var(--border);border-radius:1rem;padding:3rem 2rem;text-align:center;max-width:420px;width:100%}
  .icon{font-size:3.5rem;margin-bottom:1rem}
  h1{font-size:1.4rem;font-weight:700;margin-bottom:.5rem}
  p{color:var(--muted);margin-bottom:1.5rem;line-height:1.6}
  a{color:var(--accent);text-decoration:none;font-weight:500}
  small{display:block;margin-top:1rem;color:var(--muted);font-size:.75rem}
</style>
</head>
<body>
<div class="card">
  <div class="icon">⚠️</div>
  <h1>Bir Hata Oluştu</h1>
  <p>Beklenmedik bir hata oluştu. Ayrıntılar sistem günlüğüne kaydedildi.</p>
  <?php if (isset($e) && defined('LOG_LEVEL') && LOG_LEVEL === 'DEBUG'): ?>
    <div style="text-align: left; background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 0.5rem; overflow-x: auto; font-size: 0.85rem; margin-bottom: 1.5rem;">
        <strong>Hata:</strong> <?= htmlspecialchars($e->getMessage()) ?><br>
        <strong>Dosya:</strong> <?= htmlspecialchars($e->getFile()) ?>:<?= $e->getLine() ?><br>
        <pre><?= htmlspecialchars($e->getTraceAsString()) ?></pre>
    </div>
  <?php endif; ?>
  <a href="javascript:history.back()">← Geri Dön</a>
  <small>Sorun devam ederse yönetici ile iletişime geçin.</small>
</div>
</body>
</html>
