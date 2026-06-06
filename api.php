<?php
// api.php - Basic Router & API Controller
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST");

require_once __DIR__ . '/db.php';

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
            
            // Terminate TruGen conversation if there is an active session
            if (!empty($session['trugen_conversation_id'])) {
                terminateTruGenConversation($session['trugen_conversation_id']);
            }
            
            // Re-fetch updated session
            $session = getSession($sessionId);
        }
        
        // If final_score is not generated, generate it using Gemini
        if (empty($session['final_score'])) {
            $evaluation = generateGeminiEvaluation($sessionId);
            
            $stmt = $db->prepare("UPDATE sessions SET final_score = :final_score WHERE id = :id");
            $stmt->execute([
                'final_score' => json_encode($evaluation),
                'id' => $sessionId
            ]);
            
            $session['final_score'] = json_encode($evaluation);
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
        
        if (empty($name) || empty($email)) {
            $input = json_decode(file_get_contents('php://input'), true);
            $name = $input['name'] ?? '';
            $email = $input['email'] ?? '';
        }

        if (empty($name) || empty($email)) {
            throw new Exception("Candidate name and email are required");
        }
        
        $sessionId = createSession($name, $email);
        
        // Start session and set client cookie
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['session_id'] = $sessionId;
        setcookie("session_id", $sessionId, time() + 86400, "/");

        // Write system welcome message to transcripts log
        logTranscript($sessionId, 'SYSTEM', "Session started for candidate: $name");

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
        
        $uploadDir = __DIR__ . '/uploads/' . $sessionId;
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
            "filepath" => "uploads/" . $sessionId . "/latest.jpg"
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
            logTranscript($sessionId, 'AGENT', $text);
            
            $db = getDB();
            if (stripos($text, 'interview is complete') !== false || 
                stripos($text, 'generate your feedback report') !== false || 
                stripos($text, 'analyze your responses') !== false) {
                
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
            $db = getDB();
            $stmt = $db->prepare("UPDATE sessions SET current_status = 'IN_PROGRESS' WHERE id = :id");
            $stmt->execute(['id' => $sessionId]);
            
        } elseif ($eventName === 'utterance_committed') {
            $candidateText = $eventPayload['text'] ?? '';
            if (!empty($candidateText)) {
                logTranscript($sessionId, 'USER', $candidateText);
                
                $db = getDB();
                
                $status = $session['current_status'];
                $pref = $session['mcq_preference'];
                
                require_once __DIR__ . '/ai_service.php';
                
                if ($status === 'MCQ_PROMPTING') {
                    $payload = [
                        "contents" => [
                            [
                                "parts" => [
                                    [
                                        "text" => "Given the user response: '" . $candidateText . "', classify the user preference into one of these two options: READ_ALOUD, SELF_READ. Return only the preference string."
                                    ]
                                ]
                            ]
                        ]
                    ];
                    
                    try {
                        $classification = strtoupper(trim(callGemini($payload)));
                    } catch (Exception $e) {
                        $classification = 'SELF_READ';
                    }
                    
                    if (strpos($classification, 'READ_ALOUD') !== false) {
                        $stmt = $db->prepare("UPDATE sessions SET mcq_preference = 'READ', current_status = 'MCQ_ACTIVE' WHERE id = :id");
                        $stmt->execute(['id' => $sessionId]);
                        
                        $question = getNextUnansweredQuestion($sessionId);
                        if ($question) {
                            $spokenText = "The question is: " . $question['question'] . 
                                          ". Option A: " . $question['option_a'] . 
                                          ". Option B: " . $question['option_b'] . 
                                          ". Option C: " . $question['option_c'] . 
                                          ". Option D: " . $question['option_d'] . 
                                          ". Which one do you think is correct?";
                            logTranscript($sessionId, 'AGENT', $spokenText);
                            injectSpeakText($convId, cleanSpeechText($spokenText));
                        }
                    } else {
                        $stmt = $db->prepare("UPDATE sessions SET mcq_preference = 'SILENT', current_status = 'MCQ_ACTIVE' WHERE id = :id");
                        $stmt->execute(['id' => $sessionId]);
                        
                        logTranscript($sessionId, 'SYSTEM', "Candidate preferred silent reading. Awaiting option selection.");
                    }
                } elseif ($status === 'MCQ_ACTIVE') {
                    $question = getNextUnansweredQuestion($sessionId);
                    if ($question) {
                        $payload = [
                            "contents" => [
                                [
                                    "parts" => [
                                        [
                                            "text" => "Given the user response: '" . $candidateText . "' and the current question: '" . $question['question'] . "' with options A: '" . $question['option_a'] . "', B: '" . $question['option_b'] . "', C: '" . $question['option_c'] . "', D: '" . $question['option_d'] . "'. Classify the user response into one of these options: A, B, C, D, or NONE if they did not select an option. Return only the option letter (A, B, C, or D) or NONE."
                                        ]
                                    ]
                                ]
                            ]
                        ];
                        
                        try {
                            $classification = strtoupper(trim(callGemini($payload)));
                        } catch (Exception $e) {
                            $classification = 'NONE';
                        }
                        
                        if (in_array($classification, ['A', 'B', 'C', 'D'])) {
                            $isCorrect = ($classification === strtoupper(trim($question['correct_option'])));
                            saveCandidateResponse($sessionId, $question['id'], $classification, $isCorrect);
                            
                            $nextQuestion = getNextUnansweredQuestion($sessionId);
                            
                            $feedback = $isCorrect ? "That is correct!" : "That is incorrect. The correct answer was Option " . $question['correct_option'] . ".";
                            
                            if ($nextQuestion) {
                                $nextPrompt = "";
                                if ($pref === 'READ') {
                                    $nextPrompt = " Let's move to the next question. The question is: " . $nextQuestion['question'] . 
                                                  ". Option A: " . $nextQuestion['option_a'] . 
                                                  ". Option B: " . $nextQuestion['option_b'] . 
                                                  ". Option C: " . $nextQuestion['option_c'] . 
                                                  ". Option D: " . $nextQuestion['option_d'] . 
                                                  ". Which one do you think is correct?";
                                } else {
                                    $nextPrompt = " Let's move to the next question. Please read it on your screen and select your answer.";
                                }
                                
                                $spokenText = $feedback . $nextPrompt;
                                logTranscript($sessionId, 'AGENT', $spokenText);
                                injectSpeakText($convId, cleanSpeechText($spokenText));
                            } else {
                                $stmt = $db->prepare("UPDATE sessions SET current_status = 'COMPLETED', completed_at = CURRENT_TIMESTAMP WHERE id = :id");
                                $stmt->execute(['id' => $sessionId]);
                                
                                $spokenText = $feedback . " We have completed the MCQ assessment. Thank you.";
                                logTranscript($sessionId, 'AGENT', $spokenText);
                                logTranscript($sessionId, 'SYSTEM', "MCQ assessment completed.");
                                injectSpeakText($convId, cleanSpeechText($spokenText));
                            }
                        } else {
                            $stmt = $db->prepare("UPDATE sessions SET current_status = 'CANDIDATE_RESPONDED' WHERE id = :id");
                            $stmt->execute(['id' => $sessionId]);
                            
                            $transcripts = getTranscripts($sessionId);
                            $contextStr = "";
                            foreach ($transcripts as $t) {
                                $contextStr .= $t['speaker'] . ": " . $t['message'] . "\n";
                            }
                            
                            $imagePath = __DIR__ . '/uploads/' . $sessionId . '/latest.jpg';
                            $agentResponse = "";
                            if (file_exists($imagePath) && is_readable($imagePath)) {
                                try {
                                    $agentResponse = queryGeminiVision($imagePath, $candidateText, $contextStr);
                                } catch (Exception $visionEx) {
                                    $agentResponse = queryGeminiChat($transcripts);
                                }
                            } else {
                                $agentResponse = queryGeminiChat($transcripts);
                            }
                            
                            $cleanResponse = cleanSpeechText($agentResponse);
                            logTranscript($sessionId, 'AGENT', $cleanResponse);
                            injectSpeakText($convId, $cleanResponse);
                        }
                    } else {
                        $transcripts = getTranscripts($sessionId);
                        $agentResponse = queryGeminiChat($transcripts);
                        $cleanResponse = cleanSpeechText($agentResponse);
                        logTranscript($sessionId, 'AGENT', $cleanResponse);
                        injectSpeakText($convId, $cleanResponse);
                    }
                } else {
                    $stmt = $db->prepare("UPDATE sessions SET current_status = 'CANDIDATE_RESPONDED' WHERE id = :id");
                    $stmt->execute(['id' => $sessionId]);
                    
                    $transcripts = getTranscripts($sessionId);
                    $contextStr = "";
                    foreach ($transcripts as $t) {
                        $contextStr .= $t['speaker'] . ": " . $t['message'] . "\n";
                    }
                    
                    $imagePath = __DIR__ . '/uploads/' . $sessionId . '/latest.jpg';
                    $agentResponse = "";
                    if (file_exists($imagePath) && is_readable($imagePath)) {
                        try {
                            $agentResponse = queryGeminiVision($imagePath, $candidateText, $contextStr);
                        } catch (Exception $visionEx) {
                            $agentResponse = queryGeminiChat($transcripts);
                        }
                    } else {
                        $agentResponse = queryGeminiChat($transcripts);
                    }
                    
                    $cleanResponse = cleanSpeechText($agentResponse);
                    logTranscript($sessionId, 'AGENT', $cleanResponse);
                    injectSpeakText($convId, $cleanResponse);
                }
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

function injectSpeakText($conversationId, $text) {
    if ($conversationId === 'mock_id') {
        return true;
    }
    
    $apiKey = getenv('TRUGEN_API_KEY');
    if (!$apiKey) {
        $apiKey = $_ENV['TRUGEN_API_KEY'] ?? '';
    }
    
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

function terminateTruGenConversation($conversationId) {
    if ($conversationId === 'mock_id') {
        return true;
    }
    
    $apiKey = getenv('TRUGEN_API_KEY');
    if (!$apiKey) {
        $apiKey = $_ENV['TRUGEN_API_KEY'] ?? '';
    }
    
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

function analyzeScreenshotForEvaluation($imagePath) {
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
    
    return callGemini($payload, 'gemini-3.5-flash');
}

function generateGeminiEvaluation($sessionId) {
    require_once __DIR__ . '/ai_service.php';
    $session = getSession($sessionId);
    if (!$session) {
        throw new Exception("Session not found");
    }
    
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
    $imagePath = __DIR__ . '/uploads/' . $sessionId . '/latest.jpg';
    $visionNotes = "No screen capture shared.";
    if (file_exists($imagePath) && is_readable($imagePath)) {
        try {
            $visionNotes = analyzeScreenshotForEvaluation($imagePath);
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
    
    $jsonResponse = callGemini($payload, 'gemini-3.5-flash');
    
    $decoded = json_decode($jsonResponse, true);
    if (!$decoded) {
        throw new Exception("Failed to generate a valid JSON evaluation from Gemini.");
    }
    
    return $decoded;
}
