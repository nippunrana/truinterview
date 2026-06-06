<?php
// api.php - Basic Router & API Controller
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST");

require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? '';

try {
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
    
    throw new Exception("Invalid or unsupported action: " . $action);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
