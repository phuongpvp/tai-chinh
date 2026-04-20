<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';
require_once 'SimpleXLSXGen.php';

// Filter by stores mapping
$stmt = $conn->prepare("SELECT id, name FROM stores ORDER BY id ASC");
$stmt->execute();
$stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $store_id = $_GET['store_id'] ?? 'all';
    
    $where_store_loans = "";
    $where_store_customers = "";
    $params = [];
    if ($store_id !== 'all') {
        $where_store_loans = " AND l.store_id = ? ";
        $where_store_customers = " AND c.store_id = ? ";
        $params[] = $store_id;
    }

    $exportData = [];
    $filename = "export.xlsx";

    try {
        if ($action == 'task1_4') {
            // 1 & 4: Xuất CCCD nợ xấu (Tra số ĐT / tra hộ khẩu)
            $sql = "SELECT c.id, c.name, c.identity_card, c.phone, c.address, s.name as store_name
                    FROM loans l
                    JOIN customers c ON l.customer_id = c.id
                    LEFT JOIN stores s ON l.store_id = s.id
                    WHERE l.status = 'bad_debt'
                    AND LENGTH(TRIM(c.identity_card)) = 12
                    $where_store_loans
                    GROUP BY c.id";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $exportData[] = ['Mã KH', 'Tên Khách Hàng', 'CCCD/CMND', 'Số Điện Thoại', 'Địa Chỉ', 'Trạng Thái', 'Công Ty'];
            foreach ($rows as $r) {
                $exportData[] = [$r['id'], $r['name'], $r['identity_card'], $r['phone'], $r['address'], 'Nợ xấu', $r['store_name']];
            }
            $filename = "CCCD_Khach_No_Xau_" . date('d_m_Y') . ".xlsx";

        } elseif ($action == 'task2') {
            // 2: Số Vina nợ xấu
            $sql = "SELECT c.id, c.name, c.phone, c.identity_card, c.address, s.name as store_name
                    FROM loans l
                    JOIN customers c ON l.customer_id = c.id
                    LEFT JOIN stores s ON l.store_id = s.id
                    WHERE l.status = 'bad_debt'
                    AND c.phone REGEXP '^(091|094|081|082|083|084|085|088|8491|8494)'
                    $where_store_loans
                    GROUP BY c.id";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $exportData[] = ['Mã KH', 'Tên Khách Hàng', 'CCCD/CMND', 'Số Vina', 'Địa Chỉ', 'Trạng Thái', 'Công Ty'];
            foreach ($rows as $r) {
                $exportData[] = [$r['id'], $r['name'], $r['identity_card'], $r['phone'], $r['address'], 'Nợ xấu', $r['store_name']];
            }
            $filename = "Vina_NoXau_" . date('d_m_Y') . ".xlsx";

        } elseif ($action == 'task3') {
            // 3: Khách có CMND 9 số
            $sql = "SELECT c.id, c.name, c.phone, c.identity_card, c.address, s.name as store_name
                    FROM customers c
                    LEFT JOIN stores s ON c.store_id = s.id
                    WHERE LENGTH(c.identity_card) = 9
                    $where_store_customers";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $exportData[] = ['Mã KH', 'Tên Khách Hàng', 'CMND (9 số)', 'Số Điện Thoại', 'Địa Chỉ', 'Công Ty'];
            foreach ($rows as $r) {
                $exportData[] = [$r['id'], $r['name'], $r['identity_card'], $r['phone'], $r['address'], $r['store_name']];
            }
            $filename = "Khach_CMND_9so_" . date('d_m_Y') . ".xlsx";
        }

        if (count($exportData) > 1) { 
            // Có dữ liệu thật sự (lớn hơn 1 dòng Header)
            $xlsx = SimpleXLSXGen::fromArray($exportData);
            $xlsx->downloadAs($filename);
            exit();
        } else {
            $error_msg = "Không có dữ liệu thỏa mãn điều kiện để xuất Excel!";
        }
    } catch (Exception $e) {
        $error_msg = "Lỗi hệ thống khi tải data: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Xuất File Dữ Liệu Khách Hàng - Công Việc</title>
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
                    <h1 class="h5"><i class="fas fa-file-excel me-1 text-success"></i> Công cụ Xuất File Xử Lý Số Liệu</h1>
                </div>

                <?php if (isset($error_msg)): ?>
                    <div class="alert alert-warning alert-dismissible fade show shadow-sm" role="alert">
                        <i class="fas fa-exclamation-triangle"></i> <?php echo $error_msg; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Filter Bar -->
                 <div class="card p-3 mb-4 bg-white border shadow-sm">
                    <form class="row g-2" method="GET" id="filterForm">
                        <div class="col-md-4">
                            <label class="fw-bold small mb-1">Lọc theo Công Ty</label>
                            <select name="store_id" class="form-select form-select-sm" id="storeSelect">
                                <option value="all">Tất cả Công ty</option>
                                <?php foreach ($stores as $store): ?>
                                    <option value="<?php echo $store['id']; ?>" <?php echo ((isset($_GET['store_id']) && $_GET['store_id'] == $store['id']) ? 'selected' : ''); ?>>
                                        <?php echo htmlspecialchars($store['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                 </div>

                <!-- Export Options -->
                <div class="row row-cols-1 row-cols-md-3 g-4">
                    <!-- Task 1 & 4 -->
                    <div class="col">
                        <div class="card h-100 shadow-sm border-0">
                            <div class="card-body text-center">
                                <div class="mb-3 mt-2">
                                    <i class="fas fa-id-card fa-3x text-primary opacity-75"></i>
                                </div>
                                <h6 class="card-title fw-bold">Xuất CCCD Khách Nợ Xấu</h6>
                                <p class="card-text small text-muted">Bao gồm Danh sách CCCD/CMND của khách bị đánh dấu nợ xấu để tra cứu SĐT hoặc Hộ Khẩu.</p>
                            </div>
                            <div class="card-footer bg-white border-0 text-center pb-4">
                                <button type="button" onclick="exportData('task1_4')" class="btn btn-primary btn-sm px-4 fw-bold shadow-sm">
                                    <i class="fas fa-download me-1"></i> Tải file Excel
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Task 2 -->
                    <div class="col">
                        <div class="card h-100 shadow-sm border-0">
                            <div class="card-body text-center">
                                <div class="mb-3 mt-2">
                                    <i class="fas fa-sim-card fa-3x text-success opacity-75"></i>
                                </div>
                                <h6 class="card-title fw-bold">Xuất Số Vina Nợ Xấu (Không CCCD)</h6>
                                <p class="card-text small text-muted">Lọc các khách hàng Nợ Xấu, chưa có khai báo CCCD và sử dụng các đầu số của mạng Vinaphone.</p>
                            </div>
                            <div class="card-footer bg-white border-0 text-center pb-4">
                                <button type="button" onclick="exportData('task2')" class="btn btn-success btn-sm px-4 fw-bold shadow-sm">
                                    <i class="fas fa-download me-1"></i> Tải file Excel
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Task 3 -->
                    <div class="col">
                        <div class="card h-100 shadow-sm border-0">
                            <div class="card-body text-center">
                                <div class="mb-3 mt-2">
                                    <i class="fas fa-search fa-3x text-warning opacity-75"></i>
                                </div>
                                <h6 class="card-title fw-bold">Xuất Khách Có CMND Cũ (9 Số)</h6>
                                <p class="card-text small text-muted">Danh sách khách hàng đang sử dụng định dạng CMND 9 số cũ để đi tra cứu đổi dữ liệu CCCD mới.</p>
                            </div>
                            <div class="card-footer bg-white border-0 text-center pb-4">
                                <button type="button" onclick="exportData('task3')" class="btn btn-warning btn-sm text-dark px-4 fw-bold shadow-sm">
                                    <i class="fas fa-download me-1"></i> Tải file Excel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function exportData(action) {
            const storeId = document.getElementById('storeSelect').value;
            window.location.href = 'export_tasks.php?action=' + action + '&store_id=' + storeId;
        }

        // Tự động reload trang để giữ URL state (tùy chọn UI)
        document.getElementById('storeSelect').addEventListener('change', function() {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('store_id', this.value);
            urlParams.delete('action'); // Xoá action đi để không tự tải file khi vừa đổi combobox
            window.location.search = urlParams.toString();
        });
    </script>
</body>
</html>
