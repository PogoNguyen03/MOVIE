<?php
session_start();

// Kiểm tra xem người dùng đã đăng nhập chưa
function checkAuth() {
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        header('Location: login.php');
        exit;
    }
}

// Hàm đăng xuất
function logout() {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}
?> 