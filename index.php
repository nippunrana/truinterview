<?php
// index.php - Standalone Landing Page for TruInterview
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// If cookie exists and session is active, redirect to interview workspace
$sessionId = $_COOKIE['session_id'] ?? '';
if (!empty($sessionId)) {
    $session = getSession($sessionId);
    if ($session && $session['current_status'] !== 'COMPLETED') {
        header('Location: interview.php');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TruInterview - AI-Powered Technical Assessment Practice</title>
  <meta name="description" content="Practice makes perfect. Ace your next technical assessment with TruInterview - a real-time conversational AI interviewer.">
  <link rel="stylesheet" href="assets/css/landing.css">
</head>
<body>

  <!-- Navigation Header -->
  <header class="landing-header">
    <div class="nav-container">
      <a href="index.php" class="brand">
        <svg style="width: 28px; height: 28px; color: var(--color-brand);" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
        </svg>
        <span class="brand-logo">TruInterview</span>
      </a>
      
      <ul class="nav-links">
        <li><a href="#features" class="nav-link">Features</a></li>
        <li><a href="#preview" class="nav-link">Scorecard</a></li>
        <li><a href="#how-it-works" class="nav-link">How it Works</a></li>
        <li><a href="#faq" class="nav-link">FAQ</a></li>
      </ul>

      <div class="nav-cta" style="display: flex; gap: 16px; align-items: center;">
        <?php if (isLoggedIn()): ?>
          <?php $u = getCurrentUser(); ?>
          <?php if ($u['role'] === 'candidate'): ?>
            <a href="candidate-v2/index.php" class="btn btn-primary btn-pill">Go to Dashboard</a>
          <?php else: ?>
            <a href="recruiter/index.php" class="btn btn-primary btn-pill">Recruiter Dashboard</a>
          <?php endif; ?>
        <?php else: ?>
          <a href="login.php" class="nav-link" style="font-weight: 600; text-decoration: none; color: var(--color-text-secondary);">Sign In</a>
          <a href="register.php" class="btn btn-primary btn-pill">Register</a>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <!-- Hero Section -->
  <section class="hero-section">
    <div class="stripe-bg"></div>
    <div class="hero-container">
      <div class="hero-content">
        <div class="badge sponsor-badge">
          <span class="google-dots">
            <span class="g-dot g-blue"></span>
            <span class="g-dot g-red"></span>
            <span class="g-dot g-yellow"></span>
            <span class="g-dot g-green"></span>
          </span>
          <span>Powered by Google Gemini 3.5 &amp; TruGen.ai</span>
        </div>
        <h1 class="hero-title">Overcome technical interview anxiety with Google Gemini &amp; TruGen.ai</h1>
        <p class="hero-description">
          Converse naturally with a real-time conversational voice agent powered by <strong>TruGen.ai</strong>, share your coding screen context evaluated by <strong>Google Gemini Vision</strong>, and ace your screening assessments.
        </p>
        <div class="hero-actions-wrapper">
          <div class="hero-actions">
            <a href="interview.php" class="btn btn-primary">Start Mock Interview</a>
            <?php if (isLoggedIn() && getCurrentUser()['role'] === 'recruiter'): ?>
              <a href="recruiter/index.php" class="btn btn-secondary">Create Recruiter Invite</a>
            <?php else: ?>
              <a href="login.php?redirect=recruiter" class="btn btn-secondary">Create Recruiter Invite</a>
            <?php endif; ?>
          </div>
          <div class="hero-fud">
            <span>No credit card required</span>
            <span class="hero-fud-dot"></span>
            <span>Real-time voice stream</span>
            <span class="hero-fud-dot"></span>
            <span>Google XYZ Resume Tuning</span>
          </div>
        </div>
      </div>
      
      <div class="hero-visual">
        <div class="hero-image-wrapper">
          <img src="assets/images/candidate_practicing.jpg" alt="Candidate practicing code interview with TruInterview" class="hero-img">
          
          <div class="status-widget">
            <span class="pulse-dot"></span>
            <span>Status: Live Assessment</span>
          </div>
          
          <div class="dialogue-widget">
            <div class="dialogue-speaker">
              <span>AI Interviewer</span>
              <div class="waveform-anim">
                <span class="waveform-bar"></span>
                <span class="waveform-bar"></span>
                <span class="waveform-bar"></span>
                <span class="waveform-bar"></span>
                <span class="waveform-bar"></span>
              </div>
            </div>
            <div class="dialogue-text">
              "How would you optimize the time complexity of this algorithm from O(N²) to O(N log N)?"
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Tech Logo Bar -->
    <div class="tech-logobar">
      <div class="tech-container">
        <span class="tech-label">Core Hackathon Stack:</span>
        <div class="tech-logos">
          <div class="tech-item">
            <svg class="tech-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
            <span>Google Gemini 3.5</span>
          </div>
          <div class="tech-item">
            <svg class="tech-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/></svg>
            <span>TruGen.ai Real-time Audio</span>
          </div>
          <div class="tech-item">
            <svg class="tech-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 7V4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v3M4 17v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3M9 12h6M12 9v6"/></svg>
            <span>PHP 7.4+</span>
          </div>
          <div class="tech-item">
            <svg class="tech-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <span>PostgreSQL DB</span>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Features Grid Section -->
  <section id="features" class="features-section">
    <div class="section-container">
      <div class="section-header">
        <h2 class="section-title">Engineered to simulate real assessment workflows</h2>
        <p class="section-subtitle">TruInterview integrates multimodal inputs, vision-based code evaluation, and automated feedback loops to mirror real-world interviews.</p>
      </div>

      <div class="features-grid">
        <div class="feature-card">
          <div class="feature-icon-wrapper">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path>
            </svg>
          </div>
          <h3 class="feature-title">TruGen Conversational Agent</h3>
          <p class="feature-desc">Engage in live low-latency technical interviews powered by <strong>TruGen.ai</strong>. Experience responsive, voice-driven feedback that adapts dynamically to your coding speed.</p>
        </div>

        <div class="feature-card">
          <div class="feature-icon-wrapper">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"></path>
            </svg>
          </div>
          <h3 class="feature-title">Live Code Vision</h3>
          <p class="feature-desc">Share your screen context as you code. <strong>Google Gemini 3.5 Flash</strong> reviews code structures, algorithms, and logical flows directly via real-time canvas tracking.</p>
        </div>

        <div class="feature-card">
          <div class="feature-icon-wrapper">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
            </svg>
          </div>
          <h3 class="feature-title">Dynamic MCQ Assessment</h3>
          <p class="feature-desc">Dynamically switches into multi-choice questions using <strong>Google Gemini</strong> to parse and classify vocal answers and read questions aloud according to preference.</p>
        </div>

        <div class="feature-card">
          <div class="feature-icon-wrapper">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
          </div>
          <h3 class="feature-title">AI Resume Optimizer</h3>
          <p class="feature-desc">Analyzes resumes for seniority matches, runs a Gap Analysis voice chat, performs date math checks, and writes an ATS-optimized resume using the <strong>Google XYZ impact formula</strong>.</p>
        </div>

        <div class="feature-card">
          <div class="feature-icon-wrapper">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
            </svg>
          </div>
          <h3 class="feature-title">Dual-Role Ecosystem</h3>
          <p class="feature-desc">Includes Candidate V2 multi-profile career tracks (up to 3 tracks with automated level promotion) and a Recruiter direct invite link builder with AI category classification.</p>
        </div>

        <div class="feature-card">
          <div class="feature-icon-wrapper">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
            </svg>
          </div>
          <h3 class="feature-title">AI Vision Proctoring</h3>
          <p class="feature-desc">Ensures test integrity using browser-native checks (tab focus, clipboard locks, multi-screen blocks) and <strong>Gemini Vision</strong> webcam conduct tracking for maximum security.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- Interactive Scorecard Preview Section -->
  <section id="preview" class="scorecard-preview-section">
    <div class="scorecard-preview-container">
      <div class="scorecard-preview-card">
        <div class="scorecard-preview-header">
          <div>
            <h3>Candidate Assessment Report</h3>
            <p>Session Completed Mockup</p>
          </div>
          <div class="badge" style="background: rgba(16, 185, 129, 0.08); color: #10b981; border-color: rgba(16, 185, 129, 0.15);">Passed</div>
        </div>
        
        <div class="preview-score-circles">
          <div class="preview-score-circle">
            <svg class="circular-chart" viewBox="0 0 36 36">
              <path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
              <path class="circle-fill-indigo" stroke-dasharray="80, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
            </svg>
            <span class="score-num">8/10</span>
            <span class="score-label">Logic &amp; Design</span>
          </div>
          
          <div class="preview-score-circle">
            <svg class="circular-chart" viewBox="0 0 36 36">
              <path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
              <path class="circle-fill-blue" stroke-dasharray="90, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
            </svg>
            <span class="score-num">9/10</span>
            <span class="score-label">Problem Solving</span>
          </div>

          <div class="preview-score-circle">
            <svg class="circular-chart" viewBox="0 0 36 36">
              <path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
              <path class="circle-fill-green" stroke-dasharray="70, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
            </svg>
            <span class="score-num">7/10</span>
            <span class="score-label">Speech/Comm</span>
          </div>
        </div>

        <div class="preview-badge-card">
          <h4>Highlights</h4>
          <div class="preview-badge-item">
            <span class="preview-badge-dot dot-success"></span>
            <span>Identified optimal algorithms quickly under speaking pressure.</span>
          </div>
          <div class="preview-badge-item">
            <span class="preview-badge-dot dot-warning"></span>
            <span>Needs to walk through corner cases more exhaustively in live coding.</span>
          </div>
        </div>

        <div class="preview-summary-box">
          Jane demonstrated solid command of Big-O analysis and clean JavaScript logic. Highly recommend practicing recursive base cases.
        </div>
      </div>

      <div style="display: flex; flex-direction: column; gap: var(--space-4);">
        <div class="badge">Actionable Analytics</div>
        <h2 class="section-title">Get a detailed evaluation of your performance</h2>
        <p style="color: var(--text-secondary); line-height: 1.7; font-size: 1.05rem;">
          Once you conclude the interview session, Gemini processes your conversation transcripts, code quality updates, and screen snapshots. You will immediately receive a structured dashboard highlighting your strengths, development items, and specific ratings.
        </p>
        <div style="margin-top: var(--space-2);">
          <a href="interview.php" class="btn btn-primary">Get Your Scorecard</a>
        </div>
      </div>
    </div>
  </section>

  <!-- Interactive Proctoring Simulator Section -->
  <section id="proctor-simulator" class="proctor-simulator-section">
    <div class="section-container">
      <div class="proctor-simulator-grid">
        <div class="proctor-desc-side">
          <div class="badge">Google Gemini Vision in Action</div>
          <h2 class="section-title">Experience Real-Time AI Proctoring</h2>
          <p class="section-body-text">
            Our dual-integrity engine leverages advanced browser hooks alongside <strong>Google Gemini Vision</strong> webcam analysis to ensure secure, authentic assessments.
          </p>
          <p class="section-body-text">
            Click any button below to simulate candidate behavior and watch how our real-time AI warning system flags misconduct automatically.
          </p>
          
          <div class="proctor-controls">
            <button class="btn btn-secondary sim-btn" onclick="simulateProctor('gaze')">Simulate Look Away</button>
            <button class="btn btn-secondary sim-btn" onclick="simulateProctor('tab')">Simulate Tab Switch</button>
            <button class="btn btn-secondary sim-btn" onclick="simulateProctor('multi')">Simulate Multiple Faces</button>
            <button class="btn btn-primary sim-btn reset-btn" onclick="simulateProctor('clear')">Reset Sandbox</button>
          </div>
        </div>
        
        <div class="proctor-console-side">
          <div class="proctor-console-card">
            <div class="console-header">
              <div class="console-title-group">
                <span class="pulse-dot"></span>
                <h3>AI Proctor Stream</h3>
              </div>
              <span id="proctor-status-tag" class="console-tag tag-safe">Safe</span>
            </div>
            
            <div class="console-monitor">
              <div class="webcam-sim">
                <svg viewBox="0 0 100 100" class="avatar-silhouette">
                  <circle cx="50" cy="35" r="20" fill="none" stroke="currentColor" stroke-width="2"/>
                  <path d="M20 80c0-15 10-25 30-25s30 10 30 25" fill="none" stroke="currentColor" stroke-width="2"/>
                </svg>
                <div id="proctor-overlay" class="webcam-overlay"></div>
                <div id="alert-box" class="target-box alert-border" style="display:none;">
                  <span id="alert-box-label" class="box-label alert-bg">Anomaly Detected</span>
                </div>
              </div>
              
              <div class="console-metrics">
                <div class="metric-item">
                  <span class="metric-lbl">Integrity Level</span>
                  <span id="metric-integrity" class="metric-val text-success">100%</span>
                </div>
                <div class="metric-item">
                  <span class="metric-lbl">Active Warnings</span>
                  <span id="metric-warnings" class="metric-val">0 / 3</span>
                </div>
                <div class="metric-item">
                  <span class="metric-lbl">Analysis Mode</span>
                  <span class="metric-val text-brand">Gemini 3.5 Vision</span>
                </div>
              </div>
            </div>
            
            <div class="console-feed">
              <h4>Activity Log</h4>
              <div id="feed-log" class="feed-log-container">
                <div class="feed-event event-info">[12:04:10] Session loaded. Camera and screenshare permissions verified.</div>
                <div class="feed-event event-info">[12:04:15] Gaze tracking initialized. Baseline established.</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- How It Works Section -->
  <section id="how-it-works" class="how-it-works-section">
    <div class="section-container">
      <div class="section-header">
        <h2 class="section-title">Simple 4-step preparation cycle</h2>
        <p class="section-subtitle">Initialize your profile, complete security validations, and begin assessment simulation.</p>
      </div>

      <div class="steps-container">
        <div class="step-card">
          <div class="step-num">1</div>
          <h3 class="step-title">Initialize Profile</h3>
          <p class="step-desc">Register or log in, set up your candidate profile (up to 3 career tracks), or claim a recruiter direct invite link.</p>
        </div>

        <div class="step-card">
          <div class="step-num">2</div>
          <h3 class="step-title">Environment Check</h3>
          <p class="step-desc">Validate system access via our pre-check wizard. Enforces Chromium browser, fullscreen mode, and screen sharing.</p>
        </div>

        <div class="step-card">
          <div class="step-num">3</div>
          <h3 class="step-title">Converse &amp; Code</h3>
          <p class="step-desc">Speak naturally with the TruGen conversational agent while Google Gemini analyzes code structures and MCQ selections.</p>
        </div>

        <div class="step-card">
          <div class="step-num">4</div>
          <h3 class="step-title">ATS &amp; Scorecard</h3>
          <p class="step-desc">Review your ratings dashboard and fine-tune your resume using our reality check and Google XYZ impact optimizer.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- FAQ Accordion Section -->
  <section id="faq" class="faq-section">
    <div class="section-container">
      <div class="section-header">
        <h2 class="section-title">Frequently Asked Questions</h2>
        <p class="section-subtitle">Everything you need to know about the practice portal.</p>
      </div>

      <div class="faq-grid">
        <details class="faq-details">
          <summary class="faq-summary">How does the AI agent evaluate my code?</summary>
          <div class="faq-content">
            <p>The application captures periodic snapshots of your shared screen (every 9 seconds or when you manually click "Submit Code"). Gemini 3.5 Flash reviews these images alongside your vocal answers to score your logical code quality, code completeness, and system architectures.</p>
          </div>
        </details>

        <details class="faq-details">
          <summary class="faq-summary">What technical topics are tested?</summary>
          <div class="faq-content">
            <p>Mock assessments cover standard technical skills including JavaScript, CSS variables, PHP OOP syntax, algorithm design, and API design principles. The multiple-choice questions dynamically align with these core topics.</p>
          </div>
        </details>

        <details class="faq-details">
          <summary class="faq-summary">How are Google Gemini and TruGen.ai integrated?</summary>
          <div class="faq-content">
            <p>TruGen.ai provides the conversational audio iframe token for high-speed voice streaming. Google Gemini models act as the cognitive brain behind the scenes, routing dialog intents, processing visual proctoring alerts, reviewing resume gaps, and synthesizing scorecards.</p>
          </div>
        </details>

        <details class="faq-details">
          <summary class="faq-summary">Is my data secure?</summary>
          <div class="faq-content">
            <p>Yes. Screen captures and audio inputs are only analyzed during the active session context. They are stored locally in the workspace uploads folder corresponding to your unique session token and are never shared publicly.</p>
          </div>
        </details>
      </div>
    </div>
  </section>

  <!-- Footer -->
  <footer class="landing-footer">
    <div class="footer-container">
      <span class="footer-logo">TruInterview</span>
      <span class="footer-copy">&copy; 2026 TruInterview. Crafted with Gemini 3.5 Flash &amp; TruGen.ai. All rights reserved.</span>
    </div>
  </footer>

  <!-- Interactive Proctoring Simulator Logic -->
  <script>
    function simulateProctor(type) {
      const statusTag = document.getElementById('proctor-status-tag');
      const overlay = document.getElementById('proctor-overlay');
      const alertBox = document.getElementById('alert-box');
      const alertBoxLabel = document.getElementById('alert-box-label');
      const integrity = document.getElementById('metric-integrity');
      const warnings = document.getElementById('metric-warnings');
      const log = document.getElementById('feed-log');
      
      const now = new Date();
      const timeStr = `[${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}:${String(now.getSeconds()).padStart(2, '0')}]`;
      
      // Reset defaults
      overlay.className = 'webcam-overlay';
      overlay.innerHTML = '';
      alertBox.style.display = 'none';
      
      if (type === 'clear') {
        statusTag.className = 'console-tag tag-safe';
        statusTag.innerText = 'Safe';
        integrity.className = 'metric-val text-success';
        integrity.innerText = '100%';
        warnings.className = 'metric-val';
        warnings.innerText = '0 / 3';
        log.innerHTML = `<div class="feed-event event-info">${timeStr} Sandbox reset. Monitoring baseline established.</div>`;
        return;
      }
      
      statusTag.className = 'console-tag tag-pending';
      statusTag.innerText = 'Analyzing...';
      log.innerHTML += `<div class="feed-event event-info">${timeStr} Snapshot captured. Feeding frame to Gemini Vision API...</div>`;
      log.scrollTop = log.scrollHeight;
      
      setTimeout(() => {
        if (type === 'gaze') {
          statusTag.className = 'console-tag tag-danger';
          statusTag.innerText = 'Misconduct';
          
          overlay.className = 'webcam-overlay overlay-danger';
          overlay.innerHTML = '<span class="warn-banner">⚠️ Gaze Away Flagged</span>';
          
          alertBox.style.display = 'block';
          alertBox.style.top = '15%';
          alertBox.style.left = '60%';
          alertBox.style.width = '30%';
          alertBox.style.height = '30%';
          alertBoxLabel.innerText = 'Gaze: Out of Bound (94% confidence)';
          
          integrity.className = 'metric-val text-warning';
          integrity.innerText = '75%';
          warnings.className = 'metric-val text-danger';
          warnings.innerText = '1 / 3';
          
          log.innerHTML += `<div class="feed-event event-danger">${timeStr} WARNING: Candidate gaze vector shifted off-screen. (Gemini: "Candidate is looking down at a mobile device or secondary screen").</div>`;
        } else if (type === 'tab') {
          statusTag.className = 'console-tag tag-danger';
          statusTag.innerText = 'Suspicious';
          
          overlay.className = 'webcam-overlay overlay-danger';
          overlay.innerHTML = '<span class="warn-banner">⚠️ Tab/Window Switched</span>';
          
          integrity.className = 'metric-val text-warning';
          integrity.innerText = '50%';
          warnings.className = 'metric-val text-danger';
          warnings.innerText = '2 / 3';
          
          log.innerHTML += `<div class="feed-event event-danger">${timeStr} ALERT: Focus lost. Candidate switched active browser tabs or application window. clipboard copy blocked.</div>`;
        } else if (type === 'multi') {
          statusTag.className = 'console-tag tag-danger';
          statusTag.innerText = 'Misconduct';
          
          overlay.className = 'webcam-overlay overlay-danger';
          overlay.innerHTML = '<span class="warn-banner">⚠️ Multiple Persons Detected</span>';
          
          alertBox.style.display = 'block';
          alertBox.style.top = '10%';
          alertBox.style.left = '20%';
          alertBox.style.width = '60%';
          alertBox.style.height = '70%';
          alertBoxLabel.innerText = 'AI Alert: Secondary Face Detected (98% confidence)';
          
          integrity.className = 'metric-val text-danger';
          integrity.innerText = '20%';
          warnings.className = 'metric-val text-danger';
          warnings.innerText = '3 / 3 (Session Terminated)';
          
          log.innerHTML += `<div class="feed-event event-critical">${timeStr} CRITICAL: Gemini Vision API flagged secondary face in webcam frame. Session auto-terminated due to security misconduct rules.</div>`;
        }
        log.scrollTop = log.scrollHeight;
      }, 750);
    }
  </script>

</body>
</html>
