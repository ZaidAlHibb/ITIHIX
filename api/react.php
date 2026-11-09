<?php

require_once __DIR__ . '/../includes/bootstrap.php';
$me = require_login();
if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Metode tidak valid.'], 405);
}
csrf_check();

$act = $_POST['act'] ?? '';
$pid = (int)($_POST['post_id'] ?? 0);
$post = qone('SELECT p.id, p.user_id, p.title FROM posts p JOIN users u ON u.id = p.user_id AND u.status = "active" WHERE p.id = ? AND p.status = "approved" AND p.deleted_at IS NULL', [$pid]);
if (!$post) {
    json_out(['ok' => false, 'error' => 'Postingan tidak ditemukan.'], 404);
}
if (!rate_limit('react', 90, 60)) {
    json_out(['ok' => false, 'error' => 'Terlalu cepat, coba sebentar lagi.'], 429);
}

$tables = ['like' => 'likes', 'repost' => 'reposts', 'save' => 'saves'];
$uid = (int)$me['id'];

if ($act === 'share') {
    q('INSERT INTO shares (user_id, post_id) VALUES (?,?)', [$uid, $pid]);
    log_activity('share', 'post', $pid, 'Membagikan postingan');
    json_out(['ok' => true, 'count' => (int)qval('SELECT COUNT(*) FROM shares WHERE post_id = ?', [$pid])]);
}
if (!isset($tables[$act])) {
    json_out(['ok' => false, 'error' => 'Aksi tidak dikenal.'], 400);
}

$t = $tables[$act];
$exists = qval("SELECT 1 FROM $t WHERE user_id = ? AND post_id = ?", [$uid, $pid]);
if ($exists) {
    q("DELETE FROM $t WHERE user_id = ? AND post_id = ?", [$uid, $pid]);
    $active = false;
} else {
    q("INSERT IGNORE INTO $t (user_id, post_id) VALUES (?,?)", [$uid, $pid]);
    $active = true;
    if ($act !== 'save' && (int)$post['user_id'] !== $uid) {
        $verb = $act === 'like' ? 'menyukai' : 'membagikan ulang';
        notify((int)$post['user_id'], $act === 'like' ? 'heart-o' : 'retweet', $me['display_name'] . ' ' . $verb . ' laporanmu', '“' . mb_substr($post['title'], 0, 80) . '”', 'post.php?id=' . $pid);
    }
}
log_activity($act . ($active ? '' : '_undo'), 'post', $pid);
json_out(['ok' => true, 'active' => $active, 'count' => (int)qval("SELECT COUNT(*) FROM $t WHERE post_id = ?", [$pid])]);
