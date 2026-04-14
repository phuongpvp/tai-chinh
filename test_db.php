<?php
require 'config.php';
$stmt = $conn->query("SELECT l.id, l.start_date, l.period_days, l.paid_until_date, l.next_payment_date, l.status, l.is_hidden_from_reminder, c.name FROM loans l JOIN customers c ON l.customer_id = c.id WHERE c.name LIKE '%KHÁNH%'");
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
