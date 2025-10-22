<?php
include 'auth_check.php';
include 'db_connect.php';

// Cek Izin Akses Halaman (Admin, Dokter, Owner)
if (!isDoctorOrAdmin() && $current_user_role !== 'owner') {
    // Jika tidak diizinkan, arahkan ke dashboard
    header("Location: dashboard.php");
    exit;
}

$error_message = '';
$success_message = '';

// 1. Logika CRUD Arsip (Hanya untuk Admin)
if (isAdmin() && $_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';

    // Logika Menambah Arsip Baru
    if ($action === 'add_archive') {
        $archive_code = trim($_POST['archive_code'] ?? '');
        // --- PERUBAHAN UTAMA DI SINI ---
        // Mengambil tanggal dari form POST. Jika kosong, fallback ke tanggal hari ini.
        $created_date = trim($_POST['created_date'] ?? date('Y-m-d')); 
        // ------------------------------

        if (!empty($archive_code)) {
            $stmt = $conn->prepare("INSERT INTO archives (archive_code, created_date) VALUES (?, ?)");
            $stmt->bind_param("ss", $archive_code, $created_date);

            if ($stmt->execute()) {
                $success_message = "Arsip berhasil ditambahkan!";
            } else {
                // MySQL Error 1062 = Duplicate entry (kemungkinan archive_code)
                if ($conn->errno == 1062) {
                    $error_message = "Gagal menyimpan arsip: Kode arsip mungkin sudah ada.";
                } else {
                    $error_message = "Gagal menyimpan arsip: " . $conn->error;
                }
            }
            $stmt->close();
        }
    } 
    // Logika Hapus Arsip
    elseif ($action === 'delete_archive') {
// ... kode penghapusan arsip tetap sama ...
// (Lanjutan kode penghapusan)
        $archive_code_to_delete = trim($_POST['archive_code_to_delete'] ?? '');
        
        if (!empty($archive_code_to_delete)) {
            // Catatan Penting: Penghapusan arsip akan MENGHAPUS SEMUA DATA PASIEN yang terikat!
            // Kita perlu mengambil ID arsip terlebih dahulu.
            
            // 1. Ambil ID Arsip
            $stmt_id = $conn->prepare("SELECT id FROM archives WHERE archive_code = ?");
            $stmt_id->bind_param("s", $archive_code_to_delete);
            $stmt_id->execute();
            $result_id = $stmt_id->get_result();
            $archive_data = $result_id->fetch_assoc();
            $stmt_id->close();

            if ($archive_data) {
                $archive_id = $archive_data['id'];

                // 2. Hapus data pasien yang terkait (asumsi foreign key set ke CASCADE)
                // Jika foreign key tidak set ke CASCADE, Anda perlu HAPUS PASIEN TERLEBIH DAHULU:
                // $stmt_del_patients = $conn->prepare("DELETE FROM patients WHERE archive_id = ?");
                // $stmt_del_patients->bind_param("i", $archive_id);
                // $stmt_del_patients->execute();
                // $stmt_del_patients->close();

                // 3. Hapus Arsip
                $stmt_del_archive = $conn->prepare("DELETE FROM archives WHERE id = ?");
                $stmt_del_archive->bind_param("i", $archive_id);
                
                if ($stmt_del_archive->execute()) {
                    $success_message = "Arsip dengan kode **" . htmlspecialchars($archive_code_to_delete) . "** berhasil dihapus beserta data pasien terkait.";
                } else {
                    $error_message = "Gagal menghapus arsip: " . $conn->error;
                }
                $stmt_del_archive->close();

            } else {
                $error_message = "Kode arsip tidak ditemukan.";
            }
        } else {
            $error_message = "Kode arsip tidak valid.";
        }
    }

    // Redirect untuk menghindari form resubmission dan menampilkan pesan
    if (!empty($success_message) || !empty($error_message)) {
        $status = empty($success_message) ? 'error' : 'success';
        $msg = empty($success_message) ? $error_message : $success_message;
        
        header("Location: arsip.php?status=" . $status . "&msg=" . urlencode($msg));
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

// 2. Ambil Daftar Arsip dari Database
$archives = [];
// Query untuk mendapatkan daftar arsip beserta jumlah pasien
$result = $conn->query("
    SELECT a.id, a.archive_code, a.created_date, COUNT(p.id) AS total_patients 
    FROM archives a
    LEFT JOIN patients p ON a.id = p.archive_id
    GROUP BY a.id, a.archive_code, a.created_date
    ORDER BY a.created_date DESC
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $archives[] = $row;
    }
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
    <title>Arsip Pasien - PsiArsip</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .sidebar-item:hover, .sidebar-item.active {
            background-color: #2563eb;
        }
        .user-avatar {
            background-color: #f59e0b;
        }
        #sidebar {
            transition: transform 0.3s ease-in-out;
        }
        #archive-modal { display: none; }
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
                <a href="arsip.php" class="sidebar-item active flex items-center p-3 rounded-lg transition duration-200 admin-doctor"><i class="fas fa-folder-open w-6 text-center mr-3"></i>Arsip Pasien</a>
                <a href="pencarian.php" class="sidebar-item flex items-center p-3 rounded-lg transition duration-200 admin-doctor"><i class="fas fa-search w-6 text-center mr-3"></i>Pencarian Global</a>
                <a href="users.php" id="menu-users" class="sidebar-item flex items-center p-3 rounded-lg transition duration-200 admin-only" style="<?php echo isAdmin() ? '' : 'display: none;'; ?>"><i class="fas fa-users-cog w-6 text-center mr-3"></i>Manajemen User</a>
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
                <div class="container mx-auto admin-doctor">
                    <h1 class="text-3xl md:text-4xl font-bold text-gray-900">Arsip Pasien</h1>
                    <p class="text-gray-600 mt-1">Daftar semua arsip pasien yang tersimpan.</p>

                    <div class="bg-white p-6 rounded-xl shadow-md mt-8">
                        <div class="flex flex-col md:flex-row justify-between items-center mb-6">
                            <button id="btn-add-archive" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition-all w-full md:w-auto mb-4 md:mb-0 admin-only" style="<?php echo isAdmin() ? '' : 'display: none;'; ?>"><i class="fas fa-plus-circle mr-2"></i>Buat Arsip Baru</button>
                            <input type="text" id="search-archive" placeholder="Cari kode arsip..." class="w-full md:w-1/3 border rounded-lg px-4 py-2">
                        </div>
                        
                        <?php if (!empty($success_message)): ?>
                            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4"><?php echo $success_message; ?></div>
                        <?php elseif (!empty($error_message)): ?>
                            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4"><?php echo $error_message; ?></div>
                        <?php endif; ?>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left" id="archive-table">
                                <thead><tr class="border-b bg-gray-50"><th class="p-4">Kode Arsip</th><th class="p-4">Jumlah Pasien</th><th class="p-4">Tanggal Dibuat</th><th class="p-4 text-center">Aksi</th></tr></thead>
                                <tbody id="archive-table-body">
                                    <?php if (!empty($archives)): ?>
                                        <?php foreach ($archives as $archive): ?>
                                            <tr class="border-b hover:bg-gray-50">
                                                <td class="p-4 font-medium"><?php echo htmlspecialchars($archive['archive_code']); ?></td>
                                                <td class="p-4"><?php echo $archive['total_patients']; ?></td>
                                                <td class="p-4"><?php echo date('d/m/Y', strtotime($archive['created_date'])); ?></td>
                                                <td class="p-4 text-center space-x-2 whitespace-nowrap">
                                                    <a href="detail_arsip.php?code=<?php echo urlencode($archive['archive_code']); ?>" class="text-blue-600 hover:underline"><i class="fas fa-eye"></i> Detail</a>
                                                    
                                                    <?php if (isAdmin()): ?>
                                                        <form method="POST" action="arsip.php" class="inline delete-form" onsubmit="return confirmDelete('<?php echo addslashes($archive['archive_code']); ?>', <?php echo $archive['total_patients']; ?>);">
                                                            <input type="hidden" name="action" value="delete_archive">
                                                            <input type="hidden" name="archive_code_to_delete" value="<?php echo htmlspecialchars($archive['archive_code']); ?>">
                                                            <button type="submit" class="text-red-500 hover:text-red-700"><i class="fas fa-trash"></i> Hapus</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="p-4 text-center text-gray-500">Belum ada data arsip.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
        <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-20 hidden"></div>

        <div id="archive-modal" class="fixed inset-0 bg-black bg-opacity-50 flex justify-center items-center z-50">
            <div class="bg-white rounded-lg shadow-xl p-8 w-full max-w-md">
                <h2 class="text-2xl font-bold mb-2">Tambah Arsip Baru</h2>
                <p class="text-gray-600 mb-6">Isi detail untuk membuat arsip baru.</p>
                <form id="archive-form" method="POST" action="arsip.php">
                    <input type="hidden" name="action" value="add_archive">
                    <div class="mb-4"><label for="archive-code" class="block text-gray-700 font-semibold mb-2">Kode Arsip</label><input type="text" id="archive-code" name="archive_code" class="w-full border rounded-lg px-4 py-2" placeholder="Masukkan Kode Arsip" required></div>
                    
                    <div class="mb-6">
                        <label for="archive-date" class="block text-gray-700 font-semibold mb-2">Tanggal Arsip</label>
                        <input type="date" 
                               id="archive-date" 
                               name="created_date" 
                               value="<?php echo date('Y-m-d'); ?>" 
                               class="w-full border rounded-lg px-4 py-2" 
                               required>
                    </div>
                    <div class="flex justify-end space-x-4"><button type="button" id="btn-cancel" class="px-6 py-2 text-gray-700 border rounded-lg hover:bg-gray-100">Batal</button><button type="submit" id="btn-save" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Simpan Arsip</button></div>
                </form>
            </div>
        </div>
    </div>

<script>
    // Fungsi konfirmasi DELETE kustom
    function confirmDelete(archiveCode, totalPatients) {
        let message = `Anda yakin ingin menghapus arsip dengan kode ${archiveCode}?`;
        if (totalPatients > 0) {
            message += `\n\nPERINGATAN: Arsip ini memiliki ${totalPatients} data pasien terkait. MENGHAPUS arsip ini akan **MENGHAPUS SEMUA DATA PASIEN TERSEBUT SECARA PERMANEN** (berdasarkan konfigurasi database).`;
        }
        message += `\n\nTekan OK untuk melanjutkan penghapusan.`;
        
        return confirm(message);
    }
    
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
        
        const addArchiveButton = document.getElementById('btn-add-archive');
        const archiveModal = document.getElementById('archive-modal');
        const cancelModalButton = document.getElementById('btn-cancel');
        const searchArchiveInput = document.getElementById('search-archive');

        const showModal = () => { 
            // Tambahkan: Set nilai default input type="date" ke hari ini
            document.getElementById('archive-date').value = new Date().toISOString().substring(0, 10);
            archiveModal.style.display = 'flex'; 
        };
        const hideModal = () => { archiveModal.style.display = 'none'; };

        if (addArchiveButton) addArchiveButton.addEventListener('click', showModal);
        if (cancelModalButton) cancelModalButton.addEventListener('click', hideModal);
        
        window.addEventListener('click', (e) => { if (e.target === archiveModal) hideModal(); });

        // Search Filter (Client-side)
        searchArchiveInput.addEventListener('input', () => {
            const searchTerm = searchArchiveInput.value.toLowerCase();
            const rows = document.getElementById('archive-table-body').getElementsByTagName('tr');
            Array.from(rows).forEach(row => {
                const code = row.cells[0].textContent.toLowerCase();
                row.style.display = code.includes(searchTerm) ? '' : 'none';
            });
        });
    });
</script>

</body>
</html>