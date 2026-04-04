<?php
require_once 'config.php';
requireLogin();

$pageTitle = 'Thông tin cá nhân';
$activePage = 'profile';
$user = cvGetUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['fullname'] ?? '');
    $currentPass = $_POST['current_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    // Lấy user hiện tại từ DB
    $dbUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $dbUser->execute([$user['id']]);
    $dbUser = $dbUser->fetch();

    if ($fullName) {
        $stmt = $pdo->prepare("UPDATE users SET fullname = ? WHERE id = ?");
        $stmt->execute([$fullName, $user['id']]);
        $_SESSION['fullname'] = $fullName;
    }

    // Đổi mật khẩu
    if ($newPass) {
        if (!password_verify($currentPass, $dbUser['password'])) {
            $_SESSION['flash_message'] = 'Mật khẩu hiện tại không đúng!';
            $_SESSION['flash_type'] = 'error';
            redirect('/cong-viec/ho-so');
        }
        if ($newPass !== $confirmPass) {
            $_SESSION['flash_message'] = 'Mật khẩu mới không khớp!';
            $_SESSION['flash_type'] = 'error';
            redirect('/cong-viec/ho-so');
        }
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hash, $user['id']]);
    }

    $_SESSION['flash_message'] = 'Đã cập nhật thông tin';
    redirect('/cong-viec/ho-so');
}

include 'layout_top.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><span class="page-icon">👤</span>Thông tin cá nhân</h1>
    </div>
</div>

<div class="page-body">
    <div style="max-width:500px;">
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Họ và tên</label>
                <input type="text" name="fullname" class="form-input" value="<?= sanitize($user['fullname']) ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Tên đăng nhập</label>
                <input type="text" class="form-input" value="<?= sanitize($user['username']) ?>" disabled style="opacity:0.6">
            </div>

            <div class="form-group">
                <label class="form-label">Vai trò</label>
                <input type="text" class="form-input" value="<?= ['admin'=>'Quản trị viên','manager'=>'Quản lý','employee'=>'Nhân viên'][$user['role']] ?? $user['role'] ?>" disabled style="opacity:0.6">
            </div>

            <hr style="border:none;border-top:1px solid var(--border-color);margin:24px 0">

            <h3 style="font-size:15px;font-weight:600;margin-bottom:16px;">🔒 Đổi mật khẩu</h3>

            <div class="form-group">
                <label class="form-label">Mật khẩu hiện tại</label>
                <input type="password" name="current_password" class="form-input" placeholder="Nhập mật khẩu hiện tại">
            </div>

            <div class="form-group">
                <label class="form-label">Mật khẩu mới</label>
                <input type="password" name="new_password" class="form-input" placeholder="Nhập mật khẩu mới">
            </div>

            <div class="form-group">
                <label class="form-label">Xác nhận mật khẩu mới</label>
                <input type="password" name="confirm_password" class="form-input" placeholder="Nhập lại mật khẩu mới">
            </div>

            <button type="submit" class="btn btn-primary btn-lg">💾 Lưu thay đổi</button>
        </form>
    </div>
</div>

<?php include 'layout_bottom.php'; ?>
