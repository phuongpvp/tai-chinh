<?php
// Include permissions helper at the top
if (file_exists('permissions_helper.php')) {
    require_once 'permissions_helper.php';
}
?>
<nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block bg-white sidebar collapse border-end"
    style="top: 56px; position: fixed; bottom: 0; overflow-y: auto; padding-top: 20px;">
    <div class="position-sticky">
        <ul class="nav flex-column">
            <!-- TÍN CHẤP -->
            <?php if (!function_exists('hasPermission') || hasPermission($conn, 'contracts.list')): ?>
                <li class="nav-item">
                    <a class="nav-link text-secondary fw-bold" href="/hop-dong">
                        <i class="fas fa-folder me-2"></i> TÍN CHẤP
                    </a>
                </li>
            <?php endif; ?>

            <!-- DANH SÁCH KHÁCH HÀNG -->
            <?php if (!function_exists('hasPermission') || hasPermission($conn, 'customers.view')): ?>
                <li class="nav-item">
                    <a class="nav-link text-secondary fw-bold" href="/khach-hang">
                        <i class="fas fa-address-book me-2"></i> DANH SÁCH KHÁCH HÀNG
                    </a>
                </li>
            <?php endif; ?>

            <!-- QUẢN LÝ CỬA HÀNG -->
            <?php if (!function_exists('hasPermission') || hasPermission($conn, 'stores.view')): ?>
                <li class="nav-item">
                    <a class="nav-link text-secondary fw-bold" href="/cua-hang">
                        <i class="fas fa-store me-2"></i> QUẢN LÝ CỬA HÀNG
                    </a>
                </li>
            <?php endif; ?>

            <!-- QUẢN LÝ THU CHI -->
            <?php
            $thuchi_pages = ['expenses.php', 'incomes.php'];
            $is_thuchi_page = in_array(basename($_SERVER['PHP_SELF']), $thuchi_pages);
            $can_view_expenses = !function_exists('hasPermission') || hasPermission($conn, 'expenses.view');
            $can_view_incomes = !function_exists('hasPermission') || hasPermission($conn, 'incomes.view');
            ?>
            <?php if ($can_view_expenses || $can_view_incomes): ?>
                <li class="nav-item">
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 text-secondary fw-bold nav-link-collapse-trigger <?php echo $is_thuchi_page ? '' : 'collapsed'; ?>"
                        data-bs-toggle="collapse" data-bs-target="#thuChiSubmenu" style="cursor: pointer;"
                        aria-expanded="<?php echo $is_thuchi_page ? 'true' : 'false'; ?>">
                        <span><i class="fas fa-desktop me-2"></i> QUẢN LÝ THU CHI</span>
                        <i class="fas fa-chevron-down text-muted" style="font-size: 0.8rem;"></i>
                    </div>
                    <div class="collapse <?php echo $is_thuchi_page ? 'show' : ''; ?>" id="thuChiSubmenu">
                        <ul class="nav flex-column ms-3">
                            <?php if ($can_view_expenses): ?>
                                <li class="nav-item">
                                    <a class="nav-link text-secondary py-1 <?php echo (basename($_SERVER['PHP_SELF']) == 'expenses.php') ? 'text-primary fw-bold' : ''; ?>"
                                        href="/chi-hoat-dong">
                                        <i class="fas fa-angle-right me-2"></i> Chi hoạt động
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if ($can_view_incomes): ?>
                                <li class="nav-item">
                                    <a class="nav-link text-secondary py-1 <?php echo (basename($_SERVER['PHP_SELF']) == 'incomes.php') ? 'text-primary fw-bold' : ''; ?>"
                                        href="/thu-hoat-dong">
                                        <i class="fas fa-angle-right me-2"></i> Thu hoạt động
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </li>
            <?php endif; ?>

            <!-- QUẢN LÝ NGUỒN VỐN -->
            <?php if (!function_exists('hasPermission') || hasPermission($conn, 'capital.view')): ?>
                <li class="nav-item">
                    <a class="nav-link text-secondary fw-bold <?php echo (basename($_SERVER['PHP_SELF']) == 'capital_management.php') ? 'active text-primary' : ''; ?>"
                        href="/nguon-von">
                        <i class="fas fa-desktop me-2"></i> QUẢN LÝ NGUỒN VỐN
                    </a>
                </li>
            <?php endif; ?>

            <!-- QUẢN LÝ CỔ ĐÔNG -->
            <?php
            $shareholder_pages = ['shareholder_dashboard.php', 'shareholders.php', 'dividend_distribution.php', 'dividend_distribution_multi.php', 'dividend_report.php'];
            $is_shareholder_page = in_array(basename($_SERVER['PHP_SELF']), $shareholder_pages);
            $can_view_shareholders = !function_exists('hasPermission') || hasPermission($conn, 'shareholders.view');
            $can_distribute = !function_exists('hasPermission') || hasPermission($conn, 'dividends.distribute');
            ?>
            <?php if ($can_view_shareholders || $can_distribute): ?>
                <li class="nav-item">
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 text-secondary fw-bold nav-link-collapse-trigger <?php echo $is_shareholder_page ? '' : 'collapsed'; ?>"
                        data-bs-toggle="collapse" data-bs-target="#coDongSubmenu" style="cursor: pointer;"
                        aria-expanded="<?php echo $is_shareholder_page ? 'true' : 'false'; ?>">
                        <span><i class="fas fa-users me-2"></i> QUẢN LÝ CỔ ĐÔNG</span>
                        <i class="fas fa-chevron-down text-muted" style="font-size: 0.8rem;"></i>
                    </div>
                    <div class="collapse <?php echo $is_shareholder_page ? 'show' : ''; ?>" id="coDongSubmenu">
                        <ul class="nav flex-column ms-3">
                            <?php if ($can_view_shareholders): ?>
                                <li class="nav-item">
                                    <a class="nav-link text-secondary py-1 <?php echo (basename($_SERVER['PHP_SELF']) == 'shareholder_dashboard.php') ? 'text-primary fw-bold' : ''; ?>"
                                        href="/co-dong">
                                        <i class="fas fa-angle-right me-2"></i> Dashboard
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link text-secondary py-1 <?php echo (basename($_SERVER['PHP_SELF']) == 'shareholders.php') ? 'text-primary fw-bold' : ''; ?>"
                                        href="/co-dong/danh-sach">
                                        <i class="fas fa-angle-right me-2"></i> Danh sách cổ đông
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if ($can_distribute): ?>
                                <li class="nav-item">
                                    <a class="nav-link text-secondary py-1 <?php echo (basename($_SERVER['PHP_SELF']) == 'dividend_distribution.php') ? 'text-primary fw-bold' : ''; ?>"
                                        href="/co-dong/chia-co-tuc">
                                        <i class="fas fa-angle-right me-2"></i> Chia cổ tức
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link text-secondary py-1 <?php echo (basename($_SERVER['PHP_SELF']) == 'dividend_distribution_multi.php') ? 'text-primary fw-bold' : ''; ?>"
                                        href="/co-dong/chia-co-tuc-nhieu">
                                        <i class="fas fa-angle-right me-2"></i> Chia cổ tức đồng loạt
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link text-secondary py-1 <?php echo (basename($_SERVER['PHP_SELF']) == 'dividend_report.php') ? 'text-primary fw-bold' : ''; ?>"
                                        href="/co-dong/bao-cao">
                                        <i class="fas fa-angle-right me-2"></i> Báo cáo tổng hợp
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </li>
            <?php endif; ?>

            <!-- QUẢN LÝ NHÂN VIÊN -->
            <?php
            $is_nhanvien_page = in_array(basename($_SERVER['PHP_SELF']), ['users.php', 'user_add.php', 'user_edit.php', 'user_permissions.php']);
            ?>
            <?php if (!function_exists('hasPermission') || hasPermission($conn, 'users.view')): ?>
                <li class="nav-item">
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 text-secondary fw-bold nav-link-collapse-trigger <?php echo $is_nhanvien_page ? '' : 'collapsed'; ?>"
                        data-bs-toggle="collapse" data-bs-target="#nhanVienSubmenu" style="cursor: pointer;"
                        aria-expanded="<?php echo $is_nhanvien_page ? 'true' : 'false'; ?>">
                        <span><i class="fas fa-desktop me-2"></i> QUẢN LÝ NHÂN VIÊN</span>
                        <i class="fas fa-chevron-down text-muted" style="font-size: 0.8rem;"></i>
                    </div>
                    <div class="collapse <?php echo $is_nhanvien_page ? 'show' : ''; ?>" id="nhanVienSubmenu">
                        <ul class="nav flex-column ms-3">
                            <li class="nav-item">
                                <a class="nav-link text-secondary py-1 <?php echo (basename($_SERVER['PHP_SELF']) == 'users.php') ? 'text-primary fw-bold' : ''; ?>"
                                    href="/nhan-vien">
                                    <i class="fas fa-angle-right me-2"></i> Danh sách nhân viên
                                </a>
                            </li>

                        </ul>
                    </div>
                </li>
            <?php endif; ?>



            <!-- BÁO CÁO -->
            <?php
            $report_pages = ['report_cash_book.php', 'report_profit.php', 'report_interest_detail.php', 'report_loans.php'];
            $is_report_page = in_array(basename($_SERVER['PHP_SELF']), $report_pages);
            $can_view_any_report = !function_exists('hasAnyPermission') || hasAnyPermission($conn, [
                'reports.cash_book',
                'reports.transactions',
                'reports.profit',
                'reports.interest_detail',
                'reports.loans',
                'reports.inventory',
                'reports.closed_contracts'
            ]);
            ?>
            <?php if ($can_view_any_report): ?>
                <li class="nav-item">
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 text-secondary fw-bold nav-link-collapse-trigger <?php echo $is_report_page ? '' : 'collapsed'; ?>"
                        data-bs-toggle="collapse" data-bs-target="#baoCaoSubmenu" style="cursor: pointer;"
                        aria-expanded="<?php echo $is_report_page ? 'true' : 'false'; ?>">
                        <span><i class="fas fa-file-alt me-2"></i> BÁO CÁO</span>
                        <i class="fas fa-chevron-down text-muted" style="font-size: 0.8rem;"></i>
                    </div>
                    <div class="collapse <?php echo $is_report_page ? 'show' : ''; ?>" id="baoCaoSubmenu">
                        <ul class="nav flex-column ms-3">
                            <?php if (!function_exists('hasPermission') || hasPermission($conn, 'reports.cash_book')): ?>
                                <li class="nav-item">
                                    <a class="nav-link text-secondary py-1 <?php echo (basename($_SERVER['PHP_SELF']) == 'report_cash_book.php') ? 'text-primary fw-bold' : ''; ?>"
                                        href="/bao-cao/so-quy">
                                        <i class="fas fa-angle-right me-2"></i> Sổ quỹ tiền mặt
                                    </a>
                                </li>
                            <?php endif; ?>
                            <li class="nav-item">
                                <a class="nav-link text-secondary py-1 <?php echo (basename($_SERVER['PHP_SELF']) == 'report_transactions.php') ? 'text-primary fw-bold' : ''; ?>"
                                    href="/bao-cao/tong-ket-giao-dich">
                                    <i class="fas fa-angle-right me-2"></i> Tổng kết giao dịch
                                </a>
                            </li>

                            <?php if (!function_exists('hasPermission') || hasPermission($conn, 'reports.profit')): ?>
                                <li class="nav-item">
                                    <a class="nav-link text-secondary py-1 <?php echo (basename($_SERVER['PHP_SELF']) == 'report_profit.php') ? 'text-primary fw-bold' : ''; ?>"
                                        href="/bao-cao/loi-nhuan">
                                        <i class="fas fa-angle-right me-2"></i> Tổng kết lợi nhuận
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if (!function_exists('hasPermission') || hasPermission($conn, 'reports.interest_detail')): ?>
                                <li class="nav-item">
                                    <a class="nav-link text-secondary py-1 <?php echo (basename($_SERVER['PHP_SELF']) == 'report_interest_detail.php') ? 'text-primary fw-bold' : ''; ?>"
                                        href="/bao-cao/chi-tiet-lai">
                                        <i class="fas fa-angle-right me-2"></i> Chi tiết tiền lãi
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if (!function_exists('hasPermission') || hasPermission($conn, 'reports.loans')): ?>
                                <li class="nav-item">
                                    <a class="nav-link text-secondary py-1 <?php echo (basename($_SERVER['PHP_SELF']) == 'report_loans.php') ? 'text-primary fw-bold' : ''; ?>"
                                        href="/bao-cao/dang-cho-vay">
                                        <i class="fas fa-angle-right me-2"></i> Báo cáo đang cho vay
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </li>
            <?php endif; ?>

            <!-- CÀI ĐẶT HỆ THỐNG -->
            <?php
            $settings_pages = ['settings.php', 'sheets_sync_manager.php', 'telegram_manager.php'];
            $is_settings_page = in_array(basename($_SERVER['PHP_SELF']), $settings_pages);
            ?>
            <?php if (!function_exists('hasPermission') || hasPermission($conn, 'system.manage')): ?>
                <li class="nav-item">
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 text-secondary fw-bold nav-link-collapse-trigger <?php echo $is_settings_page ? '' : 'collapsed'; ?>"
                        data-bs-toggle="collapse" data-bs-target="#settingsSubmenu" style="cursor: pointer;"
                        aria-expanded="<?php echo $is_settings_page ? 'true' : 'false'; ?>">
                        <span><i class="fas fa-cog me-2"></i> CÀI ĐẶT</span>
                        <i class="fas fa-chevron-down text-muted" style="font-size: 0.8rem;"></i>
                    </div>
                    <div class="collapse <?php echo $is_settings_page ? 'show' : ''; ?>" id="settingsSubmenu">
                        <ul class="nav flex-column ms-3">
                            <li class="nav-item">
                                <a class="nav-link text-secondary py-1 <?php echo (basename($_SERVER['PHP_SELF']) == 'telegram_manager.php') ? 'text-primary fw-bold' : ''; ?>"
                                    href="/cai-dat/telegram">
                                    <i class="fas fa-angle-right me-2"></i> Telegram Bot
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>
            <?php endif; ?>
        </ul>

        <!-- Support Info -->
        <div class="mt-5 px-3">
            <div class="alert alert-light border rounded">
                <small class="text-muted d-block mb-1">Hỗ trợ:</small>
                <div class="fw-bold text-primary"><i class="fab fa-telegram-plane"></i> +84789195618</div>
            </div>
        </div>

        <!-- Theme Toggle -->
        <div class="px-3 mt-3">
            <div class="btn-group w-100" role="group">
                <button type="button" class="btn btn-outline-secondary btn-sm active"><i class="fas fa-sun"></i>
                    Bright</button>
                <button type="button" class="btn btn-outline-secondary btn-sm"><i class="fas fa-moon"></i> Dark</button>
            </div>
        </div>
    </div>
</nav>