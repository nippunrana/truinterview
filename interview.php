<?php
// interview.php - Main Frontend Panel Shell
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// Enforce login for all users visiting this page
requireAuth();

// Prevent caching and disable back-forward cache (BFcache) to force Safari to release media locks on unload
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Enforce HTTPS in non-local environments
$host = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
$isLocal = in_array($host, ['localhost', '127.0.0.1']) || preg_match('/^192\.168\./', $host);
if (!$isLocal && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) {
    $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . $redirect);
    exit();
}

$profileId = $_GET['profile_id'] ?? '';
$inviteCode = $_GET['code'] ?? '';
$sessionId = $_GET['session_id'] ?? $_COOKIE['session_id'] ?? '';
$session = null;
$trugenAgentId = '';
if (!empty($sessionId)) {
    $session = getSession($sessionId);
    if ($session) {
        $hasTarget = !empty($profileId) || !empty($inviteCode);
        if ($hasTarget && $session['current_status'] === 'COMPLETED') {
            $session = null;
            setcookie("session_id", "", time() - 3600, "/");
            $_COOKIE['session_id'] = "";
            $sessionId = "";
        } elseif (!empty($profileId) && (!isset($session['profile_id']) || $session['profile_id'] != $profileId)) {
            $session = null;
            setcookie("session_id", "", time() - 3600, "/");
            $_COOKIE['session_id'] = "";
            $sessionId = "";
        } else {
            if (isset($_GET['session_id'])) {
                setcookie("session_id", $sessionId, time() + 86400, "/");
            }
            $userSettings = getSessionUserSettings($session['id']);
            $trugenAgentId = (!empty($userSettings['custom_trugen_agent_id'])) ? $userSettings['custom_trugen_agent_id'] : getenv('TRUGEN_AGENT_ID');
        }
    }
}
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
  <!-- Lottie animation player -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/lottie-web/5.12.2/lottie.min.js" defer></script>
  <style>
    /* ── AI Interviewer Avatar Panel ──────────────────────────── */
    .ai-interviewer-panel {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      width: 100%;
      height: 100%;
      min-height: 220px;
      padding: var(--space-4);
      position: relative;
      gap: var(--space-3);
    }
    .lottie-avatar-container {
      position: relative;
      width: 140px;
      height: 140px;
      border-radius: 50%;
      background: rgba(99, 102, 241, 0.06);
      border: 2px solid rgba(99, 102, 241, 0.2);
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      transition: border-color 0.4s ease, box-shadow 0.4s ease;
      flex-shrink: 0;
    }
    .lottie-avatar-container.avatar-speaking {
      border-color: rgba(99, 102, 241, 0.8);
      box-shadow: 0 0 0 6px rgba(99, 102, 241, 0.12), 0 0 0 12px rgba(99, 102, 241, 0.05);
    }
    .lottie-avatar-container.avatar-listening {
      border-color: rgba(16, 185, 129, 0.7);
      box-shadow: 0 0 0 6px rgba(16, 185, 129, 0.1), 0 0 0 12px rgba(16, 185, 129, 0.04);
    }
    .lottie-avatar-container.avatar-thinking {
      border-color: rgba(245, 158, 11, 0.6);
      box-shadow: 0 0 0 6px rgba(245, 158, 11, 0.08);
    }
    .lottie-avatar-container.avatar-idle {
      border-color: rgba(99, 102, 241, 0.2);
      box-shadow: none;
    }
    /* Outer ring pulse animation for speaking state */
    @keyframes avatar-ring-pulse {
      0%, 100% { transform: scale(1); opacity: 0.5; }
      50% { transform: scale(1.08); opacity: 0.15; }
    }
    .lottie-avatar-ring {
      position: absolute;
      inset: -16px;
      border-radius: 50%;
      border: 2px solid rgba(99, 102, 241, 0.3);
      pointer-events: none;
      opacity: 0;
      transition: opacity 0.3s;
    }
    .avatar-speaking .lottie-avatar-ring {
      opacity: 1;
      animation: avatar-ring-pulse 1.4s ease-in-out infinite;
    }
    #lottie-player {
      width: 100%;
      height: 100%;
    }
    /* Avatar identity card */
    .ai-identity-card {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
      text-align: center;
    }
    .ai-identity-name {
      font-size: var(--text-lg);
      font-weight: 700;
      color: var(--color-text-primary);
      letter-spacing: -0.02em;
    }
    .ai-identity-role {
      font-size: var(--text-xs);
      color: var(--color-text-muted);
      text-transform: uppercase;
      letter-spacing: 0.07em;
      font-weight: 600;
    }
    /* State badge */
    .avatar-state-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: var(--text-xs);
      font-weight: 600;
      letter-spacing: 0.03em;
      transition: all 0.3s ease;
    }
    .avatar-state-idle    { background: rgba(99,102,241,0.08); color: var(--color-text-muted); }
    .avatar-state-speaking { background: rgba(99,102,241,0.12); color: var(--color-accent); }
    .avatar-state-listening { background: rgba(16,185,129,0.1); color: var(--color-success, #10b981); }
    .avatar-state-thinking { background: rgba(245,158,11,0.1); color: #d97706; }
    /* Mic indicator strip */
    .mic-indicator-strip {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      padding: 6px 12px;
      border-radius: var(--radius-inner);
      background: var(--color-bg-subtle, rgba(0,0,0,0.04));
      border: 1px solid var(--color-border);
      font-size: var(--text-xs);
      color: var(--color-text-muted);
      font-weight: 600;
      width: 100%;
      justify-content: center;
    }
    .mic-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: var(--color-text-muted);
      transition: background 0.3s;
    }
    .mic-dot.active { background: #10b981; animation: mic-pulse 1s ease-in-out infinite; }
    @keyframes mic-pulse {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.4; }
    }
  </style>
  <?php if ($session && $session['current_status'] === 'COMPLETED'): ?>
    <style>
      .workspace-grid { display: none !important; }
    </style>
  <?php endif; ?>
  <script>
    // Monkeypatch getUserMedia and getDisplayMedia to track and stop all media streams globally
    (function() {
      window.activeMediaStreams = [];
      if (navigator.mediaDevices) {
        if (navigator.mediaDevices.getUserMedia) {
          const originalGetUserMedia = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
          navigator.mediaDevices.getUserMedia = async function(constraints) {
            try {
              const stream = await originalGetUserMedia(constraints);
              window.activeMediaStreams.push(stream);
              return stream;
            } catch (err) {
              throw err;
            }
          };
        }
        if (navigator.mediaDevices.getDisplayMedia) {
          const originalGetDisplayMedia = navigator.mediaDevices.getDisplayMedia.bind(navigator.mediaDevices);
          navigator.mediaDevices.getDisplayMedia = async function(constraints) {
            try {
              const stream = await originalGetDisplayMedia(constraints);
              window.activeMediaStreams.push(stream);
              return stream;
            } catch (err) {
              throw err;
            }
          };
        }
      }
    })();

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
  <!-- Browser Restriction Block Overlay -->
  <div id="browser-block-overlay" class="modal-overlay" style="display: none; z-index: 10000; text-align: center;">
    <div class="modal-card" style="border: 2px solid var(--color-danger); max-width: 500px;">
      <div class="modal-header">
        <div style="font-size: 48px; margin-bottom: var(--space-4);">🚫</div>
        <h2>Unsupported Browser</h2>
        <p style="color: var(--color-danger); font-weight: 600; margin-top: var(--space-2);">Action Required</p>
      </div>
      <div style="color: var(--color-text-secondary); font-size: var(--text-sm); line-height: 1.6; text-align: left; margin: var(--space-2) 0;">
        To ensure interview security and proctoring integrity, this assessment can only be taken using a Chromium-based browser (such as Google Chrome, Microsoft Edge, Brave, or Opera).
        <br><br>
        Please open this link in a supported Chromium-based browser to proceed with your assessment.
      </div>
    </div>
  </div>

  <!-- Integrity Setup Wizard Modal -->
  <div id="integrity-setup-modal" class="modal-overlay" style="display: none; z-index: 9999; text-align: center;">
    <div class="modal-card" style="border: 1px solid var(--color-border); max-width: 500px; padding: var(--space-6); text-align: left;">
      <div id="wizard-setup-view">
        <div class="modal-header" style="text-align: center;">
          <div style="font-size: 36px; margin-bottom: var(--space-2);">🛡️</div>
          <h2>Pre-Interview Security Setup</h2>
          <p style="color: var(--color-text-secondary); margin-top: var(--space-1); font-size: var(--text-sm);">Complete the following steps sequentially to begin your assessment.</p>
        </div>
        
        <div class="setup-steps" style="display: flex; flex-direction: column; gap: var(--space-4); margin: var(--space-4) 0;">
          <!-- Step 1: Screen Share -->
          <div class="setup-step" id="setup-step-screen" style="display: flex; align-items: center; justify-content: space-between; padding: var(--space-3); border-radius: var(--radius-inner); border: 1px solid var(--color-border); background: var(--color-surface-elevated); transition: var(--transition-smooth);">
            <div style="display: flex; align-items: center; gap: var(--space-3);">
              <div class="step-indicator-circle" id="setup-circle-1" style="width: 24px; height: 24px; border-radius: 50%; border: 2px solid var(--color-border); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; background: var(--color-surface); color: var(--color-text-secondary);">1</div>
              <div>
                <div style="font-weight: 600; font-size: var(--text-sm);">Share Entire Screen</div>
                <div style="font-size: var(--text-xs); color: var(--color-text-muted);">Streams desktop context securely</div>
              </div>
            </div>
            <button id="setup-btn-screen" class="btn-action" style="padding: 6px 12px; font-size: var(--text-xs); line-height: 1;">Share</button>
          </div>
          
          <!-- Step 2: Webcam & Mic -->
          <div class="setup-step" id="setup-step-webcam" style="display: flex; align-items: center; justify-content: space-between; padding: var(--space-3); border-radius: var(--radius-inner); border: 1px solid var(--color-border); opacity: 0.5; pointer-events: none; transition: var(--transition-smooth);">
            <div style="display: flex; align-items: center; gap: var(--space-3);">
              <div class="step-indicator-circle" id="setup-circle-2" style="width: 24px; height: 24px; border-radius: 50%; border: 2px solid var(--color-border); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; background: var(--color-surface); color: var(--color-text-secondary);">2</div>
              <div>
                <div style="font-weight: 600; font-size: var(--text-sm);">Enable Camera & Mic Access</div>
                <div style="font-size: var(--text-xs); color: var(--color-text-muted);">Webcam monitoring validation</div>
              </div>
            </div>
            <button id="setup-btn-webcam" class="btn-action btn-secondary" style="padding: 6px 12px; font-size: var(--text-xs); line-height: 1;" disabled>Allow</button>
          </div>
          
          <!-- Step 3: Fullscreen -->
          <div class="setup-step" id="setup-step-fullscreen" style="display: flex; align-items: center; justify-content: space-between; padding: var(--space-3); border-radius: var(--radius-inner); border: 1px solid var(--color-border); opacity: 0.5; pointer-events: none; transition: var(--transition-smooth);">
            <div style="display: flex; align-items: center; gap: var(--space-3);">
              <div class="step-indicator-circle" id="setup-circle-3" style="width: 24px; height: 24px; border-radius: 50%; border: 2px solid var(--color-border); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; background: var(--color-surface); color: var(--color-text-secondary);">3</div>
              <div>
                <div style="font-weight: 600; font-size: var(--text-sm);">Enter Fullscreen Mode</div>
                <div style="font-size: var(--text-xs); color: var(--color-text-muted);">Enforces isolated test environment</div>
              </div>
            </div>
            <button id="setup-btn-fullscreen" class="btn-action btn-secondary" style="padding: 6px 12px; font-size: var(--text-xs); line-height: 1;" disabled>Allow</button>
          </div>
        </div>
        
        <button id="setup-start-btn" class="btn-action" style="width: 100%; margin-top: var(--space-2); display: none !important;" disabled>
          <span>Start Interview Call</span>
          <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
        </button>
      </div>

      <div id="wizard-resume-view" style="display: none;">
        <div class="modal-header" style="text-align: center;">
          <div style="font-size: 48px; margin-bottom: var(--space-4);">🖥️</div>
          <h2>Fullscreen Mode Required</h2>
          <p style="color: var(--color-accent); font-weight: 600; margin-top: var(--space-2);">Assessment Environment Locked</p>
        </div>
        <div style="color: var(--color-text-secondary); font-size: var(--text-sm); line-height: 1.6; margin: var(--space-3) 0;">
          To resume your technical assessment, you must return to Fullscreen Mode. This helps secure the test environment and prevent accidental navigation.
        </div>
        <button id="enter-fullscreen-resume-btn" class="btn-action" style="width: 100%; margin-top: var(--space-2);">
          <span>Re-enter Fullscreen</span>
          <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-5h-4m4 0v4m0-4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path></svg>
        </button>
      </div>

      <div id="wizard-screen-share-resume-view" style="display: none;">
        <div class="modal-header" style="text-align: center;">
          <div style="font-size: 48px; margin-bottom: var(--space-4);">🖥️</div>
          <h2>Screen Share Required</h2>
          <p style="color: var(--color-accent); font-weight: 600; margin-top: var(--space-2);">Screen Sharing Stopped</p>
        </div>
        <div style="color: var(--color-text-secondary); font-size: var(--text-sm); line-height: 1.6; margin: var(--space-3) 0;">
          To proceed with your technical assessment, you must share your entire screen.
        </div>
        <button id="screen-share-resume-btn" class="btn-action" style="width: 100%; margin-top: var(--space-2);">
          <span>Share Entire Screen</span>
          <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"></path></svg>
        </button>
      </div>

      <div id="wizard-monitor-resume-view" style="display: none;">
        <div class="modal-header" style="text-align: center;">
          <div style="font-size: 48px; margin-bottom: var(--space-4);">🖥️</div>
          <h2>Single Display Required</h2>
          <p style="color: var(--color-danger); font-weight: 600; margin-top: var(--space-2);">Multiple Displays Detected</p>
        </div>
        <div style="color: var(--color-text-secondary); font-size: var(--text-sm); line-height: 1.6; margin: var(--space-3) 0; text-align: left;">
          To secure the assessment environment, you must use only a single monitor/display. Connecting external screens or secondary monitors is strictly prohibited.
          <br><br>
          Please disconnect any external displays or screens to resume your assessment.
        </div>
        <button onclick="transitionToCompleted()" class="btn-action btn-danger" style="width: 100%; margin-top: var(--space-4); display: flex; align-items: center; justify-content: center; gap: var(--space-2);">
          <span>End & Close Interview</span>
          <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 5H5a2 2 0 00-2 2v10a2 2 0 002 2h14a2 2 0 002-2V7a2 2 0 00-2-2zM9 9h6v6H9V9z"></path></svg>
        </button>
      </div>
    </div>
  </div>

  <script>
    (function() {
      const ua = navigator.userAgent;
      const isChromium = !!window.chrome || ua.includes("Chrome") || ua.includes("Chromium") || ua.includes("CriOS");
      if (!isChromium) {
        window.addEventListener('DOMContentLoaded', () => {
          const overlay = document.getElementById('browser-block-overlay');
          if (overlay) {
            overlay.style.display = 'flex';
          }
        });
      }
    })();
  </script>

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
            <p style="color: var(--color-accent); font-weight: 600;">You are launching an assessment for: <?php echo htmlspecialchars($codeDetails['job_role'] ?? 'Technical Assessment'); ?></p>
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
          <input type="hidden" id="profile_id" value="<?php echo htmlspecialchars($profileId); ?>">
          
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
          <a href="<?php echo isLoggedIn() ? 'candidate-v2/index.php' : 'index.php'; ?>" class="back-link">
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
        <div class="panel-header" style="display: flex; justify-content: space-between; align-items: center;">
          <h3 class="panel-title">AI Interviewer</h3>
          <div class="proctor-indicator warning" id="proctor-status">
            <span class="proctor-dot"></span>
            <span class="proctor-text">Connecting...</span>
          </div>
        </div>
        <!-- AI Interviewer Avatar (Lottie-powered, replaces TruGen iframe) -->
        <div class="agent-video-container" id="agent-video-container">
          <div class="ai-interviewer-panel">
            <div class="lottie-avatar-container avatar-idle" id="lottie-avatar-container">
              <div class="lottie-avatar-ring"></div>
              <div id="lottie-player"></div>
            </div>
            <div class="ai-identity-card">
              <div class="ai-identity-name">Alex</div>
              <div class="ai-identity-role">AI Technical Interviewer</div>
              <div class="avatar-state-badge avatar-state-idle" id="avatar-state-badge">● Connecting...</div>
            </div>
            <div class="mic-indicator-strip" id="mic-indicator-strip">
              <div class="mic-dot" id="mic-dot"></div>
              <span id="avatar-status-label">● Connecting...</span>
            </div>
          </div>
        </div>
        <div class="proctor-note" style="padding: var(--space-2) var(--space-4); font-size: var(--text-xs); color: var(--color-text-muted); text-align: center; border-bottom: 1px solid var(--color-border);">
          Webcam is being monitored locally to verify interview integrity.
        </div>
        
        <div class="webcam-monitor-block" data-status="connecting" id="webcam-monitor-block">
          <div class="webcam-monitor-header">
            <span class="webcam-monitor-title">Candidate Webcam Monitor</span>
            <span class="webcam-status-pill" id="webcam-status-pill">Connecting</span>
          </div>
          <div class="webcam-monitor-viewport">
            <div class="webcam-monitor-placeholder" id="webcam-monitor-placeholder">
              <svg style="width: 28px; height: 28px; opacity: 0.5; color: var(--color-text-muted);" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"></path></svg>
              <p style="font-size: var(--text-xs); color: var(--color-text-muted); margin-top: var(--space-1);">Webcam feed initializing...</p>
            </div>
            <video id="webcam-display-video" autoplay playsinline muted></video>
            <canvas id="webcam-mesh-canvas"></canvas>
            <div class="webcam-alert-badge" id="webcam-alert-badge" style="display: none;"></div>
            <div class="webcam-scan-corner top-left"></div>
            <div class="webcam-scan-corner top-right"></div>
            <div class="webcam-scan-corner bottom-left"></div>
            <div class="webcam-scan-corner bottom-right"></div>
          </div>
        </div>
        


        <div class="media-controls">
          <button id="end-interview-btn" class="btn-control danger" onclick="transitionToCompleted()" title="End Interview">
            <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 5H5a2 2 0 00-2 2v10a2 2 0 002 2h14a2 2 0 002-2V7a2 2 0 00-2-2zM9 9h6v6H9V9z"></path></svg>
          </button>
        </div>
      </div>

      <!-- Right Panel: Candidate Console -->
      <div class="panel right-panel">
        <div class="console-grid">
          
          <!-- Screen Capture Preview Row -->
          <div class="console-section" style="padding: var(--space-3); height: 100%;">
            <div style="display: flex; flex-direction: row; gap: var(--space-4); align-items: stretch; height: 100%; width: 100%;">
              
              <!-- Live Screen Division -->
              <div class="live-screen-division" style="display: flex; flex-direction: column; height: 100%; flex-shrink: 0;">
                <h4 style="font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-text-secondary); margin-bottom: var(--space-2);">Live Screen</h4>
                <div class="screen-preview" id="screen-preview" style="height: 120px; aspect-ratio: 16 / 9; width: auto; background-color: #000; border-radius: var(--radius-inner); overflow: hidden; position: relative; border: 1px solid var(--color-border); display: flex; align-items: center; justify-content: center;">
                  <div class="screen-placeholder">
                    <svg style="width: 28px; height: 28px; opacity: 0.4;" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25"></path></svg>
                    <p style="font-size: var(--text-xs); margin-top: 4px; color: var(--color-text-muted);">Screen stream inactive</p>
                  </div>
                  <canvas id="capture-canvas" style="display: none;"></canvas>
                </div>

              </div>

              <!-- Security Checks Division -->
              <div class="security-checks-division" style="flex: 1; min-width: 0; height: 100%; display: flex; flex-direction: column;">
                <h4 style="font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-text-secondary); margin-bottom: var(--space-2);">Security Checks</h4>
                
                <div class="security-checks-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); grid-template-rows: repeat(2, 1fr); gap: var(--space-2); flex: 1;">
                  <!-- Webcam Feed -->
                  <div class="security-check-card" id="sec-node-webcam" data-okay="false" title="Webcam Monitoring: Inactive">
                    <div class="security-check-icon-wrapper">
                      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"></path></svg>
                    </div>
                    <div class="security-check-info">
                      <span class="security-check-name">Webcam Feed</span>
                    </div>
                    <div class="security-check-status-badge">
                      <span class="status-icon">✗</span>
                    </div>
                  </div>

                  <!-- Screen Sharing -->
                  <div class="security-check-card" id="sec-node-screen" data-okay="false" title="Screen Context Share: Inactive">
                    <div class="security-check-icon-wrapper">
                      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 21h6l-.75-4M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                    </div>
                    <div class="security-check-info">
                      <span class="security-check-name">Screen Share</span>
                    </div>
                    <div class="security-check-status-badge">
                      <span class="status-icon">✗</span>
                    </div>
                  </div>

                  <!-- Fullscreen Mode -->
                  <div class="security-check-card" id="sec-node-fullscreen" data-okay="false" title="Fullscreen Environment: Inactive">
                    <div class="security-check-icon-wrapper">
                      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-5h-4m4 0v4m0-4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path></svg>
                    </div>
                    <div class="security-check-info">
                      <span class="security-check-name">Fullscreen</span>
                    </div>
                    <div class="security-check-status-badge">
                      <span class="status-icon">✗</span>
                    </div>
                  </div>

                  <!-- Tab Focus -->
                  <div class="security-check-card" id="sec-node-focus" data-okay="true" title="Tab Focus state: Focused">
                    <div class="security-check-icon-wrapper">
                      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-12h9.75c1.05 0 2 .922 2 2v9.75c0 1.05-.95 2-2 2H7.5a2 2 0 01-2-2V8c0-1.05.95-2 2-2z"></path></svg>
                    </div>
                    <div class="security-check-info">
                      <span class="security-check-name">Tab Focus</span>
                    </div>
                    <div class="security-check-status-badge">
                      <span class="status-icon">✓</span>
                    </div>
                  </div>

                  <!-- Mouse Cursor -->
                  <div class="security-check-card" id="sec-node-cursor" data-okay="true" title="Cursor Position: Inside Screen">
                    <div class="security-check-icon-wrapper">
                      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5"></path></svg>
                    </div>
                    <div class="security-check-info">
                      <span class="security-check-name">Cursor Inside</span>
                    </div>
                    <div class="security-check-status-badge">
                      <span class="status-icon">✓</span>
                    </div>
                  </div>

                  <!-- Monitor Display (Single Display check) -->
                  <div class="security-check-card" id="sec-node-monitor" data-okay="false" title="Monitor Display: Initializing">
                    <div class="security-check-icon-wrapper">
                      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 4h12a2 2 0 012 2v10a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2z M9 20h6 M12 18v2"></path>
                      </svg>
                    </div>
                    <div class="security-check-info">
                      <span class="security-check-name">Single Display</span>
                    </div>
                    <div class="security-check-status-badge">
                      <span class="status-icon">✗</span>
                    </div>
                  </div>
                </div>

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
    
    <!-- Proctor Warning Banner -->
    <div id="proctor-warning-banner" style="display: none; position: fixed; top: 24px; left: 50%; transform: translateX(-50%); z-index: 9999; padding: var(--space-3) var(--space-6); border-radius: var(--radius-inner); font-weight: 600; font-size: var(--text-sm); box-shadow: 0 10px 30px var(--color-shadow); align-items: center; gap: var(--space-3); transition: var(--transition-smooth);">
      <span class="proctor-banner-icon"></span>
      <span class="proctor-banner-message"></span>
    </div>
  </div>
  <?php endif; ?>

  <script>
    const sessionActive = <?php echo $session ? 'true' : 'false'; ?>;
    const sessionId = <?php echo json_encode($sessionId); ?>;
    const startedTime = <?php echo json_encode($session ? $session['started_at'] : ''); ?>;
    const sessionStatus = <?php echo json_encode($session ? $session['current_status'] : ''); ?>;
    const hasFinalScore = <?php echo ($session && !empty($session['final_score'])) ? 'true' : 'false'; ?>;
    // trugenAgentId removed — replaced by Web Speech API engine
    const candidateName = <?php echo json_encode($session ? $session['candidate_name'] : ''); ?>;
    const candidateEmail = <?php echo json_encode($session ? $session['email'] : ''); ?>;
  </script>
  <script type="module">
    import { initProctor, destroyProctor, getWebcamStream } from './assets/js/proctor.js';
    window.initProctor = initProctor;
    window.destroyProctor = destroyProctor;
    window.getWebcamStream = getWebcamStream;
  </script>
  <script type="module">
    import { initBrowserProctor, destroyBrowserProctor } from './assets/js/browser_proctor.js';
    window.initBrowserProctor = initBrowserProctor;
    window.destroyBrowserProctor = destroyBrowserProctor;
  </script>
  <script src="assets/js/speech_engine.js?v=<?php echo filemtime(__DIR__ . '/assets/js/speech_engine.js'); ?>" defer></script>
  <script src="assets/js/interview.js?v=<?php echo filemtime(__DIR__ . '/assets/js/interview.js'); ?>" defer></script>
</body>
</html>
