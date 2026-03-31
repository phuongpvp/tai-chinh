<?php
session_start();
if (!isset($_SESSION['user_id']))
    header("Location: login.php");
require_once 'config.php';
require_once 'permissions_helper.php';
require_once __DIR__ . '/cv_helper.php';

// Helper: format file size
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

$id = $_GET['id'] ?? 0;
// Fetch Contract Details
$sql = "SELECT l.*, c.name as customer_name, c.phone 
        FROM loans l 
        JOIN customers c ON l.customer_id = c.id 
        WHERE l.id = ? AND l.store_id = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$id, $current_store_id]);
$loan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$loan)
    die("Không tìm thấy hợp đồng!");

// Setup Table (Lazy Init)
$conn->exec("CREATE TABLE IF NOT EXISTS loan_extensions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT,
    extension_date DATE,
    old_days INT,
    extend_days INT,
    from_date DATE,
    to_date DATE,
    note TEXT,
    FOREIGN KEY (loan_id) REFERENCES loans(id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$conn->exec("ALTER TABLE loan_extensions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
// Add user_id column if not exists
$col_check = $conn->query("SHOW COLUMNS FROM loan_extensions LIKE 'user_id'")->fetchAll();
if (empty($col_check)) {
    $conn->exec("ALTER TABLE loan_extensions ADD COLUMN user_id INT DEFAULT NULL");
}

// Ensure type column is VARCHAR to support all transaction types
try {
    $type_col = $conn->query("SHOW COLUMNS FROM transactions LIKE 'type'")->fetch(PDO::FETCH_ASSOC);
    if ($type_col && stripos($type_col['Type'], 'enum') !== false) {
        $conn->exec("ALTER TABLE transactions MODIFY COLUMN type VARCHAR(50) NOT NULL DEFAULT 'collect_interest'");
    }
} catch (Exception $e) {}

// Add created_at column to transactions for accurate timestamps
$col_check2 = $conn->query("SHOW COLUMNS FROM transactions LIKE 'created_at'")->fetchAll();
if (empty($col_check2)) {
    $conn->exec("ALTER TABLE transactions ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
}

// Setup Attachments Table (Lazy Init)
$conn->exec("CREATE TABLE IF NOT EXISTS contract_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    store_id INT NOT NULL,
    user_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(500) NOT NULL,
    file_type ENUM('document','image','video') NOT NULL DEFAULT 'document',
    file_size BIGINT DEFAULT 0,
    drive_file_id VARCHAR(255) DEFAULT NULL,
    drive_link VARCHAR(500) DEFAULT NULL,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
// Add drive columns if missing
try { $conn->exec("ALTER TABLE contract_attachments ADD COLUMN drive_file_id VARCHAR(255) DEFAULT NULL"); } catch(Exception $e) {}
try { $conn->exec("ALTER TABLE contract_attachments ADD COLUMN drive_link VARCHAR(500) DEFAULT NULL"); } catch(Exception $e) {}

// Update Enum for Transactions to support 'lend_more'
try {
    $conn->exec("ALTER TABLE transactions MODIFY COLUMN type ENUM('disburse','collect_interest','pay_principal','pay_all','expense','capital_in','lend_more','adjust_debt','pay_debt') NOT NULL");
} catch (Exception $e) {
    // Ignore if already exists or other error, mostly likely it is fine.
}

// Handle Form Submits
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'];
    $amount = isset($_POST['amount']) ? str_replace(',', '', $_POST['amount']) : 0;
    $note = $_POST['note'] ?? '';

    // --- Attachment Upload (Google Drive) ---
    if ($action == 'upload_attachment') {
        require_once __DIR__ . '/cong-viec/config.php';
        require_once __DIR__ . '/cong-viec/google_drive.php';
        $image_exts = ['jpg','jpeg','png','gif','webp','bmp'];
        $video_exts = ['mp4','mov','avi','webm','mkv'];
        $upload_msg = '';

        if (isset($_FILES['attachment_files'])) {
            try {
                $drive = new GoogleDrive();

                // Tìm/tạo subfolder cho khách (đồng bộ với CV)
                $custFolderId = $loan['cv_drive_folder_id'] ?? null;
                if (!$custFolderId) {
                    $custFolderId = $drive->createFolder($loan['customer_name'] . ' #' . $id);
                    $conn->prepare("UPDATE loans SET cv_drive_folder_id = ? WHERE id = ?")->execute([$custFolderId, $id]);
                }

                $files = $_FILES['attachment_files'];
                $count = is_array($files['name']) ? count($files['name']) : 1;

                for ($i = 0; $i < $count; $i++) {
                    $original_name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
                    $tmp_name = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                    $file_size = is_array($files['size']) ? $files['size'][$i] : $files['size'];
                    $mime_type = is_array($files['type']) ? $files['type'][$i] : $files['type'];
                    $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];

                    if ($error !== UPLOAD_ERR_OK || empty($original_name)) continue;

                    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                    if (in_array($ext, $image_exts)) {
                        $file_type = 'image';
                    } elseif (in_array($ext, $video_exts)) {
                        $file_type = 'video';
                    } else {
                        $file_type = 'document';
                    }

                    $result = $drive->uploadFile($tmp_name, $original_name, $mime_type ?: 'application/octet-stream', $custFolderId);
                    if (isset($result['id'])) {
                        $drive->makePublic($result['id']);
                        $drive_link = 'https://drive.google.com/file/d/' . $result['id'] . '/view';

                        $stmt_att = $conn->prepare("INSERT INTO contract_attachments (loan_id, store_id, user_id, file_name, original_name, file_type, file_size, drive_file_id, drive_link) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt_att->execute([$id, $current_store_id, $_SESSION['user_id'], $original_name, $original_name, $file_type, $file_size, $result['id'], $drive_link]);
                    }
                }
                $upload_msg = 'Đã upload file lên Google Drive thành công!';
            } catch (Exception $e) {
                $upload_msg = 'Lỗi upload: ' . $e->getMessage();
            }
        }

        // AJAX request → return JSON
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => $upload_msg]);
            exit;
        }
        $view_mode_param = isset($_GET['view_mode']) ? '&view_mode=' . $_GET['view_mode'] : '';
        header("Location: contract_view.php?id=$id&msg=" . urlencode($upload_msg) . "&tab=attachments" . $view_mode_param);
        exit;
    }

    // --- Attachment Delete (Google Drive) ---
    if ($action == 'delete_attachment') {
        $att_id = $_POST['attachment_id'] ?? 0;
        $stmt_get_att = $conn->prepare("SELECT * FROM contract_attachments WHERE id = ? AND loan_id = ? AND store_id = ?");
        $stmt_get_att->execute([$att_id, $id, $current_store_id]);
        $att = $stmt_get_att->fetch(PDO::FETCH_ASSOC);

        if ($att) {
            // Xóa trên Drive nếu có
            if (!empty($att['drive_file_id'])) {
                require_once __DIR__ . '/cong-viec/config.php';
                require_once __DIR__ . '/cong-viec/google_drive.php';
                try {
                    $drive = new GoogleDrive();
                    $drive->deleteFile($att['drive_file_id']);
                } catch (Exception $e) { /* ignore */ }
            } else {
                // Fallback: xóa file local cũ
                $file_path = __DIR__ . '/uploads/contracts/' . $id . '/' . $att['file_name'];
                if (file_exists($file_path)) unlink($file_path);
            }
            $stmt_del_att = $conn->prepare("DELETE FROM contract_attachments WHERE id = ?");
            $stmt_del_att->execute([$att_id]);
        }

        $view_mode_param = isset($_GET['view_mode']) ? '&view_mode=' . $_GET['view_mode'] : '';
        header("Location: contract_view.php?id=$id&msg=" . urlencode('Đã xóa file!') . "&tab=interest" . $view_mode_param);
        exit;
    }
    $date = date('Y-m-d');

    // Preserve view_mode for redirects
    $view_mode_param = isset($_GET['view_mode']) ? '&view_mode=' . $_GET['view_mode'] : '';
    if (empty($view_mode_param) && isset($_POST['view_mode'])) {
        $view_mode_param = '&view_mode=' . $_POST['view_mode'];
    }


    if ($action == 'update_note') {
        $contract_note = $_POST['contract_note'] ?? '';
        $loan_id = $_POST['loan_id'] ?? $id;

        // Create note_history table if not exists
        $conn->exec("CREATE TABLE IF NOT EXISTS note_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            loan_id INT NOT NULL,
            user_id INT NOT NULL,
            note_content TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Log to history (only if note is not empty)
        if (!empty(trim($contract_note))) {
            $stmt_history = $conn->prepare("INSERT INTO note_history (loan_id, user_id, note_content) VALUES (?, ?, ?)");
            $stmt_history->execute([$loan_id, $_SESSION['user_id'], $contract_note]);
        }

        // Clear the contract_note field after saving to history
        $stmt = $conn->prepare("UPDATE loans SET contract_note = '' WHERE id = ? AND store_id = ?");
        $stmt->execute([$loan_id, $current_store_id]);

        $msg = "Đã lưu ghi chú thành công!";
        header("Location: contract_view.php?id=$loan_id&msg=" . urlencode($msg) . "&tab=notes" . $view_mode_param);
        exit;
    }


    if ($action == 'pay_interest') {
        $stmt_trans = $conn->prepare("INSERT INTO transactions (loan_id, type, amount, date, note, store_id, user_id) VALUES (?, 'collect_interest', ?, ?, ?, ?, ?)");
        $stmt_trans->execute([$id, $amount, $date, $note, $current_store_id, $_SESSION['user_id']]);
        $msg = "Đã thu lãi thành công!";
        header("Location: contract_view.php?id=$id&msg=" . urlencode($msg) . $view_mode_param);
        exit;
    } elseif ($action == 'pay_multischedule') {
        $msg_count = 0;
        $max_paid_date = null;
        if (isset($_POST['periods']) && is_array($_POST['periods'])) {
            foreach ($_POST['periods'] as $p_val) {
                $parts = explode('|', $p_val);
                if (count($parts) == 4) {
                    $amount = str_replace(',', '', $parts[1]);
                    $note = "Đóng lãi kỳ " . $parts[0] . " (" . $parts[2] . " - " . $parts[3] . ")";

                    // Track max date
                    $d = DateTime::createFromFormat('d-m-Y', $parts[3]);
                    if ($d) {
                        $db_date = $d->format('Y-m-d');
                        if (!$max_paid_date || $db_date > $max_paid_date) {
                            $max_paid_date = $db_date;
                        }
                    }

                    // PREVENT DUPLICATE: Check if this specific period note exists
                    $check_stmt = $conn->prepare("SELECT id FROM transactions WHERE loan_id = ? AND note = ? AND store_id = ?");
                    $check_stmt->execute([$id, $note, $current_store_id]);

                    if ($check_stmt->rowCount() == 0) {
                        $stmt_trans = $conn->prepare("INSERT INTO transactions (loan_id, type, amount, date, note, store_id, user_id) VALUES (?, 'collect_interest', ?, ?, ?, ?, ?)");
                        $stmt_trans->execute([$id, $amount, $date, $note, $current_store_id, $_SESSION['user_id']]);
                        $msg_count++;
                    }
                }
            }
        }

        // Update Paid Until Date AND Next Payment Date
        if ($max_paid_date) {
            $curr = $loan['paid_until_date'];
            if (!$curr || $max_paid_date > $curr) {
                // Calculate next payment date = max_paid_date + 1 day (Start of next period)
                // Example: Paid until 26-03 -> Next due is 27-03
                $next_payment_ts = strtotime($max_paid_date) + 86400; // +1 day
                $next_payment_date = date('Y-m-d', $next_payment_ts);

                $upd = $conn->prepare("UPDATE loans SET paid_until_date = ?, next_payment_date = ?, is_hidden_from_reminder = 0, appointment_date = NULL WHERE id = ? AND store_id = ?");
                $upd->execute([$max_paid_date, $next_payment_date, $id, $current_store_id]);
            }
        }

        $msg = "Đã đóng lãi thành công $msg_count kỳ!";
        cvAutoTransferOnPayment($conn, $id, $_SESSION['user_id']);
        header("Location: contract_view.php?id=$id&msg=" . urlencode($msg) . $view_mode_param);
        exit;
    } elseif ($action == 'pay_custom') {
        $from_date = $_POST['custom_from_date'];
        $to_date = $_POST['custom_to_date'];
        $amount_paid = str_replace(['.', ','], '', $_POST['custom_amount']);
        $other_costs = isset($_POST['custom_other_costs']) ? intval(str_replace(['.', ','], '', $_POST['custom_other_costs'])) : 0;

        $diff_time = abs(strtotime($to_date) - strtotime($from_date));
        $diff_days = ceil($diff_time / (60 * 60 * 24)) + 1; // +1 to include both start and end date

        $expected_interest = 0;
        if ($loan['interest_type'] == 'ngay' || $loan['interest_rate'] > 100) {
            $rate_real = $loan['interest_rate'];
            $mult = ($rate_real < 500) ? 1000 : 1;
            $expected_interest = ($loan['amount'] / 1000000) * ($rate_real * $mult) * $diff_days;
        } else {
            $daily_int = ($loan['amount'] * ($loan['interest_rate'] / 100)) / 30;
            $expected_interest = $daily_int * $diff_days;
        }
        // $expected_interest = round($expected_interest / 1000) * 1000; // Removed rounding per user request
        $expected_interest = round($expected_interest); // Round to integer to match UI

        // Calculate overpayment or underpayment
        $overpayment = 0;
        $underpayment = 0;

        if ($amount_paid > $expected_interest) {
            $overpayment = $amount_paid - $expected_interest;
        } elseif ($amount_paid < $expected_interest) {
            $underpayment = $expected_interest - $amount_paid;
        }

        // Record payment with overpayment/underpayment (NO principal reduction)
        $note_int = "Đóng lãi từ " . date('d/m/Y', strtotime($from_date)) . " đến " . date('d/m/Y', strtotime($to_date));
        if ($other_costs > 0) {
            $note_int .= " + Tiền khác: " . number_format($other_costs);
        }

        // Insert into transactions table
        $stmt_trans = $conn->prepare("INSERT INTO transactions (loan_id, type, amount, date, note, store_id, user_id) VALUES (?, 'collect_interest', ?, ?, ?, ?, ?)");
        $stmt_trans->execute([$id, $amount_paid, $date, $note_int, $current_store_id, $_SESSION['user_id']]);

        // Also insert into payment_history with overpayment/underpayment tracking
        try {
            $stmt_payment = $conn->prepare("INSERT INTO payment_history (loan_id, payment_date, interest_amount, amount_paid, overpayment, underpayment, period_start, period_end, store_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_payment->execute([$id, $date, $expected_interest, $amount_paid, $overpayment, $underpayment, $from_date, $to_date, $current_store_id]);
        } catch (PDOException $e) {
            // Table might not exist yet, ignore
        }

        // Update paid_until_date to mark this period as paid (auto-tick checkbox)
        $current_paid_until = $loan['paid_until_date'];
        if (!$current_paid_until || $to_date > $current_paid_until) {
            $stmt_update = $conn->prepare("UPDATE loans SET paid_until_date = ? WHERE id = ? AND store_id = ?");
            $stmt_update->execute([$to_date, $id, $current_store_id]);

            // Also update next_payment_date
            $period_days = $loan['period_days'] > 0 ? $loan['period_days'] : 30;
            $next_payment = date('Y-m-d', strtotime($to_date . " + $period_days days"));
            $stmt_next = $conn->prepare("UPDATE loans SET next_payment_date = ?, is_hidden_from_reminder = 0, appointment_date = NULL WHERE id = ? AND store_id = ?");
            $stmt_next->execute([$next_payment, $id, $current_store_id]);
        }

        // Build success message
        if ($overpayment > 0) {
            $msg = "Đã đóng lãi thành công! Tiền thừa: " . number_format($overpayment) . " VNĐ";
        } elseif ($underpayment > 0) {
            $msg = "Đã đóng lãi thành công! Nợ cũ: " . number_format($underpayment) . " VNĐ";
        } else {
            $msg = "Đã đóng lãi thành công!";
        }

        header("Location: contract_view.php?id=$id&msg=" . urlencode($msg) . $view_mode_param);
        cvAutoTransferOnPayment($conn, $id, $_SESSION['user_id']);
        exit;


    } elseif ($action == 'extend') {
        $extend_days = $_POST['extend_days'];
        $current_end_date = !empty($loan['end_date']) ? $loan['end_date'] : date('Y-m-d', strtotime($loan['start_date'] . ' + ' . $loan['period_days'] . ' days'));
        $new_end_date = date('Y-m-d', strtotime($current_end_date . ' + ' . $extend_days . ' days'));

        $stmt_ext = $conn->prepare("INSERT INTO loan_extensions (loan_id, extension_date, old_days, extend_days, from_date, to_date, note, store_id, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_ext->execute([$id, $date, $loan['period_days'], $extend_days, $current_end_date, $new_end_date, $note, $current_store_id, $_SESSION['user_id']]);

        $stmt_update = $conn->prepare("UPDATE loans SET end_date = ? WHERE id = ? AND store_id = ?");
        $stmt_update->execute([$new_end_date, $id, $current_store_id]);

        if ($amount > 0) {
            $stmt_trans = $conn->prepare("INSERT INTO transactions (loan_id, type, amount, date, note, store_id, user_id) VALUES (?, 'collect_interest', ?, ?, ?, ?, ?)");
            $stmt_trans->execute([$id, $amount, $date, "Đóng lãi gia hạn ($extend_days ngày)", $current_store_id, $_SESSION['user_id']]);
        }
        $msg = "Đã gia hạn hợp đồng thêm $extend_days ngày!";
        header("Location: contract_view.php?id=$id&msg=" . urlencode($msg) . $view_mode_param);
        exit;

    } elseif ($action == 'close_contract') {
        try {
            $final_interest = $_POST['final_interest'];
            $other_costs = str_replace(['.', ','], '', $_POST['other_costs']);
            $close_date = $_POST['close_date'] ?? $date;

            $remaining_principal = $loan['amount'];
            $total_close = $remaining_principal + $final_interest + $other_costs;
            $close_note = "Đóng hợp đồng (Gốc: " . number_format($remaining_principal) . ", Lãi: " . number_format($final_interest) . ($other_costs > 0 ? ", Khác: " . number_format($other_costs) : '') . ")";
            $stmt_close = $conn->prepare("INSERT INTO transactions (loan_id, type, amount, date, note, store_id, user_id) VALUES (?, 'pay_all', ?, ?, ?, ?, ?)");
            $stmt_close->execute([$id, $total_close, $close_date, $close_note, $current_store_id, $_SESSION['user_id']]);

            $stmt_update = $conn->prepare("UPDATE loans SET amount = 0, status = 'closed', end_date = ? WHERE id = ? AND store_id = ?");
            $stmt_update->execute([$close_date, $id, $current_store_id]);
            cvAutoTransferOnPayment($conn, $id, $_SESSION['user_id']);

            $msg = "Đã tất toán hợp đồng thành công!";
            header("Location: contract_view.php?id=$id&msg=" . urlencode($msg) . $view_mode_param);
            exit;
        } catch (Exception $e) {
            die("Lỗi đóng HĐ: " . $e->getMessage());
        }

    } elseif ($action == 'pay_principal') {
        $amount_pay = str_replace(['.', ','], '', $_POST['amount']);
        $date_pay = $_POST['date'];
        $note_pay = $_POST['note'];

        if ($amount_pay > 0) {
            $stmt_trans = $conn->prepare("INSERT INTO transactions (loan_id, type, amount, date, note, store_id, user_id) VALUES (?, 'pay_principal', ?, ?, ?, ?, ?)");
            $stmt_trans->execute([$id, $amount_pay, $date_pay, $note_pay, $current_store_id, $_SESSION['user_id']]);

            $new_amount = $loan['amount'] - $amount_pay;
            $stmt_update = $conn->prepare("UPDATE loans SET amount = ? WHERE id = ? AND store_id = ?");
            $stmt_update->execute([$new_amount, $id, $current_store_id]);
            $msg = "Đã trả bớt gốc thành công!";
        } else {
            $msg = "Số tiền phải lớn hơn 0!";
        }
        header("Location: contract_view.php?id=$id&msg=" . urlencode($msg) . $view_mode_param);
        exit;

    } elseif ($action == 'lend_more') {
        $amount_lend = str_replace(['.', ','], '', $_POST['amount']);
        $date_lend = $_POST['date'];
        $note_lend = $_POST['note'];

        if ($amount_lend > 0) {
            $stmt_trans = $conn->prepare("INSERT INTO transactions (loan_id, type, amount, date, note, store_id, user_id) VALUES (?, 'lend_more', ?, ?, ?, ?, ?)");
            $stmt_trans->execute([$id, $amount_lend, $date_lend, $note_lend, $current_store_id, $_SESSION['user_id']]);

            $new_amount = $loan['amount'] + $amount_lend;
            $stmt_update = $conn->prepare("UPDATE loans SET amount = ? WHERE id = ? AND store_id = ?");
            $stmt_update->execute([$new_amount, $id, $current_store_id]);
            $msg = "Đã vay thêm gốc thành công!";
        } else {
            $msg = "Số tiền phải lớn hơn 0!";
        }
        header("Location: contract_view.php?id=$id&msg=" . urlencode($msg) . $view_mode_param);
        exit;

    } elseif ($action == 'pay_debt') {
        $debt_amount = str_replace(['.', ','], '', $_POST['debt_amount']);
        $debt_amount = (int) $debt_amount;
        if ($debt_amount > 0) {
            // Deduct from old_debt
            $stmt_debt = $conn->prepare("UPDATE loans SET old_debt = GREATEST(old_debt - ?, 0) WHERE id = ? AND store_id = ?");
            $stmt_debt->execute([$debt_amount, $id, $current_store_id]);

            // Log to transactions
            try {
                $stmt_trans = $conn->prepare("INSERT INTO transactions (loan_id, type, amount, date, note, store_id, user_id) VALUES (?, 'pay_debt', ?, ?, ?, ?, ?)");
                $stmt_trans->execute([$id, $debt_amount, $date, 'Khách trả nợ', $current_store_id, $_SESSION['user_id']]);
            } catch (Exception $e) {
                // Fallback: use collect_interest type if pay_debt not supported
                $stmt_trans = $conn->prepare("INSERT INTO transactions (loan_id, type, amount, date, note, store_id, user_id) VALUES (?, 'collect_interest', ?, ?, ?, ?, ?)");
                $stmt_trans->execute([$id, $debt_amount, $date, 'Khách trả nợ', $current_store_id, $_SESSION['user_id']]);
            }

            $msg = "Đã ghi nhận trả nợ " . number_format($debt_amount) . " VNĐ!";
        } else {
            $msg = "Số tiền phải lớn hơn 0!";
        }
        header("Location: contract_view.php?id=$id&msg=" . urlencode($msg) . "&tab=debt" . $view_mode_param);
        exit;

    } elseif ($action == 'delete_transaction') {
        $trans_id = $_POST['trans_id'];
        $stmt_get = $conn->prepare("SELECT * FROM transactions WHERE id = ? AND store_id = ?");
        $stmt_get->execute([$trans_id, $current_store_id]);
        $trans = $stmt_get->fetch(PDO::FETCH_ASSOC);

        if ($trans) {
            $reverse_amount = 0;
            if ($trans['type'] == 'pay_principal') {
                $reverse_amount = $trans['amount'];
            } elseif ($trans['type'] == 'disburse' || $trans['type'] == 'lend_more') {
                $reverse_amount = -$trans['amount'];
            }

            if ($reverse_amount != 0) {
                $stmt_upd = $conn->prepare("UPDATE loans SET amount = amount + ? WHERE id = ? AND store_id = ?");
                $stmt_upd->execute([$reverse_amount, $id, $current_store_id]);
            }

            $stmt_del = $conn->prepare("DELETE FROM transactions WHERE id = ? AND store_id = ?");
            $stmt_del->execute([$trans_id, $current_store_id]);
            $msg = "Đã xóa giao dịch thành công!";
        }
        header("Location: contract_view.php?id=$id&msg=" . urlencode($msg) . $view_mode_param);
        exit;
    }
}
// Check for redirect message
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}

// Fetch Extension History
$ext_stmt = $conn->prepare("SELECT le.*, u.username, u.fullname as full_name FROM loan_extensions le LEFT JOIN users u ON le.user_id = u.id WHERE le.loan_id = ? ORDER BY le.id ASC");
$ext_stmt->execute([$id]);
$extensions = $ext_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Transaction History with User Names
$hist_stmt = $conn->prepare("SELECT t.*, u.username, u.fullname as full_name 
                              FROM transactions t 
                              LEFT JOIN users u ON t.user_id = u.id 
                              WHERE t.loan_id = ? 
                              ORDER BY t.id DESC");
$hist_stmt->execute([$id]);
$history = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);

// Build unified history log (transactions + extensions)
$all_history = [];
foreach ($history as $h) {
    $all_history[] = [
        'date' => $h['created_at'] ?? $h['date'],
        'type' => $h['type'],
        'amount' => $h['amount'],
        'note' => $h['note'] ?? '',
        'user' => $h['full_name'] ?? $h['username'] ?? '',
        'source' => 'transaction',
        'sort_id' => $h['id']
    ];
}
foreach ($extensions as $ext) {
    $all_history[] = [
        'date' => $ext['extension_date'],
        'type' => 'extend',
        'amount' => 0,
        'note' => ($ext['note'] ?? 'Gia hạn') . ' (' . $ext['extend_days'] . ' ngày)',
        'user' => $ext['full_name'] ?? $ext['username'] ?? '',
        'source' => 'extension',
        'sort_id' => $ext['id'] + 900000
    ];
}
// Sort by date descending, then by id descending as tiebreaker
usort($all_history, function($a, $b) {
    $cmp = strtotime($b['date']) - strtotime($a['date']);
    if ($cmp !== 0) return $cmp;
    return $b['sort_id'] - $a['sort_id'];
});
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Chi tiết Hợp Đồng - <?php echo $loan['loan_code']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
</head>

<?php if (!isset($_GET['view_mode']) || $_GET['view_mode'] != 'modal'): ?>

    <body class="bg-light">

        <!-- Header -->
        <?php include 'header.php'; ?>

        <div class="row g-0">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 d-none d-md-block bg-white sidebar h-100 shadow-sm position-fixed pt-5">
                <?php include 'sidebar.php'; ?>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-5 mt-4">
            <?php else: ?>

                <body class="bg-white">
                    <div class="container-fluid p-0">
                    <?php endif; ?>
                    <?php if (!isset($_GET['view_mode']) || $_GET['view_mode'] != 'modal'): ?>
                        <?php // include 'navbar.php' was removed in favor of header.php + sidebar.php layout 
                            ?>
                    <?php endif; ?>

                    <div class="container-fluid content-wrapper">
                        <?php if (isset($_GET['msg'])): ?>
                            <script>
                                if (window.parent) {
                                    window.parent.contractChanged = true;
                                }
                            </script>
                        <?php endif; ?>
                        <h5 class="my-3 text-secondary">Chi tiết hợp đồng</h5>

                        <!-- Info Cards -->
                        <div class="row mb-4">
                            <!-- Left Info Block -->
                            <div class="col-md-6">
                                <div class="card shadow-sm h-100">
                                    <div class="card-body p-0">
                                        <table class="table table-bordered mb-0 align-middle">
                                            <tr>
                                                <td colspan="3"
                                                    class="fw-bold text-danger text-uppercase fs-5 py-2 px-3">
                                                    <?php echo $loan['customer_name']; ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold bg-light" style="width: 30%;">Tiền vay</td>
                                                <td colspan="2" class="text-end fw-bold fs-5">
                                                    <?php echo number_format($loan['amount']); ?> VNĐ
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold bg-light">Lãi phí</td>
                                                <td colspan="2" class="text-end fw-bold">
                                                    <?php
                                                    if ($loan['interest_type'] == 'ngay' || $loan['interest_rate'] > 100) {
                                                        echo number_format($loan['interest_rate']) . ' đ/1tr/ngày';
                                                    } else {
                                                        echo $loan['interest_rate'] . '%';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold bg-light">Vay từ ngày</td>
                                                <td colspan="2" class="text-center">
                                                    <?php echo date('d-m-Y', strtotime($loan['start_date'])); ?>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- Right Info Block -->
                            <div class="col-md-6">
                                <div class="card shadow-sm h-100">
                                    <div class="card-body p-0">
                                        <table class="table table-bordered mb-0 align-middle">
                                            <tr>
                                                <td class="fw-bold bg-light" style="width: 40%;">Tổng lãi đã thu</td>
                                                <td class="text-end fw-bold fs-5 text-success">
                                                    <?php
                                                    $total_int = 0;
                                                    foreach ($history as $h) {
                                                        if ($h['type'] == 'collect_interest')
                                                            $total_int += $h['amount'];
                                                    }
                                                    echo number_format($total_int);
                                                    ?> VNĐ
                                                </td>
                                            </tr>

                                            <?php
                                            // Calculate total overpayment and underpayment
                                            $total_overpayment = 0;
                                            $total_underpayment = 0;

                                            try {
                                                $stmt_payment = $conn->prepare("SELECT SUM(overpayment) as total_over, SUM(underpayment) as total_under FROM payment_history WHERE loan_id = ? AND store_id = ?");
                                                $stmt_payment->execute([$id, $current_store_id]);
                                                $payment_totals = $stmt_payment->fetch(PDO::FETCH_ASSOC);

                                                if ($payment_totals) {
                                                    $total_overpayment = floatval($payment_totals['total_over'] ?? 0);
                                                    $total_underpayment = floatval($payment_totals['total_under'] ?? 0);
                                                }
                                            } catch (PDOException $e) {
                                                // Table might not exist yet
                                            }

                                            // Calculate net balance (overpayment - underpayment)
                                            $cumulative_from_payments = $total_underpayment - $total_overpayment;
                                            $imported_old_debt = $loan['old_debt'] ?? 0;
                                            $total_old_debt = $imported_old_debt + $cumulative_from_payments;
                                            $net_balance = -$total_old_debt; // Negative means debt, positive means credit
                                            
                                            if ($total_old_debt != 0):
                                                ?>
                                                <tr>
                                                    <td class="fw-bold bg-light">
                                                        <?php echo ($total_old_debt < 0) ? 'Tiền thừa' : 'Nợ cũ'; ?>
                                                    </td>
                                                    <td class="text-end fw-bold text-danger">
                                                        <?php echo number_format(abs($total_old_debt)); ?> VNĐ
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                            <tr>
                                                <td class="fw-bold bg-light">Trạng thái</td>
                                                <td class="text-end">
                                                    <?php
                                                        $status = $loan['status'] ?? 'active';
                                                        $display_status = 'Đang vay';
                                                        $badge_class = 'badge bg-success rounded-pill px-3';

                                                        // Calculate display status based on next_payment_date (same logic as contracts.php)
                                                        if ($status == 'active') {
                                                            $next_pay = $loan['next_payment_date'] ?? null;
                                                            if ($next_pay) {
                                                                $next_pay_ts = strtotime($next_pay);
                                                                $today_ts = strtotime(date('Y-m-d'));
                                                                if ($today_ts >= $next_pay_ts) {
                                                                    $display_status = 'Nợ lãi phí';
                                                                    $badge_class = 'badge bg-warning text-dark rounded-pill px-3';
                                                                }
                                                            }
                                                        } elseif ($status == 'bad_debt') {
                                                            $display_status = 'Nợ xấu';
                                                            $badge_class = 'badge bg-danger rounded-pill px-3';
                                                        } elseif ($status == 'closed') {
                                                            $display_status = 'Đã kết thúc';
                                                            $badge_class = 'badge bg-secondary rounded-pill px-3';
                                                        }

                                                        echo '<span class="' . $badge_class . '">' . $display_status . '</span>';
                                                    ?>
                                                </td>
                                            </tr>
                                        </table>


                                    </div>
                                </div>
                            </div>
                        </div>


                        <?php
                        // Fetch attachments for this contract
                        $att_stmt = $conn->prepare("SELECT * FROM contract_attachments WHERE loan_id = ? AND store_id = ? ORDER BY uploaded_at DESC");
                        $att_stmt->execute([$id, $current_store_id]);
                        $attachments = $att_stmt->fetchAll(PDO::FETCH_ASSOC);
                        $att_docs = array_filter($attachments, fn($a) => $a['file_type'] == 'document');
                        $att_images = array_filter($attachments, fn($a) => $a['file_type'] == 'image');
                        $att_videos = array_filter($attachments, fn($a) => $a['file_type'] == 'video');
                        ?>

                        <!-- Action Tabs -->
                        <div class="card shadow-sm">
                            <?php $active_tab = $_GET['tab'] ?? 'interest'; ?>
                            <div class="card-header bg-white p-0">
                                <ul class="nav nav-tabs card-header-tabs m-0" id="myTab" role="tablist">
                                    <?php if (hasPermission($conn, 'contracts.pay_interest')): ?>
                                        <li class="nav-item"><button
                                                class="nav-link <?php echo ($active_tab == 'interest') ? 'active' : ''; ?>"
                                                id="interest-tab" data-bs-toggle="tab" data-bs-target="#interest"
                                                type="button">Đóng lãi
                                                phí</button></li>
                                    <?php endif; ?>

                                    <?php if (hasPermission($conn, 'contracts.extend')): ?>
                                        <li class="nav-item"><button
                                                class="nav-link <?php echo ($active_tab == 'extend') ? 'active' : ''; ?>"
                                                id="extend-tab" data-bs-toggle="tab" data-bs-target="#extend"
                                                type="button">Gia hạn</button></li>
                                    <?php endif; ?>

                                    <?php if (hasPermission($conn, 'contracts.close')): ?>
                                        <li class="nav-item"><button
                                                class="nav-link <?php echo ($active_tab == 'close') ? 'active' : ''; ?>"
                                                id="close-tab" data-bs-toggle="tab" data-bs-target="#close"
                                                type="button">Đóng HĐ</button></li>
                                    <?php endif; ?>

                                    <?php if (hasPermission($conn, 'contracts.repay_principal')): ?>
                                        <li class="nav-item"><button
                                                class="nav-link <?php echo ($active_tab == 'principal') ? 'active' : ''; ?>"
                                                id="principal-tab" data-bs-toggle="tab" data-bs-target="#principal"
                                                type="button">Trả bớt
                                                gốc</button></li>
                                    <?php endif; ?>

                                    <?php if (hasPermission($conn, 'contracts.add_installment')): ?>
                                        <li class="nav-item"><button
                                                class="nav-link <?php echo ($active_tab == 'topup') ? 'active' : ''; ?>"
                                                id="topup-tab" data-bs-toggle="tab" data-bs-target="#topup"
                                                type="button">Vay thêm</button></li>
                                    <?php endif; ?>

                                    <li class="nav-item"><button
                                            class="nav-link <?php echo ($active_tab == 'debt') ? 'active' : ''; ?>"
                                            id="debt-tab" data-bs-toggle="tab" data-bs-target="#debt"
                                            type="button">Nợ</button></li>

                                    <li class="nav-item"><button
                                            class="nav-link <?php echo ($active_tab == 'notes') ? 'active' : ''; ?>"
                                            id="notes-tab" data-bs-toggle="tab" data-bs-target="#notes" type="button"
                                            role="tab">Ghi chú</button>
                                    </li>

                                    <li class="nav-item"><button
                                            class="nav-link <?php echo ($active_tab == 'history') ? 'active' : ''; ?>"
                                            id="history-tab" data-bs-toggle="tab" data-bs-target="#history"
                                            type="button" role="tab">Lịch sử</button>
                                    </li>

                                    <li class="nav-item"><button
                                            class="nav-link <?php echo ($active_tab == 'attachments') ? 'active' : ''; ?>"
                                            id="attachments-tab" data-bs-toggle="tab" data-bs-target="#attachments"
                                            type="button" role="tab"><i class="fas fa-paperclip"></i> Đính kèm</button>
                                    </li>
                                </ul>
                            </div>
                            <div class="card-body">
                                <div class="tab-content">
                                    <div class="tab-pane fade <?php echo ($active_tab == 'interest') ? 'show active' : ''; ?>"
                                        id="interest">

                                        <!-- Custom Interest Payment Section -->
                                        <div class="mb-3">
                                            <a href="#customPay" data-bs-toggle="collapse"
                                                class="text-decoration-none fw-bold text-primary">
                                                <i class="fas fa-angle-double-right"></i> Đóng lãi phí tùy biến theo
                                                ngày
                                            </a>
                                            <div class="collapse mt-2 border p-3 bg-light rounded" id="customPay">
                                                <form method="POST" action=""
                                                    onsubmit="return confirm('Xác nhận đóng lãi tùy biến?');">
                                                    <input type="hidden" name="action" value="pay_custom">
                                                    <div class="row g-3 align-items-center">
                                                        <div class="col-md-2 text-end fw-bold">Từ ngày:</div>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control-plaintext" readonly
                                                                value="<?php echo date('d-m-Y', strtotime($loan['start_date'])); ?>">
                                                            <input type="hidden" name="custom_from_date"
                                                                id="custom_from_date"
                                                                value="<?php echo $loan['start_date']; ?>">
                                                        </div>
                                                        <div class="col-md-7"></div>

                                                        <div class="col-md-2 text-end fw-bold">Đến ngày:</div>
                                                        <div class="col-md-3">
                                                            <input type="date" name="custom_to_date" id="custom_to_date"
                                                                class="form-control"
                                                                value="<?php echo date('Y-m-d'); ?>" required>
                                                        </div>
                                                        <div class="col-md-7">
                                                            ( Ngày đóng lãi phí tiếp: <span id="lblNextDate"
                                                                class="text-primary fw-bold"></span> )
                                                        </div>

                                                        <div class="col-md-2 text-end fw-bold">Số ngày:</div>
                                                        <div class="col-md-3">
                                                            <input type="number" id="custom_days" class="form-control"
                                                                value="<?php echo $loan['period_days'] > 0 ? $loan['period_days'] : 30; ?>">
                                                        </div>
                                                        <div class="col-md-7 text-primary fw-bold">Ngày</div>

                                                        <div class="col-md-2 text-end fw-bold">Tiền lãi phí:</div>
                                                        <div class="col-md-3">
                                                            <span id="custom_expected_interest_display"
                                                                class="fw-bold text-success"></span>
                                                            <input type="hidden" name="custom_expected_interest"
                                                                id="custom_expected_interest">
                                                        </div>
                                                        <div class="col-md-7"></div>

                                                        <div class="col-md-2 text-end fw-bold">Tiền khác:</div>
                                                        <div class="col-md-3">
                                                            <input type="text" name="custom_other_costs" id="custom_other_costs"
                                                                class="form-control number-separator" value="0">
                                                        </div>
                                                        <div class="col-md-7"></div>

                                                        <div class="col-md-2 text-end fw-bold">Tổng tiền lãi phí:</div>
                                                        <div class="col-md-3">
                                                            <span id="custom_total_display"
                                                                class="fw-bold text-danger fs-5"></span>
                                                            <input type="hidden" name="custom_amount" id="custom_amount">
                                                        </div>
                                                        <div class="col-md-7"></div>

                                                        <div class="col-12 text-center mt-3">
                                                            <button type="submit" class="btn btn-primary">Đóng
                                                                lãi</button>
                                                        </div>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>

                                        <script>
                                            document.addEventListener("DOMContentLoaded", function () {
                                                const fromDateInput = document.getElementById('custom_from_date');
                                                const toDateInput = document.getElementById('custom_to_date');
                                                const daysInput = document.getElementById('custom_days');
                                                const expectedDisplay = document.getElementById('custom_expected_interest_display');
                                                const expectedInput = document.getElementById('custom_expected_interest');
                                                const otherCostsInput = document.getElementById('custom_other_costs');
                                                const totalDisplay = document.getElementById('custom_total_display');
                                                const totalInput = document.getElementById('custom_amount');
                                                const nextDateLbl = document.getElementById('lblNextDate');

                                                const loanAmount = <?php echo $loan['amount']; ?>;
                                                const interestRate = <?php echo $loan['interest_rate']; ?>;
                                                const interestType = '<?php echo $loan['interest_type']; ?>';

                                                let currentInterest = 0;

                                                function calculateCustomInterest() {
                                                    const fromDate = new Date(fromDateInput.value);
                                                    const toDate = new Date(toDateInput.value);
                                                    if (toDate > fromDate) {
                                                        const diffDays = Math.ceil(Math.abs(toDate - fromDate) / (1000 * 60 * 60 * 24)) + 1;
                                                        daysInput.value = diffDays;
                                                        updateInterestDisplay(diffDays);
                                                        updateNextDate(toDate);
                                                    }
                                                }

                                                function calculateFromDays() {
                                                    const days = parseInt(daysInput.value);
                                                    const fromDate = new Date(fromDateInput.value);
                                                    if (days > 0) {
                                                        const toDate = new Date(fromDate);
                                                        toDate.setDate(fromDate.getDate() + days - 1);
                                                        toDateInput.value = toDate.toISOString().split('T')[0];
                                                        updateInterestDisplay(days);
                                                        updateNextDate(toDate);
                                                    }
                                                }

                                                function updateNextDate(toDateObj) {
                                                    const nextDate = new Date(toDateObj);
                                                    nextDate.setDate(nextDate.getDate() + 1);
                                                    nextDateLbl.innerText = nextDate.toLocaleDateString('en-GB').split('/').join('-');
                                                }

                                                function updateInterestDisplay(days) {
                                                    let interest = 0;
                                                    if (interestType === 'ngay' || interestRate > 100) {
                                                        let rateReal = interestRate;
                                                        let mult = (rateReal < 500) ? 1000 : 1;
                                                        interest = (loanAmount / 1000000) * (rateReal * mult) * days;
                                                    } else {
                                                        interest = (loanAmount * (interestRate / 100)) / 30 * days;
                                                    }
                                                    interest = Math.round(interest);
                                                    currentInterest = interest;
                                                    expectedInput.value = interest;
                                                    expectedDisplay.innerText = new Intl.NumberFormat('vi-VN').format(interest) + ' vnđ';
                                                    updateTotal();
                                                }

                                                function updateTotal() {
                                                    let otherCosts = parseInt((otherCostsInput.value || '0').replace(/[^0-9]/g, '')) || 0;
                                                    let total = currentInterest + otherCosts;
                                                    totalInput.value = total;
                                                    totalDisplay.innerText = new Intl.NumberFormat('vi-VN').format(total);
                                                }

                                                toDateInput.addEventListener('change', calculateCustomInterest);
                                                daysInput.addEventListener('input', calculateFromDays);
                                                otherCostsInput.addEventListener('input', function (e) {
                                                    let val = e.target.value.replace(/[^0-9]/g, '');
                                                    e.target.value = val ? new Intl.NumberFormat('vi-VN').format(parseInt(val)) : '0';
                                                    updateTotal();
                                                });

                                                // Init: calculate from default days (payment cycle)
                                                calculateFromDays();
                                            });
                                        </script>


                                        <!-- AJAX Payment Script -->
                                        <script>
                                            function togglePeriodPayment(checkbox, loanId, fromDate, toDate, amount) {
                                                // Disable to prevent multiple clicks
                                                checkbox.disabled = true;

                                                // Determine action
                                                const action = checkbox.checked ? 'pay' : 'unpay';

                                                // Confirm if unpaying (deleting transaction)
                                                if (action === 'unpay') {
                                                    if (!confirm('Hủy đóng lãi kỳ này? Giao dịch sẽ bị xóa.')) {
                                                        checkbox.checked = true; // Revert
                                                        checkbox.disabled = false;
                                                        return;
                                                    }
                                                }

                                                // Prepare Data
                                                const formData = new FormData();
                                                formData.append('action', action);
                                                formData.append('loan_id', loanId);
                                                formData.append('from_date', fromDate);
                                                formData.append('to_date', toDate);
                                                formData.append('amount', amount);

                                                // Send AJAX
                                                fetch('contract_process_payment.php', {
                                                    method: 'POST',
                                                    body: formData
                                                })
                                                    .then(response => {
                                                        return response.text().then(text => {
                                                            try {
                                                                return JSON.parse(text);
                                                            } catch (e) {
                                                                console.error("Server Response:", text);
                                                                throw new Error("Lỗi Server (Không phải JSON): " + text.substring(0, 100)); // Show preview
                                                            }
                                                        });
                                                    })
                                                    .then(data => {
                                                        if (data.success) {
                                                            // Success: Reload popup to update states
                                                            // Flag parent to reload when modal closes
                                                            if (window.parent && window.parent !== window) {
                                                                window.parent.needsReload = true;
                                                            }
                                                            // Reload with interest tab active
                                                            const url = new URL(window.location.href);
                                                            url.searchParams.set('tab', 'interest');
                                                            window.location.href = url.toString();
                                                        } else {
                                                            alert('Lỗi: ' + data.message);
                                                            checkbox.checked = !checkbox.checked; // Revert
                                                            checkbox.disabled = false;
                                                        }
                                                    })
                                                    .catch(error => {
                                                        console.error('Error:', error);
                                                        alert('Lỗi kết nối: ' + error.message);
                                                        checkbox.checked = !checkbox.checked; // Revert
                                                        checkbox.disabled = false;
                                                    });
                                            }
                                        </script>

                                        <!-- Removed Form Wrapper -->

                                        <div class="mb-3 d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-0 fw-bold text-uppercase"><i
                                                        class="fas fa-calendar-check"></i> Lịch
                                                    đóng lãi</h6>
                                                <small class="text-muted">Tích vào ô để đóng lãi ngay lập tức.</small>
                                            </div>
                                            <!-- Button Removed -->
                                        </div>

                                        <div class="table-responsive">
                                            <table class="table table-bordered table-sm text-center align-middle"
                                                style="font-size:12px;">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>STT</th>
                                                        <th style="min-width:180px;">Ngày</th>
                                                        <th>Số ngày</th>
                                                        <th>Tiền lãi phí</th>
                                                        <th>Tiền khác</th>
                                                        <th>Tổng lãi phí</th>
                                                        <th>Tiền khách trả</th>
                                                        <th>Chọn</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    // ... (Calculation Loop Logic remains similar until Checkbox) ...
                                                    // NOTE: We need to re-implement the loop because replace_file_content replaces a block.
                                                    // Since the previous view_file didn't show the full loop logic clearly enough to copy-paste blindly,
                                                    // I will reuse the surrounding logic but insert the NEW checkbox code.
                                                    
                                                    // --- COPYING LOGIC (Simplified for Replacement Context) ---
                                                    // I must ensure I don't break the loop structure.
                                                    // The ReplacementContent MUST cover lines 608 to 860 roughly.
                                                    
                                                    // 1. Calculate Total Interest Paid So Far (including reversals)
                                                    $total_paid_interest = 0;
                                                    foreach ($history as $h) {
                                                        if ($h['type'] == 'collect_interest') {
                                                            // Include both positive and negative amounts (reversals)
                                                            $total_paid_interest += $h['amount'];
                                                        }
                                                    }

                                                    $period_days = $loan['period_days'] > 0 ? $loan['period_days'] : 30;
                                                    $original_principal = $loan['amount'];
                                                    $princ_payments = [];
                                                    foreach ($history as $h) {
                                                        if ($h['type'] == 'pay_principal') {
                                                            $original_principal += $h['amount'];
                                                            $princ_payments[] = ['date' => strtotime($h['date']), 'amount' => $h['amount']];
                                                        }
                                                        // If contract is closed, add back the principal portion
                                                        if ($h['type'] == 'pay_all') {
                                                            // Extract principal from close note or use full amount as fallback
                                                            if (preg_match('/G\x{1ed1}c: ([0-9,]+)/u', $h['note'], $m)) {
                                                                $closed_principal = (int) str_replace(',', '', $m[1]);
                                                            } else {
                                                                $closed_principal = $h['amount'];
                                                            }
                                                            $original_principal += $closed_principal;
                                                            $princ_payments[] = ['date' => strtotime($h['date']), 'amount' => $closed_principal];
                                                        }
                                                    }
                                                    usort($princ_payments, function ($a, $b) {
                                                        return $a['date'] - $b['date'];
                                                    });

                                                    function getPrincipalAtDate($target_date, $original, $payments)
                                                    {
                                                        $p = $original;
                                                        foreach ($payments as $pay) {
                                                            if ($pay['date'] < $target_date)
                                                                $p -= $pay['amount'];
                                                        }
                                                        return $p;
                                                    }

                                                    // Always start from start_date to show complete history and verify past payments
                                                    $current_start = strtotime($loan['start_date']);

                                                    $idx = 1;
                                                    $remaining_paid = $total_paid_interest;

                                                    $imported_periods = [];
                                                    foreach ($history as $h) {
                                                        if ($h['type'] == 'collect_interest' && strpos($h['note'], '(Import)') !== false) {
                                                            $imported_periods['has_import'] = true;
                                                            break;
                                                        }
                                                    }

                                                    $loan_end_timestamp = !empty($loan['end_date']) ? strtotime($loan['end_date']) : strtotime("+12 months", $current_start);
                                                    $paid_until_ts = !empty($loan['paid_until_date']) ? strtotime($loan['paid_until_date']) : 0;
                                                    $suggestion_shown = false;

                                                    while ($current_start < $loan_end_timestamp) {
                                                        // Determine the standard end date for this period (30 days default)
                                                        $standard_duration = $period_days;
                                                        $next_calc_start_std = strtotime("+$standard_duration days", $current_start);
                                                        $end_date_ts_std = strtotime("-1 day", $next_calc_start_std);

                                                        // Check for overlap with paid_until_date
                                                        // If paid_until_date is strictly INSIDE the current standard period (Start <= Paid < End)
                                                        // We cut the period short to end at paid_until_date
                                                        $is_overlap_split = false;
                                                        $actual_duration_days = $standard_duration;

                                                        if ($paid_until_ts > 0 && $paid_until_ts >= $current_start && $paid_until_ts < $end_date_ts_std) {
                                                            $end_date_ts = $paid_until_ts;
                                                            $next_calc_start = strtotime("+1 day", $end_date_ts);

                                                            // Calculate days for this specific short period
                                                            $diff = $end_date_ts - $current_start;
                                                            $actual_duration_days = round($diff / (60 * 60 * 24)) + 1;
                                                            $is_overlap_split = true;
                                                        } else {
                                                            $end_date_ts = $end_date_ts_std;
                                                            $next_calc_start = $next_calc_start_std;
                                                        }

                                                        $current_period_principal = getPrincipalAtDate($current_start, $original_principal, $princ_payments);
                                                        if ($current_period_principal < 0)
                                                            $current_period_principal = 0;

                                                        $period_interest = 0;
                                                        if ($loan['interest_type'] == 'ngay' || $loan['interest_rate'] > 100) {
                                                            $rate_real = $loan['interest_rate'];
                                                            $mult = ($rate_real < 500) ? 1000 : 1;
                                                            $period_interest = ($current_period_principal / 1000000) * ($rate_real * $mult) * $actual_duration_days;
                                                        } else {
                                                            // For monthly interest, we still prorate by days if it's a split period?
                                                            // Standard logic: (Principal * Rate% / 30) * Days
                                                            $daily_interest = ($current_period_principal * ($loan['interest_rate'] / 100)) / 30;
                                                            $period_interest = $daily_interest * $actual_duration_days;
                                                        }
                                                        $period_interest = round($period_interest); // Round to integer
                                                    
                                                        $s_date_str = date('d-m-Y', $current_start);
                                                        $e_date_str = date('d-m-Y', $end_date_ts);

                                                        // Pass Dates in Y-m-d format for JS
                                                        $s_date_iso = date('Y-m-d', $current_start);
                                                        $e_date_iso = date('Y-m-d', $end_date_ts);

                                                        $row_paid = 0;
                                                        $is_full = false;

                                                        // NEW LOGIC: Check if this period is covered by paid_until_date
                                                        // If the period END date is <= paid_until_date, then it's been paid
                                                        if ($paid_until_ts > 0 && $end_date_ts <= $paid_until_ts) {
                                                            $is_full = true;

                                                            // Try to get actual amount from payment_history
                                                            $actual_amount_paid = 0;
                                                            try {
                                                                $stmt_check = $conn->prepare("SELECT amount_paid FROM payment_history WHERE loan_id = ? AND period_start = ? AND period_end = ? AND store_id = ? ORDER BY id DESC LIMIT 1");
                                                                $stmt_check->execute([$id, $s_date_iso, $e_date_iso, $current_store_id]);
                                                                $payment_record = $stmt_check->fetch(PDO::FETCH_ASSOC);
                                                                if ($payment_record) {
                                                                    $actual_amount_paid = floatval($payment_record['amount_paid']);
                                                                }
                                                            } catch (PDOException $e) {
                                                                // Table might not exist yet
                                                            }

                                                            // If we found exact payment record, use it; otherwise use calculated interest
                                                            $row_paid = $actual_amount_paid > 0 ? $actual_amount_paid : $period_interest;
                                                        } else {
                                                            $is_full = false;
                                                            $row_paid = 0;
                                                        }
                                                        ?>
                                                        <tr class="<?php echo $is_full ? 'table-success' : ''; ?>">
                                                            <!-- Added visual highlight -->
                                                            <td><?php echo $idx; ?></td>
                                                            <td><?php echo $s_date_str; ?> <i
                                                                    class="fas fa-arrow-right text-muted mx-1"></i>
                                                                <?php echo $e_date_str; ?></td>
                                                            <td><?php echo $period_days; ?></td>
                                                            <td class="text-end">
                                                                <?php echo number_format($period_interest); ?> VNĐ
                                                            </td>
                                                            <td class="text-end">0 VNĐ</td>
                                                            <td class="text-end fw-bold">
                                                                <?php echo number_format($period_interest); ?> VNĐ
                                                            </td>
                                                            <td class="text-end fw-bold text-primary">
                                                                <?php
                                                                $display_amount = 0;
                                                                if ($is_full) {
                                                                    $display_amount = $row_paid;
                                                                } else {
                                                                    if (!$suggestion_shown) {
                                                                        $display_amount = $period_interest;
                                                                        $suggestion_shown = true;
                                                                    }
                                                                }

                                                                if ($display_amount > 0) {
                                                                    // Make it clickable with data attributes
                                                                    echo '<span class="payment-amount-editable" 
                                                                        style="cursor: pointer; text-decoration: underline;"
                                                                        data-period-start="' . $s_date_iso . '"
                                                                        data-period-end="' . $e_date_iso . '"
                                                                        data-expected-amount="' . $period_interest . '"
                                                                        data-current-amount="' . $display_amount . '"
                                                                        data-is-paid="' . ($is_full ? '1' : '0') . '"
                                                                        title="Click để điều chỉnh số tiền">
                                                                        » ' . number_format($display_amount) . '
                                                                    </span>';
                                                                }
                                                                ?>
                                                            </td>
                                                            <td class="text-center">
                                                                <?php
                                                                // Determine if this period is from import
                                                                // Logic: A period is "imported" if:
                                                                // 1. There exists an import transaction (has_import flag)
                                                                // 2. This period's end_date is BEFORE or ON the ORIGINAL paid_until_date from import
                                                                // We need to track the "original" paid_until from import, not the current one
                                                            
                                                                // Better approach: Check if there's an actual Import transaction covering this period
                                                                $is_imported = false;
                                                                if (isset($imported_periods['has_import'])) {
                                                                    // Find the earliest non-import transaction date
                                                                    // If this period ends before that, it's imported
                                                                    // Simpler: Check if loan has old_debt or if paid_until was set during import
                                                                    // Actually, let's use a different flag: initial_paid_until
                                                            
                                                                    // For now, let's assume: if paid_until exists from the start (from import),
                                                                    // we stored it somewhere. But we didn't.
                                                                    // 
                                                                    // SIMPLEST FIX: Don't lock ANY period. Let user manage.
                                                                    // OR: Only lock if there's a transaction with note containing "(Import)"
                                                                    // matching this exact period.
                                                            
                                                                    // Let's check if a transaction exists for this period with "(Import)" in note
                                                                    foreach ($history as $h) {
                                                                        if ($h['type'] == 'collect_interest' && strpos($h['note'], '(Import)') !== false) {
                                                                            // Check if this transaction's date matches this period
                                                                            // Import transactions are usually bulk, so we can't match exactly
                                                                            // Better: Just check if this period is fully paid AND ends before first non-import transaction
                                                                            // This is getting complex. Let's simplify:
                                                                            // If period is paid AND there's ANY import transaction, assume old periods are imported
                                                                            // But we need a cutoff date.
                                                            
                                                                            // Actually, the original import sets paid_until_date.
                                                                            // Any period ending BEFORE the ORIGINAL paid_until is imported.
                                                                            // But we don't store "original" paid_until separately.
                                                            
                                                                            // PRAGMATIC FIX: Don't lock any periods. User can undo mistakes.
                                                                            $is_imported = false;
                                                                            break;
                                                                        }
                                                                    }
                                                                }

                                                                if ($is_full && $is_imported):
                                                                    ?>
                                                                    <input type="checkbox"
                                                                        class="form-check-input border-secondary opacity-50 bg-secondary"
                                                                        style="transform: scale(1.3);" checked disabled
                                                                        title="Kỳ nhập liệu (không thể sửa)">
                                                                <?php elseif ($is_full && !$is_imported):
                                                                    ?>
                                                                    <input type="checkbox"
                                                                        class="form-check-input border-secondary"
                                                                        style="transform: scale(1.3);" checked
                                                                        onchange="togglePeriodPayment(this, <?php echo $loan['id']; ?>, '<?php echo $s_date_iso; ?>', '<?php echo $e_date_iso; ?>', <?php echo $period_interest; ?>)">
                                                                <?php else:
                                                                    ?>
                                                                    <input type="checkbox"
                                                                        class="form-check-input border-secondary"
                                                                        style="transform: scale(1.3);"
                                                                        onchange="togglePeriodPayment(this, <?php echo $loan['id']; ?>, '<?php echo $s_date_iso; ?>', '<?php echo $e_date_iso; ?>', <?php echo $period_interest; ?>)">
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                        <?php
                                                        $current_start = $next_calc_start;
                                                        $idx++;
                                                    }
                                                    ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        </form>
                                        <script>
                                            // Pa                  ss PHP variable to JS
                                            const baseTotalInterest = <?php echo $total_int; ?>;

                                            document.addEventListener('DOMContentLoaded', function () {
                                                const checkboxes = document.querySelectorAll('.form-check-input'); // Select checkboxes
                                                const btnPay = document.getElementById('btnPaySelected');
                                                const lblTop = document.getElementById('lblTopInterest');

                                                function updateSum() {
                                                    let sum = 0;
                                                    let count = 0;
                                                    checkboxes.forEach(chk => {
                                                        // Only count those with periods[] name
                                                        if (chk.checked && chk.name === 'periods[]') {
                                                            // Check if it was originally paid
                                                            const isOriginPaid = (chk.getAttribute('data-paid-origin') === '1');

                                                            if (!isOriginPaid) {
                                                                const parts = chk.value.split('|');
                                                                if (parts.length >= 2) {
                                                                    sum += parseFloat(parts[1]);
                                                                }
                                                                count++; // Only count NEW payments for button enablement to be safe
                                                            }
                                                        }
                                                    });

                                                    // Display Base + Selected
                                                    const totalDisplay = baseTotalInterest + sum;
                                                    const fmtTotal = new Intl.NumberFormat('vi-VN').format(totalDisplay) + ' VNĐ';

                                                    lblTop.innerText = fmtTotal;

                                                    // Highlight change?
                                                    if (sum > 0) {
                                                        lblTop.classList.add('text-danger');
                                                        lblTop.classList.remove('text-success');
                                                    } else {
                                                        lblTop.classList.add('text-success');
                                                        lblTop.classList.remove('text-danger');
                                                    }

                                                    if (count > 0) {
                                                        // btnPay.classList.remove('disabled');
                                                        // btnPay.removeAttribute('disabled');
                                                    } else {
                                                        // btnPay.classList.add('disabled');
                                                        // btnPay.setAttribute('disabled', 'disabled');
                                                    }
                                                }

                                                checkboxes.forEach(chk => {
                                                    chk.addEventListener('change', updateSum);
                                                });
                                            });
                                        </script>
                                    </div>

                                    <div class="tab-pane fade <?php echo ($active_tab == 'close') ? 'show active' : ''; ?>"
                                        id="close">
                                        <div class="card mb-3">
                                            <div class="card-header bg-light fw-bold">
                                                <i class="fas fa-file-signature"></i> Đóng hợp đồng
                                            </div>
                                            <div class="card-body">
                                                <form method="POST"
                                                    onsubmit="return confirm('Xác nhận ĐÓNG HỢP ĐỒNG và tất toán?');">
                                                    <input type="hidden" name="action" value="close_contract">

                                                    <?php
                                                    // Calculate Final Interest Amount
                                                    // Logic: Calculate total expected interest from Start Date to Today based on Principal History
                                                    // Then subtract Total Paid Interest.
                                                    
                                                    // Re-use Principal History ($princ_payments) and GetPrincipalAtDate from Interest Tab (Needs Global scope or copy)
                                                    // For simplicity and to ensure function existence, I will define a local helper or assume scope. 
                                                    // The function getPrincipalAtDate is defined inside the Loop of previous tab, so it is NOT available here!
                                                    // I must re-define or move it.
                                                    // Safe approach: Re-implement similar logic here briefly.
                                                    
                                                    $close_today = strtotime(date('Y-m-d'));

                                                    // LOGIC UPDATE: Calculate from Paid Until Date if available
                                                    // This matches the List View logic and handles imported historical data correctly.
                                                    $close_start = !empty($loan['paid_until_date']) ? strtotime($loan['paid_until_date']) : strtotime($loan['start_date']);

                                                    // Calculate Days (Simple difference)
                                                    $total_days_num = floor(($close_today - $close_start) / 86400);
                                                    if ($total_days_num < 0)
                                                        $total_days_num = 0;

                                                    // Interest Amount
                                                    // Use Current Principal (Amount)
                                                    $current_principal = $loan['amount'];

                                                    $interest_due = 0;
                                                    if ($loan['interest_type'] == 'ngay' || $loan['interest_rate'] > 100) {
                                                        $rate_real = $loan['interest_rate'];
                                                        $mult = ($rate_real < 500) ? 1000 : 1;
                                                        $interest_due = ($current_principal / 1000000) * ($rate_real * $mult) * $total_days_num;
                                                    } else {
                                                        $daily_interest = ($current_principal * ($loan['interest_rate'] / 100)) / 30;
                                                        $interest_due = $daily_interest * $total_days_num;
                                                    }

                                                    // Rounding
                                                    // $interest_due = round($interest_due / 1000) * 1000; // Removed rounding
                                                    $interest_due = round($interest_due); // Round to integer
                                                    
                                                    // Old Debt = Imported + Cumulative from payments
                                                    $imported_old_debt = $loan['old_debt'] ?? 0;
                                                    $cumulative_debt = 0;
                                                    try {
                                                        $stmt_debt = $conn->prepare("SELECT (SUM(underpayment) - SUM(overpayment)) as balance FROM payment_history WHERE loan_id = ? AND store_id = ?");
                                                        $stmt_debt->execute([$id, $current_store_id]);
                                                        $debt_result = $stmt_debt->fetch(PDO::FETCH_ASSOC);
                                                        $cumulative_debt = floatval($debt_result['balance'] ?? 0);
                                                    } catch (PDOException $e) {
                                                        // Table might not exist yet
                                                    }
                                                    $old_debt = $imported_old_debt + $cumulative_debt;

                                                    // Total Redemption = Principal + Interest + Old Debt
                                                    $final_total = $current_principal + $interest_due + $old_debt;

                                                    ?>

                                                    <div class="row mb-3">
                                                        <label class="col-sm-3 col-form-label fw-bold">Ngày đóng HĐ
                                                            :</label>
                                                        <div class="col-sm-3">
                                                            <input type="date" class="form-control" id="close_date_input" name="close_date"
                                                                value="<?php echo date('Y-m-d'); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="row mb-3">
                                                        <label class="col-sm-3 col-form-label fw-bold">Tiền cầm
                                                            :</label>
                                                        <div class="col-sm-4">
                                                            <div class="input-group">
                                                                <input type="text"
                                                                    class="form-control text-primary fw-bold"
                                                                    value="<?php echo number_format($loan['amount']); ?> vnđ"
                                                                    readonly>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row mb-3">
                                                        <label class="col-sm-3 col-form-label fw-bold">Nợ cũ :</label>
                                                        <div class="col-sm-4">
                                                            <div class="input-group">
                                                                <input type="text" class="form-control text-primary"
                                                                    value="<?php echo number_format($old_debt); ?> vnđ"
                                                                    readonly>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row mb-3">
                                                        <label class="col-sm-3 col-form-label fw-bold">Tiền lãi phí
                                                            :</label>
                                                        <div class="col-sm-4">
                                                            <div class="input-group">
                                                                <input type="text" class="form-control text-primary" data-close-interest
                                                                    value="<?php echo number_format($interest_due); ?> vnđ (<?php echo $total_days_num; ?> ngày)"
                                                                    readonly>
                                                                <input type="hidden" name="final_interest"
                                                                    value="<?php echo $interest_due; ?>">
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row mb-3">
                                                        <label class="col-sm-3 col-form-label fw-bold">Tiền khác
                                                            :</label>
                                                        <div class="col-sm-4">
                                                            <input type="text" name="other_costs" id="other_costs"
                                                                class="form-control number-separator" value="0">
                                                        </div>
                                                    </div>
                                                    <div class="row mb-3">
                                                        <label class="col-sm-3 col-form-label fw-bold">Tổng tiền chuộc
                                                            :</label>
                                                        <div class="col-sm-4">
                                                            <div class="input-group">
                                                                <input type="text" id="total_redemption"
                                                                    class="form-control text-danger fw-bold fs-5"
                                                                    value="<?php echo number_format($final_total); ?> vnđ"
                                                                    readonly>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="text-center mt-4">
                                                        <button type="submit" class="btn btn-primary px-5">Đóng
                                                            HĐ</button>
                                                    </div>
                                                </form>
                                                <script>
                                                    document.addEventListener('DOMContentLoaded', function () {
                                                        const otherInput = document.getElementById('other_costs');
                                                        const totalDisplay = document.getElementById('total_redemption');
                                                        const interestDisplay = document.querySelector('[data-close-interest]');
                                                        const closeDateInput = document.getElementById('close_date_input');
                                                        const hiddenInterest = document.querySelector('input[name="final_interest"]');

                                                        // PHP data for JS recalculation
                                                        const principal = <?php echo $current_principal; ?>;
                                                        const oldDebt = <?php echo $old_debt; ?>;
                                                        const closeStartTs = <?php echo $close_start; ?> * 1000; // ms
                                                        const interestRate = <?php echo $loan['interest_rate']; ?>;
                                                        const interestType = '<?php echo $loan['interest_type']; ?>';

                                                        function calcInterest(days) {
                                                            if (days < 0) days = 0;
                                                            let interest = 0;
                                                            if (interestType === 'ngay' || interestRate > 100) {
                                                                let mult = (interestRate < 500) ? 1000 : 1;
                                                                interest = (principal / 1000000) * (interestRate * mult) * days;
                                                            } else {
                                                                let daily = (principal * (interestRate / 100)) / 30;
                                                                interest = daily * days;
                                                            }
                                                            return Math.round(interest);
                                                        }

                                                        function updateAll() {
                                                            // Calculate days from paid_until to selected close date
                                                            const closeDate = new Date(closeDateInput.value + 'T00:00:00');
                                                            const startDate = new Date(closeStartTs);
                                                            const diffMs = closeDate - startDate;
                                                            const days = Math.floor(diffMs / 86400000);

                                                            const interest = calcInterest(days);
                                                            hiddenInterest.value = interest;

                                                            // Update interest display
                                                            if (interestDisplay) {
                                                                interestDisplay.value = new Intl.NumberFormat('vi-VN').format(interest) + ' vnđ (' + days + ' ngày)';
                                                            }

                                                            // Other costs
                                                            let otherVal = parseInt(otherInput.value.replace(/[^0-9]/g, '')) || 0;

                                                            // Total = principal + interest + oldDebt + other
                                                            let total = principal + interest + oldDebt + otherVal;
                                                            totalDisplay.value = new Intl.NumberFormat('vi-VN').format(total) + ' vnđ';
                                                        }

                                                        closeDateInput.addEventListener('change', updateAll);

                                                        otherInput.addEventListener('input', function (e) {
                                                            let val = e.target.value.replace(/[^0-9]/g, '');
                                                            if (!val) val = 0;
                                                            e.target.value = new Intl.NumberFormat('vi-VN').format(parseInt(val));
                                                            updateAll();
                                                        });
                                                    });
                                                </script>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="tab-pane fade <?php echo ($active_tab == 'extend') ? 'show active' : ''; ?>"
                                        id="extend">
                                        <div class="card mb-3">
                                            <div class="card-header bg-light fw-bold">
                                                <i class="fas fa-bars"></i> Gia hạn hợp đồng
                                            </div>
                                            <div class="card-body">
                                                <form method="POST" class="row g-3 align-items-center"
                                                    onsubmit="return confirm('Bạn có chắc chắn muốn gia hạn?');">
                                                    <input type="hidden" name="action" value="extend">

                                                    <div class="col-md-2 fw-bold text-end">Gia hạn thêm <span
                                                            class="text-danger">*</span>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <input type="number" name="extend_days" class="form-control"
                                                            value="10" required>
                                                    </div>
                                                    <div class="col-md-1 fw-bold">Ngày</div>

                                                    <div class="col-md-2 fw-bold text-end">Ghi chú</div>
                                                    <div class="col-md-3">
                                                        <textarea name="note" class="form-control"
                                                            rows="1">Gia hạn hợp đồng</textarea>
                                                    </div>

                                                    <div class="col-12 text-end">
                                                        <button type="submit" class="btn btn-primary">Đồng ý</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>

                                        <div class="table-responsive">
                                            <table class="table table-bordered text-center table-sm align-middle">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>STT</th>
                                                        <th>Gia hạn từ ngày</th>
                                                        <th>Đến ngày</th>
                                                        <th>Số ngày</th>
                                                        <th>Nội dung</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    $e_idx = 1;
                                                    if (count($extensions) > 0) {
                                                        foreach ($extensions as $ext) {
                                                            $f_date = date('d-m-Y', strtotime($ext['from_date']));
                                                            $t_date = date('d-m-Y', strtotime($ext['to_date']));
                                                            ?>
                                                                    <tr>
                                                                        <td><?php echo $e_idx++; ?></td>
                                                                        <td><?php echo $f_date; ?></td>
                                                                        <td><?php echo $t_date; ?></td>
                                                                        <td><?php echo $ext['extend_days']; ?></td>
                                                                        <td><?php echo $ext['note'] ? $ext['note'] : 'Gia hạn'; ?></td>
                                                                    </tr>
                                                                    <?php
                                                        }
                                                    } else {
                                                        echo '<tr><td colspan="5" class="text-muted">Chưa có lịch sử gia hạn</td></tr>';
                                                    }
                                                    ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <!-- Tab: Pay Principal -->
                                    <div class="tab-pane fade <?php echo ($active_tab == 'principal') ? 'show active' : ''; ?>"
                                        id="principal">
                                        <div class="card mb-3">
                                            <div class="card-header bg-light fw-bold">
                                                <i class="fas fa-bars"></i> Trả gốc
                                            </div>
                                            <div class="card-body">
                                                <form method="POST" class="row g-3"
                                                    onsubmit="return confirm('Xác nhận trả bớt gốc?');">
                                                    <input type="hidden" name="action" value="pay_principal">

                                                    <div class="row mb-2 align-items-center">
                                                        <div class="col-md-3 fw-bold text-end">Ngày trả trước gốc</div>
                                                        <div class="col-md-9">
                                                            <input type="date" name="date" class="form-control"
                                                                value="<?php echo date('Y-m-d'); ?>" required>
                                                        </div>
                                                    </div>

                                                    <div class="row mb-2 align-items-center">
                                                        <div class="col-md-3 fw-bold text-end">Số tiền gốc trả trước
                                                            <span class="text-danger">*</span>
                                                        </div>
                                                        <div class="col-md-9">
                                                            <input type="text" name="amount"
                                                                class="form-control number-separator" required>
                                                        </div>
                                                    </div>

                                                    <div class="row mb-2 align-items-center">
                                                        <div class="col-md-3 fw-bold text-end">Ghi chú</div>
                                                        <div class="col-md-9">
                                                            <textarea name="note" class="form-control"
                                                                rows="2"></textarea>
                                                        </div>
                                                    </div>

                                                    <div class="col-12 text-end">
                                                        <button type="submit" class="btn btn-primary">Đồng ý</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>

                                        <!-- Principal History List -->
                                        <h6 class="fw-bold"><i class="fas fa-bars"></i> Danh sách tiền gốc</h6>
                                        <div class="table-responsive">
                                            <table class="table table-bordered text-center table-sm align-middle">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>STT</th>
                                                        <th>Ngày</th>
                                                        <th>Nội dung</th>
                                                        <th>Số tiền</th>
                                                        <th></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    $p_idx = 1;
                                                    $total_curr_principal = 0; // Or better, calculate from history or just use loans.amount? 
                                                    // The screenshot shows "Tổng gốc còn lại" which implies a running balance or just current state.
                                                    // Let's use loans.amount for the footer, but the table lists transactions.
                                                    $principal_transactions = [];
                                                    foreach ($history as $h) {
                                                        if (in_array($h['type'], ['pay_principal', 'disburse', 'lend_more'])) {
                                                            $principal_transactions[] = $h;
                                                        }
                                                    }

                                                    if (count($principal_transactions) > 0) {
                                                        foreach ($principal_transactions as $pt) {
                                                            $d_date = date('d-m-Y', strtotime($pt['date']));
                                                            $d_amount = number_format($pt['amount']) . ' VNĐ';
                                                            $note_txt = ($pt['type'] == 'pay_principal') ? 'Trả bớt gốc' : (($pt['type'] == 'disburse') ? 'Giải ngân' : 'Vay thêm tiền');
                                                            if ($pt['note'])
                                                                $note_txt .= " : " . $pt['note'];

                                                            // Formatting: Pay Principal should be negative? Screenshot shows negative.
                                                            // But stored as positive in amount?
                                                            // If I stored it as positive, I should display as -...
                                                            $display_amount = $d_amount;
                                                            $amount_style = "";
                                                            if ($pt['type'] == 'pay_principal') {
                                                                $display_amount = "-" . number_format($pt['amount']) . " VNĐ";
                                                            } else {
                                                                // Borrow more / Disburse -> Positive
                                                                $display_amount = number_format($pt['amount']) . " VNĐ";
                                                            }
                                                            ?>
                                                                    <tr>
                                                                        <td><?php echo $p_idx++; ?></td>
                                                                        <td><?php echo $d_date; ?></td>
                                                                        <td class="text-start ps-3"><?php echo $note_txt; ?></td>
                                                                        <td class="text-end pe-3"><?php echo $display_amount; ?></td>
                                                                        <td>
                                                                            <form method="POST"
                                                                                onsubmit="return confirm('Bạn có chắc muốn xóa? Lãi suất và gốc sẽ bị ảnh hưởng!');"
                                                                                style="display:inline;">
                                                                                <input type="hidden" name="action"
                                                                                    value="delete_transaction">
                                                                                <input type="hidden" name="trans_id"
                                                                                    value="<?php echo $pt['id']; ?>">
                                                                                <button type="submit"
                                                                                    class="btn btn-sm text-secondary"><i
                                                                                        class="fas fa-trash-alt"></i></button>
                                                                            </form>
                                                                        </td>
                                                                    </tr>
                                                                    <?php
                                                        }
                                                    } else {
                                                        echo '<tr><td colspan="5" class="text-muted">Chưa có giao dịch gốc nào</td></tr>';
                                                    }
                                                    ?>
                                                </tbody>
                                                <tfoot class="table-light fw-bold text-danger">
                                                    <tr>
                                                        <td colspan="3" class="text-end">Tổng gốc còn lại</td>
                                                        <td class="text-end pe-3">
                                                            <?php echo number_format($loan['amount']); ?> VNĐ
                                                        </td>
                                                        <td></td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>

                                    <div class="tab-pane fade <?php echo ($active_tab == 'topup') ? 'show active' : ''; ?>"
                                        id="topup">
                                        <div class="card mb-3">
                                            <div class="card-header bg-light fw-bold">
                                                <i class="fas fa-bars"></i> Vay thêm tiền
                                            </div>
                                            <div class="card-body">
                                                <form method="POST" class="row g-3"
                                                    onsubmit="return confirm('Xác nhận vay thêm tiền?');">
                                                    <input type="hidden" name="action" value="lend_more">

                                                    <div class="row mb-2 align-items-center">
                                                        <div class="col-md-3 fw-bold text-end">Ngày vay thêm gốc</div>
                                                        <div class="col-md-9">
                                                            <input type="date" name="date" class="form-control"
                                                                value="<?php echo date('Y-m-d'); ?>" required>
                                                        </div>
                                                    </div>

                                                    <div class="row mb-2 align-items-center">
                                                        <div class="col-md-3 fw-bold text-end">Số tiền vay thêm <span
                                                                class="text-danger">*</span></div>
                                                        <div class="col-md-9">
                                                            <input type="text" name="amount"
                                                                class="form-control number-separator" required>
                                                        </div>
                                                    </div>

                                                    <div class="row mb-2 align-items-center">
                                                        <div class="col-md-3 fw-bold text-end">Ghi chú</div>
                                                        <div class="col-md-9">
                                                            <textarea name="note" class="form-control"
                                                                rows="2"></textarea>
                                                        </div>
                                                    </div>

                                                    <div class="col-12 text-end">
                                                        <button type="submit" class="btn btn-primary">Đồng ý</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>

                                        <!-- Principal History List (Duplicate of Principal Tab) -->
                                        <h6 class="fw-bold"><i class="fas fa-bars"></i> Danh sách tiền gốc</h6>
                                        <div class="table-responsive">
                                            <table class="table table-bordered text-center table-sm align-middle">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>STT</th>
                                                        <th>Ngày</th>
                                                        <th>Nội dung</th>
                                                        <th>Số tiền</th>
                                                        <th></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    $p_idx_2 = 1;
                                                    // Use same $principal_transactions from previous tab block if scope allows, 
                                                    // OR re-filter. Scope might not persist if I didn't declare it global or outside.
                                                    // PHP variables in include/main file are global relative to HTML blocks.
                                                    // So $principal_transactions is available!
                                                    
                                                    if (count($principal_transactions) > 0) {
                                                        foreach ($principal_transactions as $pt) {
                                                            $d_date = date('d-m-Y', strtotime($pt['date']));
                                                            $d_amount = number_format($pt['amount']) . ' VNĐ';
                                                            $note_txt = ($pt['type'] == 'pay_principal') ? 'Trả bớt gốc' : (($pt['type'] == 'disburse') ? 'Giải ngân' : 'Vay thêm tiền');
                                                            if ($pt['note'])
                                                                $note_txt .= " : " . $pt['note'];

                                                            $display_amount = $d_amount;
                                                            if ($pt['type'] == 'pay_principal') {
                                                                $display_amount = "-" . number_format($pt['amount']) . " VNĐ";
                                                            } else {
                                                                $display_amount = number_format($pt['amount']) . " VNĐ";
                                                            }
                                                            ?>
                                                                    <tr>
                                                                        <td><?php echo $p_idx_2++; ?></td>
                                                                        <td><?php echo $d_date; ?></td>
                                                                        <td class="text-start ps-3"><?php echo $note_txt; ?></td>
                                                                        <td class="text-end pe-3"><?php echo $display_amount; ?></td>
                                                                        <td>
                                                                            <form method="POST"
                                                                                onsubmit="return confirm('Bạn có chắc muốn xóa? Lãi suất và gốc sẽ bị ảnh hưởng!');"
                                                                                style="display:inline;">
                                                                                <input type="hidden" name="action"
                                                                                    value="delete_transaction">
                                                                                <input type="hidden" name="trans_id"
                                                                                    value="<?php echo $pt['id']; ?>">
                                                                                <button type="submit"
                                                                                    class="btn btn-sm text-secondary"><i
                                                                                        class="fas fa-lock"></i></button>
                                                                                <!-- Lock icon as per screenshot for lend? Or trash? Screenshot showed Lock for ONE item. -->
                                                                                <!-- Maybe it was the 'Initial Disburse' that was locked? -->
                                                                                <!-- I will stick to Trash for now for functionality, or render Lock if logic dictates. -->
                                                                                <!-- User screenshot for 'Vay them' showed a lock icon on the row. -->
                                                                                <!-- Maybe it was the 'Initial Disburse' that was locked? -->
                                                                                <!-- I'll use Trash for functionality unless user complains. -->
                                                                                <!-- Wait, I'll change the icon to Trash to enable deleting. -->
                                                                                <!-- Or if it's Disburse, maybe disable delete? -->
                                                                                <!-- If TYPE == 'disburse', maybe lock it. -->
                                                                            </form>
                                                                        </td>
                                                                    </tr>
                                                                    <?php
                                                        }
                                                    } else {
                                                        echo '<tr><td colspan="5" class="text-muted">Chưa có giao dịch gốc nào</td></tr>';
                                                    }
                                                    ?>
                                                </tbody>
                                                <tfoot class="table-light fw-bold text-danger">
                                                    <tr>
                                                        <td colspan="3" class="text-end">Tổng gốc còn lại</td>
                                                        <td class="text-end pe-3">
                                                            <?php echo number_format($loan['amount']); ?> VNĐ
                                                        </td>
                                                        <td></td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>

                                    <!-- Tab: Debt Payment -->
                                    <div class="tab-pane fade <?php echo ($active_tab == 'debt') ? 'show active' : ''; ?>"
                                        id="debt">
                                        <div class="card mb-3">
                                            <div class="card-header bg-light fw-bold">
                                                <i class="fas fa-money-bill-wave"></i> Khách hàng trả nợ
                                            </div>
                                            <div class="card-body">
                                                <div class="row mb-3">
                                                    <div class="col-md-4 fw-bold">Nợ hiện tại:</div>
                                                    <div class="col-md-8">
                                                        <span class="text-danger fw-bold fs-5"><?php echo number_format($loan['old_debt'] ?? 0); ?> VNĐ</span>
                                                    </div>
                                                </div>
                                                <form method="POST" onsubmit="return confirm('Xác nhận khách trả nợ?');">
                                                    <input type="hidden" name="action" value="pay_debt">
                                                    <div class="mb-3">
                                                        <label class="form-label fw-bold">Số tiền trả nợ <span class="text-danger">*</span></label>
                                                        <input type="text" name="debt_amount" class="form-control number-separator" value="0" required>
                                                    </div>
                                                    <div class="text-end">
                                                        <button type="submit" class="btn btn-primary px-4">Thanh toán</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Tab: Notes -->
                                    <div class="tab-pane fade <?php echo ($active_tab == 'notes') ? 'show active' : ''; ?>"
                                        id="notes">
                                        <form method="POST" action="">
                                            <input type="hidden" name="action" value="update_note">
                                            <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">

                                            <div class="mb-3">
                                                <label for="contract_note" class="form-label fw-bold">Ghi chú hợp
                                                    đồng</label>
                                                <textarea name="contract_note" id="contract_note" class="form-control"
                                                    rows="8"
                                                    placeholder="Nhập ghi chú cho hợp đồng..."><?php echo htmlspecialchars($loan['contract_note'] ?? ''); ?></textarea>
                                            </div>

                                            <div class="d-flex justify-content-end gap-2">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-save"></i> Lưu ghi chú
                                                </button>
                                            </div>
                                        </form>
                                        <!-- Note History -->
                                        <hr class="my-4">
                                        <h6 class="fw-bold mb-3"><i class="fas fa-history"></i> Lịch sử ghi chú</h6>
                                        <?php
                                        // Fetch note history
                                        try {
                                            $stmt_notes = $conn->prepare("
                                                SELECT nh.*, u.username, u.fullname 
                                                FROM note_history nh
                                                LEFT JOIN users u ON nh.user_id = u.id
                                                WHERE nh.loan_id = ?
                                                ORDER BY nh.created_at DESC
                                            ");
                                            $stmt_notes->execute([$loan['id']]);
                                            $note_history = $stmt_notes->fetchAll(PDO::FETCH_ASSOC);
                                        } catch (Exception $e) {
                                            // Table doesn't exist yet
                                            $note_history = [];
                                        }
                                        ?>

                                        <?php if (empty($note_history)): ?>
                                                <div class="alert alert-info">
                                                    <i class="fas fa-info-circle"></i> Chưa có lịch sử ghi chú
                                                </div>
                                        <?php else: ?>
                                                <div class="table-responsive">
                                                    <table class="table table-bordered table-hover table-sm">
                                                        <thead class="table-light">
                                                            <tr>
                                                                <th width="50">STT</th>
                                                                <th width="150">Ngày</th>
                                                                <th width="120">Người thao tác</th>
                                                                <th>Nội dung</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($note_history as $index => $note): ?>
                                                                    <tr>
                                                                        <td class="text-center"
                                                                            style="vertical-align: top !important; padding-top: 8px;">
                                                                            <?php echo $index + 1; ?>
                                                                        </td>
                                                                        <td style="vertical-align: top !important; padding-top: 8px;">
                                                                            <?php
                                                                            $date = new DateTime($note['created_at']);
                                                                            echo $date->format('d-m-Y H:i:s');
                                                                            ?>
                                                                        </td>
                                                                        <td style="vertical-align: top !important; padding-top: 8px;">
                                                                            <?php echo htmlspecialchars($note['fullname'] ?? $note['username'] ?? 'Nhân viên'); ?>
                                                                        </td>
                                                                        <td
                                                                            style="vertical-align: top !important; white-space: pre-wrap; padding-top: 8px;">
                                                                            <?php echo htmlspecialchars($note['note_content']); ?>
                                                                        </td>
                                                                    </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Tab: History -->
                                    <div class="tab-pane fade <?php echo ($active_tab == 'history') ? 'show active' : ''; ?>"
                                        id="history">
                                        <table class="table table-bordered table-striped table-hover mt-3">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width:50px;">STT</th>
                                                    <th style="width:140px;">Ngày</th>
                                                    <th style="width:130px;">Người thao tác</th>
                                                    <th style="width:120px;">Số tiền</th>
                                                    <th>Loại giao dịch</th>
                                                    <th>Ghi chú</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $stt = 1; foreach ($all_history as $h): ?>
                                                        <tr>
                                                            <td class="text-center"><?php echo $stt++; ?></td>
                                                            <td><?php echo date('d-m-Y H:i', strtotime($h['date'])); ?></td>
                                                            <td><?php echo htmlspecialchars($h['user']); ?></td>
                                                            <td class="text-end fw-bold">
                                                                <?php echo $h['amount'] > 0 ? number_format($h['amount']) : ''; ?>
                                                            </td>
                                                            <td>
                                                                <?php
                                                                switch ($h['type']) {
                                                                    case 'disburse': echo '<span class="text-primary">Giải ngân</span>'; break;
                                                                    case 'collect_interest': 
                                                                        if (strpos($h['note'], 'Khách trả nợ') !== false) {
                                                                            echo '<span class="badge bg-warning text-dark">Trả nợ</span>';
                                                                        } elseif (strpos($h['note'], 'Cập nhật nợ cũ') !== false) {
                                                                            echo '<span class="badge bg-info text-white">Nhập nợ cũ</span>';
                                                                        } else {
                                                                            echo '<span class="text-success">Đóng lãi</span>';
                                                                        }
                                                                        break;
                                                                    case 'pay_principal': echo '<span class="text-danger">Trả bớt gốc</span>'; break;
                                                                    case 'pay_all': echo '<span class="badge bg-dark">Đóng hợp đồng</span>'; break;
                                                                    case 'lend_more': echo '<span class="text-info">Vay thêm</span>'; break;
                                                                    case 'extend': echo '<span class="text-warning">Gia hạn</span>'; break;
                                                                    case 'pay_debt': echo '<span class="badge bg-warning text-dark">Trả nợ</span>'; break;
                                                                    case 'adjust_debt': echo '<span class="badge bg-info text-white">Cập nhật nợ cũ</span>'; break;
                                                                    default: echo htmlspecialchars($h['type']);
                                                                }
                                                                ?>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($h['note']); ?></td>
                                                        </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- Tab: Attachments -->
                                    <div class="tab-pane fade <?php echo ($active_tab == 'attachments') ? 'show active' : ''; ?>"
                                        id="attachments">

                                        <!-- Upload Area -->
                                        <div class="mb-3 d-flex justify-content-between align-items-center">
                                            <h6 class="mb-0 fw-bold"><i class="fas fa-paperclip"></i> File đính kèm hợp đồng</h6>
                                            <div class="d-inline">
                                                <input type="file" name="attachment_files[]" id="attachUploadInput" multiple accept="*/*" style="display:none;">
                                                <button type="button" class="btn btn-sm btn-primary" id="btnUploadFile" onclick="document.getElementById('attachUploadInput').click();">
                                                    <i class="fas fa-upload"></i> Upload file
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Upload Progress Overlay -->
                                        <div id="uploadOverlay" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.6); z-index:9999; display:none; justify-content:center; align-items:center;">
                                            <div style="background:#fff; border-radius:12px; padding:30px 40px; min-width:350px; text-align:center; box-shadow:0 8px 32px rgba(0,0,0,0.3);">
                                                <div style="font-size:40px; margin-bottom:10px;">☁️</div>
                                                <h5 style="margin-bottom:5px;" id="uploadTitle">Đang upload lên Google Drive...</h5>
                                                <p style="color:#888; font-size:13px; margin-bottom:15px;" id="uploadFileName"></p>
                                                <div style="background:#e9ecef; border-radius:8px; height:12px; overflow:hidden; margin-bottom:10px;">
                                                    <div id="uploadProgressBar" style="background:linear-gradient(90deg,#4285f4,#34a853); height:100%; width:0%; border-radius:8px; transition:width 0.3s;"></div>
                                                </div>
                                                <span id="uploadPercent" style="font-weight:600; color:#4285f4;">0%</span>
                                            </div>
                                        </div>

                                        <script>
                                        document.getElementById('attachUploadInput').addEventListener('change', function() {
                                            if (!this.files.length) return;

                                            var files = this.files;
                                            var names = [];
                                            for (var i = 0; i < files.length; i++) names.push(files[i].name);

                                            var overlay = document.getElementById('uploadOverlay');
                                            overlay.style.display = 'flex';
                                            document.getElementById('uploadFileName', names.join(', '));
                                            document.getElementById('uploadFileName').textContent = names.join(', ');
                                            document.getElementById('uploadProgressBar').style.width = '0%';
                                            document.getElementById('uploadPercent').textContent = '0%';
                                            document.getElementById('uploadTitle').textContent = 'Đang upload lên Google Drive...';
                                            document.getElementById('btnUploadFile').disabled = true;

                                            var formData = new FormData();
                                            formData.append('action', 'upload_attachment');
                                            for (var i = 0; i < files.length; i++) {
                                                formData.append('attachment_files[]', files[i]);
                                            }

                                            var xhr = new XMLHttpRequest();
                                            xhr.upload.addEventListener('progress', function(e) {
                                                if (e.lengthComputable) {
                                                    var pct = Math.round((e.loaded / e.total) * 100);
                                                    document.getElementById('uploadProgressBar').style.width = pct + '%';
                                                    document.getElementById('uploadPercent').textContent = pct + '%';
                                                    if (pct >= 100) {
                                                        document.getElementById('uploadTitle').textContent = 'Đang xử lý trên server...';
                                                    }
                                                }
                                            });
                                            xhr.addEventListener('load', function() {
                                                document.getElementById('uploadProgressBar').style.width = '100%';
                                                document.getElementById('uploadPercent').textContent = '✅ Hoàn tất!';
                                                document.getElementById('uploadTitle').textContent = 'Upload thành công!';
                                                setTimeout(function() {
                                                    window.location.href = 'contract_view.php?id=<?php echo $id; ?>&tab=attachments<?php echo isset($_GET["view_mode"]) ? "&view_mode=" . $_GET["view_mode"] : ""; ?>';
                                                }, 800);
                                            });
                                            xhr.addEventListener('error', function() {
                                                document.getElementById('uploadTitle').textContent = '❌ Upload thất bại!';
                                                document.getElementById('uploadPercent').textContent = 'Lỗi kết nối';
                                                document.getElementById('btnUploadFile').disabled = false;
                                                setTimeout(function() { overlay.style.display = 'none'; }, 2000);
                                            });

                                            var viewMode = '<?php echo isset($_GET["view_mode"]) ? "&view_mode=" . $_GET["view_mode"] : ""; ?>';
                                            xhr.open('POST', 'contract_view.php?id=<?php echo $id; ?>' + viewMode);
                                            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                                            xhr.send(formData);
                                        });
                                        </script>

                                        <!-- Sub-tabs for file types -->
                                        <ul class="nav nav-pills mb-3 justify-content-center" id="attSubTabs">
                                            <li class="nav-item">
                                                <button class="nav-link active att-sub-tab" data-att-target="att-documents" onclick="switchAttSub(this)">
                                                    <i class="fas fa-file-alt"></i> Tài liệu <span class="badge bg-primary rounded-pill"><?php echo count($att_docs); ?></span>
                                                </button>
                                            </li>
                                            <li class="nav-item">
                                                <button class="nav-link att-sub-tab" data-att-target="att-images" onclick="switchAttSub(this)">
                                                    <i class="fas fa-images"></i> Hình ảnh <span class="badge bg-success rounded-pill"><?php echo count($att_images); ?></span>
                                                </button>
                                            </li>
                                            <li class="nav-item">
                                                <button class="nav-link att-sub-tab" data-att-target="att-videos" onclick="switchAttSub(this)">
                                                    <i class="fas fa-video"></i> Video <span class="badge bg-danger rounded-pill"><?php echo count($att_videos); ?></span>
                                                </button>
                                            </li>
                                        </ul>

                                        <!-- Documents -->
                                        <div class="att-sub-content" id="att-documents">
                                            <?php if (count($att_docs) > 0): ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-hover mb-0 align-middle" style="font-size:13px;">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th style="width:30px;"></th>
                                                            <th>Tên file</th>
                                                            <th style="width:100px;">Dung lượng</th>
                                                            <th style="width:130px;">Ngày upload</th>
                                                            <th style="width:80px;">Thao tác</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php foreach ($att_docs as $doc): ?>
                                                        <tr>
                                                            <td><i class="fas fa-file-pdf text-danger"></i></td>
                                                            <td>
                                                                <?php $doc_url = !empty($doc['drive_link']) ? $doc['drive_link'] : 'uploads/contracts/' . $id . '/' . $doc['file_name']; ?>
                                                                <a href="<?php echo $doc_url; ?>" target="_blank" class="text-decoration-none">
                                                                    <?php echo htmlspecialchars($doc['original_name']); ?>
                                                                </a>
                                                            </td>
                                                            <td class="text-muted"><?php echo formatFileSize($doc['file_size']); ?></td>
                                                            <td class="text-muted"><?php echo date('d/m/Y H:i', strtotime($doc['uploaded_at'])); ?></td>
                                                            <td>
                                                                <a href="<?php echo $doc_url; ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-1" title="Xem"><i class="fas fa-external-link-alt"></i></a>
                                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Xóa file này?');">
                                                                    <input type="hidden" name="action" value="delete_attachment">
                                                                    <input type="hidden" name="attachment_id" value="<?php echo $doc['id']; ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1" title="Xóa"><i class="fas fa-trash"></i></button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php else: ?>
                                            <div class="text-center text-muted py-4"><i class="fas fa-folder-open fa-2x mb-2 d-block"></i>Chưa có tài liệu.<br><small>Nhấn "Upload file" để thêm.</small></div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Images -->
                                        <div class="att-sub-content" id="att-images" style="display:none;">
                                            <?php if (count($att_images) > 0): ?>
                                            <div class="row g-2">
                                            <?php foreach ($att_images as $img): ?>
                                                <div class="col-4 col-md-3 col-lg-2 position-relative">
                                                    <?php $img_url = !empty($img['drive_link']) ? 'https://drive.google.com/thumbnail?id=' . $img['drive_file_id'] . '&sz=w400' : 'uploads/contracts/' . $id . '/' . $img['file_name']; ?>
                                                    <?php $img_view = !empty($img['drive_link']) ? $img['drive_link'] : 'uploads/contracts/' . $id . '/' . $img['file_name']; ?>
                                                    <a href="<?php echo $img_view; ?>" target="_blank">
                                                        <img src="<?php echo $img_url; ?>" 
                                                             class="img-fluid rounded border" 
                                                             style="width:100%; height:120px; object-fit:cover; cursor:pointer;"
                                                             alt="<?php echo htmlspecialchars($img['original_name']); ?>">
                                                    </a>
                                                    <form method="POST" class="position-absolute top-0 end-0 m-1" onsubmit="return confirm('Xóa ảnh này?');">
                                                        <input type="hidden" name="action" value="delete_attachment">
                                                        <input type="hidden" name="attachment_id" value="<?php echo $img['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger py-0 px-1" style="font-size:10px; opacity:0.8;" title="Xóa"><i class="fas fa-times"></i></button>
                                                    </form>
                                                    <div class="text-center mt-1" style="font-size:11px;"><?php echo mb_strimwidth(htmlspecialchars($img['original_name']), 0, 20, '...'); ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                            </div>
                                            <?php else: ?>
                                            <div class="text-center text-muted py-4"><i class="fas fa-images fa-2x mb-2 d-block"></i>Chưa có hình ảnh.<br><small>Nhấn "Upload file" để thêm.</small></div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Videos -->
                                        <div class="att-sub-content" id="att-videos" style="display:none;">
                                            <?php if (count($att_videos) > 0): ?>
                                            <div class="row g-3">
                                            <?php foreach ($att_videos as $vid): ?>
                                                <div class="col-12 col-md-6">
                                                    <div class="position-relative">
                                                        <?php $vid_url = !empty($vid['drive_link']) ? 'https://drive.google.com/file/d/' . $vid['drive_file_id'] . '/preview' : 'uploads/contracts/' . $id . '/' . $vid['file_name']; ?>
                                                        <?php if (!empty($vid['drive_file_id'])): ?>
                                                        <iframe src="<?php echo $vid_url; ?>" class="w-100 rounded border" style="height:300px;" allow="autoplay" allowfullscreen></iframe>
                                                        <?php else: ?>
                                                        <video controls class="w-100 rounded border" style="max-height:300px;">
                                                            <source src="uploads/contracts/<?php echo $id . '/' . $vid['file_name']; ?>">
                                                            Trình duyệt không hỗ trợ video.
                                                        </video>
                                                        <?php endif; ?>
                                                        <div class="d-flex justify-content-between align-items-center mt-1 px-1" style="font-size:12px;">
                                                            <span class="text-muted"><?php echo htmlspecialchars($vid['original_name']); ?> (<?php echo formatFileSize($vid['file_size']); ?>)</span>
                                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Xóa video này?');">
                                                                <input type="hidden" name="action" value="delete_attachment">
                                                                <input type="hidden" name="attachment_id" value="<?php echo $vid['id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1"><i class="fas fa-trash"></i> Xóa</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            </div>
                                            <?php else: ?>
                                            <div class="text-center text-muted py-4"><i class="fas fa-video fa-2x mb-2 d-block"></i>Chưa có video.<br><small>Nhấn "Upload file" để thêm.</small></div>
                                            <?php endif; ?>
                                        </div>

                                    </div>

                                </div>
                            </div>
                        </div>

                    </div>

                    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
                    <script>
                        // Auto-select tab based on URL param
                        const urlParams = new URLSearchParams(window.location.search);
                        const tabParam = urlParams.get('tab');
                        if (tabParam) {
                            const tabEl = document.querySelector(`#${tabParam}-tab`);
                            if (tabEl) {
                                const tab = new bootstrap.Tab(tabEl);
                                tab.show();
                            }
                        }
                    </script>

                    <!-- Payment Amount Editor Modal -->
                    <div class="modal fade" id="paymentEditorModal" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Điều chỉnh số tiền đóng</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Tiền lãi phải đóng:</label>
                                        <div id="expectedAmount" class="fs-5 text-primary"></div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="customerPayment" class="form-label fw-bold">Khách trả:</label>
                                        <input type="text" class="form-control form-control-lg" id="customerPayment"
                                            placeholder="Nhập số tiền khách trả">
                                    </div>
                                    <div id="paymentDifference" class="alert" style="display: none;"></div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                                    <button type="button" class="btn btn-primary" id="savePaymentBtn">Lưu</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <script>
                        // Payment Amount Editor
                        let currentPeriodData = {};
                        const paymentModal = new bootstrap.Modal(document.getElementById('paymentEditorModal'));

                        // Click handler for payment amounts
                        document.querySelectorAll('.payment-amount-editable').forEach(el => {
                            el.addEventListener('click', function () {
                                currentPeriodData = {
                                    periodStart: this.dataset.periodStart,
                                    periodEnd: this.dataset.periodEnd,
                                    expectedAmount: parseFloat(this.dataset.expectedAmount),
                                    currentAmount: parseFloat(this.dataset.currentAmount),
                                    isPaid: this.dataset.isPaid === '1'
                                };

                                // Show modal
                                document.getElementById('expectedAmount').textContent =
                                    new Intl.NumberFormat('vi-VN').format(currentPeriodData.expectedAmount) + ' VNĐ';
                                document.getElementById('customerPayment').value =
                                    new Intl.NumberFormat('vi-VN').format(currentPeriodData.currentAmount);
                                document.getElementById('paymentDifference').style.display = 'none';

                                paymentModal.show();
                            });
                        });

                        // Calculate difference on input
                        document.getElementById('customerPayment').addEventListener('input', function () {
                            const input = this.value.replace(/[^0-9]/g, '');
                            const amount = parseFloat(input) || 0;
                            const expected = currentPeriodData.expectedAmount;
                            const diff = amount - expected;

                            const diffEl = document.getElementById('paymentDifference');

                            if (diff > 0) {
                                diffEl.className = 'alert alert-danger';
                                diffEl.innerHTML = '<strong>Tiền thừa:</strong> ' +
                                    new Intl.NumberFormat('vi-VN').format(diff) + ' VNĐ';
                                diffEl.style.display = 'block';
                            } else if (diff < 0) {
                                diffEl.className = 'alert alert-warning';
                                diffEl.innerHTML = '<strong>Nợ cũ:</strong> ' +
                                    new Intl.NumberFormat('vi-VN').format(Math.abs(diff)) + ' VNĐ';
                                diffEl.style.display = 'block';
                            } else {
                                diffEl.style.display = 'none';
                            }

                            // Format input
                            this.value = new Intl.NumberFormat('vi-VN').format(amount);
                        });

                        // Save payment
                        document.getElementById('savePaymentBtn').addEventListener('click', function () {
                            const amountStr = document.getElementById('customerPayment').value.replace(/[^0-9]/g, '');
                            const amount = parseFloat(amountStr) || 0;

                            if (amount <= 0) {
                                alert('Vui lòng nhập số tiền hợp lệ!');
                                return;
                            }

                            // Submit payment
                            const formData = new FormData();
                            formData.append('action', 'pay_custom');
                            formData.append('custom_from_date', currentPeriodData.periodStart);
                            formData.append('custom_to_date', currentPeriodData.periodEnd);
                            formData.append('custom_amount', amount);
                            formData.append('loan_id', <?php echo $id; ?>);

                            fetch(window.location.href, {
                                method: 'POST',
                                body: formData
                            })
                                .then(response => {
                                    if (response.ok) {
                                        location.reload();
                                    } else {
                                        alert('Có lỗi xảy ra!');
                                    }
                                })
                                .catch(error => {
                                    alert('Lỗi kết nối: ' + error);
                                });
                        });
                    </script>
                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            // Global handler for number-separator inputs
                            const separators = document.querySelectorAll('.number-separator');
                            separators.forEach(input => {
                                input.addEventListener('input', function (e) {
                                    let value = e.target.value;
                                    // Remove non-numeric characters
                                    value = value.replace(/[^0-9]/g, '');

                                    if (value) {
                                        // Convert to integer to remove leading zeros and ensure valid number
                                        const intVal = parseInt(value, 10);
                                        e.target.value = new Intl.NumberFormat('vi-VN').format(intVal);
                                    } else {
                                        e.target.value = '';
                                    }
                                });
                            });
                        });
                    </script>
                    <script>
                        // Navigate from shortcut buttons to Attachments tab + sub-tab
                        function goToAttTab(subTabName) {
                            // Activate the Attachments main tab
                            const attTab = document.getElementById('attachments-tab');
                            if (attTab) {
                                const tab = new bootstrap.Tab(attTab);
                                tab.show();
                            }
                            // Activate the correct sub-tab
                            const subBtn = document.querySelector('.att-sub-tab[data-att-target="att-' + subTabName + '"]');
                            if (subBtn) switchAttSub(subBtn);
                            // Scroll to the tab area
                            attTab.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }

                        // Switch sub-tabs within Attachments tab
                        function switchAttSub(btn) {
                            document.querySelectorAll('.att-sub-content').forEach(el => el.style.display = 'none');
                            document.querySelectorAll('.att-sub-tab').forEach(b => b.classList.remove('active'));
                            const target = btn.getAttribute('data-att-target');
                            document.getElementById(target).style.display = 'block';
                            btn.classList.add('active');
                        }
                    </script>
            </body>

</html>