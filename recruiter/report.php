<?php
// recruiter/report.php - Recruiter View Candidate Report
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

// Enforce Recruiter role
requireAuth(['recruiter']);

$user = getCurrentUser();
$company = getRecruiterCompany($user['id']);

if (!$company) {
    die("Access denied. No company profile found.");
}

$sessionId = $_GET['session_id'] ?? '';

if (empty($sessionId)) {
    die("Session ID is required.");
}

$session = getSession($sessionId);

if (!$session) {
    die("Session not found.");
}

// Security Check: Recruiter can only view reports for assessments assigned by their own company
if (empty($session['interview_link_id'])) {
    die("Access denied. This is a personal practice session, not a company assessment.");
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM interview_links WHERE id = :id");
$stmt->execute(['id' => $session['interview_link_id']]);
$link = $stmt->fetch();

if (!$link || $link['company_id'] !== $company['id']) {
    die("Access denied. You do not have permission to view this report.");
}

if ($session['current_status'] !== 'COMPLETED' || empty($session['final_score'])) {
    die("This report is not ready or has not been completed.");
}

$scoreData = json_decode($session['final_score'], true);
$responses = getCandidateResponses($sessionId);

// Let's get the template title
$stmt = $db->prepare("SELECT title FROM interview_templates WHERE id = :id");
$stmt->execute(['id' => $session['template_id']]);
$templateTitle = $stmt->fetchColumn() ?: 'Technical Assessment';

// Render scorecard circles offsets
$commScore = (float)($scoreData['communication_score'] ?? 0);
$probScore = (float)($scoreData['problem_solving_score'] ?? 0);
$codeScore = (float)($scoreData['code_quality_score'] ?? 0);

$commOffset = 251.2 - (251.2 * $commScore) / 10;
$probOffset = 251.2 - (251.2 * $probScore) / 10;
$codeOffset = 251.2 - (251.2 * $codeScore) / 10;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Candidate Assessment Report - TruInterview</title>
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    body {
      padding: 40px 20px;
    }
    .report-wrapper {
      max-width: 1000px;
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      gap: 24px;
    }
    .back-nav {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .btn-back {
      background: var(--color-surface-elevated);
      border: 1px solid var(--color-border);
      color: var(--color-text-primary);
      padding: 10px 20px;
      border-radius: 8px;
      font-size: 0.9rem;
      font-weight: 600;
      text-decoration: none;
      transition: var(--transition-smooth);
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    .btn-back:hover {
      border-color: var(--color-accent);
      transform: translateX(-2px);
    }
    .results-container {
      display: block !important;
      animation: fadeIn 0.5s ease;
    }
  </style>
</head>
<body class="theme-light">

  <div class="report-wrapper">
    
    <div class="back-nav">
      <a href="index.php" class="btn-back">
        <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
        </svg>
        <span>Back to Dashboard</span>
      </a>
    </div>

    <div class="results-container">
      
      <div class="results-header-section">
        <div class="results-header-info">
          <h2>Candidate Assessment Report</h2>
          <p>Candidate: <?php echo htmlspecialchars($session['candidate_name']); ?> (<?php echo htmlspecialchars($session['email']); ?>) | Role: <?php echo htmlspecialchars($templateTitle); ?> | Completed: <?php echo date('M d, Y h:i A', strtotime($session['completed_at'])); ?></p>
        </div>
      </div>
      
      <div class="metrics-row">
        <!-- Communication Rating -->
        <div class="metric-card metric-communication">
          <div class="metric-chart-wrapper">
            <svg class="metric-circle-svg" viewBox="0 0 100 100">
              <circle class="metric-circle-bg" cx="50" cy="50" r="40"></circle>
              <circle class="metric-circle-fill" cx="50" cy="50" r="40" style="stroke-dashoffset: <?php echo $commOffset; ?>;"></circle>
            </svg>
            <span class="metric-score-text"><?php echo $commScore; ?>/10</span>
          </div>
          <div class="metric-details">
            <h4>Communication & Presence</h4>
            <p><?php echo htmlspecialchars($scoreData['communication_feedback'] ?? 'No communication comments.'); ?></p>
          </div>
        </div>

        <!-- Problem Solving Rating -->
        <div class="metric-card metric-problem-solving">
          <div class="metric-chart-wrapper">
            <svg class="metric-circle-svg" viewBox="0 0 100 100">
              <circle class="metric-circle-bg" cx="50" cy="50" r="40"></circle>
              <circle class="metric-circle-fill" cx="50" cy="50" r="40" style="stroke-dashoffset: <?php echo $probOffset; ?>;"></circle>
            </svg>
            <span class="metric-score-text"><?php echo $probScore; ?>/10</span>
          </div>
          <div class="metric-details">
            <h4>Problem Solving</h4>
            <p><?php echo htmlspecialchars($scoreData['problem_solving_feedback'] ?? 'No problem solving comments.'); ?></p>
          </div>
        </div>

        <!-- Code Quality Rating -->
        <div class="metric-card metric-code-quality">
          <div class="metric-chart-wrapper">
            <svg class="metric-circle-svg" viewBox="0 0 100 100">
              <circle class="metric-circle-bg" cx="50" cy="50" r="40"></circle>
              <circle class="metric-circle-fill" cx="50" cy="50" r="40" style="stroke-dashoffset: <?php echo $codeOffset; ?>;"></circle>
            </svg>
            <span class="metric-score-text"><?php echo $codeScore; ?>/10</span>
          </div>
          <div class="metric-details">
            <h4>Code Quality & Logic</h4>
            <p><?php echo htmlspecialchars($scoreData['code_quality_feedback'] ?? 'No code quality comments.'); ?></p>
          </div>
        </div>
      </div>

      <!-- Strengths & Recommendations -->
      <div class="feedback-grid">
        <div class="feedback-card strengths-card">
          <div class="feedback-card-header">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"></path>
            </svg>
            <h3>Key Strengths</h3>
          </div>
          <ul class="feedback-list">
            <?php if (!empty($scoreData['strengths']) && is_array($scoreData['strengths'])): ?>
              <?php foreach ($scoreData['strengths'] as $str): ?>
                <li class="feedback-item"><?php echo htmlspecialchars($str); ?></li>
              <?php endforeach; ?>
            <?php else: ?>
              <li class="feedback-item">No feedback points generated.</li>
            <?php endif; ?>
          </ul>
        </div>

        <div class="feedback-card recommendations-card">
          <div class="feedback-card-header">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
            </svg>
            <h3>Areas for Development</h3>
          </div>
          <ul class="feedback-list">
            <?php if (!empty($scoreData['recommendations']) && is_array($scoreData['recommendations'])): ?>
              <?php foreach ($scoreData['recommendations'] as $rec): ?>
                <li class="feedback-item"><?php echo htmlspecialchars($rec); ?></li>
              <?php endforeach; ?>
            <?php else: ?>
              <li class="feedback-item">No recommendations points generated.</li>
            <?php endif; ?>
          </ul>
        </div>
      </div>

      <!-- Overall Summary -->
      <div class="overall-feedback-card">
        <h3>Interviewer Executive Summary</h3>
        <p><?php echo htmlspecialchars($scoreData['overall_feedback'] ?? 'No summary comments.'); ?></p>
      </div>

      <!-- MCQ Summary Section -->
      <?php if (!empty($responses)): ?>
        <?php 
          $correctCount = 0;
          foreach ($responses as $r) {
              if ($r['is_correct']) $correctCount++;
          }
          $totalCount = count($responses);
          $percentage = $totalCount > 0 ? Math_round(($correctCount / $totalCount) * 100) : 0;
          function Math_round($val) { return round($val); }
        ?>
        <div class="mcq-summary-section" style="margin-top: 32px;">
          <div class="mcq-summary-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--color-border); padding-bottom: 12px; margin-bottom: 20px;">
            <h3>Interactive MCQ Performance</h3>
            <span class="badge" style="background: <?php echo $percentage >= 70 ? 'var(--color-success-bg)' : 'var(--color-danger-bg)'; ?>; color: <?php echo $percentage >= 70 ? 'var(--color-success)' : 'var(--color-danger)'; ?>; padding: 6px 12px; font-weight: 700;">
              Score: <?php echo $correctCount; ?>/<?php echo $totalCount; ?> (<?php echo $percentage; ?>%)
            </span>
          </div>
          <div class="mcq-summary-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px;">
            <?php foreach ($responses as $r): ?>
              <?php 
                $isCorrect = (bool)$r['is_correct'];
                $statusClass = $isCorrect ? 'correct' : 'incorrect';
                $statusText = $isCorrect ? 'Correct' : 'Incorrect';
              ?>
              <div class="mcq-summary-card" style="background: var(--color-surface-elevated); border: 1px solid var(--color-border); border-radius: 12px; padding: 16px; display: flex; flex-direction: column; gap: 8px;">
                <div class="mcq-card-topic" style="font-size: 0.75rem; text-transform: uppercase; color: var(--color-accent); font-weight: 700; letter-spacing: 0.5px;"><?php echo htmlspecialchars($r['topic']); ?></div>
                <div class="mcq-card-question-text" style="font-size: 0.9rem; font-weight: 500; line-height: 1.4;"><?php echo htmlspecialchars($r['question']); ?></div>
                <div class="mcq-card-answers" style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px;">
                  <span class="mcq-ans-badge <?php echo $statusClass; ?>" style="font-size: 0.75rem; font-weight: 600; padding: 4px 8px; border-radius: 6px;"><?php echo $statusText; ?> (Selected <?php echo htmlspecialchars($r['selected_option']); ?>)</span>
                  <?php if (!$isCorrect): ?>
                    <span class="mcq-ans-badge expected" style="font-size: 0.75rem; font-weight: 600; padding: 4px 8px; border-radius: 6px; background: var(--color-success-bg); color: var(--color-success);">Correct: <?php echo htmlspecialchars($r['correct_option']); ?></span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

    </div>

  </div>

</body>
</html>
