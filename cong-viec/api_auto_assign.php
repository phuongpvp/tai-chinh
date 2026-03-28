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
    // 0) Backfill: Cập nhật cv_company_tag cho loans đã ở CV nhưng chưa có tag
    $pdo->exec("UPDATE loans l JOIN stores s ON l.store_id = s.id SET l.cv_company_tag = s.name WHERE l.cv_room_id IS NOT NULL AND l.cv_room_id > 0 AND (l.cv_company_tag IS NULL OR l.cv_company_tag = '')");

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
          AND l.next_payment_date = :today
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

    $results['success'] = true;
    
} catch (Exception $e) {
    $results['success'] = false;
    $results['error'] = $e->getMessage();
}

echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
