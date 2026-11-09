<?php

/**
 * ITIHIX - Konfigurasi utama.
 * Ubah nilai di bawah ini (atau set lewat environment variable) sesuai server kamu.
 */
// Muat file .env (opsional): baris KEY=VALUE, tanda # untuk komentar. Nilai dari server tetap diutamakan.
// Urutan pencarian: ITIHIX_ENV_FILE -> ../itihix.env (DI LUAR web root, paling aman) -> ./.env (dilindungi .htaccess).
$__envFile = null;
foreach ([getenv('ITIHIX_ENV_FILE') ?: '', dirname(__DIR__) . '/itihix.env', __DIR__ . '/.env'] as $__cand) {
    if ($__cand !== '' && is_readable($__cand)) {
        $__envFile = $__cand;
        break;
    }
}
if ($__envFile !== null) {
    foreach (file($__envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v, "\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv($k . '=' . $v);
            $_ENV[$k] = $v;
        }
    }
}
unset($__envFile, $__cand, $line, $k, $v);

define('DB_HOST', getenv('ITIHIX_DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('ITIHIX_DB_PORT') ?: '3306');
define('DB_NAME', getenv('ITIHIX_DB_NAME') ?: 'itihix');
define('DB_USER', getenv('ITIHIX_DB_USER') ?: 'root');
define('DB_PASS', getenv('ITIHIX_DB_PASS') ?: '');

// Folder URL aplikasi, tanpa slash di akhir. Contoh: '' (root domain) atau '/itihix'
define('BASE_URL', getenv('ITIHIX_BASE_URL') !== false ? getenv('ITIHIX_BASE_URL') : '');

// Rahasia lokal (API key, dll) boleh ditaruh di config.local.php agar tidak ikut ter-commit. Lihat config.local.php.example
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

define('APP_NAME', 'ITIHIX');
define('SCHEMA_VERSION', 1); // naikkan setiap menambah file di migrations/ (lihat includes/migrate.php)
define('MAX_LEVEL', 110);

// --- AI (opsional). Kosongkan API key = pakai mesin analisis lokal berbasis aturan ---
// AI_PROVIDER: 'openai'    = format OpenAI-compatible (/chat/completions) -> OpenRouter, gateway/proxy, Groq, Together, dll.
//              'anthropic' = format Anthropic (/v1/messages)
// Isi lewat config.local.php (disarankan) atau environment variable. Nama lama ANTHROPIC_* tetap dikenali.
defined('AI_PROVIDER') || define('AI_PROVIDER', getenv('AI_PROVIDER') ?: 'openai');
defined('AI_BASE_URL') || define('AI_BASE_URL', getenv('AI_BASE_URL') ?: (AI_PROVIDER === 'anthropic' ? 'https://api.anthropic.com' : 'https://api.openai.com/v1'));
defined('AI_API_KEY')  || define('AI_API_KEY', getenv('AI_API_KEY') ?: (defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : (getenv('ANTHROPIC_API_KEY') ?: '')));
defined('AI_MODEL')    || define('AI_MODEL', getenv('AI_MODEL') ?: (defined('ANTHROPIC_MODEL') ? ANTHROPIC_MODEL : (getenv('ANTHROPIC_MODEL') ?: 'claude-opus-5-5')));

// --- Login Google OAuth 2.0 (opsional) ---
// Buat kredensial di https://console.cloud.google.com/apis/credentials
// Authorized redirect URI: https://DOMAINMU/itihix/google_callback.php
define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');
define('GOOGLE_REDIRECT_URI', getenv('GOOGLE_REDIRECT_URI') ?: '');

// --- Email (kode verifikasi saat daftar) ---
// Contoh Gmail: SMTP_HOST=smtp.gmail.com, SMTP_PORT=587, SMTP_SECURE=tls, SMTP_USER=emailkamu@gmail.com,
// SMTP_PASS=<App Password 16 huruf dari akun Google (butuh verifikasi 2 langkah)>. Boleh ditaruh di .env.
// MAIL_DEV_LOG=1: kode ditulis ke includes/mail.log (untuk uji di komputer sendiri tanpa SMTP). Matikan di server asli.
define('SMTP_HOST', (string)(getenv('SMTP_HOST') ?: ''));
define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587));
define('SMTP_SECURE', (string)(getenv('SMTP_SECURE') !== false ? getenv('SMTP_SECURE') : 'tls'));
define('SMTP_USER', (string)(getenv('SMTP_USER') ?: ''));
define('SMTP_PASS', str_replace(' ', '', (string)(getenv('SMTP_PASS') ?: '')));
define('SMTP_FROM', (string)(getenv('SMTP_FROM') ?: ''));
define('MAIL_DEV_LOG', in_array(strtolower((string)getenv('MAIL_DEV_LOG')), ['1', 'true', 'yes', 'on'], true));
define('OTP_TTL', 600);          // kode berlaku 10 menit
define('OTP_MAX_TRIES', 5);      // salah maksimal 5x, lalu harus minta kode baru
define('OTP_RESEND_WAIT', 60);   // jeda kirim ulang (detik)

// --- Upload ---
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('MAX_IMAGE_MB', 8);
define('MAX_VIDEO_MB', 40);
define('MAX_MEDIA_PER_POST', 10); // foto/video per laporan
