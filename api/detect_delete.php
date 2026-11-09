<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/detections.php';
$me = require_login();
if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Metode tidak valid.'], 405);
}
csrf_check();
$id = (int)($_POST['id'] ?? 0);
if (!$id || !det_delete($id, (int)$me['id'])) {
    json_out(['ok' => false, 'error' => 'Hasil analisis tidak ditemukan.'], 404);
}
log_activity('detect_delete', 'detection', $id, 'Menghapus riwayat analisis');
json_out(['ok' => true]);
