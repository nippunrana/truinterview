<?php
// ai_service.php - Gemini API client integration

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Base utility to execute request against Gemini API
 */
function callGemini($payload, $model = 'gemini-3.5-flash', $apiKeyOverride = null) {
    $apiKey = $apiKeyOverride ?: getenv('GEMINI_API_KEY');
    if (!$apiKey) {
        $apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
    }
    if (empty($apiKey)) {
        throw new Exception("Gemini API key is not configured.");
    }
    
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("Curl error when calling Gemini API: " . $error);
    }
    
    if ($httpCode !== 200) {
        throw new Exception("Gemini API returned HTTP code {$httpCode}: " . $response);
    }
    
    $data = json_decode($response, true);
    if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        throw new Exception("Unexpected response format from Gemini API: " . $response);
    }
    
    return trim($data['candidates'][0]['content']['parts'][0]['text']);
}

/**
 * Multimodal vision check using Gemini
 */
function queryGeminiVision($imagePath, $prompt, $context, $apiKeyOverride = null, $model = 'gemini-3.5-flash') {
    if (!file_exists($imagePath)) {
        throw new Exception("Image file not found: " . $imagePath);
    }
    
    $imageData = base64_encode(file_get_contents($imagePath));
    $mimeType = 'image/jpeg';
    
    $systemPrompt = getInterviewSystemPrompt();
    
    $payload = [
        "contents" => [
            [
                "parts" => [
                    [
                        "text" => "Here is the candidate's latest screen capture context and dialog history.\n\n" . 
                                  "Dialog History:\n" . $context . "\n\n" .
                                  "Candidate's latest utterance: \"" . $prompt . "\"\n\n" .
                                  "Analyze the screenshot image relative to their utterance and continue the technical interview conversation."
                    ],
                    [
                        "inlineData" => [
                            "mimeType" => $mimeType,
                            "data" => $imageData
                        ]
                    ]
                ]
            ]
        ],
        "systemInstruction" => [
            "parts" => [
                [
                    "text" => $systemPrompt
                ]
            ]
        ]
    ];
    
    return callGemini($payload, $model, $apiKeyOverride);
}

/**
 * Text-only dialog chat turn progression using Gemini
 */
function queryGeminiChat($messages, $systemPrompt = null, $apiKeyOverride = null, $model = 'gemini-3.5-flash') {
    if (empty($systemPrompt)) {
        $systemPrompt = getInterviewSystemPrompt();
    }
    
    $contents = [];
    foreach ($messages as $msg) {
        $role = strtoupper($msg['speaker'] ?? $msg['role'] ?? '');
        $text = $msg['message'] ?? $msg['text'] ?? '';
        
        if ($role === 'USER' || $role === 'CLIENT') {
            $role = 'user';
        } elseif ($role === 'AGENT' || $role === 'MODEL') {
            $role = 'model';
        } else {
            continue; // Skip SYSTEM or metadata rows to preserve alternating rules
        }
        
        $contents[] = [
            "role" => $role,
            "parts" => [
                [
                    "text" => $text
                ]
            ]
        ];
    }
    
    // Ensure we have at least one valid user entry
    if (empty($contents)) {
        $contents[] = [
            "role" => "user",
            "parts" => [
                [
                    "text" => "Hello"
                ]
            ]
        ];
    }
    
    $payload = [
        "contents" => $contents,
        "systemInstruction" => [
            "parts" => [
                [
                    "text" => $systemPrompt
                ]
            ]
        ]
    ];
    
    return callGemini($payload, $model, $apiKeyOverride);
}

/**
 * Standard default system prompt for the Interviewer Agent
 */
function getInterviewSystemPrompt() {
    return "# PERSONA & ROLE
- You are Alex, a senior technical interviewer for Acme Corp.
- Tone: Professional, encouraging, objective.
- Goal: Assess candidate's experience, communication, and technical alignment.

# INTERVIEW STRUCTURE
1. Introduction: Greet the candidate and state the purpose of the call.
2. Ask Question 1: Experience with WebRTC or real-time systems.
3. Ask Question 2: Handling high-pressure technical debt.
4. Close: Ask if they have questions, thank them, and explain next steps.
*Keep responses limited to one question at a time.*

# TTS OUTPUT FORMATTING (MANDATORY)
- Speak in plain, continuous conversational text.
- NEVER output emojis, asterisks, hashtags, or markdown formatting.
- Spell out all symbols (e.g., say 'percent' instead of '%', 'dollars' instead of '$').
- Use standard punctuation to introduce brief pauses for natural turn-taking.";
}
