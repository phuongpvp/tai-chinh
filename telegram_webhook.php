<?php
// telegram_webhook.php - Handle incoming Telegram updates
require_once 'config.php';
require_once 'telegram_helper.php';

// Get the incoming update
$content = file_get_contents("php://input");
$update = json_decode($content, true);

// Log for debugging (optional - remove in production)
// file_put_contents('telegram_log.txt', date('Y-m-d H:i:s') . " - " . $content . "\n", FILE_APPEND);

try {
    if (isset($update['message'])) {
        $chatId = $update['message']['chat']['id'];
        $text = $update['message']['text'] ?? '';

        // Get configured chat ID from settings
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'telegram_chat_id'");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $configuredChatId = $result['setting_value'] ?? '';

        // Only respond to configured chat IDs for security
        if (!empty($configuredChatId)) {
            $allowedChatIds = array_map('trim', explode(',', $configuredChatId));
            if (!in_array((string)$chatId, $allowedChatIds)) {
                // Ignore messages from unauthorized chats
                http_response_code(200);
                exit;
            }
        }

        // Handle /check command
        if (strtolower($text) === '/check' || strtolower($text) === 'check') {
            $report = generateDailyReport($conn);
            sendTelegramMessage($chatId, $report, $conn);
        }
        // Add more commands here if needed
        // elseif (strtolower($text) === '/help') { ... }
    }

    http_response_code(200);
} catch (Exception $e) {
    // Log error but still return 200 to Telegram
    // file_put_contents('telegram_error.txt', date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(200);
}
?>