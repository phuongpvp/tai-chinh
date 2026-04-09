<?php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Invalid method']);
    exit;
}

$loanId = intval($_POST['loan_id'] ?? 0);
if (!$loanId) {
    echo json_encode(['ok' => false, 'msg' => 'Missing loan_id']);
    exit;
}

try {
    // Toggle: nếu đang 1 thì đổi thành 0 và ngược lại
    $stmt = $pdo->prepare("UPDATE loans SET cv_is_urgent = IF(cv_is_urgent = 1, 0, 1) WHERE id = ?");
    $stmt->execute([$loanId]);

    // Trả về trạng thái mới
    $stmt2 = $pdo->prepare("SELECT cv_is_urgent FROM loans WHERE id = ?");
    $stmt2->execute([$loanId]);
    $newVal = intval($stmt2->fetchColumn());

    echo json_encode(['ok' => true, 'is_urgent' => $newVal]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}
