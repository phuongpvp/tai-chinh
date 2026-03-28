<?php
require_once 'config.php';
requireLogin();

$user = cvGetUser();
$pageTitle = 'Thư viện & Hướng dẫn';
$activePage = 'library';

// Auto-create table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cv_library (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        content TEXT,
        category VARCHAR(50) DEFAULT 'guide',
        created_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Xử lý POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add_article':
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $category = trim($_POST['category'] ?? 'guide');
            if (!empty($title)) {
                $stmt = $pdo->prepare("INSERT INTO cv_library (title, content, category, created_by) VALUES (?, ?, ?, ?)");
                $stmt->execute([$title, $content, $category, $user['id']]);
                $_SESSION['flash_message'] = '✅ Đã thêm bài viết!';
            }
            header('Location: library.php'); exit;
            break;

        case 'edit_article':
            $id = intval($_POST['id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $category = trim($_POST['category'] ?? 'guide');
            if ($id && !empty($title)) {
                $stmt = $pdo->prepare("UPDATE cv_library SET title=?, content=?, category=? WHERE id=?");
                $stmt->execute([$title, $content, $category, $id]);
                $_SESSION['flash_message'] = '✅ Đã cập nhật!';
            }
            header('Location: library.php'); exit;
            break;

        case 'delete_article':
            $id = intval($_POST['id'] ?? 0);
            if ($id) {
                $pdo->prepare("DELETE FROM cv_library WHERE id = ?")->execute([$id]);
                $_SESSION['flash_message'] = '✅ Đã xóa!';
            }
            header('Location: library.php'); exit;
            break;
    }
}

// Lấy bài viết
$articles = $pdo->query("SELECT a.*, u.fullname as author_name FROM cv_library a LEFT JOIN users u ON a.created_by = u.id ORDER BY a.created_at DESC")->fetchAll();

$categoryLabels = ['guide' => '📖 Hướng dẫn', 'document' => '📄 Tài liệu', 'policy' => '📋 Quy trình', 'other' => '📌 Khác'];

include 'layout_top.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><span class="page-icon">📚</span>Thư viện & Hướng dẫn</h1>
        <p class="page-subtitle"><?= count($articles) ?> bài viết</p>
    </div>
    <div>
        <button class="btn btn-primary btn-sm" onclick="openModal('add-article-modal')">➕ Thêm bài viết</button>
    </div>
</div>

<div class="page-body">

    <!-- ========== DANH MỤC THƯ MỤC ========== -->
    <div style="margin-bottom:28px;">
        <div id="lib-folders" style="display:flex;gap:20px;flex-wrap:wrap;">
            <?php
            $folderNames = ['guide' => 'Hướng dẫn', 'document' => 'Tài liệu', 'policy' => 'Quy trình', 'other' => 'Khác'];
            $folderColors = [
                'guide'    => ['#f0b429','#d99a1e','#c48a17'],
                'document' => ['#4da6ff','#3b8de0','#2d74c4'],
                'policy'   => ['#2dd4a8','#22b893','#1a9e7e'],
                'other'    => ['#a78bfa','#8b6ff0','#7556db']
            ];
            $folderInnerIcons = [
                'guide'    => '<path d="M28 28H52V30H28V28ZM28 34H48V36H28V34ZM28 40H44V42H28V40Z" fill="white" opacity="0.5"/>',
                'document' => '<path d="M34 28H46V30H34V28ZM34 34H46V36H34V34ZM38 40H42V42H38V40Z" fill="white" opacity="0.5"/>',
                'policy'   => '<path d="M30 30L34 34L42 26" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" opacity="0.5"/><path d="M30 40L34 44L42 36" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" opacity="0.5"/>',
                'other'    => '<circle cx="40" cy="34" r="3" fill="white" opacity="0.5"/><path d="M40 28V31" stroke="white" stroke-width="2" stroke-linecap="round" opacity="0.5"/>'
            ];
            $catCounts = [];
            foreach ($articles as $a) {
                $c = $a['category'] ?? 'guide';
                $catCounts[$c] = ($catCounts[$c] ?? 0) + 1;
            }
            $first = true;
            foreach ($folderNames as $catKey => $catName):
                $count = $catCounts[$catKey] ?? 0;
                $colors = $folderColors[$catKey];
            ?>
            <div class="lib-folder<?= $first ? ' active' : '' ?>"
                 data-category="<?= $catKey ?>"
                 onclick="selectFolder(this, '<?= $catKey ?>')">
                <div class="lib-folder-icon-wrap">
                    <svg class="lib-folder-svg" viewBox="0 0 80 64" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <linearGradient id="grad-<?= $catKey ?>-back" x1="0" y1="0" x2="80" y2="64" gradientUnits="userSpaceOnUse">
                                <stop offset="0%" stop-color="<?= $colors[2] ?>"/>
                                <stop offset="100%" stop-color="<?= $colors[1] ?>"/>
                            </linearGradient>
                            <linearGradient id="grad-<?= $catKey ?>-front" x1="0" y1="14" x2="80" y2="60" gradientUnits="userSpaceOnUse">
                                <stop offset="0%" stop-color="<?= $colors[0] ?>"/>
                                <stop offset="100%" stop-color="<?= $colors[1] ?>"/>
                            </linearGradient>
                        </defs>
                        <path d="M2 10C2 6.68629 4.68629 4 8 4H28L36 14H72C75.3137 14 78 16.6863 78 20V54C78 57.3137 75.3137 60 72 60H8C4.68629 60 2 57.3137 2 54V10Z" fill="url(#grad-<?= $catKey ?>-back)"/>
                        <path d="M2 20C2 16.6863 4.68629 14 8 14H72C75.3137 14 78 16.6863 78 20V54C78 57.3137 75.3137 60 72 60H8C4.68629 60 2 57.3137 2 54V20Z" fill="url(#grad-<?= $catKey ?>-front)"/>
                        <?= $folderInnerIcons[$catKey] ?>
                    </svg>
                    <?php if ($count > 0): ?>
                    <span class="lib-folder-badge"><?= $count ?></span>
                    <?php endif; ?>
                </div>
                <span class="lib-folder-name"><?= $catName ?></span>
            </div>
            <?php $first = false; endforeach; ?>
        </div>
    </div>

    <!-- ========== BÀI VIẾT THEO DANH MỤC ========== -->
    <div id="lib-articles-area">
        <?php if (empty($articles)): ?>
            <div id="lib-empty-all" style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:48px;text-align:center;">
                <div style="font-size:48px;margin-bottom:12px;">📂</div>
                <p style="color:var(--text-muted);font-size:14px;">Chưa có bài viết nào</p>
                <button class="btn btn-primary" onclick="openModal('add-article-modal')" style="margin-top:12px;">➕ Thêm bài viết đầu tiên</button>
            </div>
        <?php endif; ?>

        <!-- Empty state per category -->
        <div id="lib-empty-cat" style="display:none;background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:40px;text-align:center;">
            <div style="font-size:40px;margin-bottom:10px;">📭</div>
            <p style="color:var(--text-muted);font-size:14px;">Danh mục này chưa có bài viết</p>
            <button class="btn btn-primary btn-sm" onclick="openModal('add-article-modal')" style="margin-top:10px;">➕ Thêm bài viết</button>
        </div>

        <div id="lib-articles-list" style="display:flex;flex-direction:column;gap:8px;">
            <?php foreach ($articles as $art): ?>
            <div class="lib-article-item" data-cat="<?= $art['category'] ?>" id="article-<?= $art['id'] ?>" style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-lg);overflow:hidden;">
                <!-- Header -->
                <div class="lib-article-header" onclick="toggleArticle(this)" style="display:flex;justify-content:space-between;align-items:center;padding:14px 20px;cursor:pointer;user-select:none;transition:background .15s;">
                    <div style="display:flex;align-items:center;gap:10px;min-width:0;flex:1;">
                        <span class="lib-chevron" style="font-size:11px;color:var(--text-muted);transition:transform .25s ease;flex-shrink:0;">▶</span>
                        <h3 style="color:var(--text-primary);margin:0;font-size:15px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= sanitize($art['title']) ?></h3>
                        <span style="font-size:11px;color:var(--text-muted);flex-shrink:0;margin-left:auto;padding-left:10px;">
                            <?= date('d/m/Y', strtotime($art['created_at'])) ?>
                        </span>
                    </div>
                    <div style="display:flex;gap:4px;flex-shrink:0;margin-left:12px;" onclick="event.stopPropagation();">
                        <button class="btn btn-ghost btn-sm" onclick="editArticle(<?= $art['id'] ?>, <?= htmlspecialchars(json_encode($art['title']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($art['content']), ENT_QUOTES) ?>, '<?= $art['category'] ?>')" style="font-size:12px;padding:4px 6px;">✏️</button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Xóa bài viết này?')">
                            <input type="hidden" name="action" value="delete_article">
                            <input type="hidden" name="id" value="<?= $art['id'] ?>">
                            <button type="submit" class="btn btn-ghost btn-sm" style="font-size:12px;padding:4px 6px;color:var(--accent-red);">🗑️</button>
                        </form>
                    </div>
                </div>
                <!-- Body -->
                <div class="lib-article-body" style="max-height:0;overflow:hidden;transition:max-height .3s ease;">
                    <div style="padding:0 20px 16px 36px;border-top:1px solid var(--border-color);">
                        <div style="font-size:14px;line-height:1.8;color:var(--text-secondary);white-space:pre-wrap;padding-top:14px;"><?= sanitize($art['content'] ?? '') ?></div>
                        <div style="margin-top:12px;font-size:11px;color:var(--text-muted);">
                            <?= sanitize($art['author_name'] ?? '') ?> · <?= date('d/m/Y H:i', strtotime($art['created_at'])) ?>
                            <?php if ($art['updated_at'] !== $art['created_at']): ?>
                                · Cập nhật: <?= date('d/m/Y H:i', strtotime($art['updated_at'])) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- MODAL THÊM BÀI VIẾT -->
<div class="modal-overlay" id="add-article-modal" onclick="if(event.target===this)closeModal('add-article-modal')">
    <div class="modal-content" style="max-width:900px;width:90%;">
        <div class="modal-header">
            <h3 class="modal-title">➕ Thêm bài viết</h3>
            <button class="modal-close" onclick="closeModal('add-article-modal')">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_article">
            <input type="hidden" name="category" id="add-category" value="guide">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;">
                <div>
                    <label class="form-label" style="margin-bottom:4px;font-size:12px;color:var(--text-secondary);">Danh mục</label>
                    <select name="category" id="add-category-select" class="form-select" style="width:100%;padding:10px 12px;background:var(--bg-primary);border:1px solid var(--border-color);border-radius:var(--radius-md);color:var(--text-primary);font-size:14px;">
                        <option value="guide">📖 Hướng dẫn</option>
                        <option value="document">📄 Tài liệu</option>
                        <option value="policy">📋 Quy trình</option>
                        <option value="other">📌 Khác</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" style="margin-bottom:4px;font-size:12px;color:var(--text-secondary);">Tiêu đề *</label>
                    <input type="text" name="title" placeholder="Tiêu đề bài viết..." required
                        style="width:100%;padding:10px 12px;background:var(--bg-primary);border:1px solid var(--border-color);border-radius:var(--radius-md);color:var(--text-primary);font-size:14px;outline:none;">
                </div>
                <div>
                    <label class="form-label" style="margin-bottom:4px;font-size:12px;color:var(--text-secondary);">Nội dung</label>
                    <textarea name="content" rows="12" placeholder="Nội dung bài viết..."
                        style="width:100%;padding:10px 12px;background:var(--bg-primary);border:1px solid var(--border-color);border-radius:var(--radius-md);color:var(--text-primary);font-size:14px;min-height:220px;resize:vertical;outline:none;line-height:1.7;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('add-article-modal')">Hủy</button>
                <button type="submit" class="btn btn-primary">💾 Lưu</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL SỬA BÀI VIẾT -->
<div class="modal-overlay" id="edit-article-modal" onclick="if(event.target===this)closeModal('edit-article-modal')">
    <div class="modal-content" style="max-width:900px;width:90%;">
        <div class="modal-header">
            <h3 class="modal-title">✏️ Sửa bài viết</h3>
            <button class="modal-close" onclick="closeModal('edit-article-modal')">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_article">
            <input type="hidden" name="id" id="edit-id">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;">
                <div>
                    <label class="form-label" style="margin-bottom:4px;font-size:12px;color:var(--text-secondary);">Danh mục</label>
                    <select name="category" id="edit-category" style="width:100%;padding:10px 12px;background:var(--bg-primary);border:1px solid var(--border-color);border-radius:var(--radius-md);color:var(--text-primary);font-size:14px;">
                        <option value="guide">📖 Hướng dẫn</option>
                        <option value="document">📄 Tài liệu</option>
                        <option value="policy">📋 Quy trình</option>
                        <option value="other">📌 Khác</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" style="margin-bottom:4px;font-size:12px;color:var(--text-secondary);">Tiêu đề *</label>
                    <input type="text" name="title" id="edit-title" required
                        style="width:100%;padding:10px 12px;background:var(--bg-primary);border:1px solid var(--border-color);border-radius:var(--radius-md);color:var(--text-primary);font-size:14px;outline:none;">
                </div>
                <div>
                    <label class="form-label" style="margin-bottom:4px;font-size:12px;color:var(--text-secondary);">Nội dung</label>
                    <textarea name="content" id="edit-content" rows="12"
                        style="width:100%;padding:10px 12px;background:var(--bg-primary);border:1px solid var(--border-color);border-radius:var(--radius-md);color:var(--text-primary);font-size:14px;min-height:220px;resize:vertical;outline:none;line-height:1.7;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('edit-article-modal')">Hủy</button>
                <button type="submit" class="btn btn-primary">💾 Cập nhật</button>
            </div>
        </form>
    </div>
</div>

<style>
/* Folder grid */
.lib-folder {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    padding: 20px 18px 14px;
    border-radius: var(--radius-lg);
    cursor: pointer;
    transition: background .2s, transform .2s, box-shadow .2s;
    min-width: 110px;
    text-align: center;
    user-select: none;
}
.lib-folder:hover {
    background: rgba(255,255,255,0.06);
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.25);
}
.lib-folder.active {
    background: rgba(255,255,255,0.08);
    box-shadow: 0 4px 16px rgba(0,0,0,0.2);
}
.lib-folder.active .lib-folder-name {
    color: #fff;
    font-weight: 600;
}
.lib-folder-icon-wrap {
    position: relative;
    width: 100px;
    height: 80px;
}
.lib-folder-svg {
    width: 100px;
    height: 80px;
    filter: drop-shadow(0 4px 8px rgba(0,0,0,0.3));
    transition: transform .2s;
}
.lib-folder:hover .lib-folder-svg {
    transform: scale(1.08);
}
.lib-folder-badge {
    position: absolute;
    top: -6px;
    right: -8px;
    background: #ef4444;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    min-width: 24px;
    height: 24px;
    padding: 0 6px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 6px rgba(239,68,68,0.4);
}
.lib-folder-name {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-secondary);
    line-height: 1.3;
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Article accordion */
.lib-article-header:hover { background: rgba(255,255,255,0.03); }
.lib-article-item.open .lib-chevron { transform: rotate(90deg); }
</style>

<script>
var currentCategory = 'guide';

function selectFolder(el, cat) {
    // Update active folder
    document.querySelectorAll('.lib-folder').forEach(function(f) { f.classList.remove('active'); });
    el.classList.add('active');
    currentCategory = cat;

    // Pre-select category in Add modal
    var addSelect = document.getElementById('add-category-select');
    if (addSelect) addSelect.value = cat;

    // Filter articles
    filterArticles(cat);
}

function filterArticles(cat) {
    var items = document.querySelectorAll('.lib-article-item');
    var visibleCount = 0;
    items.forEach(function(item) {
        if (item.dataset.cat === cat) {
            item.style.display = '';
            visibleCount++;
        } else {
            item.style.display = 'none';
            // Collapse if open
            if (item.classList.contains('open')) {
                item.classList.remove('open');
                var body = item.querySelector('.lib-article-body');
                if (body) body.style.maxHeight = '0';
            }
        }
    });

    // Show/hide empty state
    var emptyAll = document.getElementById('lib-empty-all');
    var emptyCat = document.getElementById('lib-empty-cat');
    if (emptyAll) emptyAll.style.display = 'none';
    if (emptyCat) emptyCat.style.display = visibleCount === 0 ? '' : 'none';
}

function toggleArticle(headerEl) {
    var item = headerEl.closest('.lib-article-item');
    var body = item.querySelector('.lib-article-body');
    if (item.classList.contains('open')) {
        body.style.maxHeight = body.scrollHeight + 'px';
        requestAnimationFrame(function() { body.style.maxHeight = '0'; });
        item.classList.remove('open');
    } else {
        body.style.maxHeight = body.scrollHeight + 'px';
        item.classList.add('open');
        body.addEventListener('transitionend', function handler() {
            if (item.classList.contains('open')) body.style.maxHeight = 'none';
            body.removeEventListener('transitionend', handler);
        });
    }
}

function editArticle(id, title, content, category) {
    document.getElementById('edit-id').value = id;
    document.getElementById('edit-title').value = title;
    document.getElementById('edit-content').value = content;
    document.getElementById('edit-category').value = category;
    openModal('edit-article-modal');
}

// Init: show first category
document.addEventListener('DOMContentLoaded', function() {
    filterArticles('guide');
});
</script>

<?php include 'layout_bottom.php'; ?>
