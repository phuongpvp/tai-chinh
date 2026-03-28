<?php
// telegram_helper.php - Helper functions for Telegram Bot integration

/**
 * Send a message to Telegram
 */
function sendTelegramMessage($chat_id, $message, $conn)
{
    // Get bot token from settings
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'telegram_bot_token'");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result || empty($result['setting_value'])) {
        return ['success' => false, 'error' => 'Bot token not configured'];
    }

    $bot_token = $result['setting_value'];
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";

    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200) {
        return ['success' => true];
    } else {
        return ['success' => false, 'error' => $response];
    }
}

/**
 * Generate daily report with appointment date logic
 */
function generateDailyReport($conn)
{
    $today = date('Y-m-d');

    // Get SMS templates from settings
    $templates = getSMSTemplates($conn);

    // Query active loans with customer info
    $stmt = $conn->prepare("
        SELECT 
            l.id,
            l.next_payment_date,
            l.appointment_date,
            l.amount,
            l.interest_rate,
            l.interest_type,
            l.period_days,
            c.name as customer_name,
            c.phone as customer_phone
        FROM loans l
        JOIN customers c ON l.customer_id = c.id
        WHERE l.status = 'active'
            AND (l.is_hidden_from_reminder IS NULL OR l.is_hidden_from_reminder = 0)
        ORDER BY l.next_payment_date
    ");
    $stmt->execute();
    $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $customersToNotify = [];

    foreach ($loans as $loan) {
        $customerName = $loan['customer_name'];
        $customerPhone = $loan['customer_phone'];

        // Calculate interest amount using proper logic
        $interestAmount = calculateInterestAmount($loan);
        $amount = number_format($interestAmount, 0, ',', '.') . ' ₫';

        $appointmentDate = $loan['appointment_date'];
        $nextPaymentDate = $loan['next_payment_date'];

        $messageContent = '';
        $reason = '';

        // Priority 1: Check appointment date
        if ($appointmentDate) {
            $appointmentTime = strtotime($appointmentDate);
            $todayTime = strtotime($today);

            if ($appointmentTime == $todayTime) {
                // Appointment is today
                $reason = "🗣️ Đến ngày khách hẹn trả!";
                $messageContent = "Hôm nay là ngày em đã hẹn, nhớ thực hiện đúng em nhé.";
            } elseif ($appointmentTime > $todayTime) {
                // Appointment is in the future, skip this customer
                continue;
            }
            // If appointment is in the past, fall through to standard logic
        }

        // Standard logic based on next_payment_date
        if (empty($messageContent) && $nextPaymentDate) {
            $paymentTime = strtotime($nextPaymentDate);
            $todayTime = strtotime($today);
            $diffDays = floor(($paymentTime - $todayTime) / 86400);

            if ($diffDays == 2) {
                $reason = "🔔 Sắp đến hạn (2 ngày)";
                $messageContent = $templates['before_2_days'];
            } elseif ($diffDays == 0) {
                $reason = "🔔 Đến hạn hôm nay!";
                $messageContent = $templates['due_today'];
            } elseif ($diffDays == -1) {
                $reason = "🔴 Đã quá hạn 1 ngày";
                $messageContent = $templates['overdue_1_day'];
            } elseif ($diffDays == -2) {
                $reason = "🔴🔴 Đã quá hạn 2 ngày";
                $messageContent = $templates['overdue_2_days'];
            } elseif ($diffDays == -3) {
                $reason = "🚨🚨🚨 Đã quá hạn 3 ngày";
                $messageContent = $templates['overdue_3_days'];
            }
            // Skip if overdue more than 3 days ($diffDays < -3)
        }

        if ($messageContent) {
            // Format payment date for display
            $paymentDateDisplay = $nextPaymentDate ? date('d/m/Y', strtotime($nextPaymentDate)) : 'N/A';

            // Replace placeholders
            $messageContent = str_replace('[Ten]', $customerName, $messageContent);
            $messageContent = str_replace('[SoTien]', $amount, $messageContent);
            $messageContent = str_replace('[NgayDongLai]', $paymentDateDisplay, $messageContent);

            $customerInfo = "👤 **{$customerName}**\n" .
                "📞 `{$customerPhone}`\n" .
                "📅 Ngày đóng lãi: `{$paymentDateDisplay}`\n" .
                "*{$reason}*\n\n" .
                "`{$messageContent}`";

            $customersToNotify[] = $customerInfo;
        }
    }

    if (count($customersToNotify) > 0) {
        return implode("\n\n--------------------\n\n", $customersToNotify);
    } else {
        return "✅ Tuyệt vời! Hôm nay không có ai cần nhắc.";
    }
}

/**
 * Get SMS templates from settings
 */
function getSMSTemplates($conn)
{
    $defaults = [
        'before_2_days' => 'Chào [Ten], đến ngày [SoTien] rồi em nhé, nhớ chuẩn bị tiền đóng lãi nhé!',
        'due_today' => 'Em [Ten] ơi, hôm nay đến hạn đóng [SoTien] rồi em nhé!',
        'overdue_1_day' => 'Em [Ten], hôm qua em quên đóng [SoTien] rồi, nhớ đóng giúp anh nhé!',
        'overdue_2_days' => 'Em [Ten] ơi, đã 2 ngày rồi em chưa đóng [SoTien], anh đang cần tiền lắm!',
        'overdue_3_days' => 'Em [Ten], đã quá 3 ngày rồi mà em vẫn chưa đóng [SoTien]. Anh rất cần em hợp tác!'
    ];

    $templates = [];
    foreach ($defaults as $key => $defaultValue) {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute(["telegram_template_{$key}"]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $templates[$key] = $result && !empty($result['setting_value']) ? $result['setting_value'] : $defaultValue;
    }

    return $templates;
}

/**
 * Set Telegram webhook
 */
function setTelegramWebhook($webhook_url, $conn)
{
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'telegram_bot_token'");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result || empty($result['setting_value'])) {
        return ['success' => false, 'error' => 'Bot token not configured'];
    }

    $bot_token = $result['setting_value'];
    $url = "https://api.telegram.org/bot{$bot_token}/setWebhook?url=" . urlencode($webhook_url);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200) {
        $data = json_decode($response, true);
        return ['success' => $data['ok'] ?? false, 'response' => $data];
    } else {
        return ['success' => false, 'error' => $response];
    }
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
?>