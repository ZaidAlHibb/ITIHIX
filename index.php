<?php
$pageTitle = 'Beranda';
$active = 'home';
$guest = true;
require __DIR__ . '/includes/header.php';

$tab = $_GET['tab'] ?? 'rekomendasi';
if (!in_array($tab, ['rekomendasi', 'terbaru', 'mengikuti'], true) || ($tab === 'mengikuti' && !$me)) {
    $tab = 'rekomendasi';
}
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 10;

$where = 'p.status = "approved" AND p.type <> "short"';
$order = 'p.created_at DESC';
// Tab Mengikuti: hanya laporan dari akun yang diikuti pemirsa (:uid diisi fetch_posts)
if ($tab === 'mengikuti') {
    $where .= ' AND p.user_id IN (SELECT followee_id FROM follows WHERE follower_id = :uid)';
}
if ($tab === 'rekomendasi') {
    $order = '((SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) + 2*(SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id AND c.deleted_at IS NULL) + 3*(SELECT COUNT(*) FROM reposts r WHERE r.post_id = p.id)) / POW(TIMESTAMPDIFF(HOUR, p.created_at, NOW()) + 2, 1.2) DESC, p.created_at DESC';
}
$posts = fetch_posts(['where' => $where, 'order' => $order, 'limit' => $per + 1, 'offset' => ($page - 1) * $per]);
$more = count($posts) > $per;
$posts = array_slice($posts, 0, $per);
$first = $me ? explode(' ', $me['display_name'])[0] : '';
// Deretan SafeSnaps: hanya video yang diunggah lewat SafeSnaps (type "short"), sama dengan halaman SafeSnaps; terbaru dulu
$shorts = $page === 1 ? fetch_posts([
    'where' => 'p.status = "approved" AND p.type = "short" AND p.video_path IS NOT NULL AND p.video_path <> ""',
    'order' => 'p.created_at DESC',
    'limit' => 10,
]) : [];
?>
<section id="page-home" class="page">
  <?php if ($me): ?>
  <div class="page-heading"><div><h1>Selamat datang kembali, <?= e($first) ?>!</h1><div class="sub">Satu laporan bisa melindungi ribuan orang.</div></div>
    <a class="btn btn-primary" href="<?= e(url('upload.php')) ?>"><?= icon('plus') ?> <span>Buat laporan</span></a></div>
  <div class="feed-composer"><?= avatar_html($me) ?><a class="composer-input" href="<?= e(url('upload.php')) ?>" style="text-decoration:none">Menemukan sesuatu yang mencurigakan? Bagikan di sini...</a>
    <div class="composer-actions"><a class="btn btn-light" href="<?= e(url('detect.php')) ?>"><?= ico_img('iTIHSCAN') ?> <span>ItihScan AI</span></a></div></div>
  <section class="mobile-protection card" id="mobileProtection">
    <button class="protection-toggle" type="button" aria-expanded="false"><span class="protection-shield"><?= icon('shield') ?></span><span class="protection-copy"><strong>Perlindungan digital</strong><small>Deteksi pesan, konsultasi AI, dan latihan keamanan</small></span><span class="protection-chevron" id="protectionChevron"><?= icon('chev-down') ?></span></button>
    <div class="protection-tools hidden" id="protectionTools">
      <a class="protection-tool" href="<?= e(url('detect.php')) ?>"><span><?= ico_img('iTIHSCAN') ?></span><strong>ItihScan AI</strong><small>Periksa pesan mencurigakan</small><b>›</b></a>
      <a class="protection-tool" href="<?= e(url('chat.php')) ?>"><span><?= ico_img('iTISTANT_Ai') ?></span><strong>iTISTANT Ai</strong><small>Tanya cara tetap aman</small><b>›</b></a>
      <a class="protection-tool" href="<?= e(url('quiz.php')) ?>"><span><?= ico_img('sor') ?></span><strong>Scam or Real?</strong><small>Latih naluri keamananmu</small><b>›</b></a>
      <a class="protection-tool" href="<?= e(url('game.php')) ?>"><span><?= ico_img('mod2p') ?></span><strong>Mask of Deception 2P</strong><small>Main berdua, tebak penipu</small><b>›</b></a>
    </div>
  </section>
  <?php else: ?>
  <div class="page-heading"><div><div class="eyebrow">Komunitas lebih waspada</div><h1>Selamat datang di ITIHIX!</h1><div class="sub">Kamu melihat sebagai tamu. Laporan komunitas bisa dibaca tanpa akun.</div></div></div>
  <div class="guest-banner"><div><strong>Mau ikut melapor dan berdiskusi?</strong><span>Masuk atau daftar untuk menyukai, berkomentar, membuat laporan, dan memakai ItihScan AI.</span></div>
    <div class="guest-banner-actions"><a class="btn btn-primary" href="<?= e(url('login.php')) ?>">Masuk</a><a class="btn btn-light" href="<?= e(url('register.php')) ?>">Daftar</a></div></div>
  <?php endif; ?>
  <?php if ($page === 1): ?>
  <section class="home-reels" aria-label="SafeSnaps">
    <div class="home-reels-head"><strong><?= icon('film') ?> SafeSnaps</strong><a href="<?= e(url('safesnaps.php')) ?>">Lihat semua →</a></div>
    <?php if (!$shorts): ?>
    <div class="card" style="padding:18px;text-align:center;color:var(--muted);font-size:13px">Belum ada SafeSnaps yang tayang. Video yang diunggah akan tampil di sini setelah disetujui moderator. <a href="<?= e(url('safesnaps_upload.php')) ?>" style="color:var(--red-dark);font-weight:750">Unggah video →</a></div>
    <?php else: ?>
    <div class="home-reels-row">
      <?php foreach ($shorts as $s): ?>
      <a class="home-reel" href="<?= e(url('safesnaps.php?v=' . (int)$s['id'])) ?>" title="<?= e($s['title']) ?>">
        <?php $pst = video_thumb_url($s['video_path']);
          $vsrc = url('uploads/' . $s['video_path']); ?>
        <?php if ($pst): ?><img class="home-reel-poster" src="<?= e($pst) ?>" alt="" loading="lazy" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover">
        <video data-src="<?= e($vsrc) ?>" muted loop playsinline preload="none" tabindex="-1" aria-hidden="true"></video>
        <?php else: /* tanpa poster: pakai frame pertama dari videonya sendiri */ ?>
        <video src="<?= e($vsrc) ?>#t=0.1" muted loop playsinline preload="metadata" tabindex="-1" aria-hidden="true"></video>
        <?php endif; ?>
        <span class="home-reel-play"><?= icon('play') ?></span>
        <span class="home-reel-info"><b>@<?= e($s['username']) ?></b><small><?= icon('heart') ?> <?= (int)$s['like_count'] ?> · <?= icon('comment') ?> <?= (int)$s['comment_count'] ?></small></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
  <script>
  /* Pratinjau SafeSnaps: putar saat disorot/disentuh, jeda saat dilepas */
  document.querySelectorAll('.home-reel').forEach(function (a) {
    var v = a.querySelector('video');
    /* Video baru dimuat saat disorot/disentuh, bukan saat halaman dibuka (hemat kuota & tidak memicu download manager) */
    function play() { if (!v.src) v.src = v.dataset.src; var p = v.play(); if (p && p.catch) p.catch(function () {}); }
    function stop() { v.pause(); try { v.currentTime = 0; } catch (e) {} }
    a.addEventListener('mouseenter', play); a.addEventListener('mouseleave', stop);
    a.addEventListener('focus', play); a.addEventListener('blur', stop);
  });
  </script>
  <?php endif; ?>
  <div class="tabs home-tabs" role="tablist">
    <a class="tab <?= $tab === 'rekomendasi' ? 'active' : '' ?>" href="?tab=rekomendasi"><span>Untuk kamu</span></a>
    <a class="tab <?= $tab === 'terbaru' ? 'active' : '' ?>" href="?tab=terbaru"><span>Terbaru</span></a>
    <?php if ($me): ?><a class="tab <?= $tab === 'mengikuti' ? 'active' : '' ?>" href="?tab=mengikuti"><span>Mengikuti</span></a><?php endif; ?>
  </div>
  <div id="feedList"><?= render_posts($posts, $me, $tab === 'mengikuti' ? 'Belum ada laporan dari akun yang kamu ikuti. Ikuti pengguna lain agar laporan mereka muncul di sini.' : ($me ? 'Belum ada laporan di tab ini. Jadilah yang pertama membuat laporan!' : 'Belum ada laporan di tab ini.')) ?></div>
  <div class="pager">
    <?php if ($page > 1): ?><a class="btn btn-light" href="?tab=<?= e($tab) ?>&page=<?= $page - 1 ?>">← Sebelumnya</a><?php endif; ?>
    <?php if ($more): ?><a class="btn btn-light" href="?tab=<?= e($tab) ?>&page=<?= $page + 1 ?>">Muat lebih banyak →</a><?php endif; ?>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>