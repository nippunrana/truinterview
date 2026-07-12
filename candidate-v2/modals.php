<?php
// candidate-v2/modals.php - Dashboard Modals Include
// Assumes $userFull is available from the parent index.php scope
?>

<!-- Loading Overlay for AI -->
<div class="modal-overlay" id="ai-loading-overlay">
  <div style="display: flex; flex-direction: column; align-items: center; gap: var(--space-4); background: var(--color-bg-surface); padding: var(--space-6) var(--space-8); border-radius: var(--radius-outer); box-shadow: var(--shadow-float);">
    <div class="spinner" style="border-color: rgba(79, 70, 229, 0.2); border-top-color: var(--color-brand-primary); width: 32px; height: 32px;"></div>
    <div id="ai-loading-text" style="font-weight: 600; color: var(--color-text-primary);">Running initial AI verification on your resume...</div>
  </div>
</div>

<!-- Loading Overlay for Practice Interview -->
<div class="modal-overlay" id="practice-loading-overlay">
  <div style="display: flex; flex-direction: column; align-items: center; gap: var(--space-4); background: var(--color-bg-surface); padding: var(--space-6) var(--space-8); border-radius: var(--radius-outer); box-shadow: var(--shadow-float);">
    <div class="spinner" id="practice-loading-spinner" style="border-color: rgba(79, 70, 229, 0.2); border-top-color: var(--color-brand-primary); width: 32px; height: 32px;"></div>
    <div id="practice-loading-text" style="font-weight: 600; color: var(--color-text-primary);">Preparing AI Interview Questions... This may take a moment.</div>
    <div id="practice-loading-actions" style="display: none; gap: var(--space-3); margin-top: var(--space-2);">
      <button class="btn btn-outline" id="btn-practice-copy-json">Copy JSON</button>
      <button class="btn btn-primary" id="btn-practice-continue">Continue to Interview</button>
    </div>
  </div>
</div>

<!-- Delete Submission Modal -->
<div class="modal-overlay" id="delete-submission-modal">
  <div class="modal-content">
    <h3 style="display: flex; align-items: center; gap: var(--space-2); color: var(--color-danger); margin-bottom: var(--space-3); font-size: var(--text-xl); font-family: 'Outfit', sans-serif;">
      <svg style="width: 24px; height: 24px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
      </svg>
      Delete Submission
    </h3>
    <div style="color: var(--color-text-secondary); font-size: var(--text-sm); line-height: 1.6; margin-bottom: var(--space-6);">
      Are you sure you want to delete this submission? This action cannot be undone.
    </div>
    <div style="display: flex; justify-content: flex-end; gap: var(--space-3);">
      <button id="btn-delete-submission-cancel" class="btn btn-outline">Cancel</button>
      <button id="btn-delete-submission-confirm" class="btn btn-primary" style="background: var(--color-danger); border-color: var(--color-danger);">Yes, Delete</button>
    </div>
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

<!-- Create Profile Modal -->
<div class="modal-overlay" id="create-modal">
  <div class="modal-content">
    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
      <h2 style="margin-bottom: var(--space-2);">Create Role Profile</h2>
      <button type="button" class="modal-close-btn" id="btn-x-close-create-modal" aria-label="Close">&times;</button>
    </div>
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

<!-- Post-Upload Success Modal -->
<div class="modal-overlay" id="post-upload-modal">
  <div class="modal-content" style="max-width: 440px;">
    <h3 style="display: flex; align-items: center; gap: var(--space-2); color: var(--color-brand-primary); margin-bottom: var(--space-3); font-size: var(--text-xl); font-family: 'Outfit', sans-serif;">
      <svg style="width: 24px; height: 24px; color: var(--color-success);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
      </svg>
      Upload Successful!
    </h3>
    <div style="color: var(--color-text-secondary); font-size: var(--text-sm); line-height: 1.6; margin-bottom: var(--space-6);">
      Your base resume has been successfully uploaded and analyzed. Would you like to optimize this resume now, or finish and return to your dashboard?
    </div>
    <div style="display: flex; justify-content: flex-end; gap: var(--space-3);">
      <button id="btn-post-upload-finish" class="btn btn-outline">Finish</button>
      <button id="btn-post-upload-optimize" class="btn btn-primary">Optimize Resume</button>
    </div>
  </div>
</div>

<!-- View AI Rationale Modal -->
<div class="modal-overlay" id="rationale-modal">
  <div class="modal-content" style="max-width: 800px; width: 90%;">
    <h2 style="margin-bottom: var(--space-2); display: flex; align-items: center; gap: 8px;">
      <svg style="width: 24px; height: 24px; color: var(--color-brand-primary);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
      </svg>
      AI Optimization Rationale
    </h2>
    <p style="color: var(--color-text-secondary); font-size: var(--text-sm); margin-bottom: var(--space-4);">Review exactly what bullet points the AI rewrote to align with ATS filters and recruiter standards.</p>
    
    <div style="overflow-y: auto; max-height: 400px; border: 1px solid var(--color-border); border-radius: var(--radius-inner); background: #fff; margin-bottom: var(--space-6);">
      <table class="changes-table" style="width: 100%; border-collapse: collapse; text-align: left;">
        <thead style="background: var(--color-bg-subtle); position: sticky; top: 0; z-index: 10;">
          <tr>
            <th style="padding: 12px; font-size: 0.78rem; text-transform: uppercase; color: var(--color-text-secondary); font-weight: 700; width: 35%; border-bottom: 1px solid var(--color-border);">Original Text</th>
            <th style="padding: 12px; font-size: 0.78rem; text-transform: uppercase; color: var(--color-text-secondary); font-weight: 700; width: 40%; border-bottom: 1px solid var(--color-border);">Optimized XYZ Version</th>
            <th style="padding: 12px; font-size: 0.78rem; text-transform: uppercase; color: var(--color-text-secondary); font-weight: 700; width: 25%; border-bottom: 1px solid var(--color-border);">Recruiter Rationale</th>
          </tr>
        </thead>
        <tbody id="rationale-table-body">
          <!-- Dynamically populated -->
        </tbody>
      </table>
    </div>
    
    <div style="display: flex; justify-content: flex-end;">
      <button type="button" class="btn btn-primary" id="btn-close-rationale-modal">Close</button>
    </div>
  </div>
</div>

<!-- Choose Resume Source Modal -->
<div class="modal-overlay" id="choose-resume-source-modal">
  <div class="modal-content" style="max-width: 500px;">
    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
      <h3 style="display: flex; align-items: center; gap: var(--space-2); color: var(--color-brand-primary); margin-bottom: var(--space-3); font-size: var(--text-xl); font-family: 'Outfit', sans-serif;">
        <svg style="width: 24px; height: 24px; color: currentColor;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
        </svg>
        <span id="crs-title-text">Setup Profile Resume</span>
      </h3>
      <button type="button" class="modal-close-btn" id="btn-crs-x-close" aria-label="Close">&times;</button>
    </div>

    <div id="crs-step-1">
      <div style="color: var(--color-text-secondary); font-size: var(--text-sm); line-height: 1.6; margin-bottom: var(--space-6);">
        Would you like to use your existing base resume or upload a completely new resume specifically for this role?
      </div>
      <div style="display: flex; flex-direction: column; gap: var(--space-3);">
        <button id="btn-crs-use-base" class="btn btn-primary" style="padding: 14px; justify-content: center; width: 100%;">
          Work with Base Resume
        </button>
        <button id="btn-crs-upload-new" class="btn btn-outline" style="padding: 14px; justify-content: center; width: 100%;">
          Upload New Resume
        </button>
      </div>
    </div>

    <div id="crs-step-2-optimized" style="display: none;">
      <div style="color: var(--color-text-secondary); font-size: var(--text-sm); line-height: 1.6; margin-bottom: var(--space-6);">
        We found an <strong>optimized version</strong> of your base resume. Using the optimized version usually delivers better results. Would you like to use the optimized version or stick with the original base resume?
      </div>
      <div style="display: flex; flex-direction: column; gap: var(--space-3);">
        <button id="btn-crs-use-optimized" class="btn btn-primary" style="padding: 14px; justify-content: center; width: 100%; background: var(--color-success); border-color: var(--color-success);">
          Use Optimized Version (Recommended)
        </button>
        <button id="btn-crs-use-original" class="btn btn-outline" style="padding: 14px; justify-content: center; width: 100%;">
          Use Original Base Resume
        </button>
      </div>
    </div>

    <div style="display: flex; justify-content: flex-end; margin-top: var(--space-6);">
      <button id="btn-crs-cancel" class="btn btn-outline">Cancel</button>
    </div>
  </div>
</div>

<input type="file" id="profile-resume-upload-input" class="hidden-upload" accept=".pdf,.doc,.docx,.md" style="display: none;" />

<!-- Account Settings Modal -->
<div class="modal-overlay" id="account-modal">
  <div class="modal-content" style="max-width: 480px;">
    <h2 style="margin-bottom: var(--space-4); display: flex; align-items: center; gap: 8px;">
      <svg style="width: 24px; height: 24px; color: var(--color-brand-primary);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"></path></svg>
      Account Settings
    </h2>

    <form id="form-account" style="display: flex; flex-direction: column; gap: var(--space-4);">
      <div class="form-group">
        <label class="form-label" for="input-account-full-name">Full Name</label>
        <input type="text" id="input-account-full-name" name="full_name" class="form-input" value="<?php echo htmlspecialchars($userFull['full_name'] ?? ''); ?>" required autocomplete="name">
      </div>

      <div style="border-top: 1px solid var(--color-border); padding-top: var(--space-4);">
        <?php if (!empty($userFull['has_password'])): ?>
        <div style="font-weight: 700; font-size: var(--text-sm); color: var(--color-text-primary); margin-bottom: var(--space-1);">Change Password</div>
        <div style="font-size: 0.72rem; color: var(--color-text-muted); margin-bottom: var(--space-3);">Leave blank if you don't want to change your password.</div>
        <?php else: ?>
        <div style="font-weight: 700; font-size: var(--text-sm); color: var(--color-text-primary); margin-bottom: var(--space-1);">Set Password</div>
        <div style="font-size: 0.72rem; color: var(--color-text-muted); margin-bottom: var(--space-3);">You signed in with Google. Set a password to also enable email + password login.</div>
        <?php endif; ?>

        <?php if (!empty($userFull['has_password'])): ?>
        <div class="form-group" style="position: relative;">
          <label class="form-label">Current Password</label>
          <div style="position: relative; display: flex; align-items: center;">
            <input type="password" id="input-current-password" name="current_password" class="form-input" placeholder="Enter current password" autocomplete="current-password" style="width: 100%; padding-right: 40px;">
            <button type="button" onclick="togglePasswordVisibility('input-current-password', this)" style="position: absolute; right: 12px; background: transparent; border: none; cursor: pointer; color: var(--color-text-muted); display: flex; align-items: center; padding: 0;">
              <svg class="eye-icon" style="width: 20px; height: 20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path class="eye-open" stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                <path class="eye-open" stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                <path class="eye-closed" style="display: none;" stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
              </svg>
            </button>
          </div>
        </div>
        <?php endif; ?>

        <div class="form-group" style="position: relative;">
          <label class="form-label">New Password</label>
          <div style="position: relative; display: flex; align-items: center;">
            <input type="password" id="input-new-password" name="new_password" class="form-input" placeholder="Min. 6 characters" autocomplete="new-password" style="width: 100%; padding-right: 40px;">
            <button type="button" onclick="togglePasswordVisibility('input-new-password', this)" style="position: absolute; right: 12px; background: transparent; border: none; cursor: pointer; color: var(--color-text-muted); display: flex; align-items: center; padding: 0;">
              <svg class="eye-icon" style="width: 20px; height: 20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path class="eye-open" stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                <path class="eye-open" stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                <path class="eye-closed" style="display: none;" stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
              </svg>
            </button>
          </div>
        </div>

        <div class="form-group" style="position: relative; margin-bottom: 0;">
          <label class="form-label">Confirm New Password</label>
          <div style="position: relative; display: flex; align-items: center;">
            <input type="password" id="input-confirm-password" name="confirm_password" class="form-input" placeholder="Re-enter new password" autocomplete="new-password" style="width: 100%; padding-right: 40px;">
            <button type="button" onclick="togglePasswordVisibility('input-confirm-password', this)" style="position: absolute; right: 12px; background: transparent; border: none; cursor: pointer; color: var(--color-text-muted); display: flex; align-items: center; padding: 0;">
              <svg class="eye-icon" style="width: 20px; height: 20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path class="eye-open" stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                <path class="eye-open" stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                <path class="eye-closed" style="display: none;" stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
              </svg>
            </button>
          </div>
        </div>
      </div>

      <div style="display: flex; justify-content: flex-end; gap: var(--space-3); margin-top: var(--space-2);">
        <button type="button" class="btn btn-outline" id="btn-close-account-modal">Cancel</button>
        <button type="submit" class="btn btn-primary" id="btn-submit-account">Save Changes</button>
      </div>
    </form>
  </div>
</div>
