/*
 * Editor foto ringan (sisi browser) untuk foto yang akan diunggah:
 *   - Coretan (pena, bisa pilih warna & ketebalan)
 *   - Blur  (kuas atau kotak)
 *   - Mosaik / pixelate (kuas atau kotak)
 *   - Undo / Redo / Reset
 * Dipakai lewat:  ITIHIX_PHOTO_EDITOR.open(file, { maxBytes }).then(fileBaru | null)
 * Foto asli tidak diubah di server: hasil edit menggantikan File di form sebelum dikirim.
 * Hasil ekspor juga membuang metadata EXIF (lokasi GPS dll.), jadi lebih aman.
 */
(function () {
  'use strict';
  const MAX_SIDE = 2400;     // sisi terpanjang kanvas kerja (menjaga memori & kecepatan)
  const MAX_UNDO = 12;
  const COLORS = ['#000000', '#ffffff', '#e53945', '#f5a623', '#ffd60a', '#2fb86b', '#2d7ff9', '#8e5cf7'];
  const ICON = {
    pen: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
    blur: '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 2.7S5 10 5 14.5a7 7 0 0 0 14 0C19 10 12 2.7 12 2.7z" opacity=".9"/><circle cx="9.5" cy="15" r="1.1" fill="#fff" opacity=".7"/></svg>',
    mosaic: '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><rect x="3" y="3" width="6" height="6"/><rect x="15" y="3" width="6" height="6"/><rect x="9" y="9" width="6" height="6"/><rect x="3" y="15" width="6" height="6"/><rect x="15" y="15" width="6" height="6"/></svg>',
    brush: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 18c4-1 3-7 8-7s2 8 8 6"/></svg>',
    box: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="3 2.5"><rect x="4" y="5" width="16" height="14" rx="1.5"/></svg>'
  };
  const ic = n => (window.icn ? window.icn(n) : '');
  const canvasOf = (w, h) => { const c = document.createElement('canvas'); c.width = Math.max(1, Math.round(w)); c.height = Math.max(1, Math.round(h)); return c; };
  const HAS_FILTER = (() => { try { return 'filter' in canvasOf(1, 1).getContext('2d'); } catch (_) { return false; } })();

  /** Perkecil bertahap (kualitas bagus) sampai ukuran target. */
  function shrink(src, tw, th) {
    let cur = src, cw = src.width, ch = src.height;
    tw = Math.max(1, Math.round(tw)); th = Math.max(1, Math.round(th));
    while (cw > tw || ch > th) {
      const nw = Math.max(tw, Math.floor(cw / 2)), nh = Math.max(th, Math.floor(ch / 2));
      const c = canvasOf(nw, nh), x = c.getContext('2d');
      x.imageSmoothingEnabled = true; x.imageSmoothingQuality = 'high';
      x.drawImage(cur, 0, 0, cw, ch, 0, 0, nw, nh);
      cur = c; cw = nw; ch = nh;
    }
    return cur;
  }
  /** Lapisan efek seukuran sumber: blur atau mosaik. */
  function makeFx(src, kind, amount) {
    const w = src.width, h = src.height, out = canvasOf(w, h), x = out.getContext('2d');
    if (kind === 'mosaic') {
      const b = Math.max(2, Math.round(amount));
      const small = shrink(src, w / b, h / b);
      x.imageSmoothingEnabled = false;
      x.drawImage(small, 0, 0, small.width, small.height, 0, 0, w, h);
    } else if (HAS_FILTER) {
      x.drawImage(src, 0, 0);                                  // alas agar tepi tidak menipis
      x.filter = 'blur(' + Math.max(1, amount) + 'px)';
      x.drawImage(src, 0, 0);
      x.filter = 'none';
    } else {                                                   // cadangan untuk Safari lama
      const k = Math.max(2, amount * 0.9);
      const small = shrink(src, w / k, h / k);
      x.imageSmoothingEnabled = true; x.imageSmoothingQuality = 'high';
      x.drawImage(small, 0, 0, small.width, small.height, 0, 0, w, h);
    }
    return out;
  }

  function loadImage(file) {
    return new Promise((res, rej) => {
      const url = URL.createObjectURL(file), img = new Image();
      img.onload = () => { URL.revokeObjectURL(url); res(img); };
      img.onerror = () => { URL.revokeObjectURL(url); rej(new Error('load')); };
      img.src = url;
    });
  }
  const toBlob = (cv, type, q) => new Promise(r => cv.toBlob(r, type, q));

  async function encode(cv, origType, maxBytes) {
    let type = /^image\/(jpeg|png|webp)$/.test(origType) ? origType : 'image/png';
    let blob = await toBlob(cv, type, 0.92);
    if (!blob) { type = 'image/jpeg'; blob = await toBlob(cv, type, 0.92); }
    if (maxBytes && blob.size > maxBytes) { type = 'image/jpeg'; blob = await toBlob(flatten(cv), type, 0.85); }
    let scale = 1, c = cv;
    while (maxBytes && blob && blob.size > maxBytes && scale > 0.3) {
      scale *= 0.8; c = canvasOf(cv.width * scale, cv.height * scale);
      const x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.drawImage(cv, 0, 0, c.width, c.height);
      blob = await toBlob(c, 'image/jpeg', 0.82);
    }
    return { blob, type };
  }
  function flatten(cv) { // JPEG tidak punya transparansi -> alas putih
    const c = canvasOf(cv.width, cv.height), x = c.getContext('2d');
    x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.drawImage(cv, 0, 0); return c;
  }
  const EXT = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp' };

  function open(file, opts) {
    opts = opts || {};
    return new Promise(async resolve => {
      let img;
      try { img = await loadImage(file); } catch (_) { alert('Foto ini tidak bisa dibuka di editor.'); return resolve(null); }
      let W = img.naturalWidth, H = img.naturalHeight;
      const k = Math.min(1, MAX_SIDE / Math.max(W, H)); W = Math.round(W * k); H = Math.round(H * k);

      const cv = canvasOf(W, H), ctx = cv.getContext('2d', { willReadFrequently: false });
      ctx.imageSmoothingQuality = 'high'; ctx.drawImage(img, 0, 0, W, H);

      const root = document.createElement('div');
      root.className = 'pe'; root.setAttribute('role', 'dialog'); root.setAttribute('aria-modal', 'true'); root.setAttribute('aria-label', 'Edit foto');
      root.innerHTML =
        '<div class="pe-bar">' +
          '<div class="pe-tools" role="tablist">' +
            '<button type="button" class="pe-tool on" data-tool="pen">' + ICON.pen + '<span>Coretan</span></button>' +
            '<button type="button" class="pe-tool" data-tool="blur">' + ICON.blur + '<span>Blur</span></button>' +
            '<button type="button" class="pe-tool" data-tool="mosaic">' + ICON.mosaic + '<span>Mosaik</span></button>' +
          '</div>' +
          '<div class="pe-acts">' +
            '<button type="button" class="pe-ib" data-pe="undo" title="Urungkan (Ctrl+Z)" aria-label="Urungkan">' + ic('undo') + '</button>' +
            '<button type="button" class="pe-ib" data-pe="redo" title="Ulangi (Ctrl+Y)" aria-label="Ulangi">' + ic('redo') + '</button>' +
            '<button type="button" class="pe-ib" data-pe="reset" title="Kembalikan ke foto awal" aria-label="Reset">' + ic('trash') + '</button>' +
          '</div>' +
        '</div>' +
        '<div class="pe-opts">' +
          '<div class="pe-mode" data-for="blur mosaic">' +
            '<button type="button" class="on" data-mode="brush">' + ICON.brush + ' Kuas</button>' +
            '<button type="button" data-mode="box">' + ICON.box + ' Kotak</button>' +
          '</div>' +
          '<div class="pe-colors" data-for="pen"></div>' +
          '<label class="pe-rng" data-for="pen blur mosaic"><span id="peSizeL">Ukuran</span><input type="range" id="peSize" min="2" max="80" value="8"></label>' +
          '<label class="pe-rng" data-for="blur mosaic"><span id="peStrL">Kekuatan</span><input type="range" id="peStr" min="1" max="100" value="45"></label>' +
        '</div>' +
        '<div class="pe-stage" id="peStage"><div class="pe-wrap" id="peWrap"></div></div>' +
        '<div class="pe-foot">' +
          '<span class="pe-hint" id="peHint"></span>' +
          '<div class="pe-foot-btns"><button type="button" class="btn btn-light" data-pe="cancel">Batal</button><button type="button" class="btn btn-primary" data-pe="save">' + ic('check') + ' Simpan</button></div>' +
        '</div>';
      document.body.appendChild(root);
      document.body.classList.add('pe-open');

      const wrap = root.querySelector('#peWrap'), stage = root.querySelector('#peStage');
      cv.className = 'pe-cv';
      const ov = document.createElement('canvas'); ov.className = 'pe-ov'; ov.width = W; ov.height = H; // kotak seleksi & pratinjau kuas
      wrap.appendChild(cv); wrap.appendChild(ov);
      const octx = ov.getContext('2d');

      // ---- state ----
      let tool = 'pen', mode = 'brush', color = COLORS[2], dirty = false;
      const sizeEl = root.querySelector('#peSize'), strEl = root.querySelector('#peStr');
      const undoS = [], redoS = [];
      const snap = () => { const c = canvasOf(W, H); c.getContext('2d').drawImage(cv, 0, 0); return c; };
      const original = snap();
      const pushUndo = () => { undoS.push(snap()); if (undoS.length > MAX_UNDO) undoS.shift(); redoS.length = 0; dirty = true; upd(); };
      const restore = c => { ctx.globalCompositeOperation = 'source-over'; ctx.clearRect(0, 0, W, H); ctx.drawImage(c, 0, 0); };
      const upd = () => {
        root.querySelector('[data-pe=undo]').disabled = !undoS.length;
        root.querySelector('[data-pe=redo]').disabled = !redoS.length;
        root.querySelector('[data-pe=reset]').disabled = !undoS.length && !dirty;
      };

      // warna
      const colBox = root.querySelector('.pe-colors');
      colBox.innerHTML = COLORS.map(c => '<button type="button" class="pe-sw' + (c === color ? ' on' : '') + '" data-c="' + c + '" style="--c:' + c + '" aria-label="Warna ' + c + '"></button>').join('') +
        '<label class="pe-sw pe-sw-custom" title="Warna lain"><input type="color" id="peColor" value="' + color + '"></label>';
      colBox.addEventListener('click', e => {
        const b = e.target.closest('[data-c]'); if (!b) return;
        color = b.dataset.c; root.querySelector('#peColor').value = color; markColor();
      });
      root.querySelector('#peColor').addEventListener('input', e => { color = e.target.value; markColor(); });
      const markColor = () => colBox.querySelectorAll('[data-c]').forEach(b => b.classList.toggle('on', b.dataset.c.toLowerCase() === color.toLowerCase()));

      const hints = {
        pen: 'Gambar langsung di foto untuk mencoret atau menyamarkan.',
        blur: 'Usap area yang mau dikaburkan (nomor, wajah, nama, alamat).',
        mosaic: 'Usap area yang mau dimosaik. Cocok untuk menutup data pribadi.'
      };
      const hintBox = { blur: 'Seret untuk membuat kotak area yang dikaburkan.', mosaic: 'Seret untuk membuat kotak area yang dimosaik.' };
      const refresh = () => {
        root.querySelectorAll('.pe-tool').forEach(b => b.classList.toggle('on', b.dataset.tool === tool));
        root.querySelectorAll('[data-for]').forEach(el => el.classList.toggle('hidden', el.dataset.for.split(' ').indexOf(tool) < 0));
        root.querySelectorAll('[data-mode]').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
        root.querySelector('#peStrL').textContent = tool === 'mosaic' ? 'Ukuran blok' : 'Kekuatan blur';
        root.querySelector('#peSizeL').textContent = tool === 'pen' ? 'Tebal pena' : 'Ukuran kuas';
        sizeEl.closest('label').classList.toggle('hidden', tool !== 'pen' && mode === 'box');
        root.querySelector('#peHint').textContent = tool !== 'pen' && mode === 'box' ? hintBox[tool] : hints[tool];
        ov.style.cursor = 'crosshair';
      };
      const sizes = { pen: 8, blur: 34, mosaic: 34 };
      root.querySelectorAll('.pe-tool').forEach(b => b.addEventListener('click', () => { sizes[tool] = +sizeEl.value; tool = b.dataset.tool; sizeEl.value = sizes[tool]; refresh(); }));
      root.querySelectorAll('[data-mode]').forEach(b => b.addEventListener('click', () => { mode = b.dataset.mode; refresh(); }));

      // ---- koordinat & ukuran ----
      const scaleOf = () => W / ov.getBoundingClientRect().width;   // piksel kanvas per piksel layar
      const pt = e => { const r = ov.getBoundingClientRect(), s = W / r.width; return { x: (e.clientX - r.left) * s, y: (e.clientY - r.top) * s }; };
      const brushPx = () => Math.max(1, +sizeEl.value * scaleOf());
      const fxAmount = () => {
        const v = +strEl.value / 100, base = Math.max(W, H);
        return tool === 'mosaic' ? Math.max(4, v * base / 22) : Math.max(2, v * base / 50);
      };

      // ---- menggambar ----
      let drawing = false, last = null, start = null, fx = null, pat = null;
      const strokeSeg = (a, b) => {
        ctx.save();
        ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.lineWidth = brushPx();
        if (tool === 'pen') ctx.strokeStyle = color; else ctx.strokeStyle = pat;
        ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x + 0.01, b.y + 0.01); ctx.stroke();
        ctx.restore();
      };
      const clearOv = () => octx.clearRect(0, 0, W, H);
      const drawCursor = p => {
        clearOv(); if (!p || (tool !== 'pen' && mode === 'box')) return;
        octx.save(); octx.lineWidth = Math.max(1, scaleOf());
        octx.strokeStyle = 'rgba(255,255,255,.95)'; octx.beginPath(); octx.arc(p.x, p.y, brushPx() / 2, 0, 7); octx.stroke();
        octx.strokeStyle = 'rgba(0,0,0,.55)'; octx.beginPath(); octx.arc(p.x, p.y, brushPx() / 2 + octx.lineWidth, 0, 7); octx.stroke();
        octx.restore();
      };
      const rectOf = (a, b) => ({ x: Math.min(a.x, b.x), y: Math.min(a.y, b.y), w: Math.abs(a.x - b.x), h: Math.abs(a.y - b.y) });

      ov.addEventListener('pointerdown', e => {
        if (e.button > 0) return;
        e.preventDefault(); ov.setPointerCapture(e.pointerId);
        pushUndo(); drawing = true; start = last = pt(e);
        if (tool !== 'pen') {
          fx = makeFx(cv, tool, fxAmount());
          if (mode === 'brush') pat = ctx.createPattern(fx, 'no-repeat');
        }
        if (tool === 'pen' || mode === 'brush') { strokeSeg(start, start); drawCursor(start); }
      });
      ov.addEventListener('pointermove', e => {
        const p = pt(e);
        if (!drawing) { drawCursor(p); return; }
        e.preventDefault();
        if (tool !== 'pen' && mode === 'box') {
          const r = rectOf(start, p); clearOv();
          octx.save(); octx.setLineDash([8 * scaleOf(), 6 * scaleOf()]); octx.lineWidth = 2 * scaleOf();
          octx.strokeStyle = '#fff'; octx.strokeRect(r.x, r.y, r.w, r.h);
          octx.strokeStyle = '#e53945'; octx.lineDashOffset = 7 * scaleOf(); octx.strokeRect(r.x, r.y, r.w, r.h); octx.restore();
          return;
        }
        // gunakan titik-titik halus dari event gabungan agar garis tidak patah
        const evs = e.getCoalescedEvents ? e.getCoalescedEvents() : [e];
        (evs.length ? evs : [e]).forEach(ev => { const q = pt(ev); strokeSeg(last, q); last = q; });
        drawCursor(p);
      });
      const finish = e => {
        if (!drawing) return; drawing = false;
        if (tool !== 'pen' && mode === 'box') {
          const r = rectOf(start, pt(e)); clearOv();
          if (r.w < 3 || r.h < 3) { undoS.pop(); upd(); } else {
            ctx.save(); ctx.beginPath(); ctx.rect(r.x, r.y, r.w, r.h); ctx.clip(); ctx.drawImage(fx, 0, 0); ctx.restore();
          }
        }
        fx = null; pat = null; upd();
      };
      ov.addEventListener('pointerup', finish);
      ov.addEventListener('pointercancel', finish);
      ov.addEventListener('pointerleave', () => { if (!drawing) clearOv(); });

      // ---- aksi ----
      const undo = () => { if (!undoS.length) return; redoS.push(snap()); restore(undoS.pop()); upd(); };
      const redo = () => { if (!redoS.length) return; undoS.push(snap()); restore(redoS.pop()); upd(); };
      const reset = () => { if (!dirty && !undoS.length) return; undoS.push(snap()); redoS.length = 0; restore(original); dirty = true; upd(); };
      const close = val => {
        document.removeEventListener('keydown', onKey, true);
        document.body.classList.remove('pe-open'); root.remove(); resolve(val);
      };
      const save = async () => {
        const btn = root.querySelector('[data-pe=save]');
        if (!undoS.length && !dirty) return close(null);   // tidak ada perubahan
        btn.disabled = true; btn.textContent = 'Menyimpan…';
        try {
          const { blob, type } = await encode(cv, file.type, opts.maxBytes);
          if (!blob) throw new Error('enc');
          if (opts.maxBytes && blob.size > opts.maxBytes) { alert('Hasil edit masih terlalu besar. Coba foto yang lebih kecil.'); btn.disabled = false; btn.innerHTML = ic('check') + ' Simpan'; return; }
          const base = (file.name || 'foto').replace(/\.[^.]+$/, '');
          close(new File([blob], base + '.' + (EXT[type] || 'png'), { type, lastModified: Date.now() }));
        } catch (_) { alert('Gagal menyimpan hasil edit.'); btn.disabled = false; btn.innerHTML = ic('check') + ' Simpan'; }
      };
      const cancel = () => { if (undoS.length && !confirm('Buang semua perubahan pada foto ini?')) return; close(null); };
      root.addEventListener('click', e => {
        const a = e.target.closest('[data-pe]'); if (!a) return;
        ({ undo, redo, reset, save, cancel })[a.dataset.pe]();
      });
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cancel(); return; }
        if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key.toLowerCase() === 'z') { e.preventDefault(); undo(); }
        else if ((e.ctrlKey || e.metaKey) && (e.key.toLowerCase() === 'y' || (e.shiftKey && e.key.toLowerCase() === 'z'))) { e.preventDefault(); redo(); }
      }
      document.addEventListener('keydown', onKey, true);

      // ukuran tampilan: muat di area stage dengan menjaga rasio
      const fit = () => {
        const r = stage.getBoundingClientRect(), pad = 16;
        const s = Math.min((r.width - pad) / W, (r.height - pad) / H, 1 * Math.max(1, window.devicePixelRatio || 1));
        const w = Math.max(40, Math.floor(W * s)), h = Math.max(40, Math.floor(H * s));
        wrap.style.width = w + 'px'; wrap.style.height = h + 'px';
      };
      window.addEventListener('resize', fit);
      root._off = () => window.removeEventListener('resize', fit);
      refresh(); upd(); requestAnimationFrame(fit);
      if (file.type === 'image/gif') root.querySelector('#peHint').textContent = 'GIF akan disimpan sebagai gambar diam (PNG). ' + hints[tool];
      // pastikan resize listener dilepas
      new MutationObserver((_, ob) => { if (!document.body.contains(root)) { root._off(); ob.disconnect(); } }).observe(document.body, { childList: true });
    });
  }

  window.ITIHIX_PHOTO_EDITOR = { open };

  /* ---- Halaman Revisi laporan: edit foto yang SUDAH terlampir ----
     Hasil edit dikirim sebagai file tersembunyi replace[indeks] (+ replace_path[indeks] = path lama);
     server mengganti foto lama saat laporan dikirim ulang. */
  function initExisting() {
    const box = document.getElementById('existingMedia'); if (!box) return;
    const form = box.closest('form'); if (!form) return;
    const MB = (+box.dataset.imgMb || 8) * 1048576;
    const state = {}; // indeks -> { input, pathInput, url }
    const setState = (item, txt, edited) => {
      const st = item.querySelector('[data-ex-state]'); if (st) st.textContent = txt;
      item.classList.toggle('edited', !!edited);
      let undo = item.querySelector('[data-ex-undo]');
      if (edited && !undo) {
        undo = document.createElement('button'); undo.type = 'button'; undo.className = 'mz-undo'; undo.setAttribute('data-ex-undo', item.dataset.ex); undo.textContent = 'Batalkan edit';
        item.querySelector('.mz-meta').appendChild(undo);
      } else if (!edited && undo) undo.remove();
    };
    const clearEdit = (item) => { const ix = item.dataset.ex, s = state[ix]; if (!s) return; s.input.remove(); s.pathInput.remove(); URL.revokeObjectURL(s.url); delete state[ix]; };
    const removed = {};   // indeks -> input hidden remove[indeks]
    box.addEventListener('click', async e => {
      // Klik gambar/video = pratinjau (lightbox) di halaman ini, bukan membuka file di tab baru
      const thumb = e.target.closest('.mz-thumb');
      if (thumb && window.ITIHIX && window.ITIHIX.openLightbox && !e.ctrlKey && !e.metaKey && !e.shiftKey && !e.button) {
        e.preventDefault();
        const items = Array.from(box.querySelectorAll('.mz-item')), cur = thumb.closest('.mz-item');
        window.ITIHIX.openLightbox(
          items.map(it => it.querySelector('.mz-thumb').getAttribute('href')),
          items.map(it => { const n = it.querySelector('.mz-name'), st = it.classList.contains('edited') ? ' (diedit)' : ''; return (n ? n.textContent : 'Media') + st; }),
          Math.max(0, items.indexOf(cur)),
          items.map(it => it.querySelector('video') ? 'video' : 'image'));
        return;
      }
      const rmBtn = e.target.closest('[data-ex-remove]');
      if (rmBtn) {
        e.preventDefault();
        const item = rmBtn.closest('.mz-item'), ix = item.dataset.ex;
        if (removed[ix]) { // batalkan penarikan
          removed[ix].remove(); delete removed[ix]; item.classList.remove('removed');
          rmBtn.setAttribute('title', 'Tarik dari laporan'); setState(item, state[ix] ? 'Diedit · terkirim saat kirim ulang' : 'Terlampir', !!state[ix]);
        } else {
          clearEdit(item);   // tarik berarti edit sebelumnya tidak perlu
          const img = item.querySelector('img'); if (img && img.dataset.orig) { img.src = img.dataset.orig; item.querySelector('.mz-thumb').href = img.dataset.orig; }
          const inp = document.createElement('input'); inp.type = 'hidden'; inp.name = 'remove[' + ix + ']'; inp.value = item.dataset.path; form.appendChild(inp); removed[ix] = inp;
          item.classList.add('removed'); setState(item, 'Ditarik · dihapus saat kirim ulang', false);
          rmBtn.setAttribute('title', 'Batalkan penarikan');
        }
        return;
      }
      const undo = e.target.closest('[data-ex-undo]');
      if (undo) {
        const item = undo.closest('.mz-item'), ix = item.dataset.ex, s = state[ix];
        if (s) { s.input.remove(); s.pathInput.remove(); URL.revokeObjectURL(s.url); delete state[ix]; }
        const img = item.querySelector('img'); if (img && img.dataset.orig) { img.src = img.dataset.orig; item.querySelector('.mz-thumb').href = img.dataset.orig; }
        setState(item, 'Terlampir', false); return;
      }
      const b = e.target.closest('[data-ex-edit]'); if (!b) return;
      e.preventDefault();
      const item = b.closest('.mz-item'), ix = item.dataset.ex, img = item.querySelector('img'); if (!img || removed[ix]) return;
      b.disabled = true;
      try {
        const src = state[ix] ? state[ix].url : img.dataset.orig;       // lanjut mengedit hasil edit sebelumnya
        const r = await fetch(src, { credentials: 'same-origin' });
        if (!r.ok) throw new Error('http');
        const blob = await r.blob();
        const ext = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'image/gif': 'gif' }[blob.type] || 'jpg';
        const file = new File([blob], 'foto-' + (+ix + 1) + '.' + ext, { type: blob.type || 'image/jpeg' });
        const nf = await open(file, { maxBytes: MB });
        if (!nf) return;
        const url = URL.createObjectURL(nf);
        let s = state[ix];
        if (!s) {
          const input = document.createElement('input'); input.type = 'file'; input.name = 'replace[' + ix + ']'; input.hidden = true;
          const pathInput = document.createElement('input'); pathInput.type = 'hidden'; pathInput.name = 'replace_path[' + ix + ']'; pathInput.value = item.dataset.path;
          form.appendChild(input); form.appendChild(pathInput); s = state[ix] = { input, pathInput, url: null };
        } else URL.revokeObjectURL(s.url);
        const dt = new DataTransfer(); dt.items.add(nf); s.input.files = dt.files; s.url = url;
        img.src = url; item.querySelector('.mz-thumb').href = url;
        setState(item, 'Diedit · terkirim saat kirim ulang', true);
      } catch (_) {
        alert('Foto ini tidak bisa dimuat ke editor. Coba muat ulang halaman.');
      } finally { b.disabled = false; }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initExisting); else initExisting();
})();