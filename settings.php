<?php
session_start();
require_once 'config.php';
require_once 'permissions_helper.php';
require_once 'settings_auth.php';

// Check permission
if (!hasPermission($conn, 'system.manage')) {
    header("Location: index.php");
    exit();
}

$success = '';
$error = '';

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $google_sheet_url = trim($_POST['google_sheet_url'] ?? '');
    $apps_script_url = trim($_POST['apps_script_url'] ?? '');
    $reminder_days = intval($_POST['reminder_days'] ?? 7);
    $settings_password = trim($_POST['settings_password'] ?? '');

    // Extract Sheet ID from URL
    $sheet_id = '';
    if (preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/', $google_sheet_url, $matches)) {
        $sheet_id = $matches[1];
    } else {
        $error = "Link Google Sheet không hợp lệ. Vui lòng kiểm tra lại.";
    }

    if (!$error) {
        // Create settings table if not exists
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");
        } catch (PDOException $e) {
            // Table might already exist
        }

        // Save settings
        try {
            $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) 
                                   VALUES (?, ?) 
                                   ON DUPLICATE KEY UPDATE setting_value = ?");

            // Save Google Sheet URL
            $stmt->execute(['google_sheet_url', $google_sheet_url, $google_sheet_url]);

            // Save Google Sheet ID
            $stmt->execute(['google_sheet_id', $sheet_id, $sheet_id]);

            // Save reminder days
            $stmt->execute(['reminder_days', $reminder_days, $reminder_days]);

            // Save Apps Script URL
            $stmt->execute(['apps_script_url', $apps_script_url, $apps_script_url]);

            // Save Settings Password if provided
            if (!empty($settings_password)) {
                $stmt->execute(['settings_password', $settings_password, $settings_password]);
            }

            $success = "Cài đặt đã được lưu thành công!";
        } catch (PDOException $e) {
            $error = "Lỗi khi lưu cài đặt: " . $e->getMessage();
        }
    }
}

// Load current settings
$settings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    // Table might not exist yet
}

$google_sheet_url = $settings['google_sheet_url'] ?? '';
$apps_script_url = $settings['apps_script_url'] ?? '';
$reminder_days = $settings['reminder_days'] ?? 7;
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Cài đặt hệ thống</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            background: #f8f9fa;
        }
    </style>
</head>

<body>
    <?php include 'header.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include 'sidebar.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content" style="margin-top: 80px;">
                <div class="row justify-content-center">
                    <div class="col-md-10">
                        <div class="card shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="bi bi-gear"></i> Cài đặt hệ thống</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($success): ?>
                                    <div class="alert alert-success alert-dismissible fade show">
                                        <i class="bi bi-check-circle"></i> <?php echo $success; ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                    </div>
                                <?php endif; ?>

                                <?php if ($error): ?>
                                    <div class="alert alert-danger alert-dismissible fade show">
                                        <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                    </div>
                                <?php endif; ?>

                                <form method="POST" action="">
                                    <h6 class="border-bottom pb-2 mb-3"><i class="bi bi-cloud-upload"></i> Tích hợp
                                        Google
                                        Sheets</h6>

                                    <div class="mb-3">
                                        <label class="form-label">Link Google Sheet <span
                                                class="text-danger">*</span></label>
                                        <input type="url" name="google_sheet_url" class="form-control"
                                            value="<?php echo htmlspecialchars($google_sheet_url); ?>"
                                            placeholder="https://docs.google.com/spreadsheets/d/YOUR_SHEET_ID/edit"
                                            required>
                                        <small class="text-muted">
                                            <i class="bi bi-info-circle"></i> Dán link Google Sheet để đồng bộ danh sách
                                            nhắc nợ
                                        </small>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Số ngày nhắc trước</label>
                                        <input type="number" name="reminder_days" class="form-control"
                                            value="<?php echo $reminder_days; ?>" min="1" max="30">
                                        <small class="text-muted">
                                            <i class="bi bi-info-circle"></i> Hệ thống sẽ đẩy danh sách khách hàng sắp
                                            đến hạn
                                            trong vòng X ngày
                                        </small>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Apps Script URL</label>
                                        <input type="url" name="apps_script_url" class="form-control"
                                            value="<?php echo htmlspecialchars($apps_script_url); ?>"
                                            placeholder="https://script.google.com/macros/s/ABC...XYZ/exec">
                                        <small class="text-muted">
                                            <i class="bi bi-info-circle"></i> URL của Google Apps Script Web App
                                            <a href="sheets_setup_guide.php" target="_blank" class="text-primary">
                                                <i class="bi bi-book"></i> Xem hướng dẫn setup
                                            </a>
                                        </small>
                                    </div>

                                    <div class="alert alert-info">
                                        <strong><i class="bi bi-lightbulb"></i> Hướng dẫn:</strong>
                                        <ol class="mb-0 mt-2">
                                            <li>Mở Google Sheet của bạn</li>
                                            <li>Nhấn nút "Chia sẻ" ở góc trên bên phải</li>
                                            <li>Chọn "Bất kỳ ai có đường liên kết" và cấp quyền <strong>"Người chỉnh
                                                    sửa"</strong></li>
                                            <li>Sao chép link và dán vào ô trên</li>
                                        </ol>
                                    </div>

                                    <!-- Security Settings -->
                                    <h6 class="border-bottom pb-2 mt-4 mb-3">Bảo mật</h6>
                                    <div class="mb-3">
                                        <label for="settings_password" class="form-label">Mật khẩu bảo vệ</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                            <input type="password" class="form-control" id="settings_password" name="settings_password"
                                                placeholder="Nhập mật khẩu mới (để trống nếu không đổi)">
                                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword()">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                        <div class="form-text">Mật khẩu này dùng để khóa truy cập vào menu Cài đặt và Đồng bộ. Mặc định: 123456</div>
                                    </div>

                                    <script>
                                        function togglePassword() {
                                            const passwordInput = document.getElementById('settings_password');
                                            const icon = event.currentTarget.querySelector('i');
                                            if (passwordInput.type === 'password') {
                                                passwordInput.type = 'text';
                                                icon.classList.remove('bi-eye');
                                                icon.classList.add('bi-eye-slash');
                                            } else {
                                                passwordInput.type = 'password';
                                                icon.classList.remove('bi-eye-slash');
                                                icon.classList.add('bi-eye');
                                            }
                                        }
                                    </script>

                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-save"></i> Lưu cài đặt
                                        </button>
                                        <a href="sheets_sync_manager.php" class="btn btn-success">
                                            <i class="bi bi-cloud-upload"></i> Đồng bộ Google Sheets
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Info Card -->
                        <div class="card shadow-sm mt-3">
                            <div class="card-header bg-secondary text-white">
                                <h6 class="mb-0"><i class="bi bi-info-circle"></i> Thông tin đồng bộ</h6>
                            </div>
                            <div class="card-body">
                                <p><strong>Cột dữ liệu được đẩy lên Sheet:</strong></p>
                                <ul>
                                    <li><strong>Cột A:</strong> Tên khách hàng</li>
                                    <li><strong>Cột B:</strong> Số tiền cần đóng (định dạng tiền tệ)</li>
                                    <li><strong>Cột C:</strong> Ngày đóng lãi</li>
                                    <li><strong>Cột I:</strong> Số điện thoại</li>
                                    <li><strong>Cột J:</strong> Số tiền cần đóng (giống cột B)</li>
                                </ul>
                                <p class="mb-0 text-muted">
                                    <i class="bi bi-arrow-repeat"></i> Mỗi lần đồng bộ, dữ liệu sẽ được thêm vào dòng
                                    trống tiếp
                                    theo (không xóa dữ liệu cũ)
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>