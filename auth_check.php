<?php
session_start();
include_once 'db_connect.php';

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Cek Session Login
if (!isset($_SESSION['user_role'])) {
    header("Location: index.php");
    exit;
}

// Hindari undefined index error
$current_user_role = $_SESSION['user_role']; 
$current_user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : "User";

// ROLE CHECK FUNCTIONS
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function isOwner() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'owner';
}

function isDoctor() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'dokter';
}

function isDoctorOrAdmin() {
    return isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'dokter']);
}

function isAdminOrOwner() {
    return isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'owner']);
}

// CONTOH PEMBATASAN HALAMAN (aktifkan bila perlu)
// if (!isAdminOrOwner()) {
//     header("Location: dashboard.php");
//     exit;
// }
?>
