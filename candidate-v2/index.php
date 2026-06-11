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
  
  <script>
    window.CANDIDATE_USER_NAME = <?php echo json_encode($user['full_name'] ?? ''); ?>;
  </script>
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
                  <a href="resume_optimizer.php?resume_path=<?php echo urlencode($baseResume['path']); ?>" class="btn btn-primary" style="flex: 1;">
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


  <?php require_once __DIR__ . '/modals.php'; ?>


  <div class="toast-container" id="toast-container"></div>

  <script src="../assets/js/candidate-v2.js" defer></script>
</body>
</html>
