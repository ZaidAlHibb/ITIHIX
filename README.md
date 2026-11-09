# ITIHIX — Navigasi Aman di Dunia Digital (PHP + MySQL)

Versi multi-halaman dari prototipe UI `ITIHIX_jelajahi_top_hashtag_mobile_sidebar.html`.
Tampilan (CSS) memakai file asli, jadi desainnya sama; yang berubah: tiap menu kini halaman PHP terpisah dan datanya masuk database.

## Kebutuhan
PHP 8.1+ (ekstensi: pdo_mysql, fileinfo, curl, mbstring) dan MySQL 5.7+/MariaDB 10.3+. Contoh: XAMPP / Laragon.

## Instalasi
1. Salin folder `itihix/` ke `htdocs` (atau web root).
2. Impor `database.sql` lewat phpMyAdmin (membuat database `itihix`, tabel, dan soal kuis).
3. Salin `.env.example` menjadi `.env` lalu isi `ITIHIX_DB_USER`, `ITIHIX_DB_PASS`, `ITIHIX_BASE_URL`, dst.
   **Lebih aman:** simpan sebagai `../itihix.env` (satu tingkat di atas folder aplikasi, di luar web root) atau tunjuk lewat variabel `ITIHIX_ENV_FILE`.
4. (Opsional, lokal saja) isi `ITIHIX_ALLOW_SETUP=1` di `.env`, buka `http://localhost/itihix/setup.php` sekali untuk membuat akun demo + contoh postingan.
   Sandi demo **acak dan hanya ditampilkan sekali**. Setelah itu set kembali ke `0` dan **hapus `setup.php`**. Halaman ini menolak akses dari luar localhost.
5. Pastikan folder `uploads/` bisa ditulis oleh web server.
6. Skema database dikelola lewat `migrations/` (lihat di bawah); tidak perlu langkah manual untuk instalasi baru.

## Migrasi database
- Perubahan skema = tambah file `migrations/NNN_deskripsi.php` (idempoten), lalu naikkan `SCHEMA_VERSION` di `config.php`.
- Migrasi tertunda dijalankan otomatis (sekali per sesi baru) atau manual: `php migrate.php`.
- `database.sql` selalu berisi skema terbaru untuk instalasi baru.

## Pengembangan
- Tes: `php tests/run.php` (tanpa dependensi, tidak butuh database).
- Gaya kode: `composer fix` (PHP-CS-Fixer, aturan di `.php-cs-fixer.dist.php`), `.editorconfig` untuk editor.
- Rapikan CSS: `python3 tools/css_dedupe.py assets/css/main.css` menghapus deklarasi yang pasti kalah oleh aturan selektor sama yang dimuat belakangan (urutan muat: style.css lalu app.css). Hanya menulis file bila cascade akhir terbukti identik.
- Kurangi `!important` tanpa mengubah cascade: `python3 tools/css_important.py --report` (dry-run) atau `--write`. Bukti statis per pasangan deklarasi; verifikasi browser lewat `tools/css-verify/` (lihat README di sana). Jalankan ulang `--report` setelah mengubah CSS, atau bila menambah `el.style.*` di JS (daftar `JS_INLINE` di tool harus ikut diperbarui).
- Stylesheet utama adalah satu file, `assets/css/main.css` (hasil gabungan style.css + app.css; bagian dasar di atas, override/komponen di bawah, ditandai komentar). `python3 tools/css_merge.py [--write]` membuang deklarasi yang terbukti selalu kalah (mode prune). `python3 tools/css_important.py --inert` menampilkan rule yang selektornya tidak pernah cocok (id/class yang tak ada di file mana pun selain CSS); `python3 tools/css_merge.py --inert --write --log docs/css-inert-removed.txt` membuangnya. Daftar yang sudah dibuang ada di `docs/css-inert-removed.txt` (termasuk keluarga `#page-chat`, padahal id halaman chat adalah `page-aichat`: rule itu tidak pernah berlaku).
- Skrip halaman terpisah: `assets/js/detect.js` (ItihScan), `assets/js/quiz.js` (Kuis); helper bersama diekspos `app.js` lewat `window.ITIHIX`.

## Opsional
- **AI sungguhan**: isi `AI_API_KEY` (dan `AI_PROVIDER`/`AI_MODEL`) di `.env`. ItihScan AI (teks + gambar) dan Chat AI akan memakai Claude. Tanpa key, dipakai mesin aturan lokal (kata kunci/pola modus penipuan) dan basis jawaban bawaan.
- **Login Google**: buat OAuth Client di Google Cloud Console, isi `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` di `.env` (= `https://DOMAIN/itihix/google_callback.php`).

## Lupa kata sandi (kode 6 digit)
Di halaman masuk ada tautan **Lupa kata sandi?** (`forgot_password.php` -> `reset_password.php`). Pengguna memasukkan email, menerima kode 6 digit (berlaku 10 menit, maks. 5 kali salah, kirim ulang tiap 60 detik), lalu membuat kata sandi baru. Tidak perlu migrasi database: kode disimpan sebagai hash di sesi, sama seperti verifikasi pendaftaran. Pesan di layar selalu sama baik email terdaftar maupun tidak. Batas: 3 kode/jam per email dan 10 permintaan/jam per IP. Akun yang hanya memakai Google menerima email berisi arahan untuk memakai tombol Google. Setelah berhasil, pengguna diminta masuk lagi (tidak auto-login).

## Verifikasi email saat daftar (kode 6 digit)
Daftar dengan email kini minta kode yang dikirim ke emailnya (`verify_email.php`). Akun baru dibuat di database hanya setelah kode benar. Kode berlaku 10 menit, maks. 5 kali salah, kirim ulang tiap 60 detik. Login Google tidak berubah.
Tambahkan ke `.env` (contoh Gmail; buat *App Password* di akun Google, butuh verifikasi 2 langkah):
```
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USER=emailkamu@gmail.com
SMTP_PASS=abcdefghijklmnop
```
Untuk uji lokal tanpa SMTP, isi `MAIL_DEV_LOG=1`: kode ditulis ke `includes/mail.log` (jangan dipakai di server asli).

## Halaman
| File | Fungsi |
|---|---|
| `login.php`, `register.php`, `forgot_password.php`, `reset_password.php`, `google_login.php`, `google_callback.php`, `logout.php` | Auth (email + Google) |
| `index.php` | Beranda/feed (Untuk kamu · Terbaru · Terverifikasi) |
| `explore.php` | Jelajahi: top hashtag, kategori, pencarian, filter tagar |
| `safesnaps.php` | SafeSnaps + unggah (masuk moderasi) |
| `detect.php` | Deteksi penipuan teks/gambar → caption otomatis (bisa diedit) → jadi laporan berlabel ItihScan AI |
| `chat.php` | Chat AI |
| `game.php` | **Tebak Scam 2P**: game 2 pemain berbentuk chat (maks. 5 pesan per ronde: pengirim 3, penebak 2); pengirim berperan Penipu/Bukan, penebak menebak; benar → keduanya +XP. **Cari lawan otomatis** (matchmaking), ruangan **publik/privat**, **batas waktu** tiap giliran (buka chat 120 dtk, balas 45 dtk, tebak 45 dtk), tombol **Akhiri match**, dan **Riwayat match** (lengkap dengan transkrip). Tabel dibuat/di-upgrade otomatis (`game_rooms`, `game_rounds`, `game_messages`) |
| `quiz.php` | Game Asli/Palsu bergaya chat WA, ada penjelasan & tanda bahaya |
| `upload.php` | Buat laporan/poster + saran label → antrean moderasi |
| `post.php` | Detail postingan + komentar |
| `profile.php` | Profil (postingan, repost, disukai, komentar, tersimpan), level, follow, daftar Pengikut/Mengikuti, privasi. Alamat profil: `/username` (`/username?tab=followers`, `/username?tab=following`) |
| `settings.php` | Akun, profil, privasi, preferensi, notifikasi |
| `notifications.php` | Notifikasi |
| `moderator.php` | Antrean moderasi: setujui (+label akhir) / minta klarifikasi / tolak, riwayat |
| `admin.php` | Dashboard, kelola pengguna & peran, semua postingan (hapus/pulihkan), log aktivitas |
| `api/*.php` | Endpoint AJAX: like, repost, simpan, bagikan, komentar, follow, chat, deteksi, kuis |

## Peran
- **User**: semua fitur komunitas; laporan masuk antrean.
- **Moderator**: fitur user + antrean moderasi; laporannya langsung terbit. Postingan yang disetujui diberi label “✓ Diverifikasi moderator” (tertaut ke profil moderator).
- **Admin**: fitur user + moderator + dashboard, kelola akun/peran/penangguhan, hapus postingan, lihat seluruh log.

## Level (maks. 110) & XP
XP untuk naik dari level L = `100 + 10×(L−1)`. Level tampil di feed (bisa disembunyikan di Pengaturan → Privasi).
Sumber XP: laporan terkirim +5 (maks. 5/hari), laporan disetujui +20, kuis benar +10..20, analisis AI +5 (maks. 5/hari), komentar +2 (maks. 5/hari), moderasi +2.
Gelar: Pemula Waspada → Penjaga Komunitas (10) → Pelindung Siber (30) → Pakar Anti-Penipuan (60) → Tameng Digital (90) → Legenda ITIHIX (110).

## Keamanan yang sudah ada
Prepared statements PDO, hash `password_hash`, token CSRF di semua POST, validasi MIME + ukuran upload, folder upload tanpa eksekusi PHP, rate limit berbasis database per IP/user/akun (login, chat, deteksi, komentar, laporan), `.htaccess` yang menolak `.env`/`.sql`/`.md`, cookie sesi `Secure` di HTTPS, soft-delete, log audit.

## Catatan
Perbaikan terbaru (migrasi, rate limit DB, pemecahan JS) belum dijalankan di PHP/MySQL sungguhan oleh pembuatnya. Jalankan `php tests/run.php` dan uji alur utama (login, unggah, kuis, ItihScan) di lokal sebelum deploy.