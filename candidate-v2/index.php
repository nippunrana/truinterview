<?php
// candidate-v2/index.php - Candidate Dashboard V2
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

// Enforce Candidate role
requireAuth(['candidate']);
$user = getCurrentUser();

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Candidate Hub - TruInterview</title>
  <link rel="stylesheet" href="../assets/css/candidate-v2.css">
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
        <div class="avatar-circle"><?php echo htmlspecialchars($initials); ?></div>
        <a href="../logout.php" class="btn btn-outline" style="padding: 6px 12px; font-size: 0.8rem;">Log Out</a>
      </div>
    </header>

    <main>
      <h1 class="title-main">Your Career Profiles</h1>
      <p class="subtitle-main">Create up to 3 distinct role profiles. Tailor your resume for each role and practice role-specific technical interviews to perfect your pitch.</p>

      <div class="bento-grid">
        
        <?php foreach ($profiles as $idx => $profile): ?>
          <div class="bento-card" data-profile-id="<?php echo $profile['id']; ?>">
            <div class="card-header">
              <div class="role-title"><?php echo htmlspecialchars($profile['role_title']); ?></div>
              <div class="card-badge">Profile <?php echo $idx + 1; ?></div>
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

            <div style="position: absolute; top: var(--space-5); right: var(--space-5); margin-top: -8px;">
              <button class="btn-delete-profile btn-danger-ghost" data-id="<?php echo $profile['id']; ?>" style="border: none; cursor: pointer; padding: 4px; border-radius: 4px;" title="Delete Profile">
                <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
              </button>
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
    </main>
  </div>

  <!-- Loading Overlay for AI -->
  <div class="modal-overlay" id="ai-loading-overlay">
    <div style="display: flex; flex-direction: column; align-items: center; gap: var(--space-4); background: var(--color-bg-surface); padding: var(--space-6) var(--space-8); border-radius: var(--radius-outer); box-shadow: var(--shadow-float);">
      <div class="spinner" style="border-color: rgba(79, 70, 229, 0.2); border-top-color: var(--color-brand-primary); width: 32px; height: 32px;"></div>
      <div style="font-weight: 600; color: var(--color-text-primary);">Running initial AI verification on your resume...</div>
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

    // Modal Logic
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
    document.querySelectorAll('.btn-delete-profile').forEach(btn => {
      btn.addEventListener('click', async (e) => {
        if (!confirm('Are you sure you want to delete this profile?')) return;
        
        const profileId = btn.getAttribute('data-id');
        try {
          const formData = new URLSearchParams();
          formData.append('action', 'delete_profile');
          formData.append('profile_id', profileId);

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
          }
        } catch (err) {
          showToast('Network error', 'error');
        }
      });
    });

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

    // Mismatch Modal Logic
    document.getElementById('btn-mismatch-skip').addEventListener('click', async () => {
      document.getElementById('mismatch-modal').classList.remove('active');
      if (window.pendingTempFilename) {
        const formData = new URLSearchParams();
        formData.append('action', 'cancel_resume');
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
        formData.append('action', 'commit_resume');
        formData.append('temp_filename', window.pendingTempFilename);
        formData.append('profile_id', window.pendingProfileId);
        window.pendingTempFilename = null;

        try {
          const res = await fetch('ajax.php', {
            method: 'POST',
            body: formData,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
          });
          const data = await res.json();
          
          if (data.success) {
            window.location.href = '../candidate/resume_optimizer.php?resume_path=' + encodeURIComponent(data.path) + '&profile_id=' + encodeURIComponent(window.pendingProfileId);
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
  </script>
</body>
</html>
