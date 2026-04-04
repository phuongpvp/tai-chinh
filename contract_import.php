<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

// Debug Error
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Auto-fix: Ensure next_payment_date column exists
try {
    $pdo->exec("ALTER TABLE loans ADD COLUMN next_payment_date DATE DEFAULT NULL");
} catch (Exception $e) {
    // Column already exists, ignore
}

$message = '';
$error = '';

/**
 * Simple Date parser for common Vietnamese Excel formats
 */
function parseDate($str)
{
    if (empty($str))
        return null;
    $str = trim($str);

    // Handle DD/MM/YYYY or DD-MM-YYYY or DD MM YYYY
    $sep = '/';
    if (strpos($str, '-') !== false)
        $sep = '-';
    elseif (strpos($str, ' ') !== false)
        $sep = ' ';

    $parts = explode($sep, $str);
    if (count($parts) == 3) {
        $d = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
        $m = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
        $y = $parts[2];
        if (strlen($y) == 2)
            $y = '20' . $y;
        return "$y-$m-$d";
    }

    // Try standard YYYY-MM-DD
    $d = strtotime($str);
    if ($d)
        return date('Y-m-d', $d);

    return null;
}

/**
 * Helper to parse amounts with commas/dots
 */
function parseAmount($str)
{
    if (empty($str))
        return 0;
    // Remove thousand separators (dots or commas followed by 3 digits)
    $clean = preg_replace('/[,.](?=\d{3})/', '', $str);
    // Replace remaining comma with dot for decimal
    $clean = str_replace(',', '.', $clean);
    return (float) filter_var($clean, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
}

/**
 * Helper to clean encoding
 */
function cleanStr($str)
{
    // Simple trim, assume UTF-8 is handled globally
    return trim($str);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['csv_file'])) {
    if ($_FILES['csv_file']['error'] == 0) {
        $file = $_FILES['csv_file']['tmp_name'];
        $filename = $_FILES['csv_file']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $rows = [];

        if ($ext === 'xlsx') {
            require_once 'SimpleXLSX.php';
            if ($xlsx = SimpleXLSX::parse($file)) {
                $rows = $xlsx->rows();
                // Assume first row is header
                if (!empty($rows)) {
                    array_shift($rows);
                }
            } else {
                echo "<script>alert('Lỗi đọc file XLSX');</script>";
            }
        } else {
            // Default to CSV
            $content = file_get_contents($file);

            // Remove BOM
            if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
                $content = substr($content, 3);
            }

            // Detect encoding - Simplified list for compatibility
            $enc = @mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1'], true);

            if ($enc && $enc != 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $enc);
                file_put_contents($file, $content);
            }

            $handle = fopen($file, "r");
            if ($handle) {
                fgetcsv($handle); // Skip header
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $rows[] = $data;
                }
                fclose($handle);
            }
        }

        $count = 0;
        $pdo = $conn;

        $skipped = 0;
        $skip_reason = '';

        $skipped = 0;
        $skip_reason = '';

        // Get selected status from form
        $import_status = $_POST['import_status'] ?? 'active';
        $valid_statuses = ['active', 'closed', 'bad_debt'];
        if (!in_array($import_status, $valid_statuses)) {
            $import_status = 'active';
        }

        // Loop through rows
        foreach ($rows as $data) {
            // Check required fields
            $code = mb_substr(trim($data[0] ?? ''), 0, 20);
            if (empty($code)) {
                $code = 'HĐ-' . date('ymd') . '-' . rand(1000, 9999);
            }

            $name = mb_substr(trim($data[1] ?? 'Khách lẻ'), 0, 50);

            // Fix: Force phone to string and pad with 0 if needed
            $phone_raw = $data[2] ?? '';
            // If Excel stored as number (e.g., 962542379), convert to string
            if (is_numeric($phone_raw)) {
                $phone = str_pad($phone_raw, 10, '0', STR_PAD_LEFT);
            } else {
                $phone = trim($phone_raw);
            }
            $phone = mb_substr($phone, 0, 100); // Allow multiple phone numbers

            $amount_str = $data[3] ?? '0';
            $rate_str = $data[4] ?? '0';
            $start_date_str = $data[5] ?? '';
            $end_date_str = $data[6] ?? '';
            $note = mb_substr(trim($data[7] ?? ''), 0, 255);
            $paid_interest_str = $data[9] ?? '0';
            $cmnd = mb_substr(trim($data[12] ?? ''), 0, 15);
            $address = mb_substr(trim($data[13] ?? ''), 0, 150);

            // Validation Checks
            $amount = parseAmount($amount_str);
            if ($amount <= 0) {
                $skipped++;
                $skip_reason .= "Dòng " . ($idx + 2) . ": Số tiền vay không hợp lệ ($amount_str)<br>";
                continue;
            }

            // Improved Rate Parsing (Handling 'k', 'tr', etc.)
            // "2.7k/1tr" -> 2700
            // "3k/1tr" -> 3000
            // "5" -> 5 (percent)
            $rate_str_lower = strtolower($rate_str);
            $multiplier = 1;

            if (strpos($rate_str_lower, 'k') !== false || strpos($rate_str_lower, 'ng') !== false) {
                $multiplier = 1000;
            }

            // Regex number extraction: catch 2.7 or 2,7
            // This stops before the next slash or text like "/1tr"
            // Pattern: Digits + optional (dot/comma + digits)
            $rate_num = 0;
            if (preg_match('/[0-9]+([.,][0-9]+)?/', $rate_str, $matches)) {
                $rate_num_str = str_replace(',', '.', $matches[0]);
                $rate_num = (float) $rate_num_str;
            }

            // Logic Adjustment:
            if ($multiplier > 1) {
                // "2.7k" -> 2.7 * 1000 = 2700
                $rate = $rate_num * $multiplier;
            } elseif ($rate_num < 100) {
                // "2700" -> 2700
                // "2.7" without k -> 2.7.
                $rate = $rate_num;
            } else {
                $rate = $rate_num;
            }

            $date_closed_str = $data[11] ?? ''; // Column L (Index 11)
            $date_closed = parseDate($date_closed_str);

            // Parse "Paid Until Date" (Column I) and "Next Payment Date" (Column K)
            $paid_until_str = $data[8] ?? ''; // Column I: Đã đóng lãi đến
            $paid_until_date = parseDate($paid_until_str);

            $next_payment_str = $data[10] ?? ''; // Column K: Ngày đóng lãi tiếp theo
            $next_payment_date = parseDate($next_payment_str);

            // ADJUSTMENT: Use Column K (Next Payment Date) as start_date if provided
            // This ensures future periods align with the Excel schedule
            if ($next_payment_date) {
                $start_date = $next_payment_date;
            } elseif ($paid_until_date) {
                // Fallback: If only paid_until_date is provided, use it
                $start_date = $paid_until_date;
            } else {
                $start_date = parseDate($start_date_str) ?: date('Y-m-d');
            }

            // Logic: If status is 'closed' AND we have a closed date, use it as end_date.
            // Otherwise use the scheduled end date.
            if ($import_status == 'closed' && $date_closed) {
                $end_date = $date_closed;
            } else {
                $end_date = parseDate($end_date_str) ?: date('Y-m-d', strtotime('+30 days'));
            }

            // Default period_days to 30
            $period_days = 30;

            // 1. Process Customer
            $customer_id = null;
            if (!empty($phone)) {
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ? AND store_id = ? LIMIT 1");
                $stmt->execute([$phone, $current_store_id]);
                $customer_id = $stmt->fetchColumn();
            }

            if (!$customer_id) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO customers (name, phone, address, identity_card, store_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $phone, $address, $cmnd, $current_store_id]);
                    $customer_id = $pdo->lastInsertId();
                } catch (Exception $e) {
                    $skipped++;
                    $skip_reason .= "Dòng " . ($idx + 2) . ": Lỗi lưu khách hàng [$name] - " . $e->getMessage() . "<br>";
                    continue;
                }
            }

            // 2. Process Loan
            $stmt = $pdo->prepare("SELECT id FROM loans WHERE loan_code = ? AND store_id = ? LIMIT 1");
            $stmt->execute([$code, $current_store_id]);
            if (!$stmt->fetch()) {
                // 2b. Parse Next Payment Date (Column 11/K -> index 10)
                $next_payment_str = $data[10] ?? '';
                $next_payment_date = parseDate($next_payment_str);

                // 2c. Parse "Paid Until Date" (Column 9/I -> index 8)
                $paid_until_str = $data[8] ?? '';
                $paid_until_date = parseDate($paid_until_str);

                // 2d. Parse "Old Debt" (Column 15/O -> index 14)
                $old_debt_str = $data[14] ?? '0';
                $old_debt = parseAmount($old_debt_str);

                // NEW: Store the actual original date from Excel (Column F)
                $original_start_date = parseDate($start_date_str);

                $stmt = $pdo->prepare("INSERT INTO loans
                    (store_id, customer_id, loan_code, amount, interest_rate, period_days, start_date, original_start_date, paid_until_date, next_payment_date, end_date, contract_note, status, loan_type, old_debt, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

                try {
                    $stmt->execute([
                        $current_store_id,
                        $customer_id,
                        $code,
                        $amount,
                        $rate,
                        $period_days,
                        $start_date,      // Calculated Start Date (for Interest)
                        $original_start_date, // Real Original Date (from Excel)
                        $paid_until_date,
                        $next_payment_date,
                        $end_date,
                        $note,  // Actually maps to contract_note if schema matches but query field name above uses contract_note
                        $import_status,
                        'tin_chap',
                        $old_debt
                    ]);
                    $loan_id = $pdo->lastInsertId();
                    $count++;

                    // 3. Process Initial Interest Payment (Original Amount) (Column 10/J -> index 9)
                    $paid_interest_str = $data[9] ?? '0';
                    $paid_interest = parseAmount($paid_interest_str);
                    if ($paid_interest > 0) {
                        $stmt = $pdo->prepare("INSERT INTO transactions (loan_id, type, amount, date, note, store_id) VALUES (?, 'collect_interest', ?, ?, ?, ?)");
                        $stmt->execute([$loan_id, $paid_interest, $start_date, "Tiền lãi đã đóng (Import)", $current_store_id]);
                    }

                    // 4. Correction Transaction removed. We trust the imported dates and amounts as is.
                } catch (Exception $e) {
                    $skipped++;
                    $skip_reason .= "Dòng " . ($idx + 2) . ": Lỗi lưu hợp đồng [$code] - " . $e->getMessage() . "<br>";
                    continue;
                }


            } else {
                $skipped++;
                $skip_reason .= "Dòng " . ($idx + 2) . ": Mã HĐ đã tồn tại ($code)<br>";
            }
        } // End Loop

        if ($count == 0) {
            $message = "<div class='alert alert-warning'>Không nhập được hợp đồng nào!</div>";
            if ($skipped > 0) {
                $message .= "<div class='alert alert-info'>Đã bỏ qua $skipped dòng. <br>Lý do (dòng đầu): $skip_reason</div>";
            }
            // Add Debug Table
            // ...
        } else {
            $message = "Đã nhập thành công $count hợp đồng!";
            if ($skipped > 0) {
                $message .= " (Bỏ qua $skipped dòng)<br><br><strong>Chi tiết lỗi:</strong><br>" . $skip_reason;
            }
        }
    } else {
        $error = "Lỗi upload file!";
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nhập liệu từ Excel (XLSX/CSV)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/style.css">
</head>

<body>
    <?php include 'header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div
                    class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Nhập dữ liệu hợp đồng</h1>
                    <a href="contracts.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Quay
                        lại</a>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-success shadow-sm">
                        <i class="fas fa-check-circle me-2"></i> <?php echo $message; ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger shadow-sm">
                        <i class="fas fa-exclamation-triangle me-2"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <div class="card shadow-sm col-md-8 mx-auto mt-4">
                    <div class="card-body p-4">
                        <h5 class="card-title text-primary mb-4"><i class="fas fa-file-excel me-2"></i> Chọn file XLSX
                        </h5>

                        <form method="POST" enctype="multipart/form-data" class="mt-4">
                            <div class="mb-4">
                                <label for="csv_file" class="form-label fw-bold">File Excel</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-file-import"></i></span>
                                    <input class="form-control" type="file" id="csv_file" name="csv_file" accept=".xlsx"
                                        required>
                                </div>
                            </div>


                            <div class="mb-4">
                                <label for="import_status" class="form-label fw-bold">Trạng thái Hợp đồng trong file
                                    này</label>
                                <select name="import_status" id="import_status" class="form-select">
                                    <option value="active">Đang vay</option>
                                    <option value="closed">Đã đóng / Kết thúc</option>
                                    <option value="bad_debt">Nợ xấu</option>
                                </select>
                                <div class="form-text text-primary"><i class="fas fa-info-circle"></i> Chọn trạng thái
                                    này để áp
                                    dụng cho tất cả hợp đồng trong file import.</div>
                            </div>

                            <div class="d-grid mt-2">
                                <button type="submit" class="btn btn-primary btn-lg fw-bold shadow-sm">
                                    <i class="fas fa-cloud-upload-alt me-2"></i> Tải lên & Nhập dữ liệu
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>
</body>

</html>