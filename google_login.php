<?php

require_once __DIR__ . '/includes/bootstrap.php';
if (GOOGLE_CLIENT_ID === '' || GOOGLE_CLIENT_SECRET === '' || GOOGLE_REDIRECT_URI === '') {
    flash('Login Google belum dikonfigurasi. Isi GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, dan GOOGLE_REDIRECT_URI di config.php.', 'err');
    redirect('login.php');
}
// ?link=1 : menautkan Google ke akun yang sedang login (dari Pengaturan), bukan masuk / daftar
if (!empty($_GET['link'])) {
    if (!current_user()) {
        redirect('login.php');
    }
    $_SESSION['g_mode'] = 'link';
} else {
    unset($_SESSION['g_mode']);
}
$_SESSION['g_state'] = bin2hex(random_bytes(16));
$qs = http_build_query([
    'client_id' => GOOGLE_CLIENT_ID, 'redirect_uri' => GOOGLE_REDIRECT_URI, 'response_type' => 'code',
    'scope' => 'openid email profile', 'state' => $_SESSION['g_state'], 'prompt' => 'select_account',
]);
redirect('https://accounts.google.com/o/oauth2/v2/auth?' . $qs);
