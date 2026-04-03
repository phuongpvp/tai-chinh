<?php
/**
 * QUẢN LÝ DỮ LIỆU — Import / Export / Xóa tất cả
 * Chỉ admin + mật khẩu riêng mới truy cập được
 */
require_once 'config.php';
requireLogin();

$user = cvGetUser();
if ($user['role'] !== 'admin') redirect('index.php');

// ─── MẬT KHẨU BẢO VỆ ───
// Đổi mật khẩu ở đây:
define('DATA_PASSWORD', 'admin@2026');

// Đăng xuất khỏi trang dữ liệu
if (isset($_GET['lock'])) {
    unset($_SESSION['data_unlocked']);
    redirect('data_manage.php');
}

// Kiểm tra mật khẩu
if (!isset($_SESSION['data_unlocked']) || $_SESSION['data_unlocked'] !== true) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['data_password'])) {
        if ($_POST['data_password'] === DATA_PASSWORD) {
            $_SESSION['data_unlocked'] = true;
        } else {
            $pwError = 'Sai mật khẩu!';
        }
    }
    
    if (!isset($_SESSION['data_unlocked']) || $_SESSION['data_unlocked'] !== true) {
        $pageTitle = 'Quản lý dữ liệu';
        $activePage = 'data_manage';
        include 'layout_top.php';
        ?>
        <div style="max-width:400px; margin:80px auto; text-align:center;">
            <div style="font-size:64px; margin-bottom:16px;">🔒</div>
            <h2 style="margin-bottom:8px;">Khu vực bảo mật</h2>
            <p style="color:var(--text-muted); margin-bottom:24px;">Nhập mật khẩu để truy cập quản lý dữ liệu</p>
            <?php if (isset($pwError)): ?>
                <div style="background:var(--status-danger)15; color:var(--status-danger); padding:10px; border-radius:var(--radius-md); margin-bottom:16px; font-size:14px;">
                    ❌ <?= $pwError ?>
                </div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group" style="margin-bottom:16px;">
                    <input type="password" name="data_password" class="form-input" placeholder="Nhập mật khẩu..." autofocus
                           style="text-align:center; font-size:16px; padding:14px;">
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%; padding:14px;">🔓 Mở khóa</button>
            </form>
        </div>
        <?php
        include 'layout_bottom.php';
        exit;
    }
}

$pageTitle = 'Quản lý dữ liệu';
$activePage = 'data_manage';

// ============================================================
// HÀM ĐỌC XLSX
// ============================================================
function readXlsxFile($filePath) {
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return false;
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
    if (!$sheetXml) { $zip->close(); return false; }
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

function cleanNotionUrl($val) {
    $val = preg_replace('/\s*\(https?:\/\/[^)]+\)/', '', $val);
    return trim($val);
}

// ============================================================
// XỬ LÝ ACTION
// ============================================================
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // ─────────────── XÓA NHẬT KÝ LÀM VIỆC & CHUYỂN PHÒNG ───────────────
    if ($action === 'clear_logs') {
        $confirm = $_POST['confirm_delete'] ?? '';
        if ($confirm === 'XOA NHAT KY') {
            $logCount = $pdo->query("SELECT COUNT(*) FROM cv_work_logs")->fetchColumn();
            $tfCount = $pdo->query("SELECT COUNT(*) FROM cv_transfer_logs")->fetchColumn();
            
            $pdo->exec("DELETE FROM cv_work_logs");
            $pdo->exec("DELETE FROM cv_transfer_logs");
            
            $message = "🗑️ Đã dọn sạch $logCount dòng Nhật ký và $tfCount dòng Lịch sử chuyển phòng (Dữ liệu Khách hàng & Kế toán giữ nguyên an toàn).";
            $messageType = 'success';
        } else {
            $message = "❌ Nhập sai xác nhận. Phải gõ đúng: XOA NHAT KY";
            $messageType = 'error';
        }
    }
    
    // ─────────────── GỘP KHÁCH HÀNG TRÙNG LẶP ───────────────
    if ($action === 'deduplicate') {
        // Nhóm theo tên và số điện thoại
        $dupes = $pdo->query("
            SELECT LOWER(TRIM(name)) as lname, IFNULL(TRIM(phone), '') as p, MIN(id) as keep_id, COUNT(*) as cnt 
            FROM customers 
            GROUP BY LOWER(TRIM(name)), IFNULL(TRIM(phone), '') 
            HAVING cnt > 1
        ")->fetchAll(PDO::FETCH_ASSOC);

        $totalMerged = 0;
        $totalDeleted = 0;

        foreach ($dupes as $d) {
            $keepId = $d['keep_id'];
            $lname = $d['lname'];
            $p = $d['p'];
            
            // Tìm tất cả các bản sao (trừ bản giữ lại)
            $stmt = $pdo->prepare("SELECT id FROM customers WHERE LOWER(TRIM(name)) = ? AND IFNULL(TRIM(phone), '') = ? AND id != ?");
            $stmt->execute([$lname, $p, $keepId]);
            $duplicateIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($duplicateIds)) continue;
            
            $inPlaceholders = str_repeat('?,', count($duplicateIds) - 1) . '?';
            $params = array_merge([$keepId], $duplicateIds);
            
            // CHUYỂN TOÀN BỘ LOG SANG BẢN GỐC TRƯỚC KHI XÓA
            $pdo->prepare("UPDATE cv_work_logs SET loan_id = ? WHERE loan_id IN ($inPlaceholders)")->execute($params);
            $pdo->prepare("UPDATE cv_transfer_logs SET loan_id = ? WHERE loan_id IN ($inPlaceholders)")->execute($params);
            $pdo->prepare("UPDATE cv_comments SET loan_id = ? WHERE loan_id IN ($inPlaceholders)")->execute($params);
            $pdo->prepare("UPDATE cv_violations SET loan_id = ? WHERE loan_id IN ($inPlaceholders)")->execute($params);
            $pdo->prepare("UPDATE cv_customer_files SET loan_id = ? WHERE loan_id IN ($inPlaceholders)")->execute($params);
            
            try { 
                $pdo->prepare("UPDATE loans SET customer_id = ? WHERE customer_id IN ($inPlaceholders)")->execute($params); 
            } catch(Exception $e) {}
            
            // XÓA BẢN SAO VÔ HỒN
            $pdo->prepare("DELETE FROM customers WHERE id IN ($inPlaceholders)")->execute($duplicateIds);
            $totalDeleted += count($duplicateIds);
            $totalMerged++;
        }
        
        if ($totalDeleted > 0) {
            $message = "✨ Đã dọn dẹp $totalDeleted khách hàng bị rác/trùng lặp (thuộc $totalMerged nhóm). Mọi dữ liệu đã gộp chung an toàn vào khách gốc!";
            $messageType = 'success';
        } else {
            $message = "👌 Tuyệt vời! Hệ thống của anh không có khách hàng nào bị trùng lặp.";
            $messageType = 'success';
        }
    }
    
    // ─────────────── IMPORT ───────────────
    if ($action === 'import') {
        $hasCustomer = isset($_FILES['xlsx_file']) && $_FILES['xlsx_file']['error'] === UPLOAD_ERR_OK && $_FILES['xlsx_file']['size'] > 0;
        $hasLog = isset($_FILES['log_file']) && $_FILES['log_file']['error'] === UPLOAD_ERR_OK && $_FILES['log_file']['size'] > 0;
        $hasTransfer = isset($_FILES['transfer_file']) && $_FILES['transfer_file']['error'] === UPLOAD_ERR_OK && $_FILES['transfer_file']['size'] > 0;

        if (!$hasCustomer && !$hasLog && !$hasTransfer) {
            $message = "❌ Vui lòng chọn ít nhất 1 file để Import (Khách hàng, Nhật ký, hoặc Chuyển phòng).";
            $messageType = 'error';
        } else {
            set_time_limit(600);
            $log = [];
            
            $deleteOld = isset($_POST['delete_old']);
            if ($deleteOld) {
                $pdo->exec("DELETE FROM cv_work_logs");
                $pdo->exec("DELETE FROM cv_transfer_logs");
                $log[] = "🗑️ Đã xóa sạch cấu trúc nhật ký/chuyển phòng cũ (Khách hàng giữ nguyên).";
            }
            
            // Build common Maps if needed (for Logs or Transfers)
            $rooms = [];
            foreach ($pdo->query("SELECT id, name, sla_days FROM cv_rooms") as $r) {
                $rooms[mb_strtolower(trim($r['name']))] = ['id' => $r['id'], 'sla' => intval($r['sla_days'] ?? 0)];
            }
            
            // --- XỬ LÝ FILE KHÁCH HÀNG ---
            if ($hasCustomer) {
                $data = readXlsxFile($_FILES['xlsx_file']['tmp_name']);
                if ($data === false) {
                    $log[] = "❌ Không đọc được file Khách hàng XLSX";
                } else {
                    // Mở rộng cột bảo vệ
                    try { $pdo->exec("ALTER TABLE customers MODIFY phone VARCHAR(50) DEFAULT NULL"); } catch(Exception $e) {}
                    try { $pdo->exec("ALTER TABLE customers MODIFY facebook_link TEXT DEFAULT NULL"); } catch(Exception $e) {}
                    
                    // Tìm phòng từ dữ liệu
                    $archiveNames = ['đã hoàn thành', 'lưu trữ - khách đã đóng hđ', 'lưu trữ - khách đã đi'];
                    $notionRooms = [];
                    for ($i = 1; $i < count($data); $i++) {
                        $roomRaw = cleanNotionUrl($data[$i][9] ?? '');
                        if (!empty($roomRaw)) $notionRooms[$roomRaw] = true;
                    }
                    
                    $newRooms = 0;
                    foreach ($notionRooms as $rn => $v) {
                        $rk = mb_strtolower($rn);
                        if (!isset($rooms[$rk])) {
                            $isArchive = in_array($rk, $archiveNames) ? 1 : 0;
                            $stmt = $pdo->prepare("INSERT INTO cv_rooms (name, is_archive) VALUES (?, ?)");
                            $stmt->execute([$rn, $isArchive]);
                            $rooms[$rk] = ['id' => $pdo->lastInsertId(), 'sla' => 0];
                            $newRooms++;
                        }
                    }
                    if ($newRooms > 0) $log[] = "➕ Tạo $newRooms phòng mới";
                    
                    // Import khách
                    $created = 0;
                    $errors = 0;
                    for ($i = 1; $i < count($data); $i++) {
                        $row = $data[$i];
                        $name     = trim($row[0] ?? '');
                        $cccd     = trim($row[1] ?? '');
                        $hktt     = trim($row[3] ?? '');
                        $facebook = cleanNotionUrl(trim($row[4] ?? ''));
                        $noiO     = trim($row[8] ?? '');
                        $roomRaw  = cleanNotionUrl(trim($row[9] ?? ''));
                        $phone    = trim($row[12] ?? '');
                        $congTy   = trim($row[15] ?? '');
                        $nguoiThan= trim($row[16] ?? '');
                        $donVi    = trim($row[22] ?? '');
                        
                        if (empty($name)) continue;
                        
                        // Fix CCCD & Phone
                        if (is_numeric($cccd) && strpos($cccd, 'E') !== false) {
                            $cccd = number_format(floatval($cccd), 0, '', '');
                        }
                        if (is_numeric($cccd)) $cccd = str_pad($cccd, 12, '0', STR_PAD_LEFT);
                        
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
                        
                        if (strlen($facebook) > 250) $facebook = substr($facebook, 0, 250);
                        
                        // Map phòng
                        $rk = mb_strtolower($roomRaw);
                        $roomInfo = $rooms[$rk] ?? ($rooms[array_key_first($rooms)] ?? ['id' => 1, 'sla' => 0]);
                        $roomId = $roomInfo['id'];
                        $slaDays = $roomInfo['sla'];
                        
                        $dueDate = ($slaDays > 0) ? date('Y-m-d', strtotime("+{$slaDays} days")) : null;
                        
                        $desc = '';
                        if ($noiO)      $desc .= "Nơi ở: $noiO\n";
                        if ($donVi)     $desc .= "Đơn vị: $donVi\n";
                        if ($nguoiThan) $desc .= "Người thân: $nguoiThan\n";
                        
                        try {
                            $stmt = $pdo->prepare("INSERT INTO customers (name, room_id, phone, cccd, hktt, facebook_link, company_tag, workplace, relatives_info, description, status, due_date, created_at, transfer_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW(), NOW())");
                            $stmt->execute([
                                $name, $roomId,
                                $phone ?: null, $cccd ?: null, $hktt ?: null,
                                $facebook ?: null, $congTy ?: null, $donVi ?: null,
                                $nguoiThan ?: null, $desc ?: null, $dueDate
                            ]);
                            $created++;
                        } catch (Exception $e) {
                            $errors++;
                        }
                    }
                    $log[] = "✅ Import $created khách hàng" . ($errors > 0 ? " ($errors lỗi)" : "");
                }
            }
                
            // --- XỬ LÝ FILE NHẬT KÝ ---
            if ($hasLog) {
                $logData = readXlsxFile($_FILES['log_file']['tmp_name']);
                if ($logData) {
                        $admin = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch();
                        $uid = $admin ? $admin['id'] : 1;
                        $defRoom = $pdo->query("SELECT id FROM cv_rooms ORDER BY id LIMIT 1")->fetchColumn();
                        
                        // Build customer name → loan_id map (cv_work_logs dùng loan_id chứ không phải customer_id)
                        $custIds = [];
                        $allCusts = $pdo->query("SELECT l.id as loan_id, c.name FROM loans l JOIN customers c ON l.customer_id = c.id WHERE l.status != 'deleted' ORDER BY l.id DESC")->fetchAll();
                        foreach ($allCusts as $ac) {
                            $key = mb_strtolower(trim($ac['name']));
                            if (!isset($custIds[$key])) { // Ưu tiên loan mới nhất (DESC)
                                $custIds[$key] = $ac['loan_id'];
                            }
                        }
                        
                        $header0 = mb_strtolower(trim($logData[0][0] ?? ''));
                        $isOldFormat = (strpos($header0, 'submission') !== false);
                        
                        // Mapping cột theo file Excel thực tế:
                        // A=Ghi chú, B=Sáng tạo, C=Việc đã làm, D=Ghi chú thêm,
                        // E=Tên khách, F=KQ khác, G=Kết quả, H=Ngày cập nhật,
                        // I=Ngày hẹn, J=PA mềm, K=Số tiền, L=Phòng
                        $noteCol    = $isOldFormat ? 12 : 0;   // A - Ghi Chú
                        $creativeCol= $isOldFormat ? 6  : 1;   // B - Công việc sáng tạo
                        $actionCol  = $isOldFormat ? 5  : 2;   // C - Công việc đã làm
                        $note2Col   = $isOldFormat ? 13 : 3;   // D - Ghi chú thêm
                        $nameCol    = $isOldFormat ? 3  : 4;   // E - Họ và tên khách
                        $otherCol   = $isOldFormat ? 10 : 5;   // F - Kết quả khác
                        $resultCol  = $isOldFormat ? 7  : 6;   // G - Kết quả thế nào
                        $dateCol    = $isOldFormat ? 2  : 7;   // H - Ngày cập nhật
                        $promiseCol = $isOldFormat ? 8  : 8;   // I - Ngày khách hẹn trả
                        $softCol    = $isOldFormat ? 11 : 9;   // J - Phương án mềm
                        $amountCol  = $isOldFormat ? 9  : 10;  // K - Số tiền khách trả
                        $roomCol    = $isOldFormat ? 4  : 11;  // L - Tên Phòng làm việc
                        
                        $imported = 0;
                        for ($i = 1; $i < count($logData); $i++) {
                            $row = $logData[$i];
                            $custName = trim($row[$nameCol] ?? '');
                            if (empty($custName)) continue;
                            
                            $dateRaw = trim($row[$dateCol] ?? '');
                            $logDate = null;
                            if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $dateRaw, $m)) {
                                $logDate = "$m[3]-$m[2]-$m[1]";
                            } elseif (preg_match('/(\d{1,2})\s+tháng\s+(\d{1,2}),?\s*(\d{4})/u', $dateRaw, $m)) {
                                $logDate = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                            } elseif (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dateRaw, $m)) {
                                $logDate = $m[1];
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
                            
                            $roomName = cleanNotionUrl(trim($row[$roomCol] ?? ''));
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
                                // Xóa hết dấu chấm và dấu phẩy (ngăn cách hàng nghìn kiểu VN: 2.000.000)
                                $amount = floatval(preg_replace('/[^\d]/', '', $amountRaw));
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
                        }
                        $log[] = "📝 Import $imported nhật ký";
                    }
                }
                
            // --- XỬ LÝ FILE CHUYỂN PHÒNG ---
            if ($hasTransfer) {
                $tfData = readXlsxFile($_FILES['transfer_file']['tmp_name']);
                if ($tfData) {
                        // Build maps
                        if (!isset($custIds) || empty($custIds)) {
                            $custIds = [];
                            $allCusts = $pdo->query("SELECT id, name FROM customers")->fetchAll();
                            foreach ($allCusts as $ac) $custIds[mb_strtolower($ac['name'])] = $ac['id'];
                        }
                        
                        $userMap = [];
                        foreach ($pdo->query("SELECT id, fullname FROM users") as $u) {
                            $userMap[mb_strtolower(trim($u['fullname']))] = $u['id'];
                        }
                        $adminId = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn() ?: 1;
                        
                        $tfImported = 0;
                        // A=chuyển từ, B=ngày chuyển, C=người chuyển, D=phòng ban (đến), E=số ngày, F=trạng thái, G=tên khách, H=vi phạm
                        for ($i = 1; $i < count($tfData); $i++) {
                            $row = $tfData[$i];
                            $fromRoom   = cleanNotionUrl(trim($row[0])); // A: chuyển từ
                            $dateRaw    = trim($row[1]); // B: ngày chuyển
                            $transferBy = trim($row[2]); // C: người chuyển
                            $toRoom     = cleanNotionUrl(trim($row[3])); // D: phòng ban (đến)
                            $custName   = trim($row[6]); // G: tên khách
                            $violation  = trim($row[7]); // H: vi phạm
                            
                            if (empty($custName)) continue;
                            
                            // Parse date
                            $tfDate = null;
                            if (preg_match('/(\d+)\s+tháng\s+(\d+),?\s*(\d{4})/u', $dateRaw, $m)) {
                                $tfDate = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                            } elseif (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $dateRaw, $m)) {
                                $tfDate = "$m[3]-$m[2]-$m[1]";
                            } elseif (is_numeric($dateRaw) && $dateRaw > 40000) {
                                $tfDate = date('Y-m-d', ($dateRaw - 25569) * 86400);
                            } else {
                                $ts = strtotime($dateRaw);
                                if ($ts) $tfDate = date('Y-m-d', $ts);
                            }
                            if (!$tfDate) continue;
                            
                            $ck = mb_strtolower($custName);
                            $customerId = $custIds[$ck] ?? null;
                            if (!$customerId) continue;
                            
                            $fromRk = mb_strtolower($fromRoom);
                            $toRk = mb_strtolower($toRoom);
                            $fromId = isset($rooms[$fromRk]) ? $rooms[$fromRk]['id'] : null;
                            $toId = isset($rooms[$toRk]) ? $rooms[$toRk]['id'] : null;
                            if (!$fromId && !$toId) continue;
                            
                            $byUser = $userMap[mb_strtolower($transferBy)] ?? $adminId;
                            
                            $note = '';
                            if (strtolower($violation) === 'yes') $note = '⚠️ Vi phạm';
                            
                            try {
                                $stmt = $pdo->prepare("INSERT INTO cv_transfer_logs (loan_id, from_room_id, to_room_id, transferred_by, transferred_at, note) VALUES (?, ?, ?, ?, ?, ?)");
                                $stmt->execute([$customerId, $fromId, $toId, $byUser, $tfDate . ' 00:00:00', $note ?: null]);
                                $tfImported++;
                            } catch (Exception $e) {}
                        }
                        $log[] = "🔄 Import $tfImported lịch sử chuyển phòng";
                    }
                }
                
            $message = implode("\n", $log);
            if (empty($message)) $message = "✅ Đã xử lý yêu cầu.";
            $messageType = 'success';
        }
    }
    
    // ─────────────── EXPORT ───────────────
    if ($action === 'export') {
        require_once __DIR__ . '/../SimpleXLSXGen.php';
        $customers = $pdo->query("
            SELECT c.*, r.name as room_name 
            FROM loans l LEFT JOIN customers c ON l.customer_id = c.id 
            LEFT JOIN cv_rooms r ON r.id = l.cv_room_id 
            ORDER BY r.name, c.name
        ")->fetchAll();
        
        $rows = [['Họ tên', 'Phòng', 'SĐT', 'CCCD', 'HKTT', 'Facebook', 'Công ty', 'Đơn vị', 'Người thân', 'Mô tả', 'Trạng thái', 'Ngày hạn', 'Ngày chuyển']];
        foreach ($customers as $c) {
            $rows[] = [
                $c['name'],
                $c['room_name'],
                $c['phone'],
                $c['cccd'],
                $c['hktt'],
                $c['facebook_link'],
                $c['company_tag'],
                $c['workplace'],
                $c['relatives_info'],
                $c['description'],
                $c['status'],
                $c['due_date'],
                $c['transfer_date']
            ];
        }
        SimpleXLSXGen::fromArray($rows)->downloadAs('khachhang_export_' . date('Y-m-d_His') . '.xlsx');
    }
}

// Thống kê
$totalCustomers = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$totalLogs = $pdo->query("SELECT COUNT(*) FROM cv_work_logs")->fetchColumn();
$totalRooms = $pdo->query("SELECT COUNT(*) FROM cv_rooms")->fetchColumn();

include 'layout_top.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><span class="page-icon">💾</span>Quản lý dữ liệu</h1>
        <p class="page-subtitle">Import, Export và quản lý dữ liệu khách hàng</p>
    </div>
    <div class="page-actions">
        <a href="?lock=1" class="btn btn-secondary" style="text-decoration:none;">🔒 Khóa lại</a>
    </div>
</div>

<div class="page-body">
    <?php if ($message): ?>
    <div style="background:<?= $messageType === 'error' ? 'var(--status-danger)' : 'var(--status-safe)' ?>15; 
                border:1px solid <?= $messageType === 'error' ? 'var(--status-danger)' : 'var(--status-safe)' ?>; 
                border-radius:var(--radius-lg); padding:16px; margin-bottom:24px; 
                color:<?= $messageType === 'error' ? 'var(--status-danger)' : 'var(--status-safe)' ?>;
                white-space:pre-line; font-size:14px;">
        <?= sanitize($message) ?>
    </div>
    <?php endif; ?>

    <!-- THỐNG KÊ -->
    <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:32px;">
        <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:20px; text-align:center;">
            <div style="font-size:32px; font-weight:800; color:var(--accent-blue);"><?= $totalCustomers ?></div>
            <div style="font-size:13px; color:var(--text-muted); margin-top:4px;">👤 Khách hàng</div>
        </div>
        <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:20px; text-align:center;">
            <div style="font-size:32px; font-weight:800; color:var(--accent-purple);"><?= $totalLogs ?></div>
            <div style="font-size:13px; color:var(--text-muted); margin-top:4px;">📝 Nhật ký</div>
        </div>
        <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:20px; text-align:center;">
            <div style="font-size:32px; font-weight:800; color:var(--accent-green);"><?= $totalRooms ?></div>
            <div style="font-size:13px; color:var(--text-muted); margin-top:4px;">🏠 Phòng</div>
        </div>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px;">
        <!-- IMPORT -->
        <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
            <h3 style="margin:0 0 8px 0; font-size:18px;">📥 Import dữ liệu</h3>
            <p style="color:var(--text-muted); font-size:13px; margin-bottom:20px;">Upload file .xlsx từ Notion để import khách hàng</p>
            
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import">
                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label">📋 File khách hàng (.xlsx) <span style="color:var(--text-muted);font-weight:400;">— không bắt buộc</span></label>
                    <input type="file" name="xlsx_file" accept=".xlsx" class="form-input"
                           style="padding:8px; background:var(--bg-input); border:1px solid var(--border-color); border-radius:var(--radius-md); color:var(--text-primary); width:100%;">
                </div>
                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label">📝 File nhật ký (.xlsx) <span style="color:var(--text-muted);font-weight:400;">— không bắt buộc</span></label>
                    <input type="file" name="log_file" accept=".xlsx" class="form-input"
                           style="padding:8px; background:var(--bg-input); border:1px solid var(--border-color); border-radius:var(--radius-md); color:var(--text-primary); width:100%;">
                </div>
                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label">🔄 File chuyển phòng (.xlsx) <span style="color:var(--text-muted);font-weight:400;">— không bắt buộc</span></label>
                    <input type="file" name="transfer_file" accept=".xlsx" class="form-input"
                           style="padding:8px; background:var(--bg-input); border:1px solid var(--border-color); border-radius:var(--radius-md); color:var(--text-primary); width:100%;">
                </div>
                <div style="margin-bottom:16px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:14px; color:var(--text-secondary);">
                        <input type="checkbox" name="delete_old" value="1" style="width:18px; height:18px;">
                        <span>🗑️ Xóa dữ liệu cũ trước khi import</span>
                    </label>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">📥 Bắt đầu Import</button>
            </form>
            
            <div style="margin-top:16px; padding:12px; background:var(--bg-main); border-radius:var(--radius-md); font-size:12px; color:var(--text-muted);">
                <strong>Cấu trúc file:</strong><br>
                Cột A: Họ tên &nbsp;|&nbsp; B: CCCD &nbsp;|&nbsp; D: HKTT &nbsp;|&nbsp; E: Facebook<br>
                J: Phòng ban &nbsp;|&nbsp; M: SĐT &nbsp;|&nbsp; P: Công ty &nbsp;|&nbsp; W: Đơn vị
            </div>
        </div>

        <!-- EXPORT + DELETE -->
        <div style="display:flex; flex-direction:column; gap:24px;">
            <!-- EXPORT -->
            <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
                <h3 style="margin:0 0 8px 0; font-size:18px;">📤 Export dữ liệu</h3>
                <p style="color:var(--text-muted); font-size:13px; margin-bottom:20px;">Tải toàn bộ danh sách khách hàng ra file Excel (.xlsx)</p>
                <form method="POST">
                    <input type="hidden" name="action" value="export">
                    <button type="submit" class="btn btn-secondary" style="width:100%;" <?= $totalCustomers == 0 ? 'disabled' : '' ?>>
                        📤 Xuất <?= $totalCustomers ?> khách hàng
                    </button>
                </form>
            </div>

            <!-- XÓA NHẬT KÝ & CHUYỂN PHÒNG -->
            <div style="background:var(--bg-card); border:1px solid var(--status-warning)30; border-radius:var(--radius-lg); padding:24px;">
                <h3 style="margin:0 0 8px 0; font-size:18px; color:var(--status-warning);">⚠️ Dọn dẹp Lịch sử</h3>
                <p style="color:var(--text-muted); font-size:13px; margin-bottom:20px;">Dọn sạch lịch sử <strong>Nhật ký làm việc</strong> và <strong>Chuyển phòng</strong>. <strong style="color:var(--status-safe);">Khách hàng & Lịch sử Thu/Chi kế toán được giữ nguyên 100%!</strong></p>
                <form method="POST" onsubmit="return confirmClearLogs()">
                    <input type="hidden" name="action" value="clear_logs">
                    <div class="form-group" style="margin-bottom:16px;">
                        <label class="form-label" style="color:var(--text-muted);">Gõ <strong style="color:var(--status-warning);">XOA NHAT KY</strong> để xác nhận</label>
                        <input type="text" name="confirm_delete" class="form-input" placeholder="XOA NHAT KY" autocomplete="off"
                               style="border-color:var(--status-warning)40;">
                    </div>
                    <button type="submit" class="btn" style="width:100%; background:var(--status-warning); color:#fff;">
                        🗑️ Xóa <?= $totalLogs ?> nhật ký
                    </button>
                </form>
            </div>
            
            <!-- QUÉT KHÁCH TRÙNG -->
            <div style="background:var(--bg-card); border:1px solid rgba(16, 185, 129, 0.3); border-radius:var(--radius-lg); padding:24px;">
                <h3 style="margin:0 0 8px 0; font-size:18px; color:var(--accent-green);">🧹 Nhổ Khách Trùng Lặp</h3>
                <p style="color:var(--text-muted); font-size:13px; margin-bottom:20px;">Hệ thống sẽ rà soát các tên khách bị nhập đúp <strong>(Trùng tên + SĐT)</strong>, gộp dồn dữ liệu về chung 1 người và xóa các bản rác thừa thãi.</p>
                <form method="POST" onsubmit="return confirm('Anh có chắc chắn muốn dọn dẹp và gộp các khách hàng bị trùng tên không?');">
                    <input type="hidden" name="action" value="deduplicate">
                    <button type="submit" class="btn" style="width:100%; background:var(--accent-green); color:#fff;">
                        ✨ Quét & Gộp (1 Click)
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmClearLogs() {
    const input = document.querySelector('input[name="confirm_delete"]').value;
    if (input !== 'XOA NHAT KY') {
        alert('Vui lòng gõ đúng: XOA NHAT KY');
        return false;
    }
    return confirm('Hành động này sẽ xóa sạch Nhật ký làm việc và Lịch sử chuyển phòng (Khách & Kế toán GIỮ NGUYÊN). Bạn có chắc chắn?');
}
</script>

<?php include 'layout_bottom.php'; ?>
