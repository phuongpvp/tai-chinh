<?php
/**
 * Test Gemini API Key — chạy file này trên server để debug
 */

// Thay API key của anh vào đây
$apiKey = $_GET['key'] ?? '';
if (!$apiKey) {
    echo "<h2>Dán API key vào URL: ?key=YOUR_KEY</h2>";
    exit;
}

echo "<h3>🔍 Test Gemini API</h3>";
echo "<pre>";

// Test 1: List models
echo "=== 1. LIST MODELS ===\n";
$ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models?key=" . $apiKey);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 15,
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP: $httpCode\n";
$data = json_decode($resp, true);

if (isset($data['models'])) {
    echo "Models có sẵn:\n";
    foreach ($data['models'] as $m) {
        if (strpos($m['name'], 'gemini') !== false) {
            echo "  ✅ " . $m['name'] . " (" . ($m['displayName'] ?? '') . ")\n";
        }
    }
} else {
    echo "Lỗi: " . ($data['error']['message'] ?? $resp) . "\n";
}

// Test 2: Simple generate
echo "\n=== 2. TEST GENERATE ===\n";
$models = ['gemini-2.0-flash', 'gemini-2.0-flash-lite', 'gemini-1.5-flash'];
foreach ($models as $model) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=" . $apiKey;
    $payload = json_encode([
        'contents' => [['parts' => [['text' => 'Nói "xin chào" bằng tiếng Việt']]]],
        'generationConfig' => ['maxOutputTokens' => 50]
    ]);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    echo "\n[$model] HTTP: $httpCode\n";
    if ($err) {
        echo "  CURL Error: $err\n";
    } else {
        $r = json_decode($resp, true);
        if (isset($r['candidates'])) {
            echo "  ✅ OK: " . ($r['candidates'][0]['content']['parts'][0]['text'] ?? '') . "\n";
        } else {
            echo "  ❌ " . ($r['error']['message'] ?? $resp) . "\n";
        }
    }
}

// Server info
echo "\n=== 3. SERVER INFO ===\n";
echo "Server IP: ";
echo file_get_contents("https://api.ipify.org") ?: "không lấy được";
echo "\n";
echo "PHP: " . phpversion() . "\n";
echo "cURL: " . (function_exists('curl_version') ? curl_version()['version'] : 'N/A') . "\n";

echo "</pre>";
