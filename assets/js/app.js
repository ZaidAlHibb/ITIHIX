/* ITIHIX front-end: interaksi tanpa framework. */
(function () {
  const body = document.body;
  const BASE = body.dataset.base || '';
  const CSRF = body.dataset.csrf || '';
  const GUEST = body.dataset.guest === '1';
  const $ = (s, c) => (c || document).querySelector(s);
  const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* Cegah unduh video lewat menu klik kanan / tombol bawaan browser (tidak mencegah ekstensi downloader seperti IDM) */
  document.addEventListener('contextmenu', e => { if (e.target.closest && e.target.closest('video, .home-reel, .reel, .itx-vwrap')) e.preventDefault(); });
  new MutationObserver(ms => ms.forEach(m => m.addedNodes.forEach(n => { if (n.nodeType === 1) (n.matches('video') ? [n] : Array.from(n.querySelectorAll('video'))).forEach(v => { v.setAttribute('controlsList', 'nodownload noremoteplayback'); v.disablePictureInPicture = true; }); }))).observe(document.documentElement, { childList: true, subtree: true });
  document.querySelectorAll('video').forEach(v => { v.setAttribute('controlsList', 'nodownload noremoteplayback'); v.disablePictureInPicture = true; });

  /* ---- Toast ---- */
  let tt;
  function toast(msg) {
    const t = $('#toast'); if (!t) return;
    t.textContent = msg; t.classList.add('show');
    clearTimeout(tt); tt = setTimeout(() => t.classList.remove('show'), 2600);
  }

  /* ---- API helper ---- */
  async function api(path, data, isForm, quiet) {
    const opt = { method: data ? 'POST' : 'GET', headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'fetch' } };
    if (data) {
      if (isForm) { opt.body = data; }
      else { opt.body = new URLSearchParams(data); }
    }
    try {
      const r = await fetch(BASE + '/api/' + path, opt);
      if (r.status === 401) { openGate(); return { ok: false }; }
      const j = await r.json();
      if (!j.ok && j.error && !quiet) toast(j.error);
      return j;
    } catch (e) { if (!quiet) toast('Koneksi bermasalah, coba lagi.'); return { ok: false }; }
  }
  /* ---- Gerbang masuk untuk tamu ---- */
  function openGate(msg) {
    const g = $('#loginGate');
    if (!g) { toast('Silakan masuk terlebih dahulu.'); return; }
    if (msg) { const m = $('#gateMsg'); if (m) m.textContent = msg; }
    g.classList.remove('hidden');
  }
  function closeGate() { const g = $('#loginGate'); if (g) g.classList.add('hidden'); }
  document.addEventListener('click', ev => {
    if (ev.target.closest('[data-gate-close]') || ev.target.id === 'loginGate') closeGate();
  });
  document.addEventListener('keydown', ev => { if (ev.key === 'Escape') closeGate(); });
  const GATE_MSG = {
    like: 'Masuk untuk menyukai laporan.', repost: 'Masuk untuk membagikan ulang laporan.', save: 'Masuk untuk menyimpan laporan.',
    'report-user': 'Masuk untuk melaporkan pengguna.', comments: 'Masuk untuk membaca dan menulis komentar.', follow: 'Masuk untuk mengikuti pengguna.', 'reel-follow': 'Masuk untuk mengikuti pengguna.', delete: 'Masuk untuk mengelola postingan.'
  };

  window.ITIHIX = { toast, api, esc, openGate, $, $$, body, BASE, CSRF, GUEST, openLightbox };   // dipakai skrip halaman (detect.js, quiz.js)
  { const ft = $('#toast'); if (ft && ft.dataset.flash) setTimeout(() => toast(ft.dataset.flash), 200); }   // pesan flash dari server -> notif sementara

  /* ---- Ingat posisi scroll menu samping saat pindah halaman ---- */
  (function () {
    const sb = $('.sidebar'); if (!sb) return;
    const K = 'itihix-nav-scroll';
    try { const v = parseInt(sessionStorage.getItem(K) || '0', 10); if (v) sb.scrollTop = v; } catch (e) {}
    const save = () => { try { sessionStorage.setItem(K, String(sb.scrollTop)); } catch (e) {} };
    sb.addEventListener('scroll', save, { passive: true });
    window.addEventListener('pagehide', save);
  })();

  /* ---- Menu titik tiga (⋯) pada postingan ---- */
  document.addEventListener('click', ev => {
    const t = ev.target.closest('[data-menu-toggle]');
    const own = t ? t.closest('.post-menu') : null;
    $$('.post-menu.open').forEach(m => { if (m !== own) { m.classList.remove('open'); const b = m.querySelector('[data-menu-toggle]'); if (b) b.setAttribute('aria-expanded', 'false'); } });
    if (own) { const o = own.classList.toggle('open'); t.setAttribute('aria-expanded', o ? 'true' : 'false'); }
  });
  document.addEventListener('keydown', ev => { if (ev.key === 'Escape') $$('.post-menu.open').forEach(m => m.classList.remove('open')); });

  /* ---- Laporkan pengguna ---- */
  const repModal = $('#reportModal'), repForm = $('#reportForm');
  function openReport(d) {
    if (!repModal) return;
    repForm.reset();
    repForm.elements.user_id.value = d.id || '';
    repForm.elements.post_id.value = d.post || '';
    if (repForm.elements.comment_id) repForm.elements.comment_id.value = d.comment || '';
    const n = $('#reportName'); if (n) n.textContent = d.name || '';
    const k = $('#reportKind'); if (k) k.textContent = d.comment ? 'Laporkan komentar' : 'Laporkan';
    repModal.classList.remove('hidden');
  }
  function closeReport() { if (repModal) repModal.classList.add('hidden'); }
  if (repModal && repForm) {
    repModal.addEventListener('click', ev => { if (ev.target === repModal || ev.target.closest('[data-report-close]')) closeReport(); });
    document.addEventListener('keydown', ev => { if (ev.key === 'Escape') closeReport(); });
    repForm.addEventListener('submit', async ev => {
      ev.preventDefault();
      const reason = repForm.elements.reason.value;
      if (!reason) { toast('Pilih alasan laporan.'); return; }
      const btn = repForm.querySelector('[type=submit]'); btn.disabled = true;
      const r = await api('report_user.php', { user_id: repForm.elements.user_id.value, post_id: repForm.elements.post_id.value, comment_id: repForm.elements.comment_id ? repForm.elements.comment_id.value : '', reason, detail: repForm.elements.detail.value });
      btn.disabled = false;
      if (r.ok) { closeReport(); toast(r.message || 'Laporan terkirim.'); }
    });
  }

  /* ---- Tema ---- */
  const tb = $('#themeToggle');
  function paintTheme() {
    if (!tb) return;
    const dark = body.classList.contains('dark');
    tb.innerHTML = dark ? icn('sun') : icn('moon');
    tb.title = dark ? 'Aktifkan mode terang' : 'Aktifkan mode gelap';
    tb.setAttribute('aria-label', tb.title);
  }
  paintTheme();
  if (tb) tb.addEventListener('click', () => {
    const on = body.classList.toggle('dark');
     document.documentElement.classList.toggle('pre-dark', on); 
    try { localStorage.setItem('itihix-theme', on ? 'dark' : 'light'); } catch (e) {}
    paintTheme();
  });

  /* ---- Kartu perlindungan (mobile): bottom-sheet dengan backdrop ---- */
  let protBackdrop = null;
  window.ITIHIX.toggleProtection = function (force) {
    const card = $('#mobileProtection'), tools = $('#protectionTools');
    if (!card) { location.href = BASE + '/detect.php'; return; }
    const open = typeof force === 'boolean' ? force : !card.classList.contains('is-open');
    card.classList.toggle('is-open', open);
    if (tools) tools.classList.toggle('hidden', !open);
    const tg = $('.protection-toggle'); if (tg) tg.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.querySelectorAll('[data-mobile="protection"]').forEach(b => {
      b.setAttribute('aria-expanded', open ? 'true' : 'false');
      b.classList.toggle('active', open);
    });
    if (!protBackdrop) {
      protBackdrop = document.createElement('div');
      protBackdrop.className = 'protection-backdrop';
      protBackdrop.addEventListener('click', () => window.ITIHIX.toggleProtection(false));
      document.body.appendChild(protBackdrop);
    }
    protBackdrop.classList.toggle('show', open);
  };
  const pt = $('.protection-toggle'); if (pt) pt.addEventListener('click', () => window.ITIHIX.toggleProtection());
  const protCard = $('#mobileProtection');
  if (protCard) {
    // Tutup saat salah satu alat dipilih, tombol Esc, atau layar melebar ke desktop
    protCard.addEventListener('click', e => { if (e.target.closest('.protection-tool')) window.ITIHIX.toggleProtection(false); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && protCard.classList.contains('is-open')) window.ITIHIX.toggleProtection(false); });
    // Tombol Kembali (bfcache): pastikan panel tidak tertinggal dalam keadaan terbuka
    window.addEventListener('pageshow', e => { if (e.persisted && protCard.classList.contains('is-open')) window.ITIHIX.toggleProtection(false); });
    window.addEventListener('resize', () => { if (window.innerWidth > 760 && protCard.classList.contains('is-open')) window.ITIHIX.toggleProtection(false); });
  }

  /* ---- Aksi postingan (event delegation) ---- */
  document.addEventListener('click', async ev => {
    const btn = ev.target.closest('[data-act]');
    if (!btn) return;
    const act = btn.dataset.act, id = btn.dataset.id;
    if (GUEST && GATE_MSG[act]) { openGate(GATE_MSG[act]); return; }

    if (['like', 'repost', 'save'].includes(act)) {
      const r = await api('react.php', { act, post_id: id });
      if (r.ok) {
        btn.classList.toggle('liked', r.active);
        const sp = btn.querySelector(".cnt"); if (sp && r.count !== undefined) sp.textContent = r.count;
        if (act === 'repost') toast(r.active ? 'Dibagikan ulang ke profilmu' : 'Repost dibatalkan');
        if (act === 'save') toast(r.active ? 'Disimpan' : 'Dihapus dari simpanan');
      }
    } else if (act === 'share') {
      const url = new URL(btn.dataset.url, location.origin).href;
      try { if (navigator.share) { await navigator.share({ title: btn.dataset.title, url }); } else { await navigator.clipboard.writeText(url); toast('Tautan disalin'); } }
      catch (e) { try { await navigator.clipboard.writeText(url); toast('Tautan disalin'); } catch (_) { toast(url); } }
      if (GUEST) return; // tamu cukup menyalin tautan
      const r = await api('react.php', { act: 'share', post_id: id });
      const sp = btn.querySelector('.cnt, .sub'); if (r.ok && sp) sp.textContent = r.count;
    } else if (act === 'comments') {
      const box = $('#comments-' + id);
      box.classList.toggle('hidden');
      if (!box.classList.contains('hidden') && box.dataset.loaded === '0') loadComments(id, box);
    } else if (act === 'delete') {
      if (!confirm('Hapus postingan ini?')) return;
      const r = await api('post_delete.php', { post_id: id });
      if (r.ok) { const el = $('#post-' + id) || $('.reel-card[data-id="' + id + '"]'); if (el) el.remove(); toast('Postingan dihapus'); if (btn.dataset.redirect) location.href = btn.dataset.redirect; }
    } else if (act === 'follow') {
      const r = await api('follow.php', { user_id: id });
      if (r.ok) { btn.innerHTML = r.active ? icn('check') + ' Mengikuti' : icn('plus') + ' Ikuti'; btn.classList.toggle('btn-light', r.active); btn.classList.toggle('btn-primary', !r.active); const inList = !!btn.closest('.fl-list'); const c = $('#followerCount'); if (c && !inList) c.textContent = r.count; const dmb = inList ? null : $('#dmBtn'); if (dmb && r.dm_locked !== undefined) { const was = dmb.dataset.dmLocked; dmb.dataset.dmLocked = r.dm_locked; if (was && !r.dm_locked) toast('Kalian saling mengikuti — sekarang bisa berkirim pesan.'); } }
    } else if (act === 'share-profile') {
      const link = new URL(btn.dataset.url, location.origin).href;
      const m = btn.closest('.post-menu'); if (m) m.classList.remove('open');
      try { if (navigator.share) { await navigator.share({ title: btn.dataset.title, url: link }); } else { await navigator.clipboard.writeText(link); toast('Tautan akun disalin'); } }
      catch (e) { if (e && e.name === 'AbortError') return; try { await navigator.clipboard.writeText(link); toast('Tautan akun disalin'); } catch (_) { toast(link); } }
    } else if (act === 'copy-link') {
      const link = new URL(btn.dataset.url, location.origin).href;
      try { await navigator.clipboard.writeText(link); toast(btn.closest('.pf-menu') ? 'Tautan akun disalin' : 'Tautan disalin'); } catch (e) { toast(link); }
    } else if (act === 'report-user') {
      openReport({ id: btn.dataset.id, name: btn.dataset.name, post: btn.dataset.post });
    } else if (act === 'reel-follow') {
      const r = await api('follow.php', { user_id: id });
      if (r.ok) { btn.classList.toggle('following', r.active); btn.innerHTML = r.active ? icn('check') + ' Mengikuti' : icn('plus') + ' Ikuti'; }
    }
  });

  /* Dropdown komentar di feed/explore: tampilkan maks. 5 komentar utama; sisanya lewat tautan ke halaman postingan. Halaman detail (data-autoload) menampilkan semua. */
  const COMMENT_PREVIEW = 5;
  /* Tautan #cm-ID (mis. dari laporan di panel moderator): buka balasan yang terlipat, gulir, dan sorot komentarnya */
  function focusCommentFromHash(box) {
    const m = /^#cm-(\d+)$/.exec(location.hash); if (!m) return;
    const el = box.querySelector('#cm-' + m[1]); if (!el) return;
    let rep = el.parentNode.closest('.comment-replies');
    while (rep) { setReplies(rep, true); rep = rep.parentNode.closest('.comment-replies'); }
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    el.classList.add('cm-flash'); setTimeout(() => el.classList.remove('cm-flash'), 2600);
  }
  async function loadComments(id, box) {
    box.innerHTML = '<div class="sub">Memuat komentar…</div>';
    const preview = box.dataset.autoload !== '1';
    const r = await api('comments.php?post_id=' + id + (preview ? '&limit=' + COMMENT_PREVIEW : ''));
    if (!r.ok) { box.innerHTML = ''; return; }
    box.dataset.loaded = '1';
    const all = preview && r.more ? '<a class="comment-all" href="' + BASE + '/post.php?id=' + id + '">Lihat semua ' + (r.total || '') + ' komentar</a>' : '';
    box.innerHTML = '<div class="comment-list">' + r.html + '</div>' + all +
      '<form class="comment-form" data-id="' + id + '"><input class="field" maxlength="1000" placeholder="Tulis komentar…" required><button class="btn btn-primary">Kirim</button></form>';
    focusCommentFromHash(box);
  }
  /* Balas komentar: tombol "Balas" mengisi formulir (parent id + chip @pengguna); balasan bersarang: muncul tepat di bawah komentar yang dibalas */
  function setReply(form, parentId, user) {
    if (!form) return;
    const inp = form.querySelector('input:not([type=hidden])'); let chip = form.querySelector('.reply-chip');
    form.dataset.parent = parentId || '';
    if (!parentId) { if (chip) chip.remove(); if (inp) inp.placeholder = 'Tulis komentar…'; return; }
    if (!chip) { chip = document.createElement('span'); chip.className = 'reply-chip'; form.insertBefore(chip, form.firstChild); }
    chip.innerHTML = '<b>Membalas</b><button type="button" class="reply-x" aria-label="Batal membalas">' + icn('xmark') + '</button>';
    if (inp) { inp.placeholder = 'Balas @' + user + '…'; inp.value = '@' + user + ' '; inp.focus(); }
  }
  window.ITIHIX = window.ITIHIX || {}; window.ITIHIX.setReply = setReply;
  /* Bagian balasan: lipat/buka; otomatis terbuka setelah kamu membalas */
  function setReplies(box, open) {
    const t = box.parentNode.querySelector('.comment-toggle[data-target="' + box.id + '"]'); if (!t) return;
    box.classList.toggle('is-collapsed', !open); t.classList.toggle('is-open', open);
    const n = t.dataset.n; t.querySelector('span').textContent = open ? 'Sembunyikan balasan' : 'Lihat ' + n + ' balasan';
  }
  function addedReply(box) {
    /* naikkan hitungan & buka seluruh leluhur, supaya balasan baru langsung terlihat */
    while (box) {
      const t = box.parentNode.querySelector('.comment-toggle[data-target="' + box.id + '"]');
      if (t) { t.dataset.n = (+t.dataset.n || 0) + 1; t.hidden = false; setReplies(box, true); }
      box = box.parentNode.closest('.comment-replies');
    }
  }
  window.ITIHIX.addedReply = addedReply;
  /* Menu komentar (⋯): hapus / hapus paksa / laporkan */
  document.addEventListener('click', async ev => {
    const rep = ev.target.closest('[data-cm-report]');
    if (rep) { rep.closest('.post-menu').classList.remove('open'); openReport({ id: rep.dataset.user, name: rep.dataset.name, post: '', comment: rep.dataset.cmReport }); return; }
    const del = ev.target.closest('[data-cm-delete]'); if (!del || del.disabled) return;
    del.closest('.post-menu').classList.remove('open');
    if (!confirm('Hapus komentar ini? Balasan di bawahnya juga akan ikut terhapus.')) return;
    del.disabled = true;
    const r = await api('comment_delete.php', { comment_id: del.dataset.cmDelete });
    del.disabled = false;
    if (!r.ok) return;
    const el = document.getElementById('cm-' + del.dataset.cmDelete); if (!el) return;
    const removed = el.querySelectorAll('.comment-item').length + 1;
    const list = el.parentNode.closest('.comment-list, #rcList');
    /* kurangi hitungan "Lihat N balasan" di semua komentar induknya */
    let box = el.parentNode.closest('.comment-replies');
    while (box) {
      const t = box.parentNode.querySelector('.comment-toggle[data-target="' + box.id + '"]');
      if (t) { const n = Math.max(0, (+t.dataset.n || 0) - removed); t.dataset.n = n; if (!n) t.hidden = true; else if (box.classList.contains('is-collapsed')) t.querySelector('span').textContent = 'Lihat ' + n + ' balasan'; }
      box = box.parentNode.closest('.comment-replies');
    }
    el.remove();
    if (list && !list.querySelector('.comment-item') && !list.querySelector('.comment-empty')) list.insertAdjacentHTML('beforeend', '<div class="sub comment-empty">Belum ada komentar. Jadilah yang pertama.</div>');
    $$('[data-act="comments"][data-id="' + r.post_id + '"] .cnt, [data-reel-comments="' + r.post_id + '"] .cnt').forEach(c => { c.textContent = r.count; });
    toast('Komentar dihapus.');
  });
  /* Upvote / downvote komentar (ala Reddit): skor = suka - tidak suka */
  document.addEventListener('click', async ev => {
    const vb = ev.target.closest('.cm-vote'); if (!vb || vb.disabled) return;
    const box = vb.closest('.cm-votes'); const btns = box.querySelectorAll('.cm-vote'); btns.forEach(b => b.disabled = true);
    const r = await api('comment_vote.php', { comment_id: vb.dataset.cid, vote: vb.dataset.vote });
    btns.forEach(b => b.disabled = false);
    if (!r.ok) return;
    box.classList.toggle('is-up', r.mine === 1); box.classList.toggle('is-down', r.mine === -1);
    box.querySelector('.cm-up').setAttribute('aria-pressed', r.mine === 1 ? 'true' : 'false');
    box.querySelector('.cm-down').setAttribute('aria-pressed', r.mine === -1 ? 'true' : 'false');
    const sc = box.querySelector('.cm-score'); sc.textContent = r.label; sc.title = r.score;
  });
  document.addEventListener('click', ev => {
    const tg = ev.target.closest('.comment-toggle');
    if (tg) { const box = document.getElementById(tg.dataset.target); if (box) setReplies(box, box.classList.contains('is-collapsed')); return; }
    const x = ev.target.closest('.reply-x'); if (x) { setReply(x.closest('form'), 0); return; }
    const b = ev.target.closest('.comment-reply'); if (!b) return;
    const form = b.closest('#rcList') ? $('#rcForm') : (b.closest('.comments') || document).querySelector('.comment-form');
    setReply(form, b.dataset.reply, b.dataset.user);
  });
  document.addEventListener('submit', async ev => {
    const f = ev.target.closest('.comment-form'); if (!f) return;
    ev.preventDefault();
    const inp = f.querySelector('input'), id = f.dataset.id;
    const r = await api('comments.php', { post_id: id, body: inp.value, parent_id: f.dataset.parent || 0 });
    if (r.ok) {
      inp.value = ''; setReply(f, 0);
      const slot = r.parent_id ? f.parentNode.querySelector('#cr-' + r.parent_id) : null;
      (slot || f.parentNode.querySelector('.comment-list')).insertAdjacentHTML('beforeend', r.html);
      if (slot) addedReply(slot);
      const cnt = $('[data-act="comments"][data-id="' + id + '"] .cnt'); if (cnt) cnt.textContent = r.count;
      const empty = f.parentNode.querySelector('.comment-empty'); if (empty) empty.remove();
    }
  });
  // Klik kartu / gambar postingan di feed -> buka halaman detail
  document.addEventListener('click', ev => {
    if (ev.defaultPrevented || ev.button !== 0) return;
    const card = ev.target.closest('.post[data-href]:not(.post-detail)'); if (!card) return;
    const t = ev.target;
    const inSlideLink = t.closest('.mg-slide a');
    if (!inSlideLink && t.closest('a, button, input, textarea, select, label, video, form, .post-menu, .comments, .mg-nav, .mg-dots, [data-act]')) return;
    if (!inSlideLink && t.closest('.itx-vwrap')) return;
    const sel = window.getSelection && window.getSelection().toString(); if (sel) return;
    ev.preventDefault();
    if (ev.ctrlKey || ev.metaKey) window.open(card.dataset.href, '_blank'); else location.href = card.dataset.href;
  });
  // Tombol kembali ala Twitter: balik ke halaman sebelumnya (feed/profil/jelajahi) jika berasal dari situs ini
  document.addEventListener('click', ev => {
    const b = ev.target.closest('[data-back]'); if (!b) return;
    let same = false; try { same = !!document.referrer && new URL(document.referrer).origin === location.origin; } catch (e) {}
    if (same && history.length > 1) { ev.preventDefault(); history.back(); }
  });

  /* ---- Klik menu ke halaman yang SEDANG dibuka: jangan menumpuk riwayat ----
     Sebelumnya tiap klik Beranda (atau logo) menambah entri riwayat, sehingga tombol Back berputar di halaman yang sama.
     Sekarang: URL sama -> cukup gulir ke atas (di Beranda, feed disegarkan); di Beranda dengan query beda
     (mis. ?tab=terbaru atau &page=2) -> feed dimuat ulang dengan MENGGANTI entri riwayat, bukan menambah. */
  document.addEventListener('click', ev => {
    if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
    const a = ev.target.closest('a.nav-item, .topbar a, .mobile-nav a, .acct-pop a');
    if (!a || a.target === '_blank' || a.hasAttribute('download')) return;
    let u; try { u = new URL(a.href, location.href); } catch (e) { return; }
    const norm = p => p.replace(/\/index(\.php)?$/, '/').replace(/\.php$/, '').replace(/(.)\/$/, '$1');
    if (u.origin !== location.origin || norm(u.pathname) !== norm(location.pathname)) return;
    const refresh = window.ITIHIX && window.ITIHIX.refreshFeed && document.getElementById('feedList') ? window.ITIHIX.refreshFeed : null;
    if (u.search === location.search) {
      ev.preventDefault();
      if (!refresh && window.scrollY < 60 && document.getElementById('page-notifications')) { location.reload(); return; }   // sudah di atas: segarkan daftar
      window.scrollTo({ top: 0, behavior: 'smooth' });
      if (refresh) refresh(u.pathname + u.search);
    } else if (refresh) {
      ev.preventDefault();
      window.scrollTo({ top: 0, behavior: 'smooth' });
      refresh(u.pathname + u.search);
    }
    // halaman lain dengan query beda (mis. profil orang lain -> profil saya): biarkan navigasi biasa
  });
  // Notifikasi -> "Tandai dibaca": form POST biasa menambah entri riwayat. Kirim lewat fetch, lalu muat ulang tanpa menambah entri.
  (function () {
    const f = document.querySelector('#page-notifications form[method="post"]');
    if (!f || !window.fetch) return;
    f.addEventListener('submit', async ev => {
      ev.preventDefault();
      try { await fetch(location.href, { method: 'POST', body: new FormData(f), credentials: 'same-origin', redirect: 'manual' }); } catch (e) { /* tetap muat ulang */ }
      location.replace(location.href);
    });
  })();

  // Auto-buka komentar pada halaman post.php
  const auto = $('.comments[data-autoload="1"]');
  if (auto && !GUEST) { auto.classList.remove('hidden'); loadComments(auto.id.replace('comments-', ''), auto); }

  /* ---- Toggle pengaturan: sinkron ke input tersembunyi ---- */
  $$('.toggle[data-input]').forEach(t => t.addEventListener('click', () => {
    t.classList.toggle('on');
    const inp = document.getElementById(t.dataset.input); if (inp) inp.value = t.classList.contains('on') ? '1' : '0';
  }));

  /* ---- Nama file terpilih ---- */
  $$('input[type=file][data-filename]').forEach(i => i.addEventListener('change', () => {
    const o = document.getElementById(i.dataset.filename);
    if (o) o.innerHTML = i.files && i.files[0] ? icn('check') + ' ' + esc(i.files[0].name) : '';
  }));

  /* ---- Chat AI ---- */
  /* Markdown -> HTML aman (input di-escape dulu). Mendukung: heading, **tebal**, *miring*, ***keduanya***, ~~coret~~, `kode`, blok kode ```, daftar bertingkat (bullet/angka), kutipan, garis, tabel, tautan http(s). */
  const renderMd = (src) => {
    const E = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const inline = (raw) => {
      const codes = [];
      let t = E(raw).replace(/`([^`\n]+?)`/g, (_, c) => { codes.push(c); return '\u0000' + (codes.length - 1) + '\u0000'; });
      t = t.replace(/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>')
           .replace(/(^|[\s(])(https?:\/\/[^\s<)]+[^\s<).,;:!?])/g, '$1<a href="$2" target="_blank" rel="noopener noreferrer">$2</a>')
           .replace(/\*\*\*(?=\S)([\s\S]+?)(?<=\S)\*\*\*/g, '<strong><em>$1</em></strong>')
           .replace(/\*\*(?=\S)([\s\S]+?)(?<=\S)\*\*/g, '<strong>$1</strong>')
           .replace(/(^|[^\w*])__(?=\S)([\s\S]+?)(?<=\S)__(?!\w)/g, '$1<strong>$2</strong>')
           .replace(/\*(?=[^\s*])([^*\n]+?)(?<=[^\s*])\*/g, '<em>$1</em>')
           .replace(/(^|[^\w])_(?=[^\s_])([^_\n]+?)(?<=[^\s_])_(?!\w)/g, '$1<em>$2</em>')
           .replace(/~~(?=\S)([\s\S]+?)(?<=\S)~~/g, '<del>$1</del>');
      t = window.icnGlyphs ? window.icnGlyphs(t) : t;
      return t.replace(/\u0000(\d+)\u0000/g, (_, i) => '<code>' + codes[i] + '</code>');
    };
    const lines = String(src).replace(/\r\n?/g, '\n').split('\n');
    let out = '', i = 0, para = [];
    const flush = () => { if (para.length) { out += '<p>' + para.map(inline).join('<br>') + '</p>'; para = []; } };
    const listRe = /^(\s*)([-*+]|\d+[.)])\s+(.*)$/;
    while (i < lines.length) {
      const ln = lines[i];
      let m;
      if ((m = ln.match(/^\s*```(\w*)\s*$/))) {            // blok kode
        flush(); const buf = []; i++;
        while (i < lines.length && !/^\s*```\s*$/.test(lines[i])) buf.push(lines[i++]);
        i++; out += '<pre><code>' + E(buf.join('\n')) + '</code></pre>'; continue;
      }
      if (/^\s*$/.test(ln)) { flush(); i++; continue; }
      if ((m = ln.match(/^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$/))) { flush(); const n = Math.min(6, m[1].length + 2); out += '<h' + n + '>' + inline(m[2]) + '</h' + n + '>'; i++; continue; }
      if (/^\s{0,3}([-*_])(\s*\1){2,}\s*$/.test(ln)) { flush(); out += '<hr>'; i++; continue; }
      if (/^\s{0,3}>/.test(ln)) {                          // kutipan
        flush(); const buf = [];
        while (i < lines.length && /^\s{0,3}>/.test(lines[i])) buf.push(lines[i++].replace(/^\s{0,3}>\s?/, ''));
        out += '<blockquote>' + renderMd(buf.join('\n')) + '</blockquote>'; continue;
      }
      if (ln.includes('|') && i + 1 < lines.length && /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/.test(lines[i + 1])) { // tabel
        flush();
        const cells = r => r.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map(c => c.trim());
        const head = cells(ln); i += 2; const rows = [];
        while (i < lines.length && lines[i].includes('|') && !/^\s*$/.test(lines[i])) rows.push(cells(lines[i++]));
        out += '<div class="md-table"><table><thead><tr>' + head.map(c => '<th>' + inline(c) + '</th>').join('') + '</tr></thead><tbody>' +
          rows.map(r => '<tr>' + head.map((_, k) => '<td>' + inline(r[k] || '') + '</td>').join('') + '</tr>').join('') + '</tbody></table></div>';
        continue;
      }
      if (listRe.test(ln)) {                               // daftar (bertingkat)
        flush(); const stack = []; // {indent, tag}
        while (i < lines.length && (m = lines[i].match(listRe))) {
          const indent = m[1].replace(/\t/g, '  ').length, tag = /\d/.test(m[2]) ? 'ol' : 'ul';
          while (stack.length && indent < stack[stack.length - 1].indent) { out += '</li></' + stack.pop().tag + '>'; }
          if (stack.length && indent === stack[stack.length - 1].indent) out += '</li>';
          else if (!stack.length || indent > stack[stack.length - 1].indent) { out += '<' + tag + '>'; stack.push({ indent, tag }); }
          out += '<li>' + inline(m[3]); i++;
          while (i < lines.length && /^\s+\S/.test(lines[i]) && !listRe.test(lines[i])) out += '<br>' + inline(lines[i++].trim()); // lanjutan baris
        }
        while (stack.length) out += '</li></' + stack.pop().tag + '>';
        continue;
      }
      para.push(ln.trim()); i++;
    }
    flush();
    return out;
  };

  window.ITIHIX.renderMd = renderMd; // dipakai assets/js/chat.js

  const chatBox = $('#chatMessages');
  if (chatBox) {
    $$('.chat-msg[data-md]', chatBox).forEach(el => { el.innerHTML = renderMd(el.textContent); el.classList.add('md'); }); // riwayat chat
    chatBox.scrollTop = chatBox.scrollHeight;
    const form = $('#chatForm'), input = $('#chatInput');
    const add = (role, text) => {
      const hint = $('#chatHint'); if (hint) hint.remove();
      const row = document.createElement('div'); row.className = 'chat-row ' + (role === 'user' ? 'me' : '');
      row.innerHTML = role === 'user' ? '<div class="chat-msg">' + esc(text) + '</div>' : '<div class="chat-msg md">' + esc(text) + '</div>';
      chatBox.appendChild(row); chatBox.scrollTop = chatBox.scrollHeight; return row;
    };
    async function send(text) {
      if (!text.trim()) return;
      add('user', text); input.value = '';
      const wait = add('assistant', 'Sedang mengetik…');
      const r = await api('chat.php', { message: text });
      const bubble = wait.querySelector('.chat-msg');
      if (r.ok) bubble.innerHTML = renderMd(r.reply); else bubble.textContent = r.error || 'Maaf, terjadi kesalahan.';
      chatBox.scrollTop = chatBox.scrollHeight;
    }
    form.addEventListener('submit', e => { e.preventDefault(); send(input.value); });
    $$('[data-quick]').forEach(b => b.addEventListener('click', () => send(b.dataset.quick)));
  }

  /* ---- Tombol Pesan terkunci (belum saling follow / penerima menonaktifkan pesan) ---- */
  document.addEventListener('click', ev => {
    const lk = ev.target.closest('[data-dm-locked]');
    if (lk && lk.dataset.dmLocked) { ev.preventDefault(); toast(lk.dataset.dmLocked); }
  });

  /* ---- Lightbox bersama (ItihScan AI & form laporan): pop-up gambar/video dengan navigasi + daftar ---- */
  let lb = null, lbSrc = [], lbName = [], lbKind = [], lbI = 0;
  function lbShow(i) {
    lbI = (i + lbSrc.length) % lbSrc.length;
    const img = lb.querySelector('img.dt-lb-img'), vid = lb.querySelector('video.dt-lb-img'), isVid = lbKind[lbI] === 'video';
    vid.pause();
    img.classList.toggle('hidden', isVid); vid.classList.toggle('hidden', !isVid);
    if (isVid) { vid.src = lbSrc[lbI]; } else { vid.removeAttribute('src'); img.src = lbSrc[lbI]; }
    lb.querySelector('.dt-lb-name').textContent = lbName[lbI] || ((isVid ? 'Video ' : 'Gambar ') + (lbI + 1));
    lb.querySelector('.dt-lb-count').textContent = (lbI + 1) + ' / ' + lbSrc.length;
    $$('.dt-lb-strip button', lb).forEach((b, k) => b.classList.toggle('on', k === lbI));
    const one = lbSrc.length < 2;
    $$('.dt-lb-nav', lb).forEach(b => b.classList.toggle('hidden', one));
    lb.querySelector('.dt-lb-strip').classList.toggle('hidden', one);
  }
  function lbClose() { if (!lb) return; const v = lb.querySelector('video'); if (v) v.pause(); lb.remove(); lb = null; document.removeEventListener('keydown', lbKey); }
  function lbKey(e) {
    if (e.key === 'Escape') lbClose();
    else if (e.key === 'ArrowRight') lbShow(lbI + 1);
    else if (e.key === 'ArrowLeft') lbShow(lbI - 1);
  }
  /** srcs = daftar URL, names = nama tiap berkas, i = indeks awal, kinds = 'image' | 'video' per berkas (opsional). */
  function openLightbox(srcs, names, i, kinds) {
    if (!srcs.length) return;
    lbClose(); lbSrc = srcs; lbName = names || []; lbKind = kinds || [];
    lb = document.createElement('div'); lb.className = 'dt-lb'; lb.setAttribute('role', 'dialog'); lb.setAttribute('aria-modal', 'true'); lb.setAttribute('aria-label', 'Pratinjau');
    lb.innerHTML = '<button type="button" class="dt-lb-x" aria-label="Tutup">×</button>' +
      '<div class="dt-lb-main"><button type="button" class="dt-lb-nav" data-d="-1" aria-label="Sebelumnya">‹</button>' +
      '<figure><img class="dt-lb-img" alt=""><video class="dt-lb-img dt-lb-vid hidden" controls playsinline></video><figcaption><span class="dt-lb-name"></span><span class="dt-lb-count"></span></figcaption></figure>' +
      '<button type="button" class="dt-lb-nav" data-d="1" aria-label="Berikutnya">›</button></div>' +
      '<div class="dt-lb-strip">' + srcs.map((u, k) => '<button type="button" data-k="' + k + '" aria-label="Berkas ' + (k + 1) + '">' +
        (lbKind[k] === 'video' ? '<span class="dt-lb-play">' + icn('play') + '</span>' : '<img src="' + esc(u) + '" alt="">') + '</button>').join('') + '</div>';
    lb.addEventListener('click', e => {
      const n = e.target.closest('.dt-lb-nav'), k = e.target.closest('[data-k]');
      if (n) lbShow(lbI + +n.dataset.d);
      else if (k) lbShow(+k.dataset.k);
      else if (e.target.closest('.dt-lb-x') || !e.target.closest('figure, .dt-lb-strip')) lbClose();
    });
    document.body.appendChild(lb); document.addEventListener('keydown', lbKey); lbShow(i || 0);
  }

  /* ---- Scrollbar tema: overlay yang fade-in hanya saat mulai di-scroll, fade-out setelah berhenti ---- */
  /* Gambar lampiran di halaman detail postingan: klik membuka pop-up lightbox, bukan membuka file gambarnya di tab baru (beranda/feed tidak berubah) */
  document.addEventListener('click', e => {
    const a = e.target.closest('#page-post .media-gallery .mg-slide a');
    if (!a || !a.querySelector('img') || e.ctrlKey || e.metaKey || e.shiftKey || e.button) return;
    e.preventDefault();
    const gal = a.closest('.media-gallery'), links = $$('.mg-slide a', gal).filter(x => x.querySelector('img'));
    openLightbox(links.map(x => x.href), links.map((_, k) => 'Gambar ' + (k + 1)), Math.max(0, links.indexOf(a)));
  });
  window.ITIHIX.openLightbox = openLightbox; // dipakai assets/js/photo-editor.js (bukti terlampir di Revisi laporan)

  const SB_V = '.dt-list, .dt-body', SB_N = '.dt-lb-strip, .dt-cap .textarea';
  function sbThumb(el) {
    const host = el.offsetParent; if (!host) return null;
    $$('.dt-sbar', host).forEach(x => { if (!x._owner || !x._owner.isConnected) x.remove(); }); // buang sisa elemen yang sudah diganti
    if (!el._sbar || !el._sbar.isConnected) { const t = document.createElement('div'); t.className = 'dt-sbar'; t._owner = el; host.appendChild(t); el._sbar = t; }
    return el._sbar;
  }
  function sbUpdate(el) {
    const th = sbThumb(el); if (!th) return;
    const ch = el.clientHeight, sh = el.scrollHeight;
    if (sh <= ch + 1) { th.classList.remove('on'); return; }
    const h = Math.max(32, Math.round(ch * ch / sh)), top = el.offsetTop + Math.round((el.scrollTop / (sh - ch)) * (ch - h));
    th.style.height = h + 'px'; th.style.top = (top + 0) + 'px'; th.style.left = (el.offsetLeft + el.offsetWidth - (el.matches('.dt-list') ? 2 : 6)) + 'px';
    th.classList.add('on'); clearTimeout(th._t); th._t = setTimeout(() => th.classList.remove('on'), 800);
  }
  document.addEventListener('scroll', e => {
    const t = e.target; if (!t || !t.matches) return;
    if (t.matches(SB_V)) sbUpdate(t);
    else if (t.matches(SB_N)) { t.classList.add('is-scrolling'); clearTimeout(t._sb); t._sb = setTimeout(() => t.classList.remove('is-scrolling'), 800); }
  }, true);

  /* ---- Unggah SafeSnaps (modal) ---- */
  const modal = $('#shortUploadModal');
  window.ITIHIX.openShortUpload = () => modal && modal.classList.add('open');
  window.ITIHIX.closeShortUpload = () => modal && modal.classList.remove('open');
  if (modal) modal.addEventListener('click', e => { if (e.target === modal) modal.classList.remove('open'); });

  /* ---- SafeSnaps: tab & auto-play ---- */
  const scroller = $('#reelsScroller');
  if (scroller) {
    const vids = $$('video.reel-media', scroller);
    const io = new IntersectionObserver(es => es.forEach(en => {
      const v = en.target;
      if (en.isIntersecting && en.intersectionRatio > .6) { if (!v.dataset.manual) v.play().catch(() => {}); }
      else { v.pause(); if (en.intersectionRatio === 0) { delete v.dataset.manual; v.closest('.reel-card')?.classList.remove('paused'); } }
    }), { threshold: [0, .6, 1], root: scroller });
    vids.forEach(v => io.observe(v));
    /* Klik video = jeda / lanjut; tombol di pojok kanan atas = mute / unmute (berlaku untuk semua video) */
    let reelMuted = true;
    const muteBtns = $$('.reel-mute', scroller);
    const ICO = p => '<svg class="ic" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + p + '</svg>';
    const MUTE_OFF = ICO('<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="22" x2="16" y1="9" y2="15"/><line x1="16" x2="22" y1="9" y2="15"/>');
    const MUTE_ON = ICO('<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/>');
    const applyMute = m => {
      reelMuted = m;
      vids.forEach(v => { v.muted = m; });
      muteBtns.forEach(b => { b.innerHTML = m ? MUTE_OFF : MUTE_ON; b.setAttribute('aria-label', m ? 'Nyalakan suara' : 'Matikan suara'); b.title = m ? 'Nyalakan suara' : 'Matikan suara'; });
    };
    applyMute(true);
    muteBtns.forEach(b => b.addEventListener('click', e => { e.stopPropagation(); applyMute(!reelMuted); }));
    vids.forEach(v => {
      const card = v.closest('.reel-card');
      v.addEventListener('click', () => {
        if (v.paused) { delete v.dataset.manual; v.play().catch(() => {}); }
        else { v.dataset.manual = '1'; v.pause(); }
      });
      v.addEventListener('pause', () => card && card.classList.toggle('paused', !!v.dataset.manual));
      v.addEventListener('play', () => card && card.classList.remove('paused'));
    });
    /* Sesuaikan ukuran kartu dengan rasio asli video (dibatasi agar tidak ekstrem) */
    const fitReel = v => {
      if (!v.videoWidth || !v.videoHeight) return;
      const ar = Math.min(2.4, Math.max(0.4, v.videoWidth / v.videoHeight));
      const card = v.closest('.reel-card'); if (card) card.style.setProperty('--ar', ar.toFixed(4));
    };
    /* Progress bar mengikuti posisi video (penuh saat durasi habis) */
    vids.forEach(v => {
      const bar = v.closest('.reel-card')?.querySelector('.reel-progress span'); if (!bar) return;
      let raf = 0;
      const paint = () => { bar.style.width = (v.duration ? Math.min(100, v.currentTime / v.duration * 100) : 0) + '%'; };
      const tick = () => { paint(); raf = requestAnimationFrame(tick); };
      v.addEventListener('play', () => { cancelAnimationFrame(raf); tick(); });
      ['pause', 'ended'].forEach(ev => v.addEventListener(ev, () => { cancelAnimationFrame(raf); if (ev === 'ended') bar.style.width = '100%'; else paint(); }));
      v.addEventListener('seeked', paint);
    });
    vids.forEach(v => { if (v.readyState >= 1) fitReel(v); v.addEventListener('loadedmetadata', () => fitReel(v)); });
    /* Panel komentar di sisi kanan video: dibuka dari tombol komentar tiap video */
    const page = $('#page-reels'), panel = $('#reelComments'), rcList = $('#rcList'), rcForm = $('#rcForm'), rcTitle = $('#rcTitle');
    let rcId = 0;
    const closeComments = () => { rcId = 0; if (panel) panel.classList.remove('open'); if (page) page.classList.remove('comments-open'); };
    const loadComments = async (id, title) => {
      if (!panel) return;
      rcId = id; window.ITIHIX.setReply(rcForm, 0); rcTitle.textContent = title || ''; rcList.innerHTML = '<div class="sub">Memuat komentar…</div>';
      panel.classList.add('open'); page.classList.add('comments-open');
      const r = await api('comments.php?post_id=' + id);
      if (rcId !== id) return;
      rcList.innerHTML = r.ok ? r.html : '<div class="sub">Komentar tidak dapat dimuat.</div>';
    };
    const openComments = (id, title) => {
      if (rcId === id && panel && panel.classList.contains('open')) { closeComments(); return; }
      return loadComments(id, title);
    };
    scroller.addEventListener('click', e => { const b = e.target.closest('[data-reel-comments]'); if (b) openComments(+b.dataset.reelComments, b.dataset.title); });
    if ($('#rcClose')) $('#rcClose').addEventListener('click', closeComments);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeComments(); });
    if (rcForm) rcForm.addEventListener('submit', async e => {
      e.preventDefault(); const inp = rcForm.querySelector('input'); const body = inp.value.trim(); if (!body || !rcId) return;
      const btn = rcForm.querySelector('button'); btn.disabled = true;
      const r = await api('comments.php', { post_id: rcId, body, parent_id: rcForm.dataset.parent || 0 });
      btn.disabled = false;
      if (r.ok) {
        inp.value = ''; window.ITIHIX.setReply(rcForm, 0); const empty = rcList.querySelector('.comment-empty'); if (empty) empty.remove();
        const slot = r.parent_id ? rcList.querySelector('#cr-' + r.parent_id) : null;
        (slot || rcList).insertAdjacentHTML('beforeend', r.html); if (slot) window.ITIHIX.addedReply(slot); const nw = (slot || rcList).lastElementChild; if (nw && nw.scrollIntoView) nw.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        const cnt = $('[data-reel-comments="' + rcId + '"] .cnt', scroller); if (cnt) cnt.textContent = r.count;
      } else if (r.error) { toast(r.error); }
    });
    /* Pindah ke video lain -> panel komentar tetap terbuka dan memuat komentar video tersebut */
    const ioC = new IntersectionObserver(es => es.forEach(en => {
      const id = +en.target.closest('.reel-card').dataset.id;
      if (en.isIntersecting && en.intersectionRatio > .6 && rcId && id !== rcId) {
        /* Panel tetap terbuka: ganti isinya ke komentar video yang sedang tampil */
        const b = en.target.closest('.reel-card').querySelector('[data-reel-comments]');
        loadComments(id, b ? b.dataset.title : '');
      }
    }), { threshold: [.6], root: scroller });
    vids.forEach(v => ioC.observe(v));
    /* Buka video tertentu dari feed: safesnaps.php?v=ID */
    const want = new URLSearchParams(location.search).get('v');
    if (want) { const c = scroller.querySelector('.reel-card[data-id="' + (+want) + '"]'); if (c) setTimeout(() => c.scrollIntoView({ block: 'center' }), 60); }
    $$('.reels-tab').forEach(t => t.addEventListener('click', () => {
      $$('.reels-tab').forEach(x => x.classList.remove('active')); t.classList.add('active');
      const mode = t.dataset.mode;
      $$('.reel-card', scroller).forEach(c => c.classList.toggle('hidden', mode === 'following' && c.dataset.following !== 'yes'));
      scroller.scrollTop = 0;
    }));
  }

  /* ---- Pemutar video ringan buatan sendiri (tanpa library/CDN). Bila gagal, kontrol bawaan browser tetap dipakai ---- */
  const VP = {
    play: '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>',
    pause: '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>',
    vol: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" fill="currentColor"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/></svg>',
    mute: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" fill="currentColor"/><line x1="22" y1="9" x2="16" y2="15"/><line x1="16" y1="9" x2="22" y2="15"/></svg>',
    fs: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3"/></svg>'
  };
  const vpTime = t => { if (!isFinite(t) || t < 0) t = 0; return Math.floor(t / 60) + ':' + String(Math.floor(t % 60)).padStart(2, '0'); };
  const VP_ERR = { 1: 'Pemuatan dibatalkan.', 2: 'Gangguan jaringan saat memuat video.', 3: 'Video rusak atau formatnya tidak didukung browser.', 4: 'File video tidak ditemukan atau formatnya tidak didukung.' };
  function initPlayer(v) {
    if (v.dataset.vp) return; v.dataset.vp = '1';
    const wrap = v.closest('.itx-vwrap'); if (!wrap) return;
    try {
      v.controls = false; v.removeAttribute('controls');
      const ui = document.createElement('div'); ui.className = 'vp-ui';
      ui.innerHTML = '<button type="button" class="vp-big" aria-label="Putar">' + VP.play + '</button>' +
        '<div class="vp-err" hidden></div>' +
        '<div class="vp-bar"><button type="button" class="vp-btn vp-pp" aria-label="Putar / jeda">' + VP.play + '</button>' +
        '<input class="vp-seek" type="range" min="0" max="1000" step="1" value="0" aria-label="Posisi video">' +
        '<span class="vp-time">0:00 / 0:00</span>' +
        '<button type="button" class="vp-btn vp-mute" aria-label="Suara">' + VP.vol + '</button>' +
        '<button type="button" class="vp-btn vp-speed" aria-label="Kecepatan">1\u00d7</button>' +
        '<button type="button" class="vp-btn vp-fs" aria-label="Layar penuh">' + VP.fs + '</button></div>';
      wrap.appendChild(ui);
      const $u = q => ui.querySelector(q), big = $u('.vp-big'), pp = $u('.vp-pp'), seek = $u('.vp-seek'), tm = $u('.vp-time'), mu = $u('.vp-mute'), sp = $u('.vp-speed'), fs = $u('.vp-fs'), er = $u('.vp-err');
      let seeking = false;
      const toggle = () => { if (v.paused) { const p = v.play(); if (p && p.catch) p.catch(() => {}); } else v.pause(); };
      const paint = () => {
        const d = v.duration; tm.textContent = vpTime(v.currentTime) + ' / ' + vpTime(d);
        if (!seeking) { seek.value = d ? Math.round(v.currentTime / d * 1000) : 0; seek.style.setProperty('--p', (seek.value / 10) + '%'); }
      };
      v.addEventListener('click', toggle); big.addEventListener('click', toggle); pp.addEventListener('click', toggle);
      v.addEventListener('play', () => {
        wrap.classList.add('is-playing'); pp.innerHTML = VP.pause; big.setAttribute('aria-label', 'Jeda');
        document.querySelectorAll('video').forEach(o => { if (o !== v && !o.paused && !o.closest('.reel-card')) o.pause(); });
      });
      const onPause = () => { wrap.classList.remove('is-playing'); pp.innerHTML = VP.play; big.setAttribute('aria-label', 'Putar'); };
      v.addEventListener('pause', onPause); v.addEventListener('ended', onPause);
      v.addEventListener('timeupdate', paint); v.addEventListener('durationchange', paint);
      v.addEventListener('loadedmetadata', () => {
        paint(); er.hidden = true;
        if (v.videoWidth && v.videoHeight) v.style.aspectRatio = Math.min(2, Math.max(0.6, v.videoWidth / v.videoHeight)).toFixed(3);
      });
      v.addEventListener('waiting', () => wrap.classList.add('is-loading'));
      ['playing', 'canplay', 'pause'].forEach(ev => v.addEventListener(ev, () => wrap.classList.remove('is-loading')));
      v.addEventListener('error', () => { const c = v.error ? v.error.code : 0; er.textContent = '\u26a0 ' + (VP_ERR[c] || 'Video tidak dapat diputar.'); er.hidden = false; big.hidden = true; });
      seek.addEventListener('input', () => { seeking = true; seek.style.setProperty('--p', (seek.value / 10) + '%'); if (v.duration) tm.textContent = vpTime(seek.value / 1000 * v.duration) + ' / ' + vpTime(v.duration); });
      seek.addEventListener('change', () => { if (v.duration) v.currentTime = seek.value / 1000 * v.duration; seeking = false; });
      mu.addEventListener('click', () => { v.muted = !v.muted; mu.innerHTML = v.muted ? VP.mute : VP.vol; });
      const rates = [1, 1.5, 2, 0.5];
      sp.addEventListener('click', () => { const n = rates[(rates.indexOf(v.playbackRate) + 1) % rates.length]; v.playbackRate = n; sp.textContent = n + '\u00d7'; });
      fs.addEventListener('click', () => {
        const f = wrap.requestFullscreen || wrap.webkitRequestFullscreen;
        if (document.fullscreenElement || document.webkitFullscreenElement) (document.exitFullscreen || document.webkitExitFullscreen).call(document);
        else if (f) f.call(wrap); else if (v.webkitEnterFullscreen) v.webkitEnterFullscreen();
      });
      ui.addEventListener('click', e => e.stopPropagation());
      paint();
    } catch (e) { v.controls = true; }
  }
  const initPlayers = () => document.querySelectorAll('video[data-itx-video]:not([data-vp])').forEach(initPlayer);
  initPlayers();
  new MutationObserver(initPlayers).observe(document.body, { childList: true, subtree: true });

  /* ---- Galeri media (carousel foto/video di feed) ---- */
  const initGallery = g => {
    if (g.dataset.ready) return; g.dataset.ready = '1';
    const tr = g.querySelector('.mg-track'); if (!tr) return;
    const n = +g.dataset.count || 1; if (n < 2) return;
    const cnt = g.querySelector('[data-mg-count]'), prev = g.querySelector('[data-mg-prev]'), next = g.querySelector('[data-mg-next]'), dots = g.querySelectorAll('.mg-dots i');
    const go = i => tr.scrollTo({ left: Math.max(0, Math.min(n - 1, i)) * tr.clientWidth, behavior: 'smooth' });
    const cur = () => Math.round(tr.scrollLeft / Math.max(1, tr.clientWidth));
    const sync = () => {
      const i = cur();
      if (cnt) cnt.textContent = (i + 1) + '/' + n;
      if (prev) prev.hidden = i <= 0; if (next) next.hidden = i >= n - 1;
      dots.forEach((d, k) => d.classList.toggle('on', k === i));
      tr.querySelectorAll('video').forEach((v, k) => { if (k !== i) v.pause(); });
    };
    tr.addEventListener('scroll', () => { clearTimeout(tr._t); tr._t = setTimeout(sync, 60); }, { passive: true });
    if (prev) prev.addEventListener('click', e => { e.preventDefault(); go(cur() - 1); });
    if (next) next.addEventListener('click', e => { e.preventDefault(); go(cur() + 1); });
    sync();
  };
  $$('[data-gallery]').forEach(initGallery);
  new MutationObserver(() => $$('[data-gallery]').forEach(initGallery)).observe(document.body, { childList: true, subtree: true });

  /* ---- Form laporan: zona unggah (pilih/seret/tempel banyak foto & video) + pratinjau + hapus satuan + pop-up ---- */
  const mediaInput = $('#reportFile'), mediaPrev = $('#mediaPreview');
  if (mediaInput && mediaPrev) {
    const MAX = +mediaInput.dataset.max || 10, IMG_MB = +mediaInput.dataset.imgMb || 8, VID_MB = +mediaInput.dataset.vidMb || 40;
    const box = $('#mediaZone'), zone = $('#mediaDrop'), head = $('#mzHead'), cnt = $('#mzCount'), bar = $('#mzBar'), clr = $('#mzClear');
    const form = mediaInput.closest('form');
    let files = [], urls = [];
    const isVid = f => f.type.indexOf('video/') === 0;
    const fmt = b => b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
    const sync = () => {
      urls.forEach(u => URL.revokeObjectURL(u)); urls = files.map(f => URL.createObjectURL(f));
      const dt = new DataTransfer(); files.forEach(f => dt.items.add(f)); mediaInput.files = dt.files;
      if (box) { box.classList.toggle('has-files', files.length > 0); box.classList.toggle('is-full', files.length >= MAX); }
      if (head) head.classList.toggle('hidden', !files.length);
      if (cnt) cnt.textContent = files.length + ' / ' + MAX + ' file dipilih';
      if (bar) bar.style.width = Math.min(100, files.length / MAX * 100) + '%';
      mediaPrev.innerHTML = files.map((f, i) =>
        '<div class="mz-item" data-i="' + i + '">' +
          '<a class="mz-thumb" href="' + urls[i] + '" data-view="' + i + '" title="Lihat ' + esc(f.name) + '">' +
            (isVid(f) ? '<video muted playsinline preload="metadata" src="' + urls[i] + '#t=0.1"></video><span class="mz-kind">' + icn('play') + ' Video</span>'
                      : '<img src="' + urls[i] + '" alt="' + esc(f.name) + '"><span class="mz-kind">' + icn('image') + ' Foto</span>') +
          '</a>' +
          '<button type="button" class="mz-x" data-rm="' + i + '" aria-label="Hapus file">' + icn('xmark') + '</button>' +
          (isVid(f) || !window.ITIHIX_PHOTO_EDITOR ? '' : '<button type="button" class="mz-ed" data-edit="' + i + '" aria-label="Edit foto" title="Edit foto (coretan, blur, mosaik)">' + icn('pen') + ' Edit</button>') +
          '<div class="mz-meta"><span class="mz-name" title="' + esc(f.name) + '">' + esc(f.name) + '</span><small>' + fmt(f.size) + '</small></div>' +
        '</div>').join('');
    };
    const add = list => {
      let over = false; const big = [];
      Array.from(list).forEach(f => {
        if (!/^(image|video)\//.test(f.type)) return;
        if (files.some(p => p.name === f.name && p.size === f.size && p.lastModified === f.lastModified)) return;
        if (f.size > (isVid(f) ? VID_MB : IMG_MB) * 1048576) { big.push(f.name); return; }
        if (files.length >= MAX) { over = true; return; }
        files.push(f);
      });
      if (big.length) warnToast((big.length === 1 ? '“' + big[0] + '” melebihi' : big.length + ' file melebihi') + ' batas ukuran (foto ' + IMG_MB + ' MB, video ' + VID_MB + ' MB).');
      else if (over) warnToast('Maksimal ' + MAX + ' file per laporan. Sisanya diabaikan.');
      sync();
    };
    mediaInput.addEventListener('change', () => add(mediaInput.files));
    if (zone) zone.addEventListener('click', e => { if (files.length >= MAX) { e.preventDefault(); toast('Sudah mencapai batas ' + MAX + ' file.'); } });
    if (clr) clr.addEventListener('click', () => { files = []; sync(); });
    mediaPrev.addEventListener('click', e => {
      const v = e.target.closest('[data-view]');
      if (v) { e.preventDefault(); openLightbox(urls, files.map(f => f.name), +v.dataset.view, files.map(f => isVid(f) ? 'video' : 'image')); return; }
      const ed = e.target.closest('[data-edit]');
      if (ed) {
        e.preventDefault(); const i = +ed.dataset.edit, cur = files[i]; if (!cur) return;
        window.ITIHIX_PHOTO_EDITOR.open(cur, { maxBytes: IMG_MB * 1048576 }).then(nf => {
          if (!nf || files[i] !== cur) return;
          files[i] = nf; sync(); toast('Foto diperbarui. Perubahan ikut terkirim bersama laporan.');
        });
        return;
      }
      const x = e.target.closest('[data-rm]'); if (x) { files.splice(+x.dataset.rm, 1); sync(); }
    });
    let dragDepth = 0;
    if (box) {
      box.addEventListener('dragenter', e => { e.preventDefault(); dragDepth++; box.classList.add('drag'); });
      box.addEventListener('dragover', e => e.preventDefault());
      box.addEventListener('dragleave', () => { if (--dragDepth <= 0) { dragDepth = 0; box.classList.remove('drag'); } });
      box.addEventListener('drop', e => { e.preventDefault(); dragDepth = 0; box.classList.remove('drag'); add(e.dataTransfer ? e.dataTransfer.files : []); });
    }
    if (form) form.addEventListener('paste', e => { // tempel screenshot dari clipboard
      const fs = Array.from(e.clipboardData ? e.clipboardData.files : []).filter(f => /^image\//.test(f.type));
      if (fs.length) { e.preventDefault(); add(fs); }
    });
  }

  /* ---- Peringatan form (muncul sebentar lalu hilang sendiri; tidak mengganggu isi form) ---- */
  function warnToast(msgs, type) {
    let st = $('#warnStack');
    if (!st) { st = document.createElement('div'); st.id = 'warnStack'; st.className = 'warn-stack'; st.setAttribute('role', 'alert'); document.body.appendChild(st); }
    (Array.isArray(msgs) ? msgs : [msgs]).forEach((m, i) => {
      const w = document.createElement('div'); w.className = 'warn-item' + (type === 'ok' ? ' ok' : '');
      w.innerHTML = '<span class="warn-ic">' + icn(type === 'ok' ? 'check' : 'warn') + '</span><span></span>';
      w.lastChild.textContent = m;
      st.appendChild(w);
      setTimeout(() => w.classList.add('show'), 30 + i * 90);
      setTimeout(() => { w.classList.remove('show'); setTimeout(() => w.remove(), 300); }, 5200 + i * 400);
    });
  }

  /* ---- Kirim form laporan lewat fetch: bila ada yang salah, tampilkan peringatan & JANGAN reload (foto tetap ada) ---- */
  const reportForm = $('#reportBody') && $('#reportBody').closest('form');
  if (reportForm) reportForm.addEventListener('submit', async e => {
    if (!reportForm.checkValidity()) return; // biarkan validasi bawaan browser
    e.preventDefault();
    if (reportForm.dataset.busy) return;
    const btn = e.submitter || reportForm.querySelector('.btn-primary');
    const label = btn ? btn.innerHTML : '';
    reportForm.dataset.busy = '1'; if (btn) { btn.disabled = true; btn.textContent = 'Mengirim…'; }
    const reset = () => { delete reportForm.dataset.busy; if (btn) { btn.disabled = false; btn.innerHTML = label; } };
    try {
      const res = await fetch(reportForm.getAttribute('action') || location.href, {
        method: 'POST', body: new FormData(reportForm), credentials: 'same-origin',
        headers: { 'X-Itihix-Form': '1', 'X-CSRF-Token': CSRF }
      });
      let data = null; try { data = await res.json(); } catch (_) {}
      if (data && data.ok && data.redirect) { window.location.href = data.redirect; return; }
      if (data && data.errors && data.errors.length) warnToast(data.errors);
      else warnToast(res.status === 419 ? 'Sesi berakhir. Muat ulang halaman lalu coba lagi.' : 'Gagal mengirim. Total ukuran file mungkin terlalu besar, coba kurangi lalu kirim lagi.');
    } catch (_) { warnToast('Koneksi bermasalah. Periksa internetmu lalu coba lagi.'); }
    reset();
  });

  /* ---- Pratinjau video SafeSnaps sebelum diunggah ---- */
  const spIn = $('#shortVideoFile'), spBox = $('#shortPreview'), spVid = $('#shortPreviewVideo');
  if (spIn && spBox && spVid) {
    const spName = $('#shortPreviewName'), spDrop = spIn.closest('.short-video-drop');
    let spUrl = null;
    const spReset = () => {
      if (spUrl) URL.revokeObjectURL(spUrl); spUrl = null;
      spVid.pause(); spVid.removeAttribute('src'); spVid.load();
      spBox.hidden = true; if (spDrop) spDrop.hidden = false; spIn.value = '';
    };
    spIn.addEventListener('change', () => {
      const f = spIn.files && spIn.files[0];
      if (!f) return spReset();
      if (spUrl) URL.revokeObjectURL(spUrl);
      spUrl = URL.createObjectURL(f); spVid.src = spUrl;
      const mb = f.size / 1048576;
      spName.textContent = f.name + ' · ' + (mb >= 1 ? mb.toFixed(1) + ' MB' : Math.max(1, Math.round(f.size / 1024)) + ' KB');
      spBox.hidden = false; if (spDrop) spDrop.hidden = true;
    });
    $('#shortPreviewClear').addEventListener('click', spReset);
    const closeOrig = window.ITIHIX.closeShortUpload;
    window.ITIHIX.closeShortUpload = () => { closeOrig(); spReset(); };
  }
})();

/* Tab beranda (Untuk kamu / Terbaru / Mengikuti): ganti isi feed tanpa memuat ulang halaman, jadi posisi scroll tidak lompat ke atas */
(function () {
  const tabs = document.querySelector('.home-tabs');
  const feed = document.getElementById('feedList');
  if (!tabs || !feed || !window.fetch || !history.pushState) return;
  let seq = 0;
  function load(url, push, top) {
    const my = ++seq, y = window.scrollY;
    feed.style.opacity = '.45'; feed.style.transition = 'opacity .12s';
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(r => { if (!r.ok) throw new Error('http'); return r.text(); })
      .then(html => {
        if (my !== seq) return;
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const nf = doc.getElementById('feedList'); if (!nf) throw new Error('nofeed');
        const np = doc.querySelector('#page-home .pager'), op = document.querySelector('#page-home .pager');
        feed.innerHTML = nf.innerHTML;
        if (op && np) op.innerHTML = np.innerHTML;
        const nt = doc.querySelector('.home-tabs');
        if (nt) Array.from(tabs.children).forEach((a, i) => { const b = nt.children[i]; if (b) a.className = b.className; });
        // Tab hanyalah filter, bukan halaman baru: ganti entri riwayat (bukan tambah) supaya tombol Back tidak berputar di tab-tab beranda
        if (push) history.replaceState({ homeTab: 1 }, '', url);
        feed.style.opacity = '';
        window.scrollTo(0, top ? 0 : y); /* tab: tetap di posisi sekarang; tombol Beranda: ke atas */
      })
      .catch(() => { location.href = url; });
  }
  tabs.addEventListener('click', ev => {
    const a = ev.target.closest('a.tab'); if (!a || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button) return;
    ev.preventDefault();
    if (a.classList.contains('active') && !/[?&]page=/.test(location.search)) return;
    load(a.href, true);
  });
  window.addEventListener('popstate', () => { if (document.getElementById('feedList')) load(location.href, false); });
  window.ITIHIX = window.ITIHIX || {};
  window.ITIHIX.refreshFeed = url => load(url || location.href, true, true);   // dipakai tombol Beranda saat sudah di Beranda
})();

/* Tab profil: ganti isi tanpa reload halaman */
(function () {
  const page = document.getElementById('page-profile');
  const tabs = page && page.querySelector('.profile-tabs');
  if (!tabs || !window.fetch || !history.pushState) return;
  let seq = 0;
  const area = () => page.querySelector('.profile-tabs').parentElement;

  function load(url, push) {
    const my = ++seq, y = window.scrollY;
    const body = [...page.children].filter(el => el.compareDocumentPosition(tabs) & Node.DOCUMENT_POSITION_PRECEDING);
    body.forEach(el => { el.style.opacity = '.5'; el.style.transition = 'opacity .12s'; });
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(r => { if (!r.ok) throw 0; return r.text(); })
      .then(html => {
        if (my !== seq) return;
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const np = doc.getElementById('page-profile'); if (!np) throw 0;
        const nt = np.querySelector('.profile-tabs');
        // ganti tab + semua yang ada di bawahnya
        tabs.innerHTML = nt.innerHTML;
        while (tabs.nextSibling) tabs.nextSibling.remove();
        let n = nt.nextSibling;
        while (n) { tabs.parentNode.appendChild(document.importNode(n, true)); n = n.nextSibling; }
        if (push) history.pushState({ pfTab: 1 }, '', url);
        window.scrollTo(0, y);
      })
      .catch(() => { location.href = url; });
  }

  page.addEventListener('click', ev => {
    const a = ev.target.closest('.profile-tabs a.tab, .pf-stat, .fl-pager a, a.tag[href*="tab="]');
    if (!a || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button) return;
    ev.preventDefault();
    load(a.href, true);
  });
  window.addEventListener('popstate', () => { if (document.querySelector('#page-profile .profile-tabs')) load(location.href, false); });
})();
