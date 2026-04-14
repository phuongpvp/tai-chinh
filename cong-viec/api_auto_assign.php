<?php
/**
 * API CRON: Tự động đẩy khách đến ngày đóng lãi vào phòng "Tín dụng 1"
 * 
 * Gọi hàng ngày (CRON hoặc URL):
 *   curl https://taichinh.motmot.vip/cong-viec/api_auto_assign.php?key=YOUR_SECRET_KEY
 * 
 * Logic:
 * - Lấy tất cả loans active có next_payment_date <= hôm nay
 * - Nếu chưa có trong phòng CV nào → assign vào "Tín dụng 1"
 * - Nếu đã ở phòng rồi → bỏ qua
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

$isCLI = (php_sapi_name() === 'cli');

if (!$isCLI) {
    header('Content-Type: application/json; charset=utf-8');
    
    // Secret key bảo vệ khi gọi qua URL
    $API_KEY = 'cv_auto_assign_2024_secret';
    if (($_GET['key'] ?? '') !== $API_KEY) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid API key']);
        exit;
    }
}

require_once __DIR__ . '/config.php';

$today = date('Y-m-d');
$results = ['date' => $today, 'assigned' => 0, 'skipped' => 0, 'errors' => 0, 'details' => []];

try {
    // 0) Sync: Luôn cập nhật cv_company_tag = đúng tên cửa hàng bên TC
    $pdo->exec("UPDATE loans l JOIN stores s ON l.store_id = s.id SET l.cv_company_tag = s.name WHERE l.cv_room_id IS NOT NULL AND l.cv_room_id > 0");

    // 0b) Sync: Cập nhật cv_tc_info = tóm tắt thông tin tài chính cho khách đang ở phòng CV
    $tcLoans = $pdo->query("
        SELECT l.id, l.loan_code, l.amount, l.interest_rate, l.interest_type, 
               l.period_days, l.next_payment_date, s.name as store_name
        FROM loans l
        LEFT JOIN stores s ON l.store_id = s.id
        WHERE l.cv_room_id IS NOT NULL AND l.cv_room_id > 0
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $tcUpdateStmt = $pdo->prepare("UPDATE loans SET cv_tc_info = ? WHERE id = ?");
    foreach ($tcLoans as $tcl) {
        $p = floatval($tcl['amount']);
        $rate = floatval($tcl['interest_rate']);
        $type = $tcl['interest_type'] ?? '';
        $days = (!empty($tcl['period_days']) && $tcl['period_days'] > 0) ? intval($tcl['period_days']) : 30;
        if ($type === 'ngay' || $rate > 100) {
            $mult = ($rate < 500) ? 1000 : 1;
            $interest = ($p / 1000000) * ($rate * $mult) * $days;
        } else {
            $interest = ($p * ($rate / 100)) / 30 * $days;
        }
        $info = "Mã HĐ: " . ($tcl['loan_code'] ?? '—');
        $info .= "\nKhoản vay: " . number_format($p, 0, ',', '.') . "đ";
        $info .= "\nLãi kỳ này: " . number_format($interest, 0, ',', '.') . "đ";
        $info .= "\nNgày đóng: " . ($tcl['next_payment_date'] ?? '—');
        $info .= "\nCửa hàng: " . ($tcl['store_name'] ?? '—');
        $tcUpdateStmt->execute([$info, $tcl['id']]);
    }
    $results['tc_info_synced'] = count($tcLoans);

    // 1) Tìm phòng "Tín dụng 1"
    $roomStmt = $pdo->query("SELECT id, name, sla_days FROM cv_rooms WHERE name LIKE '%Tín dụng 1%' LIMIT 1");
    $targetRoom = $roomStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$targetRoom) {
        $roomStmt = $pdo->query("SELECT id, name, sla_days FROM cv_rooms WHERE is_archive = 0 ORDER BY sort_order, id LIMIT 1");
        $targetRoom = $roomStmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$targetRoom) {
        echo json_encode(['error' => 'Không tìm thấy phòng CV nào']);
        exit;
    }
    
    $targetRoomId = $targetRoom['id'];
    $slaDays = intval($targetRoom['sla_days'] ?? 3);
    $results['target_room'] = $targetRoom['name'];
    $results['sla_days'] = $slaDays;
    
    // Tính cv_due_date = hôm nay + SLA phòng
    $dueDate = date('Y-m-d', strtotime("+{$slaDays} days"));
    $results['due_date'] = $dueDate;
    
    // 2) Lấy loans active có ngày đóng lãi ĐÚNG HÔM NAY và chưa ở phòng CV nào
    $stmt = $pdo->prepare("
        SELECT l.id, l.loan_code, l.amount, l.next_payment_date, l.status,
               c.name as customer_name, c.phone as customer_phone,
               l.cv_room_id, l.cv_status, s.name as store_name
        FROM loans l
        LEFT JOIN customers c ON l.customer_id = c.id
        LEFT JOIN stores s ON l.store_id = s.id
        WHERE l.status = 'active'
          AND l.next_payment_date IS NOT NULL
          AND l.next_payment_date <= :today
          AND (l.cv_room_id IS NULL OR l.cv_room_id = 0)
        ORDER BY l.next_payment_date ASC
    ");
    $stmt->execute(['today' => $today]);
    $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results['total_due'] = count($loans);
    
    // 3) Assign từng loan vào phòng — hạn = hôm nay + SLA phòng
    $assignStmt = $pdo->prepare("
        UPDATE loans SET 
            cv_room_id = ?,
            cv_status = COALESCE(cv_status, 'active'),
            cv_due_date = ?,
            cv_transfer_date = ?,
            cv_company_tag = COALESCE(cv_company_tag, ?)
        WHERE id = ?
    ");
    
    foreach ($loans as $loan) {
        try {
            $assignStmt->execute([$targetRoomId, $dueDate, $today, $loan['store_name'] ?? '', $loan['id']]);
            $results['assigned']++;
            $results['details'][] = [
                'loan_id' => $loan['id'],
                'loan_code' => $loan['loan_code'],
                'customer' => $loan['customer_name'],
                'phone' => $loan['customer_phone'],
                'payment_date' => $loan['next_payment_date'],
                'status' => 'assigned'
            ];
        } catch (Exception $e) {
            $results['errors']++;
            $results['details'][] = [
                'loan_id' => $loan['id'],
                'customer' => $loan['customer_name'],
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    // 4) Thống kê thêm: bao nhiêu loan đã ở phòng rồi
    $alreadyStmt = $pdo->prepare("
        SELECT COUNT(*) FROM loans 
        WHERE status = 'active' 
          AND next_payment_date <= :today
          AND cv_room_id IS NOT NULL AND cv_room_id > 0
    ");
    $alreadyStmt->execute(['today' => $today]);
    $results['already_in_room'] = intval($alreadyStmt->fetchColumn());
    
    // 5) Chuyển loans từ "Đã hoàn thành" QUAY LẠI "Tín dụng 1" nếu đến hạn đóng lãi mới
    $htRoom = $pdo->query("SELECT id FROM cv_rooms WHERE name LIKE '%hoàn thành%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $results['moved_back'] = 0;
    if ($htRoom) {
        $htRoomId = intval($htRoom['id']);
        $moveBackStmt = $pdo->prepare("
            SELECT l.id, c.name as customer_name
            FROM loans l
            LEFT JOIN customers c ON l.customer_id = c.id
            WHERE l.status = 'active'
              AND l.cv_room_id = ?
              AND l.next_payment_date IS NOT NULL
              AND l.next_payment_date <= ?
              AND l.next_payment_date > COALESCE(l.cv_transfer_date, '2000-01-01')
        ");
        $moveBackStmt->execute([$htRoomId, $today]);
        $moveBackLoans = $moveBackStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($moveBackLoans as $mbl) {
            $pdo->prepare("UPDATE loans SET cv_room_id = ?, cv_due_date = ?, cv_transfer_date = ? WHERE id = ?")
                 ->execute([$targetRoomId, $dueDate, $today, $mbl['id']]);
            $pdo->prepare("INSERT INTO cv_transfer_logs (loan_id, from_room_id, to_room_id, transferred_by, note) VALUES (?, ?, ?, NULL, ?)")
                 ->execute([$mbl['id'], $htRoomId, $targetRoomId, 'CRON: Đến hạn đóng lãi mới']);
            $results['moved_back']++;
            $results['details'][] = [
                'customer' => $mbl['customer_name'],
                'status' => 'moved_back_from_completed'
            ];
        }
    }

    // 6) Hoàn trả khách về phòng gốc trước 1 ngày hẹn
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $remindStmt = $pdo->prepare("
        SELECT w.loan_id, w.room_id as target_room_id, l.cv_room_id as current_room_id, 
               r.name as target_room_name, c.name as customer_name, r.sla_days,
               w.created_at as worklog_time
        FROM cv_work_logs w
        JOIN loans l ON w.loan_id = l.id
        LEFT JOIN customers c ON l.customer_id = c.id
        LEFT JOIN cv_rooms r ON w.room_id = r.id
        WHERE w.promise_date = :tomorrow
          AND l.status = 'active'
          AND (l.cv_room_id != w.room_id OR l.cv_room_id IS NULL)
          AND w.id = (
              SELECT MAX(id) 
              FROM cv_work_logs 
              WHERE loan_id = w.loan_id AND promise_date IS NOT NULL
          )
    ");
    $remindStmt->execute(['tomorrow' => $tomorrow]);
    $remindLoans = $remindStmt->fetchAll(PDO::FETCH_ASSOC);

    $results['auto_remind_moves'] = 0;
    
    // Chuẩn bị truy vấn kiểm tra hồ sơ có bị dịch chuyển hay không
    $countStmt = $pdo->prepare("
        SELECT COUNT(id) FROM cv_transfer_logs 
        WHERE loan_id = ? AND transferred_at > DATE_ADD(?, INTERVAL 1 HOUR)
    ");

    foreach ($remindLoans as $rl) {
        $loanId = $rl['loan_id'];
        
        // Kiểm tra xem khách có 'nằm lỳ' hay đã chuyển đò đi nơi khác
        $countStmt->execute([$loanId, $rl['worklog_time']]);
        $subsequentMoves = intval($countStmt->fetchColumn());
        
        if ($subsequentMoves >= 1) {
            // Hồ sơ đã có sự dịch chuyển phòng mới ở một thời điểm khác (sau > 1 giờ) -> Không kéo về nữa
            continue;
        }

        $fromRoomId = $rl['current_room_id'] ?: 0;
        $targetRoomId = $rl['target_room_id'];
        $newSlaDays = intval($rl['sla_days'] ?? 0);
        $newDueDate = $newSlaDays > 0 ? date('Y-m-d', strtotime("+{$newSlaDays} days")) : null;
        
        $pdo->prepare("UPDATE loans SET cv_room_id = ?, cv_transfer_date = ?, cv_due_date = ?, cv_planned_next_room_id = NULL WHERE id = ?")
             ->execute([$targetRoomId, $today, $newDueDate, $loanId]);
             
        $note = "Tự động hoàn trả về theo Cài đặt ngày hẹn làm việc (Hẹn ngày " . date('d/m/Y', strtotime($tomorrow)) . ").";
             
        $pdo->prepare("INSERT INTO cv_transfer_logs (loan_id, from_room_id, to_room_id, transferred_by, note, deadline_status) VALUES (?, ?, ?, NULL, ?, NULL)")
             ->execute([$loanId, $fromRoomId, $targetRoomId, $note]);
             
        $results['auto_remind_moves']++;
        $results['details'][] = [
            'customer' => $rl['customer_name'],
            'status' => 'auto_remind_to_room',
            'room' => $rl['target_room_name']
        ];
    }

    // 7) Gửi báo cáo Telegram tự động
    try {
        require_once __DIR__ . '/../telegram_helper.php';
        $conn = $pdo; // telegram_helper dùng $conn
        
        $stmt_chat = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'telegram_chat_id'");
        $stmt_chat->execute();
        $chat_row = $stmt_chat->fetch(PDO::FETCH_ASSOC);
        
        if ($chat_row && !empty($chat_row['setting_value'])) {
            $chat_id = $chat_row['setting_value'];
            $report = generateDailyReport($conn);
            
            $header = "📋 *BÁO CÁO TỰ ĐỘNG - " . date('d/m/Y') . "*\n";
            $header .= "🏠 Đã đẩy vào phòng: " . ($results['assigned'] ?? 0) . " khách\n";
            $header .= "🔄 Chuyển lại từ HT: " . ($results['moved_back'] ?? 0) . " khách\n";
            $header .= "⏰ Tự hoàn trả nhắc hẹn: " . ($results['auto_remind_moves'] ?? 0) . " khách\n";
            $header .= "--------------------\n\n";
            
            $chat_ids = array_filter(array_map('trim', explode(',', $chat_id)));
            foreach ($chat_ids as $cid) {
                sendTelegramMessage($cid, $header . $report, $conn);
            }
        } else {
            $results['telegram_sent'] = false;
            $results['telegram_note'] = 'Chat ID chưa cấu hình';
        }
    } catch (Exception $e) {
        $results['telegram_sent'] = false;
        $results['telegram_error'] = $e->getMessage();
    }

    $results['success'] = true;
    
} catch (Exception $e) {
    $results['success'] = false;
    $results['error'] = $e->getMessage();
}

echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
