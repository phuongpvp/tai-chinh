<?php
/**
 * Contract Action Buttons Helper
 * 
 * This file contains functions to render action buttons with permission checks
 */

/**
 * Render contract action buttons based on contract status and user permissions
 * 
 * @param PDO $conn Database connection
 * @param array $contract Contract data
 * @param string $filterStatus Current filter status for redirect
 * @return string HTML for action buttons
 */
function renderContractActionButtons($conn, $contract, $filterStatus = '')
{
    $html = '';
    $contract_id = $contract['id'];
    $status = $contract['status'];

    // Closed contract - show reopen button
    if ($status == 'closed') {
        if (hasPermission($conn, 'contracts.cancel_close')) {
            $html .= '<a href="contract_reopen.php?id=' . $contract_id . '&status=' . urlencode($filterStatus) . '"
                        class="btn btn-sm btn-outline-warning" title="Mở lại hợp đồng"
                        onclick="return confirm(\'Bạn có chắc chắn muốn mở lại hợp đồng này?\');">
                        <i class="fas fa-lock-open"></i> Mở
                    </a>';
        }
    }
    // Bad debt contract - show reactivate button
    elseif ($status == 'bad_debt') {
        if (hasPermission($conn, 'contracts.edit')) {
            $html .= '<a href="contract_reactivate.php?id=' . $contract_id . '&status=' . urlencode($filterStatus) . '"
                        class="btn btn-sm btn-success" title="Trở lại trạng thái ban đầu"
                        onclick="return confirm(\'Bạn có chắc chắn muốn chuyển hợp đồng này về trạng thái ĐANG VAY?\');">
                        <i class="fas fa-undo"></i>
                    </a>';
        }
    }
    // Active contract - show all action buttons
    else {
        // Pay interest button
        if (hasPermission($conn, 'contracts.pay_interest')) {
            $html .= '<a href="#"
                        onclick="openContractModal(\'contract_view.php?id=' . $contract_id . '&tab=interest\'); return false;"
                        class="btn btn-sm btn-warning text-white" title="Đóng lãi">
                        <i class="fas fa-hand-holding-usd"></i>
                    </a>';
        }

        // View history button (always show)
        $html .= '<a href="#"
                    onclick="openContractModal(\'contract_view.php?id=' . $contract_id . '&tab=history\'); return false;"
                    class="btn btn-sm btn-info text-white" title="Xem lịch sử">
                    <i class="fas fa-history"></i>
                </a>';

        // Mark as bad debt button
        if ($status != 'bad_debt' && hasPermission($conn, 'contracts.edit')) {
            $html .= '<a href="contract_mark_bad_debt.php?id=' . $contract_id . '&status=' . urlencode($filterStatus) . '"
                        class="btn btn-sm btn-danger" title="Đánh dấu nợ xấu"
                        onclick="return confirm(\'Bạn có chắc chắn muốn chuyển hợp đồng này sang NỢ XẤU?\');">
                        <i class="fas fa-exclamation-triangle"></i>
                    </a>';
        }
    }

    // Delete button
    if (hasPermission($conn, 'contracts.delete')) {
        $html .= '<a href="contract_delete.php?id=' . $contract_id . '&status=' . urlencode($filterStatus) . '"
                    class="btn btn-sm btn-light border"
                    onclick="return confirm(\'Bạn có chắc chắn muốn xóa hợp đồng này?\');"
                    title="Xóa hợp đồng">
                    <i class="fas fa-trash text-danger"></i>
                </a>';
    }

    return $html;
}

/**
 * Check if user can add new contract
 */
function canAddContract($conn)
{
    return hasPermission($conn, 'contracts.create');
}

/**
 * Check if user can view contract stats
 */
function canViewContractStats($conn)
{
    return hasPermission($conn, 'contracts.view');
}

/**
 * Check if user can export contracts
 */
function canExportContracts($conn)
{
    return hasPermission($conn, 'contracts.list');
}
?>