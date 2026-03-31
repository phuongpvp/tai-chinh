<?php
session_start();
require_once 'config.php';

// Set JSON header for AJAX requests
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$loan_id = $_POST['loan_id'] ?? 0;
$appointment_date = $_POST['appointment_date'] ?? null;

if ($loan_id > 0) {
    try {
        // Clear appointment date if empty
        if (empty($appointment_date)) {
            $stmt = $conn->prepare("UPDATE loans SET appointment_date = NULL WHERE id = ?");
            $stmt->execute([$loan_id]);
            $rows = $stmt->rowCount();
            if ($rows > 0) {
                echo json_encode(['success' => true, 'message' => 'Đã xóa ngày hẹn!']);
            } else {
                echo json_encode(['success' => false, 'error' => "Không tìm thấy khoản vay ID=$loan_id"]);
            }
        } else {
            $stmt = $conn->prepare("UPDATE loans SET appointment_date = ? WHERE id = ?");
            $stmt->execute([$appointment_date, $loan_id]);
            $rows = $stmt->rowCount();
            if ($rows > 0) {
                echo json_encode(['success' => true, 'message' => 'Đã cập nhật ngày hẹn thành công!']);
            } else {
                echo json_encode(['success' => false, 'error' => "Không cập nhật được. Loan ID=$loan_id, date=$appointment_date"]);
            }
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid loan ID']);
}
?>