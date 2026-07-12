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
require_once __DIR__ . '/conduct_service.php';
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
        
        $qa = json_decode($session['q_a'] ?? '', true);
        $firstMCQIndex = null;
        if (is_array($qa)) {
            foreach ($qa as $idx => $q) {
                if (($q['type'] ?? '') === 'mcq') {
                    $firstMCQIndex = $idx;
                    break;
                }
            }
        }
        if ($firstMCQIndex === null) {
            throw new Exception("No MCQ questions configured for this session.");
        }
        
        $db = getDB();
        $stmt = $db->prepare("UPDATE sessions SET current_status = 'MCQ_PROMPTING', mcq_preference = 'PENDING', current_mcq_index = :mcq_index WHERE id = :id");
        $stmt->execute(['mcq_index' => $firstMCQIndex, 'id' => $sessionId]);
        
        logTranscript($sessionId, 'SYSTEM', "MCQ flow triggered.");
        $promptText = "I have loaded some multiple-choice questions on your screen. Would you like me to read them to you, or do you prefer reading them yourself?";
        logTranscript($sessionId, 'AGENT', $promptText);
        
        
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
        
        if (isset($session['current_mcq_index']) && $session['current_mcq_index'] !== null) {
            $mcqIndex = $session['current_mcq_index'];
            $qa = json_decode($session['q_a'] ?? '', true);
            if ($qa && isset($qa[$mcqIndex])) {
                $q = $qa[$mcqIndex];
                echo json_encode([
                    "status" => "success",
                    "has_active_mcq" => true,
                    "mcq_preference" => $session['mcq_preference'],
                    "question" => [
                        "id" => $mcqIndex,
                        "topic" => $q['topic'] ?? $session['role_title_id'] ?? 'MCQ',
                        "question" => $q['question'],
                        "option_a" => $q['options']['A'] ?? '',
                        "option_b" => $q['options']['B'] ?? '',
                        "option_c" => $q['options']['C'] ?? '',
                        "option_d" => $q['options']['D'] ?? ''
                    ]
                ]);
                exit;
            }
        }
        
        $qa = json_decode($session['q_a'] ?? '', true);
        $openQuestions = [];
        if (is_array($qa)) {
            foreach ($qa as $q) {
                if (($q['type'] ?? '') === 'open') {
                    $openQuestions[] = [
                        "question" => $q['question']
                    ];
                }
            }
        }
        
        $currentOpenIndex = isset($session['current_open_question_index']) ? (int)$session['current_open_question_index'] : null;
        
        echo json_encode([
            "status" => "success",
            "has_active_mcq" => false,
            "current_open_question_index" => $currentOpenIndex,
            "open_questions" => $openQuestions
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
        
        if (empty($sessionId) || $questionId === '' || empty($selectedOption)) {
            throw new Exception("session_id, question_id, and option are required");
        }
        
        $session = getSession($sessionId);
        if (!$session) {
            throw new Exception("Session not found");
        }
        
        $qa = json_decode($session['q_a'] ?? '', true);
        $question = $qa[$questionId] ?? null;
        if (!$question) {
            throw new Exception("Question not found");
        }
        
        $isCorrect = (strtoupper(trim($selectedOption)) === strtoupper(trim($question['answer'])));
        saveCandidateResponse($sessionId, $questionId, $selectedOption, $isCorrect);
        
        logTranscript($sessionId, 'USER', "Selected Option " . $selectedOption);
        
        // Find the next MCQ in the q_a array
        $nextMCQIndex = null;
        if (is_array($qa)) {
            for ($i = $questionId + 1; $i < count($qa); $i++) {
                if (($qa[$i]['type'] ?? '') === 'mcq') {
                    $nextMCQIndex = $i;
                    break;
                }
            }
        }
        
        $feedback = $isCorrect ? "That is correct!" : "That is incorrect. The correct answer was Option " . $question['answer'] . ".";
        
        if ($nextMCQIndex !== null) {
            $db = getDB();
            $nextQuestion = $qa[$nextMCQIndex];
            $nextPrompt = "";
            if ($session['mcq_preference'] === 'READ') {
                $nextPrompt = " Let's move to the next question. The question is: " . $nextQuestion['question'] . 
                              ". Option A: " . ($nextQuestion['options']['A'] ?? '') . 
                              ". Option B: " . ($nextQuestion['options']['B'] ?? '') . 
                              ". Option C: " . ($nextQuestion['options']['C'] ?? '') . 
                              ". Option D: " . ($nextQuestion['options']['D'] ?? '') . 
                              ". Which one do you think is correct?";
                $stmt = $db->prepare("UPDATE sessions SET current_status = 'MCQ_ACTIVE', current_mcq_index = :next_index WHERE id = :id");
                $stmt->execute(['next_index' => $nextMCQIndex, 'id' => $sessionId]);
            } else {
                $nextPrompt = " Let's move to the next question. Please read it on your screen and select your answer.";
                $stmt = $db->prepare("UPDATE sessions SET current_status = 'MCQ_ACTIVE', current_mcq_index = :next_index WHERE id = :id");
                $stmt->execute(['next_index' => $nextMCQIndex, 'id' => $sessionId]);
            }
            
            $spokenText = $feedback . $nextPrompt;
            logTranscript($sessionId, 'AGENT', $spokenText);
        } else {
            $db = getDB();
            $stmt = $db->prepare("UPDATE sessions SET current_status = 'COMPLETED', completed_at = CURRENT_TIMESTAMP, current_mcq_index = NULL WHERE id = :id");
            $stmt->execute(['id' => $sessionId]);
            
            $spokenText = $feedback . " We have completed the MCQ assessment. Thank you.";
            logTranscript($sessionId, 'AGENT', $spokenText);
            logTranscript($sessionId, 'SYSTEM', "MCQ assessment completed.");
        }
        
        echo json_encode([
            "status" => "success",
            "message" => "Option submitted successfully",
            "is_correct" => $isCorrect,
            "has_more" => !empty($nextQuestion),
            "speak_text" => cleanSpeechText($spokenText)
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


        
        // Clear local conversation ID setting if present
        if (!empty($session['trugen_conversation_id'])) {
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
        
        // If final_score is not generated, generate it using AI
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
                $evaluation = generateEvaluation($sessionId);
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
            $templateId = null;
            $sessionType = 'assessment';

            // Increment attempts
            incrementLinkAttempts($linkId);
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

        $sessionId = createSession($name, $email, $userId, $linkId, $templateId, $sessionType, $profileId, $qaJson, $targetLevel);
        
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

    if ($action === 'greet_candidate') {
        $sessionId = $_GET['session_id'] ?? $_COOKIE['session_id'] ?? '';
        $convId = $_GET['conversation_id'] ?? '';
        
        if (empty($sessionId) || empty($convId)) {
            throw new Exception("Session ID and Conversation ID are required");
        }
        
        $session = getSession($sessionId);
        if (!$session) {
            throw new Exception("Session not found");
        }
        
        $db = getDB();
        // 1. Update session to associate conversation ID and set status
        $stmt = $db->prepare("UPDATE sessions SET trugen_conversation_id = :convId, current_status = 'IN_PROGRESS' WHERE id = :id");
        $stmt->execute(['convId' => $convId, 'id' => $sessionId]);
        
        // 2. Determine topic X (job role)
        $jobRole = 'Software Engineer';
        if (!empty($session['interview_link_id'])) {
            $link = getInterviewLink($session['interview_link_id']);
            if ($link) {
                $jobRole = $link['job_role'] ?: 'Software Engineer';
            }
        } elseif (!empty($session['profile_id'])) {
            $profile = getCandidateProfile($session['profile_id'], $session['user_id']);
            if ($profile) {
                $resumeData = !empty($profile['resume_data']) ? json_decode($profile['resume_data'], true) : [];
                if (!empty($resumeData['detected_role'])) {
                    $jobRole = $resumeData['detected_role'];
                } elseif (!empty($profile['role_title'])) {
                    $jobRole = $profile['role_title'];
                }
            }
        }
        
        // 3. Construct standard greeting message
        $candidateName = $session['candidate_name'] ?? 'Candidate';
        $greetingText = "Hello " . $candidateName . "! I am Alex, your AI interviewer. Are you ready to start the interview on the topic " . $jobRole . "?";
        
        // 4. Check if we have already logged a greeting in transcripts (to avoid double greetings on page reload or re-connection)
        $stmt = $db->prepare("SELECT COUNT(*) FROM transcripts WHERE session_id = :session_id AND speaker = 'AGENT'");
        $stmt->execute(['session_id' => $sessionId]);
        $agentMsgCount = (int)$stmt->fetchColumn();
        
        if ($agentMsgCount === 0) {
            // Associate conversation ID with session immediately
            updateSessionConversation($sessionId, $convId);
            
            // Write agent greeting to transcripts log
            logTranscript($sessionId, 'AGENT', $greetingText);
            
        }
        
        echo json_encode([
            "status" => "success",
            "message" => "Greeting triggered",
            "greeting" => $greetingText
        ]);
        exit;
    }

    if ($action === 'conduct') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception("Method not allowed. Use POST.");
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $sessionId = $_GET['session_id'] ?? $_COOKIE['session_id'] ?? $input['session_id'] ?? '';
        $userText = $input['user_message'] ?? '';
        
        if (empty($sessionId)) {
            throw new Exception("Session ID required");
        }
        
        $session = getSession($sessionId);
        if (!$session) {
            throw new Exception("Session not found");
        }
        
        $status = $session['current_status'];
        $pref = $session['mcq_preference'];
        
        // 1. Log the candidate's utterance to transcripts
        if (!empty($userText)) {
            logTranscript($sessionId, 'USER', $userText);
        }
        
        // 2. Fetch the updated list of transcripts to build the conversation history context
        $transcripts = getTranscripts($sessionId);
        $mappedMessages = [];
        $contextStr = "";
        foreach ($transcripts as $t) {
            if ($t['speaker'] === 'USER') {
                $mappedMessages[] = ['speaker' => 'USER', 'message' => $t['message']];
                $contextStr .= "USER: " . $t['message'] . "\n";
            } elseif ($t['speaker'] === 'AGENT') {
                $mappedMessages[] = ['speaker' => 'AGENT', 'message' => $t['message']];
                $contextStr .= "AGENT: " . $t['message'] . "\n";
            }
        }
        
        $spokenText = "";
        $db = getDB();
        
        // 3. Run the conversation state machine
        if ($status === 'MCQ_PROMPTING') {
            $prompt = "Given the user response: '" . $userText . "', classify the user preference into one of these two options: READ_ALOUD, SELF_READ. Return only the preference string.";
            try {
                $classification = strtoupper(trim(callAI([
                    ["role" => "user", "content" => $prompt]
                ], 'intent_classification')));
            } catch (Exception $e) {
                $classification = 'SELF_READ';
            }
            
            if (strpos($classification, 'READ_ALOUD') !== false) {
                $stmt = $db->prepare("UPDATE sessions SET mcq_preference = 'READ', current_status = 'MCQ_ACTIVE' WHERE id = :id");
                $stmt->execute(['id' => $sessionId]);
                
                $mcqIndex = $session['current_mcq_index'] ?? null;
                $qa = json_decode($session['q_a'] ?? '', true);
                $question = ($qa && $mcqIndex !== null) ? ($qa[$mcqIndex] ?? null) : null;
                
                if ($question) {
                    $spokenText = "The question is: " . $question['question'] . 
                                  ". Option A: " . ($question['options']['A'] ?? '') . 
                                  ". Option B: " . ($question['options']['B'] ?? '') . 
                                  ". Option C: " . ($question['options']['C'] ?? '') . 
                                  ". Option D: " . ($question['options']['D'] ?? '') . 
                                  ". Which one do you think is correct?";
                } else {
                    $spokenText = "We have completed the MCQ assessment. Thank you.";
                }
            } else {
                $stmt = $db->prepare("UPDATE sessions SET mcq_preference = 'SILENT', current_status = 'MCQ_ACTIVE' WHERE id = :id");
                $stmt->execute(['id' => $sessionId]);
                $spokenText = "Please read the question on your screen and select your answer.";
            }
        } elseif ($status === 'MCQ_ACTIVE') {
            $mcqIndex = $session['current_mcq_index'] ?? null;
            $qa = json_decode($session['q_a'] ?? '', true);
            $question = ($qa && $mcqIndex !== null) ? ($qa[$mcqIndex] ?? null) : null;
            
            if ($question) {
                $prompt = "Given the user response: '" . $userText . "' and the current question: '" . $question['question'] . "' with options A: '" . ($question['options']['A'] ?? '') . "', B: '" . ($question['options']['B'] ?? '') . "', C: '" . ($question['options']['C'] ?? '') . "', D: '" . ($question['options']['D'] ?? '') . "'. Classify the user response into one of these options: A, B, C, D, or NONE if they did not select an option. Return only the option letter (A, B, C, or D) or NONE.";
                try {
                    $classification = strtoupper(trim(callAI([
                        ["role" => "user", "content" => $prompt]
                    ], 'intent_classification')));
                } catch (Exception $e) {
                    $classification = 'NONE';
                }
                
                if (in_array($classification, ['A', 'B', 'C', 'D'])) {
                    $isCorrect = ($classification === strtoupper(trim($question['answer'])));
                    saveCandidateResponse($sessionId, $mcqIndex, $classification, $isCorrect);
                    
                    // Log selection to transcripts
                    logTranscript($sessionId, 'USER', "Selected Option " . $classification);
                    
                    // Find next MCQ
                    $nextMCQIndex = null;
                    if (is_array($qa)) {
                        for ($i = $mcqIndex + 1; $i < count($qa); $i++) {
                            if (($qa[$i]['type'] ?? '') === 'mcq') {
                                $nextMCQIndex = $i;
                                break;
                            }
                        }
                    }
                    
                    $feedback = $isCorrect ? "That is correct!" : "That is incorrect. The correct answer was Option " . $question['answer'] . ".";
                    
                    if ($nextMCQIndex !== null) {
                        $nextQuestion = $qa[$nextMCQIndex];
                        $nextPrompt = "";
                        if ($pref === 'READ') {
                            $nextPrompt = " Let's move to the next question. The question is: " . $nextQuestion['question'] . 
                                          ". Option A: " . ($nextQuestion['options']['A'] ?? '') . 
                                          ". Option B: " . ($nextQuestion['options']['B'] ?? '') . 
                                          ". Option C: " . ($nextQuestion['options']['C'] ?? '') . 
                                          ". Option D: " . ($nextQuestion['options']['D'] ?? '') . 
                                          ". Which one do you think is correct?";
                            $stmt = $db->prepare("UPDATE sessions SET current_status = 'MCQ_ACTIVE', current_mcq_index = :next_index WHERE id = :id");
                            $stmt->execute(['next_index' => $nextMCQIndex, 'id' => $sessionId]);
                        } else {
                            $nextPrompt = " Let's move to the next question. Please read it on your screen and select your answer.";
                            $stmt = $db->prepare("UPDATE sessions SET current_status = 'MCQ_ACTIVE', current_mcq_index = :next_index WHERE id = :id");
                            $stmt->execute(['next_index' => $nextMCQIndex, 'id' => $sessionId]);
                        }
                        $spokenText = $feedback . $nextPrompt;
                    } else {
                        $stmt = $db->prepare("UPDATE sessions SET current_status = 'COMPLETED', completed_at = CURRENT_TIMESTAMP, current_mcq_index = NULL WHERE id = :id");
                        $stmt->execute(['id' => $sessionId]);
                        $spokenText = $feedback . " We have completed the MCQ assessment. Thank you.";
                    }
                } else {
                    // General query during MCQ, fallback
                    $spokenText = queryChatWithTools($mappedMessages, $sessionId);
                }
            } else {
                $spokenText = queryChatWithTools($mappedMessages, $sessionId);
            }
        } else {
            // Standard technical interview conversation mode
            $stmt = $db->prepare("UPDATE sessions SET current_status = 'CANDIDATE_RESPONDED' WHERE id = :id");
            $stmt->execute(['id' => $sessionId]);
            
            $imagePath = __DIR__ . '/uploads/sessions/' . $sessionId . '/latest.jpg';
            if (file_exists($imagePath) && is_readable($imagePath)) {
                try {
                    $spokenText = queryChatWithTools($mappedMessages, $sessionId, $imagePath, $contextStr, $userText);
                } catch (Exception $visionEx) {
                    $spokenText = queryChatWithTools($mappedMessages, $sessionId);
                }
            } else {
                $spokenText = queryChatWithTools($mappedMessages, $sessionId);
            }
            
            // Set status to IN_PROGRESS or COMPLETED depending on what AI did
            $session = getSession($sessionId);
            if ($session['current_status'] !== 'COMPLETED') {
                $stmt = $db->prepare("UPDATE sessions SET current_status = 'IN_PROGRESS' WHERE id = :id");
                $stmt->execute(['id' => $sessionId]);
            }
        }
        
        $cleanResponse = cleanSpeechText($spokenText);
        
        // Log the agent's turn to transcripts
        logTranscript($sessionId, 'AGENT', $cleanResponse);
        
        echo json_encode([
            "status" => "success",
            "speak_text" => $cleanResponse
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

        // Analyze snapshot using the vision model (skip for browser-native deterministic telemetry)
        $aiVerdict = 'AI analysis skipped.';
        $aiConfirmed = true; // default to true if no snapshot is available for analysis
        
        $browserAlerts = ['tab_switch', 'fullscreen_exit', 'copy_paste_attempt', 'cursor_left_screen', 'device_change', 'screen_share_stopped'];
        
        if ($snapshotPath) {
            if (in_array($alertType, $browserAlerts)) {
                $aiVerdict = 'Browser-native telemetry logged.';
                $aiConfirmed = true;
            } else {
                $analysis = analyzeProctorSnapshot(__DIR__ . '/' . $snapshotPath, $alertType, $clientDetails);
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
        $speakTextMsg = null;
        if ($shouldSpeak) {
            $speakText = buildProctorWarningMessage($alertType, $aiVerdict);
            $warningSpoken = true;
            $speakTextMsg = $speakText;
            logTranscript($sessionId, 'SYSTEM', "Spoke warning to candidate: " . $speakText);
        }
        
        echo json_encode([
            "status" => "success",
            "alert_id" => $alertId,
            "ai_verdict" => $aiVerdict,
            "ai_confirmed" => $aiConfirmed,
            "warning_spoken" => $warningSpoken,
            "speak_text" => $speakTextMsg
        ]);
        exit;
    }
    
    if ($action === 'transcribe') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception("Method not allowed. Use POST.");
        }
        if (!isset($_FILES['audio'])) {
            throw new Exception("No audio file uploaded");
        }
        
        $file = $_FILES['audio'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Upload error: " . $file['error']);
        }
        
        // Ensure temp folder exists in workspace (inside uploads which is writable)
        $tempDir = __DIR__ . '/uploads/temp';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (empty($extension)) {
            $extension = 'webm';
        }
        $tempPath = $tempDir . '/transcription_' . uniqid() . '.' . $extension;
        
        if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
            throw new Exception("Failed to save uploaded audio file");
        }
        
        try {
            $mimeType = $file['type'] ?: 'audio/webm';
            $transcriptionText = transcribeAudio($tempPath, $mimeType);
            
            echo json_encode([
                "status" => "success",
                "text" => $transcriptionText
            ]);
        } catch (Exception $e) {
            file_put_contents(__DIR__ . '/uploads/debug_transcribe.log', date('[Y-m-d H:i:s] ') . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n", FILE_APPEND);
            throw $e;
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
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

    // Webhook action removed. TruGen integrations are disabled.
    
    throw new Exception("Invalid or unsupported action: " . $action);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}

