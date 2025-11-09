<?php
include 'auth_check.php';
include 'db_connect.php';

// 🔐 Cek hak akses halaman
if (!isDoctorOrAdmin() && $current_user_role !== 'owner') {
    header("Location: dashboard.php");
    exit;
}

$error_message = '';
$success_message = '';

// --- PENGATURAN PAGINATION & PENCARIAN ---
$limit = 10; // Jumlah baris per halaman
$page = isset($_GET['p']) ? (int)$_GET['p'] : 1;
$offset = ($page - 1) * $limit;
$search_query = trim($_GET['search'] ?? '');
$total_pages = 1;
$total_records = 0;

// Menentukan kondisi WHERE untuk pencarian
$where_clause = '';
$params = [];
$types = '';

if (!empty($search_query)) {
    // Cari berdasarkan kode arsip
    $where_clause = " WHERE a.archive_code LIKE ? ";
    $params[] = "%{$search_query}%";
    $types .= 's';
}

// 1. Hitung Total Records
$count_sql = "SELECT COUNT(DISTINCT a.id) FROM archives a {$where_clause}";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_stmt->bind_result($total_records);
$count_stmt->fetch();
$count_stmt->close();

$total_pages = ceil($total_records / $limit);
$page = max(1, min($page, $total_pages)); // Validasi halaman
$offset = ($page - 1) * $limit; // Pastikan offset sudah benar setelah validasi

// 2. Logika CRUD Arsip (Hanya Admin)
if (isAdmin() && $_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';

    // Logika CRUD... (Kode Anda sebelumnya)
    if ($action === 'add_archive') {
        $archive_code = trim($_POST['archive_code'] ?? '');
        $created_date = trim($_POST['created_date'] ?? date('Y-m-d'));

        if (!empty($archive_code)) {
            $check = $conn->prepare("SELECT COUNT(*) FROM archives WHERE archive_code = ?");
            $check->bind_param("s", $archive_code);
            $check->execute();
            $check->bind_result($count);
            $check->fetch();
            $check->close();

            if ($count > 0) {
                $error_message = "Kode arsip '$archive_code' sudah terdaftar!";
            } else {
                $stmt = $conn->prepare("INSERT INTO archives (archive_code, created_date) VALUES (?, ?)");
                $stmt->bind_param("ss", $archive_code, $created_date);

                if ($stmt->execute()) {
                    $success_message = "Arsip berhasil ditambahkan!";
                } else {
                    $error_message = "Gagal menyimpan arsip: " . $conn->error;
                }
                $stmt->close();
            }
        } else {
            $error_message = "Kode arsip tidak boleh kosong.";
        }
    } elseif ($action === 'delete_archive') {
        $archive_code_to_delete = trim($_POST['archive_code_to_delete'] ?? '');
        if (!empty($archive_code_to_delete)) {
            $stmt_id = $conn->prepare("SELECT id FROM archives WHERE archive_code = ?");
            $stmt_id->bind_param("s", $archive_code_to_delete);
            $stmt_id->execute();
            $result_id = $stmt_id->get_result();
            $archive_data = $result_id->fetch_assoc();
            $stmt_id->close();

            if ($archive_data) {
                $archive_id = $archive_data['id'];

                $conn->query("DELETE FROM patients WHERE archive_id = $archive_id");

                $stmt_del = $conn->prepare("DELETE FROM archives WHERE id = ?");
                $stmt_del->bind_param("i", $archive_id);

                if ($stmt_del->execute()) {
                    $success_message = "Arsip dengan kode {$archive_code_to_delete} berhasil dihapus!";
                } else {
                    $error_message = "Gagal menghapus arsip: " . $conn->error;
                }
                $stmt_del->close();
            } else {
                $error_message = "Kode arsip tidak ditemukan.";
            }
        } else {
            $error_message = "Kode arsip tidak valid.";
        }
    }

    // Redirect untuk memicu SweetAlert
    if (!empty($success_message) || !empty($error_message)) {
        $status = empty($success_message) ? 'error' : 'success';
        $msg = empty($success_message) ? $error_message : $success_message;
        header("Location: arsip.php?status={$status}&msg=" . urlencode($msg));
        exit;
    }
}

// Ambil pesan dari URL setelah redirect
if (isset($_GET['status']) && isset($_GET['msg'])) {
    if ($_GET['status'] === 'success') {
        $success_message = urldecode($_GET['msg']);
    } else {
        $error_message = urldecode($_GET['msg']);
    }
}


// 3. Ambil Daftar Arsip (Dengan Pagination & Pencarian)
$archives = [];
$sql = "
    SELECT a.id, a.archive_code, a.created_date, COUNT(p.id) AS total_patients 
    FROM archives a
    LEFT JOIN patients p ON a.id = p.archive_id
    {$where_clause}
    GROUP BY a.id, a.archive_code, a.created_date
    ORDER BY a.created_date DESC
    LIMIT ? OFFSET ?
";

$stmt_data = $conn->prepare($sql);
// Gabungkan tipe dan parameter untuk LIMIT dan OFFSET
$types .= 'ii';
$params[] = $limit;
$params[] = $offset;

// Bind semua parameter
$stmt_data->bind_param($types, ...$params);
$stmt_data->execute();
$result = $stmt_data->get_result();

while ($row = $result->fetch_assoc()) {
    $archives[] = $row;
}

$stmt_data->close();
$conn->close();

$role_display = ucfirst($current_user_role);
$initial_char = strtoupper(substr($current_user_name, 0, 1));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Arsip Pasien - Fideya</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.sidebar-item:hover, .sidebar-item.active { background-color: #1d4ed8; }
.user-avatar { background-color: #f59e0b; }
#sidebar { transition: transform 0.3s ease-in-out; }
#archive-modal { display: none; } /* Default hidden for modal */
</style>
</head>
<body class="bg-gray-50 text-gray-800 font-sans">

<div class="relative min-h-screen md:flex">

    <header class="md:hidden flex justify-between items-center p-4 bg-blue-800 text-white shadow-md z-10">
        <button id="hamburger-btn" class="focus:outline-none"><i class="fas fa-bars fa-lg"></i></button>
        <h1 class="text-xl font-bold">Fideya</h1>
        <div class="w-8"></div>
    </header>

    <aside id="sidebar" class="bg-blue-800 text-white w-64 flex-col fixed inset-y-0 left-0 transform -translate-x-full md:relative md:translate-x-0 md:flex z-30">
        <div class="md:flex items-center justify-center p-6 text-2xl font-bold border-b border-blue-700">Fideya</div>
        <nav class="flex-1 p-4 space-y-2">
            <a href="dashboard.php" class="sidebar-item flex items-center p-3 rounded-lg"><i class="fas fa-tachometer-alt w-6 mr-3"></i>Dashboard</a>
            <a href="arsip.php" class="sidebar-item flex items-center p-3 rounded-lg active"><i class="fas fa-folder-open w-6 mr-3"></i>Arsip Pasien</a>
            <a href="pencarian.php" class="sidebar-item flex items-center p-3 rounded-lg"><i class="fas fa-search w-6 mr-3"></i>Pencarian Pasien</a>
            <a href="users.php" class="sidebar-item flex items-center p-3 rounded-lg <?php echo isAdmin() ? '' : 'hidden'; ?>"><i class="fas fa-users-cog w-6 mr-3"></i>Manajemen User</a>
        </nav>
        <div class="p-4 border-t border-blue-700">
            <div class="flex items-center mb-4">
                <div class="user-avatar w-10 h-10 rounded-full flex items-center justify-center font-bold text-xl mr-3"><?php echo $initial_char; ?></div>
                <span class="font-semibold"><?php echo $role_display; ?></span>
            </div>
            <a href="?logout=true" class="sidebar-item flex items-center p-3 rounded-lg hover:bg-blue-700"><i class="fas fa-sign-out-alt w-6 mr-3"></i>Keluar</a>
        </div>
    </aside>

    <div class="flex-1 flex flex-col overflow-hidden">
        <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-100 p-4 md:p-8">
            <div class="container mx-auto">
                <h1 class="text-3xl font-bold">Arsip Pasien</h1>
                <p class="text-gray-600 mb-6">Daftar semua arsip pasien. (Total: <?php echo $total_records; ?>)</p>

                <div class="bg-white p-6 rounded-xl shadow-md">
                    <div class="flex justify-between items-center mb-6">
                        <button id="btn-add-archive" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 <?php echo isAdmin() ? '' : 'hidden'; ?>"><i class="fas fa-plus-circle mr-2"></i>Buat Arsip Baru</button>
                        
                        <form method="GET" action="arsip.php" class="flex">
                            <input type="text" name="search" id="search-archive" placeholder="Cari kode arsip..." class="border rounded-l-lg px-4 py-2 w-full md:w-64" value="<?php echo htmlspecialchars($search_query); ?>">
                            <button type="submit" class="bg-gray-200 hover:bg-gray-300 px-4 rounded-r-lg text-gray-700"><i class="fas fa-search"></i></button>
                            <?php if (!empty($search_query)): ?>
                                <a href="arsip.php" class="bg-red-500 text-white px-4 py-2 rounded-lg ml-2 hover:bg-red-600">Reset</a>
                            <?php endif; ?>
                        </form>
                        </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="border-b bg-gray-50">
                                    <th class="p-4">Kode Arsip</th>
                                    <th class="p-4">Jumlah Pasien</th>
                                    <th class="p-4">Tanggal Dibuat</th>
                                    <th class="p-4 text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="archive-table-body">
                            <?php if (!empty($archives)): foreach ($archives as $archive): ?>
                                <tr class="border-b hover:bg-gray-50">
                                    <td class="p-4 font-medium"><?php echo htmlspecialchars($archive['archive_code']); ?></td>
                                    <td class="p-4"><?php echo $archive['total_patients']; ?></td>
                                    <td class="p-4"><?php echo date('d/m/Y', strtotime($archive['created_date'])); ?></td>
                                    <td class="p-4 text-center space-x-2">
                                        <a href="detail_arsip.php?code=<?php echo urlencode($archive['archive_code']); ?>" class="text-blue-600 hover:underline"><i class="fa-solid fa-book-open"></i> Detail</a>
                                        <?php if (isAdmin()): ?>
                                            <form method="POST" action="arsip.php" class="inline delete-form" onsubmit="return confirmDelete(this, '<?php echo addslashes($archive['archive_code']); ?>', <?php echo (int)$archive['total_patients']; ?>);">
                                                <input type="hidden" name="action" value="delete_archive">
                                                <input type="hidden" name="archive_code_to_delete" value="<?php echo htmlspecialchars($archive['archive_code']); ?>">
                                                <button type="submit" class="text-red-500 hover:text-red-700"><i class="fas fa-trash"></i> Hapus</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="4" class="p-4 text-center text-gray-500">Tidak ada data arsip <?php echo !empty($search_query) ? "untuk pencarian '{$search_query}'." : '.'; ?></td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                    <div class="flex justify-between items-center mt-6">
                        <span class="text-gray-600">Halaman <?php echo $page; ?> dari <?php echo $total_pages; ?></span>
                        <div class="flex space-x-1">
                            <?php
                            $base_url = "arsip.php" . (!empty($search_query) ? "?search=" . urlencode($search_query) . "&" : "?");
                            if (!empty($search_query) && strpos($base_url, '?') === false) $base_url = "arsip.php?search=" . urlencode($search_query) . "&";
                            elseif (empty($search_query)) $base_url = "arsip.php?";

                            $link_url = function($p) use ($base_url, $search_query) {
                                $url = $base_url . "p={$p}";
                                return $url;
                            };
                            ?>

                            <a href="<?php echo $link_url($page - 1); ?>" class="px-3 py-1 border rounded-lg <?php echo $page <= 1 ? 'bg-gray-200 text-gray-500 cursor-not-allowed' : 'bg-white text-blue-600 hover:bg-blue-50'; ?>" <?php echo $page <= 1 ? 'aria-disabled="true"' : ''; ?>>
                                &laquo; Sebelumnya
                            </a>

                            <?php 
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            // Adjust start and end if they are at the edges
                            if ($end_page - $start_page < 4) {
                                $start_page = max(1, $end_page - 4);
                            }
                            if ($end_page - $start_page < 4) {
                                $end_page = min($total_pages, $start_page + 4);
                            }

                            if ($start_page > 1) { echo '<span class="px-3 py-1">...</span>'; }

                            for ($i = $start_page; $i <= $end_page; $i++): 
                            ?>
                                <a href="<?php echo $link_url($i); ?>" class="px-3 py-1 border rounded-lg <?php echo $i == $page ? 'bg-blue-600 text-white' : 'bg-white text-blue-600 hover:bg-blue-50'; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($end_page < $total_pages) { echo '<span class="px-3 py-1">...</span>'; } ?>

                            <a href="<?php echo $link_url($page + 1); ?>" class="px-3 py-1 border rounded-lg <?php echo $page >= $total_pages ? 'bg-gray-200 text-gray-500 cursor-not-allowed' : 'bg-white text-blue-600 hover:bg-blue-50'; ?>" <?php echo $page >= $total_pages ? 'aria-disabled="true"' : ''; ?>>
                                Berikutnya &raquo;
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    </div>
            </div>
        </main>
    </div>
</div>

<div id="archive-modal" class="fixed inset-0 bg-black bg-opacity-50 flex justify-center items-center z-50">
    <div class="bg-white rounded-lg shadow-xl p-8 w-full max-w-md">
        <h2 class="text-2xl font-bold mb-4">Tambah Arsip Baru</h2>
        <form method="POST" action="arsip.php" id="form-add-archive">
            <input type="hidden" name="action" value="add_archive">
            <div class="mb-4">
                <label class="block text-gray-700 mb-2">Kode Arsip</label>
                <input type="text" name="archive_code" class="w-full border rounded-lg px-4 py-2" required>
            </div>
            <div class="mb-6">
                <label class="block text-gray-700 mb-2">Tanggal Arsip</label>
                <input type="date" name="created_date" value="<?php echo date('Y-m-d'); ?>" class="w-full border rounded-lg px-4 py-2" required>
            </div>
            <div class="flex justify-end space-x-4">
                <button type="button" id="btn-cancel" class="px-6 py-2 border rounded-lg text-gray-700 hover:bg-gray-100">Batal</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Tampilkan modal secara default di sini untuk menghilangkan 'hidden'
    const modal = document.getElementById('archive-modal');
    modal.style.display = 'none';

    // SWEETALERT FEEDBACK (Jika ada status di URL)
    const urlParams = new URLSearchParams(window.location.search);
    const status = urlParams.get('status');
    const msg = urlParams.get('msg');
    
    if (status && msg) {
        const decodedMsg = decodeURIComponent(msg);
        Swal.fire({
            icon: status === 'success' ? 'success' : 'error',
            title: status === 'success' ? 'Berhasil!' : 'Gagal!',
            text: decodedMsg,
            timer: status === 'success' ? 1800 : null,
            showConfirmButton: status !== 'success',
            timerProgressBar: status === 'success'
        }).then(() => {
            // Hapus status dan msg dari URL, tapi pertahankan search dan page
            const cleanParams = new URLSearchParams(window.location.search);
            cleanParams.delete('status');
            cleanParams.delete('msg');

            const currentSearch = cleanParams.get('search');
            const currentPage = cleanParams.get('p');
            
            const newUrl = `arsip.php?${cleanParams.toString()}`;
            window.history.replaceState({}, document.title, newUrl);
        });
    }


    // MODAL HANDLER
    const addBtn = document.getElementById('btn-add-archive');
    const cancelBtn = document.getElementById('btn-cancel');

    if (addBtn) addBtn.addEventListener('click', () => modal.style.display = 'flex');
    if (cancelBtn) cancelBtn.addEventListener('click', () => modal.style.display = 'none');
    window.onclick = e => { if (e.target === modal) modal.style.display = 'none'; };

    // SIDEBAR HANDLER
    const hamburgerBtn = document.getElementById('hamburger-btn');
    const sidebar = document.getElementById('sidebar');

    if (hamburgerBtn && sidebar) {
        const overlay = document.createElement('div');
        overlay.id = 'sidebar-overlay';
        overlay.className = 'fixed inset-0 bg-black bg-opacity-50 z-20 hidden md:hidden';
        document.body.appendChild(overlay);

        const toggleSidebar = () => {
            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        };
        hamburgerBtn.addEventListener('click', toggleSidebar);
        overlay.addEventListener('click', toggleSidebar);
    }

    // Perbaiki form pencarian agar submit saat user menekan Enter
    const searchInput = document.getElementById('search-archive');
    searchInput.closest('form').addEventListener('submit', function(e) {
        // Hapus parameter page saat mencari, selalu mulai dari halaman 1
        const tempForm = document.createElement('form');
        tempForm.method = 'GET';
        tempForm.action = 'arsip.php';
        
        const searchInputClone = document.createElement('input');
        searchInputClone.type = 'hidden';
        searchInputClone.name = 'search';
        searchInputClone.value = searchInput.value;
        tempForm.appendChild(searchInputClone);
        
        e.preventDefault();
        document.body.appendChild(tempForm);
        tempForm.submit();
    });
});

// KONFIRMASI HAPUS (Menggunakan SweetAlert2)
function confirmDelete(form, code, total) {
    const warning = total > 0
        ? `Arsip ini memiliki ${total} data pasien. Semua data pasien akan dihapus permanen juga!`
        : "Arsip ini akan dihapus permanen.";
    
    Swal.fire({
        title: `Yakin ingin menghapus ${code}?`,
        text: warning,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e3342f',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Ya, Hapus!',
        cancelButtonText: 'Batal'
    }).then((result) => {
        if (result.isConfirmed) form.submit();
    });
    return false; // Mencegah submit form bawaan HTML
}
</script>
</body>
</html>