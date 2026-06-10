<?php
// chat_completions.php - Custom BYO LLM brain completions endpoint
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai_service.php';
require_once __DIR__ . '/trugen_service.php';
require_once __DIR__ . '/conduct_service.php';

function mapMessagesForGemini($messages) {
    $mapped = [];
    foreach ($messages as $msg) {
        $role = strtolower($msg['role'] ?? '');
        $content = $msg['content'] ?? '';
        
        if ($role === 'user') {
            $mapped[] = [
                'speaker' => 'USER',
                'message' => $content
            ];
        } elseif ($role === 'assistant' || $role === 'model') {
            $mapped[] = [
                'speaker' => 'AGENT',
                'message' => $content
            ];
        }
    }
    return $mapped;
}

function streamOpenAIResponse($text, $model = 'gemini-3.1-flash-lite') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    
    // Ensure all output buffers are flushed
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);
    
    $id = "chatcmpl-" . uniqid();
    $created = time();
    
    // Split text into words/spaces to simulate natural streaming chunks
    $words = preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    
    foreach ($words as $word) {
        if ($word === '') continue;
        
        $chunk = [
            "id" => $id,
            "object" => "chat.completion.chunk",
            "created" => $created,
            "model" => $model,
            "choices" => [
                [
                    "index" => 0,
                    "delta" => [
                        "content" => $word
                    ],
                    "finish_reason" => null
                ]
            ]
        ];
        
        echo "data: " . json_encode($chunk) . "\n\n";
        flush();
        usleep(5000); // 5ms delay to simulate streaming
    }
    
    // Send final stop chunk
    $finalChunk = [
        "id" => $id,
        "object" => "chat.completion.chunk",
        "created" => $created,
        "model" => $model,
        "choices" => [
            [
                "index" => 0,
                "delta" => new stdClass(),
                "finish_reason" => "stop"
            ]
        ]
    ];
    echo "data: " . json_encode($finalChunk) . "\n\n";
    echo "data: [DONE]\n\n";
    flush();
}

try {
    $inputJSON = file_get_contents('php://input');
    
    // Log request details for debug audit trail
    $debugLogPath = __DIR__ . '/uploads/debug_completions.log';
    if (!file_exists(dirname($debugLogPath))) {
        mkdir(dirname($debugLogPath), 0755, true);
    }
    file_put_contents($debugLogPath, "[" . date('Y-m-d H:i:s') . "] " . $inputJSON . "\n", FILE_APPEND);

    $input = json_decode($inputJSON, true);
    if (!$input) {
        throw new Exception("Invalid JSON request body");
    }

    $messages = $input['messages'] ?? [];
    $userEmail = $input['user'] ?? '';
    
    // 1. Locate current session
    $db = getDB();
    $session = null;
    if (!empty($userEmail)) {
        $stmt = $db->prepare("SELECT * FROM sessions WHERE email = :email AND current_status != 'COMPLETED' ORDER BY started_at DESC LIMIT 1");
        $stmt->execute(['email' => $userEmail]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$session) {
        // Fallback: use the most recent active session
        $stmt = $db->query("SELECT * FROM sessions WHERE current_status != 'COMPLETED' ORDER BY started_at DESC LIMIT 1");
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$session) {
        throw new Exception("No active session found.");
    }

    $sessionId = $session['id'];
    $status = $session['current_status'];
    $pref = $session['mcq_preference'];
    
    // Model task overrides
    $chatModel = $session['model_chat_task'] ?? 'gemini-3.1-flash-lite';
    $visionModel = $session['model_vision_task'] ?? 'gemini-3.1-flash-lite';
    
    // Recruiter custom API Key override
    $customApiKey = getSessionApiKey($session);
    
    // Extract last user utterance
    $candidateText = '';
    if (!empty($messages)) {
        $lastMsg = end($messages);
        if (($lastMsg['role'] ?? '') === 'user') {
            $candidateText = $lastMsg['content'] ?? '';
        }
    }

    $mappedMessages = mapMessagesForGemini($messages);
    $spokenText = "";

    // 2. Perform state machine flow logic identical to api.php but synchronous
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
            $classification = strtoupper(trim(callGemini($payload, $chatModel, $customApiKey)));
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
            } else {
                $spokenText = "We have completed the MCQ assessment. Thank you.";
            }
        } else {
            $stmt = $db->prepare("UPDATE sessions SET mcq_preference = 'SILENT', current_status = 'MCQ_ACTIVE' WHERE id = :id");
            $stmt->execute(['id' => $sessionId]);
            $spokenText = "Please read the question on your screen and select your answer.";
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
                $classification = strtoupper(trim(callGemini($payload, $chatModel, $customApiKey)));
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
                } else {
                    $stmt = $db->prepare("UPDATE sessions SET current_status = 'COMPLETED', completed_at = CURRENT_TIMESTAMP WHERE id = :id");
                    $stmt->execute(['id' => $sessionId]);
                    $spokenText = $feedback . " We have completed the MCQ assessment. Thank you.";
                }
            } else {
                // If they ask a general question during MCQ segment, fallback to normal response
                $spokenText = queryGeminiChatWithTools($mappedMessages, $customApiKey, $chatModel, $sessionId);
            }
        } else {
            $spokenText = queryGeminiChatWithTools($mappedMessages, $customApiKey, $chatModel, $sessionId);
        }
    } else {
        // Standard technical interview conversation mode
        $stmt = $db->prepare("UPDATE sessions SET current_status = 'CANDIDATE_RESPONDED' WHERE id = :id");
        $stmt->execute(['id' => $sessionId]);
        
        $contextStr = "";
        foreach ($mappedMessages as $msg) {
            $contextStr .= $msg['speaker'] . ": " . $msg['message'] . "\n";
        }
        
        $imagePath = __DIR__ . '/uploads/sessions/' . $sessionId . '/latest.jpg';
        if (file_exists($imagePath) && is_readable($imagePath)) {
            try {
                $spokenText = queryGeminiChatWithTools($mappedMessages, $customApiKey, $visionModel, $sessionId, $imagePath, $contextStr, $candidateText);
            } catch (Exception $visionEx) {
                $spokenText = queryGeminiChatWithTools($mappedMessages, $customApiKey, $chatModel, $sessionId);
            }
        } else {
            $spokenText = queryGeminiChatWithTools($mappedMessages, $customApiKey, $chatModel, $sessionId);
        }
    }

    $cleanResponse = cleanSpeechText($spokenText);

    $stream = $input['stream'] ?? false;
    if ($stream) {
        streamOpenAIResponse($cleanResponse, $input['model'] ?? $chatModel);
    } else {
        // Format OpenAI-compatible Chat Completions JSON output
        $response = [
            "id" => "chatcmpl-" . uniqid(),
            "object" => "chat.completion",
            "created" => time(),
            "model" => $chatModel,
            "choices" => [
                [
                    "index" => 0,
                    "message" => [
                        "role" => "assistant",
                        "content" => $cleanResponse
                    ],
                    "finish_reason" => "stop"
                ]
            ]
        ];
        echo json_encode($response);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        "error" => [
            "message" => $e->getMessage(),
            "type" => "invalid_request_error"
        ]
    ]);
}
