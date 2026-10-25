<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>404 — QR Yöneticisi</title>
<link rel="icon" type="image/svg+xml" href="<?= defined('BASE_URL') ? BASE_URL : '' ?>/assets/icon.svg">
<style>
  :root{--bg:#f4f6fb;--card:#fff;--text:#121944;--muted:#5b6385;--accent:#1F4BA8;--border:#e3e7f1;--chip:#eaf0fb}
  @media(prefers-color-scheme:dark){:root{--bg:#0b1030;--card:#141a3f;--text:#eef1fb;--muted:#a3abc9;--accent:#6EA0FF;--border:#262e5c;--chip:#1c2556}}
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);color:var(--text);font-family:'Plus Jakarta Sans',system-ui,-apple-system,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem}
  .card{background:var(--card);border:1px solid var(--border);border-radius:1.25rem;padding:3rem 2rem;box-shadow:0 10px 30px -12px rgba(18,25,68,.18);text-align:center;max-width:400px;width:100%}
  .icon{width:64px;height:64px;border-radius:18px;background:var(--chip);color:var(--accent);display:inline-flex;align-items:center;justify-content:center;margin-bottom:1.25rem}
  h1{font-size:1.5rem;font-weight:700;margin-bottom:.5rem}
  p{color:var(--muted);margin-bottom:1.5rem;line-height:1.6}
  a{color:var(--accent);text-decoration:none;font-weight:500}
</style>
</head>
<body>
<div class="card">
  <div class="icon"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></div>
  <h1>Bağlantı Bulunamadı</h1>
  <p>Bu QR kodu artık geçerli değil veya devre dışı bırakılmış olabilir.</p>
  <a href="<?= defined('BASE_URL') ? htmlspecialchars(BASE_URL) : '/' ?>">Ana Sayfaya Dön</a>
</div>
</body>
</html>
