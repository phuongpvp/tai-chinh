<?php
/**
 * IMPORT DANH SÁCH KHÁCH HÀNG TỪ NOTION (khachhang.xlsx)
 * 
 * Cập nhật phòng + thông tin khách (CCCD, SĐT, HKTT, Facebook, đơn vị...)
 * Tạo phòng mới nếu cần
 * Tính due_date dựa trên SLA phòng
 * 
 * SAU KHI CHẠY → XÓA FILE NÀY KHỎI HOSTING!
 */
set_time_limit(600);
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

// ============================================================
// HÀM ĐỌC XLSX
// ============================================================
function readXlsx($filePath) {
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) die("❌ Không mở được XLSX");
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ssDoc = new DOMDocument(); $ssDoc->loadXML($ssXml);
        foreach ($ssDoc->getElementsByTagName('si') as $si) {
            $text = '';
            foreach ($si->getElementsByTagName('t') as $t) $text .= $t->textContent;
            $sharedStrings[] = $text;
        }
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$sheetXml) die("❌ Không tìm thấy sheet1");
    $sheetDoc = new DOMDocument(); $sheetDoc->loadXML($sheetXml);
    $data = [];
    foreach ($sheetDoc->getElementsByTagName('row') as $row) {
        $rd = array_fill(0, 30, '');
        foreach ($row->getElementsByTagName('c') as $cell) {
            $ref = $cell->getAttribute('r');
            $col = preg_replace('/[0-9]/', '', $ref);
            $ci = 0;
            for ($x = 0; $x < strlen($col); $x++) $ci = $ci * 26 + (ord(strtoupper($col[$x])) - 64);
            $ci--;
            $type = $cell->getAttribute('t');
            $vN = $cell->getElementsByTagName('v')->item(0);
            $val = $vN ? $vN->textContent : '';
            if ($type === 's') $val = $sharedStrings[intval($val)] ?? '';
            if ($ci < 30) $rd[$ci] = $val;
        }
        $data[] = $rd;
    }
    $zip->close();
    return $data;
}

// Xóa link Notion khỏi tên phòng
function cleanRoomName($val) {
    $val = preg_replace('/\s*\(https?:\/\/[^)]+\)/', '', $val);
    return trim($val);
}

// ============================================================
echo "<h2>📥 Import danh sách khách + gán phòng</h2><pre>\n";
ob_flush(); flush();

// ============================================================
// BƯỚC 0: XÓA DỮ LIỆU CŨ
// ============================================================
echo "=== BƯỚC 0: Xóa dữ liệu cũ ===\n";
$oldLogs = $pdo->query("SELECT COUNT(*) FROM work_logs")->fetchColumn();
$oldCust = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$pdo->exec("DELETE FROM work_logs");
$pdo->exec("DELETE FROM customers");
echo "🗑️ Xóa $oldCust khách + $oldLogs nhật ký cũ\n\n";

// Mở rộng cột
try { $pdo->exec("ALTER TABLE work_logs MODIFY action_type TEXT DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE work_logs MODIFY result_type TEXT DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE customers MODIFY phone VARCHAR(50) DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE customers MODIFY facebook_link TEXT DEFAULT NULL"); } catch(Exception $e) {}
echo "✅ Mở rộng cột\n";

ob_flush(); flush();

// ============================================================
// BƯỚC 1: ĐỌC FILE KHÁCH HÀNG
// ============================================================
echo "=== BƯỚC 1: Đọc khachhang.xlsx ===\n";
$data = readXlsx(__DIR__ . '/khachhang.xlsx');
echo "📊 Tổng: " . count($data) . " dòng\n\n";

// Header: A=Họ và tên, B=CCCD, C=Chuyển qua Phòng, D=HKTT, E=Link facebook
//   I=Nơi ở, J=Phòng ban Hiện tại, M=Số điện thoại, P=Thuộc Công ty
//   Q=Thông tin người thân, R=Trạng thái, W=Đơn vị công tác

// ============================================================
// BƯỚC 2: TẠO PHÒNG MỚI NẾU CẦN
// ============================================================
echo "=== BƯỚC 2: Kiểm tra phòng ===\n";

$rooms = [];
foreach ($pdo->query("SELECT id, name, sla_days FROM cv_rooms") as $r) {
    $rooms[mb_strtolower(trim($r['name']))] = ['id' => $r['id'], 'sla' => intval($r['sla_days'] ?? 0)];
}

// Tìm tất cả phòng trong dữ liệu
$notionRooms = [];
for ($i = 1; $i < count($data); $i++) {
    $roomRaw = cleanRoomName($data[$i][9]); // Cột J: Phòng ban Hiện tại
    if (!empty($roomRaw)) $notionRooms[$roomRaw] = true;
}

// Tạo phòng mới — CHỈ exact matching, KHÔNG fuzzy
$archiveNames = ['đã hoàn thành', 'lưu trữ - khách đã đóng hđ', 'lưu trữ - khách đã đi'];

foreach ($notionRooms as $rn => $v) {
    $rk = mb_strtolower($rn);
    
    if (!isset($rooms[$rk])) {
        $isArchive = in_array($rk, $archiveNames) ? 1 : 0;
        $stmt = $pdo->prepare("INSERT INTO cv_rooms (name, is_archive) VALUES (?, ?)");
        $stmt->execute([$rn, $isArchive]);
        $rooms[$rk] = ['id' => $pdo->lastInsertId(), 'sla' => 0];
        $label = $isArchive ? ' [Lưu trữ]' : '';
        echo "➕ Tạo phòng: $rn$label\n";
    }
}
echo "🏠 Phòng: " . implode(', ', array_keys($rooms)) . "\n\n";
ob_flush(); flush();

// ============================================================
// BƯỚC 3: TẠO KHÁCH HÀNG
// ============================================================
echo "=== BƯỚC 3: Import khách hàng ===\n";
$created = 0;
$custIds = [];

for ($i = 1; $i < count($data); $i++) {
    $row = $data[$i];
    
    $name     = trim($row[0]);
    $cccd     = trim($row[1]);
    $chuyenPhong = cleanRoomName(trim($row[2]));
    $hktt     = trim($row[3]);
    $facebook = trim($row[4]);
    $noiO     = trim($row[8]);
    $roomRaw  = cleanRoomName(trim($row[9])); // Cột J: Phòng ban Hiện tại
    $phone    = trim($row[12]);
    $congTy   = trim($row[15]);
    $nguoiThan= trim($row[16]);
    $trangThai= cleanRoomName(trim($row[17]));
    $donVi    = trim($row[22]);
    
    if (empty($name)) continue;
    
    // Fix CCCD dạng số khoa học
    if (is_numeric($cccd) && strpos($cccd, 'E') !== false) {
        $cccd = number_format(floatval($cccd), 0, '', '');
    }
    if (is_numeric($cccd)) {
        $cccd = str_pad($cccd, 12, '0', STR_PAD_LEFT);
    }
    
    // Fix phone
    if (is_numeric($phone) && strpos($phone, 'E') !== false) {
        $phone = number_format(floatval($phone), 0, '', '');
    }
    $phoneClean = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phoneClean) >= 9 && strlen($phoneClean) <= 11) {
        $phone = (substr($phoneClean, 0, 1) !== '0') ? '0' . $phoneClean : $phoneClean;
    } elseif (strlen($phoneClean) > 11) {
        $phone = substr($phoneClean, 0, 11);
    } else {
        $phone = $phoneClean;
    }
    
    // Xóa link Notion trong facebook
    $facebook = preg_replace('/\s*\(https?:\/\/[^)]*\)/', '', $facebook);
    $facebook = trim($facebook);
    if (strlen($facebook) > 250) $facebook = substr($facebook, 0, 250);
    
    // Map phòng — exact matching
    $rk = mb_strtolower($roomRaw);
    $roomInfo = $rooms[$rk] ?? null;
    if (!$roomInfo) {
        $roomInfo = $rooms[array_key_first($rooms)];
        if (!empty($roomRaw)) echo "  ⚠️ Không tìm phòng '$roomRaw' → mặc định\n";
    }
    $roomId = $roomInfo['id'];
    $slaDays = $roomInfo['sla'];
    
    // Tất cả status = active
    $status = 'active';
    
    // Tính due_date từ SLA
    $transferDate = date('Y-m-d');
    $dueDate = null;
    if ($slaDays > 0) {
        $dueDate = date('Y-m-d', strtotime("+{$slaDays} days"));
    }
    
    // Ghép mô tả
    $desc = '';
    if ($noiO)     $desc .= "Nơi ở: $noiO\n";
    if ($donVi)    $desc .= "Đơn vị: $donVi\n";
    if ($nguoiThan) $desc .= "Người thân: $nguoiThan\n";
    
    try {
        $stmt = $pdo->prepare("INSERT INTO customers (name, room_id, phone, cccd, hktt, facebook_link, company_tag, workplace, relatives_info, description, status, due_date, created_at, transfer_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([
            $name, $roomId,
            $phone ?: null, $cccd ?: null, $hktt ?: null,
            $facebook ?: null, $congTy ?: null, $donVi ?: null,
            $nguoiThan ?: null, $desc ?: null, $status, $dueDate
        ]);
        $custIds[mb_strtolower($name)] = $pdo->lastInsertId();
        $created++;
        
        $roomLabel = $roomRaw ?: '(không rõ)';
        echo "  ✅ $name → $roomLabel" . ($phone ? " | SĐT: $phone" : '') . "\n";
    } catch (Exception $e) {
        echo "  ❌ $name: " . $e->getMessage() . "\n";
    }
    
    if ($created % 30 === 0) { ob_flush(); flush(); }
}

echo "\n✅ Tạo $created khách hàng\n\n";
ob_flush(); flush();

// ============================================================
// BƯỚC 4: IMPORT NHẬT KÝ TỪ FILE XLSX (nếu có)
// ============================================================
$logFile = __DIR__ . '/notion_import.xlsx';
if (!file_exists($logFile)) {
    $logFile = __DIR__ . '/Nhật ký làm việc khách 29_11.xlsx';
}

if (file_exists($logFile)) {
    echo "=== BƯỚC 4: Import nhật ký ===\n";
    $logData = readXlsx($logFile);
    echo "📝 Đọc " . count($logData) . " dòng nhật ký\n";
    
    $admin = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch();
    $uid = $admin ? $admin['id'] : 1;
    $defRoom = $pdo->query("SELECT id FROM cv_rooms ORDER BY id LIMIT 1")->fetchColumn();
    
    $imported = 0;
    
    $header0 = mb_strtolower(trim($logData[0][0] ?? ''));
    $isOldFormat = (strpos($header0, 'submission') !== false);
    
    $dateCol    = $isOldFormat ? 2 : 0;
    $nameCol    = $isOldFormat ? 3 : 1;
    $roomCol    = $isOldFormat ? 4 : 2;
    $actionCol  = $isOldFormat ? 5 : 3;
    $creativeCol= $isOldFormat ? 6 : 4;
    $resultCol  = $isOldFormat ? 7 : 5;
    $promiseCol = $isOldFormat ? 8 : 6;
    $amountCol  = $isOldFormat ? 9 : 7;
    $otherCol   = $isOldFormat ? 10 : 8;
    $softCol    = $isOldFormat ? 11 : 9;
    $noteCol    = $isOldFormat ? 12 : 10;
    $note2Col   = $isOldFormat ? 13 : 11;
    
    echo "📋 Format: " . ($isOldFormat ? "Form (Submission)" : "Notion CSV") . "\n";
    
    for ($i = 1; $i < count($logData); $i++) {
        $row = $logData[$i];
        $custName = trim($row[$nameCol] ?? '');
        if (empty($custName)) continue;
        
        $dateRaw = trim($row[$dateCol] ?? '');
        $logDate = null;
        if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $dateRaw, $m)) {
            $logDate = "$m[3]-$m[2]-$m[1]";
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}/', $dateRaw)) {
            $logDate = substr($dateRaw, 0, 10);
        } elseif (is_numeric($dateRaw) && $dateRaw > 40000) {
            $logDate = date('Y-m-d', ($dateRaw - 25569) * 86400);
        } else {
            $ts = strtotime($dateRaw);
            if ($ts) $logDate = date('Y-m-d', $ts);
        }
        if (!$logDate) continue;
        
        $ck = mb_strtolower($custName);
        $customerId = $custIds[$ck] ?? null;
        if (!$customerId) continue;
        
        $roomName = cleanRoomName(trim($row[$roomCol] ?? ''));
        $rk = mb_strtolower($roomName);
        $rid = isset($rooms[$rk]) ? $rooms[$rk]['id'] : $defRoom;
        
        $actionType = trim($row[$actionCol] ?? '');
        $creative   = trim($row[$creativeCol] ?? '');
        $resultType = trim($row[$resultCol] ?? '');
        $amountRaw  = trim($row[$amountCol] ?? '');
        $otherResult= trim($row[$otherCol] ?? '');
        $softPlan   = trim($row[$softCol] ?? '');
        $note       = trim($row[$noteCol] ?? '');
        $note2      = trim($row[$note2Col] ?? '');
        
        $parts = [];
        if ($creative)    $parts[] = "Sáng tạo: $creative";
        if ($otherResult) $parts[] = "KQ khác: $otherResult";
        if ($softPlan)    $parts[] = "PA mềm: $softPlan";
        if ($note)        $parts[] = $note;
        if ($note2 && $note2 !== $note) $parts[] = $note2;
        $workDone = implode('. ', $parts) ?: ($actionType . ($resultType ? ' → ' . $resultType : ''));
        
        $amount = null;
        if (!empty($amountRaw) && preg_match('/[\d]/', $amountRaw)) {
            $amount = floatval(preg_replace('/[^\d.]/', '', str_replace(',', '', $amountRaw)));
        }
        
        $promiseRaw = trim($row[$promiseCol] ?? '');
        $promiseDate = null;
        if (preg_match('/(\d+)\s+tháng\s+(\d+),?\s*(\d{4})/u', $promiseRaw, $pm)) {
            $promiseDate = sprintf('%04d-%02d-%02d', $pm[3], $pm[2], $pm[1]);
        } elseif (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $promiseRaw, $pm)) {
            $promiseDate = "$pm[3]-$pm[2]-$pm[1]";
        } elseif (is_numeric($promiseRaw) && $promiseRaw > 40000) {
            $promiseDate = date('Y-m-d', ($promiseRaw - 25569) * 86400);
        }
        
        try {
            $stmt = $pdo->prepare("INSERT INTO cv_work_logs (loan_id, user_id, room_id, work_done, log_date, action_type, result_type, promise_date, amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$customerId, $uid, $rid, $workDone, $logDate, $actionType ?: null, $resultType ?: null, $promiseDate, $amount]);
            $imported++;
        } catch (Exception $e) {}
        
        if ($i % 100 === 0) { echo "⏳ $i dòng...\n"; ob_flush(); flush(); }
    }
    echo "✅ Import $imported nhật ký\n\n";
} else {
    echo "⏭️ Không tìm thấy file nhật ký, bỏ qua\n\n";
}

// ============================================================
// THỐNG KÊ
// ============================================================
echo "============================================\n";
echo "📊 THỐNG KÊ SO SÁNH VỚI NOTION:\n\n";

$stats = $pdo->query("
    SELECT r.name, r.is_archive,
        COUNT(c.id) as cnt
    FROM cv_rooms r 
    LEFT JOIN customers c ON l.cv_room_id = r.id 
    GROUP BY r.id, r.name, r.is_archive 
    ORDER BY r.is_archive, cnt DESC
")->fetchAll();

$total = 0;
$lastArchive = -1;
foreach ($stats as $s) {
    if ($s['is_archive'] != $lastArchive) {
        if ($s['is_archive']) echo "\n  📁 LƯU TRỮ:\n";
        $lastArchive = $s['is_archive'];
    }
    $icon = $s['cnt'] > 0 ? '🟢' : '⚪';
    echo "  $icon {$s['name']}: {$s['cnt']} khách\n";
    $total += $s['cnt'];
}
echo "\n  ─────────────\n  TỔNG: $total khách\n";

$logTotal = $pdo->query("SELECT COUNT(*) FROM work_logs")->fetchColumn();
echo "  📝 Nhật ký: $logTotal\n";

echo "\n🎉 HOÀN THÀNH! XÓA FILE IMPORT KHỎI HOSTING!\n</pre>";
