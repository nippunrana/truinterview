<?php
// trugen_service.php - TruGen AI Integration Helpers

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
