/* Jendela "Edit profil" + pemotong foto (crop, geser, zoom). Tanpa library. */
(function () {
  const modal = document.getElementById('pfeModal');
  if (!modal) return;
  const inline = modal.hasAttribute('data-inline');   // tampil langsung di halaman Pengaturan (bukan jendela)
  const $ = (s) => modal.querySelector(s) || document.querySelector(s);   // jendela crop dipindah ke <body>
  const body = document.body;
  const BASE = body.dataset.base || '';
  const CSRF = body.dataset.csrf || '';
  const toast = (m) => (window.ITIHIX && window.ITIHIX.toast) ? window.ITIHIX.toast(m) : alert(m);

  const OUT = 512;                 // ukuran foto hasil (px, persegi)
  const MAX_ZOOM = 4;              // 1x = pas menutup lingkaran, 4x = paling dekat
  const MAX_FILE = 25 * 1024 * 1024;

  const el = {
    file: $('#pfeFile'), name: $('#pfeName'), bio: $('#pfeBio'), bioN: $('#pfeBioN'),
    preview: $('#pfePreview'), cropBox: $('#pfeCrop'), stage: $('#pfeStage'), canvas: $('#pfeCanvas'),
    zoom: $('#pfeZoom'), save: $('#pfeSave'), remove: $('#pfeRemove'), pick: $('#pfePick'),
    bn: $('#pfeBn'), banners: $('#pfeBanners'), bnCustom: $('#pfeBannerCustom'), bnCustomLabel: $('#pfeBannerCustomLabel'),
    cancelCrop: $('#pfeCropCancel'), apply: $('#pfeCropApply'), reset: $('#pfeReset'), colors: $('#pfeColors'), colorWrap: $('#pfeColorWrap'),
  };
  const ctx = el.canvas.getContext('2d');
  // Crop tampil di jendela sendiri, di atas jendela Edit profil / halaman Pengaturan
  if (el.cropBox && el.cropBox.parentNode !== document.body) document.body.appendChild(el.cropBox);
  const winOpen = () => !el.cropBox.classList.contains('hidden');
  function showWin() { el.cropBox.classList.remove('hidden'); body.classList.add('pfe-open'); }
  function hideWin() { el.cropBox.classList.add('hidden'); if (inline || modal.classList.contains('hidden')) body.classList.remove('pfe-open'); }

  let img = null;            // HTMLImageElement bila sedang memotong foto baru
  let S = 280, D = 252;      // sisi kanvas & diameter lingkaran potong (px CSS)
  let zoom = 1, ox = 0, oy = 0;
  let removeFlag = false;
  let croppedBlob = null, croppedUrl = '';   // hasil crop yang sudah di-"Terapkan"
  let bannerVal = '';        // '' = banner bawaan, selain itu hex #rrggbb
  let downOnBackdrop = false;
  const ptrs = new Map();
  let pinch0 = null;

  /* ---------- Buka / tutup ---------- */
  function open() {
    el.name.value = el.name.defaultValue; el.bio.value = el.bio.defaultValue; countBio();
    const checked = el.colors.querySelector('input[value="' + el.colors.dataset.current + '"]');
    if (checked) checked.checked = true;
    setBanner(el.banners.dataset.current || '');
    syncColor(); clearNew(); cancelCrop(true); removeFlag = false; paintRemove();
    if (inline) return;
    modal.classList.remove('hidden'); body.classList.add('pfe-open');
    setTimeout(() => el.name.focus(), 30);
  }
  function close() {
    if (inline) { open(); return; }                    // di Pengaturan, "Batal" = kembalikan isian semula
    modal.classList.add('hidden'); body.classList.remove('pfe-open'); cancelCrop(true);
  }
  document.addEventListener('click', (ev) => {
    const t = ev.target.closest('[data-pfe-open]');
    if (t) { ev.preventDefault(); open(); }
  });
  modal.addEventListener('mousedown', (ev) => { downOnBackdrop = ev.target === modal; });
  modal.addEventListener('click', (ev) => { if ((ev.target === modal && downOnBackdrop) || ev.target.closest('[data-pfe-close]')) close(); });
  document.addEventListener('keydown', (ev) => {
    if (ev.key !== 'Escape') return;
    if (winOpen()) { ev.stopImmediatePropagation(); abortCrop(); return; }   // Esc menutup jendela crop dulu
    if (!inline && !modal.classList.contains('hidden')) close();
  });

  /* ---------- Bio & warna ---------- */
  function countBio() { el.bioN.textContent = el.bio.value.length + '/280'; }
  el.bio.addEventListener('input', countBio);
  function syncColor() {
    const c = (el.colors.querySelector('input:checked') || {}).value || '#ffe0e2';
    el.colors.querySelectorAll('label span').forEach((s) => s.classList.toggle('on', s.parentNode.querySelector('input').value === c));
    const av = el.preview.querySelector('.pfe-av'); if (av) av.style.background = c;
  }
  el.colors.addEventListener('change', syncColor);

  /* ---------- Warna banner ---------- */
  function setBanner(v) {
    bannerVal = (v || '').toLowerCase();
    let preset = false;
    el.banners.querySelectorAll('button.pfe-sw').forEach((b) => {
      const on = b.dataset.color.toLowerCase() === bannerVal;
      b.classList.toggle('on', on); if (on) preset = true;
    });
    const custom = !!bannerVal && !preset;
    el.bnCustomLabel.classList.toggle('on', custom);
    el.bnCustomLabel.style.background = custom ? bannerVal : '';
    if (custom) el.bnCustom.value = bannerVal;
    el.bn.classList.toggle('has-color', !!bannerVal);
    if (bannerVal) el.bn.style.setProperty('--bn', bannerVal); else el.bn.style.removeProperty('--bn');
  }
  el.banners.addEventListener('click', (ev) => {
    const b = ev.target.closest('button.pfe-sw'); if (b) setBanner(b.dataset.color);
  });
  el.bnCustom.addEventListener('input', () => setBanner(el.bnCustom.value));

  /* ---------- Pilih foto ---------- */
  el.pick.addEventListener('click', () => el.file.click());
  el.file.addEventListener('change', () => {
    const f = el.file.files && el.file.files[0];
    el.file.value = '';
    if (!f) return;
    if (!/^image\//.test(f.type)) { toast('Pilih file gambar (JPG, PNG, WebP).'); return; }
    if (f.size > MAX_FILE) { toast('Ukuran foto terlalu besar (maks. 25 MB).'); return; }
    const url = URL.createObjectURL(f);
    const im = new Image();
    im.onload = () => { URL.revokeObjectURL(url); startCrop(im); };
    im.onerror = () => { URL.revokeObjectURL(url); toast('Foto tidak bisa dibaca. Coba file lain.'); };
    im.src = url;
  });

  function startCrop(im) {
    img = im; removeFlag = false; paintRemove();
    showWin();
    measure(); zoom = 1; ox = 0; oy = 0; el.zoom.value = 1; draw();
  }
  function cancelCrop(silent) {
    img = null; ptrs.clear(); pinch0 = null;
    hideWin();
    if (!silent) syncColor();
  }
  function clearNew() { croppedBlob = null; if (croppedUrl) { URL.revokeObjectURL(croppedUrl); croppedUrl = ''; } }
  /* Batal / X / Esc di jendela crop: tutup tanpa memakai foto baru (foto yang sudah diterapkan sebelumnya tetap) */
  const abortCrop = () => { cancelCrop(); paintAvatar(); };
  el.cancelCrop.addEventListener('click', abortCrop);
  const cropX = $('#pfeCropX'); if (cropX) cropX.addEventListener('click', abortCrop);
  /* Terapkan: kunci hasil potongan, tutup area crop, tampilkan pratinjau avatar baru */
  if (el.apply) el.apply.addEventListener('click', async () => {
    if (!img) return;
    const b = await cropBlob(); if (!b) { toast('Gagal memotong foto, coba lagi.'); return; }
    clearNew(); croppedBlob = b; croppedUrl = URL.createObjectURL(b);
    removeFlag = false; paintRemove();
    img = null; ptrs.clear(); pinch0 = null;
    hideWin();
    paintAvatar();
  });
  el.reset.addEventListener('click', () => { zoom = 1; ox = 0; oy = 0; el.zoom.value = 1; draw(); });

  /* ---------- Geometri ---------- */
  function measure() {
    S = Math.round(el.stage.clientWidth) || 280;
    D = Math.round(S * 0.9);
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    el.canvas.width = S * dpr; el.canvas.height = S * dpr;
    el.canvas.style.width = S + 'px'; el.canvas.style.height = S + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    el.stage.style.setProperty('--r', (D / 2) + 'px');
    el.stage.style.setProperty('--d', D + 'px');
  }
  const base = () => D / Math.min(img.naturalWidth, img.naturalHeight);   // skala "cover" pada lingkaran
  const scale = () => base() * zoom;
  function clamp() {
    const mx = Math.max(0, (img.naturalWidth * scale() - D) / 2);
    const my = Math.max(0, (img.naturalHeight * scale() - D) / 2);
    ox = Math.min(mx, Math.max(-mx, ox)); oy = Math.min(my, Math.max(-my, oy));
  }
  function draw() {
    if (!img) return;
    clamp();
    const sc = scale(), w = img.naturalWidth * sc, h = img.naturalHeight * sc;
    ctx.clearRect(0, 0, S, S);
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(img, S / 2 + ox - w / 2, S / 2 + oy - h / 2, w, h);
  }
  function setZoom(z) {   // zoom terhadap titik tengah lingkaran
    z = Math.min(MAX_ZOOM, Math.max(1, z));
    const k = z / zoom; ox *= k; oy *= k; zoom = z;
    el.zoom.value = z; draw();
  }
  el.zoom.addEventListener('input', () => setZoom(parseFloat(el.zoom.value)));
  $('#pfeZoomIn').addEventListener('click', () => setZoom(zoom + 0.25));
  $('#pfeZoomOut').addEventListener('click', () => setZoom(zoom - 0.25));
  el.stage.addEventListener('wheel', (ev) => { if (!img) return; ev.preventDefault(); setZoom(zoom * (ev.deltaY < 0 ? 1.08 : 1 / 1.08)); }, { passive: false });

  /* ---------- Geser (1 jari/mouse) & cubit (2 jari) ---------- */
  const dist = () => { const p = [...ptrs.values()]; return Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y); };
  el.canvas.addEventListener('pointerdown', (ev) => {
    if (!img) return;
    el.canvas.setPointerCapture(ev.pointerId);
    ptrs.set(ev.pointerId, { x: ev.clientX, y: ev.clientY });
    if (ptrs.size === 2) pinch0 = { d: dist(), z: zoom };
    el.stage.classList.add('grab');
  });
  el.canvas.addEventListener('pointermove', (ev) => {
    const p = ptrs.get(ev.pointerId); if (!p || !img) return;
    if (ptrs.size === 1) { ox += ev.clientX - p.x; oy += ev.clientY - p.y; p.x = ev.clientX; p.y = ev.clientY; draw(); }
    else { p.x = ev.clientX; p.y = ev.clientY; if (ptrs.size === 2 && pinch0 && pinch0.d > 0) setZoom(pinch0.z * dist() / pinch0.d); }
  });
  const up = (ev) => { ptrs.delete(ev.pointerId); pinch0 = null; if (!ptrs.size) el.stage.classList.remove('grab'); };
  el.canvas.addEventListener('pointerup', up); el.canvas.addEventListener('pointercancel', up);
  el.canvas.addEventListener('keydown', (ev) => {   // aksesibilitas: panah = geser, +/- = zoom
    const st = 12; let h = true;
    if (ev.key === 'ArrowLeft') ox += st; else if (ev.key === 'ArrowRight') ox -= st;
    else if (ev.key === 'ArrowUp') oy += st; else if (ev.key === 'ArrowDown') oy -= st;
    else if (ev.key === '+' || ev.key === '=') setZoom(zoom + 0.1); else if (ev.key === '-') setZoom(zoom - 0.1); else h = false;
    if (h) { ev.preventDefault(); draw(); }
  });
  window.addEventListener('resize', () => { if (img && winOpen()) { measure(); draw(); } });

  /* ---------- Hapus foto ---------- */
  const avEl = () => el.preview.querySelector('.pfe-av');
  const avOriginal = avEl() ? avEl().innerHTML : '';   // isi avatar asli (foto atau inisial)
  const hasPhoto = el.preview.dataset.hasPhoto === '1';
  function initialsOf(n) {                              // sama dengan initials() di PHP
    const p = n.trim().split(/\s+/).filter(Boolean);
    const f = (w) => (w ? Array.from(w)[0] : '');
    return ((f(p[0]) || '?') + f(p[1])).toUpperCase();
  }
  function paintAvatar() {                              // foto dihapus / tanpa foto -> langsung jadi huruf
    const a = avEl(); if (!a) return;
    if (croppedBlob) { a.innerHTML = '<img src="' + croppedUrl + '" alt="" style="width:100%;height:100%;object-fit:cover">'; return; }
    if (!hasPhoto) { a.textContent = initialsOf(el.name.value); return; }
    if (removeFlag) a.textContent = initialsOf(el.name.value); else a.innerHTML = avOriginal;
  }
  el.name.addEventListener('input', () => { if (!croppedBlob && (!hasPhoto || removeFlag)) paintAvatar(); });
  function paintRemove() {
    if (el.remove) {
      el.remove.classList.toggle('is-on', removeFlag);
      el.remove.querySelector('span').textContent = removeFlag ? 'Batal hapus foto' : 'Hapus foto';
    }
    paintAvatar();
  }
  if (el.remove) el.remove.addEventListener('click', () => { removeFlag = !removeFlag; if (removeFlag) { clearNew(); if (img) cancelCrop(); } paintRemove(); });

  /* ---------- Hasil crop -> Blob ---------- */
  function cropBlob() {
    return new Promise((res) => {
      const c = document.createElement('canvas'); c.width = OUT; c.height = OUT;
      const g = c.getContext('2d'); g.imageSmoothingQuality = 'high';
      g.fillStyle = '#fff'; g.fillRect(0, 0, OUT, OUT);
      const k = OUT / D, sc = scale(), w = img.naturalWidth * sc, h = img.naturalHeight * sc;
      const left = (S / 2 + ox - w / 2) - (S / 2 - D / 2), top = (S / 2 + oy - h / 2) - (S / 2 - D / 2);
      g.drawImage(img, left * k, top * k, w * k, h * k);
      c.toBlob((b) => res(b), 'image/jpeg', 0.92);
    });
  }

  /* ---------- Simpan ---------- */
  el.save.addEventListener('click', async () => {
    const name = el.name.value.trim();
    if (!name) { toast('Nama tampilan wajib diisi.'); el.name.focus(); return; }
    el.save.disabled = true; const label = el.save.textContent; el.save.textContent = 'Menyimpan…';
    const fd = new FormData();
    fd.append('display_name', name); fd.append('bio', el.bio.value);
    fd.append('banner_color', bannerVal);
    fd.append('avatar_color', (el.colors.querySelector('input:checked') || {}).value || '#ffe0e2');
    if (removeFlag) fd.append('remove_avatar', '1');
    if (croppedBlob) fd.append('avatar', croppedBlob, 'avatar.jpg');
    else if (img) { const b = await cropBlob(); if (b) fd.append('avatar', b, 'avatar.jpg'); }
    try {
      const r = await fetch(BASE + '/api/profile_update.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'fetch' } });
      const j = await r.json();
      if (j.ok) { location.reload(); return; }
      toast(j.error || 'Gagal menyimpan profil.');
    } catch (e) { toast('Koneksi bermasalah, coba lagi.'); }
    el.save.disabled = false; el.save.textContent = label;
  });

  /* ---------- Mulai (setelah semua const di atas terdefinisi) ---------- */
  if (inline) open(); else if (location.hash === '#edit') open();
})();