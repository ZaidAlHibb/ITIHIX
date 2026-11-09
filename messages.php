<?php
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_login();
$uid = (int)$me['id'];

// Siapkan percakapan yang dibuka SEBELUM header dirender, supaya badge "belum dibaca" langsung akurat.
$peer = null;
$msgs = [];
$canSend = false;
$why = '';
$isMutual = false;
$uname = trim((string)($_GET['u'] ?? ''));
if ($uname !== '') {
    $peer = qone('SELECT * FROM users WHERE username = ? AND status = "active"', [$uname]);
    if (!$peer || (int)$peer['id'] === $uid) {
        flash('Pengguna tidak ditemukan.', 'err');
        redirect('messages.php');
    }
    $pid = (int)$peer['id'];
    q('UPDATE direct_messages SET is_read = 1 WHERE sender_id = ? AND recipient_id = ? AND is_read = 0', [$pid, $uid]);
    $msgs = qall(
        'SELECT * FROM (SELECT * FROM direct_messages WHERE (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?) ORDER BY id DESC LIMIT 60) t ORDER BY id',
        [$uid, $pid, $pid, $uid]
    );
    [$canSend, $why] = dm_can_send($me, $peer);
    $isMutual = is_mutual_follow($uid, $pid);
}

$pageTitle = 'Pesan';
$active = 'messages';
$ver = fn (string $f) => (int)@filemtime(__DIR__ . '/' . $f);
$extraCss = '<link rel="stylesheet" href="' . e(url('assets/css/chat.css')) . '?v=' . $ver('assets/css/chat.css') . '">'
          . '<link rel="stylesheet" href="' . e(url('assets/css/messages.css')) . '?v=' . $ver('assets/css/messages.css') . '">';
require __DIR__ . '/includes/header.php';

$convs = dm_conversations($uid);
$lastId = $msgs ? (int)end($msgs)['id'] : 0;

// Kelompokkan seperti riwayat Chat AI (Hari ini, Kemarin, ...); yang belum pernah berkirim pesan masuk "Saling mengikuti".
$order = ['Hari ini', 'Kemarin', '7 hari terakhir', '30 hari terakhir', 'Lebih lama', 'Saling mengikuti'];
$groups = [];
foreach ($convs as $c) {
    $groups[$c['last_at'] ? chat_group_label($c['last_at']) : 'Saling mengikuti'][] = $c;
}

// Isi avatar mini (inisial / foto) untuk header dan gelembung lawan bicara.
$avInner = '';
if ($peer) {
    $avInner = !empty($peer['avatar_path']) ? '<img src="' . e(url('uploads/' . $peer['avatar_path'])) . '" alt="">' : e(initials($peer['display_name']));
}
$quick = [['hand', 'Sapa', 'Halo! 👋'], ['shield', 'Ajak waspada', 'Hai, ada tips keamanan digital terbaru nih?'], ['link', 'Cek link bareng', 'Aku dapat link mencurigakan, boleh tolong cek bareng?'], ['pray', 'Terima kasih', 'Terima kasih sudah saling follow!']];
?>
<section id="page-messages" class="page">
  <div class="cx is-side-open" id="cx">
    <aside class="cx-side" id="cxSide" aria-label="Daftar percakapan">
      <div class="cx-side-in">
        <div class="cx-side-head">
          <a class="cx-new" href="<?= e(url('explore.php')) ?>"><span aria-hidden="true"><?= icon('plus') ?></span> Cari teman</a>
          <button class="cx-icon" id="cxCollapse" type="button" title="Tutup sidebar" aria-label="Tutup sidebar">‹</button>
        </div>
        <div class="cx-search">
          <span class="cx-search-ic" aria-hidden="true"><?= icon('search') ?></span>
          <input id="cxSearch" type="search" placeholder="Cari percakapan..." autocomplete="off" aria-label="Cari percakapan">
          <button class="cx-search-x hidden" id="cxSearchX" type="button" aria-label="Hapus pencarian"><?= icon('xmark') ?></button>
        </div>
        <div class="cx-list" id="cxList" role="list">
          <?php foreach (array_merge($order, array_diff(array_keys($groups), $order)) as $g): if (empty($groups[$g])) {
              continue;
          } ?>
            <div class="cx-group"><?= e($g) ?></div>
            <?php foreach ($groups[$g] as $c):
                $snip = $c['last_body'] !== null ? ($c['last_mine'] ? 'Kamu: ' : '') . mb_strimwidth(preg_replace('/\s+/u', ' ', $c['last_body']), 0, 60, '…') : 'Mulai percakapan';
                $isActive = $peer && (int)$peer['id'] === (int)$c['id']; ?>
              <div class="cx-item dm-i<?= $isActive ? ' active' : '' ?><?= $c['unread'] ? ' unread' : '' ?>" role="listitem" data-id="<?= (int)$c['id'] ?>" data-q="<?= e(mb_strtolower($c['display_name'] . ' ' . $c['username'] . ' ' . ($c['last_body'] ?? ''))) ?>">
                <a class="cx-item-main" href="<?= e(url('messages.php?u=' . urlencode($c['username']))) ?>" title="<?= e($c['display_name']) ?>">
                  <?= avatar_html($c) ?>
                  <span class="dm-it"><span class="cx-item-title"><?= e($c['display_name']) ?></span><small class="dm-snip"><?= e($snip) ?></small></span>
                  <span class="dm-im"><?php if ($c['last_at']): ?><small class="dm-when"><?= e(time_ago($c['last_at'])) ?></small><?php endif; ?><?php if ($c['unread']): ?><b class="dm-badge"><?= (int)$c['unread'] ?></b><?php endif; ?></span>
                </a>
              </div>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <?php if (!$convs): ?>
            <div class="cx-empty">Belum ada teman chat.<br>Kamu dan pengguna lain harus <b>saling mengikuti</b> dulu.</div>
          <?php endif; ?>
          <div class="cx-empty hidden" id="dmNoMatch">Tidak ada percakapan yang cocok.</div>
        </div>
        <div class="cx-side-foot">Hanya pengguna yang saling mengikuti yang bisa berkirim pesan. Jangan bagikan OTP atau data pribadi.</div>
      </div>
    </aside>
    <div class="cx-backdrop" id="cxBackdrop"></div>

    <section class="cx-main">
      <header class="cx-head">
        <button class="cx-icon" id="cxToggle" type="button" title="Buka/tutup sidebar" aria-label="Buka atau tutup sidebar" aria-controls="cxSide" aria-expanded="true"><?= icon('bars') ?></button>
        <?php if ($peer): ?>
          <div class="cx-bot dm-av" aria-hidden="true"><?= $avInner ?></div>
          <div class="cx-head-txt"><strong id="cxTitle"><?= e($peer['display_name']) ?></strong><small>@<?= e($peer['username']) ?></small></div>
          <a class="cx-icon" href="<?= e(url(profile_url($peer['username']))) ?>" title="Lihat profil" aria-label="Lihat profil"><?= icon('user') ?></a>
          <?php if ($isMutual): ?><span class="tag tag-green cx-online"><?= icon('check') ?> Saling mengikuti</span><?php else: ?><span class="tag tag-amber cx-online">Belum saling mengikuti</span><?php endif; ?>
        <?php else: ?>
          <div class="cx-bot" aria-hidden="true"><?= icon('envelope') ?></div>
          <div class="cx-head-txt"><strong id="cxTitle">Pesan</strong><small>Obrolan pribadi dengan teman yang saling mengikuti</small></div>
        <?php endif; ?>
      </header>

      <div class="cx-scroll" id="cxScroll">
        <div class="cx-thread" id="cxThread" aria-live="polite" data-peer="<?= $peer ? (int)$peer['id'] : 0 ?>" data-last="<?= $lastId ?>" data-av="<?= e($avInner) ?>">
        <?php if (!$peer): ?>
          <div class="cx-welcome">
            <div class="cx-welcome-ic"><?= icon('envelope') ?></div>
            <h2>Pesan</h2>
            <p>Pilih percakapan di sidebar untuk mulai berkirim pesan dengan teman yang saling mengikuti denganmu.</p>
            <button type="button" class="btn btn-soft dm-open-list" id="dmOpenList">Buka daftar percakapan</button>
          </div>
        <?php elseif (!$msgs): ?>
          <div class="cx-welcome">
            <div class="cx-welcome-ic"><?= icon('chat') ?></div>
            <h2>Mulai percakapan dengan <?= e($peer['display_name']) ?></h2>
            <?php if ($canSend): ?>
              <p>Kalian saling mengikuti. Sapa @<?= e($peer['username']) ?> atau pilih salah satu di bawah.</p>
              <div class="cx-quick"><?php foreach ($quick as $q): ?><button type="button" class="cx-q" data-q="<?= e($q[2]) ?>"><span><?= icon($q[0]) ?></span><b><?= e($q[1]) ?></b><small><?= e($q[2]) ?></small></button><?php endforeach; ?></div>
            <?php else: ?>
              <p><?= e($why) ?></p>
              <a class="btn btn-soft" href="<?= e(url(profile_url($peer['username']))) ?>" style="text-decoration:none">Lihat profil</a>
            <?php endif; ?>
          </div>
        <?php else: foreach ($msgs as $m): $mine = (int)$m['sender_id'] === $uid; ?>
          <div class="cx-msg <?= $mine ? 'me' : 'bot' ?>" data-id="<?= (int)$m['id'] ?>"><?php if (!$mine): ?><div class="cx-av dm-av" aria-hidden="true"><?= $avInner ?></div><?php endif; ?><div class="cx-body"><div class="cx-bubble dm-b"><?= e($m['body']) ?></div><div class="dm-time"><?= e(dm_time($m['created_at'])) ?></div></div></div>
        <?php endforeach; endif; ?>
        </div>
      </div>
      <button class="cx-down hidden" id="cxDown" type="button" aria-label="Ke pesan terbaru"><?= icon('arrow-down') ?></button>

      <?php if ($peer): ?>
      <form class="cx-composer" id="cxForm" autocomplete="off">
        <div class="cx-box<?= $canSend ? '' : ' hidden' ?>" id="dmBox">
          <textarea id="cxInput" rows="1" maxlength="1000" placeholder="Tulis pesan... (Enter kirim, Shift+Enter baris baru)" aria-label="Pesan"></textarea>
          <button class="cx-send" id="cxSend" type="submit" aria-label="Kirim" disabled><?= icon('send') ?></button>
        </div>
        <div class="dm-locked<?= $canSend ? ' hidden' : '' ?>" id="dmLocked"><?= icon('lock') ?> <span id="dmLockedMsg"><?= e($why) ?></span></div>
        <div class="cx-foot"><span>Jangan bagikan OTP, PIN, atau data pribadi. Ada yang mencurigakan? Laporkan lewat profil pengguna.</span><span id="cxCount">0/1000</span></div>
      </form>
      <?php endif; ?>
    </section>
  </div>
</section>
<?php $extraJs = '<script src="' . e(url('assets/js/dm.js')) . '?v=' . $ver('assets/js/dm.js') . '"></script>'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>