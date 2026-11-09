/* Tebak Scam 2 pemain: klien. Polling ke api/game.php (tanpa WebSocket). */
(function () {
  const page = document.getElementById('page-game');
  if (!page || !window.ITIHIX) return;
  const { api, esc, toast } = window.ITIHIX;
  const root = document.getElementById('gmRoot');
  const CFG = { rounds: +page.dataset.rounds, xp: +page.dataset.xp, max: +page.dataset.max, reply: +page.dataset.reply || 300,
    openSec: +page.dataset.openSec || 120, turnSec: +page.dataset.turnSec || 45, guessSec: +page.dataset.guessSec || 45 };
  let st = null, rev = '', timer = null, busy = false;
  // Isian yang sedang diketik/dipilih disimpan di sini supaya tidak hilang saat tampilan dirender ulang oleh polling.
  const draft = { round: 0, cat: '', msg: '', guess: '', reason: '' };
  let offset = 0;                                   // selisih jam server - jam klien (detik) untuk hitung mundur yang akurat
  let lobbyTab = 'play', createVis = 'public', hist = null, histOpen = null, clock = null, nextClock = null, pendingMatch = 0;
  const syncClock = r => { if (r && r.now) offset = r.now - Date.now() / 1000; };

  /* ---------- Notifikasi saat tab lain dibuka / jendela tidak aktif ----------
     Hanya aktif bila pemain sedang "pergi" (tab tersembunyi atau jendela tidak fokus): bunyi pendek,
     judul tab berkedip, dan notifikasi browser (bila diizinkan). Izin diminta saat tombol cari/buat/gabung ditekan. */
  const NOTIF_OK = 'Notification' in window;
  const baseTitle = document.title;
  const siteBase = (document.body.dataset.base || '').replace(/\/$/, '');
  let blinkTimer = null, audioCtx = null;
  const isAway = () => document.hidden || !document.hasFocus();
  function unlockAudio() {
    try {
      const AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return;
      audioCtx = audioCtx || new AC();
      if (audioCtx.state === 'suspended') audioCtx.resume();
    } catch (e) { /* abaikan */ }
  }
  function beep() {
    try {
      if (!audioCtx) return;
      if (audioCtx.state === 'suspended') audioCtx.resume();
      [[880, 0], [1175, 0.16]].forEach(([f, d]) => {
        const o = audioCtx.createOscillator(), g = audioCtx.createGain(), t = audioCtx.currentTime + d;
        o.type = 'sine'; o.frequency.value = f;
        g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.18, t + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.3);
        o.connect(g); g.connect(audioCtx.destination); o.start(t); o.stop(t + 0.32);
      });
    } catch (e) { /* abaikan */ }
  }
  function stopBlink() { if (blinkTimer) { clearInterval(blinkTimer); blinkTimer = null; } document.title = baseTitle; }
  function blinkTitle(text) {
    if (blinkTimer) clearInterval(blinkTimer);
    let on = false;
    document.title = '● ' + text;
    blinkTimer = setInterval(() => { on = !on; document.title = on ? baseTitle : '● ' + text; }, 1000);
  }
  function notify(title, body, tag) {
    if (!isAway()) return;
    beep(); blinkTitle(title);
    if (NOTIF_OK && Notification.permission === 'granted') {
      try {
        const n = new Notification(title, { body, tag: 'itihix-game-' + tag, renotify: true, icon: siteBase + '/assets/img/logo.png' });
        n.onclick = () => { window.focus(); n.close(); };
        setTimeout(() => n.close(), 12000);
      } catch (e) { /* sebagian browser HP tidak mengizinkan konstruktor Notification */ }
    }
  }
  function askNotif() {
    unlockAudio();
    if (NOTIF_OK && Notification.permission === 'default') {
      try {
        const p = Notification.requestPermission(() => {});
        if (p && p.then) p.then(() => { if (st && st.view === 'waiting') renderWaiting(); });
      } catch (e) { /* abaikan */ }
    }
  }
  window.addEventListener('focus', stopBlink);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) stopBlink(); });
  window.addEventListener('pagehide', stopBlink);

  const snap = s => s ? { view: s.view, n: s.round ? s.round.n : 0, status: s.round ? s.round.status : '', turn: s.round ? s.round.turn : '', role: s.round ? s.round.role : '', cnt: s.round ? s.round.messages.length : 0 } : null;
  function detect(o, s) {
    if (!o || !s) return;
    const opp = s.opp ? s.opp.name : 'Lawan', n = snap(s);
    if (o.view === 'waiting' && n.view === 'play') return notify('Lawan ditemukan!', opp + ' siap bermain Mask of Deception. Ayo mulai!', 'match');
    if (o.view === 'waiting' && n.view === 'lobby') return notify('Pencarian berakhir', 'Belum ada lawan yang cocok. Coba cari lagi.', 'match');
    if (o.view === 'play' && n.view === 'finished') {
      const why = s.room.left_by_opp ? opp + ' mengakhiri match.' : s.room.reason === 'expired' ? 'Match berakhir karena tidak ada aktivitas.' : 'Semua ronde selesai. Lihat hasilnya!';
      return notify('Match selesai', why, 'end');
    }
    if (o.view !== 'play' || n.view !== 'play') return;
    if (n.n !== o.n) return notify('Ronde ' + n.n + ' dimulai', n.role === 'sender' ? 'Kamu jadi pengirim. Siapkan pesanmu!' : 'Kamu jadi penebak. Waspadai pesannya!', 'round');
    if (n.status === 'done' && o.status !== 'done') return notify('Ronde selesai', n.role === 'sender' ? opp + ' sudah menebak. Lihat hasilnya!' : 'Hasil ronde sudah keluar.', 'done');
    if (n.turn === 'me' && o.turn !== 'me') return notify('Giliranmu!', n.cnt === 0 && n.role === 'sender' ? 'Kamu yang membuka chat.' : opp + ' sudah membalas. Giliranmu!', 'turn');
    if (n.turn === 'guess' && o.turn !== 'guess' && n.role === 'guesser') return notify('Saatnya menebak', 'Jatah chat habis. Penipuan atau bukan?', 'guess');
  }
  const notifBlock = () => {
    if (!NOTIF_OK) return '';
    if (Notification.permission === 'granted') return '<div class="sub" style="margin-top:12px">' + icn('bell') + ' Kamu akan diberi tahu saat lawan ditemukan, jadi boleh buka tab lain. <b>Biarkan tab ini tetap terbuka.</b></div>';
    if (Notification.permission === 'denied') return '<div class="sub" style="margin-top:12px">Notifikasi diblokir di browser. Kamu tetap dapat bunyi dan judul tab berkedip saat lawan ditemukan.</div>';
    return '<div class="sub" style="margin-top:12px">Mau buka tab lain sambil menunggu?</div><button class="btn btn-light" type="button" id="gmNotif" style="margin-top:6px">' + icn('bell') + ' Aktifkan notifikasi</button>';
  };

  const nl = s => esc(s || '').replace(/\n/g, '<br>');
  const initial = p => (p && p.avatar) || '<span class="avatar">?</span>';

  /* ---------- Polling ---------- */
  async function poll(force) {
    if (busy) return;
    const r = await api('game.php?action=state' + (rev && !force ? '&since=' + encodeURIComponent(rev) : ''), null, false, true);
    if (!r || !r.ok) return schedule();
    syncClock(r);
    if (!r.unchanged) apply(r);
    schedule();
  }
  function schedule() {
    clearTimeout(timer);
    const live = st && (st.view === 'play' || st.view === 'waiting');
    const wait = document.hidden ? (live ? 2500 : 6000) : (live ? 1800 : 3500);
    timer = setTimeout(() => poll(false), wait);
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(true); });

  async function act(action, data) {
    if (busy) return;
    if (action === 'find' || action === 'create' || action === 'join') askNotif();   // harus dipanggil langsung dari klik
    busy = true; setBusy(true);
    const r = await api('game.php', Object.assign({ action }, data || {}));
    busy = false; setBusy(false);
    syncClock(r);
    if (r && r.ok) { apply(r, true); }
    schedule();
    return r;
  }
  function setBusy(b) { root.querySelectorAll('button').forEach(x => { if (b) { x.dataset.was = x.disabled ? '1' : ''; x.disabled = true; } else if (x.dataset.was !== undefined) { x.disabled = x.dataset.was === '1'; delete x.dataset.was; } }); }

  function apply(r, force) {
    const prevView = st && st.view;
    const before = snap(st);
    st = r; rev = r.rev || '';
    detect(before, st);
    if (st.round && st.round.n !== draft.round) Object.assign(draft, { round: st.round.n, cat: '', msg: '', guess: '', reason: '' });
    // Di lobi: jangan render ulang saat pengguna sedang mengetik kode ruangan (kursor tidak loncat).
    const ae = document.activeElement;
    const typing = ae && root.contains(ae) && (ae.tagName === 'TEXTAREA' || ae.tagName === 'INPUT');
    if (typing && !force && prevView === 'lobby' && st.view === 'lobby') return;
    // Sedang melihat riwayat di lobi: jangan render ulang tiap polling (daftar/detail tidak boleh reset).
    if (!force && prevView === 'lobby' && st.view === 'lobby' && lobbyTab === 'history') return;
    render();
    maybeSpin();
  }
  const liveBadge = () => st.opp ? (st.opp_online ? '<span class="gm-dot on"></span> online' : '<span class="gm-dot"></span> tidak aktif') : '';

  /* ---------- Render ---------- */
  function render() {
    if (!st) return;
    if (st.view !== 'play') { clock = null; nextClock = null; closeSpin(); }
    if (st.view !== 'lobby') closeInfo();
    if (st.view === 'lobby') return renderLobby();
    if (st.view === 'waiting') return renderWaiting();
    if (st.view === 'play') return renderPlay();
    if (st.view === 'finished') return renderFinished();
  }

  const reasonText = m => m.reason === 'completed' ? 'Selesai' : m.left_by_me ? 'Kamu mengakhiri match' : m.left_by_opp ? 'Lawan mengakhiri match' : m.reason === 'expired' ? 'Berakhir (tidak aktif)' : 'Selesai';
  const tabsHtml = () => '<div class="gm-tabs" role="tablist">' +
    '<button type="button" class="gm-tab' + (lobbyTab === 'play' ? ' on' : '') + '" data-tab="play" role="tab">' + '<img class="ico-img" src="' + (document.body.dataset.base || '').replace(/\/$/, '') + '/assets/img/mod2p.png" alt="">' + ' Main</button>' +
    '<button type="button" class="gm-tab' + (lobbyTab === 'history' ? ' on' : '') + '" data-tab="history" role="tab">' + icn('history') + ' Riwayat match</button></div>';
  function bindTabs() { root.querySelectorAll('[data-tab]').forEach(b => b.onclick = () => { if (busy) return; lobbyTab = b.dataset.tab; histOpen = null; renderLobby(); }); }

  /* ---------- Jendela "Cara bermain" (tombol i) ---------- */
  const stepsHtml = () =>
        '<ol class="gm-stepsx">' +
          '<li><i>1</i><div><b>Masuk ruangan</b><small>Cari lawan otomatis ' + icn('bolt') + ', atau buat ruangan <b>publik</b> / <b>privat</b> (lewat kode). Undian di awal menentukan siapa jadi <b>pengirim</b> duluan.</small></div></li>' +
          '<li><i>2</i><div><b>Peran rahasia</b><small>Pengirim diam-diam jadi <b>Penipu</b> (pilih 1 dari 2 kategori) atau <b>Bukan penipu</b> (pesan asli, bebas berperan jadi siapa saja).</small></div></li>' +
          '<li><i>3</i><div><b>Ngobrol maks. 5 chat</b><small>Pengirim 3 pesan, penebak 2 balasan. Batas waktu: buka chat ' + Math.round(CFG.openSec / 60 * 10) / 10 + ' menit, balas ' + CFG.turnSec + ' dtk, tebak ' + CFG.guessSec + ' dtk.</small></div></li>' +
          '<li><i>4</i><div><b>Tebak &amp; menang</b><small>Tebakan <b>benar</b> → kalian berdua <b>+' + CFG.xp + ' XP</b>. Peran bertukar tiap ronde; lanjut setelah kalian menekan siap (hitung mundur 20 dtk).</small></div></li>' +
        '</ol>' +
        '<div class="sub gm-note">XP harian dibatasi: maks. 6 ronde ber-XP per orang dan 3 dengan lawan yang sama. Kamu bisa menekan <b>Akhiri match</b> kapan saja; hasilnya tersimpan di Riwayat.</div>';
  let infoEl = null;
  const infoKey = e => { if (e.key === 'Escape') closeInfo(); };
  function closeInfo() { if (!infoEl) return; infoEl.remove(); infoEl = null; document.removeEventListener('keydown', infoKey); }
  function openInfo() {
    if (infoEl) return;
    infoEl = document.createElement('div');
    infoEl.className = 'gate gm-infowin'; infoEl.setAttribute('role', 'dialog'); infoEl.setAttribute('aria-modal', 'true'); infoEl.setAttribute('aria-label', 'Cara bermain');
    infoEl.innerHTML = '<div class="gate-box card gm-infobox"><div class="pfe-head"><h3>' + icn('info') + ' Cara bermain</h3><button class="gate-x" type="button" aria-label="Tutup">' + icn('xmark') + '</button></div><div class="gm-infobody">' + stepsHtml() + '</div></div>';
    document.body.appendChild(infoEl);
    infoEl.addEventListener('click', e => { if (e.target === infoEl || e.target.closest('.gate-x')) closeInfo(); });
    document.addEventListener('keydown', infoKey);
  }

  function renderLobby() {
    if (lobbyTab === 'history') {
      root.innerHTML = tabsHtml() + '<div id="gmHist"></div>'; bindTabs();
      if (pendingMatch) { const id = pendingMatch; pendingMatch = 0; openMatch(id); return; }   // dari layar hasil: langsung ke rincian match itu
      loadHistory(); return;
    }
    const open = st.open || [];
    root.innerHTML = tabsHtml() +
      '<div class="card gm-card gm-lobby">' +
        '<div class="gm-lh"><h3>Mulai bermain</h3><button class="gm-info" id="gmInfo" type="button" aria-label="Cara bermain" title="Cara bermain">' + icn('info') + '</button></div>' +
        '<div class="gm-lbody"><div class="gm-lcol">' +
        '<button class="btn btn-primary gm-wide" id="gmFind" type="button">' + icn('bolt') + ' Cari lawan otomatis</button>' +
        '<div class="sub gm-hint">Langsung dipasangkan dengan pemain lain yang sedang mencari.</div>' +
        '<div class="gm-or">atau buat ruangan sendiri</div>' +
        '<div class="gm-seg" role="radiogroup" aria-label="Jenis ruangan">' +
          '<button type="button" class="gm-seg-b' + (createVis === 'public' ? ' on' : '') + '" data-vis="public" role="radio" aria-checked="' + (createVis === 'public') + '"><b>' + icn('globe') + ' Publik</b><small>Tampil di daftar ruangan</small></button>' +
          '<button type="button" class="gm-seg-b' + (createVis === 'private' ? ' on' : '') + '" data-vis="private" role="radio" aria-checked="' + (createVis === 'private') + '"><b>' + icn('lock') + ' Privat</b><small>Hanya lewat kode</small></button>' +
        '</div>' +
        '<button class="btn btn-light gm-wide" id="gmCreate" type="button">' + icn('plus') + ' Buat ruangan</button>' +
        '<div class="gm-or">atau gabung dengan kode</div>' +
        '<form id="gmJoinForm" class="gm-join"><input class="field" id="gmCode" maxlength="8" placeholder="Kode ruangan, mis. K7M2P" autocomplete="off" style="text-transform:uppercase"><button class="btn btn-light" type="submit">Gabung</button></form>' +
        '</div><div class="gm-lcol gm-lrooms">' +
        '<h4 class="gm-sub">Ruangan publik</h4>' +
        (open.length ? '<div class="gm-rooms">' + open.map(o =>
          '<div class="gm-room"><div class="gm-who">' + initial(o.host) + '<div><b>' + esc(o.host.name) + '</b><small>@' + esc(o.host.username) + ' · Lv. ' + o.host.level + (o.auto ? ' · <span class="gm-badge auto">' + icn('bolt') + ' mencari lawan</span>' : '') + '</small></div></div>' +
          '<button class="btn btn-primary" type="button" data-join="' + esc(o.code) + '">Gabung</button></div>').join('') + '</div>'
          : '<div class="gm-empty">Belum ada ruangan publik</div>') +
      '</div></div></div>';
    bindTabs();
    root.querySelector('#gmInfo').onclick = openInfo;
    root.querySelector('#gmFind').onclick = () => act('find');
    root.querySelectorAll('[data-vis]').forEach(b => b.onclick = () => {
      createVis = b.dataset.vis;
      root.querySelectorAll('[data-vis]').forEach(x => { const on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-checked', on ? 'true' : 'false'); });
    });
    root.querySelector('#gmCreate').onclick = () => act('create', { visibility: createVis });
    root.querySelector('#gmJoinForm').onsubmit = e => { e.preventDefault(); const c = root.querySelector('#gmCode').value.trim(); if (!c) return toast('Isi kode ruangan dulu.'); act('join', { code: c }); };
    root.querySelectorAll('[data-join]').forEach(b => b.onclick = () => act('join', { code: b.dataset.join }));
  }

  /* ---------- Riwayat match ---------- */
  async function loadHistory() {
    const box = root.querySelector('#gmHist'); if (!box) return;
    if (histOpen) return renderDetail(box);
    if (!hist) box.innerHTML = '<div class="card gm-card"><div class="sub">Memuat riwayat…</div></div>';
    const r = await api('game.php', { action: 'history' });
    const now = root.querySelector('#gmHist');
    if (!now || lobbyTab !== 'history' || histOpen) return;
    if (!r || !r.ok) { now.innerHTML = '<div class="gm-empty">Riwayat belum bisa dimuat. Coba lagi.</div>'; return; }
    hist = r.history; renderHistList(now);
  }
  function renderHistList(box) {
    if (!hist || !hist.length) {
      box.innerHTML = '<div class="card gm-card gm-center"><div class="gm-trophy">' + icn('history') + '</div><h3>Belum ada riwayat</h3><div class="sub">Selesaikan minimal satu ronde, hasilnya akan muncul di sini.</div></div>';
      return;
    }
    box.innerHTML = '<div class="card gm-card"><h3>Riwayat match</h3><div class="gm-hlist">' + hist.map(m => {
      const ratio = m.done ? m.wins / m.done : 0, cls = ratio >= 0.5 ? 'win' : 'lose';
      return '<button type="button" class="gm-hitem" data-open="' + m.id + '"><div class="gm-who">' + initial(m.opp) + '<div><b>vs ' + esc(m.opp ? m.opp.name : 'Pemain') + '</b>' +
        '<small>' + esc(m.ago) + ' · ' + esc(m.date) + ' · ' + (m.visibility === 'private' ? icn('lock') : icn('globe')) + (m.auto ? ' ' + icn('bolt') : '') + '</small><small>' + esc(reasonText(m)) + '</small></div></div>' +
        '<div class="gm-hres"><span class="gm-hscore ' + cls + '">' + m.wins + ' / ' + m.done + '</span><small>' + (m.xp ? '+' + m.xp + ' XP' : 'tanpa XP') + '</small></div></button>';
    }).join('') + '</div></div>';
    box.querySelectorAll('[data-open]').forEach(b => b.onclick = () => openMatch(Number(b.dataset.open)));
  }
  async function openMatch(id) {
    const r = await api('game.php', { action: 'match', id: id });
    if (!r || !r.ok) return;
    histOpen = r.match;
    const box = root.querySelector('#gmHist'); if (box && lobbyTab === 'history') renderDetail(box);
  }
  function renderDetail(box) {
    const m = histOpen, opp = m.opp ? m.opp.name : 'Pemain';
    const res = rd => {
      let chip;
      if (!rd.finished) chip = '<span class="gm-chip">Belum selesai</span>';
      else if (rd.timeout) chip = '<span class="gm-chip warn">' + icn('stopwatch') + ' Waktu habis</span>';
      else chip = rd.correct ? '<span class="gm-chip ok">' + icn('check') + ' Tebakan benar' + (rd.xp ? ' · +' + rd.xp + ' XP' : '') + '</span>' : '<span class="gm-chip bad">' + icn('xmark') + ' Tebakan salah</span>';
      const guess = rd.guess === 'scam' ? icn('warn') + ' Penipuan' : rd.guess === 'safe' ? icn('check') + ' Bukan penipuan' : '—';
      return '<div class="card gm-card gm-dround"><div class="gm-dhead"><div><b>Ronde ' + rd.n + '</b><small>' + (rd.role === 'sender' ? 'Kamu pengirim' : 'Kamu penebak') + ' · sebenarnya ' + (rd.is_scam ? '<b class="bad">penipuan</b>' : '<b class="good">bukan penipuan</b>') + (rd.category ? ' (' + esc(rd.category) + ')' : '') + '</small></div>' + chip + '</div>' +
        '<div class="gm-chatlist done">' + (rd.messages.length ? transcript(rd.messages, opp) : '<div class="gm-empty-chat">Tidak ada chat.</div>') + '</div>' +
        (rd.finished ? '<div class="gm-dfoot"><small>Tebakan ' + (rd.role === 'guesser' ? 'kamu' : esc(opp)) + ': <b>' + guess + '</b>' + (rd.reason ? ' · “' + esc(rd.reason) + '”' : '') + '</small></div>' : '') + '</div>';
    };
    box.innerHTML = '<div class="gm-dbar"><button type="button" class="btn btn-light" id="gmBack">← Kembali</button></div>' +
      '<div class="card gm-card gm-dsum"><div class="gm-who">' + initial(m.opp) + '<div><b>vs ' + esc(opp) + '</b><small>' + esc(m.date) + ' · ' + esc(reasonText(m)) + '</small></div></div>' +
      '<div class="gm-stats"><div><b>' + m.wins + ' / ' + m.done + '</b><small>ronde menang</small></div><div><b>' + (m.done ? Math.round(m.wins / m.done * 100) : 0) + '%</b><small>akurasi tim</small></div><div><b>+' + m.xp + '</b><small>XP didapat</small></div></div></div>' +
      (m.rounds.length ? m.rounds.map(res).join('') : '<div class="gm-empty">Tidak ada ronde yang tercatat.</div>');
    box.querySelector('#gmBack').onclick = () => { histOpen = null; if (hist) renderHistList(root.querySelector('#gmHist')); else loadHistory(); };   // dibuka langsung dari layar hasil: daftar belum dimuat
  }

  function renderWaiting() {
    const rm = st.room, auto = rm.auto, priv = rm.visibility === 'private';
    root.innerHTML =
      '<div class="card gm-card gm-center">' +
        '<div class="gm-pulse">' + (auto ? icn('bolt') : icn('hourglass')) + '</div>' +
        '<h3>' + (auto ? 'Mencari lawan…' : 'Menunggu lawan bergabung…') + '</h3>' +
        '<span class="gm-badge' + (priv ? ' priv' : '') + '">' + (priv ? icn('lock') + ' Ruangan privat' : icn('globe') + ' Ruangan publik') + '</span>' +
        (auto
          ? '<div class="sub">Kamu otomatis dipasangkan begitu ada pemain lain yang mencari. Sudah menunggu <b id="gmWaited">0 detik</b>.</div>' + dots() +
            '<div class="sub hidden" id="gmWaitHint" style="margin-top:12px">Belum ada pemain lain? Batalkan lalu buat ruangan <b>privat</b> dan bagikan kodenya ke temanmu.</div>'
          : '<div class="sub">' + (priv ? 'Ruangan ini tidak muncul di daftar. Bagikan kode ini ke temanmu.' : 'Ruangan tampil di daftar publik. Kamu juga bisa membagikan kode ini ke temanmu.') + '</div>' +
            '<div class="gm-code" id="gmCodeBox" title="Salin kode">' + esc(rm.code) + '</div>') +
        notifBlock() +
        '<div class="gm-actions">' + (auto ? '' : '<button class="btn btn-light" type="button" id="gmCopy">' + icn('copy') + ' Salin kode</button>') +
          '<button class="btn btn-soft" type="button" id="gmCancel">' + (auto ? 'Batalkan pencarian' : 'Batalkan ruangan') + '</button></div>' +
      '</div>';
    const copy = async () => { try { await navigator.clipboard.writeText(rm.code); toast('Kode disalin'); } catch (e) { toast(rm.code); } };
    const cp = root.querySelector('#gmCopy'); if (cp) { cp.onclick = copy; root.querySelector('#gmCodeBox').onclick = copy; }
    root.querySelector('#gmCancel').onclick = () => act('leave');
    const nb = root.querySelector('#gmNotif'); if (nb) nb.onclick = askNotif;
    drawClock();
  }

  function head() {
    const r = st.round, s = st.score;
    return '<div class="card gm-bar">' +
      '<div class="gm-vs"><div class="gm-who">' + initial(st.me) + '<div><b>Kamu</b><small>' + esc(st.me.name) + '</small></div></div>' +
        '<span class="gm-x">' + '<img class="ico-img" src="' + (document.body.dataset.base || '').replace(/\/$/, '') + '/assets/img/mod2p.png" alt="">' + '</span>' +
        '<div class="gm-who">' + initial(st.opp) + '<div><b>' + esc(st.opp ? st.opp.name : '—') + '</b><small>' + liveBadge() + '</small></div></div></div>' +
      '<div class="gm-meta"><span class="tag tag-red">Ronde ' + (r ? r.n : st.room.round_no) + ' / ' + st.room.total + '</span>' +
        '<span class="tag" style="background:#f1f1f5;color:#555">Menang ' + s.wins + ' / ' + s.done + '</span>' +
        '<span class="tag tag-green">+' + s.xp + ' XP</span>' +
        '<span class="tag" style="background:#f1f1f5;color:#555" title="' + (st.room.visibility === 'private' ? 'Ruangan privat' : 'Ruangan publik') + '">' + (st.room.visibility === 'private' ? icn('lock') : icn('globe')) + '</span>' +
        '<button class="btn btn-soft gm-leave" type="button" id="gmLeave">' + icn('stop') + ' Akhiri match</button></div>' +
    '</div>';
  }
  const warnHtml = () => st.opp_online ? '' : '<div class="gm-warn">Lawan tidak terlihat aktif. Kalau terlalu lama, kamu bisa menekan <b>Akhiri match</b> untuk mengakhiri permainan.</div>';
  function bindLeave() { const b = root.querySelector('#gmLeave'); if (b) b.onclick = () => { if (confirm('Akhiri match sekarang? Permainan berakhir untuk kalian berdua dan hasil sementara tersimpan di riwayat.')) act('leave'); }; }

  /* ---------- Layar bermain (chat) ----------
     Kerangka dibuat sekali per ronde; tiap polling hanya bagian yang berubah yang diperbarui
     (daftar chat, giliran, kotak tebak) supaya isian yang sedang diketik tidak hilang. */
  let playKey = '', sub = {};
  function renderPlay() {
    const r = st.round;
    if (!r) { root.innerHTML = '<div id="gmHead">' + head() + '</div>'; playKey = ''; return bindLeave(); }
    const done = r.status === 'done';
    const key = r.n + '|' + (done ? 'done' : 'chat') + '|' + r.role;
    if (key !== playKey || !root.querySelector('#gmHead')) {
      playKey = key; sub = {};
      const guesserView = !done && r.role === 'guesser';
      root.innerHTML = '<div id="gmHead"></div><div id="gmWarn"></div>' + (done ? '<div id="gmReveal"></div>' :
        (guesserView ? '<div class="gm-playgrid">' : '') +
        '<div class="card gm-card gm-chatcard"><div id="gmRole"></div>' +
        '<div class="gm-chathead"><b>' + icn('chat') + ' Chat</b><span class="gm-timer" id="gmTimer"></span><span id="gmCounter"></span></div>' +
        '<div id="gmChat" class="gm-chatlist" aria-live="polite"></div><div id="gmTurn"></div></div>' +
        (guesserView ? '<div id="gmGuessBox" class="gm-side"></div></div>' : ''));
    }
    const headEl = root.querySelector('#gmHead'); headEl.innerHTML = head(); bindLeave();
    root.querySelector('#gmWarn').innerHTML = warnHtml();
    if (done) { clock = null; setNextClock(r); const el = root.querySelector('#gmReveal'); const k = JSON.stringify(r); if (sub.rev !== k) { sub.rev = k; el.innerHTML = reveal(r); const n = el.querySelector('#gmNext'); if (n) n.onclick = () => act('next'); } return; }
    nextClock = null;
    updateRole(r); updateChat(r); updateTurn(r); updateGuessBox(r); setClock(r);
  }

  const dots = () => '<div class="gm-dots"><i></i><i></i><i></i></div>';
  const bub = (m, who, mine) => '<div class="gm-bubble' + (mine ? ' mine' : '') + '"><small>' + esc(who) + (m.at ? ' · ' + esc(m.at) : '') + '</small><div>' + nl(m.body) + '</div></div>';
  const skipLine = (m, who) => '<div class="gm-skip">' + icn('stopwatch') + ' ' + (m.mine ? 'Kamu kehabisan waktu' : esc(who) + ' kehabisan waktu') + ', giliran dilewati.</div>';
  const transcript = (msgs, oppName) => { const who = oppName || (st && st.opp ? st.opp.name : 'Lawan'); return msgs.map(m => m.skip ? skipLine(m, who) : bub(m, m.mine ? 'Kamu' : who, m.mine)).join(''); };

  function updateRole(r) {
    const k = r.role + '|' + (r.messages.length > 0) + '|' + (r.my_category || '');
    if (sub.role === k) return; sub.role = k;
    const el = root.querySelector('#gmRole'); let h = '';
    if (r.role === 'sender') {
      const compact = r.messages.length > 0;
      h = r.is_scam
        ? '<div class="gm-role scam' + (compact ? ' compact' : '') + '"><span>' + icn('masks') + '</span><div><b>Perananmu: PENIPU' + (r.my_category ? ' · ' + esc(r.my_category) : '') + '</b>' + (compact ? '' : '<small>Rahasia! Lawan tidak tahu. Pilih satu kategori, lalu buka chat dengan pesan penipuan yang meyakinkan. Kamu punya <b>3 pesan</b>, lawan <b>2 balasan</b>. Gunakan untuk memancing korban.</small>') + '</div></div>'
        : '<div class="gm-role safe' + (compact ? ' compact' : '') + '"><span>' + icn('circle-check') + '</span><div><b>Perananmu: BUKAN PENIPU</b>' + (compact ? '' : '<small>Rahasia! Kamu bebas berperan jadi siapa saja (kurir sungguhan, bank, teman, toko, kantor…). Buka chat dengan pesan <b>asli</b> sealami mungkin dan jawab pertanyaan lawan dengan wajar. Kamu punya <b>3 pesan</b>, lawan <b>2 balasan</b>.</small>') + '</div></div>';
    } else {
      h = '<div class="gm-role guess compact"><span>' + icn('search') + '</span><div><b>Perananmu: PENEBAK</b><small>Kamu punya <b>2 balasan</b>. Ajukan pertanyaan untuk menguji lawan, dan tebak kapan saja setelah ada pesan: <b>penipuan</b> atau <b>bukan</b>.</small></div></div>';
    }
    el.innerHTML = h;
  }

  function updateChat(r) {
    const k = JSON.stringify(r.messages) + '|' + r.turn;
    if (sub.chat === k) { const c = root.querySelector('#gmCounter'); if (c) c.textContent = r.messages.length + ' / ' + r.chat_total + ' pesan'; return; }
    sub.chat = k;
    const el = root.querySelector('#gmChat');
    const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 80 || !el.children.length;
    let h = transcript(r.messages);
    if (!r.messages.length && r.role === 'guesser') h = '<div class="gm-empty-chat">Menunggu ' + esc(st.opp.name) + ' membuka chat…</div>';
    if (!r.messages.length && r.role === 'sender') h = '<div class="gm-empty-chat">Belum ada pesan. Kamu yang membuka chat.</div>';
    if (r.turn === 'opp') h += '<div class="gm-typing"><span>' + esc(st.opp.name) + ' sedang menulis</span>' + dots() + '</div>';
    el.innerHTML = h;
    if (nearBottom) el.scrollTop = el.scrollHeight;
    root.querySelector('#gmCounter').textContent = r.messages.length + ' / ' + r.chat_total + ' pesan';
  }

  function updateTurn(r) {
    const first = r.messages.length === 0;
    const k = r.turn + '|' + r.left.me + '|' + first;
    if (sub.turn === k) return; sub.turn = k;
    const el = root.querySelector('#gmTurn');
    const cc = root.querySelector('.gm-chatcard'); if (cc) cc.classList.toggle('opening', first && r.role === 'sender' && r.turn === 'me');
    if (r.turn === 'me') {
      if (first && r.role === 'sender') {
        const cats = r.is_scam ? '<h4 class="gm-sub">1. Pilih kategori penipuan</h4><div class="gm-cats">' + r.options.map(o =>
          '<label class="gm-cat' + (draft.cat === o.key ? ' on' : '') + '"><input type="radio" name="cat" value="' + esc(o.key) + '"' + (draft.cat === o.key ? ' checked' : '') + '><b>' + esc(o.name) + '</b><small>' + esc(o.hint) + '</small></label>').join('') + '</div>' : '';
        el.innerHTML = cats + '<h4 class="gm-sub">' + (r.is_scam ? '2. ' : '') + 'Pesan pembukamu</h4>' +
          '<textarea class="textarea" id="gmMsg" maxlength="' + CFG.max + '" placeholder="' + (r.is_scam ? 'Buka chat seolah-olah kamu sedang menghubungi korban…' : 'Buka chat dengan pesan asli, misalnya info paket, pesan dari teman, notifikasi bank…') + '" style="min-height:110px">' + esc(draft.msg) + '</textarea>' +
          '<div class="gm-foot"><small id="gmCount">0 / ' + CFG.max + '</small><button class="btn btn-primary" id="gmSend" type="button">Kirim ' + icn('send') + '</button></div>';
      } else {
        el.innerHTML = '<div class="gm-composer"><textarea class="textarea" id="gmMsg" maxlength="' + CFG.reply + '" rows="2" placeholder="' + (r.role === 'guesser' ? 'Balas atau ajukan pertanyaan ke ' + esc(st.opp.name) + '…' : 'Balas ' + esc(st.opp.name) + ' dengan wajar…') + '">' + esc(draft.msg) + '</textarea>' +
          '<button class="btn btn-primary" id="gmSend" type="button" aria-label="Kirim">' + icn('send') + '</button></div>' +
          '<div class="gm-foot"><small>Jatah pesanmu: <b>' + r.left.me + '</b> lagi · Enter untuk kirim, Shift+Enter baris baru</small><small id="gmCount"></small></div>';
      }
      const msg = el.querySelector('#gmMsg'), count = el.querySelector('#gmCount'), lim = (first && r.role === 'sender') ? CFG.max : CFG.reply;
      const upd = () => { draft.msg = msg.value; if (count) count.textContent = msg.value.length + ' / ' + lim; };
      msg.addEventListener('input', upd); upd();
      el.querySelectorAll('input[name=cat]').forEach(i => i.addEventListener('change', () => { draft.cat = i.value; el.querySelectorAll('.gm-cat').forEach(l => l.classList.toggle('on', l.contains(i) && i.checked)); }));
      const send = async () => {
        const text = msg.value.trim();
        if (r.is_scam && first && r.role === 'sender' && !draft.cat) return toast('Pilih salah satu kategori dulu.');
        if (!text) return toast('Tulis pesanmu dulu.');
        const res = await act('send', { message: msg.value, category: draft.cat });
        if (res && res.ok) draft.msg = '';
      };
      el.querySelector('#gmSend').onclick = send;
      msg.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
      if (!first || r.role !== 'sender') setTimeout(() => msg.focus({ preventScroll: true }), 30);
    } else if (r.turn === 'opp') {
      el.innerHTML = '<div class="gm-wait">' + icn('hourglass') + ' Menunggu <b>' + esc(st.opp.name) + '</b> menulis · jatah pesanmu: <b>' + r.left.me + '</b> lagi</div>';
    } else {
      el.innerHTML = r.role === 'guesser'
        ? '<div class="gm-wait strong">' + icn('bell') + ' Jatah chat habis. Saatnya <b>menebak</b> di kotak di bawah.</div>'
        : '<div class="gm-wait">' + icn('envelope') + ' Chat selesai. Menunggu <b>' + esc(st.opp.name) + '</b> menebak…</div>';
    }
  }

  function updateGuessBox(r) {
    const el = root.querySelector('#gmGuessBox'); if (!el) return;
    const k = String(r.can_guess) + '|' + (r.turn === 'guess');
    if (sub.guess === k) return; sub.guess = k;
    if (!r.can_guess) { el.innerHTML = '<div class="card gm-card gm-guessbox idle"><div class="gm-gh"><b>' + icn('search') + ' Tebakanmu</b><small>Kotak tebakan aktif setelah lawan mengirim pesan pertama.</small></div></div>'; return; }
    const oppN = esc(st.opp.name);
    el.innerHTML = '<div class="card gm-card gm-guessbox' + (r.turn === 'guess' ? ' urgent' : '') + '">' +
      '<div class="gm-gh"><b>' + icn('search') + ' Tebakanmu</b><small>' + (r.turn === 'guess' ? 'Chat habis. Pesan dari ' + oppN + ' ini penipuan atau bukan?' : 'Sudah yakin? Tebak sekarang, atau lanjut chat dulu untuk menguji lawan.') + ' Benar → kamu &amp; ' + oppN + ' sama-sama dapat XP.</small></div>' +
      '<div class="gm-guess">' +
        '<button type="button" class="gm-opt' + (draft.guess === 'scam' ? ' on bad' : '') + '" data-guess="scam"><span>' + icn('warn') + '</span><b>Penipuan</b><small>Ini scam</small></button>' +
        '<button type="button" class="gm-opt' + (draft.guess === 'safe' ? ' on good' : '') + '" data-guess="safe"><span>' + icn('check') + '</span><b>Bukan penipuan</b><small>Pesan asli</small></button>' +
      '</div>' +
      '<div class="gm-grow"><input class="field" id="gmReason" maxlength="300" placeholder="Alasanmu (opsional), mis. “linknya aneh”" value="' + esc(draft.reason) + '" autocomplete="off"><button class="btn btn-primary" id="gmGuess" type="button"' + (draft.guess ? '' : ' disabled') + '>Kunci tebakan</button></div></div>';
    el.querySelectorAll('[data-guess]').forEach(b => b.onclick = () => {
      draft.guess = b.dataset.guess;
      el.querySelectorAll('[data-guess]').forEach(x => { const on = x === b; x.classList.toggle('on', on); x.classList.toggle('bad', on && x.dataset.guess === 'scam'); x.classList.toggle('good', on && x.dataset.guess === 'safe'); });
      el.querySelector('#gmGuess').disabled = false;
    });
    const reason = el.querySelector('#gmReason'); reason.addEventListener('input', () => { draft.reason = reason.value; });
    el.querySelector('#gmGuess').onclick = () => {
      if (!draft.guess) return toast('Pilih tebakanmu dulu.');
      if (r.turn !== 'guess' && !confirm('Yakin menebak sekarang? Sisa chat tidak bisa dipakai lagi.')) return;
      act('guess', { guess: draft.guess, reason: draft.reason });
    };
  }

  /* Persetujuan lanjut: kedua pemain harus siap, atau otomatis lanjut saat hitung mundur habis. */
  function readyHtml(r) {
    const nx = r.next || { me_ready: false, opp_ready: false, secs: 20, deadline: null }, oppN = esc(st.opp ? st.opp.name : 'Lawan');
    const chip = (ok, who) => '<span class="gm-rd ' + (ok ? 'ok' : 'wait') + '">' + (ok ? icn('check') : icn('hourglass')) + ' ' + who + '</span>';
    const label = r.last ? 'Lihat hasil akhir →' : 'Ronde berikutnya →';
    return '<div class="gm-ready"><div class="gm-ready-who">' + chip(nx.me_ready, 'Kamu') + chip(nx.opp_ready, oppN) + '</div>' +
      '<div class="gm-ready-cd">' + (nx.deadline != null ? icn('stopwatch') + ' Lanjut otomatis dalam <b id="gmNextCd">' + fmt(nx.secs) + '</b>' : icn('hourglass') + ' Menunggu ada yang menekan siap · hitung mundur ' + nx.secs + ' dtk dimulai setelah itu') + '</div></div>' +
      '<div class="gm-foot"><small>' + (r.last ? 'Ini ronde terakhir. ' : 'Peran bertukar di ronde berikutnya. ') + 'Baca dulu transkripnya.</small>' +
      (nx.me_ready ? '<button class="btn btn-light" id="gmNext" type="button" disabled>' + icn('check') + ' Siap · menunggu ' + oppN + '</button>'
                   : '<button class="btn btn-primary" id="gmNext" type="button">' + (r.last ? 'Siap · lihat hasil akhir' : 'Siap lanjut') + ' →</button>') + '</div>';
  }

  function reveal(r) {
    const ok = r.correct, iSender = r.role === 'sender', to = r.timeout, oppN = esc(st.opp ? st.opp.name : 'Lawan');
    let icon, title, sub;
    if (to === 'open') { icon = icn('stopwatch'); title = 'Waktu habis, ronde dilewati'; sub = iSender ? 'Kamu tidak membuka chat tepat waktu. Tanpa XP untuk ronde ini.' : oppN + ' tidak membuka chat tepat waktu. Tanpa XP untuk ronde ini.'; }
    else if (to === 'guess') { icon = icn('stopwatch'); title = 'Waktu menebak habis'; sub = iSender ? oppN + ' tidak mengunci tebakan tepat waktu, jadi dianggap salah.' : 'Kamu tidak mengunci tebakan tepat waktu, jadi dianggap salah. Tanpa XP.'; }
    else { icon = ok ? icn('party') : icn('grimace'); title = ok ? 'Tebakan benar! Kalian berdua menang' : 'Tebakan meleset. Ronde ini tidak menang'; sub = ok ? (r.xp > 0 ? '+' + r.xp + ' XP untukmu' : 'Batas XP harian game tercapai, jadi kali ini tanpa XP') : 'Tidak ada XP. Coba lagi di ronde berikutnya!'; }
    const guess = r.guess === 'scam' ? icn('warn') + ' Penipuan' : r.guess === 'safe' ? icn('check') + ' Bukan penipuan' : icn('stopwatch') + ' Tidak menebak';
    return '<div class="card gm-card gm-result ' + (ok ? 'win' : 'lose') + '">' +
      '<div class="gm-banner"><span>' + icon + '</span><div><b>' + title + '</b><small>' + sub + '</small></div></div>' +
      '<div class="gm-chathead"><b>' + icn('chat') + ' Transkrip chat</b><span>' + r.messages.length + ' pesan</span></div>' +
      '<div class="gm-chatlist done">' + (r.messages.length ? transcript(r.messages) : '<div class="gm-empty-chat">Tidak ada chat di ronde ini.</div>') + '</div>' +
      '<div class="gm-facts">' +
        '<div><small>Sebenarnya</small><b class="' + (r.reveal_scam ? 'bad' : 'good') + '">' + (r.reveal_scam ? icn('warn') + ' PENIPUAN' : icn('check') + ' BUKAN PENIPUAN') + '</b>' + (r.category ? '<em>' + esc(r.category) + '</em>' : '') + '</div>' +
        '<div><small>Tebakan ' + (iSender ? oppN : 'kamu') + '</small><b>' + guess + '</b>' + (r.reason ? '<em>“' + esc(r.reason) + '”</em>' : '') + '</div>' +
      '</div>' +
      readyHtml(r) + '</div>';
  }

  function renderFinished() {
    const s = st.score, total = st.room.total;
    const why = st.room.reason === 'completed' ? '' :
      st.room.left_by_me ? '<div class="gm-warn">Kamu mengakhiri match ini. Hasilnya tersimpan di riwayat.</div>' :
      st.room.left_by_opp ? '<div class="gm-warn">' + esc(st.opp ? st.opp.name : 'Lawan') + ' mengakhiri match ini. Hasilnya tersimpan di riwayat.</div>' :
      st.room.reason === 'expired' ? '<div class="gm-warn">Permainan berakhir karena tidak ada aktivitas.</div>' : '';
    const pct = s.done ? Math.round(s.wins / s.done * 100) : 0;
    root.innerHTML = why +
      '<div class="card gm-card gm-center">' +
        '<div class="gm-trophy">' + (s.wins >= Math.ceil(total / 2) ? icn('trophy') : icn('target')) + '</div>' +
        '<h3>Permainan selesai</h3>' +
        (st.opp ? '<div class="sub">Kamu &amp; <b>' + esc(st.opp.name) + '</b></div>' : '') +
        '<div class="gm-stats"><div><b>' + s.wins + ' / ' + s.done + '</b><small>ronde menang</small></div><div><b>' + pct + '%</b><small>akurasi tim</small></div><div><b>+' + s.xp + '</b><small>XP didapat</small></div></div>' +
        (s.history.length ? '<div class="gm-hist">' + s.history.map(h =>
          '<div class="gm-h ' + (h.correct ? 'ok' : 'no') + '"><b>Ronde ' + h.n + '</b><span>' + (h.role === 'sender' ? 'Pengirim' : 'Penebak') + ' · ' + (h.is_scam ? 'Penipuan' : 'Asli') + (h.category ? ' (' + esc(h.category) + ')' : '') + '</span><i>' + (h.correct ? icn('check') + (h.xp ? ' +' + h.xp : '') : (h.timeout ? icn('stopwatch') : icn('xmark'))) + '</i></div>').join('') + '</div>' : '') +
        '<div class="gm-actions"><button class="btn btn-primary" type="button" id="gmAgain">Kembali ke lobi</button><button class="btn btn-light" type="button" id="gmHistBtn">' + icn('clipboard') + ' Lihat detail match</button></div>' +
      '</div>';
    root.querySelector('#gmAgain').onclick = async () => { lobbyTab = 'play'; histOpen = null; await act('ack'); };
    root.querySelector('#gmHistBtn').onclick = async () => { lobbyTab = 'history'; histOpen = null; hist = null; pendingMatch = (st.room && st.room.id) || 0; await act('ack'); };
  }


  /* ---------- Putaran undian di awal match: siapa jadi PENGIRIM duluan ----------
     Hasilnya sudah ditentukan server (first_sender_id); animasi ini hanya memperlihatkannya, jadi kedua layar sama.
     Peran rahasia (penipu/bukan) tetap hanya diketahui si pengirim dan tidak ditampilkan di sini. */
  const spun = {};
  let spinEl = null, spinTimers = [], spinRaf = 0;
  // Kunci unik per match (id + waktu dibuat) supaya id ruangan yang dipakai ulang tidak dianggap "sudah pernah spin".
  const spinKey = () => 'gmSpin' + st.room.id + '_' + st.room.created_ts;
  const spinSeen = () => { const k = spinKey(); if (spun[k]) return true; try { return sessionStorage.getItem(k) === '1'; } catch (e) { return false; } };
  const spinMark = () => { const k = spinKey(); spun[k] = true; try { sessionStorage.setItem(k, '1'); } catch (e) { /* abaikan */ } };
  const reduceMotion = () => document.body.classList.contains('reduce-motion') || (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
  function closeSpin() {
    spinTimers.forEach(clearTimeout); spinTimers = []; cancelAnimationFrame(spinRaf);
    if (!spinEl) return;
    const el = spinEl; spinEl = null;
    el.classList.add('out'); setTimeout(() => el.remove(), 260);
  }
  function maybeSpin() {
    const r = st && st.round;
    if (spinEl || !st || st.view !== 'play' || !r || r.n !== 1 || r.status === 'done' || r.messages.length || !st.opp) return;
    if (document.hidden) return;   // tab tidak aktif: tunggu sampai dibuka (poll dipanggil ulang saat tab kembali aktif)
    if (r.started_ts && Date.now() / 1000 + offset - r.started_ts > 90) return;   // match sudah berjalan lama: tidak perlu undian lagi
    if (spinSeen()) return;
    spinMark();
    const meSender = r.role === 'sender', oppName = st.opp.name;
    const lbl = (a, who, sm) => '<div class="gm-wl" style="--a:' + a + 'deg"><b>' + who + '</b><small>' + esc(sm) + '</small></div>';
    spinEl = document.createElement('div');
    spinEl.className = 'gm-spin'; spinEl.setAttribute('role', 'dialog'); spinEl.setAttribute('aria-label', 'Undian pengirim pertama');
    spinEl.innerHTML = '<div class="gm-spin-box">' +
      '<h3>' + icn('masks') + ' Siapa jadi pengirim duluan?</h3>' +
      '<div class="sub">Pengirim diam-diam mendapat peran <b>penipu</b> atau <b>bukan</b>. Cuma dia yang tahu…</div>' +
      '<div class="gm-wheelwrap"><div class="gm-pointer"></div><div class="gm-wheel" id="gmWheel">' +
        lbl(90, 'Kamu', st.me.name) + lbl(270, esc(oppName), '@' + st.opp.username) +
      '</div><div class="gm-hub">' + icn('masks') + '</div></div>' +
      '<div class="gm-spin-res" id="gmSpinRes">Memutar…</div>' +
      '<button class="btn btn-light" type="button" id="gmSpinSkip">Lewati</button></div>';
    document.body.appendChild(spinEl);
    const wheel = spinEl.querySelector('#gmWheel'), res = spinEl.querySelector('#gmSpinRes');
    spinEl.querySelector('#gmSpinSkip').onclick = closeSpin;
    // Segmen "Kamu" di 0-180° (pusat 90°), segmen lawan di 180-360° (pusat 270°); jarum di atas (0°).
    const center = meSender ? 90 : 270, jitter = (Math.random() - 0.5) * 90;
    const final = 360 * 5 - (center + jitter);
    const quick = reduceMotion();
    const land = () => {
      spinEl && spinEl.classList.add('landed');
      res.innerHTML = meSender ? icn('masks') + ' <b>Kamu</b> jadi pengirim duluan!<small>Cek perananmu setelah ini. Pandai-pandai menyamar ' + icn('wink') + '</small>'
        : icn('search') + ' <b>' + esc(oppName) + '</b> jadi pengirim duluan!<small>Kamu jadi penebak. Waspadai pesannya!</small>';
      spinTimers.push(setTimeout(closeSpin, 2400));
    };
    // Diputar lewat JS (bukan CSS transition) supaya tetap berputar walau pengaturan "kurangi gerakan" menonaktifkan transisi CSS.
    // Bila "kurangi gerakan" aktif, putarannya dipersingkat (bukan dihilangkan).
    const dur = quick ? 2400 : 4400, t0 = performance.now() + 350;
    const frame = now => {
      if (!spinEl) return;
      const t = Math.min(1, Math.max(0, (now - t0) / dur));
      wheel.style.transform = 'rotate(' + (final * (1 - Math.pow(1 - t, 4))) + 'deg)';
      if (t < 1) spinRaf = requestAnimationFrame(frame); else land();
    };
    spinRaf = requestAnimationFrame(frame);
  }

  /* ---------- Hitung mundur giliran ---------- */
  const fmt = sec => { sec = Math.max(0, Math.ceil(sec)); return Math.floor(sec / 60) + ':' + String(sec % 60).padStart(2, '0'); };
  function setClock(r) {
    if (r.deadline == null) { clock = null; return drawClock(); }
    const key = r.n + '|' + r.deadline + '|' + r.timer_kind;
    if (!clock || clock.key !== key) clock = { key: key, deadline: r.deadline, kind: r.timer_kind, turn: r.turn, role: r.role, fired: false, warned: false };
    else { clock.turn = r.turn; clock.role = r.role; }
    drawClock();
  }
  function setNextClock(r) {
    if (!r.next || r.next.deadline == null) { nextClock = null; return; }   // belum ada yang siap: tanpa hitung mundur
    const key = r.n + '|' + r.next.deadline;
    if (!nextClock || nextClock.key !== key) nextClock = { key: key, deadline: r.next.deadline, fired: false };
  }
  function drawClock() {
    const nc = document.getElementById('gmNextCd');
    if (nc && nextClock && st && st.view === 'play') {
      const left = nextClock.deadline - (Date.now() / 1000 + offset);
      nc.textContent = fmt(left);
      if (left <= 0 && !nextClock.fired && !document.hidden) { nextClock.fired = true; setTimeout(() => poll(true), 700); }   // server menerapkan lanjut otomatis
    }
    // lama menunggu saat mencari lawan
    const w = document.getElementById('gmWaited');
    if (w && st && st.view === 'waiting' && st.room) {
      const el = Math.max(0, Math.floor(Date.now() / 1000 + offset - st.room.created_ts));
      w.textContent = el < 60 ? el + ' detik' : Math.floor(el / 60) + ' mnt ' + String(el % 60).padStart(2, '0') + ' dtk';
      const h = document.getElementById('gmWaitHint'); if (h) h.classList.toggle('hidden', el < 45);
    }
    const t = document.getElementById('gmTimer');
    if (!t) return;
    if (!clock || !st || st.view !== 'play') { t.textContent = ''; t.className = 'gm-timer'; return; }
    const left = clock.deadline - (Date.now() / 1000 + offset);
    const mine = (clock.kind === 'open' && clock.role === 'sender') || (clock.kind === 'guess' && clock.role === 'guesser') || (clock.kind === 'reply' && clock.turn === 'me');
    const label = clock.kind === 'open' ? (clock.role === 'sender' ? 'Buka chat dalam' : 'Menunggu pembukaan')
      : clock.kind === 'guess' ? (clock.role === 'guesser' ? 'Kunci tebakan dalam' : 'Lawan menebak')
      : (clock.turn === 'me' ? 'Giliranmu' : 'Giliran lawan');
    t.innerHTML = icn('stopwatch') + ' ' + label + ' · ' + fmt(left);
    t.className = 'gm-timer' + (mine ? ' mine' : '') + (left <= 10 ? ' low' : '');
    if (mine && left > 0 && left <= 12 && !clock.warned) { clock.warned = true; notify('Waktu hampir habis!', 'Sisa ' + Math.ceil(left) + ' detik untuk giliranmu.', 'time'); }
    if (left <= 0 && !clock.fired && !document.hidden) { clock.fired = true; setTimeout(() => poll(true), 700); }   // minta server menerapkan batas waktu
  }
  setInterval(drawClock, 500);

  poll(true);
})();