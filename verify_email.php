<?php

$bare = true;
$guest = true;
$pageTitle = 'Verifikasi email';
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) {
    redirect('index.php');
}

$p = $_SESSION['pending_reg'] ?? null;
if (!$p) {
    redirect('register.php');
}

$error = '';
$info = '';
if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'verify');

    if ($action === 'cancel') {               // salah ketik email -> kembali ke formulir
        unset($_SESSION['pending_reg']);
        redirect('register.php');
    }

    if ($action === 'resend') {
        $wait = OTP_RESEND_WAIT - (time() - (int)$p['sent_at']);
        if ($wait > 0) {
            $error = 'Tunggu ' . $wait . ' detik sebelum meminta kode baru.';
        } elseif (!rate_limit('otp_resend', 5, 3600)) {
            $error = 'Terlalu banyak permintaan kode. Coba lagi nanti.';
        } else {
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            if (send_verification_code($p['email'], $p['name'], $code)) {
                $p['code_hash'] = password_hash($code, PASSWORD_DEFAULT);
                $p['expires'] = time() + OTP_TTL;
                $p['tries'] = 0;
                $p['sent_at'] = time();
                $_SESSION['pending_reg'] = $p;
                $info = 'Kode baru sudah dikirim ke ' . $p['email'] . '.';
            } else {
                $error = 'Gagal mengirim email. Coba lagi sebentar lagi.';
            }
        }
    } else { // verify
        $input = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
        if (time() > (int)$p['expires']) {
            $error = 'Kode sudah kedaluwarsa. Klik “Kirim ulang kode”.';
        } elseif ((int)$p['tries'] >= OTP_MAX_TRIES) {
            $error = 'Terlalu banyak kode salah. Klik “Kirim ulang kode” untuk kode baru.';
        } elseif (strlen($input) !== 6) {
            $error = 'Masukkan 6 digit kode dari email.';
        } elseif (!password_verify($input, $p['code_hash'])) {
            $p['tries']++;
            $_SESSION['pending_reg'] = $p;
            $left = OTP_MAX_TRIES - $p['tries'];
            $error = $left > 0 ? 'Kode salah. Sisa percobaan: ' . $left . '.' : 'Terlalu banyak kode salah. Klik “Kirim ulang kode” untuk kode baru.';
        } elseif (qval('SELECT 1 FROM users WHERE username = ?', [$p['user']])) {
            unset($_SESSION['pending_reg']);
            redirect('register.php?msg=' . rawurlencode('Nama pengguna sudah dipakai orang lain. Silakan daftar ulang dengan nama lain.'));
        } elseif (qval('SELECT 1 FROM users WHERE email = ?', [$p['email']])) {
            unset($_SESSION['pending_reg']);
            redirect('login.php?msg=' . rawurlencode('Email sudah terdaftar. Silakan masuk.'));
        } else {
            q(
                'INSERT INTO users (username, display_name, email, password_hash, avatar_color) VALUES (?,?,?,?,?)',
                [$p['user'], $p['name'], $p['email'], $p['hash'], $p['color']]
            );
            $id = (int)db()->lastInsertId();
            unset($_SESSION['pending_reg']);
            log_activity('register', 'user', $id, 'Akun baru (email terverifikasi)', $id);
            notify($id, 'star', 'Selamat datang di ITIHIX!', 'Mulai dari Scam or Real? untuk mengumpulkan XP pertamamu.', 'quiz.php');
            login_user(qone('SELECT * FROM users WHERE id = ?', [$id]));
            redirect('index.php');
        }
    }
}

$resendIn = max(0, OTP_RESEND_WAIT - (time() - (int)$p['sent_at']));
$maskedEmail = $p['email'];
$mode = 'register';
$verify = true;
require __DIR__ . '/includes/auth_card.php';
