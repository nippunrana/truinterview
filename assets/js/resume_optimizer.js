// State machine matching the implementation plan
const state = {
  resumePath: INITIAL_STATE.resumePath,
  profileId: INITIAL_STATE.profileId,
  resumeText: '',
  currentStep: 1,

  // Step 1: Reality Check outputs
  targetRole: '',
  seniority: '',
  blindSummary: '',
  jobDescription: '',

  // Step 2: Gap outputs
  gaps: [],
  gapAnswers: [],

  // Step 3: Date calculations
  verifiedDates: null,

  // Step 4: Final optimization output
  finalResult: null
};

// UI elements
const loadingContainer = document.getElementById('loading-container');
const loadingStatusText = document.getElementById('loading-status');
const actionPrev = document.getElementById('btn-prev');
const actionNext = document.getElementById('btn-next');
const stepperProgress = document.getElementById('stepper-progress');

// Steps view mappings
const stepContents = {
  1: document.getElementById('step-content-1'),
  2: document.getElementById('step-content-2'),
  3: document.getElementById('step-content-3'),
  4: document.getElementById('step-content-4')
};

function updateStepperUI() {
  // Connectors/Stepper Line
  const pct = ((state.currentStep - 1) / 3) * 100;
  stepperProgress.style.width = pct + '%';

  // Step Indicators
  for (let s = 1; s <= 4; s++) {
    const item = document.getElementById('step-nav-' + s);
    if (s < state.currentStep) {
      item.classList.add('completed');
      item.classList.remove('active');
    } else if (s === state.currentStep) {
      item.classList.add('active');
      item.classList.remove('completed');
    } else {
      item.classList.remove('active');
      item.classList.remove('completed');
    }
  }

  // Hide all content containers, show current
  for (let s = 1; s <= 4; s++) {
    if (s === state.currentStep) {
      stepContents[s].classList.add('active');
    } else {
      stepContents[s].classList.remove('active');
    }
  }

  // Navigation button configurations
  actionPrev.style.display = (state.currentStep > 1 && state.currentStep < 4) ? 'block' : 'none';

  if (state.currentStep === 1) {
    actionNext.innerHTML = 'Continue &rarr;';
    // Check if role or custom JD entered
    const jdSection = document.getElementById('jd-customization-section');
    const isJdOk = !jdSection || !jdSection.classList.contains('active') || 
                   (document.getElementById('target-role-input') && document.getElementById('job-desc-input') &&
                    document.getElementById('target-role-input').value.trim() !== '' && 
                    document.getElementById('job-desc-input').value.trim() !== '');
    actionNext.disabled = !isJdOk;
  } else if (state.currentStep === 2) {
    actionNext.innerHTML = 'Verify Experience &rarr;';
    actionNext.disabled = false;
  } else if (state.currentStep === 3) {
    actionNext.innerHTML = 'Optimize Resume &rarr;';
    actionNext.disabled = false;
  } else if (state.currentStep === 4) {
    actionNext.style.display = 'none'; // Replaced by save buttons/exit links
  }
}

function showLoading(statusMsg) {
  // Hide active step content container
  if (stepContents[state.currentStep]) {
    stepContents[state.currentStep].classList.remove('active');
  }
  loadingStatusText.textContent = statusMsg;
  loadingContainer.style.display = 'flex';
  actionNext.disabled = true;
  actionPrev.disabled = true;
}

function hideLoading() {
  loadingContainer.style.display = 'none';
  if (stepContents[state.currentStep]) {
    stepContents[state.currentStep].classList.add('active');
  }
  actionNext.disabled = false;
  actionPrev.disabled = false;
}

// Step 1: Reality check
async function loadRealityCheck() {
  showLoading('Gemini is running reality check (blind analysis)...');
  try {
    const response = await fetch('api/resume_optimizer_ajax.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'ajax_action=optimizer_reality_check&resume_text=' + encodeURIComponent(state.resumeText)
    });
    const result = await response.json();
    
    hideLoading();
    if (result.success && result.data) {
      state.targetRole = result.data.target_role;
      state.seniority = result.data.seniority;
      state.blindSummary = result.data.summary;

      document.getElementById('guessed-role').textContent = state.targetRole;
      document.getElementById('guessed-seniority').textContent = state.seniority;
      document.getElementById('guessed-summary').textContent = state.blindSummary;

      // Default set inputs in custom section
      const targetRoleInput = document.getElementById('target-role-input');
      if (targetRoleInput) {
        targetRoleInput.value = state.targetRole;
      }
    } else {
      alert('Reality check failed: ' + (result.message || 'Unknown error'));
    }
  } catch (err) {
    hideLoading();
    alert('API error: ' + err.message);
  }
}

// Step 2: Gap Analysis loading
async function runGapAnalysis() {
  showLoading('Analyzing Job Description requirements and mapping gaps...');
  
  const jdSection = document.getElementById('jd-customization-section');
  const customJdActive = jdSection && jdSection.classList.contains('active');
  if (customJdActive) {
    state.targetRole = document.getElementById('target-role-input').value.trim();
    state.jobDescription = document.getElementById('job-desc-input').value.trim();
  } else {
    // If they selected yes, targetRole is set, and jobDescription is empty or default
    state.jobDescription = "General role requirements matching: " + state.targetRole;
  }

  try {
    const response = await fetch('api/resume_optimizer_ajax.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'ajax_action=optimizer_gap_analysis&resume_text=' + encodeURIComponent(state.resumeText) +
            '&target_role=' + encodeURIComponent(state.targetRole) +
            '&job_description=' + encodeURIComponent(state.jobDescription)
    });
    const result = await response.json();

    hideLoading();
    if (result.success && result.data) {
      state.gaps = result.data.gaps || [];
      renderGapsList();
      state.currentStep = 2;
      updateStepperUI();
    } else {
      alert('Gap analysis failed: ' + (result.message || 'Unknown error'));
    }
  } catch (err) {
    hideLoading();
    alert('API error: ' + err.message);
  }
}

function renderGapsList() {
  const wrapper = document.getElementById('gaps-list');
  wrapper.innerHTML = '';

  if (state.gaps.length === 0) {
    wrapper.innerHTML = `<div style="background: rgba(16, 185, 129, 0.04); border: 1px solid rgba(16, 185, 129, 0.15); color: var(--color-emerald); padding: 24px; border-radius: var(--radius-inner); text-align: center; font-weight: 600;">
      🎉 Fantastic match! Gemini found no significant skill gaps between your resume and the target role requirements. Click verify timeline to continue.
    </div>`;
    return;
  }

  state.gaps.forEach((gap, index) => {
    const card = document.createElement('div');
    card.className = 'gap-checkbox-card';

    const importanceBadge = gap.importance === 'high' ? 
      `<span style="font-size: 0.68rem; padding: 2px 6px; border-radius: 4px; background: rgba(244, 63, 94, 0.08); color: var(--color-rose); font-weight: 700; margin-left: auto;">High Priority</span>` :
      `<span style="font-size: 0.68rem; padding: 2px 6px; border-radius: 4px; background: rgba(245, 158, 11, 0.08); color: var(--color-amber); font-weight: 700; margin-left: auto;">Recommended</span>`;

    card.innerHTML = `
      <div class="gap-header">
        <input type="checkbox" id="gap-chk-${index}" class="gap-checkbox" onchange="toggleGapTextarea(${index})">
        <div style="flex-grow: 1;">
          <label for="gap-chk-${index}" class="gap-label">${escapeHTML(gap.skill)}</label>
          <div class="gap-desc">${escapeHTML(gap.question)}</div>
        </div>
        ${importanceBadge}
      </div>
      <textarea id="gap-txt-${index}" class="gap-textarea" placeholder="E.g., yes, I did this in my last role using..."></textarea>
    `;

    wrapper.appendChild(card);
  });
}

window.toggleGapTextarea = function(index) {
  const chk = document.getElementById(`gap-chk-${index}`);
  const txt = document.getElementById(`gap-txt-${index}`);
  if (chk.checked) {
    txt.style.display = 'block';
    txt.focus();
  } else {
    txt.style.display = 'none';
    txt.value = '';
  }
};

// Step 3: Date calculations
async function loadDateTimeline() {
  showLoading('Running mathematical timeline calculations...');

  // Save Gap Answers before moving on
  state.gapAnswers = [];
  state.gaps.forEach((gap, index) => {
    const chk = document.getElementById(`gap-chk-${index}`);
    const txt = document.getElementById(`gap-txt-${index}`);
    if (chk && chk.checked && txt.value.trim() !== '') {
      state.gapAnswers.push({
        skill: gap.skill,
        answer: txt.value.trim()
      });
    }
  });

  try {
    const response = await fetch('api/resume_optimizer_ajax.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'ajax_action=optimizer_verify_dates&resume_text=' + encodeURIComponent(state.resumeText)
    });
    const result = await response.json();

    hideLoading();
    if (result.success && result.data) {
      state.verifiedDates = result.data;
      renderTimeline();
      state.currentStep = 3;
      updateStepperUI();
    } else {
      alert('Experience extraction failed: ' + (result.message || 'Unknown error'));
    }
  } catch (err) {
    hideLoading();
    alert('API error: ' + err.message);
  }
}

function renderTimeline() {
  // Total Duration
  document.getElementById('verified-total-duration').textContent = state.verifiedDates.total_experience_formatted || '0 months';

  // Warnings
  const warnBox = document.getElementById('timeline-warnings');
  const warnList = document.getElementById('timeline-warnings-list');
  warnList.innerHTML = '';

  let hasAlerts = false;
  
  // Combine formatting warnings and overlap alerts
  if (state.verifiedDates.warnings && state.verifiedDates.warnings.length > 0) {
    state.verifiedDates.warnings.forEach(w => {
      const li = document.createElement('li');
      li.textContent = w;
      warnList.appendChild(li);
    });
    hasAlerts = true;
  }

  if (state.verifiedDates.overlaps && state.verifiedDates.overlaps.length > 0) {
    state.verifiedDates.overlaps.forEach(ov => {
      const li = document.createElement('li');
      li.innerHTML = `Overlap of <strong>${ov.months} month(s)</strong> detected between <em>${escapeHTML(ov.job1)}</em> and <em>${escapeHTML(ov.job2)}</em>.`;
      warnList.appendChild(li);
    });
    hasAlerts = true;
  }

  if (hasAlerts) {
    warnBox.style.display = 'flex';
  } else {
    warnBox.style.display = 'none';
  }

  // Timeline List
  const timelineList = document.getElementById('timeline-list');
  timelineList.innerHTML = '';

  const details = state.verifiedDates.experience_details || [];
  if (details.length === 0) {
    timelineList.innerHTML = '<div style="color: var(--color-text-muted);">No experiences extracted.</div>';
    return;
  }

  details.forEach(item => {
    const timeItem = document.createElement('div');
    timeItem.className = 'timeline-item';
    timeItem.innerHTML = `
      <div class="timeline-dot"></div>
      <div class="timeline-content">
        <div>
          <div class="timeline-title">${escapeHTML(item.role)}</div>
          <div class="timeline-subtitle">${escapeHTML(item.company)} (${escapeHTML(item.start_date)} to ${escapeHTML(item.end_date)})</div>
        </div>
        <div class="timeline-duration">${escapeHTML(item.duration_formatted)}</div>
      </div>
    `;
    timelineList.appendChild(timeItem);
  });
}

// Step 4: Final optimization loading
async function runFinalOptimization() {
  showLoading('Generating optimized resume and drafting changes scorecard...');
  try {
    const response = await fetch('api/resume_optimizer_ajax.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'ajax_action=optimizer_deep_analysis&resume_text=' + encodeURIComponent(state.resumeText) +
            '&target_role=' + encodeURIComponent(state.targetRole) +
            '&job_description=' + encodeURIComponent(state.jobDescription) +
            '&gap_answers=' + encodeURIComponent(JSON.stringify(state.gapAnswers)) +
            '&verified_dates=' + encodeURIComponent(JSON.stringify(state.verifiedDates))
    });
    const result = await response.json();

    hideLoading();
    if (result.success && result.data) {
      state.finalResult = result.data;
      renderFinalOutputs();
      state.currentStep = 4;
      updateStepperUI();
    } else {
      alert('Optimization failed: ' + (result.message || 'Unknown error'));
    }
  } catch (err) {
    hideLoading();
    alert('API error: ' + err.message);
  }
}

function renderFinalOutputs() {
  const data = state.finalResult;

  // Score circle
  document.getElementById('final-score-badge').textContent = data.rating || '0';
  
  const ratingDescs = {
    10: "Perfect Score! Maximum ATS and Recruiter alignment.",
    9: "Exceptional Match! Optimized for all skimming indices.",
    8: "Strong Profile! Well keywords-aligned and readable.",
    7: "Good Match. General headers and structure complete.",
    6: "Average. Minor alignment tweaks remaining.",
  };
  document.getElementById('final-rating-desc').textContent = ratingDescs[data.rating] || "Resume successfully enhanced with Google XYZ formula.";

  // Resume text markdown
  document.getElementById('rewritten-resume-text').textContent = data.rewritten_resume_markdown;

  // Keyword and gaps alignment text
  document.getElementById('keyword-summary-text').innerHTML = markdownToHTML(data.alignment_summary);

  // Changes list
  const tbody = document.getElementById('changes-table-body');
  tbody.innerHTML = '';

  if (!data.changes || data.changes.length === 0) {
    tbody.innerHTML = `<tr><td colspan="3" style="text-align: center; color: var(--color-text-muted);">No modifications needed. Original items were already compliant.</td></tr>`;
  } else {
    data.changes.forEach(c => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>
          <span class="change-badge change-badge-before">Original</span>
          <div style="font-size: 0.85rem; color: var(--color-text-secondary);">${escapeHTML(c.original_point)}</div>
        </td>
        <td>
          <span class="change-badge change-badge-after">Optimized</span>
          <div style="font-size: 0.85rem; color: var(--color-text-primary); font-weight: 500;">${escapeHTML(c.optimized_point)}</div>
        </td>
        <td style="font-size: 0.82rem; color: var(--color-text-muted); font-style: italic;">
          ${escapeHTML(c.reasoning)}
        </td>
      `;
      tbody.appendChild(tr);
    });
  }
}

// Tab switcher in step 4
window.switchOptTab = function(tabName) {
  document.querySelectorAll('.tab-trigger').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.tab-pane').forEach(el => el.classList.remove('active'));

  event.target.classList.add('active');
  
  if (tabName === 'opt-resume') {
    document.getElementById('pane-opt-resume').classList.add('active');
  } else if (tabName === 'opt-keywords') {
    document.getElementById('pane-opt-keywords').classList.add('active');
  } else if (tabName === 'opt-changes') {
    document.getElementById('pane-opt-changes').classList.add('active');
  }
};

// Copy to clipboard
document.getElementById('btn-copy-clipboard').addEventListener('click', () => {
  const text = document.getElementById('rewritten-resume-text').textContent;
  navigator.clipboard.writeText(text).then(() => {
    const btn = document.getElementById('btn-copy-clipboard');
    btn.textContent = 'Copied!';
    btn.style.borderColor = 'var(--color-emerald)';
    btn.style.color = 'var(--color-emerald)';
    setTimeout(() => {
      btn.textContent = 'Copy to Clipboard';
      btn.style.borderColor = '';
      btn.style.color = '';
    }, 2000);
  });
});

// Save optimized resume to profile
document.getElementById('btn-save-profile').addEventListener('click', async () => {
  const btn = document.getElementById('btn-save-profile');
  btn.disabled = true;
  btn.textContent = 'Saving...';

  try {
    let bodyStr = 'ajax_action=optimizer_save_profile&optimized_markdown=' + encodeURIComponent(state.finalResult.rewritten_resume_markdown) +
                  '&original_path=' + encodeURIComponent(state.resumePath) +
                  '&target_role=' + encodeURIComponent(state.targetRole) +
                  '&job_description=' + encodeURIComponent(state.jobDescription) +
                  '&ai_refined_role=' + encodeURIComponent(state.finalResult.ai_refined_role || '');
    if (state.profileId) {
        bodyStr += '&profile_id=' + encodeURIComponent(state.profileId);
    }
    if (state.finalResult && state.finalResult.changes) {
        bodyStr += '&changes=' + encodeURIComponent(JSON.stringify(state.finalResult.changes));
    }
    
    const response = await fetch('api/resume_optimizer_ajax.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: bodyStr
    });
    const result = await response.json();
    
    if (result.success) {
      btn.textContent = 'Saved!';
      btn.style.background = 'var(--color-emerald)';
      setTimeout(() => {
        window.location.href = 'index.php?success=' + encodeURIComponent('Optimized resume added to your profile successfully.');
      }, 1500);
    } else {
      btn.disabled = false;
      btn.textContent = 'Save to Profile';
      alert('Save failed: ' + (result.message || 'Unknown error'));
    }
  } catch (err) {
    btn.disabled = false;
    btn.textContent = 'Save to Profile';
    alert('API error: ' + err.message);
  }
});

// Step 1: Target Choice handlers
const btnRcYes = document.getElementById('btn-rc-yes');
const btnRcNo = document.getElementById('btn-rc-no');
const jdSection = document.getElementById('jd-customization-section');

if (btnRcYes) {
  btnRcYes.addEventListener('click', () => {
    btnRcYes.style.background = 'rgba(16, 185, 129, 0.08)';
    btnRcYes.style.borderColor = 'var(--color-emerald)';
    btnRcNo.style.background = '';
    btnRcNo.style.borderColor = '';
    if (jdSection) {
      jdSection.style.display = 'none';
      jdSection.classList.remove('active');
    }
    actionNext.disabled = false;
  });
}

if (btnRcNo) {
  btnRcNo.addEventListener('click', () => {
    btnRcNo.style.background = 'rgba(79, 70, 229, 0.05)';
    btnRcNo.style.borderColor = 'var(--color-indigo)';
    btnRcYes.style.background = '';
    btnRcYes.style.borderColor = '';
    if (jdSection) {
      jdSection.style.display = 'flex';
      jdSection.classList.add('active');
    }
    
    const targetRoleInput = document.getElementById('target-role-input');
    const jobDescInput = document.getElementById('job-desc-input');
    const title = targetRoleInput ? targetRoleInput.value.trim() : '';
    const jd = jobDescInput ? jobDescInput.value.trim() : '';
    actionNext.disabled = (title === '' || jd === '');
  });
}

const targetRoleInput = document.getElementById('target-role-input');
if (targetRoleInput) {
  targetRoleInput.addEventListener('input', checkJdFormValidity);
}
const jobDescInput = document.getElementById('job-desc-input');
if (jobDescInput) {
  jobDescInput.addEventListener('input', checkJdFormValidity);
}

function checkJdFormValidity() {
  if (!jdSection || !jdSection.classList.contains('active')) return;
  const targetRoleInput = document.getElementById('target-role-input');
  const jobDescInput = document.getElementById('job-desc-input');
  const title = targetRoleInput ? targetRoleInput.value.trim() : '';
  const jd = jobDescInput ? jobDescInput.value.trim() : '';
  actionNext.disabled = (title === '' || jd === '');
}

// Step transitions
actionNext.addEventListener('click', () => {
  if (state.currentStep === 1) {
    runGapAnalysis();
  } else if (state.currentStep === 2) {
    loadDateTimeline();
  } else if (state.currentStep === 3) {
    runFinalOptimization();
  }
});

actionPrev.addEventListener('click', () => {
  if (state.currentStep > 1) {
    state.currentStep--;
    updateStepperUI();
  }
});

// Helper functions
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

function markdownToHTML(md) {
  if (!md) return '';
  return md
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.*?)\*/g, '<em>$1</em>')
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\n/g, '<br>');
}

// INITIALIZATION RUNTIME
window.addEventListener('DOMContentLoaded', async () => {
  showLoading('Loading and extracting resume text...');
  try {
    const response = await fetch('api/resume_optimizer_ajax.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'ajax_action=optimizer_init&resume_path=' + encodeURIComponent(state.resumePath) + (state.profileId ? '&profile_id=' + encodeURIComponent(state.profileId) : '')
    });
    const result = await response.json();
    
    if (result.success && result.resume_text) {
      state.resumeText = result.resume_text;
      await loadRealityCheck();
    } else {
      hideLoading();
      alert('Extraction failed: ' + (result.message || 'Could not read resume text.'));
      window.location.href = 'index.php';
    }
  } catch (err) {
    hideLoading();
    alert('Network connection error: ' + err.message);
    window.location.href = 'index.php';
  }
});
