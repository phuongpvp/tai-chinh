<?php
require_once 'config.php';
requireLogin();

$pageTitle = 'Nhật ký làm việc';
$activePage = 'worklog_add';
$user = cvGetUser();

// Xử lý submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_worklog') {
    $customerId = intval($_POST['loan_id'] ?? 0);
    $roomId = intval($_POST['room_id'] ?? 0);
    $actionType = trim($_POST['action_type'] ?? '');
    $resultType = trim($_POST['result_type'] ?? '');
    $promiseDate = trim($_POST['promise_date'] ?? '') ?: null;
    $amount = trim($_POST['amount'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $logDate = date('Y-m-d');

    if ($customerId && $roomId && $actionType && $resultType) {
        $amountVal = null;
        if ($amount !== '') {
            $amountVal = floatval(str_replace([',', '.', ' '], '', $amount));
        }

        // Merge custom detail + notes into work_done
        $customDetail = trim($_POST['custom_detail'] ?? '');
        if (!empty($customDetail) && !empty($notes)) {
            $workDone = $customDetail . '; ' . $notes;
        } elseif (!empty($customDetail)) {
            $workDone = $customDetail;
        } elseif (!empty($notes)) {
            $workDone = $notes;
        } else {
            $workDone = '';
        }

        $stmt = $pdo->prepare("INSERT INTO cv_work_logs (loan_id, user_id, room_id, work_done, log_date, action_type, result_type, promise_date, amount) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$customerId, $user['id'], $roomId, $workDone, $logDate, $actionType, $resultType, $promiseDate, $amountVal]);

        $_SESSION['flash_message'] = '✅ Đã lưu nhật ký!';
        redirect('/cong-viec/nhat-ky');
    } else {
        $_SESSION['flash_message'] = 'Vui lòng điền đầy đủ thông tin!';
        $_SESSION['flash_type'] = 'error';
        redirect('/cong-viec/nhat-ky');
    }
}

// Lấy danh sách khách hàng active
$customers = $pdo->query("SELECT l.id, c.name, r.name as room_name FROM loans l LEFT JOIN customers c ON l.customer_id = c.id LEFT JOIN cv_rooms r ON l.cv_room_id = r.id WHERE l.cv_status = 'active' AND l.status != 'closed' ORDER BY c.name")->fetchAll();

// Lấy danh sách phòng
$rooms = $pdo->query("SELECT id, name, icon, color, worklog_config FROM cv_rooms WHERE is_archive = 0 ORDER BY sort_order, name")->fetchAll();

// Build config map cho JS
$roomConfigMap = [];
foreach ($rooms as $r) {
    $roomConfigMap[$r['id']] = json_decode($r['worklog_config'] ?? '[]', true) ?: [];
}

$letters = range('A', 'Z');

include 'layout_top.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><span class="page-icon">📝</span>Nhật ký làm việc khách <?= date('d/m') ?></h1>
        <p class="page-subtitle">Ghi nhận công việc hàng ngày</p>
    </div>
</div>

<div class="page-body">
    <form method="POST" id="worklog-form" style="max-width:640px;">
        <input type="hidden" name="action" value="add_worklog">
        <input type="hidden" name="loan_id" id="hid-customer-id" value="">
        <input type="hidden" name="room_id" id="hid-room-id" value="">
        <input type="hidden" name="action_type" id="hid-action-type" value="">
        <input type="hidden" name="result_type" id="hid-result-type" value="">
        <input type="hidden" name="custom_detail" id="hid-custom-detail" value="">

        <!-- 1) HỌ VÀ TÊN KHÁCH -->
        <div class="wl-section">
            <label class="wl-label">Họ và tên khách <span class="wl-required">*</span></label>
            <div class="wl-search-box" id="customer-search-box">
                <input type="text" class="wl-search-input" id="customer-search" 
                       placeholder="Gõ tên để tìm khách hàng..." autocomplete="off">
                <span class="wl-search-arrow">▾</span>
                <div class="wl-dropdown" id="customer-dropdown">
                    <?php foreach ($customers as $c): ?>
                    <div class="wl-dropdown-item" data-id="<?= $c['id'] ?>" data-name="<?= sanitize($c['name']) ?>">
                        <span class="wl-dropdown-name"><?= sanitize($c['name']) ?></span>
                        <span class="wl-dropdown-meta"><?= sanitize($c['room_name'] ?? '') ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- 2) TÊN PHÒNG LÀM VIỆC -->
        <div class="wl-section">
            <label class="wl-label">Tên Phòng làm việc <span class="wl-required">*</span></label>
            <div class="wl-tags" id="room-tags">
                <?php foreach ($rooms as $i => $r): 
                    $letter = $letters[$i % 26];
                ?>
                <button type="button" class="wl-tag" data-value="<?= $r['id'] ?>" onclick="selectRoom(this)">
                    <span class="wl-tag-letter" style="background:<?= sanitize($r['color']) ?>"><?= $letter ?></span>
                    <?= sanitize($r['name']) ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 3) ĐÃ LÀM VIỆC GÌ (dynamic per room) -->
        <div class="wl-section" id="action-section" style="display:none;">
            <label class="wl-label">Đã làm việc gì <span class="wl-required">*</span></label>
            <div class="wl-tags" id="action-tags"></div>
            <div class="wl-empty" id="action-empty" style="display:none;color:var(--text-muted);font-size:14px;padding:8px 0;">
                ⚠️ Phòng này chưa được cấu hình. Admin hãy vào <a href="rooms_manage.php" style="color:var(--accent-blue);">Quản lý phòng</a> → ⚙️ để thêm.
            </div>
        </div>

        <!-- 3.5) Ô NHẬP CHI TIẾT (dynamic per action) -->
        <div class="wl-section" id="custom-input-section" style="display:none;">
            <label class="wl-label" id="custom-input-label" style="color:#f59e0b;">Nhập chi tiết</label>
            <input type="text" class="form-input" id="custom-input-field" placeholder="Nhập chi tiết..." style="font-size:15px;padding:12px 14px;">
        </div>

        <!-- 4) KẾT QUẢ (dynamic per action) -->
        <div class="wl-section" id="result-section" style="display:none;">
            <label class="wl-label">Kết quả <span class="wl-required">*</span></label>
            <div class="wl-tags" id="result-tags"></div>
        </div>

        <!-- 5) NGÀY HẸN (conditional) -->
        <div class="wl-section" id="date-section" style="display:none;">
            <label class="wl-label">Ngày hẹn</label>
            <input type="date" name="promise_date" class="form-input" id="promise-date">
        </div>

        <!-- 6) SỐ TIỀN (conditional) -->
        <div class="wl-section" id="amount-section" style="display:none;">
            <label class="wl-label">Số tiền khách trả hoặc hẹn trả <span style="font-weight:400;color:var(--text-muted)">(Ghi rõ, bao nhiêu % so với số tiền cần phải thu). VD: 10tr (100% gốc)</span></label>
            <input type="text" name="amount" class="form-input" placeholder="VD: 10tr (100% gốc)">
        </div>

        <!-- 7) GHI CHÚ -->
        <div class="wl-section" id="notes-section" style="display:none;">
            <label class="wl-label">Ghi chú thêm</label>
            <textarea name="notes" class="form-textarea" placeholder="Nhập ghi chú..."></textarea>
        </div>

        <!-- SUBMIT -->
        <div class="wl-section" id="submit-section" style="display:none;">
            <button type="submit" class="wl-submit-btn" onclick="document.getElementById('hid-custom-detail').value = (document.getElementById('custom-input-field').value || '').trim()">Submit →</button>
        </div>
    </form>
</div>

<style>
.wl-section { margin-bottom: 28px; }
.wl-label { display: block; font-size: 18px; font-weight: 700; color: var(--text-primary); margin-bottom: 12px; }
.wl-required { color: #eb5757; }
.wl-search-box { position: relative; }
.wl-search-input {
    width: 100%; padding: 12px 40px 12px 14px; font-size: 15px;
    background: var(--bg-card); border: 1.5px solid var(--border-color); border-radius: var(--radius-md);
    color: var(--text-primary); outline: none; transition: border-color 0.2s; box-sizing: border-box;
}
.wl-search-input:focus { border-color: var(--accent-blue); }
.wl-search-arrow { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none; }
.wl-dropdown {
    display: none; position: absolute; left: 0; right: 0; top: 100%; margin-top: 4px;
    background: var(--bg-card); border: 1.5px solid var(--border-color); border-radius: var(--radius-md);
    max-height: 280px; overflow-y: auto; z-index: 100; box-shadow: 0 8px 24px rgba(0,0,0,0.3);
}
.wl-dropdown.show { display: block; }
.wl-dropdown-item {
    padding: 10px 14px; cursor: pointer; display: flex; justify-content: space-between; align-items: center;
    transition: background 0.15s; font-size: 14px;
}
.wl-dropdown-item:hover { background: rgba(35,131,226,0.12); }
.wl-dropdown-item.hidden { display: none; }
.wl-dropdown-name { color: var(--text-primary); }
.wl-dropdown-meta { font-size: 12px; color: var(--text-muted); }
.wl-tags { display: flex; flex-direction: column; gap: 8px; }
.wl-tag {
    display: inline-flex; align-items: center; gap: 10px; padding: 10px 16px;
    background: var(--bg-card); border: 1.5px solid var(--border-color); border-radius: var(--radius-md);
    color: var(--text-primary); font-size: 14px; cursor: pointer; transition: all 0.2s;
    text-align: left; width: fit-content;
}
.wl-tag:hover { border-color: var(--accent-blue); background: rgba(35,131,226,0.08); }
.wl-tag.selected { border-color: var(--accent-blue); background: rgba(35,131,226,0.15); box-shadow: 0 0 0 2px rgba(35,131,226,0.3); }
.wl-tag-letter {
    display: inline-flex; align-items: center; justify-content: center;
    width: 22px; height: 22px; border-radius: 4px; font-size: 12px; font-weight: 700;
    background: var(--text-muted); color: var(--bg-primary); flex-shrink: 0;
}
.wl-submit-btn {
    padding: 14px 32px; background: var(--text-primary); color: var(--bg-primary);
    border: none; border-radius: var(--radius-md); font-size: 16px; font-weight: 700;
    cursor: pointer; transition: opacity 0.2s;
}
.wl-submit-btn:hover { opacity: 0.85; }
.wl-dropdown::-webkit-scrollbar { width: 6px; }
.wl-dropdown::-webkit-scrollbar-thumb { background: var(--text-muted); border-radius: 3px; }
</style>

<script>
const roomConfigMap = <?= json_encode($roomConfigMap, JSON_UNESCAPED_UNICODE) ?>;
const LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

let currentRoomConfig = [];
let currentActionIdx = -1;

// === CUSTOMER SEARCH ===
const searchInput = document.getElementById('customer-search');
const dropdown = document.getElementById('customer-dropdown');
const items = dropdown.querySelectorAll('.wl-dropdown-item');

searchInput.addEventListener('focus', () => { dropdown.classList.add('show'); filterCustomers(); });
searchInput.addEventListener('input', filterCustomers);
document.addEventListener('click', (e) => {
    if (!document.getElementById('customer-search-box').contains(e.target)) dropdown.classList.remove('show');
});

function filterCustomers() {
    const q = searchInput.value.toLowerCase().trim();
    items.forEach(item => {
        item.classList.toggle('hidden', q && !item.dataset.name.toLowerCase().includes(q));
    });
    dropdown.classList.add('show');
}

items.forEach(item => {
    item.addEventListener('click', () => {
        searchInput.value = item.dataset.name;
        document.getElementById('hid-customer-id').value = item.dataset.id;
        dropdown.classList.remove('show');
        searchInput.style.borderColor = 'var(--accent-blue)';
        searchInput.style.background = 'rgba(35,131,226,0.08)';
    });
});

// === LEVEL 1: SELECT ROOM → show actions ===
function selectRoom(btn) {
    document.querySelectorAll('#room-tags .wl-tag').forEach(t => t.classList.remove('selected'));
    btn.classList.add('selected');
    const roomId = btn.dataset.value;
    document.getElementById('hid-room-id').value = roomId;

    // Reset downstream
    document.getElementById('hid-action-type').value = '';
    document.getElementById('hid-result-type').value = '';
    currentActionIdx = -1;
    hideSection('result-section');
    hideSection('custom-input-section');
    document.getElementById('custom-input-field').value = '';
    hideSection('date-section');
    hideSection('amount-section');
    hideSection('notes-section');
    hideSection('submit-section');

    // Load actions for this room
    currentRoomConfig = roomConfigMap[roomId] || [];
    const actionTags = document.getElementById('action-tags');
    const actionEmpty = document.getElementById('action-empty');
    const actionSection = document.getElementById('action-section');

    actionSection.style.display = '';
    actionTags.innerHTML = '';

    if (currentRoomConfig.length === 0) {
        actionEmpty.style.display = '';
    } else {
        actionEmpty.style.display = 'none';
        currentRoomConfig.forEach((ac, idx) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'wl-tag';
            b.dataset.value = ac.action;
            b.dataset.idx = idx;
            b.innerHTML = `<span class="wl-tag-letter">${LETTERS[idx % 26]}</span> ${esc(ac.action)}`;
            b.onclick = () => selectAction(b, idx);
            actionTags.appendChild(b);
        });
    }
}

// === LEVEL 2: SELECT ACTION → show results ===
function selectAction(btn, actionIdx) {
    document.querySelectorAll('#action-tags .wl-tag').forEach(t => t.classList.remove('selected'));
    btn.classList.add('selected');
    document.getElementById('hid-action-type').value = btn.dataset.value;

    // Reset result
    document.getElementById('hid-result-type').value = '';
    currentActionIdx = actionIdx;
    hideSection('date-section');
    hideSection('amount-section');
    hideSection('notes-section');
    hideSection('submit-section');

    // Custom input (ô nhập chi tiết)
    const action = currentRoomConfig[actionIdx];
    const customSection = document.getElementById('custom-input-section');
    const customField = document.getElementById('custom-input-field');
    customField.value = '';
    if (action.show_custom_input) {
        document.getElementById('custom-input-label').textContent = action.custom_input_label || 'Nhập chi tiết';
        customSection.style.display = '';
        setTimeout(() => customField.focus(), 100);
    } else {
        customSection.style.display = 'none';
    }

    // Load results for this action
    const results = action.results || [];
    const resultTags = document.getElementById('result-tags');
    const resultSection = document.getElementById('result-section');

    if (results.length === 0) {
        // No results → skip result step, show notes + submit directly
        resultSection.style.display = 'none';
        document.getElementById('notes-section').style.display = '';
        document.getElementById('submit-section').style.display = '';
    } else {
        resultSection.style.display = '';
        resultTags.innerHTML = '';

        results.forEach((result, idx) => {
            const label = typeof result === 'object' ? result.label : result;
            const showDate = typeof result === 'object' ? (result.show_date || false) : false;
            const showAmount = typeof result === 'object' ? (result.show_amount || false) : false;

            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'wl-tag';
            b.dataset.value = label;
            b.dataset.showDate = showDate;
            b.dataset.showAmount = showAmount;
            b.innerHTML = `<span class="wl-tag-letter">${LETTERS[idx % 26]}</span> ${esc(label)}`;
            b.onclick = () => selectResult(b);
            resultTags.appendChild(b);
        });
    }
}

// === LEVEL 3: SELECT RESULT → show date/amount/notes/submit ===
function selectResult(btn) {
    document.querySelectorAll('#result-tags .wl-tag').forEach(t => t.classList.remove('selected'));
    btn.classList.add('selected');
    document.getElementById('hid-result-type').value = btn.dataset.value;

    const showDate = btn.dataset.showDate === 'true';
    const showAmount = btn.dataset.showAmount === 'true';

    document.getElementById('date-section').style.display = showDate ? '' : 'none';
    document.getElementById('amount-section').style.display = showAmount ? '' : 'none';
    document.getElementById('notes-section').style.display = '';
    document.getElementById('submit-section').style.display = '';
}

function hideSection(id) { document.getElementById(id).style.display = 'none'; }

function esc(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}
</script>

<?php include 'layout_bottom.php'; ?>
