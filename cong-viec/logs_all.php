<?php
require_once 'config.php';
requireLogin();

$user = cvGetUser();
$pageTitle = 'Nhật ký tổng hợp';
$activePage = 'logs_all';

// Filters
$filterType = $_GET['type'] ?? 'all'; // all, worklog, transfer
$filterDate = $_GET['date'] ?? date('Y-m-d');
$filterDateTo = $_GET['date_to'] ?? $filterDate;
$filterRoom = intval($_GET['room'] ?? 0);
$filterUser = intval($_GET['user_id'] ?? 0);

// Lấy danh sách phòng + nhân viên cho filter
$rooms = $pdo->query("SELECT id, name, icon FROM cv_rooms WHERE is_archive = 0 ORDER BY sort_order, id")->fetchAll();
$employees = $pdo->query("SELECT id, fullname FROM users WHERE cv_role IS NOT NULL ORDER BY fullname")->fetchAll();

// === NHẬT KÝ LÀM VIỆC ===
$workLogs = [];
if ($filterType === 'all' || $filterType === 'worklog') {
    $sql = "SELECT wl.*, u.fullname as user_name, r.name as room_name, r.icon as room_icon,
            c.name as customer_name, c.phone as customer_phone, l.loan_code
        FROM cv_work_logs wl
        JOIN users u ON wl.user_id = u.id
        LEFT JOIN cv_rooms r ON wl.room_id = r.id
        LEFT JOIN loans l ON wl.loan_id = l.id
        LEFT JOIN customers c ON l.customer_id = c.id
        WHERE wl.log_date BETWEEN ? AND ?";
    $params = [$filterDate, $filterDateTo];
    
    if ($filterRoom) { $sql .= " AND wl.room_id = ?"; $params[] = $filterRoom; }
    if ($filterUser) { $sql .= " AND wl.user_id = ?"; $params[] = $filterUser; }
    
    $sql .= " ORDER BY wl.log_date DESC, wl.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $workLogs = $stmt->fetchAll();
}

// === NHẬT KÝ CHUYỂN PHÒNG ===
$transferLogs = [];
if ($filterType === 'all' || $filterType === 'transfer') {
    $sql = "SELECT tl.*, 
        fr.name as from_room_name, fr.icon as from_room_icon,
        tr.name as to_room_name, tr.icon as to_room_icon,
        u.fullname as transferred_by_name,
        c.name as customer_name, c.phone as customer_phone, l.loan_code
        FROM cv_transfer_logs tl
        LEFT JOIN cv_rooms fr ON tl.from_room_id = fr.id
        LEFT JOIN cv_rooms tr ON tl.to_room_id = tr.id
        LEFT JOIN users u ON tl.transferred_by = u.id
        LEFT JOIN loans l ON tl.loan_id = l.id
        LEFT JOIN customers c ON l.customer_id = c.id
        WHERE DATE(tl.transferred_at) BETWEEN ? AND ?";
    $params = [$filterDate, $filterDateTo];
    
    if ($filterRoom) { $sql .= " AND (tl.from_room_id = ? OR tl.to_room_id = ?)"; $params[] = $filterRoom; $params[] = $filterRoom; }
    if ($filterUser) { $sql .= " AND tl.transferred_by = ?"; $params[] = $filterUser; }
    
    $sql .= " ORDER BY tl.transferred_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transferLogs = $stmt->fetchAll();
}

// XLSX export
if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    require_once __DIR__ . '/../SimpleXLSXGen.php';
    $exportType = $_GET['export_type'] ?? 'all';
    $dateSuffix = $filterDate . '_' . $filterDateTo;
    
    if ($exportType === 'worklog' || $exportType === 'all') {
        $rows = [['Ngày', 'Họ tên khách', 'Phòng', 'Nhân viên', 'Việc đã làm', 'Kết quả', 'Ghi chú', 'Tiền lãi', 'Tiền gốc']];
        foreach ($workLogs as $wl) {
            $rows[] = [
                date('d/m/Y', strtotime($wl['log_date'])),
                $wl['customer_name'] ?? '',
                $wl['room_name'] ?? '',
                $wl['user_name'] ?? '',
                $wl['action_type'] ?? $wl['work_done'] ?? '',
                $wl['result_type'] ?? '',
                $wl['work_done'] ?? '',
                !empty($wl['amount']) ? number_format($wl['amount'], 0, ',', '.') : '',
                !empty($wl['amount_principal']) ? number_format($wl['amount_principal'], 0, ',', '.') : ''
            ];
        }
        SimpleXLSXGen::fromArray($rows)->downloadAs('nhatky_tong_' . $dateSuffix . '.xlsx');
    }
    
    if ($exportType === 'transfer') {
        $rows = [['Thời gian', 'Họ tên khách', 'Từ phòng', 'Đến phòng', 'Người chuyển', 'Ghi chú']];
        foreach ($transferLogs as $tl) {
            $rows[] = [
                date('d/m/Y H:i', strtotime($tl['transferred_at'])),
                $tl['customer_name'] ?? '',
                $tl['from_room_name'] ?? '',
                $tl['to_room_name'] ?? '',
                $tl['transferred_by_name'] ?? '',
                $tl['note'] ?? ''
            ];
        }
        SimpleXLSXGen::fromArray($rows)->downloadAs('chuyenphong_tong_' . $dateSuffix . '.xlsx');
    }
}

include 'layout_top.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">📋 Nhật ký tổng hợp</h1>
        <p class="page-subtitle">
            <?= count($workLogs) ?> nhật ký làm việc · <?= count($transferLogs) ?> chuyển phòng
        </p>
    </div>
</div>

<div class="page-body">
    <!-- FILTER -->
    <div style="background:var(--bg-card);border-radius:var(--radius-lg);padding:16px;margin-bottom:20px;border:1px solid var(--border-color);">
        <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;">
            <div class="form-group" style="margin:0;min-width:120px;">
                <label class="form-label" style="font-size:12px;margin-bottom:4px;">Loại</label>
                <select name="type" class="form-select" style="padding:6px 8px;font-size:13px;">
                    <option value="all" <?= $filterType==='all'?'selected':'' ?>>Tất cả</option>
                    <option value="worklog" <?= $filterType==='worklog'?'selected':'' ?>>Nhật ký LV</option>
                    <option value="transfer" <?= $filterType==='transfer'?'selected':'' ?>>Chuyển phòng</option>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:12px;margin-bottom:4px;">Từ ngày</label>
                <input type="date" name="date" value="<?= $filterDate ?>" class="form-input" style="padding:6px 8px;font-size:13px;">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:12px;margin-bottom:4px;">Đến ngày</label>
                <input type="date" name="date_to" value="<?= $filterDateTo ?>" class="form-input" style="padding:6px 8px;font-size:13px;">
            </div>
            <div class="form-group" style="margin:0;min-width:140px;">
                <label class="form-label" style="font-size:12px;margin-bottom:4px;">Phòng</label>
                <select name="room" class="form-select" style="padding:6px 8px;font-size:13px;">
                    <option value="0">-- Tất cả --</option>
                    <?php foreach ($rooms as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= $filterRoom==$r['id']?'selected':'' ?>><?= $r['icon'] ?> <?= sanitize($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;min-width:140px;">
                <label class="form-label" style="font-size:12px;margin-bottom:4px;">Nhân viên</label>
                <select name="user_id" class="form-select" style="padding:6px 8px;font-size:13px;">
                    <option value="0">-- Tất cả --</option>
                    <?php foreach ($employees as $e): ?>
                        <option value="<?= $e['id'] ?>" <?= $filterUser==$e['id']?'selected':'' ?>><?= sanitize($e['fullname']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">🔍 Lọc</button>
        </form>
    </div>

    <!-- NHẬT KÝ LÀM VIỆC -->
    <?php if ($filterType === 'all' || $filterType === 'worklog'): ?>
    <div style="margin-bottom:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <h3 style="color:var(--text-primary);font-size:16px;margin:0;">📝 Nhật ký làm việc (<?= count($workLogs) ?>)</h3>
            <?php if (!empty($workLogs)): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'xlsx', 'export_type' => 'worklog'])) ?>" class="btn btn-secondary btn-sm">📥 Xuất Excel</a>
            <?php endif; ?>
        </div>
        <?php if (empty($workLogs)): ?>
            <div style="text-align:center;padding:24px;color:var(--text-muted);background:var(--bg-card);border-radius:var(--radius-lg);border:1px solid var(--border-color);">
                Không có nhật ký nào
            </div>
        <?php else: ?>
            <div style="background:var(--bg-card);border-radius:var(--radius-lg);border:1px solid var(--border-color);overflow:hidden;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="background:var(--bg-primary);border-bottom:1px solid var(--border-color);">
                            <th style="padding:10px 12px;text-align:left;color:var(--text-secondary);font-weight:600;">Ngày</th>
                            <th style="padding:10px 12px;text-align:left;color:var(--text-secondary);font-weight:600;">Khách hàng</th>
                            <th style="padding:10px 12px;text-align:left;color:var(--text-secondary);font-weight:600;">Phòng</th>
                            <th style="padding:10px 12px;text-align:left;color:var(--text-secondary);font-weight:600;">Nhân viên</th>
                            <th style="padding:10px 12px;text-align:left;color:var(--text-secondary);font-weight:600;">Nội dung</th>
                            <th style="padding:10px 12px;text-align:left;color:var(--text-secondary);font-weight:600;">Kết quả</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($workLogs as $wl): ?>
                        <tr style="border-bottom:1px solid var(--border-color);" onmouseenter="this.style.background='var(--bg-card-hover)'" onmouseleave="this.style.background=''">
                            <td style="padding:10px 12px;white-space:nowrap;"><?= date('d/m/Y', strtotime($wl['log_date'])) ?></td>
                            <td style="padding:10px 12px;">
                                <a href="/cong-viec/khach-hang/<?= $wl['loan_id'] ?>" style="color:var(--accent-blue);text-decoration:none;font-weight:600;">
                                    <?= sanitize($wl['customer_name'] ?? '—') ?>
                                </a>
                            </td>
                            <td style="padding:10px 12px;"><?= $wl['room_icon'] ?? '' ?> <?= sanitize($wl['room_name'] ?? '') ?></td>
                            <td style="padding:10px 12px;"><?= sanitize($wl['user_name']) ?></td>
                            <td style="padding:10px 12px;max-width:300px;overflow:hidden;text-overflow:ellipsis;"><?= sanitize($wl['work_done'] ?? '') ?></td>
                            <td style="padding:10px 12px;">
                                <?php 
                                $resultColors = ['success' => '#22c55e', 'promise' => '#f59e0b', 'fail' => '#ef4444', 'other' => '#6b7280'];
                                $resultLabels = ['success' => 'Thành công', 'promise' => 'Hẹn trả', 'fail' => 'Thất bại', 'other' => 'Khác'];
                                $rt = $wl['result_type'] ?? 'other';
                                ?>
                                <span style="color:<?= $resultColors[$rt] ?? '#6b7280' ?>;font-weight:600;font-size:12px;">
                                    <?= $resultLabels[$rt] ?? $rt ?>
                                </span>
                                <?php if (!empty($wl['amount'])): ?>
                                    <br><span style="font-size:11px;color:var(--text-muted);"><?= number_format($wl['amount'], 0, ',', '.') ?>đ</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- NHẬT KÝ CHUYỂN PHÒNG -->
    <?php if ($filterType === 'all' || $filterType === 'transfer'): ?>
    <div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <h3 style="color:var(--text-primary);font-size:16px;margin:0;">🔄 Chuyển phòng (<?= count($transferLogs) ?>)</h3>
            <?php if (!empty($transferLogs)): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'xlsx', 'export_type' => 'transfer'])) ?>" class="btn btn-secondary btn-sm">📥 Xuất Excel</a>
            <?php endif; ?>
        </div>
        <?php if (empty($transferLogs)): ?>
            <div style="text-align:center;padding:24px;color:var(--text-muted);background:var(--bg-card);border-radius:var(--radius-lg);border:1px solid var(--border-color);">
                Không có lịch sử chuyển phòng nào
            </div>
        <?php else: ?>
            <div style="background:var(--bg-card);border-radius:var(--radius-lg);border:1px solid var(--border-color);overflow:hidden;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="background:var(--bg-primary);border-bottom:1px solid var(--border-color);">
                            <th style="padding:10px 12px;text-align:left;color:var(--text-secondary);font-weight:600;">Thời gian</th>
                            <th style="padding:10px 12px;text-align:left;color:var(--text-secondary);font-weight:600;">Khách hàng</th>
                            <th style="padding:10px 12px;text-align:left;color:var(--text-secondary);font-weight:600;">Từ phòng</th>
                            <th style="padding:10px 12px;text-align:left;color:var(--text-secondary);font-weight:600;">Đến phòng</th>
                            <th style="padding:10px 12px;text-align:left;color:var(--text-secondary);font-weight:600;">Người chuyển</th>
                            <th style="padding:10px 12px;text-align:left;color:var(--text-secondary);font-weight:600;">Ghi chú</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transferLogs as $tl): ?>
                        <tr style="border-bottom:1px solid var(--border-color);" onmouseenter="this.style.background='var(--bg-card-hover)'" onmouseleave="this.style.background=''">
                            <td style="padding:10px 12px;white-space:nowrap;"><?= date('d/m/Y H:i', strtotime($tl['transferred_at'])) ?></td>
                            <td style="padding:10px 12px;">
                                <a href="/cong-viec/khach-hang/<?= $tl['loan_id'] ?>" style="color:var(--accent-blue);text-decoration:none;font-weight:600;">
                                    <?= sanitize($tl['customer_name'] ?? '—') ?>
                                </a>
                            </td>
                            <td style="padding:10px 12px;"><?= $tl['from_room_icon'] ?? '' ?> <?= sanitize($tl['from_room_name'] ?? '—') ?></td>
                            <td style="padding:10px 12px;"><?= $tl['to_room_icon'] ?? '' ?> <?= sanitize($tl['to_room_name'] ?? '—') ?></td>
                            <td style="padding:10px 12px;"><?= sanitize($tl['transferred_by_name'] ?? '') ?></td>
                            <td style="padding:10px 12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;"><?= sanitize($tl['note'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php include 'layout_bottom.php'; ?>
