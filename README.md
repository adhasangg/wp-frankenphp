# wp-frankenphp

Custom production-ready Docker image untuk **WordPress** ditenagai **FrankenPHP (Caddy + PHP 8.3)**. Dirancang khusus untuk performa tinggi, isolasi Redis shared, dukungan penuh `.env`, dan integrasi mulus dengan reverse proxy **Coolify (Traefik / SSL Termination)**. Mengikuti rekomendasi modul resmi dari [Web Aloha: PHP Extensions for WordPress](https://webaloha.co/php-extensions-for-wordpress/).

---

## 🚀 Fitur Unggulan

1. **FrankenPHP Engine**: Menggantikan Nginx + PHP-FPM dengan Caddy web server modern + PHP 8.3 terkompilasi langsung (C-bindings). Mendukung HTTP/2, HTTP/3, dan kompresi Zstandard & Gzip bawaan.
2. **Dukungan PHP Extension Lengkap**: Termasuk `imagick`, `gd` (WebP/AVIF), `opcache`, `redis`, `igbinary`, `mysqli`, `pdo_mysql`, `intl`, `exif`, `bcmath`, `zip`, dan lainnya.
3. **Mendukung `.env`**: Menggunakan custom `.env` loader ringan (zero external dependencies) di `wp-config.php`. Variabel dapat didefinisikan via file `.env` maupun dashboard Environment Variables di Coolify.
4. **100% Kompatibel Coolify Reverse Proxy**: 
   - Dilengkapi deteksi header `X-Forwarded-Proto: https` dan `X-Forwarded-Host`.
   - Mengatasi masalah klasik *infinite redirect loop* (`ERR_TOO_MANY_REDIRECTS`) dan *mixed content* di WordPress saat berada di belakang proxy Coolify.
   - Mengatur `auto_https off` pada Caddyfile agar tidak bentrok dengan SSL Let's Encrypt yang dikelola oleh Traefik / Coolify Proxy.
5. **Entrypoint Volume Safety**: Jika `/var/www/html` di-mount ke persistent volume kosong di Coolify, script entrypoint akan otomatis menginisialisasi file core WordPress dari `/usr/src/wordpress`.
6. **WP-CLI Siap Pakai**: Memungkinkan eksekusi perintah WordPress langsung via Web Terminal Coolify.
7. **Security Hardened**: Memblokir akses publik ke file sensitif (`.env`, `.git`, `wp-config.php`, `*.sql`, `*.log`) dan mematikan eksekusi file PHP di dalam direktori `/wp-content/uploads/`.

---

## 📦 Ekstensi PHP yang Terpasang

Berdasarkan referensi [Web Aloha](https://webaloha.co/php-extensions-for-wordpress/) & Standar WordPress Core Handbook:

| Kategori | Ekstensi | Fungsi & Kegunaan |
| :--- | :--- | :--- |
| **Core & Wajib** | `curl` | Komunikasi HTTP API, update plugin/theme |
| | `dom`, `xml` | Parsing struktur XML & RSS feed |
| | `fileinfo` | Validasi tipe MIME file upload media |
| | `hash`, `sodium` | Enkripsi kata sandi, token keamanan, modern crypto |
| | `json` | REST API WordPress & Gutenberg editor |
| | `mbstring` | Pengolahan karakter UTF-8 multilingual |
| | `mysqli`, `pdo_mysql` | Driver koneksi database MySQL / MariaDB |
| | `openssl` | Koneksi HTTPS & SSL keluar |
| | `pcre` | Pemrosesan regular expression (regex) |
| | `zip`, `zlib` | Ekstraksi paket plugin, tema, dan update core |
| **Rekomendasi / Performa** | `imagick` | Manipulasi grafis tingkat lanjut (WebP, AVIF, resize presisi) |
| | `gd` | Fallback engine manipulasi gambar |
| | `exif` | Membaca metadata gambar (orientasi kamera otomatis) |
| | `intl` | Internasionalisasi & format angka/tanggal per locale |
| | `bcmath` | Operasi matematika presisi tinggi (dibutuhkan WooCommerce) |
| | `opcache` | Caching bytecode PHP dengan JIT buffer |
| | `redis`, `igbinary` | High performance Object Caching via Redis |

---

## 📁 Struktur Proyek

```text
wp-frankenphp/
├── Dockerfile                  # Multi-stage image build FrankenPHP + WordPress + Extensions
├── Caddyfile                   # Konfigurasi Caddy untuk Coolify proxy & rewrite WP
├── wp-config.php               # Config WordPress dengan .env loader, proxy detector & Redis prefix
├── docker-entrypoint.sh        # Script inisialisasi volume persisten, folder upgrade & permission
├── config/
│   └── wordpress.ini           # Opsi PHP & OPcache optimal (512M memory, 128M upload, 300s socket timeout)
├── .env.example                # Template variabel environment (sanitized, no credentials)
├── docker-compose.yml          # Standalone compose untuk WordPress (koneksi ke shared MySQL/Redis)
├── docker-compose.full-stack.yml # Stack lengkap (WordPress + MariaDB + Redis) untuk uji lokal
└── README.md
```

---

## 🛠️ Cara Deploy di Coolify Dashboard

### Opsi A: Deploy via Git Repository (Disarankan)

1. **Push source code ini ke Git Repository** Anda (GitHub / GitLab / Gitea).
2. Di Dashboard Coolify:
   - Klik **Projects** > Pilih environment Anda > Klik **+ New**.
   - Pilih **Git Source** (GitHub/GitLab) dan pilih repo Anda.
   - Pada pilihan Build Pack, pilih **Dockerfile**.
3. **Konfigurasi Application**:
   - **Ports Exposes**: Masukkan `80` (FrankenPHP mendengarkan port 80).
   - **Domains**: Masukkan domain Anda (misal: `https://wp.example.com`).
4. **Konfigurasi Persistent Storage (Volumes)**:
   - Tambahkan volume berikut di tab **Storages**:
     - `wp_data` ➔ `/var/www/html` *(jika ingin menyimpan semua file WP)*
     - ATAU `wp_content_data` ➔ `/var/www/html/wp-content` *(jika ingin core WP selalu ikut image update)*
5. **Konfigurasi Environment Variables**:
   - Buka tab **Environment Variables** di Coolify, lalu tambahkan:
     ```env
     DB_HOST=mariadb:3306
     DB_NAME=wordpress
     DB_USER=wordpress
     DB_PASSWORD=password_database_anda
     WP_HOME=https://wp.example.com
     WP_SITEURL=https://wp.example.com
     ```
   *(Salts keamanan akan dibuat secara otomatis jika tidak Anda isi secara eksplisit)*
6. Klik **Deploy**. Coolify akan melakukan build image dan mengarahkan reverse proxy secara otomatis dengan SSL Let's Encrypt!

---

### Opsi B: Deploy via Docker Compose di Coolify

1. Di Dashboard Coolify, pilih **+ New** > **Docker Compose**.
2. Paste isi dari file `docker-compose.yml`.
3. Di tab **Environment Variables**, masukkan variabel database dan domain Anda.
4. Set domain pada service `wordpress` di Coolify.
5. Klik **Deploy**. Stack akan menyalakan WordPress, MariaDB, dan Redis sekaligus.

---

## ⚡ Mengaktifkan Redis Object Cache di WordPress

Karena ekstensi `redis` dan `igbinary` sudah terpasang di image ini:
1. Masuk ke **WP Admin** > **Plugins** > **Add New**.
2. Cari dan install plugin **Redis Object Cache** (oleh Till Krüss).
3. Buka **Settings** > **Redis** > Klik **Enable Object Cache**.
4. Status akan langsung berubah menjadi **Connected** ke container Redis.

---

## 🔒 Jaminan Keamanan & Persistensi Data (`wp-content` & Database)

Image ini dirancang khusus agar **seluruh data setup (plugin, theme, upload media, database, dan konfigurasi) TIDAK AKAN HILANG ATAU TERTIMPA** saat:
- Coolify melakukan **Redeploy** (misal setelah commit Git baru atau restart container).
- Docker menjalankan `docker compose down` dan `docker compose up -d`.

### Bagaimana Sistem Ini Menjaga Data Anda?

1. **Volume Persisten Terpisah**:
   - Direktori `/var/www/html` disimpan di named volume Docker (`wp_data`).
   - Direktori database `/var/lib/mysql` disimpan di named volume Docker (`db_data`).
   - Saat container dihentikan atau dihapus pada proses redeploy, volume ini tetap berada di storage host dan tidak ikut terhapus.
2. **Proteksi di `docker-entrypoint.sh`**:
   - Sebelum menyalin apapun, script mengecek apakah file `index.php` atau `wp-includes` sudah ada di volume.
   - Jika sudah ada instalasi WordPress sebelumnya, script akan mencetak:
     `>> [Persistent] Existing WordPress files detected. Preserving all existing data (no files overwritten).`
     dan **tidak akan menyalin ulang atau menimpa berkas yang ada** (menggunakan flag `cp -rn` *no-clobber*).
   - Berkas `wp-config.php` yang sudah disesuaikan tidak akan diganti jika sudah ada.
   - Pengecekan permission hanya dijalankan jika user/grup bukan `www-data`, sehingga proses reboot tetap instan meskipun terdapat ratusan ribu berkas upload.

> [!WARNING]
> Jangan pernah menjalankan perintah `docker compose down -v` di server produksi, karena flag `-v` memerintahkan Docker untuk menghapus seluruh volume persisten beserta isinya. Gunakan `docker compose down` biasa.

---

## 📥 Jaminan Kompatibilitas Download Plugin, Theme, & Update WordPress

Agar sistem download dan update WordPress berjalan mulus tanpa bentrok, hambatan permission, atau timeout, konfigurasi berikut telah diterapkan secara menyeluruh:

1. **`FS_METHOD = direct`**:
   - Menghilangkan *prompt* permintaan kredensial FTP saat menginstal plugin/tema dari dashboard WordPress.
2. **Direktori `wp-content/upgrade` & `WP_TEMP_DIR` Khusus**:
   - WordPress mengalirkan (*stream*) file zip dari `wordpress.org` ke folder upgrade sebelum diekstrak.
   - Script entrypoint secara otomatis membuat dan memastikan kepemilikan folder `wp-content/upgrade`, `plugins`, `themes`, dan `uploads` ke `www-data:www-data` setiap container dinyalakan.
   - `WP_TEMP_DIR` diarahkan ke volume yang sama untuk mencegah masalah *cross-device link* atau *out of space* di `/tmp`.
3. **Sertifikat SSL Root Terverifikasi (`ca-certificates`)**:
   - Memastikan request keluar (`wp_remote_get`) ke API `api.wordpress.org` dan `downloads.wordpress.org` tidak pernah gagal akibat error `cURL error 60: SSL certificate problem`.
4. **Batas Waktu dan Ukuran Longgar (PHP & Socket)**:
   - `upload_max_filesize = 128M` & `post_max_size = 128M` (mendukung upload tema besar seperti Divi, Astra Pro, Elementor).
   - `max_execution_time = 300` & `default_socket_timeout = 300` (5 menit) agar download paket update tidak putus di tengah jalan pada koneksi lambat.
5. **Paket `unzip` dan Ekstensi `zip`**:
   - Mendukung proses ekstraksi arsip zip native berkecepatan tinggi baik via WP-CLI maupun antarmuka web.
6. **Keamanan Caddy Non-Intrusif**:
   - Proteksi Caddy memblokir eksekusi PHP ilegal hanya di `/wp-content/uploads/*.php`, sehingga direktori `plugins`, `themes`, dan `upgrade` tetap berfungsi penuh tanpa terblokir.

---

## 🧪 Menjalankan Secara Lokal (Opsional)

Jika ingin menguji di komputer lokal terlebih dahulu:
```bash
# Salin konfigurasi environment
cp .env.example .env

# Jalankan container
docker compose up -d --build

# Buka di browser
# http://localhost
```
