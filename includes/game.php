<?php

/**
 * Game "Tebak Scam" (2 pemain, bergiliran).
 * Satu pemain jadi PENGIRIM (diam-diam diberi peran: Penipu atau Bukan penipu) dan menulis pesan;
 * pemain lain jadi PENEBAK yang harus menebak pesan itu penipuan atau bukan. Tebakan benar -> keduanya menang + XP.
 * Peran tukar tiap ronde. Komunikasi lewat polling (tanpa WebSocket) sehingga jalan di XAMPP/hosting biasa.
 */

const GAME_ROUNDS        = 4;    // jumlah ronde per ruangan (genap: tiap pemain jadi pengirim sama banyak)
const GAME_XP            = 15;   // XP per pemain untuk tiap tebakan benar
const GAME_DAILY_WINS    = 6;    // batas ronde ber-XP per pemain per hari (anti farming)
const GAME_PAIR_DAILY    = 3;    // batas ronde ber-XP per pasangan pemain per hari (anti kolusi dua akun)
const GAME_MSG_MIN       = 1;    // pesan pembuka pengirim: minimal (tanpa batas minimum, asal tidak kosong)
const GAME_MSG_MAX       = 600;  // pesan pembuka pengirim: maksimal
const GAME_REPLY_MAX     = 300;  // pesan lanjutan (balasan & chat berikutnya): maksimal
const GAME_CHAT_SENDER   = 3;    // jatah chat pengirim per ronde
const GAME_CHAT_GUESSER  = 2;    // jatah chat penebak per ronde (urutan: P-T-P-T-P, total 5)
const GAME_CHAT_TOTAL    = GAME_CHAT_SENDER + GAME_CHAT_GUESSER;
const GAME_ONLINE_SEC    = 20;   // pemain dianggap offline bila tak ada polling selama ini
const GAME_WAIT_EXPIRE   = 120;  // ruangan menunggu tanpa host aktif selama ini -> kedaluwarsa
const GAME_IDLE_EXPIRE   = 600;  // ruangan berjalan tanpa aktivitas kedua pemain -> berakhir
const GAME_OPEN_SEC      = 120;  // batas waktu pengirim membuka chat (habis -> ronde dilewati tanpa XP)
const GAME_TURN_SEC      = 45;   // batas waktu membalas chat (habis -> giliran dilewati, jatah chat terpakai)
const GAME_GUESS_SEC     = 45;   // batas waktu penebak mengunci tebakan setelah jatah chat habis (habis -> dianggap salah)
const GAME_NEXT_SEC      = 20;   // hitung mundur lanjut: baru mulai SETELAH salah satu pemain menekan siap; habis -> lanjut otomatis
const GAME_HISTORY_LIMIT = 30;   // jumlah match terakhir di riwayat

/** Kategori modus yang bisa dipilih pemain saat berperan jadi penipu. */
function game_categories(): array
{
    return [
        'hadiah'    => ['name' => 'Hadiah / undian palsu',          'hint' => 'Mengaku menang undian atau hadiah'],
        'phishing'  => ['name' => 'Phishing bank & e-wallet',       'hint' => 'Mengaku dari bank/dompet digital, minta OTP atau login'],
        'investasi' => ['name' => 'Investasi bodong',               'hint' => 'Menjanjikan untung besar dalam waktu singkat'],
        'loker'     => ['name' => 'Lowongan kerja palsu',           'hint' => 'Gaji besar, kerja mudah, minta biaya atau data'],
        'paket'     => ['name' => 'Paket / kurir palsu',            'hint' => 'Paket tertahan, minta biaya atau klik tautan'],
        'keluarga'  => ['name' => 'Penyamaran keluarga / kenalan',  'hint' => '“Ini nomor baru”, minta transfer mendesak'],
        'pinjol'    => ['name' => 'Pinjol & tagihan palsu',         'hint' => 'Tagihan atau ancaman utang yang tidak jelas'],
        'apk'       => ['name' => 'APK / file berbahaya',           'hint' => 'Membujuk memasang aplikasi atau membuka file'],
    ];
}

function game_ensure_tables(): void
{ /* tabel dibuat oleh migrations/ */
}

/** Tambahkan kolom baru pada instalasi lama (ruangan publik/privat, matchmaking, batas waktu). Dicek sekali per sesi. */
function game_migrate(): void
{ /* kolom dibuat oleh migrations/ */
}

function game_now(?int $ts = null): string
{
    return date('Y-m-d H:i:s', $ts ?? time());
}
class GameError extends Exception
{
    public int $httpCode;
    public function __construct(string $m, int $code = 422)
    {
        parent::__construct($m);
        $this->httpCode = $code;
    }
}

/* ---------- Ruangan ---------- */
function game_expire_rooms(): void
{
    $w = game_now(time() - GAME_WAIT_EXPIRE);
    $p = game_now(time() - GAME_IDLE_EXPIRE);
    $n = game_now();
    q("UPDATE game_rooms SET status='finished', finished_reason='expired', updated_at=? WHERE status='waiting' AND host_seen < ?", [$n, $w]);
    q("UPDATE game_rooms SET status='finished', finished_reason='expired', updated_at=? WHERE status='playing' AND host_seen < ? AND (guest_seen IS NULL OR guest_seen < ?)", [$n, $p, $p]);
}
function game_active_room(int $uid): ?array
{
    return qone("SELECT * FROM game_rooms WHERE status IN ('waiting','playing') AND (host_id = ? OR guest_id = ?) ORDER BY id DESC LIMIT 1", [$uid, $uid]);
}
function game_room_by_id(int $id): ?array
{
    return qone('SELECT * FROM game_rooms WHERE id = ?', [$id]);
}
function game_in_room(array $room, int $uid): bool
{
    return (int)$room['host_id'] === $uid || (int)($room['guest_id'] ?? 0) === $uid;
}
function game_opponent_id(array $room, int $uid): int
{
    return (int)$room['host_id'] === $uid ? (int)($room['guest_id'] ?? 0) : (int)$room['host_id'];
}
function game_touch(array $room, int $uid): void
{
    $col = (int)$room['host_id'] === $uid ? 'host_seen' : 'guest_seen';
    q("UPDATE game_rooms SET $col = ? WHERE id = ?", [game_now(), (int)$room['id']]);
}
function game_latest_round(int $roomId): ?array
{
    return qone('SELECT * FROM game_rounds WHERE room_id = ? ORDER BY round_no DESC LIMIT 1', [$roomId]);
}

function game_make_code(): string
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($try = 0; $try < 20; $try++) {
        $c = '';
        for ($i = 0; $i < 5; $i++) {
            $c .= $abc[random_int(0, strlen($abc) - 1)];
        }
        if (!qval("SELECT 1 FROM game_rooms WHERE code = ? AND status <> 'finished'", [$c])) {
            return $c;
        }
    }
    throw new GameError('Gagal membuat kode ruangan, coba lagi.', 500);
}

function game_create(int $uid, string $visibility = 'public', bool $auto = false): array
{
    game_expire_rooms();
    if ($r = game_active_room($uid)) {
        return $r;
    }
    $vis = $visibility === 'private' ? 'private' : 'public';
    $now = game_now();
    q(
        'INSERT INTO game_rooms (code, host_id, status, total_rounds, visibility, is_auto, host_seen, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
        [game_make_code(), $uid, 'waiting', GAME_ROUNDS, $vis, $auto ? 1 : 0, $now, $now, $now]
    );
    return game_room_by_id((int)db()->lastInsertId());
}

function game_join(int $uid, string $code): array
{
    game_expire_rooms();
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    if ($mine = game_active_room($uid)) {
        if ($mine['status'] === 'waiting' && (int)$mine['host_id'] === $uid) {
            throw new GameError('Kamu sedang punya ruangan sendiri. Batalkan dulu bila ingin bergabung ke ruangan lain.');
        }
        throw new GameError('Kamu masih berada di sebuah ruangan.');
    }
    $room = $code === '' ? null : qone("SELECT * FROM game_rooms WHERE code = ? AND status = 'waiting'", [$code]);
    if (!$room) {
        throw new GameError('Ruangan tidak ditemukan atau sudah penuh.', 404);
    }
    if ((int)$room['host_id'] === $uid) {
        throw new GameError('Itu ruanganmu sendiri.');
    }
    return game_join_room($uid, $room);
}

/** Masukkan $uid sebagai lawan ke ruangan yang sedang menunggu, lalu mulai ronde 1. */
function game_join_room(int $uid, array $room): array
{
    $first = random_int(0, 1) ? (int)$room['host_id'] : $uid;   // siapa yang jadi pengirim di ronde 1
    $now = game_now();
    $st = q(
        "UPDATE game_rooms SET guest_id = ?, first_sender_id = ?, status = 'playing', round_no = 1, guest_seen = ?, updated_at = ? WHERE id = ? AND status = 'waiting' AND guest_id IS NULL",
        [$uid, $first, $now, $now, (int)$room['id']]
    );
    if ($st->rowCount() !== 1) {
        throw new GameError('Ruangan sudah penuh.', 409);
    }
    $room = game_room_by_id((int)$room['id']);
    game_new_round($room, 1);
    try {
        notify((int)$room['host_id'], 'users', 'Lawan bergabung!', 'Permainan Mask of Deception dimulai.', 'game.php');
    } catch (Throwable $e) {
        error_log('[ITIHIX] ' . basename(__FILE__) . ': ' . $e->getMessage());
    }
    return $room;
}

/* ---------- Matchmaking (cari lawan otomatis) ---------- */
/** Cari ruangan publik yang sedang menunggu lalu langsung bergabung. Yang sedang mencari (is_auto) didahulukan. */
function game_try_match(int $uid, int $beforeId = 0): ?array
{
    $cut = game_now(time() - GAME_ONLINE_SEC * 2);
    $sql = "SELECT * FROM game_rooms WHERE status = 'waiting' AND visibility = 'public' AND host_id <> ? AND host_seen >= ?"
         . ($beforeId ? ' AND is_auto = 1 AND id < ' . (int)$beforeId : '')
         . ' ORDER BY is_auto DESC, id ASC LIMIT 5';
    foreach (qall($sql, [$uid, $cut]) as $room) {
        try {
            return game_join_room($uid, $room);
        } catch (GameError $e) { /* ruangan keburu penuh: coba berikutnya */
        }
    }
    return null;
}

/** Tombol "Cari lawan": gabung ke ruangan publik yang menunggu, atau buka ruangan pencarian dan tunggu. */
function game_find(int $uid): array
{
    game_expire_rooms();
    if ($r = game_active_room($uid)) {
        return $r;
    }
    if ($m = game_try_match($uid)) {
        return $m;
    }
    return game_create($uid, 'public', true);
}

/**
 * Dua pemain yang menekan "Cari lawan" hampir bersamaan sama-sama membuat ruangan. Agar tetap bertemu: pemilik ruangan
 * pencarian yang lebih BARU (id lebih besar) membatalkan miliknya dan bergabung ke yang lebih LAMA. Dipanggil tiap polling.
 */
function game_match_tick(array $room): void
{
    if ($room['status'] !== 'waiting' || !(int)($room['is_auto'] ?? 0)) {
        return;
    }
    $uid = (int)$room['host_id'];
    $cut = game_now(time() - GAME_ONLINE_SEC * 2);
    $older = qval("SELECT 1 FROM game_rooms WHERE status = 'waiting' AND visibility = 'public' AND is_auto = 1 AND host_id <> ? AND id < ? AND host_seen >= ? LIMIT 1", [$uid, (int)$room['id'], $cut]);
    if (!$older) {
        return;
    }
    $st = q("UPDATE game_rooms SET status = 'finished', finished_reason = 'cancelled', left_by = ?, updated_at = ? WHERE id = ? AND status = 'waiting' AND guest_id IS NULL", [$uid, game_now(), (int)$room['id']]);
    if ($st->rowCount() !== 1) {
        return;
    }
    if (!game_try_match($uid, (int)$room['id'])) {
        game_create($uid, 'public', true);
    }
}

/**
 * Rencana peran rahasia tiap ronde: separuh ronde "penipu", separuh "bukan penipu", urutannya acak tapi
 * deterministik per ruangan (HMAC dari id+kode ruangan), jadi seimbang dan tidak bisa ditebak pemain.
 */
function game_role_plan(array $room): array
{
    $total = max(2, (int)$room['total_rounds']);
    $keys = [];
    for ($i = 0; $i < $total; $i++) {
        $keys[$i] = hash_hmac('sha256', $room['id'] . '|' . $room['code'] . '|' . $i, (string)(defined('DB_NAME') ? DB_NAME : 'itihix') . '|game');
    }
    asort($keys);
    $plan = array_fill(0, $total, 0);
    $k = 0;
    foreach (array_keys($keys) as $idx) {
        $plan[$idx] = $k < intdiv($total, 2) ? 1 : 0;
        $k++;
    }
    return $plan;
}

function game_new_round(array $room, int $n): void
{
    $first = (int)$room['first_sender_id'];
    $other = $first === (int)$room['host_id'] ? (int)$room['guest_id'] : (int)$room['host_id'];
    $sender = $n % 2 === 1 ? $first : $other;
    $guesser = $sender === $first ? $other : $first;
    $isScam = game_role_plan($room)[$n - 1] ?? random_int(0, 1);
    $opts = null;
    if ($isScam) {
        $keys = array_keys(game_categories());
        shuffle($keys);
        $opts = json_encode(array_slice($keys, 0, 2));
    }
    $now = game_now();
    try {
        q(
            'INSERT INTO game_rounds (room_id, round_no, sender_id, guesser_id, is_scam, cat_options, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [(int)$room['id'], $n, $sender, $guesser, $isScam, $opts, 'writing', $now, $now]
        );
    } catch (PDOException $e) { /* UNIQUE (room_id, round_no): ronde sudah dibuat proses lain */
    }
}

function game_leave(int $uid): void
{
    $room = game_active_room($uid);
    if (!$room) {
        return;
    }
    $now = game_now();
    $reason = $room['status'] === 'waiting' ? 'cancelled' : 'left';
    q("UPDATE game_rooms SET status='finished', finished_reason=?, left_by=?, updated_at=? WHERE id=? AND status <> 'finished'", [$reason, $uid, $now, (int)$room['id']]);
}

/* ---------- Aksi dalam ronde ---------- */
function game_context(int $uid): array
{
    $room = game_active_room($uid);
    if (!$room || $room['status'] !== 'playing') {
        throw new GameError('Tidak ada permainan yang sedang berjalan.', 409);
    }
    game_tick($room);
    $round = game_latest_round((int)$room['id']);
    if (!$round) {
        throw new GameError('Ronde belum siap.', 409);
    }
    return [$room, $round];
}

/** Daftar chat sebuah ronde, urut. */
function game_messages(int $roundId): array
{
    return qall('SELECT id, author_id, body, seq, is_skip, created_at FROM game_messages WHERE round_id = ? ORDER BY seq', [$roundId]);
}
/** Ronde masih bisa dichat/ditebak? (status 'guessing' dipertahankan untuk ronde lama dari versi sebelumnya) */
function game_round_open(array $round): bool
{
    return in_array($round['status'], ['writing', 'guessing'], true);
}
/** Giliran siapa menulis berikutnya, dari jumlah pesan yang sudah ada: P, T, P, T, P (null = jatah chat habis, saatnya menebak). */
function game_open_turn(array $round, int $count): ?int
{
    return game_round_open($round) ? game_next_author($round, $count) : null;
}
function game_next_author(array $round, int $count): ?int
{
    if ($count >= GAME_CHAT_TOTAL) {
        return null;
    }
    return $count % 2 === 0 ? (int)$round['sender_id'] : (int)$round['guesser_id'];
}

/** Tenggat giliran saat ini: [unix_time, jenis]. Jenis: open (pengirim membuka chat), reply (membalas), guess (menebak). */
function game_deadline(array $round, array $msgs): array
{
    $n = count($msgs);
    if ($n === 0) {
        return [strtotime($round['created_at']) + GAME_OPEN_SEC, 'open'];
    }
    $base = strtotime((string)end($msgs)['created_at']);
    if ($n >= GAME_CHAT_TOTAL) {
        return [$base + GAME_GUESS_SEC, 'guess'];
    }
    return [$base + GAME_TURN_SEC, 'reply'];
}

/**
 * Terapkan batas waktu secara "malas": dipanggil tiap polling dan sebelum tiap aksi, jadi berlaku walau pemain yang
 * kehabisan waktu sudah pergi. Membalas habis -> giliran dilewati (pesan penanda, jatah terpakai). Membuka chat atau
 * menebak habis -> ronde selesai tanpa XP. Aman dipanggil bersamaan (UNIQUE seq / UPDATE bersyarat).
 */
function game_tick(array $room): void
{
    $round = game_latest_round((int)$room['id']);
    if (!$round || !game_round_open($round)) {
        return;
    }
    $msgs = game_messages((int)$round['id']);
    [$deadline, $kind] = game_deadline($round, $msgs);
    if (time() < $deadline) {
        return;
    }
    $now = game_now();
    if ($kind === 'open' || $kind === 'guess') {
        q("UPDATE game_rounds SET status = 'done', correct = 0, timeout_kind = ?, done_at = ?, updated_at = ? WHERE id = ? AND status IN ('writing','guessing')", [$kind, $now, $now, (int)$round['id']]);
        return;
    }
    $author = game_next_author($round, count($msgs));
    if ($author === null) {
        return;
    }
    try {
        q('INSERT INTO game_messages (round_id, author_id, seq, body, is_skip, created_at) VALUES (?,?,?,?,1,?)', [(int)$round['id'], $author, count($msgs) + 1, '', $now]);
    } catch (PDOException $e) { /* proses lain sudah menanganinya */
    }
}

/**
 * Kirim satu pesan chat. Urutan tetap: pengirim, penebak, pengirim, penebak, pengirim (maks. 5 pesan: 3 pengirim + 2 penebak).
 * Pesan pembuka pengirim wajib menyertakan kategori bila perannya penipu.
 */
function game_chat(int $uid, string $message, string $category): void
{
    [$room, $round] = game_context($uid);
    if (!game_round_open($round)) {
        throw new GameError(!empty($round['timeout_kind']) ? 'Waktu habis, ronde ini berakhir.' : 'Ronde ini sudah selesai.', 409);
    }
    $msgs = game_messages((int)$round['id']);
    $n = count($msgs);
    $next = game_next_author($round, $n);
    if ($next === null) {
        throw new GameError('Jatah chat ronde ini sudah habis. Saatnya menebak!', 409);
    }
    if ($next !== $uid) {
        throw new GameError('Sekarang giliran lawan menulis.', 403);
    }

    $message = trim(preg_replace("/\r\n?/", "\n", $message));
    $len = mb_strlen($message);
    $first = $n === 0;
    if ($len < 1) {
        throw new GameError('Tulis pesanmu dulu.');
    }
    if ($len > ($first ? GAME_MSG_MAX : GAME_REPLY_MAX)) {
        throw new GameError('Pesan terlalu panjang (maksimal ' . ($first ? GAME_MSG_MAX : GAME_REPLY_MAX) . ' karakter).');
    }

    $cat = null;
    if ($first) {
        if ((int)$round['is_scam']) {
            $opts = json_decode((string)$round['cat_options'], true) ?: [];
            if (!in_array($category, $opts, true)) {
                throw new GameError('Pilih salah satu kategori penipuan dulu.');
            }
            $cat = $category;
        }
        // Tolak pesan pembuka yang sama persis dengan ronde sebelumnya di ruangan ini (anti copy-paste / kolusi).
        foreach (qall('SELECT m.body FROM game_messages m JOIN game_rounds r ON r.id = m.round_id WHERE r.room_id = ? AND m.seq = 1', [(int)$room['id']]) as $m) {
            if (mb_strtolower(trim((string)$m['body'])) === mb_strtolower($message)) {
                throw new GameError('Pesan ini sama dengan ronde sebelumnya. Tulis pesan yang baru.');
            }
        }
    }
    $now = game_now();
    try {
        q('INSERT INTO game_messages (round_id, author_id, seq, body, created_at) VALUES (?,?,?,?,?)', [(int)$round['id'], $uid, $n + 1, $message, $now]);
    } catch (PDOException $e) {
        throw new GameError('Pesan sudah terkirim atau lawan lebih dulu menulis. Muat ulang tampilan.', 409);   // UNIQUE (round_id, seq)
    }
    // Penebak mungkin menebak tepat saat pesan masuk: bila ronde sudah selesai, batalkan pesan ini.
    if (!game_round_open(qone('SELECT status FROM game_rounds WHERE id = ?', [(int)$round['id']]) ?: ['status' => 'done'])) {
        q('DELETE FROM game_messages WHERE round_id = ? AND seq = ?', [(int)$round['id'], $n + 1]);
        throw new GameError('Ronde ini sudah selesai.', 409);
    }
    q('UPDATE game_rounds SET ' . ($first ? 'category = ?, ' : '') . 'updated_at = ? WHERE id = ?', array_merge($first ? [$cat] : [], [$now, (int)$round['id']]));
}

/** Hitung XP yang boleh diterima pemain untuk ronde menang ini (batas harian, batas pasangan). */
function game_xp_for(int $uid, array $room): int
{
    $dayStart = date('Y-m-d 00:00:00');
    $mine = (int)qval("SELECT COUNT(*) FROM activity_logs WHERE user_id = ? AND action = 'game_win' AND created_at >= ?", [$uid, $dayStart]);
    if ($mine >= GAME_DAILY_WINS) {
        return 0;
    }
    $a = (int)$room['host_id'];
    $b = (int)$room['guest_id'];
    $pair = (int)qval('SELECT COUNT(*) FROM game_rounds r JOIN game_rooms m ON m.id = r.room_id
                       WHERE r.correct = 1 AND (r.xp_sender > 0 OR r.xp_guesser > 0) AND r.updated_at >= ?
                         AND ((m.host_id = ? AND m.guest_id = ?) OR (m.host_id = ? AND m.guest_id = ?))', [$dayStart, $a, $b, $b, $a]);
    return $pair >= GAME_PAIR_DAILY ? 0 : GAME_XP;
}

function game_guess(int $uid, string $guess, string $reason): void
{
    [$room, $round] = game_context($uid);
    if ((int)$round['guesser_id'] !== $uid) {
        throw new GameError('Sekarang bukan giliranmu menebak.', 403);
    }
    if (!game_round_open($round)) {
        throw new GameError(!empty($round['timeout_kind']) ? 'Waktu menebak habis, ronde ini berakhir.' : 'Tebakan untuk ronde ini sudah dikirim.', 409);
    }
    if ((int)qval('SELECT COUNT(*) FROM game_messages WHERE round_id = ?', [(int)$round['id']]) < 1) {
        throw new GameError('Belum ada pesan untuk ditebak. Tunggu pengirim membuka chat.', 409);
    }
    if (!in_array($guess, ['scam', 'safe'], true)) {
        throw new GameError('Pilih tebakanmu dulu.');
    }
    $correct = ($guess === 'scam') === ((int)$round['is_scam'] === 1) ? 1 : 0;
    $xpG = 0;
    $xpS = 0;
    if ($correct) {
        $xpG = game_xp_for($uid, $room);
        $xpS = game_xp_for((int)$round['sender_id'], $room);
    }
    $doneAt = game_now();
    $st = q(
        "UPDATE game_rounds SET guess = ?, reason = ?, correct = ?, xp_sender = ?, xp_guesser = ?, status = 'done', done_at = ?, updated_at = ? WHERE id = ? AND status IN ('writing','guessing') AND guesser_id = ?",
        [$guess, mb_substr(trim($reason), 0, 300), $correct, $xpS, $xpG, $doneAt, $doneAt, (int)$round['id'], $uid]
    );
    if ($st->rowCount() !== 1) {
        throw new GameError('Tebakan untuk ronde ini sudah dikirim.', 409);
    }
    if ($correct) {
        foreach ([[$uid, $xpG], [(int)$round['sender_id'], $xpS]] as [$u, $xp]) {
            if ($xp > 0) {
                add_xp($u, $xp, 'game');
                log_activity('game_win', 'game_round', (int)$round['id'], 'Mask of Deception: tebakan benar', $u);
            }
        }
    }
}

/**
 * Tenggat lanjut otomatis (unix time), atau null bila BELUM ada pemain yang menekan siap.
 * Hitung mundur baru berjalan sejak pemain pertama menekan siap (ready_at); sebelum itu tidak ada batas waktu.
 */
function game_next_deadline(array $round): ?int
{
    if (!empty($round['ready_at'])) {
        return strtotime((string)$round['ready_at']) + GAME_NEXT_SEC;
    }
    if ((int)($round['ready_host'] ?? 0) || (int)($round['ready_guest'] ?? 0)) {   // data lama: sudah ada yang siap tapi ready_at kosong
        return strtotime((string)($round['done_at'] ?: $round['updated_at'])) + GAME_NEXT_SEC;
    }
    return null;
}

/** Pemain menyatakan siap lanjut. Ronde baru dimulai bila kedua pemain siap (atau otomatis lewat game_maybe_advance). */
function game_next(int $uid): void
{
    [$room, $round] = game_context($uid);
    if ($round['status'] !== 'done') {
        throw new GameError('Ronde ini belum selesai.', 409);
    }
    $col = (int)$room['host_id'] === $uid ? 'ready_host' : 'ready_guest';
    q("UPDATE game_rounds SET $col = 1, ready_at = COALESCE(ready_at, ?) WHERE id = ?", [game_now(), (int)$round['id']]);   // klik pertama memulai hitung mundur
    game_maybe_advance($room, qone('SELECT * FROM game_rounds WHERE id = ?', [(int)$round['id']]) ?: $round);
}

/**
 * Lanjut ke ronde berikutnya (atau akhiri match bila itu ronde terakhir) jika kedua pemain sudah siap
 * ATAU GAME_NEXT_SEC detik sudah lewat sejak pemain pertama menekan siap. Dipanggil tiap polling dan tiap klik "siap"; aman dipanggil bersamaan.
 */
function game_maybe_advance(array $room, array $round): void
{
    if ($round['status'] !== 'done' || $room['status'] !== 'playing') {
        return;
    }
    $both = (int)$round['ready_host'] === 1 && (int)$round['ready_guest'] === 1;
    $dl = game_next_deadline($round);
    if (!$both && ($dl === null || time() < $dl)) {
        return;
    }   // belum ada yang siap -> tunggu tanpa batas waktu
    $now = game_now();
    if ((int)$round['round_no'] >= (int)$room['total_rounds']) {
        q("UPDATE game_rooms SET status='finished', finished_reason='completed', updated_at=? WHERE id=? AND status='playing'", [$now, (int)$room['id']]);
        return;
    }
    $n = (int)$round['round_no'] + 1;
    $st = q("UPDATE game_rooms SET round_no = ?, updated_at = ? WHERE id = ? AND status = 'playing' AND round_no = ?", [$n, $now, (int)$room['id'], (int)$round['round_no']]);
    if ($st->rowCount() === 1) {
        game_new_round(game_room_by_id((int)$room['id']), $n);
    }
}

/* ---------- State untuk klien ---------- */
function game_player(int $uid): ?array
{
    $u = qone('SELECT * FROM users WHERE id = ?', [$uid]);
    if (!$u) {
        return null;
    }
    return ['id' => $uid, 'name' => $u['display_name'], 'username' => $u['username'], 'level' => user_level($u), 'avatar' => avatar_html($u)];
}

function game_lobby(int $uid): array
{
    game_expire_rooms();
    $cut = game_now(time() - GAME_ONLINE_SEC * 2);
    $open = [];
    foreach (qall("SELECT m.code, m.host_id, m.is_auto FROM game_rooms m WHERE m.status = 'waiting' AND m.visibility = 'public' AND m.host_id <> ? AND m.host_seen >= ? ORDER BY m.id DESC LIMIT 12", [$uid, $cut]) as $r) {
        $open[] = ['code' => $r['code'], 'auto' => (bool)$r['is_auto'], 'host' => game_player((int)$r['host_id'])];
    }
    return ['open' => $open];
}

/** State untuk klien + 'rev' (sidik jari) agar polling bisa dijawab 'unchanged' tanpa kirim ulang. */
function game_state(int $uid): array
{
    $st = game_state_raw($uid);
    unset($st['rev']);
    $st['rev'] = substr(md5(json_encode($st, JSON_UNESCAPED_UNICODE)), 0, 12);
    return $st;
}
function game_state_raw(int $uid): array
{
    game_expire_rooms();
    $room = game_active_room($uid);
    if (!$room) {
        // Ruangan terakhir yang baru selesai (untuk layar hasil / info lawan keluar) — hanya yang < 15 menit.
        $room = qone(
            "SELECT * FROM game_rooms WHERE status = 'finished' AND (host_id = ? OR guest_id = ?) AND guest_id IS NOT NULL AND updated_at >= ? ORDER BY id DESC LIMIT 1",
            [$uid, $uid, game_now(time() - 900)]
        );
        if (!$room || qval('SELECT 1 FROM game_rounds WHERE room_id = ? LIMIT 1', [(int)$room['id']]) === null) {
            return ['view' => 'lobby'] + game_lobby($uid);
        }
        $seenKey = 'game_ack_' . $room['id'];
        if (!empty($_SESSION[$seenKey])) {
            return ['view' => 'lobby'] + game_lobby($uid);
        }
    } else {
        game_touch($room, $uid);
        if ($room['status'] === 'waiting') {
            game_match_tick($room);
            $room = game_active_room($uid) ?: $room;
        }
        if ($room['status'] === 'playing') {
            game_tick($room);
            if ($lr = game_latest_round((int)$room['id'])) {
                game_maybe_advance($room, $lr);
            }
            $room = game_room_by_id((int)$room['id']) ?: $room;   // bisa sudah pindah ronde / selesai
        }
    }
    $isHost = (int)$room['host_id'] === $uid;
    $oppId = game_opponent_id($room, $uid);
    $oppSeen = $isHost ? ($room['guest_seen'] ?? null) : $room['host_seen'];
    $out = [
        'view' => $room['status'] === 'finished' ? 'finished' : ($room['status'] === 'waiting' ? 'waiting' : 'play'),
        'room' => ['id' => (int)$room['id'], 'code' => $room['code'], 'status' => $room['status'], 'round_no' => (int)$room['round_no'], 'total' => (int)$room['total_rounds'],
                   'visibility' => $room['visibility'] ?? 'public', 'auto' => (bool)($room['is_auto'] ?? 0), 'created_ts' => strtotime($room['created_at']),
                   'reason' => $room['finished_reason'], 'left_by_me' => (int)($room['left_by'] ?? 0) === $uid, 'left_by_opp' => $oppId && (int)($room['left_by'] ?? 0) === $oppId],
        'me' => game_player($uid),
        'opp' => $oppId ? game_player($oppId) : null,
        'opp_online' => $oppId && $oppSeen && strtotime($oppSeen) >= time() - GAME_ONLINE_SEC,
    ];
    if ($room['status'] === 'waiting') {
        return $out;
    }

    $cats = game_categories();
    $rounds = qall('SELECT * FROM game_rounds WHERE room_id = ? ORDER BY round_no', [(int)$room['id']]);
    $wins = 0;
    $xpTotal = 0;
    $hist = [];
    foreach ($rounds as $r) {
        if ($r['status'] !== 'done') {
            continue;
        }
        $mineSender = (int)$r['sender_id'] === $uid;
        $wins += (int)$r['correct'];
        $xpTotal += $mineSender ? (int)$r['xp_sender'] : (int)$r['xp_guesser'];
        $hist[] = ['n' => (int)$r['round_no'], 'correct' => (bool)$r['correct'], 'is_scam' => (bool)$r['is_scam'], 'role' => $mineSender ? 'sender' : 'guesser',
                   'category' => $r['category'] ? ($cats[$r['category']]['name'] ?? '') : null, 'xp' => $mineSender ? (int)$r['xp_sender'] : (int)$r['xp_guesser'],
                   'timeout' => $r['timeout_kind'] ?: null];
    }
    $out['score'] = ['wins' => $wins, 'done' => count($hist), 'xp' => $xpTotal, 'history' => $hist];
    $round = $rounds ? end($rounds) : null;
    if ($round && $room['status'] === 'playing') {
        $iSend = (int)$round['sender_id'] === $uid;
        $rd = ['n' => (int)$round['round_no'], 'status' => $round['status'], 'role' => $iSend ? 'sender' : 'guesser', 'last' => (int)$round['round_no'] >= (int)$room['total_rounds'], 'started_ts' => strtotime((string)$round['created_at'])];
        if ($iSend) {
            $rd['is_scam'] = (bool)$round['is_scam'];                       // rahasia: hanya pengirim yang tahu
            if ($round['is_scam']) {
                foreach ((json_decode((string)$round['cat_options'], true) ?: []) as $k) {
                    if (isset($cats[$k])) {
                        $rd['options'][] = ['key' => $k] + $cats[$k];
                    }
                }
            }
        }
        $msgs = game_messages((int)$round['id']);
        $cnt = count($msgs);
        $rd['messages'] = array_map(fn ($m) => ['seq' => (int)$m['seq'], 'mine' => (int)$m['author_id'] === $uid, 'body' => $m['body'], 'skip' => (bool)$m['is_skip'], 'at' => date('H:i', strtotime($m['created_at']))], $msgs);
        if ($round['status'] !== 'done') {
            [$rd['deadline'], $rd['timer_kind']] = game_deadline($round, $msgs);
            $rd['secs'] = ['open' => GAME_OPEN_SEC, 'reply' => GAME_TURN_SEC, 'guess' => GAME_GUESS_SEC];
        }
        $next = game_open_turn($round, $cnt);
        $rd['turn'] = $next === null ? 'guess' : ($next === $uid ? 'me' : 'opp');       // siapa yang harus menulis berikutnya
        $sCnt = intdiv($cnt + 1, 2);
        $gCnt = intdiv($cnt, 2);
        $rd['left'] = ['me' => $iSend ? GAME_CHAT_SENDER - $sCnt : GAME_CHAT_GUESSER - $gCnt, 'opp' => $iSend ? GAME_CHAT_GUESSER - $gCnt : GAME_CHAT_SENDER - $sCnt];
        $rd['can_guess'] = !$iSend && $round['status'] !== 'done' && $cnt >= 1;
        $rd['chat_total'] = GAME_CHAT_TOTAL;
        if ($iSend && $round['category']) {
            $rd['my_category'] = $cats[$round['category']]['name'] ?? '';
        }
        if ($round['status'] === 'done') {
            $rd += ['reveal_scam' => (bool)$round['is_scam'], 'category' => $round['category'] ? ($cats[$round['category']]['name'] ?? '') : null,
                    'next' => ['me_ready' => (bool)$round[$isHost ? 'ready_host' : 'ready_guest'], 'opp_ready' => (bool)$round[$isHost ? 'ready_guest' : 'ready_host'], 'deadline' => game_next_deadline($round), 'secs' => GAME_NEXT_SEC],
                    'guess' => $round['guess'], 'reason' => $round['reason'], 'correct' => (bool)$round['correct'], 'timeout' => $round['timeout_kind'] ?: null, 'xp' => $iSend ? (int)$round['xp_sender'] : (int)$round['xp_guesser']];
        }
        $out['round'] = $rd;
    }
    return $out;
}

/** Tandai layar hasil sudah dilihat (kembali ke lobi). */
function game_ack(int $uid): void
{
    $room = qone("SELECT id FROM game_rooms WHERE status = 'finished' AND (host_id = ? OR guest_id = ?) ORDER BY id DESC LIMIT 1", [$uid, $uid]);
    if ($room) {
        $_SESSION['game_ack_' . $room['id']] = 1;
    }
}

/* ---------- Riwayat match ---------- */
/** Daftar match yang sudah selesai (minimal satu ronde tuntas), terbaru dulu. */
function game_history(int $uid): array
{
    $rows = qall("SELECT m.id, m.code, m.host_id, m.guest_id, m.total_rounds, m.finished_reason, m.left_by, m.visibility, m.is_auto, m.created_at, m.updated_at,
        (SELECT COUNT(*) FROM game_rounds r WHERE r.room_id = m.id AND r.status = 'done') AS done_rounds,
        (SELECT COALESCE(SUM(r.correct), 0) FROM game_rounds r WHERE r.room_id = m.id AND r.status = 'done') AS wins,
        (SELECT COALESCE(SUM(CASE WHEN r.sender_id = ? THEN r.xp_sender ELSE r.xp_guesser END), 0) FROM game_rounds r WHERE r.room_id = m.id AND r.status = 'done') AS xp
      FROM game_rooms m
      WHERE m.status = 'finished' AND m.guest_id IS NOT NULL AND (m.host_id = ? OR m.guest_id = ?)
        AND EXISTS (SELECT 1 FROM game_rounds r WHERE r.room_id = m.id AND r.status = 'done')
      ORDER BY m.id DESC LIMIT " . (int)GAME_HISTORY_LIMIT, [$uid, $uid, $uid]);
    $out = [];
    foreach ($rows as $m) {
        $opp = (int)$m['host_id'] === $uid ? (int)($m['guest_id'] ?? 0) : (int)$m['host_id'];
        $out[] = [
            'id' => (int)$m['id'], 'code' => $m['code'], 'opp' => $opp ? game_player($opp) : null,
            'wins' => (int)$m['wins'], 'done' => (int)$m['done_rounds'], 'total' => (int)$m['total_rounds'], 'xp' => (int)$m['xp'],
            'reason' => $m['finished_reason'], 'left_by_me' => (int)($m['left_by'] ?? 0) === $uid, 'left_by_opp' => $opp && (int)($m['left_by'] ?? 0) === $opp,
            'visibility' => $m['visibility'] ?? 'public', 'auto' => (bool)($m['is_auto'] ?? 0),
            'ago' => time_ago($m['updated_at']), 'date' => date('d/m/Y H:i', strtotime($m['created_at'])),
        ];
    }
    return $out;
}

/** Rincian satu match (semua ronde + transkrip chat) untuk ditinjau ulang. Hanya untuk dua pemainnya dan setelah match selesai. */
function game_match_detail(int $uid, int $roomId): array
{
    $room = $roomId ? game_room_by_id($roomId) : null;
    if (!$room || !game_in_room($room, $uid) || $room['status'] !== 'finished') {
        throw new GameError('Match tidak ditemukan.', 404);
    }
    $oppId = game_opponent_id($room, $uid);
    $cats = game_categories();
    $rounds = [];
    $wins = 0;
    $done = 0;
    $xp = 0;
    foreach (qall('SELECT * FROM game_rounds WHERE room_id = ? ORDER BY round_no', [$roomId]) as $r) {
        $msgs = game_messages((int)$r['id']);
        if ($r['status'] !== 'done' && !$msgs) {
            continue;
        }
        $mineSender = (int)$r['sender_id'] === $uid;
        if ($r['status'] === 'done') {
            $done++;
            $wins += (int)$r['correct'];
            $xp += $mineSender ? (int)$r['xp_sender'] : (int)$r['xp_guesser'];
        }
        $rounds[] = [
            'n' => (int)$r['round_no'], 'role' => $mineSender ? 'sender' : 'guesser', 'finished' => $r['status'] === 'done',
            'is_scam' => (bool)$r['is_scam'], 'category' => $r['category'] ? ($cats[$r['category']]['name'] ?? '') : null,
            'guess' => $r['guess'], 'reason' => $r['reason'], 'correct' => (bool)$r['correct'], 'timeout' => $r['timeout_kind'] ?: null,
            'xp' => $mineSender ? (int)$r['xp_sender'] : (int)$r['xp_guesser'],
            'messages' => array_map(fn ($m) => ['mine' => (int)$m['author_id'] === $uid, 'body' => $m['body'], 'skip' => (bool)$m['is_skip'], 'at' => date('H:i', strtotime($m['created_at']))], $msgs),
        ];
    }
    return ['id' => $roomId, 'code' => $room['code'], 'opp' => $oppId ? game_player($oppId) : null, 'total' => (int)$room['total_rounds'],
            'wins' => $wins, 'done' => $done, 'xp' => $xp, 'reason' => $room['finished_reason'],
            'left_by_me' => (int)($room['left_by'] ?? 0) === $uid, 'left_by_opp' => $oppId && (int)($room['left_by'] ?? 0) === $oppId,
            'date' => date('d/m/Y H:i', strtotime($room['created_at'])), 'rounds' => $rounds];
}
