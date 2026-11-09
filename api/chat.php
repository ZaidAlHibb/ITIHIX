<?php

require_once __DIR__ . '/../includes/bootstrap.php';
$me = require_login();
if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Metode tidak valid.'], 405);
}
csrf_check();
$uid = (int)$me['id'];
chat_ensure_schema();
$action = (string)($_POST['action'] ?? 'send');
$cid = (int)($_POST['conversation_id'] ?? 0);

switch ($action) {
    case 'list': {
        chat_migrate_legacy($uid);
        json_out(['ok' => true, 'conversations' => chat_list($uid, (string)($_POST['q'] ?? ''))]);
    }

    case 'load': {
        $c = $cid ? chat_find($uid, $cid) : null;
        if (!$c) {
            json_out(['ok' => false, 'error' => 'Percakapan tidak ditemukan.'], 404);
        }
        json_out(['ok' => true, 'conversation' => $c, 'messages' => chat_messages($uid, $cid)]);
    }

    case 'rename': {
        $c = $cid ? chat_find($uid, $cid) : null;
        if (!$c) {
            json_out(['ok' => false, 'error' => 'Percakapan tidak ditemukan.'], 404);
        }
        $title = trim(preg_replace('/\s+/u', ' ', (string)($_POST['title'] ?? '')));
        if ($title === '' || mb_strlen($title) > 80) {
            json_out(['ok' => false, 'error' => 'Judul harus 1–80 karakter.'], 422);
        }
        q('UPDATE chat_conversations SET title = ? WHERE id = ? AND user_id = ?', [$title, $cid, $uid]);
        json_out(['ok' => true, 'title' => $title]);
    }

    case 'delete': {
        $c = $cid ? chat_find($uid, $cid) : null;
        if (!$c) {
            json_out(['ok' => false, 'error' => 'Percakapan tidak ditemukan.'], 404);
        }
        chat_attachments_purge($uid, $cid);
        q('DELETE FROM chat_messages WHERE conversation_id = ? AND user_id = ?', [$cid, $uid]);
        q('DELETE FROM chat_conversations WHERE id = ? AND user_id = ?', [$cid, $uid]);
        json_out(['ok' => true]);
    }

    case 'send': {
        // Total upload melebihi post_max_size: PHP membuang seluruh $_POST dan $_FILES
        if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            json_out(['ok' => false, 'error' => 'Total ukuran file melebihi batas server (post_max_size ' . ini_get('post_max_size') . ').'], 413);
        }
        $msg = trim((string)($_POST['message'] ?? ''));
        $files = chat_files_normalize($_FILES['files'] ?? null);
        if ($msg === '' && !$files) {
            json_out(['ok' => false, 'error' => 'Tulis pesan atau lampirkan file.'], 422);
        }
        if (mb_strlen($msg) > 1500) {
            json_out(['ok' => false, 'error' => 'Pesan maksimal 1500 karakter.'], 422);
        }
        $conv = null;
        if ($cid) {
            $conv = chat_find($uid, $cid);
            if (!$conv) {
                json_out(['ok' => false, 'error' => 'Percakapan tidak ditemukan. Mulai percakapan baru.'], 404);
            }
        }
        if (!rate_limit('chat', 15, 60)) {
            json_out(['ok' => false, 'error' => 'Terlalu banyak pesan, tunggu sebentar.'], 429);
        }
        $saved = chat_files_save($uid, $files);
        if (!$saved['ok']) {
            json_out(['ok' => false, 'error' => $saved['error']], 422);
        }
        $atts = $saved['items'];
        session_write_close(); // jangan kunci sesi selama menunggu AI (halaman lain tetap lancar)

        $isNew = false;
        if (!$conv) {
            $title = $msg !== '' ? chat_title_from($msg) : chat_title_from('Analisis ' . $atts[0]['original_name']);
            q('INSERT INTO chat_conversations (user_id, title) VALUES (?,?)', [$uid, $title]);
            $cid = (int)db()->lastInsertId();
            $isNew = true;
        }
        $reply = chat_reply($uid, $msg, $cid, $atts);
        q('INSERT INTO chat_messages (user_id, conversation_id, role, content) VALUES (?,?,?,?)', [$uid, $cid, 'user', $msg]);
        $userMsgId = (int)db()->lastInsertId();
        q('INSERT INTO chat_messages (user_id, conversation_id, role, content) VALUES (?,?,?,?)', [$uid, $cid, 'assistant', $reply]);
        chat_attachments_link($atts, $uid, $cid, $userMsgId);
        q('UPDATE chat_conversations SET updated_at = NOW() WHERE id = ?', [$cid]);
        json_out(['ok' => true, 'reply' => $reply, 'is_new' => $isNew, 'conversation' => chat_find($uid, $cid),
                  'attachments' => array_map('chat_attachment_meta', $atts)]);
    }

    default:
        json_out(['ok' => false, 'error' => 'Aksi tidak dikenal.'], 400);
}
