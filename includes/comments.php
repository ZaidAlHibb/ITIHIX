<?php

/** Helper komentar bersama: dipakai api/comments.php dan api/comment_vote.php. Dimuat setelah includes/bootstrap.php. */

/** Kolom parent_id (balasan komentar) dibuat otomatis bila belum ada. Mengembalikan true jika fitur balasan aktif. */
function comments_have_parent(): bool
{
    return schema_ready();
}   // kolom parent_id dibuat oleh migrations/

/** Tabel suka/tidak suka komentar dibuat otomatis bila belum ada (instalasi lama). */
function ensure_comment_votes(): bool
{
    return schema_ready();
}   // tabel comment_votes dibuat oleh migrations/

/** Pola @username (sama dengan aturan nama pengguna: huruf/angka/underscore, 3–30 karakter). Tidak cocok untuk alamat email. */
const COMMENT_MENTION_RE = '/(?<![A-Za-z0-9_@])@([A-Za-z0-9_]{3,30})(?![A-Za-z0-9_])/';

/** Cek sekaligus nama mana yang benar-benar pengguna aktif. Hasil di-cache: huruf kecil => username asli | false. */
function comment_mentions_lookup(array $names): array
{
    static $cache = [];
    $need = [];
    foreach ($names as $n) {
        $k = strtolower((string)$n);
        if (!array_key_exists($k, $cache)) {
            $need[$k] = true;
        }
    }
    if ($need) {
        $keys = array_keys($need);
        foreach ($keys as $k) {
            $cache[$k] = false;
        }
        try {
            $in = implode(',', array_fill(0, count($keys), '?'));
            foreach (qall("SELECT username FROM users WHERE status = 'active' AND username IN ($in)", $keys) as $r) {
                $cache[strtolower($r['username'])] = $r['username'];
            }
        } catch (Throwable $e) {
            error_log('comment mentions: ' . $e->getMessage());
        }
    }
    return $cache;
}

/** Kumpulkan semua @nama dari banyak komentar lalu cek dengan satu query (hindari query per komentar). */
function comment_prefetch_mentions(array $rows): void
{
    $names = [];
    foreach ($rows as $r) {
        if (preg_match_all(COMMENT_MENTION_RE, (string)$r['body'], $m)) {
            foreach ($m[1] as $n) {
                $names[strtolower($n)] = $n;
            }
        }
    }
    if ($names) {
        comment_mentions_lookup(array_values($names));
    }
}

/** Isi komentar -> HTML aman; @username yang valid jadi tautan ke profil (seperti tag di media sosial). */
function comment_body_html(string $body): string
{
    $safe = e($body);
    if (preg_match_all(COMMENT_MENTION_RE, $safe, $m)) {
        $map = comment_mentions_lookup($m[1]);
        $safe = preg_replace_callback(COMMENT_MENTION_RE, function ($x) use ($map) {
            $u = $map[strtolower($x[1])] ?? false;
            if (!$u) {
                return $x[0];
            }
            return '<a class="mention" href="' . e(url(profile_url($u))) . '">@' . e($u) . '</a>';
        }, $safe);
    }
    return nl2br($safe);
}

/** Siapa boleh menghapus komentar: pemilik; admin (semua); moderator (komentar milik pengguna biasa). Sama dengan aturan hapus postingan. */
function can_delete_comment(?array $viewer, array $c): bool
{
    if (!$viewer) {
        return false;
    }
    if ((int)$viewer['id'] === (int)$c['user_id']) {
        return true;
    }
    if ($viewer['role'] === 'admin') {
        return true;
    }
    return $viewer['role'] === 'moderator' && in_array($c['role'] ?? 'user', ['user', 'trusted'], true);
}

/** Tandai satu komentar + seluruh balasannya (bersarang) sebagai terhapus. Mengembalikan daftar id yang dihapus. Dipakai api/comment_delete.php dan moderator.php. */
function comment_delete_tree(int $cid): array
{
    $ids = [$cid];
    $frontier = [$cid];
    if (comments_have_parent()) {
        while ($frontier && count($ids) < 2000) {
            $in = implode(',', array_fill(0, count($frontier), '?'));
            $next = array_map('intval', array_column(qall("SELECT id FROM comments WHERE parent_id IN ($in) AND deleted_at IS NULL", $frontier), 'id'));
            $next = array_values(array_diff($next, $ids));
            $ids = array_merge($ids, $next);
            $frontier = $next;
        }
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    q("UPDATE comments SET deleted_at = NOW() WHERE id IN ($in) AND deleted_at IS NULL", $ids);
    return $ids;
}

/** Menu titik tiga di sisi kanan komentar: pemilik = hapus; moderator/admin = hapus paksa; pengguna biasa = laporkan. */
function comment_menu(array $c, array $me): string
{
    $id = (int)$c['id'];
    $own = (int)$me['id'] === (int)$c['user_id'];
    $items = '';
    if (can_delete_comment($me, $c)) {
        $label = $own ? 'Hapus komentar' : 'Hapus paksa (' . role_label((string)$me['role']) . ')';
        $items = '<button class="post-menu-item danger" type="button" role="menuitem" data-cm-delete="' . $id . '">' . icon('trash') . ' ' . e($label) . '</button>';
    } elseif (!$own && !is_staff($me)) {
        $items = '<button class="post-menu-item" type="button" role="menuitem" data-cm-report="' . $id . '" data-user="' . (int)$c['user_id'] . '" data-name="@' . e($c['username']) . '">' . icon('flag') . ' Laporkan</button>';
    }
    if ($items === '') {
        return '';
    }
    return '<div class="post-menu comment-menu"><button class="comment-menu-btn" type="button" data-menu-toggle aria-haspopup="true" aria-expanded="false" aria-label="Opsi komentar" title="Opsi">⋯</button>'
         . '<div class="post-menu-list" role="menu">' . $items . '</div></div>';
}

/** Angka skor ringkas: 1200 -> 1,2rb. */
function comment_score_fmt(int $n): string
{
    $abs = abs($n);
    if ($abs < 1000) {
        return (string)$n;
    }
    return ($n < 0 ? '-' : '') . rtrim(rtrim(number_format($abs / 1000, 1, ',', ''), '0'), ',') . 'rb';
}

/** Vote ala Reddit: panah naik, skor (suka - tidak suka), panah turun. Pilihan yang sama ditekan lagi = batal. */
function comment_vote_buttons(array $c): string
{
    $id = (int)$c['id'];
    $score = (int)($c['likes'] ?? 0) - (int)($c['dislikes'] ?? 0);
    $mine = (int)($c['my_vote'] ?? 0);
    $cls = $mine === 1 ? ' is-up' : ($mine === -1 ? ' is-down' : '');
    $b  = '<div class="cm-votes' . $cls . '" data-cid="' . $id . '" role="group" aria-label="Vote komentar">';
    $b .= '<button type="button" class="cm-vote cm-up" data-cid="' . $id . '" data-vote="like" aria-pressed="' . ($mine === 1 ? 'true' : 'false') . '" aria-label="Upvote">' . icon('arrow-down') . '</button>';
    $b .= '<span class="cm-score" title="' . $score . '">' . comment_score_fmt($score) . '</span>';
    $b .= '<button type="button" class="cm-vote cm-down" data-cid="' . $id . '" data-vote="dislike" aria-pressed="' . ($mine === -1 ? 'true' : 'false') . '" aria-label="Downvote">' . icon('arrow-down') . '</button>';
    return $b . '</div>';
}

/** Jumlah seluruh turunan (balasan, balasan dari balasan, dst.) dari satu komentar. */
function comment_descendants(int $id, array $kids): int
{
    $n = 0;
    foreach ($kids[$id] ?? [] as $k) {
        $n += 1 + comment_descendants((int)$k['id'], $kids);
    }
    return $n;
}

/**
 * Satu komentar + seluruh balasannya (bersarang). Balasan selalu muncul tepat di bawah komentar yang dibalas.
 * $kids = peta parent_id => daftar balasan. Indentasi visual dibatasi (kelas "deep" mulai kedalaman 3) agar tidak menyempit di layar kecil.
 */
function render_comment(array $c, array $me, array $kids = [], bool $canReply = true, int $depth = 0): string
{
    $id = (int)$c['id'];
    $isReply = !empty($c['parent_id']);
    $h  = '<div class="comment-item' . ($isReply ? ' is-reply' : '') . '" id="cm-' . $id . '">' . avatar_html($c) . '<div class="comment-main">';
    $h .= '<div class="comment-row"><div class="comment-bubble"><b><a href="' . e(url(profile_url($c['username']))) . '" style="color:inherit;text-decoration:none">' . e($c['display_name']) . '</a>' . role_badge((string)($c['role'] ?? 'user')) . '</b>';
    if ($c['show_level']) {
        $h .= '<span>Lv. ' . user_level($c) . '</span>';
    }
    $h .= '<span>' . e(time_ago($c['created_at'])) . '</span><p>' . comment_body_html((string)$c['body']) . '</p>';
    $h .= '</div>' . comment_menu($c, $me) . '</div>'; // tutup .comment-bubble lalu menu, tutup .comment-row
    $h .= '<div class="comment-actions">' . comment_vote_buttons($c);
    if ($canReply) {
        $h .= '<button type="button" class="comment-reply" data-reply="' . $id . '" data-user="' . e($c['username']) . '">' . icon('comment') . '<span>Balas</span></button>';
    }
    $h .= '</div>';
    if ($canReply) {
        $children = $kids[$id] ?? [];
        $n = comment_descendants($id, $kids);
        $h .= '<button type="button" class="comment-toggle" data-target="cr-' . $id . '" data-n="' . $n . '"' . ($n ? '' : ' hidden') . '>' . icon('chev-down') . '<span>Lihat ' . $n . ' balasan</span></button>';
        $h .= '<div class="comment-replies is-collapsed' . ($depth >= 2 ? ' deep' : '') . '" id="cr-' . $id . '">'
            . implode('', array_map(fn ($r) => render_comment($r, $me, $kids, true, $depth + 1), $children)) . '</div>';
    }
    return $h . '</div></div>';
}
