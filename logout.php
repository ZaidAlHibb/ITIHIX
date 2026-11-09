<?php

require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) {
    log_activity('logout', 'user', (int)current_user()['id'], 'Keluar');
}
$_SESSION = [];
session_regenerate_id(true); // sesi baru untuk tamu
flash('Kamu sudah keluar.');
redirect('index.php');
