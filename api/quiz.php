<?php

require_once __DIR__ . '/../includes/bootstrap.php';
$me = require_login();
$uid = (int)$me['id'];

const QUIZ_DAILY_LIMIT = 50; // batas soal yang bisa dijawab / di-generate per user per hari (reset tengah malam, mengikuti jam server database)

/**
 * Kuota harian user.
 *  used    = soal yang sudah dijawab hari ini
 *  left    = sisa soal yang boleh dijawab hari ini (LIMIT - used)
 *  pending = soal yang sudah dibagikan tetapi belum dijawab (terbawa lintas hari)
 *  can_gen = berapa soal BARU yang boleh di-generate sekarang = left - pending (tidak pernah negatif).
 * Contoh: berhenti di soal ke-10 -> pending 40, left 40, can_gen 0 (lanjut tanpa generate).
 *         Besoknya: pending 30 tersisa, left 50 -> setelah 30 habis, di-generate 20 soal baru.
 */
function quiz_quota(int $uid): array
{
    $used = (int)qval('SELECT COUNT(*) FROM quiz_attempts WHERE user_id = ? AND DATE(created_at) = CURDATE()', [$uid]);
    $pending = (int)qval('SELECT COUNT(*) FROM quiz_assigned WHERE user_id = ? AND answered_at IS NULL', [$uid]);
    $left = max(0, QUIZ_DAILY_LIMIT - $used);
    return ['limit' => QUIZ_DAILY_LIMIT, 'used' => $used, 'left' => $left, 'pending' => $pending, 'can_gen' => max(0, $left - $pending)];
}

/** Statistik kuis: streak sekarang, skor terbaik (streak jawaban benar beruntun terpanjang), ringkasan hari ini, dan log harian. */
function quiz_stats(int $uid): array
{
    $run = 0;
    $best = 0;
    $ok = 0;
    $bad = 0;
    foreach (qall('SELECT is_correct FROM quiz_attempts WHERE user_id = ? ORDER BY id', [$uid]) as $r) {
        if ((int)$r['is_correct']) {
            $run++;
            $ok++;
            if ($run > $best) {
                $best = $run;
            }
        } else {
            $run = 0;
            $bad++;
        }
    }
    $today = qone('SELECT COUNT(*) total, COALESCE(SUM(is_correct),0) ok FROM quiz_attempts WHERE user_id = ? AND DATE(created_at) = CURDATE()', [$uid]);
    $log = [];
    foreach (qall('SELECT DATE(created_at) d, COUNT(*) total, SUM(is_correct) ok FROM quiz_attempts WHERE user_id = ? GROUP BY DATE(created_at) ORDER BY d DESC LIMIT 14', [$uid]) as $r) {
        $log[] = ['date' => $r['d'], 'ok' => (int)$r['ok'], 'bad' => (int)$r['total'] - (int)$r['ok']];
    }
    return [
        'streak' => $run, 'best' => $best, 'total_ok' => $ok, 'total_bad' => $bad,
        'today_ok' => (int)$today['ok'], 'today_bad' => (int)$today['total'] - (int)$today['ok'], 'log' => $log,
        'quota' => quiz_quota($uid),
    ];
}

/** Pastikan kolom `highlights` ada di quiz_questions (migrasi otomatis, aman dipanggil berulang). */
function quiz_ensure_cols(): void
{ /* kolom & tabel dibuat oleh migrations/ */
}
quiz_ensure_cols();

/** Soal bawaan skenario "akun kontak tersimpan dibajak" + kiriman file. Dimasukkan sekali agar ada di bank soal meski AI mati. */
function quiz_seed_extra(): void
{
    try {
        if ((int)qval("SELECT COUNT(*) FROM quiz_questions WHERE topic = 'Akun teman dibajak'") > 0) {
            return;
        }
        $rows = [
            ['Rina Kampus', '+62 812-xxxx-3341', 1, 'Akun teman dibajak', [
                ['text' => 'Hai, kamu lagi online? Aku lagi kepepet banget nih', 'time' => '19.42'],
                ['text' => 'HP-ku error, bisa tolong transfer 350rb dulu ke rekening sepupuku? Nanti malam aku ganti. Jangan telepon ya, lagi di tempat ramai', 'time' => '19.43'],
            ], 1, 'Nomornya memang kontak tersimpan, tetapi akun teman bisa dibajak. Penipu memakai gaya akrab lalu mendesak pinjam uang ke rekening orang lain dan melarang telepon agar tidak ketahuan.',
                ['Pinjam uang mendadak lewat chat', 'Rekening atas nama orang lain', 'Melarang telepon/verifikasi suara', 'Kontak tersimpan tidak menjamin aman'], [
                ['m' => 1, 't' => 'transfer 350rb dulu', 'w' => 'Pinjam uang mendadak lewat chat.'],
                ['m' => 1, 't' => 'rekening sepupuku', 'w' => 'Rekening bukan atas nama temanmu sendiri.'],
                ['m' => 1, 't' => 'Jangan telepon', 'w' => 'Menghindari verifikasi lewat suara.'],
            ], 2],
            ['Bang Dimas', '+62 857-xxxx-0912', 1, 'File APK dari kakak', [
                ['text' => 'Eh ini foto kita pas reuni kemarin, buruan lihat', 'time' => '20.05'],
                ['text' => '', 'file' => ['name' => 'Foto_Reuni_2026.apk', 'size' => '4,8 MB'], 'time' => '20.05'],
            ], 1, 'Foto tidak pernah berformat .apk. File itu adalah aplikasi yang bisa memasang malware dan membaca SMS OTP. Akun kakak kemungkinan sudah dibajak lalu dipakai menyebarkan file ke semua kontak.',
                ['Foto berekstensi .apk', 'Mendesak segera membuka file', 'Kontak tersimpan pun bisa dibajak'], [
                ['m' => 1, 't' => 'Foto_Reuni_2026.apk', 'w' => 'Foto tidak mungkin berformat .apk; ini aplikasi pemasang malware.', 'f' => 1],
                ['m' => 0, 't' => 'buruan lihat', 'w' => 'Mendesak korban membuka file tanpa berpikir.'],
            ], 1],
            ['Kak Salsa (PKN)', '+62 813-xxxx-7754', 1, 'File tugas kelompok', [
                ['text' => 'Ini materi presentasi yang kita bahas tadi ya. Besok jam 10.00 di perpustakaan kita revisi bareng, bagian slide 5 aja', 'time' => '16.20'],
                ['text' => '', 'file' => ['name' => 'Presentasi_PKN_Kelompok3.pdf', 'size' => '1,2 MB'], 'time' => '16.20'],
            ], 0, 'Pesan ini sesuai konteks tugas kelompok: ada rencana konkret (jam dan tempat), tidak meminta uang atau kode, dan file berformat dokumen yang wajar.',
                ['Rencana konkret dan masuk akal', 'File berformat dokumen (.pdf)', 'Tidak meminta data atau uang'], [
                ['m' => 0, 't' => 'jam 10.00 di perpustakaan', 'w' => 'Rencana konkret dan wajar.'],
                ['m' => 1, 't' => 'Presentasi_PKN_Kelompok3.pdf', 'w' => 'Format dokumen wajar sesuai konteks tugas.', 'f' => 1],
            ], 2],
            ['Tante Lilis', '+62 821-xxxx-5508', 1, 'Kode verifikasi tante', [
                ['text' => 'Sayang, tante salah kirim kode verifikasi WhatsApp ke nomor kamu. Tolong kirim ulang kode 6 digit yang masuk lewat SMS ya, penting banget', 'time' => '21.10'],
            ], 1, 'Kode verifikasi WhatsApp tidak boleh dikirim ke siapa pun. Penipu memakai akun kerabat yang sudah dibajak untuk memancing kode agar bisa membajak akun berikutnya.',
                ['Meminta kode verifikasi', 'Alasan "salah kirim" yang klasik', 'Akun kerabat bisa saja sudah dibajak'], [
                ['m' => 0, 't' => 'kirim ulang kode 6 digit', 'w' => 'Kode verifikasi akun tidak boleh dibagikan ke siapa pun.'],
                ['m' => 0, 't' => 'salah kirim kode verifikasi', 'w' => 'Alasan klasik untuk memancing kode.'],
            ], 2],
        ];
        foreach ($rows as [$name, $num, $saved, $topic, $msgs, $scam, $expl, $flags, $hl, $diff]) {
            q(
                'INSERT INTO quiz_questions (sender_name, sender_number, saved_contact, topic, messages, is_scam, explanation, red_flags, highlights, difficulty) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$name, $num, $saved, $topic, json_encode($msgs, JSON_UNESCAPED_UNICODE), $scam, $expl, json_encode($flags, JSON_UNESCAPED_UNICODE), json_encode($hl, JSON_UNESCAPED_UNICODE), $diff]
            );
        }
    } catch (Throwable $e) {
        error_log('[ITIHIX quiz seed] ' . $e->getMessage());
    }
}
quiz_seed_extra();

/** Highlight penentu untuk satu baris soal: dari AI bila ada, kalau tidak pakai aturan kata kunci. */
function quiz_row_highlights(array $row): array
{
    $msgs = quiz_fix_file_messages(json_decode((string)$row['messages'], true) ?: []);
    $hl = json_decode((string)($row['highlights'] ?? ''), true);
    if (is_array($hl) && $hl) {
        $hl = quiz_clean_highlights($hl, $msgs);
        if ($hl) {
            return $hl;
        }
    }
    return quiz_auto_highlights($msgs, (bool)$row['is_scam']);
}

const QUIZ_BATCH = 5; // jumlah soal yang dibuat AI sekaligus

/** Status ronde kuis di sesi (dikembalikan sebagai referensi). Panggil ulang setelah session_start() dipanggil lagi. */
function &quiz_round(): array
{
    if (!isset($_SESSION['quiz']) || !is_array($_SESSION['quiz'])) {
        $_SESSION['quiz'] = [];
    }
    $_SESSION['quiz'] += ['served' => [], 'pending' => [], 'topics' => [], 'answered' => 0];
    return $_SESSION['quiz'];
}
$round = &quiz_round();

if (is_post()) {
    csrf_check();
    if (($_POST['action'] ?? '') !== 'answer') {
        json_out(['ok' => false, 'error' => 'Aksi tidak dikenal.'], 400);
    }
    $qid = (int)($_POST['question_id'] ?? 0);
    if (!in_array($qid, $round['pending'], true)) {
        json_out(['ok' => false, 'error' => 'Soal tidak sesuai atau sudah dijawab.'], 409);
    }
    $q = qone('SELECT * FROM quiz_questions WHERE id = ?', [$qid]);
    if (!$q) {
        json_out(['ok' => false, 'error' => 'Soal tidak ditemukan.'], 404);
    }
    if (quiz_quota($uid)['left'] <= 0) {
        json_out(['ok' => false, 'quota_exhausted' => true, 'error' => 'Kuota ' . QUIZ_DAILY_LIMIT . ' soal hari ini sudah habis. Tunggu server reset besok ya.', 'stats' => quiz_stats($uid)], 429);
    }
    $ans = (int)($_POST['answer'] ?? 0) === 1 ? 1 : 0;
    $correct = $ans === (int)$q['is_scam'];
    $bestBefore = quiz_stats($uid)['best'];
    $sid = (string)($round['sid'] ?? '');
    if ($sid === '') {
        $sid = bin2hex(random_bytes(6));
        $round['sid'] = $sid;
    }
    q('INSERT INTO quiz_attempts (user_id, question_id, is_correct, session_id) VALUES (?,?,?,?)', [$uid, $qid, $correct ? 1 : 0, $sid]);
    q('INSERT INTO quiz_assigned (user_id, question_id, assigned_at, answered_at) VALUES (?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE answered_at = NOW()', [$uid, $qid]);
    $xp = 0;
    if ($correct) {
        $xp = 10 + 5 * ((int)$q['difficulty'] - 1);
        add_xp($uid, $xp, 'kuis');
    }
    $round['answered']++;
    $round['pending'] = array_values(array_diff($round['pending'], [$qid]));
    log_activity('quiz_answer', 'quiz', $qid, $correct ? 'benar' : 'salah');
    $stats = quiz_stats($uid);
    json_out(['ok' => true, 'correct' => $correct, 'is_scam' => (bool)$q['is_scam'], 'xp' => $xp,
        'explanation' => $q['explanation'], 'red_flags' => json_decode($q['red_flags'], true) ?: [],
        'highlights' => quiz_row_highlights($q), 'sid' => $sid,
        'stats' => $stats, 'new_best' => $stats['best'] > $bestBefore]);
}

// action=stats: hanya statistik (tanpa membuat soal) untuk layar awal sebelum game dimulai.
if (($_GET['action'] ?? '') === 'stats') {
    json_out(['ok' => true, 'stats' => quiz_stats($uid)]);
}

// action=history: riwayat dikelompokkan per sesi main (satu kali Mulai Game -> Akhiri = satu grup), lengkap dengan soal,
// penjelasan, dan highlight penentu supaya bisa dipakai belajar ulang. Jawaban lama tanpa session_id dikelompokkan per jeda waktu > 30 menit.
if (($_GET['action'] ?? '') === 'history') {
    $rows = qall('SELECT a.id AS aid, a.is_correct, a.created_at AS answered_at, a.session_id, q.id, q.sender_name, q.sender_number, q.saved_contact, q.topic,
                         q.messages, q.is_scam, q.explanation, q.red_flags, q.highlights
                  FROM quiz_attempts a JOIN quiz_questions q ON q.id = a.question_id
                  WHERE a.user_id = ? ORDER BY a.id DESC LIMIT 300', [$uid]);
    $groups = [];
    $bucket = 0;
    $prevKey = null;
    $prevTs = null;
    foreach ($rows as $r) {
        $ok = (bool)(int)$r['is_correct'];
        $scam = (bool)(int)$r['is_scam'];
        $ts = strtotime((string)$r['answered_at']) ?: 0;
        if (!empty($r['session_id'])) {
            $key = (string)$r['session_id'];
        } else {
            if ($prevKey === null || $prevKey[0] !== 'o' || ($prevTs - $ts) > 1800) {
                $bucket++;
            }
            $key = 'o' . $bucket;
        }
        $prevKey = $key;
        $prevTs = $ts;
        if (!isset($groups[$key])) {
            if (count($groups) >= 25) {
                break;
            }
            $groups[$key] = ['sid' => $key, 'at' => $r['answered_at'], 'items' => []];
        }
        $groups[$key]['at'] = $r['answered_at']; // baris diurut menurun: yang terakhir terbaca = soal pertama sesi
        $groups[$key]['items'][] = [
            'id' => (int)$r['id'], 'aid' => (int)$r['aid'], 'correct' => $ok, 'is_scam' => $scam,
            'user_answer' => ($ok ? $scam : !$scam) ? 1 : 0, 'at' => $r['answered_at'],
            'sender' => $r['sender_name'], 'number' => $r['sender_number'], 'saved' => (bool)$r['saved_contact'], 'topic' => $r['topic'],
            'messages' => quiz_fix_file_messages(json_decode((string)$r['messages'], true) ?: []), 'explanation' => $r['explanation'],
            'red_flags' => json_decode((string)$r['red_flags'], true) ?: [], 'highlights' => quiz_row_highlights($r),
        ];
    }
    foreach ($groups as &$g) {
        $g['items'] = array_reverse($g['items']);
    } // urut kronologis di dalam sesi
    unset($g);
    json_out(['ok' => true, 'stats' => quiz_stats($uid), 'groups' => array_values($groups)]);
}

// fresh=1: dikirim klien saat tombol "Mulai Game" ditekan -> mulai ronde baru (nomor soal & antrean jawaban direset).
if (!empty($_GET['fresh'])) {
    $round['sid'] = bin2hex(random_bytes(6));
    $round['answered'] = 0;
    $round['pending'] = [];
    $round['topics'] = [];
}

// action=batch: siapkan sampai QUIZ_BATCH soal. Urutan prioritas:
//   1) soal yang sudah dibagikan ke user tetapi belum dijawab (lanjutan, TANPA generate);
//   2) bila kurang, generate soal baru HANYA sebatas sisa kuota harian (kuota - jawaban hari ini - soal tertunda);
//   3) bila kuota habis dan tidak ada soal tertunda -> minta user menunggu reset server.
// Generate hanya terjadi saat game berjalan (Mulai Game / prefetch); membuka halaman cuma membaca statistik.
set_time_limit(150);
$quota = quiz_quota($uid);
$stats = quiz_stats($uid);
$difficulty = min(3, 1 + intdiv($stats['streak'], 3));
$avoid = array_slice($round['topics'], -10);
$startIndex = $round['answered'] + 1;

$ids = fn (array $a) => $a ? implode(',', array_map('intval', $a)) : '0';
$assign = function (int $qid) use ($uid) {
    q('INSERT INTO quiz_assigned (user_id, question_id, assigned_at, answered_at) VALUES (?,?,NOW(),NULL) ON DUPLICATE KEY UPDATE assigned_at = NOW(), answered_at = NULL', [$uid, $qid]);
};

// Ruang yang masih boleh diisi: sisa kuota dikurangi soal yang sedang dipegang klien (belum dijawab) pada game ini.
$room = max(0, min(QUIZ_BATCH, $quota['left'] - count($round['pending'])));

// 1) Soal tertunda (belum dijawab), paling lama dulu, selain yang sudah dikirim ke klien pada game ini.
$rows = [];
if ($room > 0) {
    $rows = qall('SELECT qq.* FROM quiz_assigned a JOIN quiz_questions qq ON qq.id = a.question_id
                  WHERE a.user_id = ? AND a.answered_at IS NULL AND qq.id NOT IN (' . $ids($round['pending']) . ')
                  ORDER BY a.assigned_at ASC, qq.id ASC LIMIT ' . (int)$room, [$uid]);
}

// 2) Generate sisanya, dibatasi kuota.
$want = max(0, min($room - count($rows), $quota['can_gen']));
if ($want > 0) {
    $useAi = ai_enabled() && rate_limit('quiz_ai', 8, 300);
    $gens = [];
    if ($useAi) {
        // Lepas kunci sesi selama AI bekerja (bisa puluhan detik). Tanpa ini, permintaan jawaban user akan menunggu di antrean.
        session_write_close();
        $gens = generate_quiz_batch($want, $difficulty, $avoid);
        ini_set('session.use_cookies', '0');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cache_limiter', '');
        session_start(); // id sesi yang sama; $_SESSION dimuat ulang (termasuk jawaban yang masuk selama AI bekerja)
        $round = &quiz_round();
    }
    $new = [];
    foreach (array_slice($gens, 0, $want) as $gen) {
        q('INSERT INTO quiz_questions (sender_name, sender_number, saved_contact, topic, messages, is_scam, explanation, red_flags, highlights, difficulty) VALUES (?,?,?,?,?,?,?,?,?,?)', [
            $gen['sender_name'], $gen['sender_number'], $gen['saved_contact'], $gen['topic'],
            json_encode($gen['messages'], JSON_UNESCAPED_UNICODE), $gen['is_scam'], $gen['explanation'],
            json_encode($gen['red_flags'], JSON_UNESCAPED_UNICODE), json_encode($gen['highlights'] ?? [], JSON_UNESCAPED_UNICODE), $gen['difficulty'],
        ]);
        $row = qone('SELECT * FROM quiz_questions WHERE id = ?', [(int)db()->lastInsertId()]);
        if ($row) {
            $new[] = $row;
            $round['topics'] = array_slice(array_merge($round['topics'], [$row['topic']]), -10);
        }
    }
    // AI mati / dibatasi / balasan kurang: lengkapi dari bank soal (tetap dihitung ke kuota karena ikut dibagikan ke user).
    $held = array_map('intval', array_column(qall('SELECT question_id FROM quiz_assigned WHERE user_id = ? AND answered_at IS NULL', [$uid]), 'question_id'));
    $bank = function (array $exclude) use ($uid, $ids) {
        return qone('SELECT qq.* FROM quiz_questions qq WHERE qq.id NOT IN (' . $ids($exclude) . ')
                     ORDER BY (SELECT COUNT(*) FROM quiz_attempts a WHERE a.user_id = ? AND a.question_id = qq.id) ASC, RAND() LIMIT 1', [$uid]);
    };
    $taken = array_merge($held, array_map(fn ($r) => (int)$r['id'], $new));
    while (count($new) < $want) {
        $row = $bank(array_merge($round['served'], $taken));
        if (!$row && $round['served']) {
            $round['served'] = [];
            $row = $bank($taken);
        } // bank habis: putar ulang
        if (!$row) {
            break;
        }
        $new[] = $row;
        $taken[] = (int)$row['id'];
    }
    foreach ($new as $r) {
        $assign((int)$r['id']);
    }
    $rows = array_merge($rows, $new);
}

if (!$rows) {
    $quota = quiz_quota($uid);
    if ($quota['left'] <= 0) {
        json_out(['ok' => false, 'quota_exhausted' => true, 'quota' => $quota, 'stats' => quiz_stats($uid),
            'error' => 'Kuota ' . QUIZ_DAILY_LIMIT . ' soal hari ini sudah habis. Tunggu server reset besok, soal yang belum terjawab akan dilanjutkan.'], 429);
    }
    // Soal yang tersisa sedang dipegang klien pada game ini (belum dijawab): tidak ada yang baru untuk dikirim.
    if ($round['pending']) {
        json_out(['ok' => true, 'start_index' => $startIndex, 'stats' => $stats, 'questions' => []]);
    }
    json_out(['ok' => false, 'error' => 'Belum ada soal. Isi API key AI atau impor database.sql.'], 503);
}

$batchIds = array_map(fn ($r) => (int)$r['id'], $rows);
$round['served'] = array_slice(array_merge($round['served'], $batchIds), -200);
$round['pending'] = array_slice(array_merge($round['pending'], $batchIds), -40);
json_out(['ok' => true, 'start_index' => $startIndex, 'stats' => quiz_stats($uid), 'questions' => array_map(fn ($r) => [
    'id' => (int)$r['id'], 'sender' => $r['sender_name'], 'number' => $r['sender_number'], 'saved' => (bool)$r['saved_contact'],
    'topic' => $r['topic'], 'messages' => quiz_fix_file_messages(json_decode($r['messages'], true) ?: []),
], $rows)]);
