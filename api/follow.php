<?php

require_once __DIR__ . '/../includes/bootstrap.php';
$me = require_login();
if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Metode tidak valid.'], 405);
}
csrf_check();
$target = (int)($_POST['user_id'] ?? 0);
if ($target === (int)$me['id']) {
    json_out(['ok' => false, 'error' => 'Tidak bisa mengikuti diri sendiri.'], 422);
}
if (!qval('SELECT 1 FROM users WHERE id = ? AND status = "active"', [$target])) {
    json_out(['ok' => false, 'error' => 'Pengguna tidak ditemukan.'], 404);
}
$ex = qval('SELECT 1 FROM follows WHERE follower_id = ? AND followee_id = ?', [$me['id'], $target]);
if ($ex) {
    q('DELETE FROM follows WHERE follower_id = ? AND followee_id = ?', [$me['id'], $target]);
    $active = false;
} else {
    q('INSERT IGNORE INTO follows (follower_id, followee_id) VALUES (?,?)', [$me['id'], $target]);
    $active = true;
    notify($target, 'user', $me['display_name'] . ' mulai mengikutimu', '@' . $me['username'], profile_url($me['username']));
}
log_activity($active ? 'follow' : 'unfollow', 'user', $target);
[$dmOk, $dmWhy] = dm_can_send($me, qone('SELECT * FROM users WHERE id = ?', [$target]));
json_out(['ok' => true, 'active' => $active, 'count' => (int)qval('SELECT COUNT(*) FROM follows f JOIN users u ON u.id = f.follower_id AND u.status = "active" WHERE f.followee_id = ?', [$target]), 'dm_locked' => $dmOk ? '' : $dmWhy]);
