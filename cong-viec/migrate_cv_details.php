<?php
/**
 * Migrate dữ liệu khách hàng từ DB cũ (congviec_dseer) sang DB mới (TC)
 * Copy: facebook_link, workplace, hktt, relatives_info, company_tag, description, pinned_note, tc_info
 * Map bằng tên + SĐT khách hàng
 * Chạy 1 lần rồi XÓA
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/config.php';

echo "<h2>Migrate dữ liệu CV cũ → TC</h2><pre>";

// Kết nối DB cũ
try {
    $oldDb = new PDO(
        "mysql:host=localhost;dbname=congviec_dseer;charset=utf8mb4",
        "congviec_dseer",
        "Z93PUwj1q",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    echo "✅ Kết nối DB cũ (congviec_dseer) thành công\n\n";
} catch (Exception $e) {
    die("❌ Không kết nối được DB cũ: " . $e->getMessage());
}

// Lấy tất cả khách từ DB cũ
$oldCustomers = $oldDb->query("SELECT * FROM customers")->fetchAll();
echo "📋 Tổng khách DB cũ: " . count($oldCustomers) . "\n\n";

// Lấy tất cả loans + customers từ TC
$tcData = $pdo->query("
    SELECT l.id as loan_id, c.id as customer_id, c.name, c.phone, c.identity_card
    FROM loans l 
    JOIN customers c ON l.customer_id = c.id
    WHERE l.status = 'active'
")->fetchAll();

echo "📋 Tổng loans TC: " . count($tcData) . "\n\n";

$matched = 0;
$notMatched = 0;
$updated = 0;

// Chuẩn bị UPDATE statement
$updateStmt = $pdo->prepare("UPDATE loans SET 
    cv_facebook_link = ?, cv_workplace = ?, cv_hktt = ?, 
    cv_relatives_info = ?, cv_company_tag = ?,
    cv_description = COALESCE(cv_description, ?),
    cv_pinned_note = COALESCE(cv_pinned_note, ?),
    cv_tc_info = ?
    WHERE id = ?");

foreach ($oldCustomers as $old) {
    $oldName = trim($old['name'] ?? '');
    $oldPhone = trim($old['phone'] ?? '');
    
    if (empty($oldName)) continue;
    
    // Tìm loan match bằng tên (và phone nếu có)
    $match = null;
    foreach ($tcData as $tc) {
        $tcName = trim($tc['name'] ?? '');
        
        // Match chính xác theo tên
        if (mb_strtolower($oldName) === mb_strtolower($tcName)) {
            $match = $tc;
            break;
        }
    }
    
    // Nếu không match tên, thử phone
    if (!$match && !empty($oldPhone)) {
        $cleanOldPhone = preg_replace('/[^0-9]/', '', $oldPhone);
        foreach ($tcData as $tc) {
            $cleanTcPhone = preg_replace('/[^0-9]/', '', $tc['phone'] ?? '');
            if (!empty($cleanTcPhone) && $cleanOldPhone === $cleanTcPhone) {
                $match = $tc;
                break;
            }
        }
    }
    
    if ($match) {
        $matched++;
        
        // Kiểm tra có dữ liệu cần copy không
        $hasData = !empty($old['facebook_link']) || !empty($old['workplace']) || 
                   !empty($old['hktt']) || !empty($old['relatives_info']) || 
                   !empty($old['company_tag']) || !empty($old['description']) ||
                   !empty($old['pinned_note']) || !empty($old['tc_info']);
        
        if ($hasData) {
            $updateStmt->execute([
                $old['facebook_link'] ?? null,
                $old['workplace'] ?? null,
                $old['hktt'] ?? null,
                $old['relatives_info'] ?? null,
                $old['company_tag'] ?? null,
                $old['description'] ?? null,
                $old['pinned_note'] ?? null,
                $old['tc_info'] ?? null,
                $match['loan_id']
            ]);
            $updated++;
            echo "✅ [{$oldName}] → loan #{$match['loan_id']}: ";
            if (!empty($old['facebook_link'])) echo "FB ";
            if (!empty($old['workplace'])) echo "ĐV ";
            if (!empty($old['hktt'])) echo "HKTT ";
            if (!empty($old['relatives_info'])) echo "NT ";
            if (!empty($old['company_tag'])) echo "CT ";
            if (!empty($old['description'])) echo "MoTa ";
            if (!empty($old['tc_info'])) echo "TTTC ";
            echo "\n";
        } else {
            echo "⏭️ [{$oldName}] → matched nhưng không có dữ liệu cần copy\n";
        }
    } else {
        $notMatched++;
        echo "❌ [{$oldName}] (SĐT: {$oldPhone}) → KHÔNG TÌM THẤY trong TC\n";
    }
}

echo "\n========================================\n";
echo "📊 KẾT QUẢ:\n";
echo "   Matched: {$matched}\n";
echo "   Updated: {$updated}\n";
echo "   Not found: {$notMatched}\n";
echo "========================================\n";
echo "</pre>";
echo "<p><b>⚠️ XÓA file này sau khi chạy!</b></p>";
echo "<p><a href='index.php'>← Về trang chủ</a></p>";
