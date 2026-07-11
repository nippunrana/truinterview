<?php
// proctor_service.php - Vision Proctoring & Warning Generators

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai_service.php';

/**
 * Calls the vision model with a focused prompt to analyze a proctoring snapshot.
 * Returns ['verdict' => string, 'confirmed' => bool]
 */
function analyzeProctorSnapshot($imagePath, $alertType, $clientDetails) {
    if (!file_exists($imagePath)) {
        throw new Exception("Snapshot image file not found: " . $imagePath);
    }

    $imageData = base64_encode(file_get_contents($imagePath));

    $messages = [
        [
            "role" => "user",
            "content" => [
                ["type" => "text", "text" => getProctorPrompt($alertType)],
                ["type" => "image_url", "image_url" => ["url" => "data:image/jpeg;base64," . $imageData]]
            ]
        ]
    ];

    try {
        $responseJson = callAI($messages, 'proctor_vision', [
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'result', 'schema' => [
                'type' => 'object',
                'properties' => [
                    'confirmed' => ['type' => 'boolean'],
                    'reason' => ['type' => 'string']
                ],
                'required' => ['confirmed', 'reason']
            ]]]
        ]);
        $data = json_decode($responseJson, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['confirmed'])) {
            // Fallback parsing if JSON output is malformed or missing fields
            $confirmed = (stripos($responseJson, '"confirmed": true') !== false || stripos($responseJson, '"confirmed":true') !== false);
            $reason = $data['reason'] ?? $responseJson;
            return [
                'verdict' => $reason,
                'confirmed' => $confirmed
            ];
        }

        return [
            'verdict' => $data['reason'] ?? 'No explanation provided.',
            'confirmed' => (bool)$data['confirmed']
        ];
    } catch (Exception $e) {
        return [
            'verdict' => 'AI analysis failed: ' . $e->getMessage(),
            'confirmed' => true // Default to true (trust the local detector) if the AI call fails
        ];
    }
}

/**
 * Returns the appropriate vision prompt based on the alert type.
 */
function getProctorPrompt($alertType) {
    switch ($alertType) {
        case 'no_face':
            return "You are a webcam monitoring assistant for a live interview. The local system flagged that no face was detected in the frame.\n" .
                   "Please analyze this webcam snapshot.\n" .
                   "Task: Identify if a human candidate is visible in front of the camera, and if they are looking/engaging with the camera.\n" .
                   "Respond with JSON:\n" .
                   "{\n" .
                   "  \"confirmed\": true, // set to true if the candidate is indeed absent or completely obscured/not visible. Set to false if you can clearly see a human candidate's face or body sitting in front of the camera.\n" .
                   "  \"reason\": \"A concise description of what you see (e.g., 'The chair is empty', 'A candidate is present but looking down', 'A person is visible but their face is turned away').\"\n" .
                   "}";
        
        case 'multiple_faces':
            return "You are a webcam monitoring assistant for a live interview. The local system flagged that multiple faces were detected in the frame.\n" .
                   "Please analyze this webcam snapshot.\n" .
                   "Task: Identify how many distinct human faces/people are visible in the frame.\n" .
                   "Respond with JSON:\n" .
                   "{\n" .
                   "  \"confirmed\": true, // set to true if there are multiple people visible in the frame. Set to false if there is only one person visible in the frame (e.g., background pictures or reflections that are not actual people).\n" .
                   "  \"reason\": \"A concise explanation of the number of people visible (e.g., 'Two people are visible in the frame', 'Only one person is visible; the background poster was misidentified').\"\n" .
                   "}";
        
        case 'gaze_away':
            return "You are a webcam monitoring assistant for a live interview. The local system flagged that the candidate has been looking away from the screen/camera for an extended period.\n" .
                   "Please analyze this webcam snapshot.\n" .
                   "Task: Check if the person is actively looking away (e.g., reading from another screen/phone to their left/right/below, or talking to someone else) versus just looking slightly off-center or thinking.\n" .
                   "Respond with JSON:\n" .
                   "{\n" .
                   "  \"confirmed\": true, // set to true if the candidate is clearly looking away from the camera/screen (e.g., turned far side, looking down at a phone). Set to false if they are looking at or near the screen, or if it is a minor glance.\n" .
                   "  \"reason\": \"A concise explanation of their gaze direction and head orientation.\"\n" .
                   "}";
        
        case 'face_changed':
            return "You are a webcam monitoring assistant for a live interview. The local system flagged a potential change in the face in the frame.\n" .
                   "Please analyze this webcam snapshot.\n" .
                   "Task: Check if the candidate sitting in front of the camera has changed/is a different person than who was there initially.\n" .
                   "Respond with JSON:\n" .
                   "{\n" .
                   "  \"confirmed\": true, // set to true if the person in the frame appears completely different or if there is a clear change of candidate. Set to false if it is the same candidate.\n" .
                   "  \"reason\": \"A concise explanation of why the face changed or didn't change.\"\n" .
                   "}";

        default:
            return "You are a webcam monitoring assistant for a live interview. The local system flagged an integrity warning: " . $alertType . ".\n" .
                   "Please analyze this webcam snapshot.\n" .
                   "Respond with JSON:\n" .
                   "{\n" .
                   "  \"confirmed\": true, // set to true if you confirm an integrity anomaly. Set to false otherwise.\n" .
                   "  \"reason\": \"A concise explanation of what you see in the frame.\"\n" .
                   "}";
    }
}

/**
 * Formulates a friendly but firm TTS-friendly warning message for TruGen to speak.
 */
function buildProctorWarningMessage($alertType, $aiVerdict) {
    switch ($alertType) {
        case 'no_face':
            return "I noticed you stepped away from the camera. Please ensure you remain visible in front of the camera throughout the interview.";
        case 'multiple_faces':
            return "I detected multiple people in the camera frame. Please ensure you are alone during the interview.";
        case 'gaze_away':
            return "Please keep your attention on the screen. Looking away repeatedly is not permitted.";
        case 'tab_switch':
            return "Please do not switch tabs or windows. Navigating away is logged as a violation.";
        case 'fullscreen_exit':
            return "Fullscreen mode is required. Please re-enter fullscreen immediately to continue.";
        case 'copy_paste_attempt':
            return "Clipboard actions are restricted during this assessment.";
        case 'cursor_left_screen':
            return "Please keep your mouse focus on the assessment browser screen.";
        case 'device_change':
            return "A peripheral or device connection change has been detected and logged.";
        default:
            return "Please ensure you follow the interview integrity rules.";
    }
}
