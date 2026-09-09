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
  navigator.clipboard.writeText(text).then(() => {
    const orig = btn.textContent;
    btn.textContent = '✓';
    btn.style.color = 'var(--success)';
    setTimeout(() => {
      btn.textContent = orig;
      btn.style.color = '';
    }, 1500);
  }).catch(() => {
    // Fallback: eski yöntem
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  });
});

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
  const accent  = style.getPropertyValue('--accent').trim()  || '#6366f1';
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
    ctx.font = `${11 * devicePixelRatio / devicePixelRatio}px system-ui`;
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
  ctx.lineWidth   = 2;
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
  ctx.font       = '11px system-ui';
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
