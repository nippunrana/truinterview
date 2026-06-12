<?php
// trugen_service.php - TruGen AI Integration Helpers
require_once __DIR__ . '/db.php';

/**
 * Cleans response text by stripping emojis, markdown elements,
 * and converting symbols to words for the Text-to-Speech (TTS) engine.
 */
function cleanSpeechText($text) {
    // 1. Remove emojis
    $clean = preg_replace('/[\x{1F600}-\x{1F64F}]/u', '', $text);
    $clean = preg_replace('/[\x{1F300}-\x{1F5FF}]/u', '', $clean);
    $clean = preg_replace('/[\x{1F680}-\x{1F6FF}]/u', '', $clean);
    $clean = preg_replace('/[\x{2600}-\x{26FF}]/u', '', $clean);
    $clean = preg_replace('/[\x{2700}-\x{27BF}]/u', '', $clean);
    $clean = preg_replace('/[\x{1F900}-\x{1F9FF}]/u', '', $clean);
    $clean = preg_replace('/[\x{1F018}-\x{1F0F5}]/u', '', $clean);
    
    // 2. Remove markdown elements
    $clean = str_replace(['*', '#', '_', '`'], '', $clean);
    $clean = preg_replace('/^\s*[-*+•]\s+/m', ' ', $clean);
    
    // 3. Convert symbols to words
    $replacements = [
        '$' => ' dollars ',
        '%' => ' percent ',
        '&' => ' and ',
        '+' => ' plus ',
        '=' => ' equals ',
        '@' => ' at ',
        '#' => ' number ',
        '<' => ' less than ',
        '>' => ' greater than ',
        '/' => ' slash ',
        '\\' => ' backslash '
    ];
    
    foreach ($replacements as $symbol => $word) {
        $clean = str_replace($symbol, $word, $clean);
    }
    
    $clean = preg_replace('/\s+/', ' ', $clean);
    return trim($clean);
}

/**
 * Resolves the TruGen API key for a given conversation ID, checking the creator's user settings first, then falling back to env.
 */
function getTruGenApiKey($conversationId) {
    if (!empty($conversationId) && $conversationId !== 'mock_id') {
        try {
            $session = getSessionByConversationId($conversationId);
            if ($session) {
                $settings = getSessionUserSettings($session['id']);
                if (!empty($settings['custom_trugen_api_key'])) {
                    return $settings['custom_trugen_api_key'];
                }
            }
        } catch (Exception $e) {
            error_log("Error resolving custom TruGen API key: " . $e->getMessage());
        }
    }
    
    $apiKey = getenv('TRUGEN_API_KEY');
    if (!$apiKey) {
        $apiKey = $_ENV['TRUGEN_API_KEY'] ?? '';
    }
    return $apiKey;
}

/**
 * Interacts with the TruGen speak API endpoint to inject dialogue speech to the candidate.
 */
function injectSpeakText($conversationId, $text) {
    if ($conversationId === 'mock_id') {
        return true;
    }
    
    $apiKey = getTruGenApiKey($conversationId);
    
    if (empty($apiKey)) {
        error_log("TruGen API key not found in environment.");
        return false;
    }
    
    $url = "https://api.trugen.ai/v1/conversation/" . urlencode($conversationId) . "/speak";
    
    $payload = [
        "text" => $text
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("TruGen speak injection curl error: " . $error);
        return false;
    }
    
    if ($httpCode !== 200) {
        error_log("TruGen speak injection API returned code {$httpCode}: " . $response);
        return false;
    }
    
    return true;
}

/**
 * Invokes the TruGen conversation termination API.
 */
function terminateTruGenConversation($conversationId) {
    if ($conversationId === 'mock_id') {
        return true;
    }
    
    $apiKey = getTruGenApiKey($conversationId);
    
    if (empty($apiKey)) {
        error_log("TruGen API key not found in environment.");
        return false;
    }
    
    $url = "https://api.trugen.ai/v1/conversation/" . urlencode($conversationId);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'x-api-key: ' . $apiKey
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("TruGen termination curl error: " . $error);
        return false;
    }
    
    if ($httpCode !== 200) {
        error_log("TruGen termination API returned code {$httpCode}: " . $response);
        return false;
    }
    
    return true;
}
