<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

$id = $_GET['id'] ?? 0;
$status = $_GET['status'] ?? '';

if ($id) {
    try {
        // Re-open: Set status to 'active', clear end_date (or keep it? Usually clear closing date).
        // If we clear end_date, it might affect calculations? 
        // No, 'end_date' usually means 'Expected End Date' for active loans, and 'Actual Closed Date' for closed loans.
        // When reopening, we should probably revert to the original expected end date or just keep current date.
        // Simplest logic: Status = 'active'.
        // Let's assume end_date was updated to 'Date Closed' when closing. We might want to keep it or user will extend later.

        $stmt = $conn->prepare("UPDATE loans SET status = 'active' WHERE id = ? AND store_id = ?");
        $stmt->execute([$id, $current_store_id]);

        $msg = "Đã mở lại hợp đồng thành công!";
        header("Location: contracts.php?msg=" . urlencode($msg) . ($status ? "&status=" . urlencode($status) : ""));
        exit;

    } catch (Exception $e) {
        $error = "Lỗi: " . $e->getMessage();
        header("Location: contracts.php?error=" . urlencode($error) . ($status ? "&status=" . urlencode($status) : ""));
        exit;
    }
} else {
    header("Location: contracts.php");
    exit;
}
?>