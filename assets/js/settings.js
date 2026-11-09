/* Pengaturan: menu kiri + panel kanan (desktop), daftar -> detail (mobile) */
(function () {
  const wrap = document.getElementById('stWrap');
  if (!wrap) return;
  const tabs = Array.from(wrap.querySelectorAll('[data-st-tab]'));
  const panels = Array.from(wrap.querySelectorAll('[data-panel]'));
  const keys = tabs.map(t => t.dataset.stTab);
  const mobile = () => window.matchMedia('(max-width:760px)').matches;

  function show(key, push) {
    if (!keys.includes(key)) return;
    tabs.forEach(t => { const on = t.dataset.stTab === key; t.classList.toggle('active', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
    panels.forEach(p => p.classList.toggle('active', p.dataset.panel === key));
    wrap.dataset.view = 'detail';
    panels.forEach(p => { p.scrollTop = 0; });
    if (push) { try { history.replaceState(null, '', location.pathname + location.search.replace(/([?&])p=[^&]*&?/, '$1').replace(/[?&]$/, '') + '#' + key); } catch (e) {} }
    if (mobile()) window.scrollTo({ top: 0 });
  }
  function showList() {
    wrap.dataset.view = 'list';
    tabs.forEach(t => t.classList.remove('active'));
    try { history.replaceState(null, '', location.pathname + location.search.replace(/([?&])p=[^&]*&?/, '$1').replace(/[?&]$/, '')); } catch (e) {}
    if (mobile()) window.scrollTo({ top: 0 });
  }

  // Mobile: tombol Kembali bawaan HP/browser menutup detail dulu (kembali ke daftar), bukan keluar dari Pengaturan
  tabs.forEach(t => t.addEventListener('click', () => {
    if (mobile() && wrap.dataset.view !== 'detail') { try { history.pushState({ stDetail: 1 }, '', '#' + t.dataset.stTab); } catch (e) {} show(t.dataset.stTab, false); }
    else show(t.dataset.stTab, true);
  }));
  wrap.querySelectorAll('[data-st-back]').forEach(b => b.addEventListener('click', () => {
    if (mobile() && history.state && history.state.stDetail) history.back(); else showList();
  }));
  window.addEventListener('popstate', () => { if (mobile() && wrap.dataset.view === 'detail' && !(history.state && history.state.stDetail)) showList(); });

  // Panel awal: dari server (?p= / error) -> hash -> (desktop) Edit profil -> (mobile) daftar menu
  const start = wrap.dataset.open || location.hash.replace('#', '');
  if (keys.includes(start)) show(start, false);
  else if (!mobile()) show('profil', false);
  else showList();

  // Dari mobile ke desktop saat layar diputar: pastikan ada panel aktif
  window.addEventListener('resize', () => { if (!mobile() && !panels.some(p => p.classList.contains('active'))) show('profil', false); });

  /* ---- Pilihan tema (Terang / Gelap) ---- */
  const seg = document.getElementById('stTheme');
  if (seg) {
    const isDark = () => document.body.classList.contains('dark');
    const paint = () => seg.querySelectorAll('[data-theme]').forEach(b => b.classList.toggle('on', (b.dataset.theme === 'dark') === isDark()));
    paint();
    seg.addEventListener('click', ev => {
      const b = ev.target.closest('[data-theme]'); if (!b) return;
      const wantDark = b.dataset.theme === 'dark';
      if (wantDark !== isDark()) {
        const tb = document.getElementById('themeToggle');
        if (tb) tb.click();   // memakai logika tema bawaan (simpan pilihan + ganti ikon)
        else { document.body.classList.toggle('dark', wantDark); try { localStorage.setItem('itihix-theme', wantDark ? 'dark' : 'light'); } catch (e) {} }
      }
      paint();
    });
  }
})();