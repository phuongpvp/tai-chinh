<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

// Same query as contract_alerts.php
$sql = "SELECT l.*, c.name as customer_name, c.phone, c.address,
        (SELECT SUM(amount) FROM transactions t WHERE t.loan_id = l.id AND t.type = 'collect_interest') as total_interest_paid
        FROM loans l 
        JOIN customers c ON l.customer_id = c.id 
        WHERE l.status = 'active' AND l.store_id = ?
        ORDER BY l.id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute([$current_store_id]);
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

$alerts = [];

foreach ($loans as $loan) {
    $calc_start = !empty($loan['paid_until_date']) ? strtotime($loan['paid_until_date']) : strtotime($loan['start_date']);
    $now_ts = time();
    $days_running = floor(($now_ts - $calc_start) / 86400);
    if ($days_running < 0) $days_running = 0;

    $total_expected_interest = 0;
    if ($loan['interest_type'] == 'ngay' || $loan['interest_rate'] > 100) {
        $rate_real = $loan['interest_rate'];
        $mult = ($rate_real < 500) ? 1000 : 1;
        $daily_amount = ($loan['amount'] / 1000000) * ($rate_real * $mult);
        $total_expected_interest = $daily_amount * $days_running;
    } else {
        $daily_amount = ($loan['amount'] * ($loan['interest_rate'] / 100)) / 30;
        $total_expected_interest = $daily_amount * $days_running;
    }

    if (!empty($loan['paid_until_date'])) {
        $interest_debt = $total_expected_interest;
    } else {
        $interest_debt = $total_expected_interest - $loan['total_interest_paid'];
    }

    if ($interest_debt > 10000) {
        $alert_item = $loan;
        $alert_item['interest_debt'] = $interest_debt;

        $next_pay_ts = strtotime($loan['next_payment_date']);
        $late_days = floor(($now_ts - $next_pay_ts) / 86400);
        if ($late_days < 0) $late_days = 0;

        $period = $loan['period_days'] > 0 ? $loan['period_days'] : 30;
        $late_cycles = floor($late_days / $period);

        $reason = "";
        if ($late_cycles >= 1) {
            $reason = "Chậm $late_cycles lần đóng lãi !";
        } else {
            $reason = "Chậm lãi $late_days ngày !";
        }

        $alert_item['late_reason'] = $reason;
        $alert_item['old_debt'] = $loan['old_debt'] ?? 0;
        $alert_item['total_due'] = $alert_item['old_debt'] + $interest_debt;
        $alerts[] = $alert_item;
    }
}

// Set headers for Excel download
$filename = date('Ymd') . ' - Báo cáo Tín chấp cần xử lý.xls';
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<!--[if gte mso 9]>
<xml>
<x:ExcelWorkbook>
<x:ExcelWorksheets>
<x:ExcelWorksheet>
<x:Name>Cảnh báo</x:Name>
<x:WorksheetOptions>
<x:DisplayGridlines/>
</x:WorksheetOptions>
</x:ExcelWorksheet>
</x:ExcelWorksheets>
</x:ExcelWorkbook>
</xml>
<![endif]-->
<style>
    td, th { 
        mso-number-format: "\@"; 
        border: 1px solid #000;
        padding: 5px 8px;
        font-family: Arial;
        font-size: 11pt;
    }
    .header {
        background-color: #4472C4;
        color: white;
        font-weight: bold;
        text-align: center;
    }
    .number {
        mso-number-format: "#,##0";
        text-align: right;
    }
    .phone {
        mso-number-format: "\@";
    }
    .total-row {
        background-color: #E2EFDA;
        font-weight: bold;
    }
</style>
</head>
<body>
<table>
    <tr>
        <th class="header">STT</th>
        <th class="header">Mã HĐ</th>
        <th class="header">Khách Hàng</th>
        <th class="header">Số ĐT</th>
        <th class="header">Địa Chỉ</th>
        <th class="header">Nợ Cũ</th>
        <th class="header">Tiền Lãi Phí</th>
        <th class="header">Tiền Gốc</th>
        <th class="header">Tổng Tiền</th>
        <th class="header">Lý Do</th>
    </tr>
    <?php 
    $total_old_debt = 0;
    $total_interest = 0;
    $total_amount = 0;
    $total_due = 0;
    
    foreach ($alerts as $idx => $row): 
        $total_old_debt += $row['old_debt'];
        $total_interest += $row['interest_debt'];
        $total_amount += $row['amount'];
        $total_due += $row['total_due'];
    ?>
    <tr>
        <td style="text-align:center"><?php echo $idx + 1; ?></td>
        <td><?php echo $row['loan_code']; ?></td>
        <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
        <td class="phone"><?php echo $row['phone']; ?></td>
        <td><?php echo htmlspecialchars($row['address']); ?></td>
        <td class="number"><?php echo number_format($row['old_debt'], 0, ',', ','); ?></td>
        <td class="number"><?php echo number_format($row['interest_debt'], 0, ',', ','); ?></td>
        <td class="number"><?php echo number_format($row['amount'], 0, ',', ','); ?></td>
        <td class="number"><?php echo number_format($row['total_due'], 0, ',', ','); ?></td>
        <td><?php echo $row['late_reason']; ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total-row">
        <td colspan="5" style="text-align:right;font-weight:bold">TỔNG TIỀN</td>
        <td class="number"><?php echo number_format($total_old_debt, 0, ',', ','); ?></td>
        <td class="number"><?php echo number_format($total_interest, 0, ',', ','); ?></td>
        <td class="number"><?php echo number_format($total_amount, 0, ',', ','); ?></td>
        <td class="number"><?php echo number_format($total_due, 0, ',', ','); ?></td>
        <td></td>
    </tr>
</table>
</body>
</html>
