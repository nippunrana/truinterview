<?php
// evaluation_service.php - Candidate Evaluation & Vision Analysis Services

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai_service.php';

/**
 * Analyzes the candidate's screenshot using Gemini vision capabilities.
 */
function analyzeScreenshotForEvaluation($imagePath, $customApiKey = null, $model = 'gemini-3.5-flash') {
    if (!file_exists($imagePath)) {
        return "No screenshot was uploaded.";
    }
    
    $imageData = base64_encode(file_get_contents($imagePath));
    $mimeType = 'image/jpeg';
    
    $payload = [
        "contents" => [
            [
                "parts" => [
                    [
                        "text" => "Analyze the code, UI, or work shown in this screenshot. Provide a brief, technical summary of what is visible, including any programming languages, algorithms, UI designs, or potential bugs/issues. Keep it under 2-3 sentences."
                    ],
                    [
                        "inlineData" => [
                            "mimeType" => $mimeType,
                            "data" => $imageData
                        ]
                    ]
                ]
            ]
        ]
    ];
    
    return callGemini($payload, $model, $customApiKey);
}

/**
 * Generates a comprehensive Gemini feedback/evaluation report for a session.
 */
function generateGeminiEvaluation($sessionId) {
    $session = getSession($sessionId);
    if (!$session) {
        throw new Exception("Session not found");
    }
    
    // Resolve model tasks and custom API keys
    $evalModel = $session['model_eval_task'] ?? 'gemini-3.5-flash';
    $visionModel = $session['model_vision_task'] ?? 'gemini-3.5-flash';
    $customApiKey = getSessionApiKey($session);
    
    // 1. Gather transcripts
    $transcripts = getTranscripts($sessionId);
    $transcriptStr = "";
    foreach ($transcripts as $t) {
        $transcriptStr .= $t['speaker'] . ": " . $t['message'] . "\n";
    }
    
    // 2. Gather MCQ details
    $responses = getCandidateResponses($sessionId);
    $totalMCQ = count($responses);
    $correctMCQ = 0;
    foreach ($responses as $r) {
        if ($r['is_correct']) {
            $correctMCQ++;
        }
    }
    $mcqScoreStr = "{$correctMCQ} out of {$totalMCQ} correct";
    
    // 3. Gather Vision Notes Summary
    $imagePath = __DIR__ . '/uploads/sessions/' . $sessionId . '/latest.jpg';
    $visionNotes = "No screen capture shared.";
    if (file_exists($imagePath) && is_readable($imagePath)) {
        try {
            $visionNotes = analyzeScreenshotForEvaluation($imagePath, $customApiKey, $visionModel);
        } catch (Exception $e) {
            $visionNotes = "Error analyzing latest screen capture: " . $e->getMessage();
        }
    }
    
    // 4. Construct the prompt
    $prompt = "Analyze the following mock interview details and provide a structured evaluation:
- Candidate Name: " . $session['candidate_name'] . "
- MCQ Score: " . $mcqScoreStr . "
- Code Screenshots Context: " . $visionNotes . "
- Dialogue Transcript:
" . $transcriptStr . "
 
Provide a structured evaluation in JSON containing:
1. Communication Score (1-10) with descriptive text.
2. Problem Solving Score (1-10) with descriptive text.
3. Code Quality / Debugging Score (1-10) with details.
4. Key strengths (list of 3 items).
5. Development recommendations (list of 3 items).
6. Comprehensive overall feedback summary.
 
You MUST return ONLY a valid JSON object matching the following schema exactly (no markdown formatting, no backticks, no wrap, just the raw JSON string):
{
  \"communication_score\": 8,
  \"communication_feedback\": \"Descriptive feedback here...\",
  \"problem_solving_score\": 7,
  \"problem_solving_feedback\": \"Descriptive feedback here...\",
  \"code_quality_score\": 9,
  \"code_quality_feedback\": \"Descriptive feedback here...\",
  \"strengths\": [
    \"strength 1\",
    \"strength 2\",
    \"strength 3\"
  ],
  \"recommendations\": [
    \"rec 1\",
    \"rec 2\",
    \"rec 3\"
  ],
  \"overall_feedback\": \"Comprehensive overall feedback summary.\"
}";
    
    $payload = [
        "contents" => [
            [
                "parts" => [
                    [
                        "text" => $prompt
                    ]
                ]
            ]
        ],
        "generationConfig" => [
            "responseMimeType" => "application/json"
        ]
    ];
    
    $jsonResponse = callGemini($payload, $evalModel, $customApiKey);
    
    $decoded = json_decode($jsonResponse, true);
    if (!$decoded) {
        throw new Exception("Failed to generate a valid JSON evaluation from Gemini.");
    }
    
    return $decoded;
}
