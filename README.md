# Tugas Scalable - Galeri Puisi

Upgrade dari tugas monolitik/stateful menjadi arsitektur S3 + CloudFront + Lambda, dengan PHP + MySQL tetap di EC2.

## Komponen

- **S3**: frontend statis (`index.html`) + template `latar1.jpeg` s.d. `latar4.jpeg` + gambar puisi hasil submit.
- **CloudFront**: distribusi HTTPS dan routing multi-origin.
- **Lambda**: microservice stateless untuk membuat gambar kutipan puisi.
- **EC2**: PHP `server.php` + MySQL + session stateful.

## Struktur

```text
.
├── database/schema.sql
├── index.html
├── server.php
├── config.example.php
├── composer.json
└── lambda/
    ├── index.mjs
    ├── package.json
    └── build-lambda.ps1
```

## Routing CloudFront

- `*` -> S3 static website
- `/fungsi*` -> Lambda Function URL
- `/server.php*` -> EC2 PHP

## Jalankan lokal

1. Letakkan project pada Apache/XAMPP.
2. Buat database dari `database/schema.sql`.
3. Copy `config.example.php` menjadi `config.php`.
4. Sesuaikan konfigurasi database lokal.
5. Untuk submit gambar, isi `aws.bucket` dan `aws.lambda_url` setelah resource AWS siap.
6. Untuk backend S3, jalankan `composer install`.
7. Letakkan `latar1.jpeg` sampai `latar4.jpeg` pada root project agar pilihan template dapat ditampilkan.
