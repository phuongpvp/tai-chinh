<?php
require_once 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$loan_id = $_POST['loan_id'] ?? '';
$is_hidden = $_POST['is_hidden'] ?? 0;

if (empty($loan_id)) {
    echo json_encode(['success' => false, 'error' => 'Missing loan ID']);
    exit;
}

try {
    $stmt = $conn->prepare("UPDATE loans SET is_hidden_from_reminder = ? WHERE id = ?");
    $stmt->execute([$is_hidden, $loan_id]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>