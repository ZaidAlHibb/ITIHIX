<?php
/** Ikon Lucide (lucide.dev, lisensi ISC) sebagai SVG inline; warna mengikuti currentColor. */
function reel_icon(string $name, int $size = 26): string
{
    static $p = [
        'heart'   => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
        'comment' => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
        'bookmark' => '<path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/>',
        'share'   => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
        'more'    => '<circle cx="12" cy="12" r="1.2"/><circle cx="19" cy="12" r="1.2"/><circle cx="5" cy="12" r="1.2"/>',
        'link'    => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'user'    => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'flag'    => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/>',
        'trash'   => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
        'volume-x' => '<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="22" x2="16" y1="9" y2="15"/><line x1="16" x2="22" y1="9" y2="15"/>',
    ];
    return '<svg class="ic" xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}
$pageTitle = 'SafeSnaps';
$active = 'reels';
require __DIR__ . '/includes/header.php';

if (is_post()) {
    csrf_check();
    $cats  = parse_categories($_POST['categories'] ?? []);
    $subs  = parse_subs($_POST['subs'] ?? [], $cats);
    $labels = parse_labels($_POST['labels'] ?? []);
    $body  = trim((string)($_POST['body'] ?? ''));
    $note  = trim((string)($_POST['note'] ?? ''));
    $errors = [];
    if (mb_strlen($body) < 5) {
        $errors[] = 'Caption minimal 5 karakter.';
    }
    if (!$cats) {
        $errors[] = 'Pilih minimal satu kategori.';
    } elseif (count($cats) > MAX_CATEGORIES) {
        $errors[] = 'Pilih maksimal ' . MAX_CATEGORIES . ' kategori.';
    }
    if (!$labels) {
        $errors[] = 'Pilih minimal satu label.';
    }
    if (count($subs) > MAX_SUBS) {
        $errors[] = 'Pilih maksimal ' . MAX_SUBS . ' jenis modus.';
    }
    if (mb_strlen($body) > 1000) {
        $errors[] = 'Caption maksimal 1000 karakter.';
    }
    if (mb_strlen($note) > 255) {
        $errors[] = 'Catatan moderator maksimal 255 karakter.';
    }
    if (!rate_limit('short', 6, 3600)) {
        $errors[] = 'Kamu mengunggah terlalu banyak video. Coba lagi dalam satu jam.';
    }
    $v = ['ok' => true, 'path' => null];
    if (!$errors) {
        $v = save_upload($_FILES['video'] ?? ['error' => UPLOAD_ERR_NO_FILE], 'video'); // hanya video
        if (!$v['ok']) {
            $errors[] = $v['error'];
        } elseif (!$v['path']) {
            $errors[] = 'Pilih video terlebih dahulu.';
        }
    }
    if ($errors) {
        if (wants_form_json()) {
            json_out(['ok' => false, 'errors' => array_values($errors)]);
        }
        flash($errors[0], 'err');
        redirect('safesnaps.php');
    }
    $direct = can_publish_directly($me);   // staf + akun Terpercaya: tayang tanpa moderasi
    // kolom judul tetap terisi otomatis dari caption (tidak ditampilkan)
    $title = mb_substr(trim(preg_replace('/\s+/u', ' ', $body)), 0, 160);
    q(
        'INSERT INTO posts (user_id, type, title, category, body, video_path, user_label, user_note, status, final_label, reviewed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [$me['id'], 'short', $title, $cats[0], $body, $v['path'], $labels[0], $note, $direct ? 'approved' : 'pending', $direct ? $labels[0] : null, $direct ? date('Y-m-d H:i:s') : null]
    );
    $pid = (int)db()->lastInsertId();
    set_post_categories($pid, $cats);
    set_post_subs($pid, $subs);
    set_post_labels($pid, 'user', $labels);
    if ($direct) {
        set_post_labels($pid, 'final', $labels);
    }
    save_hashtags($pid, $body);
    log_activity('short_upload', 'post', $pid, ($direct ? '[langsung tayang] ' : '[antrean] ') . mb_substr($title, 0, 100));
    if (!$direct) {
        foreach (qall('SELECT id FROM users WHERE role = "moderator" AND status = "active" LIMIT 30') as $m) {
            notify((int)$m['id'], 'square-check', 'SafeSnaps baru menunggu tinjauan', '“' . mb_substr($title, 0, 80) . '” oleh @' . $me['username'], 'moderator.php');
        }
    }
    flash($direct ? 'Video langsung tayang.' : 'Video masuk antrean moderasi. Kamu akan diberi tahu setelah ditinjau.');
    redirect('safesnaps.php');
}

$reels = fetch_posts(['where' => 'p.status = "approved" AND p.type = "short"', 'limit' => 30]);
$following = array_column(qall('SELECT followee_id FROM follows WHERE follower_id = ?', [$me['id']]), 'followee_id');
?>
<section id="page-reels" class="page">
  <button class="short-upload-plus reels-floating-upload" type="button" aria-label="Unggah SafeSnaps" title="Unggah SafeSnaps" onclick="ITIHIX.openShortUpload()"><?= icon('plus') ?></button>
  <div class="reels-tabs reels-floating-tabs"><button class="reels-tab active" data-mode="for-you" type="button">Untuk Anda</button><span>•</span><button class="reels-tab" data-mode="following" type="button">Mengikuti</button></div>
  <div class="reels-scroller" id="reelsScroller" aria-label="Feed SafeSnaps">
  <?php if (!$reels): ?>
    <div class="card" style="padding:30px;text-align:center;max-width:380px"><div style="font-size:34px"><?= icon('film') ?></div><h3>Belum ada video</h3><p class="sub">Jadilah yang pertama membagikan tips keamanan digital lewat SafeSnaps.</p><button class="btn btn-primary" onclick="ITIHIX.openShortUpload()">Unggah video</button></div>
  <?php endif;
foreach ($reels as $r): $isF = in_array($r['user_id'], $following) ? 'yes' : 'no'; ?>
    <article class="reel-card" data-id="<?= (int)$r['id'] ?>" data-following="<?= $isF ?>">
      <div class="reel-progress"><span></span></div>
      <div class="reel-top-overlay"><span class="live-label"><i class="live-dot"></i> SafeSnaps</span><button class="reel-mute" type="button" data-act="reel-mute" aria-label="Nyalakan suara" title="Suara"><?= reel_icon('volume-x', 20) ?></button></div>
      <video class="reel-media" src="<?= e(url('uploads/' . $r['video_path'])) ?>"<?= ($rp = video_thumb_url($r['video_path'])) ? ' poster="' . e($rp) . '"' : '' ?> loop muted playsinline preload="metadata" controlsList="nodownload noremoteplayback" disablePictureInPicture oncontextmenu="return false"></video>
      <div class="reel-pause-ind" aria-hidden="true"><?= icon('play') ?></div>
      <div class="reel-gradient"></div>
      <div class="reel-caption">
        <div class="reel-author"><a class="reel-profile" href="<?= e(url(profile_url($r['username']))) ?>" aria-label="Buka profil @<?= e($r['username']) ?>"><?php if (!empty($r['avatar_path'])): ?><span class="reel-avatar" style="padding:0;overflow:hidden"><img src="<?= e(url('uploads/' . $r['avatar_path'])) ?>" alt=""></span><?php else: ?><span class="reel-avatar"><?= e(initials($r['display_name'])) ?></span><?php endif; ?></a><a class="reel-profile reel-profile-name" href="<?= e(url(profile_url($r['username']))) ?>"><b>@<?= e($r['username']) ?></b></a>
          <?php if ((int)$r['user_id'] !== (int)$me['id']): ?><button class="reel-follow <?= $isF === 'yes' ? 'following' : '' ?>" type="button" data-act="reel-follow" data-id="<?= (int)$r['user_id'] ?>"><?= $isF === 'yes' ? icon('check') . ' Mengikuti' : icon('plus') . ' Ikuti' ?></button><?php endif; ?></div>
        <p><?= linkify_body($r['body']) ?></p>
      </div>
      <div class="reel-actions">
        <button class="reel-action <?= $r['liked'] ? 'liked' : '' ?>" type="button" data-act="like" data-id="<?= (int)$r['id'] ?>" aria-label="Suka"><span class="ra-ic"><?= reel_icon('heart') ?></span><small class="cnt"><?= (int)$r['like_count'] ?></small></button>
        <button class="reel-action" type="button" data-reel-comments="<?= (int)$r['id'] ?>" data-title="<?= e($r['title']) ?>" aria-label="Buka komentar"><span class="ra-ic"><?= reel_icon('comment') ?></span><small class="cnt"><?= (int)$r['comment_count'] ?></small></button>
        <button class="reel-action <?= $r['saved'] ? 'liked' : '' ?>" type="button" data-act="save" data-id="<?= (int)$r['id'] ?>" aria-label="Simpan"><span class="ra-ic"><?= reel_icon('bookmark') ?></span><small>Simpan</small></button>
        <button class="reel-action" type="button" data-act="share" data-id="<?= (int)$r['id'] ?>" data-url="<?= e(url('post.php?id=' . $r['id'])) ?>" data-title="<?= e($r['title']) ?>" aria-label="Bagikan"><span class="ra-ic"><?= reel_icon('share') ?></span><small>Bagikan</small></button>
        <div class="post-menu reel-menu">
          <button class="reel-action reel-more" type="button" data-menu-toggle aria-haspopup="true" aria-expanded="false" aria-label="Opsi video" title="Opsi"><span class="ra-ic"><?= reel_icon('more') ?></span></button>
          <div class="post-menu-list" role="menu">
            <button class="post-menu-item" type="button" role="menuitem" data-act="copy-link" data-url="<?= e(url('post.php?id=' . $r['id'])) ?>"><?= reel_icon('link', 16) ?> Salin tautan</button>
            <a class="post-menu-item" role="menuitem" href="<?= e(url(profile_url($r['username']))) ?>"><?= reel_icon('user', 16) ?> Lihat profil @<?= e($r['username']) ?></a>
            <?php if ((int)$r['user_id'] !== (int)$me['id']): ?><button class="post-menu-item" type="button" role="menuitem" data-act="report-user" data-id="<?= (int)$r['user_id'] ?>" data-name="@<?= e($r['username']) ?>" data-post="<?= (int)$r['id'] ?>"><?= reel_icon('flag', 16) ?> Laporkan</button><?php endif; ?>
            <?php if (can_delete_post($me, $r)): ?><button class="post-menu-item danger" type="button" role="menuitem" data-act="delete" data-id="<?= (int)$r['id'] ?>"><?= reel_icon('trash', 16) ?> Hapus video</button><?php endif; ?>
          </div>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
  <aside class="card reel-comments" id="reelComments" aria-label="Komentar video">
    <div class="rc-head"><div style="min-width:0"><strong>Komentar</strong><div class="sub rc-title" id="rcTitle"></div></div><button class="rc-close" type="button" id="rcClose" aria-label="Tutup komentar"><?= icon('xmark') ?></button></div>
    <div class="rc-list" id="rcList"></div>
    <form class="rc-form" id="rcForm"><input class="field" maxlength="1000" placeholder="Tulis komentar…" required autocomplete="off"><button class="btn btn-primary" type="submit">Kirim</button></form>
  </aside>
</section>
<style>
.ht-wrap{position:relative}
.ht-list{position:absolute;left:0;right:0;top:calc(100% - 6px);z-index:30;max-height:200px;overflow:auto;padding:5px;border:1px solid var(--line);border-radius:12px;background:#fff;box-shadow:0 10px 30px rgba(20,20,30,.14)}
.ht-item{display:flex;justify-content:space-between;gap:10px;width:100%;padding:8px 10px;border:0;border-radius:9px;background:transparent;font:inherit;font-size:13px;text-align:left;cursor:pointer;color:inherit}
.ht-item.active,.ht-item:hover{background:var(--red-soft);color:var(--red-dark)}
.ht-item small{color:var(--muted)}
.ht-reco{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:2px 0 4px}
.ht-reco .sub{margin-right:2px}
.ht-chip{padding:4px 10px;border:1px solid var(--line);border-radius:999px;background:#fff;font:inherit;font-size:12px;font-weight:700;color:var(--red-dark);cursor:pointer}
.ht-chip:hover{background:var(--red-soft)}
body.dark .ht-list,body.dark .ht-chip{background:#292a33;border-color:#3b3c47}
body.dark .ht-item.active,body.dark .ht-item:hover,body.dark .ht-chip:hover{background:#3a2026}
.short-upload-dialog.ss-wide{width:min(100%,640px)}
</style>
<div id="shortUploadModal" class="short-upload-modal" role="dialog" aria-modal="true" aria-labelledby="shortUploadTitle">
  <form class="short-upload-dialog ss-wide" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="short-upload-head"><h2 id="shortUploadTitle">Unggah SafeSnaps</h2><button class="short-upload-close" type="button" aria-label="Tutup" onclick="ITIHIX.closeShortUpload()"><?= icon('xmark') ?></button></div>
    <div class="sub"><?= can_publish_directly($me) ? 'Sebagai ' . e(role_label($me['role'])) . ', videomu langsung tayang.' : 'Bagikan tips keamanan digital. Moderator akan meninjau sebelum tayang.' ?></div>
    <label class="short-video-drop" for="shortVideoFile"><span style="font-size:28px"><?= icon('film') ?></span><strong>Pilih video dari perangkat</strong><small>Hanya video · MP4, WebM, atau MOV · maks. <?= MAX_VIDEO_MB ?> MB</small>
      <input id="shortVideoFile" name="video" type="file" accept="video/mp4,video/webm,video/quicktime" required></label>
    <div class="short-preview" id="shortPreview" hidden>
      <video id="shortPreviewVideo" controls muted playsinline preload="metadata"></video>
      <div class="short-preview-info"><span id="shortPreviewName"></span><button type="button" id="shortPreviewClear" class="short-preview-clear">Ganti video</button></div>
    </div>
    <label class="field-label">Kategori</label>
    <?= category_picker('categories', [array_keys(category_defs())[0]]) ?>
    <?= sub_picker('subs', [], [array_keys(category_defs())[0]]) ?>
    <label class="field-label"><?= can_publish_directly($me) ? 'Label video' : 'Saran label untuk moderator' ?></label>
    <?= label_picker('labels', ['edukasi']) ?>
    <label class="field-label" for="reportBody">Caption video</label>
    <div class="ht-wrap" id="htBox" data-seeds="<?= e(json_encode(category_seed_tags(), JSON_UNESCAPED_UNICODE)) ?>">
      <textarea class="textarea" id="reportBody" name="body" required minlength="5" maxlength="1000" style="min-height:86px" placeholder="Ceritakan tips atau konteks video ini... #WaspadaDigital"></textarea>
      <div class="ht-list hidden" id="htList" role="listbox" aria-label="Saran tagar"></div>
    </div>
    <div class="ht-reco" id="htReco" aria-live="polite"></div>
    <label class="field-label">Catatan untuk moderator (opsional)</label>
    <input class="field" name="note" maxlength="255" placeholder="Sumber informasi atau konteks tambahan">
    <div class="short-upload-moderation"><?= icon('shield') ?> <strong>Samarkan data pribadi</strong> di video dan caption.</div>
    <button class="btn btn-primary" type="submit" style="width:100%"><?= can_publish_directly($me) ? 'Tayangkan' : 'Kirim untuk ditinjau' ?></button>
  </form>
</div>
<style>
.reel-caption{min-width:0;box-sizing:border-box;max-width:calc(100% - 84px)}
.reel-caption p{margin:0;max-width:100%;overflow-wrap:anywhere;word-break:break-word;white-space:pre-wrap;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;line-clamp:2;overflow:hidden;cursor:pointer}
.reel-caption p.expanded{display:block;-webkit-line-clamp:unset;line-clamp:unset;max-height:34vh;overflow-y:auto;padding-right:4px}
.reel-caption p a{overflow-wrap:anywhere}
</style>
<script>
document.addEventListener('click', function (e) {
  var p = e.target.closest && e.target.closest('.reel-caption p');
  if (!p || e.target.closest('a')) return;
  p.classList.toggle('expanded');
});
</script>
<?php $extraJs = '<script src="' . e(url('assets/js/hashtag-suggest.js')) . '?v=' . (int)@filemtime(__DIR__ . '/assets/js/hashtag-suggest.js') . '"></script>'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>