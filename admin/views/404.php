<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>404 — QR Yöneticisi</title>
<link rel="icon" type="image/svg+xml" href="<?= defined('BASE_URL') ? BASE_URL : '' ?>/assets/icon.svg">
<style>
  :root{--bg:#f8fafc;--card:#fff;--text:#1a1a2e;--muted:#64748b;--accent:#6366f1;--border:#e2e8f0}
  @media(prefers-color-scheme:dark){:root{--bg:#0f172a;--card:#1e293b;--text:#f1f5f9;--muted:#94a3b8;--border:#334155}}
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem}
  .card{background:var(--card);border:1px solid var(--border);border-radius:1rem;padding:3rem 2rem;text-align:center;max-width:400px;width:100%}
  .icon{font-size:4rem;margin-bottom:1rem}
  h1{font-size:1.5rem;font-weight:700;margin-bottom:.5rem}
  p{color:var(--muted);margin-bottom:1.5rem;line-height:1.6}
  a{color:var(--accent);text-decoration:none;font-weight:500}
</style>
</head>
<body>
<div class="card">
  <div class="icon">🔗</div>
  <h1>Bağlantı Bulunamadı</h1>
  <p>Bu QR kodu artık geçerli değil veya devre dışı bırakılmış olabilir.</p>
  <a href="<?= defined('BASE_URL') ? htmlspecialchars(BASE_URL) : '/' ?>">Ana Sayfaya Dön</a>
</div>
</body>
</html>
