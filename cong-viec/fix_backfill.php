<?php
/**
 * FIX: Undo backfill sai — reset cv_room_id cho loans bị gán sai vào "Đã hoàn thành"
 * Chạy 1 lần: https://taichinh.motmot.vip/cong-viec/fix_backfill.php?key=cv_auto_assign_2024_secret
 */
header('Content-Type: application/json; charset=utf-8');

$key = $_GET['key'] ?? '';
if ($key !== 'cv_auto_assign_2024_secret') {
    die(json_encode(['error' => 'Unauthorized']));
}

require_once __DIR__ . '/config.php';

$results = ['fixed' => 0, 'details' => []];

try {
    // Tìm phòng "Đã hoàn thành"
    $htRoom = $pdo->query("SELECT id FROM cv_rooms WHERE name LIKE '%hoàn thành%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$htRoom) die(json_encode(['error' => 'Không tìm thấy phòng']));
    $htRoomId = intval($htRoom['id']);

    // Tìm tất cả loans bị backfill gán sai (có log "Backfill:")
    $stmt = $pdo->prepare("
        SELECT DISTINCT tl.loan_id, c.name as customer_name
        FROM cv_transfer_logs tl
        JOIN loans l ON tl.loan_id = l.id
        LEFT JOIN customers c ON l.customer_id = c.id
        WHERE tl.note LIKE 'Backfill:%'
          AND tl.from_room_id IS NULL
          AND l.cv_room_id = ?
    ");
    $stmt->execute([$htRoomId]);
    $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($loans as $loan) {
        // Reset: bỏ khỏi CV
        $pdo->prepare("UPDATE loans SET cv_room_id = NULL, cv_status = NULL, cv_transfer_date = NULL, cv_due_date = NULL WHERE id = ?")
             ->execute([$loan['loan_id']]);
        
        // Xóa log backfill sai
        $pdo->prepare("DELETE FROM cv_transfer_logs WHERE loan_id = ? AND note LIKE 'Backfill:%' AND from_room_id IS NULL")
             ->execute([$loan['loan_id']]);

        $results['fixed']++;
        $results['details'][] = $loan['customer_name'];
    }

    $results['success'] = true;
} catch (Exception $e) {
    $results['error'] = $e->getMessage();
}

echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
