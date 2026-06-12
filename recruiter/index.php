<?php
// recruiter/index.php - Recruiter Dashboard V2
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

// Enforce Recruiter role
requireAuth(['recruiter']);
$user = getCurrentUser();
$company = getRecruiterCompany($user['id']);

if (!$company) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>No Company Found - TruInterview</title>
      <link rel="stylesheet" href="../assets/css/recruiter.css">
    </head>
    <body style="display: flex; align-items: center; justify-content: center; min-height: 100vh; background: var(--color-bg-base);">
      <div class="bento-card" style="max-width: 450px; text-align: center; align-items: center; padding: var(--space-6);">
        <svg style="width: 48px; height: 48px; color: var(--color-danger); margin-bottom: var(--space-4);" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
        </svg>
        <h2 style="margin-bottom: var(--space-2); font-family: 'Outfit', sans-serif;">Access Denied</h2>
        <p style="color: var(--color-text-secondary); font-size: var(--text-sm); line-height: 1.6; margin-bottom: var(--space-5);">No company profile was found associated with your recruiter account. Please contact system administration to be assigned to a company.</p>
        <a href="../logout.php" class="btn btn-primary">Log Out</a>
      </div>
    </body>
    </html>
    <?php
    exit();
}

$db = getDB();
// Get recruiter details
$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $user['id']]);
$userFull = $stmt->fetch(PDO::FETCH_ASSOC);

// Check for update_settings action (AJAX POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_settings') {
    header('Content-Type: application/json');
    $apiKey = $_POST['custom_gemini_api_key'] ?? '';
    $modelChat = $_POST['model_chat_task'] ?? 'gemini-3.1-flash-lite';
    $modelVision = $_POST['model_vision_task'] ?? 'gemini-3.1-flash-lite';
    $modelEval = $_POST['model_eval_task'] ?? 'gemini-3.1-flash-lite';
    
    try {
        $stmtUpdate = $db->prepare("UPDATE users SET 
            custom_gemini_api_key = :api_key, 
            model_chat_task = :model_chat, 
            model_vision_task = :model_vision, 
            model_eval_task = :model_eval
            WHERE id = :id");
        $stmtUpdate->execute([
            'api_key' => empty($apiKey) ? null : trim($apiKey),
            'model_chat' => $modelChat,
            'model_vision' => $modelVision,
            'model_eval' => $modelEval,
            'id' => $user['id']
        ]);
        echo json_encode(['success' => true, 'message' => 'Settings updated successfully.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Fetch Stats, Links, and candidate results
$stats = getRecruiterStats($company['id']);
$links = listInterviewLinks($company['id']);
$results = listCandidateResults($company['id']);

$linksCount = count($links);
$sessionsCount = count($results);

// POST handler for creating interview links
$errorMsg = '';
$successMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_link') {
    $candidateName = trim($_POST['candidate_name'] ?? '');
    $candidateEmail = trim($_POST['candidate_email'] ?? '');
    $jobRole = trim($_POST['job_role'] ?? 'Software Engineer');
    $maxAttempts = (int)($_POST['max_attempts'] ?? 3);
    $expiresAt = trim($_POST['expires_at'] ?? '');
    
    if (empty($jobRole)) {
        $errorMsg = "Job role is required.";
    } else {
        try {
            // Generate unique 6-character alphanumeric code
            $code = '';
            do {
                $code = substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 6);
                $stmtCheck = $db->prepare("SELECT id FROM interview_links WHERE code = :code");
                $stmtCheck->execute(['code' => $code]);
                $exists = $stmtCheck->fetch();
            } while ($exists);
            
            $expiresVal = !empty($expiresAt) ? date('Y-m-d H:i:s', strtotime($expiresAt)) : null;
            
            createInterviewLink(
                $company['id'],
                $user['id'],
                $code,
                !empty($candidateEmail) ? $candidateEmail : null,
                !empty($candidateName) ? $candidateName : null,
                $maxAttempts,
                $expiresVal,
                $jobRole
            );
            
            $_SESSION['success_msg'] = "Interview link created successfully! Code: $code";
            header("Location: index.php");
            exit();
        } catch (Exception $e) {
            $errorMsg = "Error creating interview link: " . $e->getMessage();
        }
    }
}

// Flash messages
if (isset($_SESSION['success_msg'])) {
    $successMsg = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
}

// Initials for avatar
$words = explode(" ", $user['full_name']);
$initials = "";
foreach ($words as $w) {
    if (!empty($w)) $initials .= strtoupper($w[0]);
}
$initials = substr($initials, 0, 2);
$firstName = !empty($words[0]) ? $words[0] : 'Recruiter';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Recruiter Dashboard - TruInterview</title>
  <link rel="stylesheet" href="../assets/css/recruiter.css">
</head>
<body>
  <div class="v2-layout">
    <header class="v2-header">
      <a href="index.php" class="brand-wrapper">
        <svg style="width: 28px; height: 28px; color: var(--color-brand-primary);" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
        </svg>
        <span class="brand-title">TruInterview</span>
        <span class="portal-badge">Recruiter</span>
      </a>
      
      <div class="user-nav">
        <button id="btn-open-create-modal" class="btn-header-join" title="Create Interview">
          <svg style="width: 16px; height: 16px; color: currentColor;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"></path>
          </svg>
          <span class="join-btn-text">Create Interview</span>
        </button>

        <button id="btn-open-settings-modal" class="btn btn-outline" style="padding: 6px; border: none; background: transparent; color: var(--color-text-secondary);" title="Settings">
          <svg style="width: 22px; height: 22px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
          </svg>
        </button>
        <div class="avatar-circle"><?php echo htmlspecialchars($initials); ?></div>
        <a href="../logout.php" class="btn btn-outline" style="padding: 6px 12px; font-size: 0.8rem;">Log Out</a>
      </div>
    </header>

    <main>
      <h1 class="title-main">Hi, <?php echo htmlspecialchars($firstName); ?>. Let's find your next great hire.</h1>
      <p class="subtitle-main">
        <?php if ($linksCount === 0): ?>
          Your recruitment pipeline is ready. Create your first assessment link to start screening and interviewing candidates.
        <?php elseif ($sessionsCount === 0): ?>
          You have <?php echo $linksCount; ?> active assessment <?php echo ($linksCount === 1 ? 'link' : 'links'); ?> live. Share them with candidates to receive AI-evaluated coding reports.
        <?php else: ?>
          Your hiring pipeline is active. You have created <?php echo $linksCount; ?> assessment <?php echo ($linksCount === 1 ? 'link' : 'links'); ?> and received <?php echo $sessionsCount; ?> completed candidate <?php echo ($sessionsCount === 1 ? 'submission' : 'submissions'); ?> for review.
        <?php endif; ?>
      </p>

      <!-- Stats Section -->
      <section class="recruiter-stats-grid">
        <div class="bento-card text-center" style="padding: var(--space-4);">
          <span style="font-size: var(--text-xs); text-transform: uppercase; color: var(--color-text-secondary); font-weight: 700; letter-spacing: 0.05em;">Interview Links</span>
          <span style="font-size: var(--text-3xl); font-weight: 800; color: var(--color-brand-primary); margin-top: 4px; display: block;"><?php echo $stats['total_links'] ?? 0; ?></span>
        </div>
        <div class="bento-card text-center" style="padding: var(--space-4);">
          <span style="font-size: var(--text-xs); text-transform: uppercase; color: var(--color-text-secondary); font-weight: 700; letter-spacing: 0.05em;">Total Sessions</span>
          <span style="font-size: var(--text-3xl); font-weight: 800; color: var(--color-brand-primary); margin-top: 4px; display: block;"><?php echo $stats['total_sessions'] ?? 0; ?></span>
        </div>
        <div class="bento-card text-center" style="padding: var(--space-4);">
          <span style="font-size: var(--text-xs); text-transform: uppercase; color: var(--color-text-secondary); font-weight: 700; letter-spacing: 0.05em;">Completed</span>
          <span style="font-size: var(--text-3xl); font-weight: 800; color: var(--color-success); margin-top: 4px; display: block;"><?php echo $stats['completed_sessions'] ?? 0; ?></span>
        </div>
        <div class="bento-card text-center" style="padding: var(--space-4);">
          <span style="font-size: var(--text-xs); text-transform: uppercase; color: var(--color-text-secondary); font-weight: 700; letter-spacing: 0.05em;">Average Score</span>
          <span style="font-size: var(--text-3xl); font-weight: 800; color: var(--color-accent); margin-top: 4px; display: block;"><?php echo ($stats['average_score'] ?? 0) > 0 ? ($stats['average_score'] . '/10') : 'N/A'; ?></span>
        </div>
      </section>

      <!-- Bento Grid for Active Interview Links -->
      <h2 style="font-size: var(--text-xl); font-weight: 700; color: var(--color-text-primary); margin-bottom: var(--space-4);">Active Assessment Links</h2>
      <div class="bento-grid">
        <!-- Create New Link bento card -->
        <div class="bento-card card-add" id="card-add-trigger">
          <svg class="add-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"></path>
          </svg>
          <div style="font-weight: 600; color: var(--color-text-primary); font-size: var(--text-lg);">Create New Link</div>
          <div style="font-size: var(--text-sm); color: var(--color-text-muted); margin-top: 4px;">Generate a new code for candidates</div>
        </div>

        <?php foreach ($links as $link): 
          $isActive = ($link['status'] === 'active');
          $expiryStr = !empty($link['expires_at']) ? date('M d, Y', strtotime($link['expires_at'])) : 'Never';
          $isExpired = !empty($link['expires_at']) && (strtotime($link['expires_at']) < time());
          $displayStatus = $isActive ? ($isExpired ? 'Expired' : 'Active') : 'Inactive';
        ?>
          <div class="bento-card">
            <div class="card-header" style="margin-bottom: var(--space-2);">
              <div class="role-title" title="<?php echo htmlspecialchars($link['job_role']); ?>"><?php echo htmlspecialchars($link['job_role']); ?></div>
              <span class="card-badge" style="font-size: 0.72rem; padding: 2px 6px;"><?php echo $displayStatus; ?></span>
            </div>

            <div style="margin-bottom: var(--space-4); font-size: var(--text-sm); color: var(--color-text-secondary); flex-grow: 1;">
              <?php if (!empty($link['candidate_name'])): ?>
                For: <strong><?php echo htmlspecialchars($link['candidate_name']); ?></strong>
                <div style="font-size: 0.75rem; color: var(--color-text-muted);"><?php echo htmlspecialchars($link['candidate_email']); ?></div>
              <?php else: ?>
                <span style="color: var(--color-text-muted); font-style: italic;">Public Link</span>
              <?php endif; ?>
            </div>

            <div class="card-body" style="gap: var(--space-2); margin-top: auto;">
              <div class="status-item" style="padding: 10px; border-radius: var(--radius-inner); font-family: monospace; font-size: var(--text-base); display: flex; justify-content: space-between; align-items: center; background: var(--color-bg-subtle);">
                <strong style="letter-spacing: 1px; color: var(--color-text-primary);"><?php echo htmlspecialchars($link['code']); ?></strong>
                <button class="btn btn-outline btn-copy-code" data-code="<?php echo htmlspecialchars($link['code']); ?>" style="padding: 4px 8px; font-size: 0.72rem; border-radius: 4px;" title="Copy Code">Copy</button>
              </div>

              <div style="font-size: 0.75rem; color: var(--color-text-secondary); display: flex; flex-direction: column; gap: 2px; margin-top: var(--space-2);">
                <div>Attempts: <strong><?php echo (int)$link['attempts_used']; ?> / <?php echo (int)$link['max_attempts']; ?></strong></div>
                <div>Expires: <strong><?php echo $expiryStr; ?></strong></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Candidate Results / History Section -->
      <section class="resume-section" style="margin-top: var(--space-8);">
        <div class="resume-section-header">
          <h2 class="resume-section-title">Candidate Submissions</h2>
          <p class="resume-section-subtitle">Review completed candidate interview sessions and proctoring logs.</p>
        </div>

        <div class="submissions-table-wrapper">
          <?php if (empty($results)): ?>
            <div style="text-align: center; padding: var(--space-8) var(--space-4); color: var(--color-text-muted); display: flex; flex-direction: column; align-items: center;">
              <svg style="width: 48px; height: 48px; color: var(--color-text-muted); margin-bottom: var(--space-3);" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.577-2.935M2.197 19.128A9.386 9.386 0 005 19.5c1.458 0 2.846-.334 4.12-.952a4.125 4.125 0 00-7.576-2.935m0 0A3 3 0 013 11c0-2.206 1.794-4 4-4s4 1.794 4 4a3 3 0 01-1.803 2.757M15 11a3 3 0 11-6 0 3 3 0 016 0zm-7 5c-1.378 0-2.656-.566-3.596-1.48A8.967 8.967 0 018 13.5a8.967 8.967 0 016.596 2.02A7.98 7.98 0 018 16z"></path>
              </svg>
              <div style="font-weight: 600;">No Submissions Yet</div>
              <p style="font-size: var(--text-xs); margin-top: 4px;">Once candidates complete their interviews using your link codes, their reports will appear here.</p>
            </div>
          <?php else: ?>
            <table class="recruiter-table">
              <thead>
                <tr>
                  <th>Candidate</th>
                  <th>Role / Code</th>
                  <th style="text-align: center;">Integrity</th>
                  <th style="text-align: center;">Score</th>
                  <th style="text-align: center;">Status</th>
                  <th style="text-align: right;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($results as $res): 
                  $scoreData = !empty($res['final_score']) ? json_decode($res['final_score'], true) : null;
                  $commScore = $scoreData['communication_score'] ?? 0;
                  $probScore = $scoreData['problem_solving_score'] ?? 0;
                  $qualScore = $scoreData['code_quality_score'] ?? 0;
                  $avgSessionScore = $scoreData ? round(($commScore + $probScore + $qualScore) / 3.0, 1) : null;
                  
                  // Proctoring stats
                  $alertsCount = getProctorAlertCount($res['id']);
                  $status = $res['current_status'];
                  
                  // Format proctor status
                  $integrityText = "Verified Clean";
                  $integrityColor = "var(--color-success)";
                  if ($alertsCount > 0) {
                      $integrityText = "$alertsCount anomaly alerts";
                      $integrityColor = "var(--color-danger)";
                  }
                ?>
                  <tr>
                    <td>
                      <div style="font-weight: 700; color: var(--color-text-primary);"><?php echo htmlspecialchars($res['candidate_name']); ?></div>
                      <div style="font-size: 0.75rem; color: var(--color-text-secondary);"><?php echo htmlspecialchars($res['email']); ?></div>
                    </td>
                    <td>
                      <div><?php echo htmlspecialchars($res['template_title']); ?></div>
                      <div style="font-size: 0.72rem; font-family: monospace; color: var(--color-brand-primary);">Code: <?php echo htmlspecialchars($res['link_code']); ?></div>
                    </td>
                    <td style="text-align: center;">
                      <?php if ($status === 'COMPLETED'): ?>
                        <span style="font-size: 0.78rem; font-weight: 600; color: <?php echo $integrityColor; ?>;">
                          <?php echo $integrityText; ?>
                        </span>
                      <?php else: ?>
                        <span style="color: var(--color-text-muted); font-size: 0.78rem;">—</span>
                      <?php endif; ?>
                    </td>
                    <td style="text-align: center; font-weight: 700;">
                      <?php echo $avgSessionScore !== null ? ($avgSessionScore . '/10') : '—'; ?>
                    </td>
                    <td style="text-align: center;">
                      <span class="card-badge" style="font-size: 0.68rem; padding: 2px 6px; text-transform: capitalize;"><?php echo strtolower($status); ?></span>
                    </td>
                    <td style="text-align: right;">
                      <?php if ($status === 'COMPLETED'): ?>
                        <a href="report.php?session_id=<?php echo urlencode($res['id']); ?>" class="btn btn-primary" style="padding: 6px 12px; font-size: 0.78rem;">View Report</a>
                      <?php else: ?>
                        <button class="btn btn-outline" style="padding: 6px 12px; font-size: 0.78rem; opacity: 0.6; cursor: not-allowed;" disabled>In Progress</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </section>
    </main>
  </div>

  <!-- Create Interview Modal -->
  <div class="modal-overlay" id="create-modal">
    <div class="modal-content">
      <h2 style="margin-bottom: var(--space-2); font-family: 'Outfit', sans-serif;">Create Assessment Link</h2>
      <p style="color: var(--color-text-secondary); font-size: var(--text-sm); margin-bottom: var(--space-5);">Configure a new coding session link code for a candidate or job opening.</p>
      
      <form id="form-create-link" method="POST" action="index.php">
        <input type="hidden" name="action" value="create_link">
        
        <div class="form-group">
          <label class="form-label" for="job_role">Job Role (Required)</label>
          <input type="text" id="job_role" name="job_role" class="form-input" placeholder="e.g. Senior Backend Engineer" required autocomplete="off" value="Software Engineer">
        </div>

        <div class="form-group">
          <label class="form-label" for="candidate_name">Candidate Name (Optional)</label>
          <input type="text" id="candidate_name" name="candidate_name" class="form-input" placeholder="e.g. John Doe" autocomplete="off">
        </div>

        <div class="form-group">
          <label class="form-label" for="candidate_email">Candidate Email (Optional)</label>
          <input type="email" id="candidate_email" name="candidate_email" class="form-input" placeholder="e.g. john.doe@example.com" autocomplete="off">
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4);">
          <div class="form-group">
            <label class="form-label" for="max_attempts">Max Attempts</label>
            <input type="number" id="max_attempts" name="max_attempts" class="form-input" min="1" max="10" value="3" required>
          </div>

          <div class="form-group">
            <label class="form-label" for="expires_at">Expiry Date (Optional)</label>
            <input type="date" id="expires_at" name="expires_at" class="form-input">
          </div>
        </div>
        
        <div style="display: flex; justify-content: flex-end; gap: var(--space-3); margin-top: var(--space-6);">
          <button type="button" class="btn btn-outline" id="btn-close-create-modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <span>Generate Link</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Settings Modal -->
  <div class="modal-overlay" id="settings-modal">
    <div class="modal-content" style="max-width: 600px;">
      <h2 style="margin-bottom: var(--space-4); display: flex; align-items: center; gap: 8px;">
        <svg style="width: 24px; height: 24px; color: var(--color-brand-primary);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
        AI Settings
      </h2>
      
      <form id="form-settings" style="display: flex; flex-direction: column; gap: var(--space-4);">
        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label">Custom Gemini API Key</label>
          <input type="password" name="custom_gemini_api_key" class="form-input" placeholder="e.g. AIzaSy..." value="<?php echo htmlspecialchars($userFull['custom_gemini_api_key'] ?? ''); ?>">
          <div style="font-size: 0.75rem; color: var(--color-text-muted); margin-top: 4px;">If empty, the platform global API key is used.</div>
        </div>
        
        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label">Dialogue (Chat) Model Override</label>
          <select name="model_chat_task" class="form-input">
            <option value="gemini-3.5-flash" <?php if (($userFull['model_chat_task'] ?? '') === 'gemini-3.5-flash') echo 'selected'; ?>>gemini-3.5-flash (Fast, conversational)</option>
            <option value="gemini-3.1-flash-lite" <?php if (($userFull['model_chat_task'] ?? '') === 'gemini-3.1-flash-lite' || empty($userFull['model_chat_task'])) echo 'selected'; ?>>gemini-3.1-flash-lite (Ultra-low latency dialog)</option>
            <option value="gemini-3.1-pro-preview" <?php if (($userFull['model_chat_task'] ?? '') === 'gemini-3.1-pro-preview') echo 'selected'; ?>>gemini-3.1-pro (Deep, rich answers)</option>
          </select>
        </div>

        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label">Screen Context (Vision) Model Override</label>
          <select name="model_vision_task" class="form-input">
            <option value="gemini-3.5-flash" <?php if (($userFull['model_vision_task'] ?? '') === 'gemini-3.5-flash') echo 'selected'; ?>>gemini-3.5-flash (Balanced speed)</option>
            <option value="gemini-3.1-pro-preview" <?php if (($userFull['model_vision_task'] ?? '') === 'gemini-3.1-pro-preview') echo 'selected'; ?>>gemini-3.1-pro (High intelligence code understanding)</option>
            <option value="gemini-3.1-flash-lite" <?php if (($userFull['model_vision_task'] ?? '') === 'gemini-3.1-flash-lite' || empty($userFull['model_vision_task'])) echo 'selected'; ?>>gemini-3.1-flash-lite (Fastest processing)</option>
          </select>
        </div>

        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label">Evaluation (Grading) Model Override</label>
          <select name="model_eval_task" class="form-input">
            <option value="gemini-3.1-pro-preview" <?php if (($userFull['model_eval_task'] ?? '') === 'gemini-3.1-pro-preview') echo 'selected'; ?>>gemini-3.1-pro (Advanced grading report evaluation)</option>
            <option value="gemini-3.5-flash" <?php if (($userFull['model_eval_task'] ?? '') === 'gemini-3.5-flash') echo 'selected'; ?>>gemini-3.5-flash (Standard grading evaluation)</option>
            <option value="gemini-3.1-flash-lite" <?php if (($userFull['model_eval_task'] ?? '') === 'gemini-3.1-flash-lite' || empty($userFull['model_eval_task'])) echo 'selected'; ?>>gemini-3.1-flash-lite (Fast grading evaluation)</option>
          </select>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: var(--space-3); margin-top: var(--space-2);">
          <button type="button" class="btn btn-outline" id="btn-close-settings-modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="btn-submit-settings">Save Settings</button>
        </div>
      </form>
    </div>
  </div>

  <div class="toast-container" id="toast-container"></div>

  <script>
    const successMsg = <?php echo json_encode($successMsg); ?>;
    const errorMsg = <?php echo json_encode($errorMsg); ?>;
    
    function showToast(message, type = 'success') {
      const container = document.getElementById('toast-container');
      const toast = document.createElement('div');
      toast.className = 'toast';
      
      let icon = '';
      if (type === 'success') {
        icon = '<svg style="width:18px;height:18px;color:var(--color-success);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg>';
      } else {
        icon = '<svg style="width:18px;height:18px;color:var(--color-danger);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
      }
      
      toast.innerHTML = icon + '<span>' + message + '</span>';
      container.appendChild(toast);
      
      setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        setTimeout(() => toast.remove(), 300);
      }, 3000);
    }

    if (successMsg) {
      showToast(successMsg, 'success');
    }
    if (errorMsg) {
      showToast(errorMsg, 'error');
    }

    // Modal triggers
    const createModal = document.getElementById('create-modal');
    const settingsModal = document.getElementById('settings-modal');

    document.getElementById('btn-open-create-modal').addEventListener('click', () => {
      createModal.classList.add('active');
      setTimeout(() => document.getElementById('job_role').focus(), 50);
    });
    
    const cardAddTrigger = document.getElementById('card-add-trigger');
    if (cardAddTrigger) {
      cardAddTrigger.addEventListener('click', () => {
        createModal.classList.add('active');
        setTimeout(() => document.getElementById('job_role').focus(), 50);
      });
    }

    document.getElementById('btn-close-create-modal').addEventListener('click', () => {
      createModal.classList.remove('active');
      document.getElementById('form-create-link').reset();
    });

    document.getElementById('btn-open-settings-modal').addEventListener('click', () => {
      settingsModal.classList.add('active');
    });

    document.getElementById('btn-close-settings-modal').addEventListener('click', () => {
      settingsModal.classList.remove('active');
    });

    // Close modal on clicking overlay
    window.addEventListener('click', (e) => {
      if (e.target === createModal) createModal.classList.remove('active');
      if (e.target === settingsModal) settingsModal.classList.remove('active');
    });

    // Copy Code logic
    document.querySelectorAll('.btn-copy-code').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const code = btn.getAttribute('data-code');
        navigator.clipboard.writeText(code).then(() => {
          btn.textContent = 'Copied!';
          showToast(`Copied code: ${code}`);
          setTimeout(() => {
            btn.textContent = 'Copy';
          }, 2000);
        }).catch(() => {
          showToast('Failed to copy code', 'error');
        });
      });
    });

    // Settings submit logic
    const formSettings = document.getElementById('form-settings');
    if (formSettings) {
      formSettings.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btnSubmit = document.getElementById('btn-submit-settings');
        btnSubmit.innerHTML = '<span class="spinner"></span> Saving...';
        btnSubmit.disabled = true;

        const formData = new URLSearchParams(new FormData(formSettings));
        formData.append('action', 'update_settings');

        try {
          const res = await fetch('index.php', {
            method: 'POST',
            body: formData,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
          });
          const data = await res.json();
          
          if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => window.location.reload(), 800);
          } else {
            showToast(data.message || 'Error updating settings', 'error');
            btnSubmit.innerHTML = 'Save Settings';
            btnSubmit.disabled = false;
          }
        } catch (err) {
          showToast('Network error', 'error');
          btnSubmit.innerHTML = 'Save Settings';
          btnSubmit.disabled = false;
        }
      });
    }
  </script>
</body>
</html>
