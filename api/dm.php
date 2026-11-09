<?php

require_once __DIR__ . '/../includes/bootstrap.php';
$me = require_login();
$uid = (int)$me['id'];

if (is_post()) {
    csrf_check();
    if (($_POST['action'] ?? '') !== 'send') {
        json_out(['ok' => false, 'error' => 'Aksi tidak dikenal.'], 400);
    }
    $to = (int)($_POST['user_id'] ?? 0);
    $raw = (string)($_POST['message'] ?? '');
    if (!mb_check_encoding($raw, 'UTF-8')) {
        json_out(['ok' => false, 'error' => 'Pesan mengandung karakter tidak valid.'], 422);
    }
    $body = trim(str_replace("\r\n", "\n", $raw));
    $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body); // buang karakter kontrol tak terlihat
    $len = mb_strlen($body);
    if ($len < 1 || $len > 1000) {
        json_out(['ok' => false, 'error' => 'Pesan harus 1–1000 karakter.'], 422);
    }
    if (!rate_limit('dm', 20, 60)) {
        json_out(['ok' => false, 'error' => 'Terlalu cepat, tunggu sebentar.'], 429);
    }
    $peer = qone('SELECT * FROM users WHERE id = ? AND status = "active"', [$to]);
    if (!$peer) {
        json_out(['ok' => false, 'error' => 'Pengguna tidak ditemukan.'], 404);
    }
    [$ok, $why] = dm_can_send($me, $peer);
    if (!$ok) {
        json_out(['ok' => false, 'error' => $why, 'locked' => true], 403);
    }
    // batas berbasis database (rate_limit sesi di atas bisa dihindari dengan membuka sesi baru)
    if ((int)qval('SELECT COUNT(*) FROM direct_messages WHERE sender_id = ? AND created_at > (NOW() - INTERVAL 60 SECOND)', [$uid]) >= 30
        || (int)qval('SELECT COUNT(*) FROM direct_messages WHERE sender_id = ? AND recipient_id = ? AND created_at > (NOW() - INTERVAL 60 SECOND)', [$uid, $to]) >= 15) {
        json_out(['ok' => false, 'error' => 'Terlalu banyak pesan, tunggu sebentar.'], 429);
    }

    $hadUnread = (bool)qval('SELECT 1 FROM direct_messages WHERE sender_id = ? AND recipient_id = ? AND is_read = 0 LIMIT 1', [$uid, $to]);
    q('INSERT INTO direct_messages (sender_id, recipient_id, body) VALUES (?,?,?)', [$uid, $to, $body]);
    $id = (int)db()->lastInsertId();
    // satu notifikasi per "rentetan" pesan belum dibaca, supaya tidak membanjiri
    if (!$hadUnread) {
        notify($to, 'chat', $me['display_name'] . ' mengirim pesan', '@' . $me['username'], 'messages.php?u=' . urlencode($me['username']));
    }
    log_activity('dm_send', 'user', $to);
    $row = qone('SELECT * FROM direct_messages WHERE id = ?', [$id]);
    json_out(['ok' => true, 'message' => dm_row_out($row, $uid)]);
}

// GET ?action=poll&with=ID&after=LAST_ID -> pesan baru dalam percakapan, sekaligus menandainya terbaca.
session_write_close(); // polling sering; jangan menahan kunci sesi
if (($_GET['action'] ?? '') !== 'poll') {
    json_out(['ok' => false, 'error' => 'Aksi tidak dikenal.'], 400);
}
$with = (int)($_GET['with'] ?? 0);
$after = (int)($_GET['after'] ?? 0);
$peer = qone('SELECT * FROM users WHERE id = ? AND status = "active"', [$with]);
if (!$peer) {
    json_out(['ok' => false, 'error' => 'Pengguna tidak ditemukan.'], 404);
}
$rows = qall(
    'SELECT * FROM direct_messages WHERE id > ? AND ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)) ORDER BY id LIMIT 100',
    [$after, $uid, $with, $with, $uid]
);
if ($rows) {
    q('UPDATE direct_messages SET is_read = 1 WHERE sender_id = ? AND recipient_id = ? AND is_read = 0', [$with, $uid]);
}
[$ok, $why] = dm_can_send($me, $peer);
json_out(['ok' => true, 'messages' => array_map(fn ($r) => dm_row_out($r, $uid), $rows), 'can_send' => $ok, 'reason' => $why]);
