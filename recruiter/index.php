<?php
// recruiter/index.php - Recruiter Dashboard
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

// Enforce Recruiter role
requireAuth(['recruiter']);

$user = getCurrentUser();

// Get company profile
$company = getRecruiterCompany($user['id']);
if (!$company) {
    // Gracefully handle if no company exists, auto-create one
    $db = getDB();
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

    try {
        if ($action === 'create_template') {
            $title = $_POST['title'] ?? '';
            $jobRole = $_POST['job_role'] ?? '';
            $description = $_POST['description'] ?? '';
            $difficulty = $_POST['difficulty'] ?? 'medium';
            $duration = $_POST['duration_minutes'] ?? 30;
            $customPrompt = $_POST['custom_system_prompt'] ?? '';
            $topics = $_POST['topics'] ?? '';
            
            if (empty($title) || empty($jobRole)) {
                throw new Exception("Title and Job Role are required.");
            }

            // Parse topics array from comma separated string
            $topicsArr = array_map('trim', explode(',', $topics));
            $topicsArr = array_filter($topicsArr);

            createInterviewTemplate(
                $company['id'],
                $user['id'],
                $title,
                $description,
                $jobRole,
                $topicsArr,
                $difficulty,
                $duration,
                $customPrompt,
                true // MCQ enabled
            );
            $success = "Template '$title' created successfully.";
        }

        if ($action === 'generate_link') {
            $templateId = $_POST['template_id'] ?? '';
            $candidateName = $_POST['candidate_name'] ?? '';
            $candidateEmail = $_POST['candidate_email'] ?? '';
            $maxAttempts = $_POST['max_attempts'] ?? 1;
            $expiresAt = $_POST['expires_at'] ?? '';
            
            if (empty($templateId)) {
                throw new Exception("Please select a template.");
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
                $templateId,
                $company['id'],
                $user['id'],
                $code,
                $candidateEmail,
                $candidateName,
                $maxAttempts,
                $expTimestamp
            );
            $success = "Assessment invite generated successfully. Code: $code";
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch dashboard data lists
$stats = getRecruiterStats($company['id']);
$templates = listInterviewTemplates($company['id']);
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
        <svg style="width: 28px; height: 28px; color: #06b6d4;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
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
        <div class="stat-title">Invite Links</div>
        <div class="stat-value"><?php echo $stats['total_links']; ?></div>
        <div class="stat-desc">Invitation codes generated</div>
      </div>
      <div class="stat-card">
        <div class="stat-title">Candidates Tested</div>
        <div class="stat-value"><?php echo $stats['total_sessions']; ?></div>
        <div class="stat-desc">Candidate assessment runs</div>
      </div>
      <div class="stat-card">
        <div class="stat-title">Completed Assessments</div>
        <div class="stat-value"><?php echo $stats['completed_sessions']; ?></div>
        <div class="stat-desc">Evaluations compiled by Gemini</div>
      </div>
      <div class="stat-card">
        <div class="stat-title">Avg Candidate Rating</div>
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
          <button class="tab-btn" onclick="switchTab('templates')">Job Templates</button>
        </div>

        <!-- Tab 1: Candidates Results -->
        <div id="tab-results" class="tab-content active">
          <div class="results-table-wrapper">
            <?php if (empty($results)): ?>
              <div class="empty-state">
                <svg class="empty-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.109A2.25 2.25 0 0112.75 21.5h-1.5a2.25 2.25 0 01-2.25-2.268v-.11a2.25 2.25 0 00-.786-3.07M11.25 14.25c0-1.113-.285-2.16-.786-3.07M12 9a3.75 3.75 0 100-7.5 3.75 3.75 0 000 7.5z"></path>
                </svg>
                <div>No candidate assessment data yet. Invite candidate via code to generate evaluations.</div>
              </div>
            <?php else: ?>
              <table class="results-table">
                <thead>
                  <tr>
                    <th>Candidate</th>
                    <th>Job Template</th>
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
                      <td style="font-family: monospace; font-weight: 700; color: var(--color-cyan);"><?php echo htmlspecialchars($row['link_code']); ?></td>
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
                          <a href="report.php?session_id=<?php echo urlencode($row['id']); ?>" class="btn-logout" style="border-color: var(--color-cyan); color: #06b6d4; padding: 4px 10px; font-size: 0.8rem;">View Report</a>
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
                <div>No invitation links created yet. Complete the setup form in the sidebar.</div>
              </div>
            <?php else: ?>
              <table class="results-table">
                <thead>
                  <tr>
                    <th>Invite Link / Code</th>
                    <th>Job Template</th>
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
                      <td><?php echo htmlspecialchars($row['template_title']); ?></td>
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
                        <span class="badge <?php echo $active ? 'badge-active' : 'badge-expired'; ?>">
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

        <!-- Tab 3: Job Templates -->
        <div id="tab-templates" class="tab-content">
          <div class="results-table-wrapper">
            <?php if (empty($templates)): ?>
              <div class="empty-state">
                <svg class="empty-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"></path>
                </svg>
                <div>No job templates created yet. Set up one in the sidebar form.</div>
              </div>
            <?php else: ?>
              <table class="results-table">
                <thead>
                  <tr>
                    <th>Template Title</th>
                    <th>Job Role Target</th>
                    <th>Difficulty</th>
                    <th>Assessment Length</th>
                    <th>Topics Included</th>
                    <th>Created At</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($templates as $row): ?>
                    <?php 
                      $parsedTopics = json_decode($row['topics'], true) ?: [];
                    ?>
                    <tr>
                      <td>
                        <div style="font-weight: 600; color: var(--color-text-primary);"><?php echo htmlspecialchars($row['title']); ?></div>
                        <div style="font-size: 0.78rem; color: var(--color-text-muted); max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($row['description']); ?></div>
                      </td>
                      <td><?php echo htmlspecialchars($row['job_role']); ?></td>
                      <td>
                        <span class="badge" style="background: #f1f5f9; text-transform: uppercase; font-size: 0.7rem; color: var(--color-text-secondary); border: 1px solid var(--color-border);">
                          <?php echo htmlspecialchars($row['difficulty']); ?>
                        </span>
                      </td>
                      <td><?php echo (int)$row['duration_minutes']; ?> mins</td>
                      <td>
                        <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                          <?php foreach ($parsedTopics as $top): ?>
                            <span class="badge" style="background: rgba(6, 182, 212, 0.08); color: var(--color-cyan); font-size: 0.7rem;"><?php echo htmlspecialchars($top); ?></span>
                          <?php endforeach; ?>
                        </div>
                      </td>
                      <td style="font-size: 0.85rem; color: var(--color-text-muted);"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Sidebar Form widgets -->
      <div style="display: flex; flex-direction: column; gap: 24px;">
        
        <!-- Generate Assessment Invite Link Form -->
        <div class="dashboard-panel" style="padding: 24px;">
          <h4 class="panel-title" style="border-bottom: 1px solid var(--color-border); padding-bottom: 12px; font-size: 1.1rem;">Generate Invite Code</h4>
          <form class="recruiter-form" method="POST" action="index.php">
            <input type="hidden" name="action" value="generate_link">

            <div class="form-group">
              <label for="template_id">Job Template</label>
              <select name="template_id" id="template_id" class="form-input" required>
                <option value="" disabled selected>-- Select Template --</option>
                <?php foreach ($templates as $t): ?>
                  <option value="<?php echo htmlspecialchars($t['id']); ?>"><?php echo htmlspecialchars($t['title'] . " (" . $t['job_role'] . ")"); ?></option>
                <?php endforeach; ?>
              </select>
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

        <!-- Create Template Form -->
        <div class="dashboard-panel" style="padding: 24px;">
          <h4 class="panel-title" style="border-bottom: 1px solid var(--color-border); padding-bottom: 12px; font-size: 1.1rem;">Create Assessment Template</h4>
          <form class="recruiter-form" method="POST" action="index.php">
            <input type="hidden" name="action" value="create_template">

            <div class="form-group">
              <label for="title">Template Title</label>
              <input type="text" name="title" id="title" class="form-input" placeholder="e.g. Senior Frontend Developer Assessment" required>
            </div>

            <div class="form-group">
              <label for="job_role">Job Role Target</label>
              <input type="text" name="job_role" id="job_role" class="form-input" placeholder="e.g. React/Next.js Engineer" required>
            </div>

            <div class="form-group">
              <label for="description">Job Description Summary</label>
              <textarea name="description" id="description" class="form-input" placeholder="Briefly describe the candidate expectations..." rows="3" style="resize: none; font-family: inherit;"></textarea>
            </div>

            <div class="form-group">
              <label for="topics">Topics (Comma separated)</label>
              <input type="text" name="topics" id="topics" class="form-input" placeholder="e.g. React, Redux, Performance, CSS Grid">
            </div>

            <div class="form-row">
              <div class="form-group">
                <label for="difficulty">Difficulty</label>
                <select name="difficulty" id="difficulty" class="form-input">
                  <option value="easy">Easy</option>
                  <option value="medium" selected>Medium</option>
                  <option value="hard">Hard</option>
                </select>
              </div>
              <div class="form-group">
                <label for="duration_minutes">Length (Minutes)</label>
                <input type="number" name="duration_minutes" id="duration_minutes" class="form-input" value="30" min="5" required>
              </div>
            </div>

            <div class="form-group">
              <label for="custom_system_prompt">Custom AI Prompt (Optional)</label>
              <textarea name="custom_system_prompt" id="custom_system_prompt" class="form-input" placeholder="Instruct the AI interviewer on specific guidelines..." rows="2" style="resize: none; font-family: inherit;"></textarea>
            </div>

            <button type="submit" class="btn-submit" style="background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);">Save Template</button>
          </form>
        </div>

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
  </script>
</body>
</html>
