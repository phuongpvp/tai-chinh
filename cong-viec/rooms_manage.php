<?php
require_once 'config.php';
requireLogin();
requireRole('admin');

$pageTitle = 'Quản lý phòng';
$activePage = 'rooms_manage';
$user = cvGetUser();

// Xử lý actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add_room':
            $name = trim($_POST['name'] ?? '');
            $icon = trim($_POST['icon'] ?? '🏠');
            $color = $_POST['color'] ?? '#f59e0b';
            $sortOrder = intval($_POST['sort_order'] ?? 0);
            $slaDays = max(0, intval($_POST['sla_days'] ?? 0));
            $isArchive = isset($_POST['is_archive']) ? 1 : 0;
            $actionOpts = array_values(array_filter(array_map('trim', explode("\n", $_POST['action_options'] ?? ''))));
            $resultOpts = array_values(array_filter(array_map('trim', explode("\n", $_POST['result_options'] ?? ''))));

            if ($name) {
                $stmt = $pdo->prepare("INSERT INTO cv_rooms (name, icon, color, sort_order, sla_days, is_archive, action_options, result_options) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $icon, $color, $sortOrder, $slaDays, $isArchive, json_encode($actionOpts, JSON_UNESCAPED_UNICODE), json_encode($resultOpts, JSON_UNESCAPED_UNICODE)]);
                $_SESSION['flash_message'] = 'Đã thêm phòng: ' . $name;
                redirect('rooms_manage.php');
            }
            break;

        case 'update_room':
            $rid = intval($_POST['room_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $icon = trim($_POST['icon'] ?? '🏠');
            $color = $_POST['color'] ?? '#f59e0b';
            $sortOrder = intval($_POST['sort_order'] ?? 0);
            $slaDays = max(0, intval($_POST['sla_days'] ?? 0));
            $isArchive = isset($_POST['is_archive']) ? 1 : 0;
            $actionOpts = array_values(array_filter(array_map('trim', explode("\n", $_POST['action_options'] ?? ''))));
            $resultOpts = array_values(array_filter(array_map('trim', explode("\n", $_POST['result_options'] ?? ''))));

            if ($rid && $name) {
                $stmt = $pdo->prepare("UPDATE cv_rooms SET name=?, icon=?, color=?, sort_order=?, sla_days=?, is_archive=?, action_options=?, result_options=? WHERE id=?");
                $stmt->execute([$name, $icon, $color, $sortOrder, $slaDays, $isArchive, json_encode($actionOpts, JSON_UNESCAPED_UNICODE), json_encode($resultOpts, JSON_UNESCAPED_UNICODE), $rid]);
                $_SESSION['flash_message'] = 'Đã cập nhật phòng';
                redirect('rooms_manage.php');
            }
            break;

        case 'delete_room':
            $rid = intval($_POST['room_id'] ?? 0);
            if ($rid) {
                // Kiểm tra còn khách hàng không
                $count = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE cv_room_id = ? AND status = 'active'");
                $count->execute([$rid]);
                if ($count->fetchColumn() > 0) {
                    $_SESSION['flash_message'] = 'Không thể xóa phòng đang có khách hàng!';
                    $_SESSION['flash_type'] = 'error';
                } else {
                    $stmt = $pdo->prepare("DELETE FROM cv_rooms WHERE id = ?");
                    $stmt->execute([$rid]);
                    $_SESSION['flash_message'] = 'Đã xóa phòng';
                }
                redirect('rooms_manage.php');
            }
            break;
    }
}

// Lấy danh sách phòng
$rooms = $pdo->query("SELECT r.*, 
    (SELECT COUNT(*) FROM loans l LEFT JOIN customers c ON l.customer_id = c.id WHERE l.cv_room_id = r.id AND l.cv_status = 'active') as customer_count
    FROM cv_rooms r ORDER BY r.sort_order, r.name")->fetchAll();

include 'layout_top.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><span class="page-icon">🏢</span>Quản lý phòng / Bộ phận</h1>
        <p class="page-subtitle">Tổng <?= count($rooms) ?> phòng</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" onclick="openModal('add-room-modal')">➕ Thêm phòng</button>
    </div>
</div>

<div class="page-body">
    <table class="data-table">
        <thead>
            <tr>
                <th>Thứ tự</th>
                <th>Phòng</th>
                <th>Thời hạn</th>
                <th>Options</th>
                <th>Khách hàng</th>
                <th>Loại</th>
                <th style="text-align:right">Thao tác</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rooms as $r): ?>
            <tr>
                <td style="color:var(--text-muted)"><?= $r['sort_order'] ?></td>
                <td>
                    <span style="margin-right:8px;font-size:18px"><?= $r['icon'] ?></span>
                    <strong><?= sanitize($r['name']) ?></strong>
                </td>
                <td>
                    <?php if ($r['sla_days'] > 0): ?>
                        <span style="background:rgba(35,131,226,0.15);color:#2383e2;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600;"><?= $r['sla_days'] ?> ngày</span>
                    <?php else: ?>
                        <span style="color:var(--text-muted);font-size:12px;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                    $wlConfig = json_decode($r['worklog_config'] ?? '[]', true) ?: [];
                    $actionCount = count($wlConfig);
                    $resultCount = 0;
                    foreach ($wlConfig as $ac) { $resultCount += count($ac['results'] ?? []); }
                    ?>
                    <?php if ($actionCount): ?>
                        <a href="room_config.php?id=<?= $r['id'] ?>" style="font-size:12px;color:var(--accent-blue);">🎯<?= $actionCount ?> · 📊<?= $resultCount ?></a>
                    <?php else: ?>
                        <a href="room_config.php?id=<?= $r['id'] ?>" style="font-size:12px;color:var(--status-warning);">⚠️ Chưa cấu hình</a>
                    <?php endif; ?>
                </td>
                <td><?= $r['customer_count'] ?></td>
                <td>
                    <?php if ($r['is_archive']): ?>
                        <span style="color:var(--text-muted)">📁 Lưu trữ</span>
                    <?php else: ?>
                        <span style="color:var(--status-safe)">● Hoạt động</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right">
                    <a href="room_config.php?id=<?= $r['id'] ?>" class="btn btn-ghost btn-sm" title="Cấu hình nhật ký">⚙️</a>
                    <button class="btn btn-ghost btn-sm" onclick='editRoom(<?= json_encode($r) ?>)'>✏️</button>
                    <?php if ($r['customer_count'] == 0): ?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Xóa phòng <?= sanitize($r['name']) ?>?')">
                        <input type="hidden" name="action" value="delete_room">
                        <input type="hidden" name="room_id" value="<?= $r['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--accent-red)">🗑️</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- MODAL: Thêm phòng -->
<div class="modal-overlay" id="add-room-modal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">➕ Thêm phòng mới</h3>
            <button class="modal-close" onclick="closeModal('add-room-modal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_room">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Tên phòng *</label>
                    <input type="text" name="name" class="form-input" placeholder="Tín dụng 1" required>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div class="form-group">
                        <label class="form-label">Icon (emoji)</label>
                        <input type="text" name="icon" class="form-input" value="🏠" placeholder="🏠">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Màu sắc</label>
                        <input type="color" name="color" class="form-input" value="#f59e0b" style="height:42px;padding:4px;">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div class="form-group">
                        <label class="form-label">Thời hạn (số ngày)</label>
                        <input type="number" name="sla_days" class="form-input" value="0" min="0" placeholder="0 = không giới hạn">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Thứ tự hiển thị</label>
                        <input type="number" name="sort_order" class="form-input" value="0" min="0">
                    </div>
                </div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" name="is_archive" value="1">
                        <span class="form-label" style="margin:0">Đây là phòng lưu trữ</span>
                    </label>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('add-room-modal')">Hủy</button>
                <button type="submit" class="btn btn-primary">Thêm phòng →</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Sửa phòng -->
<div class="modal-overlay" id="edit-room-modal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">✏️ Sửa phòng</h3>
            <button class="modal-close" onclick="closeModal('edit-room-modal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_room">
            <input type="hidden" name="room_id" id="er-id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Tên phòng *</label>
                    <input type="text" name="name" id="er-name" class="form-input" required>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div class="form-group">
                        <label class="form-label">Icon (emoji)</label>
                        <input type="text" name="icon" id="er-icon" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Màu sắc</label>
                        <input type="color" name="color" id="er-color" class="form-input" style="height:42px;padding:4px;">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div class="form-group">
                        <label class="form-label">Thời hạn (số ngày)</label>
                        <input type="number" name="sla_days" id="er-sla" class="form-input" min="0" placeholder="0 = không giới hạn">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Thứ tự hiển thị</label>
                        <input type="number" name="sort_order" id="er-sort" class="form-input" min="0">
                    </div>
                </div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" name="is_archive" id="er-archive" value="1">
                        <span class="form-label" style="margin:0">Đây là phòng lưu trữ</span>
                    </label>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('edit-room-modal')">Hủy</button>
                <button type="submit" class="btn btn-primary">💾 Lưu thay đổi</button>
            </div>
        </form>
    </div>
</div>

<script>
function editRoom(r) {
    document.getElementById('er-id').value = r.id;
    document.getElementById('er-name').value = r.name;
    document.getElementById('er-icon').value = r.icon;
    document.getElementById('er-color').value = r.color;
    document.getElementById('er-sla').value = r.sla_days || 0;
    document.getElementById('er-sort').value = r.sort_order;
    document.getElementById('er-archive').checked = r.is_archive == 1;
    openModal('edit-room-modal');
}
</script>

<?php include 'layout_bottom.php'; ?>
