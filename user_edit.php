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
// Fetch User
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: users.php?error=" . urlencode("Không tìm thấy nhân viên!"));
    exit;
}

// Fetch Stores
$stmt_stores = $conn->query("SELECT id, name FROM stores ORDER BY name");
$stores = $stmt_stores->fetchAll(PDO::FETCH_ASSOC);

// Fetch currently assigned stores
$stmt_assigned = $conn->prepare("SELECT store_id FROM user_stores WHERE user_id = ?");
$stmt_assigned->execute([$id]);
$assigned_stores = $stmt_assigned->fetchAll(PDO::FETCH_COLUMN);

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fullname = trim($_POST['fullname']);
    $role = $_POST['role'];
    $store_ids = $_POST['store_ids'] ?? [];
    $password = $_POST['password']; // Optional

    if (empty($fullname)) {
        $error = "Vui lòng nhập họ tên";
    } elseif (empty($store_ids)) {
        $error = "Vui lòng chọn ít nhất một cửa hàng";
    } else {
        try {
            if (!empty($password)) {
                // Update with password
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET fullname = ?, role = ?, store_id = ?, password = ? WHERE id = ?");
                $stmt->execute([$fullname, $role, $store_ids[0], $hashed, $id]);
            } else {
                // Update without password
                $stmt = $conn->prepare("UPDATE users SET fullname = ?, role = ?, store_id = ? WHERE id = ?");
                $stmt->execute([$fullname, $role, $store_ids[0], $id]);
            }

            // Update user-store relationships
            $conn->prepare("DELETE FROM user_stores WHERE user_id = ?")->execute([$id]);
            $stmt_user_store = $conn->prepare("INSERT INTO user_stores (user_id, store_id) VALUES (?, ?)");
            foreach ($store_ids as $store_id) {
                $stmt_user_store->execute([$id, $store_id]);
            }

            header("Location: users.php?msg=" . urlencode("Cập nhật nhân viên thành công!"));
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
    <title>Sửa thông tin nhân viên</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <?php include 'header.php'; ?>
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Sửa thông tin:
                            <?php echo htmlspecialchars($user['username']); ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <?php echo $error; ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Họ và tên <span class="text-danger">*</span></label>
                                <input type="text" name="fullname" class="form-control"
                                    value="<?php echo htmlspecialchars($user['fullname']); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Đổi mật khẩu</label>
                                <input type="password" name="password" class="form-control"
                                    placeholder="Để trống nếu không đổi">
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Vai trò</label>
                                <select name="role" class="form-select">
                                    <option value="store_staff" <?php echo ($user['role'] == 'store_staff') ? 'selected' : ''; ?>>Nhân viên cửa hàng</option>
                                    <option value="super_admin" <?php echo ($user['role'] == 'super_admin') ? 'selected' : ''; ?>>Quản trị viên (Super Admin)</option>
                                </select>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold">Cửa hàng phụ trách <span
                                        class="text-danger">*</span></label>
                                <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                                    <?php foreach ($stores as $store): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="store_ids[]"
                                                value="<?php echo $store['id']; ?>" id="store_<?php echo $store['id']; ?>"
                                                <?php echo in_array($store['id'], $assigned_stores) ? 'checked' : ''; ?>>
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
                                <button type="submit" class="btn btn-primary">Cập nhật</button>
                                <a href="users.php" class="btn btn-secondary">Hủy</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>