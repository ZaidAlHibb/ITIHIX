<?php
/** Query & render postingan (feed, profil, explore). */
require_once __DIR__ . '/labels.php';

function fetch_posts(array $o = [], ?int $viewerId = null): array
{
    $viewerId = $viewerId ?? (int)(current_user()['id'] ?? 0);
    $where  = $o['where']  ?? 'p.status = "approved"';
    $params = $o['params'] ?? [];
    $join   = $o['join']   ?? '';
    $order  = $o['order']  ?? 'p.created_at DESC';
    // Postingan milik akun yang ditangguhkan disembunyikan (kecuali diminta eksplisit, mis. oleh admin)
    $suspendedSql = !empty($o['include_suspended']) ? '' : ' AND u.status = "active"';
    $limit  = max(1, min(60, (int)($o['limit'] ?? 15)));
    $offset = max(0, (int)($o['offset'] ?? 0));
    $params['uid'] = $viewerId;
    $sql = 'SELECT p.*, u.username, u.display_name, u.role, u.level, u.xp, u.show_level, u.avatar_color, u.avatar_path,
              m.username AS mod_username,
              (SELECT COUNT(*) FROM likes    WHERE post_id = p.id) AS like_count,
              (SELECT COUNT(*) FROM reposts  WHERE post_id = p.id) AS repost_count,
              (SELECT COUNT(*) FROM shares   WHERE post_id = p.id) AS share_count,
              (SELECT COUNT(*) FROM comments WHERE post_id = p.id AND deleted_at IS NULL) AS comment_count,
              EXISTS(SELECT 1 FROM likes   WHERE post_id = p.id AND user_id = :uid) AS liked,
              EXISTS(SELECT 1 FROM reposts WHERE post_id = p.id AND user_id = :uid) AS reposted,
              EXISTS(SELECT 1 FROM saves   WHERE post_id = p.id AND user_id = :uid) AS saved
            FROM posts p
            JOIN users u ON u.id = p.user_id
            LEFT JOIN users m ON m.id = p.moderator_id ' . $join . '
            WHERE p.deleted_at IS NULL' . $suspendedSql . ' AND (' . $where . ')
            ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
    $rows = qall($sql, $params);
    if ($rows) {
        $ids = array_column($rows, 'id');
        $in = implode(',', array_map('intval', $ids));
        $tags = qall('SELECT ph.post_id, h.tag FROM post_hashtags ph JOIN hashtags h ON h.id = ph.hashtag_id WHERE ph.post_id IN (' . $in . ')');
        $map = [];
        foreach ($tags as $t) {
            $map[$t['post_id']][] = $t['tag'];
        }
        foreach ($rows as &$r) {
            $r['tags'] = $map[$r['id']] ?? [];
        }
        unset($r);
        $rows = attach_post_labels($rows);
    }
    return $rows;
}

function get_post(int $id, bool $includeSuspended = false): ?array
{
    $r = fetch_posts(['where' => 'p.id = :pid', 'params' => ['pid' => $id], 'limit' => 1, 'include_suspended' => $includeSuspended]);
    return $r[0] ?? null;
}

function linkify_body(string $text): string
{
    $safe = e($text);
    $safe = preg_replace_callback('/(?<!&)#([\p{L}\p{N}_]{2,40})/u', function ($m) {
        return '<a href="' . e(url('explore.php?tag=' . urlencode($m[1]))) . '" style="color:var(--red-dark);font-weight:700">#' . $m[1] . '</a>';
    }, $safe);
    return nl2br($safe);
}

/** Label status postingan terbit, disimpan di tabel status_badges (dibuat & diisi otomatis bila belum ada). */
function status_badges(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = ['admin' => 'OLEH ADMIN', 'moderator' => 'OLEH MODERATOR', 'trusted' => 'PELAPOR TERVERIFIKASI', 'verified' => 'LAPORAN TERVERIFIKASI']; // cadangan bila database bermasalah
    try {
        foreach (qall('SELECT code, label FROM status_badges ORDER BY sort_order') as $r) {
            $cache[$r['code']] = $r['label'];
        }
    } catch (Throwable $e) {
        error_log('status_badges: ' . $e->getMessage());
    }
    return $cache;
}

function status_tag(array $p): string
{
    $badge = status_badges();
    switch ($p['status']) {
        case 'approved':
            if ($p['moderator_id']) {
                return '<a class="tag tag-green" style="text-decoration:none" href="' . e(url(profile_url((string)$p['mod_username']))) . '" title="Ditinjau oleh @' . e($p['mod_username']) . '">' . icon('check') . ' ' . e($badge['verified']) . '</a>';
            }
            if (in_array($p['role'], ['admin', 'moderator', 'trusted'], true)) {
                return '<span class="tag tag-green">' . icon('check') . ' ' . e($badge[$p['role']]) . '</span>';
            }
            return ''; // hanya 4 label status: OLEH ADMIN, OLEH MODERATOR, PELAPOR TERVERIFIKASI, LAPORAN TERVERIFIKASI
        case 'pending': return '<span class="tag tag-amber">' . icon('hourglass') . ' Menunggu moderator</span>';
        case 'clarify': return '<span class="tag tag-amber">? Perlu klarifikasi</span>';
        case 'rejected': return '<span class="tag tag-red">' . icon('xmark') . ' Ditolak</span>';
    }
    return '';
}

/** Siapa yang boleh menghapus postingan: pemilik, admin (semua), moderator (hanya postingan pengguna biasa). */
function can_delete_post(?array $viewer, array $p): bool
{
    if (!$viewer) {
        return false;
    }
    if ((int)$viewer['id'] === (int)$p['user_id']) {
        return true;
    }
    if ($viewer['role'] === 'admin') {
        return true;
    }
    return $viewer['role'] === 'moderator' && in_array($p['role'] ?? 'user', ['user', 'trusted'], true);
}

/** Daftar media (foto/video) sebuah postingan; jatuh balik ke image_path/video_path untuk data lama. */
function post_media_items(array $p): array
{
    $items = [];
    try {
        foreach (qall('SELECT kind, path FROM post_media WHERE post_id = ? ORDER BY sort_order, id', [(int)$p['id']]) as $m) {
            $items[] = ['kind' => $m['kind'], 'path' => $m['path']];
        }
    } catch (Throwable $e) {
        error_log('[ITIHIX] ' . basename(__FILE__) . ': ' . $e->getMessage());
    }
    if (!$items) {
        if (!empty($p['image_path'])) {
            $items[] = ['kind' => 'image', 'path' => $p['image_path']];
        }
        if (!empty($p['video_path'])) {
            $items[] = ['kind' => 'video', 'path' => $p['video_path']];
        }
    }
    return $items;
}

/** Galeri ala Instagram/X: 1 media tampil penuh, 2+ jadi carousel geser dengan penghitung & titik. */
function render_media_gallery(array $items, string $variant = 'feed'): string
{
    $n = count($items);
    if (!$n) {
        return '';
    }
    $h = '<div class="media-gallery mg-' . e($variant) . ($n > 1 ? ' is-multi' : '') . '" data-gallery data-count="' . $n . '"><div class="mg-track">';
    foreach ($items as $it) {
        $u = e(url('uploads/' . $it['path']));
        if ($it['kind'] === 'video') {
            $poster = video_thumb_url($it['path']);
            $h .= '<div class="mg-slide"><div class="itx-vwrap"><video class="itx-video" data-itx-video controls playsinline controlsList="nodownload noremoteplayback" disablePictureInPicture oncontextmenu="return false" preload="none"' . ($poster ? ' poster="' . e($poster) . '"' : '') . '>'
                . '<source src="' . $u . ($poster ? '' : '#t=0.1') . '" type="' . (strtolower(pathinfo($it['path'], PATHINFO_EXTENSION)) === 'webm' ? 'video/webm' : 'video/mp4') . '"></video></div></div>';
        } else {
            $h .= '<div class="mg-slide"><a href="' . $u . '" target="_blank" rel="noopener"><img src="' . $u . '" alt="Lampiran laporan" loading="lazy"></a></div>';
        }
    }
    $h .= '</div>';
    if ($n > 1) {
        $h .= '<span class="mg-count" data-mg-count>1/' . $n . '</span>'
            . '<button class="mg-nav mg-prev" type="button" data-mg-prev aria-label="Sebelumnya" hidden>‹</button>'
            . '<button class="mg-nav mg-next" type="button" data-mg-next aria-label="Berikutnya">›</button>'
            . '<div class="mg-dots">' . str_repeat('<i></i>', $n) . '</div>';
    }
    return $h . '</div>';
}

function render_post(array $p, ?array $viewer = null, array $opt = []): string
{
    $viewer = $viewer ?? current_user();
    $id = (int)$p['id'];
    $own = $viewer && (int)$viewer['id'] === (int)$p['user_id'];
    $canDelete = can_delete_post($viewer, $p);
    $approved = $p['status'] === 'approved';
    $verified = role_badge((string)$p['role']);
    $lvl = $p['show_level'] ? ' · <b>Lv. ' . user_level($p) . '</b>' : '';
    $labels = $p['labels'] ?? effective_labels($p);
    $cats = $p['categories'] ?? [(string)$p['category']];
    $labelHtml = '';
    if ($p['type'] !== 'short') {
        if ($p['status'] === 'approved') {
            foreach ($labels as $lb) {
                $labelHtml .= label_chip($lb);
            }
        } else {
            $labelHtml = '<span class="tag" style="background:#f1f1f5;color:#666" title="Saran dari pengguna, belum dipastikan moderator">Saran: ' . e(implode(', ', array_map(fn ($l) => label_defs()[$l]['name'] ?? ucfirst($l), $labels))) . '</span>';
        }
    }
    $aiHtml = $p['ai_generated'] ? '<a class="tag tag-red" href="' . e(url('detect.php')) . '" style="text-decoration:none" title="Dibuat dengan bantuan ItihScan AI. Klik untuk mencoba.">' . icon('sparkles') . ' ItihScan AI</a>' : '';

    ob_start(); ?>
<article class="card post<?= !empty($opt['detail']) ? ' post-detail' : '' ?>" id="post-<?= $id ?>" data-id="<?= $id ?>" data-href="<?= e(url('post.php?id=' . $id)) ?>">
  <div class="post-head">
    <a href="<?= e(url(profile_url($p['username']))) ?>" style="text-decoration:none"><?= avatar_html($p) ?></a>
    <div class="post-author">
      <strong><a href="<?= e(url(profile_url($p['username']))) ?>" style="color:inherit;text-decoration:none"><?= e($p['display_name']) ?></a><?= $verified ?></strong>
      <div class="meta">@<?= e($p['username']) ?> · <a href="<?= e(url('post.php?id=' . $id)) ?>" style="color:inherit"><?= e(time_ago($p['created_at'])) ?></a><?= $lvl ?></div>
    </div>
    <?= status_tag($p) ?>
    <?php if ($approved || $canDelete): ?>
    <div class="post-menu">
      <button class="action post-menu-btn" type="button" data-menu-toggle aria-haspopup="true" aria-expanded="false" aria-label="Opsi postingan" title="Opsi">⋯</button>
      <div class="post-menu-list" role="menu">
        <?php if ($approved): ?>
        <button class="post-menu-item" type="button" role="menuitem" data-act="copy-link" data-url="<?= e(url('post.php?id=' . $id)) ?>"><?= icon('link') ?> Salin tautan</button>
        <?php if ($viewer): ?><a class="post-menu-item" role="menuitem" href="<?= e(url(profile_url($p['username']))) ?>"><?= icon('user') ?> Lihat profil @<?= e($p['username']) ?></a><?php endif; ?>
        <?php if (!$own): ?><button class="post-menu-item" type="button" role="menuitem" data-act="report-user" data-id="<?= (int)$p['user_id'] ?>" data-name="@<?= e($p['username']) ?>" data-post="<?= $id ?>"><?= icon('flag') ?> Laporkan</button><?php endif; ?>
        <?php endif; ?>
        <?php if ($canDelete): ?><button class="post-menu-item danger" type="button" role="menuitem" data-act="delete" data-id="<?= $id ?>"><?= $own ? icon('trash') . ' Hapus postingan' : icon('trash') . ' Hapus (' . e(role_label($viewer['role'])) . ')' ?></button><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php if (($p['type'] ?? '') !== 'short'): ?><h3><?= e($p['title']) ?></h3><?php endif; ?>
  <p><?= linkify_body($p['body']) ?></p>
  <?php if (trim((string)($p['scan_text'] ?? '')) !== ''): ?><div class="post-scan"><b><?= icon('sparkles') ?> Teks yang diperiksa ItihScan AI</b><p><?= e($p['scan_text']) ?></p></div><?php endif; ?>
  <?= render_media_gallery(post_media_items($p)) ?>
  <?php if ($p['status'] === 'approved' && array_intersect($labels, ['penipuan', 'mencurigakan']) && $p['type'] === 'report'): ?>
    <div class="warning-box"><div class="warning-icon"><?= icon('warn') ?></div><div><strong>Indikasi penipuan digital</strong><span>Kategori: <?= e(implode(', ', $cats)) ?>. Verifikasi lewat kanal resmi sebelum mengikuti instruksi apa pun.</span></div></div>
  <?php endif; ?>
  <?php if ($p['status'] === 'clarify' && $p['moderator_note'] && $own): ?>
    <div class="notice" style="margin:10px 0"><b>Catatan moderator:</b> <?= e($p['moderator_note']) ?><div style="margin-top:8px"><a class="btn btn-primary" href="<?= e(url('edit_report.php?id=' . $id)) ?>" style="text-decoration:none"><?= icon('pen') ?> Revisi &amp; kirim ulang</a></div></div>
  <?php endif; ?>
  <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
    <?php foreach ($cats as $ct) {
        echo category_chip($ct);
    } foreach ($p['subs'] ?? [] as $sx) {
        echo sub_chip($sx['category'], $sx['sub']);
    } ?><?= $labelHtml ?><?= $aiHtml ?>
  </div>
  <?php if ($p['status'] === 'approved'): ?>
  <div class="post-stats">
    <button class="action <?= $p['liked'] ? 'liked' : '' ?>" type="button" data-act="like" data-id="<?= $id ?>"><?= icon('heart-o', 'ic-off') ?><?= icon('heart', 'ic-on') ?> <span class="cnt"><?= (int)$p['like_count'] ?></span></button>
    <button class="action" type="button" data-act="comments" data-id="<?= $id ?>"><?= icon('comment') ?> <span class="cnt"><?= (int)$p['comment_count'] ?></span> komentar</button>
    <button class="action <?= $p['reposted'] ? 'liked' : '' ?>" type="button" data-act="repost" data-id="<?= $id ?>"><?= icon('retweet') ?> <span class="cnt"><?= (int)$p['repost_count'] ?></span> repost</button>
    <button class="action" type="button" data-act="share" data-id="<?= $id ?>" data-url="<?= e(url('post.php?id=' . $id)) ?>" data-title="<?= e($p['title']) ?>"><?= icon('share') ?> Bagikan <span class="sub"><?= (int)$p['share_count'] ?: '' ?></span></button>
    <button class="action <?= $p['saved'] ? 'liked' : '' ?>" type="button" data-act="save" data-id="<?= $id ?>" title="Simpan"><?= icon('bookmark', 'ic-off') ?><?= icon('bookmark-fill', 'ic-on') ?></button>
    <span class="spacer"></span><?php if ($p['type'] === 'short'): ?><a class="action" href="<?= e(url('safesnaps.php?v=' . $id)) ?>" style="text-decoration:none" title="Buka di SafeSnaps"><?= icon('film') ?> SafeSnaps</a><?php else: ?><span class="sub"><?= $p['type'] === 'education' ? 'Panduan' : 'Laporan komunitas' ?></span><?php endif; ?>
  </div>
  <div class="comments hidden" id="comments-<?= $id ?>" data-loaded="0"></div>
  <?php endif; ?>
</article>
<?php
    return ob_get_clean();
}

function render_posts(array $posts, ?array $viewer = null, string $empty = 'Belum ada postingan.'): string
{
    if (!$posts) {
        return '<div class="card" style="padding:28px;text-align:center;color:var(--muted)">' . e($empty) . '</div>';
    }
    return implode("\n", array_map(fn ($p) => render_post($p, $viewer), $posts));
}

/** Kartu laporan milik sendiri yang belum terbit (menunggu, perlu klarifikasi, ditolak) untuk tab Pending di profil. */
function render_my_report_card(array $p): string
{
    $id = (int)$p['id'];
    $st = $p['status'];
    $resent = $st === 'pending' && !empty($p['moderator_id']);          // pernah ditinjau lalu dikirim ulang
    $note = trim((string)($p['moderator_note'] ?? ''));
    $chips = '';
    foreach ($p['categories'] ?? [] as $c) {
        $chips .= category_chip($c, false);
    }
    foreach ($p['subs'] ?? [] as $x) {
        $chips .= '<span class="tag tag-sub">' . icon('subarrow') . ' ' . e($x['sub']) . '</span>';
    }
    foreach ($p['labels'] ?? [] as $l) {
        $chips .= label_chip($l, false);
    }
    $by = !empty($p['mod_username']) ? ' oleh @' . e($p['mod_username']) : '';
    $when = !empty($p['reviewed_at']) ? ' · ditinjau ' . e(time_ago($p['reviewed_at'])) . $by : '';
    $box = '';
    if ($st === 'clarify') {
        $box = '<div class="notice notice-clarify" style="margin:10px 0 0"><b>? Moderator meminta klarifikasi' . $when . ':</b> ' . e($note !== '' ? $note : 'Mohon lengkapi bukti dan konteks laporan.') . '</div>';
    } elseif ($st === 'rejected') {
        $box = '<div class="notice notice-reject" style="margin:10px 0 0"><b>' . icon('xmark') . ' Alasan penolakan' . $when . ':</b> ' . e($note !== '' ? $note : 'Laporan belum memenuhi pedoman komunitas.') . '</div>';
    } elseif ($resent) {
        $box = '<div class="notice" style="margin:10px 0 0"><b>' . icon('undo') . ' Revisi terkirim, menunggu tinjauan ulang.</b>'
             . ($note !== '' ? '<br>Permintaan sebelumnya: ' . e($note) : '') . (($p['user_note'] ?? '') !== '' ? '<br>Jawabanmu: ' . e($p['user_note']) : '') . '</div>';
    } else {
        $box = '<div class="notice" style="margin:10px 0 0">' . icon('hourglass') . ' Menunggu moderator memeriksa bukti dan konteks laporanmu.</div>';
    }
    $act = '<a class="btn btn-light" href="' . e(url('post.php?id=' . $id)) . '" style="text-decoration:none">Lihat detail</a>';
    if ($st === 'clarify') {
        $act = '<a class="btn btn-primary" href="' . e(url('edit_report.php?id=' . $id)) . '" style="text-decoration:none">' . icon('pen') . ' Revisi &amp; kirim ulang</a>' . $act;
    } elseif ($st === 'rejected') {
        $act .= '<a class="btn btn-light" href="' . e(url('upload.php')) . '" style="text-decoration:none">' . icon('plus') . ' Buat laporan baru</a>';
    }
    return '<div class="card" style="padding:16px 18px;margin-bottom:12px" id="my-report-' . $id . '">'
        . '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px">' . status_tag($p) . ($resent ? '<span class="tag tag-muted">' . icon('undo') . ' Dikirim ulang</span>' : '')
        . '<span class="sub" style="margin-left:auto">Dikirim ' . e(time_ago($p['created_at'])) . '</span></div>'
        . '<h3 style="margin:0 0 4px;font-size:15px"><a href="' . e(url('post.php?id=' . $id)) . '" style="color:inherit;text-decoration:none">' . e($p['title']) . '</a></h3>'
        . '<p class="sub" style="margin:0 0 8px">' . e(mb_strimwidth(preg_replace('/\s+/u', ' ', (string)$p['body']), 0, 160, '…')) . '</p>'
        . '<div style="display:flex;gap:6px;flex-wrap:wrap">' . $chips . '</div>' . $box
        . '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">' . $act . '</div></div>';
}
