<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

// Only Super Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'super_admin') {
    die("Unauthorized");
}

$id = $_GET['id'] ?? 0;

if ($id == $_SESSION['user_id']) {
    header("Location: users.php?error=" . urlencode("Không thể tự xóa tài khoản của chính mình!"));
    exit;
}

if ($id) {
    try {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: users.php?msg=" . urlencode("Đã xóa nhân viên thành công!"));
    } catch (Exception $e) {
        header("Location: users.php?error=" . urlencode("Lỗi: " . $e->getMessage()));
    }
} else {
    header("Location: users.php");
}
exit;
?>