<?php
/**
 * info.php — Pasif / arşivlenmiş / zamanı gelmemiş / süresi dolmuş ya da bilinmeyen QR okutulunca
 * gösterilen markalı bilgi sayfası (çıplak 404 yerine).
 *
 * Girdi: $infoState = archived | inactive | scheduled | expired | unknown
 * Dil: ?lang=tr|en, yoksa tarayıcının Accept-Language başlığı (tr değilse İngilizce).
 * Veritabanı yoksa Settings varsayılanlara düşer; sayfa yine açılır.
 */
$infoState = $infoState ?? 'unknown';

$lang = $_GET['lang'] ?? '';
if (!in_array($lang, ['tr', 'en'], true)) {
    $lang = preg_match('/^\s*tr\b/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') ? 'tr' : 'en';
}

$ctaUrl = Settings::get('info_page_url');
if (!isValidUrl($ctaUrl)) $ctaUrl = 'https://acibadem.world/';
$ctaHost  = preg_replace('/^www\./', '', (string)parse_url($ctaUrl, PHP_URL_HOST));
$isWorld  = strtolower($ctaHost) === 'acibadem.world';
$delay    = max(0, min(60, (int)Settings::get('info_page_redirect_seconds')));
$custom   = trim(Settings::get('info_page_message_' . $lang));

$T = [
    'tr' => [
        'title' => [
            'inactive'  => 'Bu QR kod artık kullanılmıyor',
            'archived'  => 'Bu QR kod artık kullanılmıyor',
            'scheduled' => 'Bu sayfa henüz yayında değil',
            'expired'   => 'Bu etkinlik sona erdi',
            'unknown'   => 'Bağlantı bulunamadı',
        ],
        'body' => [
            'inactive'  => 'Okuttuğunuz QR kodun yönlendirdiği içerik yayından kaldırıldı.',
            'archived'  => 'Okuttuğunuz QR kodun yönlendirdiği içerik yayından kaldırıldı.',
            'scheduled' => 'Okuttuğunuz QR kodun içeriği kısa süre içinde yayına alınacak. Lütfen daha sonra tekrar deneyin.',
            'expired'   => 'Okuttuğunuz QR kodun ait olduğu etkinlik veya kampanya tamamlandı.',
            'unknown'   => 'Okuttuğunuz QR kod veya bağlantı tanınmadı.',
        ],
        'more'    => 'Acıbadem\'in dünya genelindeki ofislerine, etkinliklerine ve hizmetlerine buradan ulaşabilirsiniz.',
        'cta'     => $isWorld ? 'ACIBADEM.WORLD\'e git' : 'Siteye git',
        'auto'    => 'saniye içinde otomatik yönlendirileceksiniz.',
        'other'   => 'English',
    ],
    'en' => [
        'title' => [
            'inactive'  => 'This QR code is no longer active',
            'archived'  => 'This QR code is no longer active',
            'scheduled' => 'This page is not live yet',
            'expired'   => 'This event has ended',
            'unknown'   => 'Link not found',
        ],
        'body' => [
            'inactive'  => 'The content this QR code pointed to has been taken offline.',
            'archived'  => 'The content this QR code pointed to has been taken offline.',
            'scheduled' => 'The content for this QR code will be published soon. Please try again later.',
            'expired'   => 'The event or campaign this QR code belongs to has ended.',
            'unknown'   => 'We could not recognise this QR code or link.',
        ],
        'more'    => 'You can reach Acıbadem\'s international offices, events and services here.',
        'cta'     => $isWorld ? 'Go to ACIBADEM.WORLD' : 'Continue to website',
        'auto'    => 'seconds until you are redirected automatically.',
        'other'   => 'Türkçe',
    ],
][$lang];
if (!isset($T['title'][$infoState])) $infoState = 'unknown';

$otherLang = $lang === 'tr' ? 'en' : 'tr';
$qs = $_GET; $qs['lang'] = $otherLang;
$otherHref = '?' . http_build_query($qs);
?><!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<?php if ($delay > 0): ?><meta http-equiv="refresh" content="<?= $delay ?>;url=<?= e($ctaUrl) ?>"><?php endif; ?>
<title><?= e($T['title'][$infoState]) ?> · Acıbadem</title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' fill='%231D2660'/%3E%3Cpath d='M15 87 50 13 85 87H76L50 34 24 87Z' fill='%23fff'/%3E%3C/svg%3E">
<style>
  :root{--navy:#1D2660;--bg:#f4f6fb;--card:#fff;--text:#1a1f3d;--muted:#5b6380;--border:#e3e7f1}
  @media(prefers-color-scheme:dark){:root{--bg:#0d1230;--card:#161c42;--text:#eef1fa;--muted:#a9b0cc;--border:#2a3266}}
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:1rem}
  .card{background:var(--card);border:1px solid var(--border);border-radius:1rem;max-width:440px;width:100%;overflow:hidden;box-shadow:0 10px 30px rgba(29,38,96,.08)}
  .brand{background:var(--navy);color:#fff;display:flex;flex-direction:column;align-items:center;gap:.9rem;padding:2rem 1rem 1.6rem}
  .brand svg{width:56px;height:56px}
  .brand .word{font-size:1.05rem;letter-spacing:.42em;padding-left:.42em;font-weight:500}
  .body{padding:2rem 1.75rem 1.75rem;text-align:center}
  h1{font-size:1.35rem;font-weight:700;line-height:1.3;margin-bottom:.75rem}
  p{color:var(--muted);line-height:1.6;margin-bottom:.75rem}
  .btn{display:inline-block;margin-top:1rem;background:var(--navy);color:#fff;text-decoration:none;font-weight:600;padding:.85rem 1.6rem;border-radius:.6rem;letter-spacing:.02em}
  @media(prefers-color-scheme:dark){.btn{background:#fff;color:var(--navy)}}
  .btn:focus-visible{outline:3px solid #8aa0ff;outline-offset:2px}
  .host{display:block;margin-top:.6rem;font-size:.8rem;color:var(--muted)}
  .auto{font-size:.85rem;margin-top:1rem}
  .lang{margin-top:1.25rem;font-size:.85rem}
  .lang a{color:var(--muted)}
</style>
</head>
<body>
<main class="card">
  <div class="brand" aria-label="Acıbadem">
    <svg viewBox="0 0 100 100" aria-hidden="true"><path d="M15 87 50 13 85 87H76L50 34 24 87Z" fill="#fff"/></svg>
    <span class="word">ACIBADEM</span>
  </div>
  <div class="body">
    <h1><?= e($T['title'][$infoState]) ?></h1>
    <p><?= e($T['body'][$infoState]) ?></p>
    <?php if ($custom !== ''): ?><p><?= nl2br(e($custom)) ?></p><?php endif; ?>
    <p><?= e($T['more']) ?></p>
    <a class="btn" href="<?= e($ctaUrl) ?>"><?= e($T['cta']) ?></a>
    <span class="host"><?= e($ctaHost) ?></span>
    <?php if ($delay > 0): ?><p class="auto"><?= $delay ?> <?= e($T['auto']) ?></p><?php endif; ?>
  </div>
</main>
<div class="lang"><a href="<?= e($otherHref) ?>" hreflang="<?= $otherLang ?>"><?= e($T['other']) ?></a></div>
</body>
</html>
