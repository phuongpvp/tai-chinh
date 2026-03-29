<?php
/**
 * Cài đặt AI — Gemini API Key + Prompt
 * Chỉ admin mới truy cập được
 */
require_once 'config.php';
requireLogin();
$user = cvGetUser();
if ($user['role'] !== 'admin') { redirect('/cong-viec/tong-quan'); }

$activePage = 'ai_settings';
$pageTitle = 'Cài đặt AI';

// Đảm bảo bảng system_settings tồn tại
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

$success = '';
$error = '';

// Xử lý lưu
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apiKey = trim($_POST['gemini_api_key'] ?? '');
    $prompt = trim($_POST['gemini_prompt'] ?? '');
    
    try {
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        
        if ($apiKey) {
            $stmt->execute(['gemini_api_key', $apiKey, $apiKey]);
        }
        $stmt->execute(['gemini_prompt', $prompt, $prompt]);
        
        $success = 'Đã lưu cài đặt thành công!';
    } catch (Exception $e) {
        $error = 'Lỗi: ' . $e->getMessage();
    }
}

// Load settings hiện tại
$currentKey = '';
$currentPrompt = '';
try {
    $s = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('gemini_api_key', 'gemini_prompt')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $currentKey = $s['gemini_api_key'] ?? '';
    $currentPrompt = $s['gemini_prompt'] ?? '';
} catch (Exception $e) {}

$defaultPrompt = "Bạn là chuyên gia QUẢN TRỊ RỦI RO TÀI CHÍNH. Hãy phân tích khách hàng một cách chuyên nghiệp, dùng ngôn ngữ chuyên môn chuẩn Việt Nam.

QUY TẮC NGÔN NGỮ:
- KHÔNG dùng: 'nhà chăm sóc tài chính', 'rầu khỏe', 'đảo đổi', 'lời trích'.
- DÙNG: 'Nhân viên xử lý', 'Lịch sử thanh toán', 'Tình trạng nợ', 'Thiện chí trả nợ'.

Dựa trên dữ liệu, hãy đánh giá (Nếu không có dữ liệu thì báo 'Chưa có thông tin'):

1. **Chỉ số tín nhiệm** (Tốt / Trung bình / Thấp).
2. **Khả năng thanh toán** (Cao / Trung bình / Thấp).
3. **Cảnh báo rủi ro** — liệt kê các dấu hiệu (nếu có).
4. **Giải pháp đề xuất** (LƯU Ý: Nếu khách TỐT thì đề xuất 'Duy trì quan hệ/Tri ân', khách TRUNG BÌNH mới 'Nhắc nhở nhẹ', khách XẤU mới dùng 'Biện pháp mạnh').

Trả lời bằng Tiếng Việt Tự Nhiên, ngắn gọn, có emoji.";

include 'layout_top.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">🤖 Cài đặt AI</h1>
        <p style="color:var(--text-muted);font-size:14px;margin-top:4px;">Cấu hình AI để đánh giá khách hàng tự động</p>
    </div>
</div>

<div class="page-body">

    <?php if ($success): ?>
    <div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);border-radius:var(--radius-lg);padding:12px 16px;margin-bottom:20px;color:#22c55e;font-size:14px;">
        ✅ <?= sanitize($success) ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:var(--radius-lg);padding:12px 16px;margin-bottom:20px;color:#ef4444;font-size:14px;">
        ❌ <?= sanitize($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
        <!-- API KEY -->
        <section style="margin-bottom:24px;">
            <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:20px;border-left:3px solid #8b5cf6;">
                <div style="margin-bottom:16px;">
                    <span style="color:#8b5cf6;font-size:13px;font-weight:600;">🔑 OPENROUTER API KEY</span>
                </div>
                <div style="margin-bottom:12px;">
                    <input type="password" name="gemini_api_key" id="api-key-input"
                        value="<?= sanitize($currentKey) ?>"
                        placeholder="Dán API Key từ openrouter.ai (sk-or-...)..."
                        class="form-input" 
                        style="width:100%;padding:10px 14px;font-size:14px;font-family:monospace;">
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    
                    <button type="button" onclick="toggleApiKey()" class="btn btn-ghost btn-sm" style="font-size:12px;">👁️ Hiện/Ẩn</button>
                </div>
                <?php if ($currentKey): ?>
                <div style="margin-top:10px;font-size:12px;color:var(--accent-green);">
                    ✅ Đã cấu hình (<?= substr($currentKey, 0, 8) ?>...<?= substr($currentKey, -4) ?>)
                </div>
                <?php else: ?>
                <div style="margin-top:10px;font-size:12px;color:var(--accent-red);">
                    ⚠️ Chưa cấu hình API Key
                </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- PROMPT -->
        <section style="margin-bottom:24px;">
            <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:20px;border-left:3px solid #f59e0b;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                    <span style="color:#f59e0b;font-size:13px;font-weight:600;">📝 PROMPT ĐÁNH GIÁ</span>
                    <button type="button" onclick="resetPrompt()" class="btn btn-ghost btn-sm" style="font-size:12px;color:var(--text-muted);">🔄 Reset mặc định</button>
                </div>
                <textarea name="gemini_prompt" id="prompt-input" class="form-textarea"
                    style="width:100%;min-height:200px;font-size:13px;line-height:1.7;padding:14px;"
                    placeholder="Nhập prompt hướng dẫn AI đánh giá khách hàng..."><?= sanitize($currentPrompt ?: $defaultPrompt) ?></textarea>
                <div style="font-size:12px;color:var(--text-muted);margin-top:8px;">
                    💡 Prompt này sẽ được gửi kèm dữ liệu khách hàng (nhật ký, chuyển phòng, giao dịch TC) cho AI phân tích.
                    Người dùng cũng có thể nhập prompt riêng khi phân tích từng khách.
                </div>
            </div>
        </section>

        <!-- NÚT LƯU -->
        <div style="display:flex;gap:12px;">
            <button type="submit" class="btn btn-primary" style="background:#8b5cf6;border-color:#8b5cf6;padding:10px 28px;font-size:14px;">
                💾 Lưu cài đặt
            </button>
            <a href="/cong-viec/tong-quan" class="btn btn-secondary" style="padding:10px 20px;font-size:14px;">← Quay lại</a>
        </div>
    </form>

    
</div>

<script>
function toggleApiKey(){
    var el=document.getElementById('api-key-input');
    el.type = el.type==='password' ? 'text' : 'password';
}
function resetPrompt(){
    if(!confirm('Reset prompt về mặc định?')) return;
    document.getElementById('prompt-input').value = <?= json_encode($defaultPrompt) ?>;
}
</script>

<?php include 'layout_bottom.php'; ?>
