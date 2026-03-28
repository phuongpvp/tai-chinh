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
        // Update loan status back to active
        $stmt = $conn->prepare("UPDATE loans SET status = 'active' WHERE id = ? AND store_id = ?");
        $stmt->execute([$id, $current_store_id]);

        $redirect_url = "contracts.php" . ($return_status ? "?status=" . urlencode($return_status) : "");
        header("Location: " . $redirect_url . "&msg=" . urlencode("Đã kích hoạt lại hợp đồng!"));
        exit;
    } catch (Exception $e) {
        $redirect_url = "contracts.php" . ($return_status ? "?status=" . urlencode($return_status) : "");
        header("Location: " . $redirect_url . "&error=" . urlencode("Lỗi: " . $e->getMessage()));
        exit;
    }
}

header("Location: contracts.php");
exit;
