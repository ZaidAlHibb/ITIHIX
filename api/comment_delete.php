<?php

/** Hapus komentar. POST: comment_id. Pemilik menghapus komentarnya; moderator/admin menghapus paksa. Balasan di bawahnya ikut terhapus. */
require_once __DIR__ . '/../includes/bootstrap.php';
$me = require_login();
require_once __DIR__ . '/../includes/comments.php';
if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Metode tidak valid.'], 405);
}
csrf_check();

$cid = (int)($_POST['comment_id'] ?? 0);
try {
    $c = qone('SELECT c.id, c.user_id, c.post_id, c.body, u.role FROM comments c JOIN users u ON u.id = c.user_id WHERE c.id = ? AND c.deleted_at IS NULL', [$cid]);
    if (!$c) {
        json_out(['ok' => false, 'error' => 'Komentar tidak ditemukan.'], 404);
    }
    if (!can_delete_comment($me, $c)) {
        json_out(['ok' => false, 'error' => 'Kamu tidak berhak menghapus komentar ini.'], 403);
    }

    // hapus komentar ini + seluruh balasannya (bersarang)
    $ids = comment_delete_tree($cid);

    $own = (int)$c['user_id'] === (int)$me['id'];
    log_activity($own ? 'comment_delete' : 'comment_force_delete', 'comment', $cid, mb_substr((string)$c['body'], 0, 120));
    if (!$own) {
        notify((int)$c['user_id'], 'xmark', 'Komentarmu dihapus oleh ' . mb_strtolower(role_label((string)$me['role'])), '“' . mb_substr((string)$c['body'], 0, 80) . '” melanggar panduan komunitas.', 'post.php?id=' . (int)$c['post_id']);
    }
    json_out([
        'ok' => true,
        'post_id' => (int)$c['post_id'],
        'removed' => count($ids),
        'count' => (int)qval('SELECT COUNT(*) FROM comments WHERE post_id = ? AND deleted_at IS NULL', [(int)$c['post_id']]),
    ]);
} catch (Throwable $e) {
    error_log('comment_delete: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => is_staff($me) ? 'Error server: ' . $e->getMessage() : 'Gagal menghapus, coba lagi nanti.'], 500);
}
