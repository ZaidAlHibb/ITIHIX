<?php

/* Simpan profil dari jendela "Edit profil": nama tampilan, bio, warna avatar, dan foto (sudah di-crop di browser). */
require_once __DIR__ . '/../includes/bootstrap.php';
$me = require_login();
if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Metode tidak valid.'], 405);
}
csrf_check();

$uid    = (int)$me['id'];
$colors = ['#ffe0e2', '#d9e8ff', '#fce2c5', '#e6dcff', '#d9f2e6', '#fff0c2', '#ffd9f0', '#dcdcdc'];
$name   = trim((string)($_POST['display_name'] ?? ''));
$bio    = trim((string)($_POST['bio'] ?? ''));
$color  = (string)($_POST['avatar_color'] ?? $me['avatar_color']);

if ($name === '' || mb_strlen($name) > 60) {
    json_out(['ok' => false, 'error' => 'Nama tampilan wajib diisi (maks. 60 karakter).'], 422);
}
if (mb_strlen($bio) > 280) {
    json_out(['ok' => false, 'error' => 'Bio maksimal 280 karakter.'], 422);
}
if (!in_array($color, $colors, true)) {
    $color = in_array($me['avatar_color'], $colors, true) ? $me['avatar_color'] : '#ffe0e2';
}

/* Warna banner: '' = bawaan, selain itu harus hex 6 digit. */
$banner = array_key_exists('banner_color', $_POST) ? strtolower(trim((string)$_POST['banner_color'])) : strtolower((string)($me['banner_color'] ?? ''));
if (!preg_match('/^#[0-9a-f]{6}$/', $banner)) {
    $banner = '';
}

$img = save_upload($_FILES['avatar'] ?? ['error' => UPLOAD_ERR_NO_FILE], 'image');
if (!$img['ok']) {
    json_out(['ok' => false, 'error' => $img['error']], 422);
}

$old  = $me['avatar_path'] ?: null;
$path = $old;
if (!empty($_POST['remove_avatar'])) {
    $path = null;
}
if (!empty($img['path'])) {
    $path = $img['path'];
}

q('UPDATE users SET display_name=?, bio=?, avatar_color=?, avatar_path=?, banner_color=? WHERE id=?', [$name, $bio, $color, $path, $banner !== '' ? $banner : null, $uid]);

/* Bersihkan foto lama yang sudah tidak dipakai (hanya bila tidak dipakai pengguna lain). */
if ($old && $old !== $path && !qval('SELECT 1 FROM users WHERE avatar_path = ? LIMIT 1', [$old])) {
    $abs = realpath(UPLOAD_DIR . '/' . $old);
    $root = realpath(UPLOAD_DIR);
    if ($abs && $root && str_starts_with($abs, $root . DIRECTORY_SEPARATOR) && is_file($abs)) {
        @unlink($abs);
    }
}

log_activity('settings_profile', 'user', $uid, 'Mengubah profil');
flash('Profil diperbarui.');
json_out(['ok' => true, 'message' => 'Profil diperbarui.']);
