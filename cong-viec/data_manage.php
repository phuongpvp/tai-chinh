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
    
    // ─────────────── XÓA TẤT CẢ ───────────────
    if ($action === 'delete_all') {
        $confirm = $_POST['confirm_delete'] ?? '';
        if ($confirm === 'XOA TAT CA') {
            $logCount = $pdo->query("SELECT COUNT(*) FROM cv_work_logs")->fetchColumn();
            $custCount = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
            $tfCount = $pdo->query("SELECT COUNT(*) FROM cv_transfer_logs")->fetchColumn();
            $pdo->exec("DELETE FROM cv_transfer_logs");
            $pdo->exec("DELETE FROM cv_work_logs");
            $pdo->exec("DELETE FROM customers");
            $message = "🗑️ Đã xóa $custCount khách hàng + $logCount nhật ký + $tfCount chuyển phòng";
            $messageType = 'success';
        } else {
            $message = "❌ Nhập sai xác nhận. Phải gõ đúng: XOA TAT CA";
            $messageType = 'error';
        }
    }
    
    // ─────────────── IMPORT ───────────────
    if ($action === 'import' && isset($_FILES['xlsx_file'])) {
        $file = $_FILES['xlsx_file'];
        if ($file['error'] === UPLOAD_ERR_OK && $file['size'] > 0) {
            set_time_limit(600);
            $data = readXlsxFile($file['tmp_name']);
            
            if ($data === false) {
                $message = "❌ Không đọc được file XLSX";
                $messageType = 'error';
            } else {
                $log = [];
                $deleteOld = isset($_POST['delete_old']);
                
                if ($deleteOld) {
                    $pdo->exec("DELETE FROM cv_work_logs");
                    $pdo->exec("DELETE FROM customers");
                    $log[] = "🗑️ Đã xóa dữ liệu cũ";
                }
                
                // Mở rộng cột
                try { $pdo->exec("ALTER TABLE customers MODIFY phone VARCHAR(50) DEFAULT NULL"); } catch(Exception $e) {}
                try { $pdo->exec("ALTER TABLE customers MODIFY facebook_link TEXT DEFAULT NULL"); } catch(Exception $e) {}
                
                // Đọc phòng
                $rooms = [];
                foreach ($pdo->query("SELECT id, name, sla_days FROM cv_rooms") as $r) {
                    $rooms[mb_strtolower(trim($r['name']))] = ['id' => $r['id'], 'sla' => intval($r['sla_days'] ?? 0)];
                }
                
                // Tìm phòng từ dữ liệu
                $archiveNames = ['đã hoàn thành', 'lưu trữ - khách đã đóng hđ', 'lưu trữ - khách đã đi'];
                $notionRooms = [];
                for ($i = 1; $i < count($data); $i++) {
                    $roomRaw = cleanNotionUrl($data[$i][9]);
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
                    $name     = trim($row[0]);
                    $cccd     = trim($row[1]);
                    $hktt     = trim($row[3]);
                    $facebook = cleanNotionUrl(trim($row[4]));
                    $noiO     = trim($row[8]);
                    $roomRaw  = cleanNotionUrl(trim($row[9]));
                    $phone    = trim($row[12]);
                    $congTy   = trim($row[15]);
                    $nguoiThan= trim($row[16]);
                    $donVi    = trim($row[22]);
                    
                    if (empty($name)) continue;
                    
                    // Fix CCCD
                    if (is_numeric($cccd) && strpos($cccd, 'E') !== false) {
                        $cccd = number_format(floatval($cccd), 0, '', '');
                    }
                    if (is_numeric($cccd)) $cccd = str_pad($cccd, 12, '0', STR_PAD_LEFT);
                    
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
                    
                    if (strlen($facebook) > 250) $facebook = substr($facebook, 0, 250);
                    
                    // Map phòng
                    $rk = mb_strtolower($roomRaw);
                    $roomInfo = $rooms[$rk] ?? $rooms[array_key_first($rooms)];
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
                
                // Import nhật ký nếu có file
                if (isset($_FILES['log_file']) && $_FILES['log_file']['error'] === UPLOAD_ERR_OK && $_FILES['log_file']['size'] > 0) {
                    $logData = readXlsxFile($_FILES['log_file']['tmp_name']);
                    if ($logData) {
                        $admin = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch();
                        $uid = $admin ? $admin['id'] : 1;
                        $defRoom = $pdo->query("SELECT id FROM cv_rooms ORDER BY id LIMIT 1")->fetchColumn();
                        
                        // Build customer name → id map
                        $custIds = [];
                        $allCusts = $pdo->query("SELECT id, name FROM customers")->fetchAll();
                        foreach ($allCusts as $ac) {
                            $custIds[mb_strtolower($ac['name'])] = $ac['id'];
                        }
                        
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
                        
                        $imported = 0;
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
                        }
                        $log[] = "📝 Import $imported nhật ký";
                    }
                }
                
                // Import lịch sử chuyển phòng nếu có file
                if (isset($_FILES['transfer_file']) && $_FILES['transfer_file']['error'] === UPLOAD_ERR_OK && $_FILES['transfer_file']['size'] > 0) {
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
                $messageType = 'success';
            }
        } else {
            $message = "❌ Vui lòng chọn file XLSX";
            $messageType = 'error';
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
                    <label class="form-label">📋 File khách hàng (.xlsx) *</label>
                    <input type="file" name="xlsx_file" accept=".xlsx" class="form-input" required
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

            <!-- XÓA TẤT CẢ -->
            <div style="background:var(--bg-card); border:1px solid var(--status-danger)30; border-radius:var(--radius-lg); padding:24px;">
                <h3 style="margin:0 0 8px 0; font-size:18px; color:var(--status-danger);">⚠️ Xóa tất cả dữ liệu</h3>
                <p style="color:var(--text-muted); font-size:13px; margin-bottom:20px;">Xóa toàn bộ khách hàng và nhật ký. <strong style="color:var(--status-danger);">Không thể hoàn tác!</strong></p>
                <form method="POST" onsubmit="return confirmDeleteAll()">
                    <input type="hidden" name="action" value="delete_all">
                    <div class="form-group" style="margin-bottom:16px;">
                        <label class="form-label" style="color:var(--text-muted);">Gõ <strong style="color:var(--status-danger);">XOA TAT CA</strong> để xác nhận</label>
                        <input type="text" name="confirm_delete" class="form-input" placeholder="XOA TAT CA" autocomplete="off"
                               style="border-color:var(--status-danger)40;">
                    </div>
                    <button type="submit" class="btn" style="width:100%; background:var(--status-danger); color:#fff;" <?= $totalCustomers == 0 ? 'disabled' : '' ?>>
                        🗑️ Xóa <?= $totalCustomers ?> khách + <?= $totalLogs ?> nhật ký
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDeleteAll() {
    const input = document.querySelector('input[name="confirm_delete"]').value;
    if (input !== 'XOA TAT CA') {
        alert('Vui lòng gõ đúng: XOA TAT CA');
        return false;
    }
    return confirm('Bạn chắc chắn muốn xóa TẤT CẢ dữ liệu? Hành động này KHÔNG THỂ hoàn tác!');
}
</script>

<?php include 'layout_bottom.php'; ?>
