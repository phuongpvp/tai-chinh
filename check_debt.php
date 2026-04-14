<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

// Mặc định lấy từ 1/4 đến 14/4 như anh yêu cầu, nhưng làm thêm bộ lọc cho linh hoạt luôn
$startDate = $_GET['from_date'] ?? date('Y-04-01');
$endDate = $_GET['to_date'] ?? date('Y-04-14');

// Lọc các hợp đồng đang vay và có ngày nợ (Next Payment Date) nằm trong khoảng
// Gỡ bỏ giới hạn store_id để lấy toàn bộ các công ty
$sql = "SELECT l.*, c.name as customer_name, c.phone, c.address, s.name as store_name
        FROM loans l 
        JOIN customers c ON l.customer_id = c.id 
        LEFT JOIN stores s ON l.store_id = s.id
        WHERE l.status = 'active' 
        AND DATE(l.next_payment_date) BETWEEN ? AND ?
        ORDER BY l.next_payment_date ASC, s.id ASC";

$stmt = $conn->prepare($sql);
$stmt->execute([$startDate, $endDate]);
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Khách hàng có lịch nợ (1/4 - 14/4)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="/style.css">
</head>

<body>

    <?php include 'header.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                
                 <!-- Breadcrumb & Title -->
                 <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h5"><i class="fas fa-search-dollar me-1"></i> Kiểm tra Khách hàng đến hạn nợ</h1>
                </div>

                <!-- Filter Bar -->
                 <div class="card p-3 mb-3 bg-white border shadow-sm">
                    <form class="row g-2" method="GET">
                        <div class="col-md-3">
                            <label class="fw-bold small">Từ ngày nợ</label>
                            <input type="date" name="from_date" class="form-control form-control-sm" value="<?php echo $startDate; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="fw-bold small">Đến ngày</label>
                            <input type="date" name="to_date" class="form-control form-control-sm" value="<?php echo $endDate; ?>">
                        </div>
                         <div class="col-md-auto align-self-end">
                            <button class="btn btn-info btn-sm text-white fw-bold"><i class="fas fa-filter"></i> Lọc dữ liệu</button>
                        </div>
                    </form>
                 </div>

                <!-- Table -->
                <div class="card shadow-sm border-0">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0" style="font-size: 13px;">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="text-center" style="width: 50px;">STT</th>
                                        <th>Thông tin khách hàng</th>
                                        <th>SDT / Địa chỉ</th>
                                        <th class="text-center text-primary">Ngày vay</th>
                                        <th class="text-center text-danger fw-bold">Ngày phải đóng (Nợ)</th>
                                        <th class="text-end text-primary">Tiền gốc vay</th>
                                        <th class="text-center">Khu vực (Công ty)</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($loans) > 0): ?>
                                        <?php $stt = 1; foreach ($loans as $row): ?>
                                        <tr>
                                            <td class="text-center"><?= $stt++ ?></td>
                                            <td>
                                                <a href="contract_view.php?id=<?php echo $row['id']; ?>"
                                                    class="fw-bold text-decoration-none text-primary"><?php echo $row['customer_name']; ?></a>
                                                <div class="small text-muted">Mã: <?php echo $row['loan_code']; ?></div>
                                            </td>
                                            <td>
                                                <div class="fw-bold"><?php echo $row['phone']; ?></div>
                                                <div class="small text-muted"><?php echo $row['address']; ?></div>
                                            </td>
                                            <td class="text-center"><?php echo date('d/m/Y', strtotime($row['start_date'])); ?></td>
                                            <td class="text-center text-danger fw-bold" style="background: rgba(239, 68, 68, 0.05);">
                                                <?php echo date('d/m/Y', strtotime($row['next_payment_date'])); ?>
                                            </td>
                                            <td class="text-end text-primary fw-bold"><?php echo number_format($row['amount']); ?> đ</td>
                                            <td class="text-center fw-bold text-secondary">
                                                <i class="fas fa-store opacity-50 me-1"></i> <?php echo $row['store_name']; ?>
                                            </td>
                                            <td class="text-center">
                                                <a href="contract_view.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary px-2 py-1"><i class="fas fa-eye"></i> Xem</a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <tr class="bg-light fw-bold">
                                            <td colspan="5" class="text-end">Tổng gốc đang cho vay:</td>
                                            <td class="text-end text-primary"><?php echo number_format(array_sum(array_column($loans, 'amount'))); ?> đ</td>
                                            <td colspan="2"></td>
                                        </tr>
                                    <?php else: ?>
                                        <tr><td colspan="7" class="text-center py-4">Không có khách hàng nào có lịch nợ trong khoảng thời gian này!</td></tr>
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
