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

$resumePath = $_GET['resume_path'] ?? '';
if (empty($resumePath)) {
    header("Location: index.php");
    exit;
}

$profileId = $_GET['profile_id'] ?? $_POST['profile_id'] ?? null;
$isProfileMode = !empty($profileId);

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
  <link rel="stylesheet" href="../assets/css/candidate.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/candidate.css'); ?>">
  <link rel="stylesheet" href="../assets/css/resume_optimizer.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/resume_optimizer.css'); ?>">
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
        <div class="panel-title-large" id="loading-status">Analyzing with AI...</div>
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
        <p class="panel-subtitle">The AI has analyzed your resume blindly to deduce how standard Applicant Tracking Systems bucket your profile.</p>

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

        <?php if ($isProfileMode): ?>
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
        <?php else: ?>
        <!-- Base Resume Mode: Informational note only -->
        <div style="background: rgba(79, 70, 229, 0.04); border: 1px solid rgba(79, 70, 229, 0.15); padding: 18px; border-radius: var(--radius-inner); margin-top: 16px; display: flex; align-items: flex-start; gap: 12px;">
          <svg style="width: 20px; height: 20px; color: var(--color-indigo); flex-shrink: 0; margin-top: 2px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
          <div>
            <div style="font-weight: 700; color: var(--color-text-primary); font-size: 0.9rem;">Base Resume Optimization</div>
            <div style="font-size: 0.82rem; color: var(--color-text-secondary); margin-top: 4px; line-height: 1.45;">
              We will perform a general optimization on your base resume for the detected role above. You can customize target job descriptions later when creating specific profiles.
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- STEP 2: GAP ANALYSIS & QUESTIONNAIRE -->
      <div class="panel-step-content" id="step-content-2">
        <h3 class="panel-title-large">Step 2: Gap Analysis & Skill Gathering</h3>
        <p class="panel-subtitle">The AI has compared your resume against the Job Description. Tell us if you have worked with these missing items, and we'll weave them into the final version.</p>

        <div id="gaps-list" style="display: flex; flex-direction: column; gap: 16px;">
          <!-- Loaded dynamically -->
        </div>
      </div>

      <!-- STEP 3: EXPERIENCE MATHEMATICAL TIMELINE -->
      <div class="panel-step-content" id="step-content-3">
        <h3 class="panel-title-large">Step 3: Computational Experience Verification</h3>
        <p class="panel-subtitle">ATS algorithms rank resumes strictly on calculated duration metrics. The AI parsed your employment dates, and we computed them mathematically to prevent overlaps and formatting failures.</p>

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

          <?php if ($isProfileMode): ?>
          <div style="background: #f8fafc; border: 1px solid var(--color-border); padding: 20px; border-radius: var(--radius-inner); display: flex; align-items: center; justify-content: space-between;">
            <div>
              <div style="font-weight: 700; color: var(--color-text-primary); font-size: 0.95rem;">Commit to Profile</div>
              <div style="font-size: 0.8rem; color: var(--color-text-muted); margin-top: 2px;">Replace active resume with this version.</div>
            </div>
            <button class="btn-primary-action" id="btn-save-profile" style="padding: 10px 20px; font-size: 0.85rem; min-width: 140px;">Save to Profile</button>
          </div>
          <?php else: ?>
          <div style="background: #f8fafc; border: 1px solid var(--color-border); padding: 20px; border-radius: var(--radius-inner); display: flex; flex-direction: column; gap: 14px;">
            <div>
              <div style="font-weight: 700; color: var(--color-text-primary); font-size: 0.95rem;">Base Resume Optimized</div>
              <div style="font-size: 0.8rem; color: var(--color-text-muted); margin-top: 2px;">We detected this resume targets <strong id="detected-role-label">this role</strong>. Save your optimized base resume, and optionally set up a role profile to start practicing.</div>
            </div>
            <div style="display: flex; gap: 8px;">
              <button class="btn-secondary-action" id="btn-save-base-only" style="padding: 10px 16px; font-size: 0.85rem; min-width: 100px;">Save Only</button>
              <button class="btn-primary-action" id="btn-save-with-role" style="padding: 10px 16px; font-size: 0.85rem; min-width: 160px;">Save & Create Role Profile</button>
            </div>
          </div>
          <?php endif; ?>
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
    const INITIAL_STATE = {
      resumePath: <?php echo json_encode($resumePath); ?>,
      profileId: <?php echo json_encode($_GET['profile_id'] ?? null); ?>
    };
  </script>
  <script src="../assets/js/resume_optimizer.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/resume_optimizer.js'); ?>"></script>

</body>
</html>
