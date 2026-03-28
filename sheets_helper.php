<?php
/**
 * Google Sheets Helper - Using Apps Script Web App
 * 
 * This helper uses Google Apps Script as a bridge to write data to Google Sheets
 * without requiring OAuth2 or Service Account setup on the PHP side.
 */

/**
 * Sync data to Google Sheets via Apps Script Web App
 * 
 * @param string $sheet_id Google Sheet ID
 * @param array $data Array of data rows
 * @param string $apps_script_url Apps Script Web App URL (optional, from settings)
 * @return array Result with success status and message
 */
function syncToGoogleSheetsViaAppsScript($sheet_id, $data, $apps_script_url = null)
{
  // If no Apps Script URL provided, try to get from settings
  if (!$apps_script_url) {
    global $conn;
    try {
      $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'apps_script_url'");
      $stmt->execute();
      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      $apps_script_url = $result['setting_value'] ?? null;
    } catch (PDOException $e) {
      // Ignore
    }
  }

  if (!$apps_script_url) {
    return [
      'success' => false,
      'error' => 'Chưa cấu hình Apps Script URL. Vui lòng xem hướng dẫn bên dưới.'
    ];
  }

  // Format data for columns A, B, C, I, J
  $values = [];
  foreach ($data as $row) {
    $amount_formatted = number_format($row['amount'], 0, ',', '.') . ' ₫';

    $values[] = [
      $row['fullname'],           // Column A
      $amount_formatted,          // Column B
      $row['payment_date'],       // Column C
      '',                         // Column D
      '',                         // Column E
      '',                         // Column F
      '',                         // Column G
      '',                         // Column H
      $row['phone'],              // Column I
      $amount_formatted           // Column J
    ];
  }

  // Prepare POST data
  $post_data = json_encode([
    'sheetId' => $sheet_id,
    'values' => $values
  ]);

  // Send request to Apps Script
  $ch = curl_init($apps_script_url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($post_data)
  ]);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For development only

  $response = curl_exec($ch);
  $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curl_error = curl_error($ch);
  curl_close($ch);

  if ($curl_error) {
    return [
      'success' => false,
      'error' => 'Lỗi kết nối: ' . $curl_error
    ];
  }

  if ($http_code != 200) {
    return [
      'success' => false,
      'error' => "HTTP Error {$http_code}. Vui lòng kiểm tra: (1) Apps Script đã deploy đúng chưa? (2) URL Apps Script có đúng không? (3) Quyền truy cập có được set là 'Anyone' không? Response: " . substr($response, 0, 300)
    ];
  }

  $result = json_decode($response, true);

  if (isset($result['success']) && $result['success']) {
    return [
      'success' => true,
      'message' => 'Đã đồng bộ thành công ' . count($values) . ' bản ghi'
    ];
  } else {
    return [
      'success' => false,
      'error' => $result['error'] ?? 'Lỗi không xác định'
    ];
  }
}

/**
 * Generate Apps Script code for user to deploy
 */
function getAppsScriptCode()
{
  return <<<'JAVASCRIPT'
function doPost(e) {
  try {
    var data = JSON.parse(e.postData.contents);
    var sheetId = data.sheetId;
    var values = data.values;
    
    // Open the spreadsheet by ID
    var ss = SpreadsheetApp.openById(sheetId);
    var sheet = ss.getSheets()[0]; // First sheet
    
    // Find the first empty row in column A (starting from row 2)
    var startRow = 2;
    var lastRow = sheet.getLastRow();
    
    // Search for first empty cell in column A
    for (var i = 2; i <= lastRow + 1; i++) {
      var cellValue = sheet.getRange(i, 1).getValue();
      if (cellValue === "" || cellValue === null) {
        startRow = i;
        break;
      }
    }
    
    // Write data to specific columns only: A, B, C, I, J
    // This preserves formula columns D, E, F, G, H
    if (values && values.length > 0) {
      for (var i = 0; i < values.length; i++) {
        var row = startRow + i;
        var rowData = values[i];
        
        // Column A: Customer name (index 0)
        sheet.getRange(row, 1).setValue(rowData[0]);
        
        // Column B: Amount (index 1)
        sheet.getRange(row, 2).setValue(rowData[1]);
        
        // Column C: Payment date (index 2)
        sheet.getRange(row, 3).setValue(rowData[2]);
        
        // Column I: Phone number (index 8)
        if (rowData[8]) {
          sheet.getRange(row, 9).setValue(rowData[8]);
        }
        
        // Column J: Amount duplicate (index 9)
        sheet.getRange(row, 10).setValue(rowData[9]);
      }
    }
    
    return ContentService.createTextOutput(JSON.stringify({
      success: true,
      rowsAdded: values.length,
      startRow: startRow
    })).setMimeType(ContentService.MimeType.JSON);
    
  } catch (error) {
    return ContentService.createTextOutput(JSON.stringify({
      success: false,
      error: error.toString()
    })).setMimeType(ContentService.MimeType.JSON);
  }
}
JAVASCRIPT;
}
?>