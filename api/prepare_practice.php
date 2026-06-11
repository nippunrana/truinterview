<?php
// api/prepare_practice.php - Prepares practice interview questions using Gemini

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../ai_service.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Method not allowed. Use POST.");
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $currentUser = getCurrentUser();
    if (!$currentUser || $currentUser['role'] !== 'candidate') {
        throw new Exception("Unauthorized. Please log in as a candidate.");
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $profileId = $_POST['profile_id'] ?? $input['profile_id'] ?? null;

    if (!$profileId) {
        throw new Exception("Profile ID is required.");
    }

    $db = getDB();

    // 1. Fetch Candidate Profile
    $stmt = $db->prepare("SELECT role_title, level, resume_data FROM candidate_profiles WHERE id = :profile_id AND user_id = :user_id");
    $stmt->execute(['profile_id' => $profileId, 'user_id' => $currentUser['id']]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        throw new Exception("Profile not found or access denied.");
    }

    $currentLevel = (int)($profile['level'] ?? 0);
    $targetLevel = $currentLevel === 0 ? 1 : $currentLevel + 1;
    
    if ($targetLevel > 10) {
        throw new Exception("You have completed all 10 levels for this role!");
    }

    $resumeData = !empty($profile['resume_data']) ? json_decode($profile['resume_data'], true) : [];
    $userEnteredRole = $resumeData['user_entered_role'] ?? $profile['role_title'];
    $detectedRole = $resumeData['detected_role'] ?? '';
    $userEnteredDescription = $resumeData['user_entered_description'] ?? '';

    // 2. Fetch User Model Settings
    $stmt = $db->prepare("SELECT model_eval_task FROM users WHERE id = :id");
    $stmt->execute(['id' => $currentUser['id']]);
    $userSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    $modelEval = $userSettings['model_eval_task'] ?: 'gemini-3.5-flash';

    // 3. Formulate Context
    $roleContext = "Role: " . ($detectedRole ?: $userEnteredRole) . "\n";
    if ($userEnteredDescription) {
        $roleContext .= "Description: " . $userEnteredDescription . "\n";
    }

    $historyContext = "";
    if ($targetLevel > 1) {
        // Fetch previous session Q&A to progressively make it harder
        $stmt = $db->prepare("SELECT q_a FROM sessions WHERE profile_id = :profile_id AND q_a IS NOT NULL ORDER BY started_at DESC LIMIT 1");
        $stmt->execute(['profile_id' => $profileId]);
        $prevSession = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($prevSession && $prevSession['q_a']) {
            $prevQA = json_decode($prevSession['q_a'], true);
            if (is_array($prevQA)) {
                $historyContext .= "<history>\n";
                $historyContext .= "Questions asked in the previous level (Level " . ($targetLevel - 1) . "):\n";
                foreach ($prevQA as $idx => $qa) {
                    $historyContext .= ($idx + 1) . ". " . $qa['question'] . "\n";
                }
                $historyContext .= "</history>\n\n";
            }
        }
    }

    $prompt = <<<EOT
<context>
You are generating practice interview questions for a candidate.
Target Level: {$targetLevel} out of 10.
Candidate Profile:
{$roleContext}
</context>

{$historyContext}<task>
Generate exactly 10 interview questions appropriate for the candidate's target level.
For each question, also provide an expected answer.
</task>

<constraints>
- If <history> is present, the new questions MUST be distinct from previous questions and cover more advanced concepts or different aspects of the role to ensure skill progression.
- Answers must be concise, factual, and strictly under 100 words.
- Do NOT include conversational filler, introductions, pleasantries, or motivational fluff.
- Rely solely on the JSON schema for output formatting. Do not output anything outside the JSON.
</constraints>
EOT;

    // 4. Call Gemini
    $schema = [
        "type" => "ARRAY",
        "items" => [
            "type" => "OBJECT",
            "properties" => [
                "question" => [
                    "type" => "STRING",
                    "description" => "The interview question."
                ],
                "answer" => [
                    "type" => "STRING",
                    "description" => "A straight-forward answer in less than 100 words without fluff."
                ]
            ],
            "required" => ["question", "answer"]
        ]
    ];

    $payload = [
        "contents" => [
            [
                "role" => "user",
                "parts" => [
                    ["text" => $prompt]
                ]
            ]
        ],
        "generationConfig" => [
            "responseMimeType" => "application/json",
            "responseSchema" => $schema,
            "temperature" => 0.7
        ]
    ];

    $responseJson = callGemini($payload, $modelEval);
    $qaData = json_decode($responseJson, true);

    if (!$qaData || !is_array($qaData) || count($qaData) !== 10) {
        // Fallback: If AI didn't return exactly 10, that's okay, but let's ensure it's valid JSON array
        if (!is_array($qaData)) {
            throw new Exception("AI generated invalid JSON structure.");
        }
    }

    // 5. Store in Session
    $_SESSION['pending_qa_' . $profileId] = json_encode($qaData);
    $_SESSION['pending_level_' . $profileId] = $targetLevel;

    echo json_encode([
        "status" => "success",
        "target_level" => $targetLevel,
        "qa_data" => $qaData,
        "message" => "Prepared " . count($qaData) . " questions successfully."
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
