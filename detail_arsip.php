<?php
include 'auth_check.php';
include 'db_connect.php';

// Cek Izin Akses Halaman (Admin, Dokter, Owner)
if (!isDoctorOrAdmin() && $current_user_role !== 'owner') {
    header("Location: dashboard.php");
    exit;
}

$archiveCode = $_GET['code'] ?? null;
$archiveId = null;
$error_message = null; // Inisialisasi pesan error
$success_message = null; // Inisialisasi pesan sukses

if (!$archiveCode) {
    header("Location: arsip.php");
    exit;
}

// =======================================================
// FITUR BARU: PAGINATION & SORTING CONFIGURATION
// =======================================================
$limit = 10; // Jumlah data per halaman

// 1. Inisialisasi Pagination
$page = isset($_GET['halaman']) && is_numeric($_GET['halaman']) ? (int)$_GET['halaman'] : 1;
// Batasi halaman minimal 1
if ($page < 1) {
    $page = 1;
}

// 2. Inisialisasi Sorting
$allowed_columns_sql = [
    'nrm' => 'p.nrm',
    'name' => 'p.name',
    'gender' => 'p.gender',
    'diagnosis' => 'p.diagnosis',
    'doctor' => 'd.name', // Alias dari kolom JOIN
    'date' => 'p.patient_date'
];

$sort_col_param = $_GET['sort'] ?? 'name';
$sort_order = strtoupper($_GET['order'] ?? 'ASC');

// Validasi input sorting
if (!isset($allowed_columns_sql[$sort_col_param])) {
    $sort_col_param = 'name'; // Default
}
if (!in_array($sort_order, ['ASC', 'DESC'])) {
    $sort_order = 'ASC'; // Default
}
$order_by_sql = $allowed_columns_sql[$sort_col_param] . ' ' . $sort_order;
// =======================================================
// END PAGINATION & SORTING CONFIGURATION
// =======================================================


// 1. Ambil ID Arsip dari Kode Arsip
$stmt = $conn->prepare("SELECT id FROM archives WHERE archive_code = ?");
$stmt->bind_param("s", $archiveCode);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $archiveId = $row['id'];
} else {
    die("Kode Arsip tidak ditemukan.");
}
$stmt->close();

// Ambil Daftar Dokter
$doctors = [];
$doctorResult = $conn->query("SELECT id, name FROM doctors");
while ($row = $doctorResult->fetch_assoc()) {
    $doctors[] = $row;
}

// 3. Logika CRUD Pasien (Hanya Admin) - TIDAK BERUBAH
if (isAdmin() && $_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';

    // Logika Simpan (Tambah/Edit)
    if ($action === 'save_patient') {
        $patientId = $_POST['patient-id'] ?? null;
        $nrm = $_POST['patient-nrm'] ?? null;
        $name = trim($_POST['patient-name']);
        $gender = $_POST['patient-gender'];
        $doctorId = $_POST['patient-doctor'];
        $date = $_POST['patient-date'];
        $diagnosis = trim($_POST['patient-diagnosis']);
        $file = $_FILES['patient-file'] ?? null;
        
        $filePath = null;
        $uploadDir = 'files/';
        
        // REVISI: Penanganan Error Upload File yang Lebih Baik
        if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
            
            // 1. Cek jika terjadi error upload oleh PHP
            if ($file['error'] !== UPLOAD_ERR_OK) {
                switch ($file['error']) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $error_message = "File gagal diunggah: Ukuran file melebihi batas yang diizinkan oleh server. Cek konfigurasi php.ini Anda.";
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $error_message = "File gagal diunggah: Upload file hanya terkirim sebagian.";
                        break;
                    case UPLOAD_ERR_NO_TMP_DIR:
                        $error_message = "File gagal diunggah: Folder temporary tidak ditemukan.";
                        break;
                    case UPLOAD_ERR_CANT_WRITE:
                        $error_message = "File gagal diunggah: Gagal menulis file ke disk (Izin server).";
                        break;
                    default:
                        $error_message = "Terjadi kesalahan upload file yang tidak diketahui (Kode: {$file['error']}).";
                }
            } else {
                // Lanjutkan proses jika UPLOAD_ERR_OK
                
                // 2. Pengecekan keamanan dasar untuk jenis file
                $file_mime = mime_content_type($file['tmp_name']);
                if (strpos($file_mime, 'application/pdf') === false && strpos($file_mime, 'image/') === false) {
                    $error_message = "Jenis file tidak diizinkan. Hanya PDF dan Gambar (JPG/PNG).";
                }

                // 3. Pindahkan file jika tidak ada error keamanan
                if (!isset($error_message)) {
                    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $fileName = uniqid('file_') . '.' . $ext;
                    $destination = $uploadDir . $fileName;

                    if (move_uploaded_file($file['tmp_name'], $destination)) {
                        $filePath = $destination;
                    } else {
                        // Gagal move_uploaded_file (kemungkinan izin folder/path salah)
                        $error_message = "Gagal memindahkan file. Pastikan folder 'files/' ada dan dapat ditulis (izin chmod 777 atau 755).";
                    }
                }
            }
        }
        
        // Jika sedang edit dan tidak ada file baru diupload, ambil path file lama
        if ($patientId && !$filePath && (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE)) {
              // Query untuk mendapatkan path file lama
              $stmt_old_path = $conn->prepare("SELECT file_path FROM patients WHERE id = ?");
              $stmt_old_path->bind_param("i", $patientId);
              $stmt_old_path->execute();
              $result_old_path = $stmt_old_path->get_result();
              if ($row_old_path = $result_old_path->fetch_assoc()) {
                  $filePath = $row_old_path['file_path'];
              }
              $stmt_old_path->close();
        }

        // Hanya proses simpan database jika tidak ada error file
        if (!isset($error_message)) {
            if ($patientId) {
                // UPDATE Pasien
                $sql_parts = ["nrm=?", "name=?", "gender=?", "diagnosis=?", "doctor_id=?", "patient_date=?"];
                $types = "ssssis";
                $params = [$nrm, $name, $gender, $diagnosis, $doctorId, $date];

                $sql_parts[] = "file_path=?";
                $types .= "s";
                $params[] = $filePath;
                
                $sql = "UPDATE patients SET " . implode(', ', $sql_parts) . " WHERE id=?";
                $types .= "i"; // Tambahkan 'i' untuk WHERE ID
                $params[] = $patientId;

                $stmt = $conn->prepare($sql);
                // Menggunakan '...' untuk unpacking parameter array
                if (!$stmt->bind_param($types, ...$params)) {
                       $error_message = "Gagal mengikat parameter update: " . $stmt->error;
                }
                
            } else {
                // Tambah Pasien Baru (INSERT)
                $sql = "INSERT INTO patients (archive_id, nrm, name, gender, diagnosis, doctor_id, patient_date, file_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                // Tipe data yang benar: i, s, s, s, i, s, s
                // Ubah menjadi "issssiss"
if (!$stmt->bind_param("issssiss", $archiveId, $nrm, $name, $gender, $diagnosis, $doctorId, $date, $filePath)) {
                    $error_message = "Gagal mengikat parameter insert: " . $stmt->error;
                }
            }

            if (!isset($error_message)) {
                if ($stmt->execute()) {
                    $success_message = "Data pasien berhasil disimpan.";
                    // Success, redirect untuk menghindari resubmission
                    header("Location: detail_arsip.php?code=" . urlencode($archiveCode) . "&status=success&msg=" . urlencode($success_message));
                    exit;
                } else {
                    $error_message = "Gagal menyimpan data pasien: " . $stmt->error . " | Cek koneksi dan tipe data Anda.";
                }
                $stmt->close();
            }
        }
        
        // Redirect dengan pesan error jika ada kegagalan file/database
        if (isset($error_message)) {
            header("Location: detail_arsip.php?code=" . urlencode($archiveCode) . "&status=error&msg=" . urlencode($error_message));
            exit;
        }
    }
} 
// Logika Delete Pasien - TIDAK BERUBAH
elseif (isAdmin() && isset($_GET['delete_id'])) {
    $deleteId = $_GET['delete_id'];
    
    // Opsional: Hapus juga file fisik
    // 1. Ambil path file
    $stmt_path = $conn->prepare("SELECT file_path FROM patients WHERE id=?");
    $stmt_path->bind_param("i", $deleteId);
    $stmt_path->execute();
    $result_path = $stmt_path->get_result();
    if ($row_path = $result_path->fetch_assoc()) {
        $fileToDelete = $row_path['file_path'];
    }
    $stmt_path->close();
    
    // 2. Hapus dari database
    $stmt = $conn->prepare("DELETE FROM patients WHERE id=?");
    if ($stmt === false) {
        $error_message = "Gagal menyiapkan statement DELETE.";
    } else {
        $stmt->bind_param("i", $deleteId);
        if ($stmt->execute()) {
            // 3. Hapus file fisik jika ada
            if (!empty($fileToDelete) && file_exists($fileToDelete)) {
                unlink($fileToDelete);
            }
            
            $success_message = "Data pasien berhasil dihapus.";
            header("Location: detail_arsip.php?code=" . urlencode($archiveCode) . "&status=success&msg=" . urlencode($success_message));
            exit;
        } else {
            $error_message = "Gagal menghapus data pasien: " . $stmt->error;
        }
        $stmt->close();
    }
    
    // Tambahkan redirect error untuk delete
    if (isset($error_message)) {
        header("Location: detail_arsip.php?code=" . urlencode($archiveCode) . "&status=error&msg=" . urlencode($error_message));
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

// =======================================================
// FITUR BARU: LOGIKA PAGINATION (Total Data & Halaman)
// =======================================================
// 1. Hitung Total Pasien (tanpa filter/search, karena filtering saat ini client-side)
$count_sql = "SELECT COUNT(*) AS total FROM patients WHERE archive_id = ?";
$stmt_count = $conn->prepare($count_sql);
$stmt_count->bind_param("i", $archiveId);
$stmt_count->execute();
$count_result = $stmt_count->get_result();
$total_patients = $count_result->fetch_assoc()['total'];
$stmt_count->close();

$total_pages = ceil($total_patients / $limit);

// Tentukan offset setelah mengetahui total halaman
$offset = ($page - 1) * $limit;

// Pastikan offset tidak negatif
if ($offset < 0) {
    $offset = 0;
}
// =======================================================
// END LOGIKA PAGINATION
// =======================================================


// 4. Ambil Daftar Pasien untuk Arsip Ini (Dengan Pagination dan Sorting)
$patients = [];
$sql = "
    SELECT p.*, d.name as doctor_name 
    FROM patients p
    JOIN doctors d ON p.doctor_id = d.id
    WHERE p.archive_id = ?
    ORDER BY {$order_by_sql}
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);

// Bind archiveId, limit, dan offset
// i: integer (archiveId), i: integer (limit), i: integer (offset)
$stmt->bind_param("iii", $archiveId, $limit, $offset); 
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $patients[] = $row;
}

$stmt->close();
$conn->close();

// Set user display variables
$role_display = ucfirst($current_user_role); 
$initial_char = strtoupper(substr($current_user_name ?? 'U', 0, 1));

// =======================================================
// HELPER FUNCTION UNTUK SORTING LINK
// =======================================================
function get_sort_link($column, $current_sort_param, $current_order, $archiveCode) {
    // Tentukan urutan dan ikon baru
    $new_order = 'ASC';
    $arrow = '<i class="fas fa-sort ml-1 text-gray-400"></i>';

    if ($current_sort_param === $column) {
        $new_order = ($current_order === 'ASC') ? 'DESC' : 'ASC';
        $arrow = ($current_order === 'ASC') ? '<i class="fas fa-sort-up ml-1 text-blue-600"></i>' : '<i class="fas fa-sort-down ml-1 text-blue-600"></i>';
    }

    // Ambil parameter halaman saat ini (jika ada) untuk dipertahankan
    $current_page = $_GET['halaman'] ?? 1;
    $halaman_param = ($current_page > 1) ? "&halaman={$current_page}" : "";

    $base_url = "detail_arsip.php?code=" . urlencode($archiveCode);
    $link = "{$base_url}&sort={$column}&order={$new_order}{$halaman_param}";

    return "<a href=\"{$link}\" class=\"flex items-center hover:text-blue-500 transition\">{$arrow}</a>";
}
// =======================================================
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Arsip - Fideya</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .sidebar-item:hover, .sidebar-item.active { background-color: #2563eb; }
        .user-avatar { background-color: #f59e0b; }
        #sidebar { transition: transform 0.3s ease-in-out; }
        #patient-table-body tr:hover { background-color: #f9fafb; }
        #form-container-modal { display: none; }
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
                <a href="arsip.php" class="sidebar-item active flex items-center p-3 rounded-lg transition duration-200 admin-doctor"><i class="fas fa-folder-open w-6 text-center mr-3"></i>Arsip Pasien</a>
                <a href="pencarian.php" class="sidebar-item flex items-center p-3 rounded-lg transition duration-200 admin-doctor"><i class="fas fa-search w-6 text-center mr-3"></i>Pencarian Pasien</a>
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
                    <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2">Detail Arsip: <span id="archive-id-display" class="text-blue-600"><?php echo htmlspecialchars($archiveCode); ?></span></h1>
                    <a href="arsip.php" class="text-blue-600 hover:underline">&larr; Kembali ke daftar arsip</a>

                    <div class="bg-white p-6 rounded-xl shadow-md mt-8">
                        <div class="flex flex-col md:flex-row justify-between items-center mb-6">
                            <button id="btn-show-form" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition-all w-full md:w-auto mb-4 md:mb-0 admin-only" style="<?php echo isAdmin() ? '' : 'display: none;'; ?>"><i class="fas fa-plus-circle mr-2"></i>Tambah Pasien</button>
                            <div class="flex w-full md:w-auto gap-4">
                                <input type="text" id="search-input" placeholder="Cari pasien..." class="w-full md:w-auto border rounded-lg px-4 py-2">
                                <select id="filter-dokter" class="border rounded-lg px-4 py-2">
                                    <option value="">Semua Dokter</option>
                                    <?php foreach ($doctors as $d): ?>
                                        <option value="<?php echo htmlspecialchars($d['name']); ?>"><?php echo htmlspecialchars($d['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <?php if ($error_message): ?>
                            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4">⚠️ <?php echo $error_message; ?></div>
                        <?php elseif ($success_message): ?>
                            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4">✅ <?php echo $success_message; ?></div>
                        <?php endif; ?>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left">
                                <thead>
                                    <tr class="border-b bg-gray-50">
                                        <th class="p-4 cursor-pointer">
                                            <div class="flex items-center justify-between">
                                                <span>NRM</span>
                                                <?php echo get_sort_link('nrm', $sort_col_param, $sort_order, $archiveCode); ?>
                                            </div>
                                        </th>
                                        <th class="p-4 cursor-pointer">
                                            <div class="flex items-center justify-between">
                                                <span>Nama</span>
                                                <?php echo get_sort_link('name', $sort_col_param, $sort_order, $archiveCode); ?>
                                            </div>
                                        </th>
                                        <th class="p-4 cursor-pointer">
                                            <div class="flex items-center justify-between">
                                                <span>Gender</span>
                                                <?php echo get_sort_link('gender', $sort_col_param, $sort_order, $archiveCode); ?>
                                            </div>
                                        </th>
                                        <th class="p-4 cursor-pointer">
                                            <div class="flex items-center justify-between">
                                                <span>Diagnosa</span>
                                                <?php echo get_sort_link('diagnosis', $sort_col_param, $sort_order, $archiveCode); ?>
                                            </div>
                                        </th>
                                        <th class="p-4 cursor-pointer">
                                            <div class="flex items-center justify-between">
                                                <span>Dokter</span>
                                                <?php echo get_sort_link('doctor', $sort_col_param, $sort_order, $archiveCode); ?>
                                            </div>
                                        </th>
                                        <th class="p-4 cursor-pointer">
                                            <div class="flex items-center justify-between">
                                                <span>Tanggal</span>
                                                <?php echo get_sort_link('date', $sort_col_param, $sort_order, $archiveCode); ?>
                                            </div>
                                        </th>
                                        <th class="p-4">File</th>
                                        <th class="p-4 text-center admin-only" style="<?php echo isAdmin() ? '' : 'display: none;'; ?>">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody id="patient-table-body">
                                    <?php if (!empty($patients)): ?>
                                        <?php foreach ($patients as $p): ?>
                                            <tr data-id="<?php echo $p['id']; ?>" data-doctor="<?php echo htmlspecialchars($p['doctor_name']); ?>">
                                                <td class="p-4 font-medium">
                                                   <a 
                                                        href="riwayat_kunjungan.php?nrm=<?php echo urlencode($p['nrm']); ?>&nama=<?php echo urlencode($p['name']); ?>"
                                                             class="text-blue-600 hover:underline"
                                                             >
                                                         <?php echo htmlspecialchars($p['nrm']); ?>
                                                       </a>
                                                </td>

                                                <td class="p-4"><?php echo htmlspecialchars($p['name']); ?></td>
                                                <td class="p-4"><?php echo $p['gender']; ?></td>
                                                <td class="p-4"><?php echo htmlspecialchars($p['diagnosis']); ?></td>
                                                <td class="p-4"><?php echo htmlspecialchars($p['doctor_name']); ?></td>
                                                <td class="p-4"><?php echo date('Y-m-d', strtotime($p['patient_date'])); ?></td>
                                                <td class="p-4">
                                                     <?php if ($p['file_path']): ?>
                                                         <a href="<?php echo htmlspecialchars($p['file_path']); ?>" target="_blank" class="text-blue-600 hover:underline">Lihat File</a>
                                                     <?php else: ?>
                                                         -
                                                     <?php endif; ?>
                                                </td>
                                                <td class="p-4 text-center space-x-2 admin-only" style="<?php echo isAdmin() ? '' : 'display: none;'; ?>">
                                                     <button 
                                                         class="btn-edit text-blue-600" 
                                                         data-id="<?php echo $p['id']; ?>"
                                                         data-nrm="<?php echo htmlspecialchars($p['nrm']); ?>"
                                                         data-name="<?php echo htmlspecialchars($p['name']); ?>"
                                                         data-gender="<?php echo htmlspecialchars($p['gender']); ?>"
                                                         data-diagnosis="<?php echo htmlspecialchars($p['diagnosis']); ?>"
                                                         data-doctor-id="<?php echo htmlspecialchars($p['doctor_id']); ?>"
                                                         data-date="<?php echo date('Y-m-d', strtotime($p['patient_date'])); ?>"
                                                         data-filepath="<?php echo htmlspecialchars($p['file_path']); ?>"
                                                     ><i class="fas fa-edit"></i></button>
                                                     
                                                     <a href="?code=<?php echo urlencode($archiveCode); ?>&delete_id=<?php echo $p['id']; ?>" class="btn-delete text-red-500">
                                                         <i class="fas fa-trash"></i>
                                                     </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="8" class="p-4 text-center text-gray-500">Tidak ada data pasien yang ditemukan.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            <p id="no-results" class="text-center text-gray-500 py-8 hidden">Tidak ada data pasien yang ditemukan.</p>
                        </div>
                        
                        <?php if ($total_pages > 1): ?>
                        <div class="flex justify-between items-center mt-6">
                            <div class="text-sm text-gray-600">
                                Menampilkan data <?php echo min($total_patients, $offset + 1); ?> hingga <?php echo min($total_patients, $offset + $limit); ?> dari total <?php echo $total_patients; ?> data.
                            </div>
                            <nav class="flex items-center space-x-1" aria-label="Pagination">
                                <?php
                                $base_url = "detail_arsip.php?code=" . urlencode($archiveCode) . "&sort=" . urlencode($sort_col_param) . "&order=" . urlencode($sort_order);
                                
                                // Tombol Previous
                                $prev_page = $page - 1;
                                $prev_url = $base_url . "&halaman={$prev_page}";
                                $disabled_prev = ($page <= 1) ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-200';
                                ?>
                                <a href="<?php echo $prev_url; ?>" class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md <?php echo $disabled_prev; ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>

                                <?php for ($i = 1; $i <= $total_pages; $i++): 
                                    $page_url = $base_url . "&halaman={$i}";
                                    $active_class = ($i == $page) ? 'bg-blue-600 text-white border-blue-600 hover:bg-blue-700' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-200';
                                ?>
                                    <a href="<?php echo $page_url; ?>" class="px-3 py-2 text-sm font-medium border rounded-md <?php echo $active_class; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>

                                <?php
                                // Tombol Next
                                $next_page = $page + 1;
                                $next_url = $base_url . "&halaman={$next_page}";
                                $disabled_next = ($page >= $total_pages) ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-200';
                                ?>
                                <a href="<?php echo $next_url; ?>" class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md <?php echo $disabled_next; ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </nav>
                        </div>
                        <?php endif; ?>
                        </div>
                </div>
            </main>
        </div>
        <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-20 hidden"></div>

        <div id="form-container-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
            <div class="bg-white p-8 rounded-xl shadow-2xl w-full max-w-2xl">
                <h2 id="form-title" class="text-2xl font-bold mb-6">Tambah Pasien Baru</h2>
                <form id="patient-form" method="POST" action="detail_arsip.php?code=<?php echo urlencode($archiveCode); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_patient">
                    <input type="hidden" id="patient-id" name="patient-id">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                        <input type="text" id="patient-nrm" name="patient-nrm" placeholder="NRM" class="p-3 border rounded-lg" required>
                        <input type="text" id="patient-name" name="patient-name" placeholder="Nama Pasien" class="p-3 border rounded-lg" required>
                        <select id="patient-gender" name="patient-gender" class="p-3 border rounded-lg" required>
                            <option value="">Pilih Gender</option>
                            <option value="Laki-laki">Laki-laki</option>
                            <option value="Perempuan">Perempuan</option>
                        </select>
                        <select id="patient-doctor" name="patient-doctor" class="p-3 border rounded-lg" required>
                            <option value="">Pilih Dokter</option>
                            <?php foreach ($doctors as $d): ?>
                                <option value="<?php echo $d['id']; ?>" data-name="<?php echo htmlspecialchars($d['name']); ?>"><?php echo htmlspecialchars($d['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="date" id="patient-date" name="patient-date" class="p-3 border rounded-lg" required>
                        <input type="text" id="patient-diagnosis" name="patient-diagnosis" placeholder="Diagnosa" class="p-3 border rounded-lg" required>
                        <input type="file" id="patient-file" name="patient-file" class="p-2 border rounded-lg w-full">
                        <p id="file-hint" class="text-sm text-gray-500 md:col-span-2 hidden">Kosongkan jika tidak ingin mengubah file saat Edit. File saat ini: <span id="current-file"></span></p>
                    </div>
                    <div class="flex justify-end gap-4 mt-8"><button type="button" id="btn-cancel" class="bg-gray-300 text-gray-800 px-6 py-2 rounded-lg hover:bg-gray-400">Batal</button><button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700">Simpan</button></div>
                </form>
            </div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Logika Sidebar Mobile (sama)
    const hamburgerBtn = document.getElementById('hamburger-btn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const toggleSidebar = () => {
        sidebar.classList.toggle('-translate-x-full');
        overlay.classList.toggle('hidden');
    };
    if (hamburgerBtn) hamburgerBtn.addEventListener('click', toggleSidebar);
    if (overlay) overlay.addEventListener('click', toggleSidebar);

    const formModal = document.getElementById('form-container-modal');
    const btnShowForm = document.getElementById('btn-show-form');
    const btnCancel = formModal.querySelector('#btn-cancel');
    const patientForm = document.getElementById('patient-form');
    const formTitle = document.getElementById('form-title');
    const patientTableBody = document.getElementById('patient-table-body');
    const searchInput = document.getElementById('search-input');
    const filterDokter = document.getElementById('filter-dokter');
    const noResults = document.getElementById('no-results');
    const fileHint = document.getElementById('file-hint');
    const currentFileSpan = document.getElementById('current-file');
    
    // Fungsi untuk mengubah tampilan modal
    const showModal = (isEdit = false, data = {}) => {
        formTitle.textContent = isEdit ? 'Edit Data Pasien' : 'Tambah Pasien Baru';
        patientForm.reset();
        
        // Atur required atribut pada file input: Wajib saat tambah, opsional saat edit.
        document.getElementById('patient-file').required = !isEdit;

        if (isEdit) {
            document.getElementById('patient-id').value = data.id;
            document.getElementById('patient-nrm').value = data.nrm;
            document.getElementById('patient-name').value = data.name;
            document.getElementById('patient-gender').value = data.gender;
            document.getElementById('patient-date').value = data.date;
            document.getElementById('patient-diagnosis').value = data.diagnosis;
            document.getElementById('patient-doctor').value = data.doctorId; // Set value based on ID
            
            // Tampilkan hint file saat edit
            fileHint.classList.remove('hidden');
            if (data.filepath && data.filepath !== '-') {
                const filename = data.filepath.split('/').pop();
                currentFileSpan.innerHTML = `<a href="${data.filepath}" target="_blank" class="text-green-600 hover:underline">${filename}</a>`;
            } else {
                 currentFileSpan.textContent = 'Tidak ada';
            }
        } else {
            document.getElementById('patient-id').value = '';
            fileHint.classList.add('hidden');
        }
        formModal.style.display = 'flex';
    };

    const hideModal = () => { formModal.style.display = 'none'; };

    if (btnShowForm) btnShowForm.addEventListener('click', () => showModal(false));
    if (btnCancel) btnCancel.addEventListener('click', hideModal);
    window.addEventListener('click', (e) => { if (e.target === formModal) hideModal(); });

    // Event Listener untuk Edit (menggunakan delegation)
    patientTableBody.addEventListener('click', (e) => {
        const editButton = e.target.closest('.btn-edit');
        if (editButton) {
            // Ambil data dari data attributes
            const data = {
                id: editButton.dataset.id,
                nrm: editButton.dataset.nrm,
                name: editButton.dataset.name,
                gender: editButton.dataset.gender,
                diagnosis: editButton.dataset.diagnosis,
                doctorId: editButton.dataset.doctorId,
                date: editButton.dataset.date,
                filepath: editButton.dataset.filepath
            };
            showModal(true, data);
        }
    });

    // Filter dan Search (Client-side)
    // CATATAN: Karena pagination sudah diterapkan di backend, filtering/searching
    // yang dilakukan di sini (client-side) HANYA AKAN BEKERJA pada data yang 
    // sedang ditampilkan (maksimal 10 data). Untuk filtering seluruh data, 
    // Anda perlu memindahkan logika pencarian ke query SQL di backend.
    function filterAndRenderTable() {
        const searchTerm = searchInput.value.toLowerCase();
        const selectedDoctor = filterDokter.value;
        let visibleCount = 0;

        Array.from(patientTableBody.rows).forEach(row => {
        const name = row.cells[1].textContent.toLowerCase(); // kolom Nama
        const doctor = row.cells[4].textContent.trim();       // kolom Dokter
            
            const matchesSearch = name.includes(searchTerm);
            const matchesDoctor = selectedDoctor === '' || doctor === selectedDoctor;

            if (matchesSearch && matchesDoctor) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        noResults.classList.toggle('hidden', visibleCount > 0);
    }

    searchInput.addEventListener('input', filterAndRenderTable);
    filterDokter.addEventListener('change', filterAndRenderTable);
});

document.addEventListener('DOMContentLoaded', () => {
    // Cek status pesan dari URL
    const urlParams = new URLSearchParams(window.location.search);
    const status = urlParams.get('status');
    const msg = urlParams.get('msg');

    if (status === 'success') {
        Swal.fire({
            icon: 'success',
            title: 'Berhasil!',
            text: decodeURIComponent(msg),
            timer: 2000,
            showConfirmButton: false
        });
        // Hapus parameter dari URL tanpa reload ulang
        setTimeout(() => {
            const cleanUrl = window.location.origin + window.location.pathname + window.location.search.replace(/[?&]status=[^&]+/, '').replace(/[?&]msg=[^&]+/, '');
            window.history.replaceState({}, document.title, cleanUrl);
        }, 2500);
    } else if (status === 'error') {
        Swal.fire({
            icon: 'error',
            title: 'Gagal!',
            text: decodeURIComponent(msg),
            confirmButtonText: 'Tutup'
        });
        setTimeout(() => {
            const cleanUrl = window.location.origin + window.location.pathname + window.location.search.replace(/[?&]status=[^&]+/, '').replace(/[?&]msg=[^&]+/, '');
            window.history.replaceState({}, document.title, cleanUrl);
        }, 2500);
    }

    // ===============================
    // SweetAlert2 Konfirmasi Delete
    // ===============================
    const deleteLinks = document.querySelectorAll('a[href*="delete_id="]');
    deleteLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const href = link.getAttribute('href');
            const row = link.closest('tr');
            // Ambil NRM untuk konfirmasi
            const nrm = row ? row.cells[0].textContent.trim() : 'pasien ini'; 
            Swal.fire({
                title: 'Yakin ingin menghapus?',
                text: `Data pasien dengan NRM "${nrm}" akan dihapus permanen.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Ya, hapus!',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = href;
                }
            });
        });
    });
});
</script>
</body>
</html>