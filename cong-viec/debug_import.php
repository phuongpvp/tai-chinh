<?php
/**
 * DEBUG IMPORT v2 — Kiểm tra dữ liệu SAU KHI import
 */
session_start();
require_once __DIR__ . '/config.php';
if (!isset($_SESSION['user_id'])) { header('Location: /login.php'); exit; }

$pageTitle = 'Debug Import v2';
$activePage = '';
include 'layout_top.php';
?>

<div class="page-header">
    <h1>🔍 Debug Import v2 — Kiểm tra DB</h1>
</div>

<?php
// 1. Tổng số work_logs trong DB
$totalLogs = $pdo->query("SELECT COUNT(*) FROM cv_work_logs")->fetchColumn();
echo "<div style='background:var(--bg-secondary);padding:16px;border-radius:8px;margin-bottom:16px;'>";
echo "<h3 style='margin:0 0 12px;'>📊 Tình trạng Database</h3>";
echo "<strong>Tổng cv_work_logs:</strong> $totalLogs<br>";

// 2. Kiểm tra user_id trong work_logs có hợp lệ không
$invalidUsers = $pdo->query("SELECT COUNT(*) FROM cv_work_logs wl LEFT JOIN users u ON wl.user_id = u.id WHERE u.id IS NULL")->fetchColumn();
echo "<strong>Work logs có user_id không tồn tại:</strong> <span style='color:" . ($invalidUsers > 0 ? '#ff5252' : '#4caf50') . ";'>$invalidUsers</span><br>";

// 3. Kiểm tra loan_id trong work_logs có hợp lệ không
$invalidLoans = $pdo->query("SELECT COUNT(*) FROM cv_work_logs wl LEFT JOIN loans l ON wl.loan_id = l.id WHERE l.id IS NULL")->fetchColumn();
echo "<strong>Work logs có loan_id không tồn tại trong loans:</strong> <span style='color:" . ($invalidLoans > 0 ? '#ff5252' : '#4caf50') . ";'>$invalidLoans</span><br>";

// 4. Xem sample work_logs
echo "</div>";

echo "<h3>📋 10 bản ghi cv_work_logs mới nhất</h3>";
$sample = $pdo->query("SELECT wl.id, wl.loan_id, wl.user_id, wl.room_id, wl.action_type, wl.log_date, wl.work_done FROM cv_work_logs wl ORDER BY wl.id DESC LIMIT 10")->fetchAll();
echo "<div style='overflow-x:auto;'><table class='data-table' style='font-size:12px;'>";
echo "<tr><th>ID</th><th>loan_id</th><th>user_id</th><th>room_id</th><th>action</th><th>log_date</th><th>work_done</th><th>Loan tồn tại?</th><th>Tên khách</th></tr>";
foreach ($sample as $s) {
    $loanCheck = $pdo->prepare("SELECT l.id, c.name FROM loans l JOIN customers c ON l.customer_id = c.id WHERE l.id = ?");
    $loanCheck->execute([$s['loan_id']]);
    $loan = $loanCheck->fetch();
    $loanStatus = $loan ? "✅ " . htmlspecialchars($loan['name']) : "❌ KHÔNG TỒN TẠI";
    $loanColor = $loan ? '#4caf50' : '#ff5252';
    
    echo "<tr>";
    echo "<td>{$s['id']}</td>";
    echo "<td><strong>{$s['loan_id']}</strong></td>";
    echo "<td>{$s['user_id']}</td>";
    echo "<td>{$s['room_id']}</td>";
    echo "<td>" . htmlspecialchars($s['action_type'] ?? '') . "</td>";
    echo "<td>{$s['log_date']}</td>";
    echo "<td style='max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'>" . htmlspecialchars(mb_substr($s['work_done'] ?? '', 0, 50)) . "</td>";
    echo "<td style='color:$loanColor;'>$loanStatus</td>";
    echo "<td></td>";
    echo "</tr>";
}
echo "</table></div>";

// 5. So sánh: loan_id trong work_logs vs loans.id
echo "<h3 style='margin-top:20px;'>🔎 So sánh ID: Một khách hàng cụ thể</h3>";

// Lấy 1 khách có nhật ký import
$sampleLog = $pdo->query("SELECT loan_id, COUNT(*) as cnt FROM cv_work_logs GROUP BY loan_id ORDER BY cnt DESC LIMIT 1")->fetch();
if ($sampleLog) {
    $testLoanId = $sampleLog['loan_id'];
    $testCount = $sampleLog['cnt'];
    
    echo "<div style='background:var(--bg-secondary);padding:16px;border-radius:8px;'>";
    echo "<strong>loan_id phổ biến nhất trong cv_work_logs:</strong> $testLoanId (có $testCount bản ghi)<br>";
    
    // Check if this loan_id exists in loans
    $loanInfo = $pdo->prepare("SELECT l.id, l.customer_id, l.loan_code, c.name, c.id as cust_id FROM loans l JOIN customers c ON l.customer_id = c.id WHERE l.id = ?");
    $loanInfo->execute([$testLoanId]);
    $loanRow = $loanInfo->fetch();
    
    if ($loanRow) {
        echo "<strong>Trong bảng loans:</strong> ✅ Tồn tại — Khách: <strong>{$loanRow['name']}</strong>, customer_id={$loanRow['cust_id']}, loan_code={$loanRow['loan_code']}<br>";
        echo "<strong>URL để test:</strong> <a href='customer.php?id=$testLoanId' style='color:#4fc3f7;'>customer.php?id=$testLoanId</a><br>";
        
        // So sánh: loans.id vs customers.id cho khách này
        echo "<br><strong>⚠️ So sánh quan trọng:</strong><br>";
        echo "- loans.id (loan_id) = <strong>$testLoanId</strong><br>";
        echo "- customers.id = <strong>{$loanRow['cust_id']}</strong><br>";
        echo "- Hai ID " . ($testLoanId == $loanRow['cust_id'] ? "<span style='color:#4caf50;'>GIỐNG nhau</span>" : "<span style='color:#ff9800;'>KHÁC nhau</span> (đây là mấu chốt!)") . "<br>";
    } else {
        echo "<strong style='color:#ff5252;'>❌ loan_id=$testLoanId KHÔNG TỒN TẠI trong bảng loans!</strong><br>";
        
        // Check if it exists as customer_id instead
        $custInfo = $pdo->prepare("SELECT id, name FROM customers WHERE id = ?");
        $custInfo->execute([$testLoanId]);
        $custRow = $custInfo->fetch();
        if ($custRow) {
            echo "<strong style='color:#ff9800;'>⚠️ Nhưng TỒN TẠI trong bảng customers!</strong> → Code import đang dùng SAI ID (customers.id thay vì loans.id)<br>";
            
            // Find the correct loan_id
            $correctLoan = $pdo->prepare("SELECT id FROM loans WHERE customer_id = ? ORDER BY id DESC LIMIT 1");
            $correctLoan->execute([$testLoanId]);
            $correctId = $correctLoan->fetchColumn();
            if ($correctId) {
                echo "<strong>→ loan_id đúng phải là:</strong> <strong style='color:#4caf50;'>$correctId</strong><br>";
            }
        }
    }
    echo "</div>";
}

// 6. Hiển thị mapping customer name → IDs
echo "<h3 style='margin-top:20px;'>📝 Bảng mapping 10 khách đầu (customers.id vs loans.id)</h3>";
$mapping = $pdo->query("SELECT c.id as cust_id, c.name, l.id as loan_id FROM customers c LEFT JOIN loans l ON l.customer_id = c.id ORDER BY c.id LIMIT 10")->fetchAll();
echo "<div style='overflow-x:auto;'><table class='data-table' style='font-size:12px;'>";
echo "<tr><th>customers.id</th><th>Tên</th><th>loans.id</th><th>Giống?</th></tr>";
foreach ($mapping as $m) {
    $same = ($m['cust_id'] == $m['loan_id']);
    echo "<tr>";
    echo "<td>{$m['cust_id']}</td>";
    echo "<td>" . htmlspecialchars($m['name']) . "</td>";
    echo "<td>" . ($m['loan_id'] ?? 'NULL') . "</td>";
    echo "<td style='color:" . ($same ? '#4caf50' : '#ff9800') . ";'>" . ($same ? '✅ Giống' : '❌ Khác') . "</td>";
    echo "</tr>";
}
echo "</table></div>";

?>

<div style="margin-top:24px;">
    <a href="data_manage.php" class="btn btn-secondary">← Quay lại Quản lý dữ liệu</a>
</div>

<?php include 'layout_bottom.php'; ?>
