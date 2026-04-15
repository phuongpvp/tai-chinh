<?php
require_once 'config.php';
requireLogin();
requireRole(['admin']);

$pageTitle = 'Bảng màu Kết quả';
$activePage = 'settings';
$user = cvGetUser();

// 1. Lấy tất cả tên Kết quả từ các phòng
$allResults = [];
$cfgStmt = $pdo->query("SELECT id, name, worklog_config FROM cv_rooms WHERE is_archive = 0");
while ($row = $cfgStmt->fetch(PDO::FETCH_ASSOC)) {
    $config = json_decode($row['worklog_config'] ?: '[]', true) ?: [];
    foreach ($config as $action) {
        if (!empty($action['results']) && is_array($action['results'])) {
            foreach ($action['results'] as $res) {
                // $res có thể là string hoặc array
                $resName = is_string($res) ? $res : ($res['label'] ?? '');
                $resName = trim($resName);
                if ($resName !== '') {
                    if (!isset($allResults[$resName])) {
                        $allResults[$resName] = [];
                    }
                    $allResults[$resName][] = $row['name'];
                }
            }
        }
    }
}

// 2. Load mảng màu hiện tại từ system_settings
$colorSettings = [];
$setStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'cv_result_colors'");
$setStmt->execute();
$setRow = $setStmt->fetch(PDO::FETCH_ASSOC);
if ($setRow && !empty($setRow['setting_value'])) {
    $colorSettings = json_decode($setRow['setting_value'], true) ?: [];
}

// 3. Xử lý lưu mảng màu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_colors') {
    $updatedColors = [];
    $bgColors = $_POST['bg_color'] ?? [];
    $fgColors = $_POST['fg_color'] ?? [];
    $keys = $_POST['result_key'] ?? [];
    
    foreach ($keys as $i => $k) {
        $keyTitle = trim($k);
        if ($keyTitle !== '') {
            $updatedColors[$keyTitle] = [
                'bg' => $bgColors[$i] ?? 'rgba(245,166,35,0.15)',
                'color' => $fgColors[$i] ?? '#f5a623'
            ];
        }
    }
    
    // Lưu vào DB (nếu chưa có key thì insert, có rồi thì update)
    $jsonVal = json_encode($updatedColors, JSON_UNESCAPED_UNICODE);
    if ($setRow === false) {
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('cv_result_colors', ?)");
        $stmt->execute([$jsonVal]);
    } else {
        $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'cv_result_colors'");
        $stmt->execute([$jsonVal]);
    }
    
    $_SESSION['flash_message'] = '✅ Đã lưu cấu hình bảng màu thành công!';
    redirect('/cong-viec/color_settings.php');
}

// Hàm sinh mã màu Hex sang Rgba (opacity 0.15 cho mượt)
function defaultHexToRgba($hex) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) == 3) {
        $r = hexdec(substr($hex,0,1).substr($hex,0,1));
        $g = hexdec(substr($hex,1,1).substr($hex,1,1));
        $b = hexdec(substr($hex,2,1).substr($hex,2,1));
    } else {
        $r = hexdec(substr($hex,0,2));
        $g = hexdec(substr($hex,2,2));
        $b = hexdec(substr($hex,4,2));
    }
    return "rgba($r, $g, $b, 0.15)";
}

include 'layout_top.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><span class="page-icon">🎨</span> Bảng màu Kết quả</h1>
        
    </div>
</div>

<div class="page-body">
    <div style="background:var(--bg-card); border-radius:var(--radius-lg); padding:24px; border:1px solid var(--border-color);">
        <form method="POST">
            <input type="hidden" name="action" value="save_colors">
            
            
            <?php if (empty($allResults)): ?>
                <div class="empty-state">Chưa có kết quả nào được cấu hình ở bất kỳ phòng ban nào.</div>
            <?php else: ?>
                <table style="width:100%; border-collapse:collapse; font-size:14px; text-align:left;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border-color);">
                            <th style="padding:12px;width:35%;">Tên Kết Quả</th>
                            <th style="padding:12px;width:30%;">Cấu hình màu</th>
                            <th style="padding:12px;">Bản xem trước (Preview)</th>
                            <th style="padding:12px;width:20%;">Xuất hiện tại</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $idx = 0;
                        foreach ($allResults as $resName => $roomsList): 
                            $idx++;
                            $c = $colorSettings[$resName] ?? null;
                            $fgColor = $c ? $c['color'] : '#f5a623';
                            $bgColor = $c ? $c['bg'] : 'rgba(245,166,35,0.15)';
                            
                            // Nếu bg đang lưu dạng rgba, ta lấy mã màu gốc (nếu được) để đổ vào thẻ input color.
                            // Vì HTML color input chỉ hỗ trợ định dạng #RRGGBB. Ở đây ta dùng 1 biến phụ chứa hex cho input bg nếu muốn,
                            // hoặc đơn giản là ta cho phép người dùng pick Foreground Color (chữ), rồi tự động gen Background là opacity của FG.
                            // Cách thông minh nhất: Chỉ cho chọn Mã màu (Text/Border), Background sẽ tự nội suy rgba( ..., 0.15 ).
                        ?>
                        <tr style="border-bottom:1px solid var(--border-color); background: <?= $idx%2==0 ? 'var(--bg-elevated)' : 'transparent' ?>;">
                            <td style="padding:12px; font-weight:600; color:var(--text-primary);">
                                <input type="hidden" name="result_key[]" value="<?= sanitize($resName) ?>">
                                <?= sanitize($resName) ?>
                            </td>
                            
                            <td style="padding:12px;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <input type="color" id="picker-<?= $idx ?>" value="<?= $fgColor ?>" 
                                        onchange="document.getElementById('input-<?= $idx ?>').value = this.value; updatePreview(this.value, <?= $idx ?>)"
                                        style="width:36px; height:36px; padding:0; border:1px solid var(--border-color); border-radius:6px; cursor:pointer;">
                                    
                                    <input type="text" name="fg_color[]" id="input-<?= $idx ?>" value="<?= $fgColor ?>"
                                        oninput="let v = this.value.trim(); if(v && !v.startsWith('#')){ v = '#' + v; } if(/^#[0-9A-F]{6}$/i.test(v)){ document.getElementById('picker-<?= $idx ?>').value = v; } updatePreview(v, <?= $idx ?>);"
                                        style="width:80px; padding:6px 8px; font-size:13px; font-family:monospace; border:1px solid var(--border-color); border-radius:4px;"
                                        placeholder="#f5a623">
                                    
                                    <!-- Input ẩn để lưu background Rgba -->
                                    <input type="hidden" name="bg_color[]" id="bg-input-<?= $idx ?>" value="<?= $bgColor ?>">
                                </div>
                            </td>
                            
                            <td style="padding:12px;">
                                <span class="tag preview-tag-<?= $idx ?>" style="--tag-bg:<?= $bgColor ?>; --tag-color:<?= $fgColor ?>; font-weight:600; font-size:12px; border: 1.5px solid currentColor; display:inline-block; padding:4px 10px; border-radius:12px;">
                                    <?= sanitize($resName) ?>
                                </span>
                            </td>
                            
                            <td style="padding:12px; font-size:12px; color:var(--text-muted);">
                                <?= implode(', ', array_unique($roomsList)) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div style="margin-top:24px; text-align:right;">
                    <a href="/cong-viec/rooms_manage.php" class="btn btn-secondary" style="margin-right:8px;">Quay lại</a>
                    <button type="submit" class="btn btn-primary" style="padding:8px 24px;">💾 Lưu Bảng Màu</button>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<script>
function hexToRgba(hex, opacity) {
    let r = 0, g = 0, b = 0;
    if (hex.length == 4) {
        r = "0x" + hex[1] + hex[1];
        g = "0x" + hex[2] + hex[2];
        b = "0x" + hex[3] + hex[3];
    } else if (hex.length == 7) {
        r = "0x" + hex[1] + hex[2];
        g = "0x" + hex[3] + hex[4];
        b = "0x" + hex[5] + hex[6];
    }
    return "rgba("+ +r + "," + +g + "," + +b + "," + opacity + ")";
}

function updatePreview(fgHex, idx) {
    // Đảm bảo có dấu # ở trước cho valid color
    if (fgHex && !fgHex.startsWith('#')) {
        fgHex = '#' + fgHex;
    }
    // Lấp liếm lỗi regex nếu độ dài không đủ
    var bgRgba = 'rgba(245,166,35,0.15)'; 
    if (/^#([0-9A-F]{3}){1,2}$/i.test(fgHex)) {
        bgRgba = hexToRgba(fgHex, 0.15); // Auto sinh nền làm mờ bằng 15% màu chủ đạo
    }
    
    // Cập nhật ẩn giá trị form
    document.getElementById('bg-input-' + idx).value = bgRgba;
    
    // Đổi màu preview tức thời
    var tags = document.querySelectorAll('.preview-tag-' + idx);
    tags.forEach(function(el) {
        el.style.setProperty('--tag-bg', bgRgba);
        el.style.setProperty('--tag-color', fgHex);
    });
}
</script>

<?php include 'layout_bottom.php'; ?>
