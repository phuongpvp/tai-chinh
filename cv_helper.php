<?php
/**
 * CV Auto Transfer Helper
 * Gọi SAU KHI kế toán đóng lãi/tất toán → kiểm tra nợ → chuyển phòng CV
 * 
 * Logic:
 * - Đóng đủ lãi kỳ (next_payment_date > hôm nay) → vào "Đã hoàn thành"
 *   (dù đang ở Tín dụng 1 hay chưa ở CV — vì đóng sớm cũng cần tracking)
 * - Còn nợ kỳ (next_payment_date <= hôm nay) → giữ nguyên (CRON sẽ assign)
 * - Tất toán (status = closed) → vào "Đã hoàn thành"
 * - Đã ở "Đã hoàn thành" rồi → bỏ qua
 */
function cvAutoTransferOnPayment($conn, $loanId, $userId = null) {
    try {
        $stmt = $conn->prepare("
            SELECT l.cv_room_id, l.next_payment_date, l.status, l.store_id, s.name as store_name
            FROM loans l
            LEFT JOIN stores s ON l.store_id = s.id
            WHERE l.id = ?
        ");
        $stmt->execute([$loanId]);
        $loan = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$loan) return;
        
        $today = date('Y-m-d');
        $oldRoomId = !empty($loan['cv_room_id']) ? intval($loan['cv_room_id']) : 0;
        
        // Tìm phòng "Đã hoàn thành"
        $htRoom = $conn->query("SELECT id FROM cv_rooms WHERE name LIKE '%hoàn thành%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$htRoom) return;
        $htRoomId = intval($htRoom['id']);
        
        // Đã ở "Đã hoàn thành" rồi → bỏ qua
        if ($oldRoomId === $htRoomId) return;
        
        // === KIỂM TRA: đã đóng đủ lãi kỳ này chưa? ===
        $shouldComplete = false;
        
        if ($loan['status'] === 'closed') {
            // Tất toán → luôn hoàn thành
            $shouldComplete = true;
        } elseif (!empty($loan['next_payment_date']) && $loan['next_payment_date'] > $today) {
            // next_payment_date > hôm nay = đã đóng đủ kỳ hiện tại
            $shouldComplete = true;
        }
        // Nếu next_payment_date <= hôm nay = còn nợ → KHÔNG chuyển
        
        if ($shouldComplete) {
            $companyTag = $loan['store_name'] ?? '';
            
            if ($oldRoomId > 0) {
                // Đang ở CV (Tín dụng 1) → chuyển sang Đã hoàn thành
                $conn->prepare("UPDATE loans SET cv_room_id = ?, cv_transfer_date = ?, cv_company_tag = IFNULL(NULLIF(cv_company_tag,''), ?) WHERE id = ?")
                     ->execute([$htRoomId, $today, $companyTag, $loanId]);
                $conn->prepare("INSERT INTO cv_transfer_logs (loan_id, from_room_id, to_room_id, transferred_by, note) VALUES (?, ?, ?, ?, ?)")
                     ->execute([$loanId, $oldRoomId, $htRoomId, $userId, 'Tự động: Đã đóng đủ lãi kỳ này']);
            } else {
                // Chưa ở CV (đóng sớm trước hạn) → thêm thẳng vào Đã hoàn thành
                $conn->prepare("UPDATE loans SET cv_room_id = ?, cv_transfer_date = ?, cv_status = 'active', cv_company_tag = IFNULL(NULLIF(cv_company_tag,''), ?) WHERE id = ?")
                     ->execute([$htRoomId, $today, $companyTag, $loanId]);
                $conn->prepare("INSERT INTO cv_transfer_logs (loan_id, from_room_id, to_room_id, transferred_by, note) VALUES (?, NULL, ?, ?, ?)")
                     ->execute([$loanId, $htRoomId, $userId, 'Tự động: Đóng lãi sớm → Đã hoàn thành']);
            }
        }
        // Còn nợ → không làm gì, CRON sẽ assign vào Tín dụng 1 khi đến hạn
             
    } catch (Exception $e) {
        // Lỗi CV → bỏ qua, payment đã lưu
    }
}
