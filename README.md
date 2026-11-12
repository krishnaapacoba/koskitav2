# KosKita — Sistem Informasi Manajemen & Pemesanan Kos

Stack: HTML, CSS, JavaScript, PHP 8+ (PDO pgsql), PostgreSQL 12+.

## Menjalankan
1. Buat database dan impor skema + data contoh:
   ```bash
   createdb koskita_db
   psql -d koskita_db -f database/schema.sql
   psql -d koskita_db -f database/seed.sql
   ```
   (skema memakai ekstensi `pgcrypto`; pada Postgres terkelola jalankan `CREATE EXTENSION pgcrypto;` sebagai superuser lebih dulu.)
2. Sesuaikan kredensial di `config/config.php` (atau variabel env `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`).
3. Pastikan ekstensi PHP `pdo_pgsql` dan `fileinfo` aktif, serta folder `uploads/*` dapat ditulis web server.
4. Jalankan:
   ```bash
   php -S localhost:8000 router.php
   ```
   Atau letakkan folder `koskita/` di htdocs/www Apache (BASE_URL terdeteksi otomatis). Pada Nginx, tolak akses langsung ke `/uploads/documents|payments|tickets/`.

## Akun contoh (password: `password123`)
| Peran | Email |
|---|---|
| Superadmin | superadmin@koskita.test |
| Admin Site (KosKita Melati) | admin@koskita.test |
| Penghuni | tenant@koskita.test |

## Alur calon penghuni → penghuni
1. Landing page dapat dilihat tanpa login (nav hanya **Masuk** & **Daftar**). Kamar ditahan/terisi tampil abu-abu dan tidak bisa dipesan.
2. Klik **Pesan** → wajib login/daftar. Setelah daftar, otomatis masuk dan kembali ke landing page (nav berubah: Kamar, Fasilitas, nama, Keluar).
3. Pilih kamar → konfirmasi → kamar `ON_HOLD` → `onboarding.php` untuk unggah KTP/KK/Buku Nikah/lainnya (boleh lebih dari satu).
4. Admin menyetujui/menolak di **Verifikasi**. Ditolak → email alasan + unggah ulang. Disetujui → email berisi tombol ke `first_payment.php`.
5. Pembayaran pertama memakai komponen yang sama dengan pembayaran bulanan (QRIS + bukti, atau Tunai dengan popup + OK).
6. Admin memverifikasi di **Pembayaran** → status `GUEST` menjadi `TENANT`, kamar `OCCUPIED`, email konfirmasi; kredensial yang sama kini membuka dashboard penghuni.

## Catatan
- **Email**: dikirim via `mail()` bila server mail tersedia, dan selalu dicatat di tabel `email_outbox`. Dengan `APP_DEBUG = true`, calon penghuni melihatnya di menu **Email** (`mailbox.php`). Matikan `APP_DEBUG` di produksi dan pasang SMTP/library mailer.
- **QRIS** diunggah admin per kosan (kolom `kosan.qris_path`) di menu Pembayaran.
- **Berkas privat** (identitas, bukti bayar, foto tiket) disajikan lewat `file.php` dengan pemeriksaan hak akses; admin hanya bisa melihat data kosan yang ditugaskan.
- **Jatuh tempo bulanan** dihitung dari periode pembayaran terakhir + 1 bulan (tanpa cron). Pengingat tampil di dashboard dan halaman Pembayaran.
- **Checkout** bersifat *soft deactivation*: akun `INACTIVE`, kamar kembali `AVAILABLE`, riwayat pembayaran & tiket tetap tersimpan.
- Tambahan di luar PRD awal: kolom `qris_path`, `catatan`, `id_kosan`/`nomor_kamar` pada `payments`, `id_kosan` pada `tickets`, tabel `email_outbox`, halaman `first_payment.php`, `file.php`, `mailbox.php`, `tenant/receipt.php`, `superadmin/manage_facilities.php`.
