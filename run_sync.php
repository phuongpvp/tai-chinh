<?php
/**
 * File tạm: Chạy sync tc_info 1 lần rồi XÓA file này đi.
 */
$key = $_GET['key'] ?? '';
if ($key !== 'run_now_2026') {
    http_response_code(403);
    echo 'Sai key';
    exit;
}

// Chạy api_auto_assign
$_GET['key'] = 'cv_auto_assign_2024_secret';
ob_start();
include __DIR__ . '/cong-viec/api_auto_assign.php';
$output = ob_get_clean();

header('Content-Type: application/json; charset=utf-8');
echo $output;
