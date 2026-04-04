<?php
session_start();
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trang chủ - Trương Hưng</title>
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="/style.css">
    <style>
        /* Custom Brand Text in Navbar - minimal override if needed */
        .custom-brand-text {
            font-size: 14px;
            opacity: 0.8;
        }
    </style>
</head>

<body>

    <!-- Top Header -->
    <?php include 'header.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include 'sidebar.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="pt-3 pb-2 mb-3 text-center">
                    <h2 class="fw-bold mb-3" style="color: #444;">Chào mừng bạn đến với Trương Hưng</h2>
                    <h4 class="text-secondary fw-normal">Chúc bạn một ngày làm việc vui vẻ và tràn đầy năng lượng !</h4>
                </div>

                <div class="card shadow-sm border-0 mt-4">
                    <div class="card-body" style="font-size: 0.9rem; line-height: 1.6;">
                        <h6 class="card-title fw-bold text-muted mb-3">Quý khách hàng khi đăng ký và sử dụng phần mềm
                            cam kết:</h6>

                        <p class="mb-2">
                            • <strong>Tuân thủ pháp luật:</strong> Không sử dụng phần mềm cho các hành vi vi phạm pháp
                            luật,
                            trái đạo đức hoặc thuần phong mỹ tục Việt Nam. Lãi suất cho vay ≥100%/năm (tương đương
                            khoảng 0.273%/ngày)
                            là vi phạm pháp luật, có thể bị truy cứu trách nhiệm hình sự theo
                            <span class="text-danger fw-bold">Điều 201 Bộ luật Hình sự</span>.
                            Quý khách hàng cần đảm bảo lãi suất áp dụng tuân thủ quy định pháp luật, đặc biệt cần tìm
                            hiểu và tuân thủ
                            <strong>Điều 468 Bộ Luật Dân Sự 2015</strong> về lãi suất vay.
                        </p>

                        <p class="mb-0">
                            • <strong>Quản lý tài khoản và trách nhiệm:</strong> Khách hàng cần tìm hiểu, nghiên cứu các
                            quy định của pháp luật
                            có liên quan đến hoạt động kinh doanh của mình hoặc có thể sử dụng các dịch vụ pháp lý để
                            bảo đảm rằng
                            Khách hàng có đầy đủ hiểu biết về ngành nghề mình đang kinh doanh để thực hiện các hành vi
                            phù hợp quy định
                            của pháp luật. Quý Khách Hàng tự chịu trách nhiệm quản lý nội dung, thông tin và hoạt động
                            trên tài khoản.
                            Đảm bảo các thông tin, phí dịch vụ, lãi suất, và mọi hoạt động liên quan tuân thủ pháp luật
                            hiện hành,
                            đồng thời chịu hoàn toàn trách nhiệm về bất kỳ vi phạm nào.
                        </p>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle Sidebar if needed via JS (Bootstrap collapse handles it mainly)
    </script>
</body>

</html>