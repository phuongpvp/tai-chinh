<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id']))
    header("Location: login.php");
require_once 'config.php';
require_once 'permissions_helper.php';

// Require permission to edit contracts
requirePermission($conn, 'contracts.edit', 'contracts.php');

$id = $_GET['id'] ?? null;
$current_status = $_GET['status'] ?? '';

if (!$id) {
    header("Location: contracts.php" . ($current_status ? "?status=$current_status" : ""));
    exit;
}

// Handle Update
$msg = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update') {
    // 1. Update Customer
    $name = $_POST['customer_name'];
    $phone = $_POST['customer_phone'];
    $address = $_POST['customer_address'];
    $identity = $_POST['customer_identity'];

    // Get Customer ID first
    $stmt_l = $conn->prepare("SELECT customer_id FROM loans WHERE id = ? AND store_id = ?");
    $stmt_l->execute([$id, $current_store_id]);
    $loan_info = $stmt_l->fetch(PDO::FETCH_ASSOC);
    $cid = $loan_info['customer_id'];

    $stmt_c = $conn->prepare("UPDATE customers SET name = ?, phone = ?, address = ?, identity_card = ?, gender = ?, date_of_birth = ? WHERE id = ? AND store_id = ?");
    $gender = $_POST['customer_gender'] ?? null;
    $dob = $_POST['customer_dob'] ?? null;
    if (empty($dob)) $dob = null;
    $stmt_c->execute([$name, $phone, $address, $identity, $gender, $dob, $cid, $current_store_id]);

    // 2. Update Loan
    // Note: Some fields like Amount, Start Date might be sensitive if transactions exist. 
    // For now, allow editing as per screenshot implies full edit.
    $loan_code = $_POST['loan_code'];
    $amount = str_replace(['.', ','], '', $_POST['amount']);
    $interest_type = $_POST['interest_type'];
    $interest_rate = $_POST['interest_rate'];
    $period_days = $_POST['period_days'];
    $total_days = $_POST['total_days'];
    $start_date = $_POST['start_date'];
    $note = $_POST['note'];
    $collateral = $_POST['collateral'];

    // Calculate new end date based on total days? Or just save?
    // Logic: If total_days changed, update end_date.
    $end_date = date('Y-m-d', strtotime("$start_date + $total_days days"));

    $stmt_u = $conn->prepare("UPDATE loans SET loan_code = ?, amount = ?, interest_type = ?, interest_rate = ?, period_days = ?, start_date = ?, end_date = ?, contract_note = ?, collateral = ? WHERE id = ? AND store_id = ?");
    $stmt_u->execute([$loan_code, $amount, $interest_type, $interest_rate, $period_days, $start_date, $end_date, $note, $collateral, $id, $current_store_id]);
    // Redirect back to contracts list
    $redirect_url = 'contracts.php';
    if (!empty($current_status)) {
        $redirect_url .= '?status=' . urlencode($current_status);
    }
    header("Location: $redirect_url");
    exit;
}

// Fetch Data
$stmt = $conn->prepare("SELECT l.*, c.name, c.phone, c.address, c.identity_card, c.gender, c.date_of_birth 
                        FROM loans l 
                        JOIN customers c ON l.customer_id = c.id 
                        WHERE l.id = ? AND l.store_id = ?");
$stmt->execute([$id, $current_store_id]);
$loan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$loan) {
    die("Hợp đồng không tồn tại.");
}

// Calc Total Days for display
$t_days = ceil(abs(strtotime($loan['end_date']) - strtotime($loan['start_date'])) / 86400);
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Sửa Hợp Đồng -
        <?php echo $loan['loan_code']; ?>
    </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css"> <!-- Reuse style -->
</head>

<body class="bg-light">

    <div class="container mt-4">
        <div class="card shadow">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-secondary fw-bold">Hợp đồng vay tiền</h5>
                <a href="contracts.php" class="btn-close"></a>
            </div>
            <div class="card-body">

                <?php if ($msg): ?>
                    <div class="alert alert-success">
                        <?php echo $msg; ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="return_status" value="<?php echo htmlspecialchars($current_status); ?>">

                    <!-- Customer Info -->
                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label fw-bold text-end">Tên khách hàng <span
                                class="text-danger">*</span></label>
                        <div class="col-sm-4">
                            <input type="text" name="customer_name" class="form-control"
                                value="<?php echo $loan['name']; ?>" required>
                        </div>
                        <label class="col-sm-2 col-form-label fw-bold text-end">Mã HĐ</label>
                        <div class="col-sm-4">
                            <input type="text" name="loan_code" class="form-control"
                                value="<?php echo $loan['loan_code']; ?>">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label fw-bold text-end">Số CCCD/Hộ chiếu</label>
                        <div class="col-sm-4">
                            <input type="text" name="customer_identity" class="form-control"
                                value="<?php echo $loan['identity_card']; ?>">
                        </div>
                        <label class="col-sm-2 col-form-label fw-bold text-end">SĐT</label>
                        <div class="col-sm-4">
                            <input type="text" name="customer_phone" class="form-control"
                                value="<?php echo $loan['phone']; ?>">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label fw-bold text-end">Giới tính</label>
                        <div class="col-sm-4">
                            <select name="customer_gender" class="form-select">
                                <option value="">-- Chọn --</option>
                                <option value="Nam" <?php echo ($loan['gender'] ?? '') == 'Nam' ? 'selected' : ''; ?>>Nam</option>
                                <option value="Nữ" <?php echo ($loan['gender'] ?? '') == 'Nữ' ? 'selected' : ''; ?>>Nữ</option>
                            </select>
                        </div>
                        <label class="col-sm-2 col-form-label fw-bold text-end">Ngày sinh</label>
                        <div class="col-sm-4">
                            <input type="date" name="customer_dob" class="form-control"
                                value="<?php echo $loan['date_of_birth'] ?? ''; ?>">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label fw-bold text-end">Địa chỉ</label>
                        <div class="col-sm-10">
                            <input type="text" name="customer_address" class="form-control"
                                value="<?php echo $loan['address']; ?>">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label fw-bold text-end">Tài sản thế chấp</label>
                        <div class="col-sm-10">
                            <textarea name="collateral" class="form-control"
                                rows="2"><?php echo $loan['collateral']; ?></textarea>
                        </div>
                    </div>

                    <hr>

                    <!-- Loan Info -->
                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label fw-bold text-end">Tổng số tiền vay <span
                                class="text-danger">*</span></label>
                        <div class="col-sm-4">
                            <input type="text" name="amount" class="form-control number-separator bg-light"
                                value="<?php echo number_format($loan['amount']); ?>" required>
                        </div>
                        <!-- Quick Buttons Placeholder if needed -->
                        <div class="col-sm-6"></div>
                    </div>

                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label fw-bold text-end">Hình thức lãi</label>
                        <div class="col-sm-4">
                            <select name="interest_type" class="form-select">
                                <option value="ngay" <?php if ($loan['interest_type'] == 'ngay')
                                    echo 'selected'; ?>>Lãi
                                    phí ngày</option>
                                <!-- Add other types if necessary -->
                            </select>
                        </div>
                        <div class="col-sm-4 d-flex align-items-center">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" checked disabled>
                                <label class="form-check-label">Thu lãi trước</label>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label fw-bold text-end">Lãi phí <span
                                class="text-danger">*</span></label>
                        <div class="col-sm-4">
                            <input type="text" name="interest_rate" class="form-control"
                                value="<?php echo $loan['interest_rate']; ?>" required>
                        </div>
                        <div class="col-sm-6 d-flex align-items-center">
                            <div class="form-check me-3">
                                <input class="form-check-input" type="radio" name="rate_mode" checked>
                                <label class="form-check-label">k/1 triệu</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="rate_mode">
                                <label class="form-check-label">k/1 ngày</label>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label fw-bold text-end">Số ngày vay <span
                                class="text-danger">*</span></label>
                        <div class="col-sm-4">
                            <input type="number" name="total_days" class="form-control" value="<?php echo $t_days; ?>">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label fw-bold text-end">Kỳ lãi phí <span
                                class="text-danger">*</span></label>
                        <div class="col-sm-4">
                            <input type="number" name="period_days" class="form-control"
                                value="<?php echo $loan['period_days']; ?>">
                        </div>
                        <div class="col-sm-6 text-muted">
                            (VD : 10 ngày đóng lãi phí 1 lần thì điền số 10)
                        </div>
                    </div>

                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label fw-bold text-end">Ngày vay <span
                                class="text-danger">*</span></label>
                        <div class="col-sm-4">
                            <input type="date" name="start_date" class="form-control"
                                value="<?php echo $loan['start_date']; ?>">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label fw-bold text-end">Ghi chú</label>
                        <div class="col-sm-10">
                            <textarea name="note" class="form-control"
                                rows="2"><?php echo $loan['contract_note']; ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 bg-light p-3 border-top mt-4">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Cập nhật</button>
                        <button type="button" class="btn btn-info text-white"><i class="fas fa-camera"></i> Chứng
                            từ</button>
                        <button type="button" class="btn btn-success"><i class="fas fa-print"></i> In HĐ</button>
                        <a href="contracts.php<?php echo $current_status ? '?status=' . urlencode($current_status) : ''; ?>"
                            class="btn btn-light border">Thoát</a>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <script>
        // Simple Number Formatter
        document.querySelectorAll('.number-separator').forEach(inp => {
            inp.addEventListener('input', function (e) {
                let val = e.target.value.replace(/[^0-9]/g, '');
                if (val) e.target.value = new Intl.NumberFormat('vi-VN').format(parseInt(val));
            });
        });
    </script>

</body>

</html>