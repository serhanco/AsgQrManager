<?php
/**
 * QR tasarım alanları: renkler, nokta şekli, köşe (göz) biçimi/rengi, renk geçişi ve canlı okunabilirlik uyarıları.
 * QR formundaki "özel görünüm" ve şablon formu aynı alanları kullanır.
 *
 * Beklenen: $d = ['fg','bg','shape','eye','eye_color','gradient','fg2'] (boş olabilir)
 * Sayfa, alan değişince 'qr-design-change' olayını dinleyip önizlemeyi yeniler; window.qrDesign.params() sorgu parametrelerini verir.
 */
$dv = fn(string $k, string $def = '') => (string)(($d[$k] ?? '') !== '' && $d[$k] !== null ? $d[$k] : $def);
$fgV = strtoupper($dv('fg', '#000000')); $bgV = strtoupper($dv('bg', '#FFFFFF'));
$eyeColorOn = $dv('eye_color') !== '';
$gradV = $dv('gradient', 'none');
?>
<div class="design-fields">
  <div class="preset-row mb-3">
    <button type="button" class="btn btn-ghost btn-sm preset" data-fg="#000000" data-bg="#FFFFFF">Siyah / Beyaz</button>
    <button type="button" class="btn btn-ghost btn-sm preset" data-fg="#1C2561" data-bg="#FFFFFF">Lacivert</button>
    <button type="button" class="btn btn-ghost btn-sm preset" data-fg="#0A3A86" data-bg="#FFFFFF">Kurumsal mavi</button>
    <button type="button" class="btn btn-ghost btn-sm preset" data-fg="#0A5C36" data-bg="#FFFFFF">Koyu yeşil</button>
    <button type="button" class="btn btn-ghost btn-sm preset" data-fg="#7A0C1E" data-bg="#FFFFFF">Bordo</button>
    <button type="button" class="btn btn-ghost btn-sm preset" data-fg="#FFFFFF" data-bg="#1C2561" title="Ters renk: koyu zemin üzerinde açık QR">Ters (lacivert zemin)</button>
  </div>
  <div class="form-row">
    <div class="form-group">
      <label class="form-label" for="fg_color">QR rengi</label>
      <div class="color-field">
        <input type="color" id="fg_picker" value="<?= e(strtolower($fgV)) ?>" aria-label="QR rengi seç">
        <input class="form-control" type="text" id="fg_color" name="fg_color" maxlength="7" value="<?= e($fgV) ?>">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label" for="bg_color">Arka plan</label>
      <div class="color-field">
        <input type="color" id="bg_picker" value="<?= e(strtolower($bgV)) ?>" aria-label="Arka plan rengi seç">
        <input class="form-control" type="text" id="bg_color" name="bg_color" maxlength="7" value="<?= e($bgV) ?>">
      </div>
    </div>
  </div>

  <div class="form-row">
    <div class="form-group">
      <label class="form-label" for="shape">Nokta şekli</label>
      <select class="form-control" id="shape" name="shape">
        <?php foreach (LinkService::SHAPES as $k => $label): ?>
          <option value="<?= e($k) ?>" <?= $dv('shape', 'square') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label" for="eye">Köşe biçimi <?= tip('Köşedeki üç büyük kare (göz). Okuyucular QR\'ı bunlardan bulur; biçimi değişse de yapısı korunur.') ?></label>
      <select class="form-control" id="eye" name="eye">
        <?php foreach (QrDesign::EYES as $k => $label): ?>
          <option value="<?= e($k) ?>" <?= $dv('eye', 'square') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="form-row">
    <div class="form-group">
      <label class="form-check">
        <input type="checkbox" id="eye_color_on" name="eye_color_on" value="1" <?= $eyeColorOn ? 'checked' : '' ?>>
        <span>Köşelere ayrı renk</span>
      </label>
      <div class="color-field mt-2" id="eye-color-wrap" style="<?= $eyeColorOn ? '' : 'display:none' ?>">
        <input type="color" id="eye_picker" value="<?= e(strtolower($dv('eye_color', '#0A3A86'))) ?>" aria-label="Köşe rengi seç">
        <input class="form-control" type="text" id="eye_color" name="eye_color" maxlength="7" value="<?= e(strtoupper($dv('eye_color', '#0A3A86'))) ?>">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label" for="gradient">Renk geçişi</label>
      <select class="form-control" id="gradient" name="gradient">
        <?php foreach (QrDesign::GRADIENTS as $k => $label): ?>
          <option value="<?= e($k) ?>" <?= $gradV === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="color-field mt-2" id="fg2-wrap" style="<?= $gradV !== 'none' ? '' : 'display:none' ?>">
        <input type="color" id="fg2_picker" value="<?= e(strtolower($dv('fg2', '#0A3A86'))) ?>" aria-label="İkinci renk seç">
        <input class="form-control" type="text" id="fg2" name="fg2" maxlength="7" value="<?= e(strtoupper($dv('fg2', '#0A3A86'))) ?>">
      </div>
    </div>
  </div>
  <div id="design-msg" class="design-msg text-sm mb-2" aria-live="polite"></div>
</div>

<script>
(function () {
  const $ = (s) => document.querySelector(s);
  const ok = (h) => /^#[0-9a-fA-F]{6}$/.test(h);
  const pairs = [['#fg_picker', '#fg_color'], ['#bg_picker', '#bg_color'], ['#eye_picker', '#eye_color'], ['#fg2_picker', '#fg2']];
  const MIN = <?= QrDesign::MIN_CONTRAST ?>, WARN = <?= QrDesign::WARN_CONTRAST ?>;

  function lum(hex) {
    const v = [1, 3, 5].map(i => parseInt(hex.substr(i, 2), 16) / 255)
      .map(c => c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4));
    return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
  }
  function values() {
    return {
      fg: $('#fg_color').value.toUpperCase(), bg: $('#bg_color').value.toUpperCase(), shape: $('#shape').value, eye: $('#eye').value,
      eye_color: $('#eye_color_on').checked ? $('#eye_color').value.toUpperCase() : '',
      gradient: $('#gradient').value, fg2: $('#gradient').value !== 'none' ? $('#fg2').value.toUpperCase() : ''
    };
  }
  // QrDesign::check ile aynı kurallar: hata kaydı engeller, uyarı yalnızca bilgi verir
  function check() {
    const v = values(), errors = [], warns = [];
    const inks = [['QR rengi', v.fg]];
    if (v.gradient !== 'none') inks.push(['Geçiş rengi', v.fg2]);
    if (v.eye_color) inks.push(['Köşe rengi', v.eye_color]);
    if (![v.bg].concat(inks.map(i => i[1])).every(ok)) return { errors: ['Renkler #RRGGBB biçiminde olmalı.'], warns: [] };
    let light = 0, dark = 0;
    inks.forEach(([label, c]) => {
      const a = lum(c), b = lum(v.bg), ratio = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
      a >= b ? light++ : dark++;
      if (ratio < MIN) errors.push(label + ' ile arka plan arasındaki kontrast çok düşük (' + ratio.toFixed(1) + ':1); QR okunmaz.');
      else if (ratio < WARN) warns.push(label + ' ile kontrast düşük (' + ratio.toFixed(1) + ':1). Baskıdan önce farklı telefonlarla deneyin.');
    });
    if (light && dark) errors.push('Renklerin bir kısmı zeminden koyu, bir kısmı açık. QR\'ın tamamı ya koyu ya açık olmalı.');
    else if (light) warns.push('Ters renk: iPhone ve güncel Android kameraları okur; bazı eski okuyucu uygulamaları okumayabilir.');
    return { errors, warns };
  }
  function render() {
    const el = $('#design-msg'), r = check();
    el.innerHTML = '';
    const line = (cls, text) => { const p = document.createElement('div'); p.className = cls; p.textContent = text; el.appendChild(p); };
    r.errors.forEach(t => line('design-msg-error', t));
    r.warns.forEach(t => line('design-msg-warn', t));
    if (!r.errors.length && !r.warns.length) line('design-msg-ok', '✓ Renkler okunabilir');
  }
  function changed() { render(); document.dispatchEvent(new CustomEvent('qr-design-change')); }

  pairs.forEach(([p, t]) => {
    const pick = $(p), text = $(t);
    pick.addEventListener('input', () => { text.value = pick.value.toUpperCase(); changed(); });
    text.addEventListener('input', () => { if (ok(text.value)) pick.value = text.value.toLowerCase(); changed(); });
  });
  document.querySelectorAll('.design-fields .preset').forEach(b => b.addEventListener('click', () => {
    $('#fg_color').value = b.dataset.fg; $('#bg_color').value = b.dataset.bg;
    $('#fg_picker').value = b.dataset.fg.toLowerCase(); $('#bg_picker').value = b.dataset.bg.toLowerCase();
    changed();
  }));
  ['#shape', '#eye', '#gradient'].forEach(s => $(s).addEventListener('change', changed));
  $('#eye_color_on').addEventListener('change', () => { $('#eye-color-wrap').style.display = $('#eye_color_on').checked ? '' : 'none'; changed(); });
  $('#gradient').addEventListener('change', () => { $('#fg2-wrap').style.display = $('#gradient').value !== 'none' ? '' : 'none'; });

  window.qrDesign = { values, check };
  render();
})();
</script>
