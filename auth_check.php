<?php
session_start();
include_once 'db_connect.php';

// Fungsi Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Cek Session
if (!isset($_SESSION['user_role'])) {
    header("Location: index.php");
    exit;
}

$current_user_role = $_SESSION['user_role']; 
$current_user_name = $_SESSION['user_name']; 

// Fungsi untuk memeriksa peran di PHP
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}
function isDoctorOrAdmin() {
    return isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'dokter']);
}

// Untuk Halaman yang hanya boleh diakses Admin dan Dokter (misalnya arsip.php)
// if (!isDoctorOrAdmin()) {
//     header("Location: dashboard.php"); // Atau halaman lain
//     exit;
// }
?>
