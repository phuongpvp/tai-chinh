<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';
require_once 'permissions_helper.php';

// Check permission
if (!hasPermission($conn, 'dividends.distribute')) {
    die("Bạn không có quyền chia cổ tức!");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'distribute_single') {
    $store_id = $_POST['store_id'];
    $distribution_date = $_POST['distribution_date'];
    $total_amount = str_replace([',', '.'], '', $_POST['total_amount']);
    $note = trim($_POST['note']);

    // Verify store ownership
    if ($store_id != $current_store_id) {
        die("Không có quyền thao tác với store này!");
    }

    // Validate amount
    if ($total_amount <= 0) {
        header("Location: dividend_distribution.php?error=" . urlencode("Số tiền phải lớn hơn 0!"));
        exit;
    }

    // Check if store has enough balance (calculate actual cash fund)
    $stmt = $conn->prepare("SELECT initial_balance FROM stores WHERE id = ?");
    $stmt->execute([$store_id]);
    $store = $stmt->fetch();
    $initial_balance = $store['initial_balance'] ?? 0;

    // Calculate actual cash fund
    $sql_cash_in = "SELECT 
                    (SELECT SUM(amount) FROM transactions WHERE store_id = ? AND type IN ('collect_interest', 'pay_principal', 'pay_all') AND (note NOT LIKE '%Import%' OR note IS NULL)) as in1,
                    (SELECT SUM(amount) FROM other_transactions WHERE store_id = ? AND type = 'income' AND (note NOT LIKE '%Import%' OR note IS NULL)) as in2";
    $stmt_in = $conn->prepare($sql_cash_in);
    $stmt_in->execute([$store_id, $store_id]);
    $row_in = $stmt_in->fetch();
    $total_in = ($row_in['in1'] ?? 0) + ($row_in['in2'] ?? 0);

    $sql_cash_out = "SELECT 
                    (SELECT SUM(amount) FROM transactions WHERE store_id = ? AND type IN ('disburse', 'lend_more') AND (note NOT LIKE '%Import%' OR note IS NULL)) as out1,
                    (SELECT SUM(amount) FROM other_transactions WHERE store_id = ? AND type = 'expense' AND (note NOT LIKE '%Import%' OR note IS NULL)) as out2";
    $stmt_out = $conn->prepare($sql_cash_out);
    $stmt_out->execute([$store_id, $store_id]);
    $row_out = $stmt_out->fetch();
    $total_out = ($row_out['out1'] ?? 0) + ($row_out['out2'] ?? 0);

    $current_balance = $initial_balance + $total_in - $total_out;

    if ($current_balance < $total_amount) {
        header("Location: dividend_distribution.php?error=" . urlencode("Quỹ không đủ! Hiện tại: " . number_format($current_balance) . "đ"));
        exit;
    }

    // Get shareholders
    $stmt = $conn->prepare("SELECT * FROM shareholders WHERE store_id = ?");
    $stmt->execute([$store_id]);
    $shareholders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($shareholders) == 0) {
        header("Location: dividend_distribution.php?error=" . urlencode("Không có cổ đông nào!"));
        exit;
    }

    try {
        $conn->beginTransaction();

        // 1. Create distribution record
        $stmt = $conn->prepare("INSERT INTO dividend_distributions 
            (store_id, total_amount, distribution_date, note, user_id, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$store_id, $total_amount, $distribution_date, $note, $_SESSION['user_id']]);
        $distribution_id = $conn->lastInsertId();

        // 2. Create detail records for each shareholder
        $stmt = $conn->prepare("INSERT INTO dividend_details 
            (distribution_id, shareholder_id, shareholder_code, shareholder_name, amount, percentage) 
            VALUES (?, ?, ?, ?, ?, ?)");

        foreach ($shareholders as $sh) {
            $amount = round($total_amount * ($sh['percentage'] / 100));
            $stmt->execute([
                $distribution_id,
                $sh['id'],
                $sh['shareholder_code'],
                $sh['name'],
                $amount,
                $sh['percentage']
            ]);
        }

        // 3. Log to cash book (other_transactions)
        $expense_note = "Chia cổ tức ngày " . date('d/m/Y', strtotime($distribution_date));
        if ($note) {
            $expense_note .= " - " . $note;
        }

        $stmt = $conn->prepare("INSERT INTO other_transactions 
            (store_id, type, amount, receiver_payer, note, user_id, created_at) 
            VALUES (?, 'expense', ?, 'Cổ đông', ?, ?, NOW())");
        $stmt->execute([$store_id, $total_amount, $expense_note, $_SESSION['user_id']]);

        // 4. Cash fund is automatically updated via other_transactions (step 3)
        // No need to modify initial_balance directly

        $conn->commit();

        header("Location: dividend_distribution.php?msg=" . urlencode("Chia cổ tức thành công! Đã trừ " . number_format($total_amount) . "đ vào quỹ."));
        exit;

    } catch (Exception $e) {
        $conn->rollBack();
        header("Location: dividend_distribution.php?error=" . urlencode("Lỗi: " . $e->getMessage()));
        exit;
    }
}

// Invalid request
header("Location: dividend_distribution.php");
exit;
?>