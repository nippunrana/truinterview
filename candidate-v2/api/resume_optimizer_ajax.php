<?php
// candidate-v2/api/resume_optimizer_ajax.php - Resume Optimizer V2 AJAX handler
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../optimizer_service.php';

requireAuth(['candidate']);
$user = getCurrentUser();
$db = getDB();

$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $user['id']]);
$userFull = $stmt->fetch(PDO::FETCH_ASSOC);

if (isset($_GET['ajax_action']) || isset($_POST['ajax_action'])) {
    $action = $_GET['ajax_action'] ?? $_POST['ajax_action'] ?? '';
    header('Content-Type: application/json');

    $model = $userFull['model_optimizer_task'] ?? 'gemini-3.5-flash';
    $apiKey = $userFull['custom_gemini_api_key'] ?? null;

    try {
        if ($action === 'optimizer_init') {
            $resumePath = $_POST['resume_path'] ?? '';
            $profileId = $_POST['profile_id'] ?? null;
            if (empty($resumePath)) {
                echo json_encode(['success' => false, 'message' => 'Resume path is required.']);
                exit;
            }
            
            $text = null;
            
            if (!empty($profileId)) {
                $stmt = $db->prepare("SELECT text_version FROM candidate_profiles WHERE id = :id AND user_id = :uid");
                $stmt->execute(['id' => $profileId, 'uid' => $user['id']]);
                $cachedText = $stmt->fetchColumn();
                if (!empty($cachedText)) {
                    $text = $cachedText;
                }
            } else {
                $resumes = getCandidateResumes($userFull['resume_path'] ?? '');
                foreach ($resumes as $r) {
                    if ($r['path'] === $resumePath && !empty($r['text_version'])) {
                        $text = $r['text_version'];
                        break;
                    }
                }
            }

            if (empty($text)) {
                $ext = strtolower(pathinfo($resumePath, PATHINFO_EXTENSION));
                $fullPath = __DIR__ . '/../../' . $resumePath;
                $text = optimizer_extract_text($fullPath, $ext, $model, $apiKey);
            }
            
            echo json_encode(['success' => true, 'resume_text' => $text]);
            exit;
        }

        if ($action === 'optimizer_reality_check') {
            $resumeText = $_POST['resume_text'] ?? '';
            if (empty($resumeText)) {
                echo json_encode(['success' => false, 'message' => 'Resume text content is empty.']);
                exit;
            }
            $result = optimizer_reality_check($resumeText, $model, $apiKey);
            echo json_encode(['success' => true, 'data' => $result]);
            exit;
        }

        if ($action === 'optimizer_gap_analysis') {
            $resumeText = $_POST['resume_text'] ?? '';
            $targetRole = $_POST['target_role'] ?? '';
            $jobDescription = $_POST['job_description'] ?? '';

            $result = optimizer_gap_analysis($resumeText, $targetRole, $jobDescription, $model, $apiKey);
            echo json_encode(['success' => true, 'data' => $result]);
            exit;
        }

        if ($action === 'optimizer_verify_dates') {
            $resumeText = $_POST['resume_text'] ?? '';
            
            // Extract raw dates
            $rawExp = optimizer_extract_dates($resumeText, $model, $apiKey);
            // Verify mathematically
            $result = optimizer_verify_dates_math($rawExp);
            
            echo json_encode(['success' => true, 'data' => $result]);
            exit;
        }

        if ($action === 'optimizer_deep_analysis') {
            $resumeText = $_POST['resume_text'] ?? '';
            $targetRole = $_POST['target_role'] ?? '';
            $jobDescription = $_POST['job_description'] ?? '';
            $gapAnswers = json_decode($_POST['gap_answers'] ?? '[]', true);
            $verifiedDates = json_decode($_POST['verified_dates'] ?? '{}', true);

            $result = optimizer_generate_rewrite($resumeText, $targetRole, $jobDescription, $gapAnswers, $verifiedDates, $model, $apiKey);
            echo json_encode(['success' => true, 'data' => $result]);
            exit;
        }

        if ($action === 'optimizer_save_profile') {
            $optimizedMarkdown = $_POST['optimized_markdown'] ?? '';
            $profileId = $_POST['profile_id'] ?? null;
            $changesRaw = $_POST['changes'] ?? '';
            $changes = !empty($changesRaw) ? json_decode($changesRaw, true) : null;

            if (empty($optimizedMarkdown)) {
                echo json_encode(['success' => false, 'message' => 'Optimized markdown content is required.']);
                exit;
            }

            if (!empty($profileId)) {
                $result = optimizer_save_to_candidate_profile($profileId, $user['id'], $optimizedMarkdown, $changes);
            } else {
                $result = optimizer_save_to_profile($user['id'], $optimizedMarkdown, $changes);
            }
            echo json_encode(['success' => true, 'data' => $result]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Invalid action requested.']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}
