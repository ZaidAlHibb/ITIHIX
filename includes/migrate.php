<?php

/**
 * Sistem migrasi skema sederhana.
 *  - File migrasi ada di /migrations, nama NNN_deskripsi.php, dijalankan berurutan sekali saja.
 *  - Tiap file mengembalikan fungsi: `return function (PDO $pdo): void { ... };` dan HARUS idempoten
 *    (aman dijalankan ulang; pakai CREATE TABLE IF NOT EXISTS dan helper migration_has_column()).
 *  - Pencatatan di tabel schema_migrations. Menjalankan: `php migrate.php` (CLI) atau otomatis sekali per sesi
 *    lewat ensure_schema() bila jumlah migrasi di disk lebih banyak daripada yang tercatat.
 *  - Setiap menambah file migrasi, naikkan SCHEMA_VERSION di config.php.
 */
function migration_has_column(PDO $pdo, string $table, string $col): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}
function migration_has_table(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}
/** Tambah kolom hanya bila belum ada. */
function migration_add_column(PDO $pdo, string $table, string $col, string $ddl): void
{
    if (migration_has_table($pdo, $table) && !migration_has_column($pdo, $table, $col)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
    }
}
function migration_files(): array
{
    $f = glob(__DIR__ . '/../migrations/*.php') ?: [];
    sort($f, SORT_STRING);
    return $f;
}
/** Jalankan semua migrasi yang belum tercatat. @return string[] nama migrasi yang baru dijalankan */
function run_migrations(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        name VARCHAR(120) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $locked = (int)$pdo->query("SELECT GET_LOCK('itihix_migrate', 15)")->fetchColumn() === 1;   // cegah dua proses berjalan bersamaan
    if (!$locked) {
        throw new RuntimeException('Migrasi sedang dijalankan proses lain.');
    }
    $ran = [];
    try {
        $done = array_flip($pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
        foreach (migration_files() as $file) {
            $name = basename($file, '.php');
            if (isset($done[$name])) {
                continue;
            }
            $fn = require $file;
            if (!is_callable($fn)) {
                throw new RuntimeException("Migrasi $name tidak mengembalikan fungsi.");
            }
            $fn($pdo);
            $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
            $ran[] = $name;
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('itihix_migrate')");
    }
    return $ran;
}
/** Dipanggil dari bootstrap: murah (satu query per sesi baru), menjalankan migrasi bila ada yang tertunda. */
function ensure_schema(): void
{
    if (($_SESSION['schema_v'] ?? null) === SCHEMA_VERSION) {
        return;
    }
    try {
        $pending = count(migration_files());
        $applied = 0;
        try {
            $applied = (int)qval('SELECT COUNT(*) FROM schema_migrations');
        } catch (Throwable $e) {
            $applied = 0;
        }
        if ($applied < $pending) {
            run_migrations();
        }
        $_SESSION['schema_v'] = SCHEMA_VERSION;
    } catch (Throwable $e) {
        error_log('[ITIHIX migrate] ' . $e->getMessage());   // jangan memutus halaman; coba lagi di request berikutnya
    }
}

/** True bila skema sudah dipastikan terbaru pada sesi ini (menggantikan semua cek kolom/tabel per-request yang lama). */
function schema_ready(): bool
{
    return ($_SESSION['schema_v'] ?? null) === SCHEMA_VERSION;
}
