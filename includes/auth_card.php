<?php
/**
 * Halaman masuk & daftar (mandiri, tanpa header/sidebar aplikasi).
 * Variabel dari pemanggil: $mode ('login'|'register'), $error, $pageTitle
 */
$view = $view ?? '';
$isForgot = ($view === 'forgot');
$isReset = ($view === 'reset');
$isAux = $isForgot || $isReset;           // halaman lupa / atur ulang kata sandi
$isLogin = ($mode === 'login') && !$isAux;
$isVerify = !empty($verify);
$flash = flash();
$old = fn (string $k) => e($_POST[$k] ?? '');
$logo = e(url('assets/img/logo.png'));
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0b0b0e">
<meta name="color-scheme" content="dark">
<title><?= e($pageTitle ?? 'Masuk') ?> — ITIHIX</title>
<link rel="icon" type="image/png" href="<?= $logo ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/auth.css')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/auth.css') ?>">
<script>
(function () { // arah transisi dari halaman sebelumnya (masuk <-> daftar)
  try {
    var d = sessionStorage.getItem('itihix-auth-dir');
    if (d) { sessionStorage.removeItem('itihix-auth-dir'); document.documentElement.setAttribute('data-dir', d); }
  } catch (e) {}
})();
</script>
</head>
<body data-base="<?= e(BASE_URL) ?>">
<?php
$ic = [
  'user'  => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/>',
  'at'    => '<circle cx="12" cy="12" r="4"/><path d="M16 12v1.5a2.5 2.5 0 0 0 5 0V12a9 9 0 1 0-3.5 7.1"/>',
  'mail'  => '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="m4 7 8 6 8-6"/>',
  'lock'  => '<rect x="4" y="10" width="16" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
  'eye'   => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
  'eyeoff' => '<path d="M3 3l18 18"/><path d="M10.6 6.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4M6.5 6.9C3.7 8.7 2 12 2 12s3.6 7 10 7c1.6 0 3-.4 4.3-1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
  'back'  => '<path d="M15 6l-6 6 6 6"/>',
  'next'  => '<path d="M9 6l6 6-6 6"/>',
  'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.8 2.8L16 9.8"/>',
];
$svg = fn (string $n, string $cls = 'ico') => '<svg class="' . $cls . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $ic[$n] . '</svg>';
$isReg = !$isLogin && !$isVerify && !$isAux;
$startS2 = $isReg && !empty($error) && stripos($error, 'sandi') !== false;
?>
<div class="auth auth-<?= e($mode) ?>">

  <main class="auth-left">

    <!-- Hero khusus mobile: logo tersorot lampu, selaras dengan panggung di desktop -->
    <header class="m-top">
      <div class="m-fx" aria-hidden="true"><div class="m-glow"></div><div class="m-beam"></div><div class="m-lamp"></div></div>
      <a class="m-brand" href="<?= e(url('index.php')) ?>" aria-label="ITIHIX, ke beranda">
        <img src="<?= $logo ?>" alt="" width="76" height="76"><b>ITIHIX</b>
      </a>
    </header>

    <div class="auth-inner">
      <?php if ($isReg): ?>
      <div class="steps" id="steps" aria-live="polite"><span class="s on"></span><span class="s"></span><em>Langkah 1 dari 2</em></div>
      <?php endif; ?>

      <h1><?= $isForgot ? 'Lupa kata sandi?' : ($isReset ? 'Cek emailmu.' : ($isVerify ? 'Cek emailmu.' : ($isLogin ? 'Cek dulu sebelum klik.' : 'Gabung jadi penjaga.'))) ?></h1>
      <p class="lead"><?= $isForgot
        ? 'Masukkan email akunmu. Kami kirim kode 6 digit untuk membuat kata sandi baru.'
        : ($isReset
        ? 'Jika ' . e($maskedEmail ?? '') . ' terdaftar, kami sudah mengirim kode 6 digit. Masukkan kode dan kata sandi barumu.'
        : ($isVerify
        ? 'Kami mengirim 6 digit kode ke ' . e($maskedEmail ?? '') . '. Masukkan kodenya untuk menyelesaikan pendaftaran.'
        : ($isLogin
        ? 'Masuk untuk melapor, memeriksa pesan dengan ItihScan AI, dan belajar mengenali penipuan.'
        : 'Buat akun untuk melapor penipuan, mengumpulkan XP, dan ikut menjaga komunitas.'))) ?></p>

      <div class="card">
        <?php if (!empty($error)): ?><div class="alert err" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if (!empty($flash)): ?><div class="alert <?= $flash['t'] === 'err' ? 'err' : 'ok' ?>" role="status"><?= e($flash['m']) ?></div><?php endif; ?>
        <?php if (!empty($_GET['msg'])): ?><div class="alert ok" role="status"><?= e($_GET['msg']) ?></div><?php endif; ?>
        <?php if (!empty($info)): ?><div class="alert ok" role="status"><?= e($info) ?></div><?php endif; ?>

        <?php if ($isForgot): ?>
        <form id="authForm" method="post" autocomplete="on">
          <?= csrf_field() ?>
          <div class="fld"><label for="f-email">Email akun</label>
            <div class="inp"><?= $svg('mail') ?>
              <input id="f-email" name="email" type="email" required value="<?= $old('email') ?>" placeholder="kamu@email.com" autocomplete="email" inputmode="email" autocapitalize="none" autocorrect="off" spellcheck="false" enterkeyhint="go" autofocus></div></div>
          <button class="btn btn-main" type="submit">Kirim kode</button>
        </form>
        <p class="note vnote">Hanya untuk akun yang mendaftar dengan email. Akun Google masuk lewat tombol Google.</p>

        <?php elseif ($isReset): ?>
        <form id="authForm" method="post" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reset">
          <div class="fld"><label for="f-code">Kode dari email</label>
            <input id="f-code" class="code-input" name="code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="••••••" autofocus></div>
          <div class="fld fld-pass"><label for="f-pass">Kata sandi baru</label>
            <div class="inp"><?= $svg('lock') ?>
              <input id="f-pass" type="password" name="password" required minlength="8" placeholder="Minimal 8 karakter" autocomplete="new-password" enterkeyhint="next">
              <button type="button" class="toggle" aria-pressed="false" aria-label="Tampilkan kata sandi"><span class="t-txt">Tampilkan</span><?= $svg('eye', 't-ico eye') ?><?= $svg('eyeoff', 't-ico eye-off') ?></button></div></div>
          <div class="fld"><label for="f-pass2">Ulangi kata sandi baru</label>
            <div class="inp"><?= $svg('lock') ?>
              <input id="f-pass2" type="password" name="password2" required minlength="8" placeholder="Ketik ulang kata sandi" autocomplete="new-password" enterkeyhint="go"></div></div>
          <button class="btn btn-main" type="submit">Simpan kata sandi baru</button>
        </form>
        <form method="post" class="verify-aux">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="resend">
          <button class="link-btn" type="submit" id="resendBtn" data-wait="<?= (int)$resendIn ?>" <?= $resendIn > 0 ? 'disabled' : '' ?>>Kirim ulang kode<?= $resendIn > 0 ? ' (' . (int)$resendIn . ')' : '' ?></button>
        </form>
        <form method="post" class="verify-aux">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="cancel">
          <button class="link-btn" type="submit">Salah email? Ganti</button>
        </form>
        <p class="note vnote">Tidak ada di kotak masuk? Cek folder spam. Kode berlaku <?= (int)(OTP_TTL / 60) ?> menit.</p>
        <script>
        (function () {
          var b = document.getElementById('resendBtn'); if (!b) return;
          var n = parseInt(b.getAttribute('data-wait'), 10) || 0; if (n <= 0) return;
          var t = setInterval(function () {
            n--; if (n <= 0) { clearInterval(t); b.disabled = false; b.textContent = 'Kirim ulang kode'; }
            else b.textContent = 'Kirim ulang kode (' + n + ')';
          }, 1000);
        })();
        </script>

        <?php elseif ($isVerify): ?>
        <form id="authForm" method="post" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="verify">
          <div class="fld"><label for="f-code">Kode verifikasi</label>
            <input id="f-code" class="code-input" name="code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="••••••" autofocus></div>
          <button class="btn btn-main" type="submit">Verifikasi &amp; buat akun</button>
        </form>
        <form method="post" class="verify-aux">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="resend">
          <button class="link-btn" type="submit" id="resendBtn" data-wait="<?= (int)$resendIn ?>" <?= $resendIn > 0 ? 'disabled' : '' ?>>Kirim ulang kode<?= $resendIn > 0 ? ' (' . (int)$resendIn . ')' : '' ?></button>
        </form>
        <form method="post" class="verify-aux">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="cancel">
          <button class="link-btn" type="submit">Salah email? Ganti</button>
        </form>
        <p class="note vnote">Tidak ada di kotak masuk? Cek folder spam. Kode berlaku <?= (int)(OTP_TTL / 60) ?> menit.</p>
        <script>
        (function () {
          var b = document.getElementById('resendBtn'); if (!b) return;
          var n = parseInt(b.getAttribute('data-wait'), 10) || 0; if (n <= 0) return;
          var t = setInterval(function () {
            n--; if (n <= 0) { clearInterval(t); b.disabled = false; b.textContent = 'Kirim ulang kode'; }
            else b.textContent = 'Kirim ulang kode (' + n + ')';
          }, 1000);
        })();
        </script>

        <?php elseif ($isLogin): ?>
        <form id="authForm" method="post" autocomplete="on">
          <?= csrf_field() ?>
          <div class="fld"><label for="f-email">Email atau nama pengguna</label>
            <div class="inp"><?= $svg('user') ?>
              <input id="f-email" name="email" required value="<?= $old('email') ?>" placeholder="kamu@email.com" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" enterkeyhint="next"></div></div>

          <div class="fld fld-pass"><label for="f-pass">Kata sandi</label>
            <div class="inp"><?= $svg('lock') ?>
              <input id="f-pass" type="password" name="password" required minlength="1" placeholder="Kata sandi kamu" autocomplete="current-password" enterkeyhint="go">
              <button type="button" class="toggle" aria-pressed="false" aria-label="Tampilkan kata sandi"><span class="t-txt">Tampilkan</span><?= $svg('eye', 't-ico eye') ?><?= $svg('eyeoff', 't-ico eye-off') ?></button></div>
            <p class="forgot"><a href="<?= e(url('forgot_password.php')) ?>">Lupa kata sandi?</a></p></div>

          <button class="btn btn-main" type="submit">Masuk</button>
        </form>

        <?php else: /* register */ ?>
        <form id="authForm" class="reg-form<?= $startS2 ? ' s2' : '' ?>" method="post" autocomplete="on">
          <?= csrf_field() ?>
          <div class="step step-1">
            <div class="fld"><label for="f-name">Nama tampilan</label>
              <div class="inp"><?= $svg('user') ?>
                <input id="f-name" name="display_name" required maxlength="60" value="<?= $old('display_name') ?>" placeholder="Nama yang akan tampil" autocomplete="name" enterkeyhint="next"></div></div>
            <div class="fld"><label for="f-user">Nama pengguna</label>
              <div class="inp"><?= $svg('at') ?>
                <input id="f-user" name="username" required pattern="[A-Za-z0-9_]{3,30}" maxlength="30" value="<?= $old('username') ?>" placeholder="contoh: budi_santoso" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" enterkeyhint="next"></div>
              <p class="hint">3–30 karakter: huruf, angka, atau underscore.</p></div>
            <div class="fld"><label for="f-email">Email</label>
              <div class="inp"><?= $svg('mail') ?>
                <input id="f-email" name="email" type="email" required value="<?= $old('email') ?>" placeholder="kamu@email.com" autocomplete="email" inputmode="email" autocapitalize="none" autocorrect="off" spellcheck="false" enterkeyhint="next"></div>
              <p class="hint">Kami kirim kode verifikasi 6 digit ke email ini.</p></div>
            <button class="btn btn-main btn-next" type="button" id="nextBtn">Lanjut <?= $svg('next', 'bi') ?></button>
          </div>

          <div class="step step-2">
            <div class="reg-chip"><?= $svg('check', 'ci') ?><span>Mendaftar sebagai <b id="chipUser">@</b></span><button type="button" class="link-btn" id="chipEdit">Ubah</button></div>
            <div class="fld fld-pass"><label for="f-pass">Kata sandi</label>
              <div class="inp"><?= $svg('lock') ?>
                <input id="f-pass" type="password" name="password" required minlength="8" placeholder="Minimal 8 karakter" autocomplete="new-password" enterkeyhint="next">
                <button type="button" class="toggle" aria-pressed="false" aria-label="Tampilkan kata sandi"><span class="t-txt">Tampilkan</span><?= $svg('eye', 't-ico eye') ?><?= $svg('eyeoff', 't-ico eye-off') ?></button></div>
              <div class="meter" id="meter" data-lv="0" aria-live="polite"><span class="bars"><i></i><i></i><i></i><i></i></span><span class="meter-txt" id="meterTxt">Minimal 8 karakter</span></div></div>
            <div class="fld"><label for="f-pass2">Ulangi kata sandi</label>
              <div class="inp"><?= $svg('lock') ?>
                <input id="f-pass2" type="password" name="password2" required minlength="8" placeholder="Ketik ulang kata sandi" autocomplete="new-password" enterkeyhint="go"></div>
              <p class="hint" id="matchHint" aria-live="polite"></p></div>
            <div class="btn-row">
              <button type="button" class="back-btn" id="backBtn" aria-label="Kembali ke langkah 1"><?= $svg('back', 'bi') ?></button>
              <button class="btn btn-main" type="submit">Daftar sekarang</button>
            </div>
          </div>
        </form>
        <?php endif; ?>

        <?php if (!$isVerify && !$isAux): ?>
        <div class="or">atau</div>

        <a class="btn btn-ghost" href="<?= e(url('google_login.php')) ?>">
          <svg width="19" height="19" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.8 2.4 30.3 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.6 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.6 5.9c4.4-4.1 7-10.1 7-17.6z"/><path fill="#FBBC05" d="M10.5 28.7A14.5 14.5 0 0 1 9.5 24c0-1.6.3-3.2.8-4.7l-7.9-6.1A24 24 0 0 0 0 24c0 3.9.9 7.5 2.6 10.8l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.6-5.9c-2.1 1.4-4.9 2.3-8.3 2.3-6.3 0-11.6-4.1-13.5-9.8l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg>
          Lanjutkan dengan Google
        </a>
        <?php endif; ?>
      </div>

      <?php if ($isAux): ?>
      <p class="switch"><a href="<?= e(url('login.php')) ?>">← Kembali ke halaman masuk</a></p>
      <?php elseif (!$isVerify): ?>
      <p class="switch"><?= $isLogin ? 'Belum punya akun?' : 'Sudah punya akun?' ?>
        <a href="<?= e(url($isLogin ? 'register.php' : 'login.php')) ?>" data-dir="<?= $isLogin ? 'fwd' : 'back' ?>"><?= $isLogin ? 'Daftar' : 'Masuk' ?></a></p>
      <p class="switch guest"><a href="<?= e(url('index.php')) ?>">Lanjutkan sebagai tamu →</a></p>
      <?php endif; ?>
      <p class="note">Dengan melanjutkan, kamu menyetujui <b>Ketentuan Layanan</b> dan <b>Kebijakan Privasi</b>.</p>
    </div>
  </main>

  <aside class="stage" id="stage" aria-hidden="true">
    <div class="stage-floor"></div>
    <div class="lamp"></div>
    <div class="beam"></div>
    <div class="layer dim"><img src="<?= $logo ?>" alt=""></div>
    <div class="layer lit"><img src="<?= $logo ?>" alt=""></div>
  </aside>

</div>
<script>
(function () { // transisi saat pindah antara halaman masuk dan daftar
  var inner = document.querySelector('.auth-inner');
  var link = document.querySelector('a[data-dir]');
  if (!inner || !link) return;
  var reduce = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
  link.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || reduce) return;
    e.preventDefault();
    var dir = link.getAttribute('data-dir');
    try { sessionStorage.setItem('itihix-auth-dir', dir); } catch (err) {}
    document.documentElement.removeAttribute('data-dir'); // buang arah masuk supaya tidak menimpa animasi keluar
    inner.classList.add('leave-' + dir);
    setTimeout(function () { location.href = link.href; }, 400);
  });
  window.addEventListener('pageshow', function (ev) { // kembali via tombol back browser
    if (ev.persisted) inner.classList.remove('leave-fwd', 'leave-back');
  });
})();
</script>
<script>
(function () {
  // tampil / sembunyikan kata sandi (teks di desktop, ikon mata di mobile)
  var toggles = document.querySelectorAll('.toggle');
  Array.prototype.forEach.call(toggles, function (t) {
    t.addEventListener('click', function () {
      var input = t.parentNode.querySelector('input');
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      var tx = t.querySelector('.t-txt'); if (tx) tx.textContent = show ? 'Sembunyikan' : 'Tampilkan';
      t.setAttribute('aria-pressed', show ? 'true' : 'false');
      t.setAttribute('aria-label', show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
    });
  });

  // tombol kirim: tampilkan spinner & cegah kirim ganda
  Array.prototype.forEach.call(document.querySelectorAll('form'), function (f) {
    var b = f.querySelector('.btn-main[type="submit"]'); if (!b) return;
    f.addEventListener('submit', function (e) {
      setTimeout(function () {
        if (e.defaultPrevented) return;
        b.classList.add('loading'); b.setAttribute('aria-busy', 'true'); f.setAttribute('data-sent', '1');
      }, 0);
      if (f.getAttribute('data-sent') === '1') e.preventDefault();
    });
  });
  window.addEventListener('pageshow', function (ev) {
    if (!ev.persisted) return;
    Array.prototype.forEach.call(document.querySelectorAll('.btn.loading'), function (b) { b.classList.remove('loading'); b.removeAttribute('aria-busy'); });
    Array.prototype.forEach.call(document.querySelectorAll('form[data-sent]'), function (f) { f.removeAttribute('data-sent'); });
  });

  // pendaftaran: kekuatan kata sandi, cek kecocokan, dan 2 langkah di mobile
  var form = document.getElementById('authForm');
  if (form && form.classList.contains('reg-form')) {
    var p = document.getElementById('f-pass'), p2 = document.getElementById('f-pass2');
    var meter = document.getElementById('meter'), mTxt = document.getElementById('meterTxt'), mh = document.getElementById('matchHint');
    var labels = ['Minimal 8 karakter', 'Lemah', 'Cukup', 'Bagus', 'Kuat'];
    function strength() {
      var v = p.value, s = 0;
      if (v.length >= 8) s++;
      if (v.length >= 12) s++;
      if (/[a-z]/.test(v) && /[A-Z]/.test(v) && /\d/.test(v)) s++;
      if (/[^A-Za-z0-9]/.test(v)) s++;
      if (v.length > 0 && v.length < 8) s = 0;
      meter.setAttribute('data-lv', v ? String(Math.max(s, 1)) : '0');
      mTxt.textContent = !v ? labels[0] : (v.length < 8 ? 'Terlalu pendek (min. 8)' : labels[Math.max(s, 1)]);
    }
    function match() {
      if (!p2.value) { mh.textContent = ''; mh.className = 'hint'; return; }
      var ok = p.value === p2.value;
      mh.textContent = ok ? 'Kata sandi cocok' : 'Belum sama dengan di atas';
      mh.className = 'hint ' + (ok ? 'ok' : 'bad');
    }
    p.addEventListener('input', function () { strength(); match(); });
    p2.addEventListener('input', match);
    strength(); match();

    var mq = window.matchMedia('(max-width:900px)');
    var steps = document.getElementById('steps');
    var bars = steps ? steps.querySelectorAll('.s') : [], sTxt = steps ? steps.querySelector('em') : null;
    var chip = document.getElementById('chipUser'), userIn = document.getElementById('f-user');
    function setStep(n) {
      form.classList.toggle('s2', n === 2);
      if (steps) { bars[1].classList.toggle('on', n === 2); sTxt.textContent = 'Langkah ' + n + ' dari 2'; }
      if (n === 2) chip.textContent = '@' + userIn.value.trim().replace(/^@/, '');
    }
    function go2() {
      var f = form.querySelectorAll('.step-1 input');
      for (var i = 0; i < f.length; i++) if (!f[i].checkValidity()) { f[i].reportValidity(); return false; }
      setStep(2); p.focus(); return true;
    }
    document.getElementById('nextBtn').addEventListener('click', go2);
    document.getElementById('backBtn').addEventListener('click', function () { setStep(1); document.getElementById('f-name').focus(); });
    document.getElementById('chipEdit').addEventListener('click', function () { setStep(1); userIn.focus(); });
    form.addEventListener('submit', function (e) { // Enter di langkah 1 = lanjut, bukan kirim
      if (mq.matches && !form.classList.contains('s2')) { e.preventDefault(); go2(); }
    });
    if (form.classList.contains('s2')) setStep(2);
  }

  // lampu sorot: logo tersingkap hanya di area yang disinari kursor
  var stage = document.getElementById('stage');
  if (!stage) return;
  var reduce = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
  var home = { x: 0.5, y: 0.5 };
  var cur = reduce ? { x: home.x, y: home.y } : { x: 0.18, y: 0.1 }; // animasi masuk dari kiri atas
  var tgt = { x: home.x, y: home.y };
  var TAU = 340;                  // makin besar, lampu makin pelan menyusul kursor (milidetik)
  var idle = !reduce, t0 = performance.now(), last = 0, raf = 0;

  function apply() {
    var w = stage.clientWidth, h = stage.clientHeight;
    var x = cur.x * w, y = cur.y * h;
    var dx = x - w / 2, dy = Math.max(y, 1);
    stage.style.setProperty('--lx', x + 'px');
    stage.style.setProperty('--ly', y + 'px');
    stage.style.setProperty('--ba', (-Math.atan2(dx, dy) * 180 / Math.PI) + 'deg');
    stage.style.setProperty('--bh', (Math.sqrt(dx * dx + dy * dy) + Math.min(w, h) * 0.14) + 'px');
  }
  function loop(now) {
    if (idle) { // tanpa kursor: lampu menyapu pelan melewati logo
      var t = now - t0;
      tgt.x = 0.5 + 0.27 * Math.sin(t / 1900);
      tgt.y = 0.46 + 0.2 * Math.sin(t / 1300 + 1);
    }
    var dt = last ? Math.min(now - last, 64) : 16; last = now;
    var k = 1 - Math.exp(-dt / TAU);   // meluncur halus, sama cepatnya di layar 60Hz maupun 144Hz
    var ex = tgt.x - cur.x, ey = tgt.y - cur.y;
    cur.x += ex * k; cur.y += ey * k;
    apply();
    if (idle || Math.abs(ex) > 0.0008 || Math.abs(ey) > 0.0008) raf = requestAnimationFrame(loop);
    else { raf = 0; last = 0; }
  }
  function kick() { if (!raf) raf = requestAnimationFrame(loop); }

  function onMove(e) {
    if (e.pointerType === 'touch' || !stage.offsetWidth) return;
    var r = stage.getBoundingClientRect();
    idle = false;
    if (e.clientX < r.left) {
      // kursor di sisi form/kartu: lampu otomatis kembali ke tengah logo
      tgt.x = home.x; tgt.y = home.y;
    } else {
      stage.classList.add('moved');
      tgt.x = Math.min(0.97, Math.max(0.03, (e.clientX - r.left) / r.width));
      tgt.y = Math.min(0.97, Math.max(0.1, (e.clientY - r.top) / r.height));
    }
    kick();
  }
  if (window.PointerEvent) window.addEventListener('pointermove', onMove, { passive: true });
  else window.addEventListener('mousemove', onMove, { passive: true });

  document.documentElement.addEventListener('mouseleave', function () { // kursor keluar jendela
    if (reduce) return;
    idle = true; t0 = performance.now(); kick();
  });
  window.addEventListener('resize', apply);

  apply();
  kick();
})();
</script>
</body>
</html>