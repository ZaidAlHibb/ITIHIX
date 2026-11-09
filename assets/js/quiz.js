/* ITIHIX: Kuis Asli / Palsu. Dimuat setelah app.js; memakai helper bersama dari window.ITIHIX. */
(function () {
  const { $, $$, body, esc, toast, api } = window.ITIHIX;
  /* ---- Kuis Asli / Palsu (endless) ---- */
  const quiz = $('#quizApp');
  if (quiz) {
    // gid = id permainan; naik setiap Mulai/Akhiri supaya hasil fetch lama (yang telat datang) diabaikan.
    const state = { q: null, answered: true, queue: [], n: 0, fetching: null, started: false, loading: false, gid: 0, playing: false, fresh: true, s: null, groups: [], open: {}, curSid: null, view: 'idle', reviewFrom: null, reviewKey: null };
    const ui = {
      idle: $('#quizIdle'), body: $('#quizBody'), result: $('#quizResult'), review: $('#quizReview'), start: $('#startQuiz'), end: $('#endQuiz'),
      chat: $('#quizChat'), count: $('#quizCount'), fb: $('#quizFeedback'), next: $('#nextQuiz'), opts: $('#quizOptions'), ask: $('#quizAsk'),
      streak: $('#qsStreak'), best: $('#qsBest'), today: $('#qsToday'), total: $('#quizTotal'), quota: $('#qsQuota'), quotaNote: $('#qzQuotaNote'),
      hist: $('#qzHist'), histList: $('#quizHistList'), histToggle: $('#qzHistToggle'), histClose: $('#qzHistClose'),
      rvChat: $('#rvChat'), rvFb: $('#rvFeedback'), rvBack: $('#rvBack'),
    };
    const newSession = () => ({ ok: 0, bad: 0, xp: 0, run: 0, bestRun: 0, t0: Date.now() });

    /* ---- Riwayat (kiri, bisa ditutup) ---- */
    const mobileMQ = window.matchMedia('(max-width:760px)');
    function setHist(open, save) {
      quiz.classList.toggle('hist-closed', !open);
      ui.histToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (save) { try { localStorage.setItem('itihix-qz-hist', open ? '1' : '0'); } catch (e) {} }
    }
    (function () {
      let v = null; try { v = localStorage.getItem('itihix-qz-hist'); } catch (e) {}
      setHist(v === null ? !mobileMQ.matches : v === '1', false);   // di HP awalnya tertutup
    })();
    ui.histToggle.addEventListener('click', () => setHist(quiz.classList.contains('hist-closed'), true));
    ui.histClose.addEventListener('click', () => setHist(false, true));

    function paintStats(s) {
      if (!s) return;
      ui.streak.textContent = s.streak;
      ui.best.textContent = s.best;
      ui.today.textContent = s.today_ok + ' / ' + s.today_bad;
      ui.total.textContent = 'Total: ' + s.total_ok + ' benar · ' + s.total_bad + ' salah';
      paintQuota(s.quota);
    }
    // Kuota harian (maks. 50 soal/hari): tampilkan sisa, dan kunci tombol Mulai bila habis. Soal yang belum terjawab dilanjutkan di hari berikutnya.
    function paintQuota(qt) {
      if (!qt) return;
      state.quota = qt;
      const out = qt.left <= 0;
      if (ui.quota) ui.quota.textContent = qt.left + ' / ' + qt.limit;
      if (ui.quotaNote) ui.quotaNote.textContent = out
        ? 'Kuota ' + qt.limit + ' soal hari ini sudah habis. Tunggu server reset besok' + (qt.pending ? ' — ' + qt.pending + ' soal yang belum terjawab akan dilanjutkan.' : '.')
        : (qt.pending ? qt.pending + ' soal belum terjawab akan dilanjutkan lebih dulu.' : '');
      ui.start.disabled = out;
      const again = $('#againQuiz'); if (again) again.disabled = out;
    }
    const histTime = it => {
      const d = it.ts ? new Date(it.ts) : new Date(String(it.at || '').replace(' ', 'T'));
      return isNaN(d) ? '' : d.toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    };
    // Riwayat berbentuk dropdown (seperti daftar chat AI): satu sesi main = satu grup yang bisa dibuka/ditutup, isinya soal-soal sesi itu.
    function renderHist() {
      if (!state.groups.length) { ui.histList.innerHTML = '<p class="sub" style="padding:12px">Belum ada riwayat. Mainkan satu game, lalu tinjau jawabanmu di sini untuk belajar.</p>'; return; }
      ui.histList.innerHTML = state.groups.map(g => {
        const open = !!state.open[g.sid], n = g.items.length, ok = g.items.filter(x => x.correct).length, acc = n ? Math.round(ok / n * 100) : 0;
        const live = state.playing && g.sid === state.curSid;
        return '<div class="qz-grp' + (open ? ' open' : '') + '">' +
          '<button type="button" class="qz-gh" data-g="' + esc(g.sid) + '" aria-expanded="' + open + '"><span class="qz-chev">' + icn('caret-right') + '</span>' +
          '<span class="qz-gt"><b>' + (live ? icn('dot') + ' Sedang main' : esc(histTime(g) || 'Sesi')) + '</b><small>' + n + ' soal · ' + ok + ' benar</small></span>' +
          '<span class="qz-acc ' + (acc >= 80 ? 'hi' : acc >= 50 ? 'mid' : 'lo') + '">' + acc + '%</span></button>' +
          (open ? '<div class="qz-gi">' + g.items.map((it, i) =>
            '<button type="button" class="qz-hi' + (state.view === 'review' && state.reviewKey === g.sid + ':' + it.id ? ' active' : '') + '" data-g="' + esc(g.sid) + '" data-i="' + i + '">' +
            '<span class="qz-dot ' + (it.correct ? 'ok' : 'no') + '" title="' + (it.correct ? 'Benar' : 'Salah') + '">' + (it.correct ? icn('check') : icn('xmark')) + '</span>' +
            '<span class="qz-hi-t"><b>' + esc(it.sender) + '</b><small>' + esc(it.topic || '') + '</small></span>' +
            '<span class="qz-hi-k ' + (it.is_scam ? 'scam' : 'safe') + '">' + (it.is_scam ? 'Penipuan' : 'Asli') + '</span></button>').join('') + '</div>' : '') +
          '</div>';
      }).join('');
    }
    async function loadHistory() {
      const r = await api('quiz.php?action=history', null, false, true);
      if (r && r.ok) {
        state.groups = r.groups || []; paintStats(r.stats);
        if (!Object.keys(state.open).length && state.groups[0]) state.open[state.groups[0].sid] = true;   // sesi terbaru terbuka
        renderHist();
      } else if (!state.groups.length) renderHist();
    }
    ui.histList.addEventListener('click', e => {
      const h = e.target.closest('.qz-gh');
      if (h) { state.open[h.dataset.g] = !state.open[h.dataset.g]; renderHist(); return; }
      const b = e.target.closest('.qz-hi'); if (!b) return;
      const g = state.groups.find(x => x.sid === b.dataset.g); const it = g && g.items[+b.dataset.i];
      if (!it) return;
      openReview(it, g.sid);
      if (mobileMQ.matches) setHist(false, false);
    });
    $('#qzNew').addEventListener('click', () => { startGame(); if (mobileMQ.matches) setHist(false, false); });

    /* ---- Chat + highlight penentu ---- */
    // Cocokkan potongan highlight ke teks pesan; hanya yang benar-benar ada yang dipakai, diberi nomor berurutan.
    // Pesan yang isinya hanya nama file (mis. "Undangan.apk") dijadikan kartu file, jaga-jaga bila AI menulisnya sebagai teks biasa.
    const FILE_RE = /^[^\p{L}\p{N}]{0,4}\s*(?:(?:file|lampiran|dokumen)\s*:\s*)?([\p{L}\p{N}_.\-() ]{1,60}\.(?:apk|xapk|exe|zip|rar|pdf|docx?|xlsx?|pptx?|jpe?g|png|mp4|mp3))\s*$/iu;
    function fixMsgs(list) {
      return (list || []).map(m => {
        if (!m || m.file) return m;
        const mm = FILE_RE.exec(String(m.text || '').trim()); if (!mm) return m;
        const name = mm[1].trim(); let h = 0; for (const c of name) h = (h * 31 + c.charCodeAt(0)) >>> 0;
        const ext = name.split('.').pop().toLowerCase(), big = ['apk', 'xapk', 'mp4', 'zip', 'rar', 'exe'].includes(ext);
        const kb = 200 + (h % 6000) + (big ? 3000 : 0);
        return { ...m, text: '', file: { name, size: kb >= 1000 ? (kb / 1000).toFixed(1).replace('.', ',') + ' MB' : kb + ' KB' } };
      });
    }
    function prepHl(messages, raw) {
      messages = fixMsgs(messages);
      const out = [];
      (raw || []).forEach(h => {
        const m = +h.m, t = String(h.t || ''), msg = messages[m];
        if (!t || !msg) return;
        const text = String(msg.text || ''), fname = msg.file ? String(msg.file.name || '') : '';
        let i = text.toLowerCase().indexOf(t.toLowerCase());
        if (i >= 0) { out.push({ m, s: i, e: i + t.length, w: h.w || '', f: false, t: text.slice(i, i + t.length) }); return; }
        i = fname ? fname.toLowerCase().indexOf(t.toLowerCase()) : -1;
        if (i >= 0) out.push({ m, s: i, e: i + t.length, w: h.w || '', f: true, t: fname.slice(i, i + t.length) });   // sorotan pada nama file
      });
      return out.map((h, k) => ({ ...h, n: k + 1 }));
    }
    function markText(text, idx, hls, kind, onFile) {
      const rs = hls.filter(h => h.m === idx && !!h.f === !!onFile).sort((a, b) => a.s - b.s);
      let out = '', pos = 0;
      rs.forEach(h => {
        if (h.s < pos) return;                                   // lewati yang tumpang tindih
        out += esc(text.slice(pos, h.s)) +
          '<mark class="hl hl-' + kind + '" title="' + esc(h.w) + '">' + esc(text.slice(h.s, h.e)) + '<sup>' + h.n + '</sup></mark>';
        pos = h.e;
      });
      return out + esc(text.slice(pos));
    }
    // Kartu file ala WhatsApp: ikon sesuai ekstensi, nama file, ukuran + jenis, dan tombol unduh.
    const FILE_ICONS = { apk: [icn('robot'), 'apk'], xapk: [icn('robot'), 'apk'], exe: [icn('gear'), 'exe'], zip: [icn('zip'), 'zip'], rar: [icn('zip'), 'zip'], pdf: [icn('pdf'), 'pdf'], doc: [icn('word'), 'doc'], docx: [icn('word'), 'doc'],
      xls: [icn('excel'), 'xls'], xlsx: [icn('excel'), 'xls'], ppt: [icn('ppt'), 'ppt'], pptx: [icn('ppt'), 'ppt'], jpg: [icn('image'), 'img'], jpeg: [icn('image'), 'img'], png: [icn('image'), 'img'], mp4: [icn('film'), 'vid'], mp3: [icn('music'), 'aud'] };
    function fileCard(file, idx, hls, kind) {
      const name = String(file.name || 'file'), ext = (name.split('.').pop() || '').toLowerCase();
      const ic = FILE_ICONS[ext] || [icn('paperclip'), 'oth'];
      const nameHtml = kind ? markText(name, idx, hls, kind, true) : esc(name);
      return '<div class="wa-file"><div class="wa-fi wa-' + ic[1] + '">' + ic[0] + '</div>' +
        '<div class="wa-fm"><div class="wa-fn">' + nameHtml + '</div><div class="wa-fs">' + esc(file.size || '') + ' · ' + esc(ext.toUpperCase()) + '</div></div>' +
        '<div class="wa-dl" aria-hidden="true">' + icn('download') + '</div></div>';
    }
    function chatHTML(q, hls, kind) {
      q = { ...q, messages: fixMsgs(q.messages) };
      return '<div class="chat-head"><div class="avatar">' + (q.saved ? icn('smile') : '?') + '</div><div><strong>' + esc(q.sender) + '</strong><div class="sub">' + esc(q.number) + ' · ' + (q.saved ? 'kontak tersimpan' : 'nomor tidak tersimpan') + '</div></div></div>' +
        '<div class="chat-body"><div class="qz-today">HARI INI</div>' +
        q.messages.map((m, i) => {
          const txt = String(m.text || '');
          return '<div class="bubble' + (m.file ? ' has-file' : '') + '">' + (m.file ? fileCard(m.file, i, hls, kind) : '') +
            (txt ? '<div class="bt">' + (kind ? markText(txt, i, hls, kind, false) : esc(txt)) + '</div>' : '') +
            '<small>' + esc(m.time) + '</small></div>';
        }).join('') + '</div>';
    }
    // Panel hasil: verdict + penjelasan + daftar penentu bernomor (warna merah = penipuan, hijau = aman).
    function resultHTML(d) {
      const kind = d.is_scam ? 'red' : 'green';
      const yours = d.user_answer ? icn('warn') + ' Penipuan' : icn('check') + ' Asli dan aman';
      const head = d.correct
        ? '<span class="qz-pill ok">' + icn('check') + ' Tepat!</span>' + (d.xp ? '<span class="qz-meta">+' + d.xp + ' XP' + (d.streak != null ? ' · streak ' + d.streak : '') + (d.new_best ? ' ' + icn('trophy') + ' Skor terbaik baru!' : '') + '</span>' : '')
        : '<span class="qz-pill no">' + icn('xmark') + ' Kurang tepat</span><span class="qz-meta">Streak kembali ke 0</span>';
      const why = d.hls.length
        ? '<div class="qz-legend"><span class="hl hl-' + kind + '">' + (d.is_scam ? 'Merah' : 'Hijau') + '</span> ' + (d.is_scam ? 'bagian yang menunjukkan penipuan' : 'bagian yang menunjukkan pesan aman') + '</div>' +
          '<ol class="qz-why">' + d.hls.map(h => '<li class="' + kind + '"><b>' + esc(h.t) + '</b>' + (h.w ? '<span>' + esc(h.w) + '</span>' : '') + '</li>').join('') + '</ol>'
        : '<ul class="qz-flags ' + kind + '">' + (d.red_flags || []).map(f => '<li>' + esc(f) + '</li>').join('') + '</ul>';
      return '<div class="qz-verdict">' + head + '</div>' +
        '<div class="qz-kind ' + (d.is_scam ? 'scam' : 'safe') + '">Pesan ini <b>' + (d.is_scam ? 'PENIPUAN' : 'ASLI & AMAN') + '</b><small>Jawabanmu: ' + yours + '</small></div>' +
        '<p class="qz-expl">' + esc(d.explanation) + '</p>' + why;
    }

    /* ---- Tampilan: idle / play / result / review ---- */
    function showView(v) {
      state.view = v;
      ui.idle.classList.toggle('hidden', v !== 'idle');
      ui.body.classList.toggle('hidden', v !== 'play');
      ui.result.classList.toggle('hidden', v !== 'result');
      ui.review.classList.toggle('hidden', v !== 'review');
      const live = state.playing;
      ui.end.classList.toggle('hidden', !live);
      ui.count.classList.toggle('hidden', !(live && v === 'play'));
      renderHist();
    }
    function openReview(it, sid) {
      if (state.view !== 'review') state.reviewFrom = state.view;
      state.reviewKey = sid + ':' + it.id;
      const hls = prepHl(it.messages, it.highlights);
      ui.rvChat.innerHTML = chatHTML(it, hls, it.is_scam ? 'red' : 'green');
      ui.rvFb.className = 'quiz-feedback ' + (it.correct ? 'ok' : 'no');
      ui.rvFb.innerHTML = resultHTML({ ...it, hls });
      showView('review');
    }
    ui.rvBack.addEventListener('click', () => { state.reviewKey = null; showView(state.reviewFrom || 'idle'); });

    function setOpts(disabled) { $$('button', ui.opts).forEach(b => { b.disabled = disabled; b.classList.remove('btn-primary'); b.classList.add('btn-light'); }); }
    function render(q, idx) {
      state.q = q; state.answered = false;
      ui.count.textContent = 'Soal ke-' + idx + ' sesi ini';
      ui.chat.innerHTML = chatHTML(q, [], null);
      ui.fb.classList.add('hidden'); ui.next.classList.add('hidden'); ui.ask.classList.remove('hidden');
      setOpts(false);
    }
    /* Minta 5 soal sekaligus. Hasilnya masuk antrean (state.queue); hanya satu permintaan berjalan pada satu waktu. */
    function fetchBatch() {
      if (state.fetching) return state.fetching;
      const gid = state.gid;
      const url = 'quiz.php?action=batch' + (state.fresh ? '&fresh=1' : '');
      state.fresh = false;
      const p = (async () => {
        let r = null;
        try { r = await api(url, null, false, true); } catch (e) { r = null; }
        if (state.fetching === p) state.fetching = null;
        if (gid !== state.gid || !state.playing) return null; // game sudah diakhiri / diulang: buang hasilnya
        if (r && r.ok && r.questions && r.questions.length) {
          if (!state.started) { state.started = true; state.n = (r.start_index || 1) - 1; paintStats(r.stats); }
          state.queue.push(...r.questions);
        } else if (r && !r.ok) { state.fresh = state.fresh || !state.started; if (r.stats) paintStats(r.stats); }
        return r;
      })();
      state.fetching = p;
      return p;
    }
    /* Tampilkan soal berikutnya dari antrean (instan). Bila antrean kosong, tunggu generate yang sedang berjalan / mulai yang baru. */
    async function showNext() {
      if (state.loading || !state.playing) return; state.loading = true;
      const gid = state.gid;
      try {
        if (!state.queue.length) {
          state.q = null; state.answered = true;
          ui.count.textContent = 'AI sedang membuat soal…';
          ui.fb.classList.add('hidden'); ui.next.classList.add('hidden');
          setOpts(true);
          const r = await fetchBatch();
          if (gid !== state.gid || !state.playing) return;
          if (!state.queue.length) {
            ui.count.textContent = (r && r.error) || 'Gagal memuat soal.';
            if (r && r.quota_exhausted) { ui.next.classList.add('hidden'); return; }   // kuota harian habis: tunggu reset, tidak perlu "Coba lagi"
            ui.next.textContent = 'Coba lagi'; ui.next.classList.remove('hidden');
            return;
          }
        }
        ui.next.textContent = 'Pertanyaan berikutnya →';
        render(state.queue.shift(), ++state.n);
        // Sisa 1 soal di antrean (= pengguna sedang di soal ke-4 dari 5): buat 5 soal berikutnya di latar belakang saat dia menjawab.
        if (state.queue.length <= 1 && !state.fetching) fetchBatch();
      } finally { state.loading = false; }
    }
    function startGame() {
      if (state.quota && state.quota.left <= 0) { toast('Kuota ' + state.quota.limit + ' soal hari ini sudah habis. Tunggu server reset besok.'); return; }
      state.gid++; state.playing = true; state.queue = []; state.n = 0; state.fetching = null; state.started = false;
      state.loading = false; state.q = null; state.answered = true; state.fresh = true; state.s = newSession(); state.reviewKey = null; state.curSid = null;
      ui.chat.innerHTML = ''; ui.fb.classList.add('hidden'); ui.next.classList.add('hidden'); ui.ask.classList.remove('hidden'); setOpts(true);
      ui.count.textContent = 'AI sedang membuat soal…';
      showView('play');
      showNext();
    }
    const fmtDur = ms => { const t = Math.max(0, Math.round(ms / 1000)), m = Math.floor(t / 60), d = t % 60; return m ? m + ' mnt ' + d + ' dtk' : d + ' dtk'; };
    function endGame() {
      if (!state.playing) return;
      const s = state.s; state.playing = false; state.gid++; state.queue = []; state.fetching = null; state.loading = false; state.q = null; state.reviewKey = null;
      const tot = s.ok + s.bad, acc = tot ? Math.round(s.ok / tot * 100) : 0;
      const grade = !tot ? '' : acc >= 80 ? 'Naluri digitalmu tajam! ' + icn('shield') : acc >= 50 ? 'Lumayan, terus asah kewaspadaanmu ' + icn('thumbs-up') : 'Hati-hati, yuk pelajari lagi ciri-ciri penipuan ' + icn('warn');
      const card = (l, v) => '<div class="card stat"><small>' + l + '</small><strong>' + v + '</strong></div>';
      ui.result.innerHTML = tot
        ? '<div style="text-align:center;margin-bottom:10px"><div class="eyebrow">Test selesai</div><h3 style="font-size:19px;margin:5px 0 4px">Statistik permainanmu</h3><div class="sub">' + grade + '</div></div>' +
          '<div class="stat-grid" style="margin-bottom:10px">' + card('Soal dijawab', tot) + card('Benar', s.ok) + card('Salah', s.bad) + '</div>' +
          '<div class="stat-grid">' + card('Akurasi', acc + '%') + card('Streak terpanjang', s.bestRun) + card('XP didapat', '+' + s.xp) + '</div>' +
          '<div class="risk-meter" style="margin:4px 0 6px"><span style="width:' + acc + '%;background:' + (acc >= 80 ? '#168765' : acc >= 50 ? '#d99a1f' : '#e53945') + '"></span></div>' +
          '<div class="sub" style="text-align:center">Durasi bermain: ' + fmtDur(Date.now() - s.t0) + ' · Buka <b>Riwayat</b> untuk meninjau jawabanmu.</div>'
        : '<div style="text-align:center"><div style="font-size:36px">' + icn('question') + '</div><h3 style="font-size:17px;margin:8px 0 4px">Belum ada soal yang dijawab</h3><div class="sub">Test diakhiri sebelum kamu menjawab soal apa pun.</div></div>';
      ui.result.innerHTML += '<div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:14px"><button type="button" class="btn btn-primary" id="againQuiz">' + icn('redo') + ' Main lagi</button></div>';
      showView('result');
      $('#againQuiz').addEventListener('click', startGame);
      if (state.quota) paintQuota(state.quota);
      loadHistory();
    }
    $$('button[data-answer]', ui.opts).forEach(b => b.addEventListener('click', async () => {
      if (!state.q || state.answered || !state.playing) return; state.answered = true;
      const gid = state.gid, q = state.q;
      setOpts(true);
      const r = await api('quiz.php', { action: 'answer', question_id: q.id, answer: b.dataset.answer });
      if (gid !== state.gid || !state.playing) { if (r && r.ok) { paintStats(r.stats); loadHistory(); } return; } // test diakhiri saat menunggu respons
      if (!r || !r.ok) { state.answered = false; setOpts(false); return; }
      const s = state.s;
      if (r.correct) { s.ok++; s.run++; s.xp += r.xp || 0; if (s.run > s.bestRun) s.bestRun = s.run; } else { s.bad++; s.run = 0; }
      paintStats(r.stats);
      const hls = prepHl(q.messages, r.highlights);
      const item = { id: q.id, correct: r.correct, is_scam: r.is_scam, user_answer: +b.dataset.answer, ts: Date.now(), sender: q.sender, number: q.number, saved: q.saved, topic: q.topic,
        messages: q.messages, explanation: r.explanation, red_flags: r.red_flags || [], highlights: r.highlights || [] };
      let grp = state.groups.find(x => x.sid === r.sid);
      if (!grp) { grp = { sid: r.sid, ts: Date.now(), items: [] }; state.groups.unshift(grp); }
      state.curSid = r.sid; state.open[r.sid] = true;
      grp.items = grp.items.filter(x => x.id !== q.id).concat(item);
      renderHist();
      // chat dirender ulang dengan highlight: merah = tanda penipuan, hijau = penanda pesan aman
      ui.chat.innerHTML = chatHTML(q, hls, r.is_scam ? 'red' : 'green');
      ui.ask.classList.add('hidden');
      ui.fb.className = 'quiz-feedback ' + (r.correct ? 'ok' : 'no');
      ui.fb.innerHTML = resultHTML({ ...item, hls, xp: r.xp, streak: r.stats.streak, new_best: r.new_best });
      if (r.stats && r.stats.quota && r.stats.quota.left <= 0) {
        // jawaban ke-50 hari ini: tidak ada soal berikutnya sampai server reset
        ui.next.classList.add('hidden');
        ui.fb.innerHTML += '<div class="qz-kind safe"><b>Kuota hari ini habis ' + icn('party') + '</b><small>Kamu sudah menjawab ' + r.stats.quota.limit + ' soal. Tunggu server reset besok' + (r.stats.quota.pending ? '; ' + r.stats.quota.pending + ' soal yang belum terjawab akan dilanjutkan.' : '.') + '</small></div>';
      } else {
        ui.next.textContent = 'Pertanyaan berikutnya →';
        ui.next.classList.remove('hidden');
      }
    }));
    ui.next.addEventListener('click', showNext);
    ui.start.addEventListener('click', startGame);
    ui.end.addEventListener('click', endGame);
    // Layar awal: tampilkan statistik + riwayat saja, TIDAK membuat soal sampai "Mulai Game" ditekan.
    showView('idle');
    loadHistory();
  }
})();
