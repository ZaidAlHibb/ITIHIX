<?php

$bare = true;
$guest = true;
$pageTitle = 'Daftar';
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) {
    redirect('index.php');
}
$error = '';
if (is_post()) {
    csrf_check();
    $name = trim((string)($_POST['display_name'] ?? ''));
    $user = trim((string)($_POST['username'] ?? ''));
    $mail = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $pw = (string)($_POST['password'] ?? '');
    if ($name === '' || mb_strlen($name) > 60) {
        $error = 'Nama tampilan wajib diisi (maks. 60 karakter).';
    } elseif (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $user)) {
        $error = 'Nama pengguna 3–30 karakter: huruf, angka, atau underscore.';
    } elseif (username_reserved($user)) {
        $error = 'Nama pengguna ini dicadangkan oleh sistem. Pilih yang lain.';
    } elseif (!filter_var($mail, FILTER_VALIDATE_EMAIL) || strlen($mail) > 190) {
        $error = 'Format email tidak valid.';
    } elseif (strlen($pw) < 8) {
        $error = 'Kata sandi minimal 8 karakter.';
    } elseif ($pw !== ($_POST['password2'] ?? '')) {
        $error = 'Konfirmasi kata sandi tidak sama.';
    } elseif (qval('SELECT 1 FROM users WHERE username = ?', [$user])) {
        $error = 'Nama pengguna sudah dipakai.';
    } elseif (qval('SELECT 1 FROM users WHERE email = ?', [$mail])) {
        $error = 'Email sudah terdaftar. Silakan masuk.';
    } elseif (!rate_limit('register', 5, 3600)) {
        $error = 'Terlalu banyak pendaftaran dari perangkat ini. Coba lagi nanti.';
    } else {
        // Akun BELUM dibuat: data ditahan di sesi sampai kode dari email dimasukkan di verify_email.php
        $colors = ['#ffe0e2', '#d9e8ff', '#fce2c5', '#e6dcff', '#d9f2e6', '#fff0c2'];
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['pending_reg'] = [
            'name' => $name, 'user' => $user, 'email' => $mail,
            'hash' => password_hash($pw, PASSWORD_DEFAULT), 'color' => $colors[array_rand($colors)],
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires' => time() + OTP_TTL, 'tries' => 0, 'sent_at' => time(),
        ];
        if (!send_verification_code($mail, $name, $code)) {
            unset($_SESSION['pending_reg']);
            $error = 'Gagal mengirim email verifikasi. Pastikan alamat email benar, lalu coba lagi beberapa saat.';
        } else {
            redirect('verify_email.php');
        }
    }
}
$mode = 'register';
require __DIR__ . '/includes/auth_card.php';
