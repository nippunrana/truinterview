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
    <h3 style="display: flex; align-items: center; gap: var(--space-2); color: var(--color-brand-primary); margin-bottom: var(--space-3); font-size: var(--text-xl); font-family: 'Outfit', sans-serif;">
      <svg style="width: 24px; height: 24px; color: currentColor;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
      </svg>
      Setup Profile Resume
    </h3>
    
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
