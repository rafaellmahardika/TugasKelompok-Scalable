<?php
/**
 * Monolithic stateful backend.
 * Single path: server.php?aksi=...
 */

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

header('Content-Type: application/json; charset=utf-8');

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    echo json_encode(['status' => 'error', 'pesan' => 'File config.php belum dibuat.']);
    exit;
}

$config = require $configFile;

$dbConfig = $config['db'] ?? [];
$conn = new mysqli(
    $dbConfig['host'] ?? '127.0.0.1',
    $dbConfig['user'] ?? '',
    $dbConfig['pass'] ?? '',
    $dbConfig['name'] ?? 'db_puisi_scalable2'
);

if ($conn->connect_error) {
    echo json_encode(['status' => 'error', 'pesan' => 'Koneksi database gagal.']);
    exit;
}

$conn->set_charset('utf8mb4');
$aksi = $_GET['aksi'] ?? '';

function respond(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requireLogin(): int
{
    if (!isset($_SESSION['user_id'])) {
        respond(['status' => 'error', 'pesan' => 'Akses ditolak. Silakan login terlebih dahulu.']);
    }
    return (int) $_SESSION['user_id'];
}

function lambdaUrl(array $config): string
{
    return rtrim((string) ($config['aws']['lambda_url'] ?? ''), '/');
}

function invokeImageService(array $config, array $params): string
{
    $baseUrl = lambdaUrl($config);
    if ($baseUrl === '' || str_contains($baseUrl, 'GANTI_DENGAN')) {
        throw new RuntimeException('Lambda Function URL belum dikonfigurasi.');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL belum terpasang di server.');
    }

    $url = $baseUrl . '/?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPGET => true,
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Gagal menghubungi Lambda: ' . $curlError);
    }

    if ($statusCode !== 200 || !str_contains(strtolower($contentType), 'image/jpeg')) {
        $detail = trim(substr($body, 0, 300));
        throw new RuntimeException("Lambda mengembalikan HTTP {$statusCode}. {$detail}");
    }

    return $body;
}

function createS3Client(array $config)
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        throw new RuntimeException('AWS SDK for PHP belum terpasang. Jalankan composer install.');
    }

    require_once $autoload;

    if (!class_exists('\Aws\S3\S3Client')) {
        throw new RuntimeException('AWS SDK for PHP tidak ditemukan.');
    }

    $bucket = (string) ($config['aws']['bucket'] ?? '');
    if ($bucket === '' || str_contains($bucket, 'GANTI_DENGAN')) {
        throw new RuntimeException('Nama bucket S3 belum dikonfigurasi.');
    }

    return new \Aws\S3\S3Client([
        'version' => 'latest',
        'region' => $config['aws']['region'] ?? 'us-east-1',
    ]);
}

function imageKeyFor(int $userId, int $puisiId): string
{
    return "puisi/user-{$userId}/puisi-{$puisiId}.jpg";
}

if ($aksi === 'register') {
    $username = trim($_POST['username'] ?? '');
    $passwordRaw = $_POST['password'] ?? '';
    $nama = trim($_POST['nama'] ?? '');
    $noId = trim($_POST['no_id'] ?? '');

    if ($username === '' || $passwordRaw === '' || $nama === '' || $noId === '') {
        respond(['status' => 'error', 'pesan' => 'Semua data registrasi wajib diisi.']);
    }

    $password = password_hash($passwordRaw, PASSWORD_DEFAULT);
    $stmt = $conn->prepare(
        'INSERT INTO users (username, password, nama, no_id) VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('ssss', $username, $password, $nama, $noId);

    if (!$stmt->execute()) {
        if ($conn->errno === 1062) {
            respond(['status' => 'error', 'pesan' => 'Username atau nomor ID sudah digunakan.']);
        }
        respond(['status' => 'error', 'pesan' => 'Registrasi gagal.']);
    }

    respond(['status' => 'success', 'pesan' => 'Registrasi berhasil. Silakan login.']);
}

if ($aksi === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare('SELECT id, username, password, nama FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if (!$row || !password_verify($password, $row['password'])) {
        respond(['status' => 'error', 'pesan' => 'Username atau password salah.']);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['id'];
    $_SESSION['username'] = $row['username'];
    $_SESSION['nama'] = $row['nama'];

    respond([
        'status' => 'success',
        'pesan' => 'Login berhasil.',
        'user' => [
            'username' => $row['username'],
            'nama' => $row['nama'],
        ],
    ]);
}

if ($aksi === 'submit_puisi') {
    $userId = requireLogin();

    $judul = trim($_POST['judul'] ?? '');
    $isi = trim($_POST['isi'] ?? '');
    $bait = trim($_POST['bait'] ?? '');
    $kategori = trim($_POST['kategori'] ?? '');
    $keyword = trim($_POST['keyword'] ?? '');
    $template = basename(trim($_POST['template'] ?? ''));

    $allowedTemplates = ['latar1.jpeg', 'latar2.jpeg', 'latar3.jpeg', 'latar4.jpeg'];
    if ($judul === '' || $isi === '' || $bait === '' || $kategori === '' || $keyword === '') {
        respond(['status' => 'error', 'pesan' => 'Judul, isi, bait, kategori, dan keyword wajib diisi.']);
    }
    if (!in_array($template, $allowedTemplates, true)) {
        respond(['status' => 'error', 'pesan' => 'Template gambar tidak valid.']);
    }

    // Penulis tidak dikirim dari browser. Backend mengambilnya dari session.
    $penulis = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Anonim';
    $tglSubmit = date('Y-m-d H:i:s');
    $uploadedKey = null;

    try {
        $conn->begin_transaction();

        $stmt = $conn->prepare(
            'INSERT INTO puisi (user_id, judul, tgl_submit, isi, kategori, keyword, gambar)
             VALUES (?, ?, ?, ?, ?, ?, NULL)'
        );
        $stmt->bind_param('isssss', $userId, $judul, $tglSubmit, $isi, $kategori, $keyword);
        if (!$stmt->execute()) {
            throw new RuntimeException('Gagal menyimpan data puisi.');
        }

        $puisiId = (int) $conn->insert_id;
        $uploadedKey = imageKeyFor($userId, $puisiId);

        // Lambda menghasilkan JPEG final secara stateless.
        $jpeg = invokeImageService($config, [
            'template' => $template,
            'judul' => $judul,
            'penulis' => $penulis,
            'bait' => $bait,
        ]);

        $s3 = createS3Client($config);
        $s3->putObject([
            'Bucket' => $config['aws']['bucket'],
            'Key' => $uploadedKey,
            'Body' => $jpeg,
            'ContentType' => 'image/jpeg',
            'CacheControl' => 'public, max-age=31536000',
        ]);

        $stmtUpdate = $conn->prepare('UPDATE puisi SET gambar = ? WHERE id = ? AND user_id = ?');
        $stmtUpdate->bind_param('sii', $uploadedKey, $puisiId, $userId);
        if (!$stmtUpdate->execute()) {
            throw new RuntimeException('Gambar berhasil dibuat, tetapi metadata gambar gagal disimpan.');
        }

        $conn->commit();
        respond([
            'status' => 'success',
            'pesan' => 'Puisi dan gambar berhasil disimpan.',
            'data' => [
                'id' => $puisiId,
                'gambar' => $uploadedKey,
            ],
        ]);
    } catch (Throwable $e) {
        $conn->rollback();

        // S3 tidak ikut transaksi MySQL, jadi hapus objek jika sudah sempat terbuat.
        if ($uploadedKey !== null) {
            try {
                $s3 = createS3Client($config);
                $s3->deleteObject([
                    'Bucket' => $config['aws']['bucket'],
                    'Key' => $uploadedKey,
                ]);
            } catch (Throwable $cleanupError) {
                // Cleanup best-effort; error utama tetap dikembalikan ke user.
            }
        }

        respond(['status' => 'error', 'pesan' => $e->getMessage()]);
    }
}

if ($aksi === 'daftar_puisi') {
    $userId = requireLogin();
    $result = $conn->query(
        'SELECT p.id, p.judul, p.tgl_submit, p.kategori, p.keyword,
                p.gambar, u.nama AS penulis
         FROM puisi p
         INNER JOIN users u ON u.id = p.user_id
         WHERE p.user_id = ' . $userId . '
         ORDER BY p.id DESC'
    );

    $baseUrl = rtrim((string) ($config['public_base_url'] ?? ''), '/');
    $daftar = [];

    while ($row = $result->fetch_assoc()) {
        $key = $row['gambar'];
        $row['gambar_url'] = ($key && $baseUrl !== '')
            ? $baseUrl . '/' . str_replace('%2F', '/', rawurlencode($key))
            : ($key ? '/' . str_replace('%2F', '/', rawurlencode($key)) : null);
        $daftar[] = $row;
    }

    respond(['status' => 'success', 'data' => $daftar]);
}

if ($aksi === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }
    session_destroy();
    respond(['status' => 'success', 'pesan' => 'Berhasil keluar dari akun.']);
}

respond(['status' => 'error', 'pesan' => 'Aksi tidak dikenali.']);
