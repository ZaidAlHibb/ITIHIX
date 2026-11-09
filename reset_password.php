<?php

$bare = true;
$guest = true;
$pageTitle = 'Atur ulang kata sandi';
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) {
    redirect('index.php');
}

$p = $_SESSION['pending_reset'] ?? null;
if (!$p) {
    redirect('forgot_password.php');
}

$error = '';
$info = '';
if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'reset');

    if ($action === 'cancel') {               // salah ketik email -> kembali ke formulir
        unset($_SESSION['pending_reset']);
        redirect('forgot_password.php');
    }

    if ($action === 'resend') {
        $wait = OTP_RESEND_WAIT - (time() - (int)$p['sent_at']);
        if ($wait > 0) {
            $error = 'Tunggu ' . $wait . ' detik sebelum meminta kode baru.';
        } elseif (!rate_limit('reset_ip', 10, 3600, 'ip' . client_ip())) {
            $error = 'Terlalu banyak permintaan dari perangkat ini. Coba lagi nanti.';
        } else {
            $_SESSION['pending_reset'] = $p = start_password_reset($p['email']);
            $info = 'Jika email terdaftar, kode baru sudah dikirim.';
        }
    } else { // reset
        $input = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
        $pw = (string)($_POST['password'] ?? '');
        if (time() > (int)$p['expires']) {
            $error = 'Kode sudah kedaluwarsa. Klik “Kirim ulang kode”.';
        } elseif ((int)$p['tries'] >= OTP_MAX_TRIES) {
            $error = 'Terlalu banyak kode salah. Klik “Kirim ulang kode” untuk kode baru.';
        } elseif (strlen($input) !== 6) {
            $error = 'Masukkan 6 digit kode dari email.';
        } elseif (strlen($pw) < 8) {
            $error = 'Kata sandi minimal 8 karakter.';
        } elseif ($pw !== (string)($_POST['password2'] ?? '')) {
            $error = 'Konfirmasi kata sandi tidak sama.';
        } elseif (!$p['code_hash'] || !password_verify($input, $p['code_hash'])) {
            $p['tries']++;
            $_SESSION['pending_reset'] = $p;
            $left = OTP_MAX_TRIES - $p['tries'];
            $error = $left > 0 ? 'Kode salah. Sisa percobaan: ' . $left . '.' : 'Terlalu banyak kode salah. Klik “Kirim ulang kode” untuk kode baru.';
        } else {
            $u = qone('SELECT * FROM users WHERE id = ? AND email = ? AND status = ? LIMIT 1', [(int)$p['uid'], $p['email'], 'active']);
            if (!$u || !$u['password_hash']) {
                unset($_SESSION['pending_reset']);
                redirect('forgot_password.php');
            }
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), (int)$u['id']]);
            // buka blokir percobaan masuk yang mungkin menumpuk (kunci sama dengan login.php)
            foreach ([$u['email'], $u['username']] as $ident) {
                rate_limit_clear('login_acct', 'id' . sha1(mb_strtolower(ltrim((string)$ident, '@'))));
            }
            unset($_SESSION['pending_reset']);
            log_activity('password_reset', 'user', (int)$u['id'], 'Kata sandi diatur ulang lewat kode email', (int)$u['id']);
            notify((int)$u['id'], 'star', 'Kata sandi diubah', 'Kata sandimu baru saja diatur ulang. Jika bukan kamu, segera ganti kata sandi dan hubungi admin.', 'settings.php');
            redirect('login.php?msg=' . rawurlencode('Kata sandi berhasil diubah. Silakan masuk dengan kata sandi baru.'));
        }
    }
}

$resendIn = max(0, OTP_RESEND_WAIT - (time() - (int)$p['sent_at']));
$maskedEmail = mask_email($p['email']);
$mode = 'login';
$view = 'reset';
require __DIR__ . '/includes/auth_card.php';
