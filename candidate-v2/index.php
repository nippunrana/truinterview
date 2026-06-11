<?php
// candidate-v2/index.php - Candidate Dashboard V2
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

// Enforce Candidate role
requireAuth(['candidate']);
$user = getCurrentUser();

$db = getDB();
$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $user['id']]);
$userFull = $stmt->fetch(PDO::FETCH_ASSOC);

$profiles = getCandidateProfiles($user['id']);
$maxProfiles = 3;
$canAddProfile = count($profiles) < $maxProfiles;

// Get initials for avatar placeholder
$words = explode(" ", $user['full_name']);
$initials = "";
foreach ($words as $w) {
    if (!empty($w)) $initials .= strtoupper($w[0]);
}
$initials = substr($initials, 0, 2);
$firstName = !empty($words[0]) ? $words[0] : 'Candidate';
$profileCount = count($profiles);

$resumes = getCandidateResumes($userFull['resume_path'] ?? '');
$baseResume = null;
foreach ($resumes as $r) {
    if (!empty($r['is_base'])) {
        $baseResume = $r;
        break;
    }
}
if (!$baseResume && !empty($resumes)) {
    $baseResume = $resumes[0];
    $baseResume['is_base'] = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Candidate Hub - TruInterview</title>
  <link rel="stylesheet" href="../assets/css/candidate-v2.css">
  
  <!-- JOIN INTERVIEW STYLES (Modular options) -->
  <style>
    /* OPTION A: Command Center */
    .join-hero-section {
      background: var(--color-bg-surface, #ffffff);
      border: 1px solid var(--color-border, #e5e7eb);
      border-radius: var(--radius-outer, 16px);
      padding: var(--space-6, 24px);
      margin-top: var(--space-8, 32px);
      margin-bottom: 0;
      display: flex;
      flex-direction: column;
      gap: var(--space-4, 16px);
      box-shadow: var(--shadow-sm, 0 1px 2px rgba(0,0,0,0.05));
    }
    @media (min-width: 640px) {
      .join-hero-section {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
      }
    }
    .join-hero-content h2 {
      font-size: var(--text-lg, 1.125rem);
      font-weight: 600;
      color: var(--color-text-primary, #111827);
      margin-bottom: var(--space-1, 4px);
      margin-top: 0;
    }
    .join-hero-content p {
      font-size: var(--text-sm, 0.875rem);
      color: var(--color-text-secondary, #4b5563);
      margin: 0;
    }
    .join-hero-form {
      display: flex;
      gap: var(--space-2, 8px);
      width: 100%;
    }
    @media (min-width: 640px) {
      .join-hero-form {
        max-width: 320px;
      }
    }
    .join-input {
      flex: 1;
      padding: 10px 14px;
      border: 1px solid var(--color-border, #e5e7eb);
      border-radius: var(--radius-inner, 8px);
      font-size: var(--text-base, 1rem);
      font-family: inherit;
      outline: none;
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
      letter-spacing: 1px;
      background: var(--color-bg-surface, #ffffff);
      color: var(--color-text-primary, #111827);
      box-sizing: border-box;
    }
    .join-input:focus {
      border-color: var(--color-brand-primary, #4f46e5);
      box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
    }

    /* OPTION C: Header Action */
    .btn-header-join {
      background: var(--color-brand-primary, #4f46e5);
      color: #ffffff;
      border: 1px solid var(--color-brand-primary, #4f46e5);
      padding: 6px 12px;
      font-size: 0.875rem;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 6px;
      border-radius: var(--radius-inner, 8px);
      cursor: pointer;
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: 0 1px 2px rgba(79, 70, 229, 0.2);
    }
    .btn-header-join:hover {
      background: #4338ca;
      border-color: #4338ca;
      box-shadow: 0 4px 6px rgba(79, 70, 229, 0.25);
      transform: translateY(-1px);
    }
    .join-btn-text {
      display: none;
    }
    @media (min-width: 640px) {
      .join-btn-text {
        display: inline;
      }
    }
  </style>
  <!-- END JOIN INTERVIEW STYLES -->
</head>
<body>

  <div class="v2-layout">
    
    <header class="v2-header">
      <a href="index.php" class="brand-wrapper">
        <svg style="width: 28px; height: 28px; color: var(--color-brand-primary);" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
        </svg>
        <span class="brand-title">TruInterview</span>
      </a>
      
      <div class="user-nav">
        <!-- OPTION C: Header Action Button -->
        <button id="btn-open-join-modal" class="btn-header-join" title="Join Interview">
          <svg style="width: 16px; height: 16px; color: currentColor;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
          <span class="join-btn-text">Join Interview</span>
        </button>
        <!-- END OPTION C -->

        <button id="btn-open-settings-modal" class="btn btn-outline" style="padding: 6px; border: none; background: transparent; color: var(--color-text-secondary);" title="Settings">
          <svg style="width: 22px; height: 22px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
        </button>
        <div class="avatar-circle"><?php echo htmlspecialchars($initials); ?></div>
        <a href="../logout.php" class="btn btn-outline" style="padding: 6px 12px; font-size: 0.8rem;">Log Out</a>
      </div>
    </header>

    <main>
      <h1 class="title-main">Hi, <?php echo htmlspecialchars($firstName); ?>. Let's get you hired.</h1>
      <p class="subtitle-main">
        <?php if ($profileCount === 0): ?>
          Your interview prep starts here. Create your first role profile so you can get an AI-tailored resume and start practicing live mock interviews.
        <?php elseif ($profileCount === 1): ?>
          You're on the board. You can add 2 more distinct roles. Upload a resume to get it optimized, then jump into a practice interview to sharpen your pitch.
        <?php elseif ($profileCount === 2): ?>
          You're building your range with room for 1 more role. Keep refining your resumes and practicing so you can walk into your real interviews completely prepared.
        <?php else: ?>
          Your target roles are locked in. Focus on perfecting your optimized resumes and mastering your mock interviews for these 3 positions.
        <?php endif; ?>
      </p>

      <div class="bento-grid">
        
        <?php foreach ($profiles as $idx => $profile): ?>
          <div class="bento-card" data-profile-id="<?php echo $profile['id']; ?>">
            <div class="card-header">
              <div class="role-title"><?php echo htmlspecialchars($profile['role_title']); ?></div>
              <div style="display: flex; align-items: center; gap: var(--space-2);">
                <div class="card-badge">Profile <?php echo $idx + 1; ?></div>
                <button class="btn-delete-profile btn-danger-ghost" data-id="<?php echo $profile['id']; ?>" style="border: none; cursor: pointer; padding: 4px; border-radius: 4px; display: flex; align-items: center; justify-content: center; background: transparent; color: var(--color-text-muted); transition: color 0.2s;" onmouseover="this.style.color='var(--color-danger)'" onmouseout="this.style.color='var(--color-text-muted)'" title="Delete Profile">
                  <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                </button>
              </div>
            </div>

            <div class="card-body">
              <div class="status-item">
                <?php if (!empty($profile['optimized_resume_path'])): ?>
                  <svg class="status-icon status-success" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                  </svg>
                  <div>
                    <strong>Resume Optimized</strong>
                    <div style="font-size: 0.75rem; margin-top: 2px;">
                      <a href="../<?php echo htmlspecialchars($profile['optimized_resume_path']); ?>" target="_blank" style="color: var(--color-brand-primary); text-decoration: none;">View File</a>
                    </div>
                  </div>
                <?php else: ?>
                  <svg class="status-icon status-pending" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                  </svg>
                  <div>
                    <strong>No Resume Uploaded</strong>
                    <div style="font-size: 0.75rem; margin-top: 2px;">Upload a base resume to optimize for this role.</div>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <div class="card-actions">
              <!-- If we had an optimizer tool here, we'd link to it. For now we just upload. -->
              <label class="btn btn-outline" style="flex: 1; text-align: center; padding: 10px 0;">
                <input type="file" class="hidden-upload resume-upload-input" data-id="<?php echo $profile['id']; ?>" accept=".pdf,.doc,.docx,.md" />
                <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                Upload Resume
              </label>

              <a href="../interview.php?practice_role=<?php echo urlencode($profile['role_title']); ?>" class="btn btn-primary" style="flex: 1;">
                <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                Practice
              </a>
            </div>

          </div>
        <?php endforeach; ?>

        <?php if ($canAddProfile): ?>
          <div class="bento-card card-add" id="btn-open-create-modal">
            <svg class="add-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"></path>
            </svg>
            <div style="font-weight: 600; color: var(--color-text-primary); font-size: var(--text-lg);">Add New Role</div>
            <div style="font-size: var(--text-sm); color: var(--color-text-muted); margin-top: 4px;">You can add <?php echo $maxProfiles - count($profiles); ?> more profile(s)</div>
          </div>
        <?php endif; ?>
        
      </div>

      <!-- RESUME MANAGEMENT SECTION -->
      <section class="resume-section">
        <div class="resume-section-header">
          <h2 class="resume-section-title">Resume Management</h2>
          <p class="resume-section-subtitle">Select or upload your base resume so that our AI can optimize your range for targeted profiles.</p>
        </div>

        <div class="resume-grid">
          <!-- Left Panel: Base Resume Details -->
          <div class="resume-base-card">
            <div class="resume-base-header">
              <span class="resume-base-title">Selected Base Resume</span>
              <?php if ($baseResume): ?>
                <span class="resume-badge-base">Active</span>
              <?php endif; ?>
            </div>

            <div class="resume-base-body">
              <?php if ($baseResume): 
                $ext = strtoupper(pathinfo($baseResume['path'], PATHINFO_EXTENSION));
                $displayRole = !empty($baseResume['detected_role']) ? $baseResume['detected_role'] : 'Resume';
              ?>
                <div>
                  <div style="font-weight: 700; font-size: var(--text-sm); color: var(--color-text-primary); display: flex; align-items: center; gap: 8px;">
                    <svg style="width: 18px; height: 18px; color: var(--color-brand-primary);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    <span><?php echo htmlspecialchars($displayRole); ?></span>
                    <span style="font-size: 10px; padding: 2px 6px; border-radius: 4px; background: var(--color-bg-subtle); color: var(--color-text-secondary); font-weight: 600; text-transform: uppercase;"><?php echo htmlspecialchars($ext); ?></span>
                  </div>
                  <div style="font-size: var(--text-xs); color: var(--color-text-muted); margin-top: 4px;">
                    Uploaded on <?php echo date('M d, Y h:i A', $baseResume['date']); ?>
                  </div>
                </div>

                <div class="resume-summary-box">
                  <div style="font-weight: 700; font-size: 11px; text-transform: uppercase; color: var(--color-text-secondary); margin-bottom: 6px; letter-spacing: 0.05em;">AI Profile Analysis</div>
                  <div><?php echo htmlspecialchars($baseResume['short_description'] ?? 'No description parsed yet.'); ?></div>
                </div>

                <div style="display: flex; gap: var(--space-3); margin-top: auto; padding-top: var(--space-4);">
                  <a href="../candidate/resume_optimizer.php?resume_path=<?php echo urlencode($baseResume['path']); ?>" class="btn btn-primary" style="flex: 1;">
                    <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                    Optimize Base
                  </a>
                  <a href="../<?php echo htmlspecialchars($baseResume['path']); ?>" target="_blank" class="btn btn-outline" style="flex: 1;">
                    <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    View Document
                  </a>
                </div>
              <?php else: ?>
                <div style="text-align: center; padding: var(--space-8) var(--space-4); color: var(--color-text-muted); display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%;">
                  <svg style="width: 48px; height: 48px; color: var(--color-text-muted); margin-bottom: var(--space-3);" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                  </svg>
                  <div style="font-weight: 600; color: var(--color-text-secondary);">No Base Resume Selected</div>
                  <p style="font-size: var(--text-xs); margin-top: 4px; max-width: 280px;">Upload a resume to establish your primary profile and activate interview prep.</p>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Right Panel: Resume History & Upload -->
          <div class="resume-history-card">
            <div class="resume-base-header">
              <span class="resume-base-title">Upload & Version History</span>
              <span style="font-size: var(--text-xs); color: var(--color-text-secondary);"><?php echo count($resumes); ?>/5 Resumes</span>
            </div>

            <div class="resume-base-body">
              <?php if (empty($resumes)): ?>
                <div style="text-align: center; padding: var(--space-8) var(--space-4); color: var(--color-text-muted); display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%;">
                  <svg style="width: 32px; height: 32px; color: var(--color-text-muted); margin-bottom: var(--space-2);" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                  <p style="font-size: var(--text-xs); margin: 0;">Upload your first resume in the action box below.</p>
                </div>
              <?php else: ?>
                <div class="resume-list">
                  <?php foreach ($resumes as $idx => $res): 
                    $isResBase = !empty($res['is_base']);
                    $fileName = basename($res['path']);
                    $displayRole = !empty($res['detected_role']) ? $res['detected_role'] : 'Resume';
                    $ext = strtoupper(pathinfo($res['path'], PATHINFO_EXTENSION));
                  ?>
                    <div class="resume-item <?php echo $isResBase ? 'active' : ''; ?>">
                      <div class="resume-item-info" style="max-width: 60%;">
                        <div class="resume-item-title" style="display: flex; align-items: center; gap: 8px;" title="<?php echo htmlspecialchars($displayRole); ?>">
                          <?php if ($isResBase): ?>
                            <span class="resume-badge-base" style="font-size: 8px; padding: 1px 4px;">Base</span>
                          <?php endif; ?>
                          <span style="white-space: nowrap; text-overflow: ellipsis; overflow: hidden;"><?php echo htmlspecialchars($displayRole); ?></span>
                          <span style="font-size: 9px; padding: 1px 4px; border-radius: 3px; background: var(--color-bg-subtle); color: var(--color-text-secondary); font-weight: 600; text-transform: uppercase;"><?php echo htmlspecialchars($ext); ?></span>
                        </div>
                        <div class="resume-item-date">
                          Uploaded <?php echo date('M d, Y', $res['date']); ?>
                        </div>
                      </div>

                      <div class="resume-item-actions">
                        <?php if (!$isResBase): ?>
                          <button class="btn btn-outline btn-set-base" data-path="<?php echo htmlspecialchars($res['path']); ?>" style="padding: 4px 8px; font-size: 11px;" title="Set as base resume">Set Base</button>
                        <?php endif; ?>
                        <a href="../<?php echo htmlspecialchars($res['path']); ?>" target="_blank" class="btn btn-outline" style="padding: 4px; border-radius: 6px;" title="View Resume">
                          <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        </a>
                        <button class="btn btn-outline btn-delete-global-resume" data-path="<?php echo htmlspecialchars($res['path']); ?>" style="padding: 4px; border-radius: 6px; color: var(--color-danger);" title="Delete Resume">
                          <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                        </button>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <?php if (count($resumes) < 5): ?>
                <div style="margin-top: auto; padding-top: var(--space-4);">
                  <label class="btn btn-outline btn-full" style="padding: 10px 0; border-style: dashed; cursor: pointer;">
                    <input type="file" class="hidden-upload" id="global-resume-file-input" accept=".pdf,.doc,.docx,.md" />
                    <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                    Upload New Version
                  </label>
                </div>
              <?php else: ?>
                <div style="margin-top: auto; padding-top: var(--space-4); text-align: center; font-size: var(--text-xs); color: var(--color-text-muted);">
                  Limit of 5 resumes reached. Delete previous versions to upload.
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </section>

      <!-- JOIN INTERVIEW BAR -->
      <div class="join-hero-section" id="join-bottom-bar">
        <div class="join-hero-content">
          <h2>Got an interview code?</h2>
          <p>Enter your 6-digit code to join a live session instantly.</p>
        </div>
        <form class="join-hero-form" onsubmit="event.preventDefault(); joinInterview('input-join-bar');">
          <input type="text" id="input-join-bar" class="join-input" placeholder="e.g. 1A2B3C" autocomplete="off" maxlength="10">
          <button type="submit" class="btn btn-primary" style="white-space: nowrap;">Join Now</button>
        </form>
      </div>
      <!-- END JOIN INTERVIEW BAR -->
    </main>
  </div>

  <!-- Loading Overlay for AI -->
  <div class="modal-overlay" id="ai-loading-overlay">
    <div style="display: flex; flex-direction: column; align-items: center; gap: var(--space-4); background: var(--color-bg-surface); padding: var(--space-6) var(--space-8); border-radius: var(--radius-outer); box-shadow: var(--shadow-float);">
      <div class="spinner" style="border-color: rgba(79, 70, 229, 0.2); border-top-color: var(--color-brand-primary); width: 32px; height: 32px;"></div>
      <div style="font-weight: 600; color: var(--color-text-primary);">Running initial AI verification on your resume...</div>
    </div>
  </div>

  <!-- Delete Profile Modal -->
  <div class="modal-overlay" id="delete-modal">
    <div class="modal-content">
      <h3 style="display: flex; align-items: center; gap: var(--space-2); color: var(--color-danger); margin-bottom: var(--space-3); font-size: var(--text-xl); font-family: 'Outfit', sans-serif;">
        <svg style="width: 24px; height: 24px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
        </svg>
        Delete Profile
      </h3>
      <div style="color: var(--color-text-secondary); font-size: var(--text-sm); line-height: 1.6; margin-bottom: var(--space-6);">
        Are you sure you want to delete this profile? This action cannot be undone.
      </div>
      <div style="display: flex; justify-content: flex-end; gap: var(--space-3);">
        <button id="btn-delete-cancel" class="btn btn-outline">Cancel</button>
        <button id="btn-delete-confirm" class="btn btn-primary" style="background: var(--color-danger); border-color: var(--color-danger);">Yes, Delete</button>
      </div>
    </div>
  </div>

  <!-- Delete Global Resume Modal -->
  <div class="modal-overlay" id="delete-global-resume-modal">
    <div class="modal-content">
      <h3 style="display: flex; align-items: center; gap: var(--space-2); color: var(--color-danger); margin-bottom: var(--space-3); font-size: var(--text-xl); font-family: 'Outfit', sans-serif;">
        <svg style="width: 24px; height: 24px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
        </svg>
        Delete Resume
      </h3>
      <div style="color: var(--color-text-secondary); font-size: var(--text-sm); line-height: 1.6; margin-bottom: var(--space-6);">
        Are you sure you want to delete this resume? This will remove the file from your account.
      </div>
      <div style="display: flex; justify-content: flex-end; gap: var(--space-3);">
        <button id="btn-delete-global-cancel" class="btn btn-outline">Cancel</button>
        <button id="btn-delete-global-confirm" class="btn btn-primary" style="background: var(--color-danger); border-color: var(--color-danger);">Yes, Delete</button>
      </div>
    </div>
  </div>

  <!-- Mismatch Modal -->
  <div class="modal-overlay" id="mismatch-modal">
    <div class="modal-content">
      <h3 style="display: flex; align-items: center; gap: var(--space-2); color: var(--color-danger); margin-bottom: var(--space-3); font-size: var(--text-xl); font-family: 'Outfit', sans-serif;">
        <svg style="width: 24px; height: 24px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
        </svg>
        Name Mismatch Detected
      </h3>
      <div id="mismatch-modal-text" style="color: var(--color-text-secondary); font-size: var(--text-sm); line-height: 1.6; margin-bottom: var(--space-6);">
        The uploaded resume is not for [Profile Name] but instead shows the name [X]. Do you really want to upload this to your profile or want to skip it?
      </div>
      <div style="display: flex; justify-content: flex-end; gap: var(--space-3);">
        <button id="btn-mismatch-skip" class="btn btn-outline">Skip / Cancel</button>
        <button id="btn-mismatch-confirm" class="btn btn-primary" style="background: var(--color-danger);">Yes, Upload Anyway</button>
      </div>
    </div>
  </div>

  <!-- Settings Modal -->
  <div class="modal-overlay" id="settings-modal">
    <div class="modal-content" style="max-width: 600px;">
      <h2 style="margin-bottom: var(--space-4); display: flex; align-items: center; gap: 8px;">
        <svg style="width: 24px; height: 24px; color: var(--color-brand-primary);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
        AI Brain Settings
      </h2>
      
      <form id="form-settings" style="display: flex; flex-direction: column; gap: var(--space-4);">
        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label">Custom Gemini API Key</label>
          <input type="password" name="custom_gemini_api_key" class="form-input" placeholder="e.g. AIzaSy..." value="<?php echo htmlspecialchars($userFull['custom_gemini_api_key'] ?? ''); ?>">
          <div style="font-size: 0.75rem; color: var(--color-text-muted); margin-top: 4px;">If empty, the platform global API key is used.</div>
        </div>
        
        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label">Dialogue (Chat) Model Override</label>
          <select name="model_chat_task" class="form-input">
            <option value="gemini-3.5-flash" <?php if (($userFull['model_chat_task'] ?? '') === 'gemini-3.5-flash') echo 'selected'; ?>>gemini-3.5-flash (Fast, conversational)</option>
            <option value="gemini-3.1-flash-lite" <?php if (($userFull['model_chat_task'] ?? '') === 'gemini-3.1-flash-lite') echo 'selected'; ?>>gemini-3.1-flash-lite (Ultra-low latency dialog)</option>
            <option value="gemini-3.1-pro-preview" <?php if (($userFull['model_chat_task'] ?? '') === 'gemini-3.1-pro-preview') echo 'selected'; ?>>gemini-3.1-pro (Deep, rich answers)</option>
          </select>
        </div>

        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label">Screen Context (Vision) Model Override</label>
          <select name="model_vision_task" class="form-input">
            <option value="gemini-3.5-flash" <?php if (($userFull['model_vision_task'] ?? '') === 'gemini-3.5-flash') echo 'selected'; ?>>gemini-3.5-flash (Balanced speed)</option>
            <option value="gemini-3.1-pro-preview" <?php if (($userFull['model_vision_task'] ?? '') === 'gemini-3.1-pro-preview') echo 'selected'; ?>>gemini-3.1-pro (High intelligence code understanding)</option>
            <option value="gemini-3.1-flash-lite" <?php if (($userFull['model_vision_task'] ?? '') === 'gemini-3.1-flash-lite') echo 'selected'; ?>>gemini-3.1-flash-lite (Fastest processing)</option>
          </select>
        </div>

        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label">Evaluation (Grading) Model Override</label>
          <select name="model_eval_task" class="form-input">
            <option value="gemini-3.1-pro-preview" <?php if (($userFull['model_eval_task'] ?? '') === 'gemini-3.1-pro-preview') echo 'selected'; ?>>gemini-3.1-pro (Advanced grading report evaluation)</option>
            <option value="gemini-3.5-flash" <?php if (($userFull['model_eval_task'] ?? '') === 'gemini-3.5-flash') echo 'selected'; ?>>gemini-3.5-flash (Standard grading evaluation)</option>
            <option value="gemini-3.1-flash-lite" <?php if (($userFull['model_eval_task'] ?? '') === 'gemini-3.1-flash-lite') echo 'selected'; ?>>gemini-3.1-flash-lite (Fast grading evaluation)</option>
          </select>
        </div>

        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label">Resume Optimizer Model Override</label>
          <select name="model_optimizer_task" class="form-input">
            <option value="gemini-3.5-flash" <?php if (($userFull['model_optimizer_task'] ?? '') === 'gemini-3.5-flash') echo 'selected'; ?>>gemini-3.5-flash (Fast, accurate optimization)</option>
            <option value="gemini-3.1-flash-lite" <?php if (($userFull['model_optimizer_task'] ?? '') === 'gemini-3.1-flash-lite') echo 'selected'; ?>>gemini-3.1-flash-lite (Ultra-fast execution)</option>
            <option value="gemini-3.1-pro-preview" <?php if (($userFull['model_optimizer_task'] ?? '') === 'gemini-3.1-pro-preview') echo 'selected'; ?>>gemini-3.1-pro (Maximum alignment & deep quality rewrite)</option>
          </select>
        </div>
        
        <div style="display: flex; justify-content: flex-end; gap: var(--space-3); margin-top: var(--space-2);">
          <button type="button" class="btn btn-outline" id="btn-close-settings-modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="btn-submit-settings">Save Settings</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Create Profile Modal -->
  <div class="modal-overlay" id="create-modal">
    <div class="modal-content">
      <h2 style="margin-bottom: var(--space-2);">Create Role Profile</h2>
      <p style="color: var(--color-text-secondary); font-size: var(--text-sm); margin-bottom: var(--space-5);">Enter the title of the job role you want to prepare for (e.g. "Senior Frontend Developer", "AI Engineer").</p>
      
      <form id="form-create-profile">
        <div class="form-group">
          <label class="form-label" for="role_title">Role Title</label>
          <input type="text" id="role_title" name="role_title" class="form-input" placeholder="e.g. Mobile App Developer" required autocomplete="off">
        </div>
        
        <div style="display: flex; justify-content: flex-end; gap: var(--space-3); margin-top: var(--space-6);">
          <button type="button" class="btn btn-outline" id="btn-close-create-modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="btn-submit-create">
            <span>Create Profile</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- OPTION C: Join Interview Modal -->
  <div class="modal-overlay" id="join-modal">
    <div class="modal-content" style="max-width: 400px;">
      <h2 style="margin-bottom: var(--space-2); display: flex; align-items: center; gap: 8px;">
        <svg style="width: 24px; height: 24px; color: var(--color-brand-primary);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
        Join Interview
      </h2>
      <p style="color: var(--color-text-secondary); font-size: var(--text-sm); margin-bottom: var(--space-5);">Enter your 6-digit access code below.</p>
      
      <form id="form-join-modal" onsubmit="event.preventDefault(); joinInterview('input-option-c');">
        <div class="form-group">
          <label class="form-label" for="input-option-c">Interview Code</label>
          <input type="text" id="input-option-c" class="join-input" style="width: 100%; box-sizing: border-box;" placeholder="e.g. 1A2B3C" required autocomplete="off" maxlength="10">
        </div>
        
        <div style="display: flex; justify-content: flex-end; gap: var(--space-3); margin-top: var(--space-6);">
          <button type="button" class="btn btn-outline" id="btn-close-join-modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <span>Join Room</span>
          </button>
        </div>
      </form>
    </div>
  </div>
  <!-- END OPTION C -->

  <div class="toast-container" id="toast-container"></div>

  <script>
    const toastContainer = document.getElementById('toast-container');
    function showToast(message, type = 'success') {
      const toast = document.createElement('div');
      toast.className = 'toast';
      
      let icon = '';
      if (type === 'success') {
        icon = '<svg style="width:18px;height:18px;color:var(--color-success);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg>';
      } else {
        icon = '<svg style="width:18px;height:18px;color:var(--color-danger);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
      }
      
      toast.innerHTML = icon + '<span>' + message + '</span>';
      toastContainer.appendChild(toast);
      
      setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        setTimeout(() => toast.remove(), 300);
      }, 3000);
    }

    // Settings Modal Logic
    const settingsModal = document.getElementById('settings-modal');
    const btnOpenSettings = document.getElementById('btn-open-settings-modal');
    const btnCloseSettings = document.getElementById('btn-close-settings-modal');
    const formSettings = document.getElementById('form-settings');
    
    if (btnOpenSettings) {
      btnOpenSettings.addEventListener('click', () => {
        settingsModal.classList.add('active');
      });
    }
    
    if (btnCloseSettings) {
      btnCloseSettings.addEventListener('click', () => {
        settingsModal.classList.remove('active');
      });
    }

    if (formSettings) {
      formSettings.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btnSubmit = document.getElementById('btn-submit-settings');
        btnSubmit.innerHTML = '<span class="spinner"></span> Saving...';
        btnSubmit.disabled = true;

        const formData = new URLSearchParams(new FormData(formSettings));
        formData.append('action', 'update_settings');

        try {
          const res = await fetch('ajax.php', {
            method: 'POST',
            body: formData,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
          });
          const data = await res.json();
          
          if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => window.location.reload(), 1000);
          } else {
            showToast(data.message || 'Error updating settings', 'error');
            btnSubmit.innerHTML = 'Save Settings';
            btnSubmit.disabled = false;
          }
        } catch (err) {
          showToast('Network error', 'error');
          btnSubmit.innerHTML = 'Save Settings';
          btnSubmit.disabled = false;
        }
      });
    }

    // Create Profile Modal Logic
    const createModal = document.getElementById('create-modal');
    const btnOpenCreate = document.getElementById('btn-open-create-modal');
    const btnCloseCreate = document.getElementById('btn-close-create-modal');
    const formCreate = document.getElementById('form-create-profile');
    
    if (btnOpenCreate) {
      btnOpenCreate.addEventListener('click', () => {
        createModal.classList.add('active');
        document.getElementById('role_title').focus();
      });
    }
    
    if (btnCloseCreate) {
      btnCloseCreate.addEventListener('click', () => {
        createModal.classList.remove('active');
        formCreate.reset();
      });
    }

    // Handle Create Profile
    if (formCreate) {
      formCreate.addEventListener('submit', async (e) => {
        e.preventDefault();
        const roleTitle = document.getElementById('role_title').value.trim();
        const btnSubmit = document.getElementById('btn-submit-create');
        
        if (!roleTitle) return;
        
        btnSubmit.innerHTML = '<span class="spinner"></span> Creating...';
        btnSubmit.disabled = true;

        try {
          const formData = new URLSearchParams();
          formData.append('action', 'create_profile');
          formData.append('role_title', roleTitle);

          const res = await fetch('ajax.php', {
            method: 'POST',
            body: formData,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
          });
          const data = await res.json();
          
          if (data.success) {
            window.location.reload();
          } else {
            showToast(data.message || 'Error creating profile', 'error');
            btnSubmit.innerHTML = '<span>Create Profile</span>';
            btnSubmit.disabled = false;
          }
        } catch (err) {
          showToast('Network error', 'error');
          btnSubmit.innerHTML = '<span>Create Profile</span>';
          btnSubmit.disabled = false;
        }
      });
    }

    // Handle Delete Profile
    let pendingDeleteProfileId = null;

    document.querySelectorAll('.btn-delete-profile').forEach(btn => {
      btn.addEventListener('click', (e) => {
        pendingDeleteProfileId = btn.getAttribute('data-id');
        document.getElementById('delete-modal').classList.add('active');
      });
    });

    document.getElementById('btn-delete-cancel').addEventListener('click', () => {
      document.getElementById('delete-modal').classList.remove('active');
      pendingDeleteProfileId = null;
    });

    document.getElementById('btn-delete-confirm').addEventListener('click', async () => {
      if (!pendingDeleteProfileId) return;
      
      const btnConfirm = document.getElementById('btn-delete-confirm');
      const originalText = btnConfirm.innerHTML;
      btnConfirm.innerHTML = '<span class="spinner" style="border-width: 2px; width: 14px; height: 14px; margin-right: 6px;"></span> Deleting...';
      btnConfirm.disabled = true;

      try {
        const formData = new URLSearchParams();
        formData.append('action', 'delete_profile');
        formData.append('profile_id', pendingDeleteProfileId);

        const res = await fetch('ajax.php', {
          method: 'POST',
          body: formData,
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        });
        const data = await res.json();
        
        if (data.success) {
          window.location.reload();
        } else {
          showToast(data.message || 'Error deleting profile', 'error');
          btnConfirm.innerHTML = originalText;
          btnConfirm.disabled = false;
          document.getElementById('delete-modal').classList.remove('active');
        }
      } catch (err) {
        showToast('Network error', 'error');
        btnConfirm.innerHTML = originalText;
        btnConfirm.disabled = false;
        document.getElementById('delete-modal').classList.remove('active');
      }
    });

    // Flag to differentiate global and profile uploads in the mismatch modal
    window.isGlobalUpload = false;

    // Handle File Upload
    document.querySelectorAll('.resume-upload-input').forEach(input => {
      input.addEventListener('change', async (e) => {
        if (!e.target.files || e.target.files.length === 0) return;
        
        const file = e.target.files[0];
        const profileId = input.getAttribute('data-id');
        
        const formData = new FormData();
        formData.append('action', 'upload_resume');
        formData.append('profile_id', profileId);
        formData.append('resume_file', file);

        window.isGlobalUpload = false;
        document.getElementById('ai-loading-overlay').classList.add('active');

        try {
          const res = await fetch('ajax.php', {
            method: 'POST',
            body: formData
          });
          const data = await res.json();
          
          if (data.success) {
            window.location.href = '../candidate/resume_optimizer.php?resume_path=' + encodeURIComponent(data.path) + '&profile_id=' + encodeURIComponent(profileId);
          } else {
            document.getElementById('ai-loading-overlay').classList.remove('active');
            if (data.error_type === 'name_mismatch') {
              window.pendingTempFilename = data.temp_filename;
              window.pendingProfileId = profileId;
              const profileName = <?php echo json_encode($user['full_name']); ?>;
              const extractedName = data.extracted_name || 'Unknown Name';
              
              document.getElementById('mismatch-modal-text').innerHTML = `The resume uploaded is not for <strong>${escapeHTML(profileName)}</strong> but instead it is showing the name <strong>${escapeHTML(extractedName)}</strong>.<br><br>Do you really want to upload this to your profile or want to skip it?`;
              document.getElementById('mismatch-modal').classList.add('active');
            } else {
              showToast(data.message || 'Error uploading file', 'error');
            }
          }
        } catch (err) {
          document.getElementById('ai-loading-overlay').classList.remove('active');
          showToast('Network error during upload', 'error');
        }
        
        input.value = ''; // Reset
      });
    });

    // Handle Global File Upload
    const globalUploadInput = document.getElementById('global-resume-file-input');
    if (globalUploadInput) {
      globalUploadInput.addEventListener('change', async (e) => {
        if (!e.target.files || e.target.files.length === 0) return;
        
        const file = e.target.files[0];
        const formData = new FormData();
        formData.append('action', 'upload_global_resume');
        formData.append('resume_file', file);

        window.isGlobalUpload = true;
        document.getElementById('ai-loading-overlay').classList.add('active');

        try {
          const res = await fetch('ajax.php', {
            method: 'POST',
            body: formData
          });
          const data = await res.json();
          
          if (data.success) {
            window.location.reload();
          } else {
            document.getElementById('ai-loading-overlay').classList.remove('active');
            if (data.error_type === 'name_mismatch') {
              window.pendingTempFilename = data.temp_filename;
              const profileName = <?php echo json_encode($user['full_name']); ?>;
              const extractedName = data.extracted_name || 'Unknown Name';
              
              document.getElementById('mismatch-modal-text').innerHTML = `The resume uploaded is not for <strong>${escapeHTML(profileName)}</strong> but instead it is showing the name <strong>${escapeHTML(extractedName)}</strong>.<br><br>Do you really want to upload this to your profile or want to skip it?`;
              document.getElementById('mismatch-modal').classList.add('active');
            } else {
              showToast(data.message || 'Error uploading file', 'error');
            }
          }
        } catch (err) {
          document.getElementById('ai-loading-overlay').classList.remove('active');
          showToast('Network error during upload', 'error');
        }
        
        globalUploadInput.value = ''; // Reset
      });
    }

    // Handle Set Base Resume
    document.querySelectorAll('.btn-set-base').forEach(btn => {
      btn.addEventListener('click', async (e) => {
        const path = btn.getAttribute('data-path');
        const formData = new URLSearchParams();
        formData.append('action', 'set_base_resume');
        formData.append('resume_path', path);

        try {
          const res = await fetch('ajax.php', {
            method: 'POST',
            body: formData,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
          });
          const data = await res.json();
          if (data.success) {
            showToast('Base resume updated successfully!', 'success');
            setTimeout(() => window.location.reload(), 800);
          } else {
            showToast(data.message || 'Error setting base resume', 'error');
          }
        } catch (err) {
          showToast('Network error', 'error');
        }
      });
    });

    // Handle Delete Global Resume
    let pendingDeleteResumePath = null;
    const deleteGlobalModal = document.getElementById('delete-global-resume-modal');
    
    document.querySelectorAll('.btn-delete-global-resume').forEach(btn => {
      btn.addEventListener('click', (e) => {
        pendingDeleteResumePath = btn.getAttribute('data-path');
        deleteGlobalModal.classList.add('active');
      });
    });

    document.getElementById('btn-delete-global-cancel').addEventListener('click', () => {
      deleteGlobalModal.classList.remove('active');
      pendingDeleteResumePath = null;
    });

    document.getElementById('btn-delete-global-confirm').addEventListener('click', async () => {
      if (!pendingDeleteResumePath) return;
      
      const btnConfirm = document.getElementById('btn-delete-global-confirm');
      const originalText = btnConfirm.innerHTML;
      btnConfirm.innerHTML = '<span class="spinner" style="border-width: 2px; width: 14px; height: 14px; margin-right: 6px;"></span> Deleting...';
      btnConfirm.disabled = true;

      const formData = new URLSearchParams();
      formData.append('action', 'delete_global_resume');
      formData.append('resume_path', pendingDeleteResumePath);

      try {
        const res = await fetch('ajax.php', {
          method: 'POST',
          body: formData,
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        });
        const data = await res.json();
        
        if (data.success) {
          deleteGlobalModal.classList.remove('active');
          showToast('Resume deleted successfully.', 'success');
          setTimeout(() => window.location.reload(), 800);
        } else {
          showToast(data.message || 'Error deleting resume', 'error');
          btnConfirm.innerHTML = originalText;
          btnConfirm.disabled = false;
          deleteGlobalModal.classList.remove('active');
          pendingDeleteResumePath = null;
        }
      } catch (err) {
        showToast('Network error', 'error');
        btnConfirm.innerHTML = originalText;
        btnConfirm.disabled = false;
        deleteGlobalModal.classList.remove('active');
        pendingDeleteResumePath = null;
      }
    });

    // Mismatch Modal Logic
    document.getElementById('btn-mismatch-skip').addEventListener('click', async () => {
      document.getElementById('mismatch-modal').classList.remove('active');
      if (window.pendingTempFilename) {
        const formData = new URLSearchParams();
        formData.append('action', window.isGlobalUpload ? 'cancel_global_resume' : 'cancel_resume');
        formData.append('temp_filename', window.pendingTempFilename);
        window.pendingTempFilename = null;
        
        await fetch('ajax.php', {
          method: 'POST',
          body: formData,
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        });
      }
    });

    document.getElementById('btn-mismatch-confirm').addEventListener('click', async () => {
      document.getElementById('mismatch-modal').classList.remove('active');
      document.getElementById('ai-loading-overlay').classList.add('active');

      if (window.pendingTempFilename) {
        const formData = new URLSearchParams();
        formData.append('action', window.isGlobalUpload ? 'commit_global_resume' : 'commit_resume');
        formData.append('temp_filename', window.pendingTempFilename);
        if (!window.isGlobalUpload) {
          formData.append('profile_id', window.pendingProfileId);
        }
        window.pendingTempFilename = null;

        try {
          const res = await fetch('ajax.php', {
            method: 'POST',
            body: formData,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
          });
          const data = await res.json();
          
          if (data.success) {
            if (window.isGlobalUpload) {
              window.location.reload();
            } else {
              window.location.href = '../candidate/resume_optimizer.php?resume_path=' + encodeURIComponent(data.path) + '&profile_id=' + encodeURIComponent(window.pendingProfileId);
            }
          } else {
            document.getElementById('ai-loading-overlay').classList.remove('active');
            showToast(data.message || 'Failed to complete upload', 'error');
          }
        } catch (err) {
          document.getElementById('ai-loading-overlay').classList.remove('active');
          showToast('Error completing upload', 'error');
        }
      }
    });

    function escapeHTML(str) {
      if (!str) return '';
      return str.replace(/[&<>'"]/g, 
        tag => ({
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          "'": '&#39;',
          '"': '&quot;'
        }[tag] || tag)
      );
    }

    // --- OPTION C: Join Modal Logic ---
    const joinModal = document.getElementById('join-modal');
    const btnOpenJoin = document.getElementById('btn-open-join-modal');
    const btnCloseJoin = document.getElementById('btn-close-join-modal');

    if (btnOpenJoin) {
      btnOpenJoin.addEventListener('click', () => {
        joinModal.classList.add('active');
        setTimeout(() => document.getElementById('input-option-c').focus(), 50);
      });
    }

    if (btnCloseJoin) {
      btnCloseJoin.addEventListener('click', () => {
        joinModal.classList.remove('active');
        document.getElementById('form-join-modal').reset();
      });
    }

    // --- SHARED JOIN LOGIC (All Options) ---
    function joinInterview(inputId) {
      const inputEl = document.getElementById(inputId);
      const code = inputEl.value.trim();
      if (!code) {
        showToast('Please enter an interview code', 'error');
        inputEl.focus();
        return;
      }
      
      showToast('Joining interview room...', 'success');
      
      // Navigate to the interview room with the code
      setTimeout(() => {
        window.location.href = '../interview_room.php?code=' + encodeURIComponent(code);
      }, 500);
    }
  </script>
</body>
</html>
