# Prompt: Pembayaran QRIS & Virtual Account langsung (Kasera Pay Direct API)

Salin semua isi di bawah garis ini ke Claude Code, di root project website tujuan.
Sesuaikan bagian yang bertanda `[...]`.

---

Tolong tambahkan fitur **pilih metode pembayaran langsung di halaman checkout kita sendiri** memakai **Kasera Pay Direct API** (https://pay.kasera.id/docs), supaya pembeli TIDAK dilempar ke halaman checkout Kasera untuk QRIS dan Virtual Account.

## Konteks project
- Stack: [PHP native / Laravel / dll.], database [MySQL], hosting [shared hosting cPanel / VPS].
- Halaman checkout sekarang: `[payment.php]`. Halaman status order: `[order-status.php]`. Webhook Kasera: `[kasera-webhook.php]`.
- Client Kasera yang sudah ada: `[lib/kasera-client.php]` (kalau belum ada, buatkan: request Bearer + Idempotency-Key, verifikasi signature webhook).
- Gaya tampilan: **ikuti style halaman yang sudah ada** (warna, font, bentuk tombol/kartu). Baca dulu file CSS/halaman yang ada sebelum menulis UI.
- API key, webhook secret, dan password JANGAN ditulis ulang atau dicetak di chat; baca dari config/env yang sudah ada.

## Yang harus dibuat

### 1. Pemilih metode di halaman checkout
Kartu "Payment Method" berisi:
- **QRIS** (baris besar, label "Instant", ikon QR).
- **Virtual Account**: grid 4 kolom (2 kolom di HP) untuk `va_bca, va_bri, va_bni, va_mandiri, va_permata, va_cimb, va_danamon, va_maybank`. **Tiap bank tampil dengan LOGO**, bukan teks.
- **Other methods** (e-wallet dll.) = alur lama: redirect ke `checkout_url` Kasera.
- Pilihan tampil dengan state jelas: garis tebal, bayangan offset, centang. Ringkasan order ("Pay with ...") dan teks tombol ikut berubah via JS. Tombol dinonaktifkan setelah submit (cegah order ganda) dan di-reset saat halaman kembali dari bfcache (`pageshow`).
- Radio input harus bisa diakses keyboard (`:focus-visible`).

### 2. Logo bank
- Simpan di `assets/banks/<kode>.svg|png|webp`, nama file = kode metode (mis. `va_bca.svg`).
- Unduh dari Wikimedia Commons lewat API (`commons.wikimedia.org/w/api.php`, generator=search, prop=imageinfo) dengan header `User-Agent` yang jelas. Beri jeda 2 detik antar unduhan (kena rate limit kalau beruntun).
- **Verifikasi visual**: buat halaman HTML preview semua logo, screenshot dengan Chrome headless, lalu lihat hasilnya. Pastikan tiap logo memuat tulisan bank, bukan hanya ikon (versi "cropped" sering hanya ikon).
- Hindari SVG yang sangat besar (>100 KB); pakai thumbnail PNG Wikimedia (lebar standar 330/500px) kalau perlu.
- Kode render: kalau file logo ada pakai `<img loading="lazy">`, kalau tidak ada fallback ke wordmark teks. Tambahkan `assets/banks/README.txt`.

### 3. Pembuatan transaksi
- Fungsi `kaseraEnsureTransactionForOrder($pdo, $order, ?string $method)`:
  - Metode langsung: kirim `payment_methods: [kode]`, **hapus** `checkout.steps`.
  - Untuk VA: `customer.name` wajib dan hanya 30 karakter ASCII -> transliterasi (`iconv ASCII//TRANSLIT//IGNORE`), buang karakter selain `[A-Za-z0-9 .,'-]`, fallback nama default.
  - `Idempotency-Key: order-<order_code>`.
  - Simpan respons ke DB: `kasera_payment_method` dan `kasera_payment_data` (JSON: `payment`, `instructions`, `expires_at`). Buat kolom otomatis dengan `SHOW COLUMNS` + `ALTER TABLE` bila belum ada (dibungkus try/catch).
- Whitelist kode metode di server; jangan percaya input form. Validasi batas nominal per metode (QRIS min 1.000, VA min 10.000, maks 10.000.000).
- Total tagihan SELALU dihitung ulang di server dari harga di DB.
- Untuk metode langsung, setelah order dibuat redirect ke halaman status kita sendiri (jangan ke `checkout_url`, walau Kasera mengembalikannya). Hanya metode `checkout` yang redirect ke `checkout_url`.
- Order dibuat berstatus `pending` DULU, baru memanggil Kasera. Stok baru dikurangi saat pembayaran terkonfirmasi.

### 4. Halaman bayar / status order
Kalau order `pending` dan ada data pembayaran:
- Header: nama metode + **hitung mundur** ke `expires_at` (berkedip saat <5 menit; saat habis ganti dengan pesan kedaluwarsa + tombol kembali).
- Kotak nominal + tombol Copy.
- **QRIS**: render QR dari `payment.qr_string` (payload EMV mentah) di browser memakai `qrcodejs` dari cdnjs, error correction M, canvas 480px ditampilkan 240px. Tombol "Download QR" (canvas.toDataURL) dan "Copy QR code text". Fallback pesan + salin teks bila library gagal dimuat.
- **VA**: logo bank, `payment.payment_code` dalam font monospace besar + tombol Copy, instruksi "transfer nominal persis".
- Tampilkan `instructions.title` dan `instructions.steps` dari Kasera (selalu di-escape).
- Polling status tiap 4 detik. Setiap ~12 detik tambahkan `&sync=1`: server tanya langsung `GET /v1/transactions/:id` sebagai cadangan webhook, **dibatasi 1x/10 detik per order** (lock file di `sys_get_temp_dir()`), karena endpoint ini publik.
- Reload otomatis saat status berubah ke `confirmed` / `rejected`.
- Jangan retry pembuatan transaksi (tanpa metode) kalau data pembayaran sudah ada, karena Idempotency-Key sama dengan body berbeda akan bentrok.

### 5. Hal-hal yang SUDAH TERBUKTI jadi masalah (jangan diulang)
- Status sukses di payload Kasera bernilai **`"succeeded"`**. Daftar status "paid" harus memuat `succeeded` (plus `paid, success, settlement, completed`).
- Pengiriman email tiket di dalam webhook tidak boleh bisa menggagalkan konfirmasi: bungkus `try/catch (Throwable)`, catat ke log, dan tandai gagal supaya bisa dikirim ulang. Order yang sudah `confirmed` TIDAK akan diproses lagi saat webhook di-retry, jadi tanpa ini tiket hilang diam-diam.
- Webhook harus idempotent dan membalas 200 cepat; Kasera mengirim ulang `payment.paid` kalau tidak ada ack.
- Embed gambar QR/logo di email sebagai CID, bukan link remote.

### 6. Aturan pengerjaan
- Ikuti gaya kode dan komentar yang sudah ada di project.
- Semua output ke HTML di-escape; data ke JS lewat `json_encode`.
- Jalankan `php -l` di semua file yang diubah.
- Jangan commit/deploy; tunjukkan ringkasan perubahan dan daftar file yang harus di-upload.
- Di akhir, beri **checklist tes** (mode `kp_test_...` dulu): bayar QRIS, bayar tiap jenis VA, biarkan kedaluwarsa, bayar dua kali, matikan webhook lalu pastikan polling `sync` tetap mengonfirmasi.

## Hasil akhir yang diharapkan
Pembeli memilih QRIS atau bank, lalu langsung melihat QR atau nomor VA di halaman kita (dengan timer dan tombol salin), tanpa pindah ke situs lain. Tiket dikonfirmasi otomatis dan emailnya terkirim.
