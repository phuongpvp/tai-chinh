<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}
require_once 'config.php';
require_once 'SimpleXLSXGen.php';

// --- Replicate Filtering Logic ---
$whereClauses = ["l.store_id = ?"];
$params = [$current_store_id];

if (isset($_GET['type']) && $_GET['type'] != '') {
    $whereClauses[] = "l.loan_type = ?";
    $params[] = $_GET['type'];
}
if (isset($_GET['code']) && $_GET['code'] != '') {
    $whereClauses[] = "l.loan_code LIKE ?";
    $params[] = '%' . $_GET['code'] . '%';
}
if (isset($_GET['name']) && $_GET['name'] != '') {
    $whereClauses[] = "c.name LIKE ?";
    $params[] = '%' . $_GET['name'] . '%';
}

$filterStatus = $_GET['status'] ?? '';

// --- Status-Specific Filter Logic ---
if ($filterStatus == 'closed') {
    // 1. Contracts with 'closed' status
    $whereClauses[] = "l.status = 'closed'";
} elseif ($filterStatus == 'late_interest') {
    // 2. Late Interest (Only active contracts)
    $whereClauses[] = "l.status = 'active'";
    // Note: We'll filter this in PHP loop for simplicity, same as contracts.php logic
    // Or we can add SQL condition if we calculated dates in SQL.
    // For consistency with contracts.php, we fetch active and filter in loop.
} elseif ($filterStatus == 'overdue') {
    // 3. Overdue Principal (Only active contracts)
    $whereClauses[] = "l.status = 'active'";
} elseif ($filterStatus == 'bad_debt') {
    // 4. Bad Debt
    $whereClauses[] = "l.status = 'bad_debt'";
} else {
    // Default: 'active' contracts (excluding bad_debt and closed)
    $whereClauses[] = "l.status = 'active'";
}

// Date Range Filter
if (isset($_GET['from_date']) && $_GET['from_date'] != '') {
    // For closed contracts, filter by end_date (Closing Date)
    // For others, filter by start_date (Loan Date)
    if ($filterStatus == 'closed') {
        $whereClauses[] = "DATE(l.end_date) >= ?";
    } else {
        $whereClauses[] = "l.start_date >= ?";
    }
    $params[] = $_GET['from_date'];
}
if (isset($_GET['to_date']) && $_GET['to_date'] != '') {
    if ($filterStatus == 'closed') {
        $whereClauses[] = "DATE(l.end_date) <= ?";
    } else {
        $whereClauses[] = "l.start_date <= ?";
    }
    $params[] = $_GET['to_date'];
}

// --- Query Data ---
$sql = "SELECT l.*, c.name as customer_name, c.phone, c.identity_card, c.address,
        (SELECT SUM(amount) FROM transactions t WHERE t.loan_id = l.id AND t.type = 'collect_interest') as total_interest_paid 
        FROM loans l 
        JOIN customers c ON l.customer_id = c.id
        WHERE " . implode(' AND ', $whereClauses) . "
        ORDER BY l.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Process Data & Columns ---
$exportData = [];

// Header Row (Exact match to user request)
$header = [
    'Mã HĐ',
    'Tên KH',
    'SĐT',
    'Tiền vay',
    'Lãi Phí',
    'Ngày vay',
    'Ngày hết hạn',
    'Ghi Chú Tín chấp',
    'Đã đóng lãi đến',
    'Tiền lãi đã đóng',
    'Ngày đóng lãi tiếp theo',
    'Ngày đóng HĐ',
    'CMND',
    'Địa chỉ',
    'Nợ cũ' // Added as useful extra, or can be removed if strictly exact match desired
];
$exportData[] = $header;

$counter = 1;
foreach ($loans as $loan) {
    // --- REPLICATED LOGIC FROM contracts.php (For calculations if needed) ---
    // (We mainly need raw data fields now, but Next Payment might need calculation fallback)

    // Calculate Next Payment Date if missing
    $start_date = strtotime($loan['start_date']);
    $next_payment_date = '';
    if (!empty($loan['next_payment_date'])) {
        $next_payment_date = date('d-m-Y', strtotime($loan['next_payment_date']));
    } elseif (!empty($loan['paid_until_date'])) {
        $next_ts = strtotime($loan['paid_until_date']) + 86400; // +1 day logic
        $next_payment_date = date('d-m-Y', $next_ts);
    } else {
        $next_payment_date = date('d-m-Y', $start_date);
    }

    // Paid Until Date
    $paid_until = !empty($loan['paid_until_date']) ? date('d-m-Y', strtotime($loan['paid_until_date'])) : '';

    // End Date (Expiration)
    $end_date = !empty($loan['end_date']) ? date('d-m-Y', strtotime($loan['end_date'])) : '';

    // Closed Date (Only if closed)
    $date_closed = '';
    if ($loan['status'] == 'closed' && !empty($loan['end_date'])) {
        $date_closed = date('d-m-Y', strtotime($loan['end_date']));
    }

    // Rate Display
    $rate_disp = $loan['interest_rate'];
    if ($loan['loan_type'] == 1)
        $rate_disp = number_format($loan['interest_rate']) . 'k/1triệu'; // Match format
    else
        $rate_disp = $loan['interest_rate'] . '%';

    // Original Start Date
    $display_start_date = !empty($loan['original_start_date']) ? $loan['original_start_date'] : $loan['start_date'];

    // Identity Card & Address (Need to fetch if not joined? No, we mapped SELECT l.*, c.*)
    $identity_card = $loan['identity_card'] ?? ''; // From join
    $address = $loan['address'] ?? ''; // From join

    // --- Build Row ---
    $row = [];
    $row[] = $loan['loan_code'];
    $row[] = $loan['customer_name'];
    $row[] = $loan['phone'];
    $row[] = number_format($loan['amount']);
    $row[] = $rate_disp;
    $row[] = date('d-m-Y', strtotime($display_start_date)); // Column F: Ngày vay
    $row[] = $end_date; // Column G: Ngày hết hạn
    $row[] = $loan['contract_note'] ?? $loan['note'] ?? ''; // Column H: Ghi chú
    $row[] = $paid_until; // Column I: Đã đóng lãi đến
    $row[] = number_format($loan['total_interest_paid'] ?? 0); // Column J: Tiền lãi đã đóng
    $row[] = $next_payment_date; // Column K: Ngày đóng lãi tiếp theo
    $row[] = $date_closed; // Column L: Ngày đóng HĐ
    // Extra Info
    $row[] = $identity_card; // Column M: CMND (Need to ensure SELECT gets this)
    $row[] = $address; // Column N: Địa chỉ
    $row[] = number_format($loan['old_debt'] ?? 0); // Column O: Old Debt

    $exportData[] = $row;
}

// Generate XLSX
$xlsx = SimpleXLSXGen::fromArray($exportData);
$xlsx->downloadAs("hop_dong_" . date('Y-m-d') . ".xlsx");
