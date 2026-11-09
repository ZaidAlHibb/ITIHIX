<?php

/**
 * Pengiriman email (tanpa library tambahan).
 * Prioritas: SMTP (bila SMTP_HOST diisi) -> fungsi mail() bawaan PHP.
 * Bila keduanya gagal dan MAIL_DEV_LOG = true, isi email ditulis ke includes/mail.log (untuk uji di lokal).
 */

function mail_configured(): bool
{
    return SMTP_HOST !== '' || (bool)MAIL_DEV_LOG;
}

/** Kirim email HTML sederhana. Mengembalikan true bila diterima server email. */
function send_mail(string $to, string $subject, string $html, string $text = ''): bool
{
    $from = SMTP_FROM !== '' ? SMTP_FROM : (SMTP_USER !== '' ? SMTP_USER : 'no-reply@localhost');
    $ok = false;
    if (SMTP_HOST !== '') {
        $ok = smtp_send($from, $to, $subject, $html, $text);
    } elseif (!MAIL_DEV_LOG) {
        $ok = mail_native($from, $to, $subject, $html);
    }
    if (!$ok && MAIL_DEV_LOG) {
        @file_put_contents(__DIR__ . '/mail.log', '[' . date('Y-m-d H:i:s') . "] KE: $to | $subject\n" . ($text ?: strip_tags($html)) . "\n\n", FILE_APPEND);
        return true; // mode uji: dianggap terkirim, baca kodenya di includes/mail.log
    }
    return $ok;
}

function mail_headers(string $from, string $to, string $subject, string $boundary): string
{
    $enc = fn (string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
    $h  = 'From: ' . $enc(APP_NAME) . ' <' . $from . ">\r\n";
    $h .= 'To: <' . $to . ">\r\n";
    $h .= 'Subject: ' . $enc($subject) . "\r\n";
    $h .= 'Date: ' . date('r') . "\r\n";
    $h .= 'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . (parse_url('http://' . substr(strrchr($from, '@') ?: '@localhost', 1), PHP_URL_HOST) ?: 'localhost') . ">\r\n";
    $h .= "MIME-Version: 1.0\r\n";
    $h .= 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n";
    return $h;
}
function mail_body(string $html, string $text, string $boundary): string
{
    $text = $text !== '' ? $text : trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    return '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
         . '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
         . '--' . $boundary . "--\r\n";
}

function mail_native(string $from, string $to, string $subject, string $html): bool
{
    $b = 'b' . bin2hex(random_bytes(8));
    $hdr = mail_headers($from, $to, $subject, $b);
    $hdr = preg_replace('/^(To|Subject): .*\r\n/m', '', $hdr); // mail() menambahkannya sendiri
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', mail_body($html, '', $b), rtrim($hdr));
}

function smtp_send(string $from, string $to, string $subject, string $html, string $text): bool
{
    $secure = strtolower(SMTP_SECURE); // 'ssl' (465) | 'tls' (STARTTLS, 587) | '' (tanpa enkripsi)
    $host = ($secure === 'ssl' ? 'ssl://' : '') . SMTP_HOST;
    $fp = @stream_socket_client($host . ':' . SMTP_PORT, $en, $es, 12, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        return false;
    }
    stream_set_timeout($fp, 12);
    $read = function () use ($fp): string {
        $out = '';
        while (($l = fgets($fp, 515)) !== false) {
            $out .= $l;
            if (isset($l[3]) && $l[3] === ' ') {
                break;
            }
        }
        return $out;
    };
    $cmd = function (string $c, string $ok) use ($fp, $read): bool {
        fwrite($fp, $c . "\r\n");
        return str_starts_with($read(), $ok);
    };
    try {
        if (!str_starts_with($read(), '220')) {
            return false;
        }
        $me = 'localhost';
        if (!$cmd('EHLO ' . $me, '250')) {
            return false;
        }
        if ($secure === 'tls') {
            if (!$cmd('STARTTLS', '220')) {
                return false;
            }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return false;
            }
            if (!$cmd('EHLO ' . $me, '250')) {
                return false;
            }
        }
        if (SMTP_USER !== '') {
            if (!$cmd('AUTH LOGIN', '334') || !$cmd(base64_encode(SMTP_USER), '334') || !$cmd(base64_encode(SMTP_PASS), '235')) {
                return false;
            }
        }
        if (!$cmd('MAIL FROM:<' . $from . '>', '250') || !$cmd('RCPT TO:<' . $to . '>', '250') || !$cmd('DATA', '354')) {
            return false;
        }
        $b = 'b' . bin2hex(random_bytes(8));
        $data = mail_headers($from, $to, $subject, $b) . "\r\n" . mail_body($html, $text, $b);
        $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $data));
        $data = str_replace("\n", "\r\n", $data);
        fwrite($fp, $data . "\r\n.\r\n");
        $ok = str_starts_with($read(), '250');
        @fwrite($fp, "QUIT\r\n");
        return $ok;
    } finally {
        @fclose($fp);
    }
}

/** Email berisi kode verifikasi pendaftaran. */
function send_verification_code(string $to, string $name, string $code): bool
{
    $mins = (int)(OTP_TTL / 60);
    $n = e($name);
    $html = '<div style="font-family:Arial,sans-serif;max-width:440px;margin:auto;padding:24px;background:#111116;color:#f4f2f0;border-radius:16px">'
        . '<h2 style="margin:0 0 8px;color:#fff">Verifikasi email ITIHIX</h2>'
        . '<p style="color:#c9c7ce;line-height:1.6">Halo ' . $n . ', masukkan kode berikut untuk menyelesaikan pendaftaran:</p>'
        . '<div style="font-size:34px;font-weight:800;letter-spacing:10px;text-align:center;background:#17171d;border-radius:12px;padding:16px;margin:18px 0;color:#ff5a66">' . e($code) . '</div>'
        . '<p style="color:#8e8c95;font-size:13px;line-height:1.6">Kode berlaku ' . $mins . ' menit. Jangan bagikan kode ini ke siapa pun, termasuk yang mengaku dari ITIHIX. Jika kamu tidak merasa mendaftar, abaikan email ini.</p></div>';
    $text = "Halo $name,\n\nKode verifikasi ITIHIX kamu: $code\nBerlaku $mins menit. Jangan bagikan kode ini ke siapa pun.\n\nJika kamu tidak merasa mendaftar, abaikan email ini.";
    return send_mail($to, 'Kode verifikasi ITIHIX: ' . $code, $html, $text);
}

/** Email berisi kode untuk mengonfirmasi penggantian email akun. */
function send_email_change_code(string $to, string $name, string $code): bool
{
    $mins = (int)(OTP_TTL / 60);
    $n = e($name);
    $html = '<div style="font-family:Arial,sans-serif;max-width:440px;margin:auto;padding:24px;background:#111116;color:#f4f2f0;border-radius:16px">'
        . '<h2 style="margin:0 0 8px;color:#fff">Konfirmasi email baru ITIHIX</h2>'
        . '<p style="color:#c9c7ce;line-height:1.6">Halo ' . $n . ', masukkan kode berikut di Pengaturan untuk menjadikan alamat ini email akunmu:</p>'
        . '<div style="font-size:34px;font-weight:800;letter-spacing:10px;text-align:center;background:#17171d;border-radius:12px;padding:16px;margin:18px 0;color:#ff5a66">' . e($code) . '</div>'
        . '<p style="color:#8e8c95;font-size:13px;line-height:1.6">Kode berlaku ' . $mins . ' menit. Jangan bagikan kode ini ke siapa pun. Jika kamu tidak meminta penggantian email, abaikan email ini.</p></div>';
    $text = "Halo $name,\n\nKode konfirmasi email baru ITIHIX kamu: $code\nBerlaku $mins menit. Jangan bagikan kode ini ke siapa pun.\n\nJika kamu tidak meminta penggantian email, abaikan email ini.";
    return send_mail($to, 'Kode konfirmasi email ITIHIX: ' . $code, $html, $text);
}

/** Email berisi kode untuk mengatur ulang kata sandi. */
function send_password_reset_code(string $to, string $name, string $code): bool
{
    $mins = (int)(OTP_TTL / 60);
    $n = e($name);
    $html = '<div style="font-family:Arial,sans-serif;max-width:440px;margin:auto;padding:24px;background:#111116;color:#f4f2f0;border-radius:16px">'
        . '<h2 style="margin:0 0 8px;color:#fff">Atur ulang kata sandi ITIHIX</h2>'
        . '<p style="color:#c9c7ce;line-height:1.6">Halo ' . $n . ', masukkan kode berikut untuk membuat kata sandi baru:</p>'
        . '<div style="font-size:34px;font-weight:800;letter-spacing:10px;text-align:center;background:#17171d;border-radius:12px;padding:16px;margin:18px 0;color:#ff5a66">' . e($code) . '</div>'
        . '<p style="color:#8e8c95;font-size:13px;line-height:1.6">Kode berlaku ' . $mins . ' menit. Jangan bagikan kode ini ke siapa pun, termasuk yang mengaku dari ITIHIX. Jika kamu tidak meminta ini, abaikan email ini; kata sandimu tidak berubah.</p></div>';
    $text = "Halo $name,\n\nKode atur ulang kata sandi ITIHIX kamu: $code\nBerlaku $mins menit. Jangan bagikan kode ini ke siapa pun.\n\nJika kamu tidak meminta ini, abaikan email ini; kata sandimu tidak berubah.";
    return send_mail($to, 'Kode atur ulang kata sandi ITIHIX: ' . $code, $html, $text);
}

/** Untuk akun yang hanya masuk lewat Google (belum punya kata sandi): arahkan ke tombol Google. */
function send_google_only_notice(string $to, string $name): bool
{
    $n = e($name);
    $html = '<div style="font-family:Arial,sans-serif;max-width:440px;margin:auto;padding:24px;background:#111116;color:#f4f2f0;border-radius:16px">'
        . '<h2 style="margin:0 0 8px;color:#fff">Akunmu masuk lewat Google</h2>'
        . '<p style="color:#c9c7ce;line-height:1.6">Halo ' . $n . ', ada permintaan atur ulang kata sandi untuk email ini. Akun ITIHIX-mu terhubung dengan Google dan belum memakai kata sandi, jadi cukup klik <b>Lanjutkan dengan Google</b> di halaman masuk.</p>'
        . '<p style="color:#8e8c95;font-size:13px;line-height:1.6">Jika kamu tidak meminta ini, abaikan email ini.</p></div>';
    $text = "Halo $name,\n\nAkun ITIHIX kamu masuk lewat Google dan belum memakai kata sandi. Gunakan tombol \"Lanjutkan dengan Google\" di halaman masuk.\n\nJika kamu tidak meminta ini, abaikan email ini.";
    return send_mail($to, 'Akun ITIHIX kamu masuk lewat Google', $html, $text);
}

/** Sembunyikan sebagian email: budi.santoso@gmail.com -> b***@gmail.com */
function mask_email(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false || $at < 1) {
        return $email;
    }
    return mb_substr($email, 0, 1) . str_repeat('*', max(2, min(6, $at - 1))) . substr($email, $at);
}

/**
 * Mulai alur lupa kata sandi. Hasilnya selalu berbentuk sama (ada/tidaknya akun tidak bocor ke pengunjung);
 * kode hanya dibuat & dikirim bila akun aktif dan pembatas laju per email belum terlampaui.
 */
function start_password_reset(string $email): array
{
    $now = time();
    $p = ['email' => $email, 'uid' => null, 'code_hash' => null, 'expires' => $now + OTP_TTL, 'tries' => 0, 'sent_at' => $now];
    $u = qone('SELECT id, display_name, password_hash, status FROM users WHERE email = ? LIMIT 1', [$email]);
    if (!$u || $u['status'] !== 'active') {
        return $p;
    }
    if (!rate_limit('reset_email', 3, 3600, 'id' . sha1($email))) {
        return $p;
    }
    $uid = (int)$u['id'];
    if (!$u['password_hash']) {
        send_google_only_notice($email, $u['display_name']);
        return $p;
    }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    if (send_password_reset_code($email, $u['display_name'], $code)) {
        $p['uid'] = $uid;
        $p['code_hash'] = password_hash($code, PASSWORD_DEFAULT);
        log_activity('reset_requested', 'user', $uid, 'Meminta kode atur ulang kata sandi', $uid);
    } else {
        error_log('[ITIHIX reset] gagal mengirim email atur ulang untuk user ' . $uid);
    }
    return $p;
}
