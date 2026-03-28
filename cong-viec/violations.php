<?php
require_once 'config.php';
requireLogin();
requireRole(['admin', 'manager']);

$pageTitle = 'Vi phạm quy trình';
$activePage = 'violations';
$user = cvGetUser();

// Filters
$filterType = $_GET['type'] ?? '';
$filterPage = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($filterPage - 1) * $perPage;

// Build query
$where = "1=1";
$params = [];

if ($filterType) {
    $where .= " AND v.type = ?";
    $params[] = $filterType;
}

// Count total
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM cv_violations v WHERE $where");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

// Fetch violations
$sql = "SELECT v.*, 
    c.name as customer_name, v.loan_id as customer_id,
    u.fullname as user_name
    FROM cv_violations v
    LEFT JOIN loans l ON v.loan_id = l.id
    LEFT JOIN customers c ON l.customer_id = c.id
    LEFT JOIN users u ON v.user_id = u.id
    WHERE $where
    ORDER BY v.created_at DESC
    LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$violations = $stmt->fetchAll();

$typeLabels = [
    'TRANSFER_SAME_ROOM' => ['label' => 'Chuyển cùng phòng', 'color' => '#eb5757', 'icon' => '🔴'],
    'DUPLICATE_TRANSFER' => ['label' => 'Chuyển trùng lặp', 'color' => '#f5a623', 'icon' => '🟡'],
    'DATA_MISSING' => ['label' => 'Thiếu dữ liệu', 'color' => '#6b7280', 'icon' => '⚪'],
];

include 'layout_top.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><span class="page-icon">⚠️</span>Vi phạm quy trình</h1>
        <p class="page-subtitle">Tổng <strong><?= $totalRows ?></strong> vi phạm được ghi nhận</p>
    </div>
</div>

<div class="page-body">
    <!-- FILTER -->
    <div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
        <a href="?type=" class="btn <?= !$filterType ? 'btn-primary' : 'btn-secondary' ?> btn-sm">Tất cả (<?= $totalRows ?>)</a>
        <?php foreach ($typeLabels as $typeKey => $typeInfo): ?>
            <a href="?type=<?= $typeKey ?>" class="btn <?= $filterType === $typeKey ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
                <?= $typeInfo['icon'] ?> <?= $typeInfo['label'] ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($violations)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">✅</div>
            <div class="empty-state-text">Chưa có vi phạm nào được ghi nhận</div>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>📅 Thời gian</th>
                    <th>Loại</th>
                    <th>👤 Khách hàng</th>
                    <th>Người thao tác</th>
                    <th>Chi tiết</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($violations as $v): 
                    $info = $typeLabels[$v['type']] ?? ['label' => $v['type'], 'color' => '#6b7280', 'icon' => '❓'];
                    $detail = $v['detail'] ? json_decode($v['detail'], true) : null;
                ?>
                <tr>
                    <td style="white-space:nowrap"><?= date('d/m/Y H:i', strtotime($v['created_at'])) ?></td>
                    <td>
                        <span class="tag" style="--tag-bg:<?= $info['color'] ?>20;--tag-color:<?= $info['color'] ?>;">
                            <?= $info['icon'] ?> <?= $info['label'] ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($v['customer_name']): ?>
                            <a href="customer.php?id=<?= $v['customer_id'] ?>" style="font-weight:500;"><?= sanitize($v['customer_name']) ?></a>
                        <?php else: ?>
                            <span style="color:var(--text-muted)">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= sanitize($v['user_name'] ?? '—') ?></td>
                    <td style="font-size:12px;color:var(--text-muted);max-width:250px;">
                        <?php if ($detail): ?>
                            <?php 
                            $parts = [];
                            if (isset($detail['from_room_id'])) {
                                $fr = $pdo->prepare("SELECT name FROM cv_rooms WHERE id = ?");
                                $fr->execute([$detail['from_room_id']]);
                                $frName = $fr->fetchColumn();
                                $parts[] = "Từ: " . ($frName ?: $detail['from_room_id']);
                            }
                            if (isset($detail['to_room_id'])) {
                                $tr = $pdo->prepare("SELECT name FROM cv_rooms WHERE id = ?");
                                $tr->execute([$detail['to_room_id']]);
                                $trName = $tr->fetchColumn();
                                $parts[] = "Đến: " . ($trName ?: $detail['to_room_id']);
                            }
                            echo sanitize(implode(' → ', $parts));
                            ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- PAGINATION -->
        <?php if ($totalPages > 1): ?>
        <div style="display:flex;justify-content:center;gap:8px;margin-top:20px;">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a href="?type=<?= $filterType ?>&page=<?= $p ?>" 
                   class="btn <?= $p === $filterPage ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
                    <?= $p ?>
                </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include 'layout_bottom.php'; ?>
