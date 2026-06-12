<?php
// interview_room.php - Bridge page for joining interviews by invitation code
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

// Enforce candidate login
requireAuth(['candidate']);
$user = getCurrentUser();

// Accept and sanitize invitation code
$code = $_GET['code'] ?? '';
$code = strtoupper(trim($code));

$link = null;
$company = null;
$error = '';

if (empty($code)) {
    $error = "No assessment code was provided. Please go back and enter a valid code.";
} else {
    $link = getInterviewLinkByCode($code);
    if (!$link) {
        $error = "The assessment code <strong>" . htmlspecialchars($code) . "</strong> is invalid or inactive.";
    } elseif ($link['expires_at'] && strtotime($link['expires_at']) < time()) {
        $error = "This assessment invitation code has expired.";
    } elseif ($link['attempts_used'] >= $link['max_attempts']) {
        $error = "You have already reached the maximum allowed attempts for this assessment code.";
    } else {
        // Fetch company details
        $db = getDB();
        $stmt = $db->prepare("SELECT name, logo_url FROM companies WHERE id = :company_id");
        $stmt->execute(['company_id' => $link['company_id']]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Fetch candidate profiles and match them
$profiles = getCandidateProfiles($user['id']);
$matchedProfiles = [];
$otherProfiles = [];

$levelNames = [
    0 => "Novice",
    1 => "Terminology",
    2 => "Mechanics",
    3 => "Implementation",
    4 => "Analysis",
    5 => "Troubleshooting",
    6 => "Integration",
    7 => "Optimization",
    8 => "Security",
    9 => "Governance",
    10 => "Strategic Leadership"
];

if ($link && !empty($profiles)) {
    $linkSlug = preg_replace('/\s+/', '-', strtolower(trim($link['job_role'])));
    $linkSlug = preg_replace('/[^a-zA-Z0-9\-]/', '', $linkSlug);
    $linkSlug = preg_replace('/-+/', '-', $linkSlug);
    $linkSlug = trim($linkSlug, '-');

    foreach ($profiles as $profile) {
        $profileSlug = preg_replace('/\s+/', '-', strtolower(trim($profile['role_title'])));
        $profileSlug = preg_replace('/[^a-zA-Z0-9\-]/', '', $profileSlug);
        $profileSlug = preg_replace('/-+/', '-', $profileSlug);
        $profileSlug = trim($profileSlug, '-');

        $isCategoryMatch = (!empty($profile['category_id']) && !empty($link['category_id']) && $profile['category_id'] === $link['category_id']);
        $isRoleMatch = ($profileSlug === $linkSlug);

        if ($isCategoryMatch || $isRoleMatch) {
            $matchedProfiles[] = $profile;
        } else {
            $otherProfiles[] = $profile;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Join Assessment Room - TruInterview</title>
  <link rel="stylesheet" href="assets/css/candidate-v2.css">
  <script>
    // Inline script to prevent theme flash before body render
    (function() {
      const savedTheme = localStorage.getItem('theme') || 'light';
      document.documentElement.className = 'theme-' + savedTheme;
      window.addEventListener('DOMContentLoaded', () => {
        document.body.className = 'theme-' + savedTheme;
      });
    })();
  </script>
  <style>
    /* Page specific custom premium styling extensions */
    .room-layout {
      margin-top: calc(72px + var(--space-6));
      display: grid;
      grid-template-columns: 1fr;
      gap: var(--space-6);
      width: 100%;
    }
    @media (min-width: 1024px) {
      .room-layout {
        grid-template-columns: 1.4fr 1fr;
      }
    }
    .back-btn-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: var(--color-text-secondary);
      font-weight: 600;
      font-size: var(--text-sm);
      text-decoration: none;
      transition: color 0.2s;
      margin-bottom: var(--space-4);
    }
    .back-btn-link:hover {
      color: var(--color-brand-primary);
    }
    .assessment-card {
      background: var(--color-bg-surface);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-outer);
      padding: var(--space-6);
      box-shadow: var(--shadow-md);
    }
    .company-header {
      display: flex;
      align-items: center;
      gap: var(--space-4);
      margin-bottom: var(--space-5);
      border-bottom: 1px solid var(--color-border);
      padding-bottom: var(--space-4);
    }
    .company-logo {
      width: 64px;
      height: 64px;
      border-radius: var(--radius-inner);
      object-fit: cover;
      border: 1px solid var(--color-border);
    }
    .company-logo-fallback {
      width: 64px;
      height: 64px;
      border-radius: var(--radius-inner);
      background: var(--color-brand-light);
      color: var(--color-brand-primary);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 2rem;
      font-weight: 800;
      border: 1px solid var(--color-brand-light);
    }
    .job-title {
      font-size: var(--text-2xl);
      font-weight: 800;
      color: var(--color-text-primary);
      margin-bottom: var(--space-1);
    }
    .company-name {
      font-size: var(--text-base);
      color: var(--color-text-secondary);
      font-weight: 600;
    }
    .job-description-title {
      font-size: var(--text-sm);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--color-text-secondary);
      font-weight: 700;
      margin-bottom: var(--space-2);
    }
    .job-description-text {
      color: var(--color-text-secondary);
      font-size: var(--text-sm);
      line-height: 1.6;
      margin-bottom: var(--space-5);
    }
    .meta-badge-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: var(--space-3);
    }
    .meta-badge-item {
      background: var(--color-bg-subtle);
      border: 1px solid var(--color-border);
      padding: var(--space-3);
      border-radius: var(--radius-inner);
      display: flex;
      align-items: center;
      gap: var(--space-3);
    }
    .meta-badge-icon {
      font-size: 1.25rem;
    }
    .meta-badge-label {
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--color-text-muted);
      font-weight: 700;
      margin-bottom: 2px;
    }
    .meta-badge-value {
      font-size: var(--text-sm);
      color: var(--color-text-primary);
      font-weight: 600;
    }
    .setup-card {
      background: var(--color-bg-surface);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-outer);
      padding: var(--space-6);
      box-shadow: var(--shadow-md);
      display: flex;
      flex-direction: column;
      height: fit-content;
    }
    .setup-title {
      font-size: var(--text-lg);
      font-weight: 700;
      color: var(--color-text-primary);
      margin-bottom: var(--space-4);
    }
    .profile-selection-list {
      display: flex;
      flex-direction: column;
      gap: var(--space-3);
      margin-bottom: var(--space-5);
    }
    .profile-card {
      border: 1px solid var(--color-border);
      border-radius: var(--radius-inner);
      padding: var(--space-3) var(--space-4);
      cursor: pointer;
      position: relative;
      display: flex;
      align-items: center;
      gap: var(--space-3);
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .profile-card:hover {
      background: var(--color-bg-subtle);
      border-color: var(--color-border-hover);
    }
    .profile-card.selected {
      border-color: var(--color-brand-primary);
      background: rgba(79, 70, 229, 0.02);
      box-shadow: 0 0 0 1px var(--color-brand-primary);
    }
    .profile-card-radio {
      accent-color: var(--color-brand-primary);
      width: 18px;
      height: 18px;
      cursor: pointer;
    }
    .profile-card-details {
      flex: 1;
      min-width: 0;
    }
    .profile-card-role {
      font-weight: 600;
      font-size: var(--text-sm);
      color: var(--color-text-primary);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      margin-bottom: 2px;
    }
    .profile-card-meta {
      font-size: var(--text-xs);
      color: var(--color-text-muted);
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .match-tag {
      background: rgba(16, 185, 129, 0.08);
      color: var(--color-success);
      font-size: 9px;
      font-weight: 700;
      padding: 1px 5px;
      border-radius: 4px;
      text-transform: uppercase;
      margin-left: auto;
    }
    .friendly-reminder {
      background: rgba(217, 119, 6, 0.06);
      border: 1px solid rgba(217, 119, 6, 0.15);
      border-radius: var(--radius-inner);
      padding: var(--space-4);
      color: #b45309;
      font-size: var(--text-sm);
      line-height: 1.5;
      margin-bottom: var(--space-5);
      display: flex;
      gap: var(--space-3);
      align-items: flex-start;
    }
    .friendly-reminder-icon {
      font-size: 1.25rem;
      line-height: 1;
      flex-shrink: 0;
    }
    .start-btn {
      width: 100%;
      padding: 14px;
      font-size: var(--text-base);
      font-weight: 700;
      border-radius: var(--radius-inner);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: var(--space-2);
    }
    .error-card {
      max-width: 500px;
      margin: var(--space-12) auto;
      text-align: center;
      padding: var(--space-8);
      background: var(--color-bg-surface);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-outer);
      box-shadow: var(--shadow-lg);
    }
    .error-icon {
      font-size: 3rem;
      margin-bottom: var(--space-4);
    }
    .error-title {
      font-size: var(--text-xl);
      font-weight: 700;
      color: var(--color-danger);
      margin-bottom: var(--space-2);
    }
    .error-text {
      color: var(--color-text-secondary);
      font-size: var(--text-sm);
      line-height: 1.6;
      margin-bottom: var(--space-6);
    }
  </style>
</head>
<body>

  <!-- Header -->
  <header class="v2-header">
    <div class="v2-header-inner">
      <a href="candidate-v2/index.php" class="brand-wrapper">
        <svg style="width: 28px; height: 28px; color: var(--color-brand-primary);" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
        </svg>
        <span class="brand-title">TruInterview</span>
      </a>
      <div class="user-nav">
        <div class="avatar-circle">
          <?php
            $words = explode(" ", $user['full_name']);
            $initials = "";
            foreach ($words as $w) {
                if (!empty($w)) $initials .= strtoupper($w[0]);
            }
            echo htmlspecialchars(substr($initials, 0, 2));
          ?>
        </div>
        <a href="logout.php" class="btn btn-outline-header" style="padding: 6px 12px; font-size: 0.8rem;">Log Out</a>
      </div>
    </div>
  </header>

  <!-- Layout Container -->
  <div class="v2-layout">
    <a href="candidate-v2/index.php" class="back-btn-link">
      <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
      </svg>
      <span>Back to Dashboard</span>
    </a>

    <?php if (!empty($error)): ?>
      <!-- Error State -->
      <div class="error-card">
        <div class="error-icon">⚠️</div>
        <h2 class="error-title">Unable to Join Assessment</h2>
        <p class="error-text"><?php echo $error; ?></p>
        <a href="candidate-v2/index.php" class="btn btn-primary">Return to Dashboard</a>
      </div>
    <?php else: ?>
      <!-- Main Content -->
      <div class="room-layout">
        
        <!-- Left Side: Assessment Details -->
        <div class="assessment-card">
          <div class="company-header">
            <?php if (!empty($company['logo_url'])): ?>
              <img src="<?php echo htmlspecialchars($company['logo_url']); ?>" alt="<?php echo htmlspecialchars($company['name'] ?? $link['company_name']); ?>" class="company-logo">
            <?php else: ?>
              <div class="company-logo-fallback">
                <?php echo htmlspecialchars(strtoupper(substr($company['name'] ?? $link['company_name'] ?? 'Company', 0, 1))); ?>
              </div>
            <?php endif; ?>
            <div>
              <h1 class="job-title"><?php echo htmlspecialchars($link['job_role']); ?></h1>
              <div class="company-name"><?php echo htmlspecialchars($company['name'] ?? $link['company_name'] ?? 'Company'); ?></div>
            </div>
          </div>

          <?php if (!empty($link['job_description'])): ?>
            <div class="job-description-title">Job Description & Details</div>
            <div class="job-description-text">
              <?php echo nl2br(htmlspecialchars($link['job_description'])); ?>
            </div>
          <?php endif; ?>

          <div class="job-description-title" style="margin-top: var(--space-4);">Assessment Structure</div>
          <div class="meta-badge-grid">
            <div class="meta-badge-item">
              <span class="meta-badge-icon">⚡</span>
              <div>
                <div class="meta-badge-label">Difficulty Level</div>
                <div class="meta-badge-value">
                  <?php 
                    $minLvl = max(0, (int)$link['min_level']);
                    echo htmlspecialchars($levelNames[$minLvl] ?? "Novice") . " (Level " . $minLvl . ")";
                  ?>
                </div>
              </div>
            </div>

            <div class="meta-badge-item">
              <span class="meta-badge-icon">❓</span>
              <div>
                <div class="meta-badge-label">Question Config</div>
                <div class="meta-badge-value">
                  <?php 
                    $numOpen = isset($link['num_open_questions']) ? (int)$link['num_open_questions'] : 4;
                    $numMcq = isset($link['num_mcq_questions']) ? (int)$link['num_mcq_questions'] : 4;
                    echo $numOpen . " Open / " . $numMcq . " MCQ";
                  ?>
                </div>
              </div>
            </div>

            <div class="meta-badge-item">
              <span class="meta-badge-icon">🔄</span>
              <div>
                <div class="meta-badge-label">Attempts Allowed</div>
                <div class="meta-badge-value">
                  <?php echo (int)($link['attempts_used'] ?? 0) . " / " . (int)($link['max_attempts'] ?? 1); ?> Used
                </div>
              </div>
            </div>

            <?php if (!empty($link['expires_at'])): ?>
              <div class="meta-badge-item">
                <span class="meta-badge-icon">📅</span>
                <div>
                  <div class="meta-badge-label">Expiration Date</div>
                  <div class="meta-badge-value">
                    <?php echo date('M d, Y', strtotime($link['expires_at'])); ?>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Right Side: Config & Profile Selection -->
        <div class="setup-card">
          <h2 class="setup-title">Configure Assessment Profile</h2>
          
          <?php if (empty($profiles)): ?>
            <!-- Friendly Reminder when no profiles are available -->
            <div class="friendly-reminder">
              <span class="friendly-reminder-icon">💡</span>
              <div>
                <strong>No profiles created yet.</strong><br>
                Creating a tailored profile and uploading your resume increases your chances of passing the technical assessment and advancing to the next level!
              </div>
            </div>
            
            <a href="candidate-v2/index.php" class="btn btn-primary start-btn" style="text-align: center;">
              Create Role Profile First
            </a>
          <?php else: ?>
            <p style="color: var(--color-text-secondary); font-size: var(--text-sm); margin-bottom: var(--space-4);">
              Select one of your candidate profiles to proceed with the technical assessment. The AI will use your resume to customize and grade your interview.
            </p>

            <div class="profile-selection-list">
              <!-- Render Matched Profiles First -->
              <?php foreach ($matchedProfiles as $idx => $p): ?>
                <label class="profile-card selected" data-profile-id="<?php echo $p['id']; ?>">
                  <input type="radio" name="selected_profile" class="profile-card-radio" value="<?php echo $p['id']; ?>" checked>
                  <div class="profile-card-details">
                    <div class="profile-card-role" title="<?php echo htmlspecialchars($p['role_title']); ?>">
                      <?php echo htmlspecialchars($p['role_title']); ?>
                    </div>
                    <div class="profile-card-meta">
                      <span>Level <?php echo (int)($p['level'] ?? 0); ?></span>
                      <span>•</span>
                      <span><?php echo !empty($p['optimized_resume_path']) ? 'Resume uploaded' : 'No resume'; ?></span>
                    </div>
                  </div>
                  <span class="match-tag">Matched</span>
                </label>
              <?php endforeach; ?>

              <!-- Render Other Profiles -->
              <?php foreach ($otherProfiles as $idx => $p): ?>
                <label class="profile-card <?php echo (empty($matchedProfiles) && $idx === 0) ? 'selected' : ''; ?>" data-profile-id="<?php echo $p['id']; ?>">
                  <input type="radio" name="selected_profile" class="profile-card-radio" value="<?php echo $p['id']; ?>" <?php echo (empty($matchedProfiles) && $idx === 0) ? 'checked' : ''; ?>>
                  <div class="profile-card-details">
                    <div class="profile-card-role" title="<?php echo htmlspecialchars($p['role_title']); ?>">
                      <?php echo htmlspecialchars($p['role_title']); ?>
                    </div>
                    <div class="profile-card-meta">
                      <span>Level <?php echo (int)($p['level'] ?? 0); ?></span>
                      <span>•</span>
                      <span><?php echo !empty($p['optimized_resume_path']) ? 'Resume uploaded' : 'No resume'; ?></span>
                    </div>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>

            <!-- Friendly Reminder to optimize profile -->
            <div class="friendly-reminder">
              <span class="friendly-reminder-icon">💡</span>
              <div>
                Tailoring profiles and uploading your resume increases your chances of passing the technical assessment and advancing to the next level!
              </div>
            </div>

            <button type="button" class="btn btn-primary start-btn" id="btn-start-assessment">
              <span>Start Assessment</span>
              <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
              </svg>
            </button>
          <?php endif; ?>
        </div>

      </div>
    <?php endif; ?>
  </div>

  <!-- Loading Overlay for AI Question Generation -->
  <div class="modal-overlay" id="loading-overlay">
    <div style="display: flex; flex-direction: column; align-items: center; gap: var(--space-4); background: var(--color-bg-surface); padding: var(--space-6) var(--space-8); border-radius: var(--radius-outer); box-shadow: var(--shadow-float); text-align: center;">
      <div class="spinner" style="border-color: rgba(79, 70, 229, 0.2); border-top-color: var(--color-brand-primary); width: 32px; height: 32px; margin-bottom: var(--space-2);"></div>
      <div style="font-weight: 700; color: var(--color-text-primary); font-size: var(--text-base);">Preparing AI Assessment Questions...</div>
      <div style="font-size: var(--text-xs); color: var(--color-text-muted); max-width: 280px;">This may take a moment. We are formulating tailored questions matching your profile and level constraints.</div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      // Handle Profile Card selection visual state change
      const cards = document.querySelectorAll('.profile-card');
      cards.forEach(card => {
        card.addEventListener('click', (e) => {
          // If we clicked the radio itself, don't trigger again to avoid recursion
          if (e.target.tagName !== 'INPUT') {
            const radio = card.querySelector('input[type="radio"]');
            if (radio) {
              radio.checked = true;
            }
          }
          cards.forEach(c => c.classList.remove('selected'));
          card.classList.add('selected');
        });
      });

      // Handle start assessment action
      const startBtn = document.getElementById('btn-start-assessment');
      const loadingOverlay = document.getElementById('loading-overlay');
      const codeVal = <?php echo json_encode($code); ?>;

      if (startBtn) {
        startBtn.addEventListener('click', async () => {
          const selectedRadio = document.querySelector('input[name="selected_profile"]:checked');
          if (!selectedRadio) {
            alert('Please select a candidate profile to proceed.');
            return;
          }
          const profileId = selectedRadio.value;

          // Show loading overlay
          if (loadingOverlay) {
            loadingOverlay.classList.add('active');
          }

          try {
            const formData = new URLSearchParams();
            formData.append('profile_id', profileId);
            formData.append('code', codeVal);

            const res = await fetch('api/prepare_practice.php', {
              method: 'POST',
              body: formData,
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            });
            const data = await res.json();

            if (data.status === 'success') {
              // Redirect directly to interview page
              window.location.href = 'interview.php?code=' + encodeURIComponent(codeVal) + '&profile_id=' + encodeURIComponent(profileId);
            } else {
              if (loadingOverlay) loadingOverlay.classList.remove('active');
              alert(data.message || 'Error preparing assessment interview. Please try again.');
            }
          } catch (err) {
            if (loadingOverlay) loadingOverlay.classList.remove('active');
            console.error('Fetch error:', err);
            alert('Network error while preparing interview. Please try again.');
          }
        });
      }
    });
  </script>
</body>
</html>
