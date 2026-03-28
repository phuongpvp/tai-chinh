<?php
/**
 * Search API — Tìm khách hàng theo tên hoặc SĐT
 * Trả về JSON cho auto-suggest
 * Adapted for TC database: queries loans + customers
 */
require_once 'config.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 1) {
    echo json_encode([]);
    exit;
}

$searchTerm = "%$q%";
$stmt = $pdo->prepare("
    SELECT l.id, c.name, c.phone, l.cv_status as status, r.name as room_name, r.icon as room_icon
    FROM loans l
    LEFT JOIN customers c ON l.customer_id = c.id
    LEFT JOIN cv_rooms r ON l.cv_room_id = r.id
    WHERE (c.name LIKE ? OR c.phone LIKE ?) AND l.status != 'closed'
    ORDER BY l.cv_status ASC, c.name ASC
    LIMIT 10
");
$stmt->execute([$searchTerm, $searchTerm]);
$results = $stmt->fetchAll();

echo json_encode($results, JSON_UNESCAPED_UNICODE);
