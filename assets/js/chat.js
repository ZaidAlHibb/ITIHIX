/* ITIHIX Chat AI: riwayat percakapan, sidebar (baru / recent / cari), buka-tutup sidebar. */
(function () {
  const root = document.getElementById('cx');
  if (!root || !window.ITIHIX) return;
  const { api, esc, toast } = window.ITIHIX;
  const md = window.ITIHIX.renderMd || (t => '<p>' + esc(t) + '</p>');
  const $ = id => document.getElementById(id);
  const el = { side: $('cxSide'), list: $('cxList'), search: $('cxSearch'), searchX: $('cxSearchX'), thread: $('cxThread'), scroll: $('cxScroll'),
    form: $('cxForm'), input: $('cxInput'), send: $('cxSend'), count: $('cxCount'), title: $('cxTitle'), down: $('cxDown'), toggle: $('cxToggle'),
    plus: $('cxPlus'), fileIn: $('cxFile'), files: $('cxFiles'), drop: $('cxDrop'), main: root.querySelector('.cx-main') };
  let boot = {};
  try { boot = JSON.parse($('cxBoot').textContent || '{}'); } catch (e) {}

  const mq = window.matchMedia('(max-width: 760px)');
  const LS = 'itihix-chat-side';
  const state = { convs: boot.conversations || [], convId: null, busy: false, view: 0, query: '', files: [], retry: null };
  const QUICK = [
    ['link', 'Cara cek phishing', 'Bagaimana cara mengenali link phishing?'],
    ['warn', 'Terlanjur klik link', 'Apa yang harus dilakukan jika terlanjur klik link penipuan?'],
    ['key', 'Keamanan OTP', 'Apakah aman membagikan kode OTP kepada kurir?'],
    ['briefcase', 'Lowongan palsu', 'Apa saja ciri lowongan kerja palsu?']
  ];

  /* ---------- Sidebar buka/tutup ---------- */
  function setSide(open, persist) {
    root.classList.toggle('is-side-open', open);
    el.toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (persist && !mq.matches) { try { localStorage.setItem(LS, open ? '1' : '0'); } catch (e) {} }
  }
  function initSide() {
    let open = !mq.matches;
    if (!mq.matches) { try { if (localStorage.getItem(LS) === '0') open = false; } catch (e) {} }
    setSide(open, false);
  }
  el.toggle.addEventListener('click', () => setSide(!root.classList.contains('is-side-open'), true));
  $('cxCollapse').addEventListener('click', () => setSide(false, true));
  $('cxBackdrop').addEventListener('click', () => setSide(false, false));
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && mq.matches) setSide(false, false); });
  (mq.addEventListener ? mq.addEventListener.bind(mq, 'change') : mq.addListener.bind(mq))(initSide);
  initSide();

  /* ---------- Daftar percakapan ---------- */
  const GROUPS = ['Hari ini', 'Kemarin', '7 hari terakhir', '30 hari terakhir', 'Lebih lama'];
  function renderList() {
    const l = el.list, keep = l.scrollTop; l.innerHTML = '';   // simpan posisi scroll agar daftar tidak lompat ke atas
    if (!state.convs.length) {
      l.innerHTML = '<div class="cx-empty">' + (state.query ? 'Tidak ada percakapan yang cocok dengan “' + esc(state.query) + '”.' : 'Belum ada percakapan.<br>Mulai dengan mengirim pesan.') + '</div>';
      return;
    }
    const by = {};
    state.convs.forEach(c => (by[c.group] = by[c.group] || []).push(c));
    GROUPS.concat(Object.keys(by).filter(g => !GROUPS.includes(g))).forEach(g => {
      if (!by[g]) return;
      const h = document.createElement('div'); h.className = 'cx-group'; h.textContent = state.query ? 'Hasil pencarian' : g;
      if (!state.query || !l.querySelector('.cx-group')) l.appendChild(h);
      by[g].forEach(c => l.appendChild(itemEl(c)));
    });
    l.scrollTop = keep;
  }
  function itemEl(c) {
    const d = document.createElement('div');
    d.className = 'cx-item' + (c.id === state.convId ? ' active' : ''); d.dataset.id = c.id; d.setAttribute('role', 'listitem');
    d.innerHTML = '<button type="button" class="cx-item-main" title="' + esc(c.title) + '"><span class="cx-item-title">' + esc(c.title) + '</span></button>' +
      '<span class="cx-item-actions"><button type="button" data-act="rename" title="Ubah nama" aria-label="Ubah nama">' + icn('pen') + '</button><button type="button" data-act="delete" title="Hapus" aria-label="Hapus">' + icn('trash') + '</button></span>';
    return d;
  }
  function markActive() { el.list.querySelectorAll('.cx-item').forEach(i => i.classList.toggle('active', Number(i.dataset.id) === state.convId)); }

  async function refreshList(q) {
    state.query = q !== undefined ? q : state.query;
    const r = await api('chat.php', { action: 'list', q: state.query });
    if (r.ok) { state.convs = r.conversations; renderList(); }
  }

  el.list.addEventListener('click', async e => {
    const item = e.target.closest('.cx-item'); if (!item) return;
    const id = Number(item.dataset.id), act = e.target.closest('[data-act]');
    if (!act) { if (!state.busy) openConv(id); else toast('Tunggu balasan selesai dulu.'); return; }
    if (act.dataset.act === 'rename') startRename(item, id);
    if (act.dataset.act === 'delete') {
      const c = state.convs.find(x => x.id === id);
      if (!confirm('Hapus percakapan “' + (c ? c.title : '') + '”? Tindakan ini tidak bisa dibatalkan.')) return;
      const r = await api('chat.php', { action: 'delete', conversation_id: id });
      if (!r.ok) return;
      state.convs = state.convs.filter(x => x.id !== id); renderList();
      if (state.convId === id) newChat(); toast('Percakapan dihapus.');
    }
  });
  function startRename(item, id) {
    const c = state.convs.find(x => x.id === id); if (!c) return;
    const main = item.querySelector('.cx-item-main'), inp = document.createElement('input');
    inp.className = 'cx-rename'; inp.value = c.title; inp.maxLength = 80; inp.setAttribute('aria-label', 'Nama percakapan');
    main.replaceWith(inp); item.classList.add('editing'); inp.focus(); inp.select();
    let done = false;
    const finish = async save => {
      if (done) return; done = true;
      const v = inp.value.trim();
      if (save && v && v !== c.title) {
        const r = await api('chat.php', { action: 'rename', conversation_id: id, title: v });
        if (r.ok) { c.title = r.title; if (state.convId === id) el.title.textContent = c.title; }
      }
      renderList();
    };
    inp.addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); finish(true); } if (ev.key === 'Escape') finish(false); });
    inp.addEventListener('blur', () => finish(true));
  }

  /* ---------- Pencarian ---------- */
  let st;
  el.search.addEventListener('input', () => {
    const q = el.search.value.trim(); el.searchX.classList.toggle('hidden', !q);
    clearTimeout(st); st = setTimeout(() => refreshList(q), 220);
  });
  el.searchX.addEventListener('click', () => { el.search.value = ''; el.searchX.classList.add('hidden'); refreshList(''); el.search.focus(); });

  /* ---------- Thread / pesan ---------- */
  const nearBottom = () => el.scroll.scrollHeight - el.scroll.scrollTop - el.scroll.clientHeight < 140;
  const toBottom = (smooth) => { el.scroll.scrollTo({ top: el.scroll.scrollHeight, behavior: smooth ? 'smooth' : 'auto' }); };
  el.scroll.addEventListener('scroll', () => el.down.classList.toggle('hidden', nearBottom()));
  el.down.addEventListener('click', () => toBottom(true));

  function emptyState() {
    el.thread.innerHTML = '<div class="cx-welcome"><div class="cx-welcome-ic"><img class="ico-img" src="' + (document.body.dataset.base || '').replace(/\/$/, '') + '/assets/img/iTISTANT_Ai.png" alt=""></div><h2>Halo! Aku iTISTANT AI.</h2>' +
      '<p>Kirim pesan yang membuatmu ragu, atau lampirkan file lewat tombol <b>' + icn('plus') + '</b> (gambar, PDF, DOCX, teks) untuk kuperiksa tanda bahayanya.</p>' +
      '<div class="cx-quick">' + QUICK.map(q => '<button type="button" class="cx-q" data-q="' + esc(q[2]) + '"><span>' + icn(q[0]) + '</span><b>' + esc(q[1]) + '</b><small>' + esc(q[2]) + '</small></button>').join('') + '</div></div>';
  }
  el.thread.addEventListener('click', e => {
    const q = e.target.closest('.cx-q'); if (q) { send(q.dataset.q); return; }
    const cp = e.target.closest('.cx-copy');
    if (cp) {
      const raw = cp.closest('.cx-msg').dataset.raw || '';
      (navigator.clipboard ? navigator.clipboard.writeText(raw) : Promise.reject()).then(() => { cp.innerHTML = icn('check') + ' Tersalin'; setTimeout(() => cp.innerHTML = icn('copy') + ' Salin', 1500); }).catch(() => toast('Gagal menyalin.'));
    }
    const rt = e.target.closest('.cx-retry');
    if (rt && state.retry) {
      const r = state.retry; state.retry = null;
      rt.closest('.cx-msg').remove();
      const us = el.thread.querySelectorAll('.cx-msg.me'); if (us.length) us[us.length - 1].remove();
      send(r.text, r.files);
    }
  });

  const ICON = { pdf: icn('pdf'), docx: icn('word'), text: icn('file'), image: icn('image') };
  const fmtSize = b => b < 1024 ? b + ' B' : b < 1048576 ? Math.round(b / 1024) + ' KB' : (b / 1048576).toFixed(1) + ' MB';
  function attsHtml(atts) {
    if (!atts || !atts.length) return '';
    return '<div class="cx-atts">' + atts.map(a => {
      const open = a.url ? ' href="' + esc(a.url) + '" target="_blank" rel="noopener"' : '';
      const tag = a.url ? 'a' : 'div';
      if (a.kind === 'image' && a.url) return '<a class="cx-att-img"' + open + ' title="' + esc(a.name) + '"><img src="' + esc(a.url) + '" alt="' + esc(a.name) + '" loading="lazy"></a>';
      return '<' + tag + ' class="cx-att-file"' + open + ' title="' + esc(a.name) + '"><span class="cx-chip-ic">' + (ICON[a.kind] || icn('file')) + '</span><span class="cx-chip-txt"><b>' + esc(a.name) + '</b><small>' + fmtSize(a.size) + '</small></span></' + tag + '>';
    }).join('') + '</div>';
  }

  function addMsg(role, text, opts) {
    opts = opts || {};
    const w = el.thread.querySelector('.cx-welcome'); if (w) w.remove();
    const m = document.createElement('div');
    m.className = 'cx-msg ' + (role === 'user' ? 'me' : 'bot'); m.dataset.raw = text;
    if (role === 'user') m.innerHTML = '<div class="cx-ubody">' + attsHtml(opts.atts) + (text ? '<div class="cx-bubble">' + esc(text) + '</div>' : '') + '</div>';
    else m.innerHTML = '<div class="cx-av" aria-hidden="true"><img class="ico-img" src="' + (document.body.dataset.base || '').replace(/\/$/, '') + '/assets/img/iTISTANT_Ai.png" alt=""></div><div class="cx-body"><div class="cx-bubble md">' + (opts.html || md(text)) + '</div>' +
      (opts.noActions ? '' : '<div class="cx-actions"><button type="button" class="cx-copy">' + icn('copy') + ' Salin</button></div>') + '</div>';
    el.thread.appendChild(m); return m;
  }
  function typingMsg(label) {
    const m = addMsg('assistant', '', { html: '<span class="cx-dots" aria-label="Sedang mengetik"><i></i><i></i><i></i></span>' + (label ? '<span class="cx-wait">' + esc(label) + '</span>' : ''), noActions: true });
    m.classList.add('typing'); return m;
  }
  function renderConversation(msgs) {
    el.thread.innerHTML = '';
    if (!msgs.length) emptyState(); else msgs.forEach(m => addMsg(m.role, m.content, { atts: m.attachments }));
    toBottom(false);
  }

  /* ---------- Buka / baru ---------- */
  function setUrl(id) {
    try { const u = new URL(location.href); if (id) u.searchParams.set('c', id); else u.searchParams.delete('c'); history.replaceState(null, '', u); } catch (e) {}
  }
  function newChat() {
    state.view++; state.convId = null; el.title.textContent = 'iTISTANT AI'; setUrl(0);
    emptyState(); markActive(); el.input.focus(); if (mq.matches) setSide(false, false);
  }
  async function openConv(id) {
    const v = ++state.view;
    if (mq.matches) setSide(false, false);
    const r = await api('chat.php', { action: 'load', conversation_id: id });
    if (v !== state.view) return;
    if (!r.ok) { state.convs = state.convs.filter(x => x.id !== id); renderList(); newChat(); return; }
    state.convId = id; el.title.textContent = r.conversation.title; setUrl(id); markActive(); renderConversation(r.messages);
  }
  $('cxNew').addEventListener('click', () => { if (state.busy) { toast('Tunggu balasan selesai dulu.'); return; } newChat(); });
  $('cxNewTop').addEventListener('click', () => { if (state.busy) { toast('Tunggu balasan selesai dulu.'); return; } newChat(); });

  /* ---------- Lampiran (+) ---------- */
  const MAX_FILES = 4, MAX_MB = 10;
  const EXT = { image: ['jpg', 'jpeg', 'png', 'webp', 'gif'], pdf: ['pdf'], docx: ['docx'], text: ['txt', 'md', 'csv', 'tsv', 'json', 'log', 'eml', 'xml'] };
  const kindOf = f => { const e = (f.name.split('.').pop() || '').toLowerCase(); for (const k in EXT) if (EXT[k].includes(e)) return k; return null; };

  function addFiles(list) {
    let added = 0;
    for (const f of Array.from(list || [])) {
      if (state.files.length >= MAX_FILES) { toast('Maksimal ' + MAX_FILES + ' file per pesan.'); break; }
      const kind = kindOf(f);
      if (!kind) { toast('Format «' + f.name + '» belum didukung. Gunakan gambar, PDF, DOCX, atau file teks.'); continue; }
      if (!f.size) { toast('File «' + f.name + '» kosong.'); continue; }
      if (f.size > MAX_MB * 1048576) { toast('«' + f.name + '» melebihi ' + MAX_MB + ' MB.'); continue; }
      if (state.files.some(x => x.file.name === f.name && x.file.size === f.size)) continue;
      state.files.push({ file: f, kind: kind, url: kind === 'image' ? URL.createObjectURL(f) : '' }); added++;
    }
    renderFiles(); sync(); if (added) el.input.focus();
  }
  function renderFiles() {
    el.files.classList.toggle('hidden', !state.files.length);
    el.files.innerHTML = state.files.map((x, i) =>
      '<div class="cx-chip" title="' + esc(x.file.name) + '">' +
      (x.kind === 'image' ? '<img src="' + x.url + '" alt="">' : '<span class="cx-chip-ic">' + ICON[x.kind] + '</span>') +
      '<span class="cx-chip-txt"><b>' + esc(x.file.name) + '</b><small>' + fmtSize(x.file.size) + '</small></span>' +
      '<button type="button" class="cx-chip-x" data-rm="' + i + '" aria-label="Hapus lampiran">' + icn('xmark') + '</button></div>').join('');
  }
  el.files.addEventListener('click', e => {
    const b = e.target.closest('[data-rm]'); if (!b) return;
    const x = state.files.splice(Number(b.dataset.rm), 1)[0];
    if (x && x.url) URL.revokeObjectURL(x.url);
    renderFiles(); sync();
  });
  el.plus.addEventListener('click', () => el.fileIn.click());
  el.fileIn.addEventListener('change', () => { addFiles(el.fileIn.files); el.fileIn.value = ''; });
  el.input.addEventListener('paste', e => {          // tempel gambar/file dari clipboard
    const fl = e.clipboardData && e.clipboardData.files;
    if (fl && fl.length) { e.preventDefault(); addFiles(fl); }
  });
  let dragN = 0;                                      // seret & lepas file ke area chat
  const hasFiles = e => e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files');
  el.main.addEventListener('dragenter', e => { if (!hasFiles(e)) return; e.preventDefault(); dragN++; el.drop.classList.remove('hidden'); });
  el.main.addEventListener('dragover', e => { if (hasFiles(e)) e.preventDefault(); });
  el.main.addEventListener('dragleave', e => { if (!hasFiles(e)) return; dragN = Math.max(0, dragN - 1); if (!dragN) el.drop.classList.add('hidden'); });
  el.main.addEventListener('drop', e => {
    if (!hasFiles(e)) return;
    e.preventDefault(); dragN = 0; el.drop.classList.add('hidden');
    if (state.busy) { toast('Tunggu balasan selesai dulu.'); return; }
    addFiles(e.dataTransfer.files);
  });

  /* ---------- Kirim ---------- */
  function setBusy(b) { state.busy = b; root.classList.toggle('is-busy', b); sync(); }
  async function send(text, files) {
    text = (text || '').trim();
    files = files || state.files.slice();
    if ((!text && !files.length) || state.busy) return;
    const v = state.view;
    const um = addMsg('user', text, { atts: files.map(x => ({ kind: x.kind, name: x.file.name, size: x.file.size, url: x.url })) });
    el.input.value = ''; autosize(); state.files = []; renderFiles(); sync(); toBottom(true);
    const wait = typingMsg(files.length ? 'Membaca file…' : ''); toBottom(true); setBusy(true);
    const fd = new FormData();
    fd.append('action', 'send'); fd.append('message', text); fd.append('conversation_id', state.convId || '');
    files.forEach(x => fd.append('files[]', x.file, x.file.name));
    const r = await api('chat.php', fd, true);
    setBusy(false);
    if (r.ok) {
      state.retry = null;
      const c = r.conversation;
      const i = state.convs.findIndex(x => x.id === c.id); if (i >= 0) state.convs.splice(i, 1);
      if (!state.query) { state.convs.unshift(c); renderList(); } else refreshList();
      if (v !== state.view) { markActive(); return; }   // pengguna sudah pindah percakapan
      state.convId = c.id; el.title.textContent = c.title; setUrl(c.id); markActive();
      const box = um.querySelector('.cx-atts');
      if (box && r.attachments && r.attachments.length) box.outerHTML = attsHtml(r.attachments);   // tautan file dari server
      wait.remove(); addMsg('assistant', r.reply); toBottom(true);
    } else if (v === state.view) {
      state.retry = { text: text, files: files };
      wait.classList.remove('typing');
      wait.querySelector('.cx-body').innerHTML = '<div class="cx-bubble err">' + esc(r.error || 'Maaf, terjadi kesalahan.') + '</div><div class="cx-actions"><button type="button" class="cx-retry">' + icn('redo') + ' Coba lagi</button></div>';
      toBottom(true);
    }
    el.input.focus();
  }

  /* ---------- Composer ---------- */
  function autosize() { el.input.style.height = 'auto'; el.input.style.height = Math.min(el.input.scrollHeight, 170) + 'px'; }
  function sync() {
    const n = el.input.value.length; el.count.textContent = n + '/1500'; el.count.classList.toggle('warn', n > 1350);
    el.send.disabled = state.busy || (!el.input.value.trim() && !state.files.length);
  }
  el.input.addEventListener('input', () => { autosize(); sync(); });
  el.input.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(el.input.value); } });
  el.form.addEventListener('submit', e => { e.preventDefault(); send(el.input.value); });

  /* ---------- Mulai ---------- */
  renderList();
  // ingat posisi scroll daftar percakapan walau halaman dimuat ulang
  const SK = 'itihix-chat-list-scroll'; let sT;
  try { el.list.scrollTop = parseInt(sessionStorage.getItem(SK) || '0', 10) || 0; } catch (e) {}
  el.list.addEventListener('scroll', () => { clearTimeout(sT); sT = setTimeout(() => { try { sessionStorage.setItem(SK, String(el.list.scrollTop)); } catch (e) {} }, 120); }, { passive: true });
  if (boot.open && boot.open.conversation) {
    state.convId = boot.open.conversation.id; el.title.textContent = boot.open.conversation.title; renderConversation(boot.open.messages); markActive();
  } else { emptyState(); }
  sync();
  if (!mq.matches) el.input.focus();
})();