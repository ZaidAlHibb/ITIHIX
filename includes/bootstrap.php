<?php

declare(strict_types=1);
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'secure' => $isHttps, 'samesite' => 'Lax']);
    session_start();
}
date_default_timezone_set('Asia/Makassar'); // WITA (Kalimantan Selatan)
// Satu buffer TANPA batas ukuran. php.ini (Laragon/XAMPP) biasanya output_buffering=4096: buffer bawaan itu mengirim header setelah ±4 KB,
// sehingga redirect()/header() yang dipanggil setelah layout dicetak gagal ("headers already sent"). Buang buffer bawaan, pakai milik sendiri.
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

/* ---------- Database ---------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
        $pdo->exec("SET time_zone = '+08:00'");
    }
    return $pdo;
}
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function qall(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}
function qone(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r ?: null;
}
function qval(string $sql, array $params = [])
{
    $r = q($sql, $params)->fetchColumn();
    return $r === false ? null : $r;
}

/* ---------- Helper umum ---------- */
function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}
/**
 * Nama pengguna yang bentrok dengan halaman/folder situs (login, admin, api, assets, ...).
 * Daftar dibaca langsung dari isi folder utama, jadi otomatis ikut bertambah bila ada halaman baru.
 */
function username_reserved(string $u): bool
{
    static $set = null;
    if ($set === null) {
        $set = array_fill_keys(['api', 'assets', 'uploads', 'includes', 'docs', 'migrations', 'profile', 'login', 'logout', 'register', 'admin', 'moderator', 'settings', 'index', 'static', 'public', 'followers', 'following'], true);
        foreach (@scandir(dirname(__DIR__)) ?: [] as $f) {
            if ($f !== '' && $f[0] !== '.') {
                $set[strtolower(pathinfo($f, PATHINFO_FILENAME))] = true;
            }
        }
    }
    return isset($set[strtolower($u)]);
}
/**
 * Alamat profil berbentuk /username (tanpa BASE_URL; bungkus dengan url()).
 * Untuk nama yang bentrok dengan halaman situs, otomatis memakai profile.php?u=username.
 */
function profile_url(string $username, array $query = []): string
{
    if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username) || username_reserved($username)) {
        return 'profile.php?' . http_build_query(['u' => $username] + $query);
    }
    return rawurlencode($username) . ($query ? '?' . http_build_query($query) : '');
}
function wants_form_json(): bool
{
    return ($_SERVER['HTTP_X_ITIHIX_FORM'] ?? '') === '1';
} // form dikirim lewat fetch (tanpa reload halaman)
function redirect(string $path): never
{
    $to = preg_match('#^https?://#', $path) ? $path : url($path);
    if (wants_form_json()) {
        json_out(['ok' => true, 'redirect' => $to]);
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Location: ' . $to);
    exit;
}
function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}
function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}
function csrf_check(): void
{
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
            json_out(['ok' => false, 'error' => 'Sesi tidak valid, muat ulang halaman.'], 419);
        }
        http_response_code(419);
        exit('Sesi tidak valid. Muat ulang halaman lalu coba lagi.');
    }
}
function flash(?string $msg = null, string $type = 'ok')
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['m' => $msg, 't' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
function json_out(array $data, int $code = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function time_ago(string $dt): string
{
    $diff = time() - strtotime($dt);
    if ($diff < 60) {
        return 'baru saja';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' menit lalu';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' jam lalu';
    }
    if ($diff < 86400 * 7) {
        return floor($diff / 86400) . ' hari lalu';
    }
    return date('d M Y', strtotime($dt));
}
function num_short($n): string
{
    $n = (int)$n;
    if ($n >= 1000000) {
        return rtrim(rtrim(number_format($n / 1000000, 1, ',', ''), '0'), ',') . ' jt';
    }
    if ($n >= 1000) {
        return rtrim(rtrim(number_format($n / 1000, 1, ',', ''), '0'), ',') . ' rb';
    }
    return (string)$n;
}

/* ---------- Auth ---------- */
function current_user(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $u = qone('SELECT * FROM users WHERE id = ?', [$_SESSION['uid']]);
            if ($u && $u['status'] !== 'active') {
                $u = null;
                unset($_SESSION['uid']);
            }
        }
    }
    return $u;
}
function require_login(): array
{
    $u = current_user();
    if (!$u) {
        if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
            json_out(['ok' => false, 'error' => 'Silakan masuk terlebih dahulu.'], 401);
        }
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect('login.php');
    }
    return $u;
}
function require_role(string ...$roles): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
            json_out(['ok' => false, 'error' => 'Akses ditolak.'], 403);
        }
        http_response_code(403);
        exit('<h1>403</h1><p>Kamu tidak punya akses ke halaman ini. <a href="' . e(url('index.php')) . '">Kembali</a></p>');
    }
    return $u;
}
function is_staff(?array $u): bool
{
    return $u && in_array($u['role'], ['moderator', 'admin'], true);
}
function login_user(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    q('UPDATE users SET last_login = NOW() WHERE id = ?', [$u['id']]);
    log_activity('login', 'user', (int)$u['id'], 'Masuk ke akun', (int)$u['id']);
}
function role_label(string $r): string
{
    return ['user' => 'Pengguna', 'trusted' => 'Terpercaya', 'moderator' => 'Moderator', 'admin' => 'Admin'][$r] ?? $r;
}
/** Alasan cepat untuk catatan moderator (tolak / klarifikasi): klik untuk menambahkan ke kolom catatan. */
function review_templates(): array
{
    return [
        'Bukti kurang'        => 'Bukti belum cukup. Mohon lampirkan tangkapan layar, tautan, atau dokumen pendukung.',
        'Data pribadi'        => 'Laporan memuat data pribadi. Mohon sensor nomor rekening, telepon, alamat, atau identitas lain.',
        'Kronologi kurang'    => 'Kronologi kurang jelas. Mohon jelaskan urutan kejadian dan kerugian yang dialami.',
        'Tanpa konteks'       => 'Klaim belum memiliki konteks atau sumber yang dapat diverifikasi.',
        'Label terlalu pasti' => 'Label terlalu pasti. Gunakan label Mencurigakan jika belum terbukti.',
        'Duplikat'            => 'Duplikat dari laporan yang sudah terbit.',
        'Di luar topik'       => 'Di luar topik ITIHIX (bukan penipuan atau edukasi keamanan).',
    ];
}
function review_template_chips(): string
{
    $h = '<div class="rv-tpl"><span>Alasan cepat:</span>';
    foreach (review_templates() as $label => $text) {
        $h .= '<button type="button" class="rv-chip" data-t="' . e($text) . '" onclick="var t=this.form.note;t.value=(t.value.trim()?t.value.trim()+\' \':\'\')+this.dataset.t;t.focus()">' . e($label) . '</button>';
    }
    return $h . '</div>';
}
/** Boleh menerbitkan tanpa antrean moderasi: staf (moderator/admin) dan akun Terpercaya. */
function can_publish_directly(?array $u): bool
{
    return $u && in_array($u['role'], ['trusted', 'moderator', 'admin'], true);
}
/** Penanda peran berbentuk label (kosong untuk pengguna biasa). */
function role_badge(string $role): string
{
    $m = ['admin' => ['rb-admin', 'Admin'], 'moderator' => ['rb-mod', 'Moderator'], 'trusted' => ['rb-trusted', 'Terpercaya']];
    if (!isset($m[$role])) {
        return '';
    }
    return ' <span class="role-badge ' . $m[$role][0] . '" title="' . e(role_label($role)) . '">' . $m[$role][1] . '</span>';
}

/* ---------- Laporan pengguna (report user) ---------- */
function report_reasons(): array
{
    return [
        'penipuan'     => 'Penipuan / modus kejahatan',
        'penyamaran'   => 'Menyamar sebagai orang lain',
        'spam'         => 'Spam atau promosi berlebihan',
        'pelecehan'    => 'Pelecehan atau ujaran kebencian',
        'tidak_pantas' => 'Konten tidak pantas',
        'lainnya'      => 'Lainnya',
    ];
}

/* ---------- Log aktivitas & notifikasi ---------- */
function log_activity(string $action, ?string $type = null, ?int $tid = null, string $detail = '', ?int $uid = null): void
{
    try {
        $uid = $uid ?? (current_user()['id'] ?? null);
        q(
            'INSERT INTO activity_logs (user_id, action, target_type, target_id, detail, ip) VALUES (?,?,?,?,?,?)',
            [$uid, $action, $type, $tid, mb_substr($detail, 0, 500), client_ip()]
        );
    } catch (Throwable $e) {
        error_log('[ITIHIX] ' . basename(__FILE__) . ': ' . $e->getMessage());
    }
}
function notify(int $uid, string $icon, string $title, string $body = '', ?string $link = null): void
{
    q('INSERT INTO notifications (user_id, icon, title, body, link) VALUES (?,?,?,?,?)', [$uid, $icon, mb_substr($title, 0, 160), mb_substr($body, 0, 255), $link]);
}
function unread_count(int $uid): int
{
    return (int)qval('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$uid]);
}

/* ---------- Level & XP (maks. level 110) ---------- */
function xp_needed(int $level): int
{
    return 100 + 10 * ($level - 1);
}   // XP untuk naik dari level L ke L+1
function level_info(int $xp): array
{
    $lvl = 1;
    $left = $xp;
    while ($lvl < MAX_LEVEL && $left >= xp_needed($lvl)) {
        $left -= xp_needed($lvl);
        $lvl++;
    }
    $need = $lvl >= MAX_LEVEL ? 0 : xp_needed($lvl);
    return ['level' => $lvl, 'cur' => $left, 'need' => $need, 'pct' => $need ? (int)round($left / $need * 100) : 100];
}
/** Daftar gelar: level awal, level akhir, nama, ikon, deskripsi. Sumber tunggal untuk level_title() & halaman profil. */
function level_tiers(): array
{
    return [
        ['min' => 1,   'max' => 9,   'title' => 'Pemula Waspada',      'icon' => 'seedling', 'desc' => 'Baru mulai mengenali tanda-tanda penipuan digital.'],
        ['min' => 10,  'max' => 29,  'title' => 'Penjaga Komunitas',   'icon' => 'shield', 'desc' => 'Mulai aktif menjaga lingkungan sekitar dari penipuan.'],
        ['min' => 30,  'max' => 59,  'title' => 'Pelindung Siber',     'icon' => 'key', 'desc' => 'Rutin melapor, memeriksa, dan membantu orang lain.'],
        ['min' => 60,  'max' => 89,  'title' => 'Pakar Anti-Penipuan', 'icon' => 'spy', 'desc' => 'Jeli membaca modus dan jadi rujukan komunitas.'],
        ['min' => 90,  'max' => 109, 'title' => 'Tameng Digital',      'icon' => 'castle', 'desc' => 'Garda terdepan yang melindungi ribuan orang.'],
        ['min' => 110, 'max' => 110, 'title' => 'Legenda ITIHIX',      'icon' => 'crown', 'desc' => 'Level tertinggi. Teladan kewaspadaan digital.'],
    ];
}
/** Total XP yang dibutuhkan (dari 0) untuk mencapai level $lvl. */
function xp_to_reach(int $lvl): int
{
    $n = max(0, $lvl - 1);
    return 100 * $n + 5 * $n * max(0, $n - 1);
}
function level_title(int $lvl): string
{
    $t = 'Pemula Waspada';
    foreach (level_tiers() as $tier) {
        if ($lvl >= $tier['min']) {
            $t = $tier['title'];
        }
    }
    return $t;
}
/** Pastikan caption hasil ItihScan AI memuat tagar #ItihScanAi (ditambahkan di akhir bila belum ada). */
function ensure_scan_tag(string $caption): string
{
    if (preg_match('/#ItihScanAi\b/i', $caption)) {
        return $caption;
    }
    $c = rtrim($caption);
    return $c === '' ? '#ItihScanAi' : $c . ' #ItihScanAi';
}
/** Level tampilan: dihitung dari XP (bila kolom xp ikut diambil) supaya selalu sinkron, bukan dari kolom level yang bisa tertinggal. */
function user_level(array $row): int
{
    return isset($row['xp']) ? level_info((int)$row['xp'])['level'] : (int)($row['level'] ?? 1);
}
const XP_DAILY_FEATURE_MAX = 250; // batas total XP per hari dari SEMUA fitur (laporan, kuis, ItihScan, komentar, game, dll.)
const XP_DAILY_MISSION     = 250; // hadiah XP bila ketiga Misi Harian selesai (sekali per hari, di luar batas fitur)

/** Tabel pencatat XP harian (dibuat oleh migrations/). kind: 'feature' = XP dari fitur, 'mission' = hadiah Misi Harian. */
function xp_daily_ready(): bool
{
    return schema_ready();
}   // tabel xp_daily dibuat oleh migrations/
function xp_feature_today(int $uid): int
{
    if (!xp_daily_ready()) {
        return 0;
    }
    return (int)qval("SELECT amount FROM xp_daily WHERE user_id = ? AND day = CURDATE() AND kind = 'feature'", [$uid]);
}

/** Beri hadiah Misi Harian (sekali per hari). Mengembalikan true bila hadiah baru saja diberikan. */
function claim_daily_mission_xp(int $uid): bool
{
    if (!xp_daily_ready()) {
        return false;
    }
    $st = q("INSERT IGNORE INTO xp_daily (user_id, day, kind, amount) VALUES (?, CURDATE(), 'mission', ?)", [$uid, XP_DAILY_MISSION]);
    if ($st->rowCount() !== 1) {
        return false;
    } // sudah diklaim hari ini
    add_xp($uid, XP_DAILY_MISSION, 'misi harian');
    notify($uid, 'star', 'Misi Harian selesai!', 'Kamu mendapat +' . XP_DAILY_MISSION . ' XP dari Misi Harian.', 'profile.php?tab=level');
    return true;
}

function add_xp(int $uid, int $amount, string $reason = ''): void
{
    if ($amount <= 0) {
        return;
    }
    if ($reason !== 'misi harian' && xp_daily_ready()) {   // semua fitur digabung: maks. XP_DAILY_FEATURE_MAX per hari
        $amount = min($amount, max(0, XP_DAILY_FEATURE_MAX - xp_feature_today($uid)));
        if ($amount <= 0) {
            return;
        }
        q("INSERT INTO xp_daily (user_id, day, kind, amount) VALUES (?, CURDATE(), 'feature', ?) ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount)", [$uid, $amount]);
    }
    $old = qone('SELECT xp, level FROM users WHERE id = ?', [$uid]);
    if (!$old) {
        return;
    }
    q('UPDATE users SET xp = xp + ? WHERE id = ?', [$amount, $uid]);      // atomik: tidak menimpa XP dari proses lain
    $newXp = (int)qval('SELECT xp FROM users WHERE id = ?', [$uid]);
    $info = level_info($newXp);
    q('UPDATE users SET level = ? WHERE id = ?', [$info['level'], $uid]);
    $prev = level_info((int)$old['xp'])['level'];
    if ($info['level'] > max($prev, (int)$old['level'])) {
        notify($uid, 'star', 'Naik ke Level ' . $info['level'] . '!', 'Kamu kini bergelar ' . level_title($info['level']) . '. Terus bantu komunitas tetap waspada.', 'profile.php');
    }
}

/* ---------- Tampilan kecil ---------- */
function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $s = mb_substr($parts[0] ?? '?', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '');
    return mb_strtoupper($s);
}
function avatar_html(array $u, string $extra = '', string $class = 'avatar'): string
{
    $color = $u['avatar_color'] ?? '#ffe0e2';
    if (!empty($u['avatar_path'])) {
        $ini = e(initials($u['display_name'] ?? '?')); // bila file foto hilang/rusak: tampilkan inisial, bukan ikon gambar rusak
        return '<div class="' . $class . '" style="padding:0;overflow:hidden;background:' . e($color) . ';color:#5b2a2f;' . $extra . '"><img src="' . e(url('uploads/' . $u['avatar_path'])) . '" alt="" data-i="' . $ini . '" onerror="this.replaceWith(document.createTextNode(this.dataset.i))" style="width:100%;height:100%;object-fit:cover"></div>';
    }
    return '<div class="' . $class . '" style="background:' . e($color) . ';color:#5b2a2f;' . $extra . '">' . e(initials($u['display_name'] ?? '?')) . '</div>';
}
function label_tag(string $label): string
{
    $map = ['penipuan' => ['tag-red', ':warn: Penipuan'], 'mencurigakan' => ['tag-amber', ':question: Mencurigakan'], 'edukasi' => ['tag-green', ':check: Edukasi'], 'klarifikasi' => ['tag-amber', ':question: Klarifikasi']];
    [$c, $t] = $map[$label] ?? ['tag-red', $label];
    return '<span class="tag ' . $c . '">' . eic($t) . '</span>';
}

/* ---------- Hashtag ---------- */
function extract_hashtags(string $text): array
{
    preg_match_all('/#([\p{L}\p{N}_]{2,40})/u', $text, $m);
    $out = [];
    foreach ($m[1] as $t) {
        $out[mb_strtolower($t)] = $t;
    }
    return array_slice(array_values($out), 0, 8);
}
function save_hashtags(int $postId, string $text): void
{
    foreach (extract_hashtags($text) as $tag) {
        q('INSERT IGNORE INTO hashtags (tag) VALUES (?)', [$tag]);
        $hid = qval('SELECT id FROM hashtags WHERE tag = ?', [$tag]);
        if ($hid) {
            q('INSERT IGNORE INTO post_hashtags (post_id, hashtag_id) VALUES (?,?)', [$postId, $hid]);
        }
    }
}
function top_hashtags(int $limit = 5, int $days = 1): array
{
    $rows = qall('SELECT h.tag, COUNT(*) c FROM post_hashtags ph JOIN hashtags h ON h.id = ph.hashtag_id
                  JOIN posts p ON p.id = ph.post_id AND p.status = "approved" AND p.deleted_at IS NULL
                  JOIN users pu ON pu.id = p.user_id AND pu.status = "active"
                  WHERE p.created_at >= DATE_SUB(NOW(), INTERVAL ' . (int)$days . ' DAY)
                  GROUP BY h.id ORDER BY c DESC, h.tag LIMIT ' . (int)$limit);
    if (count($rows) < $limit) { // jika hari ini sepi, ambil sepanjang waktu
        $rows = qall('SELECT h.tag, COUNT(*) c FROM post_hashtags ph JOIN hashtags h ON h.id = ph.hashtag_id
                      JOIN posts p ON p.id = ph.post_id AND p.status = "approved" AND p.deleted_at IS NULL
                  JOIN users pu ON pu.id = p.user_id AND pu.status = "active"
                      GROUP BY h.id ORDER BY c DESC, h.tag LIMIT ' . (int)$limit);
    }
    return $rows;
}

/* ---------- Upload file (aman) ---------- */
function save_upload(array $file, string $kind = 'image'): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Gagal mengunggah file (kode ' . $file['error'] . ').'];
    }
    $max = ($kind === 'video' ? MAX_VIDEO_MB : MAX_IMAGE_MB) * 1024 * 1024;
    if ($file['size'] > $max) {
        return ['ok' => false, 'error' => 'Ukuran file melebihi batas ' . ($kind === 'video' ? MAX_VIDEO_MB : MAX_IMAGE_MB) . ' MB.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = $kind === 'video'
        ? ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov']
        : ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Format file tidak didukung.'];
    }
    if ($kind === 'image' && @getimagesize($file['tmp_name']) === false) {
        return ['ok' => false, 'error' => 'File bukan gambar yang valid.'];
    }
    $sub = date('Y/m');
    $dir = UPLOAD_DIR . '/' . $sub;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Folder upload tidak dapat dibuat.'];
    }
    $name = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
    $abs = $dir . '/' . $name;
    $ext = $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $abs)) {
        return ['ok' => false, 'error' => 'File tidak dapat disimpan.'];
    }
    // Kompresi otomatis (gambar -> WebP terkecil, video -> MP4 720p + poster). Gagal = pakai file asli.
    if (MEDIA_COMPRESS) {
        try {
            $c = $kind === 'video' ? compress_video($abs, $ext) : compress_image($abs, $mime);
            if ($c) {
                $abs = $c['abs'];
                $mime = $c['mime'];
                $ext = $c['ext'];
                $name = basename($abs);
            }
        } catch (Throwable $e) {
            error_log('[ITIHIX] ' . basename(__FILE__) . ': ' . $e->getMessage());
        }
    }
    return ['ok' => true, 'path' => $sub . '/' . $name, 'mime' => $mime, 'abs' => $abs];
}

/* ---------- Upload banyak file sekaligus (foto & video campur), maks. $max ---------- */
function save_media_files(array $files, int $max = 10): array
{
    $list = [];
    if (isset($files['name']) && is_array($files['name'])) {
        foreach (array_keys($files['name']) as $i) {
            $list[] = ['name' => $files['name'][$i], 'type' => $files['type'][$i] ?? '', 'tmp_name' => $files['tmp_name'][$i] ?? '',
                       'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $files['size'][$i] ?? 0];
        }
    } elseif (isset($files['name'])) {
        $list[] = $files;
    }
    $list = array_values(array_filter($list, fn ($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    if (count($list) > $max) {
        return ['ok' => false, 'error' => 'Maksimal ' . $max . ' file per laporan.', 'items' => []];
    }
    $items = [];
    foreach ($list as $n => $f) {
        $mime = ($f['error'] === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name'])) ? (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) : '';
        $kind = strpos($mime, 'video/') === 0 ? 'video' : 'image';
        $r = save_upload($f, $kind);
        if (!$r['ok'] || empty($r['path'])) {
            foreach ($items as $it) {
                @unlink($it['abs']);
            }
            return ['ok' => false, 'error' => 'File ke-' . ($n + 1) . ' (' . mb_substr((string)$f['name'], 0, 40) . '): ' . ($r['error'] ?? 'gagal diunggah.'), 'items' => []];
        }
        $items[] = ['path' => $r['path'], 'kind' => $kind, 'abs' => $r['abs']];
    }
    return ['ok' => true, 'items' => $items];
}

/* Simpan daftar media sebuah postingan. image_path/video_path tetap diisi dengan media pertama (untuk thumbnail & kompatibilitas). */
function attach_post_media(int $postId, array $items): void
{
    foreach ($items as $i => $it) {
        q('INSERT INTO post_media (post_id, kind, path, sort_order) VALUES (?,?,?,?)', [$postId, $it['kind'], $it['path'], $i]);
    }
}

/* ---------- Rate limit sederhana (berbasis sesi) ---------- */
/**
 * Pembatas laju berbasis DATABASE (tabel rate_limits), per user (bila login) atau per IP.
 * Tidak bisa dihindari dengan membuang cookie, tidak seperti versi lama berbasis $_SESSION.
 * Bila tabel belum ada/DB bermasalah, jatuh ke penghitung session (tetap membatasi, tidak "gagal terbuka").
 * $subject bisa diisi manual (mis. 'ip1.2.3.4' atau 'id'.sha1($email)) untuk pembatas khusus.
 */
function rate_limit_subject(): string
{
    return !empty($_SESSION['uid']) ? 'u' . (int)$_SESSION['uid'] : 'ip' . client_ip();
}
function rate_limit(string $key, int $max, int $seconds, ?string $subject = null): bool
{
    $subject = substr($subject ?? rate_limit_subject(), 0, 64);
    $now = time();
    try {
        $n = (int)qval('SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND subject = ? AND hit_at > ?', [$key, $subject, $now - $seconds]);
        if ($n >= $max) {
            return false;
        }
        q('INSERT INTO rate_limits (bucket, subject, hit_at) VALUES (?,?,?)', [$key, $subject, $now]);
        if (random_int(1, 200) === 1) {
            q('DELETE FROM rate_limits WHERE hit_at < ?', [$now - 86400]);
        }   // bersih-bersih berkala
        return true;
    } catch (Throwable $e) {
        error_log('[ITIHIX rate_limit] ' . $e->getMessage());
        $k = $key . '|' . $subject;
        $_SESSION['rl'][$k] = array_values(array_filter($_SESSION['rl'][$k] ?? [], fn ($t) => $t > $now - $seconds));
        if (count($_SESSION['rl'][$k]) >= $max) {
            return false;
        }
        $_SESSION['rl'][$k][] = $now;
        return true;
    }
}
/** Hapus hitungan sebuah pembatas (mis. setelah login berhasil). */
function rate_limit_clear(string $key, ?string $subject = null): void
{
    $subject = substr($subject ?? rate_limit_subject(), 0, 64);
    try {
        q('DELETE FROM rate_limits WHERE bucket = ? AND subject = ?', [$key, $subject]);
    } catch (Throwable $e) {
        error_log('[ITIHIX rate_limit_clear] ' . $e->getMessage());
    }
    unset($_SESSION['rl'][$key . '|' . $subject]);
}

require_once __DIR__ . '/migrate.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/posts.php';
require_once __DIR__ . '/dm.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/chat_store.php';

if (!defined('ITIHIX_TESTING')) {
    ensure_schema();
}   // jalankan migrasi tertunda (satu query ringan per sesi baru)
