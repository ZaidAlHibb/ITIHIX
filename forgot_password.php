<?php

$bare = true;
$guest = true;
$pageTitle = 'Lupa kata sandi';
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) {
    redirect('index.php');
}
$error = '';
if (is_post()) {
    csrf_check();
    $mail = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    if (!filter_var($mail, FILTER_VALIDATE_EMAIL) || strlen($mail) > 190) {
        $error = 'Format email tidak valid.';
    } elseif (!rate_limit('reset_ip', 10, 3600, 'ip' . client_ip())) {
        $error = 'Terlalu banyak permintaan dari perangkat ini. Coba lagi nanti.';
    } else {
        $_SESSION['pending_reset'] = start_password_reset($mail);
        redirect('reset_password.php');
    }
}
$mode = 'login';
$view = 'forgot';
require __DIR__ . '/includes/auth_card.php';
