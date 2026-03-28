<?php
session_start();
require_once 'config.php';
require_once 'permissions_helper.php';
require_once 'sheets_helper.php';

// Check permission
if (!hasPermission($conn, 'system.manage')) {
    header("Location: index.php");
    exit();
}

$apps_script_code = getAppsScriptCode();
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Hướng dẫn cài đặt Google Apps Script</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism.min.css">
    <style>
        body {
            background: #f8f9fa;
        }

        .step-number {
            width: 40px;
            height: 40px;
            background: #0d6efd;
            color: white;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 10px;
        }

        .code-block {
            background: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            position: relative;
        }

        .copy-btn {
            position: absolute;
            top: 10px;
            right: 10px;
        }
    </style>
</head>

<body>
    <?php include 'sidebar.php'; ?>

    <div class="container-fluid" style="margin-left: 250px; padding: 20px;">
        <div class="row">
            <div class="col-md-10">
                <div class="card shadow-sm">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-book"></i> Hướng dẫn cài đặt Google Apps Script</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning">
                            <strong><i class="bi bi-exclamation-triangle"></i> Quan trọng:</strong>
                            Để hệ thống có thể đẩy dữ liệu lên Google Sheets, anh cần tạo một Google Apps Script Web
                            App.
                            Quá trình này chỉ cần làm <strong>1 lần duy nhất</strong> và mất khoảng <strong>5-10
                                phút</strong>.
                        </div>

                        <!-- Step 1 -->
                        <div class="mb-4">
                            <h5><span class="step-number">1</span> Mở Google Sheet của anh</h5>
                            <p>Truy cập vào Google Sheet mà anh đã cấu hình trong trang Cài đặt.</p>
                        </div>

                        <!-- Step 2 -->
                        <div class="mb-4">
                            <h5><span class="step-number">2</span> Mở Apps Script Editor</h5>
                            <p>Trong Google Sheet, nhấn vào menu: <strong>Extensions</strong> (Tiện ích mở rộng) →
                                <strong>Apps Script</strong></p>
                            <img src="https://i.imgur.com/placeholder1.png" class="img-fluid border rounded mt-2"
                                alt="Mở Apps Script" style="max-width: 600px;">
                        </div>

                        <!-- Step 3 -->
                        <div class="mb-4">
                            <h5><span class="step-number">3</span> Xóa code mặc định và dán code mới</h5>
                            <p>Xóa toàn bộ code có sẵn trong editor, sau đó dán đoạn code sau:</p>
                            <div class="code-block">
                                <button class="btn btn-sm btn-primary copy-btn" onclick="copyCode()">
                                    <i class="bi bi-clipboard"></i> Sao chép
                                </button>
                                <pre><code class="language-javascript" id="appsScriptCode"><?php echo htmlspecialchars($apps_script_code); ?></code></pre>
                            </div>
                        </div>

                        <!-- Step 4 -->
                        <div class="mb-4">
                            <h5><span class="step-number">4</span> Lưu dự án</h5>
                            <p>Nhấn vào biểu tượng <strong>💾 Save</strong> (hoặc Ctrl+S) để lưu code.</p>
                            <p class="text-muted"><small>Anh có thể đặt tên project là "Loan Manager Sync" hoặc tên bất
                                    kỳ.</small></p>
                        </div>

                        <!-- Step 5 -->
                        <div class="mb-4">
                            <h5><span class="step-number">5</span> Deploy Web App</h5>
                            <p>Nhấn vào nút <strong>Deploy</strong> (Triển khai) → <strong>New deployment</strong>
                                (Triển khai mới)</p>
                            <img src="https://i.imgur.com/placeholder2.png" class="img-fluid border rounded mt-2"
                                alt="Deploy" style="max-width: 600px;">
                        </div>

                        <!-- Step 6 -->
                        <div class="mb-4">
                            <h5><span class="step-number">6</span> Cấu hình deployment</h5>
                            <ol>
                                <li>Nhấn vào biểu tượng <strong>⚙️</strong> bên cạnh "Select type"</li>
                                <li>Chọn <strong>Web app</strong></li>
                                <li>Cấu hình như sau:
                                    <ul>
                                        <li><strong>Description:</strong> Loan Manager Sync (hoặc tên bất kỳ)</li>
                                        <li><strong>Execute as:</strong> Me (email của anh)</li>
                                        <li><strong>Who has access:</strong> <span
                                                class="text-danger fw-bold">Anyone</span></li>
                                    </ul>
                                </li>
                                <li>Nhấn <strong>Deploy</strong></li>
                            </ol>
                            <div class="alert alert-info mt-2">
                                <i class="bi bi-info-circle"></i> <strong>Lưu ý:</strong> Phải chọn "Anyone" để hệ thống
                                PHP có thể gọi được API.
                            </div>
                        </div>

                        <!-- Step 7 -->
                        <div class="mb-4">
                            <h5><span class="step-number">7</span> Ủy quyền (Authorization)</h5>
                            <p>Google sẽ yêu cầu anh ủy quyền cho script:</p>
                            <ol>
                                <li>Nhấn <strong>Authorize access</strong></li>
                                <li>Chọn tài khoản Google của anh</li>
                                <li>Nhấn <strong>Advanced</strong> (Nâng cao)</li>
                                <li>Nhấn <strong>Go to [Project Name] (unsafe)</strong></li>
                                <li>Nhấn <strong>Allow</strong> (Cho phép)</li>
                            </ol>
                            <div class="alert alert-warning mt-2">
                                <i class="bi bi-exclamation-triangle"></i> Đây là script do anh tự tạo nên hoàn toàn an
                                toàn!
                            </div>
                        </div>

                        <!-- Step 8 -->
                        <div class="mb-4">
                            <h5><span class="step-number">8</span> Sao chép Web App URL</h5>
                            <p>Sau khi deploy thành công, Google sẽ hiển thị một <strong>Web app URL</strong>. URL này
                                có dạng:</p>
                            <div class="code-block">
                                <code>https://script.google.com/macros/s/ABC...XYZ/exec</code>
                            </div>
                            <p class="mt-2">Nhấn <strong>Copy</strong> để sao chép URL này.</p>
                        </div>

                        <!-- Step 9 -->
                        <div class="mb-4">
                            <h5><span class="step-number">9</span> Cập nhật URL vào hệ thống</h5>
                            <p>Quay lại trang <strong>Cài đặt</strong> của hệ thống và dán URL vào ô <strong>"Apps
                                    Script URL"</strong>.</p>
                            <a href="settings.php" class="btn btn-primary">
                                <i class="bi bi-gear"></i> Đi tới trang Cài đặt
                            </a>
                        </div>

                        <!-- Step 10 -->
                        <div class="mb-4">
                            <h5><span class="step-number">10</span> Hoàn tất!</h5>
                            <p>Bây giờ anh có thể sử dụng tính năng <strong>Đồng bộ Google Sheets</strong>!</p>
                            <a href="sheets_sync_manager.php" class="btn btn-success">
                                <i class="bi bi-cloud-upload"></i> Đồng bộ ngay
                            </a>
                        </div>

                        <hr>

                        <div class="alert alert-secondary">
                            <h6><i class="bi bi-question-circle"></i> Câu hỏi thường gặp</h6>
                            <p><strong>Q: Tại sao phải chọn "Anyone" trong Who has access?</strong></p>
                            <p>A: Vì server PHP của anh không có tài khoản Google, nên cần public URL để gọi được API.
                            </p>

                            <p class="mt-3"><strong>Q: Có an toàn không?</strong></p>
                            <p>A: Có! Script chỉ nhận dữ liệu và ghi vào Sheet của anh. Không ai khác có thể truy cập
                                Sheet nếu anh không share.</p>

                            <p class="mt-3"><strong>Q: Nếu muốn thay đổi code sau này?</strong></p>
                            <p>A: Anh chỉ cần mở lại Apps Script Editor, sửa code, lưu lại. Không cần deploy lại.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-javascript.min.js"></script>
    <script>
        function copyCode() {
            const code = document.getElementById('appsScriptCode').textContent;
            navigator.clipboard.writeText(code).then(() => {
                alert('Đã sao chép code vào clipboard!');
            });
        }
    </script>
</body>

</html>