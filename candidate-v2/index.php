<?php
// candidate-v2/index.php - Candidate Dashboard V2
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

// Enforce Candidate role
requireAuth(['candidate']);
$user = getCurrentUser();

$db = getDB();
$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $user['id']]);
$userFull = $stmt->fetch(PDO::FETCH_ASSOC);

$profiles = getCandidateProfiles($user['id']);

// Fetch active public interview links with company names
$publicLinksStmt = $db->prepare("
    SELECT il.*, c.name as company_name, c.logo_url
    FROM interview_links il
    JOIN companies c ON il.company_id = c.id
    WHERE il.status = 'active'
      AND il.is_public = TRUE
      AND (il.expires_at IS NULL OR il.expires_at > CURRENT_TIMESTAMP)
    ORDER BY il.created_at DESC
");
$publicLinksStmt->execute();
$publicLinks = $publicLinksStmt->fetchAll(PDO::FETCH_ASSOC);

// Retroactively backfill detected_role for profiles missing the key in JSON
$profilesUpdated = false;
foreach ($profiles as $idx => $profile) {
    if (!empty($profile['optimized_resume_path'])) {
        $profileResumeData = !empty($profile['resume_data']) ? json_decode($profile['resume_data'], true) : [];
        if (!isset($profileResumeData['detected_role'])) {
            try {
                require_once __DIR__ . '/../ai_service.php';
                $fullPath = __DIR__ . '/../' . $profile['optimized_resume_path'];
                if (file_exists($fullPath)) {
                    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
                    $model = $userFull['model_chat_task'] ?? 'gemini-3.5-flash';
                    $apiKey = $userFull['custom_gemini_api_key'] ?? null;
                    
                    $verification = verifyUploadedResume($fullPath, $ext, $user['full_name'], $model, $apiKey);
                    if ($verification && !isset($verification['error'])) {
                        $detectedRole = $verification['detected_role'] ?? 'Resume';
                        $profileChanges = $profileResumeData['optimization_changes'] ?? null;
                        
                        updateCandidateProfileResume(
                            $profile['id'],
                            $user['id'],
                            $profile['optimized_resume_path'],
                            $profile['text_version'] ?? null,
                            !empty($profile['needs_human_review']),
                            $profileChanges,
                            $detectedRole
                        );
                        $profilesUpdated = true;
                    }
                }
            } catch (Exception $e) {
                // Fail silently
            }
        }
    }
}
if ($profilesUpdated) {
    $profiles = getCandidateProfiles($user['id']);
}

$maxProfiles = 3;
$canAddProfile = count($profiles) < $maxProfiles;

// Get initials for avatar placeholder
$words = explode(" ", $user['full_name']);
$initials = "";
foreach ($words as $w) {
    if (!empty($w)) $initials .= strtoupper($w[0]);
}
$initials = substr($initials, 0, 2);
$firstName = !empty($words[0]) ? $words[0] : 'Candidate';
$profileCount = count($profiles);

$submissionHistory = listCandidateHistory($user['id']);

$resumes = getCandidateResumes($userFull['resume_path'] ?? '');
$baseResume = null;
foreach ($resumes as $r) {
    if (!empty($r['is_base'])) {
        $baseResume = $r;
        break;
    }
}
if (!$baseResume && !empty($resumes)) {
    $baseResume = $resumes[0];
    $baseResume['is_base'] = true;
}

$baseResumeHasOptimized = false;
$baseResumeOptPath = '';
if ($baseResume) {
    if (!empty($baseResume['optimization_changes'])) {
        $baseResumeHasOptimized = true;
        $baseResumeOptPath = $baseResume['path'];
    } else {
        foreach ($resumes as $r) {
            if (!empty($r['optimization_changes'])) {
                if ((!empty($r['original_path']) && $r['original_path'] === $baseResume['path']) ||
                    (empty($r['original_path']) && $baseResume['date'] <= $r['date'])) {
                    $baseResumeHasOptimized = true;
                    $baseResumeOptPath = $r['path'];
                    break;
                }
            }
        }
    }
}

$levelNames = [
    0 => "Novice",
    1 => "Terminology",
    2 => "Mechanics",
    3 => "Implementation",
    4 => "Analysis",
    5 => "Troubleshooting",
    6 => "Integration",
    7 => "Optimization",
    8 => "Security",
    9 => "Governance",
    10 => "Strategic Leadership"
];

$levelDescriptions = [
    0 => "Initial level. Upload your resume and optimize it to start practicing.",
    1 => "Terminology & Recall: Baseline technical vocabulary and definitions.",
    2 => "Basic Mechanics: Understanding the underlying inner workings.",
    3 => "Standard Implementation: Basic execution and common coding patterns.",
    4 => "Comparative Analysis: Trade-offs and choosing the right tool.",
    5 => "Troubleshooting: Debugging, profiling, and root-cause analysis.",
    6 => "Component Integration: State coordination and API boundaries.",
    7 => "Scale & Optimization: Concurrency, caching, and heavy workloads.",
    8 => "Security & Constraints: Compliance, access control, and trust logic.",
    9 => "System Governance: CI/CD architecture and development velocity.",
    10 => "Strategic Leadership: Business alignment and architectural strategy."
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Candidate Hub - TruInterview</title>
  <link rel="stylesheet" href="../assets/css/candidate-v2.css">
  
  <script>
    window.CANDIDATE_USER_NAME = <?php echo json_encode($user['full_name'] ?? ''); ?>;
  </script>
</head>
<body>

  <!-- Header -->
  <header class="v2-header">
    <div class="v2-header-inner">
      <a href="index.php" class="brand-wrapper">
        <svg style="width: 28px; height: 28px; color: var(--color-brand-primary);" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
        </svg>
        <span class="brand-title">TruInterview</span>
      </a>
      
      <div class="user-nav">
        <!-- OPTION C: Header Action Button -->
        <button id="btn-open-join-modal" class="btn-header-join" title="Join Interview">
          <svg style="width: 16px; height: 16px; color: currentColor;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
          <span class="join-btn-text">Join Interview</span>
        </button>
        <!-- END OPTION C -->

        <button id="btn-open-settings-modal" class="btn btn-outline-header" style="padding: 6px; border: none; background: transparent;" title="Settings">
          <svg style="width: 22px; height: 22px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
        </button>
        <div class="avatar-circle"><?php echo htmlspecialchars($initials); ?></div>
        <a href="../logout.php" class="btn btn-outline-header" style="padding: 6px 12px; font-size: 0.8rem;">Log Out</a>
      </div>
    </div>
  </header>

  <!-- Hero Section -->
  <section class="v2-hero">
    <div class="v2-hero-inner">
      <h1 class="title-main">Hi, <?php echo htmlspecialchars($firstName); ?>. Let's get you hired.</h1>
      <p class="subtitle-main">
        <?php if ($profileCount === 0): ?>
          Your interview prep starts here. Create your first role profile so you can get an AI-tailored resume and start practicing live mock interviews.
        <?php elseif ($profileCount === 1): ?>
          You're on the board. You can add 2 more distinct roles. Upload a resume to get it optimized, then jump into a practice interview to sharpen your pitch.
        <?php elseif ($profileCount === 2): ?>
          You're building your range with room for 1 more role. Keep refining your resumes and practicing so you can walk into your real interviews completely prepared.
        <?php else: ?>
          Your target roles are locked in. Focus on perfecting your optimized resumes and mastering your mock interviews for these 3 positions.
        <?php endif; ?>
      </p>
      
      <div class="bento-grid">
        
        <?php foreach ($profiles as $idx => $profile): ?>
          <div class="bento-card" data-profile-id="<?php echo $profile['id']; ?>">
            <?php
            $hasResume = !empty($profile['optimized_resume_path']);
            $isOptimized = false;
            $profileChanges = null;
            $profileResumeData = [];
            if ($hasResume) {
                $profileResumeData = !empty($profile['resume_data']) ? json_decode($profile['resume_data'], true) : [];
                $profileChanges = $profileResumeData['optimization_changes'] ?? null;
                $isOptimized = !empty($profileChanges);
            }
            ?>
            <div class="card-header" style="margin-bottom: var(--space-2);">
              <div class="role-title" title="<?php echo htmlspecialchars($profile['role_title']); ?>"><?php echo htmlspecialchars($profile['role_title']); ?></div>
              <div style="display: flex; align-items: center; gap: var(--space-2); flex-shrink: 0;">
                <div class="card-badge">Profile <?php echo $idx + 1; ?></div>
                <button class="btn-delete-profile btn-danger-ghost" data-id="<?php echo $profile['id']; ?>" style="border: none; cursor: pointer; padding: 4px; border-radius: 4px; display: flex; align-items: center; justify-content: center; background: transparent; color: var(--color-text-muted); transition: color 0.2s;" onmouseover="this.style.color='var(--color-danger)'" onmouseout="this.style.color='var(--color-text-muted)'" title="Delete Profile">
                  <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                </button>
              </div>
            </div>

            <?php if (!empty($profile['category_name'])): ?>
              <div style="margin-bottom: var(--space-4); display: flex;">
                <div class="category-badge premium-tooltip-trigger" data-tooltip="<?php echo htmlspecialchars($profile['category_description'] ?? 'No description available'); ?>">
                  <span class="category-name"><?php echo htmlspecialchars($profile['category_name']); ?></span>
                  <?php if (isset($profile['category_match_percentage']) && $profile['category_match_percentage'] !== ''): ?>
                    <span class="category-divider"></span>
                    <span class="category-match"><?php echo htmlspecialchars($profile['category_match_percentage']); ?>% Match</span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endif; ?>

            <?php
            $profileLevel = (int)($profile['level'] ?? 0);
            $levelTooltip = $levelDescriptions[$profileLevel];
            if ($profileLevel === 0) {
                if ($hasResume && $isOptimized) {
                    $levelTooltip = "Resume optimized! Take Level 1 (Score 60%+ to pass).";
                } else {
                    $levelTooltip = "Initial level. Upload and optimize your resume to unlock Level 1.";
                }
            } else if ($profileLevel < 10) {
                $levelTooltip = "Level {$profileLevel}: " . $levelDescriptions[$profileLevel] . " Score 60%+ to reach Level " . ($profileLevel + 1) . "!";
            }
            ?>
            <div class="level-container <?php echo $profileLevel === 10 ? 'level-10' : ''; ?> premium-tooltip-trigger" data-tooltip="<?php echo htmlspecialchars($levelTooltip); ?>">
              <div class="level-header">
                <span class="level-title-label">
                  <svg style="width: 13px; height: 13px;" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                  </svg>
                  Level <?php echo $profileLevel; ?>/10
                </span>
                <span class="level-name"><?php echo htmlspecialchars($levelNames[$profileLevel]); ?></span>
              </div>
              <div class="level-bar-pips">
                <?php for ($i = 1; $i <= 10; $i++): ?>
                  <span class="level-pip <?php echo $i <= $profileLevel ? 'active' : ''; ?>" title="Level <?php echo $i; ?>: <?php echo htmlspecialchars($levelNames[$i]); ?>"></span>
                <?php endfor; ?>
              </div>
            </div>

            <?php if (!$isOptimized): 
              if (!$hasResume) {
                  $progressPercent = 15;
                  $progressText = "Step 1/2: Upload base resume to unlock optimizations";
                  $progressClass = "step-upload";
              } else {
                  $progressPercent = 50;
                  $progressText = "Step 2/2: Optimize resume to unlock practice interview";
                  $progressClass = "step-optimize";
              }
            ?>
              <div class="profile-progress-tracker <?php echo $progressClass; ?>">
                <div class="progress-info">
                  <span class="progress-label"><?php echo htmlspecialchars($progressText); ?></span>
                  <span class="progress-percentage"><?php echo $progressPercent; ?>%</span>
                </div>
                <div class="progress-track-wrapper">
                  <div class="progress-track-bar">
                    <div class="progress-track-fill" style="width: <?php echo $progressPercent; ?>%;"></div>
                  </div>
                  <div class="progress-steps-nodes">
                    <div class="progress-node node-upload <?php echo $hasResume ? 'completed' : 'active'; ?>" title="Upload Resume">
                      <span class="node-dot"></span>
                      <span class="node-text">Upload</span>
                    </div>
                    <div class="progress-node node-optimize <?php echo $isOptimized ? 'completed' : ($hasResume ? 'active' : 'upcoming'); ?>" title="Optimize Resume">
                      <span class="node-dot"></span>
                      <span class="node-text">Optimize</span>
                    </div>
                    <div class="progress-node node-ready <?php echo $isOptimized ? 'completed' : 'upcoming'; ?>" title="Ready to Practice">
                      <span class="node-dot"></span>
                      <span class="node-text">Ready</span>
                    </div>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <div class="card-body">
              <div class="status-item">
                <?php 
                // Variables are already defined above
                ?>
                <?php if ($hasResume && $isOptimized): 
                  $displayRole = !empty($profileResumeData['detected_role']) ? $profileResumeData['detected_role'] : 'Optimized Resume';
                ?>
                  <svg class="status-icon status-success" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                  </svg>
                  <div>
                    <strong><?php echo htmlspecialchars($displayRole); ?></strong>
                    <div style="font-size: 0.75rem; margin-top: 2px; display: flex; gap: 6px; align-items: center;">
                      <a href="optimized_resume_viewer.php?path=<?php echo urlencode($profile['optimized_resume_path']); ?>" target="_blank" style="color: var(--color-brand-primary); text-decoration: none;">View Optimized</a>
                      <?php if (!empty($profileChanges)): ?>
                        <span style="color: var(--color-text-muted);">•</span>
                        <a href="#" class="view-rationale-trigger" data-changes="<?php echo htmlspecialchars(json_encode($profileChanges)); ?>" style="color: var(--color-brand-primary); text-decoration: none;">View AI Rationale</a>
                      <?php endif; ?>
                    </div>
                    <?php if ($profileLevel === 10): ?>
                      <div style="margin-top: var(--space-2); font-size: 0.78rem; color: #d97706; font-weight: 600; display: flex; align-items: center; gap: 4px;">
                        <svg style="width: 14px; height: 14px; flex-shrink: 0;" fill="currentColor" viewBox="0 0 20 20">
                          <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                        </svg>
                        <span>Ultimate mastery achieved!</span>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php elseif ($hasResume && !$isOptimized): 
                  $displayRole = !empty($profileResumeData['detected_role']) ? $profileResumeData['detected_role'] : 'Resume';
                ?>
                  <svg class="status-icon status-pending" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                  </svg>
                  <div>
                    <strong><?php echo htmlspecialchars($displayRole); ?></strong>
                    <div style="font-size: 0.75rem; margin-top: 2px; display: flex; gap: 6px; align-items: center;">
                      <a href="resume_viewer.php?path=<?php echo urlencode($profile['optimized_resume_path']); ?>" target="_blank" style="color: var(--color-brand-primary); text-decoration: none;">View Document</a>
                    </div>
                  </div>
                <?php else: ?>
                  <svg class="status-icon status-pending" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                  </svg>
                  <div>
                    <strong>No Resume Uploaded</strong>
                    <div style="font-size: 0.75rem; margin-top: 2px;">Upload a base resume to optimize for this role.</div>
                  </div>
                <?php endif; ?>
              </div>


            </div>

            <div class="card-actions">
              <?php if ($hasResume && $isOptimized): ?>
                <?php if ($profileLevel < 10): ?>
                  <button class="btn btn-primary btn-prepare-practice" data-profile-id="<?php echo htmlspecialchars($profile['id']); ?>" style="flex: 1;">
                    <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    Take Level <?php echo $profileLevel + 1; ?>
                  </button>
                <?php else: ?>
                  <button class="btn btn-outline" style="flex: 1; opacity: 0.65; cursor: not-allowed; gap: 6px;" disabled>
                    <svg style="width: 16px; height: 16px; color: var(--color-success);" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    All Levels Completed
                  </button>
                <?php endif; ?>
              <?php elseif ($hasResume && !$isOptimized): ?>
                <a href="resume_optimizer.php?resume_path=<?php echo urlencode($profile['optimized_resume_path']); ?>&profile_id=<?php echo urlencode($profile['id']); ?>" 
                  class="btn btn-outline" 
                  style="flex: 1; text-align: center; padding: 10px 0; display: flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none;">
                  <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                  Optimize Resume
                </a>
              <?php else: ?>
                <button class="btn btn-outline btn-choose-resume" 
                  data-profile-id="<?php echo $profile['id']; ?>"
                  data-has-base="<?php echo $baseResume ? '1' : '0'; ?>"
                  data-base-path="<?php echo $baseResume ? htmlspecialchars($baseResume['path']) : ''; ?>"
                  data-has-optimized="<?php echo $baseResumeHasOptimized ? '1' : '0'; ?>"
                  data-opt-path="<?php echo htmlspecialchars($baseResumeOptPath); ?>"
                  style="flex: 1; text-align: center; padding: 10px 0;">
                  <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                  Upload Resume
                </button>
              <?php endif; ?>
            </div>
            <?php if ($hasResume && $isOptimized && $profileLevel < 10): ?>
              <div class="pass-hint tech-mono" style="margin-top: var(--space-2); font-size: 10px; text-align: center; font-weight: 500; display: flex; flex-direction: column; gap: 2px;">
                <div>Questions: 4 open / 4 MCQ</div>
                <div>Score 60% or higher to pass.</div>
              </div>
            <?php endif; ?>

          </div>
        <?php endforeach; ?>

        <?php if ($canAddProfile): ?>
          <div class="bento-card card-add" id="btn-open-create-modal">
            <svg class="add-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"></path>
            </svg>
            <div style="font-weight: 600; color: var(--color-text-primary); font-size: var(--text-lg);">Add New Role</div>
            <div style="font-size: var(--text-sm); color: var(--color-text-muted); margin-top: 4px;">You can add <?php echo $maxProfiles - count($profiles); ?> more profile(s)</div>
          </div>
        <?php endif; ?>
        
      </div>
    </div>
  </section>

  <!-- Main Content Layout -->
  <div class="v2-layout">
    <main>

      <!-- MATCHED PUBLIC ASSESSMENTS SECTION -->
      <?php
      // Compile matched public assessments across all profiles
      $allMatchedAssessments = [];
      foreach ($publicLinks as $pLink) {
          $matchedProfilesForThisLink = [];
          foreach ($profiles as $profile) {
              $profileSlug = preg_replace('/\s+/', '-', strtolower(trim($profile['role_title'])));
              $profileSlug = preg_replace('/[^a-zA-Z0-9\-]/', '', $profileSlug);
              $profileSlug = preg_replace('/-+/', '-', $profileSlug);
              $profileSlug = trim($profileSlug, '-');

              $isCategoryMatch = (!empty($profile['category_id']) && !empty($pLink['category_id']) && $profile['category_id'] === $pLink['category_id']);
              
              $pLinkSlug = preg_replace('/\s+/', '-', strtolower(trim($pLink['job_role'])));
              $pLinkSlug = preg_replace('/[^a-zA-Z0-9\-]/', '', $pLinkSlug);
              $pLinkSlug = preg_replace('/-+/', '-', $pLinkSlug);
              $pLinkSlug = trim($pLinkSlug, '-');
              
              $isRoleMatch = ($profileSlug === $pLinkSlug);

              if ($isCategoryMatch || $isRoleMatch) {
                  $matchedProfilesForThisLink[] = $profile;
              }
          }

          if (!empty($matchedProfilesForThisLink)) {
              $allMatchedAssessments[] = [
                  'link' => $pLink,
                  'profiles' => $matchedProfilesForThisLink
              ];
          }
      }
      ?>

      <?php if (!empty($allMatchedAssessments)): ?>
      <section class="matched-assessments-section" style="margin-top: var(--space-14); margin-bottom: var(--space-14);">
        <div class="resume-section-header" style="margin-bottom: var(--space-5);">
          <h2 class="resume-section-title">Open Interviews Found Matched To Your Interest</h2>
          <p class="resume-section-subtitle">We tailored these open interviews matching your active profiles. Launch a session to answer their questions.</p>
        </div>

        <div style="margin: 0 var(--space-6); display: flex; flex-direction: column; gap: var(--space-3);">
          <?php foreach ($allMatchedAssessments as $item): 
            $ma = $item['link'];
            $maLevelVal = (int)($ma['min_level'] ?? 0);
            $maLevelName = $levelNames[$maLevelVal] ?? "Novice";
            
            $matchedTitles = [];
            foreach ($item['profiles'] as $mp) {
                $matchedTitles[] = $mp['role_title'];
            }
            $matchedProfilesText = implode(", ", $matchedTitles);
            $targetProfileId = $item['profiles'][0]['id'];
          ?>
            <div class="assessment-bar">
              
              <!-- Left Side: Logo & Main Info -->
              <div style="display: flex; gap: var(--space-4); align-items: center; min-width: 0; flex: 1;">
                <?php if (!empty($ma['logo_url'])): ?>
                  <img src="<?php echo htmlspecialchars($ma['logo_url']); ?>" alt="<?php echo htmlspecialchars($ma['company_name']); ?>" style="width: 42px; height: 42px; border-radius: 8px; object-fit: cover; border: 1px solid var(--color-border); flex-shrink: 0;">
                <?php else: ?>
                  <div style="width: 42px; height: 42px; border-radius: 8px; background: var(--color-brand-light); color: var(--color-brand-primary); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; font-weight: 700; border: 1px solid var(--color-brand-light); flex-shrink: 0;">
                    <?php echo htmlspecialchars(strtoupper(substr($ma['company_name'], 0, 1))); ?>
                  </div>
                <?php endif; ?>
                
                <div style="min-width: 0;">
                  <div style="display: flex; align-items: center; gap: var(--space-2); flex-wrap: wrap; margin-bottom: 2px;">
                    <span style="font-weight: 700; font-size: var(--text-sm); color: var(--color-text-primary);"><?php echo htmlspecialchars($ma['job_role']); ?></span>
                    <span style="font-size: 0.72rem; color: var(--color-text-muted);">at</span>
                    <span style="font-weight: 600; font-size: var(--text-sm); color: var(--color-text-secondary);"><?php echo htmlspecialchars($ma['company_name']); ?></span>
                  </div>
                  
                  <?php if (!empty($ma['job_description'])): ?>
                    <div style="font-size: 0.75rem; color: var(--color-text-secondary); line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; margin-bottom: 4px;" title="<?php echo htmlspecialchars($ma['job_description']); ?>">
                      <?php echo htmlspecialchars($ma['job_description']); ?>
                    </div>
                  <?php endif; ?>

                  <div style="font-size: 0.7rem; color: var(--color-brand-primary); font-weight: 500; display: flex; align-items: center; gap: 4px;">
                    <svg style="width: 12px; height: 12px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                    <span>Matches profile: <?php echo htmlspecialchars($matchedProfilesText); ?></span>
                  </div>
                </div>
              </div>

              <!-- Middle: Metadata Badges -->
              <div style="display: flex; align-items: center; gap: var(--space-3); flex-shrink: 0; font-size: 0.72rem; color: var(--color-text-secondary);">
                <!-- Level difficulty info -->
                <div style="background: var(--color-bg-subtle); border: 1px solid var(--color-border); padding: 4px 10px; border-radius: 6px; display: flex; align-items: center; gap: 6px; font-weight: 600;">
                  <span style="color: var(--color-brand-primary);">⚡</span>
                  <span><?php echo htmlspecialchars($maLevelName); ?> (<span class="tech-mono">Level <?php echo $maLevelVal; ?></span>)</span>
                </div>

                <!-- Attempts Remaining -->
                <div style="background: var(--color-bg-subtle); border: 1px solid var(--color-border); padding: 4px 10px; border-radius: 6px; display: flex; align-items: center; gap: 6px; font-weight: 600;">
                  <span style="color: var(--color-text-muted);">🔄</span>
                  <span>Attempts: <span class="tech-mono"><?php echo (int)($ma['attempts_used'] ?? 0); ?>/<?php echo (int)($ma['max_attempts'] ?? 1); ?></span></span>
                </div>
                
                <!-- Questions Count -->
                <div style="background: var(--color-bg-subtle); border: 1px solid var(--color-border); padding: 4px 10px; border-radius: 6px; display: flex; align-items: center; gap: 6px; font-weight: 600;">
                  <span style="color: var(--color-brand-primary);">❓</span>
                  <span>Questions: <span class="tech-mono"><?php echo isset($ma['num_open_questions']) ? (int)$ma['num_open_questions'] : 4; ?> open / <?php echo isset($ma['num_mcq_questions']) ? (int)$ma['num_mcq_questions'] : 4; ?> MCQ</span></span>
                </div>
                
                <!-- Expiration Date -->
                <?php if (!empty($ma['expires_at'])): 
                  $expiryTime = strtotime($ma['expires_at']);
                  $expiryFormatted = date('M d, Y', $expiryTime);
                ?>
                  <div style="background: var(--color-bg-subtle); border: 1px solid var(--color-border); padding: 4px 10px; border-radius: 6px; display: flex; align-items: center; gap: 6px; font-weight: 600;">
                    <span style="color: var(--color-text-muted);">📅</span>
                    <span>Expires: <span class="tech-mono"><?php echo $expiryFormatted; ?></span></span>
                  </div>
                <?php endif; ?>

                <!-- Category Match Percentage -->
                <?php if (isset($ma['category_match_percentage']) && $ma['category_match_percentage'] > 0): ?>
                  <div class="tech-mono" style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.15); padding: 4px 10px; border-radius: 6px; display: flex; align-items: center; gap: 4px; font-weight: 700; color: var(--color-success);">
                    <span><?php echo htmlspecialchars($ma['category_match_percentage']); ?>% Match</span>
                  </div>
                <?php endif; ?>
              </div>

              <!-- Right Side: Action Button -->
              <div style="flex-shrink: 0;">
                <button class="btn btn-primary btn-prepare-assessment" data-profile-id="<?php echo htmlspecialchars($targetProfileId); ?>" data-code="<?php echo htmlspecialchars($ma['code']); ?>" style="padding: 8px 16px; font-size: 0.8rem; border-radius: var(--radius-inner); font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 4px; border: none; height: 38px;">
                  Take Interview &rarr;
                </button>
              </div>

            </div>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <!-- RESUME MANAGEMENT SECTION -->
      <section class="resume-section">
        <div class="resume-section-header">
          <h2 class="resume-section-title">Resume Management</h2>
          <p class="resume-section-subtitle">Select or upload your base resume so that our AI can optimize your range for targeted profiles.</p>
        </div>

        <div class="resume-grid">
          <!-- Left Panel: Base Resume Details -->
          <div class="resume-base-card">
            <div class="resume-base-header">
              <span class="resume-base-title">Selected Base Resume</span>
              <?php if ($baseResume): ?>
                <span class="resume-badge-base">Active</span>
              <?php endif; ?>
            </div>

            <div class="resume-base-body">
              <?php if ($baseResume): 
                $ext = strtoupper(pathinfo($baseResume['path'], PATHINFO_EXTENSION));
                $isBaseOpt = !empty($baseResume['optimization_changes']);
                $displayRole = !empty($baseResume['detected_role']) ? $baseResume['detected_role'] : 'Resume';
                
                if ($isBaseOpt) {
                    $origName = '';
                    if (!empty($baseResume['original_path'])) {
                        foreach ($resumes as $orig) {
                            if ($orig['path'] === $baseResume['original_path']) {
                                $origName = $orig['detected_role'] ?? '';
                                break;
                            }
                        }
                    }
                    if (empty($origName)) {
                        foreach ($resumes as $orig) {
                            if (empty($orig['optimization_changes'])) {
                                $origName = $orig['detected_role'] ?? '';
                                break;
                            }
                        }
                    }
                    if (!empty($origName)) {
                        $displayRole = $origName;
                    }
                }
              ?>
                <div>
                  <div style="font-weight: 700; font-size: var(--text-sm); color: var(--color-text-primary); display: flex; align-items: center; gap: 8px;">
                    <svg style="width: 18px; height: 18px; color: var(--color-brand-primary);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    <?php if ($isBaseOpt): ?>
                      <span class="resume-badge-base" style="font-size: 8px; padding: 1px 4px; background: rgba(79, 70, 229, 0.1); color: var(--color-brand-primary); text-transform: uppercase;">Optimized</span>
                    <?php endif; ?>
                    <span><?php echo htmlspecialchars($displayRole); ?></span>
                    <span class="tech-mono" style="font-size: 10px; padding: 2px 6px; border-radius: 4px; background: var(--color-bg-subtle); color: var(--color-text-secondary); font-weight: 600; text-transform: uppercase;"><?php echo htmlspecialchars($ext); ?></span>
                  </div>
                  <div style="font-size: var(--text-xs); color: var(--color-text-muted); margin-top: 4px;">
                    Uploaded on <span class="tech-mono"><?php echo date('M d, Y h:i A', $baseResume['date']); ?></span>
                  </div>
                </div>

                <div class="resume-summary-box">
                  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <div style="font-weight: 700; font-size: 11px; text-transform: uppercase; color: var(--color-text-secondary); letter-spacing: 0.05em;">AI Profile Analysis</div>
                    <?php if (!empty($baseResume['optimization_changes'])): ?>
                      <a href="#" class="view-rationale-trigger" data-changes="<?php echo htmlspecialchars(json_encode($baseResume['optimization_changes'])); ?>" style="font-size: 11px; color: var(--color-brand-primary); text-decoration: none; font-weight: 600;">View AI Rationale &rarr;</a>
                    <?php endif; ?>
                  </div>
                  <div><?php echo htmlspecialchars($baseResume['short_description'] ?? 'No description parsed yet.'); ?></div>
                </div>
                <div style="display: flex; gap: var(--space-3); margin-top: auto; padding-top: var(--space-4);">
                  <?php 
                    $hasOptimized = $baseResumeHasOptimized;
                    $optResumePath = $baseResumeOptPath;
                  ?>
                  <?php if ($hasOptimized): ?>
                    <a href="optimized_resume_viewer.php?path=<?php echo urlencode($optResumePath); ?>" class="btn btn-primary" style="flex: 1; background-color: var(--color-success); border-color: var(--color-success);">
                      <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                      View Optimized
                    </a>
                  <?php else: ?>
                    <a href="resume_optimizer.php?resume_path=<?php echo urlencode($baseResume['path']); ?>" class="btn btn-primary" style="flex: 1;">
                      <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                      Optimize Base
                    </a>
                  <?php endif; ?>
                  <a href="resume_viewer.php?path=<?php echo urlencode($baseResume['path']); ?>" class="btn btn-outline" style="flex: 1;">
                    <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    View Document
                  </a>
                </div>
              <?php else: ?>
                <div style="text-align: center; padding: var(--space-8) var(--space-4); color: var(--color-text-muted); display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%;">
                  <svg style="width: 48px; height: 48px; color: var(--color-text-muted); margin-bottom: var(--space-3);" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                  </svg>
                  <div style="font-weight: 600; color: var(--color-text-secondary);">No Base Resume Selected</div>
                  <p style="font-size: var(--text-xs); margin-top: 4px; max-width: 280px;">Upload a resume to establish your primary profile and activate interview prep.</p>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Right Panel: Resume History & Upload -->
          <div class="resume-history-card">
            <div class="resume-base-header">
              <span class="resume-base-title">Upload & Version History</span>
              <span style="font-size: var(--text-xs); color: var(--color-text-secondary);"><?php echo count($resumes); ?>/5 Resumes</span>
            </div>

            <div class="resume-base-body">
              <?php if (empty($resumes)): ?>
                <div style="text-align: center; padding: var(--space-8) var(--space-4); color: var(--color-text-muted); display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%;">
                  <svg style="width: 32px; height: 32px; color: var(--color-text-muted); margin-bottom: var(--space-2);" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                  <p style="font-size: var(--text-xs); margin: 0;">Upload your first resume in the action box below.</p>
                </div>
              <?php else: ?>
                <div class="resume-list">
                  <?php foreach ($resumes as $idx => $res): 
                    $isResBase = !empty($res['is_base']);
                    $isOptimized = !empty($res['optimization_changes']);
                    $fileName = basename($res['path']);
                    $displayRole = !empty($res['detected_role']) ? $res['detected_role'] : 'Resume';
                    
                    if ($isOptimized) {
                        $origName = '';
                        if (!empty($res['original_path'])) {
                            foreach ($resumes as $orig) {
                                if ($orig['path'] === $res['original_path']) {
                                    $origName = $orig['detected_role'] ?? '';
                                    break;
                                }
                            }
                        }
                        if (empty($origName)) {
                            foreach ($resumes as $orig) {
                                if (empty($orig['optimization_changes'])) {
                                    $origName = $orig['detected_role'] ?? '';
                                    break;
                                }
                            }
                        }
                        if (!empty($origName)) {
                            $displayRole = $origName;
                        }
                    }
                    
                    $ext = strtoupper(pathinfo($res['path'], PATHINFO_EXTENSION));
                  ?>
                    <div class="resume-item <?php echo $isResBase ? 'active' : ''; ?>">
                       <div class="resume-item-info" style="max-width: 60%;">
                        <div class="resume-item-title" style="display: flex; align-items: center; gap: 8px;" title="<?php echo htmlspecialchars($displayRole); ?>">
                          <?php if ($isResBase): ?>
                            <span class="resume-badge-base" style="font-size: 8px; padding: 1px 4px;">Base</span>
                          <?php endif; ?>
                          <?php if ($isOptimized): ?>
                            <span class="resume-badge-base" style="font-size: 8px; padding: 1px 4px; background: rgba(79, 70, 229, 0.1); color: var(--color-brand-primary);">Optimized Resume</span>
                          <?php endif; ?>
                          <span style="white-space: nowrap; text-overflow: ellipsis; overflow: hidden;"><?php echo htmlspecialchars($displayRole); ?></span>
                          <span class="tech-mono" style="font-size: 9px; padding: 1px 4px; border-radius: 3px; background: var(--color-bg-subtle); color: var(--color-text-secondary); font-weight: 600; text-transform: uppercase;"><?php echo htmlspecialchars($ext); ?></span>
                        </div>
                        <div class="resume-item-date">
                          Uploaded <span class="tech-mono"><?php echo date('M d, Y', $res['date']); ?></span>
                        </div>
                      </div>

                      <div class="resume-item-actions">
                        <?php if (!$isResBase): ?>
                          <button class="btn btn-outline btn-set-base" data-path="<?php echo htmlspecialchars($res['path']); ?>" style="padding: 4px 8px; font-size: 11px;" title="Set as base resume">Set Base</button>
                        <?php endif; ?>
                        <a href="resume_viewer.php?path=<?php echo urlencode($res['path']); ?>" class="btn btn-outline" style="padding: 4px; border-radius: 6px;" title="View Resume">
                          <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        </a>
                        <button class="btn btn-outline btn-delete-global-resume" data-path="<?php echo htmlspecialchars($res['path']); ?>" style="padding: 4px; border-radius: 6px; color: var(--color-danger);" title="Delete Resume">
                          <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                        </button>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <?php if (count($resumes) < 5): ?>
                <div style="margin-top: auto; padding-top: var(--space-4);">
                  <label class="btn btn-outline btn-full" style="padding: 10px 0; border-style: dashed; cursor: pointer;">
                    <input type="file" class="hidden-upload" id="global-resume-file-input" accept=".pdf,.doc,.docx,.md" />
                    <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                    Upload New Version
                  </label>
                </div>
              <?php else: ?>
                <div style="margin-top: auto; padding-top: var(--space-4); text-align: center; font-size: var(--text-xs); color: var(--color-text-muted);">
                  Limit of 5 resumes reached. Delete previous versions to upload.
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </section>

      <!-- My Submissions Section -->
      <section class="resume-section" style="margin-top: var(--space-14);">
        <div class="resume-section-header">
          <h2 class="resume-section-title">My Submissions</h2>
          <p class="resume-section-subtitle">Your interview session history. You can delete sessions you no longer need.</p>
        </div>

        <div class="submissions-table-wrapper">
          <?php if (empty($submissionHistory)): ?>
            <div style="text-align: center; padding: var(--space-8) var(--space-4); color: var(--color-text-muted); display: flex; flex-direction: column; align-items: center;">
              <svg style="width: 48px; height: 48px; color: var(--color-text-muted); margin-bottom: var(--space-3);" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
              </svg>
              <div style="font-weight: 600;">No Submissions Yet</div>
              <p style="font-size: var(--text-xs); margin-top: 4px;">Once you complete an interview session, it will appear here.</p>
            </div>
          <?php else: ?>
            <table class="recruiter-table">
              <thead>
                <tr>
                  <th>Role</th>
                  <th style="text-align: center;">Type</th>
                  <th style="text-align: center;">Score</th>
                  <th style="text-align: center;">Status</th>
                  <th style="text-align: center;">Date</th>
                  <th style="text-align: right;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($submissionHistory as $session):
                  $scoreData = !empty($session['final_score']) ? json_decode($session['final_score'], true) : null;
                  $avgScore = null;
                  if ($scoreData) {
                      $comm = (float)($scoreData['communication_score'] ?? 0);
                      $prob = (float)($scoreData['problem_solving_score'] ?? 0);
                      $qual = (float)($scoreData['code_quality_score'] ?? 0);
                      $avgScore = round(($comm + $prob + $qual) / 3.0, 1);
                  }
                  $sessionType = $session['session_type'] ?? 'assessment';
                  $sessionDate = !empty($session['started_at']) ? date('M j, Y', strtotime($session['started_at'])) : '—';
                ?>
                  <?php $isViewable = ($session['current_status'] === 'COMPLETED' && !empty($session['final_score'])); ?>
                  <tr
                    id="submission-row-<?php echo htmlspecialchars($session['id']); ?>"
                    <?php if ($isViewable): ?>
                      onclick="window.location.href='report.php?session_id=<?php echo urlencode($session['id']); ?>'"
                      style="cursor: pointer;"
                    <?php endif; ?>
                  >
                    <td>
                      <div style="font-weight: 700; color: var(--color-text-primary);"><?php echo htmlspecialchars($session['template_title'] ?? 'Unknown Role'); ?></div>
                    </td>
                    <td style="text-align: center;">
                      <span class="card-badge" style="font-size: 0.68rem; padding: 2px 8px; text-transform: capitalize;"><?php echo htmlspecialchars($sessionType); ?></span>
                    </td>
                    <td style="text-align: center; font-weight: 700;">
                      <?php echo $avgScore !== null ? ($avgScore . '/10') : '—'; ?>
                    </td>
                    <td style="text-align: center;">
                      <span class="card-badge" style="font-size: 0.68rem; padding: 2px 6px; text-transform: capitalize;"><?php echo strtolower($session['current_status']); ?></span>
                    </td>
                    <td style="text-align: center; font-size: 0.78rem; color: var(--color-text-secondary);">
                      <?php echo $sessionDate; ?>
                    </td>
                    <td style="text-align: right;" onclick="event.stopPropagation()">
                      <button
                        class="btn btn-outline"
                        style="padding: 6px 12px; font-size: 0.78rem; color: var(--color-danger); border-color: var(--color-danger);"
                        onclick="deleteSubmission('<?php echo htmlspecialchars($session['id']); ?>')"
                      >Delete</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </section>
      <!-- End My Submissions Section -->

      <!-- JOIN INTERVIEW BAR -->
      <div class="join-hero-section" id="join-bottom-bar">
        <div class="join-hero-content">
          <h2>Got an interview code?</h2>
          <p>Enter your 6-digit code to join a live session instantly.</p>
        </div>
        <form class="join-hero-form" onsubmit="event.preventDefault(); joinInterview('input-join-bar');">
          <input type="text" id="input-join-bar" class="join-input" placeholder="e.g. 1A2B3C" autocomplete="off" maxlength="10">
          <button type="submit" class="btn btn-primary" style="white-space: nowrap;">Join Now</button>
        </form>
      </div>
      <!-- END JOIN INTERVIEW BAR -->
    </main>
  </div>


  <?php require_once __DIR__ . '/modals.php'; ?>


  <div class="toast-container" id="toast-container"></div>

  <script src="../assets/js/candidate-v2.js" defer></script>
</body>
</html>
