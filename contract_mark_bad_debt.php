<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

$id = $_GET['id'] ?? 0;
$return_status = $_GET['status'] ?? '';

if ($id > 0) {
    try {
        // Update loan status to bad_debt
        $stmt = $conn->prepare("UPDATE loans SET status = 'bad_debt' WHERE id = ? AND store_id = ?");
        $stmt->execute([$id, $current_store_id]);

        $params = [];
        if ($return_status) $params[] = "status=" . urlencode($return_status);
        $params[] = "msg=" . urlencode("Đã chuyển hợp đồng sang NỢ XẤU!");
        header("Location: contracts.php?" . implode("&", $params));
        exit;
    } catch (Exception $e) {
        $params = [];
        if ($return_status) $params[] = "status=" . urlencode($return_status);
        $params[] = "error=" . urlencode("Lỗi: " . $e->getMessage());
        header("Location: contracts.php?" . implode("&", $params));
        exit;
    }
}

header("Location: contracts.php");
exit;
