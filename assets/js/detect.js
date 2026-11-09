/* ITIHIX: ItihScan AI (Deteksi). Dimuat setelah app.js; memakai helper bersama dari window.ITIHIX. */
(function () {
  const { $, $$, body, esc, toast, BASE, openLightbox, api } = window.ITIHIX;
  /* ---- ItihScan AI: form -> card hasil, riwayat di kiri (bisa ditutup) ---- */
  const dPage = $('#page-detect');
  if (dPage) (function () {
    const dForm = $('#detectForm'), formView = $('#detectFormView'), resView = $('#detectResultView');
    const list = $('#detectHistoryList'), btn = $('#detectBtn');
    const picker = $('#detectImages'), drop = $('#dtDrop'), prev = $('#dtPreviews'), attach = $('#dtAttach'), attachCount = $('#dtAttachCount');
    const MAX = +attach.dataset.max || 6, MAX_MB = +attach.dataset.maxMb || 8;
    let items = [], cur = null, picked = [];
    try { items = JSON.parse($('#detectHistoryData').textContent || '[]'); } catch (e) { items = []; }

    const meta = v => v === 'penipuan'
      ? { cls: 'danger', col: '#e53945', title: icn('warn') + ' Terindikasi penipuan', tag: 'tag-red', label: 'Penipuan' }
      : v === 'mencurigakan'
        ? { cls: 'warn', col: '#d99a1f', title: icn('warn') + ' Mencurigakan', tag: 'tag-amber', label: 'Mencurigakan' }
        : { cls: 'ok', col: '#168765', title: icn('check') + ' Tidak ada indikasi kuat', tag: 'tag-green', label: 'Aman' };
    const imgUrl = p => BASE + '/uploads/' + p;
    const imgsOf = h => (h.images && h.images.length) ? h.images : (h.image ? [h.image] : []);
    const setTab = t => { dPage.dataset.tab = t; $$('[data-dt-tab]', dPage).forEach(b => b.classList.toggle('active', b.dataset.dtTab === t)); };
    $$('[data-dt-tab]', dPage).forEach(b => b.addEventListener('click', () => setTab(b.dataset.dtTab)));

    /* ---- Riwayat: tutup / buka (diingat) ---- */
    const KEY = 'dtSideClosed';
    const setSide = closed => { dPage.classList.toggle('side-closed', closed); try { localStorage.setItem(KEY, closed ? '1' : '0'); } catch (e) {} };
    try { if (localStorage.getItem(KEY) === '1') dPage.classList.add('side-closed'); } catch (e) {}
    $('#dtSideClose').addEventListener('click', () => setSide(true));
    $('#dtSideOpen').addEventListener('click', () => setSide(false));
    $('#dtNew').addEventListener('click', () => showForm());

    function renderList() {
      const n = items.length;
      $('#detectCount').textContent = n; $('#detectCountM').textContent = '(' + n + ')'; $('#detectCountOpen').textContent = '(' + n + ')';
      if (!n) { list.innerHTML = '<div class="dt-empty">Belum ada riwayat.<br>Hasil yang kamu simpan akan muncul di sini.</div>'; return; }
      list.innerHTML = items.map(h => {
        const m = meta(h.verdict), im = imgsOf(h);
        const t = h.text ? h.text : (im.length > 1 ? im.length + ' gambar' : im.length ? '(gambar)' : '(kosong)');
        return '<div class="dt-item' + (cur && cur.id === h.id ? ' active' : '') + '" data-id="' + h.id + '">' +
          '<button type="button" class="dt-item-main" data-act="view"><span class="tag ' + m.tag + '">' + esc(m.label) + '</span>' +
          '<strong>' + esc(t) + '</strong><small>' + esc(h.time) + ' · risiko ' + h.score + '/100</small></button>' +
          '<div class="dt-item-act"><button type="button" class="dt-mini" data-act="post" title="Post sebagai laporan">' + icn('plus') + '</button>' +
          '<button type="button" class="dt-mini dt-del" data-act="del" title="Hapus">' + icn('trash') + '</button></div></div>';
      }).join('');
    }

    /* ---- Lampiran gambar: banyak + preview ---- */
    function renderPicked() {
      prev.querySelectorAll('img').forEach(i => URL.revokeObjectURL(i.src));
      const n = picked.length;
      attachCount.textContent = n ? n + ' / ' + MAX + ' gambar' : '';
      attach.classList.toggle('has-files', n > 0);
      prev.classList.toggle('hidden', !n);
      prev.innerHTML = picked.map((f, i) => {
        const u = URL.createObjectURL(f);
        return '<div class="dt-pv" data-i="' + i + '"><a href="' + u + '" data-view="' + i + '" title="Lihat ' + esc(f.label || f.name) + '"><img src="' + u + '" alt="' + esc(f.label || f.name) + '"></a>' +
          '<button type="button" class="dt-pv-x" data-rm="' + i + '" aria-label="Hapus gambar">×</button>' +
          (window.ITIHIX_PHOTO_EDITOR ? '<button type="button" class="dt-pv-ed" data-edit="' + i + '" aria-label="Edit gambar" title="Edit gambar (coretan, blur, mosaik)">' + icn('pen') + ' Edit</button>' : '') +
          '<span class="dt-pv-n">' + esc(f.label || f.name) + '</span></div>';
      }).join('') + (n && n < MAX ? '<label class="dt-pv dt-pv-add" for="detectImages" title="Tambah gambar"><span>' + icn('plus') + '</span><small>Tambah</small></label>' : '');
    }
    /* Kenali gambar dari isi file (bukan nama/ekstensi): nama seperti "WhatsApp Image 2026-10-31 at 17.30.10 (1)"
       sering tanpa ekstensi/tipe yang dikenali browser sehingga ditolak, dan karakter spasi/kurung di nama bisa
       diblokir firewall hosting. Maka tipe dicek dari byte awal, lalu file dikirim dengan nama aman. */
    async function sniffImage(f) {
      try {
        const b = new Uint8Array(await f.slice(0, 16).arrayBuffer());
        const at = (i, s) => [...s].every((c, k) => b[i + k] === c.charCodeAt(0));
        if (b[0] === 0xFF && b[1] === 0xD8 && b[2] === 0xFF) return ['image/jpeg', 'jpg'];
        if (b[0] === 0x89 && at(1, 'PNG')) return ['image/png', 'png'];
        if (at(0, 'GIF8')) return ['image/gif', 'gif'];
        if (at(0, 'RIFF') && at(8, 'WEBP')) return ['image/webp', 'webp'];
      } catch (e) { /* abaikan */ }
      return null;
    }
    let seq = 0;
    async function addFiles(files) {
      let skipped = 0, big = 0;
      for (const f of files) {
        const kind = await sniffImage(f);
        if (!kind && !/^image\//.test(f.type)) { skipped++; continue; }
        if (f.size > MAX_MB * 1048576) { big++; continue; }
        if (picked.some(p => p.label === f.name && p.size === f.size && p.lastModified === f.lastModified)) continue;
        if (picked.length >= MAX) { toast('Maksimal ' + MAX + ' gambar per analisis.'); break; }
        const mime = kind ? kind[0] : f.type, ext = kind ? kind[1] : ((f.name.split('.').pop() || 'jpg').toLowerCase().replace(/[^a-z0-9]/g, '') || 'jpg');
        const safe = new File([f], 'gambar-' + Date.now().toString(36) + '-' + (++seq) + '.' + ext, { type: mime, lastModified: f.lastModified });
        safe.label = f.name;           // nama asli hanya untuk tampilan
        picked.push(safe);
      }
      if (skipped) toast('Hanya file gambar yang bisa dilampirkan.');
      if (big) toast('Gambar melebihi ' + MAX_MB + ' MB dilewati.');
      renderPicked();
    }
    picker.addEventListener('change', () => { addFiles([...picker.files]); picker.value = ''; });
    prev.addEventListener('click', e => {
      const v = e.target.closest('[data-view]');
      if (v) { e.preventDefault(); openLightbox($$('.dt-pv img', prev).map(i => i.src), picked.map(f => f.label || f.name), +v.dataset.view); return; }
      const ed = e.target.closest('[data-edit]');
      if (ed) {
        e.preventDefault(); const i = +ed.dataset.edit, curF = picked[i]; if (!curF) return;
        window.ITIHIX_PHOTO_EDITOR.open(curF, { maxBytes: MAX_MB * 1048576 }).then(nf => {
          if (!nf || picked[i] !== curF) return;
          const ext = ({ 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp' })[nf.type] || 'jpg';
          const safe = new File([nf], 'gambar-' + Date.now().toString(36) + '-' + (++seq) + '.' + ext, { type: nf.type || 'image/jpeg' });
          safe.label = (curF.label || curF.name).replace(/ \(diedit\)$/, '') + ' (diedit)';
          picked[i] = safe; renderPicked();
          toast('Gambar diperbarui. Versi yang diedit inilah yang diperiksa dan dipakai di laporan.');
        });
        return;
      }
      const x = e.target.closest('[data-rm]'); if (!x) return; picked.splice(+x.dataset.rm, 1); renderPicked();
    });
    ['dragenter', 'dragover'].forEach(ev => attach.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('drag'); }));
    ['dragleave', 'drop'].forEach(ev => attach.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('drag'); }));
    attach.addEventListener('drop', e => addFiles([...(e.dataTransfer ? e.dataTransfer.files : [])]));
    dForm.addEventListener('paste', e => { // tempel screenshot langsung dari clipboard
      const fs = [...(e.clipboardData ? e.clipboardData.files : [])];
      if (fs.length) { e.preventDefault(); addFiles(fs); }
    });

    function showForm() {
      cur = null; renderList();
      resView.classList.add('hidden'); formView.classList.remove('hidden');
      dForm.reset(); picked = []; renderPicked();
      setTab('work'); const t = $('#detectText'); if (t) t.focus({ preventScroll: true });
    }

    async function doPost(d) {
      const cap = $('#aiCaption') && cur && cur.id === d.id ? $('#aiCaption').value : d.caption;
      const rr = await api('detect_caption.php', { detection_id: d.id, caption: cap, save: 1 });
      if (rr.ok) location.href = BASE + '/upload.php?detection=' + d.id;
    }

    async function doDelete(d) {
      if (!confirm('Hapus analisis ini dari riwayat?')) return;
      const rr = await api('detect_delete.php', { id: d.id });
      if (!rr.ok) return;
      items = items.filter(x => x.id !== d.id);
      toast('Riwayat dihapus.');
      if (cur && cur.id === d.id) showForm(); else renderList();
    }

    function showResult(d) {
      cur = d; const m = meta(d.verdict), im = imgsOf(d);
      const src = (d.text ? '<div class="dt-src"><b>Teks diperiksa</b><p class="dt-src-text">' + esc(d.text) + '</p></div>' : '') +
        (im.length ? '<div class="dt-src"><b>Gambar diperiksa <span class="dt-badge">' + im.length + '</span></b><div class="dt-gallery">' +
          im.map((p, i) => '<a class="dt-thumb" href="' + esc(imgUrl(p)) + '" data-view="' + i + '" title="Lihat gambar ' + (i + 1) + '"><img src="' + esc(imgUrl(p)) + '" alt="Gambar ' + (i + 1) + ' yang diperiksa" loading="lazy"></a>').join('') + '</div></div>' : '');
      resView.innerHTML =
        '<div class="dt-verdict ' + m.cls + '"><div class="dt-verdict-top"><h3>' + m.title + '</h3><span class="dt-score">' + d.score + '<small>/100</small></span></div>' +
        '<div class="dt-meter"><span style="width:' + d.score + '%;background:' + m.col + '"></span></div></div>' +
        '<div class="dt-body">' +
          (src ? '<div class="dt-srcs">' + src + '</div>' : '') +
          '<div class="dt-cols">' +
            '<section class="dt-col dt-find"><h4>Temuan</h4><ul>' + (d.reasons || []).map(x => '<li>' + esc(x) + '</li>').join('') + '</ul></section>' +
            '<section class="dt-col dt-safe"><h4>Langkah aman</h4><ul>' + (d.advice || []).map(x => '<li>' + esc(x) + '</li>').join('') + '</ul></section>' +
          '</div>' +
          ((d.after || []).length ? '<section class="dt-after"><h4>' + icn('lifering') + ' Jika sudah terlanjur mengikuti pesan ini</h4><p class="dt-after-sub">Lakukan berurutan, mulai dari yang paling mendesak.</p><ol>' + d.after.map(x => '<li>' + esc(x).replace(/\b(iasc\.ojk\.go\.id|patrolisiber\.id)\b/g, '<a href="https://$1" target="_blank" rel="noopener">$1</a>') + '</li>').join('') + '</ol></section>' : '') +
          '<section class="dt-cap"><div class="dt-cap-head"><label for="aiCaption">Saran caption untuk dipost</label><span class="dt-ai-badge" title="Caption ini dibuat otomatis oleh AI">' + icn('sparkles') + ' Caption AI</span><span class="sub">Bisa diedit sebelum dipost</span></div>' +
          '<textarea class="textarea" id="aiCaption" rows="5"></textarea></section>' +
        '</div>' +
        '<div class="dt-foot dt-actions"><button type="button" class="btn btn-primary" id="dtPost">' + icn('plus') + ' Post</button>' +
        '<button type="button" class="btn btn-light dt-danger" id="dtDel">' + icn('trash') + ' Hapus</button>' +
        '<button type="button" class="btn btn-light" id="dtAgain">' + icn('redo') + ' Deteksi lagi</button>' +
        '<span class="sub dt-note">' + icn('check') + ' Tersimpan otomatis di riwayat · Mesin: ' + (d.engine === 'rules' ? 'aturan lokal' : 'AI') + ' · indikasi awal, bukan kepastian hukum.</span></div>';
      $$('.dt-thumb', resView).forEach(a => a.addEventListener('click', e => { e.preventDefault(); openLightbox(im.map(imgUrl), im.map((_, k) => 'Gambar ' + (k + 1)), +a.dataset.view); }));
      $('#aiCaption').value = d.caption || '';
      $('#aiCaption').addEventListener('change', async e => {   // caption diedit: simpan otomatis ke riwayat
        const v = e.target.value;
        if (v === d.caption) return;
        const rr = await api('detect_caption.php', { detection_id: d.id, caption: v }, false, true);
        if (rr.ok) d.caption = v;
      });
      formView.classList.add('hidden'); resView.classList.remove('hidden');
      $('#dtPost').addEventListener('click', () => doPost(d));
      $('#dtAgain').addEventListener('click', showForm);
      $('#dtDel').addEventListener('click', () => doDelete(d));
      setTab('work'); renderList();
    }

    list.addEventListener('click', e => {
      const b = e.target.closest('[data-act]'); if (!b) return;
      const row = b.closest('.dt-item'), d = items.find(x => x.id === +row.dataset.id); if (!d) return;
      if (b.dataset.act === 'view') showResult(d);
      else if (b.dataset.act === 'post') doPost(d);
      else if (b.dataset.act === 'del') doDelete(d);
    });

    dForm.addEventListener('submit', async e => {
      e.preventDefault();
      const text = $('#detectText').value.trim();
      if (!text && !picked.length) { toast('Isi teks atau unggah gambar dulu.'); return; }
      const fd = new FormData(); fd.append('text', text);
      picked.forEach(f => fd.append('images[]', f));
      const unav = $('#detectUnavailable'); if (unav) unav.classList.add('hidden');
      btn.disabled = true; btn.textContent = 'Mendeteksi…';
      const r = await api('detect.php', fd, true);
      btn.disabled = false; btn.innerHTML = '<img class="ico-img" src="' + (document.body.dataset.base || '').replace(/\/$/, '') + '/assets/img/iTIHSCAN.png" alt=""> ItihScan AI';
      if (!r.ok) {
        // AI mati / gagal: tampilkan pesan yang tetap terlihat (bukan hanya toast), isian pengguna dibiarkan utuh
        if (r.unavailable && unav) {
          const dt = $('#detectUnavailableDetail'); if (dt) dt.textContent = r.detail ? 'Detail (admin): ' + r.detail : '';
          unav.classList.remove('hidden'); unav.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
        return;
      }
      const nd = { id: r.id, text: r.text || '', image: r.image || null, images: r.images || [], score: r.score, verdict: r.verdict, reasons: r.reasons || [], advice: r.advice || [], after: r.after || [], caption: r.caption || '', engine: r.engine, time: 'baru saja', saved: true };
      items = items.filter(x => x.id !== nd.id); items.unshift(nd);   // otomatis masuk riwayat
      showResult(nd);
    });

    renderList(); renderPicked();
  })();
})();
