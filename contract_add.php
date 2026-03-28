<?php
session_start();
if (!isset($_SESSION['user_id']))
    header("Location: login.php");
require_once 'config.php';
require_once 'permissions_helper.php';

// Require permission to add contracts
requirePermission($conn, 'contracts.create', 'contracts.php');

// Fetch existing customers
$customers = $conn->prepare("SELECT id, name, phone, identity_card FROM customers WHERE store_id = ? ORDER BY name");
$customers->execute([$current_store_id]);
$customers = $customers->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $customer_type = $_POST['customer_type'] ?? 'existing';
    $customer_id = 0;

    // 1. Handle Customer
    if ($customer_type == 'new') {
        $c_name = $_POST['new_customer_name'];
        $c_phone = $_POST['new_customer_phone'];
        $c_card = $_POST['new_customer_card'];
        $c_address = $_POST['new_customer_address'];

        if (empty($c_name)) {
            $error = "Vui lòng nhập tên khách hàng mới";
        } else {
            try {
                $c_gender = $_POST['new_customer_gender'] ?? '';
                $c_dob = !empty($_POST['new_customer_dob']) ? $_POST['new_customer_dob'] : null;
                $stmt = $conn->prepare("INSERT INTO customers (name, phone, identity_card, address, gender, date_of_birth, store_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$c_name, $c_phone, $c_card, $c_address, $c_gender, $c_dob, $current_store_id]);
                $customer_id = $conn->lastInsertId();
            } catch (PDOException $e) {
                $error = "Lỗi tạo khách hàng: " . $e->getMessage();
            }
        }
    } else {
        $customer_id = $_POST['customer_id'];
        if (empty($customer_id))
            $error = "Vui lòng chọn khách hàng";
    }

    // 2. Handle Loan
    if (!$error) {
        $amount = str_replace(',', '', $_POST['amount']);
        $loan_type = $_POST['loan_type'] ?? 'tin_chap'; // Default to tin_chap if hidden/missing
        $interest_type = $_POST['interest_type'];
        $interest_rate = $_POST['interest_rate'];
        $period_days = $_POST['period_days']; // Kỳ lãi phí (Frequency)
        $total_days = $_POST['total_days'];   // Số ngày vay (Duration)
        $start_date = $_POST['start_date'];
        $collateral = $_POST['collateral'];
        $note = $_POST['note'];
        $loan_code = $_POST['loan_code'];

        if (empty($loan_code))
            $loan_code = 'TC-' . time();

        // Calculate End Date
        $end_date = date('Y-m-d', strtotime($start_date . ' + ' . $total_days . ' days'));

        // Handle Interest Type 'ngay' vs 'percent' logic if needed
        // For now storing raw input.

        try {
            $stmt = $conn->prepare("INSERT INTO loans 
                (customer_id, loan_code, amount, loan_type, interest_type, interest_rate, period_days, start_date, end_date, collateral, contract_note, status, store_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)");

            $stmt->execute([
                $customer_id,
                $loan_code,
                $amount,
                $loan_type,
                $interest_type,
                $interest_rate,
                $period_days,
                $start_date,
                $end_date,
                $collateral,
                $note,
                $current_store_id
            ]);

            $loan_id = $conn->lastInsertId();

            // Transaction: Disburse
            $stmt_trans = $conn->prepare("INSERT INTO transactions (loan_id, type, amount, date, note, store_id, user_id) VALUES (?, 'disburse', ?, ?, 'Giải ngân hợp đồng mới', ?, ?)");
            $stmt_trans->execute([$loan_id, $amount, $start_date, $current_store_id, $_SESSION['user_id']]);

            $success = "Tạo hợp đồng thành công!";
            header("Location: contracts.php?msg=" . urlencode($success));
            exit;
        } catch (PDOException $e) {
            $error = "Lỗi tạo hợp đồng: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Thêm mới hợp đồng</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .quick-btn {
            cursor: pointer;
        }

        .form-label {
            font-weight: bold;
            font-size: 0.9rem;
        }

        .card-header {
            font-size: 1.1rem;
        }
    </style>
</head>

<body class="bg-light">
    <?php include 'navbar.php'; ?>

    <div class="container mt-4 mb-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white pt-3 pb-2">
                <h5 class="text-secondary fw-bold">Hợp đồng vay tiền</h5>
            </div>
            <div class="card-body p-4">
                <?php if ($error): ?>
                    <div class="alert alert-danger py-2"><?php echo $error; ?></div><?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success py-2"><?php echo $success; ?> <a href="contracts.php">Danh sách HĐ</a>
                    </div><?php endif; ?>

                <form method="POST" id="contractForm">
                    <input type="hidden" name="loan_code" value="<?php echo time(); ?>">
                    <!-- Customer Section -->
                    <div class="border rounded p-3 mb-4 bg-white">
                        <div class="d-flex align-items-center mb-3">
                            <div class="form-check me-4">
                                <input class="form-check-input" type="radio" name="customer_type" id="cust_new"
                                    value="new" checked>
                                <label class="form-check-label" for="cust_new">Khách hàng mới</label>
                            </div>
                            <div class="form-check me-4">
                                <input class="form-check-input" type="radio" name="customer_type" id="cust_exist"
                                    value="existing">
                                <label class="form-check-label" for="cust_exist">Khách hàng đã có trong hệ thống</label>
                            </div>
                            <button type="button" class="btn btn-sm btn-light border" onclick="location.reload()"><i
                                    class="fas fa-sync-alt"></i></button>
                        </div>

                        <!-- New Customer Fields -->
                        <div id="new_customer_fields">
                            <div class="row mb-2">
                                <div class="col-md-6">
                                    <div class="row align-items-center">
                                        <label class="col-sm-4 col-form-label text-end text-danger">Tên khách hàng
                                            *</label>
                                        <div class="col-sm-8">
                                            <input type="text" name="new_customer_name" class="form-control" autofocus>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-md-6">
                                    <div class="row align-items-center">
                                        <label class="col-sm-4 col-form-label text-end">Số CCCD/HC</label>
                                        <div class="col-sm-8">
                                            <input type="text" name="new_customer_card" class="form-control">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="row align-items-center">
                                        <label class="col-sm-4 col-form-label text-end">SĐT</label>
                                        <div class="col-sm-8">
                                            <input type="text" name="new_customer_phone" class="form-control">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-md-6">
                                    <div class="row align-items-center">
                                        <label class="col-sm-4 col-form-label text-end">Giới tính</label>
                                        <div class="col-sm-8">
                                            <select name="new_customer_gender" class="form-select">
                                                <option value="">-- Chọn --</option>
                                                <option value="Nam">Nam</option>
                                                <option value="Nữ">Nữ</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="row align-items-center">
                                        <label class="col-sm-4 col-form-label text-end">Ngày sinh</label>
                                        <div class="col-sm-8">
                                            <input type="date" name="new_customer_dob" class="form-control">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-md-12">
                                    <div class="row align-items-center">
                                        <label class="col-sm-2 col-form-label text-end">Địa chỉ</label>
                                        <div class="col-sm-10">
                                            <textarea name="new_customer_address" class="form-control"
                                                rows="1"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Existing Customer Field -->
                        <div id="existing_customer_fields" style="display:none;">
                            <div class="row mb-2">
                                <div class="col-md-12">
                                    <div class="row align-items-center">
                                        <label class="col-sm-2 col-form-label text-end text-danger">Chọn khách hàng
                                            *</label>
                                        <div class="col-sm-10">
                                            <select name="customer_id" class="form-select">
                                                <option value="">-- Tìm theo tên hoặc SĐT --</option>
                                                <?php foreach ($customers as $c): ?>
                                                    <option value="<?php echo $c['id']; ?>"><?php echo $c['name']; ?> -
                                                        <?php echo $c['phone']; ?> - <?php echo $c['identity_card']; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-2">
                            <div class="col-md-12">
                                <div class="row align-items-center">
                                    <label class="col-sm-2 col-form-label text-end">Đơn vị công tác</label>
                                    <div class="col-sm-10">
                                        <textarea name="collateral" class="form-control" rows="2"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Loan Details Section -->
                    <div class="border rounded p-3 bg-white">
                        <div class="row mb-2">
                            <div class="col-md-6">
                                <div class="row align-items-center">
                                    <label class="col-sm-4 col-form-label text-end text-danger">Tổng số tiền vay
                                        *</label>
                                    <div class="col-sm-8">
                                        <input type="text" id="amount" name="amount" class="form-control" required
                                            value="0">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex gap-1">
                                    <button type="button" class="btn btn-secondary btn-sm quick-btn"
                                        onclick="addAmount(-5000000)">-5</button>
                                    <button type="button" class="btn btn-secondary btn-sm quick-btn"
                                        onclick="addAmount(5000000)">+5</button>
                                    <button type="button" class="btn btn-secondary btn-sm quick-btn"
                                        onclick="addAmount(10000000)">10</button>
                                    <button type="button" class="btn btn-secondary btn-sm quick-btn"
                                        onclick="addAmount(20000000)">20</button>
                                    <button type="button" class="btn btn-secondary btn-sm quick-btn"
                                        onclick="addAmount(30000000)">30</button>
                                    <button type="button" class="btn btn-secondary btn-sm quick-btn"
                                        onclick="addAmount(40000000)">40</button>
                                    <button type="button" class="btn btn-secondary btn-sm quick-btn"
                                        onclick="addAmount(50000000)">50</button>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-2">
                            <div class="col-md-6">
                                <div class="row align-items-center">
                                    <label class="col-sm-4 col-form-label text-end fw-bold">Hình thức lãi</label>
                                    <div class="col-sm-8">
                                        <select name="interest_type" class="form-select">
                                            <option value="ngay">Lãi phí ngày</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex align-items-center h-100">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="prepaid">
                                        <label class="form-check-label" for="prepaid">Thu lãi trước</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-2">
                            <div class="col-md-6">
                                <div class="row align-items-center">
                                    <label class="col-sm-4 col-form-label text-end text-danger">Lãi phí 1 triệu/1 ngày *</label>
                                    <div class="col-sm-8">
                                        <input type="text" name="interest_rate" class="form-control" value="0">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-2">
                            <div class="col-md-6">
                                <div class="row align-items-center">
                                    <label class="col-sm-4 col-form-label text-end text-danger">Số ngày vay *</label>
                                    <div class="col-sm-8">
                                        <input type="number" name="total_days" class="form-control" value="0" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-2">
                            <div class="col-md-6">
                                <div class="row align-items-center">
                                    <label class="col-sm-4 col-form-label text-end text-danger">Kỳ lãi phí *</label>
                                    <div class="col-sm-8">
                                        <input type="number" name="period_days" class="form-control" value="0" required>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-text mt-2">(VD: 10 ngày đóng lãi phí 1 lần thì điền số 10)</div>
                            </div>
                        </div>

                        <div class="row mb-2">
                            <div class="col-md-6">
                                <div class="row align-items-center">
                                    <label class="col-sm-4 col-form-label text-end text-danger">Ngày vay *</label>
                                    <div class="col-sm-8">
                                        <input type="date" name="start_date" class="form-control"
                                            value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-2">
                            <div class="col-md-12">
                                <div class="row align-items-center">
                                    <label class="col-sm-2 col-form-label text-end">Ghi chú</label>
                                    <div class="col-sm-10">
                                        <textarea name="note" class="form-control" rows="2"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="text-end mt-4 px-3">
                        <button type="submit" class="btn btn-primary px-4 bg-primary text-white">Thêm mới</button>
                        <a href="contracts.php" class="btn btn-light border px-4">Thoát</a>
                    </div>
                </form>
            </div>
        </div>
        <div class="text-danger mt-3 small">
            *Chú ý: Khách hàng phải đảm bảo lãi suất và chi phí khi cho vay (gọi chung là "chi phí vay") tuân thủ quy
            định pháp luật tại từng thời điểm.
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle Customer Fields
        const radioNew = document.getElementById('cust_new');
        const radioExist = document.getElementById('cust_exist');
        const fieldsNew = document.getElementById('new_customer_fields');
        const fieldsExist = document.getElementById('existing_customer_fields');

        function toggleCustomer() {
            if (radioNew.checked) {
                fieldsNew.style.display = 'block';
                fieldsExist.style.display = 'none';
            } else {
                fieldsNew.style.display = 'none';
                fieldsExist.style.display = 'block';
            }
        }
        radioNew.addEventListener('change', toggleCustomer);
        radioExist.addEventListener('change', toggleCustomer);

        // Format number with dots
        function formatNumber(num) {
            return new Intl.NumberFormat('vi-VN').format(num);
        }
        function parseAmount(str) {
            return parseInt(String(str).replace(/[^0-9]/g, '')) || 0;
        }

        // Quick Amount
        function addAmount(val) {
            const input = document.getElementById('amount');
            let current = parseAmount(input.value);
            let newVal = current + val;
            if (newVal < 0) newVal = 0;
            input.value = formatNumber(newVal);
        }

        // Auto-format amount input
        document.getElementById('amount').addEventListener('input', function(e) {
            let val = parseAmount(e.target.value);
            e.target.value = val > 0 ? formatNumber(val) : '0';
        });

        // Strip separators before submit
        document.getElementById('contractForm').addEventListener('submit', function() {
            const amountInput = document.getElementById('amount');
            amountInput.value = parseAmount(amountInput.value);
        });

        // Strip leading zeros from all number inputs
        document.querySelectorAll('input[type="number"]').forEach(function(input) {
            input.addEventListener('input', function() {
                if (this.value.length > 1 && this.value.startsWith('0')) {
                    this.value = parseInt(this.value, 10) || 0;
                }
            });
        });
    </script>
</body>

</html>