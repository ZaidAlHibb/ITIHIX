<?php

require_once __DIR__ . '/../includes/bootstrap.php';
$me = require_login();
if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Metode tidak valid.'], 405);
}
csrf_check();

$target = (int)($_POST['user_id'] ?? 0);
$postId = (int)($_POST['post_id'] ?? 0);
$reason = (string)($_POST['reason'] ?? '');
$detail = trim((string)($_POST['detail'] ?? ''));
$commentId = (int)($_POST['comment_id'] ?? 0);

if ($target === (int)$me['id']) {
    json_out(['ok' => false, 'error' => 'Kamu tidak bisa melaporkan dirimu sendiri.'], 422);
}
$u = qone('SELECT id, username FROM users WHERE id = ? AND status = "active"', [$target]);
if (!$u) {
    json_out(['ok' => false, 'error' => 'Pengguna tidak ditemukan.'], 404);
}
if (!isset(report_reasons()[$reason])) {
    json_out(['ok' => false, 'error' => 'Pilih alasan laporan.'], 422);
}
if (mb_strlen($detail) > 500) {
    json_out(['ok' => false, 'error' => 'Keterangan maksimal 500 karakter.'], 422);
}
if ($postId && !qval('SELECT 1 FROM posts WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$postId, $target])) {
    $postId = 0;
}
// laporan komentar: kaitkan ke postingan tempat komentar itu berada & sertakan cuplikan komentarnya untuk moderator
if ($commentId) {
    $cm = qone('SELECT id, post_id, body FROM comments WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$commentId, $target]);
    if (!$cm) {
        json_out(['ok' => false, 'error' => 'Komentar tidak ditemukan atau sudah dihapus.'], 404);
    }
    $postId = (int)$cm['post_id'];
    $note = '[Komentar #' . (int)$cm['id'] . '] “' . mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$cm['body'])), 0, 160) . '”';
    $detail = mb_substr($note . ($detail !== '' ? ' — ' . $detail : ''), 0, 500);
}
// pencegah laporan ganda: komentar yang sama hanya bisa dilaporkan sekali selama masih ditinjau; komentar lain dari akun yang sama tetap boleh
if ($commentId) {
    if (qval('SELECT 1 FROM user_reports WHERE reporter_id = ? AND comment_id = ? AND status = "open"', [$me['id'], $commentId])) {
        json_out(['ok' => false, 'error' => 'Kamu sudah melaporkan komentar ini. Laporanmu masih ditinjau moderator.'], 409);
    }
} elseif (qval('SELECT 1 FROM user_reports WHERE reporter_id = ? AND reported_id = ? AND comment_id IS NULL AND status = "open"', [$me['id'], $target])) {
    json_out(['ok' => false, 'error' => 'Kamu sudah melaporkan pengguna ini. Laporanmu masih ditinjau moderator.'], 409);
}
if (!rate_limit('report_user', 5, 3600)) {
    json_out(['ok' => false, 'error' => 'Terlalu banyak laporan. Coba lagi nanti.'], 429);
}

try {
    q(
        'INSERT INTO user_reports (reporter_id, reported_id, post_id, comment_id, reason, detail) VALUES (?,?,?,?,?,?)',
        [$me['id'], $target, $postId ?: null, $commentId ?: null, $reason, $detail]
    );
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Fitur laporan pengguna belum siap. Hubungi admin.'], 500);
}
$rid = (int)db()->lastInsertId();
log_activity('report_user', 'user', $target, 'Alasan: ' . $reason . ' (#' . $rid . ')' . ($commentId ? ' komentar #' . $commentId : ''));

foreach (qall('SELECT id FROM users WHERE role IN ("moderator","admin") AND status = "active" AND id <> ?', [$me['id']]) as $s) {
    notify((int)$s['id'], 'flag', 'Laporan pengguna baru', '@' . $me['username'] . ' melaporkan ' . ($commentId ? 'komentar @' : '@') . $u['username'] . ' — ' . report_reasons()[$reason], 'moderator.php?tab=reports');
}
json_out(['ok' => true, 'message' => $commentId ? 'Komentar dilaporkan. Terima kasih, moderator akan meninjaunya.' : 'Laporan terkirim. Terima kasih, moderator akan meninjaunya.']);
