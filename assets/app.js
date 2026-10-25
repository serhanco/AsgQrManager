/* app.js — QR Manager Panel JavaScript */
'use strict';

/* ─── Mobil Sidebar ─────────────────────────────────────────────────────── */
(function () {
  const hamburger  = document.querySelector('.hamburger');
  const sidebar    = document.querySelector('.sidebar');
  const backdrop   = document.querySelector('.sidebar-backdrop');
  if (!hamburger || !sidebar) return;

  function openSidebar() {
    sidebar.classList.add('open');
    backdrop.classList.add('show');
    document.body.style.overflow = 'hidden';
  }
  function closeSidebar() {
    sidebar.classList.remove('open');
    backdrop.classList.remove('show');
    document.body.style.overflow = '';
  }

  hamburger.addEventListener('click', () => {
    sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
  });
  backdrop.addEventListener('click', closeSidebar);
})();

/* ─── Aktif nav bağlantısı ─────────────────────────────────────────────── */
(function () {
  const links = document.querySelectorAll('.sidebar-nav a');
  const path  = location.pathname.replace(/\/+$/, '');
  links.forEach(a => {
    if (a.pathname.replace(/\/+$/, '') === path) {
      a.classList.add('active');
    }
  });
})();

/* ─── URL Kopyala ──────────────────────────────────────────────────────── */
document.addEventListener('click', function (e) {
  const btn = e.target.closest('.copy-btn[data-copy]');
  if (!btn) return;
  const text = btn.dataset.copy;
  const done = () => {
    btn.classList.add('copied');
    const prev = btn.getAttribute('title');
    btn.setAttribute('title', 'Kopyalandı');
    setTimeout(() => { btn.classList.remove('copied'); if (prev !== null) btn.setAttribute('title', prev); }, 1500);
  };
  navigator.clipboard.writeText(text).then(done).catch(() => {
    // Yedek yöntem (HTTP veya eski tarayıcı)
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    done();
  });
});

/* ─── Açık / koyu tema ─────────────────────────────────────────────────── */
(function () {
  const root = document.documentElement;
  const mq = window.matchMedia ? matchMedia('(prefers-color-scheme: dark)') : null;
  if (!root.dataset.theme) root.dataset.theme = mq && mq.matches ? 'dark' : 'light';
  document.querySelectorAll('.theme-toggle').forEach(btn => btn.addEventListener('click', () => {
    const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
    root.dataset.theme = next;
    try { localStorage.setItem('qrm-theme', next); } catch (e) {}
    window.dispatchEvent(new Event('themechange'));
  }));
})();

/* ─── Silme Onayı ──────────────────────────────────────────────────────── */
document.addEventListener('submit', function (e) {
  const form = e.target;
  if (form.dataset.confirm) {
    if (!confirm(form.dataset.confirm)) {
      e.preventDefault();
    }
  }
});

/* ─── Logo Galerisi Seçimi ─────────────────────────────────────────────── */
(function () {
  const gallery  = document.querySelector('.logo-gallery');
  const input    = document.querySelector('#logo-file-input');
  const checkbox = document.querySelector('#use-logo-checkbox');
  if (!gallery || !input) return;

  gallery.addEventListener('click', function (e) {
    const item = e.target.closest('.logo-item');
    if (!item) return;
    gallery.querySelectorAll('.logo-item').forEach(i => i.classList.remove('selected'));
    item.classList.add('selected');
    input.value = item.dataset.file;
  });

  // Checkbox ile galeriyi göster/gizle
  if (checkbox) {
    const galleryWrap = document.querySelector('.logo-gallery-wrap');
    function updateGalleryVisibility() {
      if (galleryWrap) galleryWrap.style.display = checkbox.checked ? '' : 'none';
      if (!checkbox.checked) {
        input.value = '';
        gallery.querySelectorAll('.logo-item').forEach(i => i.classList.remove('selected'));
      }
    }
    checkbox.addEventListener('change', updateGalleryVisibility);
    updateGalleryVisibility(); // ilk yüklemede
  }
})();


/* ─── Dashboard Tarama Grafiği ─────────────────────────────────────────── */
window.drawScanChart = function (canvasId, labels, data) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;
  if (!canvas.dataset.bound) {
    // Boyut veya tema değişince yeniden çiz
    canvas.dataset.bound = '1';
    let t;
    const redraw = () => { clearTimeout(t); t = setTimeout(() => window.drawScanChart(canvasId, labels, data), 120); };
    window.addEventListener('resize', redraw);
    window.addEventListener('themechange', redraw);
  }
  const ctx  = canvas.getContext('2d');
  const w    = canvas.offsetWidth;
  const h    = canvas.offsetHeight;
  canvas.width  = w * devicePixelRatio;
  canvas.height = h * devicePixelRatio;
  ctx.scale(devicePixelRatio, devicePixelRatio);

  const padL = 42, padR = 12, padT = 12, padB = 30;
  const chartW = w - padL - padR;
  const chartH = h - padT - padB;
  const max    = Math.max(...data, 1);
  const n      = data.length;

  // Renk (CSS değişkeni)
  const style = getComputedStyle(document.documentElement);
  const accent  = style.getPropertyValue('--accent').trim()  || '#1F4BA8';
  const muted   = style.getPropertyValue('--border').trim()  || '#e2e8f0';
  const textColor = style.getPropertyValue('--text-muted').trim() || '#64748b';

  // Grid çizgileri
  ctx.strokeStyle = muted;
  ctx.lineWidth   = 1;
  const gridCount = 4;
  for (let i = 0; i <= gridCount; i++) {
    const y = padT + (chartH / gridCount) * i;
    ctx.beginPath();
    ctx.moveTo(padL, y);
    ctx.lineTo(padL + chartW, y);
    ctx.stroke();
    // Y eksen etiket
    ctx.fillStyle = textColor;
    ctx.font = '11px ' + (style.getPropertyValue('--font').trim() || 'system-ui');
    ctx.textAlign = 'right';
    const val = Math.round(max * (1 - i / gridCount));
    ctx.fillText(val, padL - 5, y + 4);
  }

  // Dolgu alanı (gradient)
  const xs  = data.map((_, i) => padL + (i / (n - 1 || 1)) * chartW);
  const ys  = data.map(v => padT + chartH - (v / max) * chartH);

  const grad = ctx.createLinearGradient(0, padT, 0, padT + chartH);
  grad.addColorStop(0, accent + '55');
  grad.addColorStop(1, accent + '00');
  ctx.beginPath();
  ctx.moveTo(xs[0], ys[0]);
  for (let i = 1; i < n; i++) ctx.lineTo(xs[i], ys[i]);
  ctx.lineTo(xs[n-1], padT + chartH);
  ctx.lineTo(xs[0],   padT + chartH);
  ctx.closePath();
  ctx.fillStyle = grad;
  ctx.fill();

  // Çizgi
  ctx.beginPath();
  ctx.strokeStyle = accent;
  ctx.lineWidth   = 2.25;
  ctx.lineJoin    = 'round';
  ctx.moveTo(xs[0], ys[0]);
  for (let i = 1; i < n; i++) ctx.lineTo(xs[i], ys[i]);
  ctx.stroke();

  // Noktalar
  data.forEach((v, i) => {
    if (v === 0) return;
    ctx.beginPath();
    ctx.arc(xs[i], ys[i], 3, 0, Math.PI * 2);
    ctx.fillStyle = accent;
    ctx.fill();
  });

  // X eksen etiketleri (sadece 7'de bir)
  ctx.fillStyle  = textColor;
  ctx.textAlign  = 'center';
  ctx.font       = '11px ' + (style.getPropertyValue('--font').trim() || 'system-ui');
  const step = Math.max(1, Math.ceil(n / 6));
  for (let i = 0; i < n; i += step) {
    const d = labels[i] ? labels[i].slice(5) : ''; // MM-DD
    ctx.fillText(d, xs[i], h - padB + 16);
  }
};

/* ─── Analytics Toggle (AJAX) ──────────────────────────────────────────── */
(function () {
  const toggle = document.querySelector('#analytics-toggle');
  if (!toggle) return;
  toggle.addEventListener('change', function () {
    const enabled = this.checked;
    const base    = document.querySelector('meta[name=base-url]')?.content || '';
    fetch(base + '/admin/analytics-toggle', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: '_csrf=' + encodeURIComponent(document.querySelector('meta[name=csrf]')?.content || '')
           + '&enabled=' + (enabled ? '1' : '0'),
    })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) {
        toggle.checked = !enabled;
        alert('Ayar değiştirilemedi.');
      }
    })
    .catch(() => {
      toggle.checked = !enabled;
    });
  });
})();

/* ─── Log arama/filtre ─────────────────────────────────────────────────── */
(function () {
  const searchInput = document.querySelector('#log-search');
  const logViewer   = document.querySelector('.log-viewer');
  if (!searchInput || !logViewer) return;

  const originalText = logViewer.textContent;
  searchInput.addEventListener('input', function () {
    const q = this.value.toLowerCase().trim();
    if (!q) {
      logViewer.textContent = originalText;
      return;
    }
    const lines = originalText.split('\n');
    logViewer.textContent = lines.filter(l => l.toLowerCase().includes(q)).join('\n');
  });
})();

/* ─── QR önizleme yenile (create sayfasında) ───────────────────────────── */
(function () {
  const form    = document.querySelector('#qr-options-form');
  const preview = document.querySelector('#qr-preview-img');
  if (!form || !preview) return;

  function refreshPreview() {
    const base   = document.querySelector('meta[name=base-url]')?.content || '';
    const slug   = form.dataset.slug;
    if (!slug) return;
    const logo   = form.querySelector('#logo-file-input')?.value || '';
    const url    = `${base}/admin/qr-view?slug=${slug}&fmt=svg&logo=${encodeURIComponent(logo)}`;
    preview.src  = url;
  }

  form.querySelectorAll('input, select').forEach(el => {
    el.addEventListener('change', refreshPreview);
  });
})();

/* ─── Etiket girişi (çip + öneri + uyarı) ──────────────────────────────────── */
(function () {
  const box = document.querySelector('.tag-input');
  if (!box) return;

  const hidden  = document.getElementById('tags');
  const entry   = box.querySelector('input');
  const chipsEl = box.querySelector('.tag-chips');
  const sugEl   = document.getElementById('tag-suggest');
  const countEl = document.getElementById('tag-count');
  const hintEl  = document.getElementById('tag-hint');
  const all     = JSON.parse(box.dataset.all || '[]');            // [{tag,count}]
  const cats    = JSON.parse(box.dataset.categories || '{}');     // {key: label}
  const MAX     = parseInt(box.dataset.max || '10', 10);
  const catSel  = document.getElementById('category');

  // Sunucudaki LinkService::tagKey ile aynı kural: büyük/küçük harf ve Türkçe aksan farkı yok sayılır
  const key = t => t.replace(/İ|I|ı/g, 'i').replace(/[Çç]/g, 'c').replace(/[Ğğ]/g, 'g')
                    .replace(/[Öö]/g, 'o').replace(/[Şş]/g, 's').replace(/[Üü]/g, 'u').toLowerCase();
  const clean = t => t.replace(/\s+/g, ' ').replace(/[^\p{L}\p{N} _\-.]/gu, '').trim();
  const known = new Map(all.map(a => [key(a.tag), a.tag]));

  let tags = (hidden.value || '').split(',').map(clean).filter(Boolean);
  let sel = -1;

  function sync() {
    hidden.value = tags.join(',');
    chipsEl.innerHTML = '';
    tags.forEach((t, i) => {
      const c = document.createElement('span');
      c.className = 'tag-chip';
      c.textContent = '#' + t;
      const b = document.createElement('button');
      b.type = 'button'; b.setAttribute('aria-label', t + ' etiketini kaldır'); b.textContent = '×';
      b.addEventListener('click', () => { tags.splice(i, 1); sync(); entry.focus(); });
      c.appendChild(b);
      chipsEl.appendChild(c);
    });
    countEl.textContent = tags.length + '/' + MAX + ' etiket';
    entry.placeholder = tags.length ? '' : 'Etiket yazıp Enter’a basın (örn. UK, poster)';
    document.querySelectorAll('.tag-popular [data-tag]').forEach(b => {
      b.hidden = tags.some(t => key(t) === key(b.dataset.tag));
    });
  }

  function say(msg, warn) {
    hintEl.textContent = msg || '';
    hintEl.className = warn ? 'warn' : '';
  }

  function add(raw) {
    let t = clean(raw);
    if (!t) return false;
    if (t.length > 30) { say('Etiket en fazla 30 karakter olabilir.', true); return false; }
    const k = key(t);
    if (tags.some(x => key(x) === k)) { say('“' + t + '” zaten ekli.', true); return false; }
    if (tags.length >= MAX) { say('En fazla ' + MAX + ' etiket eklenebilir.', true); return false; }
    if (known.has(k) && known.get(k) !== t) {
      say('“' + t + '” mevcut etiketle birleştirildi: ' + known.get(k), false);
      t = known.get(k);
    } else {
      const catHit = Object.entries(cats).find(([ck, cl]) => key(cl) === k || ck === k || (k === 'ofis' && ck === 'office') || (k === 'etkinlik' && ck === 'event'));
      say(catHit ? '“' + t + '” zaten “Tür” alanında var; etiket olarak tekrar gerekmez (yine de eklendi).'
                 : (!known.has(k) && all.length ? 'Yeni etiket: “' + t + '”. Aynı anlama gelen bir etiket varsa onu seçin; raporlar bölünmesin.' : ''),
          !!catHit);
    }
    tags.push(t);
    entry.value = '';
    closeSuggest();
    sync();
    return true;
  }

  function closeSuggest() { sugEl.hidden = true; sel = -1; }

  function renderSuggest() {
    const q = key(clean(entry.value));
    const items = all.filter(a => !tags.some(t => key(t) === key(a.tag)) && (q === '' || key(a.tag).includes(q))).slice(0, 8);
    sugEl.innerHTML = '';
    if (!items.length || (q === '' && document.activeElement !== entry)) { closeSuggest(); return; }
    items.forEach((a, i) => {
      const b = document.createElement('button');
      b.type = 'button'; b.setAttribute('role', 'option');
      b.innerHTML = '<span></span><small></small>';
      b.firstChild.textContent = '#' + a.tag;
      b.lastChild.textContent = a.count + ' QR';
      b.addEventListener('mousedown', e => { e.preventDefault(); add(a.tag); entry.focus(); });
      sugEl.appendChild(b);
    });
    sugEl.hidden = false;
    sel = -1;
  }

  entry.addEventListener('input', () => {
    if (/[,;]/.test(entry.value)) {
      entry.value.split(/[,;]/).forEach(p => add(p));
      return;
    }
    say('');
    renderSuggest();
  });
  entry.addEventListener('focus', renderSuggest);
  entry.addEventListener('keydown', e => {
    const opts = [...sugEl.querySelectorAll('button')];
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      if (sugEl.hidden || !opts.length) return;
      e.preventDefault();
      sel = (sel + (e.key === 'ArrowDown' ? 1 : -1) + opts.length) % opts.length;
      opts.forEach((o, i) => o.classList.toggle('sel', i === sel));
    } else if (e.key === 'Enter' || e.key === 'Tab' && entry.value.trim()) {
      if (e.key === 'Enter') e.preventDefault();       // Enter formu göndermesin
      if (sel >= 0 && opts[sel]) { add(all.filter(a => !tags.some(t => key(t) === key(a.tag)) && key(a.tag).includes(key(clean(entry.value))))[sel].tag); }
      else if (entry.value.trim()) add(entry.value);
    } else if (e.key === 'Backspace' && !entry.value && tags.length) {
      tags.pop(); sync();
    } else if (e.key === 'Escape') {
      closeSuggest();
    }
  });
  entry.addEventListener('blur', () => { if (entry.value.trim()) add(entry.value); closeSuggest(); });
  box.addEventListener('click', () => entry.focus());
  box.closest('form').addEventListener('submit', () => { if (entry.value.trim()) add(entry.value); });

  // Sık kullanılan / önerilen etiket düğmeleri
  document.querySelectorAll('.tag-popular [data-tag]').forEach(b =>
    b.addEventListener('click', () => { add(b.dataset.tag); }));

  // Tür değişince, aynı kavramı etiket olarak girmeye karşı nazik uyarı
  if (catSel) catSel.addEventListener('change', () => {
    const lbl = cats[catSel.value];
    if (lbl && tags.some(t => key(t) === key(lbl))) say('“' + lbl + '” artık “Tür” alanında; etiketten çıkarabilirsiniz.', true);
  });

  sync();
})();

/* ─── Listede çoklu seçim ──────────────────────────────────────────────────── */
(function () {
  const bar = document.getElementById('bulk-bar');
  if (!bar) return;
  const boxes = () => [...document.querySelectorAll('.row-sel')];
  const master = document.getElementById('sel-all');
  const label = bar.querySelector('[data-count]');
  function refresh() {
    const n = boxes().filter(b => b.checked).length;
    bar.classList.toggle('show', n > 0);
    label.textContent = n + ' QR seçili';
    if (master) master.checked = n > 0 && n === boxes().length;
  }
  boxes().forEach(b => b.addEventListener('change', refresh));
  if (master) master.addEventListener('change', () => { boxes().forEach(b => b.checked = master.checked); refresh(); });
  bar.querySelector('[data-clear]').addEventListener('click', () => { boxes().forEach(b => b.checked = false); refresh(); });
  bar.closest('form').addEventListener('submit', e => {
    const f = e.target;
    if (!f.add.value.trim() && !f.remove.value.trim()) { e.preventDefault(); f.add.focus(); }
  });
  refresh();
})();
