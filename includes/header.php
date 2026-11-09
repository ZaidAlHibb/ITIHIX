<?php
/**
 * Layout pembuka. Variabel yang bisa diset sebelum require:
 *   $pageTitle (string), $active (string: kunci menu), $bare (bool: tanpa sidebar, utk login/register),
 *   $guest (bool: boleh diakses tanpa login)
 */
require_once __DIR__ . '/bootstrap.php';
$bare  = $bare  ?? false;
$guest = $guest ?? false;
$me = $guest ? current_user() : require_login();
$pageTitle = $pageTitle ?? 'ITIHIX';
$active = $active ?? '';
$unread = $me ? unread_count((int)$me['id']) : 0;
$pendingCount = ($me && is_staff($me)) ? (int)qval('SELECT COUNT(*) FROM posts WHERE status="pending" AND deleted_at IS NULL') : 0;
$dmUnread = $me ? dm_unread_count((int)$me['id']) : 0;
$flash = flash();
function ico_img(string $name): string
{
    return '<img class="ico-img" src="' . e(url('assets/img/' . $name . '.png')) . '" alt="">';
}
function ico_mask(string $name): string
{
    return '<span class="ico-mask" style="--m:url(' . e(url('assets/img/' . $name . '.png')) . ')" aria-hidden="true"></span>';
}
function nav_item(string $key, string $href, string $icon, string $label, string $active, string $badge = ''): string
{
    return '<a class="nav-item' . ($active === $key ? ' active' : '') . '" data-page="' . e($key) . '" href="' . e(url($href)) . '"><span class="ni">' . $icon . '</span> ' . e($label) . $badge . '</a>';
}
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#e53945">
<title><?= e($pageTitle) ?> — ITIHIX</title>
<link rel="icon" type="image/png" href="<?= e(url('assets/img/logo.png')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/main.css')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/main.css') ?>">
<?php if (!empty($extraCss)) {
    echo $extraCss;
} ?>
<script>
(function(){
  var dark = true; // default gelap
  try { if (localStorage.getItem('itihix-theme') === 'light') dark = false; } catch(e) {}
  if (dark) document.documentElement.classList.add('pre-dark');
})();
</script>
</head>
<body<?= ($me && $me['reduce_motion']) ? ' class="reduce-motion"' : '' ?> data-base="<?= e(BASE_URL) ?>" data-csrf="<?= e(csrf_token()) ?>" data-guest="<?= $me ? '0' : '1' ?>">
<?= icon_sprite() ?>
<script>if(document.documentElement.classList.contains('pre-dark'))document.body.classList.add('dark');</script>
<div class="app">
<header class="topbar">
  <a class="brand" href="<?= e(url('index.php')) ?>" style="text-decoration:none;color:inherit"><img class="logo-img" src="<?= e(url('assets/img/logo.png')) ?>" alt="ITIHIX" width="30" height="30" style="width:30px;height:30px;max-width:30px;display:block;flex-shrink:0"><div>ITIHIX</div></a>
  <?php if (!$bare && $me): ?>
  <form class="searchbox" action="<?= e(url('explore.php')) ?>" method="get"><span class="search-icon"><?= icon('search') ?></span><input id="globalSearch" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Cari laporan, topik, atau komunitas..." autocomplete="off"></form>
  <?php endif; ?>
  <div class="top-actions">
    <button class="icon-btn" id="themeToggle" type="button" title="Aktifkan mode gelap" aria-label="Aktifkan mode gelap"><?= icon('moon') ?></button>
    <?php if ($me): ?>
      <a class="icon-btn" href="<?= e(url('messages.php')) ?>" title="Pesan" style="display:grid;place-items:center;text-decoration:none"><?= icon('chat') ?><?php if ($dmUnread): ?><span class="dot"></span><?php endif; ?></a>
      <a class="icon-btn" href="<?= e(url('notifications.php')) ?>" title="Notifikasi" style="display:grid;place-items:center;text-decoration:none"><?= icon('bell') ?><?php if ($unread): ?><span class="dot"></span><?php endif; ?></a>
      <div class="acct-menu" id="acctMenu">
        <a href="<?= e(url('profile.php')) ?>" class="acct-trigger" id="acctTrigger" title="Profil" aria-haspopup="menu" aria-expanded="false" style="text-decoration:none"><?= avatar_html($me) ?></a>
        <div class="acct-pop" id="acctPop" role="menu" aria-label="Menu akun">
          <a class="acct-head" href="<?= e(url('profile.php')) ?>" role="menuitem"><?= avatar_html($me) ?><span><strong><?= e($me['display_name']) ?></strong><small>@<?= e($me['username']) ?></small></span></a>
          <a class="acct-item" role="menuitem" href="<?= e(url('profile.php')) ?>"><span class="ni"><?= icon('user') ?></span>Profil saya</a>
          <a class="acct-item" role="menuitem" href="<?= e(url('settings.php')) ?>"><span class="ni"><?= icon('gear') ?></span>Pengaturan</a>
          <?php if (is_staff($me)): ?><a class="acct-item" role="menuitem" href="<?= e(url('moderator.php')) ?>"><span class="ni"><?= icon('square-check') ?></span>Antrean moderasi<?= $pendingCount ? '<span class="tag tag-amber">' . $pendingCount . '</span>' : '' ?></a><?php endif; ?>
          <?php if ($me['role'] === 'admin'): ?><a class="acct-item" role="menuitem" href="<?= e(url('admin.php')) ?>"><span class="ni"><?= icon('chart') ?></span>Dashboard admin</a><?php endif; ?>
          <a class="acct-item acct-out" role="menuitem" href="<?= e(url('logout.php')) ?>"><span class="ni"><?= icon('logout') ?></span>Keluar</a>
        </div>
      </div>
      <script>/* HP: ketuk avatar = buka dropdown; desktop tetap menuju profil */
      (function(){var m=document.getElementById('acctMenu'),t=document.getElementById('acctTrigger');if(!m||!t)return;var mq=window.matchMedia('(max-width:760px)');
      function set(o){m.classList.toggle('open',o);t.setAttribute('aria-expanded',o?'true':'false')}
      t.addEventListener('click',function(e){if(!mq.matches)return;e.preventDefault();set(!m.classList.contains('open'))});
      document.addEventListener('click',function(e){if(!m.contains(e.target))set(false)});
      document.addEventListener('keydown',function(e){if(e.key==='Escape')set(false)});
      window.addEventListener('pageshow',function(){set(false)});})();</script>
    <?php else: ?>
      <a class="btn btn-light" href="<?= e(url('login.php')) ?>">Masuk</a>
      <a class="btn btn-primary" href="<?= e(url('register.php')) ?>">Daftar</a>
    <?php endif; ?>
  </div>
</header>
<div class="layout">
<?php if (!$bare && !$me): /* sidebar tamu: hanya Beranda + ajakan masuk */ ?>
<aside class="sidebar">
  <div class="nav-group">
    <div class="nav-label">Menu utama</div>
    <?= nav_item('home', 'index.php', icon('home'), 'Beranda', $active) ?>
  </div>
  <div class="card guest-card">
    <strong>Gabung ITIHIX</strong>
    <p class="sub">Masuk untuk melapor, memeriksa pesan dengan ItihScan AI, bermain kuis, dan ikut berdiskusi.</p>
    <a class="btn btn-primary" href="<?= e(url('login.php')) ?>">Masuk</a>
    <a class="btn btn-light" href="<?= e(url('register.php')) ?>">Daftar</a>
  </div>
  <div style="font-size:10px;color:#a2a3aa;padding:14px 8px">© 2026 ITIHIX · Tetap waspada, tetap terlindungi.</div>
</aside>
<?php endif; ?>
<?php if (!$bare && $me): ?>
<aside class="sidebar">
  <div class="nav-group">
    <div class="nav-label">Menu utama</div>
    <?= nav_item('home', 'index.php', icon('home'), 'Beranda', $active) ?>
    <?= nav_item('explore', 'explore.php', icon('compass'), 'Jelajahi', $active) ?>
    <?= nav_item('reels', 'safesnaps.php', icon('film'), 'SafeSnaps', $active) ?>
  </div>
  <div class="nav-group">
    <div class="nav-label">Perlindungan digital</div>
        <?= nav_item('detect', 'detect.php', ico_mask('iTIHSCAN'), 'ItihScan AI', $active) ?>
        <?= nav_item('chat', 'chat.php', ico_mask('iTISTANT_Ai'), 'iTISTANT AI', $active) ?>
        <?= nav_item('quiz', 'quiz.php', ico_mask('sor'), 'Scam or Real?', $active) ?>
        <?= nav_item('game', 'game.php', ico_mask('mod2p'), 'Mask of Deception 2P', $active) ?>
  </div>
  <div class="nav-group">
    <div class="nav-label">Komunitas</div>
    <?= nav_item('upload', 'upload.php', icon('plus'), 'Buat laporan', $active) ?>
    <?= nav_item('messages', 'messages.php', icon('chat'), 'Pesan', $active, $dmUnread ? ' <span class="tag tag-red" style="margin-left:auto">' . $dmUnread . '</span>' : '') ?>
    <?= nav_item('notifications', 'notifications.php', icon('bell'), 'Notifikasi', $active, $unread ? ' <span class="tag tag-red" style="margin-left:auto">' . $unread . '</span>' : '') ?>
  </div>
  <?php if (is_staff($me)): ?>
  <div class="nav-group">
    <div class="nav-label">Moderasi</div>
    <?= nav_item('moderator', 'moderator.php', icon('square-check'), 'Antrean moderasi', $active, $pendingCount ? ' <span class="tag tag-amber" style="margin-left:auto">' . $pendingCount . '</span>' : '') ?>
    <?php if ($me['role'] === 'admin'): ?><?= nav_item('admin', 'admin.php', icon('chart'), 'Dashboard admin', $active) ?><?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="nav-group">
    <div class="nav-label">Akun</div>
    <?= nav_item('profile', 'profile.php', icon('user'), 'Profil saya', $active) ?>
    <?= nav_item('settings', 'settings.php', icon('gear'), 'Pengaturan', $active) ?>
    <a class="nav-item" href="<?= e(url('logout.php')) ?>"><span class="ni"><?= icon('logout') ?></span> Keluar</a>
  </div>
  <div style="font-size:10px;color:#a2a3aa;padding:14px 8px">© 2026 ITIHIX · Tetap waspada, tetap terlindungi.</div>
</aside>
<?php endif; ?>
<script>/* pulihkan posisi scroll menu samping sebelum halaman tampil (tidak lompat ke atas) */
(function(){try{var s=document.querySelector('.sidebar'),v=parseInt(sessionStorage.getItem('itihix-nav-scroll')||'0',10);if(s&&v)s.scrollTop=v}catch(e){}})();</script>
<main class="main">