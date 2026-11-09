</main>
<?php if (!$bare && !$me): /* kolom kanan untuk tamu */
    $hot = top_hashtags(4, 1);
    ?>
<aside class="right-col">
  <div class="card side-card"><div class="side-title"><?= icon('trend') ?> Topik hangat</div>
    <?php foreach ($hot as $i => $h): ?>
      <div class="trend"><div class="trend-n"><?= sprintf('%02d', $i + 1) ?></div><div><strong>#<?= e($h['tag']) ?></strong><small><?= num_short($h['c']) ?> laporan & diskusi</small></div></div>
    <?php endforeach;
    if (!$hot): ?><div class="sub">Belum ada topik hari ini.</div><?php endif; ?></div>
  <div class="card side-card"><div class="side-title"><?= icon('lightbulb') ?> Tips keamanan</div><div class="notice">Jangan pernah membagikan OTP atau PIN. Periksa alamat situs dengan teliti dan verifikasi informasi melalui kanal resmi.</div></div>
  <div style="font-size:10px;line-height:1.9;color:#a1a2aa;padding:0 5px">ITIHIX adalah platform edukasi dan laporan komunitas, bukan pengganti laporan resmi ke pihak berwenang.</div>
</aside>
<?php endif; ?>
<?php if (!$bare && $me):
    $li = level_info((int)$me['xp']);
    $hot = top_hashtags(4, 1);
    $uid = (int)$me['id'];
    $mQuiz = (int)qval('SELECT COUNT(*) FROM quiz_attempts WHERE user_id=? AND DATE(created_at)=CURDATE()', [$uid]);
    $mGame = (int)qval("SELECT COUNT(*) FROM game_rounds WHERE (sender_id=? OR guesser_id=?) AND status='done' AND DATE(updated_at)=CURDATE()", [$uid, $uid]);
    $mRep  = (int)qval('SELECT COUNT(*) FROM posts WHERE user_id=? AND DATE(created_at)=CURDATE() AND deleted_at IS NULL', [$uid]);
    $missionDone = $mQuiz >= 5 && $mGame >= 1 && $mRep >= 1;
    if ($missionDone) {
        try {
            if (claim_daily_mission_xp($uid)) {
                $li = level_info((int)qval('SELECT xp FROM users WHERE id = ?', [$uid]));
            }
        } catch (Throwable $e) {
            error_log('misi: ' . $e->getMessage());
        }
    }
    ?>
<aside class="right-col">
  <a class="level-card level-card-link" href="<?= e(url('profile.php?tab=level')) ?>" title="Lihat info level &amp; gelar"><div class="level-top"><span style="font-size:11px;font-weight:750">LEVEL PENJAGA</span><span class="level-tag"><?= icon('check') ?> AKTIF</span></div>
    <div class="level-num">Lv. <?= $li['level'] ?> <span style="font-size:12px;font-weight:600">/ <?= MAX_LEVEL ?></span></div>
    <div class="progress"><span style="width:<?= $li['pct'] ?>%"></span></div>
    <p><?= $li['need'] ? number_format($li['cur'], 0, ',', '.') . ' / ' . number_format($li['need'], 0, ',', '.') . ' XP menuju Level ' . ($li['level'] + 1) : 'Level maksimal tercapai!' ?></p>
    <span class="level-more">Lihat info level &amp; gelar →</span></a>
  <div class="card side-card"><div class="side-title"><?= icon('trend') ?> Topik hangat <a href="<?= e(url('explore.php')) ?>">Lihat semua</a></div>
    <?php foreach ($hot as $i => $h): ?>
      <a class="trend" href="<?= e(url('explore.php?tag=' . urlencode($h['tag']))) ?>" style="text-decoration:none;color:inherit"><div class="trend-n"><?= sprintf('%02d', $i + 1) ?></div><div><strong>#<?= e($h['tag']) ?></strong><small><?= num_short($h['c']) ?> laporan & diskusi</small></div></a>
    <?php endforeach;
    if (!$hot): ?><div class="sub">Belum ada topik hari ini.</div><?php endif; ?></div>
  <div class="card side-card"><div class="side-title"><?= icon('target') ?> Misi hari ini <span class="tag tag-red">+<?= XP_DAILY_MISSION ?> XP</span></div>
    <div class="mission"><div class="mission-icon"><?= ico_img('sor') ?></div><div style="flex:1"><strong>Mainkan kuis keamanan</strong><small><?= min($mQuiz, 5) ?> dari 5 soal · +10 XP/benar</small><div class="mini-progress"><span style="width:<?= min(100, $mQuiz * 20) ?>%"></span></div></div></div>
    <div class="mission"><div class="mission-icon"><?= ico_img('mod2p') ?></div><div style="flex:1"><strong>Mainkan 1 ronde Mask of Deception</strong><small><?= min($mGame, 1) ?> dari 1 · main berdua, tebak penipu</small><div class="mini-progress"><span style="width:<?= min(100, $mGame * 100) ?>%"></span></div></div></div>
    <div class="mission"><div class="mission-icon"><?= icon('plus') ?></div><div style="flex:1"><strong>Buat 1 laporan</strong><small><?= min($mRep, 1) ?> dari 1 · +5 XP (+20 jika disetujui)</small><div class="mini-progress"><span style="width:<?= min(100, $mRep * 100) ?>%"></span></div></div></div>
    <div class="sub" style="margin:6px 0 2px;font-size:11px"><?= $missionDone ? icon('check') . ' Hadiah ' . XP_DAILY_MISSION . ' XP dari Misi Harian sudah diterima.' : 'Selesaikan ketiga misi untuk mendapat +' . XP_DAILY_MISSION . ' XP.' ?> XP dari semua fitur dibatasi maks. <?= XP_DAILY_FEATURE_MAX ?>/hari (terpakai <?= min(XP_DAILY_FEATURE_MAX, xp_feature_today($uid)) ?>).</div>
    <a class="btn btn-soft" style="width:100%;margin-top:7px;text-decoration:none" href="<?= e(url('quiz.php')) ?>">Lanjutkan misi →</a></div>
  <div class="card side-card"><div class="side-title"><?= icon('lightbulb') ?> Tips keamanan</div><div class="notice">Jangan pernah membagikan OTP atau PIN. Periksa alamat situs dengan teliti dan verifikasi informasi melalui kanal resmi.</div></div>
  <div style="font-size:10px;line-height:1.9;color:#a1a2aa;padding:0 5px">ITIHIX adalah platform edukasi dan laporan komunitas, bukan pengganti laporan resmi ke pihak berwenang.</div>
</aside>
<?php endif; ?>
</div>
<?php if (!$bare && !$me): ?>
<nav class="mobile-nav">
  <a class="<?= $active === 'home' ? 'active' : '' ?>" href="<?= e(url('index.php')) ?>"><span><?= icon('home') ?></span>Beranda</a>
  <a href="<?= e(url('login.php')) ?>"><span><?= icon('login') ?></span>Masuk</a>
  <a href="<?= e(url('register.php')) ?>"><span><?= icon('plus') ?></span>Daftar</a>
</nav>
<div class="gate hidden" id="loginGate" role="dialog" aria-modal="true" aria-labelledby="gateTitle">
  <div class="gate-box card">
    <button class="gate-x" type="button" data-gate-close aria-label="Tutup"><?= icon('xmark') ?></button>
    <img class="gate-logo" src="<?= e(url('assets/img/logo.png')) ?>" alt="" width="42" height="42" style="width:42px;height:42px;max-width:42px;display:block;margin:0 auto">
    <h3 id="gateTitle">Masuk untuk melanjutkan</h3>
    <p class="sub" id="gateMsg">Kamu sedang melihat sebagai tamu. Masuk atau daftar untuk memakai fitur ini.</p>
    <div class="gate-actions">
      <a class="btn btn-primary" href="<?= e(url('login.php')) ?>">Masuk</a>
      <a class="btn btn-light" href="<?= e(url('register.php')) ?>">Daftar</a>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if (!$bare && $me): ?>
<section class="mobile-protection card" id="mobileProtection">
  <button class="protection-toggle" type="button" aria-expanded="false"><span class="protection-shield"><?= icon('shield') ?></span><span class="protection-copy"><strong>Perlindungan digital</strong><small>Deteksi pesan, konsultasi AI, dan latihan keamanan</small></span><span class="protection-chevron" id="protectionChevron"><?= icon('chev-down') ?></span></button>
  <div class="protection-tools hidden" id="protectionTools">
    <a class="protection-tool" href="<?= e(url('detect.php')) ?>"><span><?= ico_img('iTIHSCAN') ?></span><strong>ItihScan AI</strong><small>Periksa pesan mencurigakan</small><b>›</b></a>
    <a class="protection-tool" href="<?= e(url('chat.php')) ?>"><span><?= ico_img('iTISTANT_Ai') ?></span><strong>iTISTANT Ai</strong><small>Tanya cara tetap aman</small><b>›</b></a>
    <a class="protection-tool" href="<?= e(url('quiz.php')) ?>"><span><?= ico_img('sor') ?></span><strong>Scam or Real?</strong><small>Latih naluri keamananmu</small><b>›</b></a>
    <a class="protection-tool" href="<?= e(url('game.php')) ?>"><span><?= ico_img('mod2p') ?></span><strong>Mask of Deception 2P</strong><small>Main berdua, tebak penipu</small><b>›</b></a>
  </div>
</section>
<nav class="mobile-nav">
  <a class="<?= $active === 'home' ? 'active' : '' ?>" href="<?= e(url('index.php')) ?>"><span><?= icon('home') ?></span>Beranda</a>
  <a class="<?= $active === 'explore' ? 'active' : '' ?>" href="<?= e(url('explore.php')) ?>"><span><?= icon('compass') ?></span>Jelajahi</a>
  <a class="<?= $active === 'reels' ? 'active' : '' ?>" href="<?= e(url('safesnaps.php')) ?>"><span><?= icon('film') ?></span>SafeSnaps</a>
  <button type="button" data-mobile="protection" aria-expanded="false" onclick="ITIHIX.toggleProtection()"><span><?= icon('shield') ?></span>Lindungi</button>
  <a class="<?= $active === 'upload' ? 'active' : '' ?>" href="<?= e(url('upload.php')) ?>"><span><?= icon('plus') ?></span>Lapor</a>
  <a class="<?= $active === 'profile' ? 'active' : '' ?>" href="<?= e(url('profile.php')) ?>"><span><?= icon('user') ?></span>Profil</a>
</nav>
<?php endif; ?>
</div>
<?php if (!$bare && $me): ?>
<div class="gate hidden" id="reportModal" role="dialog" aria-modal="true" aria-labelledby="reportTitle">
  <form class="gate-box card report-box" id="reportForm">
    <button class="gate-x" type="button" data-report-close aria-label="Tutup"><?= icon('xmark') ?></button>
    <h3 id="reportTitle"><span id="reportKind">Laporkan</span> <span id="reportName"></span></h3>
    <p class="sub">Laporanmu dikirim ke moderator dan tidak ditampilkan ke pengguna yang dilaporkan.</p>
    <input type="hidden" name="user_id"><input type="hidden" name="post_id"><input type="hidden" name="comment_id">
    <div class="report-opts">
      <?php foreach (report_reasons() as $k => $t): ?><label class="report-opt"><input type="radio" name="reason" value="<?= e($k) ?>"> <?= e($t) ?></label><?php endforeach; ?>
    </div>
    <textarea class="textarea" name="detail" maxlength="500" placeholder="Jelaskan singkat (opsional)" style="min-height:70px"></textarea>
    <div class="gate-actions"><button class="btn btn-light" type="button" data-report-close>Batal</button><button class="btn btn-primary" type="submit">Kirim laporan</button></div>
  </form>
</div>
<?php endif; ?>
<div id="toast" class="toast"<?= !empty($flash) ? ' data-flash="' . e($flash['m']) . '"' : '' ?>></div>
<script src="<?= e(url('assets/js/icons.js')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/icons.js') ?>"></script>
<script src="<?= e(url('assets/js/photo-editor.js')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/photo-editor.js') ?>"></script>
<script src="<?= e(url('assets/js/app.js')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
<?php if (!empty($extraJs)) {
    echo $extraJs;
} ?>
</body>
</html>