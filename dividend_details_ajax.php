<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    exit("Unauthorized");
}
require_once 'config.php';

$distribution_id = $_GET['id'] ?? 0;

// Get distribution info
$stmt = $conn->prepare("
    SELECT dd.*, s.name as store_name, u.fullname, u.username
    FROM dividend_distributions dd
    LEFT JOIN stores s ON dd.store_id = s.id
    LEFT JOIN users u ON dd.user_id = u.id
    WHERE dd.id = ?
");
$stmt->execute([$distribution_id]);
$distribution = $stmt->fetch();

if (!$distribution) {
    echo '<div class="alert alert-danger">Không tìm thấy dữ liệu</div>';
    exit;
}

// Get details
$stmt = $conn->prepare("
    SELECT * FROM dividend_details 
    WHERE distribution_id = ? 
    ORDER BY amount DESC
");
$stmt->execute([$distribution_id]);
$details = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="mb-3">
    <table class="table table-sm table-borderless">
        <tr>
            <th width="150">Cửa hàng:</th>
            <td>
                <?php echo htmlspecialchars($distribution['store_name']); ?>
            </td>
        </tr>
        <tr>
            <th>Ngày chia:</th>
            <td>
                <?php echo date('d/m/Y', strtotime($distribution['distribution_date'])); ?>
            </td>
        </tr>
        <tr>
            <th>Tổng số tiền:</th>
            <td class="fw-bold text-danger">
                <?php echo number_format($distribution['total_amount']); ?> đ
            </td>
        </tr>
        <tr>
            <th>Ghi chú:</th>
            <td>
                <?php echo htmlspecialchars($distribution['note'] ?: '-'); ?>
            </td>
        </tr>
        <tr>
            <th>Người thực hiện:</th>
            <td>
                <?php echo htmlspecialchars($distribution['fullname'] ?: $distribution['username']); ?>
            </td>
        </tr>
        <?php if ($distribution['batch_id']): ?>
            <tr>
                <th>Batch ID:</th>
                <td><span class="badge bg-info">
                        <?php echo htmlspecialchars($distribution['batch_id']); ?>
                    </span></td>
            </tr>
        <?php endif; ?>
    </table>
</div>

<h6 class="border-bottom pb-2">Chi tiết chia cho từng cổ đông:</h6>
<table class="table table-hover table-sm">
    <thead class="table-light">
        <tr>
            <th>Mã CĐ</th>
            <th>Tên cổ đông</th>
            <th class="text-end">Tỷ lệ %</th>
            <th class="text-end">Số tiền nhận</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($details as $d): ?>
            <tr>
                <td>
                    <?php echo htmlspecialchars($d['shareholder_code']); ?>
                </td>
                <td>
                    <?php echo htmlspecialchars($d['shareholder_name']); ?>
                </td>
                <td class="text-end">
                    <?php echo number_format($d['percentage'], 2); ?>%
                </td>
                <td class="text-end fw-bold text-success">
                    <?php echo number_format($d['amount']); ?> đ
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot class="table-light fw-bold">
        <tr>
            <td colspan="3" class="text-end">Tổng cộng:</td>
            <td class="text-end text-danger">
                <?php echo number_format($distribution['total_amount']); ?> đ
            </td>
        </tr>
    </tfoot>
</table>