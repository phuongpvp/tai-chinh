<nav class="navbar navbar-expand-lg navbar-dark bg-secondary fixed-top" style="z-index: 1040;">
    <div class="container-fluid">
        <!-- Sidebar Toggle (Mobile) -->
        <button class="btn btn-outline-light d-md-none me-2" type="button" data-bs-toggle="collapse"
            data-bs-target="#sidebarMenu">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Brand -->
        <a class="navbar-brand fw-bold" href="/trang-chu">
            <span class="text-warning text-uppercase"
                style="letter-spacing: 1px; font-size: 1.2rem; text-shadow: 1px 1px 2px rgba(0,0,0,0.5);">Trương
                Hưng</span>
        </a>

        <!-- Right Menu -->
        <div class="d-flex align-items-center ms-auto">
            <!-- CV Quick Switch -->
            <a href="/cong-viec/" class="btn btn-sm text-white fw-bold me-3 shadow-sm border-0" 
               style="background: linear-gradient(135deg, #10b981, #059669); border-radius: 6px; padding: 4px 10px;" 
               title="Chuyển sang nền tảng Công Việc">
                <i class="fas fa-exchange-alt"></i> CÔNG VIỆC
            </a>

            <!-- Store Selector Dropdown -->
            <div class="dropdown me-3">
                <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" id="storeSelector"
                    data-bs-toggle="dropdown">
                    <i class="fas fa-building"></i> <?php echo $current_store_name; ?>
                </button>
                <ul class="dropdown-menu" aria-labelledby="storeSelector">
                    <?php if (count($user_stores) > 1): ?>
                        <?php foreach ($user_stores as $store): ?>
                            <li>
                                <a class="dropdown-item <?php echo $store['id'] == $current_store_id ? 'active' : ''; ?>"
                                    href="#"
                                    onclick="switchStore(<?php echo $store['id']; ?>, '<?php echo htmlspecialchars($store['name']); ?>'); return false;">
                                    <?php echo htmlspecialchars($store['name']); ?>
                                    <?php if ($store['id'] == $current_store_id): ?>
                                        <i class="fas fa-check text-success ms-2"></i>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li><span class="dropdown-item text-muted">Chỉ có 1 cửa hàng</span></li>
                    <?php endif; ?>
                </ul>
            </div>
            <div class="text-white me-3 position-relative">
                <a href="/contract_alerts.php" class="text-white text-decoration-none">
                    <i class="fas fa-bell"></i>
                    <!-- Simplified Badge: Ideally this count should be dynamic. 
                         For now, hardcoded or needs a shared counter include. -->
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                        style="font-size: 0.6rem;">!</span>
                </a>
            </div>
            <div class="text-white me-3 position-relative">
                <i class="fas fa-lock"></i>
            </div>
            <div class="text-white me-3">
                <i class="fas fa-user-circle"></i>
            </div>

            <!-- User Dropdown -->
            <div class="dropdown">
                <a class="text-white text-decoration-none dropdown-toggle" href="#" role="button" id="userMenu"
                    data-bs-toggle="dropdown">
                    <?php echo isset($_SESSION['fullname']) ? $_SESSION['fullname'] : 'Admin'; ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userMenu">
                    <li><a class="dropdown-item" href="/dang-xuat">Đăng xuất</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<script>
    function switchStore(storeId, storeName) {
        if (confirm('Chuyển sang cửa hàng: ' + storeName + '?')) {
            fetch('/store_switch.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'store_id=' + storeId
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Reload page to reflect new store
                        window.location.reload();
                    } else {
                        alert('Lỗi: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Lỗi kết nối: ' + error);
                });
        }
    }
</script>