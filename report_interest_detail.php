<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

// Filter
$from_date = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
$to_date = $_GET['to_date'] ?? date('Y-m-d');

// Query Interest Transactions
// Filter by type = 'collect_interest'
// Join with loans and customers to get details
// Exclude reversal transactions (negative amounts)
$sql = "SELECT 
    t.id,
    t.date,
    t.amount,
    t.note,
    l.loan_code,
    l.amount as original_loan_amount,
    c.name as customer_name,
    l.id as loan_id
FROM transactions t
JOIN loans l ON t.loan_id = l.id
JOIN customers c ON l.customer_id = c.id
WHERE t.store_id = ? 
    AND t.type = 'collect_interest'
    AND t.date BETWEEN ? AND ?
    AND (t.note NOT LIKE '%Import%' OR t.note IS NULL)
    AND t.amount > 0
ORDER BY t.date DESC, t.id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute([$current_store_id, $from_date, $to_date]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_interest = 0;
// Note: "Tiền khác" usually comes from "cost_other" in some systems, but here we might just have interest.
// If your system separates fees vs interest in the same transaction, we need to know logic.
// For now, assume 'amount' is Total Interest. "Tien khac" = 0 unless specified.
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Báo cáo thu tiền lãi phí</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <?php include 'header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content" style="padding-top: 80px !important;">
                <div
                    class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <div>
                        <small class="text-muted">Trang chủ / Báo cáo / Chi tiết tiền lãi</small>
                        <h1 class="h4 mt-1">Báo cáo thu tiền lãi phí</h1>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary"><i class="fas fa-file-excel"></i> Xuất
                        Excel</button>
                </div>

                <!-- Filters -->
                <div class="card shadow-sm mb-4 border-0">
                    <div class="card-body bg-light">
                        <form method="GET" class="row g-2 align-items-end">
                            <div class="col-auto">
                                <label class="small fw-bold">Loại hình</label>
                                <select class="form-select form-select-sm" disabled>
                                    <option>Tất cả</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <label class="small fw-bold">Từ Ngày</label>
                                <input type="date" name="from_date" class="form-control form-control-sm"
                                    value="<?php echo $from_date; ?>">
                            </div>
                            <div class="col-auto">
                                <label class="small fw-bold">Đến Ngày</label>
                                <input type="date" name="to_date" class="form-control form-control-sm"
                                    value="<?php echo $to_date; ?>">
                            </div>
                            <div class="col-auto">
                                <label class="small fw-bold">Nhân viên</label>
                                <select class="form-select form-select-sm" disabled>
                                    <option>Tất cả</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-sm btn-primary px-3 fw-bold">Tìm kiếm</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Table -->
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-primary text-white py-2">
                        <h6 class="mb-0 small"><i class="fas fa-list"></i> Báo cáo thu tiền lãi phí</h6>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover mb-0 small">
                            <thead class="bg-light text-center">
                                <tr>
                                    <th>#</th>
                                    <th>Loại Hình</th>
                                    <th>Mã HĐ</th>
                                    <th>Khách hàng</th>
                                    <th>Tên hàng</th>
                                    <th>Tiền vay</th>
                                    <th>Ngày GD</th>
                                    <th>Tiền lãi phí</th>
                                    <th>Tiền khác</th>
                                    <th>Tổng lãi phí</th>
                                    <th>Loại GD</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($transactions)): ?>
                                    <tr>
                                        <td colspan="11" class="text-center py-4 text-muted">Không có dữ liệu</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($transactions as $idx => $t):
                                        $total_interest += $t['amount'];
                                        ?>
                                        <tr>
                                            <td class="text-center"><?php echo $idx + 1; ?></td>
                                            <td class="text-center">Tín chấp</td>
                                            <td class="text-center text-danger fw-bold">
                                                <a href="contract_view.php?id=<?php echo $t['loan_id']; ?>"
                                                    class="text-danger text-decoration-none">
                                                    <?php echo $t['loan_code']; ?>
                                                </a>
                                            </td>
                                            <td>
                                                <a href="contract_view.php?id=<?php echo $t['loan_id']; ?>"
                                                    class="text-primary text-decoration-none">
                                                    <?php echo htmlspecialchars($t['customer_name']); ?>
                                                </a>
                                            </td>
                                            <td></td> <!-- Tên hàng empty for Tin chap -->
                                            <td class="text-end"><?php echo number_format($t['original_loan_amount']); ?></td>
                                            <td class="text-center"><?php echo date('H:i d/m/Y', strtotime($t['date'])); ?></td>

                                            <!-- Tien lai phi -->
                                            <td class="text-end text-primary fw-bold">
                                                +<?php echo number_format($t['amount']); ?></td>

                                            <!-- Tien khac (assuming 0 for now) -->
                                            <td class="text-end">0</td>

                                            <!-- Tong lai phi -->
                                            <td class="text-end text-primary fw-bold">
                                                +<?php echo number_format($t['amount']); ?></td>

                                            <td class="text-center">Đóng lãi</td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <!-- Total Footer -->
                                    <tr class="fw-bold bg-light">
                                        <td colspan="7" class="text-end text-danger">Tổng</td>
                                        <td class="text-end text-primary">+<?php echo number_format($total_interest); ?>
                                        </td>
                                        <td class="text-end">0</td>
                                        <td class="text-end text-primary">+<?php echo number_format($total_interest); ?>
                                        </td>
                                        <td></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>