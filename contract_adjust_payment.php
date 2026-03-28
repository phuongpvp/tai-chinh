<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$payment_id = $_POST['payment_id'] ?? 0;
$new_amount = $_POST['new_amount'] ?? 0;

if ($payment_id <= 0 || $new_amount < 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit();
}

try {
    // Get payment details
    $stmt = $conn->prepare("
        SELECT ph.*, l.store_id 
        FROM payment_history ph
        JOIN loans l ON ph.loan_id = l.id
        WHERE ph.id = ?
    ");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        echo json_encode(['success' => false, 'error' => 'Payment not found']);
        exit();
    }

    // Check permission
    if ($payment['store_id'] != $current_store_id) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit();
    }

    // Calculate expected interest amount
    $expected_interest = floatval($payment['interest_amount']);
    $new_amount = floatval($new_amount);

    // Calculate overpayment or underpayment
    $overpayment = 0;
    $underpayment = 0;

    if ($new_amount > $expected_interest) {
        $overpayment = $new_amount - $expected_interest;
    } elseif ($new_amount < $expected_interest) {
        $underpayment = $expected_interest - $new_amount;
    }

    // Update payment record
    $stmt = $conn->prepare("
        UPDATE payment_history 
        SET amount_paid = ?,
            overpayment = ?,
            underpayment = ?
        WHERE id = ?
    ");
    $stmt->execute([$new_amount, $overpayment, $underpayment, $payment_id]);

    echo json_encode([
        'success' => true,
        'overpayment' => $overpayment,
        'underpayment' => $underpayment,
        'expected_interest' => $expected_interest,
        'new_amount' => $new_amount
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>