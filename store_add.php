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

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $address = trim($_POST['address']);
    $phone = trim($_POST['phone']);

    if (empty($name)) {
        $error = "Vui lòng nhập tên cửa hàng";
    } else {
        try {
            $stmt = $conn->prepare("INSERT INTO stores (name, address, phone) VALUES (?, ?, ?)");
            $stmt->execute([$name, $address, $phone]);
            $new_store_id = $conn->lastInsertId();

            // Auto-assign the creator to this store
            $stmt2 = $conn->prepare("INSERT INTO user_stores (user_id, store_id) VALUES (?, ?)");
            $stmt2->execute([$_SESSION['user_id'], $new_store_id]);

            header("Location: store_select.php?msg=" . urlencode("Đã tạo cửa hàng '$name' thành công!"));
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
    <title>Thêm Cửa Hàng Mới</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f4f7f6;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            font-family: 'Inter', sans-serif;
        }

        .card-add {
            width: 100%;
            max-width: 500px;
            border: none;
            border-radius: 12px;
        }
    </style>
</head>

<body>
    <div class="card shadow-lg card-add">
        <div class="card-header bg-white border-0 pt-4 pb-2 text-center">
            <h4 class="fw-bold text-primary">Thêm Cửa Hàng Mới</h4>
        </div>
        <div class="card-body p-4">
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label fw-bold">Tên cửa hàng <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control form-control-lg"
                        placeholder="VD: Chi nhánh Cầu Giấy" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Địa chỉ</label>
                    <input type="text" name="address" class="form-control" placeholder="Số 123 đường ABC...">
                </div>
                <div class="mb-4">
                    <label class="form-label">Số điện thoại / Hotline</label>
                    <input type="text" name="phone" class="form-control" placeholder="0912...">
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary btn-lg">Tạo Cửa Hàng</button>
                    <a href="store_select.php" class="btn btn-light btn-lg border">Hủy bỏ</a>
                </div>
            </form>
        </div>
    </div>
</body>

</html>