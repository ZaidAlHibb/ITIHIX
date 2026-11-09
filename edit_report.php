<?php
// Revisi laporan yang diminta klarifikasi oleh moderator, lalu kirim ulang ke antrean.
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_login();
require_once __DIR__ . '/includes/media_dropzone.php';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$p = qone('SELECT * FROM posts WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$id, $me['id']]);
if (!$p || $p['type'] !== 'report') {
    flash('Laporan tidak ditemukan.', 'err');
    redirect('profile.php?tab=pending');
}
if ($p['status'] !== 'clarify') {
    flash('Laporan ini tidak sedang meminta klarifikasi, jadi tidak dapat direvisi.', 'err');
    redirect('profile.php?tab=pending');
}

/** Tambahkan media baru ke laporan yang sudah punya media (data lama di image_path/video_path dipindah dulu ke post_media). */
function append_post_media(int $pid, array $existing, array $new): void
{
    if (!$new) {
        return;
    }
    $rows = (int)qval('SELECT COUNT(*) FROM post_media WHERE post_id = ?', [$pid]);
    if ($rows === 0 && $existing) {
        foreach (array_values($existing) as $i => $it) {
            q('INSERT INTO post_media (post_id, kind, path, sort_order) VALUES (?,?,?,?)', [$pid, $it['kind'], $it['path'], $i]);
        }
        $rows = count($existing);
    }
    foreach (array_values($new) as $i => $it) {
        q('INSERT INTO post_media (post_id, kind, path, sort_order) VALUES (?,?,?,?)', [$pid, $it['kind'], $it['path'], $rows + $i]);
    }
    foreach ($new as $it) {
        if ($it['kind'] === 'image') {
            q('UPDATE posts SET image_path = ? WHERE id = ? AND image_path IS NULL', [$it['path'], $pid]);
            break;
        }
    }
    foreach ($new as $it) {
        if ($it['kind'] === 'video') {
            q('UPDATE posts SET video_path = ? WHERE id = ? AND video_path IS NULL', [$it['path'], $pid]);
            break;
        }
    }
}

/** Hapus file gambar lama dari disk bila tidak dipakai lagi oleh postingan/analisis mana pun (supaya versi tanpa sensor tidak tersisa). */
function unlink_image_if_unused(string $path): void
{
    if ($path === '' || strpos($path, '..') !== false) {
        return;
    }
    $used = (int)qval('SELECT COUNT(*) FROM posts WHERE image_path = ? OR video_path = ?', [$path, $path]);
    try {
        $used += (int)qval('SELECT COUNT(*) FROM post_media WHERE path = ?', [$path]);
    } catch (Throwable $e) {
        error_log('[ITIHIX] ' . basename(__FILE__) . ': ' . $e->getMessage());
    }
    try {
        $used += (int)qval('SELECT COUNT(*) FROM detections WHERE image_path = ?', [$path]);
    } catch (Throwable $e) {
        error_log('[ITIHIX] ' . basename(__FILE__) . ': ' . $e->getMessage());
    }
    try {
        $used += (int)qval('SELECT COUNT(*) FROM detections WHERE LOCATE(?, images) > 0 OR LOCATE(?, images) > 0', [$path, str_replace('/', '\\/', $path)]);
    } catch (Throwable $e) {
        error_log('[ITIHIX] ' . basename(__FILE__) . ': ' . $e->getMessage());
    }
    if ($used > 0) {
        return;
    }
    $abs = UPLOAD_DIR . '/' . $path;
    if (is_file($abs)) {
        @unlink($abs);
    }
    $poster = UPLOAD_DIR . '/' . video_poster_rel($path);   // poster video (bila ada)
    if (preg_match('/\.(mp4|webm|mov)$/i', $path) && is_file($poster)) {
        @unlink($poster);
    }
}

/** Ganti foto lama dengan hasil edit (coretan/blur/mosaik). $repl: [idx => ['old' => path, 'new' => path, 'abs' => file]] */
function replace_post_images(int $pid, array $existing, array $repl): void
{
    try {
        $rows = (int)qval('SELECT COUNT(*) FROM post_media WHERE post_id = ?', [$pid]);
        if ($rows === 0 && $existing) { // data lama hanya di image_path/video_path: pindahkan dulu ke post_media
            foreach (array_values($existing) as $i => $it) {
                q('INSERT INTO post_media (post_id, kind, path, sort_order) VALUES (?,?,?,?)', [$pid, $it['kind'], $it['path'], $i]);
            }
        }
        foreach ($repl as $r) {
            q('UPDATE post_media SET path = ? WHERE post_id = ? AND path = ?', [$r['new'], $pid, $r['old']]);
        }
    } catch (Throwable $e) {
        error_log('[ITIHIX] ' . basename(__FILE__) . ': ' . $e->getMessage());
    }
    foreach ($repl as $r) {
        q('UPDATE posts SET image_path = ? WHERE id = ? AND image_path = ?', [$r['new'], $pid, $r['old']]);
    }
    foreach ($repl as $r) {
        unlink_image_if_unused($r['old']);
    }
}

/** Tarik (hapus) media dari laporan. $rm: [idx => path]. */
function remove_post_media(int $pid, array $existing, array $rm): void
{
    $viaTable = false;
    try {
        $rows = (int)qval('SELECT COUNT(*) FROM post_media WHERE post_id = ?', [$pid]);
        if ($rows === 0 && $existing) { // data lama hanya di image_path/video_path: pindahkan dulu ke post_media
            foreach (array_values($existing) as $i => $it) {
                q('INSERT INTO post_media (post_id, kind, path, sort_order) VALUES (?,?,?,?)', [$pid, $it['kind'], $it['path'], $i]);
            }
        }
        foreach ($rm as $path) {
            q('DELETE FROM post_media WHERE post_id = ? AND path = ?', [$pid, $path]);
        }
        $viaTable = true;
    } catch (Throwable $e) {
        error_log('[ITIHIX] ' . basename(__FILE__) . ': ' . $e->getMessage());
    }
    if ($viaTable) { // sinkronkan thumbnail (media pertama yang tersisa)
        $img = $vid = null;
        foreach (qall('SELECT kind, path FROM post_media WHERE post_id = ? ORDER BY sort_order, id', [$pid]) as $m) {
            if ($m['kind'] === 'image' && !$img) {
                $img = $m['path'];
            }
            if ($m['kind'] === 'video' && !$vid) {
                $vid = $m['path'];
            }
        }
        q('UPDATE posts SET image_path = ?, video_path = ? WHERE id = ?', [$img, $vid, $pid]);
    } else {
        foreach ($rm as $path) {
            q('UPDATE posts SET image_path = NULL WHERE id = ? AND image_path = ?', [$pid, $path]);
            q('UPDATE posts SET video_path = NULL WHERE id = ? AND video_path = ?', [$pid, $path]);
        }
    }
    foreach ($rm as $path) {
        unlink_image_if_unused($path);
    }
}

$existing = post_media_items($p);
$remain = max(0, MAX_MEDIA_PER_POST - count($existing));
$f = [
    'title' => $p['title'], 'body' => $p['body'], 'note' => '',
    'categories' => post_category_sets([$id])[$id] ?? [(string)$p['category']],
    'subs' => post_sub_sets([$id])[$id] ?? [],
    'labels' => post_label_sets([$id])[$id]['user'] ?? [(string)$p['user_label']],
];
$errors = [];

if (is_post()) {
    csrf_check();
    $f['title'] = trim((string)($_POST['title'] ?? ''));
    $f['body'] = trim((string)($_POST['body'] ?? ''));
    $f['note'] = trim((string)($_POST['note'] ?? ''));
    $f['categories'] = parse_categories($_POST['categories'] ?? []);
    $f['subs'] = parse_subs($_POST['subs'] ?? [], $f['categories']);
    $f['labels'] = parse_labels($_POST['labels'] ?? []);
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
    if (!$f['labels']) {
        $errors[] = 'Pilih minimal satu label.';
    }
    if (mb_strlen($f['body']) < 20 || mb_strlen($f['body']) > 4000) {
        $errors[] = 'Isi laporan harus 20–4000 karakter.';
    }
    if (mb_strlen($f['note']) < 5 || mb_strlen($f['note']) > 255) {
        $errors[] = 'Tuliskan jawaban / klarifikasimu untuk moderator (5–255 karakter).';
    }
    if (!$errors && !rate_limit('resubmit', 10, 3600)) {
        $errors[] = 'Terlalu sering mengirim ulang. Coba lagi nanti.';
    }
    // Media lama yang ditarik (remove[indeks] = path), dicocokkan lewat indeks + path
    $rm = [];
    if (!empty($_POST['remove']) && is_array($_POST['remove'])) {
        foreach ($_POST['remove'] as $ix => $rp) {
            $ix = (int)$ix;
            if (!isset($existing[$ix]) || (string)$rp !== $existing[$ix]['path']) {
                $errors[] = 'Media yang ditarik tidak cocok dengan laporan ini. Muat ulang halaman lalu coba lagi.';
                $rm = [];
                break;
            }
            $rm[$ix] = $existing[$ix]['path'];
        }
    }
    $remain = max(0, MAX_MEDIA_PER_POST - (count($existing) - count($rm)));   // slot yang ditarik bisa diisi media baru
    $media = save_media_files($_FILES['media'] ?? [], $remain);
    if (!$media['ok']) {
        $errors[] = $media['error'];
    }
    // Foto lama yang diedit di editor foto (dikirim sebagai replace[indeks]); dicocokkan lewat indeks + path lama
    $repl = [];
    if (!$errors && !empty($_FILES['replace']['name']) && is_array($_FILES['replace']['name'])) {
        foreach ($_FILES['replace']['name'] as $ix => $nm) {
            $ix = (int)$ix;
            $one = ['name' => $nm, 'type' => $_FILES['replace']['type'][$ix] ?? '', 'tmp_name' => $_FILES['replace']['tmp_name'][$ix] ?? '',
                    'error' => $_FILES['replace']['error'][$ix] ?? UPLOAD_ERR_NO_FILE, 'size' => $_FILES['replace']['size'][$ix] ?? 0];
            if ($one['error'] === UPLOAD_ERR_NO_FILE || isset($rm[$ix])) {
                continue;
            }   // yang ditarik tidak perlu diganti
            $old = $existing[$ix] ?? null;
            if (!$old || $old['kind'] !== 'image' || (string)($_POST['replace_path'][$ix] ?? '') !== $old['path']) {
                $errors[] = 'Foto yang diedit tidak cocok dengan laporan ini. Muat ulang halaman lalu coba lagi.';
                break;
            }
            $rs = save_upload($one, 'image');
            if (!$rs['ok'] || empty($rs['path'])) {
                $errors[] = 'Foto hasil edit gagal disimpan: ' . ($rs['error'] ?? 'gagal diunggah.');
                break;
            }
            $repl[$ix] = ['old' => $old['path'], 'new' => $rs['path'], 'abs' => $rs['abs']];
        }
        if ($errors) {
            foreach ($repl as $rp) {
                @unlink($rp['abs']);
            } $repl = [];
        }
    }
    if ($errors) {
        foreach ($media['items'] ?? [] as $it) {
            if (!empty($it['abs'])) {
                @unlink($it['abs']);
            }
        }
    }
    if ($errors && wants_form_json()) {
        json_out(['ok' => false, 'errors' => array_values($errors)]);
    }
    if (!$errors) {
        // hanya berlaku bila statusnya MASIH 'clarify' (mencegah kiriman ganda / balapan dengan moderator)
        $st = q(
            'UPDATE posts SET title = ?, category = ?, body = ?, user_label = ?, user_note = ?, status = "pending" WHERE id = ? AND user_id = ? AND status = "clarify"',
            [$f['title'], $f['categories'][0], $f['body'], $f['labels'][0], $f['note'], $id, $me['id']]
        );
        if ($st->rowCount() < 1) {
            foreach ($media['items'] as $it) {
                @unlink($it['abs']);
            }
            foreach ($repl as $rp) {
                @unlink($rp['abs']);
            }
            flash('Status laporan sudah berubah, muat ulang halaman.', 'err');
            redirect('profile.php?tab=pending');
        }
        set_post_categories($id, $f['categories']);
        set_post_subs($id, $f['subs']);
        set_post_labels($id, 'user', $f['labels']);
        q('DELETE FROM post_hashtags WHERE post_id = ?', [$id]);
        save_hashtags($id, $f['body']);
        if ($repl) {
            replace_post_images($id, $existing, $repl);
        }
        if ($rm) {
            remove_post_media($id, $existing, $rm);
        }
        try {
            append_post_media($id, $existing, $media['items']);
        } catch (Throwable $e) {
            error_log('[ITIHIX] ' . basename(__FILE__) . ': ' . $e->getMessage());
        }
        log_activity('post_resubmit', 'post', $id, '[dikirim ulang] ' . mb_substr($f['title'], 0, 100) . ($repl ? ' [' . count($repl) . ' foto diedit]' : '') . ($rm ? ' [' . count($rm) . ' media ditarik]' : ''));
        // beritahu moderator yang meminta klarifikasi; bila sudah tidak aktif, semua moderator
        $targets = [];
        if ((int)$p['moderator_id'] && qone('SELECT id FROM users WHERE id = ? AND status = "active" AND role IN ("moderator","admin")', [$p['moderator_id']])) {
            $targets[] = (int)$p['moderator_id'];
        }
        if (!$targets) {
            foreach (qall('SELECT id FROM users WHERE role = "moderator" AND status = "active" LIMIT 30') as $m) {
                $targets[] = (int)$m['id'];
            }
        }
        foreach ($targets as $t) {
            notify($t, 'undo', 'Laporan dikirim ulang setelah klarifikasi', '“' . mb_substr($f['title'], 0, 80) . '” oleh @' . $me['username'], 'moderator.php');
        }
        notify((int)$me['id'], 'hourglass', 'Revisi laporanmu terkirim', 'Moderator akan meninjau ulang “' . mb_substr($f['title'], 0, 80) . '”.', 'profile.php?tab=pending');
        flash('Revisi terkirim. Laporanmu kembali masuk antrean moderasi.');
        redirect('profile.php?tab=pending');
    }
}

$pageTitle = 'Revisi laporan';
$active = 'profile';
require __DIR__ . '/includes/header.php';
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
  <div class="page-heading"><div><div class="eyebrow">Revisi laporan</div><h1>Perbaiki &amp; kirim ulang</h1><div class="sub">Moderator meminta klarifikasi. Perbaiki laporanmu lalu kirim kembali untuk ditinjau.</div></div>
    <a class="btn btn-light" href="<?= e(url('profile.php?tab=pending')) ?>">← Kembali</a></div>
  <div class="card" style="padding:20px">
    <div class="notice" style="background:#fff8e7;color:#6f5d30"><b>? Permintaan moderator:</b> <?= e($p['moderator_note'] !== '' ? $p['moderator_note'] : 'Mohon lengkapi bukti dan konteks laporan.') ?></div>
    <?php foreach ($errors as $er): ?><div class="alert-err" style="margin-top:12px"><?= e($er) ?></div><?php endforeach; ?>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
      <label class="field-label">Judul laporan</label>
      <input class="field" name="title" required maxlength="160" value="<?= e($f['title']) ?>">
      <label class="field-label">Kategori</label>
      <?= category_picker('categories', $f['categories']) ?>
      <?= sub_picker('subs', array_column($f['subs'], 'sub'), $f['categories']) ?>
      <label class="field-label">Saran label untuk moderator</label>
      <?= label_picker('labels', $f['labels']) ?>
      <label class="field-label">Ceritakan apa yang terjadi</label>
      <div class="ht-wrap" id="htBox" data-seeds="<?= e(json_encode(category_seed_tags(), JSON_UNESCAPED_UNICODE)) ?>">
      <textarea class="textarea" id="reportBody" name="body" required maxlength="4000" style="min-height:150px"><?= e($f['body']) ?></textarea>
      <div class="ht-list hidden" id="htList" role="listbox" aria-label="Saran tagar"></div>
      </div>
      <div class="ht-reco" id="htReco" aria-live="polite"></div>
      <?php if ($existing): ?>
        <label class="field-label">Bukti yang sudah terlampir (<?= count($existing) ?>)</label>
        <div class="sub" style="margin:-2px 0 8px;font-size:12px">Klik <b>Edit</b> untuk menyamarkan data pribadi di foto, atau <b>✕</b> untuk menarik media dari laporan. Perubahan baru berlaku saat kamu menekan “Kirim ulang”.</div>
        <div class="mz-grid" id="existingMedia" data-img-mb="<?= (int)MAX_IMAGE_MB ?>">
        <?php foreach ($existing as $xi => $xm): $xu = e(url('uploads/' . $xm['path']));
            $xv = $xm['kind'] === 'video'; ?>
          <div class="mz-item" data-ex="<?= $xi ?>" data-path="<?= e($xm['path']) ?>">
            <a class="mz-thumb" href="<?= $xu ?>" target="_blank" rel="noopener" title="Klik untuk memperbesar"><?php if ($xv): ?><video muted playsinline preload="metadata" src="<?= $xu ?>#t=0.1"></video><span class="mz-kind"><?= icon('play') ?> Video</span><?php else: ?><img src="<?= $xu ?>" alt="Foto <?= $xi + 1 ?>" loading="lazy" data-orig="<?= $xu ?>"><span class="mz-kind"><?= icon('image') ?> Foto</span><?php endif; ?></a>
            <button type="button" class="mz-x" data-ex-remove="<?= $xi ?>" aria-label="Tarik <?= $xv ? 'video' : 'foto' ?> ini" title="Tarik dari laporan"><?= icon('xmark') ?></button>
            <?php if (!$xv): ?><button type="button" class="mz-ed" data-ex-edit="<?= $xi ?>" title="Edit foto (coretan, blur, mosaik)"><?= icon('pen') ?> Edit</button><?php endif; ?>
            <div class="mz-meta"><span class="mz-name"><?= $xv ? 'Video' : 'Foto' ?> <?= $xi + 1 ?></span><small data-ex-state>Terlampir</small></div>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <label class="field-label">Tambah bukti pendukung (opsional · total maks. <?= (int)MAX_MEDIA_PER_POST ?> file per laporan)</label>
      <?= media_dropzone(MAX_MEDIA_PER_POST) ?>
      <label class="field-label">Jawaban / klarifikasi untuk moderator</label>
      <textarea class="textarea" name="note" required minlength="5" maxlength="255" style="min-height:80px" placeholder="Jelaskan jawabanmu atas permintaan moderator, atau apa yang kamu perbaiki..."><?= e($f['note']) ?></textarea>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px"><a class="btn btn-light" href="<?= e(url('profile.php?tab=pending')) ?>">Batal</a><button class="btn btn-primary">Kirim ulang untuk ditinjau →</button></div>
    </form>
  </div>
</section>
<?php $extraJs = '<script src="' . e(url('assets/js/hashtag-suggest.js')) . '?v=' . (int)@filemtime(__DIR__ . '/assets/js/hashtag-suggest.js') . '"></script>'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>