<?php
require_once 'config.php';
requireLogin();

$roomId = intval($_GET['id'] ?? 0);
if (!$roomId) redirect('/cong-viec/tong-quan');

// Lấy thông tin phòng (bao gồm SLA)
$room = $pdo->prepare("SELECT * FROM cv_rooms WHERE id = ?");
$room->execute([$roomId]);
$room = $room->fetch();
if (!$room) redirect('/cong-viec/tong-quan');
$slaDays = intval($room['sla_days'] ?? 0);

$user = cvGetUser();
$pageTitle = $room['name'];
$activePage = 'room-' . $roomId;

// Xử lý thêm khách hàng mới
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_customer') {
        $name = trim($_POST['name'] ?? '');
        $dueDate = $_POST['due_date'] ?? null;
        $notes = trim($_POST['notes'] ?? '');
        $assignedTo = intval($_POST['assigned_to'] ?? 0) ?: null;
        $phone = trim($_POST['phone'] ?? '');
        $cccd = trim($_POST['cccd'] ?? '');
        $companyTag = trim($_POST['company_tag'] ?? '');

        if (!empty($name)) {
            $now = date('Y-m-d H:i:s');
            // Tự tính due_date từ SLA nếu không nhập tay
            if (empty($dueDate) && $slaDays > 0) {
                $computed = computeCaseStatus($now, $slaDays);
                $dueDate = $computed['due_date'];
            }
            // Tạo customer trong bảng TC trước
            $stmt = $pdo->prepare("INSERT INTO customers (name, phone, identity_card) VALUES (?, ?, ?)");
            $stmt->execute([$name, $phone ?: null, $cccd ?: null]);
            $newCustId = $pdo->lastInsertId();
            
            // Tạo loan và gán vào phòng CV
            $loanCode = 'CV-' . $newCustId;
            $stmt2 = $pdo->prepare("INSERT INTO loans (customer_id, loan_code, amount, status, cv_room_id, cv_assigned_to, cv_due_date, cv_notes, cv_status, cv_transfer_date) VALUES (?, ?, 0, 'active', ?, ?, ?, ?, 'active', ?)");
            $stmt2->execute([$newCustId, $loanCode, $roomId, $assignedTo, $dueDate ?: null, $notes, $now]);
            $_SESSION['flash_message'] = 'Đã thêm khách hàng: ' . $name;
            redirect('/cong-viec/phong/' . $roomId);
        }
    }
}

// Lấy danh sách khách hàng
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$sql = "SELECT l.id, c.name, c.phone, c.identity_card as cccd, c.address,
        l.cv_room_id as room_id, l.cv_status as status, l.cv_due_date as due_date,
        l.cv_assigned_to as assigned_to, l.cv_notes as notes,
        l.cv_transfer_date as transfer_date, l.loan_code, l.amount as loan_amount,
        l.cv_company_tag as company_tag,
        u.fullname as assigned_name 
        FROM loans l LEFT JOIN customers c ON l.customer_id = c.id 
        LEFT JOIN users u ON l.cv_assigned_to = u.id
        WHERE l.cv_room_id = ? AND l.status != 'closed'";
$params = [$roomId];

if ($filter === 'overdue') {
    $sql .= " AND l.cv_status = 'active' AND l.cv_due_date < CURDATE()";
} elseif ($filter === 'warning') {
    $warnDays = max(1, intval($slaDays / 3));
    $sql .= " AND l.cv_status = 'active' AND l.cv_due_date >= CURDATE() AND l.cv_due_date < DATE_ADD(CURDATE(), INTERVAL $warnDays DAY)";
} elseif ($filter === 'safe') {
    $warnDays = max(1, intval($slaDays / 3));
    $sql .= " AND l.cv_status = 'active' AND (l.cv_due_date >= DATE_ADD(CURDATE(), INTERVAL $warnDays DAY) OR l.cv_due_date IS NULL)";
} elseif ($filter === 'active') {
    $sql .= " AND l.cv_status = 'active'";
} else {
    $sql .= " AND l.cv_status IN ('active','overdue','completed')";
}

if ($search) {
    $sql .= " AND c.name LIKE ?";
    $params[] = '%' . $search . '%';
}

$sql .= " ORDER BY l.cv_due_date ASC, c.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

// Lấy danh sách nhân viên (cho dropdown)
$employees = $pdo->query("SELECT id, fullname, role FROM users WHERE cv_role IS NOT NULL ORDER BY fullname")->fetchAll();

// Thống kê
$warnDays = max(1, intval($slaDays / 3));
$stats = $pdo->prepare("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN cv_due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
    SUM(CASE WHEN cv_due_date >= CURDATE() AND cv_due_date < DATE_ADD(CURDATE(), INTERVAL $warnDays DAY) THEN 1 ELSE 0 END) as warning
    FROM loans WHERE cv_room_id = ? AND cv_status IN ('active','overdue','completed') AND status != 'closed'");
$stats->execute([$roomId]);
$stats = $stats->fetch();

include 'layout_top.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <span class="page-icon" style="font-size:36px"><?= $room['icon'] ?></span>
            <?= sanitize($room['name']) ?>
        </h1>
        <p class="page-subtitle">
            <strong><?= $stats['total'] ?></strong> khách hàng
            <?php if ($slaDays > 0): ?>
                · <span style="color:var(--accent-blue)">Hạn: <?= $slaDays ?> ngày</span>
            <?php endif; ?>
            <?php if ($stats['overdue'] > 0): ?>
                · <span style="color:var(--status-danger)"><?= $stats['overdue'] ?> quá hạn</span>
            <?php endif; ?>
            <?php if ($stats['warning'] > 0): ?>
                · <span style="color:var(--status-warning)"><?= $stats['warning'] ?> sắp quá hạn</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="page-actions">
        <div class="search-bar">
            <span class="search-icon">🔍</span>
            <input type="text" placeholder="Tìm khách hàng..." id="search-input"
                   value="<?= sanitize($search) ?>" onkeydown="if(event.key==='Enter') searchCustomer()">
        </div>
        <button class="btn btn-primary" onclick="openModal('add-customer-modal')">
            ➕ Thêm khách
        </button>
    </div>
</div>

<div class="page-body">
    <!-- FILTER TABS -->
    <div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
        <a href="?id=<?= $roomId ?>&filter=all" class="btn <?= $filter === 'all' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
            Tất cả (<?= $stats['total'] ?>)
        </a>
        <a href="?id=<?= $roomId ?>&filter=overdue" class="btn <?= $filter === 'overdue' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
            🔴 Quá hạn (<?= $stats['overdue'] ?>)
        </a>
        <a href="?id=<?= $roomId ?>&filter=warning" class="btn <?= $filter === 'warning' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
            🟡 Sắp quá hạn (<?= $stats['warning'] ?>)
        </a>
        <a href="?id=<?= $roomId ?>&filter=safe" class="btn <?= $filter === 'safe' ? 'btn-primary' : 'btn-secondary' ?> btn-sm" style="text-decoration:none">
            🟢 Còn hạn (<?= $stats['total'] - $stats['overdue'] - $stats['warning'] ?>)
        </a>
    </div>

    <!-- CUSTOMER CARDS GRID -->
    <?php if (empty($customers)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">👤</div>
            <div class="empty-state-text">Chưa có khách hàng nào trong phòng này</div>
            <button class="btn btn-primary" onclick="openModal('add-customer-modal')">➕ Thêm khách hàng đầu tiên</button>
        </div>
    <?php else: ?>
        <div class="customer-grid">
            <?php foreach ($customers as $c): 
                $days = getDaysRemaining($c['due_date']);
                $statusColor = getStatusColor($days, $slaDays);
                if ($days === null) {
                    $statusText = 'Chưa có hạn';
                } elseif ($days < 0) {
                    $statusText = 'Quá hạn ' . abs($days) . ' ngày';
                } elseif ($days === 0) {
                    $statusText = 'Hết hạn hôm nay';
                } else {
                    $statusText = 'Còn ' . $days . ' ngày';
                }
            ?>
                <a href="/cong-viec/khach-hang/<?= $c['id'] ?>" class="customer-card" style="text-decoration:none;color:inherit;">
                    <div class="customer-card-header">
                        <div class="customer-avatar">👤</div>
                        <div class="customer-name"><?= sanitize($c['name']) ?></div>
                    </div>
                    <div class="customer-status" style="background:<?= $statusColor ?>20; color:<?= $statusColor ?>">
                        <span class="status-dot" style="background:<?= $statusColor ?>"></span>
                        <?= $statusText ?>
                    </div>
                    <?php if (!empty($c['company_tag'])): ?>
                    <div class="customer-tags">
                        <span class="tag" style="background:var(--accent-green);color:#000;font-weight:600;font-size:11px;"><?= sanitize($c['company_tag']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($c['assigned_name']): ?>
                    <div class="customer-tags">
                        <span class="tag"><?= sanitize($c['assigned_name']) ?></span>
                    </div>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL: Thêm khách hàng -->
<div class="modal-overlay" id="add-customer-modal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">➕ Thêm khách hàng mới</h3>
            <button class="modal-close" onclick="closeModal('add-customer-modal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_customer">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Họ và tên khách hàng *</label>
                    <input type="text" name="name" class="form-input" placeholder="Nhập họ tên" required>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div class="form-group">
                        <label class="form-label">SĐT</label>
                        <input type="text" name="phone" class="form-input" placeholder="0xxx xxx xxx">
                    </div>
                    <div class="form-group">
                        <label class="form-label">CCCD</label>
                        <input type="text" name="cccd" class="form-input" placeholder="Số CCCD">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div class="form-group">
                        <label class="form-label">Ngày hết hạn <?= $slaDays > 0 ? '<span style="color:var(--text-muted);font-weight:400">(tự động từ SLA)</span>' : '' ?></label>
                        <input type="date" name="due_date" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Thuộc công ty</label>
                        <input type="text" name="company_tag" class="form-input" placeholder="Tên công ty">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Nhân viên phụ trách</label>
                    <select name="assigned_to" class="form-select">
                        <option value="">-- Chọn nhân viên --</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>"><?= sanitize($emp['fullname']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Ghi chú</label>
                    <textarea name="notes" class="form-textarea" placeholder="Ghi chú thêm..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('add-customer-modal')">Hủy</button>
                <button type="submit" class="btn btn-primary">Thêm khách →</button>
            </div>
        </form>
    </div>
</div>

<script>
function searchCustomer() {
    const q = document.getElementById('search-input').value;
    window.location.href = '?id=<?= $roomId ?>&search=' + encodeURIComponent(q);
}
</script>

<?php include 'layout_bottom.php'; ?>
