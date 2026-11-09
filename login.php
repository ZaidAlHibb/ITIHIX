<?php

$bare = true;
$guest = true;
$pageTitle = 'Masuk';
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) {
    redirect('index.php');
}
$error = '';
if (is_post()) {
    csrf_check();
    $id = trim((string)($_POST['email'] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    // Dua pembatas: per IP (longgar, untuk jaringan bersama) dan per akun yang dituju (ketat, melawan tebak sandi).
    $acctKey = 'id' . sha1(mb_strtolower(ltrim($id, '@')));
    if (!rate_limit('login_ip', 30, 600, 'ip' . client_ip()) || !rate_limit('login_acct', 8, 600, $acctKey)) {
        $error = 'Terlalu banyak percobaan. Coba lagi dalam beberapa menit.';
    } else {
        $u = qone('SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1', [$id, ltrim($id, '@')]);
        if (!$u || !$u['password_hash'] || !password_verify($pw, $u['password_hash'])) {
            $error = ($u && !$u['password_hash']) ? 'Akun ini terdaftar lewat Google. Gunakan tombol “Lanjutkan dengan Google”.' : 'Email/nama pengguna atau kata sandi salah.';
            log_activity('login_failed', 'user', $u ? (int)$u['id'] : null, 'Percobaan masuk gagal: ' . mb_substr($id, 0, 60), $u ? (int)$u['id'] : null);
        } elseif ($u['status'] !== 'active') {
            $error = 'Akun ini ditangguhkan. Hubungi admin untuk bantuan.';
        } else {
            rate_limit_clear('login_acct', $acctKey);
            login_user($u);
            $to = $_SESSION['after_login'] ?? '';
            unset($_SESSION['after_login']);
            if ($to && strpos($to, BASE_URL . '/') === 0 && strpos($to, '//') !== 0) {
                redirect($to);
            }
            redirect('index.php');
        }
    }
}
$mode = 'login';
require __DIR__ . '/includes/auth_card.php';
