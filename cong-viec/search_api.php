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
    SELECT l.id, c.name, c.phone, l.cv_status as status,
        CASE WHEN l.cv_status = 'active' AND l.cv_room_id IS NOT NULL THEN r.name ELSE NULL END as room_name,
        CASE WHEN l.cv_status = 'active' AND l.cv_room_id IS NOT NULL THEN r.icon ELSE NULL END as room_icon
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
