<?php
$pageTitle = 'Pengaturan';
$active = 'settings';
require __DIR__ . '/includes/header.php';
$errors = [];
$meSaved = $me;

if (is_post()) {
    csrf_check();
    $sec = $_POST['section'] ?? '';
    $uid = (int)$me['id'];
    if ($sec === 'account') {
        $name = trim((string)($_POST['display_name'] ?? ''));
        $user = trim((string)($_POST['username'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) {
            $errors[] = 'Nama tampilan wajib diisi (maks. 60 karakter).';
        }
        if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $user)) {
            $errors[] = 'Nama pengguna 3–30 karakter: huruf, angka, atau underscore.';
        }
        // Nama yang sudah dipakai akunmu sendiri tetap boleh (agar akun lama dengan nama dicadangkan bisa menyimpan info lain)
        if (!$errors && strcasecmp($user, (string)$me['username']) !== 0 && username_reserved($user)) {
            $errors[] = 'Nama pengguna ini dicadangkan oleh sistem. Pilih yang lain.';
        }
        if (!$errors && qval('SELECT 1 FROM users WHERE username = ? AND id <> ?', [$user, $uid])) {
            $errors[] = 'Nama pengguna sudah dipakai.';
        }
        if (!$errors) {
            q('UPDATE users SET display_name=?, username=? WHERE id=?', [$name, $user, $uid]);
            log_activity('settings_account', 'user', $uid, 'Mengubah info akun');
            flash('Informasi akun disimpan.');
            redirect('settings.php?p=akun');
        }
    } elseif ($sec === 'email_send') {          // ganti email, langkah 1: kirim kode ke email baru
        $mail = mb_strtolower(trim((string)($_POST['new_email'] ?? '')));
        $pe = $_SESSION['pending_email'] ?? null;
        $wait = $pe ? OTP_RESEND_WAIT - (time() - (int)$pe['sent_at']) : 0;
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Format email tidak valid.';
        } elseif ($mail === mb_strtolower((string)$me['email'])) {
            $errors[] = 'Itu sudah menjadi email akunmu.';
        } elseif (qval('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$mail, $uid])) {
            $errors[] = 'Email sudah dipakai akun lain.';
        } elseif ($wait > 0) {
            $errors[] = 'Tunggu ' . $wait . ' detik sebelum meminta kode baru.';
        } elseif (!rate_limit('email_change', 5, 3600)) {
            $errors[] = 'Terlalu banyak permintaan kode. Coba lagi nanti.';
        } else {
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            if (send_email_change_code($mail, (string)$me['display_name'], $code)) {
                $_SESSION['pending_email'] = ['uid' => $uid, 'email' => $mail, 'code_hash' => password_hash($code, PASSWORD_DEFAULT), 'expires' => time() + OTP_TTL, 'tries' => 0, 'sent_at' => time()];
                flash('Kode verifikasi dikirim ke ' . $mail . '.');
                redirect('settings.php?p=akun');
            } else {
                $errors[] = 'Gagal mengirim email. Coba lagi sebentar lagi.';
            }
        }
    } elseif ($sec === 'email_verify') {        // langkah 2: masukkan kode, baru email diganti
        $pe = $_SESSION['pending_email'] ?? null;
        $input = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
        if (!$pe || (int)$pe['uid'] !== $uid) {
            $errors[] = 'Belum ada permintaan ganti email. Kirim kode dulu.';
        } elseif (time() > (int)$pe['expires']) {
            $errors[] = 'Kode sudah kedaluwarsa. Kirim ulang kode.';
        } elseif ((int)$pe['tries'] >= OTP_MAX_TRIES) {
            $errors[] = 'Terlalu banyak kode salah. Kirim ulang kode untuk kode baru.';
        } elseif (strlen($input) !== 6) {
            $errors[] = 'Masukkan 6 digit kode dari email.';
        } elseif (!password_verify($input, $pe['code_hash'])) {
            $pe['tries']++;
            $_SESSION['pending_email'] = $pe;
            $left = OTP_MAX_TRIES - $pe['tries'];
            $errors[] = $left > 0 ? 'Kode salah. Sisa percobaan: ' . $left . '.' : 'Terlalu banyak kode salah. Kirim ulang kode untuk kode baru.';
        } elseif (qval('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$pe['email'], $uid])) {
            unset($_SESSION['pending_email']);
            $errors[] = 'Email sudah dipakai akun lain.';
        } else {
            q('UPDATE users SET email=? WHERE id=?', [$pe['email'], $uid]);
            unset($_SESSION['pending_email']);
            log_activity('settings_email', 'user', $uid, 'Mengganti email (terverifikasi)');
            flash('Email berhasil diperbarui.');
            redirect('settings.php?p=akun');
        }
    } elseif ($sec === 'email_cancel') {
        unset($_SESSION['pending_email']);
        redirect('settings.php?p=akun');
    } elseif ($sec === 'password') {
        $cur = (string)($_POST['current'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        if ($me['password_hash'] && !password_verify($cur, $me['password_hash'])) {
            $errors[] = 'Kata sandi saat ini salah.';
        } elseif (strlen($new) < 8) {
            $errors[] = 'Kata sandi baru minimal 8 karakter.';
        } elseif ($new !== ($_POST['new2'] ?? '')) {
            $errors[] = 'Konfirmasi kata sandi baru tidak sama.';
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $uid]);
            log_activity('settings_password', 'user', $uid, 'Mengganti kata sandi');
            flash('Kata sandi diperbarui.');
            redirect('settings.php?p=keamanan');
        }
    } elseif ($sec === 'email_enable') {        // aktifkan login email + kata sandi (untuk akun yang daftar lewat Google)
        $new = (string)($_POST['new'] ?? '');
        if ($me['password_hash']) {
            $errors[] = 'Login email sudah aktif.';
        } elseif (strlen($new) < 8) {
            $errors[] = 'Kata sandi minimal 8 karakter.';
        } elseif ($new !== ($_POST['new2'] ?? '')) {
            $errors[] = 'Konfirmasi kata sandi tidak sama.';
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $uid]);
            log_activity('settings_email_login_on', 'user', $uid, 'Mengaktifkan login email');
            flash('Login email aktif. Kamu bisa masuk dengan email dan kata sandi ini.');
            redirect('settings.php?p=tertaut');
        }
    } elseif ($sec === 'email_disable' || $sec === 'google_unlink') {
        // Wajib tersisa satu cara masuk: email dicabut hanya bila Google tertaut, Google dicabut hanya bila login email aktif.
        $isG = $sec === 'google_unlink';
        $cur = (string)($_POST['current'] ?? '');
        if (!rate_limit('unlink_pw', 6, 600)) {
            $errors[] = 'Terlalu banyak percobaan. Coba lagi nanti.';
        } elseif ($isG && !$me['password_hash']) {
            $errors[] = 'Google tidak bisa diputus karena itu satu-satunya cara masuk. Aktifkan login email dulu.';
        } elseif (!$isG && !$me['google_id']) {
            $errors[] = 'Login email tidak bisa dinonaktifkan karena itu satu-satunya cara masuk. Tautkan Google dulu.';
        } elseif (!$me['password_hash'] || !password_verify($cur, $me['password_hash'])) {
            $errors[] = 'Kata sandi salah.';
        } else {
            q($isG ? 'UPDATE users SET google_id = NULL WHERE id = ?' : 'UPDATE users SET password_hash = NULL WHERE id = ?', [$uid]);
            log_activity($isG ? 'unlink_google' : 'settings_email_login_off', 'user', $uid, $isG ? 'Memutus akun Google' : 'Menonaktifkan login email');
            flash($isG ? 'Akun Google diputus. Masuk sekarang lewat email dan kata sandi.' : 'Login email dinonaktifkan. Masuk sekarang lewat Google.');
            redirect('settings.php?p=tertaut');
        }
    } elseif ($sec === 'privacy') {
        q('UPDATE users SET show_level=?, allow_messages=?, hide_reposts=?, hide_likes=?, hide_comments=? WHERE id=?', [(int)!empty($_POST['show_level']), (int)!empty($_POST['allow_messages']), (int)!empty($_POST['hide_reposts']), (int)!empty($_POST['hide_likes']), (int)!empty($_POST['hide_comments']), $uid]);
        log_activity('settings_privacy', 'user', $uid, 'Mengubah privasi');
        flash('Pengaturan privasi disimpan.');
        redirect('settings.php?p=privasi');
    } elseif ($sec === 'prefs') {
        // Tiap panel (Notifikasi / Tampilan) hanya mengirim kolomnya sendiri; kolom yang tidak dikirim dibiarkan.
        $set = [];
        $vals = [];
        foreach (['notif_reports', 'notif_weekly', 'reduce_motion'] as $k) {
            if (array_key_exists($k, $_POST)) {
                $set[] = $k . '=?';
                $vals[] = (int)!empty($_POST[$k]);
            }
        }
        if ($set) {
            $vals[] = $uid;
            q('UPDATE users SET ' . implode(', ', $set) . ' WHERE id=?', $vals);
        }
        $back = in_array($_POST['panel'] ?? '', ['notifikasi', 'tampilan'], true) ? $_POST['panel'] : 'notifikasi';
        flash('Preferensi disimpan.');
        redirect('settings.php?p=' . $back);
    }
    $meSaved = $me; // data tersimpan di DB: dipakai untuk bagian yang tidak boleh ikut berubah (menu kiri, edit profil)
    foreach (['display_name', 'username'] as $k) {
        if (isset($_POST[$k])) {
            $me[$k] = (string)$_POST[$k];
        }
    } // isian form akun dikembalikan bila ada error
}
$panelKeys = ['profil', 'akun', 'tertaut', 'keamanan', 'privasi', 'notifikasi', 'tampilan'];
$openPanel = (string)($_GET['p'] ?? '');
if (is_post() && $errors) {
    $openPanel = ['account' => 'akun', 'email_send' => 'akun', 'email_verify' => 'akun', 'password' => 'keamanan', 'email_enable' => 'tertaut', 'email_disable' => 'tertaut', 'google_unlink' => 'tertaut', 'privacy' => 'privasi', 'prefs' => 'notifikasi'][$_POST['section'] ?? ''] ?? '';
}
if (!in_array($openPanel, $panelKeys, true)) {
    $openPanel = '';
}
$pfeColors  = ['#ffe0e2', '#d9e8ff', '#fce2c5', '#e6dcff', '#d9f2e6', '#fff0c2', '#ffd9f0', '#dcdcdc'];
$pfeBanners = array_merge($pfeColors, ['#e53945', '#3b82f6', '#22a06b', '#7c5cff']);
$curColor   = in_array($meSaved['avatar_color'], $pfeColors, true) ? $meSaved['avatar_color'] : '#ffe0e2';
$curBanner  = preg_match('/^#[0-9a-f]{6}$/i', (string)($meSaved['banner_color'] ?? '')) ? strtolower($meSaved['banner_color']) : '';
$extraJs    = ($extraJs ?? '') . '<script src="' . e(url('assets/js/profile-edit.js')) . '?v=' . (int)@filemtime(__DIR__ . '/assets/js/profile-edit.js') . '"></script>'
            . '<script src="' . e(url('assets/js/settings.js')) . '?v=' . (int)@filemtime(__DIR__ . '/assets/js/settings.js') . '"></script>';
// Pesan error tampil sebagai toast (bukan div di halaman), jadi tidak menggeser layout yang terkunci
if ($errors) {
    $extraJs .= '<script>setTimeout(function(){ if (window.ITIHIX) ITIHIX.toast(' . json_encode(implode(' ', $errors), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '); }, 200);</script>';
}
function toggle_row(string $name, string $title, string $desc, $on): string
{
    $v = $on ? '1' : '0';
    return '<div class="setting-row"><div><strong>' . e($title) . '</strong><small>' . e($desc) . '</small></div>'
        . '<input type="hidden" id="f_' . $name . '" name="' . $name . '" value="' . $v . '">'
        . '<button type="button" class="toggle ' . ($on ? 'on' : '') . '" data-input="f_' . $name . '" aria-label="' . e($title) . '"></button></div>';
}
?>
<section id="page-settings" class="page">
  <div class="page-heading"><div><h1>Pengaturan</h1><div class="sub">Kelola akun, profil, privasi, notifikasi, dan tampilan.</div></div></div>

  <?php
    $stItems = [
      'profil'     => ['user',     'Edit profil',          'Foto, nama, bio, dan warna banner'],
      'akun'       => ['envelope', 'Informasi akun',       'Nama pengguna dan email'],
      'tertaut'    => ['link',     'Akun tertaut',         'Login dengan email dan Google'],
      'keamanan'   => ['shield',   'Kata sandi & keamanan','Ganti kata sandi'],
      'privasi'    => ['lock',     'Privasi',              'Repost, disukai, komentar, level, pesan'],
      'notifikasi' => ['bell',     'Notifikasi',           'Status laporan dan ringkasan mingguan'],
      'tampilan'   => ['sun',      'Tampilan',             'Mode terang / gelap dan animasi'],
    ];
?>
  <div class="st-wrap" id="stWrap" data-open="<?= e($openPanel) ?>" data-view="list">

    <aside class="card st-nav" aria-label="Menu pengaturan">
      <a class="st-me" href="<?= e(url('profile.php')) ?>">
        <?= avatar_html($meSaved, '', 'avatar') ?>
        <div class="st-me-t"><strong><?= e($meSaved['display_name']) ?></strong><small>@<?= e($meSaved['username']) ?> · Lihat profil</small></div>
        <span class="st-chev"><?= icon('caret-right') ?></span>
      </a>
      <nav class="st-list" role="tablist">
        <?php foreach ($stItems as $k => [$ic, $tt, $sb]): ?>
        <button type="button" class="st-item" role="tab" data-st-tab="<?= $k ?>" aria-controls="st-<?= $k ?>">
          <span class="st-ico"><?= icon($ic) ?></span>
          <span class="st-txt"><strong><?= e($tt) ?></strong><small><?= e($sb) ?></small></span>
          <span class="st-chev"><?= icon('caret-right') ?></span>
        </button>
        <?php endforeach; ?>
      </nav>
    </aside>

    <div class="st-panels">

      <section class="card st-panel" id="st-profil" role="tabpanel" data-panel="profil">
        <div class="st-head"><button type="button" class="st-back" data-st-back aria-label="Kembali"><?= icon('arrow-right') ?></button><div><h2>Edit profil</h2><div class="sub">Foto, nama, bio, dan warna yang tampil di profil publikmu.</div></div></div>
        <div class="pfe-inline" id="pfeModal" data-inline>
      <div class="pfe-photo">
        <div class="pf-cover pfe-bn<?= $curBanner ? ' has-color' : '' ?>" id="pfeBn"<?= $curBanner ? ' style="--bn:' . e($curBanner) . '"' : '' ?>></div>
        <div class="pfe-preview" id="pfePreview" data-initial="<?= e(initials($meSaved['display_name'])) ?>" data-has-photo="<?= $meSaved['avatar_path'] ? 1 : 0 ?>"><?= avatar_html($meSaved, '', 'pfe-av') ?></div>

        <div class="gate hidden pfe-cropwin" id="pfeCrop" role="dialog" aria-modal="true" aria-labelledby="pfeCropTitle">
          <div class="gate-box card pfe-cropbox">
            <div class="pfe-head"><h3 id="pfeCropTitle">Atur foto profil</h3><button class="gate-x" type="button" id="pfeCropX" aria-label="Tutup"><?= icon('xmark') ?></button></div>
            <div class="pfe-crop">
              <div class="pfe-stage" id="pfeStage">
                <canvas id="pfeCanvas" tabindex="0" aria-label="Area potong foto. Geser untuk memposisikan, scroll atau cubit untuk zoom."></canvas>
                <div class="pfe-mask"></div><div class="pfe-ring"></div>
              </div>
              <div class="pfe-zoomrow">
                <button class="pfe-zbtn" type="button" id="pfeZoomOut" aria-label="Perkecil">−</button>
                <input type="range" id="pfeZoom" min="1" max="4" step="0.01" value="1" aria-label="Zoom">
                <button class="pfe-zbtn" type="button" id="pfeZoomIn" aria-label="Perbesar">+</button>
              </div>
              <div class="pfe-hint">Geser foto untuk memposisikan · scroll atau cubit untuk zoom</div>
            </div>
            <div class="pfe-foot">
              <button class="btn btn-light" type="button" id="pfeReset"><?= icon('undo') ?> Reset</button>
              <button class="btn btn-light" type="button" id="pfeCropCancel">Batal</button>
              <button class="btn btn-primary" type="button" id="pfeCropApply"><?= icon('check') ?> Terapkan</button>
            </div>
          </div>
        </div>

        <div class="pfe-photo-actions">
          <button class="btn btn-light" type="button" id="pfePick"><?= icon('image') ?> <?= $meSaved['avatar_path'] ? 'Ganti foto' : 'Unggah foto' ?></button>
          <?php if ($meSaved['avatar_path']): ?><button class="btn btn-light pfe-del" type="button" id="pfeRemove"><?= icon('trash') ?> <span>Hapus foto</span></button><?php endif; ?>
        </div>
        <input type="file" id="pfeFile" accept="image/*" hidden>
      </div>

      <label class="field-label" for="pfeName">Nama tampilan</label>
      <input class="field" id="pfeName" maxlength="60" value="<?= e($meSaved['display_name']) ?>" autocomplete="off">

      <label class="field-label" for="pfeBio">Bio <span class="pfe-count" id="pfeBioN">0/280</span></label>
      <textarea class="textarea" id="pfeBio" maxlength="280" style="min-height:80px"><?= e($meSaved['bio']) ?></textarea>

      <div id="pfeColorWrap">
        <label class="field-label">Warna avatar <span class="pfe-count">dipakai bila tanpa foto</span></label>
        <div class="pfe-colors" id="pfeColors" data-current="<?= e($curColor) ?>">
          <?php foreach ($pfeColors as $c): ?><label><input type="radio" name="pfe_color" value="<?= $c ?>" <?= $curColor === $c ? 'checked' : '' ?>><span style="background:<?= $c ?>"></span></label><?php endforeach; ?>
        </div>
      </div>
      <div id="pfeBannerWrap">
        <label class="field-label">Warna banner <span class="pfe-count">tampil di atas profil</span></label>
        <div class="pfe-swatches" id="pfeBanners" data-current="<?= e($curBanner) ?>">
          <button type="button" class="pfe-sw pfe-sw-def" data-color="" title="Bawaan" aria-label="Warna bawaan"></button>
          <?php foreach ($pfeBanners as $c): ?><button type="button" class="pfe-sw" data-color="<?= $c ?>" style="background:<?= $c ?>" aria-label="Warna <?= $c ?>"></button><?php endforeach; ?>
          <label class="pfe-sw pfe-sw-custom" id="pfeBannerCustomLabel" title="Warna kustom"><input type="color" id="pfeBannerCustom" value="<?= $curBanner ?: '#e53945' ?>" aria-label="Warna kustom"></label>
        </div>
      </div>

      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px">
        <button class="btn btn-light" type="button" data-pfe-close>Batal</button>
        <button class="btn btn-primary" type="button" id="pfeSave">Simpan profil</button>
      </div>
    </div>
      </section>

      <section class="card st-panel" id="st-akun" role="tabpanel" data-panel="akun">
        <div class="st-head"><button type="button" class="st-back" data-st-back aria-label="Kembali"><?= icon('arrow-right') ?></button><div><h2>Informasi akun</h2><div class="sub">Dipakai untuk masuk dan menerima pemberitahuan.</div></div></div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="section" value="account">
          <label class="field-label" for="stName">Nama tampilan</label><input class="field" id="stName" name="display_name" maxlength="60" required value="<?= e($me['display_name']) ?>">
          <label class="field-label" for="stUser">Nama pengguna</label><input class="field" id="stUser" name="username" maxlength="30" required value="<?= e($me['username']) ?>">
          <div class="st-hint">3–30 karakter: huruf, angka, atau underscore.</div>
          <div class="st-actions"><button class="btn btn-primary">Simpan akun</button></div>
        </form>

        <?php $pe = $_SESSION['pending_email'] ?? null;
if ($pe && (int)$pe['uid'] !== (int)$me['id']) {
    $pe = null;
} ?>
        <div class="st-mail">
          <label class="field-label" for="stMail"><?= $meSaved['password_hash'] || !$meSaved['google_id'] ? 'Email' : 'Email (dari akun Google)' ?></label>
          <input class="field" id="stMail" type="email" value="<?= e($meSaved['email']) ?>" readonly>
          <?php if ($pe): ?>
            <div class="st-hint">Kode 6 digit dikirim ke <strong><?= e($pe['email']) ?></strong>. Email baru berlaku setelah kode diverifikasi.</div>
            <form method="post" class="st-mailform"><?= csrf_field() ?><input type="hidden" name="section" value="email_verify">
              <input class="field st-code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" placeholder="••••••" aria-label="Kode verifikasi" required>
              <button class="btn btn-primary">Verifikasi</button>
            </form>
            <div class="st-mailrow">
              <form method="post"><?= csrf_field() ?><input type="hidden" name="section" value="email_send"><input type="hidden" name="new_email" value="<?= e($pe['email']) ?>"><button class="btn btn-light">Kirim ulang kode</button></form>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="section" value="email_cancel"><button class="btn btn-light">Batal / ganti email lain</button></form>
            </div>
          <?php else: ?>
            <div class="st-hint">Untuk mengganti email, kami kirim kode verifikasi ke email barunya dulu.</div>
            <form method="post" class="st-mailform"><?= csrf_field() ?><input type="hidden" name="section" value="email_send">
              <input class="field" type="email" name="new_email" placeholder="Email baru" autocomplete="email" required>
              <button class="btn btn-primary">Kirim kode</button>
            </form>
          <?php endif; ?>
        </div>
      </section>

      <?php
        $hasEmailLogin = !empty($meSaved['password_hash']);
        $hasGoogle = !empty($meSaved['google_id']);
?>
      <section class="card st-panel" id="st-tertaut" role="tabpanel" data-panel="tertaut">
        <div class="st-head"><button type="button" class="st-back" data-st-back aria-label="Kembali"><?= icon('arrow-right') ?></button><div><h2>Akun tertaut</h2><div class="sub">Pilih cara masuk ke ITIHIX. Kamu bisa memakai email dan Google sekaligus, tapi minimal satu harus aktif.</div></div></div>

        <div class="st-link">
          <div class="st-link-h"><strong>Google</strong><?= $hasGoogle ? '<span class="st-badge" style="margin:0">' . icon('check') . ' Terhubung</span>' : '<span class="tag tag-muted">Belum terhubung</span>' ?></div>
          <?php if ($hasGoogle): ?>
            <div class="st-hint">Kamu bisa masuk dengan tombol “Lanjutkan dengan Google”.</div>
            <?php if ($hasEmailLogin): ?>
              <form method="post" class="st-mailform" onsubmit="return confirm('Putuskan akun Google dari ITIHIX?')"><?= csrf_field() ?><input type="hidden" name="section" value="google_unlink">
                <input class="field" type="password" name="current" placeholder="Kata sandi untuk konfirmasi" autocomplete="current-password" required>
                <button class="btn btn-light">Putuskan Google</button>
              </form>
            <?php else: ?>
              <div class="notice" style="margin-top:10px">Google belum bisa diputus karena itu satu-satunya cara masuk akunmu. Aktifkan login email di bawah dulu.</div>
            <?php endif; ?>
          <?php else: ?>
            <div class="st-hint">Tautkan agar bisa masuk dengan satu ketukan lewat Google.</div>
            <div class="st-actions" style="justify-content:flex-start"><a class="btn btn-primary" href="<?= e(url('google_login.php?link=1')) ?>">Hubungkan Google</a></div>
          <?php endif; ?>
        </div>

        <div class="st-link">
          <div class="st-link-h"><strong>Email &amp; kata sandi</strong><?= $hasEmailLogin ? '<span class="st-badge" style="margin:0">' . icon('check') . ' Aktif</span>' : '<span class="tag tag-muted">Belum aktif</span>' ?></div>
          <?php if ($hasEmailLogin): ?>
            <div class="st-hint">Masuk dengan <strong><?= e($meSaved['email']) ?></strong> (atau nama penggunamu) dan kata sandi. Ganti kata sandi di menu “Kata sandi &amp; keamanan”.</div>
            <?php if ($hasGoogle): ?>
              <form method="post" class="st-mailform" onsubmit="return confirm('Nonaktifkan login email? Kamu hanya bisa masuk lewat Google.')"><?= csrf_field() ?><input type="hidden" name="section" value="email_disable">
                <input class="field" type="password" name="current" placeholder="Kata sandi untuk konfirmasi" autocomplete="current-password" required>
                <button class="btn btn-light">Nonaktifkan login email</button>
              </form>
            <?php else: ?>
              <div class="notice" style="margin-top:10px">Login email belum bisa dinonaktifkan karena itu satu-satunya cara masuk akunmu. Tautkan Google dulu bila ingin menggantinya.</div>
            <?php endif; ?>
          <?php else: ?>
            <div class="st-hint">Akunmu terdaftar lewat Google. Buat kata sandi agar bisa masuk juga dengan email <strong><?= e($meSaved['email']) ?></strong>.</div>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="section" value="email_enable">
              <label class="field-label" for="stEn1">Kata sandi baru</label><input class="field" id="stEn1" type="password" name="new" minlength="8" required autocomplete="new-password">
              <label class="field-label" for="stEn2">Ulangi kata sandi</label><input class="field" id="stEn2" type="password" name="new2" minlength="8" required autocomplete="new-password">
              <div class="st-hint">Minimal 8 karakter. Email bisa diganti di menu “Informasi akun”.</div>
              <div class="st-actions"><button class="btn btn-primary">Aktifkan login email</button></div>
            </form>
          <?php endif; ?>
        </div>
      </section>

      <section class="card st-panel" id="st-keamanan" role="tabpanel" data-panel="keamanan">
        <div class="st-head"><button type="button" class="st-back" data-st-back aria-label="Kembali"><?= icon('arrow-right') ?></button><div><h2>Kata sandi &amp; keamanan</h2><div class="sub"><?= $me['password_hash'] ? 'Gunakan kata sandi yang kuat dan unik.' : 'Akunmu masuk lewat Google. Aktifkan login email di menu “Akun tertaut” untuk membuat kata sandi.' ?></div></div></div>
        <?php if ($me['google_id']): ?><div class="st-badge"><?= icon('check') ?> Akun Google terhubung</div><?php endif; ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="section" value="password">
          <?php if ($me['password_hash']): ?><label class="field-label" for="stCur">Kata sandi saat ini</label><input class="field" id="stCur" type="password" name="current" required autocomplete="current-password"><?php endif; ?>
          <label class="field-label" for="stNew">Kata sandi baru</label><input class="field" id="stNew" type="password" name="new" minlength="8" required autocomplete="new-password">
          <div class="st-hint">Minimal 8 karakter.</div>
          <label class="field-label" for="stNew2">Ulangi kata sandi baru</label><input class="field" id="stNew2" type="password" name="new2" minlength="8" required autocomplete="new-password">
          <div class="st-actions"><button class="btn btn-primary">Perbarui kata sandi</button></div>
        </form>
      </section>

      <section class="card st-panel" id="st-privasi" role="tabpanel" data-panel="privasi">
        <div class="st-head"><button type="button" class="st-back" data-st-back aria-label="Kembali"><?= icon('arrow-right') ?></button><div><h2>Privasi</h2><div class="sub">Kamu yang menentukan apa yang terlihat publik.</div></div></div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="section" value="privacy">
          <div class="st-sub">Aktivitas di profil</div>
          <?= toggle_row('hide_reposts', 'Repost privat', 'Tab Repost hanya bisa dilihat olehmu', $me['hide_reposts'] ?? 0) ?>
          <?= toggle_row('hide_likes', 'Disukai privat', 'Tab Disukai hanya bisa dilihat olehmu', $me['hide_likes'] ?? 0) ?>
          <?= toggle_row('hide_comments', 'Komentar privat', 'Tab Komentar hanya bisa dilihat olehmu', $me['hide_comments'] ?? 0) ?>
          <div class="st-sub">Lainnya</div>
          <?= toggle_row('show_level', 'Tampilkan level di postingan', 'Perlihatkan level penjaga di feed dan profil', $me['show_level']) ?>
          <?= toggle_row('allow_messages', 'Izinkan pesan dari komunitas', 'Pengguna yang saling mengikuti denganmu dapat mengirim pesan', $me['allow_messages']) ?>
          <div class="st-actions"><button class="btn btn-primary">Simpan privasi</button></div>
        </form>
      </section>

      <section class="card st-panel" id="st-notifikasi" role="tabpanel" data-panel="notifikasi">
        <div class="st-head"><button type="button" class="st-back" data-st-back aria-label="Kembali"><?= icon('arrow-right') ?></button><div><h2>Notifikasi</h2><div class="sub">Sesuaikan cara ITIHIX memberi kabar.</div></div></div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="section" value="prefs"><input type="hidden" name="panel" value="notifikasi">
          <?= toggle_row('notif_reports', 'Notifikasi laporan', 'Status moderasi dan tanggapan laporan', $me['notif_reports']) ?>
          <?= toggle_row('notif_weekly', 'Ringkasan edukasi mingguan', 'Tips keamanan digital terbaru', $me['notif_weekly']) ?>
          <div class="st-actions"><button class="btn btn-primary">Simpan notifikasi</button></div>
        </form>
      </section>

      <section class="card st-panel" id="st-tampilan" role="tabpanel" data-panel="tampilan">
        <div class="st-head"><button type="button" class="st-back" data-st-back aria-label="Kembali"><?= icon('arrow-right') ?></button><div><h2>Tampilan</h2><div class="sub">Atur tema dan animasi antarmuka.</div></div></div>
        <div class="setting-row st-theme-row"><div><strong>Tema</strong><small>Berlaku langsung di perangkat ini</small></div>
          <div class="st-seg" id="stTheme" role="group" aria-label="Tema">
            <button type="button" data-theme="light"><?= icon('sun') ?> Terang</button>
            <button type="button" data-theme="dark"><?= icon('moon') ?> Gelap</button>
          </div>
        </div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="section" value="prefs"><input type="hidden" name="panel" value="tampilan">
          <?= toggle_row('reduce_motion', 'Mode hemat gerakan', 'Kurangi animasi antarmuka', $me['reduce_motion']) ?>
          <div class="st-actions"><button class="btn btn-primary">Simpan tampilan</button></div>
        </form>
      </section>

    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>