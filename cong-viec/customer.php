<?php
require_once 'config.php';
requireLogin();

$customerId = intval($_GET['id'] ?? 0);
if (!$customerId) redirect('index.php');

$user = cvGetUser();

// Lấy thông tin khách hàng + phòng (bao gồm SLA)
$stmt = $pdo->prepare("SELECT l.id, c.name, c.phone, c.identity_card as cccd, c.address, c.gender, c.date_of_birth,
    l.cv_room_id as room_id, l.cv_assigned_to as assigned_to, l.cv_status as status, 
    l.cv_due_date as due_date, l.cv_transfer_date as transfer_date,
    l.cv_notes as notes, l.cv_pinned_note as pinned_note, l.cv_description as description,
    l.cv_planned_next_room_id as planned_next_room_id, l.cv_drive_folder_id as drive_folder_id,
    l.loan_code, l.amount as loan_amount, l.created_at,
    l.next_payment_date, l.status as loan_status,
    l.cv_facebook_link as facebook_link, l.cv_workplace as workplace, l.cv_hktt as hktt, 
    l.cv_relatives_info as relatives_info, l.cv_company_tag as company_tag, l.cv_tc_info as tc_info,
    r.name as room_name, r.icon as room_icon, r.sla_days, u.fullname as assigned_name
    FROM loans l 
    LEFT JOIN customers c ON l.customer_id = c.id
    LEFT JOIN cv_rooms r ON l.cv_room_id = r.id
    LEFT JOIN users u ON l.cv_assigned_to = u.id
    WHERE l.id = ?");
$stmt->execute([$customerId]);
$customer = $stmt->fetch();
if (!$customer) redirect('index.php');

$pageTitle = $customer['name'];
$activePage = 'room-' . $customer['room_id'];

// ============================================================
// XỬ LÝ CÁC ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'update_info':
            $name = trim($_POST['name'] ?? '');
            $dueDate = $_POST['due_date'] ?? null;
            $assignedTo = intval($_POST['assigned_to'] ?? 0) ?: null;
            $notes = trim($_POST['notes'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $cccd = trim($_POST['cccd'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $facebookLink = trim($_POST['facebook_link'] ?? '');
            $relativesInfo = trim($_POST['relatives_info'] ?? '');
            $companyTag = trim($_POST['company_tag'] ?? '');
            $workplace = trim($_POST['workplace'] ?? '');
            $hktt = trim($_POST['hktt'] ?? '');
            $plannedNextRoomId = intval($_POST['planned_next_room_id'] ?? 0) ?: null;
            
            if (!empty($name)) {
                $stmt = $pdo->prepare("UPDATE customers SET 
                    name=?, phone=?, identity_card=?, address=?
                    WHERE id = (SELECT customer_id FROM loans WHERE id = ?)");
                $stmt->execute([$name, $phone ?: null, $cccd ?: null, $address ?: null, $customerId]);
                
                $stmt2 = $pdo->prepare("UPDATE loans SET 
                    cv_due_date=?, cv_assigned_to=?, cv_notes=?, cv_planned_next_room_id=?,
                    cv_facebook_link=?, cv_workplace=?, cv_hktt=?, cv_relatives_info=?, cv_company_tag=?
                    WHERE id=?");
                $stmt2->execute([$dueDate ?: null, $assignedTo, $notes, $plannedNextRoomId,
                    $facebookLink ?: null, $workplace ?: null, $hktt ?: null, $relativesInfo ?: null, $companyTag ?: null,
                    $customerId]);
                $_SESSION['flash_message'] = 'Đã cập nhật thông tin khách hàng';
                redirect('customer.php?id=' . $customerId);
            }
            break;

        case 'save_description':
            $desc = trim($_POST['description'] ?? '');
            $stmt = $pdo->prepare("UPDATE loans SET cv_description = ? WHERE id = ?");
            $stmt->execute([$desc ?: null, $customerId]);
            $_SESSION['flash_message'] = 'Đã cập nhật mô tả khách hàng';
            redirect('customer.php?id=' . $customerId);
            break;

        case 'change_room':
            $newRoomId = intval($_POST['room_id'] ?? 0);
            $transferNote = trim($_POST['transfer_note'] ?? '');
            
            if ($newRoomId) {
                $fromRoomId = $customer['room_id'];
                
                // === VALIDATE: Không được chuyển sang cùng phòng ===
                if ($newRoomId === $fromRoomId) {
                    // Tạo Violation
                    $pdo->prepare("INSERT INTO cv_violations (type, loan_id, user_id, detail) VALUES (?, ?, ?, ?)")
                        ->execute(['TRANSFER_SAME_ROOM', $customerId, $user['id'], 
                            json_encode(['from_room_id' => $fromRoomId, 'to_room_id' => $newRoomId])]);
                    $_SESSION['flash_message'] = 'Không thể chuyển sang cùng phòng!';
                    $_SESSION['flash_type'] = 'error';
                    redirect('customer.php?id=' . $customerId);
                }
                
                // === CHỐNG DUPLICATE (60 giây) ===
                $dupCheck = $pdo->prepare("SELECT id FROM cv_transfer_logs 
                    WHERE loan_id = ? AND from_room_id = ? AND to_room_id = ? 
                    AND transferred_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)");
                $dupCheck->execute([$customerId, $fromRoomId, $newRoomId]);
                if ($dupCheck->fetch()) {
                    $pdo->prepare("INSERT INTO cv_violations (type, loan_id, user_id, detail) VALUES (?, ?, ?, ?)")
                        ->execute(['DUPLICATE_TRANSFER', $customerId, $user['id'],
                            json_encode(['from_room_id' => $fromRoomId, 'to_room_id' => $newRoomId, 'within_seconds' => 60])]);
                    $_SESSION['flash_message'] = 'Thao tác trùng lặp! Vui lòng đợi 60 giây.';
                    $_SESSION['flash_type'] = 'warning';
                    redirect('customer.php?id=' . $customerId);
                }
                
                // === TRANSACTION: Chuyển phòng ===
                $pdo->beginTransaction();
                try {
                    $now = date('Y-m-d H:i:s');
                    
                    // 1) Tạo TransferLog
                    $stmt = $pdo->prepare("INSERT INTO cv_transfer_logs (loan_id, from_room_id, to_room_id, transferred_by, transferred_at, note) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$customerId, $fromRoomId, $newRoomId, $user['id'], $now, $transferNote ?: null]);
                    
                    // 2) Lấy SLA phòng mới
                    $newRoom = $pdo->prepare("SELECT sla_days FROM cv_rooms WHERE id = ?");
                    $newRoom->execute([$newRoomId]);
                    $newSlaDays = intval($newRoom->fetchColumn());
                    
                    // 3) Tính due_date mới từ SLA
                    $newDueDate = null;
                    if ($newSlaDays > 0) {
                        $computed = computeCaseStatus($now, $newSlaDays);
                        $newDueDate = $computed['due_date'];
                    }
                    
                    // 4) Update customer
                    $updateSql = "UPDATE loans SET cv_room_id=?, cv_transfer_date=?, cv_planned_next_room_id=NULL";
                    $updateParams = [$newRoomId, $now];
                    if ($newDueDate) {
                        $updateSql .= ", cv_due_date=?";
                        $updateParams[] = $newDueDate;
                    }
                    $updateSql .= " WHERE id=?";
                    $updateParams[] = $customerId;
                    $pdo->prepare($updateSql)->execute($updateParams);
                    
                    $pdo->commit();
                    $_SESSION['flash_message'] = 'Đã chuyển khách sang phòng mới';
                    redirect('customer.php?id=' . $customerId);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $_SESSION['flash_message'] = 'Lỗi khi chuyển phòng: ' . $e->getMessage();
                    $_SESSION['flash_type'] = 'error';
                    redirect('customer.php?id=' . $customerId);
                }
            }
            break;

        case 'add_comment':
            $content = trim($_POST['content'] ?? '');
            if (!empty($content)) {
                $stmt = $pdo->prepare("INSERT INTO cv_comments (loan_id, user_id, content) VALUES (?, ?, ?)");
                $stmt->execute([$customerId, $user['id'], $content]);
                $_SESSION['flash_message'] = 'Đã thêm bình luận';
                redirect('customer.php?id=' . $customerId);
            }
            break;

        case 'add_worklog':
            $workDone = trim($_POST['work_done'] ?? '');
            $logDate = $_POST['log_date'] ?? date('Y-m-d');
            $logRoomId = intval($_POST['log_room_id'] ?? 0) ?: $customer['room_id'];
            $actionType = trim($_POST['action_type'] ?? '');
            $resultType = trim($_POST['result_type'] ?? '');
            $promiseDate = $_POST['promise_date'] ?? null;
            // Số tiền lãi đã trả
            $amountInterest = $_POST['amount_interest'] ?? null;
            if ($amountInterest !== null && $amountInterest !== '') {
                $amountInterest = floatval(str_replace([',', '.'], '', $amountInterest));
            } else {
                $amountInterest = null;
            }
            // Số tiền gốc đã trả
            $amountPrincipal = $_POST['amount_principal'] ?? null;
            if ($amountPrincipal !== null && $amountPrincipal !== '') {
                $amountPrincipal = floatval(str_replace([',', '.'], '', $amountPrincipal));
            } else {
                $amountPrincipal = null;
            }
            if (!empty($actionType) || !empty($workDone)) {
                // Merge custom detail + notes(workDone) properly
                $customDetail = trim($_POST['custom_detail'] ?? '');
                if (!empty($customDetail) && !empty($workDone)) {
                    $workDone = $customDetail . '; ' . $workDone;
                } elseif (!empty($customDetail)) {
                    $workDone = $customDetail;
                } elseif (empty($workDone)) {
                    $workDone = '';
                }
                $stmt = $pdo->prepare("INSERT INTO cv_work_logs (loan_id, user_id, room_id, work_done, log_date, action_type, result_type, promise_date, amount, amount_principal) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$customerId, $user['id'], $logRoomId, $workDone, $logDate, 
                    $actionType ?: null, $resultType ?: null, $promiseDate ?: null, $amountInterest, $amountPrincipal]);
                $_SESSION['flash_message'] = 'Đã lưu nhật ký làm việc';
                redirect('customer.php?id=' . $customerId . '&tab=worklogs');
            }
            break;

        case 'update_pinned':
            $pinnedNote = trim($_POST['pinned_note'] ?? '');
            $stmt = $pdo->prepare("UPDATE loans SET cv_pinned_note = ? WHERE id = ?");
            $stmt->execute([$pinnedNote, $customerId]);
            $_SESSION['flash_message'] = 'Đã cập nhật nội dung lưu ý';
            redirect('customer.php?id=' . $customerId);
            break;

        case 'mark_completed':
            if ($user['role'] !== 'employee') {
                $oldRoomId = $customer['room_id'];
                // Tìm phòng "Đã hoàn thành"
                $htRoom = $pdo->query("SELECT id FROM cv_rooms WHERE name LIKE '%hoàn thành%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $htRoomId = $htRoom ? intval($htRoom['id']) : null;

                $stmt = $pdo->prepare("UPDATE loans SET cv_room_id = ?, cv_transfer_date = ? WHERE id = ?");
                if ($htRoomId) {
                    $stmt->execute([$htRoomId, date('Y-m-d'), $customerId]);
                    // Log chuyển phòng
                    if ($oldRoomId && intval($oldRoomId) !== $htRoomId) {
                        $pdo->prepare("INSERT INTO cv_transfer_logs (loan_id, from_room_id, to_room_id, transferred_by, note) VALUES (?, ?, ?, ?, ?)")
                             ->execute([$customerId, $oldRoomId, $htRoomId, $user['id'], 'Thủ công: Đánh dấu hoàn thành']);
                    }
                } else {
                    $stmt->execute([$customerId]);
                }
                $_SESSION['flash_message'] = 'Đã đánh dấu hoàn thành';
                redirect('room.php?id=' . ($htRoomId ?: $customer['room_id']));
            }
            break;

        case 'upload_file':
            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                require_once 'google_drive.php';
                try {
                    $drive = new GoogleDrive();

                    // Tạo subfolder cho khách nếu chưa có
                    $custFolderId = $customer['drive_folder_id'] ?? null;
                    if (!$custFolderId) {
                        $custFolderId = $drive->createFolder($customer['name'] . ' #' . $customerId);
                        $pdo->prepare("UPDATE loans SET cv_drive_folder_id = ? WHERE id = ?")->execute([$custFolderId, $customerId]);
                    }

                    // Upload file
                    $result = $drive->uploadFile(
                        $_FILES['file']['tmp_name'],
                        $_FILES['file']['name'],
                        $_FILES['file']['type'] ?: 'application/octet-stream',
                        $custFolderId
                    );

                    // Set public
                    if (isset($result['id'])) {
                        $drive->makePublic($result['id']);
                        $link = 'https://drive.google.com/file/d/' . $result['id'] . '/view';

                        $stmt = $pdo->prepare("INSERT INTO cv_customer_files (loan_id, file_name, drive_file_id, drive_folder_id, drive_link, mime_type, file_size, uploaded_by) VALUES (?,?,?,?,?,?,?,?)");
                        $stmt->execute([$customerId, $_FILES['file']['name'], $result['id'], $custFolderId, $link, $_FILES['file']['type'], $_FILES['file']['size'], $user['id']]);

                        $_SESSION['flash_message'] = 'Đã upload file: ' . $_FILES['file']['name'];
                    } else {
                        $errDetail = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                        $_SESSION['flash_message'] = 'Upload thất bại: ' . ($result['error']['message'] ?? $errDetail);
                        $_SESSION['flash_type'] = 'error';
                    }
                } catch (Exception $e) {
                    $_SESSION['flash_message'] = 'Lỗi: ' . $e->getMessage();
                    $_SESSION['flash_type'] = 'error';
                }
            }
            redirect('customer.php?id=' . $customerId . '&tab=files');
            break;

        case 'delete_file':
            $fileId = intval($_POST['file_id'] ?? 0);
            if ($fileId) {
                $file = $pdo->prepare("SELECT drive_file_id FROM cv_customer_files WHERE id = ? AND loan_id = ?");
                $file->execute([$fileId, $customerId]);
                $driveFileId = $file->fetchColumn();
                if ($driveFileId) {
                    require_once 'google_drive.php';
                    try {
                        $drive = new GoogleDrive();
                        $drive->deleteFile($driveFileId);
                    } catch (Exception $e) { /* ignore */ }
                    $pdo->prepare("DELETE FROM cv_customer_files WHERE id = ?")->execute([$fileId]);
                    $_SESSION['flash_message'] = 'Đã xóa file';
                }
            }
            redirect('customer.php?id=' . $customerId . '&tab=files');
            break;
    }
}

// Danh sách phòng (cho chuyển phòng — bao gồm cả phòng lưu trữ)
$rooms = $pdo->query("SELECT id, name, icon, sla_days, is_archive FROM cv_rooms ORDER BY sort_order, name")->fetchAll();

// Danh sách nhân viên
$employees = $pdo->query("SELECT id, fullname FROM users WHERE cv_role IS NOT NULL ORDER BY fullname")->fetchAll();

// File đính kèm
$customerFiles = $pdo->prepare("SELECT cf.*, u.fullname as uploader FROM cv_customer_files cf LEFT JOIN users u ON cf.uploaded_by = u.id WHERE cf.loan_id = ? ORDER BY cf.created_at DESC");
$customerFiles->execute([$customerId]);
$customerFiles = $customerFiles->fetchAll();

// Bình luận
$comments = $pdo->prepare("SELECT cm.*, u.fullname, u.role 
    FROM cv_comments cm 
    JOIN users u ON cm.user_id = u.id 
    WHERE cm.loan_id = ? 
    ORDER BY cm.created_at DESC");
$comments->execute([$customerId]);
$comments = $comments->fetchAll();

// Nhật ký làm việc
$workLogs = $pdo->prepare("SELECT wl.*, u.fullname as user_name, r.name as room_name
    FROM cv_work_logs wl
    JOIN users u ON wl.user_id = u.id
    LEFT JOIN cv_rooms r ON wl.room_id = r.id
    WHERE wl.loan_id = ?
    ORDER BY wl.log_date ASC, wl.created_at ASC");
$workLogs->execute([$customerId]);
$workLogs = $workLogs->fetchAll();

// Lịch sử chuyển phòng
$transferLogs = $pdo->prepare("SELECT tl.*, 
    fr.name as from_room_name, fr.icon as from_room_icon,
    tr.name as to_room_name, tr.icon as to_room_icon,
    u.fullname as transferred_by_name
    FROM cv_transfer_logs tl
    LEFT JOIN cv_rooms fr ON tl.from_room_id = fr.id
    LEFT JOIN cv_rooms tr ON tl.to_room_id = tr.id
    LEFT JOIN users u ON tl.transferred_by = u.id
    WHERE tl.loan_id = ?
    ORDER BY tl.transferred_at DESC");
$transferLogs->execute([$customerId]);
$transferLogs = $transferLogs->fetchAll();

// Lấy giao dịch tài chính từ TC
$tcTransactions = $pdo->prepare("SELECT t.*, u.fullname as user_name FROM transactions t LEFT JOIN users u ON t.user_id = u.id WHERE t.loan_id = ? ORDER BY COALESCE(t.created_at, t.date) DESC");
$tcTransactions->execute([$customerId]);
$tcTransactions = $tcTransactions->fetchAll();

// Build combined timeline
$timeline = [];
foreach ($workLogs as $wl) {
    $parts = [];
    if ($wl['room_name']) $parts[] = 'Phòng làm việc: ' . $wl['room_name'];
    if ($wl['action_type']) $parts[] = 'Việc đã làm: ' . $wl['action_type'];
    if ($wl['result_type']) $parts[] = 'Kết quả: ' . $wl['result_type'];
    if ($wl['amount']) $parts[] = 'Lãi đã trả: ' . number_format($wl['amount'], 0, ',', '.') . 'đ';
    if (!empty($wl['amount_principal'])) $parts[] = 'Gốc đã trả: ' . number_format($wl['amount_principal'], 0, ',', '.') . 'đ';
    if ($wl['promise_date']) $parts[] = 'Ngày hẹn: ' . date('d/m/Y', strtotime($wl['promise_date']));
    if ($wl['work_done'] && $wl['work_done'] !== ($wl['action_type'] . ' → ' . $wl['result_type'])) $parts[] = 'Ghi chú: ' . $wl['work_done'];
    $timeline[] = [
        'date' => $wl['log_date'] . ' ' . date('H:i:s', strtotime($wl['created_at'])),
        'sort_time' => strtotime($wl['created_at'] ?? $wl['log_date']),
        'type' => 'Nhật ký làm việc',
        'type_key' => 'worklog',
        'summary' => implode('; ', $parts) ?: $wl['work_done'],
        'user' => $wl['user_name'] ?? '',
        'room' => $wl['room_name'] ?? ''
    ];
}
foreach ($transferLogs as $tl) {
    $parts = [];
    $parts[] = 'Từ phòng: ' . ($tl['from_room_name'] ?? '?');
    $parts[] = 'Đến phòng: ' . ($tl['to_room_name'] ?? '?');
    $parts[] = 'Người chuyển: ' . ($tl['transferred_by_name'] ?? '?');
    if ($tl['note']) $parts[] = 'Ghi chú: ' . $tl['note'];
    $timeline[] = [
        'date' => $tl['transferred_at'],
        'sort_time' => strtotime($tl['transferred_at']),
        'type' => 'Nhật ký chuyển phòng',
        'type_key' => 'transfer',
        'summary' => implode('; ', $parts),
        'user' => $tl['transferred_by_name'] ?? '',
        'room' => ($tl['from_room_name'] ?? '') . ' → ' . ($tl['to_room_name'] ?? '')
    ];
}
$txTypeLabels = ['collect_interest'=>'đóng lãi','disburse'=>'giải ngân','pay_principal'=>'trả gốc','pay_all'=>'tất toán','lend_more'=>'cho vay thêm','adjust_debt'=>'điều chỉnh nợ','pay_debt'=>'trả nợ'];
foreach ($tcTransactions as $tx) {
    $label = $txTypeLabels[$tx['type']] ?? $tx['type'];
    $parts = [];
    $parts[] = 'Loại: ' . ucfirst($label);
    $parts[] = 'Số tiền: ' . number_format($tx['amount'], 0, ',', '.') . 'đ';
    if ($tx['note']) $parts[] = 'Ghi chú: ' . $tx['note'];
    $timeline[] = [
        'date' => $tx['created_at'] ?? $tx['date'],
        'sort_time' => strtotime($tx['created_at'] ?? $tx['date']),
        'type' => 'Kế toán nhập tiền',
        'type_key' => 'transaction',
        'summary' => implode('; ', $parts),
        'user' => $tx['user_name'] ?? '',
        'room' => ''
    ];
}
usort($timeline, function($a, $b) { return $b['sort_time'] - $a['sort_time']; });

// Tab hiện tại
$tab = $_GET['tab'] ?? 'worklog';

// XLSX export (must be before any HTML output)
if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    require_once __DIR__ . '/../SimpleXLSXGen.php';
    $exportTab = $tab;
    $safeCustomerName = preg_replace('/[^a-zA-Z0-9_]/', '', $customer['name']);
    
    if ($exportTab === 'timeline') {
        $rows = [['Thời gian', 'Họ tên khách', 'Loại thông tin', 'Nội dung tổng hợp']];
        foreach ($timeline as $item) {
            $rows[] = [
                date('d/m/Y', strtotime($item['date'])),
                $customer['name'],
                $item['type'],
                $item['summary']
            ];
        }
        SimpleXLSXGen::fromArray($rows)->downloadAs('tonghop_' . $safeCustomerName . '_' . date('Ymd') . '.xlsx');
    }
    
    if ($exportTab === 'worklog') {
        $rows = [['Ngày', 'Họ tên khách', 'Nhân viên', 'Phòng làm việc', 'Việc đã làm', 'Kết quả', 'Ghi chú', 'Lãi đã trả', 'Gốc đã trả']];
        foreach ($workLogs as $wl) {
            $rows[] = [
                date('d/m/Y', strtotime($wl['log_date'])),
                $customer['name'],
                $wl['user_name'] ?? '',
                $wl['room_name'] ?? '',
                $wl['action_type'] ?? '',
                $wl['result_type'] ?? '',
                $wl['work_done'] ?? '',
                $wl['amount'] ? number_format($wl['amount'], 0, ',', '.') : '',
                !empty($wl['amount_principal']) ? number_format($wl['amount_principal'], 0, ',', '.') : ''
            ];
        }
        SimpleXLSXGen::fromArray($rows)->downloadAs('nhatky_' . $safeCustomerName . '_' . date('Ymd') . '.xlsx');
    }
    
    if ($exportTab === 'transfers') {
        $rows = [['Thời gian', 'Họ tên khách', 'Từ phòng', 'Đến phòng', 'Người chuyển', 'Ghi chú']];
        foreach ($transferLogs as $tl) {
            $rows[] = [
                date('d/m/Y H:i', strtotime($tl['transferred_at'])),
                $customer['name'],
                $tl['from_room_name'] ?? '',
                $tl['to_room_name'] ?? '',
                $tl['transferred_by_name'] ?? '',
                $tl['note'] ?? ''
            ];
        }
        SimpleXLSXGen::fromArray($rows)->downloadAs('chuyenphong_' . $safeCustomerName . '_' . date('Ymd') . '.xlsx');
    }
}

// Tính trạng thái
$days = getDaysRemaining($customer['due_date']);
$statusColor = getStatusColor($days);
$statusText = getStatusLabel($days);

// Planned next room name
$plannedRoomName = null;
if ($customer['planned_next_room_id']) {
    $pr = $pdo->prepare("SELECT name, icon FROM cv_rooms WHERE id = ?");
    $pr->execute([$customer['planned_next_room_id']]);
    $plannedRoom = $pr->fetch();
    if ($plannedRoom) $plannedRoomName = $plannedRoom['icon'] . ' ' . $plannedRoom['name'];
}

include 'layout_top.php';
?>

<div class="page-header">
    <div>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
            <a href="room.php?id=<?= $customer['room_id'] ?>" class="btn btn-ghost btn-sm" style="text-decoration:none">← Quay lại</a>
        </div>
        <div style="display:flex;align-items:center;gap:16px;">
            <div class="customer-avatar" style="width:56px;height:56px;font-size:28px;">👤</div>
            <div>
                <h1 class="page-title" style="font-size:26px;"><?= sanitize($customer['name']) ?></h1>
                <div style="display:flex;align-items:center;gap:12px;margin-top:6px;flex-wrap:wrap;">
                    <button class="btn btn-ghost btn-sm" onclick="openModal('change-room-modal')">🔄 Chuyển qua Phòng</button>
                    <button class="btn btn-ghost btn-sm" onclick="openModal('worklog-modal')">📝 Lưu Nhật Ký</button>
                    <a href="../contracts.php" class="btn btn-ghost btn-sm" style="text-decoration:none;color:var(--accent-blue);" target="_blank">💰 Tài chính</a>
                    <span style="font-size:13px;">
                        <span style="margin-right:4px"><?= $customer['room_icon'] ?></span>
                        <?= sanitize($customer['room_name']) ?>
                        <?php if ($customer['sla_days'] > 0): ?>
                            <span style="color:var(--text-muted);font-size:11px;">(Hạn: <?= $customer['sla_days'] ?> ngày)</span>
                        <?php endif; ?>
                    </span>
                    <?php if ($customer['assigned_name']): ?>
                        <span class="tag"><?= sanitize($customer['assigned_name']) ?></span>
                    <?php endif; ?>
                    <span class="customer-status" style="background:<?= $statusColor ?>20; color:<?= $statusColor ?>; font-size:13px;">
                        <span class="status-dot" style="background:<?= $statusColor ?>"></span>
                        <?= $statusText ?>
                    </span>
                    <?php if ($plannedRoomName): ?>
                        <span class="tag" style="--tag-bg:rgba(35,131,226,0.15);--tag-color:#2383e2;">→ <?= sanitize($plannedRoomName) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="page-actions">
        <button class="btn btn-secondary" onclick="openModal('edit-modal')">✏️ Sửa</button>
        <?php if ($user['role'] !== 'employee'): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Đánh dấu hoàn thành?')">
            <input type="hidden" name="action" value="mark_completed">
            <button type="submit" class="btn btn-secondary">✅ Hoàn thành</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="page-body">

    <!-- THÔNG TIN KHÁCH HÀNG MỞ RỘNG -->
    <?php 
    $hasExtraInfo = $customer['phone'] || $customer['cccd'] || $customer['address'] || $customer['facebook_link'] || $customer['company_tag'] || $customer['relatives_info'];
    if ($hasExtraInfo): ?>
    <section style="margin-bottom:28px;">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:16px;">
            <?php if ($customer['phone']): ?>
            <div><span style="color:var(--text-muted);font-size:12px;">📱 SĐT</span><br><strong style="font-size:14px;"><?= sanitize($customer['phone']) ?></strong></div>
            <?php endif; ?>
            <?php if ($customer['cccd']): ?>
            <div><span style="color:var(--text-muted);font-size:12px;">🪪 CCCD</span><br><strong style="font-size:14px;"><?= sanitize($customer['cccd']) ?></strong></div>
            <?php endif; ?>
            <?php if ($customer['company_tag']): ?>
            <div><span style="color:var(--text-muted);font-size:12px;">🏢 Công ty</span><br><strong style="font-size:14px;"><?= sanitize($customer['company_tag']) ?></strong></div>
            <?php endif; ?>
            <?php if ($customer['address']): ?>
            <div><span style="color:var(--text-muted);font-size:12px;">📍 Địa chỉ</span><br><span style="font-size:14px;"><?= sanitize($customer['address']) ?></span></div>
            <?php endif; ?>
            <?php if ($customer['facebook_link']): ?>
            <div><span style="color:var(--text-muted);font-size:12px;">📘 Facebook</span><br><a href="<?= sanitize($customer['facebook_link']) ?>" target="_blank" style="font-size:14px;">Xem trang →</a></div>
            <?php endif; ?>
            <?php if ($customer['relatives_info']): ?>
            <div style="grid-column:1/-1;"><span style="color:var(--text-muted);font-size:12px;">👨‍👩‍👧 Người thân</span><br><span style="font-size:14px;white-space:pre-wrap;"><?= sanitize($customer['relatives_info']) ?></span></div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- THÔNG TIN TÀI CHÍNH (chỉ xem) -->
    <?php if ($customer['loan_amount']): ?>
    <section style="margin-bottom:20px;">
        <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:16px;border-left:3px solid var(--accent-blue);">
            <div style="margin-bottom:8px;">
                <span style="color:var(--accent-blue);font-size:12px;font-weight:600;">💰 THÔNG TIN TÀI CHÍNH (chỉ xem)</span>
                <?php if ($customer['loan_code']): ?>
                    <span style="float:right;font-size:12px;color:var(--text-muted);">Mã HĐ: <strong><?= sanitize($customer['loan_code']) ?></strong></span>
                <?php endif; ?>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;">
                <div>
                    <span style="color:var(--text-muted);font-size:11px;">Số tiền vay</span><br>
                    <strong style="font-size:15px;color:var(--accent-red);"><?= number_format($customer['loan_amount'], 0, ',', '.') ?>đ</strong>
                </div>
                <?php if ($customer['next_payment_date']): ?>
                <div>
                    <span style="color:var(--text-muted);font-size:11px;">Ngày đóng lãi tiếp</span><br>
                    <strong style="font-size:15px;color:<?= strtotime($customer['next_payment_date']) <= time() ? 'var(--accent-red)' : 'var(--accent-green)' ?>;">
                        <?= date('d/m/Y', strtotime($customer['next_payment_date'])) ?>
                    </strong>
                </div>
                <?php endif; ?>
                <div>
                    <span style="color:var(--text-muted);font-size:11px;">Trạng thái HĐ</span><br>
                    <?php 
                    $loanStatusLabels = ['active' => '🟢 Đang vay', 'closed' => '✅ Đã tất toán', 'bad_debt' => '🔴 Nợ xấu'];
                    ?>
                    <strong style="font-size:13px;"><?= $loanStatusLabels[$customer['loan_status']] ?? $customer['loan_status'] ?></strong>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- LƯU Ý (hiện luôn phía trên THÔNG TIN KHÁCH HÀNG) -->
    <section style="margin-bottom:20px;">
        <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:14px 16px;border-left:3px solid #f59e0b;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                <span style="color:#f59e0b;font-size:12px;font-weight:600;">📌 NỘI DUNG CẦN LƯU Ý</span>
                <button class="btn btn-ghost btn-sm" onclick="togglePinnedEdit()" style="color:var(--accent-blue);font-size:12px;">✏️ Sửa</button>
            </div>
            <div id="pinned-display">
                <?php if ($customer['pinned_note']): ?>
                    <div style="white-space:pre-wrap;font-size:14px;line-height:1.7;color:var(--text-secondary);"><?= sanitize($customer['pinned_note']) ?></div>
                <?php else: ?>
                    <span style="color:var(--text-muted);font-size:13px;font-style:italic;">Chưa có nội dung lưu ý</span>
                <?php endif; ?>
            </div>
            <form method="POST" id="pinned-edit" style="display:none;margin-top:8px;">
                <input type="hidden" name="action" value="update_pinned">
                <textarea name="pinned_note" class="form-textarea" style="margin-bottom:8px;min-height:80px;"><?= sanitize($customer['pinned_note'] ?? '') ?></textarea>
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="togglePinnedEdit()">Hủy</button>
                    <button type="submit" class="btn btn-primary btn-sm">💾 Lưu</button>
                </div>
            </form>
        </div>
    </section>

    <!-- THÔNG TIN KHÁCH HÀNG -->
    <section style="margin-bottom:28px;">
        <form method="POST" id="desc-form">
            <input type="hidden" name="action" value="save_description">
            <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <span style="color:var(--text-muted);font-size:12px;font-weight:600;">📋 THÔNG TIN KHÁCH HÀNG</span>
                    <div>
                        <button type="button" class="btn btn-ghost btn-sm" id="desc-edit-btn" onclick="enableDescEdit()" style="color:var(--accent-blue);">✏️ Sửa</button>
                        <button type="submit" class="btn btn-primary btn-sm" id="desc-save-btn" style="display:none;">💾 Lưu</button>
                        <button type="button" class="btn btn-ghost btn-sm" id="desc-cancel-btn" style="display:none;color:var(--text-muted);" onclick="cancelDescEdit()">Hủy</button>
                    </div>
                </div>
                <!-- Hiển thị chế độ xem -->
                <div id="desc-display" style="font-size:14px;line-height:1.6;white-space:pre-wrap;color:var(--text-secondary);min-height:40px;max-height:200px;overflow-y:auto;padding:8px;"><?= sanitize($customer['description'] ?? '') ?: '<span style="color:var(--text-muted);font-style:italic;">Chưa có mô tả</span>' ?></div>
                <!-- Textarea ẩn -->
                <textarea name="description" class="form-textarea" id="desc-textarea"
                    style="min-height:160px;border:1px solid var(--border-color);padding:8px;resize:vertical;font-size:14px;line-height:1.6;display:none;"
                    placeholder="Nhập mô tả về khách hàng..."><?= sanitize($customer['description'] ?? '') ?></textarea>
            </div>
        </form>
    </section>
    <script>
    var _descOriginal = '';
    function enableDescEdit() {
        _descOriginal = document.getElementById('desc-textarea').value;
        document.getElementById('desc-display').style.display = 'none';
        document.getElementById('desc-textarea').style.display = '';
        document.getElementById('desc-edit-btn').style.display = 'none';
        document.getElementById('desc-save-btn').style.display = '';
        document.getElementById('desc-cancel-btn').style.display = '';
        document.getElementById('desc-textarea').focus();
    }
    function cancelDescEdit() {
        document.getElementById('desc-textarea').value = _descOriginal;
        document.getElementById('desc-display').style.display = '';
        document.getElementById('desc-textarea').style.display = 'none';
        document.getElementById('desc-edit-btn').style.display = '';
        document.getElementById('desc-save-btn').style.display = 'none';
        document.getElementById('desc-cancel-btn').style.display = 'none';
    }
    </script>

    <?php if (!empty($customer['tc_info'])): ?>
    <!-- THÔNG TIN TỪ TÀI CHÍNH (read-only) -->
    <section style="margin-bottom:28px;">
        <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:16px;border-left:3px solid var(--accent-orange);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <span style="color:var(--accent-orange);font-size:12px;font-weight:600;">🔗 THÔNG TIN TỪ TÀI CHÍNH</span>
                <span style="color:var(--text-muted);font-size:11px;">Tự động đồng bộ</span>
            </div>
            <div style="font-size:14px;line-height:1.8;white-space:pre-wrap;color:var(--text-secondary);padding:8px;"><?= sanitize($customer['tc_info']) ?></div>
        </div>
    </section>
    <?php endif; ?>


    <!-- TAB NAVIGATION -->
    <div style="display:flex;gap:4px;margin-bottom:20px;border-bottom:1px solid var(--border-color);padding-bottom:0;">
        <a href="?id=<?= $customerId ?>&tab=comments" class="btn btn-ghost btn-sm" style="border-radius:var(--radius-md) var(--radius-md) 0 0;padding:8px 16px;<?= $tab==='comments' ? 'background:var(--bg-card);color:var(--text-primary);border:1px solid var(--border-color);border-bottom:1px solid var(--bg-primary);margin-bottom:-1px;' : '' ?>">
            💬 Bình luận (<?= count($comments) ?>)
        </a>
        <a href="?id=<?= $customerId ?>&tab=worklog" class="btn btn-ghost btn-sm" style="border-radius:var(--radius-md) var(--radius-md) 0 0;padding:8px 16px;<?= $tab==='worklog' ? 'background:var(--bg-card);color:var(--text-primary);border:1px solid var(--border-color);border-bottom:1px solid var(--bg-primary);margin-bottom:-1px;' : '' ?>">
            📋 Nội dung công việc (<?= count($workLogs) ?>)
        </a>
        <a href="?id=<?= $customerId ?>&tab=transfers" class="btn btn-ghost btn-sm" style="border-radius:var(--radius-md) var(--radius-md) 0 0;padding:8px 16px;<?= $tab==='transfers' ? 'background:var(--bg-card);color:var(--text-primary);border:1px solid var(--border-color);border-bottom:1px solid var(--bg-primary);margin-bottom:-1px;' : '' ?>">
            📜 Chuyển phòng (<?= count($transferLogs) ?>)
        </a>
        <a href="?id=<?= $customerId ?>&tab=files" class="btn btn-ghost btn-sm" style="border-radius:var(--radius-md) var(--radius-md) 0 0;padding:8px 16px;<?= $tab==='files' ? 'background:var(--bg-card);color:var(--text-primary);border:1px solid var(--border-color);border-bottom:1px solid var(--bg-primary);margin-bottom:-1px;' : '' ?>">
            📎 File (<?= count($customerFiles) ?>)
        </a>
        <a href="?id=<?= $customerId ?>&tab=timeline" class="btn btn-ghost btn-sm" style="border-radius:var(--radius-md) var(--radius-md) 0 0;padding:8px 16px;<?= $tab==='timeline' ? 'background:var(--bg-card);color:var(--text-primary);border:1px solid var(--border-color);border-bottom:1px solid var(--bg-primary);margin-bottom:-1px;' : '' ?>">
            📊 Tổng hợp (<?= count($timeline) ?>)
        </a>
    </div>

    <!-- TAB: BÌNH LUẬN -->
    <?php if ($tab === 'comments'): ?>
    <section>
        <?php foreach ($comments as $cm): 
            $initial = mb_substr($cm['fullname'], 0, 1, 'UTF-8');
            $colors = ['#3b82f6','#8b5cf6','#ec4899','#f59e0b','#10b981','#06b6d4'];
            $bgColor = $colors[crc32($cm['fullname']) % count($colors)];
        ?>
        <div style="display:flex;gap:12px;margin-bottom:16px;">
            <div class="user-avatar" style="background:<?= $bgColor ?>;flex-shrink:0;"><?= $initial ?></div>
            <div style="flex:1;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                    <strong style="font-size:14px;"><?= sanitize($cm['fullname']) ?></strong>
                    <span style="font-size:12px;color:var(--text-muted);"><?= date('d/m/Y H:i', strtotime($cm['created_at'])) ?></span>
                </div>
                <div style="font-size:14px;color:var(--text-secondary);line-height:1.7;white-space:pre-wrap;"><?= sanitize($cm['content']) ?></div>
            </div>
        </div>
        <?php endforeach; ?>

        <form method="POST" style="display:flex;gap:8px;align-items:flex-start;">
            <input type="hidden" name="action" value="add_comment">
            <div class="user-avatar" style="flex-shrink:0;margin-top:2px;"><?= mb_substr($user['fullname'], 0, 1, 'UTF-8') ?></div>
            <textarea name="content" class="form-textarea" placeholder="Thêm bình luận..." style="min-height:60px;flex:1;" required></textarea>
            <button type="submit" class="btn btn-primary" style="margin-top:2px;">Gửi</button>
        </form>
    </section>
    <?php endif; ?>

    <!-- TAB: NHẬT KÝ LÀM VIỆC -->
    <?php if ($tab === 'worklog'): ?>
    <section>
        <div style="display:flex;align-items:center;justify-content:flex-end;gap:8px;margin-bottom:16px;">
            <a href="?id=<?= $customerId ?>&tab=worklog&export=xlsx" class="btn btn-secondary btn-sm">📥 Xuất Excel</a>
            <button class="btn btn-primary btn-sm" onclick="openModal('worklog-modal')">➕ Thêm</button>
        </div>

        <?php if (empty($workLogs)): ?>
            <div class="empty-state" style="padding:24px;">
                <div class="empty-state-text">Chưa có nhật ký làm việc</div>
            </div>
        <?php else: ?>
            <table class="data-table" style="font-size:12px;">
                <thead>
                    <tr>
                        <th>📅 Ngày</th>
                        <th>🧑 Họ tên khách</th>
                        <th>👤 Nhân viên</th>
                        <th>🏢 Phòng làm việc</th>
                        <th>🎯 Việc đã làm</th>
                        <th>📊 Kết quả</th>
                        <th>📝 Ghi chú</th>
                        <th>💰 Lãi đã trả</th>
                        <th>💰 Gốc đã trả</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($workLogs as $wl): ?>
                    <tr>
                        <td style="white-space:nowrap"><?= date('d/m/Y', strtotime($wl['log_date'])) ?></td>
                        <td style="font-weight:600;"><?= sanitize($customer['name']) ?></td>
                        <td>
                            <span class="tag" style="--tag-bg:rgba(59,130,246,0.15);--tag-color:#3b82f6;">
                                <?= sanitize($wl['user_name']) ?>
                            </span>
                        </td>
                        <td style="white-space:nowrap;">
                            <?php if ($wl['room_name']): ?>
                                <span class="tag" style="--tag-bg:rgba(16,185,129,0.15);--tag-color:#10b981;">
                                    <?= sanitize($wl['room_name']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:var(--text-muted)">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($wl['action_type']): ?>
                                <span class="tag" style="--tag-bg:rgba(34,197,94,0.15);--tag-color:#22c55e;">
                                    <?= sanitize($wl['action_type']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:var(--text-muted)">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($wl['result_type']): 
                                // Mỗi loại kết quả 1 màu riêng
                                $rtColors = [
                                    'Tất máy thuê bao' => ['rgba(107,114,128,0.2)','#9ca3af'],
                                    'Không phản hồi'   => ['rgba(239,68,68,0.15)','#ef4444'],
                                    'Hẹn trả lãi'      => ['rgba(234,179,8,0.15)','#eab308'],
                                    'Hẹn trả gốc'      => ['rgba(249,115,22,0.15)','#f97316'],
                                    'Đã liên hệ được'  => ['rgba(34,197,94,0.15)','#22c55e'],
                                    'Cam kết trả'       => ['rgba(59,130,246,0.15)','#3b82f6'],
                                    'Kết quả khác'      => ['rgba(168,85,247,0.15)','#a855f7'],
                                ];
                                $rtKey = $wl['result_type'];
                                $rtBg = $rtColors[$rtKey][0] ?? 'rgba(245,166,35,0.15)';
                                $rtFg = $rtColors[$rtKey][1] ?? '#f5a623';
                            ?>
                                <span class="tag" style="--tag-bg:<?= $rtBg ?>;--tag-color:<?= $rtFg ?>;">
                                    <?= sanitize($wl['result_type']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:var(--text-muted)">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="max-width:250px;"><?= sanitize($wl['work_done']) ?></td>
                        <td>
                            <?php if ($wl['amount']): ?>
                                <strong style="color:var(--accent-green)"><?= number_format($wl['amount'], 0, ',', '.') ?>₫</strong>
                            <?php else: ?>
                                <span style="color:var(--text-muted)">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($wl['amount_principal'])): ?>
                                <strong style="color:var(--accent-blue)"><?= number_format($wl['amount_principal'], 0, ',', '.') ?>₫</strong>
                            <?php else: ?>
                                <span style="color:var(--text-muted)">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- TAB: LỊCH SỬ CHUYỂN PHÒNG -->
    <?php if ($tab === 'transfers'): ?>
    <section>
        <div style="display:flex;align-items:center;justify-content:flex-end;gap:8px;margin-bottom:16px;">
            <a href="?id=<?= $customerId ?>&tab=transfers&export=xlsx" class="btn btn-secondary btn-sm">📥 Xuất Excel</a>
        </div>
        <?php if (empty($transferLogs)): ?>
            <div class="empty-state" style="padding:24px;">
                <div class="empty-state-text">Chưa có lịch sử chuyển phòng</div>
            </div>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>📅 Thời gian</th>
                        <th>🧑 Họ tên khách</th>
                        <th>Từ phòng</th>
                        <th></th>
                        <th>Đến phòng</th>
                        <th>👤 Người chuyển</th>
                        <th>📝 Ghi chú</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transferLogs as $tl): ?>
                    <tr>
                        <td style="white-space:nowrap"><?= date('d/m/Y H:i', strtotime($tl['transferred_at'])) ?></td>
                        <td style="font-weight:600;"><?= sanitize($customer['name']) ?></td>
                        <td>
                            <span class="tag" style="--tag-bg:rgba(235,87,87,0.15);--tag-color:#eb5757;">
                                <?= $tl['from_room_icon'] ?> <?= sanitize($tl['from_room_name']) ?>
                            </span>
                        </td>
                        <td style="text-align:center;color:var(--text-muted);">→</td>
                        <td>
                            <span class="tag" style="--tag-bg:rgba(76,175,80,0.15);--tag-color:#4caf50;">
                                <?= $tl['to_room_icon'] ?> <?= sanitize($tl['to_room_name']) ?>
                            </span>
                        </td>
                        <td><?= sanitize($tl['transferred_by_name'] ?? '') ?></td>
                        <td style="max-width:200px;font-size:13px;color:var(--text-secondary);"><?= sanitize($tl['note'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- TAB LƯU Ý đã được chuyển lên trên tabs -->

    <!-- TAB: FILE ĐÍNH KÈM -->
    <?php if ($tab === 'files'): ?>
    <section>
        <!-- Upload form -->
        <form method="POST" enctype="multipart/form-data" style="margin-bottom:20px;">
            <input type="hidden" name="action" value="upload_file">
            <div style="background:var(--bg-card);border:2px dashed var(--border-color);border-radius:var(--radius-lg);padding:24px;text-align:center;cursor:pointer;transition:all 0.2s;" 
                 id="file-drop-zone"
                 onclick="document.getElementById('file-input').click()"
                 ondragover="event.preventDefault();this.style.borderColor='var(--accent-blue)';this.style.background='rgba(35,131,226,0.05)'"
                 ondragleave="this.style.borderColor='var(--border-color)';this.style.background='var(--bg-card)'"
                 ondrop="event.preventDefault();this.style.borderColor='var(--border-color)';document.getElementById('file-input').files=event.dataTransfer.files;document.getElementById('file-name-display').textContent=event.dataTransfer.files[0].name;document.getElementById('file-submit-btn').style.display=''">
                <div style="font-size:32px;margin-bottom:8px;">📎</div>
                <div style="color:var(--text-secondary);font-size:14px;">Kéo thả file vào đây hoặc <span style="color:var(--accent-blue);text-decoration:underline;">chọn file</span></div>
                <div style="color:var(--text-muted);font-size:12px;margin-top:4px;">Hỗ trợ: PDF, hình ảnh, Word, Excel...</div>
                <div id="file-name-display" style="color:var(--accent-blue);font-size:13px;margin-top:8px;font-weight:600;"></div>
            </div>
            <input type="file" id="file-input" name="file" style="display:none" onchange="document.getElementById('file-name-display').textContent=this.files[0]?.name||'';document.getElementById('file-submit-btn').style.display=this.files[0]?'':'none'">
            <div style="text-align:right;margin-top:8px;">
                <button type="submit" class="btn btn-primary btn-sm" id="file-submit-btn" style="display:none;">⬆️ Upload lên Drive</button>
            </div>
        </form>

        <!-- File list -->
        <?php if (empty($customerFiles)): ?>
            <div class="empty-state" style="padding:24px;">
                <div class="empty-state-text">Chưa có file đính kèm</div>
            </div>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>📄 Tên file</th>
                        <th>📊 Dung lượng</th>
                        <th>👤 Người upload</th>
                        <th>📅 Ngày</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customerFiles as $cf): ?>
                    <tr>
                        <td>
                            <a href="<?= sanitize($cf['drive_link']) ?>" target="_blank" style="color:var(--accent-blue);text-decoration:none;font-weight:500;">
                                <?php
                                    $ext = strtolower(pathinfo($cf['file_name'], PATHINFO_EXTENSION));
                                    $icons = ['pdf'=>'📕','doc'=>'📘','docx'=>'📘','xls'=>'📗','xlsx'=>'📗','jpg'=>'🖼️','jpeg'=>'🖼️','png'=>'🖼️','gif'=>'🖼️'];
                                    echo ($icons[$ext] ?? '📄') . ' ';
                                ?>
                                <?= sanitize($cf['file_name']) ?>
                            </a>
                        </td>
                        <td style="white-space:nowrap;font-size:13px;color:var(--text-muted);">
                            <?php
                                $size = $cf['file_size'] ?? 0;
                                if ($size >= 1048576) echo round($size / 1048576, 1) . ' MB';
                                elseif ($size >= 1024) echo round($size / 1024, 1) . ' KB';
                                else echo $size . ' B';
                            ?>
                        </td>
                        <td style="font-size:13px;"><?= sanitize($cf['uploader'] ?? '') ?></td>
                        <td style="white-space:nowrap;font-size:13px;"><?= date('d/m/Y', strtotime($cf['created_at'])) ?></td>
                        <td style="text-align:right;">
                            <a href="<?= sanitize($cf['drive_link']) ?>" target="_blank" class="btn btn-ghost btn-sm" title="Xem">👁️</a>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Xóa file này?')">
                                <input type="hidden" name="action" value="delete_file">
                                <input type="hidden" name="file_id" value="<?= $cf['id'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--status-danger);" title="Xóa">🗑️</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- TAB: TỔNG HỢP -->
    <?php if ($tab === 'timeline'): ?>
    <section>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <span style="color:var(--text-muted);font-size:13px;">Tổng: <?= count($timeline) ?> mục</span>
            <a href="?id=<?= $customerId ?>&tab=timeline&export=xlsx" class="btn btn-secondary btn-sm">📥 Xuất Excel</a>
        </div>
        <?php if (empty($timeline)): ?>
            <div class="empty-state" style="padding:24px;">
                <div class="empty-state-text">Chưa có dữ liệu</div>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Thời gian</th>
                        <th>Họ tên khách</th>
                        <th>Loại thông tin</th>
                        <th>Nội dung tổng hợp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $typeColors = [
                        'worklog' => ['bg' => 'rgba(59,130,246,0.1)', 'color' => '#3b82f6'],
                        'transfer' => ['bg' => 'rgba(245,158,11,0.1)', 'color' => '#f59e0b'],
                        'transaction' => ['bg' => 'rgba(16,185,129,0.1)', 'color' => '#10b981'],
                    ];
                    foreach ($timeline as $item): 
                        $tc = $typeColors[$item['type_key']] ?? ['bg' => 'transparent', 'color' => 'inherit'];
                    ?>
                    <tr>
                        <td style="white-space:nowrap;font-size:13px;"><?= date('d/m/Y', strtotime($item['date'])) ?></td>
                        <td style="font-size:13px;"><?= sanitize($customer['name']) ?></td>
                        <td>
                            <span style="font-size:12px;padding:2px 8px;border-radius:var(--radius-sm);background:<?= $tc['bg'] ?>;color:<?= $tc['color'] ?>;">
                                <?= $item['type'] ?>
                            </span>
                        </td>
                        <td style="font-size:13px;line-height:1.5;max-width:400px;"><?= sanitize($item['summary']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<!-- MODAL: Sửa thông tin -->
<div class="modal-overlay" id="edit-modal">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <h3 class="modal-title">✏️ Sửa thông tin khách hàng</h3>
            <button class="modal-close" onclick="closeModal('edit-modal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_info">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Họ và tên *</label>
                    <input type="text" name="name" class="form-input" value="<?= sanitize($customer['name']) ?>" required>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div class="form-group">
                        <label class="form-label">📱 SĐT</label>
                        <input type="text" name="phone" class="form-input" value="<?= sanitize($customer['phone'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">🪪 CCCD</label>
                        <input type="text" name="cccd" class="form-input" value="<?= sanitize($customer['cccd'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">📍 Địa chỉ</label>
                    <input type="text" name="address" class="form-input" value="<?= sanitize($customer['address'] ?? '') ?>">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div class="form-group">
                        <label class="form-label">📘 Facebook Link</label>
                        <input type="url" name="facebook_link" class="form-input" value="<?= sanitize($customer['facebook_link'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">🏢 Thuộc công ty</label>
                        <input type="text" name="company_tag" class="form-input" value="<?= sanitize($customer['company_tag'] ?? '') ?>">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div class="form-group">
                        <label class="form-label">🏭 Đơn vị công tác</label>
                        <input type="text" name="workplace" class="form-input" value="<?= sanitize($customer['workplace'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">🏠 HKTT</label>
                        <input type="text" name="hktt" class="form-input" value="<?= sanitize($customer['hktt'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">👨‍👩‍👧 Thông tin người thân</label>
                    <textarea name="relatives_info" class="form-textarea" style="min-height:60px;"><?= sanitize($customer['relatives_info'] ?? '') ?></textarea>
                </div>
                <hr style="border:none;border-top:1px solid var(--border-color);margin:8px 0 16px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div class="form-group">
                        <label class="form-label">Ngày hết hạn</label>
                        <input type="date" name="due_date" class="form-input" value="<?= $customer['due_date'] ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nhân viên phụ trách</label>
                        <select name="assigned_to" class="form-select">
                            <option value="">-- Chọn nhân viên --</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['id'] ?>" <?= $emp['id'] == $customer['assigned_to'] ? 'selected' : '' ?>><?= sanitize($emp['fullname']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">🔮 Dự kiến chuyển đến phòng</label>
                    <select name="planned_next_room_id" class="form-select">
                        <option value="">-- Chưa xác định --</option>
                        <?php foreach ($rooms as $r): ?>
                            <option value="<?= $r['id'] ?>" <?= $r['id'] == $customer['planned_next_room_id'] ? 'selected' : '' ?>>
                                <?= $r['icon'] ?> <?= sanitize($r['name']) ?>
                                <?= $r['sla_days'] > 0 ? '(Hạn: '.$r['sla_days'].' ngày)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Ghi chú</label>
                    <textarea name="notes" class="form-textarea"><?= sanitize($customer['notes'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('edit-modal')">Hủy</button>
                <button type="submit" class="btn btn-primary">💾 Lưu thay đổi</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Chuyển phòng -->
<div class="modal-overlay" id="change-room-modal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">🔄 Chuyển qua phòng khác</h3>
            <button class="modal-close" onclick="closeModal('change-room-modal')">&times;</button>
        </div>
        <form method="POST" id="transfer-form" onsubmit="return handleTransferSubmit()">
            <input type="hidden" name="action" value="change_room">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Phòng hiện tại</label>
                    <div style="padding:10px 14px;background:var(--bg-elevated);border-radius:var(--radius-md);font-size:14px;">
                        <?= $customer['room_icon'] ?> <?= sanitize($customer['room_name']) ?>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Chọn phòng mới *</label>
                    <select name="room_id" class="form-select" required>
                        <?php foreach ($rooms as $r): ?>
                            <?php if ($r['id'] != $customer['room_id']): ?>
                            <option value="<?= $r['id'] ?>">
                                <?= $r['icon'] ?> <?= sanitize($r['name']) ?>
                                <?= $r['sla_days'] > 0 ? '(Hạn: '.$r['sla_days'].' ngày)' : '' ?>
                            </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Ghi chú chuyển phòng</label>
                    <textarea name="transfer_note" class="form-textarea" style="min-height:60px;" placeholder="Lý do chuyển phòng..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('change-room-modal')">Hủy</button>
                <button type="submit" class="btn btn-primary" id="transfer-btn">🔄 Chuyển phòng</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Thêm nhật ký (3 cấp cascade) -->
<?php
// Load worklog_config cho TẤT CẢ phòng (không chỉ phòng hiện tại)
$allRoomConfigs = [];
$cfgStmt = $pdo->query("SELECT id, worklog_config FROM cv_rooms WHERE is_archive = 0");
while ($row = $cfgStmt->fetch()) {
    $allRoomConfigs[$row['id']] = json_decode($row['worklog_config'] ?: '[]', true) ?: [];
}
$currentRoomConfig = $allRoomConfigs[$customer['room_id']] ?? [];
?>
<div class="modal-overlay" id="worklog-modal">
    <div class="modal" style="max-width:560px;">
        <div class="modal-header">
            <h3 class="modal-title">📝 Lưu nhật ký làm việc</h3>
            <button class="modal-close" onclick="closeModal('worklog-modal')">&times;</button>
        </div>
        <form method="POST" id="wl-modal-form" onsubmit="return wlBeforeSubmit()">
            <input type="hidden" name="action" value="add_worklog">
            <input type="hidden" name="log_date" value="<?= date('Y-m-d') ?>">
            <input type="hidden" name="action_type" id="wl-hid-action" value="">
            <input type="hidden" name="result_type" id="wl-hid-result" value="">
            <input type="hidden" name="custom_detail" id="wl-hid-custom" value="">
            <div class="modal-body">
                <!-- PHÒNG LÀM VIỆC -->
                <div class="form-group">
                    <label class="form-label">🏢 Phòng làm việc</label>
                    <select name="log_room_id" class="form-input" style="font-size:13px;" id="wl-room-select" onchange="wlSwitchRoom(this.value)">
                        <?php foreach ($rooms as $rm): ?>
                            <?php if (!empty($rm['is_archive'])) continue; ?>
                            <option value="<?= $rm['id'] ?>" <?= $rm['id'] == $customer['room_id'] ? 'selected' : '' ?>>
                                <?= $rm['icon'] ?> <?= sanitize($rm['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- HÀNH ĐỘNG -->
                <div class="form-group">
                    <label class="form-label">🎯 Đã làm việc gì <span style="color:#eb5757">*</span></label>
                    <div id="wl-action-area">
                    <?php if (empty($currentRoomConfig)): ?>
                        <p style="color:var(--text-muted);font-size:13px;">⚠️ Phòng chưa cấu hình.
                        <a href="room_config.php?id=<?= $customer['room_id'] ?>" style="color:var(--accent-blue);">Cấu hình →</a></p>
                    <?php else: ?>
                        <div class="wl-modal-tags" id="wl-action-tags">
                            <?php foreach ($currentRoomConfig as $i => $ac): ?>
                            <button type="button" class="wl-mtag" data-idx="<?= $i ?>" data-value="<?= sanitize($ac['action']) ?>" onclick="wlSelectAction(this, <?= $i ?>)">
                                <span class="wl-mtag-letter"><?= chr(65 + $i % 26) ?></span>
                                <?= sanitize($ac['action']) ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
                <!-- Ô NHẬP CHI TIẾT (nếu action có show_custom_input) -->
                <div class="form-group" id="wl-custom-input-group" style="display:none;">
                    <label class="form-label" id="wl-custom-input-label" style="color:var(--accent-orange);font-weight:600;"></label>
                    <input type="text" id="wl-custom-input" class="form-input" placeholder="Nhập chi tiết...">
                </div>
                <!-- KẾT QUẢ -->
                <div class="form-group" id="wl-result-group" style="display:none;">
                    <label class="form-label">📊 Kết quả <span style="color:#eb5757">*</span></label>
                    <div class="wl-modal-tags" id="wl-result-tags"></div>
                </div>
                <!-- NGÀY HẸN -->
                <div class="form-group" id="wl-date-group" style="display:none;">
                    <label class="form-label">📅 Ngày hẹn</label>
                    <input type="date" name="promise_date" class="form-input">
                </div>
                <!-- SỐ TIỀN -->
                <div class="form-group" id="wl-amount-group" style="display:none;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div>
                            <label class="form-label">💰 Số tiền lãi đã trả</label>
                            <input type="text" name="amount_interest" class="form-input" placeholder="VD: 500000">
                        </div>
                        <div>
                            <label class="form-label">💰 Số tiền gốc đã trả</label>
                            <input type="text" name="amount_principal" class="form-input" placeholder="VD: 5000000">
                        </div>
                    </div>
                </div>
                <!-- GHI CHÚ -->
                <div class="form-group" id="wl-notes-group" style="display:none;">
                    <label class="form-label">Ghi chú thêm</label>
                    <textarea name="work_done" class="form-textarea" style="min-height:60px;" placeholder="Mô tả thêm..."></textarea>
                </div>
            </div>
            <div class="modal-footer" id="wl-footer" style="display:none;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('worklog-modal')">Hủy</button>
                <button type="submit" class="btn btn-primary">💾 Lưu nhật ký</button>
            </div>
        </form>
    </div>
</div>

<style>
.wl-modal-tags { display: flex; flex-direction: column; gap: 6px; }
.wl-mtag {
    display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px;
    background: var(--bg-primary); border: 1.5px solid var(--border-color); border-radius: var(--radius-md);
    color: var(--text-primary); font-size: 13px; cursor: pointer; transition: all 0.15s;
    text-align: left; width: fit-content;
}
.wl-mtag:hover { border-color: var(--accent-blue); background: rgba(35,131,226,0.08); }
.wl-mtag.selected { border-color: var(--accent-blue); background: rgba(35,131,226,0.15); box-shadow: 0 0 0 2px rgba(35,131,226,0.3); }
.wl-mtag-letter {
    display: inline-flex; align-items: center; justify-content: center;
    width: 20px; height: 20px; border-radius: 3px; font-size: 11px; font-weight: 700;
    background: var(--text-muted); color: var(--bg-primary); flex-shrink: 0;
}
</style>

<script>
// Tất cả config phòng
const allRoomConfigs = <?= json_encode($allRoomConfigs, JSON_UNESCAPED_UNICODE) ?>;
let wlRoomConfig = allRoomConfigs[<?= intval($customer['room_id']) ?>] || [];
const WL_LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

// Khi đổi phòng → rebuild action tags
function wlSwitchRoom(roomId) {
    wlRoomConfig = allRoomConfigs[roomId] || [];
    // Reset hidden fields
    document.getElementById('wl-hid-action').value = '';
    document.getElementById('wl-hid-result').value = '';
    document.getElementById('wl-hid-custom').value = '';
    document.getElementById('wl-result-group').style.display = 'none';
    document.getElementById('wl-result-tags').innerHTML = '';
    document.getElementById('wl-custom-input-group').style.display = 'none';
    document.getElementById('wl-custom-input').value = '';
    document.getElementById('wl-date-group').style.display = 'none';
    document.getElementById('wl-amount-group').style.display = 'none';
    document.getElementById('wl-notes-group').style.display = 'none';
    document.getElementById('wl-footer').style.display = 'none';

    // Rebuild action tags
    const area = document.getElementById('wl-action-area');
    if (!wlRoomConfig.length) {
        area.innerHTML = '<p style="color:var(--text-muted);font-size:13px;">⚠️ Phòng chưa cấu hình nhật ký.</p>';
        return;
    }
    let html = '<div class="wl-modal-tags" id="wl-action-tags">';
    wlRoomConfig.forEach(function(ac, i) {
        const safe = ac.action.replace(/</g,'&lt;').replace(/>/g,'&gt;');
        html += '<button type="button" class="wl-mtag" data-idx="' + i + '" data-value="' + safe + '" onclick="wlSelectAction(this, ' + i + ')">';
        html += '<span class="wl-mtag-letter">' + WL_LETTERS[i % 26] + '</span> ' + safe;
        html += '</button>';
    });
    html += '</div>';
    area.innerHTML = html;
}

function wlSelectAction(btn, actionIdx) {
    document.querySelectorAll('#wl-action-tags .wl-mtag').forEach(t => t.classList.remove('selected'));
    btn.classList.add('selected');
    document.getElementById('wl-hid-action').value = btn.dataset.value;
    document.getElementById('wl-hid-result').value = '';
    document.getElementById('wl-date-group').style.display = 'none';
    document.getElementById('wl-amount-group').style.display = 'none';
    document.getElementById('wl-notes-group').style.display = 'none';
    document.getElementById('wl-footer').style.display = 'none';

    // Custom input (ô nhập chi tiết)
    const actionCfg = wlRoomConfig[actionIdx] || {};
    const customGroup = document.getElementById('wl-custom-input-group');
    const customInput = document.getElementById('wl-custom-input');
    customInput.value = '';
    if (actionCfg.show_custom_input) {
        document.getElementById('wl-custom-input-label').textContent = actionCfg.custom_input_label || 'Nhập chi tiết';
        customGroup.style.display = '';
        setTimeout(function() { customInput.focus(); }, 100);
    } else {
        customGroup.style.display = 'none';
    }

    const results = wlRoomConfig[actionIdx]?.results || [];
    const container = document.getElementById('wl-result-tags');
    container.innerHTML = '';

    if (results.length === 0) {
        // No results → skip, show notes + submit directly
        document.getElementById('wl-result-group').style.display = 'none';
        document.getElementById('wl-notes-group').style.display = '';
        document.getElementById('wl-footer').style.display = '';
    } else {
        results.forEach((r, i) => {
            const label = typeof r === 'object' ? r.label : r;
            const showDate = typeof r === 'object' ? (r.show_date || false) : false;
            const showAmount = typeof r === 'object' ? (r.show_amount || false) : false;
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'wl-mtag';
            b.dataset.value = label;
            b.dataset.showDate = showDate;
            b.dataset.showAmount = showAmount;
            const safe = label.replace(/</g,'&lt;').replace(/>/g,'&gt;');
            b.innerHTML = '<span class="wl-mtag-letter">' + WL_LETTERS[i%26] + '</span> ' + safe;
            b.onclick = function() { wlSelectResult(b); };
            container.appendChild(b);
        });
        document.getElementById('wl-result-group').style.display = '';
    }
}

function wlSelectResult(btn) {
    document.querySelectorAll('#wl-result-tags .wl-mtag').forEach(t => t.classList.remove('selected'));
    btn.classList.add('selected');
    document.getElementById('wl-hid-result').value = btn.dataset.value;
    document.getElementById('wl-date-group').style.display = btn.dataset.showDate === 'true' ? '' : 'none';
    document.getElementById('wl-amount-group').style.display = btn.dataset.showAmount === 'true' ? '' : 'none';
    document.getElementById('wl-notes-group').style.display = '';
    document.getElementById('wl-footer').style.display = '';
}

function wlBeforeSubmit() {
    var ci = document.getElementById('wl-custom-input').value.trim();
    document.getElementById('wl-hid-custom').value = ci;
    return true;
}
</script>

<script>
function togglePinnedEdit() {
    const display = document.getElementById('pinned-display');
    const edit = document.getElementById('pinned-edit');
    if (edit.style.display === 'none') {
        display.style.display = 'none';
        edit.style.display = 'block';
    } else {
        display.style.display = 'block';
        edit.style.display = 'none';
    }
}

// Chống double-click khi chuyển phòng
function handleTransferSubmit() {
    const btn = document.getElementById('transfer-btn');
    btn.disabled = true;
    btn.textContent = '⏳ Đang chuyển...';
    return true;
}
</script>

<?php include 'layout_bottom.php'; ?>
