<?php
/**
 * AI Đánh giá khách hàng — OpenRouter API (free models)
 * AJAX endpoint: nhận loan_id → gom data → gửi AI → trả kết quả
 */
require_once 'config.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

// === API KEY ===
$API_KEY = trim($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'gemini_api_key' LIMIT 1")->fetchColumn() ?: '');
if (!$API_KEY) {
    echo json_encode(['error' => 'Chưa cấu hình API Key. Vào Cài đặt AI → dán key từ openrouter.ai']);
    exit;
}

$loanId = intval($_POST['loan_id'] ?? 0);
$customPrompt = trim($_POST['prompt'] ?? '');

if (!$loanId) {
    echo json_encode(['error' => 'Thiếu loan_id']);
    exit;
}

// === 1. GOM DỮ LIỆU KHÁCH ===

// Thông tin cơ bản
$stmt = $pdo->prepare("SELECT c.name, c.phone, c.address, 
    l.loan_code, l.amount as loan_amount, l.interest_rate, l.interest_type, l.period_days,
    l.next_payment_date, l.status as loan_status, l.created_at as loan_date,
    l.cv_company_tag as company_tag,
    r.name as room_name
    FROM loans l
    LEFT JOIN customers c ON l.customer_id = c.id
    LEFT JOIN cv_rooms r ON l.cv_room_id = r.id
    WHERE l.id = ?");
$stmt->execute([$loanId]);
$info = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$info) {
    echo json_encode(['error' => 'Không tìm thấy khách']);
    exit;
}

// Nhật ký làm việc (30 bản gần nhất)
$wlStmt = $pdo->prepare("SELECT wl.log_date, wl.action_type, wl.result_type, wl.work_done, 
    wl.promise_date, wl.amount, wl.amount_principal, u.fullname as staff, r.name as room
    FROM cv_work_logs wl 
    LEFT JOIN users u ON wl.user_id = u.id
    LEFT JOIN cv_rooms r ON wl.room_id = r.id
    WHERE wl.loan_id = ? 
    ORDER BY wl.log_date DESC, wl.created_at DESC LIMIT 30");
$wlStmt->execute([$loanId]);
$workLogs = $wlStmt->fetchAll(PDO::FETCH_ASSOC);

// Lịch sử chuyển phòng
$tlStmt = $pdo->prepare("SELECT tl.transferred_at, 
    fr.name as from_room, tr.name as to_room, tl.note
    FROM cv_transfer_logs tl
    LEFT JOIN cv_rooms fr ON tl.from_room_id = fr.id
    LEFT JOIN cv_rooms tr ON tl.to_room_id = tr.id
    WHERE tl.loan_id = ? 
    ORDER BY tl.transferred_at DESC LIMIT 20");
$tlStmt->execute([$loanId]);
$transfers = $tlStmt->fetchAll(PDO::FETCH_ASSOC);

// Giao dịch tài chính (10 gần nhất)
$txStmt = $pdo->prepare("SELECT t.date, t.type, t.amount, t.note 
    FROM transactions t WHERE t.loan_id = ? 
    ORDER BY COALESCE(t.created_at, t.date) DESC LIMIT 10");
$txStmt->execute([$loanId]);
$transactions = $txStmt->fetchAll(PDO::FETCH_ASSOC);

// === 2. CHUẨN BỊ DATA TEXT ===
$dataText = "=== THÔNG TIN KHÁCH HÀNG ===\n";
$dataText .= "Tên: {$info['name']}\n";
$dataText .= "SĐT: {$info['phone']}\n";
$dataText .= "Địa chỉ: {$info['address']}\n";
$dataText .= "Công ty: {$info['company_tag']}\n";
$dataText .= "Phòng hiện tại: {$info['room_name']}\n";
$dataText .= "Mã khoản vay: {$info['loan_code']}\n";
$dataText .= "Số tiền vay: " . number_format($info['loan_amount'], 0, ',', '.') . "đ\n";
$dataText .= "Lãi suất: {$info['interest_rate']}%\n";
$dataText .= "Kỳ hạn: {$info['period_days']} ngày\n";
$dataText .= "Ngày đóng lãi tiếp: {$info['next_payment_date']}\n";
$dataText .= "Trạng thái vay: {$info['loan_status']}\n";
$dataText .= "Ngày vay: {$info['loan_date']}\n\n";

if ($workLogs) {
    $wlCount = count($workLogs);
    $dataText .= "=== NHẬT KÝ LÀM VIỆC ({$wlCount} bản ghi gần nhất) ===\n";
    foreach ($workLogs as $i => $wl) {
        $n = $i + 1;
        $dataText .= "{$n}. [{$wl['log_date']}] Phòng: {$wl['room']} | NV: {$wl['staff']} | Việc: {$wl['action_type']} | KQ: {$wl['result_type']}";
        if ($wl['work_done']) $dataText .= " | Ghi chú: {$wl['work_done']}";
        if ($wl['promise_date']) $dataText .= " | Hẹn: {$wl['promise_date']}";
        if ($wl['amount']) $dataText .= " | Lãi: " . number_format($wl['amount'], 0, ',', '.') . "đ";
        if ($wl['amount_principal']) $dataText .= " | Gốc: " . number_format($wl['amount_principal'], 0, ',', '.') . "đ";
        $dataText .= "\n";
    }
    $dataText .= "\n";
}

if ($transfers) {
    $dataText .= "=== LỊCH SỬ CHUYỂN PHÒNG ===\n";
    foreach ($transfers as $tl) {
        $dataText .= "[{$tl['transferred_at']}] {$tl['from_room']} → {$tl['to_room']}";
        if ($tl['note']) $dataText .= " ({$tl['note']})";
        $dataText .= "\n";
    }
    $dataText .= "\n";
}

if ($transactions) {
    $dataText .= "=== GIAO DỊCH TÀI CHÍNH (gần nhất) ===\n";
    foreach ($transactions as $tx) {
        $dataText .= "[{$tx['date']}] {$tx['type']}: " . number_format($tx['amount'], 0, ',', '.') . "đ";
        if ($tx['note']) $dataText .= " ({$tx['note']})";
        $dataText .= "\n";
    }
}

// === 3. TẠO PROMPT ===
$defaultPrompt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'gemini_prompt' LIMIT 1")->fetchColumn();
if (!$defaultPrompt) {
    $defaultPrompt = "Bạn là chuyên gia QUẢN TRỊ RỦI RO TÀI CHÍNH. Hãy phân tích khách hàng một cách chuyên nghiệp, dùng ngôn ngữ Thu hồi nợ chuẩn Việt Nam.

QUY TẮC NGÔN NGỮ:
- KHÔNG dùng: 'nhà chăm sóc tài chính', 'rầu khỏe', 'đảo đổi', 'lời trích'.
- DÙNG: 'Nhân viên xử lý', 'Lịch sử thanh toán', 'Tình trạng nợ', 'Thiện chí trả nợ'.

Dựa trên dữ liệu, hãy đánh giá (Nếu không có dữ liệu thì báo 'Chưa có thông tin'):

1. **Chỉ số tín nhiệm** (Tốt / Trung bình / Thấp) — khách có thiện chí giữ liên lạc và đúng hẹn không?
2. **Khả năng thanh toán** (Cao / Trung bình / Thấp) — dựa trên lịch sử nộp tiền thực tế.
3. **Cảnh báo rủi ro** — dấu hiệu mất liên lạc, hứa nhưng không thực hiện...
4. **Giải pháp đề xuất** — cần nhắc nhở nhẹ hay tăng cường tần suất làm việc?

Trả lời bằng Tiếng Việt Tự Nhiên, ngắn gọn, có emoji.";
}

$prompt = $customPrompt ?: $defaultPrompt;
$fullPrompt = $prompt . "\n\n" . $dataText;

// === 4. GỌI OPENROUTER API ===
$url = "https://openrouter.ai/api/v1/chat/completions";

$payload = json_encode([
    'model' => 'openrouter/free',
    'messages' => [
        ['role' => 'user', 'content' => $fullPrompt]
    ],
    'max_tokens' => 1024,
    'temperature' => 0.7,
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $API_KEY,
        'HTTP-Referer: https://taichinh.motmot.vip',
        'X-Title: Tai Chinh AI'
    ],
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(['error' => 'Lỗi kết nối: ' . $err]);
    exit;
}

$result = json_decode($response, true);

if ($httpCode !== 200) {
    $errMsg = $result['error']['message'] ?? ($result['error'] ?? 'HTTP ' . $httpCode);
    if (is_array($errMsg)) $errMsg = json_encode($errMsg);
    echo json_encode(['error' => 'AI API lỗi: ' . $errMsg]);
    exit;
}

$aiText = $result['choices'][0]['message']['content'] ?? '';

if (!$aiText) {
    echo json_encode(['error' => 'AI không trả kết quả']);
    exit;
}

echo json_encode([
    'success' => true,
    'analysis' => $aiText,
    'data_summary' => [
        'worklogs' => count($workLogs),
        'transfers' => count($transfers),
        'transactions' => count($transactions)
    ]
]);
