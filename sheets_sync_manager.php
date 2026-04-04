<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'config.php';
require_once 'permissions_helper.php';
require_once 'settings_auth.php';
require_once 'sheets_helper.php';

// Check permission
if (!hasPermission($conn, 'system.manage')) {
    header("Location: index.php");
    exit();
}

$success = '';
$error = '';
$preview_data = [];

// Load settings
$settings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    $error = "Chưa cấu hình Google Sheets. Vui lòng vào trang Cài đặt.";
}

$google_sheet_id = $settings['google_sheet_id'] ?? '';
$apps_script_url = $settings['apps_script_url'] ?? '';
$reminder_days = intval($settings['reminder_days'] ?? 7);

// Get current store ID from config.php context
$current_store_id = $current_store_id ?? 1;

// Handle sync action
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'sync') {
    if (!$google_sheet_id) {
        $error = "Chưa cấu hình Google Sheet. Vui lòng vào trang Cài đặt.";
    } elseif (!$apps_script_url) {
        $error = "Chưa cấu hình Apps Script URL. Vui lòng vào trang Cài đặt.";
    } else {
        // Get data to sync
        $data_to_sync = getUpcomingPayments($conn, $reminder_days, $current_store_id);

        if (empty($data_to_sync)) {
            $error = "Không có khách hàng nào sắp đến hạn trong {$reminder_days} ngày tới.";
        } else {
            // Sync to Google Sheets
            $result = syncToGoogleSheetsViaAppsScript($google_sheet_id, $data_to_sync, $apps_script_url);

            if ($result['success']) {
                $success = $result['message'];

                // Log sync history
                try {
                    $stmt = $conn->prepare("
                        INSERT INTO sync_history (user_id, sync_date, record_count, status)
                        VALUES (?, NOW(), ?, 'success')
                    ");
                    $stmt->execute([$_SESSION['user_id'], count($data_to_sync)]);
                } catch (PDOException $e) {
                    // Ignore logging error
                }
            } else {
                $error = "Lỗi khi đồng bộ: " . $result['error'];
            }
        }
    }
}

// Get preview data
$preview_data = getUpcomingPayments($conn, $reminder_days, $current_store_id);

// Get sync history
$sync_history = getSyncHistory($conn);

/**
 * Get upcoming payments - SIMPLIFIED using next_payment_date column
 */
function getUpcomingPayments($conn, $days, $store_id)
{
    $data = [];
    $today = date('Y-m-d');
    $end_date = date('Y-m-d', strtotime("+{$days} days"));

    try {
        $stmt = $conn->prepare("
            SELECT 
                l.id,
                c.name,
                c.phone,
                l.amount,
                l.interest_rate,
                l.interest_type,
                l.period_days,
                l.next_payment_date
            FROM loans l
            JOIN customers c ON l.customer_id = c.id
            WHERE l.status = 'active' 
                AND l.next_payment_date IS NOT NULL
                AND l.next_payment_date >= ?
                AND l.next_payment_date <= ?
            ORDER BY l.next_payment_date, c.name
        ");
        $stmt->execute([$today, $end_date]);
        $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($loans as $loan) {
            // Calculate interest amount for this payment period
            $interest_amount = calculateInterestAmount($loan);

            $data[] = [
                'fullname' => $loan['name'],
                'amount' => $interest_amount,
                'payment_date' => date('d/m/Y', strtotime($loan['next_payment_date'])),
                'phone' => $loan['phone'] ?? ''
            ];
        }
    } catch (PDOException $e) {
        // Return empty array on error
    }

    return $data;
}

/**
 * Calculate interest amount based on loan type - MATCHES contract_view.php logic
 */
function calculateInterestAmount($loan)
{
    $principal = floatval($loan['amount']);
    $rate = floatval($loan['interest_rate']);
    $interest_type = $loan['interest_type'];
    $period_days = !empty($loan['period_days']) && $loan['period_days'] > 0 ? $loan['period_days'] : 30;

    $period_interest = 0;

    // Logic from contract_view.php lines 819-826
    if ($interest_type == 'ngay' || $rate > 100) {
        $rate_real = $rate;
        $mult = ($rate_real < 500) ? 1000 : 1;
        $period_interest = ($principal / 1000000) * ($rate_real * $mult) * $period_days;
    } else {
        $daily_interest = ($principal * ($rate / 100)) / 30;
        $period_interest = $daily_interest * $period_days;
    }

    // Round to nearest 1000 (like in contract_view.php line 827)
    // $period_interest = round($period_interest / 1000) * 1000; // Removed rounding

    return $period_interest;
}

/**
 * Get sync history
 */
function getSyncHistory($conn)
{
    try {
        $stmt = $conn->query("
            SELECT sh.*, u.username 
            FROM sync_history sh
            LEFT JOIN users u ON sh.user_id = u.id
            ORDER BY sh.sync_date DESC
            LIMIT 10
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đồng bộ Google Sheets</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/style.css">
    <style>
        body {
            background: #f8f9fa;
        }

        .section-title {
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 10px;
            margin-bottom: 20px;
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
                <div class="row">
                    <div class="col-md-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="bi bi-cloud-upload"></i> Đồng bộ Google Sheets</h5>
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

                                <!-- Preview Section -->
                                <h6 class="section-title">
                                    <i class="bi bi-eye"></i> Xem trước dữ liệu
                                    <span class="badge bg-primary"><?php echo count($preview_data); ?> bản ghi</span>
                                    <small class="text-muted">(Trong <?php echo $reminder_days; ?> ngày tới)</small>
                                </h6>

                                <?php if (!empty($preview_data)): ?>
                                    <div class="table-responsive mb-4">
                                        <table class="table table-sm table-hover table-bordered">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width: 50px;" class="text-center">STT</th>
                                                    <th>Tên khách hàng</th>
                                                    <th style="width: 150px;" class="text-end">Số tiền</th>
                                                    <th style="width: 120px;" class="text-center">Ngày đóng lãi</th>
                                                    <th style="width: 130px;" class="text-center">Số điện thoại</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($preview_data as $index => $row): ?>
                                                    <tr>
                                                        <td class="text-center"><?php echo $index + 1; ?></td>
                                                        <td><?php echo htmlspecialchars($row['fullname']); ?></td>
                                                        <td class="text-end">
                                                            <?php echo number_format($row['amount'], 0, ',', '.'); ?> ₫
                                                        </td>
                                                        <td class="text-center"><?php echo $row['payment_date']; ?></td>
                                                        <td class="text-center"><?php echo htmlspecialchars($row['phone']); ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- Sync Button -->
                                    <form method="POST" action="">
                                        <input type="hidden" name="action" value="sync">
                                        <button type="submit" class="btn btn-success btn-lg">
                                            <i class="bi bi-cloud-upload"></i> Đồng bộ ngay
                                            (<?php echo count($preview_data); ?> bản
                                            ghi)
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle"></i> Không có khách hàng nào sắp đến hạn trong
                                        <?php echo $reminder_days; ?> ngày tới.
                                    </div>
                                <?php endif; ?>

                                <!-- Help Section -->
                                <div class="alert alert-light border mt-4">
                                    <h6><i class="bi bi-question-circle"></i> Cần trợ giúp?</h6>
                                    <ul class="mb-0">
                                        <li>Xem <a href="sheets_setup_guide.php" target="_blank" class="text-primary"><i
                                                    class="bi bi-book"></i> Hướng dẫn setup Google Apps Script</a></li>
                                        <li>Vào <a href="settings.php" class="text-primary"><i class="bi bi-gear"></i>
                                                Trang cài
                                                đặt</a> để cấu hình Google Sheet và Apps Script URL</li>
                                    </ul>
                                </div>

                                <!-- Sync History -->
                                <h6 class="section-title mt-4">
                                    <i class="bi bi-clock-history"></i> Thông tin đồng bộ
                                </h6>

                                <?php if (!empty($sync_history)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Thời gian</th>
                                                    <th>Người thực hiện</th>
                                                    <th>Số bản ghi</th>
                                                    <th>Trạng thái</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($sync_history as $history): ?>
                                                    <tr>
                                                        <td><?php echo date('d/m/Y H:i', strtotime($history['sync_date'])); ?>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($history['username'] ?? 'N/A'); ?></td>
                                                        <td><?php echo $history['record_count']; ?></td>
                                                        <td>
                                                            <?php if ($history['status'] == 'success'): ?>
                                                                <span class="badge bg-success">Thành công</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-danger">Thất bại</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted"><i class="bi bi-info-circle"></i> Chưa có lịch sử đồng bộ</p>
                                <?php endif; ?>
                            </div>
                        </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>