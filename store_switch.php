<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$new_store_id = $_POST['store_id'] ?? 0;

if (!$new_store_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid store ID']);
    exit;
}

try {
    // Super admin can access any store
    if (isset($_SESSION['role']) && $_SESSION['role'] == 'super_admin') {
        $stmt = $conn->prepare("SELECT name FROM stores WHERE id = ?");
        $stmt->execute([$new_store_id]);
        $store = $stmt->fetch();

        if ($store) {
            $_SESSION['current_store_id'] = $new_store_id;

            // Auto-create user_stores record if not exists
            $stmt2 = $conn->prepare("INSERT IGNORE INTO user_stores (user_id, store_id) VALUES (?, ?)");
            $stmt2->execute([$_SESSION['user_id'], $new_store_id]);

            echo json_encode([
                'success' => true,
                'message' => 'Đã chuyển sang ' . $store['name'],
                'store_name' => $store['name']
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Cửa hàng không tồn tại']);
        }
    } else {
        // Regular user: check user_stores
        $stmt = $conn->prepare("SELECT s.name FROM stores s JOIN user_stores us ON s.id = us.store_id WHERE us.user_id = ? AND us.store_id = ?");
        $stmt->execute([$_SESSION['user_id'], $new_store_id]);
        $store = $stmt->fetch();

        if ($store) {
            $_SESSION['current_store_id'] = $new_store_id;
            echo json_encode([
                'success' => true,
                'message' => 'Đã chuyển sang ' . $store['name'],
                'store_name' => $store['name']
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Bạn không có quyền truy cập cửa hàng này']);
        }
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>