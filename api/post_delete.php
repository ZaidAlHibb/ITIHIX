<?php

require_once __DIR__ . '/../includes/bootstrap.php';
$me = require_login();
if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Metode tidak valid.'], 405);
}
csrf_check();
$pid = (int)($_POST['post_id'] ?? 0);
$post = qone('SELECT p.id, p.user_id, p.title, u.role FROM posts p JOIN users u ON u.id = p.user_id WHERE p.id = ? AND p.deleted_at IS NULL', [$pid]);
if (!$post) {
    json_out(['ok' => false, 'error' => 'Postingan tidak ditemukan.'], 404);
}
if (!can_delete_post($me, $post)) {
    json_out(['ok' => false, 'error' => 'Kamu tidak berhak menghapus ini.'], 403);
}
q('UPDATE posts SET deleted_at = NOW() WHERE id = ?', [$pid]);
log_activity('post_delete', 'post', $pid, 'Menghapus: ' . mb_substr($post['title'], 0, 100));
if ((int)$post['user_id'] !== (int)$me['id']) {
    notify((int)$post['user_id'], 'xmark', 'Postinganmu dihapus oleh ' . mb_strtolower(role_label($me['role'])), '“' . mb_substr($post['title'], 0, 80) . '” melanggar panduan komunitas.');
}
json_out(['ok' => true]);
