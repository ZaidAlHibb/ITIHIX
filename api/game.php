<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/game.php';
$me = require_login();
$uid = (int)$me['id'];
game_ensure_tables();

$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'state');

try {
    // --- Baca saja: dipanggil tiap ~2 detik oleh klien. Sesi dilepas dulu agar tidak saling mengunci. ---
    if ($action === 'state' && !is_post()) {
        session_write_close();
        $st = game_state($uid);
        if (isset($_GET['since']) && isset($st['rev']) && hash_equals((string)$st['rev'], (string)$_GET['since'])) {
            json_out(['ok' => true, 'unchanged' => true, 'rev' => $st['rev'], 'now' => time()]);
        }
        json_out(['ok' => true, 'now' => time()] + $st);
    }

    if (!is_post()) {
        json_out(['ok' => false, 'error' => 'Metode tidak valid.'], 405);
    }
    csrf_check();
    if (!rate_limit('game', 60, 60)) {
        json_out(['ok' => false, 'error' => 'Terlalu cepat, tunggu sebentar.'], 429);
    }

    switch ($action) {
        case 'create':
            game_create($uid, (string)($_POST['visibility'] ?? 'public'));
            break;
        case 'find':
            game_find($uid);
            break;
        case 'history':
            json_out(['ok' => true, 'now' => time(), 'history' => game_history($uid)]);
            // no break
        case 'match':
            json_out(['ok' => true, 'now' => time(), 'match' => game_match_detail($uid, (int)($_POST['id'] ?? 0))]);
            // no break
        case 'join':
            game_join($uid, (string)($_POST['code'] ?? ''));
            break;
        case 'send':
            game_chat($uid, (string)($_POST['message'] ?? ''), (string)($_POST['category'] ?? ''));
            break;
        case 'guess':
            game_guess($uid, (string)($_POST['guess'] ?? ''), (string)($_POST['reason'] ?? ''));
            break;
        case 'next':
            game_next($uid);
            break;
        case 'leave':
            game_leave($uid);   // tanpa game_ack: pemain yang mengakhiri match juga melihat layar hasil
            break;
        case 'ack':
            game_ack($uid);
            break;
        default:
            json_out(['ok' => false, 'error' => 'Aksi tidak dikenal.'], 400);
    }
    $st = game_state($uid);
    json_out(['ok' => true, 'now' => time()] + $st);
} catch (GameError $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], $e->httpCode);
} catch (PDOException $e) {
    error_log('[ITIHIX game] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'DEBUG: ' . $e->getMessage()], 500);
}
