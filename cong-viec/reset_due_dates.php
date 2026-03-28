<?php
/**
 * Reset cv_due_date cho tất cả loans đã assign vào phòng CV
 * Tính lại = hôm nay + SLA phòng
 * Chạy 1 lần rồi XÓA
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/config.php';

$today = date('Y-m-d');
echo "<h2>Reset cv_due_date theo SLA phòng</h2><pre>";

// Lấy tất cả phòng có SLA
$rooms = $pdo->query("SELECT id, name, sla_days FROM cv_rooms WHERE is_archive = 0")->fetchAll(PDO::FETCH_ASSOC);

$total = 0;
foreach ($rooms as $room) {
    $sla = intval($room['sla_days'] ?? 3);
    $dueDate = date('Y-m-d', strtotime("+{$sla} days"));
    
    $stmt = $pdo->prepare("UPDATE loans SET cv_due_date = ?, cv_transfer_date = ? WHERE cv_room_id = ? AND cv_status = 'active' AND status != 'closed'");
    $stmt->execute([$dueDate, $today, $room['id']]);
    $count = $stmt->rowCount();
    $total += $count;
    
    echo "📁 {$room['name']} (SLA: {$sla} ngày) → Hạn mới: {$dueDate} — {$count} khách đã reset\n";
}

echo "\n=== DONE: {$total} khách đã được reset cv_due_date ===\n";
echo "</pre>";
echo "<p><b>⚠️ XÓA file này sau khi chạy!</b></p>";
echo "<p><a href='index.php'>← Về trang chủ</a></p>";
