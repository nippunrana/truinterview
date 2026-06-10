<?php
// candidate-v2/ajax.php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ai_service.php';

// Enforce Candidate role
requireAuth(['candidate']);
$user = getCurrentUser();

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

if ($action === 'update_settings') {
    $apiKey = $_POST['custom_gemini_api_key'] ?? '';
    $modelChat = $_POST['model_chat_task'] ?? 'gemini-3.1-flash-lite';
    $modelVision = $_POST['model_vision_task'] ?? 'gemini-3.1-flash-lite';
    $modelEval = $_POST['model_eval_task'] ?? 'gemini-3.1-flash-lite';
    $modelOptimizer = $_POST['model_optimizer_task'] ?? 'gemini-3.5-flash';
    
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE users SET 
            custom_gemini_api_key = :api_key, 
            model_chat_task = :model_chat, 
            model_vision_task = :model_vision, 
            model_eval_task = :model_eval,
            model_optimizer_task = :model_optimizer
            WHERE id = :id");
        $stmt->execute([
            'api_key' => empty($apiKey) ? null : trim($apiKey),
            'model_chat' => $modelChat,
            'model_vision' => $modelVision,
            'model_eval' => $modelEval,
            'model_optimizer' => $modelOptimizer,
            'id' => $user['id']
        ]);
        echo json_encode(['success' => true, 'message' => 'Settings updated successfully.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'create_profile') {
    $roleTitle = trim($_POST['role_title'] ?? '');
    if (empty($roleTitle)) {
        echo json_encode(['success' => false, 'message' => 'Role title is required.']);
        exit;
    }

    $id = createCandidateProfile($user['id'], $roleTitle);
    if ($id) {
        echo json_encode(['success' => true, 'profile_id' => $id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Maximum of 3 profiles allowed.']);
    }
    exit;
}

if ($action === 'delete_profile') {
    $profileId = $_POST['profile_id'] ?? '';
    if (empty($profileId)) {
        echo json_encode(['success' => false, 'message' => 'Profile ID is required.']);
        exit;
    }

    $profile = getCandidateProfile($profileId, $user['id']);
    if ($profile) {
        // Delete resume file if exists
        if (!empty($profile['optimized_resume_path'])) {
            $fullPath = __DIR__ . '/../' . $profile['optimized_resume_path'];
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }
        deleteCandidateProfile($profileId, $user['id']);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Profile not found.']);
    }
    exit;
}

if ($action === 'upload_resume') {
    $profileId = $_POST['profile_id'] ?? '';
    if (empty($profileId)) {
        echo json_encode(['success' => false, 'message' => 'Profile ID is required.']);
        exit;
    }

    $profile = getCandidateProfile($profileId, $user['id']);
    if (!$profile) {
        echo json_encode(['success' => false, 'message' => 'Profile not found.']);
        exit;
    }

    if (!isset($_FILES['resume_file']) || $_FILES['resume_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'File upload error.']);
        exit;
    }

    $tmpName = $_FILES['resume_file']['tmp_name'];
    $fileName = $_FILES['resume_file']['name'];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $allowed = ['pdf', 'doc', 'docx', 'md'];

    if (!in_array($ext, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: PDF, DOC/DOCX, MD.']);
        exit;
    }

    $uploadDir = __DIR__ . '/../uploads/resumes/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $tempFileName = 'temp_v2_' . $user['id'] . '_' . $profileId . '_' . time() . '.' . $ext;
    $dest = $uploadDir . $tempFileName;

    if (move_uploaded_file($tmpName, $dest)) {
        // Run AI Verification
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute(['id' => $user['id']]);
        $userFull = $stmt->fetch();
        
        $model = $userFull['model_chat_task'] ?? 'gemini-3.1-flash-lite';
        $apiKey = $userFull['custom_gemini_api_key'] ?? null;
        
        $verification = verifyUploadedResume($dest, $ext, $user['full_name'], $model, $apiKey);

        if (!$verification || isset($verification['error'])) {
            @unlink($dest);
            echo json_encode(['success' => false, 'error_type' => 'ai_error', 'message' => 'AI verification failed: ' . ($verification['error'] ?? 'Unknown')]);
            exit;
        }

        if (!$verification['is_valid_resume']) {
            @unlink($dest);
            echo json_encode(['success' => false, 'error_type' => 'invalid_resume', 'message' => 'It is not a valid resume, please upload the correct file.']);
            exit;
        }

        if (!$verification['is_name_match']) {
            echo json_encode([
                'success' => false,
                'error_type' => 'name_mismatch',
                'extracted_name' => $verification['extracted_name'] ?? 'Unknown Name',
                'temp_filename' => $tempFileName
            ]);
            exit;
        }

        $finalFileName = 'v2_' . $user['id'] . '_' . $profileId . '_' . time() . '.' . $ext;
        $finalDest = $uploadDir . $finalFileName;
        
        if (rename($dest, $finalDest)) {
            $resumePath = 'uploads/resumes/' . $finalFileName;
            
            // Remove old unoptimized/optimized file if it exists so we don't leak space
            if (!empty($profile['optimized_resume_path'])) {
                $oldPath = __DIR__ . '/../' . $profile['optimized_resume_path'];
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }

            updateCandidateProfileResume($profileId, $user['id'], $resumePath);
            echo json_encode(['success' => true, 'path' => $resumePath]);
        } else {
            @unlink($dest);
            echo json_encode(['success' => false, 'message' => 'Failed to finalize file storage.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save file.']);
    }
    exit;
}

if ($action === 'commit_resume') {
    $tempFileName = $_POST['temp_filename'] ?? '';
    $profileId = $_POST['profile_id'] ?? '';
    
    if (empty($tempFileName) || strpos($tempFileName, 'temp_v2_' . $user['id']) !== 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid file access request.']);
        exit;
    }

    $profile = getCandidateProfile($profileId, $user['id']);
    if (!$profile) {
        echo json_encode(['success' => false, 'message' => 'Profile not found.']);
        exit;
    }

    $uploadDir = __DIR__ . '/../uploads/resumes/';
    $tempPath = $uploadDir . $tempFileName;

    if (!file_exists($tempPath)) {
        echo json_encode(['success' => false, 'message' => 'Temporary file not found.']);
        exit;
    }

    $ext = pathinfo($tempFileName, PATHINFO_EXTENSION);
    $finalFileName = 'v2_' . $user['id'] . '_' . $profileId . '_' . time() . '.' . $ext;
    $finalDest = $uploadDir . $finalFileName;

    if (rename($tempPath, $finalDest)) {
        $resumePath = 'uploads/resumes/' . $finalFileName;
        
        if (!empty($profile['optimized_resume_path'])) {
            $oldPath = __DIR__ . '/../' . $profile['optimized_resume_path'];
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        updateCandidateProfileResume($profileId, $user['id'], $resumePath);
        echo json_encode(['success' => true, 'path' => $resumePath]);
    } else {
        @unlink($tempPath);
        echo json_encode(['success' => false, 'message' => 'Failed to save resume.']);
    }
    exit;
}

if ($action === 'cancel_resume') {
    $tempFileName = $_POST['temp_filename'] ?? '';
    if (!empty($tempFileName) && strpos($tempFileName, 'temp_v2_' . $user['id']) === 0) {
        $tempPath = __DIR__ . '/../uploads/resumes/' . $tempFileName;
        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }
    }
    echo json_encode(['success' => true, 'message' => 'Upload discarded.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
exit;
