<?php
$pageTitle = 'Notifikasi';
$active = 'notifications';
require __DIR__ . '/includes/header.php';
if (is_post()) {
    csrf_check();
    if (($_POST['do'] ?? '') === 'read_all') {
        q('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [$me['id']]);
        flash('Semua notifikasi ditandai dibaca.');
    }
    redirect('notifications.php');
}
if (isset($_GET['go'])) { // tandai dibaca lalu arahkan
    $n = qone('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [(int)$_GET['go'], $me['id']]);
    if ($n) {
        q('UPDATE notifications SET is_read = 1 WHERE id = ?', [$n['id']]);
        redirect($n['link'] ?: 'notifications.php');
    }
    redirect('notifications.php');
}
$rows = qall('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 60', [$me['id']]);
?>
<section id="page-notifications" class="page">
  <div class="page-heading"><div><h1>Notifikasi</h1><div class="sub">Aktivitas komunitas dan pembaruan laporanmu.</div></div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="read_all"><button class="btn btn-light">Tandai dibaca</button></form></div>
  <div class="card" style="padding:5px 18px">
    <?php foreach ($rows as $n): ?>
      <a class="setting-row" href="?go=<?= (int)$n['id'] ?>" style="text-decoration:none;color:inherit">
        <div class="mission-icon"><?= notif_icon((string)$n['icon']) ?></div>
        <div style="flex:1;min-width:0"><strong><?= e($n['title']) ?></strong><small><?= e($n['body']) ?></small><div class="sub"><?= e(time_ago($n['created_at'])) ?></div></div>
        <?php if (!$n['is_read']): ?><span class="dot" style="position:static"></span><?php endif; ?>
      </a>
    <?php endforeach;
if (!$rows): ?><div style="padding:28px;text-align:center;color:var(--muted)">Belum ada notifikasi.</div><?php endif; ?>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
