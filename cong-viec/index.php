<?php
require_once 'config.php';
requireLogin();

$pageTitle = 'Dashboard';
$activePage = 'dashboard';

// Lấy thống kê các phòng (cv_rooms + loans)
$roomsQuery = "
    SELECT r.*,
        (SELECT COUNT(*) FROM loans l WHERE l.cv_room_id = r.id AND l.cv_status = 'active' AND l.status != 'closed') as total_customers,
        (SELECT COUNT(*) FROM loans l WHERE l.cv_room_id = r.id AND l.cv_status = 'active' AND l.status != 'closed' AND l.cv_due_date < CURDATE()) as overdue_count,
        (SELECT COUNT(*) FROM loans l WHERE l.cv_room_id = r.id AND l.cv_status = 'active' AND l.status != 'closed' AND l.cv_due_date >= CURDATE() AND l.cv_due_date < DATE_ADD(CURDATE(), INTERVAL GREATEST(2, CEIL(r.sla_days / 3)) DAY)) as warning_count,
        (SELECT COUNT(*) FROM loans l WHERE l.cv_room_id = r.id AND l.cv_status = 'active' AND l.status != 'closed' AND (l.cv_due_date >= DATE_ADD(CURDATE(), INTERVAL GREATEST(2, CEIL(r.sla_days / 3)) DAY) OR l.cv_due_date IS NULL)) as safe_count
    FROM cv_rooms r
    WHERE r.is_archive = 0
    ORDER BY r.sort_order, r.name
";
$rooms = $pdo->query($roomsQuery)->fetchAll();

// Phòng lưu trữ
$archiveRooms = $pdo->query("
    SELECT r.*,
        (SELECT COUNT(*) FROM loans l WHERE l.cv_room_id = r.id AND l.cv_status = 'active' AND l.status != 'closed') as total_customers,
        (SELECT COUNT(*) FROM loans l WHERE l.cv_room_id = r.id AND l.cv_status = 'active' AND l.status != 'closed' AND l.cv_due_date < CURDATE()) as overdue_count,
        (SELECT COUNT(*) FROM loans l WHERE l.cv_room_id = r.id AND l.cv_status = 'active' AND l.status != 'closed' AND l.cv_due_date >= CURDATE() AND l.cv_due_date < DATE_ADD(CURDATE(), INTERVAL GREATEST(2, CEIL(r.sla_days / 3)) DAY)) as warning_count,
        (SELECT COUNT(*) FROM loans l WHERE l.cv_room_id = r.id AND l.cv_status = 'active' AND l.status != 'closed' AND (l.cv_due_date >= DATE_ADD(CURDATE(), INTERVAL GREATEST(2, CEIL(r.sla_days / 3)) DAY) OR l.cv_due_date IS NULL)) as safe_count
    FROM cv_rooms r 
    WHERE r.is_archive = 1
    ORDER BY r.sort_order, r.name
")->fetchAll();

// Tổng quát
$totalCustomers = $pdo->query("SELECT COUNT(*) FROM loans WHERE cv_status = 'active' AND status != 'closed' AND cv_room_id IS NOT NULL")->fetchColumn();
$totalOverdue = $pdo->query("SELECT COUNT(*) FROM loans WHERE cv_status = 'active' AND status != 'closed' AND cv_room_id IS NOT NULL AND cv_due_date < CURDATE()")->fetchColumn();
$totalDueToday = $pdo->query("SELECT COUNT(*) FROM loans WHERE cv_status = 'active' AND status != 'closed' AND cv_room_id IS NOT NULL AND cv_due_date = CURDATE()")->fetchColumn();
$totalOnTrack = $totalCustomers - $totalOverdue - $totalDueToday;

include 'layout_top.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><span class="page-icon">📊</span>Bàn Làm Việc của tôi</h1>
        <p class="page-subtitle">Tổng <strong><?= $totalCustomers ?></strong> khách hàng · <span style="color:var(--status-danger)"><?= $totalOverdue ?> quá hạn</span><?php if ($totalDueToday > 0): ?> · <span style="color:var(--status-warning)"><?= $totalDueToday ?> hết hạn hôm nay</span><?php endif; ?></p>
    </div>
    <div class="page-actions">
        <div class="view-toggle">
            <button class="view-toggle-btn active" onclick="setView('gallery')" id="btn-gallery">
                🖼️ Gallery
            </button>
            <button class="view-toggle-btn" onclick="setView('table')" id="btn-table">
                📋 Bảng
            </button>
        </div>
        <?php if ($user['role'] === 'admin'): ?>
            <a href="rooms_manage.php" class="btn btn-secondary">⚙️ Quản lý phòng</a>
        <?php endif; ?>
    </div>
</div>

<div class="page-body">
    <!-- STATS WIDGETS -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:24px;">
        <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:16px;">
            <div style="font-size:12px;color:#fff;margin-bottom:4px;">Tổng Khách</div>
            <div style="font-size:28px;font-weight:800;color:var(--accent-blue);"><?= $totalCustomers ?></div>
        </div>
        <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:16px;border-left:3px solid var(--status-danger);">
            <div style="font-size:12px;color:#fff;margin-bottom:4px;">🔴 Quá hạn</div>
            <div style="font-size:28px;font-weight:800;color:var(--status-danger);"><?= $totalOverdue ?></div>
        </div>
        <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:16px;border-left:3px solid var(--status-warning);">
            <div style="font-size:12px;color:#fff;margin-bottom:4px;">🟡 Hết hạn hôm nay</div>
            <div style="font-size:28px;font-weight:800;color:var(--status-warning);"><?= $totalDueToday ?></div>
        </div>
        <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:16px;border-left:3px solid var(--status-safe);">
            <div style="font-size:12px;color:#fff;margin-bottom:4px;">🟢 Còn hạn</div>
            <div style="font-size:28px;font-weight:800;color:var(--status-safe);"><?= $totalOnTrack ?></div>
        </div>
    </div>

    <!-- GALLERY VIEW -->
    <div id="view-gallery">
        <div class="gallery-grid">
            <?php foreach ($rooms as $room): ?>
                <a href="room.php?id=<?= $room['id'] ?>" class="room-card" style="--card-accent: <?= sanitize($room['color']) ?>; text-decoration:none; color:inherit;">
                    <div class="room-card-icon"><?= $room['icon'] ?></div>
                    <div class="room-card-name"><?= sanitize($room['name']) ?></div>
                    <div class="room-card-stats">
                        <div class="room-stat">
                            <span class="room-stat-dot blue"></span>
                            <span>Tổng khách: <strong><?= $room['total_customers'] ?></strong></span>
                        </div>
                        <div class="room-stat">
                            <span class="room-stat-dot red"></span>
                            <span>Quá hạn: <strong><?= $room['overdue_count'] ?></strong></span>
                        </div>
                        <div class="room-stat">
                            <span class="room-stat-dot yellow"></span>
                            <span>Gần quá hạn: <strong><?= $room['warning_count'] ?></strong></span>
                        </div>
                        <div class="room-stat">
                            <span class="room-stat-dot green"></span>
                            <span>Chưa quá hạn: <strong><?= $room['safe_count'] ?></strong></span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($archiveRooms)): ?>
            <h2 style="margin-top:32px; font-size:18px; color:var(--text-muted); font-weight:600; margin-bottom:16px;">📁 Lưu trữ</h2>
            <div class="gallery-grid">
                <?php foreach ($archiveRooms as $room): ?>
                    <a href="room.php?id=<?= $room['id'] ?>" class="room-card" style="--card-accent: #6b6b6b; text-decoration:none; color:inherit;">
                        <div class="room-card-icon"><?= $room['icon'] ?></div>
                        <div class="room-card-name"><?= sanitize($room['name']) ?></div>
                        <div class="room-card-stats">
                            <div class="room-stat">
                                <span class="room-stat-dot blue"></span>
                                <span>Tổng khách: <strong><?= $room['total_customers'] ?></strong></span>
                            </div>
                            <div class="room-stat">
                                <span class="room-stat-dot red"></span>
                                <span>Quá hạn: <strong><?= $room['overdue_count'] ?></strong></span>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- TABLE VIEW -->
    <div id="view-table" style="display:none;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Phòng</th>
                    <th>Tổng khách</th>
                    <th>Quá hạn</th>
                    <th>Sắp quá hạn</th>
                    <th>Còn hạn</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rooms as $room): ?>
                <tr onclick="location.href='room.php?id=<?= $room['id'] ?>'" style="cursor:pointer">
                    <td>
                        <span style="margin-right:8px"><?= $room['icon'] ?></span>
                        <strong><?= sanitize($room['name']) ?></strong>
                    </td>
                    <td><?= $room['total_customers'] ?></td>
                    <td>
                        <?php if ($room['overdue_count'] > 0): ?>
                            <span style="color:var(--status-danger);font-weight:600"><?= $room['overdue_count'] ?></span>
                        <?php else: ?>
                            <span style="color:var(--text-muted)">0</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($room['warning_count'] > 0): ?>
                            <span style="color:var(--status-warning);font-weight:600"><?= $room['warning_count'] ?></span>
                        <?php else: ?>
                            <span style="color:var(--text-muted)">0</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span style="color:var(--status-safe)"><?= $room['safe_count'] ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function setView(view) {
    document.getElementById('view-gallery').style.display = view === 'gallery' ? '' : 'none';
    document.getElementById('view-table').style.display = view === 'table' ? '' : 'none';
    document.getElementById('btn-gallery').classList.toggle('active', view === 'gallery');
    document.getElementById('btn-table').classList.toggle('active', view === 'table');
    localStorage.setItem('dashboard-view', view);
}

const savedView = localStorage.getItem('dashboard-view');
if (savedView) setView(savedView);
</script>

<?php include 'layout_bottom.php'; ?>
