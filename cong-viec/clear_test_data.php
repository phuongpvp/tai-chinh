<?php
/**
 * Xóa dữ liệu test CV - Reset tất cả loans về chưa assign phòng
 * Chạy 1 lần rồi XÓA
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/config.php';

echo "<h2>Xóa dữ liệu test CV</h2><pre>";

// Reset tất cả loans: xóa cv_room_id, cv_due_date, cv_status, cv_transfer_date
$stmt = $pdo->query("UPDATE loans SET cv_room_id = NULL, cv_due_date = NULL, cv_status = NULL, cv_transfer_date = NULL WHERE cv_room_id IS NOT NULL");
$count = $stmt->rowCount();
echo "✅ Đã reset {$count} loans (xóa phòng CV, hạn, trạng thái)\n";

echo "\n=== DONE ===\n";
echo "Mai 6h sáng CRON sẽ tự đẩy khách đến hạn vào phòng Tín dụng 1.\n";
echo "</pre>";
echo "<p><b>⚠️ XÓA file này sau khi chạy!</b></p>";
echo "<p><a href='index.php'>← Về trang chủ</a></p>";
