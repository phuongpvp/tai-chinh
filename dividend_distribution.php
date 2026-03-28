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

// Get current store cash fund (same logic as contracts.php)
$stmt = $conn->prepare("SELECT initial_balance FROM stores WHERE id = ?");
$stmt->execute([$current_store_id]);
$store = $stmt->fetch();
$initial_balance = $store['initial_balance'] ?? 0;

// Calculate total cash in (exclude imported transactions)
$sql_cash_in = "SELECT 
                (SELECT SUM(amount) FROM transactions WHERE store_id = ? AND type IN ('collect_interest', 'pay_principal', 'pay_all') AND (note NOT LIKE '%Import%' OR note IS NULL)) as in1,
                (SELECT SUM(amount) FROM other_transactions WHERE store_id = ? AND type = 'income' AND (note NOT LIKE '%Import%' OR note IS NULL)) as in2";
$stmt_in = $conn->prepare($sql_cash_in);
$stmt_in->execute([$current_store_id, $current_store_id]);
$row_in = $stmt_in->fetch();
$total_in = ($row_in['in1'] ?? 0) + ($row_in['in2'] ?? 0);

// Calculate total cash out (exclude imported transactions)
$sql_cash_out = "SELECT 
                (SELECT SUM(amount) FROM transactions WHERE store_id = ? AND type IN ('disburse', 'lend_more') AND (note NOT LIKE '%Import%' OR note IS NULL)) as out1,
                (SELECT SUM(amount) FROM other_transactions WHERE store_id = ? AND type = 'expense' AND (note NOT LIKE '%Import%' OR note IS NULL)) as out2";
$stmt_out = $conn->prepare($sql_cash_out);
$stmt_out->execute([$current_store_id, $current_store_id]);
$row_out = $stmt_out->fetch();
$total_out = ($row_out['out1'] ?? 0) + ($row_out['out2'] ?? 0);

// Current balance = Initial + In - Out
$current_balance = $initial_balance + $total_in - $total_out;

// Get shareholders
$stmt = $conn->prepare("SELECT * FROM shareholders WHERE store_id = ? ORDER BY percentage DESC");
$stmt->execute([$current_store_id]);
$shareholders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get distribution history
$stmt = $conn->prepare("
    SELECT dd.*, u.username, u.fullname
    FROM dividend_distributions dd
    LEFT JOIN users u ON dd.user_id = u.id
    WHERE dd.store_id = ?
    ORDER BY dd.distribution_date DESC, dd.created_at DESC
    LIMIT 20
");
$stmt->execute([$current_store_id]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Chia cổ tức - Trương Hưng</title>
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
                        <li class="breadcrumb-item active">Chia cổ tức</li>
                    </ol>
                </nav>

                <h4 class="mb-3 border-bottom pb-2"><i class="fas fa-hand-holding-usd"></i> Chia cổ tức</h4>

                <?php if (isset($_GET['msg'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo htmlspecialchars($_GET['msg']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo htmlspecialchars($_GET['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Current Balance -->
                <div class="card shadow-sm mb-4 border-primary">
                    <div class="card-body text-center">
                        <h6 class="text-muted mb-2">Quỹ tiền mặt hiện tại</h6>
                        <h2 class="text-primary mb-0">
                            <?php echo number_format($current_balance); ?> <small class="text-muted">đ</small>
                        </h2>
                    </div>
                </div>

                <?php if (count($shareholders) == 0): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        Chưa có cổ đông nào. Vui lòng <a href="shareholders.php">thêm cổ đông</a> trước khi chia cổ tức.
                    </div>
                <?php else: ?>
                    <!-- Distribution Form -->
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="fas fa-calculator"></i> Tính toán chia cổ tức</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="dividend_process.php" id="dividendForm">
                                <input type="hidden" name="action" value="distribute_single">
                                <input type="hidden" name="store_id" value="<?php echo $current_store_id; ?>">

                                <div class="row mb-4">
                                    <label class="col-md-3 col-form-label fw-bold">Ngày chia cổ tức:</label>
                                    <div class="col-md-6">
                                        <input type="date" name="distribution_date" class="form-control"
                                            value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>

                                <div class="row mb-4">
                                    <label class="col-md-3 col-form-label fw-bold">Số tiền chia:</label>
                                    <div class="col-md-6">
                                        <input type="text" name="total_amount" id="total_amount"
                                            class="form-control form-control-lg number-separator"
                                            placeholder="VD: 10.000.000" required>
                                        <small class="text-muted">Số tiền sẽ được trừ trực tiếp vào quỹ</small>
                                    </div>
                                </div>

                                <div class="row mb-4">
                                    <label class="col-md-3 col-form-label fw-bold">Ghi chú:</label>
                                    <div class="col-md-6">
                                        <textarea name="note" class="form-control" rows="2"
                                            placeholder="Ghi chú về lần chia cổ tức này..."></textarea>
                                    </div>
                                </div>

                                <!-- Preview -->
                                <div class="row mb-4">
                                    <div class="col-md-9 offset-md-3">
                                        <div class="card border-info">
                                            <div class="card-header bg-info text-white">
                                                <strong>Preview kết quả chia</strong>
                                            </div>
                                            <div class="card-body">
                                                <table class="table table-sm mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>Mã CĐ</th>
                                                            <th>Tên</th>
                                                            <th class="text-end">Tỷ lệ %</th>
                                                            <th class="text-end">Số tiền nhận</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="preview_body">
                                                        <?php foreach ($shareholders as $sh): ?>
                                                            <tr>
                                                                <td>
                                                                    <?php echo htmlspecialchars($sh['shareholder_code']); ?>
                                                                </td>
                                                                <td>
                                                                    <?php echo htmlspecialchars($sh['name']); ?>
                                                                </td>
                                                                <td class="text-end">
                                                                    <?php echo number_format($sh['percentage'], 2); ?>%
                                                                </td>
                                                                <td class="text-end preview-amount"
                                                                    data-percentage="<?php echo $sh['percentage']; ?>">
                                                                    0 đ
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                    <tfoot class="table-light fw-bold">
                                                        <tr>
                                                            <td colspan="3" class="text-end">Tổng cộng:</td>
                                                            <td class="text-end text-success" id="preview_total">0 đ</td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-9 offset-md-3">
                                        <button type="submit" class="btn btn-success btn-lg px-5" id="submitBtn" disabled>
                                            <i class="fas fa-check-circle"></i> Xác nhận chia cổ tức
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Distribution History -->
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-history"></i> Lịch sử chia cổ tức</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($history) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Ngày</th>
                                            <th>Số tiền</th>
                                            <th>Ghi chú</th>
                                            <th>Người thực hiện</th>
                                            <th class="text-center">Chi tiết</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($history as $h): ?>
                                            <tr>
                                                <td>
                                                    <?php echo date('d/m/Y', strtotime($h['distribution_date'])); ?>
                                                </td>
                                                <td class="fw-bold text-danger">
                                                    <?php echo number_format($h['total_amount']); ?> đ
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($h['note'] ?: '-'); ?>
                                                    <?php if ($h['batch_id']): ?>
                                                        <br><small class="badge bg-info">Chia đồng loạt:
                                                            <?php echo $h['batch_id']; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($h['fullname'] ?: $h['username'] ?: '-'); ?>
                                                </td>
                                                <td class="text-center">
                                                    <button class="btn btn-sm btn-outline-primary"
                                                        onclick="viewDetails(<?php echo $h['id']; ?>)">
                                                        <i class="fas fa-eye"></i> Xem
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center py-4">Chưa có lịch sử chia cổ tức</p>
                        <?php endif; ?>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <!-- Modal View Details -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Chi tiết chia cổ tức</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailsContent">
                    <div class="text-center">
                        <div class="spinner-border" role="status"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Number separator
        document.addEventListener('DOMContentLoaded', function () {
            const separators = document.querySelectorAll('.number-separator');
            separators.forEach(input => {
                input.addEventListener('input', function (e) {
                    let value = e.target.value.replace(/[^0-9]/g, '');
                    if (value) {
                        const intVal = parseInt(value, 10);
                        e.target.value = new Intl.NumberFormat('vi-VN').format(intVal);
                        updatePreview(intVal);
                    } else {
                        e.target.value = '';
                        updatePreview(0);
                    }
                });
            });
        });

        function updatePreview(totalAmount) {
            const previewAmounts = document.querySelectorAll('.preview-amount');
            let calculatedTotal = 0;

            previewAmounts.forEach(el => {
                const percentage = parseFloat(el.dataset.percentage);
                const amount = Math.round(totalAmount * (percentage / 100));
                el.textContent = new Intl.NumberFormat('vi-VN').format(amount) + ' đ';
                calculatedTotal += amount;
            });

            document.getElementById('preview_total').textContent =
                new Intl.NumberFormat('vi-VN').format(calculatedTotal) + ' đ';

            // Enable submit button if amount > 0
            document.getElementById('submitBtn').disabled = (totalAmount <= 0);
        }

        function viewDetails(distributionId) {
            const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
            modal.show();

            fetch('dividend_details_ajax.php?id=' + distributionId)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('detailsContent').innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('detailsContent').innerHTML =
                        '<div class="alert alert-danger">Lỗi tải dữ liệu</div>';
                });
        }

        // Confirm before submit
        document.getElementById('dividendForm').addEventListener('submit', function (e) {
            const amount = document.getElementById('total_amount').value;
            if (!confirm(`Xác nhận chia cổ tức ${amount} đ?\n\nSố tiền này sẽ được trừ trực tiếp vào quỹ tiền mặt.`)) {
                e.preventDefault();
            }
        });
    </script>
</body>

</html>