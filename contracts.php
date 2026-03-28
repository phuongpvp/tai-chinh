<?php
// Disable caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';
require_once 'permissions_helper.php';

// Require permission to view contracts list
requirePermission($conn, 'contracts.list', 'index.php');

// --- Handle Form Actions ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'update_old_debt') {
        $loan_id = $_POST['loan_id'];
        // Allow negative numbers. Remove separators but keep minus sign.
        // Input: "-10,000" -> "-10000"
        $old_debt = str_replace([',', '.'], '', $_POST['old_debt']); 
        
        // Ensure store context
        $stmt = $conn->prepare("UPDATE loans SET old_debt = ? WHERE id = ? AND store_id = ?");
        $stmt->execute([$old_debt, $loan_id, $current_store_id]);

        // Log to transactions for history
        try {
            $formatted_debt = number_format(intval($old_debt));
            $note = "Cập nhật nợ cũ: " . $formatted_debt . " VNĐ";
            $stmt_log = $conn->prepare("INSERT INTO transactions (loan_id, type, amount, date, note, store_id, user_id) VALUES (?, 'adjust_debt', 0, NOW(), ?, ?, ?)");
            $stmt_log->execute([$loan_id, $note, $current_store_id, $_SESSION['user_id']]);
        } catch (PDOException $e) { /* ignore if fails */ }

        // Clear payment_history so overpayment/underpayment doesn't override the manually set value
        try {
            $stmt_clear = $conn->prepare("DELETE FROM payment_history WHERE loan_id = ? AND store_id = ?");
            $stmt_clear->execute([$loan_id, $current_store_id]);
        } catch (PDOException $e) { /* ignore if table doesn't exist */ }

        $return_status = $_POST['return_status'] ?? '';
        $redirect_url = "contracts.php" . ($return_status ? "?status=" . urlencode($return_status) : "");
        header("Location: " . $redirect_url);
        exit();
    }
}

// --- 1. Filter Logic ---
$whereClauses = ["l.store_id = ?"];
$params = [$current_store_id];

if (isset($_GET['type']) && $_GET['type'] != '') {
    $whereClauses[] = "l.loan_type = ?";
    $params[] = $_GET['type'];
}

if (isset($_GET['code']) && $_GET['code'] != '') {
    $whereClauses[] = "l.loan_code LIKE ?";
    $params[] = '%' . $_GET['code'] . '%';
}
if (isset($_GET['name']) && $_GET['name'] != '') {
    $whereClauses[] = "c.name LIKE ?";
    $params[] = '%' . $_GET['name'] . '%';
}
// Status Filter Logic Change:
// Some statuses depend on calculations (late_interest, overdue).
// So we ONLY filter by DB status if it's a direct match ('closed', 'active', 'bad_debt').
// If it's a computed status, we fetch 'active' and filter in PHP.
$filterStatus = $_GET['status'] ?? '';
if ($filterStatus == 'closed') {
    $whereClauses[] = "l.status = ?";
    $params[] = 'closed';
} elseif ($filterStatus == 'bad_debt') {
    $whereClauses[] = "l.status = ?";
    $params[] = 'bad_debt';
} elseif ($filterStatus == 'late_interest' || $filterStatus == 'overdue') {
    // These are computed statuses, normally applied to active loans (or all non-final ones).
    // Let's assume we search within non-closed loans.
    $whereClauses[] = "l.status != 'closed'";
} elseif ($filterStatus == '') {
    // Default: Show ONLY "active" contracts. 
    // "Bad Debt" and "Closed" are hidden unless explicitly filtered.
    $whereClauses[] = "l.status = 'active'";
} else {
    // If exact match (old links?)
    $whereClauses[] = "l.status = ?";
    $params[] = $filterStatus;
}


$whereSql = "";
if (count($whereClauses) > 0) {
    $whereSql = "WHERE " . implode(" AND ", $whereClauses);
}

// Handle Delete Request MOVED to contract_delete.php
// Display Alerts from Redirects - Handled in Main Content below

// --- Lazy Migration: Add gender and date_of_birth to customers ---
try {
    $conn->exec("ALTER TABLE customers ADD COLUMN gender VARCHAR(10) DEFAULT NULL AFTER address");
} catch (PDOException $e) { /* column already exists */ }
try {
    $conn->exec("ALTER TABLE customers ADD COLUMN date_of_birth DATE DEFAULT NULL AFTER gender");
} catch (PDOException $e) { /* column already exists */ }

// --- 2. Fetch Loans Data (Detailed) ---
$sql = "SELECT l.*, c.name as customer_name, c.phone, c.identity_card, c.gender, c.date_of_birth,
        (SELECT SUM(amount) FROM transactions t WHERE t.loan_id = l.id AND t.type = 'collect_interest') as total_interest_paid,
        (SELECT SUM(amount) FROM transactions t WHERE t.loan_id = l.id AND t.type = 'collect_interest' AND DATE(t.date) = CURDATE() AND (t.note NOT LIKE '%Import%' OR t.note IS NULL)) as interest_today
        FROM loans l 
        LEFT JOIN customers c ON l.customer_id = c.id 
        $whereSql
        ORDER BY l.id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 3. Calculate Stats (Aggregated) ---
// Note: "Quỹ tiền mặt" calculated from: Initial Balance + Cash In - Cash Out

// Fetch Initial Balance
$initial_balance = 0;
try {
    $stmt_capital = $conn->prepare("SELECT initial_balance FROM stores WHERE id = ?");
    $stmt_capital->execute([$current_store_id]);
    $store_capital = $stmt_capital->fetch();
    $initial_balance = $store_capital['initial_balance'] ?? 0;
} catch (Exception $e) {
    $initial_balance = 0;
}

$sql_cash_in = "SELECT 
                (SELECT SUM(amount) FROM transactions WHERE store_id = ? AND type IN ('collect_interest', 'pay_principal', 'pay_all') AND (note NOT LIKE '%Import%' OR note IS NULL)) as in1,
                (SELECT SUM(amount) FROM other_transactions WHERE store_id = ? AND type = 'income' AND (note NOT LIKE '%Import%' OR note IS NULL)) as in2";
$stmt_in = $conn->prepare($sql_cash_in);
$stmt_in->execute([$current_store_id, $current_store_id]);
$row_in = $stmt_in->fetch();
$total_in = ($row_in['in1'] ?? 0) + ($row_in['in2'] ?? 0);

$sql_cash_out = "SELECT 
                (SELECT SUM(amount) FROM transactions WHERE store_id = ? AND type IN ('disburse', 'lend_more') AND (note NOT LIKE '%Import%' OR note IS NULL)) as out1,
                (SELECT SUM(amount) FROM other_transactions WHERE store_id = ? AND type = 'expense' AND (note NOT LIKE '%Import%' OR note IS NULL)) as out2";
$stmt_out = $conn->prepare($sql_cash_out);
$stmt_out->execute([$current_store_id, $current_store_id]);
$row_out = $stmt_out->fetch();
$total_out = ($row_out['out1'] ?? 0) + ($row_out['out2'] ?? 0);

$stat_cash_on_hand = $initial_balance + $total_in - $total_out;

// --- 4. GLOBAL DASHBOARD STATS (Unfiltered) ---
// We need to fetch ALL relevant loans to calculate expected interest correctly, 
// regardless of the current view filters.

// A. Collected Interest
// Today
$sql_int_today = "SELECT SUM(amount) as total FROM transactions WHERE store_id = ? AND type = 'collect_interest' AND DATE(date) = CURDATE() AND (note NOT LIKE '%Import%' OR note IS NULL)";
$stmt_today = $conn->prepare($sql_int_today);
$stmt_today->execute([$current_store_id]);
$total_interest_today = $stmt_today->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// All Time (Interest + Other Income as requested)
$sql_int_all = "SELECT 
                (SELECT SUM(amount) FROM transactions WHERE store_id = ? AND type = 'collect_interest' AND (note NOT LIKE '%Import%' OR note IS NULL)) as t1,
                (SELECT SUM(amount) FROM other_transactions WHERE store_id = ? AND type = 'income') as t2";
$stmt_all = $conn->prepare($sql_int_all);
$stmt_all->execute([$current_store_id, $current_store_id]);
$row_all = $stmt_all->fetch();
$total_collected_all = ($row_all['t1'] ?? 0) + ($row_all['t2'] ?? 0);

// B. Expected Interest (Forecast)
// We need to iterate all active/bad_debt loans
$sql_forecast = "SELECT l.*, 
                 (SELECT SUM(amount) FROM transactions t WHERE t.loan_id = l.id AND t.type = 'collect_interest') as total_interest_paid 
                 FROM loans l 
                 WHERE l.store_id = ? AND l.status IN ('active', 'bad_debt')";
$stmt_forecast = $conn->prepare($sql_forecast);
$stmt_forecast->execute([$current_store_id]);
$all_loans = $stmt_forecast->fetchAll(PDO::FETCH_ASSOC);

// C. Customer Counts
try {
    $stmt_count = $conn->prepare("SELECT 
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
        SUM(CASE WHEN status = 'bad_debt' THEN 1 ELSE 0 END) as bad_debt_count
        FROM loans WHERE store_id = ?");
    $stmt_count->execute([$current_store_id]);
    $counts = $stmt_count->fetch(PDO::FETCH_ASSOC);
    $count_active = intval($counts['active_count'] ?? 0);
    $count_bad_debt = intval($counts['bad_debt_count'] ?? 0);
} catch (PDOException $e) {
    $count_active = 0;
    $count_bad_debt = 0;
}
$count_late_interest = 0; // Will be counted after processing loans

$expected_interest_all = 0;       // Active + Bad Debt
$expected_interest_active = 0;    // Active Only

foreach ($all_loans as $l) {
    // Calc Accrued Interest Implementation (Same as main loop)
    $start_ts = strtotime($l['start_date']);
    $now_ts = time();
    $days = ceil(abs($now_ts - $start_ts) / 86400);
    if ($days < 1) $days = 1;

    $daily = 0;
    if ($l['interest_type'] == 'ngay' || $l['interest_rate'] > 100) {
        $rate = $l['interest_rate'];
        $mult = ($rate < 500) ? 1000 : 1; 
        $daily = ($l['amount'] / 1000000) * ($rate * $mult);
    } else {
        $daily = ($l['amount'] * ($l['interest_rate'] / 100)) / 30;
    }

    // Determine Debt
    $debt = 0;
    // Priority logic matching main loop
    if (!empty($l['paid_until_date'])) {
        $paid_ts = strtotime($l['paid_until_date']);
        if ($paid_ts < $now_ts) {
            $diff_d = floor(($now_ts - $paid_ts) / 86400);
            $debt = $diff_d * $daily;
        }
    } elseif (!empty($l['next_payment_date'])) {
        $next_ts = strtotime($l['next_payment_date']);
        if ($next_ts < $now_ts) {
            $diff_d = floor(($now_ts - $next_ts) / 86400);
            $debt = $diff_d * $daily;
        }
    } else {
        // Fallback: Total accrued - paid
        $total_accrued = $daily * $days;
        $debt = $total_accrued - $l['total_interest_paid'];
    }

    if ($debt < 0) $debt = 0;

    // Accumulate
    $expected_interest_all += $debt;
    
    // Check status for Active Only
    // Note: Database status 'active' includes loans that might be 'Nợ lãi phí' (Overdue) visually,
    // but semantically they are Active. User said "Active + Bad Debt" vs "Active".
    // So 'active' DB status maps to User's "Active".
    if ($l['status'] == 'active') {
        $expected_interest_active += $debt;
    }
}

$stat_total_loan = 0;
$stat_total_debt = 0; // Combined old_debt from all loans
$stat_interest_forecast = 0; 
$stat_interest_paid = 0;

$processed_loans = [];

foreach ($loans as $loan) {
    $stat_total_loan += $loan['amount'];
    $stat_interest_paid += $loan['total_interest_paid'];

    // 1. Calculate Days Running
    $start_ts = strtotime($loan['start_date']);
    $now_ts = time();
    $days_running = ceil(abs($now_ts - $start_ts) / 86400);
    if ($days_running < 1)
        $days_running = 1;

    // 2. Calculate Total Expected Interest (Accrued)
    $total_expected_interest = 0;
    if ($loan['interest_type'] == 'ngay' || $loan['interest_rate'] > 100) {
        $rate_real = $loan['interest_rate'];
        $mult = ($rate_real < 500) ? 1000 : 1; // Handled legacy 'k' vs raw
        $daily_amount = ($loan['amount'] / 1000000) * ($rate_real * $mult);
        $total_expected_interest = $daily_amount * $days_running;
    } else {
        // Percent / Month
        $daily_amount = ($loan['amount'] * ($loan['interest_rate'] / 100)) / 30;
        $total_expected_interest = $daily_amount * $days_running;
    }

    // 3. Calculate "Interest to Date" (Debt)
    $interest_debt = 0;
    $debt_days_display = 0;
    $period_days = $loan['period_days'] > 0 ? $loan['period_days'] : 30; // Get actual period

    // Priority 1: Use 'paid_until_date' 
    if (!empty($loan['paid_until_date'])) {
        $paid_until_ts = strtotime($loan['paid_until_date']);
        $today_ts = time();

        if ($paid_until_ts < $today_ts) {
            $diff_seconds = $today_ts - $paid_until_ts;
            $diff_days_debt = floor($diff_seconds / (60 * 60 * 24));

            $debt_days_display = $diff_days_debt;

            // LOGIC MATCH: Minimum charge removed (User request: Actual days)
            // if ($diff_days_debt < $period_days) {
            //    $diff_days_debt = $period_days;
            // }

            $interest_debt = $diff_days_debt * $daily_amount;
        }
    }
    // Priority 2: Use Next Payment Date (Legacy fallback)
    elseif (!empty($loan['next_payment_date'])) {
        $next_pay_ts = strtotime($loan['next_payment_date']);
        $today_ts = time();
        if ($next_pay_ts < $today_ts) {
            $diff_seconds = $today_ts - $next_pay_ts;
            $diff_days_debt = floor($diff_seconds / (60 * 60 * 24));

            $debt_days_display = $diff_days_debt;

            // LOGIC MATCH: Minimum charge removed for Active display purposes
            // if ($diff_days_debt < $period_days) {
            //    $diff_days_debt = $period_days;
            // }

            $interest_debt = $diff_days_debt * $daily_amount;
        }
    } else {
        // Fallback
        $interest_debt = $total_expected_interest - $loan['total_interest_paid'];
        if ($daily_amount > 0) {
            $debt_days_display = round($interest_debt / $daily_amount);
        }
    }

    if ($interest_debt < 0)
        $interest_debt = 0;
    $stat_interest_forecast += $interest_debt;

    // 4. Calculate Next Payment Date
    // PRIORITY 1: Use next_payment_date field from database (from Excel Column K)
    if (!empty($loan['next_payment_date'])) {
        $next_payment_date = date('d-m-Y', strtotime($loan['next_payment_date']));
    }
    // PRIORITY 2: Calculate from paid_until_date (Dynamic, reflects actual payments)
    elseif (!empty($loan['paid_until_date'])) {
        $paid_until_ts = strtotime($loan['paid_until_date']);
        // Next Due Date = Paid Until + 1 day (Start of next period)
        $next_due_ts = $paid_until_ts + 86400;
        $next_payment_date = date('d-m-Y', $next_due_ts);
    }
    // PRIORITY 3: Fallback to start date
    else {
        $next_payment_date = date('d-m-Y', strtotime($loan['start_date']));
    }

    // Process row data for display
    $row = $loan; // Reset row
    $row['days_running'] = $days_running;
    $row['calculated_interest_todate'] = $interest_debt;
    $row['debt_days_display'] = $debt_days_display; // Re-assign after reset!
    
    // Calculate cumulative debt from payment_history
    $cumulative_debt = 0;
    try {
        $stmt_debt = $conn->prepare("SELECT (SUM(underpayment) - SUM(overpayment)) as balance FROM payment_history WHERE loan_id = ? AND store_id = ?");
        $stmt_debt->execute([$loan['id'], $current_store_id]);
        $debt_result = $stmt_debt->fetch(PDO::FETCH_ASSOC);
        $cumulative_debt = floatval($debt_result['balance'] ?? 0);
    } catch (PDOException $e) {
        // Table might not exist yet
    }
    $row['cumulative_debt'] = $cumulative_debt;
    $row['total_old_debt'] = ($loan['old_debt'] ?? 0) + $cumulative_debt; // Nợ cũ import + nợ phát sinh
    $period_interest = $daily_amount * $loan['period_days']; // Lãi phí cả kỳ
    $row['total_payment_due'] = $period_interest + $row['total_old_debt'];
    $row['next_payment_date'] = $next_payment_date;

    // 4. Rate Display
    if ($loan['interest_rate'] > 100) {
        // e.g., 2700 -> "2.7k"
        $n = $loan['interest_rate'] / 1000;

        // DEBUG: Show raw values (remove after testing)
        // echo "DEBUG [{$loan['loan_code']}]: rate={$loan['interest_rate']}, n=$n, floor=" . floor($n) . "<br>";

        // Format with 1 decimal place, then remove trailing .0 if needed
        if ($n == floor($n)) {
            // Integer like 3.0 -> "3k"
            $row['interest_rate'] = number_format($n, 0) . 'k/1tr';
        } else {
            // Decimal like 2.7 -> "2.7k"
            $row['interest_rate'] = number_format($n, 1) . 'k/1tr';
        }
    } else {
        // e.g., 5 -> "5%"
        $row['interest_rate'] = number_format($loan['interest_rate'], 1) . '%';
    }

    // 5. Status Determination (MATCH LEGACY)
    // "Nợ lãi phí" IF Today >= Next Payment Date (Overdue or Due)
    // "Đang vay" IF Today < Next Payment Date

    $row['display_status'] = 'Đang vay';
    $row['display_class'] = 'badge bg-light text-dark border'; // Default

    // Check Date Comparison for Status
    $next_pay_date_str = $row['next_payment_date']; // Should be DD-MM-YYYY
    $next_pay_date_obj = DateTime::createFromFormat('d-m-Y', $next_pay_date_str);
    $today_obj = new DateTime();
    $today_obj->setTime(0, 0, 0); // reset time

    // If Next Payment Date is Valid
    if ($next_pay_date_obj) {
        $next_pay_ts_compare = $next_pay_date_obj->getTimestamp();
        $today_ts_compare = $today_obj->getTimestamp();

        if ($today_ts_compare > $next_pay_ts_compare) {
            $row['display_status'] = 'Nợ lãi phí';
            $row['display_class'] = 'badge bg-warning text-dark';
        } elseif ($today_ts_compare == $next_pay_ts_compare) {
            // Due Today
            $row['display_status'] = 'Nợ lãi phí'; // Or "Đến hạn"
            $row['display_class'] = 'badge bg-warning text-dark';
        }
    }

    if ($loan['status'] == 'closed') {
        $row['display_status'] = 'Đã đóng';
        $row['display_class'] = 'badge bg-secondary';
    } elseif ($loan['status'] == 'bad_debt') {
        $row['display_status'] = 'Nợ xấu';
        $row['display_class'] = 'badge bg-danger';
    }

    // STRICT PHP FILTERING
    // If user filtered by 'late_interest' (Nợ lãi phí) but this row is NOT 'Nợ lãi phí', skip it.
    if ($filterStatus == 'late_interest' && $row['display_status'] != 'Nợ lãi phí') {
        continue;
    }
    // If user filtered by 'active' (Đang vay - good standing only)
    if ($filterStatus == 'active' && $row['display_status'] != 'Đang vay') {
        continue;
    }


    $processed_loans[] = $row;

    // Count late interest ("Nợ lãi phí") for stats
    if ($loan['status'] == 'active' && $row['display_status'] == 'Nợ lãi phí') {
        $count_late_interest++;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Hợp đồng tín chấp - Trương Hưng v2.1</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .stat-label {
            font-size: 12px;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .stat-value {
            font-size: 18px;
            font-weight: bold;
            color: #333;
        }

        .stat-sub {
            font-size: 11px;
            color: #d9534f;
        }

        .table-custom thead th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            color: #495057;
            font-weight: 600;
            font-size: 0.8rem;
            vertical-align: middle;
        }

        .table-custom tbody td {
            vertical-align: middle;
            padding: 0.75rem 0.5rem;
        }

        .badge-status {
            font-size: 11px;
            padding: 5px 8px;
            border-radius: 4px;
        }

        /* Specific borders for Status Column matching screenshot */
        .status-cell {
            background-color: #fff3cd;
            color: #856404;
            font-weight: bold;
            text-align: center;
        }

        .filter-bar {
            background: #fff;
            padding: 10px;
            border-radius: 4px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const checkAll = document.getElementById('checkAll');
            const rowChecks = document.querySelectorAll('.row-check');
            const btnDelete = document.getElementById('btnDeleteSelected');

            function toggleButton() {
                let checkedCount = document.querySelectorAll('.row-check:checked').length;
                if (checkedCount > 0) {
                    btnDelete.style.display = 'inline-block';
                } else {
                    btnDelete.style.display = 'none';
                }
            }

            checkAll.addEventListener('change', function() {
                rowChecks.forEach(cb => {
                    cb.checked = checkAll.checked;
                });
                toggleButton();
            });

            rowChecks.forEach(cb => {
                cb.addEventListener('change', toggleButton);
            });
        });
    </script>
</head>

<body>

    <!-- Header -->
    <?php include 'header.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include 'sidebar.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">

                <?php if (isset($_GET['msg'])): ?>
                    <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
                        <?php echo htmlspecialchars($_GET['msg']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
                        <strong>Lỗi:</strong> <?php echo htmlspecialchars($_GET['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Breadcrumb -->
                <nav aria-label="breadcrumb" class="mb-3">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="index.php"><i class="fas fa-home"></i> Trang chủ</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Tín chấp</li>
                    </ol>
                </nav>

                <!-- Stats Row -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="stat-box" style="border-left-color: #007bff;">
                            <div class="row">
                                <div class="col-6 border-end">
                                    <div class="stat-label">QUỸ TIỀN MẶT <i
                                            class="fas fa-sync-alt text-muted small"></i></div>
                                    <div class="stat-value"><?php echo number_format($stat_cash_on_hand); ?></div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-label">TIỀN CHO VAY</div>
                                    <div class="stat-value text-primary">
                                        <?php echo number_format(array_sum(array_column($all_loans, 'amount'))); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-2 pt-2 border-top">
                                <div class="col-6">
                                    <div class="stat-label">TIỀN NỢ</div>
                                    <div class="stat-value text-danger">
                                        <?php echo number_format(array_sum(array_column($all_loans, 'old_debt'))); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="stat-box" style="border-left-color: #28a745; height: 100%;">
                            <div class="stat-label">LÃI PHÍ DỰ KIẾN</div>
                            <div class="stat-value text-success"><?php echo number_format($expected_interest_all); ?></div>
                            <small class="text-muted" style="font-size: 9px;">(Tất cả)</small>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="stat-box" style="border-left-color: #20c997; height: 100%;">
                            <div class="stat-label">DỰ KIẾN (TRỪ NỢ XẤU)</div>
                            <div class="stat-value text-success" style="color: #20c997 !important;"><?php echo number_format($expected_interest_active); ?></div>
                            <small class="text-muted" style="font-size: 9px;">(Đang vay)</small>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="stat-box" style="border-left-color: #17a2b8; height: 100%;">
                            <div class="stat-label">SỐ TIỀN LÃI ĐÃ THU TRONG NGÀY</div>
                            <div class="stat-value text-info"><?php echo number_format($total_interest_today); ?></div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="stat-box" style="border-left-color: #fd7e14; height: 100%;">
                            <div class="stat-label">TỔNG SỐ TIỀN LÃI ĐÃ THU</div>
                            <div class="stat-value" style="color: #fd7e14;"><?php echo number_format($total_collected_all); ?></div>
                        </div>
                    </div>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="stat-box" style="border-left-color: #0d6efd; height: 100%;">
                            <div class="stat-label">TỔNG SỐ KHÁCH ĐANG VAY</div>
                            <div class="stat-value text-primary"><?php echo number_format($count_active); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-box" style="border-left-color: #dc3545; height: 100%;">
                            <div class="stat-label">TỔNG SỐ KHÁCH NỢ XẤU</div>
                            <div class="stat-value text-danger"><?php echo number_format($count_bad_debt); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-box" style="border-left-color: #ffc107; height: 100%;">
                            <div class="stat-label">TỔNG SỐ KHÁCH CHẬM LÃI</div>
                            <div class="stat-value" style="color: #e6a800;"><?php echo number_format($count_late_interest); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Content Body -->
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white fw-bold border-bottom">
                        <i class="far fa-calendar-alt"></i> Hợp đồng tín chấp
                    </div>
                    <div class="card-body p-0">

                        <!-- Filter Form -->
                        <div class="p-3 bg-light border-bottom">
                            <form method="GET" action="/hop-dong" class="row g-2 align-items-end">
                                <div class="col-auto">
                                    <label class="small fw-bold">Mã HĐ</label>
                                    <input type="text" name="code" class="form-control form-control-sm"
                                        style="width: 100px;" value="<?php echo $_GET['code'] ?? ''; ?>">
                                </div>
                                <div class="col-auto">
                                    <label class="small fw-bold">Tên khách hàng</label>
                                    <input type="text" name="name" class="form-control form-control-sm"
                                        style="width: 180px;" value="<?php echo $_GET['name'] ?? ''; ?>">
                                </div>
                                <div class="col-auto">
                                    <label class="small fw-bold">Từ ngày</label>
                                    <input type="date" name="from_date" class="form-control form-control-sm"
                                        value="<?php echo $_GET['from_date'] ?? ''; ?>">
                                </div>
                                <div class="col-auto">
                                    <label class="small fw-bold">Đến ngày</label>
                                    <input type="date" name="to_date" class="form-control form-control-sm"
                                        value="<?php echo $_GET['to_date'] ?? ''; ?>">
                                </div>
                                <div class="col-auto">
                                    <label class="small fw-bold">Trạng thái hợp đồng</label>
                                    <select name="status" class="form-select form-select-sm" style="width: 150px;" onchange="this.form.submit()">
                                        <option value="">Tất cả hợp đồng đang vay</option>
                                        <option value="late_interest" <?php if (($_GET['status'] ?? '') == 'late_interest')
                                            echo 'selected'; ?>>Hợp đồng chậm lãi</option>
                                        <option value="bad_debt" <?php if (($_GET['status'] ?? '') == 'bad_debt')
                                            echo 'selected'; ?>>Hợp đồng nợ xấu</option>
                                        <option value="closed" <?php if (($_GET['status'] ?? '') == 'closed')
                                            echo 'selected'; ?>>Hợp đồng đã kết thúc</option>
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <button type="submit" class="btn btn-sm btn-info text-white fw-bold"><i
                                            class="fas fa-search"></i> Tìm kiếm</button>
                                            
                                    <!-- Export Excel Button -->
                                    <a href="#" onclick="exportExcel(); return false;" class="btn btn-sm btn-success fw-bold ms-2 text-white">
                                        <i class="fas fa-file-excel"></i> Xuất Excel
                                    </a>
                                    <script>
                                        function exportExcel() {
                                            var form = document.getElementById('filterForm'); // Ensure your form has id="filterForm" or use this.form logic
                                            // Since the form above doesn't have an ID in the original code, we can append parameters manually or submit to a different action.
                                            // Better approach: Redirect to contract_export.php with current query params
                                            var params = new URLSearchParams(window.location.search);
                                            window.location.href = 'contract_export.php?' + params.toString();
                                        }
                                    </script>

                                    <a href="contract_import.php" class="btn btn-sm btn-primary fw-bold ms-2"><i
                                            class="fas fa-file-import"></i> Nhập dữ liệu</a>
                                    <a href="contract_add.php" class="btn btn-sm btn-success fw-bold ms-2"><i
                                            class="fas fa-plus"></i> Thêm mới</a>
                                    <button type="button" class="btn btn-sm btn-danger fw-bold ms-2"
                                        id="btnDeleteSelected" style="display:none;"
                                        onclick="if(confirm('Bạn có chắc chắn muốn xóa các hợp đồng đã chọn?')) document.getElementById('deleteForm').submit();">
                                        <i class="fas fa-trash"></i> Xóa chọn
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Table -->
                        <div class="table-responsive">
                            <form id="deleteForm" method="POST" action="contract_delete.php">
                                <table class="table table-custom table-bordered table-hover table-striped mb-0">

                                <thead>
                                    <tr>
                                        <th class="text-center" width="40">
                                            <input type="checkbox" id="checkAll">
                                        </th>
                                        <th width="250">Thông tin khách hàng</th>
                                        <th class="text-end">VNĐ</th>
                                        <th class="text-end">Lãi phí</th>
                                        <th class="text-center">Ngày vay</th>
                                        <?php if ($filterStatus == 'closed'): ?>
                                            <th class="text-end">Tiền lãi đã đóng</th>
                                            <th class="text-center">Ngày tất toán</th>
                                            <th class="text-center">Ngày GD</th>
                                        <?php else: ?>
                                            <th class="text-end">Lãi phí đã đóng</th>
                                            <th class="text-end">Lãi phí đến hôm nay</th>
                                            <th class="text-center">Tình trạng</th>
                                            <th class="text-center">Ngày phải đóng lãi phí</th>
                                            <th class="text-end">Tổng tiền phải đóng trong kỳ</th>
                                        <?php endif; ?>
                                        <th class="text-end">Nợ cũ</th>
                                        <th class="text-center" width="100">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($processed_loans) > 0): ?>
                                        <?php foreach ($processed_loans as $idx => $row): ?>
                                            <tr>
                                                <td class="text-center">
                                                    <input type="checkbox" name="ids[]"
                                                        value="<?php echo $row['id']; ?>" class="row-check">
                                                </td>
                                                <td>
                                                    <a href="contract_edit.php?id=<?php echo $row['id']; ?><?php echo $filterStatus ? '&status=' . urlencode($filterStatus) : ''; ?>"
                                                        class="fw-bold text-decoration-none text-primary"><?php echo $row['customer_name']; ?></a>
                                                    <div class="small text-muted">
                                                        <?php
                                                            $info_parts = [];
                                                            if (!empty($row['gender'])) $info_parts[] = $row['gender'];
                                                            if (!empty($row['date_of_birth'])) $info_parts[] = date('d/m/Y', strtotime($row['date_of_birth']));
                                                            if (!empty($row['identity_card'])) $info_parts[] = $row['identity_card'];
                                                            if (!empty($row['phone'])) $info_parts[] = $row['phone'];
                                                            echo implode('; ', $info_parts);
                                                        ?>
                                                    </div>
                                                </td>

                                                <td class="text-end">
                                                    <div class="fw-bold text-danger">
                                                        <?php echo number_format($row['amount']); ?>
                                                    </div>
                                                </td>
                                                <td class="text-end">
                                                     <div class="small text-muted" style="font-size: 12px;">
                                                        <?php echo $row['interest_rate']; ?>
                                                    </div>
                                                </td>
                                                <td class="text-center small">
                                                    <?php 
                                                        $display_start_date = !empty($row['original_start_date']) ? $row['original_start_date'] : $row['start_date'];
                                                        echo date('d-m-Y', strtotime($display_start_date)); 
                                                    ?>
                                                    <div class="small text-danger" style="font-size: 12px;">
                                                        Kỳ lãi: <?php echo $row['period_days']; ?> Ngày
                                                    </div>
                                                </td>

                                                <?php if ($filterStatus == 'closed'): ?>
                                                    <td class="text-end fw-bold text-success">
                                                        <?php echo number_format($row['total_interest_paid'] ?? 0); ?>
                                                    </td>
                                                    <td class="text-center small">
                                                        <?php echo isset($row['end_date']) ? date('d-m-Y', strtotime($row['end_date'])) : '-'; ?>
                                                    </td>
                                                    <td class="text-center small text-muted">
                                                        <?php echo isset($row['end_date']) ? date('d-m-Y H:i', strtotime($row['end_date'])) : '-'; ?>
                                                    </td>
                                                <?php else: ?>
                                                    <td class="text-end fw-bold text-success">
                                                        <?php echo number_format($row['total_interest_paid']); ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <?php echo number_format($row['calculated_interest_todate']); ?>
                                                        <div class="small text-muted" style="font-size:12px;">
                                                            (<?php echo $row['debt_days_display']; ?> ngày)
                                                        </div>
                                                    </td>
                                                    <td
                                                        class="status-cell <?php echo ($row['display_status'] == 'Nợ lãi phí') ? 'bg-warning bg-opacity-25' : ''; ?>">
                                                        <span class="<?php echo $row['display_class']; ?>">
                                                            <?php echo $row['display_status']; ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-center fw-bold small <?php 
                                                        // Highlight if due date is today
                                                        $due_date_obj = DateTime::createFromFormat('d-m-Y', $row['next_payment_date']);
                                                        $today_obj = new DateTime();
                                                        if ($due_date_obj && $due_date_obj->format('Y-m-d') == $today_obj->format('Y-m-d')) {
                                                            echo 'bg-danger bg-opacity-75 text-white';
                                                        }
                                                    ?>">
                                                        <?php echo $row['next_payment_date']; ?>
                                                        <?php 
                                                        $due_date_obj = DateTime::createFromFormat('d-m-Y', $row['next_payment_date']);
                                                        $today_obj = new DateTime();
                                                        if ($due_date_obj && $due_date_obj->format('Y-m-d') == $today_obj->format('Y-m-d')) {
                                                            echo '<div class="small">Hôm nay</div>';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td class="text-end fw-bold <?php echo ($row['total_payment_due'] > 0) ? 'text-danger' : 'text-success'; ?>">
                                                        <?php echo number_format($row['total_payment_due']); ?>
                                                    </td>
                                                <?php endif; ?>

                                                <td class="text-end <?php echo ($row['total_old_debt'] > 0) ? 'text-danger' : ($row['total_old_debt'] < 0 ? 'text-success' : 'text-muted'); ?>">
                                                    <?php echo number_format($row['total_old_debt']); ?>
                                                    <a href="#"
                                                        onclick="openOldDebtModal(<?php echo $row['id']; ?>, '<?php echo $row['loan_code']; ?>', <?php echo $row['old_debt'] ?? 0; ?>); return false;"
                                                        class="text-secondary ms-1" title="Sửa nợ cũ (nhập từ HT cũ)"><i class="fas fa-edit small"></i></a>
                                                </td>
                                                <td class="text-center">
                                                    <div class="d-flex justify-content-center" style="gap: 5px !important;">
                                                        <?php if ($row['status'] == 'closed'): ?>
                                                            <a href="contract_reopen.php?id=<?php echo $row['id']; ?>&status=<?php echo urlencode($filterStatus); ?>"
                                                               class="btn btn-sm btn-outline-warning" title="Mở lại hợp đồng"
                                                               onclick="return confirm('Bạn có chắc chắn muốn mở lại hợp đồng này?');">
                                                                <i class="fas fa-lock-open"></i> Mở
                                                            </a>
                                                        <?php elseif ($row['status'] == 'bad_debt'): ?>
                                                            <a href="#"
                                                                onclick="openContractModal('contract_view.php?id=<?php echo $row['id']; ?>&tab=interest'); return false;"
                                                                class="btn btn-sm btn-warning text-white" title="Xem chi tiết / Đóng lãi"><i
                                                                    class="fas fa-hand-holding-usd"></i></a>
                                                            <a href="contract_reactivate.php?id=<?php echo $row['id']; ?>&status=<?php echo urlencode($filterStatus); ?>"
                                                               class="btn btn-sm btn-success" title="Trở lại trạng thái ban đầu"
                                                               onclick="return confirm('Bạn có chắc chắn muốn chuyển hợp đồng này về trạng thái ĐANG VAY?');">
                                                                <i class="fas fa-undo"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="#"
                                                                onclick="openContractModal('contract_view.php?id=<?php echo $row['id']; ?>&tab=interest'); return false;"
                                                                class="btn btn-sm btn-warning text-white" title="Đóng lãi"><i
                                                                    class="fas fa-hand-holding-usd"></i></a>
                                                            
                                                            <?php if ($row['status'] != 'bad_debt'): ?>
                                                            <a href="contract_mark_bad_debt.php?id=<?php echo $row['id']; ?>&status=<?php echo urlencode($filterStatus); ?>"
                                                               class="btn btn-sm btn-danger" title="Đánh dấu nợ xấu"
                                                               onclick="return confirm('Bạn có chắc chắn muốn chuyển hợp đồng này sang NỢ XẤU?');">
                                                                <i class="fas fa-exclamation-triangle"></i>
                                                            </a>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                        <a href="cong-viec/customer.php?id=<?php echo $row['id']; ?>"
                                                           class="btn btn-sm btn-outline-info" title="Làm việc (CV)"
                                                           target="_blank"><i class="fas fa-briefcase"></i></a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <!-- Footer Total -->
                                        <tr class="bg-warning bg-opacity-25 fw-bold">
                                            <td colspan="2" class="text-end">Tổng</td>
                                            <td class="text-end text-danger"><?php echo number_format($stat_total_loan); ?>
                                            </td>
                                            <td></td>
                                            <td></td>
                                            <td class="text-end text-success">
                                                <?php echo number_format($stat_interest_paid); ?>
                                            </td>
                                            <td class="text-end"><?php echo number_format($stat_interest_forecast); ?></td>
                                            <td colspan="4"></td>
                                        </tr>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="14" class="text-center py-5 text-muted">Không có dữ liệu hợp đồng
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                    <!-- Cache Buster: <?php echo time(); ?> -->
                                </tbody>
                            </table>
                            </form>
                        </div>

                        <!-- Pagination (Mock) -->
                        <div class="d-flex justify-content-end p-2 border-top bg-light">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item disabled"><a class="page-link" href="#">Prev</a></li>
                                <li class="page-item active"><a class="page-link" href="#">1</a></li>
                                <li class="page-item"><a class="page-link" href="#">Next</a></li>
                            </ul>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <!-- Generic Modal -->
    <div class="modal fade" id="contractModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-secondary text-white py-2">
                    <h5 class="modal-title fs-6">Chi tiết hợp đồng</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <div class="modal-body p-0 bg-light">
                    <!-- Content loaded via Ajax -->
                </div>
            </div>
        </div>
    </div>

    <!-- Old Debt Modal -->
    <div class="modal fade" id="oldDebtModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <form method="POST" action="">
                <div class="modal-content">
                    <div class="modal-header bg-secondary text-white py-2">
                        <h5 class="modal-title fs-6">Sửa nợ cũ</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_old_debt">
                        <input type="hidden" name="loan_id" id="mod_loan_id">
                        <input type="hidden" name="return_status" value="<?php echo htmlspecialchars($filterStatus); ?>">
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Hợp đồng:</label>
                            <input type="text" class="form-control form-control-sm" id="mod_loan_code" readonly>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Số tiền nợ cũ:</label>
                            <input type="text" name="old_debt" id="mod_old_debt"
                                class="form-control fw-bold text-danger" required>
                        </div>
                    </div>
                    <div class="modal-footer p-1">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Hủy</button>
                        <button type="submit" class="btn btn-sm btn-primary">Lưu</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openOldDebtModal(id, code, val) {
            document.getElementById('mod_loan_id').value = id;
            document.getElementById('mod_loan_code').value = code;

            // Format value
            const formatted = new Intl.NumberFormat('vi-VN').format(val);
            document.getElementById('mod_old_debt').value = formatted;

            // Re-instantiate to avoid conflict if already exists
            const modalEl = document.getElementById('oldDebtModal');
            let myModal = bootstrap.Modal.getInstance(modalEl);
            if (!myModal) {
                myModal = new bootstrap.Modal(modalEl);
            }
            myModal.show();
        }

        // Format input
        const oldDebtInput = document.getElementById('mod_old_debt');
        if (oldDebtInput) {
            oldDebtInput.addEventListener('input', function (e) {
                // Allow digits and minus sign at start
                let val = e.target.value;
                
                // Remove invalid chars (keep digits and minus)
                val = val.replace(/[^0-9-]/g, '');
                
                // Ensure minus is only at index 0
                if (val.indexOf('-') > 0) {
                    val = val.replace(/-/g, '');
                }

                if (val !== '' && val !== '-') {
                    const isNegative = val.startsWith('-');
                    const numVal = parseInt(val.replace(/-/g, ''));
                    if (!isNaN(numVal)) {
                        e.target.value = (isNegative ? '-' : '') + new Intl.NumberFormat('vi-VN').format(numVal);
                    }
                } else {
                    e.target.value = val; // Allow typing just '-' or empty
                }
            });
        }

        function openContractModal(url) {
            // 1. Show Loading
            const modalBody = document.querySelector('#contractModal .modal-body');
            modalBody.innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary" role="status"></div><br>Đang tải dữ liệu...</div>';

            const modal = new bootstrap.Modal(document.getElementById('contractModal'), {
                keyboard: false
            });
            modal.show();

            // 2. Add view_mode=modal param
            const fetchUrl = url + (url.includes('?') ? '&' : '?') + 'view_mode=modal';

            // 3. Create Iframe
            const iframe = document.createElement('iframe');
            iframe.src = fetchUrl;
            iframe.style.width = '100%';
            iframe.style.height = '85vh';
            iframe.style.border = 'none';

            modalBody.innerHTML = '';
            modalBody.appendChild(iframe);
        }

        // Initialize change tracking
        window.needsReload = false;

        // Reload page when modal is closed IF changes happened (set by iframe)
        const modalEl = document.getElementById('contractModal');
        modalEl.addEventListener('hidden.bs.modal', function (event) {
            if (window.needsReload) {
                // Show loading indicator before reload to give feedback
                document.body.style.opacity = '0.5';
                document.body.style.cursor = 'wait';
                window.location.reload();
            }
        });

        // Toggle Sidebar if needed via JS
    </script>
</body>

</html>