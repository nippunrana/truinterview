<?php
// api.php - Basic Router & API Controller
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST");

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ai_service.php';
require_once __DIR__ . '/trugen_service.php';
require_once __DIR__ . '/evaluation_service.php';

$action = $_GET['action'] ?? '';

try {
    if ($action === 'trigger_mcq') {
        $sessionId = $_GET['session_id'] ?? $_COOKIE['session_id'] ?? '';
        if (empty($sessionId)) {
            throw new Exception("Session ID required");
        }
        
        $session = getSession($sessionId);
        if (!$session) {
            throw new Exception("Session not found");
        }
        
        $question = getNextUnansweredQuestion($sessionId);
        if (!$question) {
            throw new Exception("No unanswered MCQ questions available.");
        }
        
        $db = getDB();
        $stmt = $db->prepare("UPDATE sessions SET current_status = 'MCQ_PROMPTING', mcq_preference = 'PENDING' WHERE id = :id");
        $stmt->execute(['id' => $sessionId]);
        
        logTranscript($sessionId, 'SYSTEM', "MCQ flow triggered.");
        $promptText = "I have loaded some multiple-choice questions on your screen. Would you like me to read them to you, or do you prefer reading them yourself?";
        logTranscript($sessionId, 'AGENT', $promptText);
        
        if (!empty($session['trugen_conversation_id'])) {
            injectSpeakText($session['trugen_conversation_id'], $promptText);
        }
        
        echo json_encode([
            "status" => "success",
            "message" => "MCQ flow started"
        ]);
        exit;
    }
    
    if ($action === 'get_mcq_state') {
        $sessionId = $_GET['session_id'] ?? $_COOKIE['session_id'] ?? '';
        if (empty($sessionId)) {
            throw new Exception("Session ID required");
        }
        
        $session = getSession($sessionId);
        if (!$session) {
            throw new Exception("Session not found");
        }
        
        $status = $session['current_status'];
        if ($status === 'MCQ_PROMPTING' || $status === 'MCQ_ACTIVE') {
            $question = getNextUnansweredQuestion($sessionId);
            if ($question) {
                echo json_encode([
                    "status" => "success",
                    "has_active_mcq" => true,
                    "mcq_preference" => $session['mcq_preference'],
                    "question" => [
                        "id" => $question['id'],
                        "topic" => $question['topic'],
                        "question" => $question['question'],
                        "option_a" => $question['option_a'],
                        "option_b" => $question['option_b'],
                        "option_c" => $question['option_c'],
                        "option_d" => $question['option_d']
                    ]
                ]);
                exit;
            }
        }
        
        echo json_encode([
            "status" => "success",
            "has_active_mcq" => false
        ]);
        exit;
    }
    
    if ($action === 'submit_option') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception("Method not allowed. Use POST.");
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $sessionId = $input['session_id'] ?? $_COOKIE['session_id'] ?? '';
        $questionId = $input['question_id'] ?? '';
        $selectedOption = $input['option'] ?? '';
        
        if (empty($sessionId) || empty($questionId) || empty($selectedOption)) {
            throw new Exception("session_id, question_id, and option are required");
        }
        
        $session = getSession($sessionId);
        if (!$session) {
            throw new Exception("Session not found");
        }
        
        $question = getMCQQuestionById($questionId);
        if (!$question) {
            throw new Exception("Question not found");
        }
        
        $isCorrect = (strtoupper(trim($selectedOption)) === strtoupper(trim($question['correct_option'])));
        saveCandidateResponse($sessionId, $questionId, $selectedOption, $isCorrect);
        
        logTranscript($sessionId, 'USER', "Selected Option " . $selectedOption);
        
        $nextQuestion = getNextUnansweredQuestion($sessionId);
        
        $feedback = "";
        if ($isCorrect) {
            $feedback = "That is correct!";
        } else {
            $feedback = "That is incorrect. The correct answer was Option " . $question['correct_option'] . ".";
        }
        
        if ($nextQuestion) {
            $db = getDB();
            $nextPrompt = "";
            if ($session['mcq_preference'] === 'READ') {
                $nextPrompt = " Let's move to the next question. The question is: " . $nextQuestion['question'] . 
                              ". Option A: " . $nextQuestion['option_a'] . 
                              ". Option B: " . $nextQuestion['option_b'] . 
                              ". Option C: " . $nextQuestion['option_c'] . 
                              ". Option D: " . $nextQuestion['option_d'] . 
                              ". Which one do you think is correct?";
                $stmt = $db->prepare("UPDATE sessions SET current_status = 'MCQ_ACTIVE' WHERE id = :id");
                $stmt->execute(['id' => $sessionId]);
            } else {
                $nextPrompt = " Let's move to the next question. Please read it on your screen and select your answer.";
                $stmt = $db->prepare("UPDATE sessions SET current_status = 'MCQ_ACTIVE' WHERE id = :id");
                $stmt->execute(['id' => $sessionId]);
            }
            
            $spokenText = $feedback . $nextPrompt;
            logTranscript($sessionId, 'AGENT', $spokenText);
            if (!empty($session['trugen_conversation_id'])) {
                injectSpeakText($session['trugen_conversation_id'], cleanSpeechText($spokenText));
            }
        } else {
            $db = getDB();
            $stmt = $db->prepare("UPDATE sessions SET current_status = 'COMPLETED', completed_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute(['id' => $sessionId]);
            
            $spokenText = $feedback . " We have completed the MCQ assessment. Thank you.";
            logTranscript($sessionId, 'AGENT', $spokenText);
            logTranscript($sessionId, 'SYSTEM', "MCQ assessment completed.");
            
            if (!empty($session['trugen_conversation_id'])) {
                injectSpeakText($session['trugen_conversation_id'], cleanSpeechText($spokenText));
            }
        }
        
        echo json_encode([
            "status" => "success",
            "message" => "Option submitted successfully",
            "is_correct" => $isCorrect,
            "has_more" => !empty($nextQuestion)
        ]);
        exit;
    }

    if ($action === 'complete') {
        $sessionId = $_GET['session_id'] ?? $_COOKIE['session_id'] ?? '';
        if (empty($sessionId)) {
            throw new Exception("Session ID required");
        }
        
        $session = getSession($sessionId);
        if (!$session) {
            throw new Exception("Session not found");
        }
        
        $db = getDB();
        if ($session['current_status'] !== 'COMPLETED') {
            $stmt = $db->prepare("UPDATE sessions SET current_status = 'COMPLETED', completed_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute(['id' => $sessionId]);
            
            // Re-fetch updated session to ensure we have correct properties after state transition
            $session = getSession($sessionId);
        }


        
        // Terminate TruGen conversation if there is an active session
        if (!empty($session['trugen_conversation_id'])) {
            terminateTruGenConversation($session['trugen_conversation_id']);
            $stmt = $db->prepare("UPDATE sessions SET trugen_conversation_id = NULL WHERE id = :id");
            $stmt->execute(['id' => $sessionId]);
            $session['trugen_conversation_id'] = null;
        }
        
        // Re-fetch updated session
        $session = getSession($sessionId);
        
        $fast = $_GET['fast'] ?? '0';
        if ($fast === '1') {
            echo json_encode([
                "status" => "success",
                "session" => $session
            ]);
            exit;
        }
        
        // If final_score is not generated, generate it using Gemini
        if (empty($session['final_score'])) {
            if (($session['closure_reason'] ?? '') === 'misconduct') {
                $evaluation = [
                    "communication_score" => 0,
                    "communication_feedback" => "Interview terminated early due to repeated misconduct / off-topic behavior.",
                    "problem_solving_score" => 0,
                    "problem_solving_feedback" => "Interview terminated early due to repeated misconduct / off-topic behavior.",
                    "code_quality_score" => 0,
                    "code_quality_feedback" => "Interview terminated early due to repeated misconduct / off-topic behavior.",
                    "strengths" => [
                        "None"
                    ],
                    "recommendations" => [
                        "Maintain professional conduct during technical interviews.",
                        "Engage seriously with the assessment questions.",
                        "Avoid off-topic conversations or prompt injection attempts."
                    ],
                    "overall_feedback" => "The interview was terminated by the automated system due to a breach of the professional conduct guidelines. Multiple warnings were issued for off-topic behavior or prompt-injection attempts before closure."
                ];
            } else {
                $evaluation = generateGeminiEvaluation($sessionId);
            }
            
            $stmt = $db->prepare("UPDATE sessions SET final_score = :final_score WHERE id = :id");
            $stmt->execute([
                'final_score' => json_encode($evaluation),
                'id' => $sessionId
            ]);
            
            $session['final_score'] = json_encode($evaluation);
        }

        // If the session has an associated profile_id, it is completed, it is a practice session,
        // and we have a final score generated, check if the candidate scored >= 60% to pass the level.
        if (!empty($session['profile_id']) && ($session['session_type'] ?? '') === 'practice' && !empty($session['final_score'])) {
            $eval = json_decode($session['final_score'], true);
            if (is_array($eval)) {
                $comm = (float)($eval['communication_score'] ?? 0);
                $prob = (float)($eval['problem_solving_score'] ?? 0);
                $qual = (float)($eval['code_quality_score'] ?? 0);
                $avgScore = ($comm + $prob + $qual) / 3.0; // average score out of 10
                $percentage = $avgScore * 10.0; // convert to percentage out of 100
                
                if ($percentage >= 60.0) {
                    $sessionLevel = (int)($session['level'] ?? 0);
                    if ($sessionLevel > 0) {
                        $stmtProfile = $db->prepare("UPDATE candidate_profiles SET level = GREATEST(level, :level) WHERE id = :profile_id");
                        $stmtProfile->execute([
                            'level' => $sessionLevel,
                            'profile_id' => $session['profile_id']
                        ]);
                    }
                }
            }
        }
        
        // Fetch MCQ responses
        $responses = getCandidateResponses($sessionId);
        
        echo json_encode([
            "status" => "success",
            "session" => $session,
            "mcq_responses" => $responses,
            "final_score" => json_decode($session['final_score'], true)
        ]);
        exit;
    }

    if ($action === 'start') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception("Method not allowed. Use POST.");
        }
        
        // Handle both standard form submit and JSON payload
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $inviteCode = $_POST['invite_code'] ?? '';
        $profileId = $_POST['profile_id'] ?? null;
        
        if (empty($name) || empty($email)) {
            $input = json_decode(file_get_contents('php://input'), true);
            $name = $input['name'] ?? '';
            $email = $input['email'] ?? '';
            $inviteCode = $input['invite_code'] ?? '';
            $profileId = $input['profile_id'] ?? $profileId;
        }

        if (empty($name) || empty($email)) {
            throw new Exception("Candidate name and email are required");
        }

        // Get currently logged-in user if available
        $userId = null;
        $currentUser = getCurrentUser();
        if ($currentUser && $currentUser['role'] === 'candidate') {
            $userId = $currentUser['id'];
        }

        $linkId = null;
        $templateId = null;
        $sessionType = 'practice';
        
        // Defaults
        $modelChat = $_POST['model_chat_task'] ?? $input['model_chat_task'] ?? null;
        $modelVision = $_POST['model_vision_task'] ?? $input['model_vision_task'] ?? null;
        $modelEval = $_POST['model_eval_task'] ?? $input['model_eval_task'] ?? null;

        if ($userId) {
            $db = getDB();
            $stmt = $db->prepare("SELECT model_chat_task, model_vision_task, model_eval_task FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $candidateDefaults = $stmt->fetch();
            if ($candidateDefaults) {
                if (empty($modelChat)) {
                    $modelChat = $candidateDefaults['model_chat_task'] ?: null;
                }
                if (empty($modelVision)) {
                    $modelVision = $candidateDefaults['model_vision_task'] ?: null;
                }
                if (empty($modelEval)) {
                    $modelEval = $candidateDefaults['model_eval_task'] ?: null;
                }
            }
        }

        if (empty($modelChat)) $modelChat = 'gemini-3.1-flash-lite';
        if (empty($modelVision)) $modelVision = 'gemini-3.1-flash-lite';
        if (empty($modelEval)) $modelEval = 'gemini-3.1-flash-lite';

        if (!empty($inviteCode)) {
            $link = getInterviewLinkByCode($inviteCode);
            if (!$link) {
                throw new Exception("Invalid or inactive invitation code.");
            }
            if ($link['expires_at'] && strtotime($link['expires_at']) < time()) {
                throw new Exception("This invitation code has expired.");
            }
            if ($link['attempts_used'] >= $link['max_attempts']) {
                throw new Exception("This invitation code has already been used maximum allowed times.");
            }
            
            $linkId = $link['id'];
            $templateId = $link['template_id'];
            $sessionType = 'assessment';

            // Increment attempts
            incrementLinkAttempts($linkId);
            
            // Resolve recruiter settings
            $db = getDB();
            $stmt = $db->prepare("SELECT u.model_chat_task, u.model_vision_task, u.model_eval_task FROM users u WHERE u.id = :id");
            $stmt->execute(['id' => $link['created_by']]);
            $recruiterSettings = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($recruiterSettings) {
                $modelChat = $recruiterSettings['model_chat_task'] ?: $modelChat;
                $modelVision = $recruiterSettings['model_vision_task'] ?: $modelVision;
                $modelEval = $recruiterSettings['model_eval_task'] ?: $modelEval;
            }
        }
        
        // Start session to access pending QA data
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $qaJson = null;
        $targetLevel = null;
        if ($profileId) {
            $qaKey = 'pending_qa_' . $profileId;
            $levelKey = 'pending_level_' . $profileId;
            if (isset($_SESSION[$qaKey])) {
                $qaJson = $_SESSION[$qaKey];
                unset($_SESSION[$qaKey]);
            }
            if (isset($_SESSION[$levelKey])) {
                $targetLevel = $_SESSION[$levelKey];
                unset($_SESSION[$levelKey]);
            }
        }

        $sessionId = createSession($name, $email, $userId, $linkId, $templateId, $sessionType, $modelChat, $modelVision, $modelEval, $profileId, $qaJson, $targetLevel);
        
        // Set client cookie
        $_SESSION['session_id'] = $sessionId;
        setcookie("session_id", $sessionId, time() + 86400, "/");

        // Write system welcome message to transcripts log
        logTranscript($sessionId, 'SYSTEM', "Session started for candidate: $name (" . ($sessionType === 'practice' ? 'Practice' : 'Assessment') . ")");

        echo json_encode([
            "status" => "success",
            "session_id" => $sessionId
        ]);
        exit;
    }
    
    if ($action === 'status') {
        $sessionId = $_GET['session_id'] ?? $_COOKIE['session_id'] ?? '';
        if (empty($sessionId)) {
            throw new Exception("Session ID required");
        }
        
        $session = getSession($sessionId);
        if (!$session) {
            throw new Exception("Session not found");
        }
        
        $transcripts = getTranscripts($sessionId);
        
        echo json_encode([
            "status" => "success",
            "session" => $session,
            "transcripts" => $transcripts
        ]);
        exit;
    }
    
    if ($action === 'upload_frame') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception("Method not allowed. Use POST.");
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $frame = $input['frame'] ?? '';
        
        if (empty($frame)) {
            throw new Exception("No frame payload provided");
        }
        
        $sessionId = $input['session_id'] ?? $_COOKIE['session_id'] ?? '';
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($sessionId)) {
            $sessionId = $_SESSION['session_id'] ?? '';
        }
        
        if (empty($sessionId)) {
            throw new Exception("Active session required for uploads");
        }
        
        $session = getSession($sessionId);
        if (!$session) {
            throw new Exception("Invalid session ID");
        }
        
        if (preg_match('/^data:image\/(\w+);base64,(.*)$/', $frame, $matches)) {
            $type = strtolower($matches[1]);
            $data = base64_decode($matches[2]);
            if ($data === false) {
                throw new Exception("Invalid base64 payload");
            }
        } else {
            throw new Exception("Payload format invalid, expected base64 data URI");
        }
        
        if (!in_array($type, ['jpg', 'jpeg', 'png'])) {
            throw new Exception("Unsupported file format: " . $type);
        }
        
        $uploadDir = __DIR__ . '/uploads/sessions/' . $sessionId;
        if (!file_exists($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                throw new Exception("Failed to create upload directory");
            }
        }
        
        $filePath = $uploadDir . '/latest.jpg';
        if (file_put_contents($filePath, $data) === false) {
            throw new Exception("Failed to write frame file to disk");
        }
        
        echo json_encode([
            "status" => "success",
            "message" => "Frame uploaded successfully",
            "session_id" => $sessionId,
            "filepath" => "uploads/sessions/" . $sessionId . "/latest.jpg"
        ]);
        exit;
    }

    if ($action === 'proctor_alert') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception("Method not allowed. Use POST.");
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            throw new Exception("Invalid JSON payload");
        }
        
        $sessionId = $input['session_id'] ?? $_COOKIE['session_id'] ?? '';
        if (empty($sessionId)) {
            throw new Exception("Session ID required");
        }
        
        $session = getSession($sessionId);
        if (!$session) {
            throw new Exception("Session not found");
        }
        
        $alertType = $input['alert_type'] ?? '';
        $severity = $input['severity'] ?? 'warning';
        $clientDetails = $input['client_details'] ?? [];
        $snapshot = $input['snapshot'] ?? '';
        
        if (empty($alertType)) {
            throw new Exception("Alert type is required");
        }
        
        $snapshotPath = null;
        if (!empty($snapshot)) {
            if (preg_match('/^data:image\/(\w+);base64,(.*)$/', $snapshot, $matches)) {
                $type = strtolower($matches[1]);
                $data = base64_decode($matches[2]);
                if ($data === false) {
                    throw new Exception("Invalid base64 payload");
                }
            } else {
                throw new Exception("Payload format invalid, expected base64 data URI");
            }
            
            if (!in_array($type, ['jpg', 'jpeg', 'png'])) {
                throw new Exception("Unsupported file format: " . $type);
            }
            
            $uploadDir = __DIR__ . '/uploads/sessions/' . $sessionId;
            if (!file_exists($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    throw new Exception("Failed to create upload directory");
                }
            }
            
            $filename = 'proctor_' . time() . '_' . uniqid() . '.jpg';
            $snapshotPath = 'uploads/sessions/' . $sessionId . '/' . $filename;
            $filePath = __DIR__ . '/' . $snapshotPath;
            if (file_put_contents($filePath, $data) === false) {
                throw new Exception("Failed to write snapshot file to disk");
            }
        }
        
        // Load proctor service
        require_once __DIR__ . '/proctor_service.php';
        
        // Determine which Gemini API key to override with
        $apiKeyOverride = getSessionApiKey($session);
        $model = $session['model_vision_task'] ?? 'gemini-3.5-flash';
        
        // Analyze snapshot using Gemini Vision (skip for browser-native deterministic telemetry)
        $aiVerdict = 'AI analysis skipped.';
        $aiConfirmed = true; // default to true if no snapshot is available for analysis
        
        $browserAlerts = ['tab_switch', 'fullscreen_exit', 'copy_paste_attempt', 'cursor_left_screen', 'device_change', 'screen_share_stopped'];
        
        if ($snapshotPath) {
            if (in_array($alertType, $browserAlerts)) {
                $aiVerdict = 'Browser-native telemetry logged.';
                $aiConfirmed = true;
            } else {
                $analysis = analyzeProctorSnapshot(__DIR__ . '/' . $snapshotPath, $alertType, $clientDetails, $apiKeyOverride, $model);
                $aiVerdict = $analysis['verdict'] ?? 'AI analysis completed.';
                $aiConfirmed = isset($analysis['confirmed']) ? (bool)$analysis['confirmed'] : true;
            }
        }
        
        // Save alert to database
        $alertId = saveProctorAlert($sessionId, $alertType, $severity, $clientDetails, $snapshotPath, $aiVerdict, $aiConfirmed);
        
        // Log to transcript
        $logMessage = "Proctor warning: [Type: " . $alertType . "] [Severity: " . $severity . "] AI Confirmed: " . ($aiConfirmed ? 'Yes' : 'No') . " - Verdict: " . $aiVerdict;
        logTranscript($sessionId, 'SYSTEM', $logMessage);
        
        // If alert is confirmed and severity is critical, we can speak a warning.
        $shouldSpeak = false;
        if ($aiConfirmed && $severity === 'critical') {
            // Count existing confirmed critical alerts in db
            $db = getDB();
            $stmt = $db->prepare("SELECT COUNT(*) FROM proctor_alerts WHERE session_id = :session_id AND severity = 'critical' AND ai_confirmed = TRUE");
            $stmt->execute(['session_id' => $sessionId]);
            $criticalCount = (int)$stmt->fetchColumn();
            
            // If this is the first one (since it was just inserted, count will be 1)
            if ($criticalCount === 1) {
                $shouldSpeak = true;
            }
        }
        
        $warningSpoken = false;
        if ($shouldSpeak) {
            $speakText = buildProctorWarningMessage($alertType, $aiVerdict);
            require_once __DIR__ . '/trugen_service.php';
            if (!empty($session['trugen_conversation_id'])) {
                try {
                    injectSpeakText($session['trugen_conversation_id'], $speakText);
                    $warningSpoken = true;
                    logTranscript($sessionId, 'SYSTEM', "Spoke warning to candidate: " . $speakText);
                } catch (Exception $e) {
                    logTranscript($sessionId, 'SYSTEM', "Failed to inject speak warning: " . $e->getMessage());
                }
            }
        }
        
        echo json_encode([
            "status" => "success",
            "alert_id" => $alertId,
            "ai_verdict" => $aiVerdict,
            "ai_confirmed" => $aiConfirmed,
            "warning_spoken" => $warningSpoken
        ]);
        exit;
    }
    
    if ($action === 'proctor_status') {
        $sessionId = $_GET['session_id'] ?? $_COOKIE['session_id'] ?? '';
        if (empty($sessionId)) {
            throw new Exception("Session ID required");
        }
        
        $session = getSession($sessionId);
        if (!$session) {
            throw new Exception("Session not found");
        }
        
        $alerts = getProctorAlerts($sessionId);
        $confirmedCount = getProctorAlertCount($sessionId);
        
        echo json_encode([
            "status" => "success",
            "alert_count" => count($alerts),
            "confirmed_count" => $confirmedCount,
            "alerts" => $alerts
        ]);
        exit;
    }

    if ($action === 'webhook') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception("Method not allowed. Use POST.");
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            throw new Exception("Invalid JSON webhook payload");
        }
        
        $convId = $input['conversation_id'] ?? '';
        if (empty($convId)) {
            throw new Exception("Missing conversation_id");
        }
        
        $event = $input['event'] ?? [];
        $eventName = $event['name'] ?? '';
        $eventPayload = $event['payload'] ?? [];
        
        $session = getSessionByConversationId($convId);
        if (!$session) {
            // Auto-associate the conversation_id with the latest STARTED session
            $session = getLatestStartedSession();
            if ($session) {
                updateSessionConversation($session['id'], $convId);
                $session = getSession($session['id']);
            }
        }
        
        if (!$session) {
            // Create a temporary session for testing if no active session exists
            $sessionId = createSession("Test Candidate", "test@example.com");
            updateSessionConversation($sessionId, $convId);
            $session = getSession($sessionId);
        }
        
        $sessionId = $session['id'];
        
        if ($eventName === 'agent.started_speaking') {
            $text = $eventPayload['text'] ?? '';
            if (is_array($text)) {
                $text = implode(" ", $text);
            }
            logTranscript($sessionId, 'AGENT', $text);
            
            $db = getDB();
            if (($session['current_status'] ?? '') === 'TERMINATING') {
                // Keep the status as 'TERMINATING' so it is not overwritten
            } elseif (!empty($text) && (stripos($text, 'interview is complete') !== false || 
                stripos($text, 'generate your feedback report') !== false || 
                stripos($text, 'analyze your responses') !== false)) {
                
                $stmt = $db->prepare("UPDATE sessions SET current_status = 'COMPLETED', completed_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute(['id' => $sessionId]);
                
                if (!empty($convId)) {
                    terminateTruGenConversation($convId);
                }
            } else {
                $stmt = $db->prepare("UPDATE sessions SET current_status = 'AGENT_SPEAKING' WHERE id = :id");
                $stmt->execute(['id' => $sessionId]);
            }
            
        } elseif ($eventName === 'agent.stopped_speaking') {
            $text = $eventPayload['text'] ?? '';
            if (is_array($text)) {
                $text = implode(" ", $text);
            }
            logTranscript($sessionId, 'AGENT', $text);
            
            $db = getDB();
            if (($session['current_status'] ?? '') === 'TERMINATING') {
                $stmt = $db->prepare("UPDATE sessions SET current_status = 'COMPLETED', completed_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute(['id' => $sessionId]);
                
                if (!empty($convId)) {
                    terminateTruGenConversation($convId);
                }
            } elseif (!empty($text) && (stripos($text, 'interview is complete') !== false || 
                stripos($text, 'generate your feedback report') !== false || 
                stripos($text, 'analyze your responses') !== false)) {
                
                $stmt = $db->prepare("UPDATE sessions SET current_status = 'COMPLETED', completed_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute(['id' => $sessionId]);
                
                if (!empty($convId)) {
                    terminateTruGenConversation($convId);
                }
            } else {
                $stmt = $db->prepare("UPDATE sessions SET current_status = 'IN_PROGRESS' WHERE id = :id");
                $stmt->execute(['id' => $sessionId]);
            }
            
        } elseif ($eventName === 'utterance_committed') {
            $candidateText = $eventPayload['text'] ?? '';
            if (is_array($candidateText)) {
                $candidateText = implode(" ", $candidateText);
            }
            if (!empty($candidateText)) {
                logTranscript($sessionId, 'USER', $candidateText);
            }
            
        } elseif ($eventName === 'call_ended') {
            $db = getDB();
            $stmt = $db->prepare("UPDATE sessions SET current_status = 'COMPLETED', completed_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute(['id' => $sessionId]);
            logTranscript($sessionId, 'SYSTEM', "Call ended.");
        }
        
        echo json_encode([
            "status" => "success",
            "message" => "Webhook event processed: " . $eventName,
            "session_id" => $sessionId
        ]);
        exit;
    }
    
    throw new Exception("Invalid or unsupported action: " . $action);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}

