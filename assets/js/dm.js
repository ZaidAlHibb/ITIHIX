/* ITIHIX Pesan: sidebar percakapan (buka/tutup, cari), kirim pesan, polling pesan baru. UI mengikuti Chat AI. */
(function () {
  const root = document.getElementById('cx');
  if (!root || !window.ITIHIX || !document.getElementById('page-messages')) return;
  const { api, esc, toast } = window.ITIHIX;
  const $ = id => document.getElementById(id);
  const el = { list: $('cxList'), search: $('cxSearch'), searchX: $('cxSearchX'), thread: $('cxThread'), scroll: $('cxScroll'), down: $('cxDown'),
    toggle: $('cxToggle'), form: $('cxForm'), input: $('cxInput'), send: $('cxSend'), count: $('cxCount') };
  const peer = el.thread.dataset.peer && el.thread.dataset.peer !== '0' ? el.thread.dataset.peer : '';
  const mq = window.matchMedia('(max-width: 760px)');
  const LS = 'itihix-dm-side';

  /* ---------- Sidebar buka/tutup ---------- */
  function setSide(open, persist) {
    root.classList.toggle('is-side-open', open);
    el.toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (persist && !mq.matches) { try { localStorage.setItem(LS, open ? '1' : '0'); } catch (e) {} }
  }
  function initSide() {
    let open = mq.matches ? !peer : true;                       // HP: daftar langsung terbuka bila belum memilih percakapan
    if (!mq.matches) { try { if (localStorage.getItem(LS) === '0') open = false; } catch (e) {} }
    setSide(open, false);
  }
  el.toggle.addEventListener('click', () => setSide(!root.classList.contains('is-side-open'), true));
  $('cxCollapse').addEventListener('click', () => setSide(false, true));
  $('cxBackdrop').addEventListener('click', () => setSide(false, false));
  const ol = $('dmOpenList'); if (ol) ol.addEventListener('click', () => setSide(true, false));
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && mq.matches) setSide(false, false); });
  (mq.addEventListener ? mq.addEventListener.bind(mq, 'change') : mq.addListener.bind(mq))(initSide);
  initSide();

  /* ---------- Pencarian percakapan (di sisi klien) ---------- */
  function filterList() {
    const q = el.search.value.trim().toLowerCase();
    el.searchX.classList.toggle('hidden', !q);
    let head = null, shown = 0, headShown = 0;
    Array.from(el.list.children).forEach(n => {
      if (n.classList.contains('cx-group')) { if (head) head.classList.toggle('hidden', !headShown); head = n; headShown = 0; }
      else if (n.classList.contains('dm-i')) { const ok = !q || n.dataset.q.includes(q); n.classList.toggle('hidden', !ok); if (ok) { shown++; headShown++; } }
    });
    if (head) head.classList.toggle('hidden', !headShown);
    const nm = $('dmNoMatch'); if (nm) nm.classList.toggle('hidden', !q || shown > 0);
  }
  el.search.addEventListener('input', filterList);
  el.searchX.addEventListener('click', () => { el.search.value = ''; filterList(); el.search.focus(); });
  const SK = 'itihix-dm-list-scroll'; let sT;
  try { el.list.scrollTop = parseInt(sessionStorage.getItem(SK) || '0', 10) || 0; } catch (e) {}
  el.list.addEventListener('scroll', () => { clearTimeout(sT); sT = setTimeout(() => { try { sessionStorage.setItem(SK, String(el.list.scrollTop)); } catch (e) {} }, 120); }, { passive: true });

  if (!peer) return; // tanpa percakapan terbuka: hanya sidebar

  /* ---------- Thread ---------- */
  const av = el.thread.dataset.av || '';                         // HTML avatar mini dari server (sudah di-escape)
  let last = parseInt(el.thread.dataset.last, 10) || 0, sending = false;
  const nearBottom = () => el.scroll.scrollHeight - el.scroll.scrollTop - el.scroll.clientHeight < 140;
  const toBottom = smooth => el.scroll.scrollTo({ top: el.scroll.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
  el.scroll.addEventListener('scroll', () => el.down.classList.toggle('hidden', nearBottom()));
  el.down.addEventListener('click', () => toBottom(true));

  function addMsg(m) {
    if (el.thread.querySelector('.cx-msg[data-id="' + m.id + '"]')) return false;
    const w = el.thread.querySelector('.cx-welcome'); if (w) w.remove();
    const d = document.createElement('div');
    d.className = 'cx-msg ' + (m.mine ? 'me' : 'bot'); d.dataset.id = m.id;
    d.innerHTML = (m.mine ? '' : '<div class="cx-av dm-av" aria-hidden="true">' + av + '</div>') +
      '<div class="cx-body"><div class="cx-bubble dm-b">' + esc(m.body) + '</div><div class="dm-time">' + esc(m.time) + '</div></div>';
    el.thread.appendChild(d);
    if (m.id > last) last = m.id;
    touchItem(m);
    return true;
  }
  // perbarui cuplikan & waktu di sidebar untuk percakapan yang sedang dibuka
  function touchItem(m) {
    const it = el.list.querySelector('.dm-i.active'); if (!it) return;
    const sn = it.querySelector('.dm-snip'); if (sn) sn.textContent = (m.mine ? 'Kamu: ' : '') + m.body.replace(/\s+/g, ' ').slice(0, 60);
    const wh = it.querySelector('.dm-when'); if (wh) wh.textContent = 'baru saja';
  }
  function setLocked(canSend, reason) {
    $('dmBox').classList.toggle('hidden', !canSend);
    $('dmLocked').classList.toggle('hidden', canSend);
    if (!canSend && reason) $('dmLockedMsg').textContent = reason;
    const q = el.thread.querySelector('.cx-quick'); if (q && !canSend) q.remove();
  }

  /* ---------- Kirim ---------- */
  async function send(text) {
    text = (text || '').trim();
    if (!text || sending) return;
    sending = true; sync();
    const r = await api('dm.php', { action: 'send', user_id: peer, message: text });
    sending = false;
    if (r.ok) { el.input.value = ''; autosize(); addMsg(r.message); toBottom(true); }
    else if (r.locked) setLocked(false, r.error);
    sync(); el.input.focus();
  }
  el.thread.addEventListener('click', e => { const q = e.target.closest('.cx-q'); if (q) send(q.dataset.q); });

  /* ---------- Composer ---------- */
  function autosize() { el.input.style.height = 'auto'; el.input.style.height = Math.min(el.input.scrollHeight, 170) + 'px'; }
  function sync() {
    const n = el.input.value.length; el.count.textContent = n + '/1000'; el.count.classList.toggle('warn', n > 900);
    el.send.disabled = sending || !el.input.value.trim();
  }
  el.input.addEventListener('input', () => { autosize(); sync(); });
  el.input.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(el.input.value); } });
  el.form.addEventListener('submit', e => { e.preventDefault(); send(el.input.value); });

  /* ---------- Pesan baru dari lawan bicara (polling) ---------- */
  async function poll() {
    if (document.hidden) return;
    const r = await api('dm.php?action=poll&with=' + peer + '&after=' + last, null, false, true);
    if (!r || !r.ok) return;
    const stick = nearBottom(); let added = 0;
    r.messages.forEach(m => { if (addMsg(m)) added++; });
    if (added && stick) toBottom(true);
    setLocked(r.can_send, r.reason);
  }
  setInterval(poll, 4000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });

  sync(); toBottom(false);
  if (!mq.matches && !$('dmBox').classList.contains('hidden')) el.input.focus();
})();