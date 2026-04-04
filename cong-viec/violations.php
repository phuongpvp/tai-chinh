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
    <div class="empty-state" style="padding: 60px 20px;">
        <div class="empty-state-icon" style="font-size: 48px; margin-bottom: 20px;">🚧</div>
        <div class="empty-state-text" style="font-size: 20px; font-weight: 600; color: var(--text-color);">Tính năng đang phát triển</div>
        <div style="color: var(--text-muted); margin-top: 10px;">Tính năng này tạm thời khóa để cập nhật và sẽ sớm ra mắt.</div>
        <a href="index.php" class="btn btn-primary" style="margin-top: 24px;">⬅️ Quay lại Bàn Làm Việc</a>
    </div>
</div>

<?php include 'layout_bottom.php'; ?>
