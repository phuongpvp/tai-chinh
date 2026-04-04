<?php
require_once 'config.php';
requireLogin();
requireRole(['admin']);

$pageTitle = 'Cấu hình nhật ký';
$activePage = 'rooms_manage';
$user = cvGetUser();

$roomId = intval($_GET['id'] ?? 0);
if (!$roomId) { redirect('/cong-viec/quan-ly-phong'); }

$room = $pdo->prepare("SELECT * FROM cv_rooms WHERE id = ?");
$room->execute([$roomId]);
$room = $room->fetch();
if (!$room) { redirect('/cong-viec/quan-ly-phong'); }

// Xử lý save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_config') {
    $configJson = $_POST['config_json'] ?? '[]';
    // Validate JSON
    $config = json_decode($configJson, true);
    if ($config === null) {
        $_SESSION['flash_message'] = 'Dữ liệu JSON không hợp lệ!';
        $_SESSION['flash_type'] = 'error';
    } else {
        $stmt = $pdo->prepare("UPDATE cv_rooms SET worklog_config = ? WHERE id = ?");
        $stmt->execute([json_encode($config, JSON_UNESCAPED_UNICODE), $roomId]);
        $_SESSION['flash_message'] = '✅ Đã lưu cấu hình nhật ký cho phòng ' . $room['name'];
    }
    redirect('/cong-viec/cau-hinh-phong/' . $roomId);
}

// Load existing config
$config = json_decode($room['worklog_config'] ?? '[]', true) ?: [];

include 'layout_top.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><span class="page-icon">⚙️</span>Cấu hình nhật ký: <?= sanitize($room['name']) ?></h1>
        <p class="page-subtitle">Thiết lập hành động và kết quả cho phòng <strong><?= sanitize($room['name']) ?></strong></p>
    </div>
    <div class="page-actions">
        <a href="rooms_manage.php" class="btn btn-secondary">← Quản lý phòng</a>
    </div>
</div>

<div class="page-body">
    <form method="POST" id="config-form">
        <input type="hidden" name="action" value="save_config">
        <input type="hidden" name="config_json" id="config-json" value="">

        <div id="config-container"></div>

        <button type="button" class="btn btn-secondary" onclick="addAction()" style="margin-top:16px;">
            ➕ Thêm hành động
        </button>

        <div style="margin-top:24px;padding-top:20px;border-top:1px solid var(--border-color);display:flex;gap:12px;">
            <button type="submit" class="btn btn-primary btn-lg" onclick="prepareSubmit()">💾 Lưu cấu hình</button>
            <a href="rooms_manage.php" class="btn btn-secondary btn-lg">Hủy</a>
        </div>
    </form>
</div>

<style>
.action-block {
    background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg);
    padding: 20px; margin-bottom: 16px; transition: box-shadow 0.2s, opacity 0.2s, transform 0.2s;
}
.action-block.dragging {
    opacity: 0.4; box-shadow: none;
}
.action-block.drag-over {
    border-color: var(--accent-blue); box-shadow: 0 -3px 0 0 var(--accent-blue);
}
.action-header {
    display: flex; align-items: center; gap: 12px; margin-bottom: 12px;
}
.action-header input {
    flex: 1; font-size: 15px; font-weight: 600; padding: 10px 14px;
    background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md);
    color: var(--text-primary);
}
.action-header input:focus { border-color: var(--accent-blue); outline: none; }
.result-list { margin-left: 24px; }
.result-item {
    display: flex; align-items: center; gap: 8px; margin-bottom: 6px;
    padding: 4px 0; transition: opacity 0.2s, transform 0.15s;
}
.result-item.dragging {
    opacity: 0.4;
}
.result-item.drag-over {
    border-top: 2px solid var(--accent-blue); margin-top: -2px;
}
.result-item input {
    flex: 1; padding: 8px 12px; font-size: 14px;
    background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md);
    color: var(--text-primary);
}
.result-item input:focus { border-color: var(--accent-blue); outline: none; }
.result-item label { font-size: 12px; color: var(--text-muted); white-space: nowrap; display: flex; align-items: center; gap: 4px; }
.drag-handle {
    cursor: grab; color: var(--text-muted); font-size: 16px; padding: 4px 2px;
    user-select: none; flex-shrink: 0; opacity: 0.5; transition: opacity 0.15s, color 0.15s;
    display: flex; align-items: center;
}
.drag-handle:hover { opacity: 1; color: var(--accent-blue); }
.drag-handle:active { cursor: grabbing; }
.btn-icon {
    width: 32px; height: 32px; border-radius: var(--radius-md); border: 1px solid var(--border-color);
    background: var(--bg-primary); color: var(--text-muted); cursor: pointer; display: flex;
    align-items: center; justify-content: center; font-size: 14px; transition: all 0.15s; flex-shrink: 0;
}
.btn-icon:hover { border-color: var(--status-danger); color: var(--status-danger); }
.btn-icon.add:hover { border-color: var(--accent-blue); color: var(--accent-blue); }
.result-section-title {
    font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase;
    margin: 12px 0 8px; letter-spacing: 0.5px;
}
.action-options {
    display: flex; align-items: center; gap: 12px; margin: 0 0 12px 0;
    padding: 8px 12px; background: rgba(245,158,11,0.06); border-radius: var(--radius-md);
}
.action-opt-label {
    font-size: 12px; color: var(--text-secondary); white-space: nowrap;
    display: flex; align-items: center; gap: 4px; cursor: pointer;
}
.action-opt-input {
    flex: 1; padding: 6px 10px; font-size: 13px;
    background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md);
    color: var(--text-primary);
}
.action-opt-input:focus { border-color: var(--accent-blue); outline: none; }
</style>

<script>
let configData = <?= json_encode($config, JSON_UNESCAPED_UNICODE) ?>;

function render() {
    const container = document.getElementById('config-container');
    container.innerHTML = '';

    configData.forEach((action, aIdx) => {
        const block = document.createElement('div');
        block.className = 'action-block';
        block.draggable = true;
        block.dataset.actionIdx = aIdx;

        // Action drag events
        block.addEventListener('dragstart', e => {
            if (e.target !== block) return;
            dragType = 'action';
            dragFrom = aIdx;
            block.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', aIdx);
        });
        block.addEventListener('dragend', () => {
            block.classList.remove('dragging');
            clearAllDragOver();
            dragType = null; dragFrom = null;
        });
        block.addEventListener('dragover', e => {
            if (dragType !== 'action') return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            clearAllDragOver();
            block.classList.add('drag-over');
        });
        block.addEventListener('dragleave', () => block.classList.remove('drag-over'));
        block.addEventListener('drop', e => {
            e.preventDefault();
            block.classList.remove('drag-over');
            if (dragType !== 'action' || dragFrom === null) return;
            const to = aIdx;
            if (dragFrom !== to) {
                const item = configData.splice(dragFrom, 1)[0];
                configData.splice(to, 0, item);
                render();
            }
        });

        let html = `<div class="action-header">
            <span class="drag-handle" title="Kéo để sắp xếp">☰</span>
            <span style="font-size:13px;color:var(--text-muted);font-weight:700;">🎯 Hành động ${aIdx + 1}</span>
            <input type="text" value="${esc(action.action)}" 
                   onchange="configData[${aIdx}].action = this.value" 
                   placeholder="VD: Gọi điện">
            <button type="button" class="btn-icon" onclick="removeAction(${aIdx})" title="Xóa hành động">✕</button>
        </div>`;

        // Custom input option
        const hasCustom = action.show_custom_input || false;
        const customLabel = action.custom_input_label || '';
        html += `<div class="action-options">
            <label class="action-opt-label"><input type="checkbox" ${hasCustom ? 'checked' : ''}
                onchange="configData[${aIdx}].show_custom_input = this.checked; render()"> ✏️ Có ô nhập chi tiết</label>
        </div>`;
        if (hasCustom) {
            html += `<div style="margin: 0 0 12px 0; padding: 8px 12px; background: rgba(245,158,11,0.1); border: 1px dashed rgba(245,158,11,0.4); border-radius: var(--radius-md);">
                <label style="font-size:12px; color:#f59e0b; font-weight:600; margin-bottom:6px; display:block;">📝 Tiêu đề ô nhập (sẽ hiện cho nhân viên khi chọn hành động này)</label>
                <input type="text" class="action-opt-input" value="${esc(customLabel)}"
                    onchange="configData[${aIdx}].custom_input_label = this.value"
                    placeholder="VD: Công việc sáng tạo là gì" style="width:100%;">
            </div>`;
        }

        // Results
        html += `<div class="result-list" data-action-idx="${aIdx}">
            <div class="result-section-title">📊 Kết quả (khi chọn "${esc(action.action)}")</div>`;

        (action.results || []).forEach((result, rIdx) => {
            const label = typeof result === 'object' ? result.label : result;
            const showDate = typeof result === 'object' ? (result.show_date || false) : false;
            const showAmount = typeof result === 'object' ? (result.show_amount || false) : false;

            html += `<div class="result-item" draggable="true" data-action-idx="${aIdx}" data-result-idx="${rIdx}"
                ondragstart="resultDragStart(event, ${aIdx}, ${rIdx})"
                ondragend="resultDragEnd(event)"
                ondragover="resultDragOver(event, ${aIdx}, ${rIdx})"
                ondragleave="resultDragLeave(event)"
                ondrop="resultDrop(event, ${aIdx}, ${rIdx})">
                <span class="drag-handle" title="Kéo để sắp xếp">☰</span>
                <span style="color:var(--text-muted);font-size:12px;width:20px;text-align:center;">${String.fromCharCode(65 + rIdx)}</span>
                <input type="text" value="${esc(label)}" 
                       onchange="updateResult(${aIdx}, ${rIdx}, 'label', this.value)" 
                       placeholder="VD: Hẹn trả gốc">
                <label><input type="checkbox" ${showDate ? 'checked' : ''} 
                       onchange="updateResult(${aIdx}, ${rIdx}, 'show_date', this.checked)"> 📅 Ngày hẹn</label>
                <label><input type="checkbox" ${showAmount ? 'checked' : ''} 
                       onchange="updateResult(${aIdx}, ${rIdx}, 'show_amount', this.checked)"> 💰 Số tiền</label>
                <button type="button" class="btn-icon" onclick="removeResult(${aIdx}, ${rIdx})" title="Xóa">✕</button>
            </div>`;
        });

        html += `<button type="button" class="btn btn-secondary btn-sm" onclick="addResult(${aIdx})" style="margin-top:8px;margin-left:20px;">
            + Thêm kết quả
        </button></div>`;

        block.innerHTML = html;
        container.appendChild(block);
    });
}

function esc(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML.replace(/"/g, '&quot;');
}

function addAction() {
    configData.push({ action: '', results: [], show_custom_input: false, custom_input_label: '' });
    render();
    // Focus the new action input
    const inputs = document.querySelectorAll('.action-header input[type="text"]');
    if (inputs.length) inputs[inputs.length - 1].focus();
}

function removeAction(idx) {
    if (confirm('Xóa hành động này và tất cả kết quả?')) {
        configData.splice(idx, 1);
        render();
    }
}

function addResult(actionIdx) {
    if (!configData[actionIdx].results) configData[actionIdx].results = [];
    configData[actionIdx].results.push({ label: '', show_date: false, show_amount: false });
    render();
    // Focus new result
    const block = document.querySelectorAll('.action-block')[actionIdx];
    const inputs = block.querySelectorAll('.result-item input[type="text"]');
    if (inputs.length) inputs[inputs.length - 1].focus();
}

function removeResult(actionIdx, resultIdx) {
    configData[actionIdx].results.splice(resultIdx, 1);
    render();
}

function updateResult(actionIdx, resultIdx, field, value) {
    let result = configData[actionIdx].results[resultIdx];
    // Ensure object format
    if (typeof result === 'string') {
        result = { label: result, show_date: false, show_amount: false };
        configData[actionIdx].results[resultIdx] = result;
    }
    result[field] = value;
}

function prepareSubmit() {
    // Clean up: read all values from DOM to ensure sync
    const blocks = document.querySelectorAll('.action-block');
    configData = [];
    blocks.forEach(block => {
        const actionInput = block.querySelector('.action-header input[type="text"]');
        const actionOptCheckbox = block.querySelector('.action-options input[type="checkbox"]');
        const actionOptInput = block.querySelector('.action-opt-input');
        const action = { 
            action: actionInput.value.trim(), 
            results: [],
            show_custom_input: actionOptCheckbox?.checked || false,
            custom_input_label: actionOptInput?.value.trim() || ''
        };

        block.querySelectorAll('.result-item').forEach(ri => {
            const label = ri.querySelector('input[type="text"]').value.trim();
            const checks = ri.querySelectorAll('input[type="checkbox"]');
            if (label) {
                action.results.push({
                    label: label,
                    show_date: checks[0]?.checked || false,
                    show_amount: checks[1]?.checked || false,
                });
            }
        });

        if (action.action) configData.push(action);
    });

    document.getElementById('config-json').value = JSON.stringify(configData);
}

// === Drag & Drop state ===
let dragType = null; // 'action' or 'result'
let dragFrom = null;
let dragResultFrom = null; // {aIdx, rIdx}

// Clear all drag-over highlights
function clearAllDragOver() {
    document.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
}

// === Result drag handlers ===
function resultDragStart(e, aIdx, rIdx) {
    e.stopPropagation();
    dragType = 'result';
    dragResultFrom = { aIdx, rIdx };
    e.target.closest('.result-item').classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', rIdx);
}

function resultDragEnd(e) {
    e.target.closest('.result-item')?.classList.remove('dragging');
    clearAllDragOver();
    dragType = null;
    dragResultFrom = null;
}

function resultDragOver(e, aIdx, rIdx) {
    if (dragType !== 'result') return;
    if (dragResultFrom.aIdx !== aIdx) return; // only within same action
    e.preventDefault();
    e.stopPropagation();
    e.dataTransfer.dropEffect = 'move';
    clearAllDragOver();
    e.target.closest('.result-item').classList.add('drag-over');
}

function resultDragLeave(e) {
    e.target.closest('.result-item')?.classList.remove('drag-over');
}

function resultDrop(e, aIdx, rIdx) {
    e.preventDefault();
    e.stopPropagation();
    e.target.closest('.result-item')?.classList.remove('drag-over');
    if (dragType !== 'result' || !dragResultFrom) return;
    if (dragResultFrom.aIdx !== aIdx) return;
    const from = dragResultFrom.rIdx;
    if (from !== rIdx) {
        const results = configData[aIdx].results;
        const item = results.splice(from, 1)[0];
        results.splice(rIdx, 0, item);
        render();
    }
}

// Initial render
render();
</script>

<?php include 'layout_bottom.php'; ?>
