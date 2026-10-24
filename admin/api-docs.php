<?php
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();
Auth::requirePerm('admin');

$api = BASE_URL . '/api/v1';
$pageTitle  = 'API Dokümantasyonu';
$activePage = 'api-tokens';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title">📖 API Dokümantasyonu</h1>
  <a class="btn btn-ghost" href="<?= BASE_URL ?>/admin/api-tokens">🔌 Anahtarlar</a>
</div>

<div class="card doc">
  <h3>Genel</h3>
  <ul>
    <li>Taban adres: <code><?= e($api) ?></code> — yalnızca HTTPS.</li>
    <li>Kimlik doğrulama: <code>Authorization: Bearer qrm_…</code> (veya <code>X-API-Key</code>). Anahtarı <a href="<?= BASE_URL ?>/admin/api-tokens">API Anahtarları</a>'ndan oluşturun; yalnızca sunucudan sunucuya kullanın. Bir kullanıcıya bağlanan anahtar o kullanıcının rolü ve kapsamıyla çalışır (kapsam dışındaki QR'lar 404 döner, arşivleme yalnızca yönetici yetkisiyle).</li>
    <li>İstek/yanıt: JSON (<code>Content-Type: application/json</code>). Hata biçimi: <code>{"error":{"code":"…","message":"…"}}</code></li>
    <li>QR'lar API ile <strong>silinemez</strong>; <code>archive</code> ile arşivlenir ve <code>restore</code> ile geri alınır. Kısa ad (<code>slug</code>) oluşturulduktan sonra değiştirilemez (basılı QR bozulmasın diye).</li>
    <li>Tarih/saatler panelde seçili saat dilimine göredir (<code><?= e(Settings::timezone()->getName()) ?></code>), biçim <code>2026-11-05 18:30</code>.</li>
  </ul>

  <h3>Uç noktalar</h3>
  <table>
    <thead><tr><th>Yöntem</th><th>Adres</th><th>Açıklama</th></tr></thead>
    <tbody>
      <tr><td>GET</td><td><code>/ping</code></td><td>Anahtarı ve bağlantıyı doğrular</td></tr>
      <tr><td>GET</td><td><code>/tags</code></td><td>Kullanılan etiketler ve QR/okutma sayıları. Benzer yazımlar (İstanbul/istanbul) tek etiket sayılır; yazarken mevcut yazım korunur.</td></tr>
      <tr><td>GET</td><td><code>/links</code></td><td>Liste. Filtre: <code>q, status, category, tag, page, per_page</code> (<code>tag=__none</code> etiketsiz QR’lar) (status: live, inactive, scheduled, expired, archived)</td></tr>
      <tr><td>POST</td><td><code>/links</code></td><td>QR oluştur (yazma yetkisi)</td></tr>
      <tr><td>GET</td><td><code>/links/{slug}</code></td><td>Tek QR</td></tr>
      <tr><td>PATCH</td><td><code>/links/{slug}</code></td><td>Gönderilen alanları güncelle (örn. yeni hedef URL)</td></tr>
      <tr><td>POST</td><td><code>/links/{slug}/activate</code> · <code>deactivate</code> · <code>archive</code> · <code>restore</code></td><td>Durum değiştir</td></tr>
      <tr><td>GET</td><td><code>/links/{slug}/qr?format=svg|png|pdf|eps|ai&amp;size=&amp;transparent=1&amp;download=1</code></td><td>QR dosyası. PNG size: 600, 1200, 2000, 3000 px · PDF / EPS / AI size: 30, 50, 100, 200 mm (EPS ve AI CMYK)</td></tr>
      <tr><td>GET</td><td><code>/links/{slug}/stats?from=YYYY-MM-DD&amp;to=YYYY-MM-DD</code></td><td>Okutma istatistikleri (günlük, cihaz, ülke…)</td></tr>
    </tbody>
  </table>

  <h3>QR oluşturma alanları</h3>
  <table>
    <thead><tr><th>Alan</th><th>Açıklama</th></tr></thead>
    <tbody>
      <tr><td><code>target_url</code> *</td><td>Hedef adres (http/https)</td></tr>
      <tr><td><code>slug</code></td><td>İsteğe bağlı özel kısa ad (3–16 karakter, A-Z a-z 0-9 _ -). Boşsa üretilir.</td></tr>
      <tr><td><code>title</code>, <code>notes</code></td><td>Başlık, iç not</td></tr>
      <tr><td><code>category</code></td><td><code>hq</code> · <code>office</code> · <code>event</code> · <code>campaign</code> · <code>other</code></td></tr>
      <tr><td><code>tags</code></td><td>Dizi veya virgüllü metin</td></tr>
      <tr><td><code>starts_at</code>, <code>ends_at</code>, <code>after_end_url</code></td><td>Zamanlı hedef: bitince QR otomatik <code>after_end_url</code>'ye gider</td></tr>
      <tr><td><code>fallback_url</code></td><td>Pasif/arşiv/zamanı gelmemiş QR için yedek hedef</td></tr>
      <tr><td><code>utm_enabled</code>, <code>utm_campaign</code></td><td>Hedefe otomatik UTM ekler</td></tr>
      <tr><td><code>fg_color</code>, <code>bg_color</code>, <code>shape</code></td><td>Görünüm (<code>#0B2A5B</code>, <code>square|rounded|dots</code>). Boşsa marka varsayılanı. Kontrast en az 4:1.</td></tr>
      <tr><td><code>logo</code></td><td>Logo galerisindeki dosya adı</td></tr>
      <tr><td><code>is_active</code></td><td>Varsayılan <code>true</code></td></tr>
    </tbody>
  </table>

  <h3>Örnekler</h3>
<pre>
# Etkinlik QR'ı oluştur (bitince ofis sayfasına geçer)
curl -X POST <?= e($api) ?>/links \
  -H "Authorization: Bearer $QRM_TOKEN" -H "Content-Type: application/json" \
  -d '{"title":"Arab Health 2027","slug":"arabhealth-2027","category":"event","tags":["dubai","fuar"],
       "target_url":"https://acibadem.world/events/arab-health-2027",
       "ends_at":"2027-02-01 00:00","after_end_url":"https://acibadem.world/office/dubai",
       "utm_enabled":true,"fg_color":"#0B2A5B","bg_color":"#FFFFFF"}'

# Hedefi değiştir (QR aynı kalır)
curl -X PATCH <?= e($api) ?>/links/arabhealth-2027 \
  -H "Authorization: Bearer $QRM_TOKEN" -H "Content-Type: application/json" \
  -d '{"target_url":"https://acibadem.world/events/arab-health-2027-program"}'

# Matbaa için PDF (100 mm) indir
curl -o qr.pdf -H "Authorization: Bearer $QRM_TOKEN" "<?= e($api) ?>/links/arabhealth-2027/qr?format=pdf&amp;size=100"

# Son 30 günün istatistiği
curl -H "Authorization: Bearer $QRM_TOKEN" "<?= e($api) ?>/links/arabhealth-2027/stats"
</pre>
</div>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
