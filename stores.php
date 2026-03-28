<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'config.php';

// Only super_admin can manage stores
if (($_SESSION['role'] ?? '') != 'super_admin') {
    die("Chỉ Super Admin mới có quyền quản lý cửa hàng.");
}

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_store') {
    $store_id = $_POST['store_id'];

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
            $error = "Không thể xóa cửa hàng này vì vẫn còn dữ liệu ({$counts['customers']} khách hàng, {$counts['loans']} hợp đồng, {$counts['transactions']} giao dịch). Vui lòng xóa hết dữ liệu trước.";
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

// Fetch all stores
$stmt = $conn->query("SELECT s.*, 
    (SELECT COUNT(*) FROM customers WHERE store_id = s.id) as customer_count,
    (SELECT COUNT(*) FROM loans WHERE store_id = s.id) as loan_count,
    (SELECT COUNT(*) FROM users WHERE store_id = s.id) as user_count
    FROM stores s ORDER BY s.id");
$stores = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý cửa hàng</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <?php include 'header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div
                    class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="fas fa-store me-2"></i>Quản lý cửa hàng</h1>
                    <a href="store_add.php" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus me-1"></i>Thêm cửa hàng
                    </a>
                </div>

                <?php if (isset($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-1"></i>
                        <?= $success ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-1"></i>
                        <?= $error ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['msg'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-1"></i>
                        <?= htmlspecialchars($_GET['msg']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Tên cửa hàng</th>
                                <th>Địa chỉ</th>
                                <th>Số điện thoại</th>
                                <th class="text-end">Số dư đầu kỳ</th>
                                <th class="text-center">Khách hàng</th>
                                <th class="text-center">Hợp đồng</th>
                                <th class="text-center">Nhân viên</th>
                                <th class="text-center">Hành động</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stores as $store): ?>
                                <tr>
                                    <td><?= $store['id'] ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($store['name']) ?></strong>
                                    </td>
                                    <td><?= htmlspecialchars($store['address'] ?: '-') ?></td>
                                    <td><?= htmlspecialchars($store['phone'] ?: '-') ?></td>
                                    <td class="text-end"><?= number_format($store['initial_balance']) ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-info"><?= $store['customer_count'] ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-primary"><?= $store['loan_count'] ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary"><?= $store['user_count'] ?></span>
                                    </td>
                                    <td class="text-center">
                                        <a href="store_edit.php?id=<?= $store['id'] ?>"
                                            class="btn btn-sm btn-outline-primary" title="Sửa thông tin">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if ($store['customer_count'] == 0 && $store['loan_count'] == 0): ?>
                                            <form method="POST" style="display: inline;"
                                                onsubmit="return confirm('Bạn có chắc chắn muốn xóa cửa hàng này? Hành động này không thể hoàn tác!');">
                                                <input type="hidden" name="action" value="delete_store">
                                                <input type="hidden" name="store_id" value="<?= $store['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Xóa">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-secondary" disabled
                                                title="Không thể xóa vì còn dữ liệu">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (empty($stores)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-store fa-3x mb-3"></i>
                        <p>Chưa có cửa hàng nào. Bấm "Thêm cửa hàng" để tạo mới.</p>
                    </div>
                <?php endif; ?>

            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>