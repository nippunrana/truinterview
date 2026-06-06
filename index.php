<?php
// index.php - Main Frontend Panel Shell
require_once __DIR__ . '/db.php';

$sessionId = $_COOKIE['session_id'] ?? '';
$session = null;
if (!empty($sessionId)) {
    $session = getSession($sessionId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TruInterview - AI Multimodal Technical Interviewer</title>
  <link rel="stylesheet" href="style.css">
  <script>
    // Inline script to prevent theme flash before body render
    (function() {
      const savedTheme = localStorage.getItem('theme') || 'dark';
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
<body class="theme-dark">

  <!-- Registration Overlay Modal -->
  <div id="registration-modal" class="modal-overlay" style="display: <?php echo $session ? 'none' : 'flex'; ?>;">
    <div class="modal-card">
      <div class="modal-header">
        <h2>TruInterview</h2>
        <p>Enter your details to begin your interactive mock interview assessment.</p>
      </div>
      <form id="registration-form" onsubmit="handleRegister(event)">
        <div class="form-group" style="margin-bottom: var(--space-4);">
          <label for="candidate_name">Full Name</label>
          <input type="text" id="candidate_name" class="form-input" placeholder="e.g. John Doe" required autocomplete="name">
        </div>
        <div class="form-group" style="margin-bottom: var(--space-6);">
          <label for="candidate_email">Email Address</label>
          <input type="email" id="candidate_email" class="form-input" placeholder="e.g. john@example.com" required autocomplete="email">
        </div>
        <button type="submit" class="btn-action" style="width: 100%;">
          <span>Start Interview Session</span>
          <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
        </button>
      </form>
    </div>
  </div>

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
                <button id="submit-screenshot-btn" class="btn-action" disabled>
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

  <script>
    const sessionActive = <?php echo $session ? 'true' : 'false'; ?>;
    let sessionId = '<?php echo $sessionId; ?>';
    let startedTime = '<?php echo $session ? $session['started_at'] : ''; ?>';
    let timerInterval = null;
    let pollInterval = null;

    // Local settings object
    const mediaState = {
      mic: false,
      camera: false,
      screen: false
    };

    function initVisualizer() {
      const container = document.getElementById('visualizer-container');
      if (!container) return;
      container.innerHTML = '';
      for (let i = 0; i < 40; i++) {
        const bar = document.createElement('div');
        bar.className = 'visualizer-bar';
        container.appendChild(bar);
      }
    }

    function animateVisualizer() {
      if (!mediaState.mic) {
        document.querySelectorAll('.visualizer-bar').forEach(bar => {
          bar.style.height = '15px';
        });
        return;
      }
      document.querySelectorAll('.visualizer-bar').forEach(bar => {
        // Mock animation bars
        const height = Math.floor(Math.random() * 30) + 5;
        bar.style.height = height + 'px';
      });
    }

    function toggleTheme() {
      const currentTheme = document.documentElement.className === 'theme-light' ? 'light' : 'dark';
      const newTheme = currentTheme === 'light' ? 'dark' : 'light';
      document.documentElement.className = 'theme-' + newTheme;
      document.body.className = 'theme-' + newTheme;
      localStorage.setItem('theme', newTheme);
      updateThemeToggleButton(newTheme);
    }

    function handleRegister(event) {
      event.preventDefault();
      const name = document.getElementById('candidate_name').value;
      const email = document.getElementById('candidate_email').value;

      fetch('api.php?action=start', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: `name=${encodeURIComponent(name)}&email=${encodeURIComponent(email)}`
      })
      .then(res => res.json())
      .then(data => {
        if (data.status === 'success') {
          sessionId = data.session_id;
          document.getElementById('registration-modal').style.display = 'none';
          location.reload(); // Reload to start sessions and timers correctly
        } else {
          alert('Error: ' + data.message);
        }
      })
      .catch(err => {
        console.error(err);
        alert('Failed to connect to server API.');
      });
    }

    function toggleMedia(type) {
      const btn = document.getElementById(type + '-toggle');
      if (!btn) return;
      mediaState[type] = !mediaState[type];
      if (mediaState[type]) {
        btn.classList.add('active');
        btn.classList.remove('disabled');
      } else {
        btn.classList.remove('active');
        btn.classList.remove('disabled');
      }
    }

    function toggleScreenShare() {
      const btn = document.getElementById('screen-share-btn');
      const submitBtn = document.getElementById('submit-screenshot-btn');
      mediaState.screen = !mediaState.screen;
      if (mediaState.screen) {
        btn.classList.add('active');
        submitBtn.removeAttribute('disabled');
      } else {
        btn.classList.remove('active');
        submitBtn.setAttribute('disabled', 'true');
      }
    }

    function updateTimer() {
      if (!startedTime) return;
      const started = new Date(startedTime).getTime();
      const now = new Date().getTime();
      const diff = Math.max(0, Math.floor((now - started) / 1000));
      
      const mins = String(Math.floor(diff / 60)).padStart(2, '0');
      const secs = String(diff % 60).padStart(2, '0');
      document.getElementById('timer-display').innerText = `${mins}:${secs}`;
    }

    function pollStatus() {
      if (!sessionId) return;
      fetch(`api.php?action=status&session_id=${sessionId}`)
      .then(res => res.json())
      .then(data => {
        if (data.status === 'success') {
          // Update status indicator
          const statusText = document.getElementById('session-status-text');
          statusText.innerText = data.session.current_status;
          
          // Render Transcripts
          const transcriptsContainer = document.getElementById('transcripts-feed');
          transcriptsContainer.innerHTML = '';
          
          data.transcripts.forEach(msg => {
            const row = document.createElement('div');
            let typeClass = 'system';
            if (msg.speaker === 'USER') typeClass = 'candidate';
            if (msg.speaker === 'AGENT') typeClass = 'agent';
            
            row.className = `transcript-message ${typeClass}`;
            row.innerHTML = `
              <span class="message-sender">${msg.speaker}</span>
              <span class="message-text">${msg.message}</span>
            `;
            transcriptsContainer.appendChild(row);
          });
          
          // Scroll transcripts feed to bottom
          transcriptsContainer.scrollTop = transcriptsContainer.scrollHeight;
        }
      })
      .catch(err => console.error('Error polling status:', err));
    }

    // Initialize scripts on page load
    window.addEventListener('DOMContentLoaded', () => {
      initVisualizer();
      setInterval(animateVisualizer, 100);

      if (sessionActive) {
        updateTimer();
        timerInterval = setInterval(updateTimer, 1000);
        pollStatus();
        pollInterval = setInterval(pollStatus, 3000); // Poll every 3s
      }
    });
  </script>
</body>
</html>
