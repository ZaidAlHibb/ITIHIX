<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/detections.php';
$me = require_login();
if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Metode tidak valid.'], 405);
}
csrf_check();
$id = (int)($_POST['detection_id'] ?? 0);
$cap = trim((string)($_POST['caption'] ?? ''));
if (!qval('SELECT 1 FROM detections WHERE id = ? AND user_id = ?', [$id, $me['id']])) {
    json_out(['ok' => false, 'error' => 'Hasil analisis tidak ditemukan.'], 404);
}
q('UPDATE detections SET caption = ? WHERE id = ?', [mb_substr($cap, 0, 2000), $id]);
// save=1 -> masukkan ke riwayat (dipakai tombol "Simpan riwayat" dan "Post")
if (!empty($_POST['save']) && det_has_saved()) {
    q('UPDATE detections SET saved = 1 WHERE id = ?', [$id]);
}
json_out(['ok' => true]);
