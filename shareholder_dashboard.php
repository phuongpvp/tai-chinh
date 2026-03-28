<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';
require_once 'permissions_helper.php';

// Check permission
if (!hasPermission($conn, 'shareholders.view')) {
    die("Bạn không có quyền xem trang này!");
}

// Only show this page when at default store (store_id = 1)
if ($current_store_id != 1) {
    die("Trang này chỉ hiển thị ở cửa hàng Trương Hưng!");
}

// Get all stores (except default store)
$stmt = $conn->prepare("SELECT id, name FROM stores WHERE id != 1 ORDER BY id ASC");
$stmt->execute();
$stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get shareholders data for each store
$store_data = [];
foreach ($stores as $store) {
    $stmt = $conn->prepare("
        SELECT 
            shareholder_code,
            name,
            capital_amount,
            percentage
        FROM shareholders 
        WHERE store_id = ?
        ORDER BY percentage DESC
    ");
    $stmt->execute([$store['id']]);
    $shareholders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($shareholders) > 0) {
        $store_data[] = [
            'store_id' => $store['id'],
            'store_name' => $store['name'],
            'shareholders' => $shareholders,
            'total_capital' => array_sum(array_column($shareholders, 'capital_amount'))
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Dashboard Cổ đông - Trương Hưng</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>

<body>

    <?php include 'header.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content" style="padding-top: 80px !important;">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="index.php">Trang chủ</a></li>
                        <li class="breadcrumb-item"><a href="shareholders.php">Cổ đông</a></li>
                        <li class="breadcrumb-item active">Dashboard</li>
                    </ol>
                </nav>

                <h4 class="mb-3 border-bottom pb-2">
                    <i class="fas fa-chart-pie"></i> Dashboard Cổ đông - Tỷ lệ vốn góp
                </h4>

                <?php if (count($store_data) == 0): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        Chưa có dữ liệu cổ đông ở các cửa hàng. Vui lòng thêm cổ đông trước.
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($store_data as $data): ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="card shadow-sm h-100">
                                    <div class="card-header bg-primary text-white">
                                        <h6 class="mb-0">
                                            <i class="fas fa-store"></i>
                                            <?php echo htmlspecialchars($data['store_name']); ?>
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <!-- Chart Canvas -->
                                        <div class="mb-3" style="position: relative; height: 250px;">
                                            <canvas id="chart_<?php echo $data['store_id']; ?>"></canvas>
                                        </div>

                                        <!-- Legend -->
                                        <div class="mt-3">
                                            <h6 class="text-muted mb-2">Chi tiết:</h6>
                                            <table class="table table-sm table-borderless">
                                                <?php foreach ($data['shareholders'] as $sh): ?>
                                                    <tr>
                                                        <td class="fw-bold" style="width: 40px;">
                                                            <?php echo htmlspecialchars($sh['shareholder_code']); ?>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($sh['name']); ?>
                                                        </td>
                                                        <td class="text-end fw-bold text-success">
                                                            <?php echo number_format($sh['percentage'], 1); ?>%
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </table>
                                            <div class="border-top pt-2 mt-2">
                                                <strong>Tổng vốn:</strong>
                                                <span class="text-primary float-end">
                                                    <?php echo number_format($data['total_capital']); ?> đ
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script
        src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
    <script>
        // Color palette
        const colors = [
            '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF',
            '#FF9F40', '#FF6384', '#C9CBCF', '#4BC0C0', '#FF6384'
        ];

        // Create charts
        <?php foreach ($store_data as $data): ?>
            {
                const ctx = document.getElementById('chart_<?php echo $data['store_id']; ?>');
                const shareholderNames = [
                    <?php foreach ($data['shareholders'] as $sh): ?>
                                        '<?php echo addslashes($sh['name']); ?>',
                    <?php endforeach; ?>
                ];
                const data = {
                    labels: [
                        <?php foreach ($data['shareholders'] as $sh): ?>
                                                    '<?php echo addslashes($sh['shareholder_code'] . ' - ' . $sh['name']); ?>',
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                        data: [
                            <?php foreach ($data['shareholders'] as $sh): ?>
                                                                <?php echo $sh['percentage']; ?>,
                            <?php endforeach; ?>
                        ],
                        backgroundColor: colors.slice(0, <?php echo count($data['shareholders']); ?>),
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                };

                new Chart(ctx, {
                    type: 'pie',
                    plugins: [ChartDataLabels],
                    data: data,
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function (context) {
                                        return context.label + ': ' + context.parsed.toFixed(1) + '%';
                                    }
                                }
                            },
                            datalabels: {
                                color: '#fff',
                                font: {
                                    weight: 'bold',
                                    size: 14
                                },
                                formatter: function (value, context) {
                                    const name = shareholderNames[context.dataIndex];
                                    return name + '\n(' + value.toFixed(1) + '%)';
                                }
                            }
                        }
                    }
                });
            }
        <?php endforeach; ?>
    </script>
</body>

</html>