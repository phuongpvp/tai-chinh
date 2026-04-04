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

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_expense') {
    $receiver = trim($_POST['receiver']);
    $amount = str_replace([',', '.'], '', $_POST['amount']);
    $category = $_POST['category'];
    $note = trim($_POST['note']);

    if (empty($receiver) || empty($amount)) {
        $error_msg = "Vui lòng nhập đầy đủ Người nhận và Số tiền";
    } else {
        try {
            $stmt = $conn->prepare("INSERT INTO other_transactions (store_id, user_id, type, receiver_payer, amount, category, note) VALUES (?, ?, 'expense', ?, ?, ?, ?)");
            $stmt->execute([$current_store_id, $_SESSION['user_id'], $receiver, $amount, $category, $note]);
            $success_msg = "Ghi nhận phiếu chi thành công!";
        } catch (Exception $e) {
            $error_msg = "Lỗi: " . $e->getMessage();
        }
    }
}

// Handle Delete
if (isset($_GET['delete_id'])) {
    try {
        $stmt = $conn->prepare("DELETE FROM other_transactions WHERE id = ? AND store_id = ? AND type = 'expense'");
        $stmt->execute([$_GET['delete_id'], $current_store_id]);
        header("Location: expenses.php?msg=" . urlencode("Đã xóa phiếu chi"));
        exit;
    } catch (Exception $e) {
        $error_msg = "Lỗi khi xóa: " . $e->getMessage();
    }
}

// Filters
$from_date = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$filter_category = $_GET['category'] ?? '';

// Fetch History
$where = "WHERE t.store_id = ? AND t.type = 'expense' AND DATE(t.created_at) BETWEEN ? AND ?";
$params = [$current_store_id, $from_date, $to_date];

if ($filter_category) {
    $where .= " AND category = ?";
    $params[] = $filter_category;
}

try {
    $sql = "SELECT t.*, u.username as staff_name 
            FROM other_transactions t 
            LEFT JOIN users u ON t.user_id = u.id 
            $where 
            ORDER BY t.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $history = [];
    $error_msg = "Lỗi hệ thống hoặc thiếu bảng dữ liệu: " . $e->getMessage();
}

$total_expense = 0;
foreach ($history as $item)
    $total_expense += $item['amount'];

?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chi hoạt động</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="/style.css">
</head>

<body>
    <?php include 'header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div
                    class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h4 text-danger">Cửa hàng :
                        <?php echo htmlspecialchars($_SESSION['store_name'] ?? 'Công ty 1'); ?>
                    </h1>
                    <div class="text-muted small">
                        <?php echo date('d-m-Y'); ?>
                    </div>
                </div>

                <?php if ($success_msg || isset($_GET['msg'])): ?>
                    <div class="alert alert-success">
                        <?php echo $success_msg ?: htmlspecialchars($_GET['msg']); ?>
                    </div>
                <?php endif; ?>
                <?php if ($error_msg): ?>
                    <div class="alert alert-danger">
                        <?php echo $error_msg; ?>
                    </div>
                <?php endif; ?>

                <!-- Form Section -->
                <div class="card shadow-sm mb-4 border-0">
                    <div class="card-body bg-light rounded">
                        <form method="POST" class="row g-3 justify-content-center">
                            <input type="hidden" name="action" value="add_expense">
                            <div class="col-md-8">
                                <div class="row mb-2">
                                    <label class="col-sm-3 col-form-label text-end fw-bold small">Người nhận tiền
                                        *</label>
                                    <div class="col-sm-9">
                                        <input type="text" name="receiver" class="form-control form-control-sm"
                                            required>
                                    </div>
                                </div>
                                <div class="row mb-2">
                                    <label class="col-sm-3 col-form-label text-end fw-bold small">Số tiền *</label>
                                    <div class="col-sm-9">
                                        <input type="text" name="amount" id="expense_amount"
                                            class="form-control form-control-sm fw-bold text-danger" value="0" required>
                                    </div>
                                </div>
                                <div class="row mb-2">
                                    <label class="col-sm-3 col-form-label text-end fw-bold small">Loại phiếu *</label>
                                    <div class="col-sm-9">
                                        <select name="category" class="form-select form-select-sm">
                                            <option value="Chi khác">Chi khác</option>
                                            <option value="Tiền lương">Tiền lương</option>
                                            <option value="Tiền mặt bằng">Tiền mặt bằng</option>
                                            <option value="Tiền điện/nước">Tiền điện/nước</option>
                                            <option value="Cổ đông">Cổ đông</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="row mb-2">
                                    <label class="col-sm-3 col-form-label text-end fw-bold small">Lý do chi tiền
                                        *</label>
                                    <div class="col-sm-9">
                                        <textarea name="note" class="form-control form-control-sm" rows="3"></textarea>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <button type="submit" class="btn btn-sm btn-info text-white px-4 fw-bold">Chi
                                        tiền</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- History Section -->
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white fw-bold py-3">
                        <i class="fas fa-calendar-alt me-2"></i> Lịch sử chi tiêu
                    </div>
                    <div class="card-body p-0">
                        <!-- Filters -->
                        <div class="p-3 bg-light border-bottom">
                            <form method="GET" class="row g-2 align-items-end">
                                <div class="col-auto">
                                    <label class="small fw-bold">Từ Ngày</label>
                                    <input type="date" name="from_date" class="form-control form-control-sm"
                                        value="<?php echo $from_date; ?>">
                                </div>
                                <div class="col-auto">
                                    <label class="small fw-bold">Đến Ngày</label>
                                    <input type="date" name="to_date" class="form-control form-control-sm"
                                        value="<?php echo $to_date; ?>">
                                </div>
                                <div class="col-auto">
                                    <label class="small fw-bold">Loại phiếu</label>
                                    <select name="category" class="form-select form-select-sm">
                                        <option value="">Tất cả</option>
                                        <option value="Chi khác" <?php echo ($filter_category == 'Chi khác') ? 'selected' : ''; ?>>Chi khác</option>
                                        <option value="Tiền lương" <?php echo ($filter_category == 'Tiền lương') ? 'selected' : ''; ?>>Tiền lương</option>
                                        <option value="Tiền mặt bằng" <?php echo ($filter_category == 'Tiền mặt bằng') ? 'selected' : ''; ?>>Tiền mặt bằng</option>
                                        <option value="Tiền điện/nước" <?php echo ($filter_category == 'Tiền điện/nước') ? 'selected' : ''; ?>>Tiền điện/nước</option>
                                        <option value="Cổ đông" <?php echo ($filter_category == 'Cổ đông') ? 'selected' : ''; ?>>Cổ đông</option>
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <button type="submit"
                                        class="btn btn-sm btn-info text-white px-3 fw-bold">Tìm</button>
                                </div>
                            </form>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0 small">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-center" width="50">STT</th>
                                        <th class="text-center">Ngày</th>
                                        <th class="text-center">Nhân viên</th>
                                        <th class="text-center">Khách hàng</th>
                                        <th class="text-center">Loại phiếu</th>
                                        <th class="text-end">Số tiền</th>
                                        <th class="text-center">Ghi chú</th>
                                        <th class="text-center" width="80">Hành động</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($history)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4 text-muted">Không có dữ liệu chi tiêu
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($history as $idx => $row): ?>
                                            <tr>
                                                <td class="text-center">
                                                    <?php echo $idx + 1; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php echo date('H:i d/m/Y', strtotime($row['created_at'])); ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php echo htmlspecialchars($row['staff_name']); ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php echo htmlspecialchars($row['receiver_payer']); ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php echo htmlspecialchars($row['category']); ?>
                                                </td>
                                                <td class="text-end fw-bold text-danger">-
                                                    <?php echo number_format($row['amount']); ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php echo htmlspecialchars($row['note']); ?>
                                                </td>
                                                <td class="text-center">
                                                    <a href="#" class="text-secondary me-2"><i class="fas fa-print"></i></a>
                                                    <a href="expenses.php?delete_id=<?php echo $row['id']; ?>"
                                                        class="text-danger" onclick="return confirm('Xóa phiếu chi này?')"><i
                                                            class="fas fa-trash"></i></a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr class="fw-bold bg-light">
                                            <td colspan="5" class="text-end">Tổng</td>
                                            <td class="text-end text-danger">-
                                                <?php echo number_format($total_expense); ?>
                                            </td>
                                            <td colspan="2"></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto format amount
        const amountInput = document.getElementById('expense_amount');
        if (amountInput) {
            amountInput.addEventListener('input', function (e) {
                let val = e.target.value.replace(/[^0-9]/g, '');
                if (val) {
                    e.target.value = new Intl.NumberFormat('vi-VN').format(parseInt(val));
                } else {
                    e.target.value = '0';
                }
            });
            // Focus select all
            amountInput.addEventListener('focus', function () { this.select(); });
        }
    </script>
</body>

</html>