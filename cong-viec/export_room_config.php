<?php
/**
 * Xuất cấu hình nhật ký tất cả phòng ra Excel
 * XÓA FILE NÀY SAU KHI DÙNG XONG
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../SimpleXLSXGen.php';

$rooms = $pdo->query("SELECT id, name, icon, worklog_config FROM cv_rooms ORDER BY sort_order, id")->fetchAll();

$rows = [['Phòng', 'Hành động', 'Có ô nhập chi tiết', 'Tiêu đề ô nhập', 'STT', 'Kết quả', 'Ngày hẹn', 'Số tiền']];

foreach ($rooms as $r) {
    $config = json_decode($r['worklog_config'] ?? '[]', true) ?: [];
    $roomName = $r['icon'] . ' ' . $r['name'];

    if (empty($config)) {
        $rows[] = [$roomName, '(Chưa cấu hình)', '', '', '', '', '', ''];
        continue;
    }

    $isFirstRow = true;
    foreach ($config as $action) {
        $actionName = $action['action'] ?? '';
        $hasCustom = !empty($action['show_custom_input']) ? 'Có' : 'Không';
        $customLabel = $action['custom_input_label'] ?? '';
        $results = $action['results'] ?? [];

        if (empty($results)) {
            $rows[] = [
                $isFirstRow ? $roomName : '',
                $actionName,
                $hasCustom,
                $customLabel,
                '',
                '(Không có kết quả)',
                '',
                ''
            ];
            $isFirstRow = false;
            continue;
        }

        foreach ($results as $rIdx => $result) {
            $label = is_array($result) ? ($result['label'] ?? '') : $result;
            $showDate = is_array($result) && !empty($result['show_date']) ? '✓' : '';
            $showAmount = is_array($result) && !empty($result['show_amount']) ? '✓' : '';
            $stt = chr(65 + $rIdx); // A, B, C...

            $rows[] = [
                $isFirstRow ? $roomName : '',
                ($rIdx === 0) ? $actionName : '',
                ($rIdx === 0) ? $hasCustom : '',
                ($rIdx === 0) ? $customLabel : '',
                $stt,
                $label,
                $showDate,
                $showAmount
            ];
            $isFirstRow = false;
        }
    }
    // Dòng trống ngăn cách giữa các phòng
    $rows[] = ['', '', '', '', '', '', '', ''];
}

SimpleXLSXGen::fromArray($rows)->downloadAs('cauhinh_nhatky_phong.xlsx');
