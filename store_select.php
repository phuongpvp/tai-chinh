<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

// Only Super Admins can access this page
if ($_SESSION['role'] != 'super_admin') {
    header("Location: index.php");
    exit();
}

// Handle Delete Store
if (isset($_POST['action']) && $_POST['action'] == 'delete_store') {
    $store_id = $_POST['store_id'];

    // Prevent deleting default store
    if ($store_id == 1) {
        $error = "Không thể xóa cửa hàng mặc định!";
    } else {
        try {
            // Check if store has data
            $stmt = $conn->prepare("SELECT 
            (SELECT COUNT(*) FROM customers WHERE store_id = ?) as customers,
            (SELECT COUNT(*) FROM loans WHERE store_id = ?) as loans,
            (SELECT COUNT(*) FROM transactions WHERE store_id = ?) as transactions,
            (SELECT COUNT(*) FROM other_transactions WHERE store_id = ?) as other_trans
        ");
            $stmt->execute([$store_id, $store_id, $store_id, $store_id]);
            $counts = $stmt->fetch();

            $total = $counts['customers'] + $counts['loans'] + $counts['transactions'] + $counts['other_trans'];

            if ($total > 0) {
                $error = "Không thể xóa cửa hàng này vì vẫn còn dữ liệu. Vui lòng xóa hết dữ liệu trước.";
            } else {
                // Delete store
                $stmt = $conn->prepare("DELETE FROM stores WHERE id = ?");
                $stmt->execute([$store_id]);
                $success = "Đã xóa cửa hàng thành công!";
            }
        } catch (Exception $e) {
            $error = "Lỗi: " . $e->getMessage();
        }
    }
}

// Handle Reset Data
if (isset($_POST['action']) && $_POST['action'] == 'reset_data') {
    $store_id = $_POST['store_id'];

    try {
        $conn->beginTransaction();

        // 1. Delete transactions
        $stmt1 = $conn->prepare("DELETE FROM transactions WHERE store_id = ?");
        $stmt1->execute([$store_id]);

        // 2. Delete other_transactions
        $stmt2 = $conn->prepare("DELETE FROM other_transactions WHERE store_id = ?");
        $stmt2->execute([$store_id]);

        // 2b. Delete loan_extensions (FK -> loans)
        try {
            $loan_ids = $conn->prepare("SELECT id FROM loans WHERE store_id = ?");
            $loan_ids->execute([$store_id]);
            $ids = $loan_ids->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $conn->prepare("DELETE FROM loan_extensions WHERE loan_id IN ($placeholders)")->execute($ids);
                try { $conn->prepare("DELETE FROM note_history WHERE loan_id IN ($placeholders)")->execute($ids); } catch (Exception $e) {}
                try { $conn->prepare("DELETE FROM payment_history WHERE loan_id IN ($placeholders)")->execute($ids); } catch (Exception $e) {}
            }
        } catch (Exception $e) {}

        // 2c. Delete contract_attachments
        try {
            $conn->prepare("DELETE FROM contract_attachments WHERE store_id = ?")->execute([$store_id]);
        } catch (Exception $e) {}

        // 3. Delete loans
        $stmt3 = $conn->prepare("DELETE FROM loans WHERE store_id = ?");
        $stmt3->execute([$store_id]);

        // 4. Delete customers
        $stmt4 = $conn->prepare("DELETE FROM customers WHERE store_id = ?");
        $stmt4->execute([$store_id]);

        // 5. Reset initial_balance
        $stmt5 = $conn->prepare("UPDATE stores SET initial_balance = 0 WHERE id = ?");
        $stmt5->execute([$store_id]);

        $conn->commit();
        $success = "Đã làm sạch toàn bộ dữ liệu của cửa hàng thành công!";
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Lỗi khi làm sạch dữ liệu: " . $e->getMessage();
    }
}

// Handle Select Store
if (isset($_GET['select'])) {
    $_SESSION['working_store_id'] = $_GET['select'];
    header("Location: index.php");
    exit();
}

// Fetch all stores with comprehensive counts
$stmt = $conn->query("SELECT s.*, 
    (SELECT COUNT(*) FROM customers WHERE store_id = s.id) as customer_count,
    (SELECT COUNT(*) FROM loans WHERE store_id = s.id) as loan_count,
    (SELECT COUNT(*) FROM transactions WHERE store_id = s.id) as trans_count,
    (SELECT COUNT(*) FROM other_transactions WHERE store_id = s.id) as other_trans_count
    FROM stores s ORDER BY name ASC");
$stores = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chọn Cửa Hàng - Hệ thống Quản lý Dự án</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: #f4f7f6;
            font-family: 'Inter', sans-serif;
        }

        .store-card {
            transition: transform 0.2s, box-shadow 0.2s;
            border: none;
        }

        .store-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1) !important;
            cursor: pointer;
        }

        .store-icon {
            font-size: 2.5rem;
            color: #007bff;
        }

        .active-store {
            border: 2px solid #007bff !important;
        }
    </style>
</head>

<body>
    <div class="container py-5">
        <div class="text-center mb-5">
            <h2 class="fw-bold">Hệ Thống Quản Lý Chuỗi Cửa Hàng</h2>
            <p class="text-muted">Xin chào
                <?php echo $_SESSION['fullname']; ?>, vui lòng chọn cửa hàng để tiếp tục làm việc.
            </p>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> <?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4 justify-content-center">
            <?php foreach ($stores as $store): ?>
                <div class="col-md-4 col-lg-3">
                    <div
                        class="card h-100 shadow-sm store-card <?php echo ($current_store_id == $store['id']) ? 'active-store' : ''; ?>">
                        <div class="card-body text-center p-4"
                            onclick="window.location.href='?select=<?php echo $store['id']; ?>'" style="cursor: pointer;">
                            <div class="store-icon mb-3">
                                <i class="fas fa-store"></i>
                            </div>
                            <h5 class="card-title fw-bold mb-1">
                                <?php echo htmlspecialchars($store['name']); ?>
                            </h5>
                            <p class="small text-muted mb-3">
                                <?php echo htmlspecialchars($store['address'] ?: 'Chưa cập nhật địa chỉ'); ?>
                            </p>

                            <?php if ($current_store_id == $store['id']): ?>
                                <span class="badge bg-primary rounded-pill px-3">Đang làm việc</span>
                            <?php else: ?>
                                <span class="badge bg-light text-dark border rounded-pill px-3">Truy cập</span>
                            <?php endif; ?>
                        </div>

                        <div class="card-footer bg-light border-top-0 pb-3">
                                <!-- Info -->
                            <div class="mb-2 text-center text-secondary" style="font-size: 0.75rem;">
                                <?php if ($store['id'] == 1): ?>
                                    <span class="d-block mb-1"><i class="fas fa-lock text-warning"></i> Cửa hàng mặc định</span>
                                <?php endif; ?>
                                <span><i class="fas fa-users"></i>
                                    <?= $store['customer_count'] ?> KH</span> | 
                                    <span><i class="fas fa-file-contract"></i> <?= $store['loan_count'] ?> HĐ
                                </span> |
                                <span><i class="fas fa-exchange-alt"></i>
                                    <?= $store['trans_count'] ?> GD
                                </span>
                            </div>

                            <!-- Buttons Wrapper -->
                                <div class="d-grid gap-2">
                                    <a href="store_edit.php?id=<?= $store['id'] ?>" class="btn btn-sm btn-outline-primary w-100 py-1" onclick="event.stopPropagation();">
                                        <i class="fas fa-edit"></i> Sửa thông tin
                                    </a>
                            <!-- Reset Button (Show if any data exists OR if initial balance != 0) -->
                            <?php if ($store['customer_count'] > 0 || $store['loan_count'] > 0 || $store['trans_count'] > 0 || $store['other_trans_count'] > 0 || $store['initial_balance'] != 0): ?>
                                <form method="POST"
                                    onsubmit="return confirm('CẢNH BÁO: Hành động này sẽ xóa TOÀN BỘ dữ liệu (hợp đồng, khách hàng, giao dịch) của cửa hàng này. Bạn có chắc chắn muốn tiếp tục?');"
                                    onclick="event.stopPropagation();">
                                            <input type="hidden" name="action" value="reset_data">
                                    <input type="hidden" name="store_id" value="<?= $store['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning w-100 py-1">
                                        <i class="fas fa-eraser"></i> Làm sạch dữ liệu
                                    </button>
                                </form>
                            <?php endif; ?>

                            <!-- Delete Button (Only for non-default stores with zero operational data) -->
                            <?php if ($store['id'] != 1 && $store['customer_count'] == 0 && $store['loan_count'] == 0): ?>
                                    <form method="POST" onsubmit="return confirm('Bạn có chắc chắn muốn xóa hẳn cửa hàng này
                        khỏi hệ thống?');"
                                onclick="event.stopPropagation();">
                                               <input type="hidden" name="action" value="delete_store">
                                <input type="hidden" name="store_id" value="<?= $store['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger w-100 py-1">
                                    <i class="fas fa-trash"></i> Xóa cửa hàng
                                </button>
                                </form> <?php endif; ?> </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Add New Store Card -->
            <div class="col-md-4 col-lg-3">
                <div class="card h-100 shadow-sm store-card border-dashed" style="border: 2px dashed #ccc !important;"
                    onclick="window.location.href='store_add.php'">
                    <div class="card-body text-center d-flex flex-column justify-content-center p-4">
                        <div class="text-muted mb-3" style="font-size: 2.5rem;">
                                <i class="fas fa-plus-circle"></i>
                        </div>
                        <h5 class="card-title fw-bold text-muted">Thêm cửa hàng</h5>
                                </div>
                    </div>
                </div>
            </div>

            <div class="text-center mt-5">
                <a href="logout.php" class="btn btn-outline-secondary"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>