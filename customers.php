<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

// Handle Add Customer
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add') {
    $name = $_POST['name'];
    $phone = $_POST['phone'];
    $address = $_POST['address'];
    $cmnd = $_POST['cmnd'];

    $stmt = $conn->prepare("INSERT INTO customers (name, phone, address, cmnd, store_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$name, $phone, $address, $cmnd, $current_store_id]);
    header("Location: customers.php?msg=" . urlencode("Thêm khách hàng thành công!"));
    exit;
}

// Handle Bulk Delete
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'bulk_delete') {
    if (isset($_POST['customer_ids']) && is_array($_POST['customer_ids'])) {
        $deleted = 0;
        $errors = [];

        foreach ($_POST['customer_ids'] as $id) {
            // Check for loans
            $stmt = $conn->prepare("SELECT COUNT(*) FROM loans WHERE customer_id = ?");
            $stmt->execute([$id]);
            $loan_count = $stmt->fetchColumn();

            if ($loan_count > 0) {
                $errors[] = "ID $id có hợp đồng vay";
                continue;
            }

            // Safe to delete
            $stmt = $conn->prepare("DELETE FROM customers WHERE id = ? AND store_id = ?");
            $stmt->execute([$id, $current_store_id]);
            $deleted++;
        }

        $msg = "Đã xóa $deleted khách hàng";
        if (count($errors) > 0) {
            $msg .= ". Không thể xóa: " . implode(", ", $errors);
        }
        header("Location: customers.php?msg=" . urlencode($msg));
        exit;
    }
}

// Fetch Customers
$sql = "SELECT * FROM customers WHERE store_id = ? ORDER BY id DESC";
$stmt = $conn->prepare($sql);
$stmt->execute([$current_store_id]);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Danh sách khách hàng - Trương Hưng</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="/style.css">
    <style>
        /* Page specific styles if any */
    </style>
</head>

<body>

    <!-- Header -->
    <?php include 'header.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include 'sidebar.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="index.php">Trang chủ</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Khách hàng</li>
                    </ol>
                </nav>

                <h4 class="mb-3 border-bottom pb-2">Danh sách khách hàng</h4>

                <?php if (isset($_GET['msg'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $_GET['msg']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <!-- Add Button trigger modal -->
                        <div class="d-flex gap-2 mb-3">
                            <button type="button" id="btnDeleteSelected" class="btn btn-danger" style="display: none;"
                                onclick="deleteSelected()">
                                <i class="fas fa-trash"></i> Xóa đã chọn
                            </button>
                        </div>

                        <form method="POST" id="bulkDeleteForm">
                            <input type="hidden" name="action" value="bulk_delete">
                            <div class="table-responsive">
                                <table class="table table-hover table-striped">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 40px;" class="text-center">
                                                <input type="checkbox" id="checkAll" class="form-check-input">
                                            </th>
                                            <th style="width: 50px;">STT</th>
                                            <th>Họ tên</th>
                                            <th>Điện thoại</th>
                                            <th>CMND/CCCD</th>
                                            <th>Địa chỉ</th>
                                            <th style="width: 120px;" class="text-center">Hành động</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($customers as $idx => $c): ?>
                                            <tr>
                                                <td class="text-center">
                                                    <input type="checkbox" name="customer_ids[]"
                                                        value="<?php echo $c['id']; ?>" class="form-check-input row-check">
                                                </td>
                                                <td class="text-center text-muted"><?php echo $idx + 1; ?></td>
                                                <td class="fw-bold text-primary"><?php echo $c['name']; ?></td>
                                                <td><?php echo $c['phone']; ?></td>
                                                <td><?php echo $c['cmnd']; ?></td>
                                                <td><?php echo $c['address']; ?></td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                                        onclick="editCustomer(<?php echo htmlspecialchars(json_encode($c)); ?>)"
                                                        title="Sửa">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="customer_delete.php?id=<?php echo $c['id']; ?>"
                                                        class="btn btn-sm btn-outline-danger"
                                                        onclick="return confirm('Bạn có chắc chắn muốn xóa khách hàng này? Tất cả dữ liệu liên quan sẽ mất!')"
                                                        title="Xóa">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal Add Customer -->
    <div class="modal fade" id="addCustomerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Thêm khách hàng mới</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="customers.php">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label class="form-label">Họ tên <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Số điện thoại</label>
                            <input type="text" name="phone" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">CMND/CCCD</label>
                            <input type="text" name="cmnd" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Địa chỉ</label>
                            <textarea name="address" class="form-control"></textarea>
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

    <!-- Modal Edit Customer -->
    <div class="modal fade" id="editCustomerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Sửa thông tin khách hàng</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="customer_edit.php">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="mb-3">
                            <label class="form-label">Họ tên <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="edit_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Số điện thoại</label>
                            <input type="text" name="phone" id="edit_phone" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">CMND/CCCD</label>
                            <input type="text" name="cmnd" id="edit_cmnd" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Địa chỉ</label>
                            <textarea name="address" id="edit_address" class="form-control"></textarea>
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
        function editCustomer(customer) {
            document.getElementById('edit_id').value = customer.id;
            document.getElementById('edit_name').value = customer.name;
            document.getElementById('edit_phone').value = customer.phone;
            document.getElementById('edit_cmnd').value = customer.cmnd;
            document.getElementById('edit_address').value = customer.address;

            var myModal = new bootstrap.Modal(document.getElementById('editCustomerModal'));
            myModal.show();
        }

        // Bulk delete functionality
        document.addEventListener('DOMContentLoaded', function () {
            const checkAll = document.getElementById('checkAll');
            const rowChecks = document.querySelectorAll('.row-check');
            const btnDelete = document.getElementById('btnDeleteSelected');

            function toggleButton() {
                let checkedCount = document.querySelectorAll('.row-check:checked').length;
                if (checkedCount > 0) {
                    btnDelete.style.display = 'inline-block';
                } else {
                    btnDelete.style.display = 'none';
                }
            }

            checkAll.addEventListener('change', function () {
                rowChecks.forEach(cb => {
                    cb.checked = checkAll.checked;
                });
                toggleButton();
            });

            rowChecks.forEach(cb => {
                cb.addEventListener('change', toggleButton);
            });
        });

        function deleteSelected() {
            const checkedCount = document.querySelectorAll('.row-check:checked').length;
            if (checkedCount === 0) {
                alert('Vui lòng chọn ít nhất một khách hàng để xóa!');
                return;
            }

            if (confirm(`Bạn có chắc chắn muốn xóa ${checkedCount} khách hàng đã chọn? Hành động này không thể hoàn tác!`)) {
                document.getElementById('bulkDeleteForm').submit();
            }
        }
    </script>
</body>

</html>