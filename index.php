<?php
session_start();
include 'db_connect.php';

// Ambil alert dari session (kalau ada)
if (isset($_SESSION['alert'])) {
    $alert = $_SESSION['alert'];
    unset($_SESSION['alert']);
} else {
    $alert = null;
}

// ✅ JANGAN redirect langsung kalau masih ada alert sukses
if (isset($_SESSION['user_role']) && !$alert) {
    header("Location: dashboard.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $password = $_POST['password'];
    $role = $_POST['role_selector'];

    $stmt = $conn->prepare("SELECT name, role, password_hash FROM users WHERE email = ? AND role = ?");
    $stmt->bind_param("ss", $email, $role);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (md5($password) === $user['password_hash']) {
            // Login sukses
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = $user['name'];

            $_SESSION['alert'] = [
                'type' => 'success',
                'title' => 'Login Berhasil!',
                'text' => 'Selamat datang kembali, ' . $user['name'] . '.',
                'redirect' => 'dashboard.php'
            ];

            // Kembali ke index agar alert bisa tampil
            header("Location: index.php");
            exit;
        } else {
            $_SESSION['alert'] = [
                'type' => 'error',
                'title' => 'Login Gagal',
                'text' => 'Email atau password salah.'
            ];
        }
    } else {
        $_SESSION['alert'] = [
            'type' => 'error',
            'title' => 'Login Gagal',
            'text' => 'Email, password, atau peran tidak cocok.'
        ];
    }

    $stmt->close();
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            background: linear-gradient(120deg, #E0F2FE, #FFFFFF);
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen">
    <div class="bg-white p-8 rounded-xl shadow-lg w-full max-w-md">
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold text-blue-600">Fideya Psikologi</h1>
            <p class="text-xl text-gray-600 mt-2">Selamat Datang</p>
            <p class="text-gray-500">Masuk untuk mengakses sistem arsip.</p>
        </div>

        <form id="login-form" method="POST" action="index.php">
            <div class="mb-4">
                <label for="email" class="block text-gray-700 mb-2">Email</label>
                <input type="email" id="email" name="email"
                    class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="email@example.com" required>
            </div>
            <div class="mb-4">
                <label for="password" class="block text-gray-700 mb-2">Password</label>
                <input type="password" id="password" name="password"
                    class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="••••••••" required>
            </div>
            <div class="mb-6">
                <label for="role-selector" class="block text-gray-700 mb-2">Login Sebagai</label>
                <select id="role-selector" name="role_selector"
                    class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="admin">Admin</option>
                    <option value="dokter">Dokter</option>
                    <option value="owner">Owner</option>
                </select>
            </div>
            <button type="submit"
                class="w-full bg-blue-600 text-white py-2 rounded-lg hover:bg-blue-700 transition-all">
                Masuk
            </button>
        </form>
    </div>

  <script>
const alertData = <?php echo json_encode($alert); ?>;

if (alertData) {
    // Tampilkan alert segera setelah halaman dimuat
    setTimeout(() => {
        Swal.fire({
            icon: alertData.type,
            title: alertData.title,
            text: alertData.text,
            showConfirmButton: alertData.type !== 'success',
            timer: alertData.type === 'success' ? 1200 : undefined,
            timerProgressBar: true
        }).then(() => {
            if (alertData.type === 'success' && alertData.redirect) {
                window.location.href = alertData.redirect;
            }
        });
    }, 300); // jeda kecil 0.3 detik agar halus
}
</script>

</body>
</html>
