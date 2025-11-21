<?php
include 'auth_check.php';
include 'db_connect.php';

// Ambil parameter URL
$nrm = $_GET['nrm'] ?? '';
$nama = $_GET['nama'] ?? '';
$archive_code = $_GET['code'] ?? ''; // kode arsip dikirim dari halaman sebelumnya

if (!$nrm) {
    header("Location: arsip.php");
    exit;
}

// Ambil semua riwayat kunjungan berdasarkan NRM
$stmt = $conn->prepare("
    SELECT p.*, d.name AS doctor_name
    FROM patients p
    LEFT JOIN doctors d ON p.doctor_id = d.id
    WHERE p.nrm = ?
    ORDER BY p.patient_date DESC
");
$stmt->bind_param("s", $nrm);
$stmt->execute();
$riwayat = $stmt->get_result();

// Data user sidebar
$role_display = ucfirst($current_user_role);
$initial_char = strtoupper(substr($current_user_name, 0, 1));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Kunjungan - Fideya</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .sidebar-item:hover, .sidebar-item.active { background-color: #2563eb; }
        .user-avatar { background-color: #f59e0b; }
        #sidebar { transition: transform 0.3s ease-in-out; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 font-sans">

<div class="relative min-h-screen md:flex">

    <!-- Mobile Header -->
    <header class="md:hidden flex justify-between items-center p-4 bg-blue-800 text-white shadow-md">
        <button id="hamburger-btn"><i class="fas fa-bars fa-lg"></i></button>
        <h1 class="text-xl font-bold">Fideya</h1>
        <div class="w-8"></div>
    </header>

    <!-- Sidebar -->
    <aside id="sidebar" class="bg-blue-800 text-white w-64 flex-col fixed inset-y-0 left-0 
        transform -translate-x-full md:translate-x-0 md:relative md:flex z-30">

        <div class="p-6 text-2xl font-bold text-center border-b border-blue-700">Fideya</div>

        <nav class="flex-1 p-4 space-y-2">
            <a href="dashboard.php" class="sidebar-item flex items-center p-3 rounded-lg">
                <i class="fas fa-tachometer-alt w-6 mr-3"></i>Dashboard
            </a>
            <a href="arsip.php" class="sidebar-item flex items-center p-3 rounded-lg">
                <i class="fas fa-folder-open w-6 mr-3"></i>Arsip Pasien
            </a>
            <a href="pencarian.php" class="sidebar-item flex items-center p-3 rounded-lg">
                <i class="fas fa-search w-6 mr-3"></i>Pencarian Pasien
            </a>

            <!-- Admin Only -->
            <a href="users.php" class="sidebar-item flex items-center p-3 rounded-lg"
               style="<?php echo isAdmin() ? '' : 'display:none'; ?>">
                <i class="fas fa-users-cog w-6 mr-3"></i>Manajemen User
            </a>
        </nav>

        <div class="p-4 border-t border-blue-700">
            <div class="flex items-center mb-4">
                <div class="user-avatar w-10 h-10 rounded-full flex items-center justify-center font-bold text-xl mr-3">
                    <?php echo $initial_char; ?>
                </div>
                <span class="font-semibold"><?php echo $role_display; ?></span>
            </div>
            <a href="?logout=true" class="sidebar-item flex items-center p-3 rounded-lg">
                <i class="fas fa-sign-out-alt w-6 mr-3"></i>Keluar
            </a>
        </div>
    </aside>

    <!-- MAIN -->
    <div class="flex-1 flex flex-col overflow-hidden">
        <main class="flex-1 bg-gray-100 p-4 md:p-8">

            <div class="container mx-auto">

                <!-- TOMBOL KEMBALI 100% SESUAI ARSIP ASAL -->
                <a href="detail_arsip.php?code=<?php echo urlencode($archive_code); ?>"
                   class="text-blue-600 hover:underline inline-block mb-4">
                    <i class="fas fa-arrow-left mr-2"></i>Kembali
                </a>

                <h1 class="text-3xl font-bold">Riwayat Kunjungan Pasien</h1>

                <div class="mt-4">
                    <p class="text-lg"><strong>NRM:</strong> <?= htmlspecialchars($nrm) ?></p>
                    <p class="text-lg"><strong>Nama Pasien:</strong> <?= htmlspecialchars($nama) ?></p>
                </div>

                <!-- Card Riwayat -->
                <div class="bg-white p-6 rounded-xl shadow-md mt-8">
                    <h2 class="text-2xl font-bold mb-4">Detail Kunjungan</h2>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                            <tr class="border-b bg-gray-50">
                                <th class="p-4">NRM</th>
                                <th class="p-4">Nama</th>
                                <th class="p-4">Gender</th>
                                <th class="p-4">Diagnosis</th>
                                <th class="p-4">Dokter</th>
                                <th class="p-4">Tanggal</th>
                                <th class="p-4">File</th>

                                <?php if (isAdmin()): ?>
                                    <th class="p-4 text-center">Aksi</th>
                                <?php endif; ?>
                            </tr>
                            </thead>

                            <tbody>

                            <?php if ($riwayat->num_rows > 0): ?>
                                <?php while ($p = $riwayat->fetch_assoc()): ?>

                                    <tr class="border-b hover:bg-gray-50">

                                        <td class="p-4"><?= htmlspecialchars($p['nrm']); ?></td>
                                        <td class="p-4"><?= htmlspecialchars($p['name']); ?></td>
                                        <td class="p-4"><?= htmlspecialchars($p['gender']); ?></td>
                                        <td class="p-4"><?= htmlspecialchars($p['diagnosis']); ?></td>
                                        <td class="p-4"><?= htmlspecialchars($p['doctor_name']); ?></td>
                                        <td class="p-4"><?= date('Y-m-d', strtotime($p['patient_date'])); ?></td>

                                        <td class="p-4">
                                            <?php if ($p['file_path']): ?>
                                                <a href="<?= htmlspecialchars($p['file_path']); ?>" 
                                                   target="_blank"
                                                   class="text-blue-600 hover:underline">Lihat File</a>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>

                                        <!-- Aksi untuk admin saja -->
                                        <?php if (isAdmin()): ?>
                                            <td class="p-4 text-center space-x-2">

                                                <button 
                                                    class="btn-edit text-blue-600"
                                                    data-id="<?= $p['id']; ?>"
                                                    data-nrm="<?= htmlspecialchars($p['nrm']); ?>"
                                                    data-name="<?= htmlspecialchars($p['name']); ?>"
                                                    data-gender="<?= htmlspecialchars($p['gender']); ?>"
                                                    data-diagnosis="<?= htmlspecialchars($p['diagnosis']); ?>"
                                                    data-doctor-id="<?= htmlspecialchars($p['doctor_id']); ?>"
                                                    data-date="<?= date('Y-m-d', strtotime($p['patient_date'])); ?>"
                                                    data-filepath="<?= htmlspecialchars($p['file_path']); ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>

                                                <a href="?nrm=<?= urlencode($nrm); ?>&delete_id=<?= $p['id']; ?>"
                                                   class="btn-delete text-red-500">
                                                    <i class="fas fa-trash"></i>
                                                </a>

                                            </td>
                                        <?php endif; ?>

                                    </tr>

                                <?php endwhile; ?>
                            <?php else: ?>

                                <tr>
                                    <td colspan="<?= isAdmin() ? '8' : '7'; ?>"
                                        class="p-4 text-center text-gray-500">
                                        Tidak ada riwayat kunjungan.
                                    </td>
                                </tr>

                            <?php endif; ?>

                            </tbody>
                        </table>
                    </div>

                </div>

            </div>

        </main>
    </div>

</div>

<script>
// Sidebar Mobile Toggle
document.getElementById('hamburger-btn')?.addEventListener('click', () => {
    document.getElementById('sidebar').classList.toggle('-translate-x-full');
});
</script>

</body>
</html>
