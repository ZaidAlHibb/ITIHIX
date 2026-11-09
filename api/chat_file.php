<?php

/** Menyajikan lampiran Chat AI. Hanya pemilik file yang boleh membukanya. */
require_once __DIR__ . '/../includes/bootstrap.php';
$me = require_login();
chat_ensure_schema();
$id = (int)($_GET['id'] ?? 0);
$a = $id ? qone('SELECT * FROM chat_attachments WHERE id = ? AND user_id = ?', [$id, (int)$me['id']]) : null;
$abs = $a ? chat_attachment_abs($a) : '';
if (!$a || !is_file($abs)) {
    http_response_code(404);
    exit('File tidak ditemukan.');
}
session_write_close();
while (ob_get_level() > 0) {
    ob_end_clean();
}

$inline = in_array($a['kind'], ['image', 'pdf'], true);
header('Content-Type: ' . ($a['kind'] === 'text' ? 'text/plain; charset=utf-8' : $a['mime']));
header('X-Content-Type-Options: nosniff');
if ($a['kind'] === 'text') {
    header("Content-Security-Policy: default-src 'none'; sandbox");
}
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($a['original_name']));
header('Content-Length: ' . filesize($abs));
header('Cache-Control: private, max-age=3600');
readfile($abs);
exit;
