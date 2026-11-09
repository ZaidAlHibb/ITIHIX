<?php
$pageTitle = 'Dashboard admin';
$active = 'admin';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/admin.php';
$admin = require_role('admin');

if (($_GET['export'] ?? '') === 'logs') {   // ekspor CSV, sebelum ada output HTML
    adm_export_logs($_GET);
}
require __DIR__ . '/includes/header.php';

$tab = $_GET['tab'] ?? 'dashboard';
if (!in_array($tab, ['dashboard', 'users', 'posts', 'logs'], true)) {
    $tab = 'dashboard';
}

/* ---------- Aksi ---------- */
adm_handle_post($admin);
$tabs = ['dashboard' => 'Dashboard', 'users' => 'Pengguna & moderator', 'posts' => 'Semua postingan', 'logs' => 'Log aktivitas'];
?>
<section id="page-admin" class="page">
  <div class="page-heading"><div><h1>Dashboard admin</h1><div class="sub">Pantau kesehatan komunitas, moderasi, pengguna, dan audit aktivitas.</div></div><span class="tag tag-red">ADMIN</span></div>
  <div class="tabs"><?php foreach ($tabs as $k => $t): ?><a class="tab <?= $tab === $k ? 'active' : '' ?>" href="?tab=<?= $k ?>"><?= $t ?></a><?php endforeach; ?></div>

<?php if ($tab === 'dashboard'):
    extract(adm_dashboard_data());
    ?>
  <div class="adm-alerts"><?php foreach ($alerts as $a): ?><div class="adm-alert <?= $a[0] ?>"><span><?= e($a[1]) ?></span><?php if (!empty($a[2])): ?><a href="<?= e($a[2]) ?>">Buka →</a><?php endif; ?></div><?php endforeach; ?></div>
  <div class="stat-grid">
    <div class="card stat"><small>Total pengguna</small><strong><?= num_short($totalUsers) ?></strong><span class="change">+<?= $wu ?> minggu ini · <?= adm_delta($wu, $wup) ?></span></div>
    <div class="card stat"><small>Total laporan</small><strong><?= num_short($totalPosts) ?></strong><span class="change">+<?= $wp ?> minggu ini · <?= adm_delta($wp, $wpp) ?></span></div>
    <div class="card stat"><small>Perlu moderasi</small><strong><?= $pending ?></strong><a href="?tab=posts&status=pending" style="font-size:10px;color:var(--amber)">Tinjau postingan →</a></div>
    <div class="card stat"><small>Laporan pengguna terbuka</small><strong><?= $repOpen ?></strong><a href="<?= e(url('moderator.php?tab=reports')) ?>" style="font-size:10px;color:var(--amber)"><?= $repOld ? "$repOld > 24 jam" : 'Buka antrean →' ?></a></div>
    <div class="card stat"><small>Login gagal (24 jam)</small><strong><?= $failed ?></strong><a href="?tab=logs&action=login_failed" style="font-size:10px;color:var(--amber)">Lihat log →</a></div>
    <div class="card stat"><small>Akun ditangguhkan</small><strong><?= $suspended ?></strong><a href="?tab=users&status=suspended" style="font-size:10px;color:var(--amber)">Lihat akun →</a></div>
  </div>
  <div class="card" style="padding:18px;margin-bottom:14px"><div class="side-title">Aktivitas platform <span class="tag <?= $alerts[0][0] === 'ok' ? 'tag-green' : 'tag-amber' ?>"><?= icon('dot') ?> <?= $alerts[0][0] === 'ok' ? 'Sistem normal' : count($alerts) . ' perlu perhatian' ?></span></div>
    <div class="content-grid">
      <div class="admin-mini"><div class="sub">Postingan hari ini</div><div style="font-size:25px;font-weight:850"><?= $todayPosts ?></div><div class="mini-progress"><span style="width:<?= min(100, $todayPosts * 5) ?>%"></span></div></div>
      <div class="admin-mini"><div class="sub">Tingkat persetujuan moderator</div><div style="font-size:25px;font-weight:850"><?= $approveRate ?>%</div><div class="mini-progress"><span style="width:<?= $approveRate ?>%"></span></div></div>
      <div class="admin-mini"><div class="sub">Jawaban kuis hari ini</div><div style="font-size:25px;font-weight:850"><?= $quizToday ?></div><div class="adm-delta">7 hari: <?= $wq ?> (<?= adm_delta($wq, $wqp) ?>)</div></div>
      <div class="admin-mini"><div class="sub">Analisis AI hari ini</div><div style="font-size:25px;font-weight:850"><?= $detToday ?></div><div class="adm-delta">7 hari: <?= $wd ?> (<?= adm_delta($wd, $wdp) ?>)</div></div>
      <div class="admin-mini"><div class="sub">Pesan chat AI hari ini</div><div style="font-size:25px;font-weight:850"><?= $chatToday ?></div></div>
    </div></div>
  <div class="content-grid" style="margin-bottom:14px">
    <div class="card" style="padding:18px"><div class="side-title">Laporan masuk 7 hari terakhir</div>
      <div class="bar-chart"><?php foreach ($days as $d => $n): ?><div><i style="height:<?= max(3, (int)round($n / $max * 100)) ?>px" title="<?= $n ?>"></i><?= $n ?><br><?= date('d/m', strtotime($d)) ?></div><?php endforeach; ?></div></div>
    <div class="card" style="padding:18px"><div class="side-title">Pendaftar baru 7 hari terakhir</div>
      <div class="bar-chart"><?php foreach ($regs as $d => $n): ?><div><i style="height:<?= max(3, (int)round($n / $maxR * 100)) ?>px" title="<?= $n ?>"></i><?= $n ?><br><?= date('d/m', strtotime($d)) ?></div><?php endforeach; ?></div></div>
  </div>
  <div class="content-grid" style="margin-bottom:14px">
    <div class="card" style="padding:18px"><div class="side-title">Kesehatan AI <span class="tag <?= !$aiOn || $rulesShare >= 30 ? 'tag-red' : 'tag-green' ?>"><?= $aiOn ? 'API aktif' : 'API belum diatur' ?></span></div>
      <div class="setting-row"><div><strong>Engine 24 jam terakhir</strong><small>AI: <?= (int)($eng['ai'] ?? 0) ?> · Aturan dasar: <?= (int)($eng['rules'] ?? 0) ?><?= $engN ? " ($rulesShare%)" : '' ?></small></div></div>
      <div class="sub" style="margin:10px 0 2px">Sebaran verdict 7 hari</div>
      <?= adm_bar('Penipuan', $ver['penipuan'] ?? 0, $verMax) ?><?= adm_bar('Mencurigakan', $ver['mencurigakan'] ?? 0, $verMax, 'a') ?><?= adm_bar('Aman', $ver['aman'] ?? 0, $verMax, 'g') ?></div>
    <div class="card" style="padding:18px"><div class="side-title">Keamanan <a href="?tab=logs&action=login_failed">Lihat log →</a></div>
      <div class="setting-row"><div><strong>Login gagal 24 jam</strong><small>Ambang waspada: 5 · bahaya: 20</small></div><span class="tag <?= $failed >= 20 ? 'tag-red' : ($failed >= 5 ? 'tag-amber' : 'tag-green') ?>"><?= $failed ?></span></div>
      <div class="sub" style="margin:10px 0 2px">IP dengan gagal login terbanyak</div>
      <?php foreach ($badIps as $b): ?><div class="setting-row"><span><?= e($b['ip']) ?></span><span class="tag tag-red"><?= (int)$b['c'] ?>×</span></div><?php endforeach;
if (!$badIps): ?><div class="sub">Tidak ada percobaan gagal.</div><?php endif; ?></div>
  </div>
  <div class="content-grid" style="margin-bottom:14px">
    <div class="card" style="padding:18px"><div class="side-title">Sistem</div>
      <div class="setting-row"><div><strong>Folder uploads</strong><small><?= (int)$up['files'] ?> file</small></div><span class="tag tag-amber"><?= e(adm_bytes((float)$up['size'])) ?></span></div>
      <div class="setting-row"><div><strong>Disk server</strong><small><?= e(adm_bytes($dFree)) ?> kosong dari <?= e(adm_bytes($dTot)) ?></small></div><span class="tag <?= $dPct >= 85 ? 'tag-red' : 'tag-green' ?>"><?= $dPct ?>%</span></div>
      <div class="setting-row"><div><strong>Database</strong><small>Ukuran data + indeks</small></div><span class="tag tag-green"><?= $dbSize ? e(adm_bytes($dbSize)) : 'n/a' ?></span></div>
      <div class="setting-row"><div><strong>PHP</strong><small>Versi server</small></div><span class="tag tag-green"><?= e(PHP_VERSION) ?></span></div></div>
    <div class="card" style="padding:18px"><div class="side-title">Insight konten (30 hari)</div>
      <div class="sub">Label akhir</div><?php $lm = max(1, (int)($labs[0]['c'] ?? 1));
foreach ($labs as $l): ?><?= adm_bar(ucfirst($l['label']), (int)$l['c'], $lm, $l['label'] === 'edukasi' ? 'g' : ($l['label'] === 'penipuan' ? '' : 'a')) ?><?php endforeach;
if (!$labs): ?><div class="sub">Belum ada data.</div><?php endif; ?>
      <div class="sub" style="margin-top:10px">Modus terbanyak</div><?php $sm = max(1, (int)($subs[0]['c'] ?? 1));
foreach ($subs as $x): ?><?= adm_bar($x['sub'], (int)$x['c'], $sm) ?><?php endforeach;
if (!$subs): ?><div class="sub">Belum ada data.</div><?php endif; ?>
      <div class="sub" style="margin-top:10px">Kategori teratas</div><?php $cm = max(1, (int)($cats[0]['c'] ?? 1));
foreach ($cats as $x): ?><?= adm_bar($x['category'], (int)$x['c'], $cm, 'a') ?><?php endforeach;
if (!$cats): ?><div class="sub">Belum ada data.</div><?php endif; ?>
      <div class="sub" style="margin-top:10px">Hashtag naik: <?php foreach ($tags as $t): ?><span class="tag tag-red">#<?= e(ltrim($t['tag'], '#')) ?> <?= (int)$t['c'] ?></span> <?php endforeach;
if (!$tags) {
    echo '—';
} ?></div></div>
  </div>
  <div class="card" style="padding:18px;margin-bottom:14px"><div class="side-title">Laporan pengguna terbuka <a href="<?= e(url('moderator.php?tab=reports')) ?>">Buka antrean →</a></div>
    <?php foreach ($openReps as $r): ?><div class="setting-row"><div><strong>@<?= e($r['username']) ?></strong><small><?= e($reasons[$r['reason']] ?? $r['reason']) ?> · <?= e(time_ago($r['created_at'])) ?></small></div><span class="tag tag-red">Open</span></div><?php endforeach;
if (!$openReps): ?><div class="sub">Tidak ada laporan terbuka.</div><?php endif; ?></div>
  <div class="content-grid">
    <div class="card" style="padding:18px"><div class="side-title">Komposisi peran</div><?php foreach ($roles as $r): ?><div class="setting-row"><strong><?= e(role_label($r['role'])) ?></strong><span class="tag tag-red"><?= (int)$r['c'] ?></span></div><?php endforeach; ?></div>
    <div class="card" style="padding:18px"><div class="side-title">Peringkat XP teratas</div><?php foreach ($top as $i => $t): ?><div class="setting-row"><div><strong>#<?= $i + 1 ?> <?= e($t['display_name']) ?></strong><small>@<?= e($t['username']) ?></small></div><span class="tag tag-green">Lv. <?= (int)$t['level'] ?></span></div><?php endforeach; ?></div>
  </div>

<?php elseif ($tab === 'users'):
    extract(adm_users_data($_GET));
    ?>
  <div class="card" style="padding:18px;margin-bottom:14px"><div class="side-title">Tambah akun</div>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;align-items:end"><?= csrf_field() ?><input type="hidden" name="do" value="create_user"><input type="hidden" name="back" value="users">
      <input class="field" name="display_name" placeholder="Nama tampilan" required><input class="field" name="username" placeholder="username" required pattern="[A-Za-z0-9_]{3,30}"><input class="field" type="email" name="email" placeholder="email" required><input class="field" type="password" name="password" placeholder="sandi (min. 8)" minlength="8" required>
      <select class="field" name="role"><option value="user">Pengguna</option><option value="trusted">Terpercaya</option><option value="moderator">Moderator</option><option value="admin">Admin</option></select><button class="btn btn-primary"><?= icon('plus') ?> Tambah</button></form></div>
  <div class="card" style="padding:18px;margin-bottom:14px"><div class="side-title">Pengguna & moderator
    <form method="get" class="adm-filters"><input type="hidden" name="tab" value="users"><input class="field" name="q" value="<?= e($s) ?>" placeholder="Cari pengguna…" style="width:170px"><select class="field" name="role" style="width:auto"><option value="">Semua peran</option><?php foreach (['user', 'trusted', 'moderator', 'admin'] as $r): ?><option value="<?= $r ?>" <?= $fr === $r ? 'selected' : '' ?>><?= role_label($r) ?></option><?php endforeach; ?></select><select class="field" name="status" style="width:auto"><option value="">Semua status</option><option value="active" <?= $fs === 'active' ? 'selected' : '' ?>>Aktif</option><option value="suspended" <?= $fs === 'suspended' ? 'selected' : '' ?>>Ditangguhkan</option></select><button class="btn btn-light">Terapkan</button></form></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Pengguna</th><th>Peran</th><th>Status</th><th>Aktivitas</th><th>Laporan</th><th>Login terakhir</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach ($rows as $u): $self = (int)$u['id'] === (int)$admin['id']; ?>
      <tr><td><a href="<?= e(url(profile_url($u['username']))) ?>"><?= e($u['display_name']) ?></a> · @<?= e($u['username']) ?><br><small class="sub"><?= e($u['email']) ?> · Lv. <?= (int)$u['level'] ?></small></td>
        <td><?php if ($self): ?><?= e(role_label($u['role'])) ?><?php else: ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="set_role"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="back" value="users">
            <select name="role" onchange="this.form.submit()"><?php foreach (['user', 'trusted', 'moderator', 'admin'] as $r): ?><option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= role_label($r) ?></option><?php endforeach; ?></select></form><?php endif; ?></td>
        <td><span class="tag <?= $u['status'] === 'active' ? 'tag-green' : 'tag-red' ?>"><?= $u['status'] === 'active' ? 'Aktif' : 'Ditangguhkan' ?></span></td>
        <td><?= (int)$u['pc'] ?> post<?= $u['rc'] ? ' · ' . (int)$u['rc'] . ' tinjauan' : '' ?></td>
        <td><?= $u['ro'] ? '<span class="tag tag-red">' . (int)$u['ro'] . ' terbuka</span>' : '—' ?></td><td><?= $u['last_login'] ? e(time_ago($u['last_login'])) : '—' ?></td>
        <td><?php if (!$self): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="toggle_status"><input type="hidden" name="reason" value=""><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="back" value="users"><button class="action" onclick="return admSuspend(this, <?= $u['status'] === 'active' ? 1 : 0 ?>)"><?= $u['status'] === 'active' ? 'Tangguhkan' : 'Aktifkan' ?></button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?= adm_pager($page, $more) ?></div>

<?php elseif ($tab === 'posts'):
    extract(adm_posts_data($_GET));
    ?>
<?php if ($rv): $own = (int)$rv['user_id'] === (int)$admin['id'];
        $pend = $rv['status'] === 'pending'; ?>
  <div class="card review-card" style="padding:18px;margin-bottom:14px">
    <div class="side-title">Tinjau postingan #<?= $rid ?> <span><span class="tag <?= ['approved' => 'tag-green', 'rejected' => 'tag-red'][$rv['status']] ?? 'tag-amber' ?>"><?= e(ucfirst($rv['status'])) ?></span> <?php if ($nextP): ?><a class="btn btn-light" href="<?= e(adm_url(['review' => $nextP])) ?>">Pending berikutnya →</a><?php endif; ?> <a class="btn btn-light" href="<?= e(adm_url(['review' => ''])) ?>">Tutup ✕</a></span></div>
    <div class="post-head"><?= avatar_html($rv) ?><div class="post-author"><strong><?= e($rv['display_name']) ?> · Lv. <?= user_level($rv) ?></strong><div class="meta">@<?= e($rv['username']) ?> · <?= e(time_ago($rv['created_at'])) ?> · <?= e(implode(', ', $catS)) ?></div></div></div>
    <h3><?= e($rv['title']) ?></h3>
    <p><?= nl2br(e($rv['body'])) ?></p>
    <?php if (trim((string)($rv['scan_text'] ?? '')) !== ''): ?><div class="post-scan"><b><?= icon('sparkles') ?> Teks yang diperiksa ItihScan AI</b><p><?= e($rv['scan_text']) ?></p></div><?php endif; ?>
    <?= render_media_gallery(post_media_items($rv), 'mod') ?>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px"><span class="tag">Saran pengguna: <?= e(implode(', ', array_map(fn ($l) => label_defs()[$l]['name'] ?? ucfirst($l), $lblS))) ?></span><?php if ($rv['ai_generated']): ?><span class="tag tag-red"><?= icon('sparkles') ?> ItihScan AI</span><?php endif; ?><a class="tag tag-amber" href="<?= e(url('post.php?id=' . $rid)) ?>" target="_blank" rel="noopener">Buka halaman postingan ↗</a></div>
    <?php if (!empty($rv['moderator_note'])): ?><div class="notice" style="margin-bottom:8px"><b>Catatan moderator sebelumnya:</b> <?= e($rv['moderator_note']) ?></div><?php endif; ?>
    <?php if ($rv['user_note']): ?><div class="notice" style="margin-bottom:8px"><b>Catatan pelapor:</b> <?= e($rv['user_note']) ?></div><?php endif; ?>
    <?php if ($own): ?><div class="notice">Kamu tidak dapat meninjau laporanmu sendiri. Minta moderator lain untuk menindaklanjuti.</div>
    <?php elseif (!$pend): ?><div class="notice">Postingan ini tidak sedang menunggu tinjauan, jadi tidak dapat diputuskan lagi dari sini.</div>
    <?php else: ?>
    <?php if ($rv['author_status'] !== 'active'): ?><div class="notice" style="margin-bottom:8px"><b>Akun penulis ditangguhkan.</b> Laporan ini hanya bisa ditolak atau dimintai klarifikasi.</div><?php endif; ?>
    <div class="notice">Checklist: bukti cukup · tidak membocorkan data pribadi · klaim memiliki konteks · label tidak menyatakan kepastian berlebihan.</div>
    <form method="post" action="<?= e(url('moderator.php')) ?>"><?= csrf_field() ?><input type="hidden" name="post_id" value="<?= $rid ?>"><input type="hidden" name="return" value="admin">
      <label class="field-label">Kategori (bisa kamu ubah · maks. <?= MAX_CATEGORIES ?>)</label>
      <?= category_picker('final_categories', $catS) ?>
      <?= sub_picker('final_subs', $subS, $catS) ?>
      <label class="field-label">Label akhir (tampil publik jika disetujui · boleh lebih dari satu)</label>
      <?= label_picker('final_labels', $lblS) ?>
      <label class="field-label">Catatan moderator</label>
      <textarea class="field" name="note" placeholder="Alasan keputusan / saran perbaikan... (wajib untuk tolak & klarifikasi)" style="min-height:70px"></textarea>
      <?= review_template_chips() ?>
      <div class="review-actions">
        <button class="btn btn-light" name="decision" value="clarify">? Minta klarifikasi</button>
        <button class="btn btn-light" name="decision" value="reject" onclick="return confirm('Tolak laporan ini?')">Tolak</button>
        <button class="btn btn-primary" name="decision" value="approve" <?= $rv['author_status'] !== 'active' ? 'disabled title="Akun penulis ditangguhkan"' : '' ?>><?= icon('check') ?> Setujui laporan</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
<?php endif; ?>
  <div class="tabs"><?php foreach (['' => 'Semua', 'pending' => 'Menunggu', 'approved' => 'Disetujui', 'clarify' => 'Klarifikasi', 'rejected' => 'Ditolak'] as $k => $t): ?><a class="tab <?= $stf === $k ? 'active' : '' ?>" href="?tab=posts&status=<?= $k ?>&type=<?= e($ty) ?>&q=<?= urlencode($pq) ?>"><?= $t ?></a><?php endforeach; ?></div>
  <form method="get" class="adm-filters" style="margin-bottom:12px"><input type="hidden" name="tab" value="posts"><input type="hidden" name="status" value="<?= e($stf) ?>"><input class="field" name="q" value="<?= e($pq) ?>" placeholder="Cari judul atau @penulis…" style="width:220px"><select class="field" name="type" style="width:auto"><option value="">Semua jenis</option><?php foreach (['report' => 'Laporan', 'education' => 'Edukasi', 'short' => 'Short'] as $k => $t): ?><option value="<?= $k ?>" <?= $ty === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select><button class="btn btn-light">Cari</button></form>
  <div class="card" style="padding:18px"><div class="table-wrap"><table class="data-table"><thead><tr><th>ID</th><th>Judul</th><th>Penulis</th><th>Status</th><th>Waktu</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td>#<?= (int)$r['id'] ?></td><td><a href="<?= e(url('post.php?id=' . $r['id'])) ?>"><?= e(mb_strimwidth($r['title'], 0, 60, '…')) ?></a><?= $r['type'] === 'short' ? ' ' . icon('film') : '' ?></td><td>@<?= e($r['username']) ?></td>
        <td><span class="tag <?= ['approved' => 'tag-green', 'rejected' => 'tag-red'][$r['status']] ?? 'tag-amber' ?>"><?= e(ucfirst($r['status'])) ?></span><?= $r['deleted_at'] ? ' <span class="tag tag-red">Dihapus</span>' : '' ?></td><td><?= e(time_ago($r['created_at'])) ?></td>
        <td style="white-space:nowrap"><?php if ($r['status'] === 'pending' && !$r['deleted_at']): ?><a class="action" style="text-decoration:none;display:inline-block;margin-right:6px" href="<?= e(adm_url(['review' => $r['id']])) ?>">Tinjau</a><?php endif; ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="back" value="posts"><input type="hidden" name="post_id" value="<?= (int)$r['id'] ?>">
          <?php if ($r['deleted_at']): ?><button class="action" name="do" value="restore_post">Pulihkan</button><?php else: ?><button class="action" name="do" value="delete_post" onclick="return confirm('Hapus postingan ini?')">Hapus</button><?php endif; ?></form></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?= adm_pager($page, $more) ?></div>

<?php else:
    extract(adm_logs_data($_GET));
    ?>
  <div class="card" style="padding:18px"><div class="side-title">Log aktivitas
    <form method="get" class="adm-filters"><input type="hidden" name="tab" value="logs"><select class="field" name="action" style="width:auto;max-width:210px"><option value="">Semua aksi</option><?php foreach ($actions as $ak => $cnt): ?><option value="<?= e($ak) ?>" <?= $fa === $ak ? 'selected' : '' ?>><?= e($ak) ?> (<?= (int)$cnt ?>)</option><?php endforeach; ?></select><input class="field" name="user" value="<?= e($fu) ?>" placeholder="@pengguna" style="width:120px"><input class="field" type="date" name="from" value="<?= e($ff) ?>" title="Dari tanggal" style="width:140px"><input class="field" type="date" name="to" value="<?= e($ft) ?>" title="Sampai tanggal" style="width:140px"><button class="btn btn-light">Filter</button><a class="btn btn-light" href="<?= e(adm_url(['export' => 'logs', 'page' => ''])) ?>">Ekspor CSV</a></form></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Waktu</th><th>Pengguna</th><th>Aksi</th><th>Target</th><th>Detail</th><th>IP</th></tr></thead><tbody>
    <?php foreach ($rows as $l): ?>
      <tr><td style="white-space:nowrap"><?= e(date('d M H:i', strtotime($l['created_at']))) ?></td><td><?= $l['username'] ? '@' . e($l['username']) : '—' ?></td><td><span class="tag tag-red"><?= e($l['action']) ?></span></td><td><?= e(($l['target_type'] ?? '') . ($l['target_id'] ? ' #' . $l['target_id'] : '')) ?></td><td><?= e($l['detail']) ?></td><td><?= e($l['ip']) ?></td></tr>
    <?php endforeach;
    if (!$rows): ?><tr><td colspan="6" style="text-align:center;color:var(--muted)">Tidak ada log.</td></tr><?php endif; ?></tbody></table></div>
    <?= adm_pager($page, $more) ?></div>
<?php endif; ?>
<script>function admSuspend(b, s) { if (s) { var r = prompt('Alasan penangguhan (opsional, tersimpan di log):'); if (r === null) return false; b.form.reason.value = r; return true; } return confirm('Aktifkan kembali akun ini?'); }</script>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>