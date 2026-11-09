<?php
$pageTitle = 'Profil';
$active = 'profile';
require_once __DIR__ . '/includes/bootstrap.php';
// Alamat lama /profile.php?u=nama -> /nama (tautan lama yang sudah tersebar tetap jalan)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['u']) && preg_match('#/profile(\.php)?(\?|$)#i', (string)($_SERVER['REQUEST_URI'] ?? ''))) {
    $legacyU = trim((string)$_GET['u']);
    if ($legacyU !== '' && preg_match('/^[A-Za-z0-9_]{3,30}$/', $legacyU) && !username_reserved($legacyU)) {
        $legacyQ = $_GET;
        unset($legacyQ['u']);
        redirect(profile_url($legacyU, $legacyQ));
    }
}
require __DIR__ . '/includes/header.php';

$uname = trim((string)($_GET['u'] ?? ''));
$pu = $uname === '' ? $me : qone('SELECT * FROM users WHERE username = ?', [$uname]);
if ($pu && $pu['status'] !== 'active' && !is_staff($me)) {
    $pu = null;
} // akun ditangguhkan hanya terlihat oleh staf
if (!$pu) {
    http_response_code(404);
    echo '<div class="card" style="padding:30px;text-align:center"><h2>Pengguna tidak ditemukan</h2><a class="btn btn-primary" href="' . e(url('index.php')) . '">Beranda</a></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}
$isMe = (int)$pu['id'] === (int)$me['id'];
if (!$isMe) {
    $active = '';
}
$pid = (int)$pu['id'];
// Staf: tangguhkan / aktifkan akun, beri / cabut status Terpercaya (aturan sama dengan moderator.php; admin juga boleh menangguhkan moderator)
$canTrust = in_array($pu['role'], ['user', 'trusted'], true);
$canManage = is_staff($me) && !$isMe && (($me['role'] === 'admin') ? $pu['role'] !== 'admin' : $canTrust);
if (is_post() && isset($_POST['staff_action'])) {
    csrf_check();
    $back = profile_url($pu['username']);
    if (!$canManage) {
        flash('Kamu tidak dapat mengelola akun ini.', 'err');
        redirect($back);
    }
    $act = (string)$_POST['staff_action'];
    $why = trim(mb_substr((string)($_POST['reason'] ?? ''), 0, 200));
    $pre = $me['role'] === 'admin' ? 'admin_' : 'mod_';
    if ($act === 'suspend' && $pu['status'] === 'active') {
        q('UPDATE users SET status = "suspended" WHERE id = ?', [$pid]);
        log_activity($pre . 'suspend', 'user', $pid, 'Akun @' . $pu['username'] . ' ditangguhkan dari profil' . ($why !== '' ? ' — alasan: ' . $why : ''));
        flash('Akun @' . $pu['username'] . ' ditangguhkan.');
    } elseif ($act === 'activate' && $pu['status'] !== 'active') {
        q('UPDATE users SET status = "active" WHERE id = ?', [$pid]);
        log_activity($pre . 'activate', 'user', $pid, 'Akun @' . $pu['username'] . ' diaktifkan kembali dari profil');
        flash('Akun @' . $pu['username'] . ' diaktifkan kembali.');
    } elseif ($act === 'make_trusted' && $pu['role'] === 'user') {
        q('UPDATE users SET role = "trusted" WHERE id = ?', [$pid]);
        log_activity($pre . 'set_role', 'user', $pid, 'Peran @' . $pu['username'] . ' diubah menjadi trusted');
        notify($pid, 'square-check', 'Akunmu kini Terpercaya', 'Laporanmu sekarang langsung terbit tanpa antrean moderasi.');
        flash('@' . $pu['username'] . ' kini berstatus Terpercaya.');
    } elseif ($act === 'remove_trusted' && $pu['role'] === 'trusted') {
        q('UPDATE users SET role = "user" WHERE id = ?', [$pid]);
        log_activity($pre . 'set_role', 'user', $pid, 'Peran @' . $pu['username'] . ' diubah menjadi user');
        notify($pid, 'square-check', 'Status Terpercaya dicabut', 'Laporanmu kembali melalui antrean moderasi sebelum terbit.');
        flash('Status Terpercaya @' . $pu['username'] . ' dicabut.');
    } else {
        flash('Aksi tidak sesuai dengan status akun saat ini.', 'err');
    }
    redirect($back);
}
$isFollowing = !$isMe && (bool)qval('SELECT 1 FROM follows WHERE follower_id = ? AND followee_id = ?', [$me['id'], $pid]);
[$dmOk, $dmWhy] = $isMe ? [false, ''] : dm_can_send($me, $pu);

// Hitung hanya akun aktif, supaya angka sama dengan isi daftar Pengikut / Mengikuti
$followers = (int)qval('SELECT COUNT(*) FROM follows f JOIN users u ON u.id = f.follower_id AND u.status = "active" WHERE f.followee_id = ?', [$pid]);
$following = (int)qval('SELECT COUNT(*) FROM follows f JOIN users u ON u.id = f.followee_id AND u.status = "active" WHERE f.follower_id = ?', [$pid]);
$postCount = (int)qval('SELECT COUNT(*) FROM posts WHERE user_id = ? AND status = "approved" AND deleted_at IS NULL', [$pid]);
$li = level_info((int)$pu['xp']);
$tab = $_GET['tab'] ?? 'posts';
$allowed = $isMe ? ['posts', 'pending', 'reposts', 'likes', 'comments', 'saved'] : ['posts', 'reposts', 'likes', 'comments'];
$followTabs = ['followers', 'following']; // daftar siapa yang mengikuti / diikuti (dibuka dari angka Pengikut / Mengikuti)
if ($pu['show_level'] || $isMe) {
    $allowed[] = 'level';
} // info level & gelar kini jadi tab sendiri
$bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$joined = $bulan[(int)date('n', strtotime($pu['created_at']))] . ' ' . date('Y', strtotime($pu['created_at']));
$pc = ['pending' => 0, 'clarify' => 0, 'rejected' => 0];
if ($isMe) {
    foreach (qall('SELECT status, COUNT(*) c FROM posts WHERE user_id = ? AND deleted_at IS NULL AND type <> "short" AND status IN ("pending","clarify","rejected") GROUP BY status', [$pid]) as $r) {
        $pc[$r['status']] = (int)$r['c'];
    }
}
$pendingTotal = array_sum($pc);
if (!in_array($tab, $allowed, true) && !in_array($tab, $followTabs, true)) {
    $tab = 'posts';
}
$inFollowTab = in_array($tab, $followTabs, true);
// Privasi per tab: Repost / Disukai / Komentar bisa disembunyikan pemilik (pemilik & staf tetap bisa melihat)
$privCols = ['reposts' => 'hide_reposts', 'likes' => 'hide_likes', 'comments' => 'hide_comments'];
$privName = ['reposts' => 'Repost', 'likes' => 'Postingan yang disukai', 'comments' => 'Komentar'];
$tabHidden = fn (string $t): bool => isset($privCols[$t]) && !empty($pu[$privCols[$t]]);
$canSeeAll = $isMe || is_staff($me);
$base = url(profile_url($pu['username']));
$sep = str_contains($base, '?') ? '&' : '?';
$profLink = fn (array $q = []): string => url(profile_url($pu['username'], $q));
?>
<?php
/** Info level & gelar: ditampilkan sebagai isi tab "Level" di profil. */
$lvCard = function () use ($li, $pu) {
    $tiers = level_tiers();
    $totalXp = (int)$pu['xp'];
    $nextTier = null;
    foreach ($tiers as $t) {
        if ($t['min'] > $li['level']) {
            $nextTier = $t;
            break;
        }
    }
    ?>
  <div class="card lv-card-d is-static" id="levelCard">
    <div class="lv-head">
      <div class="lv-head-row">
        <div>
          <span class="tag lv-badge"><?= icon('star') ?> LEVEL <?= $li['level'] ?> / <?= MAX_LEVEL ?></span>
          <h3 style="margin:10px 0 4px"><?= e(level_title($li['level'])) ?></h3>
          <div class="sub">Konsisten membantu komunitas mengenali risiko digital.</div>
        </div>
        <div class="lv-shield"><?= icon('shield') ?></div>
      </div>
      <div class="progress lv-progress"><span style="width:<?= (int)$li['pct'] ?>%"></span></div>
      <div class="sub"><?= $li['need'] ? number_format($li['cur'], 0, ',', '.') . ' / ' . number_format($li['need'], 0, ',', '.') . ' XP menuju Level ' . ($li['level'] + 1) : 'Level maksimal tercapai' ?> · total <?= number_format($totalXp, 0, ',', '.') ?> XP</div>
      <?php if ($nextTier): $lvLeft = $nextTier['min'] - $li['level'];
          $xpLeft = max(0, xp_to_reach($nextTier['min']) - $totalXp); ?>
      <div class="lv-next"><?= icon('target') ?> <span><b><?= $lvLeft ?> level lagi</b> (± <?= number_format($xpLeft, 0, ',', '.') ?> XP) menuju gelar <b><?= e($nextTier['title']) ?></b> di Level <?= $nextTier['min'] ?></span></div>
      <?php else: ?><div class="lv-next"><?= icon('crown') ?> <span>Gelar tertinggi, <b>Legenda ITIHIX</b>, sudah diraih.</span></div><?php endif; ?>
    </div>
    <div class="lv-panel">
      <ol class="lv-tiers">
        <?php foreach ($tiers as $t):
            $state = $li['level'] >= $t['min'] ? ($li['level'] <= $t['max'] ? 'now' : 'done') : 'locked'; ?>
        <li class="lv-tier <?= $state ?>">
          <span class="lv-ico"><?= icon($t['icon']) ?></span>
          <div class="lv-body">
            <div class="lv-name"><?= e($t['title']) ?>
              <?php if ($state === 'now'): ?><span class="tag tag-red">Gelar saat ini</span><?php elseif ($state === 'done'): ?><span class="tag tag-green"><?= icon('check') ?> Tercapai</span><?php else: ?><span class="tag" style="background:#f1f1f5;color:#777"><?= icon('lock') ?> Terkunci</span><?php endif; ?></div>
            <div class="lv-range"><?= $t['min'] === $t['max'] ? 'Level ' . $t['min'] : 'Level ' . $t['min'] . '–' . $t['max'] ?> · butuh <?= number_format(xp_to_reach($t['min']), 0, ',', '.') ?> XP total</div>
            <div class="sub"><?= e($t['desc']) ?></div>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
      <div class="lv-xp">
        <b>Cara mendapatkan XP</b>
        <ul>
          <li>Kirim laporan <b>+5 XP</b> <small>(maks. 5×/hari)</small></li>
          <li>Laporan disetujui moderator <b>+20 XP</b></li>
          <li>Jawab benar Scam or Real? <b>+10 s.d. +20 XP</b> <small>(makin sulit, makin besar)</small></li>
          <li>Periksa pesan dengan ItihScan AI <b>+5 XP</b> <small>(maks. 5×/hari)</small></li>
          <li>Berkomentar <b>+2 XP</b> <small>(maks. 5×/hari)</small></li>
          <li>Selesaikan 3 Misi Harian <b>+250 XP</b> <small>(sekali/hari)</small></li>
        </ul>
        <div class="sub">XP dari semua fitur digabung dibatasi maksimal <b>250 XP per hari</b> (Misi Harian dihitung terpisah).</div>
        <div class="sub">XP untuk naik dari level L = 100 + 10×(L−1). Semakin tinggi level, semakin banyak XP yang dibutuhkan.</div>
      </div>
    </div>
  </div>
<?php }; ?>
<section id="page-profile" class="page">
  <?php if ($pu['status'] !== 'active'): ?><div class="notice" style="margin-bottom:12px"><b>Akun ini sedang ditangguhkan.</b> Hanya staf yang dapat melihat profil ini. Gunakan menu ⋯ untuk mengaktifkannya kembali.</div><?php endif; ?>
  <div class="card pf-card">
    <?php $bn = (isset($pu['banner_color']) && preg_match('/^#[0-9a-f]{6}$/i', (string)$pu['banner_color'])) ? $pu['banner_color'] : null; ?>
    <div class="pf-cover<?= $bn ? ' has-color' : '' ?>"<?= $bn ? ' style="--bn:' . e($bn) . '"' : '' ?>></div>
    <div class="pf-body">
      <div class="pf-top">
        <?= avatar_html($pu, '', 'profile-avatar') ?>
        <div class="pf-actions">
          <?php if ($isMe): ?><a class="btn btn-light" href="<?= e(url('settings.php')) ?>" data-pfe-open><?= icon('pen') ?> Edit profil</a>
          <?php else: ?><button class="btn <?= $isFollowing ? 'btn-light' : 'btn-primary' ?>" type="button" data-act="follow" data-id="<?= $pid ?>"><?= $isFollowing ? icon('check') . ' Mengikuti' : icon('plus') . ' Ikuti' ?></button><a class="btn btn-light" id="dmBtn" href="<?= e(url('messages.php?u=' . urlencode($pu['username']))) ?>" data-dm-locked="<?= e($dmOk ? '' : $dmWhy) ?>" style="text-decoration:none"><?= icon('envelope') ?> Pesan</a><?php endif; ?>
          <div class="post-menu pf-menu">
            <button class="btn btn-light pf-icon-btn pf-more" type="button" data-menu-toggle aria-haspopup="true" aria-expanded="false" aria-label="Opsi akun" title="Opsi">⋯</button>
            <div class="post-menu-list" role="menu">
              <button class="post-menu-item" type="button" role="menuitem" data-act="copy-link" data-url="<?= e(url(profile_url($pu['username']))) ?>"><?= icon('copy') ?> Salin akun</button>
              <button class="post-menu-item" type="button" role="menuitem" data-act="share-profile" data-url="<?= e(url(profile_url($pu['username']))) ?>" data-title="<?= e($pu['display_name']) ?> (@<?= e($pu['username']) ?>) di ITIHIX"><?= icon('share') ?> Bagikan akun</button>
              <?php if (!$isMe): ?><button class="post-menu-item danger" type="button" role="menuitem" data-act="report-user" data-id="<?= $pid ?>" data-name="@<?= e($pu['username']) ?>"><?= icon('flag') ?> Laporkan pengguna</button><?php endif; ?>
              <?php if ($canManage): ?>
              <div class="post-menu-sep"></div>
              <form method="post" class="pf-staff-form"><?= csrf_field() ?><input type="hidden" name="reason" value="">
                <?php if ($pu['role'] === 'user'): ?><button class="post-menu-item" role="menuitem" name="staff_action" value="make_trusted" onclick="return confirm('Beri status Terpercaya kepada @<?= e($pu['username']) ?>? Laporannya akan langsung terbit tanpa antrean moderasi.')"><?= icon('square-check') ?> Beri status Terpercaya</button><?php endif; ?>
                <?php if ($pu['role'] === 'trusted'): ?><button class="post-menu-item" role="menuitem" name="staff_action" value="remove_trusted" onclick="return confirm('Cabut status Terpercaya @<?= e($pu['username']) ?>?')"><?= icon('xmark') ?> Cabut status Terpercaya</button><?php endif; ?>
                <?php if ($pu['status'] === 'active'): ?><button class="post-menu-item danger" role="menuitem" name="staff_action" value="suspend" onclick="var r = prompt('Alasan penangguhan @<?= e($pu['username']) ?> (opsional, tersimpan di log):'); if (r === null) return false; this.form.reason.value = r; return true;"><?= icon('xmark') ?> Tangguhkan akun</button>
                <?php else: ?><button class="post-menu-item" role="menuitem" name="staff_action" value="activate" onclick="return confirm('Aktifkan kembali akun ini?')"><?= icon('check') ?> Aktifkan kembali akun</button><?php endif; ?>
              </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="pf-name">
        <h2><?= e($pu['display_name']) ?></h2>
        <?php if ($pu['show_level'] || $isMe): ?><span class="pf-lv" title="<?= e(level_title($li['level'])) ?>">Lv. <?= (int)$li['level'] ?></span><?php endif; ?>
        <?= role_badge((string)$pu['role']) ?>
      </div>
      <div class="pf-handle">@<?= e($pu['username']) ?> · Bergabung <?= e($joined) ?></div>
      <?php if ($pu['bio']): ?><p class="pf-bio"><?= nl2br(e($pu['bio'])) ?></p><?php endif; ?>
      <div class="pf-stats"><a class="pf-stat<?= $tab === 'followers' ? ' on' : '' ?>" href="<?= e($profLink(['tab' => 'followers'])) ?>" title="Lihat siapa yang mengikuti @<?= e($pu['username']) ?>"><strong id="followerCount"><?= $followers ?></strong> <span>Pengikut</span></a><a class="pf-stat<?= $tab === 'following' ? ' on' : '' ?>" href="<?= e($profLink(['tab' => 'following'])) ?>" title="Lihat siapa yang diikuti @<?= e($pu['username']) ?>"><strong id="followingCount"><?= $following ?></strong> <span>Mengikuti</span></a><div><strong><?= $postCount ?></strong> <span>Laporan</span></div></div>
    </div>
  </div>

  <div class="tabs profile-tabs">
    <?php $names = ['posts' => 'Postingan', 'pending' => 'Pending' . ($pendingTotal ? ' (' . $pendingTotal . ')' : ''), 'reposts' => 'Repost', 'likes' => 'Disukai', 'comments' => 'Komentar', 'saved' => 'Tersimpan', 'level' => 'Level'];
foreach ($allowed as $t): ?>
      <a class="tab <?= $tab === $t ? 'active' : '' ?>" href="<?= e($base . $sep . 'tab=' . $t) ?>"><?= $names[$t] ?><?= $tabHidden($t) ? '<span class="pf-tab-lock" title="' . ($isMe ? 'Privat: hanya kamu yang melihat' : 'Privat') . '">' . icon('lock') . '</span>' : '' ?><?= $t === 'pending' && $pc['clarify'] ? ' <span class="dot" style="position:static;display:inline-block;vertical-align:middle" title="Ada laporan yang perlu klarifikasi"></span>' : '' ?></a>
    <?php endforeach; ?>
  </div>

<?php if (!$canSeeAll && $tabHidden($tab)): ?>
  <div class="card" style="padding:34px;text-align:center"><div style="font-size:32px"><?= icon('lock') ?></div><h3><?= e($privName[$tab]) ?> bersifat privat</h3><p class="sub">@<?= e($pu['username']) ?> memilih menyembunyikan bagian ini.</p></div>
<?php else:
    $opts = ['limit' => 30, 'params' => ['pu' => $pid]];
    if ($inFollowTab) {
        $isFollowers = $tab === 'followers';
        $perPage = 30;
        $pg = max(1, min(500, (int)($_GET['page'] ?? 1)));
        // Urutkan dari yang terbaru bila tabel follows punya kolom created_at; bila tidak, dari id terbaru
        $fHasTime = (bool)qone('SHOW COLUMNS FROM follows LIKE "created_at"');
        $self = $isFollowers ? 'f.follower_id' : 'f.followee_id';   // sisi yang ditampilkan
        $anchor = $isFollowers ? 'f.followee_id' : 'f.follower_id'; // sisi pemilik profil
        $order = ($fHasTime ? 'f.created_at DESC, ' : '') . $self . ' DESC';
        $rows = qall('SELECT u.id, u.username, u.display_name, u.bio, u.avatar_path, u.avatar_color, u.role, u.xp, u.show_level,
                (SELECT 1 FROM follows x WHERE x.follower_id = ? AND x.followee_id = u.id LIMIT 1) i_follow,
                (SELECT 1 FROM follows y WHERE y.follower_id = u.id AND y.followee_id = ? LIMIT 1) follows_me
            FROM follows f JOIN users u ON u.id = ' . $self . ' AND u.status = "active"
            WHERE ' . $anchor . ' = ? ORDER BY ' . $order . ' LIMIT ' . ($perPage + 1) . ' OFFSET ' . (($pg - 1) * $perPage), [(int)$me['id'], (int)$me['id'], $pid]);
        $hasNext = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);
        $pill = fn (string $k, string $label, int $n) => '<a class="tag ' . ($tab === $k ? 'tag-red' : 'tag-muted') . '" href="' . e($profLink(['tab' => $k])) . '" style="text-decoration:none;padding:7px 12px;font-size:12px">' . $label . ' · ' . $n . '</a>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">' . $pill('followers', 'Pengikut', $followers) . $pill('following', 'Mengikuti', $following) . '</div>';
        if (!$rows) {
            $who = $isMe ? 'Kamu' : '@' . e($pu['username']);
            echo '<div class="card pcm-empty"><div class="pcm-empty-ic">' . icon('user') . '</div><b>' . ($isFollowers ? 'Belum ada pengikut' : 'Belum mengikuti siapa pun') . '</b><span>' . ($isFollowers ? 'Orang yang mengikuti ' . $who . ' akan muncul di sini.' : 'Akun yang diikuti ' . $who . ' akan muncul di sini.') . '</span></div>';
        } else {
            echo '<div class="card fl-list">';
            foreach ($rows as $r) {
                $rid = (int)$r['id'];
                $rMe = $rid === (int)$me['id'];
                $href = url(profile_url($r['username']));
                echo '<div class="fl-row"><a class="fl-av" href="' . e($href) . '">' . avatar_html($r) . '</a>'
                    . '<a class="fl-info" href="' . e($href) . '"><span class="fl-name"><strong>' . e($r['display_name']) . '</strong>' . role_badge((string)$r['role']) . '</span>'
                    . '<span class="fl-handle">@' . e($r['username']) . ($r['show_level'] ? ' · Lv. ' . (int)level_info((int)$r['xp'])['level'] : '') . (!$rMe && $r['follows_me'] ? ' <span class="tag tag-muted fl-tag">Mengikutimu</span>' : '') . '</span>'
                    . ($r['bio'] ? '<span class="fl-bio">' . e(mb_strimwidth((string)preg_replace('/\s+/u', ' ', (string)$r['bio']), 0, 110, '…')) . '</span>' : '') . '</a>';
                if ($rMe) {
                    echo '<span class="tag tag-muted">Kamu</span>';
                } else {
                    $f = (bool)$r['i_follow'];
                    echo '<button class="btn ' . ($f ? 'btn-light' : 'btn-primary') . ' fl-btn" type="button" data-act="follow" data-id="' . $rid . '">' . ($f ? icon('check') . ' Mengikuti' : icon('plus') . ' Ikuti') . '</button>';
                }
                echo '</div>';
            }
            echo '</div>';
            if ($pg > 1 || $hasNext) {
                echo '<div class="fl-pager">' . ($pg > 1 ? '<a class="btn btn-light" href="' . e($profLink(['tab' => $tab] + ($pg > 2 ? ['page' => $pg - 1] : []))) . '">← Sebelumnya</a>' : '<span></span>')
                    . '<span class="sub">Halaman ' . $pg . '</span>'
                    . ($hasNext ? '<a class="btn btn-light" href="' . e($profLink(['tab' => $tab, 'page' => $pg + 1])) . '">Berikutnya →</a>' : '<span></span>') . '</div>';
            }
        }
    } elseif ($tab === 'posts') {
        $opts['where'] = 'p.user_id = :pu' . (!$isMe && $me['role'] === 'admin' ? '' : ' AND p.status = "approved"');
        echo render_posts(fetch_posts($opts), $me, 'Belum ada postingan.');
    } elseif ($tab === 'pending' && $isMe) {
        $sf = (string)($_GET['s'] ?? '');
        if (!in_array($sf, ['pending', 'clarify', 'rejected'], true)) {
            $sf = '';
        }
        $mk = fn (string $k, string $label, int $n, string $cls) => '<a class="tag ' . ($sf === $k ? $cls : 'tag-muted') . '" href="' . e($base . $sep . 'tab=pending' . ($k !== '' ? '&s=' . $k : '')) . '" style="text-decoration:none;padding:7px 12px;font-size:12px">' . $label . ' · ' . $n . '</a>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">' . $mk('', 'Semua', $pendingTotal, 'tag-red') . $mk('clarify', '? Perlu klarifikasi', $pc['clarify'], 'tag-amber') . $mk('pending', icon('hourglass') . ' Menunggu', $pc['pending'], 'tag-amber') . $mk('rejected', icon('xmark') . ' Ditolak', $pc['rejected'], 'tag-red') . '</div>';
        $opts['where'] = 'p.user_id = :pu AND p.type <> "short" AND ' . ($sf !== '' ? 'p.status = :st' : 'p.status IN ("pending","clarify","rejected")');
        if ($sf !== '') {
            $opts['params']['st'] = $sf;
        }
        $opts['order'] = 'FIELD(p.status, "clarify", "pending", "rejected"), p.created_at DESC';
        $mine = fetch_posts($opts);
        if (!$mine) {
            echo '<div class="card" style="padding:30px;text-align:center;color:var(--muted)">' . ($pendingTotal ? 'Tidak ada laporan pada filter ini.' : icon('party') . ' Tidak ada laporan yang sedang menunggu, perlu klarifikasi, atau ditolak.') . '</div>';
        }
        foreach ($mine as $r) {
            echo render_my_report_card($r);
        }
    } elseif ($tab === 'level') {
        $lvCard();
    } elseif (in_array($tab, ['reposts', 'likes', 'saved'], true)) {
        $tbl = ['reposts' => 'reposts', 'likes' => 'likes', 'saved' => 'saves'][$tab];
        $opts['join'] = "JOIN $tbl act ON act.post_id = p.id AND act.user_id = :pu";
        $opts['where'] = 'p.status = "approved"';
        $opts['order'] = 'act.created_at DESC';
        echo render_posts(fetch_posts($opts), $me, 'Belum ada aktivitas di sini.');
    } else {
        if (!function_exists('comment_body_html')) {
            require_once __DIR__ . '/includes/comments.php';
        }
        $hasParent = function_exists('comments_have_parent') && comments_have_parent();
        $hasVotes = function_exists('ensure_comment_votes') && ensure_comment_votes();
        $cs = qall('SELECT c.id, c.body, c.created_at, p.id post_id, p.title, p.type post_type, p.image_path, pa.username post_user, pa.display_name post_name,'
            . ($hasParent ? ' c.parent_id,' : ' NULL parent_id,')
            . ($hasVotes ? ' (SELECT COALESCE(SUM(v.vote), 0) FROM comment_votes v WHERE v.comment_id = c.id) score' : ' 0 score')
            . ' FROM comments c JOIN posts p ON p.id = c.post_id JOIN users pa ON pa.id = p.user_id AND pa.status = "active"'
            . ($hasParent ? '' : '')
            . ' WHERE c.user_id = ? AND c.deleted_at IS NULL AND p.deleted_at IS NULL AND p.status = "approved" ORDER BY c.id DESC LIMIT 40', [$pid]);
        if (!$cs) {
            echo '<div class="card pcm-empty"><div class="pcm-empty-ic">' . icon('comment') . '</div><b>Belum ada komentar</b><span>Komentar yang ditulis @' . e($pu['username']) . ' akan muncul di sini.</span></div>';
        } else {
            echo '<div class="pcm-count">' . count($cs) . ' komentar terbaru</div><div class="pcm-list">';
            comment_prefetch_mentions($cs);
        }
        foreach ($cs as $c):
            $link = url('post.php?id=' . $c['post_id']) . '#cm-' . (int)$c['id'];
            $thumb = !empty($c['image_path']) ? url('uploads/' . $c['image_path']) : '';
            $score = (int)$c['score'];
            $isReply = !empty($c['parent_id']);
            ?>
          <article class="card pcm-card">
            <a class="pcm-post" href="<?= e(url('post.php?id=' . $c['post_id'])) ?>">
              <span class="pcm-thumb"><?= $thumb ? '<img src="' . e($thumb) . '" alt="" loading="lazy" onerror="this.remove()">' : icon($c['post_type'] === 'education' ? 'lightbulb' : 'file') ?></span>
              <span class="pcm-post-txt"><em><?= $isReply ? 'Membalas komentar di postingan' : 'Berkomentar di postingan' ?></em><b><?= e($c['title']) ?></b><small>oleh @<?= e($c['post_user']) ?></small></span>
            </a>
            <div class="pcm-body<?= $isReply ? ' is-reply' : '' ?>">
              <?= comment_body_html((string)$c['body']) ?>
            </div>
            <div class="pcm-foot">
              <span class="pcm-score<?= $score > 0 ? ' up' : ($score < 0 ? ' down' : '') ?>" title="Skor komentar"><?= icon('arrow-down') ?> <?= e(comment_score_fmt($score)) ?></span>
              <span class="pcm-time"><?= e(time_ago($c['created_at'])) ?></span>
              <a class="pcm-open" href="<?= e($link) ?>">Lihat percakapan <?= icon('arrow-right') ?></a>
            </div>
          </article>
        <?php endforeach;
        if ($cs) {
            echo '</div>';
        }
    }
endif; ?>
</section>
<?php if ($isMe):
    $pfeColors = ['#ffe0e2', '#d9e8ff', '#fce2c5', '#e6dcff', '#d9f2e6', '#fff0c2', '#ffd9f0', '#dcdcdc'];
    $curColor = in_array($me['avatar_color'], $pfeColors, true) ? $me['avatar_color'] : '#ffe0e2';
    $pfeBanners = array_merge($pfeColors, ['#e53945', '#3b82f6', '#22a06b', '#7c5cff']);
    $curBanner = preg_match('/^#[0-9a-f]{6}$/i', (string)($me['banner_color'] ?? '')) ? strtolower($me['banner_color']) : '';
    $extraJs = ($extraJs ?? '') . '<script src="' . e(url('assets/js/profile-edit.js')) . '?v=' . (int)@filemtime(__DIR__ . '/assets/js/profile-edit.js') . '"></script>';
    ?>
<div class="gate hidden" id="pfeModal" role="dialog" aria-modal="true" aria-labelledby="pfeTitle">
  <div class="gate-box card pfe-box">
    <div class="pfe-head">
      <h3 id="pfeTitle">Edit profil</h3>
      <button class="gate-x" type="button" data-pfe-close aria-label="Tutup"><?= icon('xmark') ?></button>
    </div>

    <div class="pfe-scroll">
      <div class="pfe-photo">
        <div class="pf-cover pfe-bn<?= $curBanner ? ' has-color' : '' ?>" id="pfeBn"<?= $curBanner ? ' style="--bn:' . e($curBanner) . '"' : '' ?>></div>
        <div class="pfe-preview" id="pfePreview" data-initial="<?= e(initials($me['display_name'])) ?>" data-has-photo="<?= $me['avatar_path'] ? 1 : 0 ?>"><?= avatar_html($me, '', 'pfe-av') ?></div>

        <div class="gate hidden pfe-cropwin" id="pfeCrop" role="dialog" aria-modal="true" aria-labelledby="pfeCropTitle">
          <div class="gate-box card pfe-cropbox">
            <div class="pfe-head"><h3 id="pfeCropTitle">Atur foto profil</h3><button class="gate-x" type="button" id="pfeCropX" aria-label="Tutup"><?= icon('xmark') ?></button></div>
            <div class="pfe-crop">
              <div class="pfe-stage" id="pfeStage">
                <canvas id="pfeCanvas" tabindex="0" aria-label="Area potong foto. Geser untuk memposisikan, scroll atau cubit untuk zoom."></canvas>
                <div class="pfe-mask"></div><div class="pfe-ring"></div>
              </div>
              <div class="pfe-zoomrow">
                <button class="pfe-zbtn" type="button" id="pfeZoomOut" aria-label="Perkecil">−</button>
                <input type="range" id="pfeZoom" min="1" max="4" step="0.01" value="1" aria-label="Zoom">
                <button class="pfe-zbtn" type="button" id="pfeZoomIn" aria-label="Perbesar">+</button>
              </div>
              <div class="pfe-hint">Geser foto untuk memposisikan · scroll atau cubit untuk zoom</div>
            </div>
            <div class="pfe-foot">
              <button class="btn btn-light" type="button" id="pfeReset"><?= icon('undo') ?> Reset</button>
              <button class="btn btn-light" type="button" id="pfeCropCancel">Batal</button>
              <button class="btn btn-primary" type="button" id="pfeCropApply"><?= icon('check') ?> Terapkan</button>
            </div>
          </div>
        </div>

        <div class="pfe-photo-actions">
          <button class="btn btn-light" type="button" id="pfePick"><?= icon('image') ?> <?= $me['avatar_path'] ? 'Ganti foto' : 'Unggah foto' ?></button>
          <?php if ($me['avatar_path']): ?><button class="btn btn-light pfe-del" type="button" id="pfeRemove"><?= icon('trash') ?> <span>Hapus foto</span></button><?php endif; ?>
        </div>
        <input type="file" id="pfeFile" accept="image/*" hidden>
      </div>

      <label class="field-label" for="pfeName">Nama tampilan</label>
      <input class="field" id="pfeName" maxlength="60" value="<?= e($me['display_name']) ?>" autocomplete="off">

      <label class="field-label" for="pfeBio">Bio <span class="pfe-count" id="pfeBioN">0/280</span></label>
      <textarea class="textarea" id="pfeBio" maxlength="280" style="min-height:84px"><?= e($me['bio']) ?></textarea>

      <div id="pfeColorWrap">
        <label class="field-label">Warna avatar <span class="pfe-count">dipakai bila tanpa foto</span></label>
        <div class="pfe-colors" id="pfeColors" data-current="<?= e($curColor) ?>">
          <?php foreach ($pfeColors as $c): ?><label><input type="radio" name="pfe_color" value="<?= $c ?>" <?= $curColor === $c ? 'checked' : '' ?>><span style="background:<?= $c ?>"></span></label><?php endforeach; ?>
        </div>
      </div>
      <div id="pfeBannerWrap">
        <label class="field-label">Warna banner <span class="pfe-count">tampil di atas profil</span></label>
        <div class="pfe-swatches" id="pfeBanners" data-current="<?= e($curBanner) ?>">
          <button type="button" class="pfe-sw pfe-sw-def" data-color="" title="Bawaan" aria-label="Warna bawaan"></button>
          <?php foreach ($pfeBanners as $c): ?><button type="button" class="pfe-sw" data-color="<?= $c ?>" style="background:<?= $c ?>" aria-label="Warna <?= $c ?>"></button><?php endforeach; ?>
          <label class="pfe-sw pfe-sw-custom" id="pfeBannerCustomLabel" title="Warna kustom"><input type="color" id="pfeBannerCustom" value="<?= $curBanner ?: '#e53945' ?>" aria-label="Warna kustom"></label>
        </div>
      </div>
      <div class="pfe-more">Ubah nama pengguna, email, atau kata sandi di <a href="<?= e(url('settings.php')) ?>">Pengaturan</a>.</div>
    </div>

    <div class="pfe-foot">
      <button class="btn btn-light" type="button" data-pfe-close>Batal</button>
      <button class="btn btn-primary" type="button" id="pfeSave">Simpan</button>
    </div>
  </div>
</div>
<?php endif; ?>
<script>
/* Tautan lama profile.php#levelCard -> tab Level */
if (location.hash === '#levelCard' && location.search.indexOf('tab=level') < 0) { location.replace(location.pathname + (location.search ? location.search + '&' : '?') + 'tab=level'); }
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>