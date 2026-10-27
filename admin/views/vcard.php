<?php
/**
 * vcard.php — kartvizit QR'ı okutulunca açılan kişi sayfası (r.php içinden).
 *
 * Girdi: $link, $vcard (VCard::find). "Rehbere ekle" aynı adrese ?vcf=1 ile .vcf indirir.
 * Dil: ?lang=tr|en, yoksa tarayıcının Accept-Language başlığı (tr değilse İngilizce).
 */
$lang = $_GET['lang'] ?? '';
if (!in_array($lang, ['tr', 'en'], true)) {
    $lang = preg_match('/^\s*tr\b/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') ? 'tr' : 'en';
}
$T = [
    'tr' => ['save' => 'Rehbere ekle', 'call' => 'Ara', 'mobile' => 'Cep', 'work' => 'İş', 'mail' => 'E-posta',
             'web' => 'Web sitesi', 'map' => 'Haritada aç', 'wa' => 'WhatsApp', 'other' => 'English',
             'hint' => 'Kişi kartını telefonunuzun rehberine kaydeder.'],
    'en' => ['save' => 'Add to contacts', 'call' => 'Call', 'mobile' => 'Mobile', 'work' => 'Office', 'mail' => 'Email',
             'web' => 'Website', 'map' => 'Open in maps', 'wa' => 'WhatsApp', 'other' => 'Türkçe',
             'hint' => 'Saves this contact card to your phone.'],
][$lang];

$base     = defined('BASE_URL') ? BASE_URL : '';
$self     = $base . '/r/' . rawurlencode($link['slug']);
$name     = VCard::fullName($vcard);
$initials = mb_strtoupper(mb_substr($vcard['first_name'] ?? '', 0, 1) . mb_substr($vcard['last_name'] ?? '', 0, 1)) ?: 'A';
$telHref  = fn(string $p) => 'tel:' . preg_replace('/[^0-9+]/', '', $p);
$waNumber = preg_replace('/\D/', '', (string)($vcard['phone_mobile'] ?? ''));
$otherQs  = http_build_query(['lang' => $lang === 'tr' ? 'en' : 'tr']);
$role     = trim(implode(' · ', array_filter([$vcard['title'] ?? '', $vcard['department'] ?? ''])));

$ic = [
    'save'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>',
    'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
    'mail'  => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
    'globe' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
    'map'   => '<path d="M20 10c0 4.99-5.54 10.19-7.4 11.79a1 1 0 0 1-1.2 0C9.54 20.19 4 14.99 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
    'chat'  => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
    'in'    => '<path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect width="4" height="12" x="2" y="9"/><circle cx="4" cy="4" r="2"/>',
];
$svg = fn(string $k) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $ic[$k] . '</svg>';

$rows = [];
if (!empty($vcard['phone_mobile'])) $rows[] = ['phone', $T['mobile'], $vcard['phone_mobile'], $telHref($vcard['phone_mobile'])];
if (!empty($vcard['phone_work']))   $rows[] = ['phone', $T['work'], $vcard['phone_work'], $telHref($vcard['phone_work'])];
if (!empty($vcard['email']))        $rows[] = ['mail', $T['mail'], $vcard['email'], 'mailto:' . $vcard['email']];
if (!empty($vcard['website']))      $rows[] = ['globe', $T['web'], preg_replace('#^https?://(www\.)?#', '', rtrim($vcard['website'], '/')), $vcard['website']];
if (!empty($vcard['linkedin']))     $rows[] = ['in', 'LinkedIn', preg_replace('#^https?://(www\.)?#', '', rtrim($vcard['linkedin'], '/')), $vcard['linkedin']];
if (!empty($vcard['address']))      $rows[] = ['map', $T['map'], $vcard['address'], 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($vcard['address'])];
?><!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#1C2561">
<title><?= e($name) ?><?= !empty($vcard['org']) ? ' · ' . e($vcard['org']) : '' ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e($base) ?>/assets/icon.svg">
<style>
  @font-face{font-family:'Plus Jakarta Sans';font-style:normal;font-weight:200 800;font-display:swap;src:url('<?= e($base) ?>/assets/fonts/plus-jakarta-sans-latin-ext.woff2') format('woff2');unicode-range:U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF}
  @font-face{font-family:'Plus Jakarta Sans';font-style:normal;font-weight:200 800;font-display:swap;src:url('<?= e($base) ?>/assets/fonts/plus-jakarta-sans-latin.woff2') format('woff2');unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}
  :root{--navy:#1C2561;--deep:#121944;--blue:#0A3A86;--sky:#60C2EE;--accent:#1F4BA8;--bg:#f4f6fb;--card:#fff;--text:#121944;--muted:#5b6385;--border:#e3e7f1;--chip:#eaf0fb}
  @media(prefers-color-scheme:dark){:root{--bg:#0b1030;--card:#141a3f;--text:#eef1fb;--muted:#a3abc9;--border:#262e5c;--chip:#1c2556;--accent:#6EA0FF}}
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);color:var(--text);font-family:'Plus Jakarta Sans',system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:0 0 2rem}
  .card{width:100%;max-width:460px;background:var(--card);border-bottom:1px solid var(--border);overflow:hidden}
  @media(min-width:500px){body{padding:2rem 1rem}.card{border:1px solid var(--border);border-radius:1.25rem;box-shadow:0 16px 40px -18px rgba(18,25,68,.3)}}
  .cover{height:132px;background:radial-gradient(120% 140% at 85% 0%,rgba(96,194,238,.35) 0%,transparent 55%),linear-gradient(135deg,var(--navy),var(--deep));position:relative}
  .cover .mark{position:absolute;top:1rem;left:1.25rem;display:flex;align-items:center;gap:.6rem;color:#fff;font-size:.78rem;letter-spacing:.38em;font-weight:600}
  .cover .mark img{width:28px;height:28px}
  .avatar{width:112px;height:112px;border-radius:50%;margin:-56px auto 0;position:relative;border:4px solid var(--card);background:var(--chip);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:2.4rem;font-weight:700;overflow:hidden}
  .avatar img{width:100%;height:100%;object-fit:cover}
  .who{text-align:center;padding:1rem 1.5rem 0}
  h1{font-size:1.5rem;font-weight:800;letter-spacing:-.01em;line-height:1.2}
  .role{color:var(--muted);margin-top:.35rem;font-size:.95rem}
  .org{display:inline-block;margin-top:.6rem;background:var(--chip);color:var(--accent);font-weight:600;font-size:.8rem;padding:.3rem .75rem;border-radius:999px}
  .quick{display:flex;justify-content:center;gap:.75rem;padding:1.25rem 1.5rem .25rem}
  .quick a{width:52px;height:52px;border-radius:50%;background:var(--chip);color:var(--accent);display:flex;align-items:center;justify-content:center}
  .quick a svg{width:22px;height:22px}
  .quick a:focus-visible,.save:focus-visible,.row:focus-visible{outline:3px solid var(--sky);outline-offset:2px}
  .save{display:flex;align-items:center;justify-content:center;gap:.6rem;margin:1.25rem 1.5rem .4rem;padding:1rem;border-radius:.9rem;background:var(--accent);color:#fff;font-weight:700;font-size:1.05rem;text-decoration:none;box-shadow:0 10px 24px -12px rgba(31,75,168,.7)}
  @media(prefers-color-scheme:dark){.save{color:#0b1030}}
  .save svg{width:22px;height:22px}
  .hint{text-align:center;font-size:.78rem;color:var(--muted);margin-bottom:1rem}
  .list{border-top:1px solid var(--border)}
  .row{display:flex;align-items:center;gap:.9rem;padding:.95rem 1.5rem;border-bottom:1px solid var(--border);color:inherit;text-decoration:none}
  .row:last-child{border-bottom:0}
  .row .ic{flex:0 0 40px;height:40px;border-radius:12px;background:var(--chip);color:var(--accent);display:flex;align-items:center;justify-content:center}
  .row .ic svg{width:19px;height:19px}
  .row small{display:block;font-size:.78rem;color:var(--muted);font-weight:600}
  .row span{display:block;font-weight:600;word-break:break-word}
  .note{padding:1rem 1.5rem 1.25rem;color:var(--muted);font-size:.9rem;line-height:1.55;border-top:1px solid var(--border);white-space:pre-line}
  .lang{margin-top:1.25rem;font-size:.85rem}
  .lang a{color:var(--muted)}
</style>
</head>
<body>
<main class="card">
  <div class="cover"><div class="mark"><img src="<?= e($base) ?>/assets/icon.svg" alt="">ACIBADEM</div></div>
  <div class="avatar"><?php if (!empty($vcard['has_photo'])): ?><img src="<?= e($self) ?>?photo=1&amp;v=<?= e(strtotime($vcard['updated_at'] ?? 'now')) ?>" alt="<?= e($name) ?>"><?php else: ?><?= e($initials) ?><?php endif; ?></div>
  <div class="who">
    <h1><?= e($name) ?></h1>
    <?php if ($role !== ''): ?><p class="role"><?= e($role) ?></p><?php endif; ?>
    <?php if (!empty($vcard['org'])): ?><span class="org"><?= e($vcard['org']) ?></span><?php endif; ?>
  </div>

  <div class="quick">
    <?php $mainPhone = $vcard['phone_mobile'] ?: ($vcard['phone_work'] ?? ''); if ($mainPhone): ?><a href="<?= e($telHref($mainPhone)) ?>" aria-label="<?= e($T['call']) ?>" title="<?= e($T['call']) ?>"><?= $svg('phone') ?></a><?php endif; ?>
    <?php if (!empty($vcard['email'])): ?><a href="mailto:<?= e($vcard['email']) ?>" aria-label="<?= e($T['mail']) ?>" title="<?= e($T['mail']) ?>"><?= $svg('mail') ?></a><?php endif; ?>
    <?php if ($waNumber !== '' && strlen($waNumber) >= 8): ?><a href="https://wa.me/<?= e($waNumber) ?>" aria-label="<?= e($T['wa']) ?>" title="<?= e($T['wa']) ?>"><?= $svg('chat') ?></a><?php endif; ?>
    <?php if (!empty($vcard['website'])): ?><a href="<?= e($vcard['website']) ?>" aria-label="<?= e($T['web']) ?>" title="<?= e($T['web']) ?>"><?= $svg('globe') ?></a><?php endif; ?>
  </div>

  <a class="save" href="<?= e($self) ?>?vcf=1"><?= $svg('save') ?> <?= e($T['save']) ?></a>
  <p class="hint"><?= e($T['hint']) ?></p>

  <?php if ($rows): ?>
  <div class="list">
    <?php foreach ($rows as [$k, $label, $text, $href]): ?>
      <a class="row" href="<?= e($href) ?>"<?= str_starts_with($href, 'http') ? ' rel="noopener"' : '' ?>>
        <span class="ic"><?= $svg($k) ?></span>
        <div><small><?= e($label) ?></small><span><?= e($text) ?></span></div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if (!empty($vcard['note'])): ?><div class="note"><?= e($vcard['note']) ?></div><?php endif; ?>
</main>
<div class="lang"><a href="?<?= e($otherQs) ?>" hreflang="<?= $lang === 'tr' ? 'en' : 'tr' ?>"><?= e($T['other']) ?></a></div>
</body>
</html>
