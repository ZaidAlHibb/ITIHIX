<?php

/** Suka / tidak suka komentar. POST: comment_id, vote = like | dislike. Menekan pilihan yang sama lagi = membatalkan. */
require_once __DIR__ . '/../includes/bootstrap.php';
$me = require_login();
require_once __DIR__ . '/../includes/comments.php';
if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Metode tidak valid.'], 405);
}
csrf_check();

$cid  = (int)($_POST['comment_id'] ?? 0);
$want = ($_POST['vote'] ?? '') === 'like' ? 1 : (($_POST['vote'] ?? '') === 'dislike' ? -1 : 0);
if (!$cid || !$want) {
    json_out(['ok' => false, 'error' => 'Aksi tidak dikenal.'], 400);
}
if (!rate_limit('cvote', 90, 60)) {
    json_out(['ok' => false, 'error' => 'Terlalu cepat, coba sebentar lagi.'], 429);
}

try {
    if (!ensure_comment_votes()) {
        json_out(['ok' => false, 'error' => 'Fitur belum siap, coba lagi nanti.'], 500);
    }

    $c = qone('SELECT c.id, c.user_id, c.post_id, c.body FROM comments c
               JOIN users u ON u.id = c.user_id AND u.status = "active"
               JOIN posts p ON p.id = c.post_id AND p.status = "approved" AND p.deleted_at IS NULL
               WHERE c.id = ? AND c.deleted_at IS NULL', [$cid]);
    if (!$c) {
        json_out(['ok' => false, 'error' => 'Komentar tidak ditemukan.'], 404);
    }

    $uid = (int)$me['id'];
    $cur = qval('SELECT vote FROM comment_votes WHERE comment_id = ? AND user_id = ?', [$cid, $uid]);
    $cur = $cur === null ? 0 : (int)$cur;
    if ($cur === $want) {
        q('DELETE FROM comment_votes WHERE comment_id = ? AND user_id = ?', [$cid, $uid]);
        $mine = 0;
    } else {
        q('INSERT INTO comment_votes (comment_id, user_id, vote) VALUES (?,?,?) ON DUPLICATE KEY UPDATE vote = VALUES(vote), created_at = CURRENT_TIMESTAMP', [$cid, $uid, $want]);
        $mine = $want;
        if ($want === 1 && (int)$c['user_id'] !== $uid) {
            notify((int)$c['user_id'], 'thumbs-up', $me['display_name'] . ' menyukai komentarmu', mb_substr($c['body'], 0, 120), 'post.php?id=' . (int)$c['post_id']);
        }
    }
    log_activity($mine === 1 ? 'comment_like' : ($mine === -1 ? 'comment_dislike' : 'comment_vote_undo'), 'comment', $cid);
    $likes = (int)qval('SELECT COUNT(*) FROM comment_votes WHERE comment_id = ? AND vote = 1', [$cid]);
    $dislikes = (int)qval('SELECT COUNT(*) FROM comment_votes WHERE comment_id = ? AND vote = -1', [$cid]);
    json_out(['ok' => true, 'mine' => $mine, 'likes' => $likes, 'dislikes' => $dislikes, 'score' => $likes - $dislikes, 'label' => comment_score_fmt($likes - $dislikes)]);
} catch (Throwable $e) {
    error_log('comment_vote: ' . $e->getMessage());
    // pesan asli hanya untuk moderator/admin; pengguna biasa melihat pesan umum
    json_out(['ok' => false, 'error' => is_staff($me) ? 'Error server: ' . $e->getMessage() : 'Gagal menyimpan, coba lagi nanti.'], 500);
}
