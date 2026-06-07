<?php
// candidate/index.php - Candidate Dashboard
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

// Enforce Candidate role
requireAuth(['candidate']);

$user = getCurrentUser();
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

    <!-- Main grid layout -->
    <div class="dashboard-grid">
      
      <!-- Session History Panel -->
      <div class="dashboard-panel">
        <div class="panel-header">
          <h3 class="panel-title">Session History</h3>
        </div>

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

      </div>

    </div>

  </div>

</body>
</html>
