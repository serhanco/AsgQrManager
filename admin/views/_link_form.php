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
$fgVal      = LinkService::normalizeHex($f['fg_color'] ?? '') ?? $brandFg;
$bgVal      = LinkService::normalizeHex($f['bg_color'] ?? '') ?? $brandBg;
$shapeVal   = $f['shape'] ?? '';
?>
<div class="form-layout">
<div class="form-main">

  <div class="card">
    <div class="card-title mb-4">🔗 Bağlantı Bilgileri</div>

    <?php if ($mode === 'create'): ?>
    <div class="form-group">
      <label class="form-label" for="custom_slug">
        Kısa Ad (slug) <span class="hint">Boş bırakırsanız otomatik üretilir. Basıldıktan sonra değiştirilemez.</span>
      </label>
      <input class="form-control" type="text" id="custom_slug" name="custom_slug"
             placeholder="london-office" value="<?= e($f['custom_slug'] ?? '') ?>"
             pattern="[A-Za-z0-9_\-]{0,16}" maxlength="16">
    </div>
    <?php endif; ?>

    <div class="form-group">
      <label class="form-label" for="target_url">Hedef URL <span class="hint">* Zorunlu</span></label>
      <input class="form-control" type="url" id="target_url" name="target_url"
             placeholder="https://acibadem.world/office/london" value="<?= e($f['target_url'] ?? '') ?>" required>
      <?php if ($mode === 'edit'): ?>
        <small class="text-muted text-xs">Bu adresi değiştirdiğinizde aynı QR yeni sayfaya yönlendirir.</small>
      <?php endif; ?>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="title">Başlık</label>
        <input class="form-control" type="text" id="title" name="title" maxlength="255"
               placeholder="Londra Ofisi" value="<?= e($f['title'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label" for="category">Tür</label>
        <select class="form-control" id="category" name="category">
          <option value="">— Seçilmedi —</option>
          <?php foreach (LinkService::CATEGORIES as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= ($f['category'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label" for="tags">Etiketler <span class="hint">Virgülle ayırın: ülke, ofis, kampanya…</span></label>
      <input class="form-control" type="text" id="tags" name="tags" maxlength="400"
             placeholder="UK, londra, kongre-2027" value="<?= e($f['tags'] ?? '') ?>">
    </div>

    <div class="form-group">
      <label class="form-label" for="notes">Not <span class="hint">Yalnızca panelde görünür (sorumlu kişi, basılan materyal vb.)</span></label>
      <textarea class="form-control" id="notes" name="notes" rows="2" maxlength="2000"><?= e($f['notes'] ?? '') ?></textarea>
    </div>

    <?php if ($mode === 'edit'): ?>
    <div class="form-group">
      <label class="form-check">
        <input type="checkbox" name="is_active" value="1" <?= !empty($f['is_active']) ? 'checked' : '' ?>>
        <span>Aktif (QR bu sayfaya yönlendirsin)</span>
      </label>
    </div>
    <?php endif; ?>
  </div>

  <div class="card mt-4">
    <div class="card-title mb-2">⏱ Zamanlama ve Yedek Hedef</div>
    <p class="text-sm text-muted mb-4">
      Etkinlik QR'ı için: başlangıç ve bitiş verin, bitince QR kendiliğinden başka bir sayfaya (örn. ofis sayfası) geçsin.
      Saatler <strong><?= e($tzName) ?></strong> saat dilimine göredir. Boş bırakırsanız QR süresiz çalışır.
    </p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="starts_at">Başlangıç</label>
        <input class="form-control" type="datetime-local" id="starts_at" name="starts_at" value="<?= e($f['starts_at'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label" for="ends_at">Bitiş</label>
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

  <div class="card mt-4">
    <div class="card-title mb-2">📈 Takip (UTM)</div>
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

  <div class="card mt-4">
    <div class="card-title mb-2">🎨 Marka ve Görünüm</div>

    <div class="form-group">
      <label class="form-check">
        <input type="radio" name="style_mode" value="brand" <?= $styleMode !== 'custom' ? 'checked' : '' ?>>
        <span>Marka varsayılanı
          <span class="swatch" style="background:<?= e($brandFg) ?>"></span><span class="swatch" style="background:<?= e($brandBg) ?>"></span>
          <span class="text-muted text-xs">(<?= e(LinkService::SHAPES[Settings::get('brand_shape')] ?? 'Kare') ?> — Ayarlar'dan değiştirilir)</span>
        </span>
      </label>
      <label class="form-check mt-2">
        <input type="radio" name="style_mode" value="custom" <?= $styleMode === 'custom' ? 'checked' : '' ?>>
        <span>Bu QR için özel görünüm</span>
      </label>
    </div>

    <div id="custom-style" style="<?= $styleMode === 'custom' ? '' : 'display:none' ?>">
      <div class="preset-row mb-3">
        <button type="button" class="btn btn-ghost btn-sm preset" data-fg="#000000" data-bg="#FFFFFF">Siyah / Beyaz</button>
        <button type="button" class="btn btn-ghost btn-sm preset" data-fg="#0B2A5B" data-bg="#FFFFFF">Lacivert</button>
        <button type="button" class="btn btn-ghost btn-sm preset" data-fg="#0A5C36" data-bg="#FFFFFF">Koyu yeşil</button>
        <button type="button" class="btn btn-ghost btn-sm preset" data-fg="#5B1A5E" data-bg="#FFFFFF">Koyu mor</button>
        <button type="button" class="btn btn-ghost btn-sm preset" data-fg="#7A0C1E" data-bg="#FFFFFF">Bordo</button>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="fg_color">QR rengi</label>
          <div class="color-field">
            <input type="color" id="fg_picker" value="<?= e(strtolower($fgVal)) ?>">
            <input class="form-control" type="text" id="fg_color" name="fg_color" maxlength="7" value="<?= e($fgVal) ?>">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label" for="bg_color">Arka plan</label>
          <div class="color-field">
            <input type="color" id="bg_picker" value="<?= e(strtolower($bgVal)) ?>">
            <input class="form-control" type="text" id="bg_color" name="bg_color" maxlength="7" value="<?= e($bgVal) ?>">
          </div>
        </div>
      </div>
      <div id="contrast-msg" class="text-sm mb-3"></div>
      <div class="form-group">
        <label class="form-label" for="shape">Nokta şekli</label>
        <select class="form-control" id="shape" name="shape">
          <?php foreach (LinkService::SHAPES as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= ($shapeVal ?: Settings::get('brand_shape')) === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <small class="text-muted text-xs">Köşelerdeki üç büyük kare her zaman kare kalır; okuyucular onlara kilitlenir.</small>
      </div>
    </div>

    <!-- Logo -->
    <div class="form-group mt-4">
      <label class="form-check">
        <input type="checkbox" id="use-logo-checkbox" name="use_logo" value="1" <?= !empty($f['logo_file']) ? 'checked' : '' ?>>
        <span>QR'ın ortasına logo ekle</span>
        <?php if (!$logoSupport['supported']): ?>
          <span class="badge badge-muted" title="GD veya Imagick bulunamadı">⚠️ PNG logosuz</span>
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
    <div class="card-title mb-2">📱 Canlı Önizleme</div>
    <div class="qr-preview-box" style="min-height:240px">
      <img id="qr-live-preview" alt="QR önizleme" style="width:100%;max-width:300px;height:auto"
           data-base="<?= e(BASE_URL) ?>" data-slug="<?= e($previewSlug ?? '') ?>">
    </div>
    <p class="text-xs text-muted mt-2">Önizleme, görünüm ayarlarınızı anında yansıtır. QR her zaman bu kısa adresi kodlar:
      <code><?= e(BASE_URL . '/r/') ?><span id="prev-slug"><?= e(($previewSlug ?? '') !== '' ? $previewSlug : '…') ?></span></code></p>
    <ul class="text-xs text-muted" style="padding-left:1rem;line-height:1.8;margin-top:.75rem">
      <li>Logo eklenince hata düzeltme otomatik en yükseğe çıkar.</li>
      <li>QR rengi arka plandan koyu olmalı; kontrast en az 4:1.</li>
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
  const fg = $('#fg_color'), bg = $('#bg_color'), fgP = $('#fg_picker'), bgP = $('#bg_picker');
  const modeInputs = form.querySelectorAll('input[name=style_mode]');
  const custom = $('#custom-style');

  function lum(hex) {
    const v = [1, 3, 5].map(i => parseInt(hex.substr(i, 2), 16) / 255)
      .map(c => c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4));
    return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
  }
  const ok = (h) => /^#[0-9a-fA-F]{6}$/.test(h);
  function checkContrast() {
    const el = $('#contrast-msg');
    if (!ok(fg.value) || !ok(bg.value)) { el.textContent = 'Renk #RRGGBB biçiminde olmalı.'; el.style.color = 'var(--danger)'; return; }
    const a = lum(fg.value), b = lum(bg.value);
    const ratio = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
    if (a >= b) { el.textContent = '⚠️ QR rengi arka plandan koyu olmalı.'; el.style.color = 'var(--danger)'; }
    else if (ratio < 4) { el.textContent = '⚠️ Kontrast düşük (' + ratio.toFixed(1) + ':1). En az 4:1 gerekir.'; el.style.color = 'var(--danger)'; }
    else { el.textContent = '✓ Kontrast yeterli (' + ratio.toFixed(1) + ':1)'; el.style.color = 'var(--success)'; }
  }

  let t = null;
  function refresh() {
    clearTimeout(t);
    t = setTimeout(() => {
      const mode = form.querySelector('input[name=style_mode]:checked').value;
      const slugInput = $('#custom_slug');
      const slug = img.dataset.slug || (slugInput ? slugInput.value : '');
      const p = new URLSearchParams({
        mode: mode,
        fg: fg.value, bg: bg.value, shape: $('#shape').value,
        logo: ($('#use-logo-checkbox').checked ? $('#logo-file-input').value : ''),
        slug: slug
      });
      $('#prev-slug').textContent = slug || '…';
      img.src = base + '/admin/qr-preview?' + p.toString();
    }, 150);
  }

  function syncMode() { custom.style.display = form.querySelector('input[name=style_mode]:checked').value === 'custom' ? '' : 'none'; }
  modeInputs.forEach(r => r.addEventListener('change', () => { syncMode(); refresh(); }));

  [[fgP, fg], [bgP, bg]].forEach(([p, t]) => {
    p.addEventListener('input', () => { t.value = p.value.toUpperCase(); checkContrast(); refresh(); });
    t.addEventListener('input', () => { if (ok(t.value)) p.value = t.value.toLowerCase(); checkContrast(); refresh(); });
  });
  document.querySelectorAll('.preset').forEach(b => b.addEventListener('click', () => {
    fg.value = b.dataset.fg; bg.value = b.dataset.bg; fgP.value = fg.value.toLowerCase(); bgP.value = bg.value.toLowerCase();
    checkContrast(); refresh();
  }));
  $('#shape').addEventListener('change', refresh);
  $('#use-logo-checkbox').addEventListener('change', refresh);
  document.addEventListener('click', (e) => { if (e.target.closest('.logo-item')) setTimeout(refresh, 0); });
  const slugInput = $('#custom_slug');
  if (slugInput) slugInput.addEventListener('input', refresh);

  syncMode(); checkContrast(); refresh();
})();
</script>
