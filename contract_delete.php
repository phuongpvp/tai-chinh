<?php
session_start();
require_once 'config.php';
require_once 'permissions_helper.php';

// 1. Auth Check
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized Access");
}

// 2. Permission Check
requirePermission($conn, 'contracts.delete', 'contracts.php');

// 2. Bulk Delete Logic (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = $_POST['ids'];
    $count = 0;
    try {
        $conn->beginTransaction();

        $stmt1 = $conn->prepare("DELETE FROM transactions WHERE loan_id = ? AND store_id = ?");
        $stmt2 = $conn->prepare("DELETE FROM loan_extensions WHERE loan_id = ? AND store_id = ?");
        $stmt3 = $conn->prepare("DELETE FROM loans WHERE id = ? AND store_id = ?");

        foreach ($ids as $id) {
            $stmt1->execute([$id, $current_store_id]);
            $stmt2->execute([$id, $current_store_id]);
            $stmt3->execute([$id, $current_store_id]);
            $count++;
        }

        $conn->commit();
        header("Location: contracts.php?msg=" . urlencode("Đã xóa $count hợp đồng!"));
        exit();
    } catch (Exception $e) {
        if ($conn->inTransaction())
            $conn->rollBack();
        header("Location: contracts.php?error=" . urlencode("Lỗi xóa hàng loạt: " . $e->getMessage()));
        exit();
    }
}

// 3. Single Delete Logic (GET)
if (!isset($_GET['id'])) {
    header("Location: contracts.php?error=" . urlencode("Thiếu ID hợp đồng!"));
    exit();
}

$id = $_GET['id'];
$status = $_GET['status'] ?? ''; // Preserve current filter status

// 3. Delete Logic
try {
    $conn->beginTransaction();

    // Delete Transactions
    $stmt1 = $conn->prepare("DELETE FROM transactions WHERE loan_id = ? AND store_id = ?");
    $stmt1->execute([$id, $current_store_id]);

    // Delete Extensions
    $stmt2 = $conn->prepare("DELETE FROM loan_extensions WHERE loan_id = ? AND store_id = ?");
    $stmt2->execute([$id, $current_store_id]);

    // Delete Loan
    $stmt3 = $conn->prepare("DELETE FROM loans WHERE id = ? AND store_id = ?");
    $stmt3->execute([$id, $current_store_id]);

    if ($stmt3->rowCount() > 0) {
        $conn->commit();
        $redirect = "contracts.php?msg=" . urlencode("Đã xóa hợp đồng thành công!");
        if ($status) {
            $redirect .= "&status=" . urlencode($status);
        }
        header("Location: " . $redirect);
    } else {
        $conn->rollBack();
        $redirect = "contracts.php?error=" . urlencode("Không tìm thấy hợp đồng để xóa!");
        if ($status) {
            $redirect .= "&status=" . urlencode($status);
        }
        header("Location: " . $redirect);
    }

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    // Log error manually if needed, or display
    error_log("Delete Error: " . $e->getMessage());
    $redirect = "contracts.php?error=" . urlencode("Lỗi hệ thống: " . $e->getMessage());
    if ($status) {
        $redirect .= "&status=" . urlencode($status);
    }
    header("Location: " . $redirect);
}
exit();
