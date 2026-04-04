<?php
// settings_auth.php - Include this at the top of files that need password protection

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if already unlocked
if (isset($_SESSION['settings_unlocked']) && $_SESSION['settings_unlocked'] === true) {
    return; // Allow access
}

$auth_error = '';

// Handle Password Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_settings_password'])) {
    require_once 'config.php';

    $input_pass = $_POST['unlock_settings_password'];

    // Get password from DB or use default
    $stored_pass = '123456'; // Default
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'settings_password'");
        $stmt->execute();
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res && !empty($res['setting_value'])) {
            $stored_pass = $res['setting_value'];
        }
    } catch (Exception $e) {
        // Table might not exist yet, use default
    }

    if ($input_pass === $stored_pass) {
        $_SESSION['settings_unlocked'] = true;
        // Reload to clear POST data
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    } else {
        $auth_error = 'Mật khẩu không chính xác!';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yêu cầu mật khẩu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
        }

        .auth-card {
            width: 100%;
            max-width: 400px;
            padding: 30px;
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .auth-icon {
            font-size: 50px;
            color: #6c757d;
            margin-bottom: 20px;
            text-align: center;
            display: block;
        }
    </style>
</head>

<body>
    <div class="auth-card">
        <i class="fas fa-lock auth-icon"></i>
        <h4 class="text-center mb-4">Bảo mật cài đặt</h4>

        <?php if ($auth_error): ?>
            <div class="alert alert-danger text-center">
                <?php echo $auth_error; ?>
            </div>
        <?php endif; ?>

        <p class="text-muted text-center mb-4">Vui lòng nhập mật khẩu để truy cập phần cài đặt hệ thống.</p>

        <form method="POST">
            <div class="mb-3">
                <input type="password" name="unlock_settings_password" class="form-control form-control-lg text-center"
                    placeholder="Nhập mật khẩu" required autofocus>
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-primary btn-lg">Mở khóa</button>
            </div>
        </form>
        <div class="text-center mt-3">
            <a href="index.php" class="text-decoration-none text-muted"><i class="fas fa-arrow-left"></i> Quay lại trang
                chủ</a>
        </div>
    </div>
</body>

</html>
<?php
exit(); // Stop execution of the main script
?>