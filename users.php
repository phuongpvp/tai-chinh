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

// Fetch Users with Store Names (multiple stores via GROUP_CONCAT)
$sql = "SELECT u.*, 
        GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ') as store_names
        FROM users u 
        LEFT JOIN user_stores us ON u.id = us.user_id
        LEFT JOIN stores s ON us.store_id = s.id 
        GROUP BY u.id
        ORDER BY u.id ASC";
$stmt = $conn->prepare($sql);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý nhân viên</title>
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
                    <h1 class="h2">Quản lý nhân viên</h1>
                    <a href="user_add.php" class="btn btn-primary btn-sm"><i class="fas fa-user-plus"></i> Thêm nhân
                        viên</a>
                </div>

                <?php if (isset($_GET['msg'])): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($_GET['msg']); ?></div>
                <?php endif; ?>
                <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Tên đăng nhập</th>
                                <th>Họ tên</th>
                                <th>Vai trò</th>
                                <th>Cửa hàng phụ trách</th>
                                <th>Hành động</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <tr>
                                    <td><?php echo $u['id']; ?></td>
                                    <td><?php echo htmlspecialchars($u['username']); ?></td>
                                    <td><?php echo htmlspecialchars($u['fullname']); ?></td>
                                    <td>
                                        <?php if ($u['role'] == 'super_admin'): ?>
                                            <span class="badge bg-danger">Quản trị viên</span>
                                        <?php else: ?>
                                            <span class="badge bg-info text-dark">Nhân viên</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $store_display = $u['store_names'] ?? 'Chưa gán';
                                        if ($u['role'] == 'super_admin') {
                                            echo '<span class="text-muted">' . htmlspecialchars($store_display) . '</span>';
                                        } else {
                                            echo htmlspecialchars($store_display);
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <a href="user_edit.php?id=<?php echo $u['id']; ?>"
                                            class="btn btn-sm btn-outline-primary" title="Sửa thông tin"><i
                                                class="fas fa-edit"></i></a>
                                        <a href="user_permissions.php?id=<?php echo $u['id']; ?>"
                                            class="btn btn-sm btn-outline-success" title="Phân quyền"><i
                                                class="fas fa-user-shield"></i></a>
                                        <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                            <a href="user_delete.php?id=<?php echo $u['id']; ?>"
                                                class="btn btn-sm btn-outline-danger"
                                                onclick="return confirm('Bạn có chắc chắn muốn xóa nhân viên này?');"
                                                title="Xóa"><i class="fas fa-trash"></i></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>