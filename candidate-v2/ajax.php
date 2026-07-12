<?php
// candidate-v2/ajax.php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ai_service.php';
require_once __DIR__ . '/../optimizer_service.php';

// Enforce Candidate role
requireAuth(['candidate']);
$user = getCurrentUser();

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

if ($action === 'create_profile') {
    $roleTitle = trim($_POST['role_title'] ?? '');
    if (empty($roleTitle)) {
        echo json_encode(['success' => false, 'message' => 'Role title is required.']);
        exit;
    }

    $categoryId = null;
    $matchPercentage = 0;

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT resume_path FROM users WHERE id = :id");
        $stmt->execute(['id' => $user['id']]);
        $userFull = $stmt->fetch();

        $categories = getAllCategories();
        $aiResult = matchRoleToCategory($roleTitle, $categories);
        
        if (!empty($aiResult['category_id']) && isset($aiResult['match_percentage'])) {
            if ($aiResult['match_percentage'] >= 15) {
                $categoryId = $aiResult['category_id'];
                $matchPercentage = $aiResult['match_percentage'];
            }
        }
    } catch (Exception $e) {
        // Fail silently and leave them empty if AI fails
    }

    $id = createCandidateProfile($user['id'], $roleTitle, $categoryId, $matchPercentage);
    if ($id) {
        // Link base resume if provided
        $baseResumePath = $_POST['base_resume_path'] ?? '';
        if (!empty($baseResumePath)) {
            $resumes = getCandidateResumes($userFull['resume_path'] ?? '');
            $baseRes = null;
            foreach ($resumes as $r) {
                if ($r['path'] === $baseResumePath) {
                    $baseRes = $r;
                    break;
                }
            }
            if ($baseRes) {
                updateCandidateProfileResume(
                    $id,
                    $user['id'],
                    $baseRes['path'],
                    $baseRes['text_version'] ?? null,
                    !empty($baseRes['needs_human_review']),
                    null,
                    $baseRes['detected_role'] ?? null
                );
            }
        }
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

if ($action === 'delete_session') {
    $sessionId = $_POST['session_id'] ?? '';
    if (empty($sessionId)) {
        echo json_encode(['success' => false, 'message' => 'Session ID is required.']);
        exit;
    }
    $deleted = deleteSession($sessionId, $user['id']);
    if ($deleted) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Session not found or access denied.']);
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
        $verification = verifyUploadedResume($dest, $ext, $user['full_name']);

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
                'temp_filename' => $tempFileName,
                'detected_role' => $verification['detected_role'] ?? 'Resume'
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

            $textVersion = optimizer_extract_text($finalDest, $ext);

            // Run QA check
            $qa = qa_assess_resume_extraction($finalDest, $ext, $textVersion);
            $needsHumanReview = false;
            if (!empty($qa['needs_fix'])) {
                $textVersion = fix_resume_extraction($finalDest, $ext, $textVersion, $qa['issues']);
                $needsHumanReview = true;
            }
            
            $detectedRole = $verification['detected_role'] ?? null;
            updateCandidateProfileResume($profileId, $user['id'], $resumePath, $textVersion, $needsHumanReview, null, $detectedRole);
            echo json_encode(['success' => true, 'path' => $resumePath, 'needs_human_review' => $needsHumanReview]);
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

        $textVersion = optimizer_extract_text($finalDest, $ext);

        // Run QA check
        $qa = qa_assess_resume_extraction($finalDest, $ext, $textVersion);
        $needsHumanReview = false;
        if (!empty($qa['needs_fix'])) {
            $textVersion = fix_resume_extraction($finalDest, $ext, $textVersion, $qa['issues']);
            $needsHumanReview = true;
        }
        
        $detectedRole = $_POST['detected_role'] ?? null;
        updateCandidateProfileResume($profileId, $user['id'], $resumePath, $textVersion, $needsHumanReview, null, $detectedRole);
        echo json_encode(['success' => true, 'path' => $resumePath, 'needs_human_review' => $needsHumanReview]);
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

if ($action === 'upload_global_resume') {
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

    $db = getDB();
    $stmt = $db->prepare("SELECT resume_path FROM users WHERE id = :id");
    $stmt->execute(['id' => $user['id']]);
    $userFull = $stmt->fetch();

    $resumes = getCandidateResumes($userFull['resume_path'] ?? '');
    if (count($resumes) >= 5) {
        echo json_encode(['success' => false, 'message' => 'Maximum limit of 5 resumes reached. Please delete an older resume before uploading a new one.']);
        exit;
    }

    $uploadDir = __DIR__ . '/../uploads/resumes/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $tempFileName = 'temp_global_' . $user['id'] . '_' . time() . '.' . $ext;
    $dest = $uploadDir . $tempFileName;

    if (move_uploaded_file($tmpName, $dest)) {
        // Run AI Verification
        $verification = verifyUploadedResume($dest, $ext, $user['full_name']);

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

        $finalFileName = 'global_' . $user['id'] . '_' . time() . '.' . $ext;
        $finalDest = $uploadDir . $finalFileName;
        
        if (rename($dest, $finalDest)) {
            $resumePath = 'uploads/resumes/' . $finalFileName;
            
            $textVersion = optimizer_extract_text($finalDest, $ext);

            // Run QA check
            $qa = qa_assess_resume_extraction($finalDest, $ext, $textVersion);
            $needsHumanReview = false;
            if (!empty($qa['needs_fix'])) {
                $textVersion = fix_resume_extraction($finalDest, $ext, $textVersion, $qa['issues']);
                $needsHumanReview = true;
            }

            foreach ($resumes as &$r) {
                $r['is_base'] = false;
            }

            $resumes[] = [
                'path' => $resumePath,
                'date' => time(),
                'text_version' => $textVersion,
                'short_description' => $verification['short_description'] ?? 'No description generated.',
                'detected_role' => $verification['detected_role'] ?? 'Resume',
                'is_base' => true,
                'needs_human_review' => $needsHumanReview
            ];

            $jsonVal = json_encode(array_values($resumes));
            $stmt = $db->prepare("UPDATE users SET resume_path = :path WHERE id = :id");
            $stmt->execute(['path' => $jsonVal, 'id' => $user['id']]);

            echo json_encode([
                'success' => true,
                'path' => $resumePath,
                'needs_human_review' => $needsHumanReview,
                'detected_role' => $verification['detected_role'] ?? 'Resume'
            ]);
        } else {
            @unlink($dest);
            echo json_encode(['success' => false, 'message' => 'Failed to finalize file storage.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save file.']);
    }
    exit;
}

if ($action === 'commit_global_resume') {
    $tempFileName = $_POST['temp_filename'] ?? '';
    if (empty($tempFileName) || strpos($tempFileName, 'temp_global_' . $user['id']) !== 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid file access request.']);
        exit;
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT resume_path FROM users WHERE id = :id");
    $stmt->execute(['id' => $user['id']]);
    $userFull = $stmt->fetch(PDO::FETCH_ASSOC);

    $resumes = getCandidateResumes($userFull['resume_path'] ?? '');
    if (count($resumes) >= 5) {
        echo json_encode(['success' => false, 'message' => 'Maximum limit of 5 resumes reached.']);
        exit;
    }

    $uploadDir = __DIR__ . '/../uploads/resumes/';
    $tempPath = $uploadDir . $tempFileName;

    if (!file_exists($tempPath)) {
        echo json_encode(['success' => false, 'message' => 'Temporary file not found.']);
        exit;
    }

    $ext = pathinfo($tempFileName, PATHINFO_EXTENSION);
    $finalFileName = 'global_' . $user['id'] . '_' . time() . '.' . $ext;
    $finalDest = $uploadDir . $finalFileName;

    if (rename($tempPath, $finalDest)) {
        $resumePath = 'uploads/resumes/' . $finalFileName;
        
        $textVersion = '';
        $shortDescription = 'Bypassed name mismatch verification.';

        $verification = verifyUploadedResume($finalDest, $ext, $user['full_name']);
        if ($verification && !isset($verification['error'])) {
            $textVersion = $verification['text_version'] ?? '';
            $shortDescription = $verification['short_description'] ?? 'Bypassed name mismatch verification.';
        }

        $textVersion = optimizer_extract_text($finalDest, $ext);

        // Run QA check
        $qa = qa_assess_resume_extraction($finalDest, $ext, $textVersion);
        $needsHumanReview = false;
        if (!empty($qa['needs_fix'])) {
            $textVersion = fix_resume_extraction($finalDest, $ext, $textVersion, $qa['issues']);
            $needsHumanReview = true;
        }

        foreach ($resumes as &$r) {
            $r['is_base'] = false;
        }

        $resumes[] = [
            'path' => $resumePath,
            'date' => time(),
            'text_version' => $textVersion,
            'short_description' => $shortDescription,
            'detected_role' => $verification['detected_role'] ?? 'Resume',
            'is_base' => true,
            'needs_human_review' => $needsHumanReview
        ];

        $jsonVal = json_encode(array_values($resumes));
        $stmt = $db->prepare("UPDATE users SET resume_path = :path WHERE id = :id");
        $stmt->execute(['path' => $jsonVal, 'id' => $user['id']]);

        echo json_encode([
            'success' => true,
            'path' => $resumePath,
            'needs_human_review' => $needsHumanReview,
            'detected_role' => $verification['detected_role'] ?? 'Resume'
        ]);
    } else {
        @unlink($tempPath);
        echo json_encode(['success' => false, 'message' => 'Failed to save resume.']);
    }
    exit;
}

if ($action === 'cancel_global_resume') {
    $tempFileName = $_POST['temp_filename'] ?? '';
    if (!empty($tempFileName) && strpos($tempFileName, 'temp_global_' . $user['id']) === 0) {
        $tempPath = __DIR__ . '/../uploads/resumes/' . $tempFileName;
        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }
    }
    echo json_encode(['success' => true, 'message' => 'Upload discarded.']);
    exit;
}

if ($action === 'set_base_resume') {
    $resumePath = $_POST['resume_path'] ?? '';
    if (empty($resumePath)) {
        echo json_encode(['success' => false, 'message' => 'Resume path is required.']);
        exit;
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT resume_path FROM users WHERE id = :id");
    $stmt->execute(['id' => $user['id']]);
    $userFull = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $resumes = getCandidateResumes($userFull['resume_path'] ?? '');
    $updated = false;
    foreach ($resumes as &$r) {
        if ($r['path'] === $resumePath) {
            $r['is_base'] = true;
            $updated = true;
        } else {
            $r['is_base'] = false;
        }
    }
    
    if ($updated) {
        $jsonVal = json_encode(array_values($resumes));
        $stmt = $db->prepare("UPDATE users SET resume_path = :path WHERE id = :id");
        $stmt->execute(['path' => $jsonVal, 'id' => $user['id']]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Resume not found in list.']);
    }
    exit;
}

if ($action === 'update_resume_text') {
    $resumePath = $_POST['resume_path'] ?? '';
    $textVersion = $_POST['text_version'] ?? '';
    
    if (empty($resumePath)) {
        echo json_encode(['success' => false, 'message' => 'Resume path is required.']);
        exit;
    }
    
    $db = getDB();
    // 1. Try updating global resume
    $stmt = $db->prepare("SELECT resume_path FROM users WHERE id = :id");
    $stmt->execute(['id' => $user['id']]);
    $userFull = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $resumes = getCandidateResumes($userFull['resume_path'] ?? '');
    $updated = false;
    foreach ($resumes as &$r) {
        if ($r['path'] === $resumePath) {
            $r['text_version'] = $textVersion;
            $updated = true;
            break;
        }
    }
    
    if ($updated) {
        $jsonVal = json_encode(array_values($resumes));
        $stmt = $db->prepare("UPDATE users SET resume_path = :path WHERE id = :id");
        $stmt->execute(['path' => $jsonVal, 'id' => $user['id']]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    // 2. Try updating profile resume
    $stmt = $db->prepare("UPDATE candidate_profiles SET text_version = :text_version WHERE user_id = :uid AND optimized_resume_path = :path");
    $stmt->execute([
        'text_version' => $textVersion,
        'uid' => $user['id'],
        'path' => $resumePath
    ]);
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Resume not found or no changes made.']);
    }
    exit;
}

if ($action === 'delete_global_resume') {
    $deletePath = $_POST['resume_path'] ?? '';
    if (empty($deletePath)) {
        echo json_encode(['success' => false, 'message' => 'Resume path is required.']);
        exit;
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT resume_path FROM users WHERE id = :id");
    $stmt->execute(['id' => $user['id']]);
    $userFull = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $resumes = getCandidateResumes($userFull['resume_path'] ?? '');
    $foundIndex = -1;
    foreach ($resumes as $idx => $r) {
        if ($r['path'] === $deletePath) {
            $foundIndex = $idx;
            break;
        }
    }
    
    if ($foundIndex !== -1) {
        $fullPath = __DIR__ . '/../' . $deletePath;
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }
        
        $wasBase = !empty($resumes[$foundIndex]['is_base']);
        array_splice($resumes, $foundIndex, 1);
        
        if ($wasBase && !empty($resumes)) {
            $resumes[0]['is_base'] = true;
        }
        
        $jsonVal = empty($resumes) ? null : json_encode(array_values($resumes));
        $stmt = $db->prepare("UPDATE users SET resume_path = :path WHERE id = :id");
        $stmt->execute(['path' => $jsonVal, 'id' => $user['id']]);
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Resume not found.']);
    }
    exit;
}

if ($action === 'update_account') {
    $newFullName = trim($_POST['full_name'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    try {
        if (empty($newFullName)) {
            throw new Exception("Full name cannot be empty.");
        }
        if (strlen($newFullName) > 150) {
            throw new Exception("Full name is too long.");
        }

        $db = getDB();
        $stmt = $db->prepare("SELECT has_password FROM users WHERE id = :id");
        $stmt->execute(['id' => $user['id']]);
        $hasPassword = $stmt->fetchColumn();

        $wantsPasswordChange = !empty($newPassword) || !empty($confirmPassword);
        if ($wantsPasswordChange) {
            if ($hasPassword && empty($currentPassword)) {
                throw new Exception("Please enter your current password.");
            }
            if (empty($newPassword) || empty($confirmPassword)) {
                throw new Exception("To change your password, fill in both new password fields.");
            }
            if ($newPassword !== $confirmPassword) {
                throw new Exception("New password and confirmation do not match.");
            }
            if (strlen($newPassword) < 6) {
                throw new Exception("New password must be at least 6 characters long.");
            }
            changeUserPassword($user['id'], $currentPassword, $newPassword);
        }

        updateUserFullName($user['id'], $newFullName);
        $_SESSION['user']['full_name'] = $newFullName;

        echo json_encode(['success' => true, 'message' => 'Account updated successfully.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
exit;
