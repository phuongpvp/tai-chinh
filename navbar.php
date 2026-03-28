<!-- Navbar included in pages -->
<link rel="stylesheet" href="style.css">
<nav class="navbar navbar-expand-lg navbar-custom mb-3">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php" style="font-weight:bold; color:#d9534f;"> <i class="fas fa-home"></i> Trang chủ</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="contracts.php">Tín Chấp</a></li>
                <li class="nav-item"><a class="nav-link" href="contracts.php?type=cam_do">Cầm Đồ</a></li>
                <li class="nav-item"><a class="nav-link" href="customers.php">Khách hàng</a></li>
                <li class="nav-item"><a class="nav-link" href="cashbook.php">Sổ quỹ</a></li>
            </ul>
            <span class="navbar-text me-3" style="font-size: 12px;">
                <i class="fas fa-user"></i> <?php echo isset($_SESSION['fullname']) ? $_SESSION['fullname'] : 'Admin'; ?>
            </span>
            <a href="logout.php" class="btn btn-sm btn-outline-secondary">Thoát</a>
        </div>
    </div>
</nav>
