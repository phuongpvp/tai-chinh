<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

$API_KEY = 'SYNC_2026_CV_TC_secret';
$key = isset($_GET['key']) ? $_GET['key'] : '';
if ($key !== $API_KEY) {
    echo json_encode(array('error' => 'Invalid API key'));
    exit;
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=taichinh_fdsew;charset=utf8mb4", "taichinh_fdsew", "ZlxUdZ5a");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo json_encode(array('error' => $e->getMessage()));
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : 'due_today';
$storeId = isset($_GET['store_id']) ? intval($_GET['store_id']) : 0;
$today = date('Y-m-d');
$storeFilter = ($storeId > 0) ? " AND l.store_id = $storeId" : "";

if ($action === 'summary') {
    $total = $conn->query("SELECT COUNT(*) FROM loans WHERE status='active'")->fetchColumn();
    $s1 = $conn->prepare("SELECT COUNT(*) FROM loans WHERE status='active' AND next_payment_date = ?");
    $s1->execute(array($today));
    $due = $s1->fetchColumn();
    $s2 = $conn->prepare("SELECT COUNT(*) FROM loans WHERE status='active' AND next_payment_date < ? AND next_payment_date IS NOT NULL");
    $s2->execute(array($today));
    $overdue = $s2->fetchColumn();
    echo json_encode(array('success'=>true, 'date'=>$today, 'total'=>(int)$total, 'due_today'=>(int)$due, 'overdue'=>(int)$overdue), JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'due_today') {
    $from = $today;
    $to = $today;
} elseif ($action === 'due_range') {
    $from = isset($_GET['from']) ? $_GET['from'] : $today;
    $to = isset($_GET['to']) ? $_GET['to'] : $today;
} elseif ($action === 'all_active') {
    $from = null;
    $to = null;
} else {
    echo json_encode(array('error' => 'Use: due_today, due_range, all_active, summary'));
    exit;
}

$sql = "SELECT c.id as customer_id, c.name as customer_name, c.phone, c.address,
               l.id as loan_id, l.loan_code, l.amount as loan_amount,
               l.interest_rate, l.interest_type, l.period_days,
               l.next_payment_date, l.paid_until_date, l.store_id,
               s.name as store_name
        FROM loans l
        JOIN customers c ON l.customer_id = c.id
        LEFT JOIN stores s ON l.store_id = s.id
        WHERE l.status = 'active'" . $storeFilter;

$params = array();
if ($from !== null) {
    $sql .= " AND l.next_payment_date IS NOT NULL AND l.next_payment_date >= ? AND l.next_payment_date <= ?";
    $params[] = $from;
    $params[] = $to;
}
$sql .= " ORDER BY l.next_payment_date, c.name";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

foreach ($rows as $i => $r) {
    $p = floatval($r['loan_amount']);
    $rate = floatval($r['interest_rate']);
    $type = isset($r['interest_type']) ? $r['interest_type'] : '';
    $days = (!empty($r['period_days']) && $r['period_days'] > 0) ? $r['period_days'] : 30;
    if ($type === 'ngay' || $rate > 100) {
        $mult = ($rate < 500) ? 1000 : 1;
        $interest = ($p / 1000000) * ($rate * $mult) * $days;
    } else {
        $interest = ($p * ($rate / 100)) / 30 * $days;
    }
    $rows[$i]['interest_amount'] = $interest;
    $diff = floor((strtotime($today) - strtotime($r['next_payment_date'])) / 86400);
    $rows[$i]['days_overdue'] = ($diff > 0) ? $diff : 0;
}

$result = array('success' => true, 'count' => count($rows), 'data' => $rows);
if ($from !== null) {
    $result['from'] = $from;
    $result['to'] = $to;
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
