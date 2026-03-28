<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';
require_once 'permissions_helper.php';

// Require permission to edit customers
requirePermission($conn, 'customers.edit', 'customers.php');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id'] ?? 0;
    $name = $_POST['name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $cmnd = $_POST['cmnd'] ?? '';
    $address = $_POST['address'] ?? '';

    if ($id > 0 && !empty($name)) {
        try {
            $stmt = $conn->prepare("UPDATE customers SET name = ?, phone = ?, cmnd = ?, address = ? WHERE id = ? AND store_id = ?");
            $stmt->execute([$name, $phone, $cmnd, $address, $id, $current_store_id]);
            header("Location: customers.php?msg=" . urlencode("Cập nhật thông tin khách hàng thành công!"));
            exit;
        } catch (Exception $e) {
            header("Location: customers.php?error=" . urlencode("Lỗi khi cập nhật: " . $e->getMessage()));
            exit;
        }
    }
}

header("Location: customers.php");
exit;
