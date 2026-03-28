<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';
require_once 'permissions_helper.php';

// Require permission to delete customers
requirePermission($conn, 'customers.delete', 'customers.php');

$id = $_GET['id'] ?? 0;

if ($id > 0) {
    // Check for loans
    $stmt = $conn->prepare("SELECT COUNT(*) FROM loans WHERE customer_id = ?");
    $stmt->execute([$id]);
    $loan_count = $stmt->fetchColumn();

    if ($loan_count > 0) {
        header("Location: customers.php?error=" . urlencode("Không thể xóa khách hàng đang có hợp đồng vay!"));
        exit;
    }

    // Safe to delete
    try {
        $stmt = $conn->prepare("DELETE FROM customers WHERE id = ? AND store_id = ?");
        $stmt->execute([$id, $current_store_id]);
        header("Location: customers.php?msg=" . urlencode("Đã xóa khách hàng thành công!"));
        exit;
    } catch (Exception $e) {
        header("Location: customers.php?error=" . urlencode("Lỗi khi xóa: " . $e->getMessage()));
        exit;
    }
}

header("Location: customers.php");
exit;
