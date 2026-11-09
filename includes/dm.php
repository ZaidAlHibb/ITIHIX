<?php

/* ---------- Pesan langsung antar pengguna (DM) ----------
 * Aturan: pesan hanya bisa DIKIRIM bila kedua pengguna saling follow (A mengikuti B DAN B mengikuti A)
 * dan penerima tidak menonaktifkan "Izinkan pesan" di Pengaturan. Riwayat tetap bisa dibaca walau kemudian unfollow.
 */
function is_mutual_follow(int $a, int $b): bool
{
    return (bool)qval('SELECT 1 FROM follows f1 JOIN follows f2 ON f2.follower_id = f1.followee_id AND f2.followee_id = f1.follower_id
                       WHERE f1.follower_id = ? AND f1.followee_id = ?', [$a, $b]);
}

/** @return array{0:bool,1:string} [boleh kirim?, alasan bila tidak] */
function dm_can_send(array $me, array $peer): array
{
    $mid = (int)$me['id'];
    $pid = (int)$peer['id'];
    if ($mid === $pid) {
        return [false, 'Tidak bisa mengirim pesan ke diri sendiri.'];
    }
    if (($peer['status'] ?? 'active') !== 'active') {
        return [false, 'Pengguna ini tidak aktif.'];
    }
    if (!is_mutual_follow($mid, $pid)) {
        return [false, 'Kamu dan @' . $peer['username'] . ' harus saling mengikuti untuk bisa berkirim pesan.'];
    }
    if (empty($peer['allow_messages'])) {
        return [false, '@' . $peer['username'] . ' sedang tidak menerima pesan.'];
    }
    return [true, ''];
}

function dm_unread_count(int $uid): int
{
    try {
        return (int)qval('SELECT COUNT(*) FROM direct_messages WHERE recipient_id = ? AND is_read = 0', [$uid]);
    } catch (Throwable $e) {
        return 0;
    }
}

function dm_time(string $dt): string
{
    $t = strtotime($dt);
    return date('Y-m-d', $t) === date('Y-m-d') ? date('H.i', $t) : date('d/m H.i', $t);
}

function dm_row_out(array $r, int $me): array
{
    return ['id' => (int)$r['id'], 'mine' => (int)$r['sender_id'] === $me, 'body' => $r['body'], 'time' => dm_time($r['created_at'])];
}

/** Daftar percakapan: pengguna yang saling follow + pengguna yang pernah berkirim pesan, urut pesan terbaru. */
function dm_conversations(int $me): array
{
    $ids = [];
    foreach (qall('SELECT f1.followee_id id FROM follows f1 JOIN follows f2 ON f2.follower_id = f1.followee_id AND f2.followee_id = f1.follower_id
                   WHERE f1.follower_id = ?', [$me]) as $r) {
        $ids[(int)$r['id']] = true;
    }
    $mutual = $ids;
    $last = [];
    foreach (qall('SELECT m.* FROM direct_messages m JOIN (
                     SELECT IF(sender_id = ?, recipient_id, sender_id) peer, MAX(id) mid FROM direct_messages
                     WHERE sender_id = ? OR recipient_id = ? GROUP BY peer) x ON x.mid = m.id', [$me, $me, $me]) as $r) {
        $peer = (int)$r['sender_id'] === $me ? (int)$r['recipient_id'] : (int)$r['sender_id'];
        $last[$peer] = $r;
        $ids[$peer] = true;
    }
    if (!$ids) {
        return [];
    }
    $unread = [];
    foreach (qall('SELECT sender_id, COUNT(*) c FROM direct_messages WHERE recipient_id = ? AND is_read = 0 GROUP BY sender_id', [$me]) as $r) {
        $unread[(int)$r['sender_id']] = (int)$r['c'];
    }
    $in = implode(',', array_map('intval', array_keys($ids)));
    $out = [];
    foreach (qall("SELECT id, username, display_name, avatar_color, avatar_path, allow_messages, status FROM users WHERE id IN ($in) AND status = 'active'") as $u) {
        $id = (int)$u['id'];
        $u['last_body'] = $last[$id]['body'] ?? null;
        $u['last_at']   = $last[$id]['created_at'] ?? null;
        $u['last_id']   = (int)($last[$id]['id'] ?? 0);
        $u['last_mine'] = isset($last[$id]) && (int)$last[$id]['sender_id'] === $me;
        $u['unread']    = $unread[$id] ?? 0;
        $u['mutual']    = isset($mutual[$id]);
        $out[] = $u;
    }
    usort($out, fn ($a, $b) => [$b['last_id'], $a['display_name']] <=> [$a['last_id'], $b['display_name']]);
    return $out;
}
