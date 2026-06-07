<?php
// interview.php - Main Frontend Panel Shell
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// Enforce login for all users visiting this page
requireAuth();

// Enforce HTTPS in non-local environments
$host = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
$isLocal = in_array($host, ['localhost', '127.0.0.1']) || preg_match('/^192\.168\./', $host);
if (!$isLocal && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) {
    $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . $redirect);
    exit();
}

$sessionId = $_COOKIE['session_id'] ?? '';
$session = null;
if (!empty($sessionId)) {
    $session = getSession($sessionId);
}

// Handle invite code parameter
$inviteCode = $_GET['code'] ?? '';
$prefName = '';
$prefEmail = '';

// Check if candidate is logged in to prefill name/email
$currentUser = getCurrentUser();
if ($currentUser && $currentUser['role'] === 'candidate') {
    $prefName = $currentUser['full_name'];
    $prefEmail = $currentUser['email'];
}

// If code is supplied, fetch code constraints
$inviteError = '';
$codeDetails = null;
if (!empty($inviteCode)) {
    $codeDetails = getInterviewLinkByCode($inviteCode);
    if (!$codeDetails) {
        $inviteError = "Invalid or inactive invitation code.";
    } elseif ($codeDetails['expires_at'] && strtotime($codeDetails['expires_at']) < time()) {
        $inviteError = "This invitation code has expired.";
    } elseif ($codeDetails['attempts_used'] >= $codeDetails['max_attempts']) {
        $inviteError = "This invitation code has already been used.";
    } else {
        // Prefill candidate name/email if specified in invite link
        if ($codeDetails['candidate_name']) {
            $prefName = $codeDetails['candidate_name'];
        }
        if ($codeDetails['candidate_email']) {
            $prefEmail = $codeDetails['candidate_email'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TruInterview - AI Multimodal Technical Interviewer</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <?php if ($session && $session['current_status'] === 'COMPLETED'): ?>
    <style>
      .workspace-grid { display: none !important; }
    </style>
  <?php endif; ?>
  <script>
    // Inline script to prevent theme flash before body render
    (function() {
      const savedTheme = localStorage.getItem('theme') || 'light';
      document.documentElement.className = 'theme-' + savedTheme;
      // Also apply directly to body when loaded
      window.addEventListener('DOMContentLoaded', () => {
        document.body.className = 'theme-' + savedTheme;
        updateThemeToggleButton(savedTheme);
      });
    })();

    function updateThemeToggleButton(theme) {
      const btn = document.getElementById('theme-toggle-btn');
      if (!btn) return;
      if (theme === 'light') {
        btn.innerHTML = `
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
          <span>Dark Mode</span>`;
      } else {
        btn.innerHTML = `
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m0-12.728l.707.707m12.728 12.728l.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z"></path></svg>
          <span>Light Mode</span>`;
      }
    }
  </script>
</head>
<body class="theme-light">

  <?php if (!$session): ?>
    <!-- Onboarding Page (No session active) -->
    <div id="registration-modal" class="onboarding-page" style="display: flex;">
      <div class="onboarding-card">
        <div class="onboarding-header">
          <div class="brand-center">
            <svg style="width: 32px; height: 32px; color: var(--color-accent);" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            <span class="brand-logo" style="font-size: var(--text-2xl); font-family: 'Outfit', sans-serif; font-weight: 600; background: var(--color-accent-gradient); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;">TruInterview</span>
          </div>
          <?php if (!empty($inviteCode) && empty($inviteError)): ?>
            <h2>Company Assessment</h2>
            <p style="color: var(--color-accent); font-weight: 600;">You are launching an assessment for: <?php echo htmlspecialchars($codeDetails['template_title'] ?? 'Technical Assessment'); ?></p>
          <?php else: ?>
            <h2>Configure Your Session</h2>
            <p>Enter your details below to begin your interactive mock technical interview assessment.</p>
          <?php endif; ?>
        </div>

        <?php if (!empty($inviteError)): ?>
          <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); color: #f87171; padding: 12px; border-radius: 8px; font-size: 0.9rem; margin-bottom: 16px; text-align: center;">
            <?php echo htmlspecialchars($inviteError); ?>
          </div>
        <?php endif; ?>

        <form id="registration-form" onsubmit="handleRegister(event)">
          <input type="hidden" id="invite_code" value="<?php echo htmlspecialchars($inviteCode); ?>">
          
          <div class="form-group" style="margin-bottom: var(--space-4);">
            <label>Full Name</label>
            <?php if (!empty($prefName)): ?>
              <div class="form-value"><?php echo htmlspecialchars($prefName); ?></div>
              <input type="hidden" id="candidate_name" value="<?php echo htmlspecialchars($prefName); ?>">
            <?php else: ?>
              <input type="text" id="candidate_name" class="form-input" placeholder="e.g. John Doe" required autocomplete="name" value="" <?php if (!empty($inviteError)) echo 'disabled'; ?>>
            <?php endif; ?>
          </div>
          <div class="form-group" style="margin-bottom: var(--space-6);">
            <label>Email Address</label>
            <?php if (!empty($prefEmail)): ?>
              <div class="form-value"><?php echo htmlspecialchars($prefEmail); ?></div>
              <input type="hidden" id="candidate_email" value="<?php echo htmlspecialchars($prefEmail); ?>">
            <?php else: ?>
              <input type="email" id="candidate_email" class="form-input" placeholder="e.g. john@example.com" required autocomplete="email" value="" <?php if (!empty($inviteError)) echo 'disabled'; ?>>
            <?php endif; ?>
          </div>
          <button type="submit" class="btn-action" style="width: 100%;" <?php if (!empty($inviteError)) echo 'disabled'; ?>>
            <span>Start Assessment</span>
            <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
          </button>
        </form>
        <div class="onboarding-footer" style="text-align: center; margin-top: var(--space-4);">
          <a href="<?php echo isLoggedIn() ? 'candidate/index.php' : 'index.php'; ?>" class="back-link">
            <svg style="width: 14px; height: 14px; display: inline; vertical-align: middle; margin-right: 4px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
            <?php echo isLoggedIn() ? 'Back to Dashboard' : 'Back to Landing Page'; ?>
          </a>
        </div>
      </div>
    </div>
  <?php else: ?>
    <!-- Main App Container -->
    <div class="app-container">
    <header>
      <div class="brand">
        <svg style="width: 24px; height: 24px; color: var(--color-accent);" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
        <span class="brand-logo">TruInterview</span>
      </div>
      
      <div class="header-meta">
        <div class="session-indicator">
          <div class="indicator-dot"></div>
          <span id="session-status-text">Connecting</span>
        </div>
        <div class="session-timer">
          <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
          <span id="timer-display">00:00</span>
        </div>
        <!-- End Interview Button (shown during active interview) -->
        <button id="end-interview-header-btn" class="btn-action btn-danger" onclick="transitionToCompleted()" title="End Interview" style="display: <?php echo ($session && $session['current_status'] !== 'COMPLETED') ? 'flex' : 'none'; ?>;">
          <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 5H5a2 2 0 00-2 2v10a2 2 0 002 2h14a2 2 0 002-2V7a2 2 0 00-2-2zM9 9h6v6H9V9z"></path></svg>
          <span>End Interview</span>
        </button>

        <!-- Start New Interview Button (shown when interview is completed) -->
        <button id="new-interview-header-btn" class="btn-action" onclick="startNewInterview()" title="Start New Interview" style="display: <?php echo ($session && $session['current_status'] === 'COMPLETED') ? 'flex' : 'none'; ?>;">
          <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"></path></svg>
          <span>New Interview</span>
        </button>
        <button id="theme-toggle-btn" class="btn-theme-toggle" onclick="toggleTheme()">
          <!-- SVG injected by JS -->
        </button>
      </div>
    </header>

    <div class="workspace-grid">
      <!-- Left Panel: Video Agent -->
      <div class="panel left-panel">
        <div class="panel-header">
          <h3 class="panel-title">AI Interviewer</h3>
        </div>
        <div class="agent-video-container" id="agent-video-container">
          <?php if ($session && $session['current_status'] !== 'COMPLETED'): ?>
            <?php $trugenAgentId = getenv('TRUGEN_AGENT_ID'); ?>
            <?php if (!empty($trugenAgentId)): ?>
              <iframe 
                src="https://app.trugen.ai/agent/<?php echo urlencode($trugenAgentId); ?>" 
                allow="camera; microphone; display-capture" 
                style="width: 100%; height: 100%; border: none; z-index: 4; position: absolute; top: 0; left: 0; background: #000;">
              </iframe>
            <?php endif; ?>
          <?php endif; ?>
          <video id="candidate-video" autoplay playsinline muted style="position: absolute; bottom: 12px; right: 12px; width: 120px; height: 90px; border-radius: var(--radius-inner); border: 2px solid var(--color-border); z-index: 5; object-fit: cover; display: none; background: #000;"></video>
          <div class="agent-video-placeholder">
            <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"></path></svg>
            <p>Agent Video Connection Pending</p>
          </div>
        </div>
        
        <!-- Waveform Visualizer -->
        <div class="visualizer-container" id="visualizer-container">
          <!-- Bars generated by JS -->
        </div>

        <div class="media-controls">
          <button id="mic-toggle" class="btn-control" onclick="toggleMedia('mic')" title="Toggle Microphone">
            <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path></svg>
          </button>
          <button id="camera-toggle" class="btn-control" onclick="toggleMedia('camera')" title="Toggle Camera">
            <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
          </button>
          <button id="end-interview-btn" class="btn-control danger" onclick="transitionToCompleted()" title="End Interview">
            <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 5H5a2 2 0 00-2 2v10a2 2 0 002 2h14a2 2 0 002-2V7a2 2 0 00-2-2zM9 9h6v6H9V9z"></path></svg>
          </button>
        </div>
      </div>

      <!-- Right Panel: Candidate Console -->
      <div class="panel right-panel">
        <div class="console-grid">
          
          <!-- Screen Capture Preview Row -->
          <div class="console-section">
            <h4 style="margin-bottom: var(--space-2); font-size: var(--text-sm); text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-text-secondary);">Screen Context Share</h4>
            <div class="screen-capture-container">
              <div class="screen-preview" id="screen-preview">
                <div class="screen-placeholder">
                  <svg style="width: 36px; height: 36px; opacity: 0.4;" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25"></path></svg>
                  <p style="font-size: var(--text-xs);">Screen stream inactive</p>
                </div>
                <canvas id="capture-canvas"></canvas>
              </div>
              <div class="screen-controls">
                <button id="screen-share-btn" class="btn-action btn-secondary" onclick="toggleScreenShare()">
                  <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"></path></svg>
                  <span>Share Screen</span>
                </button>
                <button id="submit-screenshot-btn" class="btn-action" onclick="submitAnswer()" disabled>
                  <span>Submit Code / Answer</span>
                </button>
              </div>
            </div>
          </div>

          <!-- MCQ Questions Row -->
          <div class="console-section" style="overflow-y: auto;">
            <h4 style="margin-bottom: var(--space-2); font-size: var(--text-sm); text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-text-secondary);">Interactive MCQ Assessment</h4>
            <div class="mcq-container" id="mcq-container">
              <div class="mcq-question-card" style="opacity: 0.7; text-align: center; justify-content: center; height: 100%;">
                <p class="mcq-text" style="color: var(--color-text-muted);">Waiting for the AI interviewer to load questions...</p>
              </div>
            </div>
          </div>

          <!-- Dialog Transcript Row -->
          <div class="console-section" style="background: rgba(0,0,0,0.1);">
            <h4 style="margin-bottom: var(--space-2); font-size: var(--text-sm); text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-text-secondary);">Live Transcripts</h4>
            <div class="transcripts-feed" id="transcripts-feed">
              <!-- Transcript elements injected here -->
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <script>
    const sessionActive = <?php echo $session ? 'true' : 'false'; ?>;
    const sessionId = '<?php echo $sessionId; ?>';
    const startedTime = '<?php echo $session ? $session['started_at'] : ''; ?>';
    const sessionStatus = '<?php echo $session ? $session['current_status'] : ''; ?>';
    const hasFinalScore = <?php echo ($session && !empty($session['final_score'])) ? 'true' : 'false'; ?>;
  </script>
  <script src="assets/js/app.js" defer></script>
</body>
</html>
