// assets/js/browser_proctor.js - Browser-Native Proctoring Engine

let activeSessionId = null;
let isInitialized = false;

// Onboarding checklist progress states
let fullscreenDone = false;
let screenDone = false;
let webcamDone = false;
let setupWizardComplete = false;

// Cooldown tracker: alert_type => timestamp when cooldown ends
const alertCooldowns = {};

// Event Listener references for clean cleanup
const listeners = {};

// Tracking variables for tab/app focus loss
let lastFocusLostTime = null;

// Global helper to update visual security indicators on the UI
window.setSecurityIndicator = function(indicatorId, isSecure) {
    const node = document.getElementById(`sec-node-${indicatorId}`);
    if (!node) return;
    const dot = node.querySelector('.status-glow-dot');
    if (!dot) return;
    
    if (isSecure) {
        dot.className = 'status-glow-dot status-green';
        node.style.color = 'var(--color-success)';
        node.style.borderColor = 'rgba(16, 185, 129, 0.2)';
        node.style.background = 'var(--color-success-bg)';
        if (indicatorId === 'webcam') node.title = "Webcam Monitoring: Active";
        if (indicatorId === 'screen') node.title = "Screen Sharing: Active";
        if (indicatorId === 'fullscreen') node.title = "Fullscreen Environment: Active";
        if (indicatorId === 'focus') node.title = "Tab Focus: Active";
        if (indicatorId === 'cursor') node.title = "Cursor Boundary: Secure";
    } else {
        dot.className = 'status-glow-dot status-red';
        node.style.color = 'var(--color-danger)';
        node.style.borderColor = 'rgba(239, 68, 68, 0.2)';
        node.style.background = 'var(--color-danger-bg)';
        if (indicatorId === 'webcam') node.title = "Webcam Monitoring: Anomaly/Inactive";
        if (indicatorId === 'screen') node.title = "Screen Sharing: Inactive";
        if (indicatorId === 'fullscreen') node.title = "Fullscreen Environment: Escaped/Inactive";
        if (indicatorId === 'focus') node.title = "Tab Focus: Background state";
        if (indicatorId === 'cursor') node.title = "Cursor Boundary: Outside Page";
    }
};

export function initBrowserProctor(sessionId) {
    if (isInitialized) {
        console.warn("Browser proctoring already initialized.");
        return;
    }
    activeSessionId = sessionId;
    
    // Setup wizard DOM connections and buttons
    setupIntegrityWizard();

    isInitialized = true;
    console.log("Browser proctoring wizard initialized.");
}

export function destroyBrowserProctor() {
    // Remove Fullscreen handlers
    const enterBtn = document.getElementById('setup-btn-fullscreen');
    if (enterBtn && listeners.fullscreenBtnClick) {
        enterBtn.removeEventListener('click', listeners.fullscreenBtnClick);
    }
    const resumeBtn = document.getElementById('enter-fullscreen-resume-btn');
    if (resumeBtn && listeners.resumeBtnClick) {
        resumeBtn.removeEventListener('click', listeners.resumeBtnClick);
    }
    const startBtn = document.getElementById('setup-start-btn');
    if (startBtn && listeners.startBtnClick) {
        startBtn.removeEventListener('click', listeners.startBtnClick);
    }
    if (listeners.fullscreenchange) {
        document.removeEventListener('fullscreenchange', listeners.fullscreenchange);
    }

    // Clean standard event listeners (only active if wizard completed)
    disableProctoringListeners();

    // Reset state
    isInitialized = false;
    activeSessionId = null;
    fullscreenDone = false;
    screenDone = false;
    webcamDone = false;
    setupWizardComplete = false;
    
    // Hide overlay
    const overlay = document.getElementById('integrity-setup-modal');
    if (overlay) overlay.style.display = 'none';

    // Reset indicator status to red
    window.setSecurityIndicator('webcam', false);
    window.setSecurityIndicator('screen', false);
    window.setSecurityIndicator('fullscreen', false);
    window.setSecurityIndicator('focus', false);
    window.setSecurityIndicator('cursor', false);

    console.log("Browser proctoring destroyed.");
}

// ----------------------------------------------------
// INTEGRITY SETUP WIZARD PIPELINE
// ----------------------------------------------------

function setupIntegrityWizard() {
    const overlay = document.getElementById('integrity-setup-modal');
    const wizardView = document.getElementById('wizard-setup-view');
    const resumeView = document.getElementById('wizard-resume-view');
    
    const fseBtn = document.getElementById('setup-btn-fullscreen');
    const fseResumeBtn = document.getElementById('enter-fullscreen-resume-btn');
    const screenBtn = document.getElementById('setup-btn-screen');
    const webcamBtn = document.getElementById('setup-btn-webcam');
    const startBtn = document.getElementById('setup-start-btn');

    if (!overlay || !wizardView || !resumeView) return;

    // Show wizard overlay initially
    overlay.style.display = 'flex';
    wizardView.style.display = 'block';
    resumeView.style.display = 'none';

    // Reset setup visual step nodes
    resetStepUI('fullscreen', 1);
    resetStepUI('screen', 2);
    resetStepUI('webcam', 3);

    // Initial fullscreen check
    const isFullscreen = document.fullscreenElement || document.webkitFullscreenElement;
    if (isFullscreen) {
        markStepComplete('fullscreen', 1);
        fullscreenDone = true;
        enableStep('screen');
    }

    // Step 1: Fullscreen Button Handler
    listeners.fullscreenBtnClick = async () => {
        try {
            if (document.documentElement.requestFullscreen) {
                await document.documentElement.requestFullscreen();
            } else if (document.documentElement.webkitRequestFullscreen) {
                await document.documentElement.webkitRequestFullscreen();
            }
        } catch (err) {
            console.error("Fullscreen request failed:", err);
            showProctorToast("Fullscreen permission denied or blocked by browser.", 'warning');
        }
    };
    if (fseBtn) fseBtn.addEventListener('click', listeners.fullscreenBtnClick);

    // Step 2: Screen Sharing Button Link
    listeners.screenBtnClick = () => {
        if (window.toggleScreenShare) {
            window.toggleScreenShare();
        }
    };
    if (screenBtn) screenBtn.addEventListener('click', listeners.screenBtnClick);

    // Step 3: Webcam Initialization Button Link
    listeners.webcamBtnClick = () => {
        const webcamPlaceholder = document.getElementById('webcam-monitor-placeholder');
        if (webcamPlaceholder) {
            const textEl = webcamPlaceholder.querySelector('p');
            if (textEl) textEl.innerText = "Requesting webcam permissions...";
        }
        
        // Trigger webcam permission and MediaPipe proctoring
        if (window.initProctor) {
            window.initProctor(activeSessionId, 
              (status) => {
                if (window.updateProctorIndicator) window.updateProctorIndicator(status);
                if (window.updateWebcamMonitorStatus) window.updateWebcamMonitorStatus(status);
                
                if (status === 'ok') {
                    if (window.bindWebcamStreamToVideo) window.bindWebcamStreamToVideo();
                    window.onWebcamSuccess(true);
                } else if (status === 'warning' || status === 'critical' || status === 'error') {
                    window.onWebcamSuccess(false);
                }
              },
              (landmarks) => {
                if (window.setLatestLandmarks) window.setLatestLandmarks(landmarks);
              }
            );
        }
    };
    if (webcamBtn) webcamBtn.addEventListener('click', listeners.webcamBtnClick);

    // Start Button: Loads Agent Iframe and official session start
    listeners.startBtnClick = () => {
        setupWizardComplete = true;
        overlay.style.display = 'none';
        
        // Load TruGen Agent call iframe dynamically
        if (window.loadAgentIframe) {
            window.loadAgentIframe();
        }
        
        // Enable passive security logging listeners
        enableProctoringListeners();
    };
    if (startBtn) startBtn.addEventListener('click', listeners.startBtnClick);

    // Resume button link (exclusively active when escaping fullscreen mid-interview)
    listeners.resumeBtnClick = async () => {
        try {
            if (document.documentElement.requestFullscreen) {
                await document.documentElement.requestFullscreen();
            } else if (document.documentElement.webkitRequestFullscreen) {
                await document.documentElement.webkitRequestFullscreen();
            }
        } catch (err) {
            console.error("Fullscreen resume failed:", err);
        }
    };
    if (fseResumeBtn) fseResumeBtn.addEventListener('click', listeners.resumeBtnClick);

    // Dynamic Fullscreen Change Listener
    listeners.fullscreenchange = () => {
        const isFullscreen = document.fullscreenElement || document.webkitFullscreenElement;
        
        if (setupWizardComplete) {
            // Mid-interview layout checks
            if (isFullscreen) {
                overlay.style.display = 'none';
                window.setSecurityIndicator('fullscreen', true);
            } else {
                // Exited fullscreen: show resume block overlay
                overlay.style.display = 'flex';
                wizardView.style.display = 'none';
                resumeView.style.display = 'block';
                
                window.setSecurityIndicator('fullscreen', false);
                triggerBrowserAlert('fullscreen_exit', 'warning', { reason: 'Candidate exited fullscreen mode.' });
            }
        } else {
            // Setup wizard layout checks
            if (isFullscreen) {
                markStepComplete('fullscreen', 1);
                fullscreenDone = true;
                enableStep('screen');
                window.setSecurityIndicator('fullscreen', true);
            } else {
                resetStepUI('fullscreen', 1);
                fullscreenDone = false;
                disableStep('screen');
                disableStep('webcam');
                window.setSecurityIndicator('fullscreen', false);
            }
        }
    };
    document.addEventListener('fullscreenchange', listeners.fullscreenchange);
}

// ----------------------------------------------------
// SEQUENTIAL WIZARD STATE ACTIONS
// ----------------------------------------------------

window.onScreenShareSuccess = function(isSuccess) {
    if (isSuccess) {
        markStepComplete('screen', 2);
        screenDone = true;
        enableStep('webcam');
        window.setSecurityIndicator('screen', true);
    } else {
        resetStepUI('screen', 2);
        screenDone = false;
        disableStep('webcam');
        window.setSecurityIndicator('screen', false);
    }
};

window.onWebcamSuccess = function(isSuccess) {
    const startBtn = document.getElementById('setup-start-btn');
    if (isSuccess) {
        markStepComplete('webcam', 3);
        webcamDone = true;
        window.setSecurityIndicator('webcam', true);
        if (startBtn) {
            startBtn.removeAttribute('disabled');
            startBtn.focus();
        }
    } else {
        resetStepUI('webcam', 3);
        webcamDone = false;
        window.setSecurityIndicator('webcam', false);
        if (startBtn) {
            startBtn.setAttribute('disabled', 'true');
        }
    }
};

function enableStep(stepId) {
    const stepEl = document.getElementById(`setup-step-${stepId}`);
    const btn = document.getElementById(`setup-btn-${stepId}`);
    if (!stepEl || !btn) return;
    
    stepEl.style.opacity = '1';
    stepEl.style.pointerEvents = 'auto';
    btn.removeAttribute('disabled');
    btn.className = 'btn-action';
}

function disableStep(stepId) {
    const stepEl = document.getElementById(`setup-step-${stepId}`);
    const btn = document.getElementById(`setup-btn-${stepId}`);
    if (!stepEl || !btn) return;
    
    stepEl.style.opacity = '0.5';
    stepEl.style.pointerEvents = 'none';
    btn.setAttribute('disabled', 'true');
    btn.className = 'btn-action btn-secondary';
}

function markStepComplete(stepId, num) {
    const stepEl = document.getElementById(`setup-step-${stepId}`);
    const circle = document.getElementById(`setup-circle-${num}`);
    const btn = document.getElementById(`setup-btn-${stepId}`);
    if (!stepEl || !circle || !btn) return;

    stepEl.style.background = 'rgba(16, 185, 129, 0.08)';
    stepEl.style.borderColor = 'rgba(16, 185, 129, 0.3)';
    circle.innerHTML = '✓';
    circle.style.borderColor = 'var(--color-success)';
    circle.style.color = '#fff';
    circle.style.background = 'var(--color-success)';
    
    btn.setAttribute('disabled', 'true');
    btn.innerText = 'Active';
    btn.className = 'btn-action btn-secondary';
}

function resetStepUI(stepId, num) {
    const stepEl = document.getElementById(`setup-step-${stepId}`);
    const circle = document.getElementById(`setup-circle-${num}`);
    const btn = document.getElementById(`setup-btn-${stepId}`);
    if (!stepEl || !circle || !btn) return;

    stepEl.style.background = 'var(--color-surface-elevated)';
    stepEl.style.borderColor = 'var(--color-border)';
    circle.innerHTML = `${num}`;
    circle.style.borderColor = 'var(--color-border)';
    circle.style.color = 'var(--color-text-secondary)';
    circle.style.background = 'var(--color-surface)';
    
    btn.innerText = stepId === 'fullscreen' ? 'Enter' : (stepId === 'screen' ? 'Share' : 'Allow');
}

// ----------------------------------------------------
// SECURITY TELEMETRY LIFECYCLE LISTENERS
// ----------------------------------------------------

function enableProctoringListeners() {
    // Visibility and window focus tracking
    listeners.visibilitychange = () => handleVisibilityChange();
    document.addEventListener('visibilitychange', listeners.visibilitychange);

    listeners.blur = () => handleFocusLoss();
    window.addEventListener('blur', listeners.blur);

    listeners.focus = () => handleFocusGain();
    window.addEventListener('focus', listeners.focus);

    // Keyboard shortcut blocking (DevTools preventions)
    listeners.keydown = (e) => handleKeyDown(e);
    document.addEventListener('keydown', listeners.keydown);

    // Clipboard restrictions (Copy, Cut, Paste prevention)
    listeners.copy = (e) => handleClipboardBlock(e, 'copy');
    listeners.cut = (e) => handleClipboardBlock(e, 'cut');
    listeners.paste = (e) => handleClipboardBlock(e, 'paste');
    document.addEventListener('copy', listeners.copy);
    document.addEventListener('cut', listeners.cut);
    document.addEventListener('paste', listeners.paste);

    // Multi-monitor cursor tracking (mouseout of boundary)
    listeners.mouseout = (e) => handleMouseOut(e);
    document.addEventListener('mouseout', listeners.mouseout);

    // Mousemove to restore green cursor state when mouse returns to page
    listeners.mousemove = () => {
        window.setSecurityIndicator('cursor', true);
    };
    document.addEventListener('mousemove', listeners.mousemove);

    // Hardware/Device connection change tracking
    listeners.devicechange = () => handleDeviceChange();
    if (navigator.mediaDevices && navigator.mediaDevices.addEventListener) {
        navigator.mediaDevices.addEventListener('devicechange', listeners.devicechange);
    }

    // Set initial active indicator states
    window.setSecurityIndicator('focus', document.visibilityState === 'visible' && document.hasFocus());
    window.setSecurityIndicator('cursor', true);
}

function disableProctoringListeners() {
    if (listeners.visibilitychange) document.removeEventListener('visibilitychange', listeners.visibilitychange);
    if (listeners.blur) window.removeEventListener('blur', listeners.blur);
    if (listeners.focus) window.removeEventListener('focus', listeners.focus);
    if (listeners.keydown) document.removeEventListener('keydown', listeners.keydown);
    if (listeners.copy) document.removeEventListener('copy', listeners.copy);
    if (listeners.cut) document.removeEventListener('cut', listeners.cut);
    if (listeners.paste) document.removeEventListener('paste', listeners.paste);
    if (listeners.mouseout) document.removeEventListener('mouseout', listeners.mouseout);
    if (listeners.mousemove) document.removeEventListener('mousemove', listeners.mousemove);

    if (navigator.mediaDevices && navigator.mediaDevices.removeEventListener && listeners.devicechange) {
        navigator.mediaDevices.removeEventListener('devicechange', listeners.devicechange);
    }
}

function handleVisibilityChange() {
    if (document.visibilityState === 'hidden') {
        lastFocusLostTime = Date.now();
        window.setSecurityIndicator('focus', false);
        triggerBrowserAlert('tab_switch', 'warning', { reason: 'Candidate switched tabs or minimized browser.' });
    } else {
        window.setSecurityIndicator('focus', true);
    }
}

function handleFocusLoss() {
    if (!lastFocusLostTime) {
        lastFocusLostTime = Date.now();
        window.setSecurityIndicator('focus', false);
        triggerBrowserAlert('tab_switch', 'warning', { reason: 'Candidate clicked away from browser window.' });
    }
}

function handleFocusGain() {
    window.setSecurityIndicator('focus', true);
    if (lastFocusLostTime) {
        const durationMs = Date.now() - lastFocusLostTime;
        const durationSecs = Math.round(durationMs / 100) / 10;
        lastFocusLostTime = null;
        
        // Log focal return
        console.log(`Candidate returned to assessment after ${durationSecs}s focus loss.`);
    }
}

function handleKeyDown(e) {
    const key = e.key;
    const code = e.code;
    const ctrlKey = e.ctrlKey || e.metaKey;
    const shiftKey = e.shiftKey;
    const altKey = e.altKey;

    let shouldBlock = false;
    let shortcutName = '';

    // F12 key
    if (key === 'F12' || code === 'F12') {
        shouldBlock = true;
        shortcutName = 'F12 DevTools';
    }
    // Ctrl+Shift+I / Cmd+Opt+I (Chrome/Safari DevTools)
    else if (ctrlKey && (shiftKey || altKey) && (key === 'i' || key === 'I' || code === 'KeyI')) {
        shouldBlock = true;
        shortcutName = 'Inspect DevTools shortcut';
    }
    // Ctrl+Shift+J / Cmd+Opt+J (Console)
    else if (ctrlKey && (shiftKey || altKey) && (key === 'j' || key === 'J' || code === 'KeyJ')) {
        shouldBlock = true;
        shortcutName = 'Console DevTools shortcut';
    }
    // Ctrl+Shift+C / Cmd+Opt+C (Element selector)
    else if (ctrlKey && (shiftKey || altKey) && (key === 'c' || key === 'C' || code === 'KeyC')) {
        shouldBlock = true;
        shortcutName = 'Selector DevTools shortcut';
    }
    // Ctrl+U / Cmd+Opt+U (View Source)
    else if (ctrlKey && (altKey || !shiftKey) && (key === 'u' || key === 'U' || code === 'KeyU')) {
        shouldBlock = true;
        shortcutName = 'View Source shortcut';
    }

    if (shouldBlock) {
        e.preventDefault();
        e.stopPropagation();
        showProctorToast(`Action blocked: ${shortcutName} is disabled during this assessment.`, 'warning');
        triggerBrowserAlert('copy_paste_attempt', 'warning', { reason: `Attempted to open DevTools via ${shortcutName}.` });
    }
}

function handleClipboardBlock(e, type) {
    e.preventDefault();
    showProctorToast(`Action blocked: Clipboard ${type} is disabled.`, 'warning');
    triggerBrowserAlert('copy_paste_attempt', 'warning', { reason: `Attempted clipboard ${type} operation.` });
}

function handleMouseOut(e) {
    // If e.relatedTarget is null or undefined, the mouse left the root document window boundary
    if (!e.relatedTarget) {
        // Limit false positives by verifying coordinates are actually on/near screen boundary edges
        const x = e.clientX;
        const y = e.clientY;
        const buffer = 15; // px tolerance
        
        const isNearEdge = x <= buffer || y <= buffer || (window.innerWidth - x) <= buffer || (window.innerHeight - y) <= buffer;
        if (isNearEdge) {
            window.setSecurityIndicator('cursor', false);
            triggerBrowserAlert('cursor_left_screen', 'warning', { 
                reason: `Mouse cursor moved outside the browser page. coordinates: (${x}, ${y})`,
                screen_width: window.innerWidth,
                screen_height: window.innerHeight
            });
        }
    }
}

async function handleDeviceChange() {
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const info = devices.map(d => ({ kind: d.kind, label: d.label || 'unlabeled' }));
        triggerBrowserAlert('device_change', 'warning', { 
            reason: 'Hardware configuration or media device connection changed.',
            devices: info
        });
    } catch (err) {
        triggerBrowserAlert('device_change', 'warning', { reason: 'Hardware device configuration changed.' });
    }
}

// ----------------------------------------------------
// UTILITY FUNCTIONS
// ----------------------------------------------------

async function triggerBrowserAlert(alertType, severity, clientDetails) {
    const now = Date.now();
    
    // 5 seconds cooldown per alert type to avoid database clutter
    if (alertCooldowns[alertType] && now < alertCooldowns[alertType]) {
        return;
    }
    alertCooldowns[alertType] = now + 5000;
    
    console.warn(`[Browser Proctor Alert] Anomaly: ${alertType}`, clientDetails);
    
    let snapshot = '';
    // Capture high-res screen capture frame if global grabber is available
    if (window.captureScreenFrame) {
        try {
            snapshot = window.captureScreenFrame();
        } catch (err) {
            console.error("Failed to execute high-res screen frame capture:", err);
        }
    }
    
    try {
        const response = await fetch('api.php?action=proctor_alert', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                session_id: activeSessionId,
                alert_type: alertType,
                severity: severity,
                client_details: clientDetails,
                snapshot: snapshot
            })
        });
        
        const data = await response.json();
        console.log(`[Browser Proctor response]`, data);
        
        // Show non-intrusive toast warning on screen
        if (alertType === 'tab_switch') {
            showProctorToast("Integrity Anomaly: Tab switch or focus loss detected.", "warning");
        } else if (alertType === 'fullscreen_exit') {
            showProctorToast("Assessment Paused: Please enter Fullscreen mode.", "warning");
        } else if (alertType === 'cursor_left_screen') {
            showProctorToast("Gaze/Mouse Anomaly: Please keep focus on the assessment screen.", "warning");
        } else if (alertType === 'device_change') {
            showProctorToast("Hardware Alert: Monitor or peripheral connection change detected.", "warning");
        }
    } catch (err) {
        console.error("Failed to send browser proctor alert details to api:", err);
    }
}

function showProctorToast(message, type = 'warning') {
    let container = document.getElementById('proctor-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'proctor-toast-container';
        container.style.position = 'fixed';
        container.style.bottom = '24px';
        container.style.right = '24px';
        container.style.zIndex = '99999';
        container.style.display = 'flex';
        container.style.flexDirection = 'column';
        container.style.gap = '8px';
        document.body.appendChild(container);
    }
    
    const toast = document.createElement('div');
    toast.className = `proctor-toast ${type}`;
    toast.style.background = type === 'warning' ? 'var(--color-danger-bg)' : 'var(--color-accent-gradient)';
    toast.style.color = type === 'warning' ? 'var(--color-danger)' : '#fff';
    toast.style.border = `1px solid ${type === 'warning' ? 'rgba(239, 68, 68, 0.2)' : 'rgba(99, 102, 241, 0.2)'}`;
    toast.style.padding = '12px 20px';
    toast.style.borderRadius = 'var(--radius-inner)';
    toast.style.boxShadow = '0 8px 16px var(--color-shadow)';
    toast.style.fontFamily = 'Inter, sans-serif';
    toast.style.fontSize = 'var(--text-sm)';
    toast.style.fontWeight = '500';
    toast.style.backdropFilter = 'blur(8px)';
    toast.style.transition = 'all 0.3s cubic-bezier(0.16, 1, 0.3, 1)';
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(20px)';
    toast.innerText = message;
    
    container.appendChild(toast);
    
    // Trigger transition
    setTimeout(() => {
        toast.style.opacity = '1';
        toast.style.transform = 'translateY(0)';
    }, 10);
    
    // Remove toast after 4 seconds
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-20px)';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

