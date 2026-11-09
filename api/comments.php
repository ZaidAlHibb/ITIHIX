<?php

require_once __DIR__ . '/../includes/bootstrap.php';
$me = require_login();
require_once __DIR__ . '/../includes/comments.php';

$pid = (int)($_REQUEST['post_id'] ?? 0);
$post = qone('SELECT p.id, p.user_id, p.title FROM posts p JOIN users u ON u.id = p.user_id AND u.status = "active" WHERE p.id = ? AND p.status = "approved" AND p.deleted_at IS NULL', [$pid]);
if (!$post) {
    json_out(['ok' => false, 'error' => 'Postingan tidak ditemukan.'], 404);
}

if (is_post()) {
    csrf_check();
    $body = trim((string)($_POST['body'] ?? ''));
    if ($body === '' || mb_strlen($body) > 1000) {
        json_out(['ok' => false, 'error' => 'Komentar harus 1–1000 karakter.'], 422);
    }
    if (!rate_limit('comment', 10, 60)) {
        json_out(['ok' => false, 'error' => 'Terlalu banyak komentar, tunggu sebentar.'], 429);
    }
    $parentId = (int)($_POST['parent_id'] ?? 0);
    $parent = null;
    if ($parentId && comments_have_parent()) {
        $parent = qone('SELECT id, user_id, parent_id FROM comments WHERE id = ? AND post_id = ? AND deleted_at IS NULL', [$parentId, $pid]);
        if (!$parent) {
            json_out(['ok' => false, 'error' => 'Komentar yang dibalas tidak ditemukan.'], 404);
        }
        // balasan disimpan di bawah komentar yang benar-benar dibalas (bersarang), bukan diratakan ke komentar utama
    } else {
        $parentId = 0;
    }
    if ($parentId) {
        q('INSERT INTO comments (post_id, user_id, parent_id, body) VALUES (?,?,?,?)', [$pid, $me['id'], $parentId, $body]);
    } else {
        q('INSERT INTO comments (post_id, user_id, body) VALUES (?,?,?)', [$pid, $me['id'], $body]);
    }
    $cid = (int)db()->lastInsertId();
    log_activity('comment', 'post', $pid, mb_substr($body, 0, 120));
    if ($parent && (int)$parent['user_id'] !== (int)$me['id']) {
        notify((int)$parent['user_id'], 'comment', $me['display_name'] . ' membalas komentarmu', mb_substr($body, 0, 120), 'post.php?id=' . $pid);
    }
    if ((int)$post['user_id'] !== (int)$me['id'] && (!$parent || (int)$parent['user_id'] !== (int)$post['user_id'])) {
        notify((int)$post['user_id'], 'comment', $me['display_name'] . ' mengomentari laporanmu', mb_substr($body, 0, 120), 'post.php?id=' . $pid);
    }
    $today = (int)qval('SELECT COUNT(*) FROM comments WHERE user_id = ? AND DATE(created_at) = CURDATE()', [$me['id']]);
    if ($today <= 5) {
        add_xp((int)$me['id'], 2, 'komentar');
    }
    $c = qone('SELECT c.*, u.username, u.display_name, u.level, u.xp, u.show_level, u.avatar_color, u.avatar_path, u.role FROM comments c JOIN users u ON u.id = c.user_id WHERE c.id = ?', [$cid]);
    json_out(['ok' => true, 'html' => render_comment($c, $me, [], true), 'parent_id' => $parentId, 'count' => (int)qval('SELECT COUNT(*) FROM comments WHERE post_id = ? AND deleted_at IS NULL', [$pid])]);
}

$hasParent = comments_have_parent();
$hasVotes = ensure_comment_votes();
$voteCols = $hasVotes
    ? ', (SELECT COUNT(*) FROM comment_votes v WHERE v.comment_id = c.id AND v.vote = 1) AS likes,
       (SELECT COUNT(*) FROM comment_votes v WHERE v.comment_id = c.id AND v.vote = -1) AS dislikes,
       (SELECT v.vote FROM comment_votes v WHERE v.comment_id = c.id AND v.user_id = ?) AS my_vote'
    : '';
$rows = qall('SELECT c.*, u.username, u.display_name, u.level, u.xp, u.show_level, u.avatar_color, u.avatar_path, u.role' . $voteCols . '
              FROM comments c JOIN users u ON u.id = c.user_id AND u.status = "active"
              WHERE c.post_id = ? AND c.deleted_at IS NULL ORDER BY c.created_at ASC LIMIT 400', $hasVotes ? [(int)$me['id'], $pid] : [$pid]);
comment_prefetch_mentions($rows);
$ids = [];
foreach ($rows as $r) {
    $ids[(int)$r['id']] = true;
}
$top = [];
$kids = [];
foreach ($rows as $r) {
    $par = (int)($r['parent_id'] ?? 0);
    // komentar tanpa induk (atau induknya sudah dihapus/tidak aktif) tampil sebagai komentar utama
    if ($hasParent && $par && isset($ids[$par])) {
        $kids[$par][] = $r;
    } else {
        $top[] = $r;
    }
}
// ?limit=N -> pratinjau di dropdown feed/explore: hanya N komentar utama pertama (beserta balasannya)
$limit = max(0, min(100, (int)($_GET['limit'] ?? 0)));
$topTotal = count($top);
$top = array_slice($top, 0, $limit ?: 100);
$more = $limit > 0 && $topTotal > $limit;
$html = $top ? implode('', array_map(fn ($c) => render_comment($c, $me, $kids, $hasParent), $top)) : '<div class="sub comment-empty">Belum ada komentar. Jadilah yang pertama.</div>';
json_out(['ok' => true, 'html' => $html, 'more' => $more, 'total' => (int)qval('SELECT COUNT(*) FROM comments WHERE post_id = ? AND deleted_at IS NULL', [$pid])]);
