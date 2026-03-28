<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

// Get users list for filter
$stmt_users = $conn->prepare("SELECT id, username FROM users ORDER BY username");
$stmt_users->execute();
$users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

// Filter params
$from_date = $_GET['from_date'] ?? date('Y-m-d');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$filter_user = $_GET['user_id'] ?? '';
$filter_type = $_GET['type'] ?? '';

// Helper function
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
$import_filter = "AND (t.note NOT LIKE '%Import%' OR t.note IS NULL)";
$import_filter_simple = "AND (note NOT LIKE '%Import%' OR note IS NULL)";
$amount_filter = "AND t.amount > 0 AND t.type != 'adjust_debt'";
$amount_filter_simple = "AND amount > 0 AND type != 'adjust_debt'";

// 1. Store Initial Balance
$store_initial_balance = 0;
try {
    $stmt_capital = $conn->prepare("SELECT initial_balance FROM stores WHERE id = ?");
    $stmt_capital->execute([$current_store_id]);
    $store_capital = $stmt_capital->fetch();
    $store_initial_balance = $store_capital['initial_balance'] ?? 0;
} catch (Exception $e) {
    $store_initial_balance = 0;
}

// 2. Opening Balance (before from_date)
$prev_date = date('Y-m-d', strtotime($from_date . ' -1 day'));

$sql = "SELECT SUM(amount) FROM transactions WHERE store_id = ? AND date <= ? AND type IN ('collect_interest', 'pay_principal', 'pay_all') $import_filter_simple $amount_filter_simple";
$pre_loans_in = getSum($conn, $sql, [$current_store_id, $prev_date]);

$sql = "SELECT SUM(amount) FROM transactions WHERE store_id = ? AND date <= ? AND type IN ('disburse', 'lend_more') $import_filter_simple $amount_filter_simple";
$pre_loans_out = getSum($conn, $sql, [$current_store_id, $prev_date]);

// Other transactions before period
$sql = "SELECT SUM(amount) FROM other_transactions WHERE store_id = ? AND DATE(created_at) <= ? AND type = 'income'";
$pre_other_in = getSum($conn, $sql, [$current_store_id, $prev_date]);
$sql = "SELECT SUM(amount) FROM other_transactions WHERE store_id = ? AND DATE(created_at) <= ? AND type = 'expense'";
$pre_other_out = getSum($conn, $sql, [$current_store_id, $prev_date]);

$opening_balance = $store_initial_balance + ($pre_loans_in - $pre_loans_out) + ($pre_other_in - $pre_other_out);

// 3. Period Transactions (Tín chấp)
$sql = "SELECT SUM(amount) FROM transactions WHERE store_id = ? AND date BETWEEN ? AND ? AND type IN ('collect_interest', 'pay_principal', 'pay_all') $import_filter_simple $amount_filter_simple";
$pe_tc_thu = getSum($conn, $sql, [$current_store_id, $from_date, $to_date]);

$sql = "SELECT SUM(amount) FROM transactions WHERE store_id = ? AND date BETWEEN ? AND ? AND type IN ('disburse', 'lend_more') $import_filter_simple $amount_filter_simple";
$pe_tc_chi = getSum($conn, $sql, [$current_store_id, $from_date, $to_date]);

// 4. Period Other Transactions (Thu Chi Hoạt Động)
$sql = "SELECT SUM(amount) FROM other_transactions WHERE store_id = ? AND DATE(created_at) BETWEEN ? AND ? AND type = 'income'";
$pe_hd_thu = getSum($conn, $sql, [$current_store_id, $from_date, $to_date]);
$sql = "SELECT SUM(amount) FROM other_transactions WHERE store_id = ? AND DATE(created_at) BETWEEN ? AND ? AND type = 'expense'";
$pe_hd_chi = getSum($conn, $sql, [$current_store_id, $from_date, $to_date]);

// 5. Closing Balance
$closing_balance = $opening_balance + ($pe_tc_thu - $pe_tc_chi) + ($pe_hd_thu - $pe_hd_chi);

// ==================== DETAIL TRANSACTIONS ====================
$detail_params = [$current_store_id, $from_date, $to_date];
$user_filter_sql = "";
if (!empty($filter_user)) {
    $user_filter_sql = " AND t.user_id = ?";
    $detail_params[] = $filter_user;
}

// Tín chấp transactions
$sql_detail = "SELECT t.*, l.loan_code, c.name as customer_name, u.username as user_name
    FROM transactions t
    LEFT JOIN loans l ON t.loan_id = l.id
    LEFT JOIN customers c ON l.customer_id = c.id
    LEFT JOIN users u ON t.user_id = u.id
    WHERE t.store_id = ? AND t.date BETWEEN ? AND ?
    $import_filter $amount_filter
    $user_filter_sql
    ORDER BY t.date ASC, t.id ASC";

$stmt_detail = $conn->prepare($sql_detail);
$stmt_detail->execute($detail_params);
$detail_transactions = $stmt_detail->fetchAll(PDO::FETCH_ASSOC);

// Type labels
function getTypeLabel($type) {
    $labels = [
        'collect_interest' => 'Đóng lãi',
        'pay_principal' => 'Trả gốc',
        'pay_all' => 'Tất toán',
        'disburse' => 'Giải ngân',
        'lend_more' => 'Vay thêm',
    ];
    return $labels[$type] ?? $type;
}

function isIncome($type) {
    return in_array($type, ['collect_interest', 'pay_principal', 'pay_all']);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tổng kết giao dịch</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content" style="padding-top: 80px !important;">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h4"><i class="fas fa-chart-bar"></i> Tổng kết giao dịch</h1>
                </div>

                <!-- Filter Bar -->
                <div class="card p-3 mb-3 bg-white border shadow-sm">
                    <form class="row g-2" method="GET">
                        <div class="col-md-2">
                            <label class="fw-bold small">Loại hình</label>
                            <select name="type" class="form-select form-select-sm">
                                <option value="">Tất cả</option>
                                <option value="collect_interest" <?php echo $filter_type == 'collect_interest' ? 'selected' : ''; ?>>Đóng lãi</option>
                                <option value="disburse" <?php echo $filter_type == 'disburse' ? 'selected' : ''; ?>>Giải ngân</option>
                                <option value="pay_principal" <?php echo $filter_type == 'pay_principal' ? 'selected' : ''; ?>>Trả gốc</option>
                                <option value="pay_all" <?php echo $filter_type == 'pay_all' ? 'selected' : ''; ?>>Tất toán</option>
                                <option value="lend_more" <?php echo $filter_type == 'lend_more' ? 'selected' : ''; ?>>Vay thêm</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="fw-bold small">Nhân viên</label>
                            <select name="user_id" class="form-select form-select-sm">
                                <option value="">Tất cả nhân viên</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?php echo $u['id']; ?>" <?php echo $filter_user == $u['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['username']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="fw-bold small">Từ ngày</label>
                            <input type="date" name="from_date" class="form-control form-control-sm" value="<?php echo $from_date; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="fw-bold small">Đến ngày</label>
                            <input type="date" name="to_date" class="form-control form-control-sm" value="<?php echo $to_date; ?>">
                        </div>
                        <div class="col-md-auto align-self-end">
                            <button type="submit" class="btn btn-info btn-sm text-white fw-bold"><i class="fas fa-search"></i> Tìm kiếm</button>
                            <button type="button" class="btn btn-light btn-sm border" onclick="window.print()"><i class="fas fa-print"></i></button>
                            <a href="report_transactions_export.php?from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&user_id=<?php echo $filter_user; ?>" class="btn btn-light btn-sm border"><i class="fas fa-file-excel text-success"></i></a>
                        </div>
                    </form>
                </div>

                <!-- Summary Table -->
                <div class="card shadow-sm border-0 mb-3">
                    <div class="card-body p-0">
                        <table class="table table-bordered mb-0" style="font-size: 13px;">
                            <thead>
                                <tr class="bg-primary text-white">
                                    <th class="ps-3" style="width: 50%">Bảng Tổng Kết</th>
                                    <th class="text-end" style="width: 25%">Thu</th>
                                    <th class="text-end" style="width: 25%">Chi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="ps-3 fw-bold text-success">Tiền đầu ngày</td>
                                    <td class="text-end fw-bold text-success">+<?php echo number_format($opening_balance); ?></td>
                                    <td class="text-end"></td>
                                </tr>
                                <tr>
                                    <td class="ps-3 text-primary fw-bold">Tín chấp</td>
                                    <td class="text-end"><?php echo number_format($pe_tc_thu); ?></td>
                                    <td class="text-end"><?php echo number_format($pe_tc_chi); ?></td>
                                </tr>
                                <tr>
                                    <td class="ps-3 text-primary fw-bold">Thu Chi Hoạt Động</td>
                                    <td class="text-end"><?php echo number_format($pe_hd_thu); ?></td>
                                    <td class="text-end"><?php echo number_format($pe_hd_chi); ?></td>
                                </tr>
                                <tr class="table-light">
                                    <td class="ps-3 fw-bold text-success">Tiền mặt còn lại</td>
                                    <td class="text-end fw-bold text-success">+<?php echo number_format($closing_balance); ?></td>
                                    <td class="text-end"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Detail Transactions -->
                <div class="card shadow-sm border-0">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0" style="font-size: 13px;">
                                <thead>
                                    <tr class="bg-primary text-white">
                                        <th class="text-center" style="width:35px">#</th>
                                        <th>Loại Hình</th>
                                        <th>Mã HĐ</th>
                                        <th>Người Giao Dịch</th>
                                        <th>Khách Hàng</th>
                                        <th>Ngày</th>
                                        <th>Diễn Giải</th>
                                        <th class="text-end">Thu</th>
                                        <th class="text-end">Chi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($detail_transactions) > 0): ?>
                                        <?php 
                                        $total_thu = 0;
                                        $total_chi = 0;
                                        foreach ($detail_transactions as $idx => $t): 
                                            $is_income = isIncome($t['type']);
                                            if ($is_income) {
                                                $total_thu += $t['amount'];
                                            } else {
                                                $total_chi += $t['amount'];
                                            }
                                        ?>
                                        <tr>
                                            <td class="text-center"><?php echo $idx + 1; ?></td>
                                            <td>
                                                <?php if ($is_income): ?>
                                                    <span class="badge bg-success"><?php echo getTypeLabel($t['type']); ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger"><?php echo getTypeLabel($t['type']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-bold text-secondary"><?php echo $t['loan_code'] ?? ''; ?></td>
                                            <td><?php echo htmlspecialchars($t['user_name'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php if ($t['customer_name']): ?>
                                                    <a href="contract_view.php?id=<?php echo $t['loan_id']; ?>" class="text-decoration-none fw-bold"><?php echo htmlspecialchars($t['customer_name']); ?></a>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('d/m/Y', strtotime($t['date'])); ?></td>
                                            <td class="text-muted"><?php echo htmlspecialchars($t['note'] ?? ''); ?></td>
                                            <td class="text-end fw-bold text-success">
                                                <?php if ($is_income) echo number_format($t['amount']); ?>
                                            </td>
                                            <td class="text-end fw-bold text-danger">
                                                <?php if (!$is_income) echo number_format($t['amount']); ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <tr class="bg-warning bg-opacity-25 fw-bold">
                                            <td colspan="7" class="text-end">Tổng</td>
                                            <td class="text-end text-success"><?php echo number_format($total_thu); ?></td>
                                            <td class="text-end text-danger"><?php echo number_format($total_chi); ?></td>
                                        </tr>
                                    <?php else: ?>
                                        <tr><td colspan="9" class="text-center py-4 text-muted">Không có giao dịch trong khoảng thời gian này</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
