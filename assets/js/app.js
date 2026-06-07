// app.js - Frontend Logic and Media Streams Lifecycle Management

let timerInterval = null;
let pollInterval = null;
let passivePollInterval = null;
let isTransitionedToCompleted = false;

// Media Streams References
let localStream = null;
let screenStream = null;

// Local media states
const mediaState = {
  mic: false,
  camera: false,
  screen: false
};

// Initialize audio waveform visualizer bars
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

// Animate visualizer based on microphone status
function animateVisualizer() {
  if (!mediaState.mic) {
    document.querySelectorAll('.visualizer-bar').forEach(bar => {
      bar.style.height = '15px';
    });
    return;
  }
  document.querySelectorAll('.visualizer-bar').forEach(bar => {
    const height = Math.floor(Math.random() * 35) + 5;
    bar.style.height = height + 'px';
  });
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

// Toggle Microphone or Camera media tracks
async function toggleMedia(type) {
  const btn = document.getElementById(type + '-toggle');
  if (!btn) return;

  if (type === 'mic') {
    if (!mediaState.mic) {
      try {
        if (!localStream) {
          localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: mediaState.camera });
        } else {
          let audioTrack = localStream.getAudioTracks()[0];
          if (!audioTrack) {
            const tempStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            audioTrack = tempStream.getAudioTracks()[0];
            localStream.addTrack(audioTrack);
          }
          audioTrack.enabled = true;
        }
        mediaState.mic = true;
        btn.classList.add('active');
        btn.classList.remove('disabled');
      } catch (err) {
        console.error('Error enabling microphone:', err);
        alert('Could not access microphone: ' + err.message);
        btn.classList.add('disabled');
      }
    } else {
      if (localStream) {
        const audioTrack = localStream.getAudioTracks()[0];
        if (audioTrack) {
          audioTrack.stop();
          localStream.removeTrack(audioTrack);
        }
      }
      mediaState.mic = false;
      btn.classList.remove('active');
    }
  } else if (type === 'camera') {
    const videoEl = document.getElementById('candidate-video');
    if (!mediaState.camera) {
      try {
        if (!localStream) {
          localStream = await navigator.mediaDevices.getUserMedia({ audio: mediaState.mic, video: true });
        } else {
          let videoTrack = localStream.getVideoTracks()[0];
          if (!videoTrack) {
            const tempStream = await navigator.mediaDevices.getUserMedia({ video: true });
            videoTrack = tempStream.getVideoTracks()[0];
            localStream.addTrack(videoTrack);
          }
          videoTrack.enabled = true;
        }
        
        if (videoEl) {
          videoEl.srcObject = localStream;
          videoEl.style.display = 'block';
        }
        
        mediaState.camera = true;
        btn.classList.add('active');
        btn.classList.remove('disabled');
      } catch (err) {
        console.error('Error enabling camera:', err);
        alert('Could not access camera: ' + err.message);
        btn.classList.add('disabled');
      }
    } else {
      if (localStream) {
        const videoTrack = localStream.getVideoTracks()[0];
        if (videoTrack) {
          videoTrack.stop();
          localStream.removeTrack(videoTrack);
        }
      }
      if (videoEl) {
        videoEl.srcObject = null;
        videoEl.style.display = 'none';
      }
      mediaState.camera = false;
      btn.classList.remove('active');
    }
  }
}

// Toggle desktop screen sharing
async function toggleScreenShare() {
  const btn = document.getElementById('screen-share-btn');
  const submitBtn = document.getElementById('submit-screenshot-btn');
  const previewDiv = document.getElementById('screen-preview');
  const placeholder = previewDiv.querySelector('.screen-placeholder');

  if (!mediaState.screen) {
    try {
      screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true });
      mediaState.screen = true;
      btn.classList.add('active');
      submitBtn.removeAttribute('disabled');
      
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
      
      placeholder.style.display = 'none';
      previewDiv.appendChild(screenVideo);

      // Listen for browser "Stop Sharing" button click
      screenStream.getVideoTracks()[0].onended = () => {
        stopScreenShare();
      };

      startPassivePolling();
    } catch (err) {
      console.error('Error starting screen share:', err);
      alert('Could not start screen share: ' + err.message);
      mediaState.screen = false;
      btn.classList.remove('active');
      submitBtn.setAttribute('disabled', 'true');
    }
  } else {
    stopScreenShare();
  }
}

// Clean up screen sharing
function stopScreenShare() {
  const btn = document.getElementById('screen-share-btn');
  const submitBtn = document.getElementById('submit-screenshot-btn');
  const previewDiv = document.getElementById('screen-preview');
  const placeholder = previewDiv.querySelector('.screen-placeholder');
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
  btn.classList.remove('active');
  submitBtn.setAttribute('disabled', 'true');
  placeholder.style.display = 'flex';
  
  stopPassivePolling();
}

// Canvas Frame Grabber Pipeline
function captureFrame() {
  const screenVideo = document.getElementById('screen-video-element');
  const candidateVideo = document.getElementById('candidate-video');
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

  // Overlay candidate's camera stream picture-in-picture style
  if (mediaState.camera && candidateVideo && candidateVideo.videoWidth) {
    const pipWidth = Math.floor(width * 0.22); // 22% of container
    const pipHeight = Math.floor(candidateVideo.videoHeight * (pipWidth / candidateVideo.videoWidth));
    const margin = 24;
    const x = width - pipWidth - margin;
    const y = height - pipHeight - margin;
    
    ctx.save();
    // Clip rounded corners for premium border design
    ctx.beginPath();
    if (ctx.roundRect) {
      ctx.roundRect(x, y, pipWidth, pipHeight, 12);
    } else {
      ctx.rect(x, y, pipWidth, pipHeight);
    }
    ctx.clip();
    
    ctx.drawImage(candidateVideo, x, y, pipWidth, pipHeight);
    ctx.restore();
  }

  return canvas.toDataURL('image/jpeg', 0.85);
}

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

// Active Submission of answer/code with skeleton state
async function submitAnswer() {
  const submitBtn = document.getElementById('submit-screenshot-btn');
  if (!submitBtn || submitBtn.disabled || !mediaState.screen) return;

  const mcqContainer = document.getElementById('mcq-container');
  const originalMCQHtml = mcqContainer.innerHTML;

  // Enter loading state
  submitBtn.disabled = true;
  const originalText = submitBtn.innerHTML;
  submitBtn.innerHTML = `
    <svg style="width: 16px; height: 16px; animation: spin 1s linear infinite;" fill="none" viewBox="0 0 24 24">
      <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" style="opacity: 0.25;"></circle>
      <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" style="opacity: 0.75;"></path>
    </svg>
    <span>Submitting...</span>
  `;

  // Inject a pulsing skeleton to replace options dynamically
  mcqContainer.innerHTML = `
    <div class="skeleton-mcq">
      <div class="skeleton-line skeleton-title"></div>
      <div class="skeleton-line skeleton-option"></div>
      <div class="skeleton-line skeleton-option"></div>
      <div class="skeleton-line skeleton-option"></div>
      <div class="skeleton-line skeleton-option"></div>
    </div>
  `;
  mcqContainer.classList.add('loading');

  const frameData = captureFrame();
  if (!frameData) {
    alert('Failed to capture high-resolution screen snapshot.');
    submitBtn.disabled = false;
    submitBtn.innerHTML = originalText;
    mcqContainer.innerHTML = originalMCQHtml;
    mcqContainer.classList.remove('loading');
    return;
  }

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
    if (data.status === 'success') {
      console.log('Active submission success:', data);
      
      // Keep loading shown for a short period to allow visual transition feedback
      setTimeout(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
        mcqContainer.innerHTML = originalMCQHtml;
        mcqContainer.classList.remove('loading');
      }, 2000);
    } else {
      throw new Error(data.message || 'Upload endpoint error');
    }
  } catch (err) {
    console.error('Active submission failed:', err);
    alert('Submission failed: ' + err.message);
    submitBtn.disabled = false;
    submitBtn.innerHTML = originalText;
    mcqContainer.innerHTML = originalMCQHtml;
    mcqContainer.classList.remove('loading');
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
      
      const transcriptsContainer = document.getElementById('transcripts-feed');
      if (transcriptsContainer) {
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
        transcriptsContainer.scrollTop = transcriptsContainer.scrollHeight;
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
    container.innerHTML = `
      <div class="mcq-question-card" style="opacity: 0.7; text-align: center; justify-content: center; height: 100%;">
        <p class="mcq-text" style="color: var(--color-text-muted);">Waiting for the AI interviewer to load questions...</p>
        <button class="btn-action" style="margin: var(--space-4) auto 0;" onclick="triggerMCQ()">
          <span>Start MCQ Assessment</span>
        </button>
      </div>
    `;
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
      <div class="mcq-options">
        <label class="mcq-option" data-option="A">
          <input type="radio" name="mcq-choice" value="A">
          <span class="custom-radio"></span>
          <span class="option-text">A: ${escapeHtml(q.option_a)}</span>
        </label>
        <label class="mcq-option" data-option="B">
          <input type="radio" name="mcq-choice" value="B">
          <span class="custom-radio"></span>
          <span class="option-text">B: ${escapeHtml(q.option_b)}</span>
        </label>
        <label class="mcq-option" data-option="C">
          <input type="radio" name="mcq-choice" value="C">
          <span class="custom-radio"></span>
          <span class="option-text">C: ${escapeHtml(q.option_c)}</span>
        </label>
        <label class="mcq-option" data-option="D">
          <input type="radio" name="mcq-choice" value="D">
          <span class="custom-radio"></span>
          <span class="option-text">D: ${escapeHtml(q.option_d)}</span>
        </label>
      </div>
      <button id="submit-mcq-option-btn" class="btn-action" style="margin-top: var(--space-4);" onclick="submitMCQOption()" disabled>
        <span>Submit Option</span>
      </button>
    </div>
  `;
  setupMCQListeners();
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

// Initialize on load
window.addEventListener('DOMContentLoaded', () => {
  initVisualizer();
  setInterval(animateVisualizer, 100);

  if (sessionActive) {
    if (sessionStatus === 'COMPLETED') {
      transitionToCompleted(hasFinalScore);
    } else {
      updateTimer();
      timerInterval = setInterval(updateTimer, 1000);
      pollStatus();
      pollInterval = setInterval(pollStatus, 3000);
      pollMCQState();
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

  // Shut down camera & microphone
  if (localStream) {
    localStream.getTracks().forEach(track => track.stop());
    localStream = null;
  }
  
  // Shut down screen sharing
  stopScreenShare();
  
  // Reset buttons status
  const headerBtn = document.getElementById('end-interview-header-btn');
  if (headerBtn) headerBtn.style.display = 'none';
  const newBtn = document.getElementById('new-interview-header-btn');
  if (newBtn) newBtn.style.display = 'flex';
  const micBtn = document.getElementById('mic-toggle');
  if (micBtn) micBtn.className = 'btn-control disabled';
  const camBtn = document.getElementById('camera-toggle');
  if (camBtn) camBtn.className = 'btn-control disabled';
  
  // Hide workspace grid
  const workspace = document.querySelector('.workspace-grid');
  if (workspace) workspace.style.display = 'none';

  // Inject professional analyzing ring
  let analyzingDiv = null;
  if (!immediate) {
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
          <p>Gemini is evaluating your technical skills, dialogue transcripts, and screen submissions...</p>
        </div>
      `;
      document.querySelector('.app-container').appendChild(analyzingDiv);
    }
  }

  try {
    const res = await fetch(`api.php?action=complete&session_id=${sessionId}`);
    const data = await res.json();
    
    if (analyzingDiv) analyzingDiv.remove();

    if (data.status === 'success') {
      renderDashboard(data);
    } else {
      alert('Error fetching report: ' + data.message);
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
