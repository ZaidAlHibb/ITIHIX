<?php
$pageTitle = 'Scam or Real?';
$active = 'quiz';
require __DIR__ . '/includes/header.php';
?>
<section id="page-quiz" class="page">
  <div class="qz-head">
    <div class="qz-title">
      <h1>Scam or Real?</h1>
       <div class="eyebrow">Latihan naluri digital · Endless</div>
    </div>
    <div class="qz-stats" aria-label="Statistik">
      <div class="qz-chip"><small>Streak</small><strong id="qsStreak">–</strong></div>
      <div class="qz-chip"><small><?= icon('trophy') ?> Terbaik</small><strong id="qsBest">–</strong></div>
      <div class="qz-chip"><small>Hari ini <?= icon('check') ?> / <?= icon('xmark') ?></small><strong id="qsToday">–</strong></div>
      <div class="qz-chip"><small>Sisa kuota hari ini</small><strong id="qsQuota">–</strong></div>
      <span class="tag tag-red qz-xp">+10 XP / benar</span>
    </div>
  </div>

  <div class="qz-shell" id="quizApp">
    <!-- Riwayat (kiri, bisa ditutup) -->
    <aside class="qz-hist card" id="qzHist" aria-label="Riwayat jawaban">
      <div class="qz-hist-head">
        <div><strong>Riwayat game</strong><small id="quizTotal"></small></div>
        <button type="button" class="qz-x" id="qzHistClose" aria-label="Tutup riwayat" title="Tutup riwayat"><?= icon('xmark') ?></button>
      </div>
      <button type="button" class="btn btn-soft qz-new" id="qzNew"><?= icon('plus') ?> Game baru</button>
      <div class="qz-hist-list" id="quizHistList"><p class="sub" style="padding:12px">Memuat…</p></div>
    </aside>

    <!-- Panggung permainan -->
    <div class="qz-stage card">
      <div class="qz-bar">
        <button type="button" class="btn btn-light qz-toggle" id="qzHistToggle" aria-expanded="true"><?= icon('history') ?> Riwayat</button>
        <span class="sub qz-count hidden" id="quizCount"></span>
        <button type="button" id="endQuiz" class="btn btn-soft qz-end hidden"><?= icon('stop') ?> Akhiri Test</button>
      </div>

      <!-- 1) Layar awal -->
      <div id="quizIdle" class="qz-center">
        <div style="font-size:42px;line-height:1"><?= icon('gamepad') ?></div>
        <h3>Siap menguji naluri digitalmu?</h3>
        <div class="sub">Soal dibuat AI secara endless. Setelah menjawab, bagian chat yang menentukan asli atau penipuan akan di-<i>highlight</i>. Buka <b>Riwayat</b> untuk belajar lagi dari soal sebelumnya.</div>
        <div class="sub" id="qzQuotaNote" style="font-weight:600"></div>
        <button type="button" id="startQuiz" class="btn btn-primary"><?= icon('play') ?> Mulai Game</button>
      </div>

      <!-- 2) Permainan berlangsung -->
      <div id="quizBody" class="qz-play hidden">
        <div class="qz-chatcol"><div class="quiz-chat" id="quizChat"></div></div>
        <div class="qz-side">
          <div id="quizAsk">
            <span class="tag" style="background:#f1f1f5;color:#666">CHAT MENCURIGAKAN</span>
            <h3>Menurutmu, pesan ini...</h3>
            <div class="sub">Perhatikan tautan, file yang dikirim, permintaan data, dan tekanan waktu. Kontak tersimpan pun bisa dibajak.</div>
            <div class="quiz-options" id="quizOptions">
              <button class="btn btn-light" data-answer="0"><?= icon('check') ?> Asli dan aman</button>
              <button class="btn btn-light" data-answer="1"><?= icon('warn') ?> Penipuan</button>
            </div>
          </div>
          <div id="quizFeedback" class="hidden quiz-feedback"></div>
          <button id="nextQuiz" class="btn btn-primary hidden">Pertanyaan berikutnya →</button>
        </div>
      </div>

      <!-- 3) Tinjau soal dari riwayat -->
      <div id="quizReview" class="qz-play hidden">
        <div class="qz-chatcol"><div class="quiz-chat" id="rvChat"></div></div>
        <div class="qz-side">
          <div id="rvFeedback" class="quiz-feedback"></div>
          <button type="button" id="rvBack" class="btn btn-primary">← Kembali</button>
        </div>
      </div>

      <!-- 4) Statistik setelah test diakhiri -->
      <div id="quizResult" class="qz-center hidden"></div>
    </div>
  </div>
</section>
<?php $extraJs = '<script src="' . e(url('assets/js/quiz.js')) . '?v=' . (int)@filemtime(__DIR__ . '/assets/js/quiz.js') . '"></script>';
require __DIR__ . '/includes/footer.php'; ?>