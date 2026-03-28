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
    die("Bạn không có quyền xem báo cáo!");
}

// Get date range
$from_date = $_GET['from_date'] ?? date('Y-m-01'); // First day of current month
$to_date = $_GET['to_date'] ?? date('Y-m-d'); // Today

// Get consolidated report by shareholder code
$stmt = $conn->prepare("
    SELECT 
        dd.shareholder_code,
        dd.shareholder_name,
        SUM(dd.amount) as total_amount,
        COUNT(DISTINCT dist.id) as distribution_count,
        COUNT(DISTINCT dist.store_id) as store_count
    FROM dividend_details dd
    JOIN dividend_distributions dist ON dd.distribution_id = dist.id
    WHERE dist.distribution_date BETWEEN ? AND ?
    GROUP BY dd.shareholder_code, dd.shareholder_name
    ORDER BY total_amount DESC
");
$stmt->execute([$from_date, $to_date]);
$shareholders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get selected shareholder details if requested
$selected_shareholder = $_GET['shareholder_code'] ?? null;
$shareholder_details = [];

if ($selected_shareholder) {
    $stmt = $conn->prepare("
        SELECT 
            dist.distribution_date,
            s.name as store_name,
            dd.amount,
            dd.percentage,
            dist.note,
            dist.batch_id
        FROM dividend_details dd
        JOIN dividend_distributions dist ON dd.distribution_id = dist.id
        JOIN stores s ON dist.store_id = s.id
        WHERE dd.shareholder_code = ?
        AND dist.distribution_date BETWEEN ? AND ?
        ORDER BY dist.distribution_date DESC, s.name ASC
    ");
    $stmt->execute([$selected_shareholder, $from_date, $to_date]);
    $shareholder_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Báo cáo tổng hợp cổ tức - Trương Hưng</title>
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
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="index.php">Trang chủ</a></li>
                        <li class="breadcrumb-item"><a href="shareholders.php">Cổ đông</a></li>
                        <li class="breadcrumb-item active">Báo cáo tổng hợp</li>
                    </ol>
                </nav>

                <h4 class="mb-3 border-bottom pb-2"><i class="fas fa-chart-line"></i> Báo cáo tổng hợp cổ tức</h4>

                <!-- Filter Form -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Từ ngày:</label>
                                <input type="date" name="from_date" class="form-control" 
                                    value="<?php echo $from_date; ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Đến ngày:</label>
                                <input type="date" name="to_date" class="form-control" 
                                    value="<?php echo $to_date; ?>" required>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Xem báo cáo
                                </button>
                                <a href="dividend_report.php" class="btn btn-secondary ms-2">
                                    <i class="fas fa-redo"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Summary Table -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-users"></i> Tổng hợp theo cổ đông
                            <small class="float-end">
                                Từ <?php echo date('d/m/Y', strtotime($from_date)); ?> 
                                đến <?php echo date('d/m/Y', strtotime($to_date)); ?>
                            </small>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($shareholders) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="80">Mã CĐ</th>
                                            <th>Tên cổ đông</th>
                                            <th class="text-end">Số lần nhận</th>
                                            <th class="text-end">Số cửa hàng</th>
                                            <th class="text-end">Tổng tiền nhận</th>
                                            <th width="100" class="text-center">Chi tiết</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $grand_total = 0;
                                        foreach ($shareholders as $sh): 
                                            $grand_total += $sh['total_amount'];
                                        ?>
                                            <tr>
                                                <td class="fw-bold text-primary"><?php echo htmlspecialchars($sh['shareholder_code']); ?></td>
                                                <td><?php echo htmlspecialchars($sh['shareholder_name']); ?></td>
                                                <td class="text-end"><?php echo $sh['distribution_count']; ?> lần</td>
                                                <td class="text-end"><?php echo $sh['store_count']; ?> cửa hàng</td>
                                                <td class="text-end fw-bold text-success">
                                                    <?php echo number_format($sh['total_amount']); ?> đ
                                                </td>
                                                <td class="text-center">
                                                    <a href="?from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&shareholder_code=<?php echo urlencode($sh['shareholder_code']); ?>#details" 
                                                        class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-eye"></i> Xem
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light fw-bold">
                                        <tr>
                                            <td colspan="4" class="text-end">Tổng cộng:</td>
                                            <td class="text-end text-danger"><?php echo number_format($grand_total); ?> đ</td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info text-center">
                                <i class="fas fa-info-circle"></i>
                                Không có dữ liệu trong khoảng thời gian này
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Shareholder Details -->
                <?php if ($selected_shareholder && count($shareholder_details) > 0): ?>
                    <div class="card shadow-sm" id="details">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-list"></i> Chi tiết cổ đông: 
                                <strong><?php echo htmlspecialchars($selected_shareholder); ?></strong>
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Ngày</th>
                                            <th>Cửa hàng</th>
                                            <th class="text-end">Tỷ lệ %</th>
                                            <th class="text-end">Số tiền</th>
                                            <th>Ghi chú</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $detail_total = 0;
                                        foreach ($shareholder_details as $d): 
                                            $detail_total += $d['amount'];
                                        ?>
                                            <tr>
                                                <td><?php echo date('d/m/Y', strtotime($d['distribution_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($d['store_name']); ?></td>
                                                <td class="text-end"><?php echo number_format($d['percentage'], 2); ?>%</td>
                                                <td class="text-end fw-bold text-success">
                                                    <?php echo number_format($d['amount']); ?> đ
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($d['note'] ?: '-'); ?>
                                                    <?php if ($d['batch_id']): ?>
                                                        <br><small class="badge bg-secondary"><?php echo $d['batch_id']; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light fw-bold">
                                        <tr>
                                            <td colspan="3" class="text-end">Tổng cộng:</td>
                                            <td class="text-end text-danger"><?php echo number_format($detail_total); ?> đ</td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <div class="mt-3">
                                <a href="?from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" 
                                    class="btn btn-secondary">
                                    <i class="fas fa-arrow-left"></i> Quay lại tổng hợp
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
