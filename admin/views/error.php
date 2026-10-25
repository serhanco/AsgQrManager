<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Hata — QR Yöneticisi</title>
<link rel="icon" type="image/svg+xml" href="<?= defined('BASE_URL') ? BASE_URL : '' ?>/assets/icon.svg">
<style>
  :root{--bg:#f4f6fb;--card:#fff;--text:#121944;--muted:#5b6385;--accent:#1F4BA8;--border:#e3e7f1;--chip:#eaf0fb}
  @media(prefers-color-scheme:dark){:root{--bg:#0b1030;--card:#141a3f;--text:#eef1fb;--muted:#a3abc9;--accent:#6EA0FF;--border:#262e5c;--chip:#1c2556}}
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);color:var(--text);font-family:'Plus Jakarta Sans',system-ui,-apple-system,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem}
  .card{background:var(--card);border:1px solid var(--border);border-radius:1.25rem;padding:3rem 2rem;box-shadow:0 10px 30px -12px rgba(18,25,68,.18);text-align:center;max-width:420px;width:100%}
  .icon{width:64px;height:64px;border-radius:18px;background:var(--chip);color:var(--accent);display:inline-flex;align-items:center;justify-content:center;margin-bottom:1.25rem}
  h1{font-size:1.4rem;font-weight:700;margin-bottom:.5rem}
  p{color:var(--muted);margin-bottom:1.5rem;line-height:1.6}
  a{color:var(--accent);text-decoration:none;font-weight:500}
  small{display:block;margin-top:1rem;color:var(--muted);font-size:.75rem}
</style>
</head>
<body>
<div class="card">
  <div class="icon"><?= isset($errorTitle) ? '<svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>' : '<svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>' ?></div>
  <h1><?= htmlspecialchars($errorTitle ?? 'Bir Hata Oluştu') ?></h1>
  <p><?= htmlspecialchars($errorMessage ?? 'Beklenmedik bir hata oluştu. Ayrıntılar sistem günlüğüne kaydedildi.') ?></p>
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
