<?php
include 'auth_check.php';
include 'db_connect.php';

// Cek Izin Akses Halaman (Admin, Dokter, Owner)
if (!isDoctorOrAdmin() && $current_user_role !== 'owner') {
    header("Location: dashboard.php");
    exit;
}

$search_term = $_GET['q'] ?? '';
$patients = [];
$total_results = 0;

// Query untuk mengambil data pencarian (menggunakan LIKE dan JOIN)
if (!empty($search_term) && $conn) {
    // 1. Pecah kata kunci berdasarkan spasi dan hapus yang kosong
    $keywords = array_filter(explode(' ', $search_term));
    
    // 2. Tentukan kolom yang akan dicari
    $search_fields = ['p.name', 'p.diagnosis', 'd.name', 'a.archive_code'];
    
    $where_clauses = [];
    $params = [];
    $types = '';

    if (!empty($keywords)) {
        // Buat klausa WHERE untuk SETIAP kata kunci (harus cocok semua kata kunci)
        foreach ($keywords as $keyword) {
            $like_keyword = "%" . $keyword . "%";
            
            $field_conditions = [];
            
            // Buat kondisi LIKE untuk setiap field (salah satu field boleh cocok)
            foreach ($search_fields as $field) {
                $field_conditions[] = "$field LIKE ?";
                $params[] = $like_keyword;
                $types .= 's'; // Tipe data string
            }
            
            // Gabungkan kondisi field dengan OR, lalu masukkan ke klausa WHERE utama
            $where_clauses[] = "(" . implode(" OR ", $field_conditions) . ")";
        }
    }
    
    // Gabungkan SEMUA klausa kata kunci dengan AND.
    $final_where = implode(" AND ", $where_clauses);


    // 3. Bangun Kueri SQL Final (Dibersihkan dari indentasi awal yang menyebabkan error syntax)
    $sql = "SELECT
            p.id, p.name, p.gender, p.diagnosis, p.patient_date, p.file_path,
            d.name AS doctor_name,
            a.archive_code
        FROM patients p
        JOIN doctors d ON p.doctor_id = d.id
        JOIN archives a ON p.archive_id = a.id
        WHERE " . $final_where . "
        ORDER BY p.patient_date DESC";

    // 4. Eksekusi Prepared Statement dengan fix bind_param
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        
        // Mempersiapkan array parameter untuk bind_param
        $bind_params = array_merge([$types], $params);
        
        // Memastikan parameter dilewatkan sebagai reference untuk call_user_func_array
        $refs = [];
        foreach ($bind_params as $key => $value) {
            $refs[$key] = &$bind_params[$key];
        }

        // Panggil bind_param menggunakan call_user_func_array
        call_user_func_array([$stmt, 'bind_param'], $refs);
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $patients[] = $row;
        }
        $total_results = count($patients);
        $stmt->close();
    }
}
// =======================================================
// AKHIR LOGIKA SMART SEARCH
// =======================================================

// Tutup koneksi dengan aman
if (isset($conn) && $conn) {
    $conn->close();
}

$role_display = ucfirst($current_user_role);
$initial_char = strtoupper(substr($current_user_name, 0, 1));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pencarian Global - PsiArsip</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .sidebar-item:hover, .sidebar-item.active { background-color: #2563eb; }
        .user-avatar { background-color: #f59e0b; }
        #sidebar { transition: transform 0.3s ease-in-out; }
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
                <a href="pencarian.php" class="sidebar-item active flex items-center p-3 rounded-lg transition duration-200"><i class="fas fa-search w-6 text-center mr-3"></i>Pencarian Global</a>
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
                <div class="container mx-auto">
                    <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2">Pencarian Pasien Global</h1>
                    <p class="text-gray-600 mt-1">Cari pasien berdasarkan Nama, Diagnosa, Dokter, atau Kode Arsip.</p>

                    <div class="bg-white p-6 rounded-xl shadow-md mt-8">
                        <form method="GET" action="pencarian.php" class="flex flex-col md:flex-row gap-4 mb-6">
                            <input type="search" name="q" placeholder="Masukkan nama, diagnosa, atau kode arsip..." value="<?php echo htmlspecialchars($search_term); ?>" class="w-full border rounded-lg px-4 py-2 focus:ring-blue-500 focus:border-blue-500" required>
                            <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition-all md:w-auto"><i class="fas fa-search mr-2"></i>Cari</button>
                        </form>

                        <?php if ($search_term): ?>
                            <p class="text-lg mb-4 text-gray-700">Ditemukan <?php echo $total_results; ?> hasil untuk: <?php echo htmlspecialchars($search_term); ?></p>
                        <?php endif; ?>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left">
                                <thead><tr class="border-b bg-gray-50">
                                    <th class="p-4">Nama Pasien</th>
                                    <th class="p-4">Kode Arsip</th>
                                    <th class="p-4">Diagnosa</th>
                                    <th class="p-4">Dokter</th>
                                    <th class="p-4">Tanggal</th>
                                    <th class="p-4">Aksi</th>
                                </tr></thead>
                                <tbody>
                                    <?php if (!empty($patients)): ?>
                                        <?php foreach ($patients as $p): ?>
                                            <tr class="border-b hover:bg-gray-50">
                                                <td class="p-4 font-medium"><?php echo htmlspecialchars($p['name']); ?></td>
                                                <td class="p-4"><a href="detail_arsip.php?code=<?php echo urlencode($p['archive_code']); ?>" class="text-blue-600 hover:underline"><?php echo htmlspecialchars($p['archive_code']); ?></a></td>
                                                <td class="p-4"><?php echo htmlspecialchars($p['diagnosis']); ?></td>
                                                <td class="p-4"><?php echo htmlspecialchars($p['doctor_name']); ?></td>
                                                <td class="p-4"><?php echo date('Y-m-d', strtotime($p['patient_date'])); ?></td>
                                                <td class="p-4">
                                                    <?php if ($p['file_path']): ?>
                                                        <a href="<?php echo htmlspecialchars($p['file_path']); ?>" target="_blank" class="text-green-600 hover:underline"><i class="fas fa-file-pdf mr-1"></i>Lihat File</a>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php elseif ($search_term): ?>
                                        <tr><td colspan="6" class="p-4 text-center text-gray-500">Tidak ada pasien yang cocok dengan pencarian Anda.</td></tr>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="p-4 text-center text-gray-500">Masukkan kata kunci pencarian untuk melihat hasilnya.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
        <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-20 hidden"></div>
    </div>
<script>
    // Logika Sidebar Mobile (sama seperti di file lain)
    document.addEventListener('DOMContentLoaded', () => {
        const hamburgerBtn = document.getElementById('hamburger-btn');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        const toggleSidebar = () => {
            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        };
        if (hamburgerBtn) hamburgerBtn.addEventListener('click', toggleSidebar);
        if (overlay) overlay.addEventListener('click', toggleSidebar);
    });
</script>
</body>
</html>
