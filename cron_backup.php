<?php
/**
 * Script tự động sao lưu Cơ sở dữ liệu bằng Cron Job.
 * Sử dụng thuần PHP (không dùng hàm exec) để tương thích 100% Hosting.
 */

require_once __DIR__ . '/config.php';

// 1. Thư mục lưu trữ backup trên hosting
$backup_dir = __DIR__ . '/backups/';

// Tạo thư mục nếu chưa tồn tại
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
    
    // Tạo file .htaccess để chặn người lạ tải trộm file backup
    file_put_contents($backup_dir . '.htaccess', "Require all denied\n");
}

// 2. Thiết lập tên file backup
$timestamp = date('Y-m-d_H-i-s');
$backup_file = $backup_dir . $db_name . '_' . $timestamp . '.sql';

try {
    if (!isset($conn)) {
        throw new Exception("Không tìm thấy kết nối PDO (\$conn) từ config.php");
    }

    $sqlScript = "-- =========================================================\n";
    $sqlScript .= "-- BẢN SAO LƯU CƠ SỞ DỮ LIỆU TỰ ĐỘNG (THUẦN PHP)\n";
    $sqlScript .= "-- Thời gian tạo: " . date('Y-m-d H:i:s') . "\n";
    $sqlScript .= "-- Database: " . $db_name . "\n";
    $sqlScript .= "-- =========================================================\n\n";

    $sqlScript .= "SET NAMES utf8mb4;\n";
    $sqlScript .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    // Lấy danh sách tất cả các bảng
    $tables = [];
    $stmt = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    foreach ($tables as $table) {
        $sqlScript .= "-- --------------------------------------------------------\n";
        $sqlScript .= "-- Cấu trúc bảng `$table` \n";
        $sqlScript .= "-- --------------------------------------------------------\n";
        
        $stmtCreate = $conn->query("SHOW CREATE TABLE `$table`");
        $rowCreate = $stmtCreate->fetch(PDO::FETCH_ASSOC);
        $sqlScript .= "DROP TABLE IF EXISTS `$table`;\n";
        $sqlScript .= $rowCreate['Create Table'] . ";\n\n";

        $sqlScript .= "-- Dữ liệu của bảng `$table` \n";
        
        $stmtData = $conn->query("SELECT * FROM `$table`");
        $rowCount = $stmtData->rowCount();

        if ($rowCount > 0) {
            // Tối ưu chèn nhiều dòng một lúc (bulk insert) thay vì từng dòng một
            $insert_query = "";
            $counter = 0;
            
            while ($row = $stmtData->fetch(PDO::FETCH_ASSOC)) {
                $keys = array_keys($row);
                $values = array_values($row);
                
                $formattedValues = [];
                foreach ($values as $value) {
                    if (is_null($value)) {
                        $formattedValues[] = 'NULL';
                    } else {
                        // Dùng $conn->quote() để tự động escape chuỗi an toàn tuyệt đối
                        $formattedValues[] = $conn->quote($value);
                    }
                }
                
                $keysString = "`" . implode("`, `", $keys) . "`";
                $valuesString = implode(", ", $formattedValues);
                
                if ($counter % 100 == 0) {
                    if ($counter > 0) $insert_query .= ";\n";
                    $insert_query .= "INSERT INTO `$table` ($keysString) VALUES \n($valuesString)";
                } else {
                    $insert_query .= ",\n($valuesString)";
                }
                $counter++;
            }
            if ($insert_query != "") {
                $insert_query .= ";\n\n";
                $sqlScript .= $insert_query;
            }
        }
    }

    $sqlScript .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    // 3. Ghi ra file
    if (file_put_contents($backup_file, $sqlScript) !== false) {
        echo "✅ <b style='color:green;'>Lưu BACKUP THÀNH CÔNG</b> tại: " . basename($backup_file) . "<br>";
        
        // 4. Tự động xoá file backup quá 7 ngày
        $files = glob($backup_dir . '*.sql');
        $now = time();
        $days_to_keep = 7; 
        
        foreach ($files as $file) {
            if (is_file($file)) {
                if ($now - filemtime($file) >= 60 * 60 * 24 * $days_to_keep) { 
                    unlink($file);
                    echo "🗑️ Đã xóa bản backup cũ: " . basename($file) . "<br>";
                }
            }
        }
    } else {
        echo "❌ <b style='color:red;'>LỖI:</b> Không thể ghi file backup. Vui lòng kiểm tra quyền (CHMOD) thư mục backups.<br>";
    }

} catch (Exception $e) {
    echo "❌ <b style='color:red;'>LỖI:</b> Backup thất bại. Chi tiết: <br>" . $e->getMessage();
}
?>
