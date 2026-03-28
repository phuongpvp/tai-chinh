<?php
require_once 'config.php';
requireLogin();
requireRole('admin');

$pageTitle = 'Quản lý nhân viên';
$activePage = 'users';
$user = cvGetUser();

// Xử lý actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add_user':
            $username = trim($_POST['username'] ?? '');
            $fullName = trim($_POST['fullname'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'employee';

            if ($username && $fullName && $password) {
                // Check trùng username
                $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $check->execute([$username]);
                if ($check->fetch()) {
                    $_SESSION['flash_message'] = 'Tên đăng nhập đã tồn tại!';
                    $_SESSION['flash_type'] = 'error';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, fullname, role, cv_role) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$username, $hash, $fullName, $role, $role]);
                    $_SESSION['flash_message'] = 'Đã thêm nhân viên: ' . $fullName;
                }
                redirect('users.php');
            }
            break;

        case 'update_user':
            $uid = intval($_POST['user_id'] ?? 0);
            $fullName = trim($_POST['fullname'] ?? '');
            $role = $_POST['role'] ?? 'employee';
            $password = $_POST['password'] ?? '';
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $cvRole = $isActive ? $role : null;

            if ($uid && $fullName) {
                if ($password) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET fullname=?, role=?, password=?, cv_role=? WHERE id=?");
                    $stmt->execute([$fullName, $role, $hash, $cvRole, $uid]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET fullname=?, role=?, cv_role=? WHERE id=?");
                    $stmt->execute([$fullName, $role, $cvRole, $uid]);
                }
                $_SESSION['flash_message'] = 'Đã cập nhật nhân viên';
                redirect('users.php');
            }
            break;

        case 'delete_user':
            $uid = intval($_POST['user_id'] ?? 0);
            if ($uid && $uid !== $user['id']) {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$uid]);
                $_SESSION['flash_message'] = 'Đã xóa nhân viên';
            }
            redirect('users.php');
            break;
    }
}

// Lấy danh sách nhân viên
$employees = $pdo->query("SELECT * FROM users ORDER BY role, fullname")->fetchAll();

include 'layout_top.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><span class="page-icon">👥</span>Quản lý nhân viên</h1>
        <p class="page-subtitle">Tổng <?= count($employees) ?> tài khoản</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" onclick="openModal('add-user-modal')">➕ Thêm nhân viên</button>
    </div>
</div>

<div class="page-body">
    <table class="data-table">
        <thead>
            <tr>
                <th>Nhân viên</th>
                <th>Tên đăng nhập</th>
                <th>Vai trò</th>
                <th>Trạng thái</th>
                <th>Ngày tạo</th>
                <th style="text-align:right">Thao tác</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($employees as $emp): 
                $initial = mb_substr($emp['fullname'] ?? '?', 0, 1, 'UTF-8');
                $roleClass = 'badge-' . $emp['role'];
                $roleLabel = ['admin'=>'Admin','manager'=>'Quản lý','employee'=>'Nhân viên','super_admin'=>'Quản trị viên','store_admin'=>'Quản lý CH','store_staff'=>'Nhân viên'][$emp['role']] ?? $emp['role'];
            ?>
            <tr>
                <td>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div class="user-avatar"><?= $initial ?></div>
                        <strong><?= sanitize($emp['fullname']) ?></strong>
                    </div>
                </td>
                <td style="color:var(--text-muted)"><?= sanitize($emp['username']) ?></td>
                <td><span class="badge <?= $roleClass ?>"><?= $roleLabel ?></span></td>
                <td>
                    <?php if (!empty($emp['cv_role'])): ?>
                        <span style="color:var(--status-safe)">● Hoạt động</span>
                    <?php else: ?>
                        <span style="color:var(--status-neutral)">● Vô hiệu</span>
                    <?php endif; ?>
                </td>
                <td style="color:var(--text-muted)"><?= date('d/m/Y', strtotime($emp['created_at'])) ?></td>
                <td style="text-align:right">
                    <button class="btn btn-ghost btn-sm" onclick='editUser(<?= json_encode($emp) ?>)'>✏️</button>
                    <?php if ($emp['id'] !== $user['id']): ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Xóa nhân viên này?')">
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="user_id" value="<?= $emp['id'] ?>">
                            <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--accent-red)">🗑️</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- MODAL: Thêm nhân viên -->
<div class="modal-overlay" id="add-user-modal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">➕ Thêm nhân viên mới</h3>
            <button class="modal-close" onclick="closeModal('add-user-modal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_user">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Họ và tên *</label>
                    <input type="text" name="fullname" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Tên đăng nhập *</label>
                    <input type="text" name="username" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Mật khẩu *</label>
                    <input type="password" name="password" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Vai trò</label>
                    <select name="role" class="form-select">
                        <option value="employee">Nhân viên</option>
                        <option value="manager">Quản lý</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('add-user-modal')">Hủy</button>
                <button type="submit" class="btn btn-primary">Thêm →</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Sửa nhân viên -->
<div class="modal-overlay" id="edit-user-modal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">✏️ Sửa thông tin nhân viên</h3>
            <button class="modal-close" onclick="closeModal('edit-user-modal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="user_id" id="edit-user-id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Họ và tên *</label>
                    <input type="text" name="fullname" id="edit-full-name" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Mật khẩu mới (để trống nếu không đổi)</label>
                    <input type="password" name="password" class="form-input" placeholder="Để trống nếu không đổi">
                </div>
                <div class="form-group">
                    <label class="form-label">Vai trò</label>
                    <select name="role" id="edit-role" class="form-select">
                        <option value="employee">Nhân viên</option>
                        <option value="manager">Quản lý</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" name="is_active" id="edit-is-active" value="1">
                        <span class="form-label" style="margin:0">Đang hoạt động</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('edit-user-modal')">Hủy</button>
                <button type="submit" class="btn btn-primary">💾 Lưu thay đổi</button>
            </div>
        </form>
    </div>
</div>

<script>
function editUser(u) {
    document.getElementById('edit-user-id').value = u.id;
    document.getElementById('edit-full-name').value = u.fullname;
    document.getElementById('edit-role').value = u.role;
    document.getElementById('edit-is-active').checked = !!u.cv_role;
    openModal('edit-user-modal');
}
</script>

<?php include 'layout_bottom.php'; ?>
