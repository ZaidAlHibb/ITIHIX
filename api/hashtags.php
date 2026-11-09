<?php

// Saran tagar saat menulis laporan: ?q=awalan (boleh kosong -> tagar terpopuler). Hanya tagar dari laporan yang SUDAH terbit.
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
session_write_close();
$q = ltrim(trim((string)($_GET['q'] ?? '')), '#');
if (!preg_match('/^[\p{L}\p{N}_]{0,40}$/u', $q)) {
    json_out(['ok' => true, 'tags' => []]);
}
$like = addcslashes($q, '%_\\') . '%';
$rows = qall('SELECT h.tag, COUNT(p.id) c FROM hashtags h
              JOIN post_hashtags ph ON ph.hashtag_id = h.id
              JOIN posts p ON p.id = ph.post_id AND p.status = "approved" AND p.deleted_at IS NULL
              WHERE h.tag LIKE ? GROUP BY h.id, h.tag ORDER BY c DESC, h.tag LIMIT 8', [$like]);
json_out(['ok' => true, 'tags' => array_map(fn ($r) => ['tag' => $r['tag'], 'count' => (int)$r['c']], $rows)]);
