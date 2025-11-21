<?php
session_start();
include 'db_connect.php';

// Ambil alert jika ada
$alert = $_SESSION['alert'] ?? null;
unset($_SESSION['alert']);

// Jika sudah login dan tidak sedang memunculkan alert sukses
if (isset($_SESSION['user_role']) && !$alert) {
    header("Location: dashboard.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role_selector'];

    // Ambil user berdasarkan email + role
    $stmt = $conn->prepare("SELECT id, name, role, password_hash FROM users WHERE email = ? AND role = ?");
    $stmt->bind_param("ss", $email, $role);
    $stmt->execute();
    $result = $stmt->get_result();

    // Jika user ditemukan
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $stored_hash = $user['password_hash'];

        $login_valid = false;

        // CASE 1: bcrypt (hash modern)
        if (str_starts_with($stored_hash, '$2y$') || str_starts_with($stored_hash, '$2a$')) {
            if (password_verify($password, $stored_hash)) {
                $login_valid = true;
            }
        }

        // CASE 2: hash lama MD5
        elseif (md5($password) === $stored_hash) {
            $login_valid = true;

            // Upgrade password otomatis ke bcrypt
            $new_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmtUp = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmtUp->bind_param("si", $new_hash, $user['id']);
            $stmtUp->execute();
            $stmtUp->close();
        }

        // Jika login berhasil
        if ($login_valid) {
            $_SESSION['user_role'] = $user['role'];
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
        'text' => 'Email, password, atau peran salah.'
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

        <form method="POST" action="index.php">
            <div class="mb-4">
                <label class="block text-gray-700 mb-2">Email</label>
                <input type="email" name="email" class="w-full px-4 py-2 border rounded-lg focus:ring-blue-500" required>
            </div>

            <div class="mb-4">
                <label class="block text-gray-700 mb-2">Password</label>
                <input type="password" name="password" class="w-full px-4 py-2 border rounded-lg focus:ring-blue-500" required>
            </div>

            <div class="mb-6">
                <label class="block text-gray-700 mb-2">Login Sebagai</label>
                <select name="role_selector" class="w-full px-4 py-2 border rounded-lg focus:ring-blue-500">
                    <option value="admin">Admin</option>
                    <option value="dokter">Dokter</option>
                    <option value="owner">Owner</option>
                </select>
            </div>

            <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded-lg hover:bg-blue-700">
                Masuk
            </button>
        </form>
    </div>

<script>
const alertData = <?php echo json_encode($alert); ?>;

if (alertData) {
    setTimeout(() => {
        Swal.fire({
            icon: alertData.type,
            title: alertData.title,
            text: alertData.text,
            timer: alertData.type === 'success' ? 1300 : undefined,
            showConfirmButton: alertData.type !== 'success'
        }).then(() => {
            if (alertData.redirect) {
                window.location.href = alertData.redirect;
            }
        });
    }, 300);
}
</script>

</body>
</html>
