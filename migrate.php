<?php

/** Jalankan dari terminal:  php migrate.php   (menjalankan migrasi yang belum tercatat). Tidak bisa dibuka lewat browser. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/includes/bootstrap.php';
try {
    $ran = run_migrations();
    echo $ran ? "Dijalankan:\n  - " . implode("\n  - ", $ran) . "\n" : "Tidak ada migrasi tertunda.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Gagal: ' . $e->getMessage() . "\n");
    exit(1);
}
