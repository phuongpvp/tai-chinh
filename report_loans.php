<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

// Fetch Active Loans
$sql = "SELECT l.*, c.name as customer_name, c.phone 
        FROM loans l 
        JOIN customers c ON l.customer_id = c.id 
        WHERE l.store_id = ? AND l.status = 'active'
        ORDER BY l.start_date DESC";
$stmt = $conn->prepare($sql);
$stmt->execute([$current_store_id]);
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_principal = 0;
foreach ($loans as $loan)
    $total_principal += $loan['amount'];

?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Báo cáo Đang cho vay</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="/style.css">
</head>

<body>
    <?php include 'header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content" style="padding-top: 80px !important;">
                <div
                    class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h4">Báo cáo: Hợp đồng Đang cho vay</h1>
                    <div class="text-muted small">
                        <?php echo $current_store_name; ?>
                    </div>
                </div>

                <!-- Summary Header -->
                <div class="alert alert-info shadow-sm border-0 d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fas fa-money-bill-wave me-2"></i> <b>TỔNG DƯ NỢ HIỆN TẠI:</b>
                    </div>
                    <div class="h3 mb-0 fw-bold">
                        <?php echo number_format($total_principal); ?> <small class="h6">VNĐ</small>
                    </div>
                </div>

                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white py-3 fw-bold">Danh sách chi tiết (
                        <?php echo count($loans); ?> hợp đồng)
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 small">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-center">Mã HĐ</th>
                                        <th>Khách hàng</th>
                                        <th class="text-center">Ngày vay</th>
                                        <th class="text-center">Số ngày</th>
                                        <th class="text-end">Tiền vay</th>
                                        <th class="text-center">Lãi suất</th>
                                        <th class="text-center">Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($loans)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">Hiện không có hợp đồng nào
                                                đang cho vay</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($loans as $loan): ?>
                                            <tr>
                                                <td class="text-center fw-bold text-primary">
                                                    <?php echo $loan['loan_code']; ?>
                                                </td>
                                                <td>
                                                    <div class="fw-bold">
                                                        <?php echo htmlspecialchars($loan['customer_name']); ?>
                                                    </div>
                                                    <div class="text-muted small">
                                                        <?php echo $loan['phone']; ?>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <?php echo date('d/m/Y', strtotime($loan['start_date'])); ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php echo $loan['period_days']; ?>
                                                </td>
                                                <td class="text-end fw-bold">
                                                    <?php echo number_format($loan['amount']); ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php
                                                    if ($loan['interest_type'] == 'ngay' || $loan['interest_rate'] > 100) {
                                                        echo number_format($loan['interest_rate']) . "k/1tr/ngày";
                                                    } else {
                                                        echo $loan['interest_rate'] . "%/tháng";
                                                    }
                                                    ?>
                                                </td>
                                                <td class="text-center">
                                                    <a href="contract_view.php?id=<?php echo $loan['id']; ?>"
                                                        class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i>
                                                        Xem</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>