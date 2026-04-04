<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

// Get current user info
$user_id = $_SESSION['user_id'];
$sql_user = "SELECT username FROM users WHERE id = ?";
$stmt_user = $conn->prepare($sql_user);
$stmt_user->execute([$user_id]);
$current_user = $stmt_user->fetch();
$current_user_name = $current_user['username'] ?? 'N/A';

// Filter
$from_date = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
$to_date = $_GET['to_date'] ?? date('Y-m-d');

// Helper function to get sum
function getSum($conn, $sql, $params) {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() ?: 0;
    } catch (Exception $e) {
        return 0;
    }
}

// ==================== SUMMARY CALCULATION ====================

// 1. Fetch Store Initial Balance
$store_initial_balance = 0;
try {
    $stmt_capital = $conn->prepare("SELECT initial_balance FROM stores WHERE id = ?");
    $stmt_capital->execute([$current_store_id]);
    $store_capital = $stmt_capital->fetch();
    $store_initial_balance = $store_capital['initial_balance'] ?? 0;
} catch (Exception $e) {
    $store_initial_balance = 0;
}

// 2. Calculate OPENING BALANCE (Status before $from_date)
$prev_date = date('Y-m-d', strtotime($from_date . ' -1 day'));

// Loans before period
$sql = "SELECT SUM(amount) FROM transactions WHERE store_id = ? AND date <= ? AND type IN ('collect_interest', 'pay_principal', 'pay_all') AND (note NOT LIKE '%Import%' OR note IS NULL)";
$pre_loans_in = getSum($conn, $sql, [$current_store_id, $prev_date]);

$sql = "SELECT SUM(amount) FROM transactions WHERE store_id = ? AND date <= ? AND type IN ('disburse', 'lend_more') AND (note NOT LIKE '%Import%' OR note IS NULL)";
$pre_loans_out = getSum($conn, $sql, [$current_store_id, $prev_date]);

// Other before period
$sql = "SELECT SUM(amount) FROM other_transactions WHERE store_id = ? AND DATE(created_at) <= ? AND type = 'income'";
$pre_other_in = getSum($conn, $sql, [$current_store_id, $prev_date]);

$sql = "SELECT SUM(amount) FROM other_transactions WHERE store_id = ? AND DATE(created_at) <= ? AND type = 'expense'";
$pre_other_out = getSum($conn, $sql, [$current_store_id, $prev_date]);

$period_opening_balance = $store_initial_balance + ($pre_loans_in - $pre_loans_out) + ($pre_other_in - $pre_other_out);

// 3. Calculate CLOSING BALANCE (up to end date)
// Loans total up to to_date
$sql = "SELECT SUM(amount) FROM transactions WHERE store_id = ? AND date <= ? AND type IN ('collect_interest', 'pay_principal', 'pay_all') AND (note NOT LIKE '%Import%' OR note IS NULL)";
$total_loans_in = getSum($conn, $sql, [$current_store_id, $to_date]);

$sql = "SELECT SUM(amount) FROM transactions WHERE store_id = ? AND date <= ? AND type IN ('disburse', 'lend_more') AND (note NOT LIKE '%Import%' OR note IS NULL)";
$total_loans_out = getSum($conn, $sql, [$current_store_id, $to_date]);

// Other total up to to_date
$sql = "SELECT SUM(amount) FROM other_transactions WHERE store_id = ? AND DATE(created_at) <= ? AND type = 'income'";
$total_other_in = getSum($conn, $sql, [$current_store_id, $to_date]);

$sql = "SELECT SUM(amount) FROM other_transactions WHERE store_id = ? AND DATE(created_at) <= ? AND type = 'expense'";
$total_other_out = getSum($conn, $sql, [$current_store_id, $to_date]);

$closing_balance = $store_initial_balance + ($total_loans_in - $total_loans_out) + ($total_other_in - $total_other_out);

// ==================== PERIOD TRANSACTIONS (for display in summary table) ====================

// Period Balance (Within date range) - for display only
// 2.1 Loans In - Exclude imported transactions
$sql = "SELECT SUM(amount) FROM transactions WHERE store_id = ? AND date BETWEEN ? AND ? AND type IN ('collect_interest', 'pay_principal', 'pay_all') AND (note NOT LIKE '%Import%' OR note IS NULL)";
$pe_loans_in = getSum($conn, $sql, [$current_store_id, $from_date, $to_date]);

// 2.2 Loans Out - Exclude imported transactions
$sql = "SELECT SUM(amount) FROM transactions WHERE store_id = ? AND date BETWEEN ? AND ? AND type IN ('disburse', 'lend_more') AND (note NOT LIKE '%Import%' OR note IS NULL)";
$pe_loans_out = getSum($conn, $sql, [$current_store_id, $from_date, $to_date]);

// 2.3 Other In
$sql = "SELECT SUM(amount) FROM other_transactions WHERE store_id = ? AND DATE(created_at) BETWEEN ? AND ? AND type = 'income'";
$pe_other_in = getSum($conn, $sql, [$current_store_id, $from_date, $to_date]);

// 2.4 Other Out
$sql = "SELECT SUM(amount) FROM other_transactions WHERE store_id = ? AND DATE(created_at) BETWEEN ? AND ? AND type = 'expense'";
$pe_other_out = getSum($conn, $sql, [$current_store_id, $from_date, $to_date]);

// Display Variables for Summary Table
$sum_tin_chap = $pe_loans_in - $pe_loans_out; // Net change from loans in this period
$sum_thu = $pe_other_in; // Other income in this period
$sum_chi = $pe_other_out; // Other expenses in this period


// ==================== CREDIT TRANSACTIONS (TÍN CHẤP) ====================
try {
    $sql_credit = "SELECT 
        t.id,
        t.date,
        t.type,
        t.amount,
        t.note,
        l.id as loan_id,
        l.loan_code,
        c.name as customer_name,
        COALESCE(u.fullname, u.username, 'Admin') as user_name
    FROM transactions t
    JOIN loans l ON t.loan_id = l.id
    JOIN customers c ON l.customer_id = c.id
    LEFT JOIN users u ON t.user_id = u.id
    WHERE t.store_id = ? AND t.date BETWEEN ? AND ? AND (t.note NOT LIKE '%Import%' OR t.note IS NULL) AND t.type != 'adjust_debt' AND t.amount > 0
    ORDER BY t.date ASC, t.id ASC";

    $stmt_credit = $conn->prepare($sql_credit);
    $stmt_credit->execute([$current_store_id, $from_date, $to_date]);
    $credit_trans = $stmt_credit->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $credit_trans = [];
    $credit_error = $e->getMessage();
}

// Calculate totals for credit section
$credit_total_lend = 0;
$credit_total_principal = 0;
$credit_total_interest = 0;
$credit_total_other = 0;

if (!empty($credit_trans)) {
    foreach ($credit_trans as $t) {
        if ($t['type'] == 'disburse' || $t['type'] == 'lend_more') {
            $credit_total_lend += $t['amount'];
        } elseif ($t['type'] == 'pay_principal' || $t['type'] == 'pay_all') {
            $credit_total_principal += $t['amount'];
        } elseif ($t['type'] == 'collect_interest') {
            $credit_total_interest += $t['amount'];
        }
    }
}

// ==================== OTHER TRANSACTIONS (THU CHI) ====================
try {
    $sql_other = "SELECT 
        t.id,
        t.created_at as date,
        t.type,
        t.amount,
        t.receiver_payer as name,
        t.note,
        u.username as user_name
    FROM other_transactions t
    LEFT JOIN users u ON t.user_id = u.id
    WHERE t.store_id = ? AND DATE(t.created_at) BETWEEN ? AND ?
    ORDER BY t.created_at ASC, t.id ASC";

    $stmt_other = $conn->prepare($sql_other);
    $stmt_other->execute([$current_store_id, $from_date, $to_date]);
    $other_trans = $stmt_other->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $other_trans = [];
    $other_error = $e->getMessage();
}

$other_total_income = 0;
$other_total_expense = 0;

if (!empty($other_trans)) {
    foreach ($other_trans as $t) {
        if ($t['type'] == 'income') {
            $other_total_income += $t['amount'];
        } else {
            $other_total_expense += $t['amount'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sổ quỹ tiền mặt</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="/style.css">
    <style>
        .summary-table {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: bold;
        }

        .summary-table td {
            padding: 12px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .section-header {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            font-weight: bold;
            padding: 10px 15px;
            margin-top: 20px;
            border-radius: 5px 5px 0 0;
        }

        .table-section {
            border: 2px solid #4facfe;
            border-radius: 5px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .table-section thead {
            background: #4facfe;
            color: white;
        }

        .total-row {
            background: #fff3cd !important;
            font-weight: bold;
            color: #856404;
        }
    </style>
</head>

<body>
    <?php include 'header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content" style="padding-top: 80px !important;">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h4"><i class="fas fa-book"></i> Sổ quỹ tiền mặt</h1>
                </div>

                <!-- Filters -->
                <div class="card shadow-sm mb-3 border-0">
                    <div class="card-body bg-light p-3">
                        <form method="GET" class="row g-2 align-items-end">
                            <div class="col-auto">
                                <label class="small fw-bold">Nhân Viên</label>
                                <select class="form-select form-select-sm" disabled>
                                    <option>Tất cả nhân viên</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <label class="small fw-bold">Từ ngày</label>
                                <input type="date" name="from_date" class="form-control form-control-sm" value="<?php echo $from_date; ?>">
                            </div>
                            <div class="col-auto">
                                <label class="small fw-bold">Đến ngày</label>
                                <input type="date" name="to_date" class="form-control form-control-sm" value="<?php echo $to_date; ?>">
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-sm btn-info text-white px-3">Tra báo</button>
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-sm btn-success px-3"><i class="fas fa-file-excel"></i> Xuất</button>
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-sm btn-secondary px-3">Gửi</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Summary Table -->
                <div class="table-responsive mb-4">
                    <table class="table table-bordered mb-0 summary-table text-center">
                        <thead>
                            <tr>
                                <th colspan="5" class="text-center py-2" style="font-size: 16px;">Bảng Tổng Kết</th>
                            </tr>
                            <tr style="font-size: 13px;">
                                <th>Quỹ tiền mặt đầu kỳ</th>
                                <th>Tiền mặt - Tín chấp</th>
                                <th>Thu (Ngoài)</th>
                                <th>Chi (Ngoài)</th>
                                <th>Quỹ tiền mặt cuối kỳ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="font-size: 18px;">
                                <td><?php echo number_format($period_opening_balance); ?></td>
                                <td><?php echo number_format($sum_tin_chap); ?></td>
                                <td><?php echo number_format($sum_thu); ?></td>
                                <td><?php echo number_format($sum_chi); ?></td>
                                <td><?php echo number_format($closing_balance); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Section 2: Giao dịch tín chấp -->
                <div class="table-section">
                    <div class="section-header">Giao dịch tín chấp</div>
                    <table class="table table-bordered table-sm mb-0 small">
                        <thead>
                            <tr class="text-center">
                                <th width="40">#</th>
                                <th width="100">Ngày</th>
                                <th width="150">Nhân viên</th>
                                <th width="150">Khách hàng</th>
                                <th width="200">Loại giao dịch</th>
                                <th width="120">Cho vay</th>
                                <th width="120">Tiền gốc</th>
                                <th width="120">Tiền lãi phí</th>
                                <th width="120">Tiền khác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (isset($credit_error)): ?>
                                <tr><td colspan="9" class="text-center text-danger">Lỗi truy vấn: <?php echo $credit_error; ?></td></tr>
                            <?php elseif (empty($credit_trans)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-3">
                                        <i>Không có giao dịch tín chấp trong kỳ này</i>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($credit_trans as $idx => $t): 
                                    $type_display = '';
                                    $lend_amount = 0;
                                    $principal_amount = 0;
                                    $interest_amount = 0;
                                    $other_amount = 0;
                                    
                                    // Check if this is a reversal transaction
                                    $is_reversal = (strpos($t['note'], 'Hủy đóng lãi') !== false);

                                    if ($t['type'] == 'disburse') {
                                        $type_display = 'Rút vốn (Giải ngân)';
                                        $lend_amount = $t['amount'];
                                    } elseif ($t['type'] == 'lend_more') {
                                        $type_display = 'Vay thêm';
                                        $lend_amount = $t['amount'];
                                    } elseif ($t['type'] == 'pay_principal') {
                                        $type_display = 'Trả gốc';
                                        $principal_amount = $t['amount'];
                                    } elseif ($t['type'] == 'pay_all') {
                                        $type_display = 'Đóng HĐ (Trả hết)';
                                        $principal_amount = $t['amount'];
                                    } elseif ($t['type'] == 'collect_interest') {
                                        if ($is_reversal) {
                                            $type_display = 'Hủy đóng lãi';
                                        } else {
                                            $type_display = 'Đóng lãi';
                                        }
                                        $interest_amount = $t['amount'];
                                    }
                                ?>
                                    <tr class="<?php echo $is_reversal ? 'text-danger' : ''; ?>">
                                        <td class="text-center"><?php echo $idx + 1; ?></td>
                                        <td class="text-center"><?php echo date('d-m-Y', strtotime($t['date'])); ?></td>
                                        <td><?php echo htmlspecialchars($t['user_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <a href="contract_view.php?id=<?php echo $t['loan_id']; ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($t['customer_name']); ?>
                                            </a>
                                        </td>
                                        <td><?php echo $type_display; ?></td>
                                        <td class="text-end"><?php echo $lend_amount > 0 ? number_format($lend_amount) : ''; ?></td>
                                        <td class="text-end text-success fw-bold"><?php echo $principal_amount > 0 ? number_format($principal_amount) : ''; ?></td>
                                        <td class="text-end text-primary fw-bold"><?php echo $interest_amount > 0 ? number_format($interest_amount) : ''; ?></td>
                                        <td class="text-end"><?php echo $other_amount > 0 ? number_format($other_amount) : ''; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="total-row">
                                    <td colspan="5" class="text-end">Tổng</td>
                                    <td class="text-end"><?php echo number_format($credit_total_lend); ?></td>
                                    <td class="text-end"><?php echo number_format($credit_total_principal); ?></td>
                                    <td class="text-end"><?php echo number_format($credit_total_interest); ?></td>
                                    <td class="text-end"><?php echo number_format($credit_total_other); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Section 4: Thu Chi -->
                <div class="table-section">
                    <div class="section-header">Thu Chi</div>
                    <table class="table table-bordered table-sm mb-0 small">
                        <thead>
                            <tr class="text-center">
                                <th width="40">#</th>
                                <th width="100">Ngày</th>
                                <th width="150">Nhân viên</th>
                                <th width="200">Loại giao dịch</th>
                                <th width="120">Số tiền</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (isset($other_error)): ?>
                                <tr><td colspan="5" class="text-center text-danger">Lỗi truy vấn: <?php echo $other_error; ?></td></tr>
                            <?php elseif (empty($other_trans)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3">
                                        <i>Không có giao dịch thu chi</i>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($other_trans as $idx => $t): ?>
                                    <tr>
                                        <td class="text-center"><?php echo $idx + 1; ?></td>
                                        <td class="text-center"><?php echo date('d-m-Y', strtotime($t['date'])); ?></td>
                                        <td><?php echo htmlspecialchars($t['user_name'] ?: 'N/A'); ?></td>
                                        <td>
                                            <?php echo $t['type'] == 'income' ? '<span class="badge bg-success">Thu</span>' : '<span class="badge bg-danger">Chi</span>'; ?>
                                            <?php echo htmlspecialchars($t['note']); ?>
                                        </td>
                                        <td class="text-end fw-bold <?php echo $t['type'] == 'income' ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo number_format($t['amount']); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="total-row">
                                    <td colspan="4" class="text-end">Tổng</td>
                                    <td class="text-end"><?php echo number_format($other_total_income + $other_total_expense); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>