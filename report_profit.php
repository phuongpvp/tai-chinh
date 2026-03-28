<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

// Filter
$year = $_GET['year'] ?? date('Y');

// 1. Revenue: Interest Collected (Exclude Import transactions)
$sql_revenue = "SELECT MONTH(date) as month, SUM(amount) as total 
                FROM transactions 
                WHERE store_id = ? AND YEAR(date) = ? AND type = 'collect_interest' 
                AND (note NOT LIKE '%Import%' OR note IS NULL)
                GROUP BY MONTH(date)";
$stmt = $conn->prepare($sql_revenue);
$stmt->execute([$current_store_id, $year]);
$revenue_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// 2. Expenses: Operational Costs
$sql_expenses = "SELECT MONTH(created_at) as month, SUM(amount) as total 
                 FROM other_transactions 
                 WHERE store_id = ? AND YEAR(created_at) = ? AND type = 'expense'
                 GROUP BY MONTH(created_at)";
$stmt = $conn->prepare($sql_expenses);
$stmt->execute([$current_store_id, $year]);
$expense_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Summary Stats
$total_revenue = array_sum($revenue_data);
$total_expense = array_sum($expense_data);
$net_profit = $total_revenue - $total_expense;

?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Tổng kết lợi nhuận</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .stat-card {
            border-left: 5px solid #007bff;
        }

        .stat-card.revenue {
            border-left-color: #28a745;
        }

        .stat-card.expense {
            border-left-color: #dc3545;
        }

        .stat-card.profit {
            border-left-color: #17a2b8;
        }
    </style>
</head>

<body>
    <?php include 'header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content" style="padding-top: 80px !important;">
                <div
                    class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h4">Báo cáo: Tổng kết lợi nhuận</h1>
                    <select class="form-select form-select-sm" style="width: 120px;"
                        onchange="window.location.href='?year=' + this.value">
                        <?php for ($i = date('Y'); $i >= 2020; $i--): ?>
                            <option value="<?php echo $i; ?>" <?php echo ($i == $year) ? 'selected' : ''; ?>>Năm
                                <?php echo $i; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card stat-card revenue shadow-sm border-0">
                            <div class="card-body">
                                <div class="text-muted small fw-bold">TỔNG LÃI THU</div>
                                <div class="h3 mb-0 text-success">
                                    <?php echo number_format($total_revenue); ?> <small class="h6">đ</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card expense shadow-sm border-0">
                            <div class="card-body">
                                <div class="text-muted small fw-bold">TỔNG CHI PHÍ VẬN HÀNH</div>
                                <div class="h3 mb-0 text-danger">
                                    <?php echo number_format($total_expense); ?> <small class="h6">đ</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card profit shadow-sm border-0">
                            <div class="card-body">
                                <div class="text-muted small fw-bold">LỢI NHUẬN RÒNG</div>
                                <div class="h3 mb-0 text-info">
                                    <?php echo number_format($net_profit); ?> <small class="h6">đ</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white py-3 fw-bold">Chi tiết theo tháng (Năm
                        <?php echo $year; ?>)
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Tháng</th>
                                    <th class="text-end">Tiền lãi thu</th>
                                    <th class="text-end">Chi phí</th>
                                    <th class="text-end">Lợi nhuận</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($m = 1; $m <= 12; $m++):
                                    $rev = $revenue_data[$m] ?? 0;
                                    $exp = $expense_data[$m] ?? 0;
                                    $prof = $rev - $exp;
                                    ?>
                                    <tr>
                                        <td class="fw-bold">Tháng
                                            <?php echo $m; ?>
                                        </td>
                                        <td class="text-end text-success">
                                            <?php echo number_format($rev); ?>
                                        </td>
                                        <td class="text-end text-danger">
                                            <?php echo number_format($exp); ?>
                                        </td>
                                        <td
                                            class="text-end fw-bold <?php echo ($prof >= 0) ? 'text-primary' : 'text-danger'; ?>">
                                            <?php echo number_format($prof); ?>
                                        </td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mt-4 alert alert-light border small text-muted">
                    <i class="fas fa-lightbulb me-2"></i> <b>Giải thích:</b> Lợi nhuận ròng = (Lãi thu từ vay) - (Tiền
                    điện, nước, lương, mặt bằng...). Tiền gốc cho vay không được tính là chi phí vì nó là tài sản thu
                    hồi được.
                </div>
            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>