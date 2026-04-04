<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';
require_once 'permissions_helper.php';

// Check permission
if (!hasPermission($conn, 'shareholders.view')) {
    die("Bạn không có quyền truy cập trang này!");
}

// Handle Add Shareholder
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add') {
    if (!hasPermission($conn, 'shareholders.add')) {
        die("Bạn không có quyền thêm cổ đông!");
    }

    $shareholder_code = trim($_POST['shareholder_code']);
    $name = trim($_POST['name']);
    $capital_amount = str_replace([',', '.'], '', $_POST['capital_amount']);

    // Insert shareholder
    $stmt = $conn->prepare("INSERT INTO shareholders (store_id, shareholder_code, name, capital_amount) VALUES (?, ?, ?, ?)");
    $stmt->execute([$current_store_id, $shareholder_code, $name, $capital_amount]);

    // Recalculate percentages for all shareholders in this store
    recalculatePercentages($conn, $current_store_id);

    header("Location: shareholders.php?msg=" . urlencode("Thêm cổ đông thành công!"));
    exit;
}

// Handle Delete Shareholder
if (isset($_GET['delete_id'])) {
    if (!hasPermission($conn, 'shareholders.delete')) {
        die("Bạn không có quyền xóa cổ đông!");
    }

    $id = $_GET['delete_id'];

    // Check if shareholder has dividend history
    $stmt = $conn->prepare("SELECT COUNT(*) FROM dividend_details WHERE shareholder_id = ?");
    $stmt->execute([$id]);
    $has_history = $stmt->fetchColumn() > 0;

    if ($has_history) {
        header("Location: shareholders.php?error=" . urlencode("Không thể xóa cổ đông đã có lịch sử chia cổ tức!"));
        exit;
    }

    // Safe to delete
    $stmt = $conn->prepare("DELETE FROM shareholders WHERE id = ? AND store_id = ?");
    $stmt->execute([$id, $current_store_id]);

    // Recalculate percentages
    recalculatePercentages($conn, $current_store_id);

    header("Location: shareholders.php?msg=" . urlencode("Xóa cổ đông thành công!"));
    exit;
}

// Function to recalculate percentages
function recalculatePercentages($conn, $store_id)
{
    // Get total capital
    $stmt = $conn->prepare("SELECT SUM(capital_amount) FROM shareholders WHERE store_id = ?");
    $stmt->execute([$store_id]);
    $total = $stmt->fetchColumn();

    if ($total > 0) {
        // Update each shareholder's percentage
        $stmt = $conn->prepare("SELECT id, capital_amount FROM shareholders WHERE store_id = ?");
        $stmt->execute([$store_id]);
        $shareholders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $updateStmt = $conn->prepare("UPDATE shareholders SET percentage = ? WHERE id = ?");
        foreach ($shareholders as $sh) {
            $percentage = ($sh['capital_amount'] / $total) * 100;
            $updateStmt->execute([$percentage, $sh['id']]);
        }
    }
}

// Fetch Shareholders
$sql = "SELECT * FROM shareholders WHERE store_id = ? ORDER BY percentage DESC, id ASC";
$stmt = $conn->prepare($sql);
$stmt->execute([$current_store_id]);
$shareholders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_capital = array_sum(array_column($shareholders, 'capital_amount'));
$total_percentage = array_sum(array_column($shareholders, 'percentage'));
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý cổ đông - Trương Hưng</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="/style.css">
</head>

<body>

    <!-- Header -->
    <?php include 'header.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include 'sidebar.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content" style="padding-top: 80px !important;">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="index.php">Trang chủ</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Quản lý cổ đông</li>
                    </ol>
                </nav>

                <h4 class="mb-3 border-bottom pb-2"><i class="fas fa-users"></i> Danh sách cổ đông</h4>

                <?php if (isset($_GET['msg'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($_GET['msg']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($_GET['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <!-- Add Button -->
                        <?php if (hasPermission($conn, 'shareholders.add')): ?>
                            <div class="mb-3">
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal"
                                    data-bs-target="#addShareholderModal">
                                    <i class="fas fa-user-plus"></i> Thêm cổ đông
                                </button>
                            </div>
                        <?php endif; ?>

                        <div class="table-responsive">
                            <table class="table table-hover table-striped">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 50px;">STT</th>
                                        <th>Mã CĐ</th>
                                        <th>Tên cổ đông</th>
                                        <th class="text-end">Vốn góp</th>
                                        <th class="text-end">Tỷ lệ %</th>
                                        <th style="width: 120px;" class="text-center">Hành động</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($shareholders) > 0): ?>
                                        <?php foreach ($shareholders as $idx => $sh): ?>
                                            <tr>
                                                <td class="text-center text-muted">
                                                    <?php echo $idx + 1; ?>
                                                </td>
                                                <td class="fw-bold text-primary">
                                                    <?php echo htmlspecialchars($sh['shareholder_code']); ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($sh['name']); ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php echo number_format($sh['capital_amount']); ?> đ
                                                </td>
                                                <td class="text-end fw-bold text-success">
                                                    <?php echo number_format($sh['percentage'], 2); ?>%
                                                </td>
                                                <td class="text-center">
                                                    <?php if (hasPermission($conn, 'shareholders.edit')): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                            onclick="editShareholder(<?php echo htmlspecialchars(json_encode($sh)); ?>)"
                                                            title="Sửa">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (hasPermission($conn, 'shareholders.delete')): ?>
                                                        <a href="shareholders.php?delete_id=<?php echo $sh['id']; ?>"
                                                            class="btn btn-sm btn-outline-danger"
                                                            onclick="return confirm('Bạn có chắc chắn muốn xóa cổ đông này?')"
                                                            title="Xóa">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">
                                                <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                                                Chưa có cổ đông nào
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <?php if (count($shareholders) > 0): ?>
                                    <tfoot class="table-light fw-bold">
                                        <tr>
                                            <td colspan="3" class="text-end">Tổng cộng:</td>
                                            <td class="text-end text-primary">
                                                <?php echo number_format($total_capital); ?> đ
                                            </td>
                                            <td class="text-end text-success">
                                                <?php echo number_format($total_percentage, 2); ?>%
                                            </td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal Add Shareholder -->
    <div class="modal fade" id="addShareholderModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Thêm cổ đông mới</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="shareholders.php">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label class="form-label">Mã cổ đông <span class="text-danger">*</span></label>
                            <input type="text" name="shareholder_code" class="form-control" placeholder="VD: A, B, C..."
                                required>
                            <small class="text-muted">Mã chung để nhận diện cổ đông ở nhiều cửa hàng</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tên cổ đông <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="VD: Nguyễn Văn A" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Số vốn góp <span class="text-danger">*</span></label>
                            <input type="text" name="capital_amount" class="form-control number-separator"
                                placeholder="VD: 1.000.000" required>
                            <small class="text-muted">Tỷ lệ % sẽ tự động tính dựa trên tổng vốn</small>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                            <button type="submit" class="btn btn-primary">Lưu</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Edit Shareholder -->
    <div class="modal fade" id="editShareholderModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Sửa thông tin cổ đông</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="shareholder_edit.php">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="mb-3">
                            <label class="form-label">Mã cổ đông <span class="text-danger">*</span></label>
                            <input type="text" name="shareholder_code" id="edit_shareholder_code" class="form-control"
                                required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tên cổ đông <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="edit_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Số vốn góp <span class="text-danger">*</span></label>
                            <input type="text" name="capital_amount" id="edit_capital_amount"
                                class="form-control number-separator" required>
                            <small class="text-muted">Tỷ lệ % sẽ tự động cập nhật khi thay đổi</small>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                            <button type="submit" class="btn btn-primary">Cập nhật</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editShareholder(shareholder) {
            document.getElementById('edit_id').value = shareholder.id;
            document.getElementById('edit_shareholder_code').value = shareholder.shareholder_code;
            document.getElementById('edit_name').value = shareholder.name;
            document.getElementById('edit_capital_amount').value = new Intl.NumberFormat('vi-VN').format(shareholder.capital_amount);

            var myModal = new bootstrap.Modal(document.getElementById('editShareholderModal'));
            myModal.show();
        }

        // Number separator for input fields
        document.addEventListener('DOMContentLoaded', function () {
            const separators = document.querySelectorAll('.number-separator');
            separators.forEach(input => {
                input.addEventListener('input', function (e) {
                    let value = e.target.value;
                    value = value.replace(/[^0-9]/g, '');

                    if (value) {
                        const intVal = parseInt(value, 10);
                        e.target.value = new Intl.NumberFormat('vi-VN').format(intVal);
                    } else {
                        e.target.value = '';
                    }
                });
            });
        });
    </script>
</body>

</html>