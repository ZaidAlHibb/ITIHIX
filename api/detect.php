<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/detections.php';
$me = require_login();
if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Metode tidak valid.'], 405);
}
csrf_check();
if (!rate_limit('detect', 12, 300)) {
    json_out(['ok' => false, 'error' => 'Batas analisis tercapai, coba beberapa menit lagi.'], 429);
}

$text = trim((string)($_POST['text'] ?? ''));
if (mb_strlen($text) > 4000) {
    json_out(['ok' => false, 'error' => 'Teks terlalu panjang (maks. 4000 karakter).'], 422);
}

// Lampiran gambar: boleh banyak (images[]), maks. DET_MAX_IMAGES. Field lama 'image' tetap diterima.
const DET_MAX_IMAGES = 6;
$files = [];
foreach (['images', 'image'] as $k) {
    if (!isset($_FILES[$k])) {
        continue;
    }
    $f = $_FILES[$k];
    if (is_array($f['name'])) {
        foreach (array_keys($f['name']) as $i) {
            $files[] = ['name' => $f['name'][$i], 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '', 'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $f['size'][$i] ?? 0];
        }
    } else {
        $files[] = $f;
    }
}
$files = array_values(array_filter($files, fn ($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
if (count($files) > DET_MAX_IMAGES) {
    json_out(['ok' => false, 'error' => 'Maksimal ' . DET_MAX_IMAGES . ' gambar per analisis.'], 422);
}
$imgs = [];
foreach ($files as $n => $f) {
    $r = save_upload($f, 'image');
    if (!$r['ok'] || empty($r['path'])) {
        foreach ($imgs as $done) {
            @unlink($done['abs']);
        } // batalkan semua bila satu gagal
        json_out(['ok' => false, 'error' => 'Gambar ke-' . ($n + 1) . ': ' . ($r['error'] ?? 'gagal diunggah.')], 422);
    }
    $imgs[] = $r;
}
if ($text === '' && !$imgs) {
    json_out(['ok' => false, 'error' => 'Isi teks atau unggah gambar.'], 422);
}
$paths = array_column($imgs, 'path');

$res = analyze_scam($text, array_map(fn ($r) => ['abs' => $r['abs'], 'mime' => $r['mime']], $imgs));
if ($res === null) {
    // AI tidak aktif / gagal: jangan simpan apa pun (riwayat, XP) dan bersihkan gambar yang sudah terunggah.
    foreach ($imgs as $done) {
        @unlink($done['abs']);
    }
    $out = ['ok' => false, 'unavailable' => true, 'error' => 'Maaf, deteksi belum bisa dilakukan saat ini. Coba lagi nanti ya.'];
    if (($me['role'] ?? '') === 'admin') {
        $out['detail'] = ai_last_error();
    } // hanya admin yang melihat penyebab teknisnya
    json_out($out, 503);
}
$cols = 'user_id, input_text, image_path, risk_score, verdict, reasons, advice, caption, engine';
$vals = [$me['id'], $text, $paths[0] ?? null, $res['score'], $res['verdict'], json_encode($res['reasons'], JSON_UNESCAPED_UNICODE), json_encode($res['advice'], JSON_UNESCAPED_UNICODE), $res['caption'], $res['engine']];
if (det_has_images()) {
    $cols .= ', images';
    $vals[] = $paths ? json_encode($paths) : null;
}
if (det_has_after()) {
    $cols .= ', after_steps';
    $vals[] = json_encode($res['after'] ?? [], JSON_UNESCAPED_UNICODE);
}
// Hasil langsung masuk riwayat (kolom saved bernilai default 1).
q('INSERT INTO detections (' . $cols . ') VALUES (' . implode(',', array_fill(0, count($vals), '?')) . ')', $vals);
$id = (int)db()->lastInsertId();
log_activity('detect', 'detection', $id, 'Verdict: ' . $res['verdict'] . ' (' . $res['score'] . ')');
// Batas XP harian dihitung dari log aktivitas (bukan tabel detections) supaya hapus riwayat tidak bisa dipakai menambah XP.
$today = (int)qval('SELECT COUNT(*) FROM activity_logs WHERE user_id = ? AND action = "detect" AND DATE(created_at) = CURDATE()', [$me['id']]);
if ($today <= 5) {
    add_xp((int)$me['id'], 5, 'deteksi');
}
$extra = [];
if (ai_enabled() && $res['engine'] === 'rules' && ($me['role'] ?? '') === 'admin') {
    $extra['ai_error'] = ai_last_error();
}
json_out(['ok' => true, 'id' => $id, 'text' => $text, 'image' => $paths[0] ?? null, 'images' => $paths] + $res + $extra);
