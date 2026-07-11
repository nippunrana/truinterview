<?php
// candidate-v2/api/resume_optimizer_ajax.php - Resume Optimizer V2 AJAX handler
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../optimizer_service.php';
require_once __DIR__ . '/../../ai_service.php';

requireAuth(['candidate']);
$user = getCurrentUser();
$db = getDB();

$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $user['id']]);
$userFull = $stmt->fetch(PDO::FETCH_ASSOC);

if (isset($_GET['ajax_action']) || isset($_POST['ajax_action'])) {
    $action = $_GET['ajax_action'] ?? $_POST['ajax_action'] ?? '';
    header('Content-Type: application/json');

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
                $text = optimizer_extract_text($fullPath, $ext);
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
            $result = optimizer_reality_check($resumeText);
            echo json_encode(['success' => true, 'data' => $result]);
            exit;
        }

        if ($action === 'optimizer_gap_analysis') {
            $resumeText = $_POST['resume_text'] ?? '';
            $targetRole = $_POST['target_role'] ?? '';
            $jobDescription = $_POST['job_description'] ?? '';

            $result = optimizer_gap_analysis($resumeText, $targetRole, $jobDescription);
            echo json_encode(['success' => true, 'data' => $result]);
            exit;
        }

        if ($action === 'optimizer_verify_dates') {
            $resumeText = $_POST['resume_text'] ?? '';
            
            // Extract raw dates
            $rawExp = optimizer_extract_dates($resumeText);
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

            $result = optimizer_generate_rewrite($resumeText, $targetRole, $jobDescription, $gapAnswers, $verifiedDates);
            echo json_encode(['success' => true, 'data' => $result]);
            exit;
        }

        if ($action === 'optimizer_save_profile') {
            $optimizedMarkdown = $_POST['optimized_markdown'] ?? '';
            $profileId = $_POST['profile_id'] ?? null;
            $changesRaw = $_POST['changes'] ?? '';
            $originalPath = $_POST['original_path'] ?? null;
            $targetRole = $_POST['target_role'] ?? null;
            $jobDescription = $_POST['job_description'] ?? '';
            $aiRefinedRole = $_POST['ai_refined_role'] ?? '';
            $changes = !empty($changesRaw) ? json_decode($changesRaw, true) : null;

            if (empty($optimizedMarkdown)) {
                echo json_encode(['success' => false, 'message' => 'Optimized markdown content is required.']);
                exit;
            }

            if (!empty($profileId)) {
                if (empty($aiRefinedRole)) {
                    $aiRefinedRole = $targetRole;
                }
                $result = optimizer_save_to_candidate_profile($profileId, $user['id'], $optimizedMarkdown, $changes, $aiRefinedRole, $originalPath, $targetRole, $jobDescription);
                echo json_encode(['success' => true, 'data' => $result]);
                exit;
            }

            $result = optimizer_save_to_profile($user['id'], $optimizedMarkdown, $changes, $originalPath);

            $roleProfileCreated = false;
            $roleProfileId = null;
            $limitReached = false;

            if (($_POST['create_role_profile'] ?? '') === '1') {
                $roleForProfile = !empty($aiRefinedRole) ? $aiRefinedRole : $targetRole;

                if (!empty($roleForProfile)) {
                    $roleTitleId = preg_replace('/\s+/', '-', $roleForProfile);
                    $roleTitleId = preg_replace('/[^a-zA-Z0-9\-]/', '', $roleTitleId);
                    $roleTitleId = preg_replace('/-+/', '-', $roleTitleId);
                    $roleTitleId = trim($roleTitleId, '-');

                    $newProfileId = findCandidateProfileBySlug($user['id'], $roleTitleId);

                    if (empty($newProfileId)) {
                        $categoryId = null;
                        $matchPercentage = 0;
                        try {
                            $categories = getAllCategories();
                            $aiResult = matchRoleToCategory($roleForProfile, $categories);
                            if (!empty($aiResult['category_id']) && isset($aiResult['match_percentage']) && $aiResult['match_percentage'] >= 15) {
                                $categoryId = $aiResult['category_id'];
                                $matchPercentage = $aiResult['match_percentage'];
                            }
                        } catch (Exception $e) {
                            // Fail silently and leave category empty if AI matching fails
                        }

                        $newProfileId = createCandidateProfile($user['id'], $roleForProfile, $categoryId, $matchPercentage);
                        if (empty($newProfileId)) {
                            $limitReached = true;
                        }
                    }

                    if (!empty($newProfileId)) {
                        optimizer_save_to_candidate_profile($newProfileId, $user['id'], $optimizedMarkdown, $changes, $aiRefinedRole, $originalPath, $targetRole, $jobDescription);
                        $roleProfileCreated = true;
                        $roleProfileId = $newProfileId;
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'data' => $result,
                'role_profile_created' => $roleProfileCreated,
                'role_profile_id' => $roleProfileId,
                'limit_reached' => $limitReached
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Invalid action requested.']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}
