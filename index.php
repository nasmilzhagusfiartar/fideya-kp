<?php
session_start();
include 'db_connect.php';

// Ambil alert jika ada
$alert = $_SESSION['alert'] ?? null;
unset($_SESSION['alert']);

// Jika sudah login dan tidak ada alert sukses
if (isset($_SESSION['user_role']) && !$alert) {
    header("Location: dashboard.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // Validasi Server Side (Jaga-jaga jika JS dimatikan)
    if (empty($email) || empty($password)) {
        $_SESSION['alert'] = [
            'type' => 'warning',
            'title' => 'Form Kosong',
            'text' => 'Harap isi semua kolom!'
        ];
        header("Location: index.php");
        exit;
    }

    // Ambil user berdasarkan email saja (role otomatis dari DB)
    $stmt = $conn->prepare("SELECT id, name, role, password_hash FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    // Jika user ditemukan
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $stored_hash = $user['password_hash'];
        $login_valid = false;

        // CASE 1: bcrypt
        if (str_starts_with($stored_hash, '$2y$') || str_starts_with($stored_hash, '$2a$')) {
            if (password_verify($password, $stored_hash)) {
                $login_valid = true;
            }
        }

        // CASE 2: MD5 lama
        elseif (md5($password) === $stored_hash) {
            $login_valid = true;

            // Upgrade hash ke bcrypt
            $new_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmtUp = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmtUp->bind_param("si", $new_hash, $user['id']);
            $stmtUp->execute();
            $stmtUp->close();
        }

        // Jika login sukses
        if ($login_valid) {
            $_SESSION['user_role'] = $user['role']; // role otomatis
            $_SESSION['user_name'] = $user['name'];

            $_SESSION['alert'] = [
                'type' => 'success',
                'title' => 'Login Berhasil!',
                'text' => 'Selamat datang kembali, ' . $user['name'] . '.',
                'redirect' => 'dashboard.php'
            ];

            header("Location: index.php");
            exit;
        }
    }

    // Jika gagal login
    $_SESSION['alert'] = [
        'type' => 'error',
        'title' => 'Login Gagal',
        'text' => 'Email atau password salah.'
    ];

    header("Location: index.php");
    exit;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Fideya</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="flex items-center justify-center min-h-screen" style="background: linear-gradient(120deg, #E0F2FE, #FFFFFF);">

    <div class="bg-white p-8 rounded-xl shadow-lg w-full max-w-md">
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold text-blue-600">Fideya Psikologi</h1>
            <p class="text-xl text-gray-600 mt-2">Selamat Datang</p>
            <p class="text-gray-500">Masuk untuk mengakses sistem arsip.</p>
        </div>

        <form id="loginForm" method="POST" action="index.php">
            <div class="mb-4">
                <label class="block text-gray-700 mb-2">Email</label>
                <input type="email" name="email" id="email" class="w-full px-4 py-2 border rounded-lg focus:ring-blue-500">
            </div>

            <div class="mb-6">
                <label class="block text-gray-700 mb-2">Password</label>
                <input type="password" name="password" id="password" class="w-full px-4 py-2 border rounded-lg focus:ring-blue-500">
            </div>

            <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded-lg hover:bg-blue-700 transition duration-200">
                Masuk
            </button>
        </form>
    </div>

<script>
// 1. Validasi Client-Side (Cek Field Kosong)
document.getElementById('loginForm').addEventListener('submit', function(e) {
    const email = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value.trim();

    // Jika email atau password kosong
    if (!email || !password) {
        e.preventDefault(); // Mencegah form dikirim ke PHP
        
        Swal.fire({
            icon: 'warning',
            title: 'Form Belum Lengkap',
            text: 'Harap isi Email dan Password terlebih dahulu!',
            confirmButtonColor: '#2563eb' // Sesuai warna tema
        });
    }
    // Jika terisi, form akan lanjut submit ke index.php
});

// 2. Alert Server-Side (Login Berhasil/Gagal dari PHP)
const alertData = <?php echo json_encode($alert); ?>;

if (alertData) {
    // Beri sedikit delay agar tidak bentrok dengan loading browser
    setTimeout(() => {
        Swal.fire({
            icon: alertData.type,
            title: alertData.title,
            text: alertData.text,
            timer: alertData.type === 'success' ? 1300 : undefined,
            showConfirmButton: alertData.type !== 'success',
            confirmButtonColor: '#2563eb'
        }).then(() => {
            if (alertData.redirect) {
                window.location.href = alertData.redirect;
            }
        });
    }, 100);
}
</script>

</body>
</html>