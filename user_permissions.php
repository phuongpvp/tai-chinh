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

$user_id = $_GET['id'] ?? 0;
if (!$user_id) {
    header("Location: users.php");
    exit();
}

// Fetch User
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: users.php?error=" . urlencode("Không tìm thấy nhân viên!"));
    exit;
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $selected_permissions = $_POST['permissions'] ?? [];
    
    try {
        // Delete all current permissions
        $conn->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute([$user_id]);
        
        // Insert new permissions
        if (!empty($selected_permissions)) {
            $stmt = $conn->prepare("INSERT INTO user_permissions (user_id, permission_id) VALUES (?, ?)");
            foreach ($selected_permissions as $perm_id) {
                $stmt->execute([$user_id, $perm_id]);
            }
        }

        // Update CV role
        $cv_role = $_POST['cv_role'] ?? '';
        $cv_role = in_array($cv_role, ['admin', 'manager', 'employee']) ? $cv_role : null;
        $conn->prepare("UPDATE users SET cv_role = ? WHERE id = ?")->execute([$cv_role, $user_id]);
        // Refresh user data
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $success = "Cập nhật phân quyền thành công!";
    } catch (Exception $e) {
        $error = "Lỗi: " . $e->getMessage();
    }
}

// Fetch all permissions grouped by category
$stmt = $conn->query("SELECT * FROM permissions ORDER BY category, id");
$all_permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by category
$grouped_permissions = [];
foreach ($all_permissions as $perm) {
    $grouped_permissions[$perm['category']][] = $perm;
}

// Fetch user's current permissions
$stmt = $conn->prepare("SELECT permission_id FROM user_permissions WHERE user_id = ?");
$stmt->execute([$user_id]);
$user_permission_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Category names
$category_names = [
    'contracts' => 'Tín Chấp',
    'customers' => 'Khách Hàng',
    'reports' => 'Báo Cáo',
    'expenses' => 'Chi Hoạt Động',
    'incomes' => 'Thu Hoạt Động',
    'stores' => 'Quản Lý Cửa Hàng',
    'users' => 'Quản Lý Nhân Viên',
];
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phân quyền: <?php echo htmlspecialchars($user['fullname']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="/style.css">
    <style>
        .permission-category {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .permission-category h5 {
            color: #495057;
            font-weight: bold;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #dee2e6;
        }

        .permission-item {
            padding: 8px 12px;
            margin-bottom: 8px;
            background: white;
            border-radius: 5px;
            border: 1px solid #e9ecef;
            transition: all 0.2s;
        }

        .permission-item:hover {
            background: #f0f8ff;
            border-color: #007bff;
        }

        .permission-item label {
            cursor: pointer;
            margin: 0;
            display: flex;
            align-items: center;
        }

        .permission-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-right: 10px;
            cursor: pointer;
        }

        .select-all-btn {
            font-size: 0.85rem;
            padding: 4px 12px;
        }
    </style>
</head>

<body>
    <?php include 'header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-user-shield me-2"></i>
                        Phân quyền: <?php echo htmlspecialchars($user['fullname']); ?>
                    </h1>
                    <a href="users.php" class="btn btn-secondary btn-sm">
                        <i class="fas fa-arrow-left me-1"></i>Quay lại
                    </a>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-1"></i> <?php echo $success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-1"></i> <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <form method="POST">
                            <!-- CV ROLE SECTION -->
                            <div class="permission-category" style="border-left:3px solid #f59e0b;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5>
                                        <i class="fas fa-briefcase me-2" style="color:#f59e0b;"></i>
                                        Công Việc (CV)
                                    </h5>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="permission-item">
                                            <label style="flex-direction:column;align-items:flex-start;gap:6px;">
                                                <span><i class="fas fa-user-tag me-1"></i> Vai trò bên Công Việc</span>
                                                <select name="cv_role" class="form-select form-select-sm" style="width:100%;">
                                                    <option value="employee" <?= ($user['cv_role'] ?? '') === 'employee' ? 'selected' : '' ?>>👤 Nhân viên</option>
                                                    <option value="admin" <?= ($user['cv_role'] ?? '') === 'admin' ? 'selected' : '' ?>>🔑 Admin</option>
                                                </select>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php foreach ($grouped_permissions as $category => $permissions): ?>
                                <div class="permission-category">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h5>
                                            <i class="fas fa-folder-open me-2"></i>
                                            <?php echo $category_names[$category] ?? $category; ?>
                                        </h5>
                                        <button type="button" class="btn btn-sm btn-outline-primary select-all-btn" 
                                                onclick="toggleCategory('<?php echo $category; ?>')">
                                            <i class="fas fa-check-double me-1"></i>Chọn tất cả
                                        </button>
                                    </div>
                                    
                                    <div class="row">
                                        <?php foreach ($permissions as $perm): ?>
                                            <div class="col-md-6">
                                                <div class="permission-item">
                                                    <label>
                                                        <input type="checkbox" 
                                                               name="permissions[]" 
                                                               value="<?php echo $perm['id']; ?>"
                                                               class="category-<?php echo $category; ?>"
                                                               <?php echo in_array($perm['id'], $user_permission_ids) ? 'checked' : ''; ?>>
                                                        <span><?php echo htmlspecialchars($perm['display_name']); ?></span>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <div class="d-flex justify-content-end gap-2 mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i>Lưu phân quyền
                                </button>
                                <a href="users.php" class="btn btn-secondary">Hủy</a>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        function toggleCategory(category) {
            const checkboxes = document.querySelectorAll('.category-' + category);
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            
            checkboxes.forEach(cb => {
                cb.checked = !allChecked;
            });
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>