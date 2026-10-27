<?php
/**
 * _link_form.php — create ve edit sayfalarının ortak form alanları
 *
 * Beklenen değişkenler:
 *   $f        form değerleri (string)
 *   $mode     'create' | 'edit'
 *   $logos    listLogos() çıktısı
 *   $logoSupport QrRenderer::logoSupport()
 *   $previewSlug önizleme için slug (create'te boş olabilir)
 */
if (!defined('ROOT_DIR')) exit;
$brandFg    = LinkService::normalizeHex(Settings::get('brand_fg')) ?? '#000000';
$brandBg    = LinkService::normalizeHex(Settings::get('brand_bg')) ?? '#FFFFFF';
$tzName     = Settings::timezone()->getName();
$styleMode  = $f['style_mode'] ?? 'brand';
$templates  = QrTemplates::all(getPdo(), false);
$tplVal     = (int)($f['template_id'] ?? 0);
// Bu QR arşivlenmiş bir şablona bağlıysa listede yine görünsün
if ($tplVal && !array_filter($templates, fn($t) => $t['id'] === $tplVal) && ($cur = QrTemplates::find(getPdo(), $tplVal))) $templates[] = $cur;
$canCustom  = Access::can('design.custom');
$showCustom = $canCustom || $styleMode === 'custom';
if (!$templates && $styleMode === 'template') $styleMode = 'brand';
// Tasarım alanları (_design_fields.php): özel görünüm boşsa marka varsayılanıyla başlar
$d = [
    'fg' => LinkService::normalizeHex($f['fg_color'] ?? '') ?? $brandFg,
    'bg' => LinkService::normalizeHex($f['bg_color'] ?? '') ?? $brandBg,
    'shape' => ($f['shape'] ?? '') ?: Settings::get('brand_shape'),
    'eye' => $f['eye'] ?? '', 'gradient' => $f['gradient'] ?? 'none', 'fg2' => $f['fg2'] ?? '',
    'eye_color' => !empty($f['eye_color_on']) ? ($f['eye_color'] ?? '') : '',
    'frame' => $f['frame'] ?? 'none', 'frame_color' => $f['frame_color'] ?? '', 'frame_text' => $f['frame_text'] ?? '',
];
$allTags    = array_values(array_map(fn($t) => ['tag' => $t['tag'], 'count' => $t['count']], LinkService::allTags(getPdo())));
$starterTags = ['poster', 'broşür', 'stand', 'kartvizit', 'sunum', 'e-posta', 'sosyal-medya'];
$hasTags    = trim((string)($f['tags'] ?? '')) !== '';
$isVcardForm = ($qrType ?? 'link') === 'vcard';
// Yeni QR: kategori seçilince o kategorinin varsayılan şablonu önerilir (kişi görünümü elle seçmediyse)
$catTpl = [];
if ($mode === 'create' && $templates) {
    $activeIds = array_column(array_filter($templates, fn($t) => empty($t['archived_at'])), 'id');
    $catTpl = array_filter(QrTemplates::categoryDefaults(), fn($id) => in_array($id, $activeIds, true));
}
?>
<div class="form-layout">
<div class="form-main">

<?php if ($isVcardForm):
    $vcv = is_array($f['vc'] ?? null) ? $f['vc'] : [];
    $vfield = function (string $k, string $type = 'text', string $ph = '', string $extra = '') use ($vcv) {
        [$label, $max] = VCard::FIELDS[$k];
        return '<div class="form-group"><label class="form-label" for="vc-' . $k . '">' . e($label) . '</label>'
             . '<input class="form-control" type="' . $type . '" id="vc-' . $k . '" name="vc[' . $k . ']" maxlength="' . $max . '"'
             . ' placeholder="' . e($ph) . '" value="' . e($vcv[$k] ?? '') . '"' . $extra . '></div>';
    }; ?>
  <div class="card mb-4">
    <div class="card-title mb-2"><?= icon('contact') ?> Kişi Bilgileri</div>
    <p class="text-sm text-muted mb-4">QR okutulunca bu bilgilerle bir kişi sayfası açılır; "Rehbere ekle" ile telefona kaydedilir. Bilgileri sonradan değiştirebilirsiniz, basılı QR aynı kalır.</p>
    <div class="form-row">
      <?= $vfield('first_name', 'text', 'Ayşe', ' required autocomplete="off"') ?>
      <?= $vfield('last_name', 'text', 'Yılmaz', ' autocomplete="off"') ?>
    </div>
    <div class="form-row">
      <?= $vfield('title', 'text', 'Uluslararası Hasta Koordinatörü') ?>
      <?= $vfield('department', 'text', 'Uluslararası Hasta Merkezi') ?>
    </div>
    <div class="form-row">
      <?= $vfield('phone_mobile', 'tel', '+90 532 000 00 00') ?>
      <?= $vfield('phone_work', 'tel', '+90 216 000 00 00') ?>
    </div>
    <div class="form-row">
      <?= $vfield('email', 'email', 'ad.soyad@acibadem.com') ?>
      <?= $vfield('org', 'text', VCard::DEFAULT_ORG) ?>
    </div>
    <div class="form-row">
      <?= $vfield('website', 'url', 'https://acibadem.world/') ?>
      <?= $vfield('linkedin', 'url', 'https://www.linkedin.com/in/…') ?>
    </div>
    <?= $vfield('address', 'text', 'Kozyatağı, İstanbul') ?>
    <div class="form-group">
      <label class="form-label" for="vc-note"><?= e(VCard::FIELDS['note'][0]) ?> <span class="hint">Kişi sayfasında ve rehber kaydında görünür</span></label>
      <textarea class="form-control" id="vc-note" name="vc[note]" rows="2" maxlength="500"><?= e($vcv['note'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <label class="form-label" for="vc-photo">Fotoğraf <span class="hint">JPG, PNG veya WebP, en fazla 5 MB. Kare kırpılır ve küçültülür.</span></label>
      <?php if (!empty($vcardHasPhoto) && !empty($previewSlug)): ?>
        <div class="vc-photo-row">
          <img class="vc-photo" src="<?= e(BASE_URL . '/r/' . $previewSlug) ?>?photo=1&amp;v=<?= time() ?>" alt="">
          <label class="form-check"><input type="checkbox" name="vc_photo_remove" value="1"><span>Fotoğrafı kaldır</span></label>
        </div>
      <?php endif; ?>
      <input class="form-control" type="file" id="vc-photo" name="vc_photo" accept="image/jpeg,image/png,image/webp">
    </div>
  </div>
<?php endif; ?>

  <div class="card">
    <div class="card-title mb-4"><?= icon($isVcardForm ? 'qr' : 'link') ?> <?= $isVcardForm ? 'QR Bilgileri' : 'Bağlantı Bilgileri' ?></div>

    <?php if ($mode === 'create'): ?>
    <div class="form-group">
      <label class="form-label" for="custom_slug">
        Kısa Ad (slug) <?= tip('QR’ın kısa adresinde görünen kısım: /r/<slug>. QR basıldıktan sonra değiştirilemez; hedef adres ise istediğiniz zaman değişebilir.') ?> <span class="hint">Boş bırakırsanız otomatik üretilir. Basıldıktan sonra değiştirilemez.</span>
      </label>
      <input class="form-control" type="text" id="custom_slug" name="custom_slug"
             placeholder="<?= $isVcardForm ? 'ad-soyad' : 'london-office' ?>" value="<?= e($f['custom_slug'] ?? '') ?>"
             pattern="[A-Za-z0-9_\-]{0,16}" maxlength="16">
    </div>
    <?php endif; ?>

    <?php if (!$isVcardForm): ?>
    <div class="form-group">
      <label class="form-label" for="target_url">Hedef URL <?= tip('QR okutulunca ziyaretçinin gideceği sayfa. Basılı QR aynı kalır; bu adresi değiştirerek QR’ı başka sayfaya yönlendirebilirsiniz.') ?> <span class="hint">* Zorunlu</span></label>
      <input class="form-control" type="url" id="target_url" name="target_url"
             placeholder="https://acibadem.world/office/london" value="<?= e($f['target_url'] ?? '') ?>" required>
      <?php if ($mode === 'edit'): ?>
        <small class="text-muted text-xs">Bu adresi değiştirdiğinizde aynı QR yeni sayfaya yönlendirir.</small>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="title">Başlık</label>
        <input class="form-control" type="text" id="title" name="title" maxlength="255"
               placeholder="<?= $isVcardForm ? 'Boş bırakılırsa ad soyad' : 'Londra Ofisi' ?>" value="<?= e($f['title'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label" for="category">Kategori <?= tip('QR\'ın neye ait olduğunu söyler; tek seçimlidir (Genel Merkez, Ofis, Etkinlik…). Raporlarda “kategoriye göre” dağılım verir. Ülke, etkinlik adı veya materyal gibi gruplar için Etiketler alanını kullanın.') ?></label>
        <select class="form-control" id="category" name="category">
          <option value="">— Seçilmedi —</option>
          <?php foreach (LinkService::CATEGORIES as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= ($f['category'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group tag-wrap">
      <label class="form-label" for="tag-entry">Etiketler
        <?= tip('Serbest ve çoklu gruplama: ülke, etkinlik adı, materyal, dönem… Raporlarda aynı etikete sahip tüm QR’lar tek satırda toplanır. Büyük/küçük harf ve Türkçe karakter farkı otomatik birleştirilir (İstanbul = istanbul).') ?>
        <span class="hint">Yazıp Enter’a basın · en fazla <?= LinkService::MAX_TAGS ?></span>
      </label>
      <div class="tag-box">
      <div class="tag-input" data-all="<?= e(json_encode($allTags, JSON_UNESCAPED_UNICODE)) ?>"
           data-categories="<?= e(json_encode(LinkService::CATEGORIES, JSON_UNESCAPED_UNICODE)) ?>" data-max="<?= LinkService::MAX_TAGS ?>">
        <span class="tag-chips"></span>
        <input type="text" id="tag-entry" autocomplete="off" maxlength="40" aria-controls="tag-suggest" aria-autocomplete="list">
      </div>
      <input type="hidden" id="tags" name="tags" value="<?= e($f['tags'] ?? '') ?>">
      <div class="tag-suggest" id="tag-suggest" role="listbox" hidden></div>
      </div>
      <div class="tag-meta"><span id="tag-hint" aria-live="polite"></span><span id="tag-count"></span></div>

      <div class="tag-popular">
        <?php if ($allTags): ?>
          Sık kullanılanlar:
          <?php foreach (array_slice($allTags, 0, 8) as $t): ?>
            <button type="button" class="tag-chip" data-tag="<?= e($t['tag']) ?>" title="<?= (int)$t['count'] ?> QR'da kullanılıyor">+ <?= e($t['tag']) ?> <small><?= (int)$t['count'] ?></small></button>
          <?php endforeach; ?>
        <?php else: ?>
          Başlangıç için öneriler (materyal tipi):
          <?php foreach ($starterTags as $t): ?>
            <button type="button" class="tag-chip" data-tag="<?= e($t) ?>">+ <?= e($t) ?></button>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <details class="tag-guide" <?= !$hasTags && $mode === 'create' ? 'open' : '' ?>>
        <summary><?= icon('bulb') ?> Kategori mi, etiket mi?</summary>
        <dl>
          <dt>Kategori</dt><dd>QR’ın <strong>ne olduğu</strong>. Tek seçim: Genel Merkez, Ofis, Etkinlik, Kampanya.</dd>
          <dt>Etiket</dt><dd>QR’ın <strong>hangi gruplara girdiği</strong>. Serbest ve çoklu: ülke, etkinlik adı, materyal, dönem.</dd>
        </dl>
        <div class="example">
          <strong>Örnek:</strong> Londra ofisinin kongre posteri →
          Kategori: <span class="tag-chip">Ofis</span> Etiketler: <span class="tag-chip">#UK</span> <span class="tag-chip">#poster</span> <span class="tag-chip">#kongre-2027</span><br>
          <span class="text-muted">Böylece raporda “kongre-2027 etiketli tüm QR’lar” veya “tüm posterler” tek tıkla görülür. Etiketleri sonradan
          <a href="<?= BASE_URL ?>/admin/tags">Etiketler</a> sayfasından toplu yeniden adlandırabilir veya birleştirebilirsiniz.</span>
        </div>
      </details>
    </div>

    <div class="form-group">
      <label class="form-label" for="notes">Not <span class="hint">Yalnızca panelde görünür (sorumlu kişi, basılan materyal vb.)</span></label>
      <textarea class="form-control" id="notes" name="notes" rows="2" maxlength="2000"><?= e($f['notes'] ?? '') ?></textarea>
    </div>

    <?php if ($mode === 'edit' && !empty($canToggle)): ?>
    <div class="form-group">
      <label class="form-check">
        <input type="checkbox" name="is_active" value="1" <?= !empty($f['is_active']) ? 'checked' : '' ?>>
        <span>Aktif (QR bu sayfaya yönlendirsin)</span>
      </label>
    </div>
    <?php endif; ?>
  </div>

  <div class="card mt-4">
    <div class="card-title mb-2"><?= icon('clock') ?> Zamanlama ve Yedek Hedef</div>
    <p class="text-sm text-muted mb-4">
      Etkinlik QR'ı için: başlangıç ve bitiş verin, bitince QR kendiliğinden başka bir sayfaya (örn. ofis sayfası) geçsin.
      Saatler <strong><?= e($tzName) ?></strong> saat dilimine göredir. Boş bırakırsanız QR süresiz çalışır.
    </p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="starts_at">Başlangıç <?= tip('Bu tarihten önce QR hedefe gitmez (yedek hedefe ya da 404’e düşer). Etkinlik öncesi basılan QR’lar için kullanışlıdır.') ?></label>
        <input class="form-control" type="datetime-local" id="starts_at" name="starts_at" value="<?= e($f['starts_at'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label" for="ends_at">Bitiş <?= tip('Bu tarihten sonra QR kendiliğinden “bitiş sonrası adrese” (yoksa yedek hedefe) yönlenir.') ?></label>
        <input class="form-control" type="datetime-local" id="ends_at" name="ends_at" value="<?= e($f['ends_at'] ?? '') ?>">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label" for="after_end_url">Bitişten sonra gidilecek adres <span class="hint">Boşsa yedek hedef kullanılır</span></label>
      <input class="form-control" type="url" id="after_end_url" name="after_end_url"
             placeholder="https://acibadem.world/office/london" value="<?= e($f['after_end_url'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label class="form-label" for="fallback_url">
        Yedek hedef <span class="hint">Pasif / arşivlenmiş / henüz başlamamış QR okutulursa ziyaretçi 404 yerine buraya gider.
        Boşsa Ayarlar'daki genel yedek kullanılır<?= Settings::get('fallback_url') !== '' ? ' (şu an: ' . e(Settings::get('fallback_url')) . ')' : '' ?>.</span>
      </label>
      <input class="form-control" type="url" id="fallback_url" name="fallback_url"
             placeholder="https://acibadem.world/" value="<?= e($f['fallback_url'] ?? '') ?>">
    </div>
  </div>

  <?php if (!$isVcardForm): ?>
  <div class="card mt-4">
    <div class="card-title mb-2"><?= icon('trending') ?> Takip (UTM) <?= tip('UTM, hedef sitenizin analitiğinde ziyaretin QR’dan geldiğini gösteren etikettir. Bu panel zaten okutmaları sayar; UTM yalnızca hedef sitedeki analitik içindir.') ?></div>
    <div class="form-group">
      <label class="form-check">
        <input type="checkbox" name="utm_enabled" value="1" <?= !empty($f['utm_enabled']) ? 'checked' : '' ?>>
        <span>Hedef adrese otomatik UTM ekle
          (<code>utm_source=<?= e(Settings::get('utm_source')) ?>&amp;utm_medium=<?= e(Settings::get('utm_medium')) ?></code>)</span>
      </label>
      <small class="text-muted text-xs">Google Analytics'te QR trafiği kampanya bazında ayrışır. Hedefte zaten UTM varsa dokunulmaz.</small>
    </div>
    <div class="form-group">
      <label class="form-label" for="utm_campaign">Kampanya adı <span class="hint">Boşsa kısa ad (slug) kullanılır</span></label>
      <input class="form-control" type="text" id="utm_campaign" name="utm_campaign" maxlength="100"
             placeholder="arabhealth-2027" pattern="[A-Za-z0-9_\-.]{0,100}" value="<?= e($f['utm_campaign'] ?? '') ?>">
    </div>
  </div>
  <?php endif; ?>

  <div class="card mt-4">
    <div class="card-title mb-2"><?= icon('palette') ?> Marka ve Görünüm</div>

    <div class="form-group style-modes">
      <label class="form-check">
        <input type="radio" name="style_mode" value="brand" <?= $styleMode === 'brand' ? 'checked' : '' ?>>
        <span>Marka varsayılanı
          <span class="swatch" style="background:<?= e($brandFg) ?>"></span><span class="swatch" style="background:<?= e($brandBg) ?>"></span>
          <span class="text-muted text-xs">(<?= e(LinkService::SHAPES[Settings::get('brand_shape')] ?? 'Kare') ?> — Ayarlar'dan değiştirilir)</span>
        </span>
      </label>
      <?php if ($templates): ?>
      <label class="form-check mt-2">
        <input type="radio" name="style_mode" value="template" <?= $styleMode === 'template' ? 'checked' : '' ?>>
        <span>Kurumsal şablon <span class="text-muted text-xs">(kartvizit, roll-up, yaka kartı…)</span></span>
      </label>
      <?php endif; ?>
      <?php if ($showCustom): ?>
      <label class="form-check mt-2">
        <input type="radio" name="style_mode" value="custom" <?= $styleMode === 'custom' ? 'checked' : '' ?>>
        <span>Bu QR için özel görünüm<?php if (!$canCustom): ?> <span class="text-muted text-xs">(mevcut; değiştirmek yöneticiye açık)</span><?php endif; ?></span>
      </label>
      <?php endif; ?>
    </div>

    <?php if ($templates): ?>
    <div id="template-style" class="tpl-pick" style="<?= $styleMode === 'template' ? '' : 'display:none' ?>">
      <?php foreach ($templates as $i => $t):
          $checked = $tplVal ? $tplVal === $t['id'] : $i === 0; ?>
        <label class="tpl-option">
          <input type="radio" name="template_id" value="<?= $t['id'] ?>" <?= $checked ? 'checked' : '' ?>
                 data-frame="<?= QrFrame::enabled($t['style']) ? '1' : '' ?>" data-text="<?= e($t['style']['frame_text'] ?? '') ?>">
          <img src="<?= e(BASE_URL . '/admin/qr-preview?mode=template&slug=sablon&tpl=' . $t['id']) ?>" alt="" loading="lazy">
          <span class="tpl-option-text">
            <span class="fw-bold"><?= e($t['name']) ?></span>
            <span class="text-xs text-muted"><?= e(QrTemplates::USAGES[$t['usage']] ?? '') ?><?= $t['print_mm'] ? ' · ' . (int)$t['print_mm'] . ' mm' : '' ?><?= !empty($t['archived_at']) ? ' · arşivde' : '' ?></span>
            <?php if ($t['description']): ?><span class="text-xs"><?= e($t['description']) ?></span><?php endif; ?>
          </span>
        </label>
      <?php endforeach; ?>
      <?php if (Access::isAdmin()): ?><p class="text-xs text-muted mt-2"><a href="<?= BASE_URL ?>/admin/templates">Şablonları yönet</a></p><?php endif; ?>
    </div>
    <?php if (QrTemplates::linkTextAvailable(getPdo())): ?>
    <div class="form-group" id="tpl-text-wrap" style="display:none">
      <label class="form-label" for="tpl_frame_text">Çerçeve yazısı <span class="hint">bu QR için; boş bırakırsanız şablonun yazısı kullanılır</span></label>
      <input class="form-control" type="text" id="tpl_frame_text" name="tpl_frame_text" maxlength="<?= QrFrame::MAX_TEXT ?>"
             value="<?= e((string)($f['tpl_frame_text'] ?? '')) ?>">
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($showCustom): ?>
    <div id="custom-style" style="<?= $styleMode === 'custom' ? '' : 'display:none' ?>">
      <?php if ($canCustom): ?>
        <?php include __DIR__ . '/_design_fields.php'; ?>
      <?php else: ?>
        <p class="text-sm text-muted">Bu QR'ın özel görünümü korunur. Değiştirmek için bir şablon ya da marka varsayılanını seçin.</p>
        <?php foreach (['fg_color', 'bg_color', 'shape', 'eye', 'eye_color_on', 'eye_color', 'gradient', 'fg2', 'frame', 'frame_color', 'frame_text'] as $k): ?>
          <input type="hidden" name="<?= $k ?>" value="<?= e((string)($f[$k] ?? '')) ?>">
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Logo -->
    <div class="form-group mt-4">
      <label class="form-check">
        <input type="checkbox" id="use-logo-checkbox" name="use_logo" value="1" <?= !empty($f['logo_file']) ? 'checked' : '' ?>>
        <span>QR'ın ortasına logo ekle</span>
        <?php if (!$logoSupport['supported']): ?>
          <span class="badge badge-muted" title="GD veya Imagick bulunamadı"><?= icon('alert') ?> PNG logosuz</span>
        <?php endif; ?>
      </label>
    </div>
    <input type="hidden" id="logo-file-input" name="logo_file" value="<?= e($f['logo_file'] ?? '') ?>">
    <div class="logo-gallery-wrap" style="<?= empty($f['logo_file']) ? 'display:none' : '' ?>">
      <?php if (empty($logos)): ?>
        <p class="text-sm text-muted">Henüz logo yok. <a href="<?= BASE_URL ?>/admin/settings">Ayarlar</a> sayfasından logo yükleyebilirsiniz.</p>
      <?php else: ?>
        <div class="logo-gallery">
          <?php foreach ($logos as $lf): ?>
            <div class="logo-item <?= ($f['logo_file'] ?? '') === $lf ? 'selected' : '' ?>" data-file="<?= e($lf) ?>">
              <img src="<?= BASE_URL ?>/logo/<?= rawurlencode($lf) ?>" alt="<?= e($lf) ?>" loading="lazy">
              <span><?= e($lf) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?= $formActions ?? '' ?>
</div>

<!-- Sağ: canlı önizleme -->
<aside class="form-side">
  <div class="card sticky-card">
    <div class="card-title mb-2"><?= icon('qr') ?> Canlı Önizleme</div>
    <div class="qr-preview-box" style="min-height:240px">
      <img id="qr-live-preview" alt="QR önizleme" style="width:100%;max-width:300px;height:auto"
           data-base="<?= e(BASE_URL) ?>" data-slug="<?= e($previewSlug ?? '') ?>">
    </div>
    <p class="text-xs text-muted mt-2">Önizleme, görünüm ayarlarınızı anında yansıtır. QR her zaman bu kısa adresi kodlar:
      <code><?= e(BASE_URL . '/r/') ?><span id="prev-slug"><?= e(($previewSlug ?? '') !== '' ? $previewSlug : '…') ?></span></code></p>
    <ul class="text-xs text-muted" style="padding-left:1rem;line-height:1.8;margin-top:.75rem">
      <li>Logo eklenince hata düzeltme otomatik en yükseğe çıkar.</li>
      <li>Kontrast 4:1 ve üstü önerilir; ters renk ve düşük kontrastta uyarı görürsünüz.</li>
      <li>Baskı öncesi gerçek telefonla test edin.</li>
    </ul>
  </div>
</aside>
</div>

<script>
(function () {
  const $ = (s) => document.querySelector(s);
  const form = $('#qr-live-preview').closest('form');
  const img = $('#qr-live-preview');
  const base = img.dataset.base;
  const mode = () => form.querySelector('input[name=style_mode]:checked').value;

  let t = null;
  function refresh() {
    clearTimeout(t);
    t = setTimeout(() => {
      const m = mode();
      const slugInput = $('#custom_slug');
      const slug = img.dataset.slug || (slugInput ? slugInput.value : '');
      const p = { mode: m, logo: ($('#use-logo-checkbox').checked ? $('#logo-file-input').value : ''), slug: slug };
      if (m === 'template') {
        const tp = form.querySelector('input[name=template_id]:checked');
        p.tpl = tp ? tp.value : '';
        const ft = $('#tpl_frame_text');
        if (ft) p.ftext = ft.value;
      } else if (m === 'custom') {
        if (window.qrDesign) Object.assign(p, window.qrDesign.values());
        else { // editör: mevcut özel görünüm gizli alanlarda
          const v = (k) => { const el = form.querySelector('input[name=' + k + ']'); return el ? el.value : ''; };
          Object.assign(p, { fg: v('fg_color'), bg: v('bg_color'), shape: v('shape'), eye: v('eye'),
            eye_color: v('eye_color_on') ? v('eye_color') : '', gradient: v('gradient'), fg2: v('fg2'),
            frame: v('frame'), frame_color: v('frame_color'), frame_text: v('frame_text') });
        }
      }
      $('#prev-slug').textContent = slug || '…';
      img.src = base + '/admin/qr-preview?' + new URLSearchParams(p).toString();
    }, 150);
  }

  function syncMode() {
    const m = mode();
    const c = $('#custom-style'), tp = $('#template-style');
    if (c) c.style.display = m === 'custom' ? '' : 'none';
    if (tp) tp.style.display = m === 'template' ? '' : 'none';
    // Seçilen şablonda çerçeve varsa QR'a özel yazı alanı görünür; şablonun yazısı yer tutucu olur
    const wrap = $('#tpl-text-wrap'), sel = form.querySelector('input[name=template_id]:checked');
    if (wrap) {
      const on = m === 'template' && sel && sel.dataset.frame === '1';
      wrap.style.display = on ? '' : 'none';
      if (sel) $('#tpl_frame_text').placeholder = sel.dataset.text || '';
    }
  }
  // Kategori varsayılan şablonu: görünüm elle seçilene kadar kategoriyi izler
  const catTpl = <?= json_encode((object)$catTpl) ?>;
  let styleTouched = <?= ($_SERVER['REQUEST_METHOD'] === 'POST' || !$catTpl) ? 'true' : 'false' ?>;
  function applyCategoryDefault() {
    const cat = $('#category');
    if (styleTouched || !cat) return;
    const id = catTpl[cat.value];
    const r = form.querySelector('input[name=style_mode][value=' + (id ? 'template' : 'brand') + ']');
    if (r) r.checked = true;
    if (id) { const t = form.querySelector('input[name=template_id][value="' + id + '"]'); if (t) t.checked = true; }
    syncMode(); refresh();
  }
  const catSel = $('#category');
  if (catSel) catSel.addEventListener('change', applyCategoryDefault);

  form.querySelectorAll('input[name=style_mode]').forEach(r => r.addEventListener('change', () => { styleTouched = true; syncMode(); refresh(); }));
  form.querySelectorAll('input[name=template_id]').forEach(r => r.addEventListener('change', () => { styleTouched = true; syncMode(); refresh(); }));
  const ftIn = $('#tpl_frame_text');
  if (ftIn) ftIn.addEventListener('input', refresh);
  document.addEventListener('qr-design-change', refresh);
  $('#use-logo-checkbox').addEventListener('change', refresh);
  document.addEventListener('click', (e) => { if (e.target.closest('.logo-item')) setTimeout(refresh, 0); });
  const slugInput = $('#custom_slug');
  if (slugInput) slugInput.addEventListener('input', refresh);

  syncMode(); refresh();
  applyCategoryDefault();
})();
</script>
