<?php
// Konfigurasi Database
$host = 'localhost';
$user = 'root';     // GANTI dengan username DB Anda
$pass = '';         // GANTI dengan password DB Anda
$db_name = 'psiarsip';

// Membuat koneksi
$conn = new mysqli($host, $user, $pass, $db_name);

// Cek koneksi
if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

$conn->set_charset("utf8");

// Catatan: Selalu gunakan Prepared Statements untuk operasi INSERT/UPDATE
?>
