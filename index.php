<?php
session_start();
include 'db_connect.php';

// Jika user sudah login, arahkan ke dashboard.php
if (isset($_SESSION['user_role'])) {
    header("Location: dashboard.php");
    exit;
}

$login_error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $password = $_POST['password']; 
    $role = $_POST['role_selector'];

    // Prepared Statement
    $stmt = $conn->prepare("SELECT name, role, password_hash FROM users WHERE email = ? AND role = ?");
    $stmt->bind_param("ss", $email, $role);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // PENTING: Cek password (menggunakan MD5 untuk contoh ini)
        if (MD5($password) === $user['password_hash']) {
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = $user['name'];
            header("Location: dashboard.php");
            exit;
        } else {
            $login_error = "Email atau password salah.";
        }
    } else {
        $login_error = "Email, password, atau peran salah.";
    }
    $stmt->close();
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Fideya Arsip</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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
        
        <?php if ($login_error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?php echo $login_error; ?></span>
            </div>
        <?php endif; ?>

        <form id="login-form" method="POST" action="index.php">
            <div class="mb-4">
                <label for="email" class="block text-gray-700 mb-2">Email</label>
                <input type="email" id="email" name="email" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="email@example.com" required>
            </div>
            <div class="mb-4">
                <label for="password" class="block text-gray-700 mb-2">Password</label>
                <input type="password" id="password" name="password" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="••••••••" required>
            </div>
            <div class="mb-6">
                <label for="role-selector" class="block text-gray-700 mb-2">Login Sebagai</label>
                <select id="role-selector" name="role_selector" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="admin">Admin</option>
                    <option value="dokter">Dokter</option>
                    <option value="owner">Owner</option>
                </select>
            </div>
            <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded-lg hover:bg-blue-700 transition-all">Masuk</button>
        </form>
    </div>
    
    </body>
</html>