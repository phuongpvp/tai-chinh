<?php
/**
 * Layout Template — Sidebar + Main Content wrapper
 * Include this file in all pages after config.php & requireLogin()
 * 
 * Requires: $pageTitle, $pageIcon (optional), $activePage
 */

$user = $user ?? cvGetUser();
$initials = mb_substr($user['fullname'] ?? $user['full_name'] ?? $user['username'] ?? '?', 0, 1, 'UTF-8');

// Lấy danh sách phòng cho sidebar
$roomsStmt = $pdo->query("SELECT r.*, 
    (SELECT COUNT(*) FROM loans l WHERE l.cv_room_id = r.id AND l.cv_status = 'active' AND l.status != 'closed') as total_customers
    FROM cv_rooms r 
    ORDER BY r.sort_order, r.name");
$sidebarRooms = $roomsStmt->fetchAll();

$roleLabels = [
    'admin' => 'Quản trị viên',
    'manager' => 'Quản lý',
    'employee' => 'Nhân viên',
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle ?? 'Dashboard') ?> — Công Việc</title>
    <link rel="stylesheet" href="/cong-viec/style.css?v=<?= filemtime(__DIR__.'/style.css') ?>">
</head>
<body>
    <div class="app-layout">
        <!-- SIDEBAR -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">🏠</div>
                <span class="sidebar-title">Công Việc</span>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section">
                    <a href="/cong-viec/tong-quan" class="nav-item <?= ($activePage ?? '') === 'dashboard' ? 'active' : '' ?>">
                        <span class="nav-icon">📊</span>
                        <span>Dashboard</span>
                    </a>
                    <a href="/cong-viec/khach-hang" class="nav-item <?= ($activePage ?? '') === 'customers' ? 'active' : '' ?>">
                        <span class="nav-icon">👥</span>
                        <span>Khách hàng</span>
                    </a>
                    <a href="/cong-viec/nhat-ky" class="nav-item <?= ($activePage ?? '') === 'worklog_add' ? 'active' : '' ?>">
                        <span class="nav-icon">📝</span>
                        <span>Nhật ký</span>
                    </a>
                    <a href="/cong-viec/nhat-ky-tong" class="nav-item <?= ($activePage ?? '') === 'logs_all' ? 'active' : '' ?>">
                        <span class="nav-icon">📋</span>
                        <span>Nhật ký tổng</span>
                    </a>
                    <a href="/cong-viec/thu-vien" class="nav-item <?= ($activePage ?? '') === 'library' ? 'active' : '' ?>">
                        <span class="nav-icon">📚</span>
                        <span>Thư viện & HD</span>
                    </a>
                </div>

                <div class="nav-section">
                    <div class="nav-section-title" style="display:flex;justify-content:space-between;align-items:center;cursor:pointer;" onclick="toggleRoomList()">
                        Phòng / Bộ phận
                        <span id="room-toggle-icon" style="font-size:10px;transition:transform 0.2s;">▼</span>
                    </div>
                    <div id="room-list" style="overflow:hidden;transition:max-height 0.3s ease;">
                    <?php foreach ($sidebarRooms as $sidebarRoom): ?>
                        <a href="/cong-viec/phong/<?= $sidebarRoom['id'] ?>" 
                           class="nav-item <?= ($activePage ?? '') === 'room-' . $sidebarRoom['id'] ? 'active' : '' ?>">
                            <span class="nav-icon"><?= $sidebarRoom['icon'] ?></span>
                            <span><?= sanitize($sidebarRoom['name']) ?></span>
                            <?php if ($sidebarRoom['total_customers'] > 0): ?>
                                <span class="nav-badge"><?= $sidebarRoom['total_customers'] ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                    </div>
                </div>
                <script>
                function toggleRoomList() {
                    var el = document.getElementById('room-list');
                    var icon = document.getElementById('room-toggle-icon');
                    var isHidden = el.style.maxHeight === '0px';
                    el.style.maxHeight = isHidden ? el.scrollHeight + 'px' : '0px';
                    icon.style.transform = isHidden ? 'rotate(0deg)' : 'rotate(-90deg)';
                    localStorage.setItem('cv_rooms_visible', isHidden ? '1' : '0');
                }
                (function(){
                    var el = document.getElementById('room-list');
                    var icon = document.getElementById('room-toggle-icon');
                    var visible = localStorage.getItem('cv_rooms_visible');
                    if (visible === '0') {
                        el.style.maxHeight = '0px';
                        icon.style.transform = 'rotate(-90deg)';
                    } else {
                        el.style.maxHeight = el.scrollHeight + 'px';
                    }
                })();
                </script>

                <?php if (in_array($user['role'], ['admin', 'manager'])): ?>
                <div class="nav-section">
                    <div class="nav-section-title">Quản lý</div>
                    <a href="/cong-viec/vi-pham" class="nav-item <?= ($activePage ?? '') === 'violations' ? 'active' : '' ?>">
                        <span class="nav-icon">⚠️</span>
                        <span>Vi phạm</span>
                    </a>
                    <?php if ($user['role'] === 'admin'): ?>
                    <a href="/cong-viec/quan-ly-phong" class="nav-item <?= ($activePage ?? '') === 'rooms_manage' ? 'active' : '' ?>">
                        <span class="nav-icon">🏢</span>
                        <span>Quản lý phòng</span>
                    </a>
                    <a href="/cong-viec/ai_settings.php" class="nav-item <?= ($activePage ?? '') === 'ai_settings' ? 'active' : '' ?>">
                        <span class="nav-icon">🤖</span>
                        <span>Cài đặt AI</span>
                    </a>

                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </nav>

            <div class="sidebar-footer">
                <div class="user-info" onclick="toggleDropdown('user-dropdown')">
                    <div class="user-avatar"><?= $initials ?></div>
                    <div>
                        <div class="user-name"><?= sanitize($user['fullname'] ?? $user['full_name'] ?? $user['username'] ?? '') ?></div>
                        <div class="user-role"><?= $roleLabels[$user['role']] ?? $user['role'] ?></div>
                    </div>
                </div>
                <div class="dropdown-menu" id="user-dropdown" style="bottom:calc(100% + 4px);top:auto;">
                    <a href="logout.php" class="dropdown-item" style="color: var(--accent-red)">🚪 Đăng xuất</a>
                </div>
            </div>
        </aside>

        <!-- MOBILE MENU BUTTON -->
        <button class="mobile-menu-btn" id="mobile-menu-btn" onclick="toggleSidebar()" 
                style="position:fixed;top:12px;left:12px;z-index:99;">☰</button>

        <!-- MAIN CONTENT -->
        <main class="main-content">

        <!-- SEARCH BAR -->
        <div style="position:relative;max-width:400px;margin-bottom:16px;">
            <div style="position:relative;">
                <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:14px;color:var(--text-muted);">🔍</span>
                <input type="text" id="global-search" placeholder="Tìm khách hàng (tên, SĐT)..." autocomplete="off"
                    style="width:100%;padding:8px 12px 8px 36px;background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-md);color:var(--text-primary);font-size:13px;outline:none;transition:border-color 0.2s;"
                    onfocus="this.style.borderColor='var(--accent-blue)'" onblur="setTimeout(()=>{this.style.borderColor='var(--border-color)';document.getElementById('search-results').style.display='none'},200)">
            </div>
            <div id="search-results" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-md);margin-top:4px;max-height:320px;overflow-y:auto;z-index:999;box-shadow:0 8px 24px rgba(0,0,0,0.3);"></div>
        </div>
        <script>
        (function(){
            let timer;
            const input = document.getElementById('global-search');
            const results = document.getElementById('search-results');
            input.addEventListener('input', function(){
                clearTimeout(timer);
                const q = this.value.trim();
                if (q.length < 1) { results.style.display='none'; return; }
                timer = setTimeout(()=>{
                    fetch('/cong-viec/search_api.php?q='+encodeURIComponent(q))
                    .then(r=>r.json())
                    .then(data=>{
                        if (!data.length) {
                            results.innerHTML='<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:13px;">Không tìm thấy khách hàng</div>';
                        } else {
                            results.innerHTML = data.map(c => {
                                const statusBadge = c.status==='completed' ? '<span style="font-size:11px;color:var(--accent-green);">✅</span> ' : '';
                                return `<a href="/cong-viec/khach-hang/${c.id}" style="display:flex;align-items:center;gap:10px;padding:10px 14px;text-decoration:none;color:var(--text-primary);border-bottom:1px solid var(--border-color);transition:background 0.15s;" onmouseenter="this.style.background='var(--bg-card-hover)'" onmouseleave="this.style.background=''">
                                    <div style="flex:1;min-width:0;">
                                        <div style="font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${statusBadge}${c.name}</div>
                                        <div style="font-size:12px;color:var(--text-muted);">${c.phone||''}</div>
                                    </div>
                                    <div style="flex-shrink:0;font-size:12px;color:var(--text-secondary);background:var(--bg-primary);padding:2px 8px;border-radius:var(--radius-sm);">
                                        ${c.room_icon||''} ${c.room_name||''}
                                    </div>
                                </a>`;
                            }).join('');
                        }
                        results.style.display='block';
                    });
                }, 250);
            });
            input.addEventListener('keydown', function(e){
                if(e.key==='Escape'){results.style.display='none';this.blur();}
            });
        })();
        </script>
