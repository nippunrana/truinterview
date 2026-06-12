<?php
// recruiter/index.php - Recruiter Dashboard
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

// Enforce Recruiter role
requireAuth(['recruiter']);

$user = getCurrentUser();

$db = getDB();
$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $user['id']]);
$userFull = $stmt->fetch(PDO::FETCH_ASSOC);

// Get company profile
$company = getRecruiterCompany($user['id']);
if (!$company) {
    // Gracefully handle if no company exists, auto-create one
    $companyName = $user['full_name'] . "'s Company";
    $stmt = $db->prepare("INSERT INTO companies (name, created_by) VALUES (:name, :created_by) RETURNING id");
    $stmt->execute(['name' => $companyName, 'created_by' => $user['id']]);
    $companyId = $stmt->fetchColumn();

    $stmt = $db->prepare("INSERT INTO company_members (company_id, user_id, role) VALUES (:company_id, :user_id, 'admin')");
    $stmt->execute(['company_id' => $companyId, 'user_id' => $user['id']]);
    
    $company = getRecruiterCompany($user['id']);
}

$error = '';
$success = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_settings') {
        $agentId = $_POST['custom_trugen_agent_id'] ?? '';
        $apiKey = $_POST['custom_gemini_api_key'] ?? '';
        $modelChat = $_POST['model_chat_task'] ?? 'gemini-3.1-flash-lite';
        $modelVision = $_POST['model_vision_task'] ?? 'gemini-3.1-flash-lite';
        $modelEval = $_POST['model_eval_task'] ?? 'gemini-3.1-flash-lite';
        
        try {
            $stmt = $db->prepare("UPDATE users SET 
                custom_trugen_agent_id = :agent_id, 
                custom_gemini_api_key = :api_key, 
                model_chat_task = :model_chat, 
                model_vision_task = :model_vision, 
                model_eval_task = :model_eval 
                WHERE id = :id");
            $stmt->execute([
                'agent_id' => empty($agentId) ? null : trim($agentId),
                'api_key' => empty($apiKey) ? null : trim($apiKey),
                'model_chat' => $modelChat,
                'model_vision' => $modelVision,
                'model_eval' => $modelEval,
                'id' => $user['id']
            ]);
            
            // Re-fetch user profile
            $stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
            $stmt->execute(['id' => $user['id']]);
            $userFull = $stmt->fetch(PDO::FETCH_ASSOC);
            $success = "Settings updated successfully.";
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }

    try {
        if ($action === 'generate_link') {
            $jobRole = $_POST['job_role'] ?? '';
            $candidateName = $_POST['candidate_name'] ?? '';
            $candidateEmail = $_POST['candidate_email'] ?? '';
            $maxAttempts = $_POST['max_attempts'] ?? 1;
            $expiresAt = $_POST['expires_at'] ?? '';
            
            if (empty($jobRole)) {
                throw new Exception("Please specify a job role.");
            }

            // Generate unique code
            $code = 'TRU-' . substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 6);
            
            // Check uniqueness
            $db = getDB();
            $stmt = $db->prepare("SELECT id FROM interview_links WHERE code = :code");
            $stmt->execute(['code' => $code]);
            if ($stmt->fetch()) {
                $code = 'TRU-' . substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 6);
            }

            // Format expiration timestamp
            $expTimestamp = null;
            if (!empty($expiresAt)) {
                $expTimestamp = date('Y-m-d H:i:s', strtotime($expiresAt));
            }

            createInterviewLink(
                $company['id'],
                $user['id'],
                $code,
                $candidateEmail,
                $candidateName,
                $maxAttempts,
                $expTimestamp,
                $jobRole
            );
            $success = "Assessment invite generated successfully. Code: $code";
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch dashboard data lists
$stats = getRecruiterStats($company['id']);
$links = listInterviewLinks($company['id']);
$results = listCandidateResults($company['id']);

// Get initials for recruiter avatar
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
  <title>Recruiter Dashboard - TruInterview</title>
  <link rel="stylesheet" href="../assets/css/recruiter.css">
</head>
<body class="recruiter-body">

  <div class="dashboard-wrapper">
    
    <!-- Header -->
    <header class="recruiter-header">
      <a href="../index.php" class="brand-wrapper">
        <svg style="width: 28px; height: 28px; color: var(--color-brand-primary);" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
        </svg>
        <span class="brand-title">TruInterview</span>
      </a>
      
      <div class="user-profile">
        <div class="user-info">
          <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
          <div class="user-role"><?php echo htmlspecialchars($company['name']); ?> Member</div>
        </div>
        <div class="avatar"><?php echo htmlspecialchars($initials); ?></div>
        <a href="../logout.php" class="btn-logout">Log Out</a>
      </div>
    </header>

    <!-- Notification Toast -->
    <div id="toast" class="notification-toast">Link copied to clipboard!</div>

    <!-- Stats Grid -->
    <div class="stats-grid">
      <div class="stat-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-2);">
          <div class="stat-title">Invite Links</div>
          <svg style="width: 20px; height: 20px; color: var(--color-brand-primary);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
        </div>
        <div class="stat-value"><?php echo $stats['total_links']; ?></div>
        <div class="stat-desc">Invitation codes generated</div>
      </div>
      <div class="stat-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-2);">
          <div class="stat-title">Candidates Tested</div>
          <svg style="width: 20px; height: 20px; color: var(--color-brand-primary);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
        </div>
        <div class="stat-value"><?php echo $stats['total_sessions']; ?></div>
        <div class="stat-desc">Candidate assessment runs</div>
      </div>
      <div class="stat-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-2);">
          <div class="stat-title">Completed Assessments</div>
          <svg style="width: 20px; height: 20px; color: var(--color-brand-primary);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>
        </div>
        <div class="stat-value"><?php echo $stats['completed_sessions']; ?></div>
        <div class="stat-desc">Evaluations compiled by Gemini</div>
      </div>
      <div class="stat-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-2);">
          <div class="stat-title">Avg Candidate Rating</div>
          <svg style="width: 20px; height: 20px; color: var(--color-brand-primary);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.364 1.118l1.518 4.674c.3.921-.755 1.688-1.54 1.118l-3.976-2.888a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>
        </div>
        <div class="stat-value">
          <?php echo $stats['average_score'] > 0 ? $stats['average_score'] . '/10' : '-'; ?>
        </div>
        <div class="stat-desc">Aggregated metrics average</div>
      </div>
    </div>

    <!-- Error/Success Alerts -->
    <?php if (!empty($error)): ?>
      <div style="background: rgba(239, 68, 68, 0.05); border: 1px solid rgba(239, 68, 68, 0.15); color: #dc2626; padding: 16px; border-radius: 12px; font-size: 0.9rem; font-weight: 500;">
        <?php echo htmlspecialchars($error); ?>
      </div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
      <div style="background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.15); color: #059669; padding: 16px; border-radius: 12px; font-size: 0.9rem; font-weight: 500;">
        <?php echo htmlspecialchars($success); ?>
      </div>
    <?php endif; ?>

    <!-- Recruiter Grid Layout -->
    <div class="recruiter-grid">
      
      <!-- Primary Tabs Panel -->
      <div class="dashboard-panel">
        <div class="tab-container">
          <button class="tab-btn active" onclick="switchTab('results')">Candidates Results</button>
          <button class="tab-btn" onclick="switchTab('links')">Active Invites</button>
          <button class="tab-btn" onclick="switchTab('settings')">Settings</button>
        </div>

        <!-- Tab 1: Candidates Results -->
        <div id="tab-results" class="tab-content active">
          <div class="results-table-wrapper">
            <?php if (empty($results)): ?>
              <div class="empty-state">
                <svg class="empty-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.109A2.25 2.25 0 0112.75 21.5h-1.5a2.25 2.25 0 01-2.25-2.268v-.11a2.25 2.25 0 00-.786-3.07M11.25 14.25c0-1.113-.285-2.16-.786-3.07M12 9a3.75 3.75 0 100-7.5 3.75 3.75 0 000 7.5z"></path>
                </svg>
                <div style="font-weight: 600; color: var(--color-text-secondary); margin-top: var(--space-2);">No Candidate Results Yet</div>
                <p style="font-size: var(--text-xs); margin: 0; max-width: 320px; color: var(--color-text-muted);">Candidate assessment reports will populate here once they start and complete their interviews.</p>
              </div>
            <?php else: ?>
              <table class="results-table">
                <thead>
                  <tr>
                    <th>Candidate</th>
                    <th>Job Role</th>
                    <th>Code</th>
                    <th>Started At</th>
                    <th>Status</th>
                    <th>Score</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($results as $row): ?>
                    <?php 
                      $status = $row['current_status'] ?? 'STARTED';
                      
                      // Calculate overall score
                      $displayScore = '-';
                      if ($status === 'COMPLETED' && !empty($row['final_score'])) {
                          $scoreData = json_decode($row['final_score'], true);
                          if ($scoreData) {
                              $comm = (float)($scoreData['communication_score'] ?? 0);
                              $prob = (float)($scoreData['problem_solving_score'] ?? 0);
                              $qual = (float)($scoreData['code_quality_score'] ?? 0);
                              $displayScore = round(($comm + $prob + $qual) / 3.0, 1) . '/10';
                          }
                      }
                    ?>
                    <tr>
                      <td>
                        <div style="font-weight: 600; color: var(--color-text-primary);"><?php echo htmlspecialchars($row['candidate_name']); ?></div>
                        <div style="font-size: 0.78rem; color: var(--color-text-muted);"><?php echo htmlspecialchars($row['email']); ?></div>
                      </td>
                      <td><?php echo htmlspecialchars($row['template_title']); ?></td>
                      <td style="font-family: monospace; font-weight: 700; color: var(--color-brand-primary);"><?php echo htmlspecialchars($row['link_code']); ?></td>
                      <td><?php echo date('M d, Y h:i A', strtotime($row['started_at'])); ?></td>
                      <td>
                        <span class="badge badge-<?php echo strtolower($status); ?>">
                          <?php echo str_replace('_', ' ', $status); ?>
                        </span>
                      </td>
                      <td>
                        <?php if ($displayScore !== '-'): ?>
                          <span class="score-badge"><?php echo $displayScore; ?></span>
                        <?php else: ?>
                          <span style="color: var(--color-text-muted);"><?php echo $displayScore; ?></span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($status === 'COMPLETED'): ?>
                          <a href="report.php?session_id=<?php echo urlencode($row['id']); ?>" class="btn btn-outline" style="border-color: var(--color-brand-primary); color: var(--color-brand-primary); padding: 4px 10px; font-size: 0.8rem;">View Report</a>
                        <?php else: ?>
                          <span style="color: var(--color-text-muted); font-size: 0.85rem;">Unavailable</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>

        <!-- Tab 2: Invite Links -->
        <div id="tab-links" class="tab-content">
          <div class="results-table-wrapper">
            <?php if (empty($links)): ?>
              <div class="empty-state">
                <svg class="empty-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244m6.562-7.843L12 12m2.828-9.9a13.389 13.389 0 00-2.828-9.9"></path>
                </svg>
                <div style="font-weight: 600; color: var(--color-text-secondary); margin-top: var(--space-2);">No Active Invites</div>
                <p style="font-size: var(--text-xs); margin: 0; max-width: 320px; color: var(--color-text-muted);">No invitation links created yet. Use the sidebar generator to create candidate invite codes.</p>
              </div>
            <?php else: ?>
              <table class="results-table">
                <thead>
                  <tr>
                    <th>Invite Link / Code</th>
                    <th>Job Role</th>
                    <th>Candidate Target</th>
                    <th>Attempts</th>
                    <th>Status</th>
                    <th>Expires</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($links as $row): ?>
                    <?php
                      $isExpired = $row['expires_at'] && strtotime($row['expires_at']) < time();
                      $isMaxed = $row['attempts_used'] >= $row['max_attempts'];
                      $active = ($row['status'] === 'active' && !$isExpired && !$isMaxed);
                      
                      // Link absolute path
                      $linkUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]/interview.php?code=" . $row['code'];
                    ?>
                    <tr>
                      <td>
                        <div class="copy-link-container">
                          <span class="copy-link-text"><?php echo htmlspecialchars($linkUrl); ?></span>
                          <button class="btn-copy" onclick="copyToClipboard('<?php echo htmlspecialchars($linkUrl); ?>')" title="Copy Assessment Link">
                            <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10"></path>
                            </svg>
                          </button>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--color-text-muted); margin-top: 4px; margin-left: 12px;">Code: <strong style="color: var(--color-text-primary);"><?php echo htmlspecialchars($row['code']); ?></strong></div>
                      </td>
                      <td><?php echo htmlspecialchars($row['job_role']); ?></td>
                      <td>
                        <?php if ($row['candidate_name']): ?>
                          <div style="font-weight: 600;"><?php echo htmlspecialchars($row['candidate_name']); ?></div>
                          <div style="font-size: 0.78rem; color: var(--color-text-muted);"><?php echo htmlspecialchars($row['candidate_email']); ?></div>
                        <?php else: ?>
                          <span style="color: var(--color-text-muted);">Any Guest Candidate</span>
                        <?php endif; ?>
                      </td>
                      <td style="font-variant-numeric: tabular-nums;"><?php echo $row['attempts_used']; ?> / <?php echo $row['max_attempts']; ?></td>
                      <td>
                        <span class="badge <?php echo $active ? 'badge-active' : ($isMaxed ? 'badge-used' : 'badge-expired'); ?>">
                          <?php echo $active ? 'Active' : ($isMaxed ? 'Used' : 'Expired'); ?>
                        </span>
                      </td>
                      <td style="font-size: 0.85rem; color: var(--color-text-muted);">
                        <?php echo $row['expires_at'] ? date('M d, Y', strtotime($row['expires_at'])) : 'Never'; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>



        <!-- Tab 4: Settings -->
        <div id="tab-settings" class="tab-content" style="padding: 24px;">
          <form class="recruiter-form" method="POST" action="index.php" style="display: flex; flex-direction: column; gap: 24px;">
            <input type="hidden" name="action" value="update_settings">
            
            <div style="background: rgba(255, 255, 255, 0.01); border: 1px solid var(--color-border); padding: 24px; border-radius: 12px;">
              <h3 style="margin-top: 0; margin-bottom: 16px; color: var(--color-brand-primary); font-size: 1.15rem; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                TruGen AI Setup
              </h3>
              
              <div class="form-group" style="margin-bottom: 16px;">
                <label for="custom_trugen_agent_id" style="font-weight: 600;">Custom TruGen Agent ID</label>
                <input type="text" name="custom_trugen_agent_id" id="custom_trugen_agent_id" class="form-input" 
                       placeholder="e.g. 123e4567-e89b-12d3-a456-426614174000" 
                       value="<?php echo htmlspecialchars($userFull['custom_trugen_agent_id'] ?? ''); ?>">
                <span style="font-size: 0.8rem; color: var(--color-text-muted); margin-top: 4px; display: block;">
                  Leave blank to use the platform's global TruGen Agent.
                </span>
              </div>
              
              <div style="background: rgba(79, 70, 229, 0.04); border-left: 3px solid var(--color-brand-primary); padding: 16px; border-radius: 8px; margin-top: 16px;">
                <h4 style="margin-top: 0; margin-bottom: 6px; font-size: 0.9rem; color: var(--color-text-primary); font-weight: 600;">How to Configure your TruGen Agent LLM Section</h4>
                <p style="margin: 0; font-size: 0.82rem; color: var(--color-text-secondary); line-height: 1.45;">
                  To enable TruInterview to drive the conversation, configure these settings in your TruGen dashboard LLM Section:
                </p>
                <ul style="margin: 8px 0 0 0; padding-left: 20px; font-size: 0.82rem; color: var(--color-text-secondary); line-height: 1.45;">
                  <li><strong>Base URL / LLM Endpoint:</strong> <code>https://codepane.com/truinterview/chat</code></li>
                  <li><strong>API Key:</strong> Any text (e.g. <code>dummy-key</code>)</li>
                  <li><strong>Model:</strong> Select <code>gemini-3.5-flash</code> (our server will route dialogue task requests based on the dropdown overrides below)</li>
                </ul>
              </div>
            </div>
 
            <div style="background: rgba(255, 255, 255, 0.01); border: 1px solid var(--color-border); padding: 24px; border-radius: 12px;">
              <h3 style="margin-top: 0; margin-bottom: 16px; color: var(--color-brand-primary); font-size: 1.15rem; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                AI Brain Settings
              </h3>
              
              <div class="form-group" style="margin-bottom: 20px;">
                <label for="custom_gemini_api_key" style="font-weight: 600;">Custom Gemini API Key</label>
                <input type="password" name="custom_gemini_api_key" id="custom_gemini_api_key" class="form-input" 
                       placeholder="e.g. AIzaSy..." 
                       value="<?php echo htmlspecialchars($userFull['custom_gemini_api_key'] ?? ''); ?>">
                <span style="font-size: 0.8rem; color: var(--color-text-muted); margin-top: 4px; display: block;">
                  If empty, the platform global API key is used. Your settings will override only for your invites/interview links.
                </span>
              </div>
              
              <div style="display: flex; flex-direction: column; gap: 16px;">
                <div class="form-group">
                  <label for="model_chat_task" style="font-weight: 600; display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <span style="font-size: 0.88rem; color: var(--color-text-primary);">Dialogue (Chat) Model Override</span>
                    <span style="font-weight: normal; font-size: 0.76rem; color: var(--color-brand-primary);">Recommended: Low latency models (Flash/Flash-Lite)</span>
                  </label>
                  <select name="model_chat_task" id="model_chat_task" class="form-input" style="padding: 8px 12px;">
                    <option value="gemini-3.5-flash" <?php if (($userFull['model_chat_task'] ?? '') === 'gemini-3.5-flash') echo 'selected'; ?>>gemini-3.5-flash (Fast conversation flow)</option>
                    <option value="gemini-3.1-flash-lite" <?php if (($userFull['model_chat_task'] ?? '') === 'gemini-3.1-flash-lite') echo 'selected'; ?>>gemini-3.1-flash-lite (Ultra-low latency conversation)</option>
                    <option value="gemini-3.1-pro-preview" <?php if (($userFull['model_chat_task'] ?? '') === 'gemini-3.1-pro-preview') echo 'selected'; ?>>gemini-3.1-pro (Rich, comprehensive dialog responses)</option>
                  </select>
                </div>
                
                <div class="form-group">
                  <label for="model_vision_task" style="font-weight: 600; display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <span style="font-size: 0.88rem; color: var(--color-text-primary);">Screen Context (Vision) Model Override</span>
                    <span style="font-weight: normal; font-size: 0.76rem; color: var(--color-brand-primary);">Recommended: Pro for code layout comprehension</span>
                  </label>
                  <select name="model_vision_task" id="model_vision_task" class="form-input" style="padding: 8px 12px;">
                    <option value="gemini-3.5-flash" <?php if (($userFull['model_vision_task'] ?? '') === 'gemini-3.5-flash') echo 'selected'; ?>>gemini-3.5-flash (Balanced speed & understanding)</option>
                    <option value="gemini-3.1-pro-preview" <?php if (($userFull['model_vision_task'] ?? '') === 'gemini-3.1-pro-preview') echo 'selected'; ?>>gemini-3.1-pro (Highly accurate screenshot & code recognition)</option>
                    <option value="gemini-3.1-flash-lite" <?php if (($userFull['model_vision_task'] ?? '') === 'gemini-3.1-flash-lite') echo 'selected'; ?>>gemini-3.1-flash-lite (Highest speed processing)</option>
                  </select>
                </div>
                
                <div class="form-group">
                  <label for="model_eval_task" style="font-weight: 600; display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <span style="font-size: 0.88rem; color: var(--color-text-primary);">Evaluation (Grading) Model Override</span>
                    <span style="font-weight: normal; font-size: 0.76rem; color: var(--color-brand-primary);">Recommended: Pro for deep reasoning & metric scoring</span>
                  </label>
                  <select name="model_eval_task" id="model_eval_task" class="form-input" style="padding: 8px 12px;">
                    <option value="gemini-3.1-pro-preview" <?php if (($userFull['model_eval_task'] ?? '') === 'gemini-3.1-pro-preview') echo 'selected'; ?>>gemini-3.1-pro (Deep reasoning metric scorecard report generation)</option>
                    <option value="gemini-3.5-flash" <?php if (($userFull['model_eval_task'] ?? '') === 'gemini-3.5-flash') echo 'selected'; ?>>gemini-3.5-flash (Standard scoring evaluation)</option>
                    <option value="gemini-3.1-flash-lite" <?php if (($userFull['model_eval_task'] ?? '') === 'gemini-3.1-flash-lite') echo 'selected'; ?>>gemini-3.1-flash-lite (Fast scoring evaluation)</option>
                  </select>
                </div>
              </div>
            </div>
            
            <button type="submit" class="btn-submit" style="align-self: flex-start; min-width: 180px; margin-top: 10px;">Save Settings</button>
          </form>
        </div>
      </div>

      <!-- Sidebar Form widgets -->
      <div style="display: flex; flex-direction: column; gap: 24px;">
        
        <!-- Generate Assessment Invite Link Form -->
        <div class="dashboard-panel" style="padding: 24px;">
          <h4 class="panel-title" style="border-bottom: 1px solid var(--color-border); padding-bottom: 12px; font-family: 'Outfit', sans-serif; font-weight: 700; color: var(--color-text-primary); font-size: 1.15rem; margin-top: 0; margin-bottom: 4px;">Generate Invite Code</h4>
          <form class="recruiter-form" method="POST" action="index.php">
            <input type="hidden" name="action" value="generate_link">

            <div class="form-group">
              <label for="job_role">Job Role Target</label>
              <input type="text" name="job_role" id="job_role" class="form-input" placeholder="e.g. React/Next.js Engineer" required>
            </div>

            <div class="form-group">
              <label for="candidate_name">Candidate Name (Optional)</label>
              <input type="text" name="candidate_name" id="candidate_name" class="form-input" placeholder="e.g. Jane Smith">
            </div>

            <div class="form-group">
              <label for="candidate_email">Candidate Email (Optional)</label>
              <input type="email" name="candidate_email" id="candidate_email" class="form-input" placeholder="jane@example.com">
            </div>

            <div class="form-row">
              <div class="form-group">
                <label for="max_attempts">Max Attempts</label>
                <input type="number" name="max_attempts" id="max_attempts" class="form-input" value="1" min="1" required>
              </div>
              <div class="form-group">
                <label for="expires_at">Expires At (Optional)</label>
                <input type="date" name="expires_at" id="expires_at" class="form-input">
              </div>
            </div>

            <button type="submit" class="btn-submit">Generate Invite</button>
          </form>
        </div>

        <!-- Templates removed in favor of direct job role invite codes -->

      </div>

    </div>

  </div>

  <script>
    function switchTab(tabId) {
      // Hide all contents
      document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
      // Deactivate all buttons
      document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
      
      // Activate target content and button
      document.getElementById('tab-' + tabId).classList.add('active');
      
      // Find the tab button clicked
      const clickedBtn = Array.from(document.querySelectorAll('.tab-btn')).find(btn => {
        return btn.getAttribute('onclick').includes(tabId);
      });
      if (clickedBtn) clickedBtn.classList.add('active');
    }

    function copyToClipboard(text) {
      navigator.clipboard.writeText(text).then(() => {
        const toast = document.getElementById('toast');
        toast.style.display = 'block';
        setTimeout(() => {
          toast.style.display = 'none';
        }, 3000);
      }).catch(err => {
        console.error('Copy link failed:', err);
      });
    }

    <?php if (isset($action) && $action === 'update_settings'): ?>
    window.addEventListener('DOMContentLoaded', () => {
      switchTab('settings');
    });
    <?php endif; ?>
  </script>
</body>
</html>
