<?php
require_once 'config.php';
echo "<h2>🗑️ Xóa sạch dữ liệu...</h2><pre>\n";
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
$pdo->exec("DELETE FROM work_logs"); echo "✅ Xóa work_logs\n";
$pdo->exec("DELETE FROM transfer_logs"); echo "✅ Xóa transfer_logs\n";
$pdo->exec("DELETE FROM customer_files"); echo "✅ Xóa customer_files\n";
$pdo->exec("DELETE FROM comments"); echo "✅ Xóa comments\n";
$pdo->exec("DELETE FROM violations"); echo "✅ Xóa violations\n";
$pdo->exec("DELETE FROM customers"); echo "✅ Xóa customers\n";
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");
echo "\n✅ Đã xóa sạch toàn bộ!\n⚠️ XÓA FILE NÀY SAU KHI CHẠY!</pre>";
