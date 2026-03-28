<?php
/**
 * Google Drive OAuth2 Authorization Page
 * Admin truy cập trang này 1 lần để authorize access
 */
require_once 'config.php';
require_once 'google_drive.php';

$user = cvGetUser();
if (!$user || $user['role'] !== 'admin') {
    die('Chỉ admin mới được phép');
}

// Redirect URI = trang này
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$redirectUri = $protocol . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');

$drive = new GoogleDrive();

// Xử lý callback từ Google
if (isset($_GET['code'])) {
    try {
        $drive->handleCallback($_GET['code'], $redirectUri);
        $_SESSION['flash_message'] = '✅ Kết nối Google Drive thành công!';
        redirect('gdrive_auth.php');
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$isConnected = $drive->isAuthorized();
$authUrl = $drive->getAuthUrl($redirectUri);

include 'layout_top.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">☁️ Kết nối Google Drive</h1>
        <p class="page-subtitle">Upload file khách hàng lên Google Drive của bạn</p>
    </div>
</div>

<div class="page-body">
    <section style="max-width:500px;margin:0 auto;">
        <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:24px;text-align:center;">

            <?php if ($isConnected): ?>
                <div style="font-size:48px;margin-bottom:12px;">✅</div>
                <h3 style="color:var(--accent-green);margin-bottom:8px;">Đã kết nối</h3>
                <p style="color:var(--text-muted);font-size:14px;">Google Drive đã được kết nối. File upload sẽ lưu vào folder "Khách hàng" trên Drive của bạn.</p>
                <hr style="border:none;border-top:1px solid var(--border-color);margin:16px 0;">
                <a href="<?= htmlspecialchars($authUrl) ?>" class="btn btn-secondary btn-sm">🔄 Kết nối lại</a>
            <?php else: ?>
                <div style="font-size:48px;margin-bottom:12px;">☁️</div>
                <h3 style="margin-bottom:8px;">Chưa kết nối</h3>
                <p style="color:var(--text-muted);font-size:14px;margin-bottom:16px;">Bấm nút bên dưới để đăng nhập Google và cấp quyền truy cập Drive.</p>

                <?php if (!empty($error)): ?>
                    <div style="background:rgba(235,87,87,0.1);color:#eb5757;padding:10px;border-radius:var(--radius-md);margin-bottom:12px;font-size:13px;">
                        ❌ <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <a href="<?= htmlspecialchars($authUrl) ?>" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:8px;">
                    <img src="https://www.google.com/favicon.ico" width="16" height="16" alt="">
                    Đăng nhập Google
                </a>
            <?php endif; ?>

        </div>
    </section>
</div>

<?php include 'layout_bottom.php'; ?>
