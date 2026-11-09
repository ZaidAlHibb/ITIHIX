<?php

/** Helper riwayat Deteksi AI (kolom "saved" + hapus aman). */

/** Pastikan kolom detections.saved ada (dibuat otomatis kalau belum). Baris lama dianggap sudah tersimpan. */
function det_has_saved(): bool
{
    return schema_ready();
}   // kolom dibuat oleh migrations/

/** Pastikan kolom detections.images ada (JSON daftar semua gambar). Dibuat otomatis kalau belum. */
function det_has_images(): bool
{
    return schema_ready();
}   // kolom dibuat oleh migrations/

/** Pastikan kolom detections.after_steps ada (JSON langkah jika terlanjur). Dibuat otomatis kalau belum. */
function det_has_after(): bool
{
    return schema_ready();
}   // kolom dibuat oleh migrations/

/** Daftar path semua gambar milik satu baris detections (images JSON, jatuh ke image_path untuk data lama). */
function det_image_list(array $row): array
{
    $list = json_decode((string)($row['images'] ?? ''), true);
    if (!is_array($list) || !$list) {
        $list = !empty($row['image_path']) ? [(string)$row['image_path']] : [];
    }
    return array_values(array_filter(array_map('strval', $list), fn ($x) => $x !== ''));
}

/** Berapa analisis milik user yang masih memakai path gambar ini di kolom images (JSON). */
function det_count_in_images(string $path, int $uid): int
{
    $n = 0;
    foreach (qall('SELECT images FROM detections WHERE user_id = ? AND images IS NOT NULL', [$uid]) as $r) {
        $l = json_decode((string)$r['images'], true);
        if (is_array($l) && in_array($path, $l, true)) {
            $n++;
        }
    }
    return $n;
}

/** Hapus satu hasil analisis milik user. File gambar ikut dihapus kalau tidak dipakai postingan/analisis lain. */
function det_delete(int $id, int $uid): bool
{
    $hasImgs = det_has_images();
    $d = qone('SELECT id, image_path' . ($hasImgs ? ', images' : '') . ' FROM detections WHERE id = ? AND user_id = ?', [$id, $uid]);
    if (!$d) {
        return false;
    }
    q('DELETE FROM detections WHERE id = ? AND user_id = ?', [$id, $uid]);
    foreach (det_image_list($d) as $p) {
        try {
            $used = (int)qval('SELECT COUNT(*) FROM posts WHERE image_path = ?', [$p])
                  + (int)qval('SELECT COUNT(*) FROM post_media WHERE path = ?', [$p])
                  + (int)qval('SELECT COUNT(*) FROM detections WHERE image_path = ?', [$p])
                  + ($hasImgs ? det_count_in_images($p, $uid) : 0);
        } catch (Throwable $e) {
            continue;
        } // ragu -> jangan hapus file
        if ($used === 0) {
            $abs = realpath(UPLOAD_DIR . '/' . $p);
            $root = realpath(UPLOAD_DIR);
            if ($abs && $root && str_starts_with($abs, $root . DIRECTORY_SEPARATOR)) {
                @unlink($abs);
            }
        }
    }
    return true;
}

/** Pastikan kolom posts.scan_text ada (teks yang diperiksa ItihScan AI, ikut tampil di postingan). Dibuat otomatis kalau belum. */
function post_has_scan_text(): bool
{
    return schema_ready();
}   // kolom dibuat oleh migrations/
