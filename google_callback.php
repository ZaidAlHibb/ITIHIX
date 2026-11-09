<?php

require_once __DIR__ . '/includes/bootstrap.php';
function g_post(string $url, array $data): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $r = curl_exec($ch);
    curl_close($ch);
    return $r ? json_decode($r, true) : null;
}
$gMode = (string)($_SESSION['g_mode'] ?? '');
unset($_SESSION['g_mode']);
function g_fail(string $m): never
{
    global $gMode;
    flash($m, 'err');
    redirect($gMode === 'link' ? 'settings.php?p=tertaut' : 'login.php');
}

if (empty($_GET['code']) || empty($_GET['state']) || !hash_equals($_SESSION['g_state'] ?? '', (string)$_GET['state'])) {
    g_fail('Login Google gagal (state tidak valid).');
}
unset($_SESSION['g_state']);
$tok = g_post('https://oauth2.googleapis.com/token', [
    'code' => $_GET['code'], 'client_id' => GOOGLE_CLIENT_ID, 'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri' => GOOGLE_REDIRECT_URI, 'grant_type' => 'authorization_code',
]);
if (empty($tok['access_token'])) {
    g_fail('Tidak dapat memverifikasi akun Google.');
}
$ch = curl_init('https://openidconnect.googleapis.com/v1/userinfo');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok['access_token']]]);
$info = json_decode((string)curl_exec($ch), true);
curl_close($ch);
if (empty($info['sub']) || empty($info['email']) || empty($info['email_verified'])) {
    g_fail('Email Google belum terverifikasi.');
}

$gid = $info['sub'];
$mail = mb_strtolower($info['email']);

// Mode tautkan: Google dipasang ke akun yang sedang login, tanpa membuat akun atau berganti sesi
if ($gMode === 'link') {
    $cur = current_user();
    if (!$cur) {
        redirect('login.php');
    }
    $owner = qone('SELECT id FROM users WHERE google_id = ?', [$gid]);
    if ($cur['google_id'] && $cur['google_id'] !== $gid) {
        g_fail('Akunmu sudah terhubung ke akun Google lain. Putuskan dulu sebelum menautkan yang baru.');
    }
    if ($owner && (int)$owner['id'] !== (int)$cur['id']) {
        g_fail('Akun Google ini sudah tertaut ke akun ITIHIX lain.');
    }
    q('UPDATE users SET google_id = ? WHERE id = ?', [$gid, $cur['id']]);
    log_activity('link_google', 'user', (int)$cur['id'], 'Menautkan akun Google (' . $mail . ')', (int)$cur['id']);
    flash('Akun Google (' . $mail . ') berhasil ditautkan.');
    redirect('settings.php?p=tertaut');
}
$u = qone('SELECT * FROM users WHERE google_id = ?', [$gid]);
if (!$u) {
    $u = qone('SELECT * FROM users WHERE email = ?', [$mail]);
    if ($u) {
        // Email sudah terdaftar tapi Google belum tertaut: jangan tautkan diam-diam (agar Google yang sudah diputus tidak tersambung lagi sendiri).
        g_fail('Email ' . $mail . ' sudah terdaftar. Masuk dengan email dan kata sandi, lalu hubungkan Google di Pengaturan → Akun tertaut.');
    } else {
        $base = preg_replace('/[^a-z0-9_]/', '', strtolower(strstr($mail, '@', true))) ?: 'user';
        $base = substr($base, 0, 22);
        if (strlen($base) < 3) {
            $base = str_pad($base, 3, '0');
        }
        $uname = $base;
        $i = 0;
        while (username_reserved($uname) || qval('SELECT 1 FROM users WHERE username = ?', [$uname])) {
            $i++;
            $uname = $base . random_int(10, 9999);
            if ($i > 20) {
                break;
            }
        }
        q(
            'INSERT INTO users (username, display_name, email, google_id, avatar_color) VALUES (?,?,?,?,?)',
            [$uname, mb_substr($info['name'] ?? $base, 0, 60), $mail, $gid, '#d9e8ff']
        );
        $uid = (int)db()->lastInsertId();
        log_activity('register', 'user', $uid, 'Akun baru (Google)', $uid);
        notify($uid, 'star', 'Selamat datang di ITIHIX!', 'Mulai dari Scam or Real? untuk mengumpulkan XP pertamamu.', 'quiz.php');
        $u = qone('SELECT * FROM users WHERE id = ?', [$uid]);
    }
}
if ($u['status'] !== 'active') {
    g_fail('Akun ini ditangguhkan.');
}
login_user($u);
redirect('index.php');
