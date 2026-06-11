<?php
// candidate-v2/resume_optimizer.php - Resume Optimizer Page V2
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../optimizer_service.php';

requireAuth(['candidate']);
$user = getCurrentUser();
$db = getDB();

$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $user['id']]);
$userFull = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle AJAX actions
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
                $fullPath = __DIR__ . '/../' . $resumePath;
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
            if (empty($optimizedMarkdown)) {
                echo json_encode(['success' => false, 'message' => 'Optimized markdown content is required.']);
                exit;
            }

            if (!empty($profileId)) {
                $result = optimizer_save_to_candidate_profile($profileId, $user['id'], $optimizedMarkdown);
            } else {
                $result = optimizer_save_to_profile($user['id'], $optimizedMarkdown);
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

$resumePath = $_GET['resume_path'] ?? '';
if (empty($resumePath)) {
    header("Location: index.php");
    exit;
}

$profileId = $_GET['profile_id'] ?? $_POST['profile_id'] ?? null;

// Ensure the resume path exists in user's profile database, if not V2 profile
if (empty($profileId)) {
    $resumes = getCandidateResumes($userFull['resume_path'] ?? '');
    $valid = false;
    foreach ($resumes as $r) {
        if ($r['path'] === $resumePath) {
            $valid = true;
            break;
        }
    }
    if (!$valid) {
        header("Location: index.php");
        exit;
    }
}

$words = explode(" ", $user['full_name']);
$initials = "";
foreach ($words as $w) {
    $initials .= strtoupper($w[0] ?? '');
}
$initials = substr($initials, 0, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Resume Optimizer - TruInterview</title>
  <link rel="stylesheet" href="../assets/css/candidate.css">
  <style>
    :root {
      --color-emerald: #10b981;
      --color-rose: #f43f5e;
      --color-amber: #f59e0b;
      --shadow-premium: 0 10px 30px -10px rgba(0, 0, 0, 0.08), 0 1px 3px rgba(0, 0, 0, 0.02);
    }

    .optimizer-container {
      max-width: 1000px;
      margin: 0 auto;
      padding: 40px 20px;
      display: flex;
      flex-direction: column;
      gap: 32px;
    }

    /* Stepper UI */
    .stepper {
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: var(--color-surface);
      border: 1px solid var(--color-border);
      padding: 24px 32px;
      border-radius: var(--radius-outer);
      box-shadow: var(--shadow-premium);
      position: relative;
    }

    .step-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      position: relative;
      flex: 1;
      z-index: 2;
    }

    .step-badge {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: var(--color-bg-app);
      border: 2px solid var(--color-border);
      color: var(--color-text-muted);
      font-weight: 700;
      font-size: 0.95rem;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: var(--transition-smooth);
    }

    .step-item.active .step-badge {
      background: var(--color-indigo);
      border-color: var(--color-indigo);
      color: #ffffff;
      box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.15);
    }

    .step-item.completed .step-badge {
      background: var(--color-emerald);
      border-color: var(--color-emerald);
      color: #ffffff;
    }

    .step-label {
      font-size: 0.8rem;
      font-weight: 600;
      color: var(--color-text-muted);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      transition: var(--transition-smooth);
    }

    .step-item.active .step-label {
      color: var(--color-text-primary);
    }

    .stepper-connector {
      position: absolute;
      top: 42px;
      left: 10%;
      right: 10%;
      height: 2px;
      background: var(--color-border);
      z-index: 1;
    }

    .stepper-progress {
      position: absolute;
      top: 0;
      left: 0;
      height: 100%;
      width: 0%;
      background: var(--color-indigo);
      transition: var(--transition-smooth);
    }

    /* Panels */
    .optimizer-panel {
      background: var(--color-surface);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-outer);
      padding: 40px;
      box-shadow: var(--shadow-premium);
      display: flex;
      flex-direction: column;
      gap: 32px;
      min-height: 350px;
      position: relative;
    }

    .panel-step-content {
      display: none;
      animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    .panel-step-content.active {
      display: flex;
      flex-direction: column;
      gap: 28px;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(8px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .panel-title-large {
      font-family: 'Outfit', sans-serif;
      font-size: 1.6rem;
      font-weight: 700;
      color: var(--color-text-primary);
      margin: 0;
    }

    .panel-subtitle {
      font-size: 0.95rem;
      color: var(--color-text-secondary);
      line-height: 1.5;
      margin-top: -16px;
    }

    /* Actions bottom bar */
    .actions-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-top: 1px solid var(--color-border);
      padding-top: 24px;
      margin-top: 12px;
    }

    /* Loading Skeletons */
    .skeleton-wrapper {
      display: flex;
      flex-direction: column;
      gap: 16px;
      width: 100%;
    }

    .skeleton-line {
      height: 18px;
      background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
      background-size: 200% 100%;
      animation: skeleton-loading 1.5s infinite;
      border-radius: 4px;
    }

    @keyframes skeleton-loading {
      0% { background-position: 200% 0; }
      100% { background-position: -200% 0; }
    }

    /* Timeline & Verification Math CSS */
    .timeline {
      position: relative;
      padding-left: 24px;
      border-left: 2px solid var(--color-border);
      display: flex;
      flex-direction: column;
      gap: 28px;
      margin-left: 8px;
    }

    .timeline-item {
      position: relative;
    }

    .timeline-dot {
      position: absolute;
      left: -32px;
      top: 4px;
      width: 14px;
      height: 14px;
      border-radius: 50%;
      background: #ffffff;
      border: 2px solid var(--color-indigo);
    }

    .timeline-content {
      background: var(--color-bg-app);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-inner);
      padding: 18px 24px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .timeline-title {
      font-weight: 700;
      color: var(--color-text-primary);
      font-size: 0.95rem;
      margin-bottom: 4px;
    }

    .timeline-subtitle {
      font-size: 0.85rem;
      color: var(--color-text-secondary);
    }

    .timeline-duration {
      font-weight: 700;
      color: var(--color-cyan);
      font-size: 0.85rem;
      background: rgba(6, 182, 212, 0.08);
      padding: 4px 10px;
      border-radius: 6px;
    }

    .timeline-metric-card {
      background: rgba(79, 70, 229, 0.03);
      border: 1px dashed rgba(79, 70, 229, 0.25);
      border-radius: var(--radius-inner);
      padding: 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .warning-box {
      background: rgba(244, 63, 94, 0.03);
      border: 1px solid rgba(244, 63, 94, 0.15);
      color: var(--color-rose);
      border-radius: var(--radius-inner);
      padding: 18px 24px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      font-size: 0.88rem;
    }

    /* Tabs & Score Page */
    .tab-nav {
      display: flex;
      border-bottom: 1px solid var(--color-border);
      gap: 16px;
    }

    .tab-trigger {
      background: none;
      border: none;
      padding: 12px 4px;
      font-family: inherit;
      font-size: 0.92rem;
      font-weight: 600;
      color: var(--color-text-muted);
      cursor: pointer;
      border-bottom: 2px solid transparent;
      transition: var(--transition-smooth);
    }

    .tab-trigger.active {
      color: var(--color-indigo);
      border-bottom-color: var(--color-indigo);
    }

    .tab-pane {
      display: none;
      animation: fadeIn 0.3s ease forwards;
    }

    .tab-pane.active {
      display: block;
    }

    /* Score gauge */
    .score-circle-wrapper {
      display: flex;
      align-items: center;
      gap: 20px;
      background: #f8fafc;
      padding: 20px;
      border-radius: var(--radius-inner);
      border: 1px solid var(--color-border);
    }

    .score-badge-large {
      width: 72px;
      height: 72px;
      border-radius: 50%;
      background: var(--color-indigo);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: 'Outfit', sans-serif;
      font-size: 1.8rem;
      font-weight: 800;
      box-shadow: 0 8px 20px rgba(79, 70, 229, 0.25);
    }

    .gap-checkbox-card {
      background: #ffffff;
      border: 1px solid var(--color-border);
      border-radius: var(--radius-inner);
      padding: 20px;
      transition: var(--transition-smooth);
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .gap-checkbox-card:hover {
      border-color: var(--color-indigo);
      box-shadow: 0 4px 12px rgba(79, 70, 229, 0.03);
    }

    .gap-header {
      display: flex;
      align-items: flex-start;
      gap: 12px;
    }

    .gap-checkbox {
      margin-top: 4px;
      width: 18px;
      height: 18px;
      cursor: pointer;
      accent-color: var(--color-indigo);
    }

    .gap-label {
      cursor: pointer;
      font-weight: 700;
      font-size: 0.95rem;
      color: var(--color-text-primary);
    }

    .gap-desc {
      font-size: 0.88rem;
      color: var(--color-text-secondary);
      line-height: 1.4;
      margin-top: 4px;
    }

    .gap-textarea {
      width: 100%;
      border: 1px solid var(--color-border);
      border-radius: 8px;
      padding: 10px 14px;
      font-family: inherit;
      font-size: 0.88rem;
      display: none;
      resize: vertical;
      box-sizing: border-box;
    }

    .gap-textarea:focus {
      outline: none;
      border-color: var(--color-indigo);
      box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
    }

    /* side-by-side or large box for resume code */
    .resume-output-box {
      background: #0f172a;
      color: #e2e8f0;
      border-radius: var(--radius-inner);
      padding: 24px;
      font-family: 'Fira Code', 'Courier New', Courier, monospace;
      font-size: 0.85rem;
      line-height: 1.6;
      white-space: pre-wrap;
      overflow-y: auto;
      max-height: 500px;
      border: 1px solid #1e293b;
    }

    .changes-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.88rem;
    }

    .changes-table th {
      text-align: left;
      padding: 12px;
      border-bottom: 2px solid var(--color-border);
      color: var(--color-text-muted);
      font-weight: 700;
      text-transform: uppercase;
      font-size: 0.75rem;
    }

    .changes-table td {
      padding: 16px 12px;
      border-bottom: 1px solid var(--color-border);
      vertical-align: top;
      line-height: 1.4;
    }

    .changes-table tr:hover td {
      background: #f8fafc;
    }

    .change-badge {
      display: inline-block;
      padding: 2px 6px;
      border-radius: 4px;
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      margin-bottom: 6px;
    }

    .change-badge-before {
      background: rgba(244, 63, 94, 0.08);
      color: var(--color-rose);
    }

    .change-badge-after {
      background: rgba(16, 185, 129, 0.08);
      color: var(--color-emerald);
    }
  </style>
</head>
<body class="dashboard-body">

  <div class="optimizer-container">
    <!-- Header -->
    <header class="dashboard-header">
      <a href="index.php" class="brand-wrapper">
        <svg style="width: 28px; height: 28px; color: #06b6d4;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
        </svg>
        <span class="brand-title">TruInterview</span>
      </a>
      
      <div style="display: flex; gap: 16px; align-items: center;">
        <a href="index.php" class="btn-logout" style="border-color: var(--color-border); text-decoration: none;">&larr; Exit Optimizer</a>
        <div class="user-profile">
          <div class="avatar"><?php echo htmlspecialchars($initials); ?></div>
        </div>
      </div>
    </header>

    <!-- Stepper Navigation -->
    <div class="stepper">
      <div class="stepper-connector">
        <div id="stepper-progress" class="stepper-progress"></div>
      </div>
      <div class="step-item active" id="step-nav-1">
        <div class="step-badge">1</div>
        <span class="step-label">Reality Check</span>
      </div>
      <div class="step-item" id="step-nav-2">
        <div class="step-badge">2</div>
        <span class="step-label">Skill Gaps</span>
      </div>
      <div class="step-item" id="step-nav-3">
        <div class="step-badge">3</div>
        <span class="step-label">Experience Math</span>
      </div>
      <div class="step-item" id="step-nav-4">
        <div class="step-badge">4</div>
        <span class="step-label">ATS Optimize</span>
      </div>
    </div>

    <!-- Main Wizard Panel -->
    <div class="optimizer-panel">
      
      <!-- LOADING COVER SKELETON -->
      <div id="loading-container" style="display: none; flex-direction: column; gap: 24px; width: 100%;">
        <div class="panel-title-large" id="loading-status">Analyzing with Gemini AI...</div>
        <div class="skeleton-wrapper">
          <div class="skeleton-line" style="width: 80%;"></div>
          <div class="skeleton-line" style="width: 95%;"></div>
          <div class="skeleton-line" style="width: 60%;"></div>
          <div class="skeleton-line" style="width: 85%;"></div>
          <div class="skeleton-line" style="width: 70%;"></div>
        </div>
      </div>

      <!-- STEP 1: REALITY CHECK & JD ENTRY -->
      <div class="panel-step-content active" id="step-content-1">
        <h3 class="panel-title-large">Step 1: Reality Check</h3>
        <p class="panel-subtitle">Gemini has analyzed your resume blindly to deduce how standard Applicant Tracking Systems bucket your profile.</p>

        <div style="background: #f8fafc; border: 1px solid var(--color-border); padding: 24px; border-radius: var(--radius-inner);">
          <div style="display: flex; gap: 40px; align-items: baseline; margin-bottom: 12px;">
            <div>
              <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--color-text-muted); font-weight: 700;">Guessed Target Role</div>
              <div id="guessed-role" style="font-family: 'Outfit', sans-serif; font-size: 1.35rem; font-weight: 800; color: var(--color-indigo);">-</div>
            </div>
            <div>
              <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--color-text-muted); font-weight: 700;">Guessed Seniority</div>
              <div id="guessed-seniority" style="font-family: 'Outfit', sans-serif; font-size: 1.35rem; font-weight: 800; color: var(--color-cyan);">-</div>
            </div>
          </div>
          <div style="font-size: 0.88rem; line-height: 1.5; color: var(--color-text-secondary);" id="guessed-summary">
            Performing initial scan...
          </div>
        </div>

        <div style="display: flex; flex-direction: column; gap: 16px;">
          <label style="font-weight: 700; color: var(--color-text-primary); font-size: 0.95rem;">Is this the role and seniority level you are targeting?</label>
          <div style="display: flex; gap: 16px;">
            <button class="btn-secondary-action" id="btn-rc-yes" style="flex: 1; padding: 16px; border-color: var(--color-emerald); color: var(--color-emerald);">Yes, this is my target role</button>
            <button class="btn-secondary-action" id="btn-rc-no" style="flex: 1; padding: 16px;">No, I want to customize it</button>
          </div>
        </div>

        <div id="jd-customization-section" style="display: none; flex-direction: column; gap: 16px; animation: fadeIn 0.3s ease;">
          <div style="display: flex; flex-direction: column; gap: 8px;">
            <label style="font-size: 0.88rem; font-weight: 700; color: var(--color-text-secondary);">Target Job Title / Role Name</label>
            <input type="text" id="target-role-input" class="form-input" placeholder="e.g. Senior Full Stack Engineer (PHP/JS)">
          </div>
          <div style="display: flex; flex-direction: column; gap: 8px;">
            <label style="font-size: 0.88rem; font-weight: 700; color: var(--color-text-secondary);">Target Job Description (JD) Content</label>
            <textarea id="job-desc-input" class="form-input" style="min-height: 140px; resize: vertical;" placeholder="Paste the job requirements, duties, and qualifications here to optimize your resume keywords..."></textarea>
          </div>
        </div>
      </div>

      <!-- STEP 2: GAP ANALYSIS & QUESTIONNAIRE -->
      <div class="panel-step-content" id="step-content-2">
        <h3 class="panel-title-large">Step 2: Gap Analysis & Skill Gathering</h3>
        <p class="panel-subtitle">Gemini has compared your resume against the Job Description. Tell us if you have worked with these missing items, and we'll weave them into the final version.</p>

        <div id="gaps-list" style="display: flex; flex-direction: column; gap: 16px;">
          <!-- Loaded dynamically -->
        </div>
      </div>

      <!-- STEP 3: EXPERIENCE MATHEMATICAL TIMELINE -->
      <div class="panel-step-content" id="step-content-3">
        <h3 class="panel-title-large">Step 3: Computational Experience Verification</h3>
        <p class="panel-subtitle">ATS algorithms rank resumes strictly on calculated duration metrics. Gemini parsed your employment dates, and we computed them mathematically to prevent overlaps and formatting failures.</p>

        <div class="timeline-metric-card">
          <div>
            <div style="font-size: 0.78rem; text-transform: uppercase; color: var(--color-text-muted); font-weight: 700; letter-spacing: 0.5px;">Mathematically Verified Total Experience</div>
            <div id="verified-total-duration" style="font-family: 'Outfit', sans-serif; font-size: 1.6rem; font-weight: 800; color: var(--color-indigo); margin-top: 4px;">Calculating...</div>
            <div style="font-size: 0.8rem; color: var(--color-text-muted); margin-top: 4px;">Excludes any overlapping employment periods.</div>
          </div>
          <svg style="width: 44px; height: 44px; color: var(--color-indigo); opacity: 0.65;" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-9-6h.008v.008H12v-.008zM12 15h.008v.008H12V15zm0 2.25h.008v.008H12v-.008zM9.75 15h.008v.008H9.75V15zm0 2.25h.008v.008H9.75v-.008zM7.5 15h.008v.008H7.5V15zm0 2.25h.008v.008H7.5v-.008zm6.75-4.5h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V15zm0 2.25h.008v.008h-.008v-.008zm2.25-4.5h.008v.008H16.5v-.008zm0 2.25h.008v.008H16.5V15z"></path>
          </svg>
        </div>

        <div id="timeline-warnings" class="warning-box" style="display: none;">
          <div style="font-weight: 700; display: flex; align-items: center; gap: 8px;">
            <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
            Timeline Anomalies Detected
          </div>
          <ul id="timeline-warnings-list" style="margin: 0; padding-left: 20px; line-height: 1.5; font-size: 0.84rem;"></ul>
        </div>

        <div style="display: flex; flex-direction: column; gap: 16px;">
          <h4 style="font-weight: 700; color: var(--color-text-primary); font-size: 0.95rem; margin: 0;">Calculated Timeline Chart</h4>
          <div class="timeline" id="timeline-list">
            <!-- Loaded dynamically -->
          </div>
        </div>
      </div>

      <!-- STEP 4: ATS OPTIMIZATION & DEEP ANALYSIS -->
      <div class="panel-step-content" id="step-content-4">
        <h3 class="panel-title-large">Step 4: AI Optimization & Recommendations</h3>
        <p class="panel-subtitle">Your resume has been rewritten using keyword enrichment and Google's XYZ impact structure.</p>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
          <div class="score-circle-wrapper">
            <div class="score-badge-large" id="final-score-badge">0</div>
            <div>
              <div style="font-weight: 700; color: var(--color-text-primary); font-size: 1rem;">ATS Match Index</div>
              <div style="font-size: 0.85rem; color: var(--color-text-muted); margin-top: 2px;" id="final-rating-desc">Rating based on readability & keywords.</div>
            </div>
          </div>

          <div style="background: #f8fafc; border: 1px solid var(--color-border); padding: 20px; border-radius: var(--radius-inner); display: flex; align-items: center; justify-content: space-between;">
            <div>
              <div style="font-weight: 700; color: var(--color-text-primary); font-size: 0.95rem;">Commit to Profile</div>
              <div style="font-size: 0.8rem; color: var(--color-text-muted); margin-top: 2px;">Replace active resume with this version.</div>
            </div>
            <button class="btn-primary-action" id="btn-save-profile" style="padding: 10px 20px; font-size: 0.85rem; min-width: 140px;">Save to Profile</button>
          </div>
        </div>

        <div class="tab-nav">
          <button class="tab-trigger active" onclick="switchOptTab('opt-resume')">Optimized Resume</button>
          <button class="tab-trigger" onclick="switchOptTab('opt-keywords')">Keyword Alignment</button>
          <button class="tab-trigger" onclick="switchOptTab('opt-changes')">Changes & Rationale</button>
        </div>

        <!-- Tab 1: Rewritten Resume -->
        <div class="tab-pane active" id="pane-opt-resume">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <div style="font-size: 0.8rem; font-weight: 700; color: var(--color-text-secondary); text-transform: uppercase;">Markdown Content</div>
            <button class="btn-secondary-action" id="btn-copy-clipboard" style="padding: 6px 12px; font-size: 0.78rem;">Copy to Clipboard</button>
          </div>
          <div class="resume-output-box" id="rewritten-resume-text"></div>
        </div>

        <!-- Tab 2: Keyword Summary -->
        <div class="tab-pane" id="pane-opt-keywords">
          <div style="background: #f8fafc; border: 1px solid var(--color-border); padding: 24px; border-radius: var(--radius-inner); line-height: 1.6; color: var(--color-text-secondary); font-size: 0.95rem;" id="keyword-summary-text">
            <!-- Loaded dynamically -->
          </div>
        </div>

        <!-- Tab 3: Detailed Changes -->
        <div class="tab-pane" id="pane-opt-changes">
          <div style="overflow-x: auto; border: 1px solid var(--color-border); border-radius: var(--radius-inner); background: #fff;">
            <table class="changes-table">
              <thead>
                <tr>
                  <th style="width: 35%;">Original Text</th>
                  <th style="width: 40%;">Optimized XYZ Version</th>
                  <th style="width: 25%;">Recruiter Rationale</th>
                </tr>
              </thead>
              <tbody id="changes-table-body">
                <!-- Loaded dynamically -->
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- FOOTER ACTIONS BAR -->
      <div class="actions-bar">
        <button class="btn-secondary-action" id="btn-prev" style="min-width: 120px; display: none;">&larr; Back</button>
        <div style="flex-grow: 1;"></div>
        <button class="btn-primary-action" id="btn-next" style="min-width: 140px;">Continue &rarr;</button>
      </div>

    </div>
  </div>

  <script>
    // State machine matching the implementation plan
    const state = {
      resumePath: <?php echo json_encode($resumePath); ?>,
      profileId: <?php echo json_encode($_GET['profile_id'] ?? null); ?>,
      resumeText: '',
      currentStep: 1,

      // Step 1: Reality Check outputs
      targetRole: '',
      seniority: '',
      blindSummary: '',
      jobDescription: '',

      // Step 2: Gap outputs
      gaps: [],
      gapAnswers: [],

      // Step 3: Date calculations
      verifiedDates: null,

      // Step 4: Final optimization output
      finalResult: null
    };

    // UI elements
    const loadingContainer = document.getElementById('loading-container');
    const loadingStatusText = document.getElementById('loading-status');
    const actionPrev = document.getElementById('btn-prev');
    const actionNext = document.getElementById('btn-next');
    const stepperProgress = document.getElementById('stepper-progress');

    // Steps view mappings
    const stepContents = {
      1: document.getElementById('step-content-1'),
      2: document.getElementById('step-content-2'),
      3: document.getElementById('step-content-3'),
      4: document.getElementById('step-content-4')
    };

    function updateStepperUI() {
      // Connectors/Stepper Line
      const pct = ((state.currentStep - 1) / 3) * 100;
      stepperProgress.style.width = pct + '%';

      // Step Indicators
      for (let s = 1; s <= 4; s++) {
        const item = document.getElementById('step-nav-' + s);
        if (s < state.currentStep) {
          item.classList.add('completed');
          item.classList.remove('active');
        } else if (s === state.currentStep) {
          item.classList.add('active');
          item.classList.remove('completed');
        } else {
          item.classList.remove('active');
          item.classList.remove('completed');
        }
      }

      // Hide all content containers, show current
      for (let s = 1; s <= 4; s++) {
        if (s === state.currentStep) {
          stepContents[s].classList.add('active');
        } else {
          stepContents[s].classList.remove('active');
        }
      }

      // Navigation button configurations
      actionPrev.style.display = (state.currentStep > 1 && state.currentStep < 4) ? 'block' : 'none';

      if (state.currentStep === 1) {
        actionNext.innerHTML = 'Continue &rarr;';
        // Check if role or custom JD entered
        const isJdOk = !document.getElementById('jd-customization-section').classList.contains('active') || 
                       (document.getElementById('target-role-input').value.trim() !== '' && 
                        document.getElementById('job-desc-input').value.trim() !== '');
        actionNext.disabled = !isJdOk;
      } else if (state.currentStep === 2) {
        actionNext.innerHTML = 'Verify Experience &rarr;';
        actionNext.disabled = false;
      } else if (state.currentStep === 3) {
        actionNext.innerHTML = 'Optimize Resume &rarr;';
        actionNext.disabled = false;
      } else if (state.currentStep === 4) {
        actionNext.style.display = 'none'; // Replaced by save buttons/exit links
      }
    }

    function showLoading(statusMsg) {
      // Hide active step content container
      if (stepContents[state.currentStep]) {
        stepContents[state.currentStep].classList.remove('active');
      }
      loadingStatusText.textContent = statusMsg;
      loadingContainer.style.display = 'flex';
      actionNext.disabled = true;
      actionPrev.disabled = true;
    }

    function hideLoading() {
      loadingContainer.style.display = 'none';
      if (stepContents[state.currentStep]) {
        stepContents[state.currentStep].classList.add('active');
      }
      actionNext.disabled = false;
      actionPrev.disabled = false;
    }

    // Step 1: Reality check
    async function loadRealityCheck() {
      showLoading('Gemini is running reality check (blind analysis)...');
      try {
        const response = await fetch('resume_optimizer.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'ajax_action=optimizer_reality_check&resume_text=' + encodeURIComponent(state.resumeText)
        });
        const result = await response.json();
        
        hideLoading();
        if (result.success && result.data) {
          state.targetRole = result.data.target_role;
          state.seniority = result.data.seniority;
          state.blindSummary = result.data.summary;

          document.getElementById('guessed-role').textContent = state.targetRole;
          document.getElementById('guessed-seniority').textContent = state.seniority;
          document.getElementById('guessed-summary').textContent = state.blindSummary;

          // Default set inputs in custom section
          document.getElementById('target-role-input').value = state.targetRole;
        } else {
          alert('Reality check failed: ' + (result.message || 'Unknown error'));
        }
      } catch (err) {
        hideLoading();
        alert('API error: ' + err.message);
      }
    }

    // Step 2: Gap Analysis loading
    async function runGapAnalysis() {
      showLoading('Analyzing Job Description requirements and mapping gaps...');
      
      const customJdActive = document.getElementById('jd-customization-section').classList.contains('active');
      if (customJdActive) {
        state.targetRole = document.getElementById('target-role-input').value.trim();
        state.jobDescription = document.getElementById('job-desc-input').value.trim();
      } else {
        // If they selected yes, targetRole is set, and jobDescription is empty or default
        state.jobDescription = "General role requirements matching: " + state.targetRole;
      }

      try {
        const response = await fetch('resume_optimizer.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'ajax_action=optimizer_gap_analysis&resume_text=' + encodeURIComponent(state.resumeText) +
                '&target_role=' + encodeURIComponent(state.targetRole) +
                '&job_description=' + encodeURIComponent(state.jobDescription)
        });
        const result = await response.json();

        hideLoading();
        if (result.success && result.data) {
          state.gaps = result.data.gaps || [];
          renderGapsList();
          state.currentStep = 2;
          updateStepperUI();
        } else {
          alert('Gap analysis failed: ' + (result.message || 'Unknown error'));
        }
      } catch (err) {
        hideLoading();
        alert('API error: ' + err.message);
      }
    }

    function renderGapsList() {
      const wrapper = document.getElementById('gaps-list');
      wrapper.innerHTML = '';

      if (state.gaps.length === 0) {
        wrapper.innerHTML = `<div style="background: rgba(16, 185, 129, 0.04); border: 1px solid rgba(16, 185, 129, 0.15); color: var(--color-emerald); padding: 24px; border-radius: var(--radius-inner); text-align: center; font-weight: 600;">
          🎉 Fantastic match! Gemini found no significant skill gaps between your resume and the target role requirements. Click verify timeline to continue.
        </div>`;
        return;
      }

      state.gaps.forEach((gap, index) => {
        const card = document.createElement('div');
        card.className = 'gap-checkbox-card';

        const importanceBadge = gap.importance === 'high' ? 
          `<span style="font-size: 0.68rem; padding: 2px 6px; border-radius: 4px; background: rgba(244, 63, 94, 0.08); color: var(--color-rose); font-weight: 700; margin-left: auto;">High Priority</span>` :
          `<span style="font-size: 0.68rem; padding: 2px 6px; border-radius: 4px; background: rgba(245, 158, 11, 0.08); color: var(--color-amber); font-weight: 700; margin-left: auto;">Recommended</span>`;

        card.innerHTML = `
          <div class="gap-header">
            <input type="checkbox" id="gap-chk-${index}" class="gap-checkbox" onchange="toggleGapTextarea(${index})">
            <div style="flex-grow: 1;">
              <label for="gap-chk-${index}" class="gap-label">${escapeHTML(gap.skill)}</label>
              <div class="gap-desc">${escapeHTML(gap.question)}</div>
            </div>
            ${importanceBadge}
          </div>
          <textarea id="gap-txt-${index}" class="gap-textarea" placeholder="E.g., yes, I did this in my last role using..."></textarea>
        `;

        wrapper.appendChild(card);
      });
    }

    window.toggleGapTextarea = function(index) {
      const chk = document.getElementById(`gap-chk-${index}`);
      const txt = document.getElementById(`gap-txt-${index}`);
      if (chk.checked) {
        txt.style.display = 'block';
        txt.focus();
      } else {
        txt.style.display = 'none';
        txt.value = '';
      }
    };

    // Step 3: Date calculations
    async function loadDateTimeline() {
      showLoading('Running mathematical timeline calculations...');

      // Save Gap Answers before moving on
      state.gapAnswers = [];
      state.gaps.forEach((gap, index) => {
        const chk = document.getElementById(`gap-chk-${index}`);
        const txt = document.getElementById(`gap-txt-${index}`);
        if (chk && chk.checked && txt.value.trim() !== '') {
          state.gapAnswers.push({
            skill: gap.skill,
            answer: txt.value.trim()
          });
        }
      });

      try {
        const response = await fetch('resume_optimizer.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'ajax_action=optimizer_verify_dates&resume_text=' + encodeURIComponent(state.resumeText)
        });
        const result = await response.json();

        hideLoading();
        if (result.success && result.data) {
          state.verifiedDates = result.data;
          renderTimeline();
          state.currentStep = 3;
          updateStepperUI();
        } else {
          alert('Experience extraction failed: ' + (result.message || 'Unknown error'));
        }
      } catch (err) {
        hideLoading();
        alert('API error: ' + err.message);
      }
    }

    function renderTimeline() {
      // Total Duration
      document.getElementById('verified-total-duration').textContent = state.verifiedDates.total_experience_formatted || '0 months';

      // Warnings
      const warnBox = document.getElementById('timeline-warnings');
      const warnList = document.getElementById('timeline-warnings-list');
      warnList.innerHTML = '';

      let hasAlerts = false;
      
      // Combine formatting warnings and overlap alerts
      if (state.verifiedDates.warnings && state.verifiedDates.warnings.length > 0) {
        state.verifiedDates.warnings.forEach(w => {
          const li = document.createElement('li');
          li.textContent = w;
          warnList.appendChild(li);
        });
        hasAlerts = true;
      }

      if (state.verifiedDates.overlaps && state.verifiedDates.overlaps.length > 0) {
        state.verifiedDates.overlaps.forEach(ov => {
          const li = document.createElement('li');
          li.innerHTML = `Overlap of <strong>${ov.months} month(s)</strong> detected between <em>${escapeHTML(ov.job1)}</em> and <em>${escapeHTML(ov.job2)}</em>.`;
          warnList.appendChild(li);
        });
        hasAlerts = true;
      }

      if (hasAlerts) {
        warnBox.style.display = 'flex';
      } else {
        warnBox.style.display = 'none';
      }

      // Timeline List
      const timelineList = document.getElementById('timeline-list');
      timelineList.innerHTML = '';

      const details = state.verifiedDates.experience_details || [];
      if (details.length === 0) {
        timelineList.innerHTML = '<div style="color: var(--color-text-muted);">No experiences extracted.</div>';
        return;
      }

      details.forEach(item => {
        const timeItem = document.createElement('div');
        timeItem.className = 'timeline-item';
        timeItem.innerHTML = `
          <div class="timeline-dot"></div>
          <div class="timeline-content">
            <div>
              <div class="timeline-title">${escapeHTML(item.role)}</div>
              <div class="timeline-subtitle">${escapeHTML(item.company)} (${escapeHTML(item.start_date)} to ${escapeHTML(item.end_date)})</div>
            </div>
            <div class="timeline-duration">${escapeHTML(item.duration_formatted)}</div>
          </div>
        `;
        timelineList.appendChild(timeItem);
      });
    }

    // Step 4: Final optimization loading
    async function runFinalOptimization() {
      showLoading('Generating optimized resume and drafting changes scorecard...');
      try {
        const response = await fetch('resume_optimizer.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'ajax_action=optimizer_deep_analysis&resume_text=' + encodeURIComponent(state.resumeText) +
                '&target_role=' + encodeURIComponent(state.targetRole) +
                '&job_description=' + encodeURIComponent(state.jobDescription) +
                '&gap_answers=' + encodeURIComponent(JSON.stringify(state.gapAnswers)) +
                '&verified_dates=' + encodeURIComponent(JSON.stringify(state.verifiedDates))
        });
        const result = await response.json();

        hideLoading();
        if (result.success && result.data) {
          state.finalResult = result.data;
          renderFinalOutputs();
          state.currentStep = 4;
          updateStepperUI();
        } else {
          alert('Optimization failed: ' + (result.message || 'Unknown error'));
        }
      } catch (err) {
        hideLoading();
        alert('API error: ' + err.message);
      }
    }

    function renderFinalOutputs() {
      const data = state.finalResult;

      // Score circle
      document.getElementById('final-score-badge').textContent = data.rating || '0';
      
      const ratingDescs = {
        10: "Perfect Score! Maximum ATS and Recruiter alignment.",
        9: "Exceptional Match! Optimized for all skimming indices.",
        8: "Strong Profile! Well keywords-aligned and readable.",
        7: "Good Match. General headers and structure complete.",
        6: "Average. Minor alignment tweaks remaining.",
      };
      document.getElementById('final-rating-desc').textContent = ratingDescs[data.rating] || "Resume successfully enhanced with Google XYZ formula.";

      // Resume text markdown
      document.getElementById('rewritten-resume-text').textContent = data.rewritten_resume_markdown;

      // Keyword and gaps alignment text
      document.getElementById('keyword-summary-text').innerHTML = markdownToHTML(data.alignment_summary);

      // Changes list
      const tbody = document.getElementById('changes-table-body');
      tbody.innerHTML = '';

      if (!data.changes || data.changes.length === 0) {
        tbody.innerHTML = `<tr><td colspan="3" style="text-align: center; color: var(--color-text-muted);">No modifications needed. Original items were already compliant.</td></tr>`;
      } else {
        data.changes.forEach(c => {
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td>
              <span class="change-badge change-badge-before">Original</span>
              <div style="font-size: 0.85rem; color: var(--color-text-secondary);">${escapeHTML(c.original_point)}</div>
            </td>
            <td>
              <span class="change-badge change-badge-after">Optimized</span>
              <div style="font-size: 0.85rem; color: var(--color-text-primary); font-weight: 500;">${escapeHTML(c.optimized_point)}</div>
            </td>
            <td style="font-size: 0.82rem; color: var(--color-text-muted); font-style: italic;">
              ${escapeHTML(c.reasoning)}
            </td>
          `;
          tbody.appendChild(tr);
        });
      }
    }

    // Tab switcher in step 4
    window.switchOptTab = function(tabName) {
      document.querySelectorAll('.tab-trigger').forEach(el => el.classList.remove('active'));
      document.querySelectorAll('.tab-pane').forEach(el => el.classList.remove('active'));

      event.target.classList.add('active');
      
      if (tabName === 'opt-resume') {
        document.getElementById('pane-opt-resume').classList.add('active');
      } else if (tabName === 'opt-keywords') {
        document.getElementById('pane-opt-keywords').classList.add('active');
      } else if (tabName === 'opt-changes') {
        document.getElementById('pane-opt-changes').classList.add('active');
      }
    };

    // Copy to clipboard
    document.getElementById('btn-copy-clipboard').addEventListener('click', () => {
      const text = document.getElementById('rewritten-resume-text').textContent;
      navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById('btn-copy-clipboard');
        btn.textContent = 'Copied!';
        btn.style.borderColor = 'var(--color-emerald)';
        btn.style.color = 'var(--color-emerald)';
        setTimeout(() => {
          btn.textContent = 'Copy to Clipboard';
          btn.style.borderColor = '';
          btn.style.color = '';
        }, 2000);
      });
    });

    // Save optimized resume to profile
    document.getElementById('btn-save-profile').addEventListener('click', async () => {
      const btn = document.getElementById('btn-save-profile');
      btn.disabled = true;
      btn.textContent = 'Saving...';

      try {
        let bodyStr = 'ajax_action=optimizer_save_profile&optimized_markdown=' + encodeURIComponent(state.finalResult.rewritten_resume_markdown);
        if (state.profileId) {
            bodyStr += '&profile_id=' + encodeURIComponent(state.profileId);
        }
        
        const response = await fetch('resume_optimizer.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: bodyStr
        });
        const result = await response.json();
        
        if (result.success) {
          btn.textContent = 'Saved!';
          btn.style.background = 'var(--color-emerald)';
          setTimeout(() => {
            window.location.href = 'index.php?success=' + encodeURIComponent('Optimized resume added to your profile successfully.');
          }, 1500);
        } else {
          btn.disabled = false;
          btn.textContent = 'Save to Profile';
          alert('Save failed: ' + (result.message || 'Unknown error'));
        }
      } catch (err) {
        btn.disabled = false;
        btn.textContent = 'Save to Profile';
        alert('API error: ' + err.message);
      }
    });

    // Step 1: Target Choice handlers
    const btnRcYes = document.getElementById('btn-rc-yes');
    const btnRcNo = document.getElementById('btn-rc-no');
    const jdSection = document.getElementById('jd-customization-section');

    btnRcYes.addEventListener('click', () => {
      btnRcYes.style.background = 'rgba(16, 185, 129, 0.08)';
      btnRcYes.style.borderColor = 'var(--color-emerald)';
      btnRcNo.style.background = '';
      btnRcNo.style.borderColor = '';
      jdSection.style.display = 'none';
      jdSection.classList.remove('active');
      actionNext.disabled = false;
    });

    btnRcNo.addEventListener('click', () => {
      btnRcNo.style.background = 'rgba(79, 70, 229, 0.05)';
      btnRcNo.style.borderColor = 'var(--color-indigo)';
      btnRcYes.style.background = '';
      btnRcYes.style.borderColor = '';
      jdSection.style.display = 'flex';
      jdSection.classList.add('active');
      
      const title = document.getElementById('target-role-input').value.trim();
      const jd = document.getElementById('job-desc-input').value.trim();
      actionNext.disabled = (title === '' || jd === '');
    });

    document.getElementById('target-role-input').addEventListener('input', checkJdFormValidity);
    document.getElementById('job-desc-input').addEventListener('input', checkJdFormValidity);

    function checkJdFormValidity() {
      if (!jdSection.classList.contains('active')) return;
      const title = document.getElementById('target-role-input').value.trim();
      const jd = document.getElementById('job-desc-input').value.trim();
      actionNext.disabled = (title === '' || jd === '');
    }

    // Step transitions
    actionNext.addEventListener('click', () => {
      if (state.currentStep === 1) {
        runGapAnalysis();
      } else if (state.currentStep === 2) {
        loadDateTimeline();
      } else if (state.currentStep === 3) {
        runFinalOptimization();
      }
    });

    actionPrev.addEventListener('click', () => {
      if (state.currentStep > 1) {
        state.currentStep--;
        updateStepperUI();
      }
    });

    // Helper functions
    function escapeHTML(str) {
      if (!str) return '';
      return str.replace(/[&<>'"]/g, 
        tag => ({
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          "'": '&#39;',
          '"': '&quot;'
        }[tag] || tag)
      );
    }

    function markdownToHTML(md) {
      if (!md) return '';
      return md
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.*?)\*/g, '<em>$1</em>')
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\n/g, '<br>');
    }

    // INITIALIZATION RUNTIME
    window.addEventListener('DOMContentLoaded', async () => {
      showLoading('Loading and extracting resume text...');
      try {
        const response = await fetch('resume_optimizer.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'ajax_action=optimizer_init&resume_path=' + encodeURIComponent(state.resumePath) + (state.profileId ? '&profile_id=' + encodeURIComponent(state.profileId) : '')
        });
        const result = await response.json();
        
        if (result.success && result.resume_text) {
          state.resumeText = result.resume_text;
          await loadRealityCheck();
        } else {
          hideLoading();
          alert('Extraction failed: ' + (result.message || 'Could not read resume text.'));
          window.location.href = 'index.php';
        }
      } catch (err) {
        hideLoading();
        alert('Network connection error: ' + err.message);
        window.location.href = 'index.php';
      }
    });
  </script>

</body>
</html>
