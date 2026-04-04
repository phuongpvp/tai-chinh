<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

// Fetch Active Loans
// Note: We need to filter for "Active" and check conditions.
// ideally, we fetch all active and process in PHP for complex date logic.
$sql = "SELECT l.*, c.name as customer_name, c.phone, c.address,
        (SELECT SUM(amount) FROM transactions t WHERE t.loan_id = l.id AND t.type = 'collect_interest') as total_interest_paid
        FROM loans l 
        JOIN customers c ON l.customer_id = c.id 
        WHERE l.status = 'active' AND l.store_id = ?
        ORDER BY l.id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute([$current_store_id]);
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

$alerts = [];
$total_money_alert = 0; // Just for footer stats if needed

foreach ($loans as $loan) {
    // 1. Calculate Interest Debt & Days Late
    // Similar logic to contracts.php
    // 1. Calculate Interest Debt
    // LOGIC UPDATE: Use 'paid_until_date' if available
    $calc_start = !empty($loan['paid_until_date']) ? strtotime($loan['paid_until_date']) : strtotime($loan['start_date']);
    $now_ts = time();
    $days_running = floor(($now_ts - $calc_start) / 86400); // Use floor to match Full Days passed
    if ($days_running < 0) $days_running = 0;

    // Accrued Interest
    $total_expected_interest = 0;
    if ($loan['interest_type'] == 'ngay' || $loan['interest_rate'] > 100) {
        $rate_real = $loan['interest_rate'];
        $mult = ($rate_real < 500) ? 1000 : 1;
        $daily_amount = ($loan['amount'] / 1000000) * ($rate_real * $mult);
        $total_expected_interest = $daily_amount * $days_running;
    } else {
         $daily_amount = ($loan['amount'] * ($loan['interest_rate'] / 100)) / 30;
         $total_expected_interest = $daily_amount * $days_running;
    }

    // If using paid_until_date, Accrued = Debt. Do not subtract total_paid_interest.
    // If using start_date (New loan), Accrued - Paid = Debt.
    if (!empty($loan['paid_until_date'])) {
        $interest_debt = $total_expected_interest;
    } else {
        $interest_debt = $total_expected_interest - $loan['total_interest_paid'];
    }
    
    // Rounding
    // $interest_debt = round($interest_debt / 1000) * 1000; // Removed rounding
    
    // Check if Alert is needed
    // Condition 1: Owe Interest > Threshold (e.g. > 0 or 10k)
    // AND Condition 2: Is Late?
    // "Late" means: They haven't paid up to Today.
    // Actually, "Nợ lãi phí" IS the alert condition in the screenshot.
    // The screenshot shows "Chậm 4 lần đóng lãi" or "Chậm lại 4 ngày".
    
    if ($interest_debt > 10000) {
        $alert_item = $loan;
        $alert_item['interest_debt'] = $interest_debt;
        
        // Determine "Reason" / "Late Status"
        // Use next_payment_date (ngày phải đóng tiếp theo) to calculate late days
        $next_pay_ts = strtotime($loan['next_payment_date']);
        
        // Days Late = Today - Next Payment Date (only if positive)
        $late_days = floor(($now_ts - $next_pay_ts) / 86400);
        if ($late_days < 0) $late_days = 0; // Not late yet
        
        // Cycles Late
        $period = $loan['period_days'] > 0 ? $loan['period_days'] : 30;
        $late_cycles = floor($late_days / $period);
        
        $reason = "";
        if ($late_cycles >= 1) {
            $reason = "Chậm $late_cycles lần đóng lãi !";
        } else {
             $reason = "Chậm lãi $late_days ngày !";
        }
        
        $alert_item['late_reason'] = $reason;
        
        // Old Debt
        $alert_item['old_debt'] = $loan['old_debt'] ?? 0;
        
        // Total = Old Debt + Interest Debt ONLY (User request: Don't include Principal in alerts)
        $alert_item['total_due'] = $alert_item['old_debt'] + $interest_debt;
        
        $alerts[] = $alert_item;
    }
}

?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cảnh báo tín chấp</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="/style.css">
</head>

<body>

    <?php include 'header.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                
                 <!-- Breadcrumb & Title -->
                 <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h5"><i class="fas fa-calendar-check me-1"></i> Cảnh Báo Tin chấp</h1>
                </div>

                <!-- Filter Bar (simplified) -->
                 <div class="card p-3 mb-3 bg-white border shadow-sm">
                    <form class="row g-2">
                        <div class="col-md-4">
                            <label class="fw-bold small">Tên khách hàng</label>
                            <input type="text" class="form-control form-control-sm" placeholder="Tên khách hàng...">
                        </div>
                        <div class="col-md-3">
                             <label class="fw-bold small">Trạng thái hợp đồng</label>
                             <select class="form-select form-select-sm">
                                 <option>Tất cả</option>
                             </select>
                        </div>
                         <div class="col-md-auto align-self-end">
                            <button class="btn btn-info btn-sm text-white fw-bold"><i class="fas fa-search"></i> Tìm kiếm</button>
                             <button class="btn btn-light btn-sm border"><i class="fas fa-print"></i></button>
                             <a href="/contract_alerts_export.php" class="btn btn-light btn-sm border"><i class="fas fa-file-excel text-success"></i> Xuất Excel</a>
                        </div>
                    </form>
                 </div>

                <!-- Table -->
                <div class="card shadow-sm border-0">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0" style="font-size: 13px;">
                                <thead class="bg-light">
                                    <tr>

                                        <th>Thông tin khách hàng</th>
                                        <th>Địa chỉ</th>
                                        <th class="text-end text-danger">Nợ cũ</th>
                                        <th class="text-end text-danger">Tiền lãi phí</th>
                                        <th class="text-end text-danger">Tiền gốc</th>
                                        <th class="text-end fw-bold">Tổng tiền</th>
                                        <th>Lý do</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($alerts) > 0): ?>
                                        <?php foreach ($alerts as $idx => $row): ?>
                                        <tr>

                                            <td>
                                                <a href="contract_view.php?id=<?php echo $row['id']; ?>"
                                                    class="fw-bold text-decoration-none text-primary"><?php echo $row['customer_name']; ?></a>
                                                <div class="small text-muted"><?php echo $row['phone']; ?></div>
                                            </td>
                                            <td><?php echo $row['address']; ?></td>
                                            <td class="text-end text-danger fw-bold"><?php echo number_format($row['old_debt']); ?></td>
                                            <td class="text-end text-danger fw-bold"><?php echo number_format($row['interest_debt']); ?></td>
                                            <td class="text-end text-primary fw-bold"><?php echo number_format($row['amount']); ?></td>
                                            <td class="text-end fw-bold"><?php echo number_format($row['total_due']); ?></td>
                                            <td class="text-muted"><?php echo $row['late_reason']; ?></td>
                                            <td class="text-center">
                                                <div class="d-flex justify-content-center gap-1">
                                                    <a href="contract_view.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary px-1 py-0"><i class="fas fa-comments"></i></a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <!-- Footer Total -->
                                        <tr class="bg-warning bg-opacity-25 fw-bold">
                                            <td colspan="2" class="text-end">Tổng Tiền</td>
                                            <td class="text-end text-danger"><?php echo number_format(array_sum(array_column($alerts, 'old_debt'))); ?></td>
                                            <td class="text-end text-danger">
                                                <?php 
                                                    echo number_format(array_sum(array_column($alerts, 'interest_debt'))); 
                                                ?>
                                            </td>
                                             <td class="text-end text-danger"><?php echo number_format(array_sum(array_column($alerts, 'amount'))); ?></td>
                                             <td class="text-end text-primary">
                                                <?php 
                                                    echo number_format(array_sum(array_column($alerts, 'total_due'))); 
                                                ?>
                                             </td>
                                             <td colspan="2"></td>
                                        </tr>
                                    <?php else: ?>
                                        <tr><td colspan="8" class="text-center py-4">Không có cảnh báo nào!</td></tr>
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
