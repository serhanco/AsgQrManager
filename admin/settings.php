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

            case 'automation':
                foreach (array_keys(Jobs::JOBS) as $k) Settings::set('job_' . $k, !empty($_POST['job'][$k]) ? '1' : '0');
                LinkEvents::log($pdo, null, null, Auth::actor(), 'settings', ['otomasyon' => [null, implode(', ', array_keys(array_filter((array)($_POST['job'] ?? []))))]]);
                flash('success', 'Otomasyon ayarları kaydedildi.');
                break;

            case 'run_job':
                $job = (string)($_POST['job'] ?? '');
                if (!isset(Jobs::JOBS[$job]) || !Jobs::available($pdo)) { $errors[] = 'Bilinmeyen görev.'; break; }
                @set_time_limit(300);
                $r = Jobs::run($pdo, $job, 'panel: ' . (Auth::actor()['name'] ?? ''));
                flash($r['status'] === 'error' ? 'danger' : ($r['status'] === 'skipped' ? 'warning' : 'success'),
                      Jobs::JOBS[$job][0] . ': ' . ['ok' => 'tamamlandı', 'skipped' => 'atlandı', 'error' => 'hata'][$r['status']] . ' — ' . $r['message']);
                redirect($self . '#automation');

            case 'mail':
                $host = trim((string)($_POST['smtp_host'] ?? ''));
                $port = (int)($_POST['smtp_port'] ?? 587);
                $secure = (string)($_POST['smtp_secure'] ?? 'tls');
                $from = trim((string)($_POST['mail_from'] ?? ''));
                $on = !empty($_POST['mail_enabled']);
                if ($host !== '' && !preg_match('/^[A-Za-z0-9.-]+$/', $host)) { $errors[] = 'SMTP sunucusu geçersiz (ör. smtp.hostinger.com).'; break; }
                if ($port < 1 || $port > 65535) { $errors[] = 'Port 1-65535 arasında olmalı.'; break; }
                if (!isset(Mailer::SECURE[$secure])) { $errors[] = 'Geçersiz güvenlik seçeneği.'; break; }
                if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Gönderen adresi geçersiz.'; break; }
                if ($on && ($host === '' || $from === '')) { $errors[] = 'E-postayı açmak için SMTP sunucusu ve gönderen adresi gerekli.'; break; }
                $extra = preg_split('/[\s,;]+/', trim((string)($_POST['mail_extra_to'] ?? ''))) ?: [];
                $extra = array_values(array_filter($extra));
                foreach ($extra as $a) if (!filter_var($a, FILTER_VALIDATE_EMAIL)) { $errors[] = "Ek alıcı geçersiz: {$a}"; break 2; }
                $freq = (string)($_POST['report_frequency'] ?? 'off');
                Settings::set('mail_enabled', $on ? '1' : '0');
                Settings::set('smtp_host', $host); Settings::set('smtp_port', (string)$port); Settings::set('smtp_secure', $secure);
                Settings::set('smtp_user', trim((string)($_POST['smtp_user'] ?? '')));
                // Parola alanı boş bırakılırsa mevcut parola korunur
                if ((string)($_POST['smtp_pass'] ?? '') !== '') Settings::set('smtp_pass', (string)$_POST['smtp_pass']);
                if (!empty($_POST['smtp_pass_clear'])) Settings::set('smtp_pass', '');
                Settings::set('mail_from', $from);
                Settings::set('mail_from_name', mb_substr(trim((string)($_POST['mail_from_name'] ?? '')), 0, 80) ?: 'Acıbadem QR Code Manager');
                Settings::set('report_frequency', in_array($freq, ['off', 'weekly', 'monthly'], true) ? $freq : 'off');
                Settings::set('report_editors', !empty($_POST['report_editors']) ? '1' : '0');
                Settings::set('mail_extra_to', implode(', ', $extra));
                LinkEvents::log($pdo, null, null, Auth::actor(), 'settings', ['e-posta' => [null, ($on ? 'açık' : 'kapalı') . " {$host}:{$port} rapor={$freq}"]]);
                flash('success', 'E-posta ayarları kaydedildi.' . ($on ? ' "Deneme e-postası gönder" ile kontrol edin.' : ''));
                redirect($self . '#mail');

            case 'mail_test':
                if (!Mailer::enabled()) { $errors[] = 'Önce e-postayı açıp SMTP bilgilerini kaydedin.'; break; }
                $st = $pdo->prepare('SELECT email FROM users WHERE id = ?');
                $st->execute([Auth::actor()['id'] ?? 0]);
                $to = trim((string)($_POST['test_to'] ?? '')) ?: (string)$st->fetchColumn();
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Deneme için bir e-posta adresi yazın (ya da Hesabım\'a e-posta ekleyin).'; break; }
                @set_time_limit(60);
                $err = Mailer::send([$to], 'Deneme e-postası — Acıbadem QR Code Manager',
                    '<p>Merhaba,</p><p>QR Code Manager e-posta ayarları çalışıyor. Uyarı ve rapor e-postaları bu adresten gönderilecek.</p>');
                if ($err) { $errors[] = 'Gönderilemedi: ' . $err; break; }
                flash('success', "Deneme e-postası gönderildi: {$to}");
                redirect($self . '#mail');

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
$jobsReady = Jobs::available($pdo);
$jobRuns  = $jobsReady ? Jobs::lastRuns($pdo) : [];
$cronSeen = Settings::get('cron_last_seen', '');
$dailyBackups = array_reverse(array_merge(glob(ROOT_DIR . '/logs/backups/daily-*.sql') ?: [], glob(ROOT_DIR . '/logs/backups/daily-*.sql.gz') ?: []));
rsort($dailyBackups);

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

<!-- Otomasyon -->
<div class="card" id="automation" style="grid-column:1/-1">
  <div class="card-title mb-2"><?= icon('clock') ?> Otomasyon (zamanlanmış görevler)</div>
  <?php if (!$jobsReady): ?>
    <p class="text-sm text-muted">Veritabanı güncellemesi bekleniyor. Çıkış yapıp yeniden giriş yapın.</p>
  <?php else: ?>
  <p class="text-sm text-muted mb-2">Hostinger hPanel &gt; <strong>Gelişmiş &gt; Cron Jobs</strong> bölümünde "Özel" seçip <strong>her saat</strong> (dakika 0) çalışacak şekilde şu komutu ekleyin.
    Her görev kendi sıklığında (günlük / haftalık) bir kez çalışır; görevler QR kayıtlarını değiştirmez.</p>
  <div class="cron-cmd mb-2"><code id="cron-cmd"><?= e(Jobs::cronCommand()) ?></code>
    <button class="btn btn-ghost btn-sm copy-btn" type="button" data-copy="<?= e(Jobs::cronCommand()) ?>"><?= icon('copy') ?> Kopyala</button></div>
  <p class="text-xs mb-3 <?= $cronSeen === '' ? 'text-danger' : 'text-muted' ?>"><?= $cronSeen === ''
      ? 'Cron henüz hiç çalışmadı. Komutu ekledikten sonra bir saat içinde burada son çalışma zamanı görünür.'
      : 'Cron en son ' . e(date('d.m.Y H:i', strtotime($cronSeen))) . ' tarihinde çalıştı.' ?></p>
  <form method="POST">
    <?= Auth::csrfField() ?><input type="hidden" name="action" value="automation">
    <div class="table-wrap"><table class="job-table">
      <thead><tr><th>Açık</th><th>Görev</th><th>Sıklık</th><th>Son çalışma</th><th></th></tr></thead>
      <tbody>
      <?php foreach (Jobs::JOBS as $k => [$name, $desc]): $p = Jobs::period($k); $r = $jobRuns[$k] ?? null; ?>
        <tr>
          <td><input type="checkbox" name="job[<?= $k ?>]" value="1" <?= Jobs::enabled($k) ? 'checked' : '' ?> aria-label="<?= e($name) ?>"></td>
          <td><span class="fw-bold"><?= e($name) ?></span><div class="text-xs text-muted"><?= e($desc) ?></div></td>
          <td class="text-sm" style="white-space:nowrap"><?= $p ? e(Jobs::PERIODS[$p]) : '<span class="text-muted">kapalı</span>' ?></td>
          <td class="text-xs"><?php if ($r): ?>
              <span class="badge <?= ['ok' => 'badge-success', 'error' => 'badge-danger', 'skipped' => 'badge-muted'][$r['status']] ?? 'badge-warning' ?>"><?= e(['ok' => 'tamam', 'error' => 'hata', 'skipped' => 'atlandı', 'running' => 'sürüyor'][$r['status']] ?? $r['status']) ?></span>
              <?= e(date('d.m H:i', strtotime($r['started_at']))) ?><div class="text-muted"><?= e((string)$r['message']) ?></div>
            <?php else: ?><span class="text-muted">hiç çalışmadı</span><?php endif; ?></td>
          <td><button class="btn btn-ghost btn-sm" type="submit" form="run-<?= $k ?>" title="Şimdi çalıştır"><?= icon('play') ?></button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <button class="btn btn-primary btn-sm mt-3" type="submit"><?= icon('save') ?> Kaydet</button>
  </form>
  <?php foreach (array_keys(Jobs::JOBS) as $k): ?>
    <form method="POST" id="run-<?= $k ?>" hidden><?= Auth::csrfField() ?><input type="hidden" name="action" value="run_job"><input type="hidden" name="job" value="<?= $k ?>"></form>
  <?php endforeach; ?>
  <?php if ($dailyBackups): ?><p class="text-xs text-muted mt-2">Gecelik yedekler (sunucuda, logs/backups): <?= e(implode(', ', array_map('basename', array_slice($dailyBackups, 0, 3)))) ?><?= count($dailyBackups) > 3 ? ' …' : '' ?></p><?php endif; ?>
  <?php endif; ?>
</div>

<!-- E-posta -->
<form method="POST" class="card" id="mail" style="grid-column:1/-1" autocomplete="off">
  <?= Auth::csrfField() ?><input type="hidden" name="action" value="mail">
  <div class="card-title mb-2"><?= icon('mail') ?> E-posta <span class="badge <?= Mailer::enabled() ? 'badge-success' : 'badge-muted' ?>"><?= Mailer::enabled() ? 'açık' : 'kapalı' ?></span></div>
  <p class="text-sm text-muted mb-3">Uyarı özetleri ve periyodik raporlar e-postayla gider. Kapalıyken uyarılar yalnızca panelde (Uyarılar) görünür.
    Alıcılar: e-posta adresi kayıtlı aktif kullanıcılar (yönetici tümünü, editör kendi QR'larını) ve aşağıdaki ek alıcılar. Hostinger e-postası için sunucu <code>smtp.hostinger.com</code>, port 465 (SSL) ya da 587 (STARTTLS).</p>
  <label class="form-check mb-3"><input type="checkbox" name="mail_enabled" value="1" <?= Settings::get('mail_enabled', '0') === '1' ? 'checked' : '' ?>> <span>E-posta gönderimi açık</span></label>
  <div class="form-row">
    <div class="form-group"><label class="form-label" for="smtp_host">SMTP sunucusu</label>
      <input class="form-control" id="smtp_host" name="smtp_host" value="<?= e(Settings::get('smtp_host', '')) ?>" placeholder="smtp.hostinger.com"></div>
    <div class="form-group"><label class="form-label" for="smtp_port">Port</label>
      <input class="form-control" id="smtp_port" name="smtp_port" type="number" min="1" max="65535" value="<?= e(Settings::get('smtp_port', '465')) ?>"></div>
    <div class="form-group"><label class="form-label" for="smtp_secure">Güvenlik</label>
      <select class="form-control" id="smtp_secure" name="smtp_secure">
        <?php foreach (Mailer::SECURE as $k => $label): ?><option value="<?= $k ?>" <?= Settings::get('smtp_secure', 'ssl') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select></div>
  </div>
  <div class="form-row">
    <div class="form-group"><label class="form-label" for="smtp_user">Kullanıcı adı</label>
      <input class="form-control" id="smtp_user" name="smtp_user" value="<?= e(Settings::get('smtp_user', '')) ?>" autocomplete="off" placeholder="qr@acibadem.world"></div>
    <div class="form-group"><label class="form-label" for="smtp_pass">Parola <span class="hint"><?= Settings::get('smtp_pass', '') !== '' ? 'kayıtlı; değiştirmek için yazın' : 'kayıtlı değil' ?></span></label>
      <input class="form-control" id="smtp_pass" name="smtp_pass" type="password" value="" autocomplete="new-password"></div>
  </div>
  <div class="form-row">
    <div class="form-group"><label class="form-label" for="mail_from">Gönderen adresi</label>
      <input class="form-control" id="mail_from" name="mail_from" type="email" value="<?= e(Settings::get('mail_from', '')) ?>" placeholder="qr@acibadem.world"></div>
    <div class="form-group"><label class="form-label" for="mail_from_name">Gönderen adı</label>
      <input class="form-control" id="mail_from_name" name="mail_from_name" value="<?= e(Settings::get('mail_from_name', 'Acıbadem QR Code Manager')) ?>"></div>
  </div>
  <div class="form-row">
    <div class="form-group"><label class="form-label" for="report_frequency">Periyodik rapor e-postası</label>
      <select class="form-control" id="report_frequency" name="report_frequency">
        <?php foreach (['off' => 'Kapalı', 'weekly' => 'Haftalık (pazartesi, geçen hafta)', 'monthly' => 'Aylık (ayın 1\'i, geçen ay)'] as $k => $label): ?>
          <option value="<?= $k ?>" <?= Settings::get('report_frequency', 'off') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="form-group"><label class="form-label" for="mail_extra_to">Ek alıcılar <span class="hint">virgülle; yönetici kopyasını alır</span></label>
      <input class="form-control" id="mail_extra_to" name="mail_extra_to" value="<?= e(Settings::get('mail_extra_to', '')) ?>" placeholder="pazarlama@acibadem.world"></div>
  </div>
  <label class="form-check mb-3"><input type="checkbox" name="report_editors" value="1" <?= Settings::get('report_editors', '1') === '1' ? 'checked' : '' ?>> <span>Editörler de kendi QR'larının raporunu alsın</span></label>
  <div class="btn-group">
    <button class="btn btn-primary btn-sm" type="submit"><?= icon('save') ?> Kaydet</button>
    <?php if (Mailer::enabled()): ?>
      <input class="form-control" form="mail-test" name="test_to" type="email" placeholder="deneme alıcısı (boşsa sizin adresiniz)" style="max-width:280px">
      <button class="btn btn-ghost btn-sm" type="submit" form="mail-test"><?= icon('mail') ?> Deneme e-postası gönder</button>
    <?php endif; ?>
  </div>
</form>
<form method="POST" id="mail-test" hidden><?= Auth::csrfField() ?><input type="hidden" name="action" value="mail_test"></form>

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
