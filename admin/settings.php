<?php
/**
 * settings.php — marka varsayılanları, yönlendirme, gizlilik (KVKK), paylaşım, logo galerisi, veritabanı durumu
 */
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();
Auth::requirePerm('admin');

$pdo    = getPdo();
$errors = [];
$self   = BASE_URL . '/admin/settings';

// ─── Logo yükleme yardımcıları ────────────────────────────────────────────────
/** Yüklenen görseli doğrular ve GD ile yeniden kodlayarak (içindeki olası zararlı veriyi atar) logo/ klasörüne yazar. */
function saveUploadedLogo(array $file): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Dosya yüklenemedi (hata kodu ' . (int)$file['error'] . ').');
    if ($file['size'] > 2 * 1024 * 1024) throw new RuntimeException('Logo en fazla 2 MB olabilir.');
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('Yalnızca PNG, JPG veya WEBP yüklenebilir (SVG güvenlik nedeniyle kabul edilmez).');
    }
    if ($info[0] > 4000 || $info[1] > 4000) throw new RuntimeException('Logo en fazla 4000 x 4000 piksel olabilir.');
    if (!extension_loaded('gd')) throw new RuntimeException('Sunucuda GD eklentisi yok; logo yüklenemiyor.');

    $img = @imagecreatefromstring((string)file_get_contents($file['tmp_name']));
    if ($img === false) throw new RuntimeException('Görsel okunamadı.');

    $isJpeg = $info[2] === IMAGETYPE_JPEG;
    $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', pathinfo($file['name'], PATHINFO_FILENAME));
    $base = trim(substr($base, 0, 40), '-_') ?: 'logo';
    $ext  = $isJpeg ? 'jpg' : 'png';
    $name = "{$base}.{$ext}";
    for ($i = 2; file_exists(LOGO_DIR . '/' . $name); $i++) $name = "{$base}-{$i}.{$ext}";

    if (!is_dir(LOGO_DIR)) @mkdir(LOGO_DIR, 0755, true);
    if ($isJpeg) {
        $ok = imagejpeg($img, LOGO_DIR . '/' . $name, 92);
    } else {
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $ok = imagepng($img, LOGO_DIR . '/' . $name, 6);
    }
    imagedestroy($img);
    if (!$ok) throw new RuntimeException('Logo klasörüne yazılamadı (izinleri kontrol edin).');
    @chmod(LOGO_DIR . '/' . $name, 0644);
    return $name;
}

// ─── Kaydetme ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'brand':
                $fg = LinkService::normalizeHex($_POST['brand_fg'] ?? '');
                $bg = LinkService::normalizeHex($_POST['brand_bg'] ?? '');
                $shape = $_POST['brand_shape'] ?? 'square';
                if (!$fg || !$bg) { $errors[] = 'Renkler #RRGGBB biçiminde olmalı.'; break; }
                if (($e = LinkService::colorError($fg, $bg)) !== null) { $errors[] = $e; break; }
                if (!isset(LinkService::SHAPES[$shape])) { $errors[] = 'Geçersiz şekil.'; break; }
                $logo = (string)($_POST['brand_logo'] ?? '');
                if ($logo !== '' && !validateLogoFile($logo)) { $errors[] = 'Geçersiz logo.'; break; }
                Settings::set('brand_fg', $fg); Settings::set('brand_bg', $bg);
                Settings::set('brand_shape', $shape); Settings::set('brand_logo', $logo);
                // Marka varsayılanını kullanan QR'ların önbelleği eskidi
                QrRenderer::clearAllCache();
                LinkEvents::log($pdo, null, null, Auth::actor(), 'settings', ['marka' => [null, "$fg / $bg / $shape"]]);
                flash('success', 'Marka varsayılanları kaydedildi. Varsayılanı kullanan QR\'lar yeni görünümle üretilecek.');
                break;

            case 'routing':
                $fb = trim($_POST['fallback_url'] ?? '');
                if ($fb !== '' && !isValidUrl($fb)) { $errors[] = 'Genel yedek hedef geçerli bir http(s) adresi olmalı.'; break; }
                $tz = $_POST['timezone'] ?? '';
                if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) { $errors[] = 'Geçersiz saat dilimi.'; break; }
                $us = preg_replace('/[^A-Za-z0-9_\-.]/', '', $_POST['utm_source'] ?? '') ?: 'qr';
                $um = preg_replace('/[^A-Za-z0-9_\-.]/', '', $_POST['utm_medium'] ?? '') ?: 'print';
                Settings::set('fallback_url', $fb); Settings::set('timezone', $tz);
                Settings::set('utm_source', $us);   Settings::set('utm_medium', $um);
                LinkEvents::log($pdo, null, null, Auth::actor(), 'settings', ['yönlendirme' => [null, "yedek=$fb tz=$tz utm=$us/$um"]]);
                flash('success', 'Yönlendirme ayarları kaydedildi.');
                break;

            case 'info_page':
                $url = trim($_POST['info_page_url'] ?? '');
                if (!isValidUrl($url)) { $errors[] = 'Bilgi sayfası bağlantısı geçerli bir http(s) adresi olmalı.'; break; }
                $sec = max(0, min(60, (int)($_POST['info_page_redirect_seconds'] ?? 0)));
                $mtr = mb_substr(trim($_POST['info_page_message_tr'] ?? ''), 0, 500);
                $men = mb_substr(trim($_POST['info_page_message_en'] ?? ''), 0, 500);
                Settings::set('info_page_url', $url); Settings::set('info_page_redirect_seconds', (string)$sec);
                Settings::set('info_page_message_tr', $mtr); Settings::set('info_page_message_en', $men);
                LinkEvents::log($pdo, null, null, Auth::actor(), 'settings', ['bilgi sayfası' => [null, "buton=$url geçiş={$sec}sn"]]);
                flash('success', 'Bilgi sayfası ayarları kaydedildi.');
                break;

            case 'privacy':
                $mode = $_POST['ip_mode'] ?? 'full';
                if (!in_array($mode, ['full', 'mask', 'none'], true)) { $errors[] = 'Geçersiz IP modu.'; break; }
                $days = max(0, min(3650, (int)($_POST['ip_anonymize_days'] ?? 0)));
                Settings::set('ip_mode', $mode); Settings::set('ip_anonymize_days', (string)$days);
                LinkEvents::log($pdo, null, null, Auth::actor(), 'settings', ['gizlilik' => [null, "ip=$mode anonim={$days}g"]]);
                flash('success', 'Gizlilik ayarları kaydedildi.');
                break;

            case 'anonymize_now':
                $days = (int)Settings::get('ip_anonymize_days');
                if ($days < 1) { $errors[] = 'Önce "IP anonimleştirme süresi"ni (gün) kaydedin.'; break; }
                $c = anonymizeOldScans($pdo, $days);
                flash('success', "{$c} okutma kaydının IP adresi maskelendi.");
                break;

            case 'share':
                $d = (int)($_POST['share_ttl_days'] ?? 14);
                Settings::set('share_ttl_days', (string)(in_array($d, [1, 7, 14, 30, 90], true) ? $d : 14));
                flash('success', 'Paylaşım ayarı kaydedildi.');
                break;

            case 'revoke_share':
                Settings::set('share_secret', bin2hex(random_bytes(32)));
                LinkEvents::log($pdo, null, null, Auth::actor(), 'settings', ['paylaşım' => ['açık', 'tümü iptal']]);
                flash('success', 'Tüm paylaşım bağlantıları iptal edildi.');
                break;

            case 'upload_logo':
                $name = saveUploadedLogo($_FILES['logo'] ?? []);
                flash('success', "Logo yüklendi: {$name}");
                break;

            case 'delete_logo':
                $name = (string)($_POST['logo_name'] ?? '');
                if (!validateLogoFile($name)) { $errors[] = 'Logo bulunamadı.'; break; }
                $use = $pdo->prepare('SELECT COUNT(*) FROM links WHERE logo = ?');
                $use->execute([$name]);
                if ((int)$use->fetchColumn() > 0 || Settings::get('brand_logo') === $name) {
                    $errors[] = 'Bu logo bir QR\'da veya marka varsayılanında kullanılıyor; silinemez.'; break;
                }
                @unlink(LOGO_DIR . '/' . $name);
                flash('success', "Logo silindi: {$name}");
                break;
        }
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    }

    if (!$errors) redirect($self);
}

// ─── Görünüm verileri ─────────────────────────────────────────────────────────
$logos   = listLogos();
$usage   = $pdo->query('SELECT logo, COUNT(*) c FROM links WHERE logo IS NOT NULL GROUP BY logo')->fetchAll(PDO::FETCH_KEY_PAIR);
$migs    = Migrator::status($pdo);
$backups = array_reverse(glob(ROOT_DIR . '/logs/backups/pre-migrate-*-links.sql') ?: []);
$tokens  = (int)$pdo->query('SELECT COUNT(*) FROM api_tokens WHERE revoked_at IS NULL')->fetchColumn();
$scanRows = (int)$pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn();
$linkRows = (int)$pdo->query('SELECT COUNT(*) FROM links')->fetchColumn();

$pageTitle  = 'Ayarlar';
$activePage = 'settings';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header"><h1 class="page-title"><?= icon('settings') ?> Ayarlar</h1></div>

<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

<div class="settings-grid">

<!-- Marka -->
<form method="POST" class="card">
  <?= Auth::csrfField() ?><input type="hidden" name="action" value="brand">
  <div class="card-title mb-2"><?= icon('palette') ?> Marka varsayılanları</div>
  <p class="text-sm text-muted mb-3">"Marka varsayılanı" seçili tüm QR'lar bu görünümle üretilir; burada değiştirince hepsi birlikte güncellenir. Yeni QR formunda varsayılan logo da buradan gelir.</p>
  <div class="form-row">
    <div class="form-group"><label class="form-label">QR rengi</label>
      <div class="color-field"><input type="color" id="s_fg_p" value="<?= e(strtolower(Settings::get('brand_fg'))) ?>">
      <input class="form-control" type="text" name="brand_fg" id="s_fg" maxlength="7" value="<?= e(Settings::get('brand_fg')) ?>"></div></div>
    <div class="form-group"><label class="form-label">Arka plan</label>
      <div class="color-field"><input type="color" id="s_bg_p" value="<?= e(strtolower(Settings::get('brand_bg'))) ?>">
      <input class="form-control" type="text" name="brand_bg" id="s_bg" maxlength="7" value="<?= e(Settings::get('brand_bg')) ?>"></div></div>
  </div>
  <div class="form-row">
    <div class="form-group"><label class="form-label">Nokta şekli</label>
      <select class="form-control" name="brand_shape">
        <?php foreach (LinkService::SHAPES as $k => $l): ?><option value="<?= e($k) ?>" <?= Settings::get('brand_shape') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select></div>
    <div class="form-group"><label class="form-label">Yeni QR'larda varsayılan logo</label>
      <select class="form-control" name="brand_logo">
        <option value="">Logo yok</option>
        <?php foreach ($logos as $lf): ?><option value="<?= e($lf) ?>" <?= Settings::get('brand_logo') === $lf ? 'selected' : '' ?>><?= e($lf) ?></option><?php endforeach; ?>
      </select></div>
  </div>
  <button class="btn btn-primary" type="submit">Kaydet</button>
  <p class="text-xs text-muted mt-2">Kaydedince QR önbelleği temizlenir; dosyalar bir sonraki istekte yeni görünümle üretilir. Basılı QR'ların <em>içeriği</em> (kısa adres) değişmez.</p>
</form>

<!-- Yönlendirme -->
<form method="POST" class="card">
  <?= Auth::csrfField() ?><input type="hidden" name="action" value="routing">
  <div class="card-title mb-2"><?= icon('shuffle') ?> Yönlendirme</div>
  <div class="form-group">
    <label class="form-label">Genel yedek hedef <span class="hint">Pasif, arşivlenmiş veya süresi dolmuş QR okutulunca (QR'a özel yedek yoksa) ziyaretçi buraya gider. Boşsa aşağıdaki bilgi sayfası gösterilir.</span></label>
    <input class="form-control" type="url" name="fallback_url" placeholder="https://acibadem.world/" value="<?= e(Settings::get('fallback_url')) ?>">
  </div>
  <div class="form-group">
    <label class="form-label">Saat dilimi <span class="hint">Zamanlı hedeflerin başlangıç/bitiş saatleri buna göre çalışır</span></label>
    <select class="form-control" name="timezone">
      <?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option value="<?= e($tz) ?>" <?= Settings::timezone()->getName() === $tz ? 'selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="form-row">
    <div class="form-group"><label class="form-label">UTM kaynak (utm_source)</label><input class="form-control" type="text" name="utm_source" value="<?= e(Settings::get('utm_source')) ?>"></div>
    <div class="form-group"><label class="form-label">UTM ortam (utm_medium)</label><input class="form-control" type="text" name="utm_medium" value="<?= e(Settings::get('utm_medium')) ?>"></div>
  </div>
  <button class="btn btn-primary" type="submit">Kaydet</button>
</form>

<!-- Bilgi sayfası -->
<form method="POST" class="card">
  <?= Auth::csrfField() ?><input type="hidden" name="action" value="info_page">
  <div class="card-title mb-2"><?= icon('info') ?> Bilgi sayfası</div>
  <p class="text-sm text-muted mb-2">Pasif, arşivlenmiş, zamanı gelmemiş veya süresi dolmuş bir QR okutulduğunda yedek hedef tanımlı değilse ziyaretçi 404 yerine Acıbadem logolu bu sayfayı görür (Türkçe/İngilizce, tarayıcı diline göre).
    Önizle:
    <?php foreach (['inactive' => 'pasif', 'expired' => 'süresi dolmuş', 'scheduled' => 'zamanı gelmemiş', 'unknown' => 'bilinmeyen'] as $st => $lbl): ?>
      <a href="<?= e(BASE_URL) ?>/admin/info-preview?state=<?= $st ?>" target="_blank" rel="noopener"><?= $lbl ?></a><?= $st !== 'unknown' ? ' ·' : '' ?>
    <?php endforeach; ?>
  </p>
  <div class="form-row">
    <div class="form-group"><label class="form-label">Buton bağlantısı <span class="hint">Varsayılan: ACIBADEM.WORLD ana sayfası</span></label>
      <input class="form-control" type="url" name="info_page_url" required placeholder="https://acibadem.world/" value="<?= e(Settings::get('info_page_url')) ?>"></div>
    <div class="form-group"><label class="form-label">Otomatik geçiş (saniye) <span class="hint">0 = kapalı; ziyaretçi butona kendisi basar</span></label>
      <input class="form-control" type="number" min="0" max="60" name="info_page_redirect_seconds" value="<?= e(Settings::get('info_page_redirect_seconds')) ?>"></div>
  </div>
  <div class="form-row">
    <div class="form-group"><label class="form-label">Ek not (Türkçe) <span class="hint">İsteğe bağlı</span></label>
      <textarea class="form-control" name="info_page_message_tr" rows="2" maxlength="500"><?= e(Settings::get('info_page_message_tr')) ?></textarea></div>
    <div class="form-group"><label class="form-label">Ek not (English) <span class="hint">Optional</span></label>
      <textarea class="form-control" name="info_page_message_en" rows="2" maxlength="500"><?= e(Settings::get('info_page_message_en')) ?></textarea></div>
  </div>
  <button class="btn btn-primary" type="submit">Kaydet</button>
</form>

<!-- Gizlilik -->
<div class="card">
  <div class="card-title mb-2"><?= icon('lock') ?> Gizlilik (KVKK)</div>
  <form method="POST">
    <?= Auth::csrfField() ?><input type="hidden" name="action" value="privacy">
    <div class="form-group">
      <label class="form-label">Yeni okutmalarda IP adresi</label>
      <select class="form-control" name="ip_mode">
        <option value="full" <?= Settings::get('ip_mode') === 'full' ? 'selected' : '' ?>>Tam sakla (mevcut davranış)</option>
        <option value="mask" <?= Settings::get('ip_mode') === 'mask' ? 'selected' : '' ?>>Maskele (örn. 203.0.113.0) — önerilir</option>
        <option value="none" <?= Settings::get('ip_mode') === 'none' ? 'selected' : '' ?>>Hiç saklama</option>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Eski kayıtların IP'sini maskele (gün) <span class="hint">0 = kapalı. Okutma sayıları ve raporlar etkilenmez, satırlar silinmez.</span></label>
      <input class="form-control" type="number" min="0" max="3650" name="ip_anonymize_days" value="<?= (int)Settings::get('ip_anonymize_days') ?>">
    </div>
    <button class="btn btn-primary" type="submit">Kaydet</button>
  </form>
  <form method="POST" class="mt-3" data-confirm="Belirlenen süreden eski kayıtların IP adresleri kalıcı olarak maskelenecek. Devam edilsin mi?">
    <?= Auth::csrfField() ?><input type="hidden" name="action" value="anonymize_now">
    <button class="btn btn-ghost btn-sm" type="submit">Şimdi uygula</button>
  </form>
</div>

<!-- Paylaşım -->
<div class="card">
  <div class="card-title mb-2"><?= icon('share') ?> İndirme bağlantıları</div>
  <form method="POST">
    <?= Auth::csrfField() ?><input type="hidden" name="action" value="share">
    <div class="form-group"><label class="form-label">Varsayılan geçerlilik süresi</label>
      <select class="form-control" name="share_ttl_days" style="max-width:200px">
        <?php foreach ([1, 7, 14, 30, 90] as $d): ?><option value="<?= $d ?>" <?= (int)Settings::get('share_ttl_days') === $d ? 'selected' : '' ?>><?= $d ?> gün</option><?php endforeach; ?>
      </select></div>
    <button class="btn btn-primary" type="submit">Kaydet</button>
  </form>
  <form method="POST" class="mt-3" data-confirm="Daha önce oluşturulan TÜM indirme bağlantıları çalışmaz hale gelecek. Devam edilsin mi?">
    <?= Auth::csrfField() ?><input type="hidden" name="action" value="revoke_share">
    <button class="btn btn-ghost btn-sm" type="submit" style="color:var(--danger)">Tüm paylaşım bağlantılarını iptal et</button>
  </form>
</div>

<!-- API -->
<div class="card">
  <div class="card-title mb-2"><?= icon('code') ?> API erişimi</div>
  <p class="text-sm text-muted mb-3">ACIBADEM.WORLD paneli veya başka sistemler QR üretebilir, hedefi güncelleyebilir, istatistik çekebilir. Etkin anahtar: <strong><?= $tokens ?></strong></p>
  <a class="btn btn-primary" href="<?= BASE_URL ?>/admin/api-tokens">API anahtarlarını yönet</a>
  <a class="btn btn-ghost" href="<?= BASE_URL ?>/admin/api-docs">Dokümantasyon</a>
</div>

<!-- Logo galerisi -->
<div class="card" style="grid-column:1/-1">
  <div class="card-title mb-2"><?= icon('image') ?> Logo galerisi</div>
  <div class="logo-gallery mb-3">
    <?php if (!$logos): ?><p class="text-sm text-muted">Henüz logo yok.</p><?php endif; ?>
    <?php foreach ($logos as $lf): $used = (int)($usage[$lf] ?? 0) + (Settings::get('brand_logo') === $lf ? 1 : 0); ?>
      <div class="logo-item" style="cursor:default">
        <img src="<?= BASE_URL ?>/logo/<?= rawurlencode($lf) ?>" alt="<?= e($lf) ?>" loading="lazy">
        <span><?= e($lf) ?></span>
        <span class="text-xs text-muted"><?= $used ?> kullanım</span>
        <?php if ($used === 0): ?>
          <form method="POST" data-confirm="'<?= e($lf) ?>' silinsin mi?">
            <?= Auth::csrfField() ?><input type="hidden" name="action" value="delete_logo"><input type="hidden" name="logo_name" value="<?= e($lf) ?>">
            <button class="btn btn-ghost btn-sm" type="submit" style="color:var(--danger);padding:.1rem .4rem">Sil</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <form method="POST" enctype="multipart/form-data" class="btn-group">
    <?= Auth::csrfField() ?><input type="hidden" name="action" value="upload_logo">
    <input class="form-control" type="file" name="logo" accept="image/png,image/jpeg,image/webp" required style="max-width:340px">
    <button class="btn btn-primary" type="submit">Logo yükle</button>
  </form>
  <p class="text-xs text-muted mt-2">PNG, JPG veya WEBP · en fazla 2 MB · en fazla 4000 × 4000 px. Saydam arka planlı kare veya yatay PNG en iyi sonucu verir. Logo QR alanının ~%15'ine yerleştirilir.</p>
</div>

<!-- Veritabanı -->
<div class="card" style="grid-column:1/-1">
  <div class="card-title mb-2"><?= icon('archive') ?> Veritabanı ve yedek</div>
  <p class="text-sm text-muted mb-3">
    <?= $linkRows ?> QR kaydı, <?= $scanRows ?> okutma kaydı. Güncellemeler yalnızca <strong>ekleme</strong> yapar (yeni sütun/tablo); mevcut kayıtlar silinmez ve değiştirilmez. Her güncellemeden önce <code>links</code> tablosunun yedeği <code>logs/backups/</code> klasörüne alınır.
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>Adım</th><th>Açıklama</th><th>Durum</th></tr></thead>
    <tbody>
    <?php foreach ($migs as $m): ?>
      <tr><td class="font-mono text-xs"><?= e($m['id']) ?></td><td class="text-sm"><?= e($m['description']) ?></td>
          <td class="text-sm"><?= $m['applied_at'] ? '✓ ' . e(substr($m['applied_at'], 0, 16)) : 'bekliyor' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($backups): ?>
    <p class="text-xs text-muted mt-2">Son yedekler (sunucuda): <?= e(implode(', ', array_map('basename', array_slice($backups, 0, 3)))) ?></p>
  <?php endif; ?>
</div>

</div>

<script>
[['s_fg', 's_fg_p'], ['s_bg', 's_bg_p']].forEach(([t, p]) => {
  const T = document.getElementById(t), P = document.getElementById(p);
  P.addEventListener('input', () => T.value = P.value.toUpperCase());
  T.addEventListener('input', () => { if (/^#[0-9a-fA-F]{6}$/.test(T.value)) P.value = T.value.toLowerCase(); });
});
</script>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
