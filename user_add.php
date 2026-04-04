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

// Fetch Stores
$stmt = $conn->query("SELECT id, name FROM stores ORDER BY name");
$stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $fullname = trim($_POST['fullname']);
    $role = $_POST['role'];
    $store_ids = $_POST['store_ids'] ?? [];

    if (empty($username) || empty($password) || empty($fullname)) {
        $error = "Vui lòng nhập đầy đủ thông tin bắt buộc";
    } elseif (empty($store_ids)) {
        $error = "Vui lòng chọn ít nhất một cửa hàng";
    } else {
        // Check username exists
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt_check->execute([$username]);
        if ($stmt_check->rowCount() > 0) {
            $error = "Tên đăng nhập đã tồn tại";
        } else {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            try {
                // Insert user (store_id kept for backward compatibility, use first selected store)
                $stmt = $conn->prepare("INSERT INTO users (username, password, fullname, role, store_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$username, $hashed_password, $fullname, $role, $store_ids[0]]);
                $user_id = $conn->lastInsertId();

                // Insert user-store relationships
                $stmt_user_store = $conn->prepare("INSERT INTO user_stores (user_id, store_id) VALUES (?, ?)");
                foreach ($store_ids as $store_id) {
                    $stmt_user_store->execute([$user_id, $store_id]);
                }

                header("Location: users.php?msg=" . urlencode("Thêm nhân viên thành công!"));
                exit;
            } catch (Exception $e) {
                $error = "Lỗi hệ thống: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thêm nhân viên mới</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/style.css">
</head>

<body>
    <?php include 'header.php'; ?>
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Thêm nhân viên mới</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <?php echo $error; ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Tên đăng nhập <span
                                        class="text-danger">*</span></label>
                                <input type="text" name="username" class="form-control" required>
                                <div class="form-text">Dùng để đăng nhập hệ thống</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Họ và tên <span class="text-danger">*</span></label>
                                <input type="text" name="fullname" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Mật khẩu <span class="text-danger">*</span></label>
                                <input type="password" name="password" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Vai trò</label>
                                <select name="role" class="form-select" id="roleSelect">
                                    <option value="store_staff">Nhân viên cửa hàng</option>
                                    <option value="super_admin">Quản trị viên (Super Admin)</option>
                                </select>
                            </div>

                            <div class="mb-4" id="storeSelectDiv">
                                <label class="form-label fw-bold">Cửa hàng phụ trách <span
                                        class="text-danger">*</span></label>
                                <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                                    <?php foreach ($stores as $store): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="store_ids[]"
                                                value="<?php echo $store['id']; ?>" id="store_<?php echo $store['id']; ?>">
                                            <label class="form-check-label" for="store_<?php echo $store['id']; ?>">
                                                <?php echo htmlspecialchars($store['name']); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="form-text">Chọn một hoặc nhiều cửa hàng mà nhân viên này có quyền truy cập
                                </div>
                            </div>

                            <div class="d-flex justify-content-end gap-2">
                                <button type="submit" class="btn btn-primary">Lưu nhân viên</button>
                                <a href="users.php" class="btn btn-secondary">Hủy</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle Store Select based on Role (Optional: Super Admin accesses all, but usually assigned to a default store or NULL if schema allows. Our schema defaults 1, so we let them choose "Base" store or just ignore)
        // Actually, Super Admin role overrides store_id usage in config.php logic (can switch).
        // So store_id for Super Admin is just their "Home" store.
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>