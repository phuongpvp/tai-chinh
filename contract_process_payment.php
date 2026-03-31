<?php
session_start();
file_put_contents('debug_log.txt', date('Y-m-d H:i:s') . " - Accessing Payment Script. Session: " . print_r($_SESSION, true) . "\n", FILE_APPEND);
require_once 'config.php';
require_once 'permissions_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check permission based on action
$action = $_POST['action'] ?? '';
if ($action == 'pay' && !hasPermission($conn, 'contracts.pay_interest')) {
    echo json_encode(['success' => false, 'message' => 'Bạn không có quyền đóng lãi']);
    exit;
}
if ($action == 'unpay' && !hasPermission($conn, 'contracts.unpay_interest')) {
    echo json_encode(['success' => false, 'message' => 'Bạn không có quyền hủy đóng lãi']);
    exit;
}

$action = $_POST['action'] ?? '';
$loan_id = $_POST['loan_id'] ?? 0;

if (!$loan_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid Loan ID']);
    exit;
}

// Verify Store Context
$stmt = $conn->prepare("SELECT * FROM loans WHERE id = ? AND store_id = ?");
$stmt->execute([$loan_id, $current_store_id]);
$loan = $stmt->fetch();

if (!$loan) {
    echo json_encode(['success' => false, 'message' => 'Loan not found']);
    exit;
}

try {
    if ($action === 'pay') {
        $from_date = $_POST['from_date'];
        $to_date = $_POST['to_date'];
        $amount = str_replace([',', '.'], '', $_POST['amount']);
        $note = "Đóng lãi từ " . date('d/m/Y', strtotime($from_date)) . " đến " . date('d/m/Y', strtotime($to_date));

        // 1. Create Transaction with User ID
        $stmt = $conn->prepare("INSERT INTO transactions (store_id, loan_id, type, amount, note, date, user_id, created_at) VALUES (?, ?, 'collect_interest', ?, ?, NOW(), ?, NOW())");
        $stmt->execute([$current_store_id, $loan_id, $amount, $note, $_SESSION['user_id']]);

        // 2. Update Loan Dates
        // Logic: paid_until_date becomes to_date
        // next_payment_date becomes to_date + 1 day (standard logic)

        $new_next_payment = date('Y-m-d', strtotime($to_date . ' + 1 day'));

        $stmt = $conn->prepare("UPDATE loans SET paid_until_date = ?, next_payment_date = ?, is_hidden_from_reminder = 0, appointment_date = NULL WHERE id = ?");
        $stmt->execute([$to_date, $new_next_payment, $loan_id]);

        // === AUTO CV: Khi đóng lãi xong → chuyển sang "Đã hoàn thành" ===
        require_once __DIR__ . '/cv_helper.php';
        cvAutoTransferOnPayment($conn, $loan_id, $_SESSION['user_id']);

        echo json_encode(['success' => true, 'message' => 'Payment recorded']);

    } elseif ($action === 'unpay') {
        $from_date = $_POST['from_date'];
        $to_date = $_POST['to_date'];

        // Build the expected note for exact matching
        $note_search = "Đóng lãi từ " . date('d/m/Y', strtotime($from_date)) . " đến " . date('d/m/Y', strtotime($to_date));
        
        // Strategy 1: Find by exact note match
        $stmt = $conn->prepare("SELECT id, amount, note FROM transactions WHERE loan_id = ? AND type = 'collect_interest' AND note = ? AND amount > 0 ORDER BY id DESC LIMIT 1");
        $stmt->execute([$loan_id, $note_search]);
        $trans = $stmt->fetch();

        // Strategy 2: Find by date range (transaction date matches to_date or from_date)
        if (!$trans) {
            $stmt = $conn->prepare("SELECT id, amount, note FROM transactions WHERE loan_id = ? AND type = 'collect_interest' AND amount > 0 AND (date = ? OR date = ?) ORDER BY id DESC LIMIT 1");
            $stmt->execute([$loan_id, $to_date, $from_date]);
            $trans = $stmt->fetch();
        }

        // Strategy 3: Find most recent collect_interest for this loan
        if (!$trans) {
            $stmt = $conn->prepare("SELECT id, amount, note FROM transactions WHERE loan_id = ? AND type = 'collect_interest' AND amount > 0 ORDER BY id DESC LIMIT 1");
            $stmt->execute([$loan_id]);
            $trans = $stmt->fetch();
        }

        if ($trans) {
            // Create a REVERSAL transaction (negative amount)
            $reversal_note = "Hủy đóng lãi: " . $trans['note'];
            $reversal_amount = -1 * abs($trans['amount']);

            $stmt = $conn->prepare("INSERT INTO transactions (store_id, loan_id, type, amount, note, date, user_id, created_at) 
                                    VALUES (?, ?, 'collect_interest', ?, ?, NOW(), ?, NOW())");
            $stmt->execute([$current_store_id, $loan_id, $reversal_amount, $reversal_note, $_SESSION['user_id']]);

            // Roll back paid_until_date to before this period
            $new_paid_until = date('Y-m-d', strtotime($from_date . ' - 1 day'));
            $stmt = $conn->prepare("UPDATE loans SET paid_until_date = ?, next_payment_date = ?, is_hidden_from_reminder = 0, appointment_date = NULL WHERE id = ?");
            $stmt->execute([$new_paid_until, $from_date, $loan_id]);

            echo json_encode(['success' => true, 'message' => 'Payment reverted']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy giao dịch để hủy']);
        }

    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>