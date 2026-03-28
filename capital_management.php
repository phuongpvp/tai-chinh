<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

// Handle Form Submission
$success_msg = "";
$error_msg = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_balance') {
    $new_balance = str_replace([',', '.'], '', $_POST['initial_balance']);

    if (empty($new_balance) || !is_numeric($new_balance)) {
        $error_msg = "Vui lòng nhập số tiền hợp lệ";
    } else {
        try {
            // Simply update the balance - no transaction logging needed
            // Initial balance is a STARTING POINT, not a transaction
            $stmt = $conn->prepare("UPDATE stores SET initial_balance = ? WHERE id = ?");
            $stmt->execute([$new_balance, $current_store_id]);

            $success_msg = "Cập nhật số dư đầu kỳ thành công! (" . number_format($new_balance) . " đ)";
        } catch (Exception $e) {
            $error_msg = "Lỗi: " . $e->getMessage();
        }
    }
}

// Fetch current balance
try {
    $stmt = $conn->prepare("SELECT initial_balance FROM stores WHERE id = ?");
    $stmt->execute([$current_store_id]);
    $store = $stmt->fetch();
    $current_balance = $store['initial_balance'] ?? 0;
} catch (Exception $e) {
    $current_balance = 0;
    $error_msg = "Lỗi khi tải dữ liệu: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Quản lý nguồn vốn</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
</head>

<body class="bg-light">
    <?php include 'header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div
                    class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h4 text-danger">Cửa hàng:
                        <?php echo htmlspecialchars($_SESSION['store_name'] ?? 'Công ty 1'); ?>
                    </h1>
                    <div class="text-muted small"><?php echo date('d-m-Y'); ?></div>
                </div>

                <?php if ($success_msg): ?>
                    <div class="alert alert-success"><?php echo $success_msg; ?></div>
                <?php endif; ?>
                <?php if ($error_msg): ?>
                    <div class="alert alert-danger"><?php echo $error_msg; ?></div>
                <?php endif; ?>

                <!-- Current Balance Display -->
                <div class="card shadow-sm mb-4 border-0">
                    <div class="card-header bg-gradient text-white"
                        style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <h5 class="mb-0"><i class="fas fa-wallet me-2"></i>Số dư quỹ tiền mặt đầu kỳ</h5>
                    </div>
                    <div class="card-body text-center py-5">
                        <h2 class="display-4 fw-bold text-primary mb-0">
                            <?php echo number_format($current_balance); ?> <small class="text-muted fs-5">VNĐ</small>
                        </h2>
                    </div>
                </div>

                <!-- Update Balance Form -->
                <div class="card shadow-sm mb-4 border-0">
                    <div class="card-header bg-white fw-bold py-3">
                        <i class="fas fa-edit me-2"></i>Cập nhật số dư đầu kỳ
                    </div>
                    <div class="card-body bg-light rounded">
                        <form method="POST" class="row g-3 justify-content-center">
                            <input type="hidden" name="action" value="update_balance">
                            <div class="col-md-6">
                                <div class="row mb-3">
                                    <label class="col-sm-4 col-form-label text-end fw-bold">Số dư đầu kỳ *</label>
                                    <div class="col-sm-8">
                                        <input type="text" name="initial_balance" id="balance_input"
                                            class="form-control form-control-lg fw-bold text-primary text-end"
                                            value="<?php echo number_format($current_balance); ?>" required>
                                        <small class="text-muted">Nhập số tiền vốn ban đầu hoặc số dư đầu kỳ của quỹ
                                            tiền mặt</small>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary px-4 fw-bold">
                                        <i class="fas fa-save me-2"></i>Cập nhật
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white fw-bold py-3">
                        <i class="fas fa-bolt me-2"></i>Thao tác nhanh
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="d-grid">
                                    <a href="incomes.php" class="btn btn-success btn-lg">
                                        <i class="fas fa-plus-circle me-2"></i>Nhập quỹ (Thu hoạt động)
                                    </a>
                                    <small class="text-muted mt-2">Ghi nhận tiền mặt nộp vào quỹ (góp vốn, thu
                                        khác...)</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-grid">
                                    <a href="report_cash_book.php" class="btn btn-info btn-lg text-white">
                                        <i class="fas fa-book me-2"></i>Xem sổ quỹ tiền mặt
                                    </a>
                                    <small class="text-muted mt-2">Kiểm tra báo cáo quỹ tiền mặt chi tiết</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto format amount
        const balanceInput = document.getElementById('balance_input');
        if (balanceInput) {
            balanceInput.addEventListener('input', function (e) {
                let val = e.target.value.replace(/[^0-9]/g, '');
                if (val) {
                    e.target.value = new Intl.NumberFormat('vi-VN').format(parseInt(val));
                } else {
                    e.target.value = '0';
                }
            });
            // Focus select all
            balanceInput.addEventListener('focus', function () { this.select(); });
        }
    </script>
</body>

</html>