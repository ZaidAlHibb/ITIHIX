<?php
$pageTitle = 'Buat laporan';
$active = 'upload';
require __DIR__ . '/includes/header.php';

$categories = array_keys(category_defs());
$f = ['title' => '', 'categories' => [$categories[0]], 'body' => '', 'labels' => ['penipuan'], 'subs' => [], 'note' => '', 'detection_id' => 0];
require_once __DIR__ . '/includes/detections.php';
require_once __DIR__ . '/includes/media_dropzone.php';
$detImages = [];
$errors = [];

// Prefill dari hasil ItihScan AI
$detId = (int)($_GET['detection'] ?? $_POST['detection_id'] ?? 0);
if ($detId) {
    $d = qone('SELECT * FROM detections WHERE id = ? AND user_id = ?', [$detId, $me['id']]);
    if ($d) {
        $f['detection_id'] = $detId;
        $detImages = det_image_list($d); // semua gambar hasil scan (bukan hanya yang pertama)
        if (!is_post()) {
            $f['body'] = ensure_scan_tag((string)$d['caption']);
            $f['labels'] = [$d['verdict'] === 'aman' ? 'klarifikasi' : $d['verdict']];
            // Hasil AI "aman" -> kategori otomatis "Lainnya" (bukan kategori penipuan pertama). Tetap bisa diganti manual.
            if ($d['verdict'] === 'aman' && isset(category_defs()['Lainnya'])) {
                $f['categories'] = ['Lainnya'];
            }
            $f['title'] = $d['verdict'] === 'penipuan' ? 'Waspada: pesan terindikasi penipuan' : 'Pesan mencurigakan, mohon diperiksa';
        }
    }
}

if (is_post()) {
    csrf_check();
    $f['title'] = trim((string)($_POST['title'] ?? ''));
    $f['categories'] = parse_categories($_POST['categories'] ?? []);
    $f['subs'] = parse_subs($_POST['subs'] ?? [], $f['categories']);
    $f['body'] = trim((string)($_POST['body'] ?? ''));
    $f['labels'] = parse_labels($_POST['labels'] ?? []);
    $f['note'] = trim((string)($_POST['note'] ?? ''));
    if (mb_strlen($f['title']) < 5 || mb_strlen($f['title']) > 160) {
        $errors[] = 'Judul harus 5–160 karakter.';
    }
    if (!$f['categories']) {
        $errors[] = 'Pilih minimal satu kategori.';
    } elseif (count($f['categories']) > MAX_CATEGORIES) {
        $errors[] = 'Pilih maksimal ' . MAX_CATEGORIES . ' kategori.';
    }
    if (mb_strlen($f['body']) < 20 || mb_strlen($f['body']) > 4000) {
        $errors[] = 'Isi laporan harus 20–4000 karakter.';
    }
    if (count($f['subs']) > MAX_SUBS) {
        $errors[] = 'Pilih maksimal ' . MAX_SUBS . ' jenis modus.';
    }
    if (!$f['labels']) {
        $errors[] = 'Pilih minimal satu label.';
    }
    if (mb_strlen($f['note']) > 255) {
        $errors[] = 'Catatan moderator maksimal 255 karakter.';
    }
    if (!rate_limit('post', 8, 3600)) {
        $errors[] = 'Kamu mengirim terlalu banyak laporan. Coba lagi dalam satu jam.';
    }
    $media = save_media_files($_FILES['media'] ?? [], MAX_MEDIA_PER_POST);
    if (!$media['ok']) {
        $errors[] = $media['error'];
    } elseif (!$errors && !$media['items'] && $f['detection_id'] && $detImages) {
        foreach (array_slice($detImages, 0, MAX_MEDIA_PER_POST) as $dp) {
            $media['items'][] = ['path' => $dp, 'kind' => 'image'];
        }
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
        $path = null;
        $vpath = null;
        foreach ($media['items'] as $it) {
            if ($it['kind'] === 'image' && !$path) {
                $path = $it['path'];
            } if ($it['kind'] === 'video' && !$vpath) {
                $vpath = $it['path'];
            }
        }
        $isAi = $f['detection_id'] ? 1 : 0;
        $staff = is_staff($me);                 // hanya staf yang boleh menandai tipe edukasi
        $direct = can_publish_directly($me);    // staf + akun Terpercaya: terbit tanpa moderasi
        $primary = $f['labels'][0];
        $type = ($staff && $f['labels'] === ['edukasi']) ? 'education' : 'report';
        q(
            'INSERT INTO posts (user_id, type, title, category, body, image_path, video_path, ai_generated, user_label, user_note, status, final_label, reviewed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$me['id'], $type, $f['title'], $f['categories'][0], $f['body'], $path, $vpath, $isAi, $primary, $f['note'],
           $direct ? 'approved' : 'pending', $direct ? $primary : null, $direct ? date('Y-m-d H:i:s') : null]
        );
        $pid = (int)db()->lastInsertId();
        // Hasil ItihScan AI: teks yang diperiksa ikut disimpan supaya tampil di postingan
        if ($f['detection_id'] && !empty($d) && trim((string)($d['input_text'] ?? '')) !== '' && post_has_scan_text()) {
            q('UPDATE posts SET scan_text = ? WHERE id = ?', [(string)$d['input_text'], $pid]);
        }
        set_post_categories($pid, $f['categories']);
        set_post_subs($pid, $f['subs']);
        set_post_labels($pid, 'user', $f['labels']);
        if ($direct) {
            set_post_labels($pid, 'final', $f['labels']);
        }
        try {
            attach_post_media($pid, $media['items']);
        } catch (Throwable $e) {
            error_log('[ITIHIX] ' . basename(__FILE__) . ': ' . $e->getMessage());
        }
        save_hashtags($pid, $f['body']);
        $today = (int)qval('SELECT COUNT(*) FROM posts WHERE user_id = ? AND DATE(created_at) = CURDATE()', [$me['id']]);
        $trusted = ($me['role'] === 'trusted');
        if ($today <= 5) {
            add_xp((int)$me['id'], 5, 'laporan');
            if ($trusted) {
                add_xp((int)$me['id'], 20, 'laporan disetujui');
            }
        }
        if ($direct) {
            flash('Laporan langsung terbit di beranda.' . ($trusted && $today <= 5 ? ' Kamu mendapat +25 XP.' : ''));
            redirect('post.php?id=' . $pid);
        }
        if ($direct) {
            flash('Laporan langsung terbit di beranda.');
            redirect('post.php?id=' . $pid);
        }
        foreach (qall('SELECT id FROM users WHERE role = "moderator" AND status = "active" LIMIT 30') as $m) {
            notify((int)$m['id'], 'square-check', 'Laporan baru menunggu tinjauan', '“' . mb_substr($f['title'], 0, 80) . '” oleh @' . $me['username'], 'moderator.php');
        }
        notify((int)$me['id'], 'hourglass', 'Laporanmu sedang ditinjau', 'Moderator akan memeriksa bukti dan konteks sebelum publikasi.', 'profile.php?tab=pending');
        flash('Laporan terkirim dan masuk antrean moderasi. Kamu mendapat +5 XP.');
        redirect('profile.php?tab=pending');
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
  <div class="page-heading"><div><h1>Buat laporan</h1><div class="sub"><?= can_publish_directly($me) ? 'Sebagai ' . e(role_label($me['role'])) . ', laporanmu langsung terbit.' : 'Bagikan temuan dengan jelas. Moderator akan meninjau sebelum tampil di feed publik.' ?></div></div></div>
  <div class="card" style="padding:20px">
    <div class="notice"><?= icon('shield') ?> <b>Utamakan keamanan:</b> samarkan nomor telepon, alamat, nama lengkap, dan informasi pribadi orang lain. Gunakan #tagar di isi laporan agar mudah ditemukan.</div>
    <?php if ($f['detection_id']): ?><div class="alert-ok" style="margin-top:12px"><?= icon('sparkles') ?> Formulir diisi dari hasil ItihScan AI. Postingan akan diberi <b>label ItihScan AI</b><?= trim((string)($d['input_text'] ?? '')) !== '' ? ' dan menampilkan <b>teks yang diperiksa</b>' : '' ?>. Silakan edit bila perlu.</div><?php endif; ?>
    <?php foreach ($errors as $er): ?><div class="alert-err" style="margin-top:12px"><?= e($er) ?></div><?php endforeach; ?>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="detection_id" value="<?= (int)$f['detection_id'] ?>">
      <label class="field-label">Judul laporan</label>
      <input class="field" name="title" required maxlength="160" value="<?= e($f['title']) ?>" placeholder="Contoh: Modus hadiah palsu mengatasnamakan bank">
      <label class="field-label">Kategori</label>
      <?= category_picker('categories', $f['categories']) ?>
      <?= sub_picker('subs', array_column($f['subs'], 'sub'), $f['categories']) ?>
      <label class="field-label"><?= can_publish_directly($me) ? 'Label laporan' : 'Saran label untuk moderator' ?></label>
      <?= label_picker('labels', $f['labels']) ?>
      <label class="field-label">Ceritakan apa yang terjadi</label>
      <div class="ht-wrap" id="htBox" data-seeds="<?= e(json_encode(category_seed_tags(), JSON_UNESCAPED_UNICODE)) ?>">
      <textarea class="textarea" id="reportBody" name="body" required maxlength="4000" style="min-height:150px" placeholder="Jelaskan isi pesan, cara pelaku menghubungi, dan tanda yang membuatmu curiga... #WaspadaAPK"><?= e($f['body']) ?></textarea>
      <div class="ht-list hidden" id="htList" role="listbox" aria-label="Saran tagar"></div>
      </div>
      <div class="ht-reco" id="htReco" aria-live="polite"></div>
      <label class="field-label">Bukti pendukung / poster (opsional)</label>
      <?= media_dropzone(MAX_MEDIA_PER_POST) ?>
      <?php if ($detImages): ?><div class="alert-ok" style="margin:8px 0 0;font-size:12px"><?= icon('check') ?> Memakai <b><?= count($detImages) ?> gambar</b> dari analisis AI bila kamu tidak memilih file lain.</div>
      <div class="media-preview" style="margin-top:8px"><?php foreach ($detImages as $di => $dp): ?><div class="dt-pv"><a href="<?= e(url('uploads/' . $dp)) ?>" target="_blank" rel="noopener"><img src="<?= e(url('uploads/' . $dp)) ?>" alt="Gambar scan <?= $di + 1 ?>" loading="lazy"></a><span class="dt-pv-n">Gambar scan <?= $di + 1 ?></span></div><?php endforeach; ?></div><?php endif; ?>
      <label class="field-label">Catatan untuk moderator (opsional)</label>
      <input class="field" name="note" maxlength="255" value="<?= e($f['note']) ?>" placeholder="Sumber informasi atau konteks tambahan">
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px"><a class="btn btn-light" href="<?= e(url('index.php')) ?>">Batal</a><button class="btn btn-primary"><?= can_publish_directly($me) ? 'Terbitkan' : 'Kirim untuk ditinjau' ?> →</button></div>
    </form>
  </div>
</section>
<?php $extraJs = '<script src="' . e(url('assets/js/hashtag-suggest.js')) . '?v=' . (int)@filemtime(__DIR__ . '/assets/js/hashtag-suggest.js') . '"></script>'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>