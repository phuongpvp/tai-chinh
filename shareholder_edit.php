<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';
require_once 'permissions_helper.php';

// Check permission
if (!hasPermission($conn, 'shareholders.edit')) {
    die("Bạn không có quyền sửa cổ đông!");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id'];
    $shareholder_code = trim($_POST['shareholder_code']);
    $name = trim($_POST['name']);
    $capital_amount = str_replace([',', '.'], '', $_POST['capital_amount']);

    // Verify ownership
    $stmt = $conn->prepare("SELECT store_id FROM shareholders WHERE id = ?");
    $stmt->execute([$id]);
    $shareholder = $stmt->fetch();

    if (!$shareholder || $shareholder['store_id'] != $current_store_id) {
        die("Không tìm thấy cổ đông hoặc bạn không có quyền sửa!");
    }

    // Update shareholder
    $stmt = $conn->prepare("UPDATE shareholders SET shareholder_code = ?, name = ?, capital_amount = ? WHERE id = ?");
    $stmt->execute([$shareholder_code, $name, $capital_amount, $id]);

    // Recalculate percentages
    recalculatePercentages($conn, $current_store_id);

    header("Location: shareholders.php?msg=" . urlencode("Cập nhật cổ đông thành công!"));
    exit;
}

function recalculatePercentages($conn, $store_id)
{
    $stmt = $conn->prepare("SELECT SUM(capital_amount) FROM shareholders WHERE store_id = ?");
    $stmt->execute([$store_id]);
    $total = $stmt->fetchColumn();

    if ($total > 0) {
        $stmt = $conn->prepare("SELECT id, capital_amount FROM shareholders WHERE store_id = ?");
        $stmt->execute([$store_id]);
        $shareholders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $updateStmt = $conn->prepare("UPDATE shareholders SET percentage = ? WHERE id = ?");
        foreach ($shareholders as $sh) {
            $percentage = ($sh['capital_amount'] / $total) * 100;
            $updateStmt->execute([$percentage, $sh['id']]);
        }
    }
}
?>