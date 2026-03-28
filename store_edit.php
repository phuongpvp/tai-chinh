<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

// Only Super Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'super_admin') {
    die("Unauthorized");
}

$id = $_GET['id'] ?? 0;
if (!$id) {
    header("Location: stores.php");
    exit();
}

// Fetch Store
$stmt = $conn->prepare("SELECT * FROM stores WHERE id = ?");
$stmt->execute([$id]);
$store = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$store) {
    header("Location: stores.php?error=" . urlencode("Không tìm thấy cửa hàng!"));
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $address = trim($_POST['address']);
    $phone = trim($_POST['phone']);
    $initial_balance = str_replace([',', '.'], '', $_POST['initial_balance']);

    if (empty($name)) {
        $error = "Vui lòng nhập tên cửa hàng";
    } else {
        try {
            $stmt = $conn->prepare("UPDATE stores SET name = ?, address = ?, phone = ?, initial_balance = ? WHERE id = ?");
            $stmt->execute([$name, $address, $phone, $initial_balance, $id]);

            header("Location: stores.php?msg=" . urlencode("Cập nhật cửa hàng '$name' thành công!"));
            exit;
        } catch (Exception $e) {
            $error = "Lỗi: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sửa Thông Tin Cửa Hàng</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: #f4f7f6;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            padding: 20px;
        }

        .card-edit {
            width: 100%;
            max-width: 550px;
            border: none;
            border-radius: 12px;
        }
    </style>
</head>

<body>
    <div class="card shadow-lg card-edit">
        <div class="card-header bg-white border-0 pt-4 pb-2 text-center">
            <h4 class="fw-bold text-primary"><i class="fas fa-edit me-2"></i>Sửa Thông Tin Cửa Hàng</h4>
        </div>
        <div class="card-body p-4">
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-1"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label fw-bold">Tên cửa hàng <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control"
                        value="<?php echo htmlspecialchars($store['name']); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Địa chỉ</label>
                    <input type="text" name="address" class="form-control"
                        value="<?php echo htmlspecialchars($store['address']); ?>" placeholder="Số 123 đường ABC...">
                </div>
                <div class="mb-3">
                    <label class="form-label">Số điện thoại / Hotline</label>
                    <input type="text" name="phone" class="form-control"
                        value="<?php echo htmlspecialchars($store['phone']); ?>" placeholder="0912...">
                </div>
                <div class="mb-4">
                    <label class="form-label">Số dư đầu kỳ (VNĐ)</label>
                    <input type="text" name="initial_balance" class="form-control"
                        value="<?php echo number_format($store['initial_balance']); ?>" id="balanceInput">
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save me-2"></i>Lưu Thay
                        Đổi</button>
                    <a href="stores.php" class="btn btn-light btn-lg border">Hủy bỏ</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Simple number formatting for balance
        document.getElementById('balanceInput').addEventListener('input', function (e) {
            let value = this.value.replace(/[^0-9]/g, '');
            if (value !== '') {
                this.value = new Intl.NumberFormat('vi-VN').format(value);
            }
        });
    </script>
</body>

</html>