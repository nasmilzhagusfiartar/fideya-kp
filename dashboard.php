<?php
include 'auth_check.php';
include 'db_connect.php';

// Ambil data statistik dari database
$total_pasien = $conn->query("SELECT COUNT(*) AS count FROM patients")->fetch_assoc()['count'];
$total_arsip = $conn->query("SELECT COUNT(*) AS count FROM archives")->fetch_assoc()['count'];
$total_dokter = $conn->query("SELECT COUNT(*) AS count FROM users WHERE role='dokter'")->fetch_assoc()['count'];

// Ambil data Diagnosa untuk Chart
$diagnosa_data = [];
$result = $conn->query("SELECT diagnosis, COUNT(*) as count FROM patients GROUP BY diagnosis ORDER BY count DESC LIMIT 5");
while ($row = $result->fetch_assoc()) {
    $diagnosa_data[$row['diagnosis']] = (int)$row['count'];
}
$diagnosa_json = json_encode($diagnosa_data); // Konversi ke JSON untuk dipakai di JS

$conn->close();

$role_display = ucfirst($current_user_role); // Admin, Dokter, Owner
$initial_char = strtoupper(substr($current_user_name, 0, 1));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Fideya Ardigi</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                <a href="dashboard.php" class="sidebar-item active flex items-center p-3 rounded-lg transition duration-200"><i class="fas fa-tachometer-alt w-6 text-center mr-3"></i>Dashboard</a>
                <a href="arsip.php" class="sidebar-item flex items-center p-3 rounded-lg transition duration-200"><i class="fas fa-folder-open w-6 text-center mr-3"></i>Arsip Pasien</a>
                <a href="pencarian.php" class="sidebar-item flex items-center p-3 rounded-lg transition duration-200"><i class="fas fa-search w-6 text-center mr-3"></i>Pencarian Global</a>
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
                    <div class="mb-8">
                        <h1 id="welcome-message" class="text-3xl md:text-4xl font-bold text-gray-900">Selamat Datang, <?php echo $current_user_name; ?>!</h1>
                        <p class="text-gray-600 mt-1">Berikut adalah ringkasan aktivitas klinik Anda.</p>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                        <div class="bg-white p-6 rounded-xl shadow-md border border-gray-200 flex items-center">
                            <div class="bg-blue-100 text-blue-600 p-4 rounded-full"><i class="fas fa-users fa-2x"></i></div>
                            <div class="ml-4"><h2 class="text-gray-500">Total Pasien</h2><p id="total-pasien" class="text-3xl font-bold"><?php echo $total_pasien; ?></p></div>
                        </div>
                        <div class="bg-white p-6 rounded-xl shadow-md border border-gray-200 flex items-center">
                            <div class="bg-green-100 text-green-600 p-4 rounded-full"><i class="fas fa-archive fa-2x"></i></div>
                            <div class="ml-4"><h2 class="text-gray-500">Total Arsip</h2><p id="total-arsip" class="text-3xl font-bold"><?php echo $total_arsip; ?></p></div>
                        </div>
                        <div class="bg-white p-6 rounded-xl shadow-md border border-gray-200 flex items-center">
                             <div class="bg-purple-100 text-purple-600 p-4 rounded-full"><i class="fas fa-user-md fa-2x"></i></div>
                            <div class="ml-4"><h2 class="text-gray-500">Total Dokter</h2><p id="total-dokter" class="text-3xl font-bold"><?php echo $total_dokter; ?></p></div>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                        <div class="lg:col-span-1 space-y-6">
                            <h3 class="text-xl font-semibold text-gray-900">Aksi Cepat</h3>
                            <?php if (isAdmin()): ?>
                                <a href="arsip.php" class="block bg-blue-600 text-white p-6 rounded-xl shadow-lg hover:bg-blue-700 transition duration-300 admin-only">
                                    <h4 class="text-lg font-bold">Manajemen Arsip</h4><p class="mt-1 text-blue-100">Lihat, tambah, atau edit arsip.</p>
                                </a>
                            <?php else: ?>
                                <a href="arsip.php" class="block bg-blue-600 text-white p-6 rounded-xl shadow-lg hover:bg-blue-700 transition duration-300 viewer-only">
                                    <h4 class="text-lg font-bold">Lihat Arsip</h4><p class="mt-1 text-blue-100">Lihat dan cari data arsip pasien.</p>
                                </a>
                            <?php endif; ?>
                            <a href="pencarian.php" class="block bg-gray-800 text-white p-6 rounded-xl shadow-lg hover:bg-gray-900 transition duration-300">
                                <h4 class="text-lg font-bold">Pencarian Pasien</h4><p class="mt-1 text-gray-300">Temukan data pasien dengan cepat.</p>
                            </a>
                        </div>
                        <div class="lg:col-span-2 bg-white p-6 rounded-xl shadow-md border border-gray-200">
                            <h3 class="text-xl font-semibold text-gray-900 mb-1">Distribusi Diagnosa</h3>
                             <p class="text-gray-500 mb-4">5 diagnosa pasien teratas di klinik.</p>
                            <div class="relative h-80 w-full"><canvas id="diagnosaChart"></canvas></div>
                        </div>
                    </div>
                </div>
                </main>
        </div>
        <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-20 hidden"></div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Data dari PHP dimasukkan ke JS
            const rawDiagnosaData = <?php echo $diagnosa_json; ?>;
            const labels = Object.keys(rawDiagnosaData);
            const dataValues = Object.values(rawDiagnosaData);
            
            const totalData = dataValues.reduce((a, b) => a + b, 0);

            const ctx = document.getElementById('diagnosaChart').getContext('2d');
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{ 
                        data: dataValues, 
                        backgroundColor: ['#3b82f6', '#10b981', '#ef4444', '#f97316', '#6b7280'], 
                        borderColor: '#ffffff', 
                        borderWidth: 3, 
                        hoverOffset: 15 
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '70%',
                    plugins: {
                        legend: { position: 'bottom', align: 'center', labels: { usePointStyle: true, boxWidth: 8, padding: 20, font: { size: 14 } } },
                        tooltip: { 
                            enabled: true, 
                            backgroundColor: '#1f2937', 
                            titleFont: { size: 16, weight: 'bold' }, 
                            bodyFont: { size: 14 }, 
                            padding: 12, 
                            cornerRadius: 8, 
                            callbacks: { 
                                label: (c) => ` ${c.label || ''}: ${c.raw} (${((c.raw / totalData) * 100).toFixed(1)}%)` 
                            } 
                        }
                    }
                }
            });
            
            // Logika Sidebar Mobile (sama seperti di HTML asli)
            const hamburgerBtn = document.getElementById('hamburger-btn');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            if (hamburgerBtn && sidebar && overlay) {
                const toggleSidebar = () => {
                    sidebar.classList.toggle('-translate-x-full');
                    overlay.classList.toggle('hidden');
                };
                hamburgerBtn.addEventListener('click', toggleSidebar);
                overlay.addEventListener('click', toggleSidebar);
            }
        });
    </script>
</body>
</html>