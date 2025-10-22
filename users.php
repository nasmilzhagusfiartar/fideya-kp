<?php
include 'auth_check.php';
include 'db_connect.php';

// HANYA UNTUK ADMIN
if (!isAdmin()) {
    header("Location: dashboard.php");
    exit;
}

$error_message = '';
$success_message = '';

// **REVISI: Mendefinisikan email user yang sedang login dengan aman menggunakan Null Coalescing (??)**
// Ini mencegah "Warning: Undefined array key" jika 'user_email' tidak ada dalam sesi.
$logged_in_email = $_SESSION['user_email'] ?? null;


// 1. Logika CRUD User
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';
    $id = $_POST['user_id'] ?? null;
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? '';
    $password = $_POST['password'] ?? '';

    // Logika Hapus User
    if ($action === 'delete') {
        // Pengecekan Keamanan Tambahan: Admin tidak bisa menghapus akunnya sendiri (di sisi server)
        $can_delete = true;
        if ($logged_in_email && $id) {
            $check_stmt = $conn->prepare("SELECT email FROM users WHERE id=?");
            $check_stmt->bind_param("i", $id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $target_user = $check_result->fetch_assoc();
            $check_stmt->close();

            if ($target_user && $target_user['email'] === $logged_in_email) {
                $error_message = "Anda tidak diizinkan menghapus akun Anda sendiri.";
                $can_delete = false;
            }
        }

        if ($can_delete) {
            $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $success_message = "User berhasil dihapus.";
            } else {
                $error_message = "Gagal menghapus user: " . $conn->error;
            }
            $stmt->close();
        }
    } 
    // Logika Simpan (Tambah/Edit)
    elseif ($action === 'save') {
        // Validasi dasar
        if (empty($name) || empty($email) || empty($role)) {
            $error_message = "Nama, Email, dan Peran wajib diisi.";
        }
        
        $is_new_user = empty($id);
        $stmt = null; // Inisialisasi $stmt

        if (!empty($error_message)) {
            // Lewati proses DB jika ada error validasi
        } elseif (!empty($password)) {
            // Gunakan MD5 untuk kesamaan dengan login, GANTI dengan password_hash() untuk produksi
            $password_hash = MD5($password); 
            
            if ($is_new_user) {
                // INSERT
                $sql = "INSERT INTO users (name, email, role, password_hash) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssss", $name, $email, $role, $password_hash);
            } else {
                // UPDATE (dengan password baru)
                $sql = "UPDATE users SET name=?, email=?, role=?, password_hash=? WHERE id=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssi", $name, $email, $role, $password_hash, $id);
            }
        } else {
            if ($is_new_user) {
                $error_message = "Password wajib diisi untuk user baru.";
            } else {
                // UPDATE (tanpa password baru)
                $sql = "UPDATE users SET name=?, email=?, role=? WHERE id=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssi", $name, $email, $role, $id);
            }
        }

        if (isset($stmt)) {
            if ($stmt->execute()) {
                $success_message = "User berhasil disimpan.";
            } else {
                // MySQL Error 1062 = Duplicate entry (kemungkinan email)
                if ($conn->errno == 1062) {
                     $error_message = "Gagal menyimpan user: Email mungkin sudah digunakan.";
                } else {
                     $error_message = "Gagal menyimpan user: " . $conn->error;
                }
            }
            $stmt->close();
        }
    }
    
    // Redirect untuk menghindari form resubmission
    if (!empty($success_message) || !empty($error_message)) {
        $status = empty($success_message) ? 'error' : 'success';
        $msg = empty($success_message) ? $error_message : $success_message;
        
        header("Location: users.php?status=" . $status . "&msg=" . urlencode($msg));
        exit;
    }
}

// Ambil pesan dari URL (setelah redirect)
if (isset($_GET['status']) && isset($_GET['msg'])) {
    if ($_GET['status'] === 'success') {
        $success_message = urldecode($_GET['msg']);
    } else {
        $error_message = urldecode($_GET['msg']);
    }
}

// 2. Ambil Daftar User dari Database
$users = [];
$result = $conn->query("SELECT id, name, email, role FROM users ORDER BY role, name");
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$conn->close();

$role_display = ucfirst($current_user_role); 
$initial_char = strtoupper(substr($current_user_name, 0, 1));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen User - PsiArsip</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .sidebar-item:hover, .sidebar-item.active { background-color: #2563eb; }
        .user-avatar { background-color: #f59e0b; }
        #sidebar { transition: transform 0.3s ease-in-out; }
        #user-modal { display: none; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 font-sans">
    <div class="relative min-h-screen md:flex">
        <header class="md:hidden flex justify-between items-center p-4 bg-blue-800 text-white shadow-md z-10">
            <button id="hamburger-btn" class="focus:outline-none"><i class="fas fa-bars fa-lg"></i></button>
            <h1 class="text-xl font-bold">PsiArsip</h1>
            <div class="w-8"></div>
        </header>

        <aside id="sidebar" class="bg-blue-800 text-white w-64 flex-col fixed inset-y-0 left-0 transform -translate-x-full md:relative md:translate-x-0 md:flex z-30">
            <div class="hidden md:flex items-center justify-center p-6 text-2xl font-bold border-b border-blue-700">PsiArsip</div>
            <nav class="flex-1 p-4 space-y-2">
                <a href="dashboard.php" class="sidebar-item flex items-center p-3 rounded-lg transition duration-200"><i class="fas fa-tachometer-alt w-6 text-center mr-3"></i>Dashboard</a>
                <a href="arsip.php" class="sidebar-item flex items-center p-3 rounded-lg transition duration-200"><i class="fas fa-folder-open w-6 text-center mr-3"></i>Arsip Pasien</a>
                <a href="pencarian.php" class="sidebar-item flex items-center p-3 rounded-lg transition duration-200"><i class="fas fa-search w-6 text-center mr-3"></i>Pencarian Global</a>
                <a href="users.php" id="menu-users" class="sidebar-item active flex items-center p-3 rounded-lg transition duration-200 admin-only"><i class="fas fa-users-cog w-6 text-center mr-3"></i>Manajemen User</a>
            </nav>
            <div class="p-4 border-t border-blue-700">
                <div class="flex items-center mb-4">
                    <div id="user-avatar" class="user-avatar w-10 h-10 rounded-full flex items-center justify-center font-bold text-xl mr-3"><?php echo $initial_char; ?></div>
                    <span id="user-role-display" class="font-semibold"><?php echo $role_display; ?></span>
                </div>
                <a href="?logout=true" id="logout-button" class="sidebar-item flex items-center p-3 rounded-lg transition duration-200"><i class="fas fa-sign-out-alt w-6 text-center mr-3"></i>Keluar</a>
            </div>
        </aside>

        <div class="flex-1 flex flex-col overflow-hidden">
            <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-100 p-4 md:p-8">
                <div class="container mx-auto">
                    <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2">Manajemen User</h1>
                    <p class="text-gray-600 mt-1">Kelola akun Admin, Dokter, dan Owner klinik.</p>

                    <div class="bg-white p-6 rounded-xl shadow-md mt-8">
                        <div class="flex justify-between items-center mb-6">
                            <button id="btn-add-user" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition-all"><i class="fas fa-user-plus mr-2"></i>Tambah User</button>
                            <input type="text" id="search-user" placeholder="Cari nama atau email..." class="w-1/3 border rounded-lg px-4 py-2">
                        </div>
                        
                        <?php if ($success_message): ?>
                            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4"><?php echo $success_message; ?></div>
                        <?php elseif ($error_message): ?>
                            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4"><?php echo $error_message; ?></div>
                        <?php endif; ?>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left" id="user-table">
                                <thead><tr class="border-b bg-gray-50"><th class="p-4">Nama</th><th class="p-4">Email</th><th class="p-4">Peran</th><th class="p-4 text-center">Aksi</th></tr></thead>
                                <tbody id="user-table-body">
                                    <?php if (!empty($users)): ?>
                                        <?php foreach ($users as $u): ?>
                                            <tr class="border-b hover:bg-gray-50" data-id="<?php echo $u['id']; ?>" data-name="<?php echo htmlspecialchars($u['name']); ?>" data-email="<?php echo htmlspecialchars($u['email']); ?>" data-role="<?php echo htmlspecialchars($u['role']); ?>">
                                                <td class="p-4 font-medium"><?php echo htmlspecialchars($u['name']); ?></td>
                                                <td class="p-4"><?php echo htmlspecialchars($u['email']); ?></td>
                                                <td class="p-4"><span class="px-3 py-1 text-sm rounded-full <?php echo $u['role'] == 'admin' ? 'bg-red-100 text-red-800' : ($u['role'] == 'dokter' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'); ?>"><?php echo ucfirst($u['role']); ?></span></td>
                                                <td class="p-4 text-center space-x-2">
                                                    <button class="btn-edit text-blue-600"><i class="fas fa-edit"></i> Edit</button>
                                                    
                                                    <?php if ($u['email'] !== $logged_in_email): ?>
                                                         <form method="POST" action="users.php" class="inline" onsubmit="return confirm('Yakin ingin menghapus user <?php echo addslashes($u['name']); ?>?');">
                                                             <input type="hidden" name="action" value="delete">
                                                             <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                             <button type="submit" class="text-red-500 hover:text-red-700"><i class="fas fa-trash"></i> Hapus</button>
                                                         </form>
                                                    <?php else: ?>
                                                         <span class="text-gray-400 text-sm">Akun Anda</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="p-4 text-center text-gray-500">Belum ada data user.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
        <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-20 hidden"></div>

        <div id="user-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
            <div class="bg-white p-8 rounded-xl shadow-2xl w-full max-w-md">
                <h2 id="modal-title" class="text-2xl font-bold mb-6">Tambah User Baru</h2>
                <form id="user-form" method="POST" action="users.php">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" id="user-id" name="user_id">
                    <div class="space-y-4">
                        <input type="text" id="user-name" name="name" placeholder="Nama Lengkap" class="p-3 border rounded-lg w-full" required>
                        <input type="email" id="user-email" name="email" placeholder="Email" class="p-3 border rounded-lg w-full" required>
                        <select id="user-role" name="role" class="p-3 border rounded-lg w-full" required>
                            <option value="">Pilih Peran</option>
                            <option value="admin">Admin</option>
                            <option value="dokter">Dokter</option>
                            <option value="owner">Owner</option>
                        </select>
                        <input type="password" id="user-password" name="password" placeholder="Password (Kosongkan jika tidak diubah)" class="p-3 border rounded-lg w-full" required>
                        <p id="password-hint" class="text-sm text-red-500 hidden">Password wajib diisi untuk user baru.</p>
                    </div>
                    <div class="flex justify-end gap-4 mt-8"><button type="button" id="btn-cancel" class="bg-gray-300 text-gray-800 px-6 py-2 rounded-lg hover:bg-gray-400">Batal</button><button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700">Simpan User</button></div>
                </form>
            </div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Logika Sidebar Mobile
    const hamburgerBtn = document.getElementById('hamburger-btn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const toggleSidebar = () => {
        sidebar.classList.toggle('-translate-x-full');
        overlay.classList.toggle('hidden');
    };
    if (hamburgerBtn) hamburgerBtn.addEventListener('click', toggleSidebar);
    if (overlay) overlay.addEventListener('click', toggleSidebar);

    const userModal = document.getElementById('user-modal');
    const btnAddUser = document.getElementById('btn-add-user');
    const btnCancel = userModal.querySelector('#btn-cancel');
    const modalTitle = document.getElementById('modal-title');
    const passwordInput = document.getElementById('user-password');
    const passwordHint = document.getElementById('password-hint');
    const userIdInput = document.getElementById('user-id');
    const userTableBody = document.getElementById('user-table-body');
    const searchInput = document.getElementById('search-user');

    const showModal = (isEdit = false, data = {}) => {
        modalTitle.textContent = isEdit ? 'Edit User' : 'Tambah User Baru';
        document.getElementById('user-form').reset();
        
        userIdInput.value = isEdit ? data.id : '';
        // Password wajib diisi hanya untuk user baru
        passwordInput.required = !isEdit; 
        passwordHint.classList.toggle('hidden', isEdit);

        if (isEdit) {
            document.getElementById('user-name').value = data.name;
            document.getElementById('user-email').value = data.email;
            document.getElementById('user-role').value = data.role;
        }
        
        userModal.style.display = 'flex';
    };

    const hideModal = () => { userModal.style.display = 'none'; };

    btnAddUser.addEventListener('click', () => showModal(false));
    btnCancel.addEventListener('click', hideModal);
    window.addEventListener('click', (e) => { if (e.target === userModal) hideModal(); });

    // Event Listener untuk Edit (menggunakan delegation)
    userTableBody.addEventListener('click', (e) => {
        const editButton = e.target.closest('.btn-edit');
        if (editButton) {
            const row = editButton.closest('tr');
            const data = {
                id: row.dataset.id,
                name: row.dataset.name,
                email: row.dataset.email,
                role: row.dataset.role
            };
            showModal(true, data);
        }
    });

    // Search Filter (Client-side)
    searchInput.addEventListener('input', () => {
        const searchTerm = searchInput.value.toLowerCase();
        Array.from(userTableBody.rows).forEach(row => {
            const name = row.cells[0].textContent.toLowerCase();
            const email = row.cells[1].textContent.toLowerCase();
            
            row.style.display = (name.includes(searchTerm) || email.includes(searchTerm)) ? '' : 'none';
        });
    });
});
</script>
</body>
</html>