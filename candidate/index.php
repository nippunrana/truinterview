<?php
// candidate/index.php - Candidate Dashboard
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

// Enforce Candidate role
requireAuth(['candidate']);

$user = getCurrentUser();

$db = getDB();
$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $user['id']]);
$userFull = $stmt->fetch(PDO::FETCH_ASSOC);

$error = '';
$success = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_settings') {
        $apiKey = $_POST['custom_gemini_api_key'] ?? '';
        $modelChat = $_POST['model_chat_task'] ?? 'gemini-3.5-flash';
        $modelVision = $_POST['model_vision_task'] ?? 'gemini-3.5-flash';
        $modelEval = $_POST['model_eval_task'] ?? 'gemini-3.5-flash';
        
        try {
            $stmt = $db->prepare("UPDATE users SET 
                custom_gemini_api_key = :api_key, 
                model_chat_task = :model_chat, 
                model_vision_task = :model_vision, 
                model_eval_task = :model_eval 
                WHERE id = :id");
            $stmt->execute([
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
    } elseif ($action === 'upload_resume') {
        if (isset($_FILES['resume_file']) && $_FILES['resume_file']['error'] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['resume_file']['tmp_name'];
            $fileName = $_FILES['resume_file']['name'];
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            $allowed = ['pdf', 'doc', 'docx', 'csv', 'md', 'markdown'];
            if (in_array($ext, $allowed)) {
                $uploadDir = __DIR__ . '/../uploads/resumes/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $newFileName = $user['id'] . '_' . time() . '.' . $ext;
                $dest = $uploadDir . $newFileName;
                
                if (move_uploaded_file($tmpName, $dest)) {
                    $resumePath = 'uploads/resumes/' . $newFileName;
                    try {
                        $stmt = $db->prepare("UPDATE users SET resume_path = :path WHERE id = :id");
                        $stmt->execute(['path' => $resumePath, 'id' => $user['id']]);
                        $success = "Resume uploaded successfully.";
                        
                        // Re-fetch user profile
                        $stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
                        $stmt->execute(['id' => $user['id']]);
                        $userFull = $stmt->fetch(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {
                        $error = "Failed to update database: " . $e->getMessage();
                    }
                } else {
                    $error = "Failed to move uploaded file.";
                }
            } else {
                $error = "Invalid file type. Allowed: PDF, DOC/DOCX, CSV, MD.";
            }
        } else {
            $error = "File upload error. Please select a valid file.";
        }
    }
}

$stats = getCandidateStats($user['id']);
$history = listCandidateHistory($user['id']);

// Get initials for avatar placeholder
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
  <title>Candidate Dashboard - TruInterview</title>
  <link rel="stylesheet" href="../assets/css/candidate.css">
</head>
<body class="dashboard-body">

  <div class="dashboard-wrapper">
    
    <!-- Header -->
    <header class="dashboard-header">
      <a href="../index.php" class="brand-wrapper">
        <svg style="width: 28px; height: 28px; color: #06b6d4;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
        </svg>
        <span class="brand-title">TruInterview</span>
      </a>
      
      <div class="user-profile">
        <div class="user-info">
          <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
          <div class="user-role">Candidate Account</div>
        </div>
        <div class="avatar"><?php echo htmlspecialchars($initials); ?></div>
        <a href="../logout.php" class="btn-logout">Log Out</a>
      </div>
    </header>

    <!-- Stats grid -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-title">Total Practice Runs</div>
        <div class="stat-value"><?php echo $stats['total_sessions']; ?></div>
        <div class="stat-desc">Sessions started or completed</div>
      </div>
      <div class="stat-card">
        <div class="stat-title">Completed Assessments</div>
        <div class="stat-value"><?php echo $stats['completed_sessions']; ?></div>
        <div class="stat-desc">Recruiter-assigned assessments completed</div>
      </div>
      <div class="stat-card">
        <div class="stat-title">Average Rating</div>
        <div class="stat-value">
          <?php echo $stats['average_score'] > 0 ? $stats['average_score'] . '/10' : '-'; ?>
        </div>
        <div class="stat-desc">Communication, coding & problem-solving avg</div>
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

    <!-- Main grid layout -->
    <div class="dashboard-grid">
      
      <!-- Session History Panel -->
      <div class="dashboard-panel">
        <div class="panel-header" style="border-bottom: none; padding-bottom: 0;">
          <div style="display: flex; gap: 24px; align-items: center;">
            <button id="btn-tab-history" class="tab-btn active" onclick="switchTab('history')" style="background: none; border: none; font-family: 'Outfit', sans-serif; font-size: 1.2rem; font-weight: 600; color: var(--color-indigo); cursor: pointer; padding-bottom: 8px; border-bottom: 2px solid var(--color-indigo); transition: all 0.2s;">Session History</button>
            <button id="btn-tab-settings" class="tab-btn" onclick="switchTab('settings')" style="background: none; border: none; font-family: 'Outfit', sans-serif; font-size: 1.2rem; font-weight: 500; color: var(--color-text-muted); cursor: pointer; padding-bottom: 8px; border-bottom: 2px solid transparent; transition: all 0.2s;">Settings</button>
          </div>
        </div>

        <!-- Tab Content: Session History -->
        <div id="tab-history" class="tab-content" style="display: block;">
          <div class="history-table-wrapper">
            <?php if (empty($history)): ?>
              <div class="empty-state">
                <svg class="empty-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <div>You haven't completed any sessions yet. Launch a practice run or enter a recruiter link below to start.</div>
              </div>
            <?php else: ?>
              <table class="history-table">
                <thead>
                  <tr>
                    <th>Session Date</th>
                    <th>Assessment Topic</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Score</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($history as $row): ?>
                    <?php 
                      $sessionType = $row['session_type'] ?? 'practice'; 
                      $status = $row['current_status'] ?? 'STARTED';
                      
                      // Parse scores
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
                      <td><?php echo date('M d, Y h:i A', strtotime($row['started_at'])); ?></td>
                      <td><?php echo htmlspecialchars($row['template_title'] ?? 'General Tech Practice'); ?></td>
                      <td>
                        <span class="badge badge-<?php echo $sessionType; ?>">
                          <?php echo ucfirst($sessionType); ?>
                        </span>
                      </td>
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
                        <?php elseif ($status !== 'COMPLETED'): ?>
                          <a href="../interview.php" class="btn-logout" style="border-color: #f59e0b; color: #fbbf24; padding: 4px 10px; font-size: 0.8rem;">Resume</a>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>

        <!-- Tab Content: Settings -->
        <div id="tab-settings" class="tab-content" style="display: none;">
          <form method="POST" action="index.php" style="display: flex; flex-direction: column; gap: 24px;">
            <input type="hidden" name="action" value="update_settings">
            
            <div style="background: #f8fafc; border: 1px solid var(--color-border); padding: 24px; border-radius: var(--radius-inner);">
              <h4 style="margin-top: 0; margin-bottom: 16px; font-family: 'Outfit', sans-serif; font-size: 1.15rem; color: var(--color-text-primary); font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <svg style="width: 20px; height: 20px; color: var(--color-indigo);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                AI Brain settings
              </h4>
              
              <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px;">
                <label style="font-size: 0.88rem; font-weight: 600; color: var(--color-text-secondary);">Custom Gemini API Key</label>
                <input type="password" name="custom_gemini_api_key" class="form-input" 
                       placeholder="e.g. AIzaSy..." 
                       value="<?php echo htmlspecialchars($userFull['custom_gemini_api_key'] ?? ''); ?>">
                <span style="font-size: 0.8rem; color: var(--color-text-muted); display: block; line-height: 1.4;">
                  If empty, the platform global API key is used. Your settings will override only for your own practice sessions.
                </span>
              </div>
              
              <div style="display: flex; flex-direction: column; gap: 16px;">
                <div style="display: flex; flex-direction: column; gap: 6px;">
                  <label style="font-size: 0.88rem; font-weight: 600; color: var(--color-text-secondary); display: flex; justify-content: space-between; align-items: center;">
                    <span>Dialogue (Chat) Model Override</span>
                    <span style="font-weight: normal; font-size: 0.76rem; color: var(--color-cyan);">Recommended: Low latency (Flash/Flash-Lite)</span>
                  </label>
                  <select name="model_chat_task" class="form-input" style="padding: 10px 12px; background: #fff;">
                    <option value="gemini-3.5-flash" <?php if (($userFull['model_chat_task'] ?? '') === 'gemini-3.5-flash') echo 'selected'; ?>>gemini-3.5-flash (Fast, conversational)</option>
                    <option value="gemini-3.1-flash-lite" <?php if (($userFull['model_chat_task'] ?? '') === 'gemini-3.1-flash-lite') echo 'selected'; ?>>gemini-3.1-flash-lite (Ultra-low latency dialog)</option>
                    <option value="gemini-3.1-pro-preview" <?php if (($userFull['model_chat_task'] ?? '') === 'gemini-3.1-pro-preview') echo 'selected'; ?>>gemini-3.1-pro (Deep, rich answers)</option>
                  </select>
                </div>

                <div style="display: flex; flex-direction: column; gap: 6px;">
                  <label style="font-size: 0.88rem; font-weight: 600; color: var(--color-text-secondary); display: flex; justify-content: space-between; align-items: center;">
                    <span>Screen Context (Vision) Model Override</span>
                    <span style="font-weight: normal; font-size: 0.76rem; color: var(--color-cyan);">Recommended: Balanced speed/comprehension</span>
                  </label>
                  <select name="model_vision_task" class="form-input" style="padding: 10px 12px; background: #fff;">
                    <option value="gemini-3.5-flash" <?php if (($userFull['model_vision_task'] ?? '') === 'gemini-3.5-flash') echo 'selected'; ?>>gemini-3.5-flash (Balanced speed)</option>
                    <option value="gemini-3.1-pro-preview" <?php if (($userFull['model_vision_task'] ?? '') === 'gemini-3.1-pro-preview') echo 'selected'; ?>>gemini-3.1-pro (High intelligence code understanding)</option>
                    <option value="gemini-3.1-flash-lite" <?php if (($userFull['model_vision_task'] ?? '') === 'gemini-3.1-flash-lite') echo 'selected'; ?>>gemini-3.1-flash-lite (Fastest processing)</option>
                  </select>
                </div>

                <div style="display: flex; flex-direction: column; gap: 6px;">
                  <label style="font-size: 0.88rem; font-weight: 600; color: var(--color-text-secondary); display: flex; justify-content: space-between; align-items: center;">
                    <span>Evaluation (Grading) Model Override</span>
                    <span style="font-weight: normal; font-size: 0.76rem; color: var(--color-cyan);">Recommended: Pro for deep scorecard reasoning</span>
                  </label>
                  <select name="model_eval_task" class="form-input" style="padding: 10px 12px; background: #fff;">
                    <option value="gemini-3.1-pro-preview" <?php if (($userFull['model_eval_task'] ?? '') === 'gemini-3.1-pro-preview') echo 'selected'; ?>>gemini-3.1-pro (Advanced grading report evaluation)</option>
                    <option value="gemini-3.5-flash" <?php if (($userFull['model_eval_task'] ?? '') === 'gemini-3.5-flash') echo 'selected'; ?>>gemini-3.5-flash (Standard grading evaluation)</option>
                    <option value="gemini-3.1-flash-lite" <?php if (($userFull['model_eval_task'] ?? '') === 'gemini-3.1-flash-lite') echo 'selected'; ?>>gemini-3.1-flash-lite (Fast grading evaluation)</option>
                  </select>
                </div>
              </div>
            </div>
            
            <button type="submit" class="btn-primary-action" style="align-self: flex-start; min-width: 180px; padding: 12px 24px;">Save Settings</button>
          </form>
        </div>
      </div>

      <!-- Action Widget Panels -->
      <div class="actions-panel">
        
        <!-- Practice Run Widget -->
        <div class="action-card">
          <h4 class="action-title">Self-Practice Session</h4>
          <p class="action-desc">
            Hone your skills in a simulated interview. Answer coding and algorithmic questions under natural conversational constraints.
          </p>
          <a href="../interview.php" class="btn-primary-action">Start Practice Run</a>
        </div>

        <!-- Recruiter Invite Widget -->
        <div class="action-card">
          <h4 class="action-title">Recruiter Assessment</h4>
          <p class="action-desc">
            Received an interview link code from an employer? Enter it below to begin your assigned screening test.
          </p>
          <form action="../interview.php" method="GET" style="display: flex; flex-direction: column; gap: 12px;">
            <input type="text" name="code" class="form-input" placeholder="e.g. TRU-8X2A" required style="text-align: center; text-transform: uppercase;">
            <button type="submit" class="btn-secondary-action">Launch Screening</button>
          </form>
        </div>

        <!-- Resume Upload Widget -->
        <div class="action-card">
          <h4 class="action-title">Upload Resume</h4>
          <p class="action-desc">
            Keep your profile up to date. Upload your latest resume (PDF, DOC/DOCX, CSV, Markdown).
          </p>
          <?php if (!empty($userFull['resume_path'])): ?>
            <div style="margin-bottom: 12px; font-size: 0.85rem; color: #059669; display: flex; align-items: center; gap: 6px;">
              <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg>
              Resume uploaded. <a href="../<?php echo htmlspecialchars($userFull['resume_path']); ?>" target="_blank" style="color: var(--color-indigo); text-decoration: underline;">View File</a>
            </div>
          <?php endif; ?>
          <form action="index.php" method="POST" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 12px;">
            <input type="hidden" name="action" value="upload_resume">
            <input type="file" name="resume_file" accept=".pdf,.doc,.docx,.csv,.md,.markdown" class="form-input" required style="font-size: 0.85rem; padding: 8px;">
            <button type="submit" class="btn-secondary-action">Upload Resume</button>
          </form>
        </div>

      </div>

    </div>

  </div>

  <script>
    function switchTab(tabId) {
      // Hide all contents
      document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
      // Deactivate all buttons
      document.querySelectorAll('.tab-btn').forEach(el => {
        el.classList.remove('active');
        el.style.color = 'var(--color-text-muted)';
        el.style.borderBottomColor = 'transparent';
        el.style.fontWeight = '500';
      });
      
      // Activate target content and button
      document.getElementById('tab-' + tabId).style.display = 'block';
      const activeBtn = document.getElementById('btn-tab-' + tabId);
      if (activeBtn) {
        activeBtn.classList.add('active');
        activeBtn.style.color = 'var(--color-indigo)';
        activeBtn.style.borderBottomColor = 'var(--color-indigo)';
        activeBtn.style.fontWeight = '600';
      }
    }

    <?php if (isset($action) && $action === 'update_settings'): ?>
    window.addEventListener('DOMContentLoaded', () => {
      switchTab('settings');
    });
    <?php endif; ?>
  </script>
</body>
</html>
