# Deployment AWS - Tugas Scalable Galeri Puisi

> **Target tugas**: frontend statis di S3/CloudFront, service gambar stateless di Lambda, sedangkan skrip PHP pengolahan submit dan database tetap di EC2. Hands-on menetapkan region **us-east-1** untuk resource yang dibuat pada panduan tersebut.

## 0. Sebelum membuka AWS Console

Kerjakan seluruh coding dan testing lokal terlebih dahulu. Karena sesi AWS tim hanya sekitar 3 jam, jangan gunakan waktu AWS untuk menulis/mencari-cari kode.

Yang perlu sudah ada di komputer:

```text
index.html
server.php
config.example.php
composer.json
database/schema.sql
lambda/index.mjs
lambda/package.json
latar1.jpeg
latar2.jpeg
latar3.jpeg
latar4.jpeg
```

Template `latar1.jpeg` s.d. `latar4.jpeg` boleh diganti dengan template resmi yang diberikan dosen.

---

## 1. Buat EC2 terlebih dahulu: PHP + MySQL

### Konfigurasi EC2

- Region: `us-east-1`
- OS: Ubuntu LTS
- Instance: `t2.micro` (mengikuti tugas awal)
- Security Group inbound:
  - SSH `22` dari IP kamu
  - HTTP `80` dari `0.0.0.0/0`
- Jangan buka MySQL port `3306` ke internet. Database cukup dipakai dari EC2 itu sendiri.

Setelah instance running, catat:

```text
Public IPv4 address
Public IPv4 DNS
```

Untuk CloudFront nanti yang dipakai sebagai custom origin adalah **Public IPv4 DNS** EC2.

### Install software

SSH ke EC2 lalu jalankan:

```bash
sudo apt update
sudo apt install -y apache2 php php-mysql php-curl php-mbstring php-xml unzip git composer
```

Periksa:

```bash
php -v
composer --version
```

Aktifkan Apache:

```bash
sudo systemctl enable --now apache2
```

---

## 2. Siapkan MySQL

Masuk MySQL:

```bash
sudo mysql
```

Buat database dan user aplikasi. Ganti password contoh:

```sql
CREATE DATABASE db_puisi_scalable2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'puisi_app'@'localhost' IDENTIFIED BY 'PASSWORD_DB_KALIAN';
GRANT ALL PRIVILEGES ON db_puisi_scalable2.* TO 'puisi_app'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Copy project ke web root, lalu import schema:

```bash
sudo mkdir -p /var/www/html/puisi
sudo chown -R $USER:$USER /var/www/html/puisi
```

Letakkan file project di `/var/www/html/puisi`, lalu:

```bash
mysql -u puisi_app -p db_puisi_scalable2 < /var/www/html/puisi/database/schema.sql
```

> Jangan gunakan password database di GitHub. `config.php` sengaja di-ignore oleh Git.

---

## 3. Siapkan PHP backend

Di EC2:

```bash
cd /var/www/html/puisi
cp config.example.php config.php
composer install
```

Edit:

```bash
nano config.php
```

Isi kira-kira:

```php
return [
    'db' => [
        'host' => '127.0.0.1',
        'user' => 'puisi_app',
        'pass' => 'PASSWORD_DB_KALIAN',
        'name' => 'db_puisi_scalable2',
    ],
    'aws' => [
        'region' => 'us-east-1',
        'bucket' => 'NAMA_BUCKET',
        'lambda_url' => 'https://ID.lambda-url.us-east-1.on.aws',
    ],
    'public_base_url' => 'https://ID_CLOUDFRONT.cloudfront.net',
];
```

`public_base_url` baru bisa diisi setelah CloudFront jadi. Sebelum itu boleh kosong.

Cek PHP:

```bash
php -l server.php
```

Tes awal dari browser setelah Apache siap:

```text
http://PUBLIC_IP_EC2/server.php?aksi=daftar_puisi
```

Respons belum login seharusnya berupa JSON akses ditolak.

---

## 4. Hak akses EC2 ke S3

PHP di EC2 harus bisa meng-upload file JPEG ke S3. Cara yang paling cocok untuk lab adalah menggunakan IAM role/instance profile sehingga tidak perlu menaruh Access Key/Secret Key di `config.php`.

Pada environment lab yang menyediakan `LabRole`, gunakan role tersebut pada EC2 bila diizinkan oleh akun lab dan role tersebut memiliki permission S3 yang dibutuhkan.

Tes dari EC2:

```bash
aws sts get-caller-identity
```

Lalu, setelah bucket dibuat, uji upload kecil menggunakan AWS CLI bila diperlukan:

```bash
echo "uji" > /tmp/uji.txt
aws s3 cp /tmp/uji.txt s3://NAMA_BUCKET/uji.txt --region us-east-1
```

Jika perintah ini gagal karena permission, jangan membuat Access Key sembarangan. Periksa instance role/lab role terlebih dahulu.

---

## 5. Buat S3 bucket untuk frontend, template, dan gambar

Hands-on meminta region `us-east-1`, nama bucket unik, mematikan **Block all public access**, lalu memberi bucket policy hanya `s3:GetObject` untuk objek publik. Ikuti konfigurasi ini untuk tugas kelas meskipun dokumentasi AWS saat ini merekomendasikan pola yang lebih aman untuk penggunaan nyata.

Bucket policy:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "PublicReadGetObject",
      "Effect": "Allow",
      "Principal": "*",
      "Action": "s3:GetObject",
      "Resource": "arn:aws:s3:::NAMA_BUCKET/*"
    }
  ]
}
```

Aktifkan **Static website hosting**:

```text
Index document: index.html
Error document: boleh kosong / sesuai kebutuhan
```

Upload ke root bucket:

```text
index.html
latar1.jpeg
latar2.jpeg
latar3.jpeg
latar4.jpeg
```

Buat folder/key `puisi/` secara otomatis saat PHP meng-upload gambar final. Tidak perlu membuat folder fisik.

Tes endpoint website S3 sebelum membuat CloudFront.

---

## 6. Siapkan Lambda image service di komputer lokal

Masuk folder lambda:

```powershell
cd lambda
npm install
```

`package.json` sudah menggunakan:

```text
@aws-sdk/client-s3
jimp 0.22.12
```

Buat deployment ZIP di Windows PowerShell:

```powershell
Compress-Archive -Path index.mjs, package.json, node_modules -DestinationPath function.zip -Force
```

Yang berada di root ZIP harus langsung:

```text
index.mjs
package.json
node_modules/...
```

Bukan:

```text
lambda/function/index.mjs
```

---

## 7. Buat Lambda

Di Lambda console, tetap di `us-east-1`.

Pengaturan:

```text
Create function
Author from scratch
Function name: image-overlay-processor
Runtime: Node.js 24.x
Execution role: LabRole (sesuai hands-on bila tersedia)
```

Node.js 24.x memang masih merupakan runtime Lambda yang didukung AWS saat ini.

Upload `function.zip` melalui opsi upload ZIP.

### General configuration

Setidaknya:

```text
Memory: 256 MB atau 512 MB
Timeout: 10 seconds
```

### Environment variables

Tambahkan:

```text
BUCKET_NAME = NAMA_BUCKET
```

### Function URL

Buat Function URL dengan:

```text
Auth type: NONE
```

Salin URL-nya, misalnya:

```text
https://abc123.lambda-url.us-east-1.on.aws/
```

Tes langsung:

```text
https://abc123.lambda-url.us-east-1.on.aws/?template=latar1.jpeg&judul=Puisi%20Pertama&penulis=Nama%20Anggota&bait=Ini%20penggalan%20bait
```

Hasil yang benar adalah response JPEG berisi background template + judul + penulis + bait.

---

## 8. Update config PHP dengan Lambda URL

Kembali ke EC2:

```bash
cd /var/www/html/puisi
nano config.php
```

Isi:

```php
'lambda_url' => 'https://abc123.lambda-url.us-east-1.on.aws',
```

Tanpa slash terakhir juga boleh karena kode akan membersihkan slash.

---

## 9. CloudFront: origin utama S3

Buat satu distribution sesuai hands-on.

Origin utama:

```text
S3 Website Endpoint
```

Masukkan manual tanpa `http://` dan tanpa `/` terakhir, contoh:

```text
nama-bucket.s3-website.us-east-1.amazonaws.com
```

Protocol origin:

```text
HTTP only
Port: 80
```

Default behavior:

```text
Viewer protocol policy: Redirect HTTP to HTTPS
Allowed methods: GET, HEAD
Cache policy: CachingOptimized
Default root object: index.html
```

---

## 10. CloudFront: tambahkan Lambda origin

Di Origins -> Create origin:

```text
Origin domain:
abc123.lambda-url.us-east-1.on.aws

Protocol: HTTPS only
```

Jangan masukkan `https://` dan jangan masukkan `/` di belakang.

### Cache policy

Buat policy:

```text
Name: CacheWithQueryString
Query strings: All
```

Ini penting karena image service membaca `template`, `judul`, `penulis`, dan `bait` dari query string. Hands-on versi dasar menggunakan `text`; proyek kita memperluas query-nya untuk kebutuhan puisi.

### Behavior Lambda

Buat behavior:

```text
Path pattern: /fungsi*
Origin: Lambda origin
Viewer protocol policy: Redirect HTTP to HTTPS
Allowed methods: GET, HEAD
Cache policy: CacheWithQueryString
Origin request policy: AllViewerExceptHostHeader
Function associations: None
```

`AllViewerExceptHostHeader` memang ditujukan untuk origin Lambda Function URL karena menghapus Host header viewer dan CloudFront mengisi Host dengan domain origin Lambda.

---

## 11. CloudFront: tambahkan origin EC2 untuk PHP

### Mengapa ini perlu?

Frontend kita sekarang berada di CloudFront/S3, tetapi `server.php` dan MySQL tetap di EC2. Kalau browser memanggil IP EC2 langsung dari halaman HTTPS CloudFront, arsitekturnya menjadi lebih ribet karena origin berbeda.

Solusi paling sederhana untuk project kelas ini adalah menambahkan behavior khusus:

```text
/server.php*
```

yang diarahkan ke EC2.

### Tambah origin EC2

Di Origins -> Create origin:

```text
Origin domain: PUBLIC_DNS_EC2
Protocol: HTTP only
Port: 80
```

Contoh domain EC2 berbentuk seperti:

```text
ec2-xx-xx-xx-xx.compute-1.amazonaws.com
```

### Tambah behavior `/server.php*`

```text
Path pattern: /server.php*
Origin: EC2 origin
Viewer protocol policy: Redirect HTTP to HTTPS
Allowed methods: GET, HEAD, OPTIONS, PUT, PATCH, POST, DELETE
Cache policy: CachingDisabled
Origin request policy: AllViewer
```

Tujuan pengaturan ini:

- POST login/register/submit benar-benar diteruskan ke EC2.
- Query `aksi=...` diteruskan.
- Cookie PHP session diteruskan.
- response PHP tidak di-cache.

AWS mendokumentasikan `CachingDisabled` untuk konten dinamis dan `AllViewer` untuk meneruskan header, cookie, serta query string viewer ke origin.

> **Catatan penting**: behavior `/server.php*` ini adalah tambahan integrasi proyek. Hands-on menuliskan secara eksplisit behavior `* -> S3` dan `/fungsi* -> Lambda`; task proyek pada halaman akhir juga mensyaratkan PHP + database tetap di EC2. Jadi behavior EC2 adalah jembatan agar frontend static CloudFront dapat tetap memakai session PHP di EC2 tanpa CORS/mixed-content.

---

## 12. Update config PHP setelah CloudFront jadi

Misalnya distribution:

```text
https://d123example.cloudfront.net
```

Maka `config.php` di EC2:

```php
'public_base_url' => 'https://d123example.cloudfront.net',
```

Karena gambar final disimpan sebagai key seperti:

```text
puisi/user-1/puisi-7.jpg
```

frontend akan menampilkan:

```text
https://d123example.cloudfront.net/puisi/user-1/puisi-7.jpg
```

yang melewati behavior default `*` ke S3.

---

## 13. Upload final project ke S3

Yang masuk S3 sebagai konten statis:

```text
index.html
latar1.jpeg
latar2.jpeg
latar3.jpeg
latar4.jpeg
```

**Jangan** upload:

```text
server.php
config.php
composer.json
vendor/
```

PHP dan database tetap di EC2.

---

## 14. Uji end-to-end

### A. Static S3

Buka website endpoint S3 dan pastikan `index.html` tampil.

### B. CloudFront static

```text
https://d123example.cloudfront.net/index.html
https://d123example.cloudfront.net/latar1.jpeg
```

### C. Lambda direct

```text
https://abc123.lambda-url.us-east-1.on.aws/?template=latar1.jpeg&judul=Test&penulis=Tim&bait=Halo%20dunia
```

### D. Lambda lewat CloudFront

```text
https://d123example.cloudfront.net/fungsi?template=latar1.jpeg&judul=Test&penulis=Tim&bait=Halo%20dunia
```

### E. Login/register

Buka:

```text
https://d123example.cloudfront.net/
```

Register -> login.

Browser harus menerima session cookie dari response `server.php` melalui CloudFront.

### F. Preview

Setelah login:

1. isi judul
2. isi isi puisi
3. isi bait
4. pilih latar1
5. lihat preview
6. pilih latar2
7. preview berubah

### G. Submit

Klik Submit Puisi.

Urutan backend:

```text
Browser
  -> CloudFront /server.php
  -> EC2 PHP session
  -> Lambda image service
  -> JPEG
  -> S3 PutObject
  -> MySQL menyimpan nama file
```

### H. Gallery

`daftar_puisi` sekarang hanya mengambil puisi milik session user dan mengembalikan `gambar_url`, bukan menampilkan isi puisi.

### I. Cache Lambda

Untuk URL Lambda CloudFront yang **persis sama**, ulangi request yang sama dan periksa:

```text
X-Cache: Miss from cloudfront
X-Cache: Hit from cloudfront
```

Sesuai hands-on, cache hit berarti CloudFront dapat melayani hasil yang sudah di-cache tanpa mengeksekusi Lambda lagi.

---

## 15. Checklist sebelum demo

```text
[ ] Semua resource region us-east-1
[ ] EC2 aktif
[ ] Apache + PHP aktif
[ ] MySQL aktif
[ ] schema.sql sudah di-import
[ ] User DB aplikasi berhasil login
[ ] S3 bucket aktif
[ ] Block Public Access sesuai hands-on
[ ] Bucket policy GetObject aktif
[ ] Static website hosting aktif
[ ] index.html + 4 template ada di S3
[ ] Lambda aktif
[ ] BUCKET_NAME benar
[ ] Lambda Function URL aktif
[ ] Lambda direct menghasilkan JPEG
[ ] CloudFront default * -> S3
[ ] CloudFront /fungsi* -> Lambda
[ ] CloudFront /server.php* -> EC2
[ ] /server.php* tidak di-cache
[ ] /fungsi* memakai CacheWithQueryString
[ ] /fungsi* memakai AllViewerExceptHostHeader
[ ] Register berhasil
[ ] Login berhasil
[ ] Session tetap ada setelah pindah request
[ ] Preview template berubah
[ ] Submit berhasil
[ ] File JPEG muncul di S3
[ ] nama file JPEG masuk DB
[ ] Gallery hanya menampilkan gambar user yang login
[ ] curl/header cache test berhasil
```

## 16. Setelah demo selesai

Kalau resource benar-benar tidak diperlukan lagi, hapus resource AWS sesuai aturan lab agar tidak ada resource tertinggal.
