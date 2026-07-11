<?php
// api/prepare_practice.php - Prepares practice interview questions using AI

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
    $code = $_POST['code'] ?? $input['code'] ?? null;

    if (!$profileId) {
        throw new Exception("Profile ID is required.");
    }

    $db = getDB();

    $jobRole = "";
    $jobDescription = "";
    $targetLevel = 1;
    $numOpen = 4;
    $numMCQ = 4;

    if ($code) {
        // Fetch the public interview link details by code
        $link = getInterviewLinkByCode($code);
        if (!$link) {
            throw new Exception("Active public assessment link not found.");
        }
        $targetLevel = max(1, (int)($link['min_level'] ?? 0));
        $jobRole = $link['job_role'];
        $jobDescription = $link['job_description'] ?? '';

        if (isset($link['num_open_questions']) && $link['num_open_questions'] !== null) {
            $numOpen = (int)$link['num_open_questions'];
        }
        if (isset($link['num_mcq_questions']) && $link['num_mcq_questions'] !== null) {
            $numMCQ = (int)$link['num_mcq_questions'];
        }
        if ($numOpen + $numMCQ === 0) {
            $numOpen = 4;
            $numMCQ = 4;
        }
    } else {
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
        $jobRole = $detectedRole ?: $userEnteredRole;
        $jobDescription = $resumeData['user_entered_description'] ?? '';
    }

    // 3. Formulate Context
    $roleContext = "Role: " . $jobRole . "\n";
    if ($jobDescription) {
        $roleContext .= "Description: " . $jobDescription . "\n";
    }

    $historyContext = "";
    if (!$code && $targetLevel > 1) {
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

    $levelTaxonomy = [
        1 => "Terminology & Recall: Focus on 'What is X?' - Baseline vocabulary and definitions.",
        2 => "Basic Mechanics: Focus on 'How does X work?' - Understanding the underlying mechanism.",
        3 => "Standard Implementation: Focus on 'How do you use X to do Y?' - Basic execution and coding patterns.",
        4 => "Comparative Analysis: Focus on 'X vs Y?' - Understanding trade-offs and when *not* to use a tool.",
        5 => "Troubleshooting: Focus on 'X is broken/slow, why?' - Debugging and root-cause analysis.",
        6 => "Component Integration: Focus on 'Connect X and Y.' - Handling state, data handoffs, and API boundaries.",
        7 => "Scale & Optimization: Focus on 'X under heavy load.' - Distributed systems, concurrency, and bottlenecks.",
        8 => "Security & Constraints: Focus on 'Secure X for Enterprise.' - Compliance, zero-trust, and failure states.",
        9 => "System Governance: Focus on 'Design a platform using X.' - Tech debt, CI/CD, and team velocity.",
        10 => "Strategic Leadership: Focus on 'ROI and Build vs. Buy.' - Aligning tech with business survival/budget."
    ];
    $levelDescription = $levelTaxonomy[$targetLevel] ?? $levelTaxonomy[1];

    $totalQuestions = $numOpen + $numMCQ;

    $prompt = <<<EOT
<context>
You are generating practice technical interview questions for a candidate.
Candidate Profile:
{$roleContext}

Target Level: {$targetLevel} out of 10.
Level {$targetLevel} Definition: {$levelDescription}
</context>

{$historyContext}

<task>
Generate exactly {$totalQuestions} technical interview questions aligned strictly with the Level {$targetLevel} definition.
Out of these {$totalQuestions} questions:
- Exactly {$numOpen} questions must be standard open-ended technical questions (type: "open").
- Exactly {$numMCQ} questions must be Multiple-Choice Questions (type: "mcq").
For each question, provide a corresponding correct answer.
</task>

<constraints>
- Ensure the difficulty, complexity, and theme of every question strictly matches the Level {$targetLevel} Definition. Do not generate simple definition questions if the level calls for architecture, debugging, or optimization.
- If <history> is present, ensure the new questions cover different TOPICS than those in <history>. Do not repeat concepts.
- For standard open-ended questions (type: "open"):
  - The "question" is the technical question.
  - The "options" field must be null.
  - The "answer" must be a factual explanation strictly under 100 words.
- For MCQ questions (type: "mcq"):
  - The "question" is the multiple-choice question. Do not include or embed options A, B, C, or D in this string.
  - The "options" object must contain exactly four keys: "A", "B", "C", and "D", each mapping to a unique, clear option string.
  - The "answer" must be exactly the correct option letter (one of "A", "B", "C", or "D").
- Do NOT include conversational filler, introductions, pleasantries, or motivational fluff.
- Rely solely on the JSON schema for output formatting. Do not output anything outside the JSON.
</constraints>

<examples>
Example 1: Open-Ended Question
{
  "type": "open",
  "question": "How does JavaScript handle asynchronous operations?",
  "options": null,
  "answer": "JavaScript uses an event loop and a single-threaded call stack. Asynchronous operations like I/O or timers are offloaded to Web APIs. When they complete, their callbacks are pushed to the task queue. The event loop continuously checks if the call stack is empty; if so, it dequeues the next callback from the queue and pushes it onto the stack for execution."
}

Example 2: Multiple-Choice Question (MCQ)
{
  "type": "mcq",
  "question": "Which CSS property is used to align grid items vertically inside their cell?",
  "options": {
    "A": "align-items",
    "B": "justify-items",
    "C": "align-content",
    "D": "grid-gap"
  },
  "answer": "A"
}
</examples>

<output_format>
Return ONLY a JSON array of exactly {$totalQuestions} objects matching the response schema:
[
  {
    "type": "open" | "mcq",
    "question": "string",
    "options": { "A": "string", "B": "string", "C": "string", "D": "string" } | null,
    "answer": "string"
  }
]
</output_format>

<verification>
- Confirm that exactly {$totalQuestions} objects are returned.
- Confirm that exactly {$numOpen} objects have type 'open' and {$numMCQ} objects have type 'mcq'.
- Confirm 'answer' for MCQs is exactly one of the letters: 'A', 'B', 'C', or 'D'.
- Confirm that MCQ options contain exactly keys 'A', 'B', 'C', and 'D'.
</verification>
EOT;

    // 4. Call the question generation model
    $schema = [
        "type" => "array",
        "items" => [
            "type" => "object",
            "properties" => [
                "type" => [
                    "type" => "string",
                    "enum" => ["open", "mcq"],
                    "description" => "The type of question, either 'open' or 'mcq'."
                ],
                "question" => [
                    "type" => "string",
                    "description" => "The text of the question (do not embed options A, B, C, D in this string)."
                ],
                "options" => [
                    "type" => ["object", "null"],
                    "properties" => [
                        "A" => ["type" => "string"],
                        "B" => ["type" => "string"],
                        "C" => ["type" => "string"],
                        "D" => ["type" => "string"]
                    ],
                    "required" => ["A", "B", "C", "D"],
                    "description" => "For MCQ questions, provide four options. For open questions, this field is null."
                ],
                "answer" => [
                    "type" => "string",
                    "description" => "For open questions, a factual explanation strictly under 100 words. For MCQ, the correct option letter (A, B, C, or D)."
                ]
            ],
            "required" => ["type", "question", "answer"]
        ]
    ];

    $responseJson = callAI([
        ["role" => "user", "content" => $prompt]
    ], 'question_generation', [
        'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'questions', 'schema' => $schema]]
    ]);
    $qaData = json_decode($responseJson, true);

    if (!$qaData || !is_array($qaData) || count($qaData) !== $totalQuestions) {
        // Fallback: If AI didn't return exactly $totalQuestions, that's okay, but let's ensure it's valid JSON array
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
