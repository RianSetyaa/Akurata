# Akurata POS Database

Dashboard memakai PHP API dan MySQL.

## 1. Import schema

```bash
mysql -u root -p < database/schema.sql
```

Schema akan membuat database `akurata_pos`, tabel utama, dan data contoh.

Kalau database sudah pernah dibuat sebelum fitur multitenant ditambahkan, jalankan migration ini:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_04_multitenant.sql
```

Untuk fitur invoice dan quotation, jalankan juga:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_04_sales_documents.sql
```

Untuk alur Purchase Order dan Terima Barang, jalankan:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_04_purchase_receiving.sql
```

Untuk integrasi Loyverse, jalankan:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_04_loyverse_integration.sql
```

Untuk sinkron produk dan penjualan Loyverse, jalankan:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_05_loyverse_sync.sql
```

Untuk sinkron stok dari penjualan Loyverse yang sudah terlanjur diimpor, jalankan sekali:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_05_loyverse_stock_sync.sql
```

Untuk profil bisnis dan logo usaha, jalankan:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_05_business_settings.sql
```

Untuk rekening penagihan invoice tempo, jalankan:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_05_billing_account.sql
```

Untuk DP dan pembayaran piutang parsial, jalankan:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_05_receivable_payments.sql
```

Kalau ada transaksi tempo lama yang tidak muncul di Piutang, jalankan backfill:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_05_receivable_backfill_tempo.sql
```

Untuk pengaturan pajak invoice, jalankan:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_05_tax_settings.sql
```

Untuk sinkron pajak ke Loyverse, jalankan:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_05_loyverse_tax_sync.sql
```

Untuk memilih POS/Store Loyverse yang digunakan outlet, jalankan:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_12_loyverse_store_mapping.sql
```

Untuk log semua aktivitas user dan sistem, jalankan:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_05_activity_logs.sql
```

Untuk role Administrator, Owner, Manager, dan Cashier, jalankan:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_05_roles_administrator.sql
```

Promosikan salah satu user menjadi administrator:

```sql
UPDATE users SET role = 'administrator' WHERE email = 'email-admin@domain.com';
```

Atau buat akun administrator default:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_05_create_administrator_user.sql
```

Untuk fitur hapus akun dari laman administrator tanpa merusak histori transaksi, jalankan:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_05_user_soft_delete.sql
```

Untuk OTP email saat pendaftaran, jalankan:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_05_register_otp.sql
```

Untuk pengaturan tampil/sembunyikan fitur Quotation, jalankan:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_07_quotation_setting.sql
```

Untuk fitur lupa password melalui OTP email, jalankan:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_07_password_reset.sql
```

Untuk produk volume/berat dan qty desimal, jalankan:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_08_product_volume_mode.sql
```

Untuk produk composite atau paket, jalankan setelah migration volume:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_10_composite_products.sql
```

Untuk menyimpan barcode produk, push barcode ke Loyverse, dan scan barcode saat penjualan, jalankan:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_10_product_barcode.sql
```

Pemindaian dengan kamera tersedia sebagai alternatif pada popup transaksi. Akses kamera memerlukan HTTPS atau localhost dan izin kamera dari browser. Library pemindai disimpan lokal di `dashboard/vendor`.

Untuk chat CS antara outlet dan administrator, jalankan:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_11_support_chat.sql
```

Jika migration chat sebelumnya sudah pernah dijalankan, aktifkan dukungan banyak sesi dan riwayat dengan:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_11_support_chat_sessions.sql
```

Untuk diskon transaksi web dan diskon receipt Loyverse, jalankan:

```bash
mysql -u root -p akurata_pos < database/migrations/2026_06_11_transaction_discounts.sql
```

## 2. Konfigurasi koneksi

API membaca environment variable berikut. Jika kosong, default-nya:

```text
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=akurata_pos
DB_USER=root
DB_PASS=
```

Untuk Loyverse Developer OAuth, set juga:

```text
APP_URL=https://akurata.my.id
LOYVERSE_CLIENT_ID=client_id_dari_loyverse
LOYVERSE_CLIENT_SECRET=client_secret_dari_loyverse
LOYVERSE_REDIRECT_URI=https://akurata.my.id/api/loyverse.php?action=callback
LOYVERSE_SCOPE=OPENID MERCHANT_READ STORES_READ ITEMS_READ ITEMS_WRITE INVENTORY_READ INVENTORY_WRITE TAXES_READ TAXES_WRITE RECEIPTS_READ RECEIPTS_WRITE PAYMENT_TYPES_READ SUPPLIERS_READ
```

Redirect URI di Loyverse Developer harus sama persis dengan `LOYVERSE_REDIRECT_URI`.

Untuk OTP pendaftaran via Brevo API, set:

```text
BREVO_API_KEY=api_key_dari_brevo
BREVO_SENDER_EMAIL=noreply@akurata.my.id
BREVO_SENDER_NAME=Akurata POS
```

`BREVO_SENDER_EMAIL` harus sender/domain yang sudah diverifikasi di Brevo.
Kalau environment variable di aaPanel sulit diatur, buat file `api/secrets.php` berdasarkan `api/secrets.example.php`, lalu isi key Brevo di server.

## 3. Jalankan server lokal

```bash
php -S 127.0.0.1:8000
```

Lalu buka:

```text
http://127.0.0.1:8000/dashboard/
```

## Produk Siap Loyverse

Supaya produk lokal aman dipush ke Loyverse:

- `SKU` wajib unik dan maksimal 40 karakter.
- `Nama produk` wajib maksimal 64 karakter.
- `Kategori/deskripsi` maksimal 512 karakter.
- `Harga jual` wajib 0 atau lebih dan dikirim sebagai harga tetap.
- `Harga beli` wajib 0 atau lebih dan dikirim sebagai cost/purchase cost.
- Produk lokal dikirim dengan `track_stock` aktif agar data stok Loyverse tidak dipaksa menjadi 0.
- Produk volume/berat dikirim dengan `sold_by_weight` aktif.
- Produk composite harus memakai produk biasa yang sudah dipush ke Loyverse sebagai komponen.
- Produk composite tidak memiliki stok mandiri; stok tersedia dihitung dari stok komponennya.

## Endpoint API

- `GET api/db-check.php` untuk cek koneksi database dan waktu respons.
- `POST api/register.php` untuk daftar akun dan membuat outlet baru.
- `POST api/verify-register-otp.php` untuk verifikasi OTP pendaftaran.
- `POST api/password-reset.php` untuk meminta OTP dan mengatur ulang password.
- `POST api/login.php` untuk validasi email/nomor dan password.
- `GET api/me.php` untuk cek session user yang sedang login.
- `POST api/logout.php` untuk keluar dari session.
- `GET api/dashboard.php` untuk ringkasan dashboard.
- `GET api/products.php` untuk daftar produk.
- `POST api/products.php` untuk tambah produk.
- `GET api/transactions.php` untuk transaksi.
- `POST api/transactions.php` untuk simpan transaksi, DP tempo, dan mengurangi stok.
- `GET api/quotations.php` untuk daftar quotation.
- `POST api/quotations.php` untuk membuat quotation.
- `GET api/document.php?type=invoice&id=...` untuk unduh invoice.
- `GET api/document.php?type=quotation&id=...` untuk unduh quotation.
- `GET api/document.php?type=payment&id=...` untuk unduh invoice pembayaran piutang.
- `GET api/purchases.php` untuk daftar Purchase Order.
- `POST api/purchases.php` untuk membuat Purchase Order.
- `POST api/purchases.php` dengan `action=receive` untuk terima barang dan memperbarui HPP.
- `GET api/reports.php?type=sales` untuk laporan penjualan.
- `GET api/reports.php?type=purchases` untuk laporan pembelian.
- `GET api/settings.php` untuk membaca profil dan profil bisnis.
- `POST api/settings.php` dengan `action=profile` untuk update profil user.
- `POST api/settings.php` dengan `action=business` untuk update profil bisnis dan logo usaha.
- `GET api/loyverse.php?action=status` untuk cek status integrasi Loyverse.
- `GET api/loyverse.php?action=status&include_stores=1` untuk membaca daftar POS/Store Loyverse.
- `POST api/loyverse.php?action=select_store` untuk memilih POS/Store Loyverse aktif.
- `GET api/loyverse.php?action=connect` untuk mulai login OAuth Loyverse.
- `GET api/loyverse.php?action=callback` untuk callback OAuth Loyverse.
- `POST api/loyverse.php?action=push_products` dengan JSON `ids` maksimal 5 produk untuk batch push ke Loyverse.
- `POST api/loyverse.php?action=import_receipts` untuk menarik penjualan Loyverse sebagai transaksi lokal.
- `POST api/loyverse.php?action=disconnect` untuk memutus integrasi Loyverse.
- `GET api/product-import.php?action=template` untuk mengunduh template import produk Excel.
- `POST api/product-import.php` untuk mengimpor produk biasa dan composite dari file `.xlsx`.
- `GET api/receivables.php` untuk daftar piutang.
- `GET api/receivables.php?id=...` untuk detail piutang dan riwayat pembayaran.
- `POST api/receivables.php` untuk mencatat pembayaran piutang parsial/DP lanjutan.
- `PATCH api/receivables.php` untuk menandai sisa piutang lunas.

## Multitenant

Setelah login, API menyimpan `user.id` dan `user.outlet_id` di session PHP.
Semua endpoint dashboard, produk, transaksi, restock, dan piutang mengambil `outlet_id` dari session tersebut.
Frontend tidak boleh mengirim `outlet_id` sendiri untuk menentukan tenant.
