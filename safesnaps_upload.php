<?php
/**
 * Unggah SafeSnaps — formulir sama seperti "Buat laporan" (judul, kategori, jenis modus, caption + saran tagar,
 * video, catatan moderator), tetapi TANPA pilihan label. Video tayang di SafeSnaps tanpa label laporan.
 */
$pageTitle = 'Unggah SafeSnaps';
$active = 'reels';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/media_dropzone.php';

$categories = array_keys(category_defs());
$f = ['title' => '', 'categories' => [$categories[0]], 'subs' => [], 'body' => '', 'note' => ''];
$errors = [];

if (is_post()) {
    csrf_check();
    $f['title'] = trim((string)($_POST['title'] ?? ''));
    $f['categories'] = parse_categories($_POST['categories'] ?? []);
    $f['subs'] = parse_subs($_POST['subs'] ?? [], $f['categories']);
    $f['body'] = trim((string)($_POST['body'] ?? ''));
    $f['note'] = trim((string)($_POST['note'] ?? ''));
    if (mb_strlen($f['title']) < 5 || mb_strlen($f['title']) > 160) {
        $errors[] = 'Judul harus 5–160 karakter.';
    }
    if (!$f['categories']) {
        $errors[] = 'Pilih minimal satu kategori.';
    } elseif (count($f['categories']) > MAX_CATEGORIES) {
        $errors[] = 'Pilih maksimal ' . MAX_CATEGORIES . ' kategori.';
    }
    if (count($f['subs']) > MAX_SUBS) {
        $errors[] = 'Pilih maksimal ' . MAX_SUBS . ' jenis modus.';
    }
    if (mb_strlen($f['body']) > 1000) {
        $errors[] = 'Caption maksimal 1000 karakter.';
    }
    if (mb_strlen($f['note']) > 255) {
        $errors[] = 'Catatan moderator maksimal 255 karakter.';
    }
    if (!rate_limit('short', 6, 3600)) {
        $errors[] = 'Kamu mengunggah terlalu banyak video. Coba lagi dalam satu jam.';
    }
    $media = save_media_files($_FILES['media'] ?? [], 1);
    if (!$media['ok']) {
        $errors[] = $media['error'];
    } elseif (!$media['items']) {
        $errors[] = 'Pilih video terlebih dahulu.';
    } elseif ($media['items'][0]['kind'] !== 'video') {
        $errors[] = 'SafeSnaps hanya menerima video (MP4, WebM, atau MOV).';
    }
    if ($errors) {
        foreach ($media['items'] ?? [] as $it) {
            if (!empty($it['abs'])) {
                @unlink($it['abs']);
            }
        }
    } // batalkan file yang sudah tersimpan
    if ($errors && wants_form_json()) {
        json_out(['ok' => false, 'errors' => array_values($errors)]);
    }
    if (!$errors) {
        $direct = can_publish_directly($me);   // staf + akun Terpercaya: tayang tanpa moderasi
        $vpath = $media['items'][0]['path'];
        $body = $f['body'] !== '' ? $f['body'] : $f['title'];
        q(
            'INSERT INTO posts (user_id, type, title, category, body, video_path, user_label, user_note, status, final_label, reviewed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$me['id'], 'short', $f['title'], $f['categories'][0], $body, $vpath, 'edukasi', $f['note'],
           $direct ? 'approved' : 'pending', $direct ? 'edukasi' : null, $direct ? date('Y-m-d H:i:s') : null]
        );
        $pid = (int)db()->lastInsertId();
        set_post_categories($pid, $f['categories']);
        set_post_subs($pid, $f['subs']);
        try {
            attach_post_media($pid, $media['items']);
        } catch (Throwable $e) {
            error_log('[ITIHIX] ' . basename(__FILE__) . ': ' . $e->getMessage());
        }
        save_hashtags($pid, $body);
        log_activity('short_upload', 'post', $pid, ($direct ? '[langsung tayang] ' : '[antrean] ') . mb_substr($f['title'], 0, 100));
        if ($direct) {
            flash('Video langsung tayang di SafeSnaps.');
            redirect('safesnaps.php');
        }
        foreach (qall('SELECT id FROM users WHERE role = "moderator" AND status = "active" LIMIT 30') as $m) {
            notify((int)$m['id'], 'square-check', 'SafeSnaps baru menunggu tinjauan', '“' . mb_substr($f['title'], 0, 80) . '” oleh @' . $me['username'], 'moderator.php');
        }
        notify((int)$me['id'], 'hourglass', 'SafeSnaps-mu sedang ditinjau', 'Moderator akan memeriksa video sebelum tayang.', 'profile.php?tab=pending');
        flash('Video masuk antrean moderasi. Kamu akan diberi tahu setelah ditinjau.');
        redirect('safesnaps.php');
    }
}
?>
<style>
.ht-wrap{position:relative}
.ht-list{position:absolute;left:0;right:0;top:calc(100% - 6px);z-index:30;max-height:240px;overflow:auto;padding:5px;border:1px solid var(--line);border-radius:12px;background:#fff;box-shadow:0 10px 30px rgba(20,20,30,.14)}
.ht-item{display:flex;justify-content:space-between;gap:10px;width:100%;padding:8px 10px;border:0;border-radius:9px;background:transparent;font:inherit;font-size:13px;text-align:left;cursor:pointer;color:inherit}
.ht-item.active,.ht-item:hover{background:var(--red-soft);color:var(--red-dark)}
.ht-item small{color:var(--muted)}
.ht-reco{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:2px 0 4px}
.ht-reco .sub{margin-right:2px}
.ht-chip{padding:4px 10px;border:1px solid var(--line);border-radius:999px;background:#fff;font:inherit;font-size:12px;font-weight:700;color:var(--red-dark);cursor:pointer}
.ht-chip:hover{background:var(--red-soft)}
body.dark .ht-list,body.dark .ht-chip{background:#292a33;border-color:#3b3c47}
body.dark .ht-item.active,body.dark .ht-item:hover,body.dark .ht-chip:hover{background:#3a2026}
</style>
<section id="page-upload" class="page">
  <div class="page-heading"><div><h1>Unggah SafeSnaps</h1><div class="sub"><?= can_publish_directly($me) ? 'Sebagai ' . e(role_label($me['role'])) . ', videomu langsung tayang.' : 'Bagikan tips keamanan digital lewat video singkat. Moderator akan meninjau sebelum tayang di SafeSnaps.' ?></div></div></div>
  <div class="card" style="padding:20px">
    <div class="notice"><?= icon('shield') ?> <b>Utamakan keamanan:</b> samarkan nomor telepon, alamat, nama lengkap, dan informasi pribadi orang lain. Gunakan #tagar di caption agar mudah ditemukan.</div>
    <?php foreach ($errors as $er): ?><div class="alert-err" style="margin-top:12px"><?= e($er) ?></div><?php endforeach; ?>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <label class="field-label">Judul video</label>
      <input class="field" name="title" required maxlength="160" value="<?= e($f['title']) ?>" placeholder="Contoh: 3 tanda link undian palsu">
      <label class="field-label">Kategori</label>
      <?= category_picker('categories', $f['categories']) ?>
      <?= sub_picker('subs', array_column($f['subs'], 'sub'), $f['categories']) ?>
      <label class="field-label">Caption video (opsional)</label>
      <div class="ht-wrap" id="htBox" data-seeds="<?= e(json_encode(category_seed_tags(), JSON_UNESCAPED_UNICODE)) ?>">
      <textarea class="textarea" id="reportBody" name="body" maxlength="1000" style="min-height:120px" placeholder="Ceritakan tips atau konteks video ini... #WaspadaDigital"><?= e($f['body']) ?></textarea>
      <div class="ht-list hidden" id="htList" role="listbox" aria-label="Saran tagar"></div>
      </div>
      <div class="ht-reco" id="htReco" aria-live="polite"></div>
      <label class="field-label">Video</label>
      <?= media_dropzone(1) ?>
      <label class="field-label">Catatan untuk moderator (opsional)</label>
      <input class="field" name="note" maxlength="255" value="<?= e($f['note']) ?>" placeholder="Sumber informasi atau konteks tambahan">
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px"><a class="btn btn-light" href="<?= e(url('safesnaps.php')) ?>">Batal</a><button class="btn btn-primary"><?= can_publish_directly($me) ? 'Tayangkan' : 'Kirim untuk ditinjau' ?> →</button></div>
    </form>
  </div>
</section>
<?php $extraJs = '<script src="' . e(url('assets/js/hashtag-suggest.js')) . '?v=' . (int)@filemtime(__DIR__ . '/assets/js/hashtag-suggest.js') . '"></script>'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>