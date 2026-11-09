/* Rekomendasi tagar di formulir laporan:
 *  1) ketik "#" + huruf -> daftar saran dari tagar yang sudah ada (panah/Enter/Tab untuk memilih, Esc menutup)
 *  2) baris "Rekomendasi tagar" -> gabungan tagar populer + tagar yang cocok dengan kategori yang dicentang (klik untuk menyisipkan) */
(function () {
  const ta = document.getElementById('reportBody'), box = document.getElementById('htBox');
  if (!ta || !box || !window.ITIHIX) return;
  const { api, esc } = window.ITIHIX;
  const list = document.getElementById('htList'), reco = document.getElementById('htReco');
  const TOKEN = /#([\p{L}\p{N}_]{0,40})$/u, MAXTAGS = 8;
  let seeds = {}; try { seeds = JSON.parse(box.dataset.seeds || '{}'); } catch (e) {}
  let items = [], active = -1, seq = 0, timer = null, popular = null;

  const used = () => { const s = new Set(); (ta.value.match(/#[\p{L}\p{N}_]{2,40}/gu) || []).forEach(t => s.add(t.slice(1).toLowerCase())); return s; };
  const fetchTags = async q => { const r = await api('hashtags.php?q=' + encodeURIComponent(q), null, false, true); return r && r.ok ? r.tags : []; };

  /* ---- 1) saran saat mengetik ---- */
  function tokenAtCaret() {
    const pos = ta.selectionStart, before = ta.value.slice(0, pos), m = before.match(TOKEN);
    if (!m) return null;
    const start = m.index, prev = start ? before[start - 1] : '';
    if (prev && !/\s/.test(prev)) return null;            // "#" harus di awal kata
    return { start, pos, q: m[1] };
  }
  function hide() { list.classList.add('hidden'); list.innerHTML = ''; items = []; active = -1; }
  function render() {
    if (!items.length) return hide();
    list.innerHTML = items.map((t, i) => '<button type="button" class="ht-item' + (i === active ? ' active' : '') + '" role="option" data-i="' + i + '"><span>#' + esc(t.tag) + '</span>' + (t.count ? '<small>' + t.count + ' laporan</small>' : '') + '</button>').join('');
    list.classList.remove('hidden');
  }
  function pick(i) {
    const t = items[i], k = tokenAtCaret(); if (!t || !k) return hide();
    const ins = '#' + t.tag + ' ';
    ta.value = ta.value.slice(0, k.start) + ins + ta.value.slice(k.pos);
    const p = k.start + ins.length; ta.setSelectionRange(p, p); ta.focus();
    hide(); refreshReco();
  }
  async function suggest() {
    const k = tokenAtCaret(); if (!k) return hide();
    const my = ++seq, tags = await fetchTags(k.q);
    if (my !== seq) return;                                // ada ketikan yang lebih baru
    const u = used();
    items = tags.filter(t => { const l = t.tag.toLowerCase(); return !u.has(l) || l === k.q.toLowerCase(); }).slice(0, 6); // sembunyikan tagar yang sudah dipakai di teks
    active = items.length ? 0 : -1; render();
  }
  ta.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(suggest, 150); clearTimeout(ta._r); ta._r = setTimeout(refreshReco, 250); });
  ta.addEventListener('click', () => { clearTimeout(timer); timer = setTimeout(suggest, 60); });
  ta.addEventListener('blur', () => setTimeout(hide, 180));
  ta.addEventListener('keydown', e => {
    if (list.classList.contains('hidden') || !items.length) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); active = (active + 1) % items.length; render(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); active = (active - 1 + items.length) % items.length; render(); }
    else if (e.key === 'Enter' || e.key === 'Tab') { if (active >= 0) { e.preventDefault(); pick(active); } }
    else if (e.key === 'Escape') { e.preventDefault(); hide(); }
  });
  list.addEventListener('mousedown', e => { const b = e.target.closest('.ht-item'); if (b) { e.preventDefault(); pick(parseInt(b.dataset.i, 10)); } });

  /* ---- 2) baris rekomendasi ---- */
  function chosenCategories() { return Array.from(document.querySelectorAll('input[name="categories[]"]:checked')).map(i => i.value); }
  async function refreshReco() {
    if (popular === null) popular = (await fetchTags('')).map(t => t.tag);
    const u = used(), out = [], seen = new Set();
    chosenCategories().forEach(c => (seeds[c] || []).forEach(t => out.push(t)));
    popular.forEach(t => out.push(t));
    const pool = out.filter(t => { const k = t.toLowerCase(); if (seen.has(k) || u.has(k)) return false; seen.add(k); return true; }).slice(0, 8);
    if (u.size >= MAXTAGS) { reco.innerHTML = '<span class="sub">Batas ' + MAXTAGS + ' tagar per laporan sudah tercapai.</span>'; return; }
    reco.innerHTML = pool.length ? '<span class="sub">Rekomendasi tagar:</span>' + pool.map(t => '<button type="button" class="ht-chip" data-tag="' + esc(t) + '">#' + esc(t) + '</button>').join('') : '';
  }
  reco.addEventListener('click', e => {
    const b = e.target.closest('.ht-chip'); if (!b) return;
    const v = ta.value, pos = (typeof ta._pos === 'number' ? ta._pos : v.length);
    const before = v.slice(0, pos), after = v.slice(pos);
    const lead = before && !/\s$/.test(before) ? ' ' : '';
    ta.value = before + lead + '#' + b.dataset.tag + ' ' + after.replace(/^ /, '');
    const p = (before + lead + '#' + b.dataset.tag + ' ').length; ta.setSelectionRange(p, p); ta._pos = p; ta.focus();
    refreshReco();
  });
  ['keyup', 'click', 'blur'].forEach(ev => ta.addEventListener(ev, () => { ta._pos = ta.selectionStart; }));
  document.querySelectorAll('input[name="categories[]"]').forEach(i => i.addEventListener('change', refreshReco));
  refreshReco();
})();