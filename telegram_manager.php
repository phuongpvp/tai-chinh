<?php
session_start();
require_once 'config.php';
require_once 'permissions_helper.php';
require_once 'settings_auth.php';
require_once 'telegram_helper.php';

// Check permission
if (!hasPermission($conn, 'system.manage')) {
    header("Location: index.php");
    exit();
}

$success = '';
$error = '';

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action === 'save_settings') {
            $bot_token = trim($_POST['bot_token'] ?? '');
            $chat_id = trim($_POST['chat_id'] ?? '');
            $webhook_url = trim($_POST['webhook_url'] ?? '');

            // Save templates
            $templates = [
                'before_2_days' => $_POST['template_before_2_days'] ?? '',
                'due_today' => $_POST['template_due_today'] ?? '',
                'overdue_1_day' => $_POST['template_overdue_1_day'] ?? '',
                'overdue_2_days' => $_POST['template_overdue_2_days'] ?? '',
                'overdue_3_days' => $_POST['template_overdue_3_days'] ?? '',
                'appointment_date' => $_POST['template_appointment_date'] ?? ''
            ];

            try {
                $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) 
                                       VALUES (?, ?) 
                                       ON DUPLICATE KEY UPDATE setting_value = ?");

                // Save bot token
                if (!empty($bot_token)) {
                    $stmt->execute(['telegram_bot_token', $bot_token, $bot_token]);
                }

                // Save chat ID
                if (!empty($chat_id)) {
                    $stmt->execute(['telegram_chat_id', $chat_id, $chat_id]);
                }

                // Save webhook URL
                if (!empty($webhook_url)) {
                    $stmt->execute(['telegram_webhook_url', $webhook_url, $webhook_url]);
                }

                // Save templates
                foreach ($templates as $key => $value) {
                    if (!empty($value)) {
                        $stmt->execute(["telegram_template_{$key}", $value, $value]);
                    }
                }

                $success = "Cài đặt đã được lưu thành công!";
            } catch (PDOException $e) {
                $error = "Lỗi khi lưu cài đặt: " . $e->getMessage();
            }
        } elseif ($action === 'send_report') {
            // Get chat ID
            $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'telegram_chat_id'");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $chat_ids_str = $result['setting_value'] ?? '';

            if (empty($chat_ids_str)) {
                $error = "Chưa cấu hình Chat ID!";
            } else {
                $report = generateDailyReport($conn);
                $chat_ids = array_filter(array_map('trim', explode(',', $chat_ids_str)));
                $all_success = true;
                $errors = [];

                foreach ($chat_ids as $chat_id) {
                    $result = sendTelegramMessage($chat_id, $report, $conn);
                    if (!$result['success']) {
                        $all_success = false;
                        $errors[] = "Lỗi ID $chat_id: " . ($result['error'] ?? 'Unknown error');
                    }
                }

                if ($all_success && count($errors) === 0) {
                    $success = "Đã gửi báo cáo thành công!";
                } else {
                    $error = "Lỗi khi gửi: " . implode(' | ', $errors);
                    if (count($chat_ids) > count($errors)) {
                        $success = "Đã gửi báo cáo cho một số ID, nhưng có lỗi xảy ra.";
                    }
                }
            }
        } elseif ($action === 'set_webhook') {
            $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'telegram_webhook_url'");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $webhook_url = $result['setting_value'] ?? '';

            if (empty($webhook_url)) {
                $error = "Chưa cấu hình Webhook URL!";
            } else {
                $result = setTelegramWebhook($webhook_url, $conn);

                if ($result['success']) {
                    $success = "Đã cấu hình Webhook thành công!";
                } else {
                    $error = "Lỗi khi cấu hình Webhook: " . ($result['error'] ?? 'Unknown error');
                }
            }
        } elseif ($action === 'test_connection') {
            $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'telegram_chat_id'");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $chat_ids_str = $result['setting_value'] ?? '';

            if (empty($chat_ids_str)) {
                $error = "Chưa cấu hình Chat ID!";
            } else {
                $test_message = "🤖 *Test Connection*\n\nKết nối Telegram Bot thành công!\nThời gian: " . date('d/m/Y H:i:s');
                $chat_ids = array_filter(array_map('trim', explode(',', $chat_ids_str)));
                $all_success = true;
                $errors = [];

                foreach ($chat_ids as $chat_id) {
                    $result = sendTelegramMessage($chat_id, $test_message, $conn);
                    if (!$result['success']) {
                        $all_success = false;
                        $errors[] = "Lỗi ID $chat_id: " . ($result['error'] ?? 'Unknown error');
                    }
                }

                if ($all_success && count($errors) === 0) {
                    $success = "Đã gửi tin nhắn test thành công! Kiểm tra Telegram.";
                } else {
                    $error = "Lỗi khi gửi test: " . implode(' | ', $errors);
                    if (count($chat_ids) > count($errors)) {
                        $success = "Đã gửi test cho một số ID, nhưng có lỗi xảy ra.";
                    }
                }
            }
        }
    }
}

// Load current settings
$current_settings = [];
$settings_keys = [
    'telegram_bot_token',
    'telegram_chat_id',
    'telegram_webhook_url',
    'telegram_template_before_2_days',
    'telegram_template_due_today',
    'telegram_template_overdue_1_day',
    'telegram_template_overdue_2_days',
    'telegram_template_overdue_3_days',
    'telegram_template_appointment_date'
];

foreach ($settings_keys as $key) {
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $current_settings[$key] = $result['setting_value'] ?? '';
}

// Get default templates
$default_templates = [
    'before_2_days' => 'Chào [Ten], đến ngày [SoTien] rồi em nhé, nhớ chuẩn bị tiền đóng lãi nhé!',
    'due_today' => 'Em [Ten] ơi, hôm nay đến hạn đóng [SoTien] rồi em nhé!',
    'overdue_1_day' => 'Em [Ten], hôm qua em quên đóng [SoTien] rồi, nhớ đóng giúp anh nhé!',
    'overdue_2_days' => 'Em [Ten] ơi, đã 2 ngày rồi em chưa đóng [SoTien], anh đang cần tiền lắm!',
    'overdue_3_days' => 'Em [Ten], đã quá 3 ngày rồi mà em vẫn chưa đóng [SoTien]. Anh rất cần em hợp tác!',
    'appointment_date' => 'Em [Ten] ơi, hôm nay em hẹn đóng [SoTien] đúng không? Anh chờ em nhé!'
];
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Telegram Bot</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
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
                                <h5 class="mb-0"><i class="fab fa-telegram-plane"></i> Quản lý Telegram Bot</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($success): ?>
                                    <div class="alert alert-success alert-dismissible fade show">
                                        <?php echo $success; ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                    </div>
                                <?php endif; ?>

                                <?php if ($error): ?>
                                    <div class="alert alert-danger alert-dismissible fade show">
                                        <?php echo $error; ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                    </div>
                                <?php endif; ?>

                                <form method="POST">
                                    <input type="hidden" name="action" value="save_settings">

                                    <!-- Bot Configuration -->
                                    <h6 class="border-bottom pb-2 mb-3">Cấu hình Bot</h6>

                                    <div class="mb-3">
                                        <label for="bot_token" class="form-label">Bot Token</label>
                                        <input type="text" class="form-control" id="bot_token" name="bot_token"
                                            value="<?php echo htmlspecialchars($current_settings['telegram_bot_token']); ?>"
                                            placeholder="8204905367:AAHV0tdZ_nB1gOyZTN5LBLL6C2JdtpHovjU">
                                        <div class="form-text">Token từ @BotFather trên Telegram</div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="chat_id" class="form-label">Chat ID</label>
                                        <input type="text" class="form-control" id="chat_id" name="chat_id"
                                            value="<?php echo htmlspecialchars($current_settings['telegram_chat_id']); ?>"
                                            placeholder="123456789,987654321">
                                        <div class="form-text">ID của chat/group nhận báo cáo (dùng @userinfobot để lấy). <b>Hỗ trợ nhiều ID cách nhau bằng dấu phẩy (,).</b>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="webhook_url" class="form-label">Webhook URL</label>
                                        <input type="text" class="form-control" id="webhook_url" name="webhook_url"
                                            value="<?php echo htmlspecialchars($current_settings['telegram_webhook_url']); ?>"
                                            placeholder="https://yourdomain.com/telegram_webhook.php">
                                        <div class="form-text">URL công khai của file telegram_webhook.php (để nhận lệnh
                                            /check)</div>
                                    </div>

                                    <!-- SMS Templates -->
                                    <h6 class="border-bottom pb-2 mt-4 mb-3">Mẫu tin nhắn SMS</h6>
                                    <p class="text-muted small">Sử dụng <code>[Ten]</code> cho tên khách hàng,
                                        <code>[SoTien]</code> cho số tiền, và
                                        <code>[NgayDongLai]</code> cho ngày đóng lãi
                                    </p>

                                    <div class="mb-3">
                                        <label class="form-label">🔔 Nhắc trước 2 ngày</label>
                                        <textarea class="form-control" name="template_before_2_days"
                                            rows="2"><?php echo htmlspecialchars($current_settings['telegram_template_before_2_days'] ?: $default_templates['before_2_days']); ?></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">🔔 Đến hạn hôm nay</label>
                                        <textarea class="form-control" name="template_due_today"
                                            rows="2"><?php echo htmlspecialchars($current_settings['telegram_template_due_today'] ?: $default_templates['due_today']); ?></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">🔴 Quá hạn 1 ngày</label>
                                        <textarea class="form-control" name="template_overdue_1_day"
                                            rows="2"><?php echo htmlspecialchars($current_settings['telegram_template_overdue_1_day'] ?: $default_templates['overdue_1_day']); ?></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">🔴🔴 Quá hạn 2 ngày</label>
                                        <textarea class="form-control" name="template_overdue_2_days"
                                            rows="2"><?php echo htmlspecialchars($current_settings['telegram_template_overdue_2_days'] ?: $default_templates['overdue_2_days']); ?></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">🚨🚨🚨 Quá hạn 3+ ngày</label>
                                        <textarea class="form-control" name="template_overdue_3_days"
                                            rows="2"><?php echo htmlspecialchars($current_settings['telegram_template_overdue_3_days'] ?: $default_templates['overdue_3_days']); ?></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">📅 Ngày hẹn (Khách hẹn cụ thể)</label>
                                        <textarea class="form-control" name="template_appointment_date"
                                            rows="2"><?php echo htmlspecialchars($current_settings['telegram_template_appointment_date'] ?: $default_templates['appointment_date']); ?></textarea>
                                        <small class="text-muted">Dùng khi khách hẹn ngày cụ thể (có set Ngày
                                            hẹn)</small>
                                    </div>

                                    <div class="mt-4">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-save"></i> Lưu cài đặt
                                        </button>
                                    </div>
                                </form>

                                <!-- Action Buttons -->
                                <hr class="my-4">
                                <h6 class="border-bottom pb-2 mb-3">Hành động</h6>

                                <div class="d-flex gap-2 flex-wrap">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="test_connection">
                                        <button type="submit" class="btn btn-info">
                                            <i class="bi bi-wifi"></i> Test kết nối
                                        </button>
                                    </form>

                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="send_report">
                                        <button type="submit" class="btn btn-success">
                                            <i class="bi bi-send"></i> Gửi báo cáo ngay
                                        </button>
                                    </form>

                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="set_webhook">
                                        <button type="submit" class="btn btn-warning">
                                            <i class="bi bi-link-45deg"></i> Cấu hình Webhook
                                        </button>
                                    </form>
                                </div>

                                <!-- Appointment Management -->
                                <hr class="my-4">
                                <h6 class="border-bottom pb-2 mb-3">Quản lý ngày hẹn khách</h6>

                                <?php
                                // Get active loans that need notification (same logic as report)
                                $today = date('Y-m-d');

                                $stmt_loans = $conn->prepare("
                                    SELECT 
                                        l.id,
                                        l.next_payment_date,
                                        l.appointment_date,
                                        l.is_hidden_from_reminder,
                                        c.name as customer_name,
                                        c.phone as customer_phone,
                                        CASE 
                                            WHEN l.appointment_date IS NOT NULL THEN l.appointment_date
                                            ELSE l.next_payment_date
                                        END as effective_date,
                                        DATEDIFF(
                                            CASE 
                                                WHEN l.appointment_date IS NOT NULL THEN l.appointment_date
                                                ELSE l.next_payment_date
                                            END,
                                            :today
                                        ) as days_diff
                                    FROM loans l
                                    JOIN customers c ON l.customer_id = c.id
                                    WHERE l.status = 'active' 
                                        AND (l.is_hidden_from_reminder IS NULL OR l.is_hidden_from_reminder = 0)
                                    HAVING days_diff BETWEEN -3 AND 2
                                    ORDER BY effective_date
                                ");
                                $stmt_loans->execute(['today' => $today]);
                                $active_loans = $stmt_loans->fetchAll(PDO::FETCH_ASSOC);
                                ?>

                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width: 5%;">Ẩn</th>
                                                <th style="width: 22%;">Khách hàng</th>
                                                <th style="width: 13%;">Số điện thoại</th>
                                                <th style="width: 13%;">Ngày đóng lãi</th>
                                                <th style="width: 17%;">Ngày hẹn</th>
                                                <th style="width: 30%;">Hành động</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($active_loans)): ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted py-3">
                                                        Không có khách hàng cần nhắc nhở
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($active_loans as $loan): ?>
                                                    <tr>
                                                        <td class="text-center">
                                                            <input type="checkbox" 
                                                                   class="form-check-input hide-reminder-checkbox" 
                                                                   data-loan-id="<?php echo $loan['id']; ?>"
                                                                   <?php echo ($loan['is_hidden_from_reminder'] == 1) ? 'checked' : ''; ?>>
                                                        </td>
                                                        <td class="fw-bold">
                                                            <?php echo htmlspecialchars($loan['customer_name']); ?>
                                                        </td>
                                                        <td><code><?php echo htmlspecialchars($loan['customer_phone']); ?></code>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            if ($loan['next_payment_date']) {
                                                                echo date('d/m/Y', strtotime($loan['next_payment_date']));
                                                            } else {
                                                                echo '<span class="text-muted">N/A</span>';
                                                            }
                                                            ?>
                                                        </td>
                                                        <td>
                                                            <input type="date"
                                                                class="form-control form-control-sm appointment-date-input"
                                                                data-loan-id="<?php echo $loan['id']; ?>"
                                                                value="<?php echo $loan['appointment_date'] ?? ''; ?>"
                                                                placeholder="Chọn ngày hẹn">
                                                        </td>
                                                        <td>
                                                            <button class="btn btn-sm btn-primary save-appointment"
                                                                data-loan-id="<?php echo $loan['id']; ?>">
                                                                <i class="bi bi-check-lg"></i> Lưu
                                                            </button>
                                                            <?php if ($loan['appointment_date']): ?>
                                                                <button class="btn btn-sm btn-outline-danger clear-appointment"
                                                                    data-loan-id="<?php echo $loan['id']; ?>">
                                                                    <i class="bi bi-x-lg"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="alert alert-info mt-3">
                                    <strong><i class="bi bi-info-circle"></i> Lưu ý:</strong>
                                    <ul class="mb-0 mt-2">
                                        <li>Nếu khách hẹn ngày cụ thể, hãy set <strong>Ngày hẹn</strong></li>
                                        <li>Hệ thống sẽ ưu tiên <strong>Ngày hẹn</strong> hơn <strong>Ngày đóng
                                                lãi</strong></li>
                                        <li>Nếu ngày hẹn là hôm nay → Gửi thông báo đặc biệt</li>
                                        <li>Nếu ngày hẹn trong tương lai → Bỏ qua khách này</li>
                                        <li>Nếu ngày hẹn đã qua → Áp dụng logic quá hạn bình thường</li>
                                    </ul>
                                </div>

                                <!-- Preview Report -->
                                <hr class="my-4">
                                <h6 class="border-bottom pb-2 mb-3">Xem trước báo cáo</h6>
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <pre class="mb-0"
                                            style="white-space: pre-wrap; font-size: 13px;"><?php echo htmlspecialchars(generateDailyReport($conn)); ?></pre>
                                    </div>
                                </div>

                                <!-- Instructions -->
                                <div class="alert alert-info mt-4">
                                    <strong><i class="bi bi-lightbulb"></i> Hướng dẫn:</strong>
                                    <ol class="mb-0 mt-2">
                                        <li>Tạo bot mới qua @BotFather và lấy Bot Token</li>
                                        <li>Lấy Chat ID bằng cách nhắn tin cho @userinfobot</li>
                                        <li>Nhập thông tin vào form trên và lưu</li>
                                        <li>Nhấn "Test kết nối" để kiểm tra</li>
                                        <li>Nhấn "Cấu hình Webhook" để kích hoạt lệnh /check</li>
                                        <li>Gõ /check trên Telegram để nhận báo cáo hàng ngày</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Save appointment date
            document.querySelectorAll('.save-appointment').forEach(btn => {
                btn.addEventListener('click', function () {
                    const loanId = this.dataset.loanId;
                    const dateInput = document.querySelector(`.appointment-date-input[data-loan-id="${loanId}"]`);
                    const appointmentDate = dateInput.value;

                    if (!appointmentDate) {
                        alert('Vui lòng chọn ngày hẹn!');
                        return;
                    }

                    // Send AJAX request
                    fetch('../contract_update_appointment.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: `loan_id=${loanId}&appointment_date=${appointmentDate}`
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert('✅ Đã lưu ngày hẹn thành công!');
                                location.reload();
                            } else {
                                alert('❌ Lỗi: ' + (data.error || 'Unknown error'));
                            }
                        })
                        .catch(error => {
                            alert('❌ Lỗi kết nối: ' + error);
                        });
                });
            });

            // Clear appointment date
            document.querySelectorAll('.clear-appointment').forEach(btn => {
                btn.addEventListener('click', function () {
                    if (!confirm('Bạn có chắc muốn xóa ngày hẹn?')) {
                        return;
                    }

                    const loanId = this.dataset.loanId;

                    // Send AJAX request
                    fetch('../contract_update_appointment.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: `loan_id=${loanId}&appointment_date=`
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert('✅ Đã xóa ngày hẹn!');
                                location.reload();
                            } else {
                                alert('❌ Lỗi: ' + (data.error || 'Unknown error'));
                            }
                        })
                        .catch(error => {
                            alert('❌ Lỗi kết nối: ' + error);
                        });
                });
            });
        });
    </script>
    <script src="/js/hide_reminder.js"></script>
</body>

</html>