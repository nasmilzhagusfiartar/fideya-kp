<?php
// users.php - Manajemen user + auto-sync ke tabel doctors
// Pastikan file auth_check.php dan db_connect.php tersedia dan benar
include 'auth_check.php';
include 'db_connect.php';

// Hanya admin yang boleh akses
if (!isAdmin()) {
    header("Location: dashboard.php");
    exit;
}

$error_message = '';
$success_message = '';
$logged_in_email = $_SESSION['user_email'] ?? null;

// -----------------------
// Helper kecil
// -----------------------
function safe_redirect_with_msg($url, $status, $msg) {
    $location = $url . "?status=" . $status . "&msg=" . urlencode($msg);
    header("Location: " . $location);
    exit;
}

// -----------------------
// HANDLE POST (CRUD)
// -----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = isset($_POST['user_id']) && $_POST['user_id'] !== '' ? (int)$_POST['user_id'] : null;
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? '';
    $password = $_POST['password'] ?? '';

    // -----------------------
    // DELETE
    // -----------------------
    if ($action === 'delete') {
        if (!$id) {
            $error_message = "ID user tidak valid untuk penghapusan.";
        } else {
            // Cek target user (role + name)
            $stmtRole = $conn->prepare("SELECT role, name, email FROM users WHERE id = ?");
            $stmtRole->bind_param("i", $id);
            $stmtRole->execute();
            $resRole = $stmtRole->get_result();
            $target = $resRole->fetch_assoc();
            $stmtRole->close();

            if (!$target) {
                $error_message = "User tidak ditemukan.";
            } else {
                // Cegah admin menghapus akunnya sendiri
                if ($target['email'] === $logged_in_email) {
                    $error_message = "Anda tidak dapat menghapus akun Anda sendiri.";
                } else {
                    // Hapus user
                    $stmtDel = $conn->prepare("DELETE FROM users WHERE id = ?");
                    $stmtDel->bind_param("i", $id);
                    if ($stmtDel->execute()) {
                        // Jika target adalah dokter, hapus dari tabel doctors *jika* tidak ada user dokter lain dengan nama yang sama
                        if ($target['role'] === 'dokter') {
                            // Cek apakah ada user dokter lain dengan nama yang sama
                            $stmtChk = $conn->prepare("SELECT COUNT(*) AS cnt FROM users WHERE role = 'dokter' AND name = ? AND id <> ?");
                            $stmtChk->bind_param("si", $target['name'], $id);
                            $stmtChk->execute();
                            $resChk = $stmtChk->get_result();
                            $cntRow = $resChk->fetch_assoc();
                            $stmtChk->close();

                            if ((int)$cntRow['cnt'] === 0) {
                                // Hapus dari doctors
                                $stmtDelDoc = $conn->prepare("DELETE FROM doctors WHERE name = ?");
                                $stmtDelDoc->bind_param("s", $target['name']);
                                $stmtDelDoc->execute();
                                $stmtDelDoc->close();
                            }
                        }

                        $success_message = "User berhasil dihapus.";
                    } else {
                        $error_message = "Gagal menghapus user: " . $conn->error;
                    }
                    $stmtDel->close();
                }
            }
        }
    }

    // -----------------------
    // SAVE (INSERT / UPDATE)
    // -----------------------
    elseif ($action === 'save') {
        // Validasi dasar
        if ($name === '' || $email === '' || $role === '') {
            $error_message = "Nama, email, dan peran wajib diisi.";
        } else {
            $is_new_user = empty($id);

            // Jika user baru, password wajib
            if ($is_new_user && $password === '') {
                $error_message = "Password wajib diisi untuk user baru.";
            }
        }

        if (empty($error_message)) {
            // Ambil data lama untuk update logic sinkron dokter (jika update)
            $oldUser = null;
            if (!$is_new_user) {
                $stmtOld = $conn->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
                $stmtOld->bind_param("i", $id);
                $stmtOld->execute();
                $resOld = $stmtOld->get_result();
                $oldUser = $resOld->fetch_assoc();
                $stmtOld->close();

                if (!$oldUser) {
                    $error_message = "User yang akan diupdate tidak ditemukan.";
                }
            }
        }

        if (empty($error_message)) {
            // Prepare hashed password jika ada
            $password_hash = null;
            if (!empty($password)) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
            }

            // -----------------------
            // INSERT
            // -----------------------
            if ($is_new_user) {
                $sql = "INSERT INTO users (name, email, role, password_hash) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt === false) {
                    $error_message = "Gagal menyiapkan statement insert: " . $conn->error;
                } else {
                    $stmt->bind_param("ssss", $name, $email, $role, $password_hash);
                    if ($stmt->execute()) {
                        $new_user_id = $stmt->insert_id;

                        // Jika role dokter -> insert ke tabel doctors jika belum ada nama tersebut
                        if ($role === 'dokter') {
                            $stmtChkDoc = $conn->prepare("SELECT id FROM doctors WHERE name = ? LIMIT 1");
                            $stmtChkDoc->bind_param("s", $name);
                            $stmtChkDoc->execute();
                            $resChkDoc = $stmtChkDoc->get_result();
                            $existsDoc = $resChkDoc->fetch_assoc();
                            $stmtChkDoc->close();

                            if (!$existsDoc) {
                                $stmtInsDoc = $conn->prepare("INSERT INTO doctors (name) VALUES (?)");
                                $stmtInsDoc->bind_param("s", $name);
                                $stmtInsDoc->execute();
                                $stmtInsDoc->close();
                            }
                        }

                        $success_message = "User baru berhasil dibuat.";
                    } else {
                        if ($conn->errno == 1062) {
                            $error_message = "Email sudah digunakan.";
                        } else {
                            $error_message = "Gagal menyimpan user: " . $conn->error;
                        }
                    }
                    $stmt->close();
                }
            }

            // -----------------------
            // UPDATE
            // -----------------------
            else {
                // Update user (dengan atau tanpa password)
                if ($password_hash !== null) {
                    $sql = "UPDATE users SET name = ?, email = ?, role = ?, password_hash = ? WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("ssssi", $name, $email, $role, $password_hash, $id);
                } else {
                    $sql = "UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("sssi", $name, $email, $role, $id);
                }

                if ($stmt === false) {
                    $error_message = "Gagal menyiapkan statement update: " . $conn->error;
                } else {
                    if ($stmt->execute()) {

                        // --- SYNC LOGIC FOR DOCTORS ---
                        $oldRole = $oldUser['role'] ?? null;
                        $oldName = $oldUser['name'] ?? null;
                        $newRole = $role;
                        $newName = $name;

                        // Case A: old not dokter, new dokter -> add doctor if not exists
                        if ($oldRole !== 'dokter' && $newRole === 'dokter') {
                            $stmtChkDoc = $conn->prepare("SELECT id FROM doctors WHERE name = ? LIMIT 1");
                            $stmtChkDoc->bind_param("s", $newName);
                            $stmtChkDoc->execute();
                            $resChkDoc = $stmtChkDoc->get_result();
                            $existsDoc = $resChkDoc->fetch_assoc();
                            $stmtChkDoc->close();

                            if (!$existsDoc) {
                                $stmtInsDoc = $conn->prepare("INSERT INTO doctors (name) VALUES (?)");
                                $stmtInsDoc->bind_param("s", $newName);
                                $stmtInsDoc->execute();
                                $stmtInsDoc->close();
                            }
                        }

                        // Case B: old dokter, new not dokter -> remove doctor if no other dokter uses same name
                        if ($oldRole === 'dokter' && $newRole !== 'dokter') {
                            // cek jumlah user dokter (excluding this id) with same oldName
                            $stmtChk = $conn->prepare("SELECT COUNT(*) as cnt FROM users WHERE role = 'dokter' AND name = ? AND id <> ?");
                            $stmtChk->bind_param("si", $oldName, $id);
                            $stmtChk->execute();
                            $resChk = $stmtChk->get_result();
                            $cntRow = $resChk->fetch_assoc();
                            $stmtChk->close();

                            if ((int)$cntRow['cnt'] === 0) {
                                // safe to delete doctor entry
                                $stmtDelDoc = $conn->prepare("DELETE FROM doctors WHERE name = ?");
                                $stmtDelDoc->bind_param("s", $oldName);
                                $stmtDelDoc->execute();
                                $stmtDelDoc->close();
                            }
                        }

                        // Case C: both dokter and name changed -> update doctor name(s)
                        if ($oldRole === 'dokter' && $newRole === 'dokter' && $oldName !== $newName) {
                            // Update doctor rows with oldName to newName.
                            // But be careful: if a doctor with newName already exists, we should avoid creating duplicates.
                            $stmtExistsNew = $conn->prepare("SELECT id FROM doctors WHERE name = ? LIMIT 1");
                            $stmtExistsNew->bind_param("s", $newName);
                            $stmtExistsNew->execute();
                            $resExistsNew = $stmtExistsNew->get_result();
                            $existsNew = $resExistsNew->fetch_assoc();
                            $stmtExistsNew->close();

                            if ($existsNew) {
                                // If a doctor with newName exists, delete the oldName entry only if no other users point to it.
                                $stmtChkUsers = $conn->prepare("SELECT COUNT(*) as cnt FROM users WHERE role='dokter' AND name = ?");
                                $stmtChkUsers->bind_param("s", $oldName);
                                $stmtChkUsers->execute();
                                $resChkUsers = $stmtChkUsers->get_result();
                                $cntOld = $resChkUsers->fetch_assoc();
                                $stmtChkUsers->close();

                                if ((int)$cntOld['cnt'] === 0) {
                                    $stmtDelOld = $conn->prepare("DELETE FROM doctors WHERE name = ?");
                                    $stmtDelOld->bind_param("s", $oldName);
                                    $stmtDelOld->execute();
                                    $stmtDelOld->close();
                                }
                            } else {
                                // Rename the doctor entry
                                $stmtUpdateDoc = $conn->prepare("UPDATE doctors SET name = ? WHERE name = ?");
                                $stmtUpdateDoc->bind_param("ss", $newName, $oldName);
                                $stmtUpdateDoc->execute();
                                $stmtUpdateDoc->close();
                            }
                        }

                        $success_message = "User berhasil diperbarui.";
                    } else {
                        if ($conn->errno == 1062) {
                            $error_message = "Email sudah digunakan.";
                        } else {
                            $error_message = "Gagal mengupdate user: " . $conn->error;
                        }
                    }
                    $stmt->close();
                }
            } // end update/insert branch
        } // end if no validation error
    } // end save
    // -----------------------
    // Redirect with message (avoid resubmission)
    // -----------------------
    if (!empty($success_message) || !empty($error_message)) {
        $status = empty($success_message) ? 'error' : 'success';
        $msg = empty($success_message) ? $error_message : $success_message;
        if (isset($conn)) $conn->close();
        safe_redirect_with_msg('users.php', $status, $msg);
    }
} // end POST handling

// -----------------------
// GET: fetch users for listing
// -----------------------
$users = [];
if (!isset($conn) || $conn->connect_error) {
    include 'db_connect.php';
}
$res = $conn->query("SELECT id, name, email, role FROM users ORDER BY role, name");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $users[] = $row;
    }
    $res->free_result();
}
$conn->close();

// Display variables
$role_display = ucfirst($current_user_role ?? 'Guest');
$initial_char = strtoupper(substr($current_user_name ?? 'U', 0, 1));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Manajemen User - Fideya</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        <h1 class="text-xl font-bold hidden md:flex">Fideya</h1>
        <div class="w-8"></div>
    </header>

    <aside id="sidebar" class="bg-blue-800 text-white w-64 flex-col fixed inset-y-0 left-0 transform -translate-x-full md:relative md:translate-x-0 md:flex z-30">
        <div class=" md:flex items-center justify-center p-6 text-2xl font-bold border-b border-blue-700">Fideya</div>
        <nav class="flex-1 p-4 space-y-2">
            <a href="dashboard.php" class="sidebar-item flex items-center p-3 rounded-lg transition duration-200"><i class="fas fa-tachometer-alt w-6 text-center mr-3"></i>Dashboard</a>
            <a href="arsip.php" class="sidebar-item flex items-center p-3 rounded-lg transition duration-200"><i class="fas fa-folder-open w-6 text-center mr-3"></i>Arsip Pasien</a>
            <a href="pencarian.php" class="sidebar-item flex items-center p-3 rounded-lg transition duration-200"><i class="fas fa-search w-6 text-center mr-3"></i>Pencarian Pasien</a>
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
                <p class="text-gray-600 mt-1">Kelola Admin, Dokter, dan Owner.</p>

                <div class="bg-white p-6 rounded-xl shadow-md mt-8">
                    <div class="flex justify-between items-center mb-6">
                        <button id="btn-add-user" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition-all"><i class="fas fa-user-plus mr-2"></i>Tambah User</button>
                        <input type="text" id="search-user" placeholder="Cari nama atau email..." class="w-1/3 border rounded-lg px-4 py-2">
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left" id="user-table">
                            <thead>
                                <tr class="border-b bg-gray-50">
                                    <th class="p-4">Nama</th>
                                    <th class="p-4">Email</th>
                                    <th class="p-4">Peran</th>
                                    <th class="p-4 text-center">Aksi</th>
                                </tr>
                            </thead>
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
                                                    <button type="button" class="btn-delete text-red-500 hover:text-red-700" data-id="<?php echo $u['id']; ?>" data-name="<?php echo htmlspecialchars($u['name']); ?>"><i class="fas fa-trash"></i> Hapus</button>
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

    <!-- Modal User -->
    <div id="user-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50" style="display:none;">
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
                <div class="flex justify-end gap-4 mt-8">
                    <button type="button" id="btn-cancel" class="bg-gray-300 text-gray-800 px-6 py-2 rounded-lg hover:bg-gray-400">Batal</button>
                    <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700">Simpan User</button>
                </div>
            </form>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Mobile sidebar
    const hamburgerBtn = document.getElementById('hamburger-btn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const toggleSidebar = () => { sidebar.classList.toggle('-translate-x-full'); overlay.classList.toggle('hidden'); };
    if (hamburgerBtn) hamburgerBtn.addEventListener('click', toggleSidebar);
    if (overlay) overlay.addEventListener('click', toggleSidebar);

    // Modal logic
    const userModal = document.getElementById('user-modal');
    const btnAddUser = document.getElementById('btn-add-user');
    const btnCancel = document.getElementById('btn-cancel');
    const modalTitle = document.getElementById('modal-title');
    const passwordInput = document.getElementById('user-password');
    const passwordHint = document.getElementById('password-hint');
    const userIdInput = document.getElementById('user-id');
    const userForm = document.getElementById('user-form');
    const userTableBody = document.getElementById('user-table-body');
    const searchInput = document.getElementById('search-user');

    const showModal = (isEdit = false, data = {}) => {
        modalTitle.textContent = isEdit ? 'Edit User' : 'Tambah User Baru';
        userForm.reset();
        userIdInput.value = isEdit ? data.id : '';
        passwordInput.required = !isEdit;
        passwordHint.classList.toggle('hidden', isEdit);
        passwordInput.placeholder = isEdit ? "Password (Kosongkan jika tidak diubah)" : "Password";

        if (isEdit) {
            document.getElementById('user-name').value = data.name;
            document.getElementById('user-email').value = data.email;
            document.getElementById('user-role').value = data.role;
        }
        userModal.style.display = 'flex';
    };

    const hideModal = () => { userModal.style.display = 'none'; };

    if (btnAddUser) btnAddUser.addEventListener('click', () => showModal(false));
    if (btnCancel) btnCancel.addEventListener('click', hideModal);
    window.addEventListener('click', (e) => { if (e.target === userModal) hideModal(); });

    // Edit button
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

    // Delete with sweetalert2
    userTableBody.addEventListener('click', (e) => {
        const delBtn = e.target.closest('.btn-delete');
        if (delBtn) {
            const userId = delBtn.dataset.id;
            const userName = delBtn.dataset.name;
            Swal.fire({
                title: `Hapus "${userName}"?`,
                text: "Aksi ini tidak dapat dibatalkan.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Ya, Hapus!',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    // submit form POST untuk delete
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'users.php';
                    const act = document.createElement('input'); act.type = 'hidden'; act.name = 'action'; act.value = 'delete';
                    const idInput = document.createElement('input'); idInput.type = 'hidden'; idInput.name = 'user_id'; idInput.value = userId;
                    form.appendChild(act); form.appendChild(idInput);
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
    });

    // Search filter
    searchInput.addEventListener('input', () => {
        const q = searchInput.value.toLowerCase();
        Array.from(userTableBody.rows).forEach(row => {
            const name = row.cells[0].textContent.toLowerCase();
            const email = row.cells[1].textContent.toLowerCase();
            row.style.display = (name.includes(q) || email.includes(q)) ? '' : 'none';
        });
    });

    // Status messages from redirect
    const urlParams = new URLSearchParams(window.location.search);
    const status = urlParams.get('status');
    const msg = urlParams.get('msg');
    if (status && msg) {
        Swal.fire({
            icon: status === 'success' ? 'success' : 'error',
            title: status === 'success' ? 'Berhasil' : 'Gagal',
            text: decodeURIComponent(msg),
            timer: status === 'success' ? 1600 : null,
            showConfirmButton: status === 'success' ? false : true
        }).then(() => {
            // remove query parameters
            const cleanUrl = window.location.pathname;
            window.history.replaceState({}, document.title, cleanUrl);
        });
    }
});
</script>
</body>
</html>
