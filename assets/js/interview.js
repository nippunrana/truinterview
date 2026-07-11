// interview.js - Frontend Logic and Media Streams Lifecycle Management

let timerInterval = null;
let pollInterval = null;
let passivePollInterval = null;
let isTransitionedToCompleted = false;

// Media Streams References
let screenStream = null;

// Webcam Monitor References
let meshAnimFrame = null;
let latestLandmarks = [];

// Proctor status tracking
let currentProctorStatus = 'connecting';
let bannerTimeout = null;

// Local media states
const mediaState = {
  screen: false
};

// Curated Face Mesh Connections (~100 lines)
const FACE_CONNECTIONS = [
  // Oval
  [10, 338], [338, 297], [297, 332], [332, 284], [284, 251], [251, 389], [389, 356], [356, 454], 
  [454, 323], [323, 361], [361, 288], [288, 397], [397, 365], [365, 379], [379, 378], [378, 400], 
  [400, 377], [377, 152], [152, 148], [148, 176], [176, 149], [149, 150], [150, 136], [136, 172], 
  [172, 58], [58, 132], [132, 93], [93, 234], [234, 127], [127, 162], [162, 21], [21, 54], 
  [54, 103], [103, 67], [67, 109], [109, 10],
  // Left eye
  [33, 7], [7, 163], [163, 144], [144, 145], [145, 153], [153, 154], [154, 155], [155, 133], 
  [33, 246], [246, 161], [161, 160], [160, 159], [159, 158], [158, 157], [157, 173], [173, 133],
  // Right eye
  [263, 249], [249, 390], [390, 373], [373, 374], [374, 380], [380, 381], [381, 382], [382, 362], 
  [263, 466], [466, 388], [388, 387], [387, 386], [386, 385], [385, 384], [384, 398], [398, 362],
  // Left Eyebrow
  [70, 63], [63, 105], [105, 66], [66, 107], [107, 9],
  // Right Eyebrow
  [300, 293], [293, 334], [334, 296], [296, 336], [336, 9],
  // Lips
  [61, 185], [185, 40], [40, 37], [37, 0], [0, 267], [267, 270], [270, 409], [409, 291], 
  [291, 375], [375, 321], [321, 405], [405, 314], [314, 17], [17, 84], [84, 91], [91, 146], [146, 61],
  // Nose
  [168, 6], [6, 197], [197, 195], [195, 5],
  [98, 97], [97, 2], [2, 326], [326, 327],
  [5, 4], [4, 2]
];

function bindWebcamStreamToVideo() {
  const video = document.getElementById('webcam-display-video');
  const placeholder = document.getElementById('webcam-monitor-placeholder');
  if (!video) return;

  if (window.getWebcamStream) {
    const stream = window.getWebcamStream();
    if (stream && video.srcObject !== stream) {
      video.srcObject = stream;
      video.play().then(() => {
        if (placeholder) {
          placeholder.style.opacity = '0';
          setTimeout(() => { placeholder.style.display = 'none'; }, 300);
        }
      }).catch(err => console.error("Error playing webcam video:", err));
      
      startMeshCanvasLoop();
    }
  }
}

function startMeshCanvasLoop() {
  if (meshAnimFrame) return;

  const canvas = document.getElementById('webcam-mesh-canvas');
  const video = document.getElementById('webcam-display-video');
  if (!canvas || !video) return;

  const ctx = canvas.getContext('2d');

  function tick() {
    if (video.paused || video.ended) {
      meshAnimFrame = requestAnimationFrame(tick);
      return;
    }

    if (canvas.width !== video.videoWidth || canvas.height !== video.videoHeight) {
      canvas.width = video.videoWidth || 640;
      canvas.height = video.videoHeight || 480;
    }

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    if (latestLandmarks && latestLandmarks.length > 0) {
      drawFaceMesh(ctx, latestLandmarks[0], canvas.width, canvas.height);
    }

    meshAnimFrame = requestAnimationFrame(tick);
  }

  meshAnimFrame = requestAnimationFrame(tick);
}

function drawFaceMesh(ctx, landmarks, w, h) {
  if (!landmarks) return;

  // 1. Draw connections
  ctx.strokeStyle = 'rgba(0, 255, 200, 0.55)';
  ctx.lineWidth = 1.6;

  for (let i = 0; i < FACE_CONNECTIONS.length; i++) {
    const pt1_idx = FACE_CONNECTIONS[i][0];
    const pt2_idx = FACE_CONNECTIONS[i][1];

    const pt1 = landmarks[pt1_idx];
    const pt2 = landmarks[pt2_idx];

    if (pt1 && pt2) {
      ctx.beginPath();
      ctx.moveTo(pt1.x * w, pt1.y * h);
      ctx.lineTo(pt2.x * w, pt2.y * h);
      ctx.stroke();
    }
  }

  // 2. Draw key landmarks
  const drawDot = (idx, color, radius) => {
    const pt = landmarks[idx];
    if (pt) {
      ctx.fillStyle = color;
      ctx.beginPath();
      ctx.arc(pt.x * w, pt.y * h, radius, 0, 2 * Math.PI);
      ctx.fill();
    }
  };

  // Cyan iris glows
  drawDot(468, 'rgba(0, 240, 255, 0.95)', 3.5);
  drawDot(473, 'rgba(0, 240, 255, 0.95)', 3.5);

  // Nose tip
  drawDot(4, 'rgba(255, 230, 100, 0.95)', 4);

  // Mouth corners
  drawDot(61, 'rgba(255, 120, 150, 0.95)', 3);
  drawDot(291, 'rgba(255, 120, 150, 0.95)', 3);
}

function updateWebcamMonitorStatus(status) {
  const block = document.getElementById('webcam-monitor-block');
  const pill = document.getElementById('webcam-status-pill');
  const badge = document.getElementById('webcam-alert-badge');

  if (block) {
    block.setAttribute('data-status', status);
  }

  if (pill) {
    switch (status) {
      case 'connecting':
        pill.innerText = 'Connecting';
        break;
      case 'ok':
        pill.innerText = 'Monitoring Active';
        break;
      case 'warning':
        pill.innerText = 'Attention';
        break;
      case 'critical':
        pill.innerText = 'Suspicion Alert';
        break;
      case 'error':
        pill.innerText = 'Offline';
        break;
      case 'completed':
        pill.innerText = 'Closed';
        break;
      default:
        pill.innerText = status;
        break;
    }
  }

  if (badge) {
    if (status === 'warning') {
      badge.className = 'webcam-alert-badge warning';
      badge.innerHTML = `
        <span class="webcam-alert-icon">⚠️</span>
        <span class="webcam-alert-text">Remain visible & focused</span>
      `;
      badge.style.display = 'flex';
    } else if (status === 'critical') {
      badge.className = 'webcam-alert-badge';
      badge.innerHTML = `
        <span class="webcam-alert-icon">🚨</span>
        <span class="webcam-alert-text">Integrity Anomaly Detected</span>
      `;
      badge.style.display = 'flex';
    } else if (status === 'connecting') {
      badge.className = 'webcam-alert-badge warning';
      badge.innerHTML = `
        <span class="webcam-alert-icon">🔄</span>
        <span class="webcam-alert-text">Initializing stream...</span>
      `;
      badge.style.display = 'flex';
    } else if (status === 'error') {
      badge.className = 'webcam-alert-badge';
      badge.innerHTML = `
        <span class="webcam-alert-icon">❌</span>
        <span class="webcam-alert-text">Monitoring offline</span>
      `;
      badge.style.display = 'flex';
    } else {
      badge.style.display = 'none';
      badge.innerHTML = '';
    }
  }
}

// Toggle Theme (Light/Dark)
function toggleTheme() {
  const currentTheme = document.documentElement.className === 'theme-light' ? 'light' : 'dark';
  const newTheme = currentTheme === 'light' ? 'dark' : 'light';
  document.documentElement.className = 'theme-' + newTheme;
  document.body.className = 'theme-' + newTheme;
  localStorage.setItem('theme', newTheme);
  updateThemeToggleButton(newTheme);
}

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

// Handle onboarding registration submission
function handleRegister(event) {
  event.preventDefault();
  const name = document.getElementById('candidate_name').value;
  const email = document.getElementById('candidate_email').value;
  const inviteCodeEl = document.getElementById('invite_code');
  const inviteCode = inviteCodeEl ? inviteCodeEl.value : '';

  let body = `name=${encodeURIComponent(name)}&email=${encodeURIComponent(email)}`;
  if (inviteCode) {
    body += `&invite_code=${encodeURIComponent(inviteCode)}`;
  }
  
  const profileIdEl = document.getElementById('profile_id');
  const profileId = profileIdEl ? profileIdEl.value : '';
  if (profileId) {
    body += `&profile_id=${encodeURIComponent(profileId)}`;
  }

  fetch('api.php?action=start', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded'
    },
    body: body
  })
  .then(res => res.json())
  .then(data => {
    if (data.status === 'success') {
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

// Toggle desktop screen sharing
async function toggleScreenShare() {
  const previewDiv = document.getElementById('screen-preview');
  const placeholder = previewDiv ? previewDiv.querySelector('.screen-placeholder') : null;

  if (!mediaState.screen) {
    try {
      screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true });
      
      // Enforce Entire Screen Share restriction
      const track = screenStream.getVideoTracks()[0];
      const settings = track ? track.getSettings() : {};
      if (settings.displaySurface && settings.displaySurface !== 'monitor') {
        alert("Security Restriction: You must share your ENTIRE SCREEN, not just a window or tab, to proceed with this assessment.");
        if (track) track.stop();
        screenStream = null;
        if (window.onScreenShareSuccess) window.onScreenShareSuccess(false);
        return;
      }

      mediaState.screen = true;
      if (window.setSecurityIndicator) window.setSecurityIndicator('screen', true);
      if (window.onScreenShareSuccess) window.onScreenShareSuccess(true);
      
      let screenVideo = document.getElementById('screen-video-element');
      if (!screenVideo) {
        screenVideo = document.createElement('video');
        screenVideo.id = 'screen-video-element';
        screenVideo.autoplay = true;
        screenVideo.playsinline = true;
        screenVideo.muted = true;
      }
      screenVideo.srcObject = screenStream;
      screenVideo.style.width = '100%';
      screenVideo.style.height = '100%';
      screenVideo.style.objectFit = 'contain';
      screenVideo.style.display = 'block';
      
      if (placeholder) placeholder.style.display = 'none';
      if (previewDiv) previewDiv.appendChild(screenVideo);

      // Listen for browser "Stop Sharing" button click
      screenStream.getVideoTracks()[0].onended = () => {
        stopScreenShare();
      };

      startPassivePolling();
    } catch (err) {
      console.error('Error starting screen share:', err);
      alert('Could not start screen share: ' + err.message);
      mediaState.screen = false;
      if (window.onScreenShareSuccess) window.onScreenShareSuccess(false);
    }
  } else {
    stopScreenShare();
  }
}

// Clean up screen sharing
function stopScreenShare() {
  const previewDiv = document.getElementById('screen-preview');
  const placeholder = previewDiv ? previewDiv.querySelector('.screen-placeholder') : null;
  const screenVideo = document.getElementById('screen-video-element');

  if (screenStream) {
    screenStream.getVideoTracks().forEach(track => track.stop());
    screenStream = null;
  }
  
  if (screenVideo) {
    screenVideo.srcObject = null;
    screenVideo.remove();
  }

  mediaState.screen = false;
  if (placeholder) placeholder.style.display = 'flex';
  
  if (window.setSecurityIndicator) window.setSecurityIndicator('screen', false);
  if (window.onScreenShareSuccess) window.onScreenShareSuccess(false);
  stopPassivePolling();
}

// Canvas Frame Grabber Pipeline
function captureFrame() {
  const screenVideo = document.getElementById('screen-video-element');
  const canvas = document.getElementById('capture-canvas');
  if (!canvas || !screenVideo || !mediaState.screen) return null;

  const ctx = canvas.getContext('2d');
  
  // High-resolution grid rendering (standardized to screen dimensions)
  const width = screenVideo.videoWidth || 1280;
  const height = screenVideo.videoHeight || 720;
  canvas.width = width;
  canvas.height = height;

  // Draw background screen share
  ctx.drawImage(screenVideo, 0, 0, width, height);

  return canvas.toDataURL('image/jpeg', 0.85);
}
window.captureScreenFrame = captureFrame;

// Background Passive Polling loop (uploads every 9 seconds)
function startPassivePolling() {
  stopPassivePolling();
  passivePollInterval = setInterval(async () => {
    if (!sessionId || !mediaState.screen) return;
    const frameData = captureFrame();
    if (!frameData) return;

    try {
      const response = await fetch('api.php?action=upload_frame', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          frame: frameData
        })
      });
      const data = await response.json();
      console.log('Passive frame upload status:', data);
    } catch (err) {
      console.error('Error uploading passive frame:', err);
    }
  }, 9000);
}

function stopPassivePolling() {
  if (passivePollInterval) {
    clearInterval(passivePollInterval);
    passivePollInterval = null;
  }
}



// Timer and status polling mechanics
function updateTimer() {
  if (!startedTime) return;
  const started = new Date(startedTime).getTime();
  const now = new Date().getTime();
  const diff = Math.max(0, Math.floor((now - started) / 1000));
  
  const mins = String(Math.floor(diff / 60)).padStart(2, '0');
  const secs = String(diff % 60).padStart(2, '0');
  document.getElementById('timer-display').innerText = `${mins}:${secs}`;
}

function addLocalTranscript(speaker, message) {
  if (!sessionId) return;
  const key = `transcripts_${sessionId}`;
  let localTrans = [];
  try {
    localTrans = JSON.parse(sessionStorage.getItem(key)) || [];
  } catch (e) {
    localTrans = [];
  }
  
  // De-duplicate consecutive identical messages for the same speaker (matching server-side logic)
  if (localTrans.length > 0) {
    const last = localTrans[localTrans.length - 1];
    if (last.speaker === speaker && last.message.trim() === message.trim()) {
      return;
    }
  }
  
  localTrans.push({
    speaker: speaker,
    message: message,
    timestamp: new Date().toISOString()
  });
  
  sessionStorage.setItem(key, JSON.stringify(localTrans));
  renderLocalTranscripts();
}
window.addLocalTranscript = addLocalTranscript;

function renderLocalTranscripts() {
  const transcriptsContainer = document.getElementById('transcripts-feed');
  if (!transcriptsContainer) return;
  
  const key = `transcripts_${sessionId}`;
  let localTrans = [];
  try {
    localTrans = JSON.parse(sessionStorage.getItem(key)) || [];
  } catch (e) {
    localTrans = [];
  }
  
  transcriptsContainer.innerHTML = '';
  localTrans.forEach(msg => {
    const row = document.createElement('div');
    let typeClass = 'system';
    if (msg.speaker === 'USER') typeClass = 'candidate';
    if (msg.speaker === 'AGENT') typeClass = 'agent';
    
    row.className = `transcript-message ${typeClass}`;
    row.innerHTML = `
      <span class="message-sender">${msg.speaker}</span>
      <span class="message-text">${escapeHtml(msg.message)}</span>
    `;
    transcriptsContainer.appendChild(row);
  });
  transcriptsContainer.scrollTop = transcriptsContainer.scrollHeight;
}

let greetingTriggered = false;
function triggerGreetingOnce(convId) {
  if (greetingTriggered) return;
  greetingTriggered = true;
  
  console.log("Triggering auto-greeting for conversation:", convId);
  fetch(`api.php?action=greet_candidate&conversation_id=${encodeURIComponent(convId)}&session_id=${sessionId}`)
    .then(res => res.json())
    .then(data => {
      console.log("Greeting status:", data);
      if (data.status === 'success' && data.greeting) {
        addLocalTranscript('AGENT', data.greeting);
      }
    })
    .catch(err => console.error("Error triggering greeting:", err));
}

function pollStatus() {
  if (!sessionId) return;
  fetch(`api.php?action=status&session_id=${sessionId}`)
  .then(res => res.json())
  .then(data => {
    if (data.status === 'success') {
      if (data.session.current_status === 'COMPLETED') {
        transitionToCompleted();
        return;
      }
      const statusText = document.getElementById('session-status-text');
      if (statusText) statusText.innerText = data.session.current_status;

      if (data.transcripts && data.transcripts.length > 0) {
        const key = `transcripts_${sessionId}`;
        let local = [];
        try { local = JSON.parse(sessionStorage.getItem(key)) || []; } catch(e) {}
        if (data.transcripts.length >= local.length) {
          sessionStorage.setItem(key, JSON.stringify(data.transcripts));
          renderLocalTranscripts();
        }
      }
    }
    // Perform MCQ poll to synchronize state
    pollMCQState();
  })
  .catch(err => console.error('Error polling status:', err));
}

let currentMCQQuestionId = null;

async function triggerMCQ() {
  if (!sessionId) return;
  try {
    const response = await fetch(`api.php?action=trigger_mcq&session_id=${sessionId}`);
    const data = await response.json();
    if (data.status === 'success') {
      console.log('MCQ flow started successfully.');
      pollMCQState();
    } else {
      alert('Failed to trigger MCQ: ' + data.message);
    }
  } catch (err) {
    console.error('Error triggering MCQ:', err);
    alert('Failed to trigger MCQ.');
  }
}

function pollMCQState() {
  if (!sessionId) return;
  fetch(`api.php?action=get_mcq_state&session_id=${sessionId}`)
  .then(res => res.json())
  .then(data => {
    if (data.status === 'success') {
      renderMCQ(data);
    }
  })
  .catch(err => console.error('Error polling MCQ state:', err));
}

function renderMCQ(data) {
  const container = document.getElementById('mcq-container');
  if (!container) return;

  if (!data.has_active_mcq) {
    let questionsHtml = `
      <div class="mcq-question-card" style="height: 100%; display: flex; flex-direction: column;">
        <div class="mcq-topic" style="text-transform: uppercase; font-size: var(--text-xs); color: var(--color-accent); font-weight: 700; margin-bottom: var(--space-3); letter-spacing: 0.5px;">Current Open-Ended Question</div>
        <div class="open-questions-list" style="display: flex; flex-direction: column; gap: var(--space-3); overflow-y: auto; flex: 1; padding-right: 4px; justify-content: center;">
    `;
    const activeIdx = data.current_open_question_index ? parseInt(data.current_open_question_index) - 1 : -1;
    if (data.open_questions && data.open_questions.length > 0 && activeIdx >= 0 && activeIdx < data.open_questions.length) {
      const q = data.open_questions[activeIdx];
      questionsHtml += `
          <div class="open-question-item" style="padding: var(--space-4); background: var(--color-surface-elevated); border: 2px solid var(--color-accent); border-radius: var(--radius-inner); font-size: 1.1rem; line-height: 1.6; color: var(--color-text-primary); display: flex; align-items: flex-start; gap: 12px; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.1);">
            <span style="font-weight: 700; color: var(--color-accent); min-width: 30px; font-size: 1.25rem;">Q:</span>
            <span>${escapeHtml(q.question)}</span>
          </div>
      `;
    } else {
      questionsHtml += `
        <p class="mcq-text" style="color: var(--color-text-muted); text-align: center; margin-top: var(--space-4);">Waiting for the AI interviewer to ask a question...</p>
      `;
    }
    questionsHtml += `
        </div>
      </div>
    `;
    container.innerHTML = questionsHtml;
    currentMCQQuestionId = null;
    return;
  }

  const q = data.question;
  if (currentMCQQuestionId === q.id) {
    return; // Already rendering this question
  }
  currentMCQQuestionId = q.id;

  container.innerHTML = `
    <div class="mcq-question-card">
      <div class="mcq-topic">${escapeHtml(q.topic)}</div>
      <div class="mcq-text">${escapeHtml(q.question)}</div>
      <div class="mcq-options" style="display: flex; flex-direction: column; gap: var(--space-2); cursor: default;">
        <div class="mcq-option" style="cursor: default; pointer-events: none; border-color: var(--color-border); background: var(--color-surface-elevated); display: flex; align-items: center; padding: var(--space-3); border-radius: var(--radius-inner); gap: var(--space-3);">
          <span style="font-weight: 700; color: var(--color-accent); font-size: 1.1rem; min-width: 15px;">A</span>
          <span class="option-text" style="color: var(--color-text-primary); text-align: left;">${escapeHtml(q.option_a)}</span>
        </div>
        <div class="mcq-option" style="cursor: default; pointer-events: none; border-color: var(--color-border); background: var(--color-surface-elevated); display: flex; align-items: center; padding: var(--space-3); border-radius: var(--radius-inner); gap: var(--space-3);">
          <span style="font-weight: 700; color: var(--color-accent); font-size: 1.1rem; min-width: 15px;">B</span>
          <span class="option-text" style="color: var(--color-text-primary); text-align: left;">${escapeHtml(q.option_b)}</span>
        </div>
        <div class="mcq-option" style="cursor: default; pointer-events: none; border-color: var(--color-border); background: var(--color-surface-elevated); display: flex; align-items: center; padding: var(--space-3); border-radius: var(--radius-inner); gap: var(--space-3);">
          <span style="font-weight: 700; color: var(--color-accent); font-size: 1.1rem; min-width: 15px;">C</span>
          <span class="option-text" style="color: var(--color-text-primary); text-align: left;">${escapeHtml(q.option_c)}</span>
        </div>
        <div class="mcq-option" style="cursor: default; pointer-events: none; border-color: var(--color-border); background: var(--color-surface-elevated); display: flex; align-items: center; padding: var(--space-3); border-radius: var(--radius-inner); gap: var(--space-3);">
          <span style="font-weight: 700; color: var(--color-accent); font-size: 1.1rem; min-width: 15px;">D</span>
          <span class="option-text" style="color: var(--color-text-primary); text-align: left;">${escapeHtml(q.option_d)}</span>
        </div>
      </div>
      <div class="verbal-instruction" style="margin-top: var(--space-4); display: flex; align-items: center; gap: var(--space-3); padding: var(--space-3); background: rgba(99, 102, 241, 0.08); border: 1px dashed var(--color-accent); border-radius: var(--radius-inner); color: var(--color-text-primary); font-size: var(--text-sm); font-weight: 600; line-height: 1.4; text-align: left;">
        <span style="font-size: 1.25rem;">🎤</span>
        <span>Please speak your answer aloud (e.g., "I choose A" or "The correct option is B").</span>
      </div>
    </div>
  `;
}

function escapeHtml(str) {
  if (!str) return '';
  return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function setupMCQListeners() {
  const container = document.getElementById('mcq-container');
  if (!container) return;
  const options = container.querySelectorAll('.mcq-option');
  const submitBtn = document.getElementById('submit-mcq-option-btn');
  
  options.forEach(opt => {
    const radio = opt.querySelector('input[type="radio"]');
    if (!radio) return;
    opt.addEventListener('click', (e) => {
      e.preventDefault();
      options.forEach(o => o.classList.remove('selected'));
      opt.classList.add('selected');
      radio.checked = true;
      if (submitBtn) {
        submitBtn.removeAttribute('disabled');
      }
    });
  });
}

async function submitMCQOption() {
  const container = document.getElementById('mcq-container');
  if (!container) return;
  const selectedRadio = container.querySelector('input[name="mcq-choice"]:checked');
  if (!selectedRadio) return;
  const value = selectedRadio.value;
  
  const submitBtn = document.getElementById('submit-mcq-option-btn');
  const originalText = submitBtn.innerHTML;
  
  submitBtn.disabled = true;
  submitBtn.innerHTML = `
    <svg style="width: 16px; height: 16px; animation: spin 1s linear infinite;" fill="none" viewBox="0 0 24 24">
      <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" style="opacity: 0.25;"></circle>
      <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" style="opacity: 0.75;"></path>
    </svg>
    <span>Submitting...</span>
  `;
  
  try {
    const response = await fetch(`api.php?action=submit_option`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        session_id: sessionId,
        question_id: currentMCQQuestionId,
        option: value
      })
    });
    const data = await response.json();
    if (data.status === 'success') {
      console.log('MCQ Option submitted successfully:', data);
      
      container.innerHTML = `
        <div class="skeleton-mcq">
          <div class="skeleton-line skeleton-title"></div>
          <div class="skeleton-line skeleton-option"></div>
          <div class="skeleton-line skeleton-option"></div>
          <div class="skeleton-line skeleton-option"></div>
          <div class="skeleton-line skeleton-option"></div>
        </div>
      `;
      container.classList.add('loading');
      currentMCQQuestionId = null;
      
      setTimeout(() => {
        container.classList.remove('loading');
        pollMCQState();
      }, 2000);
    } else {
      alert('Error: ' + data.message);
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalText;
    }
  } catch (err) {
    console.error('Error submitting MCQ option:', err);
    alert('Failed to submit MCQ option.');
    submitBtn.disabled = false;
    submitBtn.innerHTML = originalText;
  }
}

function updateProctorIndicator(status) {
  currentProctorStatus = status;

  const statusDiv = document.getElementById('proctor-status');
  if (statusDiv) {
    statusDiv.className = 'proctor-indicator';
    const textSpan = statusDiv.querySelector('.proctor-text');
    
    switch (status) {
      case 'connecting':
        statusDiv.classList.add('warning');
        if (textSpan) textSpan.innerText = 'Connecting...';
        break;
      case 'ok':
        statusDiv.classList.add('ok');
        if (textSpan) textSpan.innerText = 'Monitoring Active';
        break;
      case 'warning':
        statusDiv.classList.add('warning');
        if (textSpan) textSpan.innerText = 'Attention';
        break;
      case 'critical':
        statusDiv.classList.add('critical');
        if (textSpan) textSpan.innerText = 'Suspicion Alert';
        break;
      case 'error':
      default:
        statusDiv.classList.add('error');
        if (textSpan) textSpan.innerText = 'Monitoring Stopped';
        break;
    }
  }

  // Only update the banner if there is no active transient banner timeout
  if (!bannerTimeout) {
    restoreWebcamBannerState();
  }
}

function restoreWebcamBannerState() {
  const bannerDiv = document.getElementById('proctor-warning-banner');
  if (!bannerDiv) return;

  bannerDiv.className = '';
  const iconSpan = bannerDiv.querySelector('.proctor-banner-icon');
  const msgSpan = bannerDiv.querySelector('.proctor-banner-message');

  if (currentProctorStatus === 'warning') {
    bannerDiv.style.display = 'flex';
    bannerDiv.classList.add('proctor-banner-warning');
    if (iconSpan) {
      iconSpan.innerHTML = `<svg style="width: 18px; height: 18px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"></path></svg>`;
    }
    if (msgSpan) msgSpan.innerText = 'Attention: Please look at the screen and remain visible.';
  } else if (currentProctorStatus === 'critical') {
    bannerDiv.style.display = 'flex';
    bannerDiv.classList.add('proctor-banner-critical');
    if (iconSpan) {
      iconSpan.innerHTML = `<svg style="width: 18px; height: 18px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"></path></svg>`;
    }
    if (msgSpan) msgSpan.innerText = 'CRITICAL WARNING: Integrity anomaly detected! Please correct immediately.';
  } else {
    bannerDiv.style.display = 'none';
  }
}

window.showProctorBanner = function(message, severity, duration = 0, alertType = '') {
  const bannerDiv = document.getElementById('proctor-warning-banner');
  if (!bannerDiv) return;

  if (bannerTimeout) {
    clearTimeout(bannerTimeout);
    bannerTimeout = null;
  }

  bannerDiv.className = '';
  bannerDiv.style.display = 'flex';

  const iconSpan = bannerDiv.querySelector('.proctor-banner-icon');
  const msgSpan = bannerDiv.querySelector('.proctor-banner-message');

  if (severity === 'warning') {
    bannerDiv.classList.add('proctor-banner-warning');
  } else if (severity === 'critical') {
    bannerDiv.classList.add('proctor-banner-critical');
  } else {
    bannerDiv.style.display = 'none';
    return;
  }

  if (iconSpan) {
    const svgMap = {
      webcam: `<svg style="width: 18px; height: 18px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"></path></svg>`,
      screen: `<svg style="width: 18px; height: 18px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 21h6l-.75-4M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>`,
      fullscreen: `<svg style="width: 18px; height: 18px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-5h-4m4 0v4m0-4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path></svg>`,
      focus: `<svg style="width: 18px; height: 18px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-12h9.75c1.05 0 2 .922 2 2v9.75c0 1.05-.95 2-2 2H7.5a2 2 0 01-2-2V8c0-1.05.95-2 2-2z"></path></svg>`,
      cursor: `<svg style="width: 18px; height: 18px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5"></path></svg>`,
      monitor: `<svg style="width: 18px; height: 18px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 4h12a2 2 0 012 2v10a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2z M9 20h6 M12 18v2"></path></svg>`
    };
    iconSpan.innerHTML = svgMap[alertType] || (severity === 'critical' ? '🚨' : '⚠️');
  }

  if (msgSpan) {
    msgSpan.innerText = message;
  }

  if (duration > 0) {
    bannerTimeout = setTimeout(() => {
      bannerTimeout = null;
      restoreWebcamBannerState();
    }, duration);
  }
};

// Expose functions globally for the proctoring wizard in browser_proctor.js
window.updateProctorIndicator = updateProctorIndicator;
window.updateWebcamMonitorStatus = updateWebcamMonitorStatus;
window.bindWebcamStreamToVideo = bindWebcamStreamToVideo;
window.setLatestLandmarks = (landmarks) => {
  latestLandmarks = landmarks;
};

// Dynamically construct and load the TruGen AI agent iframe
window.loadAgentIframe = function() {
  const container = document.getElementById('agent-video-container');
  if (!container) return;

  // Remove placeholder
  const placeholder = container.querySelector('.agent-video-placeholder');
  if (placeholder) {
    placeholder.style.opacity = '0';
    setTimeout(() => { placeholder.style.display = 'none'; }, 300);
  }

  // Check if iframe already exists
  if (container.querySelector('iframe')) return;

  // Create iframe
  const iframe = document.createElement('iframe');
  iframe.src = `https://app.trugen.ai/embed/${encodeURIComponent(trugenAgentId)}?username=${encodeURIComponent(candidateName)}&id=${encodeURIComponent(candidateEmail)}`;
  iframe.style.width = '100%';
  iframe.style.height = '100%';
  iframe.style.border = 'none';
  // Restrict to microphone and autoplay to prevent iframe camera leaks
  iframe.setAttribute('allow', 'microphone; autoplay');
  container.appendChild(iframe);
};

// Initialize on load
window.addEventListener('DOMContentLoaded', () => {
  if (sessionActive) {
    if (sessionStatus === 'COMPLETED') {
      transitionToCompleted(hasFinalScore);
    } else {
      updateTimer();
      timerInterval = setInterval(updateTimer, 1000);
      pollStatus();
      pollInterval = setInterval(pollStatus, 3000);
      pollMCQState();

      // Start browser proctoring wizard (shows integrity setup overlay)
      if (window.initBrowserProctor) {
        window.initBrowserProctor(sessionId);
      }
    }
  }
});

async function transitionToCompleted(immediate = false) {
  if (isTransitionedToCompleted) return;
  isTransitionedToCompleted = true;

  // Clear all running timers/intervals
  if (timerInterval) clearInterval(timerInterval);
  if (pollInterval) clearInterval(pollInterval);
  stopPassivePolling();

  // Shut down screen sharing
  stopScreenShare();
  
  // Stop browser proctoring
  if (window.destroyBrowserProctor) {
    window.destroyBrowserProctor();
  }

  // Inject professional analyzing ring
  let analyzingDiv = null;
  if (!hasFinalScore) {
    analyzingDiv = document.getElementById('analyzing-screen');
    if (!analyzingDiv) {
      analyzingDiv = document.createElement('div');
      analyzingDiv.id = 'analyzing-screen';
      analyzingDiv.className = 'analyzing-container';
      analyzingDiv.innerHTML = `
        <div class="analyzing-pulse">
          <div class="analyzing-ring"></div>
          <div class="analyzing-ring"></div>
          <div class="analyzing-ring"></div>
        </div>
        <div class="analyzing-text">
          <h3>Generating Assessment Report</h3>
          <p>AI is evaluating your technical skills, dialogue transcripts, and screen submissions...</p>
        </div>
      `;
      document.querySelector('.app-container').appendChild(analyzingDiv);
    }
  }

  try {
    if (sessionStatus !== 'COMPLETED') {
      // Send end_call signal via postMessage to the TruGen iframe to trigger immediate client-side track teardown
      const agentIframe = document.querySelector('#agent-video-container iframe');
      if (agentIframe && agentIframe.contentWindow) {
        try {
          agentIframe.contentWindow.postMessage({ action: 'end_call', type: 'end_call' }, '*');
          agentIframe.contentWindow.postMessage('end_call', '*');
        } catch (e) {
          console.error("Error sending postMessage to TruGen iframe:", e);
        }
      }

      // Step 1: Fast mark session completed on backend and terminate streams (sends signaling call-end)
      const res = await fetch(`api.php?action=complete&session_id=${sessionId}&fast=1`);
      const data = await res.json();
      
      if (data.status === 'success') {
        // Step 2: Wait 3.0 seconds to let the cross-origin iframe receive the signaling call-end and shut down WebRTC media tracks cleanly
        await new Promise(resolve => setTimeout(resolve, 3000));
        
        // Step 3: Stop local webcam proctoring
        if (window.destroyProctor) {
          window.destroyProctor();
        }

        // Stop all active streams tracked globally by the monkeypatch
        try {
          if (window.activeMediaStreams && window.activeMediaStreams.length > 0) {
            window.activeMediaStreams.forEach((stream) => {
              if (stream) {
                const tracks = stream.getTracks();
                tracks.forEach(track => {
                  try {
                    track.stop();
                    track.enabled = false;
                  } catch(e) {}
                });
              }
            });
            window.activeMediaStreams = [];
          }
        } catch (e) {
          console.error("Error stopping tracked streams:", e);
        }

        // Step 4: Release local webcam display stream
        const displayVideo = document.getElementById('webcam-display-video');
        if (displayVideo) {
          if (displayVideo.srcObject) {
            const tracks = displayVideo.srcObject.getTracks();
            tracks.forEach(track => {
              try { track.stop(); } catch(e){}
            });
          }
          displayVideo.srcObject = null;
        }

        // Step 5: Release camera/microphone by navigating the agent iframe
        const agentIframeRemove = document.querySelector('#agent-video-container iframe');
        if (agentIframeRemove) {
          agentIframeRemove.src = 'about:blank';
          agentIframeRemove.style.display = 'none';
        }

        if (meshAnimFrame) {
          cancelAnimationFrame(meshAnimFrame);
          meshAnimFrame = null;
        }
        const canvas = document.getElementById('webcam-mesh-canvas');
        if (canvas) {
          const ctx = canvas.getContext('2d');
          ctx.clearRect(0, 0, canvas.width, canvas.height);
        }
        updateWebcamMonitorStatus('completed');
        const placeholder = document.getElementById('webcam-monitor-placeholder');
        if (placeholder) {
          placeholder.style.display = 'flex';
          placeholder.style.opacity = '1';
          const textEl = placeholder.querySelector('p');
          if (textEl) textEl.innerText = "Webcam Monitoring Closed";
        }
        
        const proctorIndicator = document.getElementById('proctor-status');
        if (proctorIndicator) {
          proctorIndicator.style.display = 'none';
        }
        
        // Reset buttons status
        const headerBtn = document.getElementById('end-interview-header-btn');
        if (headerBtn) headerBtn.style.display = 'none';
        const newBtn = document.getElementById('new-interview-header-btn');
        if (newBtn) newBtn.style.display = 'flex';
        
        // Hide workspace grid
        const workspace = document.querySelector('.workspace-grid');
        if (workspace) workspace.style.display = 'none';

        if (analyzingDiv) analyzingDiv.remove();
        
        location.href = 'interview.php?session_id=' + sessionId;
      } else {
        if (analyzingDiv) analyzingDiv.remove();
        alert('Error ending session: ' + data.message);
      }
    } else {
      // Clean up local tracks if we are loading into the completed state directly
      if (window.destroyProctor) {
        window.destroyProctor();
      }
      const displayVideo = document.getElementById('webcam-display-video');
      if (displayVideo) {
        if (displayVideo.srcObject) {
          try {
            displayVideo.srcObject.getTracks().forEach(track => track.stop());
          } catch (e) {
            console.error("Error stopping webcam video tracks:", e);
          }
        }
        displayVideo.srcObject = null;
      }
      const agentIframe = document.querySelector('#agent-video-container iframe');
      if (agentIframe) {
        agentIframe.src = 'about:blank';
        agentIframe.remove();
      }
      if (meshAnimFrame) {
        cancelAnimationFrame(meshAnimFrame);
        meshAnimFrame = null;
      }
      const canvas = document.getElementById('webcam-mesh-canvas');
      if (canvas) {
        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, canvas.width, canvas.height);
      }
      updateWebcamMonitorStatus('completed');
      const placeholder = document.getElementById('webcam-monitor-placeholder');
      if (placeholder) {
        placeholder.style.display = 'flex';
        placeholder.style.opacity = '1';
        const textEl = placeholder.querySelector('p');
        if (textEl) textEl.innerText = "Webcam Monitoring Closed";
      }
      const proctorIndicator = document.getElementById('proctor-status');
      if (proctorIndicator) {
        proctorIndicator.style.display = 'none';
      }
      const headerBtn = document.getElementById('end-interview-header-btn');
      if (headerBtn) headerBtn.style.display = 'none';
      const newBtn = document.getElementById('new-interview-header-btn');
      if (newBtn) newBtn.style.display = 'flex';
      const workspace = document.querySelector('.workspace-grid');
      if (workspace) workspace.style.display = 'none';

      // Step 6: Fetch report details (generates via AI on the clean reloaded page)
      const res = await fetch(`api.php?action=complete&session_id=${sessionId}`);
      const data = await res.json();
      if (analyzingDiv) analyzingDiv.remove();
      if (data.status === 'success') {
        renderDashboard(data);
      } else {
        alert('Error fetching report: ' + data.message);
      }
    }
  } catch (err) {
    console.error('Error fetching complete state:', err);
    if (analyzingDiv) analyzingDiv.remove();
    alert('Failed to generate feedback report. Refresh page to try again.');
  }
}

function startNewInterview() {
  document.cookie = "session_id=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
  location.href = 'index.php';
}

function renderDashboard(data) {
  const container = document.querySelector('.app-container');
  if (!container) return;

  const scoreData = data.final_score;
  const statusText = document.getElementById('session-status-text');
  if (statusText) {
    statusText.innerText = 'Completed';
    statusText.parentElement.style.background = 'var(--color-success-bg)';
    statusText.parentElement.style.color = 'var(--color-success)';
  }

  // Calculate MCQ correctness
  let correctMCQCount = 0;
  let mcqListHtml = '';
  data.mcq_responses.forEach(res => {
    const isCorrect = res.is_correct;
    if (isCorrect) correctMCQCount++;
    const statusClass = isCorrect ? 'correct' : 'incorrect';
    const statusText = isCorrect ? 'Correct' : 'Incorrect';
    
    mcqListHtml += `
      <div class="mcq-summary-card">
        <div class="mcq-card-topic">${escapeHtml(res.topic)}</div>
        <div class="mcq-card-question-text">${escapeHtml(res.question)}</div>
        <div class="mcq-card-answers">
          <span class="mcq-ans-badge ${statusClass}">${statusText} (Selected ${res.selected_option})</span>
          ${!isCorrect ? `<span class="mcq-ans-badge expected">Correct: ${res.correct_option}</span>` : ''}
        </div>
      </div>
    `;
  });
  
  const totalMCQs = data.mcq_responses.length;
  const mcqScorePercentage = totalMCQs > 0 ? Math.round((correctMCQCount / totalMCQs) * 100) : 0;
  
  // Format Strengths List
  let strengthsHtml = '';
  if (scoreData.strengths && Array.isArray(scoreData.strengths)) {
    scoreData.strengths.forEach(s => {
      strengthsHtml += `<li class="feedback-item">${escapeHtml(s)}</li>`;
    });
  } else {
    strengthsHtml = `<li class="feedback-item">No feedback points generated.</li>`;
  }

  // Format Recommendations List
  let recommendationsHtml = '';
  if (scoreData.recommendations && Array.isArray(scoreData.recommendations)) {
    scoreData.recommendations.forEach(r => {
      recommendationsHtml += `<li class="feedback-item">${escapeHtml(r)}</li>`;
    });
  } else {
    recommendationsHtml = `<li class="feedback-item">No recommendations points generated.</li>`;
  }

  // Compile final results panel DOM structure
  const resultsDiv = document.createElement('div');
  resultsDiv.className = 'results-container';
  
  const commOffset = 251.2 - (251.2 * scoreData.communication_score) / 10;
  const probOffset = 251.2 - (251.2 * scoreData.problem_solving_score) / 10;
  const codeOffset = 251.2 - (251.2 * scoreData.code_quality_score) / 10;

  resultsDiv.innerHTML = `
    <div class="results-header-section">
      <div class="results-header-info">
        <h2>Assessment Feedback Report</h2>
        <p>Candidate: ${escapeHtml(data.session.candidate_name)} (${escapeHtml(data.session.email)}) | Completed on: ${new Date(data.session.completed_at).toLocaleString()}</p>
      </div>
    </div>
    
    <div class="metrics-row">
      <!-- Communication Rating -->
      <div class="metric-card metric-communication">
        <div class="metric-chart-wrapper">
          <svg class="metric-circle-svg" viewBox="0 0 100 100">
            <circle class="metric-circle-bg" cx="50" cy="50" r="40"></circle>
            <circle class="metric-circle-fill" cx="50" cy="50" r="40" style="stroke-dashoffset: ${commOffset};"></circle>
          </svg>
          <span class="metric-score-text">${scoreData.communication_score}/10</span>
        </div>
        <div class="metric-details">
          <h4>Communication & Presence</h4>
          <p>${escapeHtml(scoreData.communication_feedback)}</p>
        </div>
      </div>

      <!-- Problem Solving Rating -->
      <div class="metric-card metric-problem-solving">
        <div class="metric-chart-wrapper">
          <svg class="metric-circle-svg" viewBox="0 0 100 100">
            <circle class="metric-circle-bg" cx="50" cy="50" r="40"></circle>
            <circle class="metric-circle-fill" cx="50" cy="50" r="40" style="stroke-dashoffset: ${probOffset};"></circle>
          </svg>
          <span class="metric-score-text">${scoreData.problem_solving_score}/10</span>
        </div>
        <div class="metric-details">
          <h4>Problem Solving</h4>
          <p>${escapeHtml(scoreData.problem_solving_feedback)}</p>
        </div>
      </div>

      <!-- Code Quality Rating -->
      <div class="metric-card metric-code-quality">
        <div class="metric-chart-wrapper">
          <svg class="metric-circle-svg" viewBox="0 0 100 100">
            <circle class="metric-circle-bg" cx="50" cy="50" r="40"></circle>
            <circle class="metric-circle-fill" cx="50" cy="50" r="40" style="stroke-dashoffset: ${codeOffset};"></circle>
          </svg>
          <span class="metric-score-text">${scoreData.code_quality_score}/10</span>
        </div>
        <div class="metric-details">
          <h4>Code Quality & Logic</h4>
          <p>${escapeHtml(scoreData.code_quality_feedback)}</p>
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
          ${strengthsHtml}
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
          ${recommendationsHtml}
        </ul>
      </div>
    </div>

    <!-- Overall Summary -->
    <div class="overall-feedback-card">
      <h3>Interviewer Executive Summary</h3>
      <p>${escapeHtml(scoreData.overall_feedback)}</p>
    </div>

    <!-- MCQ Summary Section -->
    ${totalMCQs > 0 ? `
      <div class="mcq-summary-section">
        <div class="mcq-summary-header">
          <h3>Interactive MCQ Performance</h3>
          <span class="mcq-percentage-badge" style="background: ${mcqScorePercentage >= 70 ? 'var(--color-success-bg)' : 'var(--color-danger-bg)'}; color: ${mcqScorePercentage >= 70 ? 'var(--color-success)' : 'var(--color-danger)'};">
            Score: ${correctMCQCount}/${totalMCQs} (${mcqScorePercentage}%)
          </span>
        </div>
        <div class="mcq-summary-grid">
          ${mcqListHtml}
        </div>
      </div>
    ` : ''}
  `;
  container.appendChild(resultsDiv);
}

// Global media tracks cleanup registry to prevent Safari process leak on page unload
function forceStopAllMediaTracks() {
  console.log("forceStopAllMediaTracks: Initiating absolute hardware release...");
  
  // Stop all active streams tracked globally by the monkeypatch
  try {
    if (window.activeMediaStreams && window.activeMediaStreams.length > 0) {
      console.log(`forceStopAllMediaTracks: Stopping ${window.activeMediaStreams.length} globally tracked MediaStreams.`);
      window.activeMediaStreams.forEach(stream => {
        if (stream) {
          stream.getTracks().forEach(track => {
            try {
              track.stop();
              track.enabled = false;
            } catch(e){}
          });
        }
      });
      window.activeMediaStreams = [];
    }
  } catch (e) {
    console.error("Error stopping globally tracked media streams on unload:", e);
  }
  
  // 1. Release local video stream tracks
  try {
    const displayVideo = document.getElementById('webcam-display-video');
    if (displayVideo && displayVideo.srcObject) {
      displayVideo.srcObject.getTracks().forEach(track => {
        try { track.stop(); } catch(e){}
      });
      displayVideo.srcObject = null;
      if (typeof displayVideo.load === 'function') {
        displayVideo.load();
      }
    }
  } catch (e) {
    console.error("Error stopping local video stream tracks on unload:", e);
  }

  // 2. Shut down proctoring media streams and faceLandmarker
  try {
    if (window.destroyProctor) {
      window.destroyProctor();
    }
  } catch (e) {
    console.error("Error destroying proctor on unload:", e);
  }

  // 3. Stop screen sharing
  try {
    if (screenStream) {
      screenStream.getVideoTracks().forEach(track => {
        try { track.stop(); } catch(e){}
      });
      screenStream = null;
    }
  } catch (e) {
    console.error("Error stopping screen share tracks on unload:", e);
  }

  // 4. Force unload cross-origin iframe to stop WebRTC media hooks
  try {
    const agentIframe = document.querySelector('#agent-video-container iframe');
    if (agentIframe) {
      if (agentIframe.contentWindow) {
        try {
          agentIframe.contentWindow.postMessage({ action: 'end_call', type: 'end_call' }, '*');
          agentIframe.contentWindow.postMessage('end_call', '*');
        } catch(e){}
      }
      agentIframe.src = 'about:blank';
      agentIframe.style.display = 'none';
    }
  } catch (e) {
    console.error("Error freeing iframe on unload:", e);
  }
}

// Register browser lifecycle listeners for absolute resource release
window.addEventListener('beforeunload', forceStopAllMediaTracks);
window.addEventListener('pagehide', forceStopAllMediaTracks);

// Listen to TruGen iframe messages for auto-closing proceedings
window.addEventListener('message', (event) => {
  if (event.origin && event.origin.includes('trugen.ai')) {
    console.log('TruGen message received:', event.data);
    const data = event.data;
    
    // Check if the message contains connection info or conversation_id to trigger the initial greeting
    if (data && typeof data === 'object') {
      const convId = data.conversation_id || data.conversationId || data.roomId || data.room_name || data.roomName;
      if (convId && typeof convId === 'string' && convId.length > 10) {
        triggerGreetingOnce(convId);
      }
      
      // Listen to pipeline speaker events to update the local transcript storage in real-time
      if (data.event && data.event.name) {
        const eventName = data.event.name;
        const eventPayload = data.event.payload || {};
        let text = eventPayload.text || '';
        if (Array.isArray(text)) {
          text = text.join(' ');
        }
        text = text.trim();
        
        if (text) {
          if (eventName === 'agent.started_speaking') {
            addLocalTranscript('AGENT', text);
          } else if (eventName === 'utterance_committed') {
            addLocalTranscript('USER', text);
          }
        }
      }
    }
    
    if (data && (
      data.type === 'call_ended' || 
      data.event === 'call_ended' ||
      data.type === 'call-ended' ||
      data.event === 'call-ended' ||
      (typeof data === 'string' && (data === 'call_ended' || data === 'call-ended' || data === 'close' || data === 'closed' || data === 'completed'))
    )) {
      console.log('TruGen call ended signal detected via postMessage.');
      if (typeof transitionToCompleted === 'function') {
        transitionToCompleted();
      }
    }
  }
});
