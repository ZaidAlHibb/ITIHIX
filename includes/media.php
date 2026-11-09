<?php

/**
 * Kompresi media otomatis saat upload (gaya media sosial):
 *  - Gambar : dikecilkan (sisi terpanjang <= IMG_MAX_DIM), EXIF dibuang, disimpan WebP/JPEG (GD).
 *  - Video  : di-transcode ke MP4 H.264 + AAC maks. 720p, faststart (ffmpeg) + poster JPG.
 * Semua opsional & aman: bila GD/ffmpeg tidak ada atau gagal, file asli tetap dipakai.
 *
 * Opsi (config.php / .env / config.local.php):
 *   FFMPEG_PATH  mis. C:\ffmpeg\bin\ffmpeg.exe (kosong = cari "ffmpeg" di PATH)
 *   IMG_MAX_DIM, IMG_QUALITY, VIDEO_MAX_DIM, VIDEO_CRF, VIDEO_TIMEOUT, MEDIA_COMPRESS (false = matikan)
 */
defined('MEDIA_COMPRESS') || define('MEDIA_COMPRESS', true);
defined('IMG_MAX_DIM')    || define('IMG_MAX_DIM', 1600);   // px, sisi terpanjang
defined('IMG_QUALITY')    || define('IMG_QUALITY', 80);     // 1-100
defined('VIDEO_MAX_DIM')  || define('VIDEO_MAX_DIM', 1280); // px, sisi terpanjang (720p)
defined('VIDEO_CRF')      || define('VIDEO_CRF', 28);       // makin besar = makin kecil (23-30 wajar)
defined('VIDEO_TIMEOUT')  || define('VIDEO_TIMEOUT', 300);  // detik

function media_exec_ok(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return $ok = function_exists('exec') && !in_array('exec', $disabled, true);
}

/** Path ffmpeg yang bisa dipakai, atau null. */
function media_ffmpeg(): ?string
{
    static $bin = false;
    if ($bin !== false) {
        return $bin;
    }
    $bin = null;
    if (!media_exec_ok()) {
        return null;
    }
    $cands = [];
    $cfg = defined('FFMPEG_PATH') ? FFMPEG_PATH : (getenv('FFMPEG_PATH') ?: '');
    if ($cfg !== '') {
        $cands[] = $cfg;
    }
    $cands[] = 'ffmpeg';
    foreach ($cands as $c) {
        $out = [];
        $code = 1;
        @exec(escapeshellarg($c) . ' -version 2>&1', $out, $code);
        if ($code === 0) {
            $bin = $c;
            break;
        }
    }
    return $bin;
}

/** Lokasi poster (relatif terhadap uploads/) untuk sebuah video, mis. 2026/10/abc.mp4 -> 2026/10/abc-poster.jpg */
function video_poster_rel(string $videoRel): string
{
    return preg_replace('/\.[A-Za-z0-9]+$/', '', $videoRel) . '-poster.jpg';
}

/** URL poster bila file-nya ada, selain itu null. */
function video_poster_url(?string $videoRel): ?string
{
    if (!$videoRel) {
        return null;
    }
    $rel = video_poster_rel($videoRel);
    return is_file(UPLOAD_DIR . '/' . $rel) ? url('uploads/' . $rel) : null;
}

/**
 * URL thumbnail video. Bila poster belum ada (video lama / diunggah tanpa ffmpeg), coba dibuat sekali dengan ffmpeg
 * lalu disimpan di samping videonya. Bila ffmpeg tidak tersedia/gagal, kembalikan null (tampilan memakai placeholder).
 */
function video_thumb_url(?string $videoRel): ?string
{
    if (!$videoRel) {
        return null;
    }
    if ($u = video_poster_url($videoRel)) {
        return $u;
    }
    $vid = UPLOAD_DIR . '/' . $videoRel;
    $rel = video_poster_rel($videoRel);
    $poster = UPLOAD_DIR . '/' . $rel;
    $miss = $poster . '.none';
    if (!is_file($vid) || is_file($miss)) {
        return null;
    }
    $ff = media_ffmpeg();
    if ($ff) {
        $cmd = escapeshellarg($ff) . ' -y -hide_banner -loglevel error -ss 0.5 -i ' . escapeshellarg($vid) . ' -frames:v 1 -vf ' . escapeshellarg('scale=min(720\,iw):-2') . ' -q:v 5 ' . escapeshellarg($poster) . ' 2>&1';
        @exec($cmd, $log, $code);
        if (!is_file($poster) || filesize($poster) < 200) {
            @exec(escapeshellarg($ff) . ' -y -hide_banner -loglevel error -i ' . escapeshellarg($vid) . ' -frames:v 1 -vf ' . escapeshellarg('scale=min(720\,iw):-2') . ' -q:v 5 ' . escapeshellarg($poster) . ' 2>&1', $log, $code);
        }
        if (is_file($poster) && filesize($poster) >= 200) {
            return url('uploads/' . $rel);
        }
        if (is_file($poster)) {
            @unlink($poster);
        }
    }
    @file_put_contents($miss, '');   // jangan coba lagi di setiap muat halaman
    return null;
}

/**
 * Kompres gambar. Mengembalikan ['abs'=>, 'mime'=>, 'ext'=>] baru bila berhasil, null bila file asli dipertahankan.
 * GIF dilewati (bisa beranimasi).
 */
function compress_image(string $abs, string $mime): ?array
{
    if (!MEDIA_COMPRESS || $mime === 'image/gif' || !extension_loaded('gd')) {
        return null;
    }
    $info = @getimagesize($abs);
    if (!$info) {
        return null;
    }
    [$w, $h] = $info;
    if ($w < 1 || $h < 1 || $w * $h > 60000000) {
        return null;
    } // cegah kehabisan memori pada gambar raksasa
    @ini_set('memory_limit', '512M');

    $src = match ($mime) {
        'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($abs) : false,
        'image/png'  => function_exists('imagecreatefrompng') ? @imagecreatefrompng($abs) : false,
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($abs) : false,
        default => false,
    };
    if (!$src) {
        return null;
    }

    // Putar sesuai orientasi EXIF (foto dari HP)
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $ex = @exif_read_data($abs);
        $o = (int)($ex['Orientation'] ?? 1);
        $deg = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($deg && ($rot = @imagerotate($src, $deg, 0))) {
            imagedestroy($src);
            $src = $rot;
            $w = imagesx($src);
            $h = imagesy($src);
        }
    }

    $scale = min(1, IMG_MAX_DIM / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    $alpha = $mime !== 'image/jpeg';
    if ($alpha) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    $useWebp = function_exists('imagewebp');
    $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $abs);
    if ($useWebp) {
        $ext = 'webp';
        $outMime = 'image/webp';
    } elseif ($mime === 'image/png') {
        $ext = 'png';
        $outMime = 'image/png';
    } else {
        $ext = 'jpg';
        $outMime = 'image/jpeg';
    }
    $tmp = $base . '.tmp.' . $ext;
    $okSave = match ($ext) {
        'webp' => @imagewebp($dst, $tmp, IMG_QUALITY),
        'png'  => @imagepng($dst, $tmp, 9),
        default => @imagejpeg($dst, $tmp, IMG_QUALITY),
    };
    imagedestroy($dst);
    if (!$okSave || !is_file($tmp)) {
        @unlink($tmp);
        return null;
    }

    // Pakai hasil kompresi hanya jika memang lebih kecil atau gambar harus diperkecil
    if ($scale >= 1 && filesize($tmp) >= filesize($abs)) {
        @unlink($tmp);
        return null;
    }
    $final = $base . '.' . $ext;
    if ($final !== $abs) {
        @unlink($abs);
    }
    if (!@rename($tmp, $final)) {
        @unlink($tmp);
        return null;
    }
    return ['abs' => $final, 'mime' => $outMime, 'ext' => $ext];
}

/**
 * Transcode video -> MP4 (H.264/AAC, maks. VIDEO_MAX_DIM, faststart) dan buat poster.
 * Mengembalikan ['abs'=>, 'mime'=>'video/mp4', 'ext'=>'mp4'] bila hasil dipakai, null bila file asli dipertahankan.
 * Poster selalu dicoba dibuat (dari hasil akhir).
 */
function compress_video(string $abs, string $ext): ?array
{
    $ff = media_ffmpeg();
    if (!$ff) {
        return null;
    }
    @set_time_limit(VIDEO_TIMEOUT + 60);
    $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $abs);
    $out = $base . '.tmp.mp4';
    $m = (int)VIDEO_MAX_DIM;
    $vf = 'scale=min(' . $m . '\,iw):min(' . $m . '\,ih):force_original_aspect_ratio=decrease:force_divisible_by=2,setsar=1';
    $timeout = '';
    if (PHP_OS_FAMILY !== 'Windows') {
        foreach (['/usr/bin/timeout', '/bin/timeout'] as $t) {
            if (is_executable($t)) {
                $timeout = $t . ' ' . (int)VIDEO_TIMEOUT . ' ';
                break;
            }
        }
    }
    $cmd = $timeout . escapeshellarg($ff) . ' -y -hide_banner -loglevel error -i ' . escapeshellarg($abs)
         . ' -map 0:v:0 -map 0:a:0? -vf ' . escapeshellarg($vf)
         . ' -c:v libx264 -preset veryfast -crf ' . (int)VIDEO_CRF . ' -maxrate 2500k -bufsize 5000k -pix_fmt yuv420p'
         . ' -c:a aac -b:a 96k -ac 2 -movflags +faststart -threads 2 ' . escapeshellarg($out) . ' 2>&1';
    $log = [];
    $code = 1;
    @exec($cmd, $log, $code);

    $result = null;
    if ($code === 0 && is_file($out) && filesize($out) > 1024) {
        // Pakai hasil bila lebih kecil, atau bila aslinya bukan MP4 (agar semua perangkat bisa memutar)
        if (filesize($out) < filesize($abs) || strtolower($ext) !== 'mp4') {
            $final = $base . '.mp4';
            if ($final !== $abs) {
                @unlink($abs);
            }
            if (@rename($out, $final)) {
                $result = ['abs' => $final, 'mime' => 'video/mp4', 'ext' => 'mp4'];
            }
        }
    }
    if (is_file($out)) {
        @unlink($out);
    }

    // Poster (frame ke-0,5 detik), dikecilkan agar ringan
    $vid = $result['abs'] ?? $abs;
    $poster = $base . '-poster.jpg';
    $pcmd = escapeshellarg($ff) . ' -y -hide_banner -loglevel error -ss 0.5 -i ' . escapeshellarg($vid)
          . ' -frames:v 1 -vf ' . escapeshellarg('scale=min(720\,iw):-2') . ' -q:v 5 ' . escapeshellarg($poster) . ' 2>&1';
    @exec($pcmd, $log, $pc);
    if (!is_file($poster) || filesize($poster) < 200) { // video sangat pendek: ambil frame pertama
        @exec(escapeshellarg($ff) . ' -y -hide_banner -loglevel error -i ' . escapeshellarg($vid) . ' -frames:v 1 -vf ' . escapeshellarg('scale=min(720\,iw):-2') . ' -q:v 5 ' . escapeshellarg($poster) . ' 2>&1', $log, $pc);
    }
    if (is_file($poster) && filesize($poster) < 200) {
        @unlink($poster);
    }
    return $result;
}
