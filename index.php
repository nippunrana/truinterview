<?php
// index.php - Standalone Landing Page for TruInterview
require_once __DIR__ . '/db.php';

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

      <div class="nav-cta">
        <a href="interview.php" class="btn btn-primary btn-pill">Start Practice Run</a>
      </div>
    </div>
  </header>

  <!-- Hero Section -->
  <section class="hero-section">
    <div class="stripe-bg"></div>
    <div class="hero-container">
      <div class="hero-content">
        <div class="badge">✨ Low-Stakes AI Preparation</div>
        <h1 class="hero-title">Overcome technical interview anxiety. Practice in a realistic, low-stakes environment.</h1>
        <p class="hero-description">
          Converse naturally with a real-time AI interviewer, share your code context, and get constructive feedback before your actual assessment.
        </p>
        <div class="hero-actions-wrapper">
          <div class="hero-actions">
            <a href="interview.php" class="btn btn-primary">Launch Mock Session</a>
            <a href="#features" class="btn btn-secondary">Learn More</a>
          </div>
          <div class="hero-fud">
            <span>No credit card required</span>
            <span class="hero-fud-dot"></span>
            <span>Instant setup</span>
            <span class="hero-fud-dot"></span>
            <span>100% Free</span>
          </div>
        </div>
      </div>
      
      <div class="hero-visual">
        <div class="hero-image-wrapper">
          <img src="assets/images/candidate_practicing.png" alt="Candidate practicing code interview with TruInterview" class="hero-img">
          
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
          <h3 class="feature-title">Multimodal AI Agent</h3>
          <p class="feature-desc">Engage in live audio/video mock sessions with a conversational agent powered by TruGen.ai. Experience realistic follow-up questions tailored to your responses.</p>
        </div>

        <div class="feature-card">
          <div class="feature-icon-wrapper">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"></path>
            </svg>
          </div>
          <h3 class="feature-title">Live Code Vision</h3>
          <p class="feature-desc">Share your screen context as you code. The platform periodically merges your browser canvas and webcam inputs to evaluate code quality using Gemini 3.5 Flash.</p>
        </div>

        <div class="feature-card">
          <div class="feature-icon-wrapper">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
            </svg>
          </div>
          <h3 class="feature-title">Interactive MCQs</h3>
          <p class="feature-desc">Dynamically switches into technical multi-choice questions. Uses semantic intent classification to let you read quietly or hear questions read aloud by the agent.</p>
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

  <!-- How It Works Section -->
  <section id="how-it-works" class="how-it-works-section">
    <div class="section-container">
      <div class="section-header">
        <h2 class="section-title">Simple 4-step preparation cycle</h2>
        <p class="section-subtitle">Initialize your profile, connect your hardware, and begin assessment simulation.</p>
      </div>

      <div class="steps-container">
        <div class="step-card">
          <div class="step-num">1</div>
          <h3 class="step-title">Enter Details</h3>
          <p class="step-desc">Register with your name and email on the configuration portal to register a local practice session.</p>
        </div>

        <div class="step-card">
          <div class="step-num">2</div>
          <h3 class="step-title">Connect Media</h3>
          <p class="step-desc">Allow webcam and microphone access, and start screen sharing to feed live code context to the model.</p>
        </div>

        <div class="step-card">
          <div class="step-num">3</div>
          <h3 class="step-title">Converse & Code</h3>
          <p class="step-desc">Answer live conceptual questions from the AI and solve multiple-choice technical questions.</p>
        </div>

        <div class="step-card">
          <div class="step-num">4</div>
          <h3 class="step-title">Review Feedback</h3>
          <p class="step-desc">Review a comprehensive performance report card outlining graded categories, strengths, and areas to polish.</p>
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
            <p>Mock assessments cover standard technical skills including Javascript, CSS variables, PHP OOP syntax, algorithm design, and API design principles. The multiple-choice questions dynamically align with these core topics.</p>
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
      <span class="footer-copy">&copy; 2026 TruInterview. Crafted with Gemini 3.5 Flash & TruGen.ai. All rights reserved.</span>
    </div>
  </footer>

</body>
</html>
