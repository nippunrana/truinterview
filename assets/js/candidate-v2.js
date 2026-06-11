// assets/js/candidate-v2.js - Candidate Hub Dashboard client-side behaviors

document.addEventListener('DOMContentLoaded', () => {
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

  let loadingInterval = null;
  function startLoadingText() {
    const textEl = document.getElementById('ai-loading-text');
    if (!textEl) return;

    clearInterval(loadingInterval);
    
    const steps = [
      { time: 0, text: "Reading document structure & verifying candidate details..." },
      { time: 1800, text: "Extracting full resume content into Markdown..." },
      { time: 3600, text: "Running AI Quality Assurance check on factual blocks..." },
      { time: 5400, text: "Resolving final document audits..." }
    ];

    textEl.textContent = steps[0].text;
    
    let startTime = Date.now();
    loadingInterval = setInterval(() => {
      const elapsed = Date.now() - startTime;
      let activeText = steps[0].text;
      for (const step of steps) {
        if (elapsed >= step.time) {
          activeText = step.text;
        }
      }
      textEl.textContent = activeText;
    }, 500);
  }

  function stopLoadingText(successMessage, delay = 1500) {
    clearInterval(loadingInterval);
    const textEl = document.getElementById('ai-loading-text');
    if (textEl && successMessage) {
      textEl.textContent = successMessage;
    }
    return new Promise(resolve => setTimeout(resolve, delay));
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

  const btnDeleteCancel = document.getElementById('btn-delete-cancel');
  if (btnDeleteCancel) {
    btnDeleteCancel.addEventListener('click', () => {
      document.getElementById('delete-modal').classList.remove('active');
      pendingDeleteProfileId = null;
    });
  }

  const btnDeleteConfirm = document.getElementById('btn-delete-confirm');
  if (btnDeleteConfirm) {
    btnDeleteConfirm.addEventListener('click', async () => {
      if (!pendingDeleteProfileId) return;
      
      const originalText = btnDeleteConfirm.innerHTML;
      btnDeleteConfirm.innerHTML = '<span class="spinner" style="border-width: 2px; width: 14px; height: 14px; margin-right: 6px;"></span> Deleting...';
      btnDeleteConfirm.disabled = true;

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
          btnDeleteConfirm.innerHTML = originalText;
          btnDeleteConfirm.disabled = false;
          document.getElementById('delete-modal').classList.remove('active');
        }
      } catch (err) {
        showToast('Network error', 'error');
        btnDeleteConfirm.innerHTML = originalText;
        btnDeleteConfirm.disabled = false;
        document.getElementById('delete-modal').classList.remove('active');
      }
    });
  }

  // Flag to differentiate global and profile uploads in the mismatch modal
  window.isGlobalUpload = false;

  // Post-Upload Success Modal Logic
  const postUploadModal = document.getElementById('post-upload-modal');
  const btnPostUploadFinish = document.getElementById('btn-post-upload-finish');
  const btnPostUploadOptimize = document.getElementById('btn-post-upload-optimize');
  let uploadedResumePath = null;

  function showPostUploadModal(resumePath) {
    uploadedResumePath = resumePath;
    if (postUploadModal) {
      postUploadModal.classList.add('active');
    }
  }

  if (btnPostUploadFinish) {
    btnPostUploadFinish.addEventListener('click', () => {
      postUploadModal.classList.remove('active');
      window.location.reload();
    });
  }

  if (btnPostUploadOptimize) {
    btnPostUploadOptimize.addEventListener('click', () => {
      if (uploadedResumePath) {
        window.location.href = 'resume_optimizer.php?resume_path=' + encodeURIComponent(uploadedResumePath);
      }
    });
  }

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
      startLoadingText();

      try {
        const res = await fetch('ajax.php', {
          method: 'POST',
          body: formData
        });
        const data = await res.json();
        
        if (data.success) {
          const msg = data.needs_human_review ? "Done! Fixed minor issues. Please verify the text version." : "All done successfully!";
          await stopLoadingText(msg, 2000);
          window.location.href = 'resume_optimizer.php?resume_path=' + encodeURIComponent(data.path) + '&profile_id=' + encodeURIComponent(profileId);
        } else {
          await stopLoadingText(null, 0);
          document.getElementById('ai-loading-overlay').classList.remove('active');
          if (data.error_type === 'name_mismatch') {
            window.pendingTempFilename = data.temp_filename;
            window.pendingProfileId = profileId;
            const profileName = window.CANDIDATE_USER_NAME || 'Candidate';
            const extractedName = data.extracted_name || 'Unknown Name';
            
            document.getElementById('mismatch-modal-text').innerHTML = `The resume uploaded is not for <strong>${escapeHTML(profileName)}</strong> but instead it is showing the name <strong>${escapeHTML(extractedName)}</strong>.<br><br>Do you really want to upload this to your profile or want to skip it?`;
            document.getElementById('mismatch-modal').classList.add('active');
          } else {
            showToast(data.message || 'Error uploading file', 'error');
          }
        }
      } catch (err) {
        await stopLoadingText(null, 0);
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
      startLoadingText();

      try {
        const res = await fetch('ajax.php', {
          method: 'POST',
          body: formData
        });
        const data = await res.json();
        
        if (data.success) {
          const msg = data.needs_human_review ? "Done! Fixed minor issues. Please verify the text version." : "All done successfully!";
          await stopLoadingText(msg, 2000);
          document.getElementById('ai-loading-overlay').classList.remove('active');
          showPostUploadModal(data.path);
        } else {
          await stopLoadingText(null, 0);
          document.getElementById('ai-loading-overlay').classList.remove('active');
          if (data.error_type === 'name_mismatch') {
            window.pendingTempFilename = data.temp_filename;
            const profileName = window.CANDIDATE_USER_NAME || 'Candidate';
            const extractedName = data.extracted_name || 'Unknown Name';
            
            document.getElementById('mismatch-modal-text').innerHTML = `The resume uploaded is not for <strong>${escapeHTML(profileName)}</strong> but instead it is showing the name <strong>${escapeHTML(extractedName)}</strong>.<br><br>Do you really want to upload this to your profile or want to skip it?`;
            document.getElementById('mismatch-modal').classList.add('active');
          } else {
            showToast(data.message || 'Error uploading file', 'error');
          }
        }
      } catch (err) {
        await stopLoadingText(null, 0);
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

  const btnDeleteGlobalCancel = document.getElementById('btn-delete-global-cancel');
  if (btnDeleteGlobalCancel) {
    btnDeleteGlobalCancel.addEventListener('click', () => {
      deleteGlobalModal.classList.remove('active');
      pendingDeleteResumePath = null;
    });
  }

  const btnDeleteGlobalConfirm = document.getElementById('btn-delete-global-confirm');
  if (btnDeleteGlobalConfirm) {
    btnDeleteGlobalConfirm.addEventListener('click', async () => {
      if (!pendingDeleteResumePath) return;
      
      const originalText = btnDeleteGlobalConfirm.innerHTML;
      btnDeleteGlobalConfirm.innerHTML = '<span class="spinner" style="border-width: 2px; width: 14px; height: 14px; margin-right: 6px;"></span> Deleting...';
      btnDeleteGlobalConfirm.disabled = true;

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
          btnDeleteGlobalConfirm.innerHTML = originalText;
          btnDeleteGlobalConfirm.disabled = false;
          deleteGlobalModal.classList.remove('active');
          pendingDeleteResumePath = null;
        }
      } catch (err) {
        showToast('Network error', 'error');
        btnDeleteGlobalConfirm.innerHTML = originalText;
        btnDeleteGlobalConfirm.disabled = false;
        deleteGlobalModal.classList.remove('active');
        pendingDeleteResumePath = null;
      }
    });
  }

  // Mismatch Modal Logic
  const btnMismatchSkip = document.getElementById('btn-mismatch-skip');
  if (btnMismatchSkip) {
    btnMismatchSkip.addEventListener('click', async () => {
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
  }

  const btnMismatchConfirm = document.getElementById('btn-mismatch-confirm');
  if (btnMismatchConfirm) {
    btnMismatchConfirm.addEventListener('click', async () => {
      document.getElementById('mismatch-modal').classList.remove('active');
      document.getElementById('ai-loading-overlay').classList.add('active');
      startLoadingText();

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
            const msg = data.needs_human_review ? "Done! Fixed minor issues. Please verify the text version." : "All done successfully!";
            await stopLoadingText(msg, 2000);
            if (window.isGlobalUpload) {
              document.getElementById('ai-loading-overlay').classList.remove('active');
              showPostUploadModal(data.path);
            } else {
              window.location.href = 'resume_optimizer.php?resume_path=' + encodeURIComponent(data.path) + '&profile_id=' + encodeURIComponent(window.pendingProfileId);
            }
          } else {
            await stopLoadingText(null, 0);
            document.getElementById('ai-loading-overlay').classList.remove('active');
            showToast(data.message || 'Failed to complete upload', 'error');
          }
        } catch (err) {
          await stopLoadingText(null, 0);
          document.getElementById('ai-loading-overlay').classList.remove('active');
          showToast('Error completing upload', 'error');
        }
      }
    });
  }

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
  const formJoinModal = document.getElementById('form-join-modal');

  if (btnOpenJoin) {
    btnOpenJoin.addEventListener('click', () => {
      joinModal.classList.add('active');
      setTimeout(() => document.getElementById('input-option-c').focus(), 50);
    });
  }

  if (btnCloseJoin) {
    btnCloseJoin.addEventListener('click', () => {
      joinModal.classList.remove('active');
      formJoinModal.reset();
    });
  }

  // Bind forms and input fields to the shared join logic
  const bottomBarForm = document.querySelector('.join-hero-form');
  if (bottomBarForm) {
    bottomBarForm.addEventListener('submit', (e) => {
      e.preventDefault();
      joinInterview('input-join-bar');
    });
  }
  
  if (formJoinModal) {
    formJoinModal.addEventListener('submit', (e) => {
      e.preventDefault();
      joinInterview('input-option-c');
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

  // --- View Rationale Modal Logic ---
  const rationaleModal = document.getElementById('rationale-modal');
  const btnCloseRationale = document.getElementById('btn-close-rationale-modal');
  const rationaleTableBody = document.getElementById('rationale-table-body');

  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('.view-rationale-trigger');
    if (trigger) {
      e.preventDefault();
      try {
        const changes = JSON.parse(trigger.getAttribute('data-changes'));
        if (!changes || changes.length === 0) {
          rationaleTableBody.innerHTML = `<tr><td colspan="3" style="text-align: center; color: var(--color-text-muted); padding: 12px;">No modifications recorded.</td></tr>`;
        } else {
          rationaleTableBody.innerHTML = '';
          changes.forEach(c => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
              <td style="padding: 12px; border-bottom: 1px solid var(--color-border); font-size: 0.85rem; color: var(--color-text-secondary);">
                <span class="change-badge change-badge-before" style="font-size: 9px; padding: 2px 6px; border-radius: 4px; background: rgba(244,63,94,0.08); color: var(--color-rose); font-weight: 700; display: inline-block; margin-bottom: 6px;">Original</span>
                <div>${escapeHTML(c.original_point)}</div>
              </td>
              <td style="padding: 12px; border-bottom: 1px solid var(--color-border); font-size: 0.85rem; color: var(--color-text-primary); font-weight: 500;">
                <span class="change-badge change-badge-after" style="font-size: 9px; padding: 2px 6px; border-radius: 4px; background: rgba(16,185,129,0.08); color: var(--color-emerald); font-weight: 700; display: inline-block; margin-bottom: 6px;">Optimized</span>
                <div>${escapeHTML(c.optimized_point)}</div>
              </td>
              <td style="padding: 12px; border-bottom: 1px solid var(--color-border); font-size: 0.82rem; color: var(--color-text-muted); font-style: italic;">
                ${escapeHTML(c.reasoning)}
              </td>
            `;
            rationaleTableBody.appendChild(tr);
          });
        }
        rationaleModal.classList.add('active');
      } catch (err) {
        showToast('Error displaying rationale details.', 'error');
      }
    }
  });

  if (btnCloseRationale) {
    btnCloseRationale.addEventListener('click', () => {
      rationaleModal.classList.remove('active');
    });
  }
});
