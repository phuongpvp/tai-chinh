<?php
/**
 * Danh sách tất cả khách hàng
 */
require_once 'config.php';
requireLogin();

$pageTitle = 'Danh sách khách hàng';
$activePage = 'customers';

// Filters
$filterRoom = $_GET['room'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterSearch = trim($_GET['q'] ?? '');
$sortBy = $_GET['sort'] ?? 'name';
$sortDir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

$allowedSorts = ['name', 'phone', 'room_name', 'created_at', 'status', 'company_tag', 'cccd'];
if (!in_array($sortBy, $allowedSorts)) $sortBy = 'name';

// Build query
$where = [];
$params = [];

if ($filterRoom !== '') {
    $where[] = 'l.cv_room_id = ?';
    $params[] = intval($filterRoom);
}
if ($filterStatus !== '') {
    $where[] = 'l.cv_status = ?';
    $params[] = $filterStatus;
}
if ($filterSearch !== '') {
    $where[] = '(c.name LIKE ? OR c.phone LIKE ? OR c.identity_card LIKE ?)';
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) . " AND l.status != 'closed'" : "WHERE l.status != 'closed'";
$sortMap = ['name'=>'c.name','phone'=>'c.phone','room_name'=>'r.name','created_at'=>'l.created_at','status'=>'l.cv_status','company_tag'=>'s.name','cccd'=>'c.identity_card'];
$sortCol = $sortMap[$sortBy] ?? 'c.name';

$sql = "SELECT l.id, c.name, c.phone, c.identity_card as cccd, c.address,
    l.cv_status as status, l.cv_room_id as room_id, l.loan_code, l.amount as loan_amount,
    r.name as room_name, r.icon as room_icon,
    s.name as company_tag,
    '' as facebook_link, '' as workplace, '' as hktt
    FROM loans l
    LEFT JOIN customers c ON l.customer_id = c.id
    LEFT JOIN cv_rooms r ON l.cv_room_id = r.id
    LEFT JOIN stores s ON l.store_id = s.id
    $whereSQL
    ORDER BY $sortCol $sortDir";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

// Danh sách phòng cho filter
$rooms = $pdo->query("SELECT id, name, icon FROM cv_rooms ORDER BY sort_order, name")->fetchAll();

include 'layout_top.php';

// Helper sắp xếp URL
function sortUrl($col) {
    global $sortBy, $sortDir, $filterRoom, $filterStatus, $filterSearch;
    $dir = ($sortBy === $col && $sortDir === 'ASC') ? 'desc' : 'asc';
    $params = ['sort' => $col, 'dir' => $dir];
    if ($filterRoom !== '') $params['room'] = $filterRoom;
    if ($filterStatus !== '') $params['status'] = $filterStatus;
    if ($filterSearch !== '') $params['q'] = $filterSearch;
    return '/cong-viec/khach-hang?' . http_build_query($params);
}
function sortIcon($col) {
    global $sortBy, $sortDir;
    if ($sortBy !== $col) return '';
    return $sortDir === 'ASC' ? ' ▲' : ' ▼';
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title">👥 Danh sách khách hàng</h1>
        <p class="page-subtitle"><?= count($customers) ?> khách hàng</p>
    </div>
</div>

<div class="page-body">
    <!-- FILTERS -->
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center;">
        <input type="text" name="q" id="live-search" value="<?= sanitize($filterSearch) ?>" placeholder="🔍 Tìm tên, SĐT, CCCD..." 
            style="padding:6px 12px;background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-md);color:var(--text-primary);font-size:13px;min-width:200px;">
        <select name="room" style="padding:6px 10px;background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-md);color:var(--text-primary);font-size:13px;">
            <option value="">-- Tất cả phòng --</option>
            <?php foreach ($rooms as $r): ?>
                <option value="<?= $r['id'] ?>" <?= $filterRoom == $r['id'] ? 'selected' : '' ?>><?= $r['icon'] ?> <?= sanitize($r['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" style="padding:6px 10px;background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-md);color:var(--text-primary);font-size:13px;">
            <option value="">-- Trạng thái --</option>
            <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>🟢 Đang xử lý</option>
            <option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>>✅ Hoàn thành</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Lọc</button>
        <?php if ($filterRoom !== '' || $filterStatus !== '' || $filterSearch !== ''): ?>
            <a href="/cong-viec/khach-hang" class="btn btn-ghost btn-sm">✕ Xóa lọc</a>
        <?php endif; ?>
    </form>

    <!-- TABLE -->
    <?php if (empty($customers)): ?>
        <div class="empty-state" style="padding:40px;">
            <div class="empty-state-icon">👥</div>
            <div class="empty-state-text">Không tìm thấy khách hàng</div>
        </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="data-table" id="customers-table" style="font-size:13px;">
            <thead>
                <tr>
                    <th style="width:30px;">#</th>
                    <th><a href="<?= sortUrl('name') ?>" style="color:inherit;text-decoration:none;">Họ và tên<?= sortIcon('name') ?></a></th>
                    <th><a href="<?= sortUrl('room_name') ?>" style="color:inherit;text-decoration:none;">Phòng ban<?= sortIcon('room_name') ?></a></th>
                    <th><a href="<?= sortUrl('company_tag') ?>" style="color:inherit;text-decoration:none;">Thuộc Công ty<?= sortIcon('company_tag') ?></a></th>
                    <th>CCCD</th>
                    <th>SĐT Liên hệ</th>
                    <th>Link Facebook</th>
                    <th>Đơn vị công tác</th>
                    <th>HKTT</th>
                    <th>Địa chỉ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $i => $c): ?>
                <tr style="cursor:pointer;" onclick="window.location='/cong-viec/khach-hang/<?= $c['id'] ?>'">
                    <td class="row-num" style="color:var(--text-muted);font-size:12px;"><?= $i + 1 ?></td>
                    <td>
                        <a href="/cong-viec/khach-hang/<?= $c['id'] ?>" style="color:var(--accent-blue);text-decoration:none;font-weight:600;white-space:nowrap;" onclick="event.stopPropagation();">
                            <?= sanitize($c['name']) ?>
                        </a>
                    </td>
                    <td style="white-space:nowrap;">
                        <span class="tag" style="--tag-bg:rgba(35,131,226,0.12);--tag-color:var(--accent-blue);font-size:12px;">
                            <?= $c['room_icon'] ?? '' ?> <?= sanitize($c['room_name'] ?? '') ?>
                        </span>
                    </td>
                    <td style="white-space:nowrap;">
                        <?php if (!empty($c['company_tag'])): ?>
                            <span class="tag" style="--tag-bg:rgba(235,87,87,0.15);--tag-color:#eb5757;font-size:12px;"><?= sanitize($c['company_tag']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;font-family:monospace;font-size:12px;"><?= sanitize($c['cccd'] ?? '') ?></td>
                    <td style="white-space:nowrap;">
                        <?php if (!empty($c['phone'])): ?>
                            <a href="tel:<?= sanitize($c['phone']) ?>" style="color:var(--text-secondary);text-decoration:none;" onclick="event.stopPropagation();">
                                <?= sanitize($c['phone']) ?>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($c['facebook_link'])): ?>
                            <a href="<?= sanitize($c['facebook_link']) ?>" target="_blank" style="color:var(--accent-blue);text-decoration:none;font-size:12px;" onclick="event.stopPropagation();">
                                <?php
                                    $fbLink = $c['facebook_link'];
                                    $fbShort = preg_replace('/^https?:\/\/(www\.)?facebook\.com\//', '', $fbLink);
                                    echo sanitize(mb_strlen($fbShort) > 20 ? mb_substr($fbShort, 0, 20) . '...' : $fbShort);
                                ?>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= sanitize($c['workplace'] ?? '') ?></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= sanitize($c['hktt'] ?? '') ?></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= sanitize($c['address'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const input = document.getElementById('live-search');
    if (!input) return;
    const table = document.getElementById('customers-table');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    const rows = tbody.querySelectorAll('tr');
    const subtitle = document.querySelector('.page-subtitle');

    input.addEventListener('input', function(){
        const q = this.value.trim().toLowerCase();
        let visible = 0;
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const match = !q || text.includes(q);
            row.style.display = match ? '' : 'none';
            if (match) {
                visible++;
                row.querySelector('.row-num').textContent = visible;
            }
        });
        if (subtitle) subtitle.textContent = visible + ' khách hàng';
    });
});
</script>

<?php include 'layout_bottom.php'; ?>

