<?php
$pageTitle = 'Antrean moderasi';
$active = 'moderator';
require_once __DIR__ . '/includes/bootstrap.php';
require_role('moderator', 'admin');
require_once __DIR__ . '/includes/comments.php';
require __DIR__ . '/includes/header.php';


if (is_post()) {
    csrf_check();
    if (isset($_POST['report_id'])) { // laporan terhadap pengguna
        $rid = (int)$_POST['report_id'];
        $act = (string)($_POST['report_decision'] ?? '');
        $ur = qone('SELECT * FROM user_reports WHERE id = ?', [$rid]);
        if (!$ur || $ur['status'] !== 'open' || !in_array($act, ['resolve', 'dismiss', 'delete_comment'], true)) {
            flash('Laporan pengguna tidak valid atau sudah ditangani.', 'err');
            redirect('moderator.php?tab=reports');
        }
        if ((int)$ur['reported_id'] === (int)$me['id']) {
            flash('Kamu tidak dapat menangani laporan tentang dirimu sendiri.', 'err');
            redirect('moderator.php?tab=reports');
        }
        $removed = 0;
        if ($act === 'delete_comment') { // hapus komentar yang dilaporkan (beserta balasannya) lalu tandai laporan ditangani
            $cm = $ur['comment_id'] ? qone('SELECT c.id, c.user_id, c.post_id, c.body, u.role FROM comments c JOIN users u ON u.id = c.user_id WHERE c.id = ? AND c.deleted_at IS NULL', [(int)$ur['comment_id']]) : null;
            if (!$cm) {
                flash('Komentar tidak ditemukan atau sudah dihapus.', 'err');
                redirect('moderator.php?tab=reports');
            }
            if (!can_delete_comment($me, $cm)) {
                flash('Kamu tidak berhak menghapus komentar ini.', 'err');
                redirect('moderator.php?tab=reports');
            }
            $removed = count(comment_delete_tree((int)$cm['id']));
            log_activity('comment_force_delete', 'comment', (int)$cm['id'], mb_substr((string)$cm['body'], 0, 120) . ' (laporan #' . $rid . ')');
            notify((int)$cm['user_id'], 'xmark', 'Komentarmu dihapus oleh ' . mb_strtolower(role_label((string)$me['role'])), '“' . mb_substr((string)$cm['body'], 0, 80) . '” melanggar panduan komunitas.', 'post.php?id=' . (int)$cm['post_id']);
            $act = 'resolve';
            // laporan lain yang masih terbuka untuk komentar yang sama ikut ditandai ditangani
            $others = qall('SELECT id, reporter_id FROM user_reports WHERE comment_id = ? AND status = "open" AND id <> ?', [(int)$cm['id'], $rid]);
            foreach ($others as $o) {
                q('UPDATE user_reports SET status = "resolved", handled_by = ?, handled_at = NOW() WHERE id = ?', [$me['id'], (int)$o['id']]);
                notify((int)$o['reporter_id'], 'flag', 'Laporanmu telah ditinjau', 'Terima kasih, komentar yang kamu laporkan sudah dihapus moderator.', 'notifications.php');
            }
        }
        q('UPDATE user_reports SET status = ?, handled_by = ?, handled_at = NOW() WHERE id = ?', [$act === 'resolve' ? 'resolved' : 'dismissed', $me['id'], $rid]);
        log_activity('report_' . $act, 'user', (int)$ur['reported_id'], 'Laporan pengguna #' . $rid);
        notify((int)$ur['reporter_id'], 'flag', 'Laporanmu telah ditinjau', $act === 'resolve' ? 'Terima kasih, moderator sudah menindaklanjuti laporanmu.' : 'Moderator memeriksa laporanmu dan belum menemukan pelanggaran.', 'notifications.php');
        flash($removed ? 'Komentar dihapus dan laporan ditandai sudah ditangani.' : ($act === 'resolve' ? 'Laporan ditandai sudah ditangani.' : 'Laporan diabaikan.'));
        redirect('moderator.php?tab=reports');
    }
    if (isset($_POST['user_action'])) { // kelola akun: tangguhkan / aktifkan / beri atau cabut Terpercaya
        $tid = (int)($_POST['user_id'] ?? 0);
        $act = (string)$_POST['user_action'];
        $qs = trim((string)($_POST['q'] ?? ''));
        $back = 'moderator.php?tab=users' . ($qs !== '' ? '&q=' . urlencode($qs) : '');
        $t = qone('SELECT id, username, role, status FROM users WHERE id = ?', [$tid]);
        // moderator hanya boleh mengelola akun pengguna biasa & Terpercaya (bukan staf, bukan dirinya sendiri)
        if (!$t || (int)$t['id'] === (int)$me['id'] || !in_array($t['role'], ['user', 'trusted'], true)) {
            flash('Akun ini tidak dapat dikelola dari sini.', 'err');
            redirect($back);
        }
        if ($act === 'suspend' && $t['status'] === 'active') {
            q('UPDATE users SET status = "suspended" WHERE id = ?', [$tid]);
            log_activity('mod_suspend', 'user', $tid, 'Akun @' . $t['username'] . ' ditangguhkan moderator');
            flash('Akun @' . $t['username'] . ' ditangguhkan.');
        } elseif ($act === 'activate' && $t['status'] !== 'active') {
            q('UPDATE users SET status = "active" WHERE id = ?', [$tid]);
            log_activity('mod_activate', 'user', $tid, 'Akun @' . $t['username'] . ' diaktifkan kembali moderator');
            flash('Akun @' . $t['username'] . ' diaktifkan kembali.');
        } elseif ($act === 'make_trusted' && $t['role'] === 'user') {
            q('UPDATE users SET role = "trusted" WHERE id = ?', [$tid]);
            log_activity('mod_set_role', 'user', $tid, 'Peran @' . $t['username'] . ' diubah menjadi trusted');
            notify($tid, 'square-check', 'Akunmu kini Terpercaya', 'Laporanmu sekarang langsung terbit tanpa antrean moderasi.');
            flash('@' . $t['username'] . ' kini berstatus Terpercaya.');
        } elseif ($act === 'remove_trusted' && $t['role'] === 'trusted') {
            q('UPDATE users SET role = "user" WHERE id = ?', [$tid]);
            log_activity('mod_set_role', 'user', $tid, 'Peran @' . $t['username'] . ' diubah menjadi user');
            notify($tid, 'square-check', 'Status Terpercaya dicabut', 'Laporanmu kembali melalui antrean moderasi sebelum terbit.');
            flash('Status Terpercaya @' . $t['username'] . ' dicabut.');
        } else {
            flash('Aksi tidak sesuai dengan status akun saat ini.', 'err');
        }
        redirect($back);
    }
    $ret = (($_POST['return'] ?? '') === 'admin') ? 'admin.php?tab=posts&status=pending' : null; // keputusan dari panel review admin
    $pid = (int)($_POST['post_id'] ?? 0);
    $dec = $_POST['decision'] ?? '';
    $note = trim((string)($_POST['note'] ?? ''));
    $final = parse_labels($_POST['final_labels'] ?? []);
    $p = qone('SELECT id, user_id, title, status, user_label, category FROM posts WHERE id = ? AND deleted_at IS NULL', [$pid]);
    if (!$p) {
        flash('Laporan tidak ditemukan.', 'err');
        redirect($ret ?? 'moderator.php');
    }
    if ($p['status'] === 'approved') {
        flash('Laporan ini sudah disetujui sebelumnya.', 'err');
        redirect($ret ?? 'moderator.php');
    }
    if ((int)$p['user_id'] === (int)$me['id']) {
        flash('Kamu tidak dapat meninjau laporanmu sendiri.', 'err');
        redirect($ret ?? 'moderator.php');
    }
    if ($p['status'] !== 'pending') {
        flash('Laporan ini tidak sedang menunggu tinjauan (mungkin sedang direvisi pelapor).', 'err');
        redirect($ret ?? 'moderator.php');
    }
    if ($dec === 'approve' && qval('SELECT status FROM users WHERE id = ?', [(int)$p['user_id']]) !== 'active') {
        flash('Akun penulis sedang ditangguhkan, jadi laporan ini hanya bisa ditolak atau dimintai klarifikasi.', 'err');
        redirect($ret ?? 'moderator.php');
    }
    $origLabels = post_label_sets([$pid])[$pid]['user'] ?? [(string)$p['user_label']];
    $origCats = post_category_sets([$pid])[$pid] ?? [(string)$p['category']];
    $origSubs = array_column(post_sub_sets([$pid])[$pid] ?? [], 'sub');
    if (!$final) {
        $final = $origLabels;
    } // tidak memilih -> pakai saran pelapor
    $newCats = array_slice(parse_categories($_POST['final_categories'] ?? []), 0, MAX_CATEGORIES);
    if (!$newCats) {
        $newCats = $origCats;
    }                      // tidak memilih -> kategori pelapor tetap
    $newSubs = parse_subs($_POST['final_subs'] ?? [], $newCats);
    $short = '“' . mb_substr($p['title'], 0, 80) . '”';
    if ($dec === 'approve') {
        q('UPDATE posts SET status="approved", moderator_id=?, final_label=?, moderator_note=?, reviewed_at=NOW() WHERE id=?', [$me['id'], $final[0], mb_substr($note, 0, 500), $pid]);
        set_post_labels($pid, 'final', $final);
        // moderator boleh menyesuaikan kategori & jenis modus yang diajukan pelapor
        q('UPDATE posts SET category = ? WHERE id = ?', [$newCats[0], $pid]);
        set_post_categories($pid, $newCats);
        set_post_subs($pid, $newSubs);
        $changed = $newCats !== $origCats || array_column($newSubs, 'sub') !== $origSubs || $final !== $origLabels;
        add_xp((int)$p['user_id'], 20, 'laporan disetujui');
        add_xp((int)$me['id'], 2, 'moderasi');
        notify((int)$p['user_id'], 'check', 'Laporanmu disetujui +20 XP', $short . ' kini tampil dengan label “LAPORAN TERVERIFIKASI”.' . ($changed ? ' Moderator menyesuaikan kategori/label laporanmu.' : ''), 'post.php?id=' . $pid);
        log_activity('mod_approve', 'post', $pid, 'Label akhir: ' . implode(', ', $final) . ' · Kategori: ' . implode(', ', $newCats) . ($newSubs ? ' · Jenis: ' . implode(', ', array_column($newSubs, 'sub')) : ''));
        flash('Laporan disetujui. Label, kategori, dan jenis modus tersimpan.');
    } elseif (in_array($dec, ['reject', 'clarify'], true)) {
        if ($note === '') {
            flash('Catatan wajib diisi untuk menolak atau meminta klarifikasi.', 'err');
            redirect($ret ?? 'moderator.php');
        }
        $st = $dec === 'reject' ? 'rejected' : 'clarify';
        q('UPDATE posts SET status=?, moderator_id=?, moderator_note=?, reviewed_at=NOW() WHERE id=?', [$st, $me['id'], mb_substr($note, 0, 500), $pid]);
        notify((int)$p['user_id'], $dec === 'reject' ? 'xmark' : 'question', $dec === 'reject' ? 'Laporanmu belum dapat diterbitkan' : 'Moderator meminta klarifikasi', $short . ' — ' . mb_substr($note, 0, 140), $dec === 'clarify' ? 'edit_report.php?id=' . $pid : 'profile.php?tab=pending');
        log_activity('mod_' . $dec, 'post', $pid, mb_substr($note, 0, 200));
        flash($dec === 'reject' ? 'Laporan ditolak.' : 'Permintaan klarifikasi dikirim ke pelapor.');
    }
    redirect($ret ?? ('moderator.php' . (isset($_GET['tab']) && is_string($_GET['tab']) ? '?tab=' . urlencode($_GET['tab']) : '')));
}

$tabIn = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : 'queue';
$tab = in_array($tabIn, ['queue', 'history', 'reports', 'users'], true) ? $tabIn : 'queue';
$repOpen = (int)qval('SELECT COUNT(*) FROM user_reports WHERE status = "open"');
$st1 = (int)qval('SELECT COUNT(*) FROM posts WHERE status="pending" AND deleted_at IS NULL');
$st2 = (int)qval('SELECT COUNT(*) FROM posts WHERE status="approved" AND moderator_id IS NOT NULL AND DATE(reviewed_at)=CURDATE()');
$st3 = (int)qval('SELECT COUNT(*) FROM posts WHERE status="clarify" AND deleted_at IS NULL');
$todayNew = (int)qval('SELECT COUNT(*) FROM posts WHERE status="pending" AND DATE(created_at)=CURDATE()');
?>
<section id="page-moderator" class="page">
  <div class="page-heading"><div><h1>Antrean moderasi</h1><div class="sub">Periksa bukti, konteks, dan risiko sebelum laporan dipublikasikan.</div></div><span class="tag tag-amber"><?= $st1 ?> menunggu</span></div>
  <div class="stat-grid">
    <div class="card stat"><small>Menunggu tinjauan</small><strong><?= $st1 ?></strong><span class="change">+<?= $todayNew ?> hari ini</span></div>
    <div class="card stat"><small>Diverifikasi hari ini</small><strong><?= $st2 ?></strong><span class="change">oleh tim moderator</span></div>
    <div class="card stat"><small>Perlu klarifikasi</small><strong><?= $st3 ?></strong><span style="font-size:10px;color:var(--amber)">Menunggu pelapor</span></div>
  </div>
  <div class="tabs"><a class="tab <?= $tab === 'queue' ? 'active' : '' ?>" href="?tab=queue">Antrean</a><a class="tab <?= $tab === 'history' ? 'active' : '' ?>" href="?tab=history">Riwayat keputusan</a><a class="tab <?= $tab === 'reports' ? 'active' : '' ?>" href="?tab=reports">Laporan pengguna<?= $repOpen ? ' (' . $repOpen . ')' : '' ?></a><a class="tab <?= $tab === 'users' ? 'active' : '' ?>" href="?tab=users">Kelola akun</a></div>

<?php if ($tab === 'queue'):
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 5; // 5 laporan per halaman, urut dari yang terlama
    $qq = trim((string)($_GET['q'] ?? ''));
    $qf = (string)($_GET['f'] ?? '');
    $qc = (string)($_GET['cat'] ?? '');
    if (!in_array($qf, ['late', 'ai', 'susp'], true)) {
        $qf = '';
    }
    if (!isset(category_defs()[$qc])) {
        $qc = '';
    }
    $where = 'p.status = "pending" AND p.deleted_at IS NULL';
    $params = [];
    if ($qq !== '') {
        $where .= ' AND (p.title LIKE :q1 OR u.username LIKE :q2)';
        $params['q1'] = $params['q2'] = '%' . addcslashes($qq, '%_') . '%';
    }
    if ($qf === 'late') {
        $where .= ' AND p.created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)';
    }
    if ($qf === 'ai') {
        $where .= ' AND p.ai_generated = 1';
    }
    if ($qf === 'susp') {
        $where .= ' AND u.status <> "active"';
    }
    if ($qc !== '') {
        [$cs, $cp] = category_filter_sql($qc);
        $where .= ' AND ' . $cs;
        $params += $cp;
    }
    $rows = qall('SELECT p.*, u.username, u.display_name, u.level, u.xp, u.avatar_color, u.avatar_path, u.status AS author_status FROM posts p JOIN users u ON u.id = p.user_id
                  WHERE ' . $where . ' ORDER BY p.created_at ASC, p.id ASC LIMIT ' . ($per + 1) . ' OFFSET ' . (($page - 1) * $per), $params);
    $ql = fn (array $o = []): string => '?' . http_build_query(array_filter(array_merge(['tab' => 'queue', 'q' => $qq, 'f' => $qf, 'cat' => $qc, 'page' => 1], $o), fn ($v) => $v !== ''));
    $lateN = (int)qval('SELECT COUNT(*) FROM posts WHERE status = "pending" AND deleted_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)');
    $suspN = (int)qval('SELECT COUNT(*) FROM posts p JOIN users u ON u.id = p.user_id WHERE p.status = "pending" AND p.deleted_at IS NULL AND u.status <> "active"');
    $more = count($rows) > $per;
    $rows = array_slice($rows, 0, $per); // terlama dulu, terbaru di halaman berikutnya
    $lblSets = post_label_sets(array_column($rows, 'id'));
    $catSets = post_category_sets(array_column($rows, 'id'));
    $subSets = post_sub_sets(array_column($rows, 'id'));
    ?>
  <form method="get" class="rp-filter"><input type="hidden" name="tab" value="queue">
    <input class="field" name="q" value="<?= e($qq) ?>" placeholder="Cari judul atau @penulis…" style="width:210px">
    <select class="field" name="f" onchange="this.form.submit()" aria-label="Filter antrean">
      <option value="">Semua antrean</option>
      <option value="late" <?= $qf === 'late' ? 'selected' : '' ?>>Lewat 24 jam (<?= $lateN ?>)</option>
      <option value="ai" <?= $qf === 'ai' ? 'selected' : '' ?>>Dibuat ItihScan AI</option>
      <option value="susp" <?= $qf === 'susp' ? 'selected' : '' ?>>Akun penulis ditangguhkan (<?= $suspN ?>)</option>
    </select>
    <select class="field" name="cat" onchange="this.form.submit()" aria-label="Filter kategori"><option value="">Semua kategori</option><?php foreach (array_keys(category_defs()) as $k): ?><option value="<?= e($k) ?>" <?= $qc === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?></select>
    <button class="btn btn-light">Terapkan</button><?php if ($qq . $qf . $qc !== ''): ?><a class="btn btn-light" href="?tab=queue">Reset</a><?php endif; ?>
    <span class="sub">Terlama di atas.</span>
  </form>
<?php
        if (!$rows && $page > 1) {
            echo '<div class="card" style="padding:32px;text-align:center;color:var(--muted)">Halaman ini kosong. <a href="?tab=queue" style="color:var(--red-dark);font-weight:700">Kembali ke halaman 1</a></div>';
        } elseif (!$rows) {
            echo '<div class="card" style="padding:32px;text-align:center;color:var(--muted)">' . icon('party') . ($qq . $qf . $qc !== '' ? ' Tidak ada laporan yang cocok dengan filter.' : ' Antrean kosong. Semua laporan sudah ditinjau.') . '</div>';
        }
foreach ($rows as $r): $sugg = $lblSets[(int)$r['id']]['user'] ?? [$r['user_label']];
    $catSel = $catSets[(int)$r['id']] ?? [$r['category']];
    $subSel = array_column($subSets[(int)$r['id']] ?? [], 'sub');
    $resent = !empty($r['moderator_id']);
    $hrs = (int)floor((time() - strtotime($r['created_at'])) / 3600);
    $late = $hrs >= 24;
    $susp = $r['author_status'] !== 'active'; ?>
  <div class="card review-card <?= $late ? 'is-late' : ($hrs >= 12 ? 'is-wait' : '') ?>">
    <div class="rp-head" style="margin-bottom:10px"><span class="tag <?= $late ? 'tag-red' : ($hrs >= 12 ? 'tag-amber' : '') ?>">Menunggu <?= $hrs >= 24 ? floor($hrs / 24) . ' hari ' . ($hrs % 24) . ' jam' : ($hrs >= 1 ? $hrs . ' jam' : '< 1 jam') ?></span><?php if ($susp): ?><span class="tag tag-red">Akun penulis ditangguhkan</span><?php endif; ?></div>
    <div class="post-head"><?= avatar_html($r) ?><div class="post-author"><strong><?= e($r['display_name']) ?> · Lv. <?= user_level($r) ?></strong><div class="meta">Laporan #IHX-<?= (int)$r['id'] ?> · <?= e(time_ago($r['created_at'])) ?> · <?= e(implode(', ', $catSets[(int)$r['id']] ?? [$r['category']])) ?><?php if (!empty($subSets[(int)$r['id']])): ?> · <?= icon('subarrow') ?> <?= e(implode(', ', array_column($subSets[(int)$r['id']], 'sub'))) ?><?php endif; ?></div></div><span class="tag tag-amber"><?= $resent ? icon('undo') . ' Dikirim ulang' : 'Menunggu' ?></span></div>
    <h3><?= e($r['title']) ?></h3>
    <p><?= nl2br(e($r['body'])) ?></p>
    <?php if (trim((string)($r['scan_text'] ?? '')) !== ''): ?><div class="post-scan"><b><?= icon('sparkles') ?> Teks yang diperiksa ItihScan AI</b><p><?= e($r['scan_text']) ?></p></div><?php endif; ?>
    <?= render_media_gallery(post_media_items($r), 'mod') ?>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px"><span class="tag">Saran pengguna: <?= e(implode(', ', array_map(fn ($l) => label_defs()[$l]['name'] ?? ucfirst($l), $sugg))) ?></span><?php if ($r['ai_generated']): ?><span class="tag tag-red"><?= icon('sparkles') ?> ItihScan AI</span><?php endif; ?></div>
    <?php if ($resent && !empty($r['moderator_note'])): ?><div class="notice" style="margin-bottom:8px"><b>Permintaan klarifikasi sebelumnya:</b> <?= e($r['moderator_note']) ?></div><?php endif; ?>
    <?php if ($r['user_note']): ?><div class="notice" style="margin-bottom:8px"><b><?= $resent ? 'Jawaban / catatan pelapor:' : 'Catatan pelapor:' ?></b> <?= e($r['user_note']) ?></div><?php endif; ?>
    <?php if ($susp): ?><div class="notice" style="margin-bottom:8px">Akun penulis sedang ditangguhkan: laporan ini tidak bisa disetujui, hanya ditolak atau dimintai klarifikasi.</div><?php endif; ?>
    <div class="notice">Checklist: bukti cukup · tidak membocorkan data pribadi · klaim memiliki konteks · label tidak menyatakan kepastian berlebihan.</div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="post_id" value="<?= (int)$r['id'] ?>">
      <label class="field-label">Kategori (bisa kamu ubah · maks. <?= MAX_CATEGORIES ?>)</label>
      <?= category_picker('final_categories', $catSel) ?>
      <?= sub_picker('final_subs', $subSel, $catSel) ?>
      <label class="field-label">Label akhir (tampil publik jika disetujui · boleh lebih dari satu)</label>
      <?= label_picker('final_labels', $sugg) ?>
      <label class="field-label">Catatan moderator</label>
      <textarea class="field" name="note" placeholder="Alasan keputusan / saran perbaikan... (wajib untuk tolak & klarifikasi)" style="min-height:70px"></textarea>
      <?= review_template_chips() ?>
      <div class="review-actions">
        <button class="btn btn-light" name="decision" value="clarify">? Minta klarifikasi</button>
        <button class="btn btn-light" name="decision" value="reject" onclick="return confirm('Tolak laporan ini?')">Tolak</button>
        <button class="btn btn-primary" name="decision" value="approve" <?= $susp ? 'disabled title="Akun penulis ditangguhkan"' : '' ?>><?= icon('check') ?> Setujui laporan</button>
      </div>
    </form>
  </div>
<?php endforeach; ?>
  <?php if ($page > 1 || $more): ?>
  <div class="pager">
    <?php if ($page > 1): ?><a class="btn btn-light" href="<?= e($ql(['page' => $page - 1])) ?>">← Lebih lama</a><?php endif; ?>
    <span class="sub" style="align-self:center">Halaman <?= $page ?></span>
    <?php if ($more): ?><a class="btn btn-light" href="<?= e($ql(['page' => $page + 1])) ?>">Lebih baru →</a><?php endif; ?>
  </div>
  <?php endif; ?>
<?php elseif ($tab === 'history'):
      $rows = qall('SELECT p.id, p.title, p.status, p.final_label, p.moderator_note, p.reviewed_at, m.username mod_user, u.username FROM posts p
                  JOIN users u ON u.id = p.user_id LEFT JOIN users m ON m.id = p.moderator_id
                  WHERE p.moderator_id IS NOT NULL AND p.deleted_at IS NULL ORDER BY p.reviewed_at DESC LIMIT 50');
      ?>
  <div class="card" style="padding:5px 18px">
    <?php foreach ($rows as $r): $c = ['approved' => 'tag-green', 'rejected' => 'tag-red', 'clarify' => 'tag-amber'][$r['status']] ?? 'tag-amber'; ?>
      <a class="setting-row" href="<?= e(url('post.php?id=' . $r['id'])) ?>" style="text-decoration:none;color:inherit"><div style="min-width:0"><strong><?= e($r['title']) ?></strong><small>oleh @<?= e($r['username']) ?> · ditinjau @<?= e($r['mod_user']) ?> · <?= e(time_ago($r['reviewed_at'])) ?><?= $r['moderator_note'] ? ' · “' . e(mb_strimwidth($r['moderator_note'], 0, 70, '…')) . '”' : '' ?></small></div><span class="tag <?= $c ?>"><?= e(ucfirst($r['status'])) ?></span></a>
    <?php endforeach;
      if (!$rows): ?><div style="padding:28px;text-align:center;color:var(--muted)">Belum ada keputusan.</div><?php endif; ?>
  </div>
<?php elseif ($tab === 'users'):
    $s = trim((string)($_GET['q'] ?? ''));
    $s = ltrim($s, '@');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 10;
    $where = '1';
    $params = [];
    if ($s !== '') {
        $where = '(u.username LIKE ? OR u.display_name LIKE ?)';
        $l = '%' . addcslashes($s, '%_') . '%';
        $params = [$l, $l];
    }
    $rows = qall('SELECT u.id, u.username, u.display_name, u.role, u.status, u.level,
                         (SELECT COUNT(*) FROM posts WHERE user_id = u.id AND deleted_at IS NULL) pc
                  FROM users u WHERE ' . $where . ' ORDER BY u.id DESC LIMIT ' . ($per + 1) . ' OFFSET ' . (($page - 1) * $per), $params);
    $more = count($rows) > $per;
    $rows = array_slice($rows, 0, $per);
    ?>
  <div class="card" style="padding:18px;margin-bottom:14px">
    <div class="side-title">Kelola akun pengguna
      <form method="get" style="display:flex;gap:6px"><input type="hidden" name="tab" value="users"><input class="field" name="q" value="<?= e($s) ?>" placeholder="Cari nama / username…" style="width:200px"><button class="btn btn-light">Cari</button></form></div>
    <div class="sub" style="margin-bottom:10px">Moderator dapat menangguhkan akun dan memberi status <b>Terpercaya</b> (laporan langsung terbit tanpa antrean). Akun staf tidak dapat diubah dari sini.</div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Pengguna</th><th>Peran</th><th>Status</th><th>Aktivitas</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach ($rows as $u): $self = (int)$u['id'] === (int)$me['id'];
        $manage = !$self && in_array($u['role'], ['user', 'trusted'], true); ?>
      <tr>
        <td><a href="<?= e(url(profile_url($u['username']))) ?>"><?= e($u['display_name']) ?></a> · @<?= e($u['username']) ?><br><small class="sub">Lv. <?= (int)$u['level'] ?></small></td>
        <td><?= e(role_label($u['role'])) ?></td>
        <td><span class="tag <?= $u['status'] === 'active' ? 'tag-green' : 'tag-red' ?>"><?= $u['status'] === 'active' ? 'Aktif' : 'Ditangguhkan' ?></span></td>
        <td><?= (int)$u['pc'] ?> post</td>
        <td><?php if ($manage): ?>
          <form method="post" style="display:flex;gap:6px;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="q" value="<?= e($s) ?>">
            <?php if ($u['role'] === 'user'): ?><button class="action" name="user_action" value="make_trusted" onclick="return confirm('Jadikan akun ini Terpercaya? Laporannya akan langsung terbit.')">Jadikan Terpercaya</button>
            <?php else: ?><button class="action" name="user_action" value="remove_trusted" onclick="return confirm('Cabut status Terpercaya akun ini?')">Cabut Terpercaya</button><?php endif; ?>
            <?php if ($u['status'] === 'active'): ?><button class="action" name="user_action" value="suspend" onclick="return confirm('Tangguhkan akun ini?')">Tangguhkan</button>
            <?php else: ?><button class="action" name="user_action" value="activate" onclick="return confirm('Aktifkan kembali akun ini?')">Aktifkan</button><?php endif; ?>
          </form>
        <?php else: ?><span class="sub">—</span><?php endif; ?></td>
      </tr>
    <?php endforeach;
if (!$rows): ?><tr><td colspan="5" style="text-align:center;color:var(--muted);padding:24px">Tidak ada pengguna ditemukan.</td></tr><?php endif; ?></tbody></table></div>
    <?php if ($page > 1 || $more): ?><div class="pager"><?php if ($page > 1): ?><a class="btn btn-light" href="?tab=users&q=<?= urlencode($s) ?>&page=<?= $page - 1 ?>">←</a><?php endif; ?><?php if ($more): ?><a class="btn btn-light" href="?tab=users&q=<?= urlencode($s) ?>&page=<?= $page + 1 ?>">→</a><?php endif; ?></div><?php endif; ?>
  </div>
<?php else: // tab laporan pengguna
    $reasons = report_reasons();
    $rs = (string)($_GET['rs'] ?? 'open');
    if (!in_array($rs, ['open', 'resolved', 'dismissed', 'all'], true)) {
        $rs = 'open';
    }
    $rr = (string)($_GET['rr'] ?? '');
    if (!isset($reasons[$rr])) {
        $rr = '';
    }
    $cnt = array_map('intval', array_column(qall('SELECT status, COUNT(*) c FROM user_reports GROUP BY status'), 'c', 'status'));
    $cnt['all'] = array_sum($cnt);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 8;
    $where = '1';
    $params = [];
    if ($rs !== 'all') {
        $where .= ' AND r.status = ?';
        $params[] = $rs;
    }
    if ($rr !== '') {
        $where .= ' AND r.reason = ?';
        $params[] = $rr;
    }
    $order = $rs === 'open' ? 'r.created_at ASC' : ($rs === 'all' ? '(r.status = "open") DESC, r.created_at DESC' : 'r.handled_at DESC');
    $rows = qall("SELECT r.*, ru.username AS reporter_user, ru.display_name AS reporter_name, tu.username AS target_user, tu.display_name AS target_name,
                         tu.role AS target_role, tu.status AS target_status, tu.avatar_color, tu.avatar_path, tu.level AS target_level,
                         p.title AS post_title, p.status AS post_status, hm.username AS handler,
                         cm.body AS cm_body, cm.post_id AS cm_post, cm.deleted_at AS cm_deleted,
                         (SELECT COUNT(*) FROM user_reports y WHERE y.comment_id IS NOT NULL AND y.comment_id = r.comment_id) AS cm_reports,
                         (SELECT COUNT(*) FROM user_reports x WHERE x.reported_id = r.reported_id) AS target_total,
                         (SELECT COUNT(*) FROM user_reports x WHERE x.reported_id = r.reported_id AND x.status = 'open') AS target_open
                  FROM user_reports r JOIN users ru ON ru.id = r.reporter_id JOIN users tu ON tu.id = r.reported_id
                  LEFT JOIN posts p ON p.id = r.post_id LEFT JOIN comments cm ON cm.id = r.comment_id LEFT JOIN users hm ON hm.id = r.handled_by
                  WHERE $where ORDER BY $order, r.id DESC LIMIT " . ($per + 1) . ' OFFSET ' . (($page - 1) * $per), $params);
    $more = count($rows) > $per;
    $rows = array_slice($rows, 0, $per);
    $rl = fn (array $o = []): string => '?' . http_build_query(array_merge(['tab' => 'reports', 'rs' => $rs, 'rr' => $rr, 'page' => 1], $o));
    ?>
  <div class="rp-summary">
    <?php foreach (['open' => ['Menunggu', 'warn'], 'resolved' => ['Ditangani', 'ok'], 'dismissed' => ['Diabaikan', 'mute'], 'all' => ['Semua laporan', 'all']] as $k => [$lb, $cl]): ?>
    <a class="rp-sum rp-<?= $cl ?> <?= $rs === $k ? 'active' : '' ?>" href="<?= e($rl(['rs' => $k])) ?>"><strong><?= (int)($cnt[$k] ?? 0) ?></strong><span><?= $lb ?></span></a>
    <?php endforeach; ?>
  </div>
  <form method="get" class="rp-filter"><input type="hidden" name="tab" value="reports"><input type="hidden" name="rs" value="<?= e($rs) ?>">
    <label for="rpReason">Alasan</label>
    <select class="field" id="rpReason" name="rr" onchange="this.form.submit()"><option value="">Semua alasan</option><?php foreach ($reasons as $k => $v): ?><option value="<?= e($k) ?>" <?= $rr === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
    <span class="sub"><?= $rs === 'open' ? 'Diurutkan dari yang paling lama menunggu.' : 'Diurutkan dari yang terbaru.' ?></span>
  </form>
  <?php if (!$rows): ?><div class="card" style="padding:32px;text-align:center;color:var(--muted)"><?= $rs === 'open' && $rr === '' ? icon('party') . ' Tidak ada laporan yang menunggu. Semua sudah ditangani.' : 'Tidak ada laporan pada filter ini.' ?></div><?php endif; ?>
  <?php foreach ($rows as $r):
      $open = $r['status'] === 'open';
      $late = $open && time() - strtotime($r['created_at']) > 86400;
      $stc = ['open' => 'tag-amber', 'resolved' => 'tag-green', 'dismissed' => ''][$r['status']];
      $stt = ['open' => 'Menunggu', 'resolved' => 'Ditangani', 'dismissed' => 'Diabaikan'][$r['status']];
      $tgt = ['username' => $r['target_user'], 'display_name' => $r['target_name'], 'avatar_color' => $r['avatar_color'], 'avatar_path' => $r['avatar_path']];
      // laporan komentar: keterangan berisi cuplikan otomatis "[Komentar #N] “…”" + catatan pelapor; pisahkan keduanya
      $isCm = !empty($r['comment_id']);
      $note = (string)$r['detail'];
      $snap = '';
      if ($isCm && preg_match('/^\[Komentar #\d+\] “(.*?)”(?: — |$)/us', $note, $mm)) {
          $snap = $mm[1];
          $note = trim(mb_substr($note, mb_strlen($mm[0])));
      }
      $cmAlive = $isCm && $r['cm_body'] !== null && $r['cm_deleted'] === null;
      $cmCanDel = $cmAlive && can_delete_comment($me, ['user_id' => (int)$r['reported_id'], 'role' => $r['target_role']]); ?>
  <div class="card rp-card <?= $open ? 'is-open' : '' ?> <?= $late ? 'is-late' : '' ?>">
    <div class="rp-head">
      <span class="tag <?= $stc ?>"><?= $stt ?></span>
      <span class="tag tag-red"><?= icon('flag') ?> <?= e($reasons[$r['reason']] ?? $r['reason']) ?></span>
      <?php if ($isCm): ?><span class="tag"><?= icon('comment') ?> Komentar</span><?php endif; ?>
      <?php if ($late): ?><span class="tag tag-red">Lewat 24 jam</span><?php endif; ?>
      <span class="rp-meta">#<?= (int)$r['id'] ?> · dilaporkan <?= e(time_ago($r['created_at'])) ?></span>
    </div>
    <div class="rp-people">
      <div class="rp-person">
        <div class="rp-label">Akun yang dilaporkan</div>
        <div class="rp-who"><?= avatar_html($tgt) ?><div><a href="<?= e(url(profile_url($r['target_user']))) ?>"><strong><?= e($r['target_name']) ?></strong></a><div class="sub">@<?= e($r['target_user']) ?> · <?= e(role_label($r['target_role'])) ?> · Lv. <?= (int)$r['target_level'] ?></div></div></div>
        <div class="rp-tags"><span class="tag <?= $r['target_total'] > 1 ? 'tag-amber' : '' ?>"><?= (int)$r['target_total'] ?> laporan terhadap akun ini</span><?php if ($r['target_open'] > 1): ?><span class="tag tag-red"><?= (int)$r['target_open'] ?> masih terbuka</span><?php endif; ?><?php if ($r['target_status'] !== 'active'): ?><span class="tag tag-red">Akun ditangguhkan</span><?php endif; ?></div>
      </div>
      <div class="rp-person">
        <div class="rp-label">Dilaporkan oleh</div>
        <div class="rp-who"><div><a href="<?= e(url(profile_url($r['reporter_user']))) ?>"><strong><?= e($r['reporter_name']) ?></strong></a><div class="sub">@<?= e($r['reporter_user']) ?></div></div></div>
      </div>
    </div>
    <?php if ($isCm): ?>
    <div class="rp-box rp-comment"><b>Komentar yang dilaporkan<?= (int)$r['cm_reports'] > 1 ? ' · ' . (int)$r['cm_reports'] . ' laporan' : '' ?><?= $r['cm_body'] === null || $r['cm_deleted'] !== null ? ' · sudah dihapus' : '' ?></b>
      <p><?= nl2br(e($cmAlive ? (string)$r['cm_body'] : ($snap !== '' ? $snap : (string)$r['cm_body']))) ?></p>
      <?php if ($r['cm_post']): ?><a class="rp-link" href="<?= e(url('post.php?id=' . (int)$r['cm_post'] . '#cm-' . (int)$r['comment_id'])) ?>" target="_blank" rel="noopener">Lihat di postingan ↗</a><?php endif; ?></div>
    <?php endif; ?>
    <?php if ($note !== ''): ?><div class="rp-box"><b>Keterangan pelapor</b><p><?= nl2br(e($note)) ?></p></div><?php endif; ?>
    <?php if ($r['post_id'] && !$isCm): ?><a class="rp-box rp-post" href="<?= e(url('post.php?id=' . (int)$r['post_id'])) ?>" target="_blank" rel="noopener"><b>Postingan terkait</b><span>“<?= e(mb_strimwidth((string)$r['post_title'], 0, 90, '…')) ?>” <small>(<?= e(ucfirst((string)$r['post_status'])) ?>) · buka ↗</small></span></a><?php endif; ?>
    <?php if ($open): ?>
    <form method="post" class="rp-actions"><?= csrf_field() ?><input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
      <a class="btn btn-light" href="<?= e(url(profile_url($r['target_user']))) ?>" title="Buka profil lalu pakai menu ⋯ untuk menangguhkan atau mengatur status Terpercaya">Buka profil &amp; kelola akun →</a>
      <span class="rp-spacer"></span>
      <?php if ($cmCanDel): ?><button class="btn btn-danger" name="report_decision" value="delete_comment" onclick="return confirm('Hapus komentar ini beserta balasannya? Laporan akan ditandai ditangani dan pemilik komentar diberi tahu.')"><?= icon('trash') ?> Hapus komentar</button><?php endif; ?>
      <button class="btn btn-light" name="report_decision" value="dismiss" onclick="return confirm('Abaikan laporan ini? Pelapor akan diberi tahu bahwa belum ada pelanggaran.')">Abaikan</button>
      <button class="btn btn-primary" name="report_decision" value="resolve"><?= icon('check') ?> Tandai ditangani</button>
    </form>
    <div class="rp-hint">Tip: tangguhkan akun lewat profil terlebih dahulu bila terbukti melanggar, lalu tandai laporan ini ditangani.</div>
    <?php else: ?><div class="rp-foot"><?= $r['status'] === 'resolved' ? 'Ditangani' : 'Diabaikan' ?> oleh @<?= e($r['handler'] ?? '—') ?><?= $r['handled_at'] ? ' · ' . e(time_ago($r['handled_at'])) : '' ?></div><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if ($page > 1 || $more): ?>
  <div class="pager">
    <?php if ($page > 1): ?><a class="btn btn-light" href="<?= e($rl(['page' => $page - 1])) ?>">← Sebelumnya</a><?php endif; ?>
    <span class="sub" style="align-self:center">Halaman <?= $page ?></span>
    <?php if ($more): ?><a class="btn btn-light" href="<?= e($rl(['page' => $page + 1])) ?>">Berikutnya →</a><?php endif; ?>
  </div>
  <?php endif; ?>
<?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>