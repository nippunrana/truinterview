// assets/js/browser_proctor.js - Browser-Native Proctoring Engine

let activeSessionId = null;
let isInitialized = false;
let screenDetailsObj = null;

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
    
    node.setAttribute('data-okay', isSecure ? 'true' : 'false');
    
    const badge = node.querySelector('.security-check-status-badge');
    if (badge) {
        badge.innerHTML = isSecure ? '<span class="status-icon">✓</span>' : '<span class="status-icon">✗</span>';
    }
    
    if (isSecure) {
        if (indicatorId === 'webcam') node.title = "Webcam Monitoring: Active";
        if (indicatorId === 'screen') node.title = "Screen Sharing: Active";
        if (indicatorId === 'fullscreen') node.title = "Fullscreen Environment: Active";
        if (indicatorId === 'focus') node.title = "Tab Focus: Active";
        if (indicatorId === 'cursor') node.title = "Cursor Boundary: Secure";
        if (indicatorId === 'monitor') node.title = "Monitor: Single Display Secure";
    } else {
        if (indicatorId === 'webcam') node.title = "Webcam Monitoring: Anomaly/Inactive";
        if (indicatorId === 'screen') node.title = "Screen Sharing: Inactive";
        if (indicatorId === 'fullscreen') node.title = "Fullscreen Environment: Escaped/Inactive";
        if (indicatorId === 'focus') node.title = "Tab Focus: Background state";
        if (indicatorId === 'cursor') node.title = "Cursor Boundary: Outside Page";
        if (indicatorId === 'monitor') node.title = "Monitor: Multiple Displays Detected";
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

    // Set initial display monitor state
    const isSingleMonitor = !window.screen.isExtended;
    window.setSecurityIndicator('monitor', isSingleMonitor);

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
    const resumeScreenBtn = document.getElementById('screen-share-resume-btn');
    if (resumeScreenBtn && listeners.screenShareResumeBtnClick) {
        resumeScreenBtn.removeEventListener('click', listeners.screenShareResumeBtnClick);
    }
    if (listeners.fullscreenchange) {
        document.removeEventListener('fullscreenchange', listeners.fullscreenchange);
    }

    // Clean screenDetails listeners
    if (screenDetailsObj && listeners.screenschange) {
        try {
            screenDetailsObj.removeEventListener('screenschange', listeners.screenschange);
        } catch (e) {
            console.error("Error removing screenschange listener:", e);
        }
    }
    screenDetailsObj = null;
    listeners.screenschange = null;

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
    window.setSecurityIndicator('monitor', false);

    console.log("Browser proctoring destroyed.");
}

// ----------------------------------------------------
// INTEGRITY SETUP WIZARD PIPELINE
// ----------------------------------------------------

function updateMidInterviewOverlayState() {
    if (!setupWizardComplete) return;

    const overlay = document.getElementById('integrity-setup-modal');
    const wizardView = document.getElementById('wizard-setup-view');
    const resumeView = document.getElementById('wizard-resume-view');
    const screenShareResumeView = document.getElementById('wizard-screen-share-resume-view');
    const monitorResumeView = document.getElementById('wizard-monitor-resume-view');

    if (!overlay || !wizardView || !resumeView || !screenShareResumeView || !monitorResumeView) return;

    const isFullscreen = !!(document.fullscreenElement || document.webkitFullscreenElement);
    const isSingleMonitor = !(screenDetailsObj && (screenDetailsObj.screens.length > 1 || window.screen.isExtended));

    if (!isSingleMonitor) {
        // Multiple monitors connected: lock and show monitor resume view
        overlay.style.display = 'flex';
        wizardView.style.display = 'none';
        resumeView.style.display = 'none';
        screenShareResumeView.style.display = 'none';
        monitorResumeView.style.display = 'block';

        triggerBrowserAlert('device_change', 'critical', {
            reason: 'Candidate connected a secondary monitor during the active session.',
            screen_count: screenDetailsObj ? screenDetailsObj.screens.length : 2
        });
    } else if (!screenDone) {
        // Screen sharing is stopped: force screen share view
        overlay.style.display = 'flex';
        wizardView.style.display = 'none';
        resumeView.style.display = 'none';
        screenShareResumeView.style.display = 'block';
        monitorResumeView.style.display = 'none';

        // Trigger proctor alert (with cooldown)
        triggerBrowserAlert('screen_share_stopped', 'critical', { reason: 'Candidate stopped screen sharing.' });
    } else if (!isFullscreen) {
        // Screen sharing is active but fullscreen exited: force fullscreen resume view
        overlay.style.display = 'flex';
        wizardView.style.display = 'none';
        screenShareResumeView.style.display = 'none';
        resumeView.style.display = 'block';
        monitorResumeView.style.display = 'none';

        triggerBrowserAlert('fullscreen_exit', 'warning', { reason: 'Candidate exited fullscreen mode.' });
    } else {
        // All checks are secure: hide overlay
        overlay.style.display = 'none';
        wizardView.style.display = 'none';
        resumeView.style.display = 'none';
        screenShareResumeView.style.display = 'none';
        monitorResumeView.style.display = 'none';
    }
}

function completeWizardAndStart() {
    if (setupWizardComplete) return;
    setupWizardComplete = true;
    
    const overlay = document.getElementById('integrity-setup-modal');
    if (overlay) overlay.style.display = 'none';
    
    // Load TruGen Agent call iframe dynamically
    if (window.loadAgentIframe) {
        window.loadAgentIframe();
    }
    
    // Enable passive security logging listeners
    enableProctoringListeners();
}

function checkAndStartInterview() {
    if (screenDone && webcamDone && fullscreenDone) {
        completeWizardAndStart();
    }
}

function setupIntegrityWizard() {
    const overlay = document.getElementById('integrity-setup-modal');
    const wizardView = document.getElementById('wizard-setup-view');
    const resumeView = document.getElementById('wizard-resume-view');
    const screenShareResumeView = document.getElementById('wizard-screen-share-resume-view');
    const monitorResumeView = document.getElementById('wizard-monitor-resume-view');
    
    const fseBtn = document.getElementById('setup-btn-fullscreen');
    const fseResumeBtn = document.getElementById('enter-fullscreen-resume-btn');
    const resumeScreenBtn = document.getElementById('screen-share-resume-btn');
    const screenBtn = document.getElementById('setup-btn-screen');
    const webcamBtn = document.getElementById('setup-btn-webcam');
    const startBtn = document.getElementById('setup-start-btn');

    if (!overlay || !wizardView || !resumeView) return;

    // Show wizard overlay initially
    overlay.style.display = 'flex';
    wizardView.style.display = 'block';
    resumeView.style.display = 'none';
    if (screenShareResumeView) screenShareResumeView.style.display = 'none';
    if (monitorResumeView) monitorResumeView.style.display = 'none';

    // Reset setup visual step nodes (Step 1: Screen, Step 2: Webcam, Step 3: Fullscreen)
    resetStepUI('screen', 1);
    resetStepUI('webcam', 2);
    resetStepUI('fullscreen', 3);

    // Initially active step is Screen Share
    enableStep('screen');
    disableStep('webcam');
    disableStep('fullscreen');

    // Step 1: Screen Sharing Button Link
    listeners.screenBtnClick = () => {
        if (window.toggleScreenShare) {
            window.toggleScreenShare();
        }
    };
    if (screenBtn) screenBtn.addEventListener('click', listeners.screenBtnClick);

    // Step 2: Webcam Initialization Button Link
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

    // Step 3: Fullscreen Button Handler
    listeners.fullscreenBtnClick = async () => {
        if (!('getScreenDetails' in window)) {
            if (window.showProctorBanner) {
                window.showProctorBanner("Browser Error: Display detection API is unsupported. Please use a supported Chromium-based browser.", 'error', 6000, 'monitor');
            }
            alert("Your browser does not support display configuration tracking. Please use a supported Chromium-based browser like Google Chrome or Microsoft Edge.");
            return;
        }

        try {
            screenDetailsObj = await window.getScreenDetails();
            const isSingleMonitor = !(screenDetailsObj.screens.length > 1 || window.screen.isExtended);
            window.setSecurityIndicator('monitor', isSingleMonitor);
        } catch (err) {
            console.error("Display permission request failed:", err);
            if (window.showProctorBanner) {
                window.showProctorBanner("Integrity Block: Display permission is required to verify your monitor configuration.", 'warning', 6000, 'monitor');
            }
            alert("Security Restriction: You must grant permission to view display details to proceed with this assessment.");
            return;
        }

        if (screenDetailsObj.screens.length > 1 || window.screen.isExtended) {
            if (window.showProctorBanner) {
                window.showProctorBanner("Integrity Block: Multiple displays detected. Please disconnect all external monitors.", 'warning', 6000, 'monitor');
            }
            alert("Security Restriction: Multiple displays detected. Please disconnect all external monitors/screens and ensure you are using a single monitor to proceed.");
            return;
        }

        // Register listener for layout changes mid-session
        if (!listeners.screenschange) {
            listeners.screenschange = () => {
                const isSingleMonitor = !(screenDetailsObj && (screenDetailsObj.screens.length > 1 || window.screen.isExtended));
                window.setSecurityIndicator('monitor', isSingleMonitor);
                if (!isSingleMonitor) {
                    triggerBrowserAlert('device_change', 'critical', {
                        reason: 'Candidate connected a secondary monitor during the active session.',
                        screen_count: screenDetailsObj.screens.length
                    });
                }
                if (setupWizardComplete) {
                    updateMidInterviewOverlayState();
                }
            };
            screenDetailsObj.addEventListener('screenschange', listeners.screenschange);
        }

        try {
            if (document.documentElement.requestFullscreen) {
                await document.documentElement.requestFullscreen();
            } else if (document.documentElement.webkitRequestFullscreen) {
                await document.documentElement.webkitRequestFullscreen();
            }
        } catch (err) {
            console.error("Fullscreen request failed:", err);
            if (window.showProctorBanner) {
                window.showProctorBanner("Fullscreen permission denied or blocked by browser.", 'warning', 4000, 'fullscreen');
            }
        }
    };
    if (fseBtn) fseBtn.addEventListener('click', listeners.fullscreenBtnClick);

    // Start Button (fallback event if still clicked somehow)
    listeners.startBtnClick = () => {
        completeWizardAndStart();
    };
    if (startBtn) startBtn.addEventListener('click', listeners.startBtnClick);

    // Resume screen share button handler
    listeners.screenShareResumeBtnClick = () => {
        if (window.toggleScreenShare) {
            window.toggleScreenShare();
        }
    };
    if (resumeScreenBtn) resumeScreenBtn.addEventListener('click', listeners.screenShareResumeBtnClick);

    // Resume button link (exclusively active when escaping fullscreen mid-interview)
    listeners.resumeBtnClick = async () => {
        if (screenDetailsObj) {
            if (screenDetailsObj.screens.length > 1 || window.screen.isExtended) {
                if (window.showProctorBanner) {
                    window.showProctorBanner("Integrity Block: Multiple displays detected. Please disconnect all external monitors to resume.", 'warning', 6000, 'monitor');
                }
                alert("Security Restriction: Multiple displays detected. Please disconnect all external monitors/screens to resume the assessment.");
                return;
            }
        }
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
            updateMidInterviewOverlayState();
        } else {
            // Setup wizard layout checks
            if (isFullscreen) {
                if (webcamDone) {
                    markStepComplete('fullscreen', 3);
                    fullscreenDone = true;
                    window.setSecurityIndicator('fullscreen', true);
                    checkAndStartInterview();
                }
            } else {
                if (webcamDone) {
                    resetStepUI('fullscreen', 3);
                    fullscreenDone = false;
                    window.setSecurityIndicator('fullscreen', false);
                }
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
        markStepComplete('screen', 1);
        screenDone = true;
        enableStep('webcam');
        window.setSecurityIndicator('screen', true);
    } else {
        resetStepUI('screen', 1);
        screenDone = false;
        disableStep('webcam');
        disableStep('fullscreen');
        window.setSecurityIndicator('screen', false);
    }

    if (setupWizardComplete) {
        updateMidInterviewOverlayState();
    }
};

window.onWebcamSuccess = function(isSuccess) {
    if (isSuccess) {
        markStepComplete('webcam', 2);
        webcamDone = true;
        window.setSecurityIndicator('webcam', true);
        enableStep('fullscreen');
        
        // Auto-complete fullscreen step if browser is already fullscreened
        const isFullscreen = document.fullscreenElement || document.webkitFullscreenElement;
        if (isFullscreen) {
            markStepComplete('fullscreen', 3);
            fullscreenDone = true;
            window.setSecurityIndicator('fullscreen', true);
            checkAndStartInterview();
        }
    } else {
        resetStepUI('webcam', 2);
        webcamDone = false;
        window.setSecurityIndicator('webcam', false);
        disableStep('fullscreen');
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
    
    btn.innerText = stepId === 'fullscreen' ? 'Allow' : (stepId === 'screen' ? 'Share' : 'Allow');
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
    const isSingleMonitor = screenDetailsObj ? (screenDetailsObj.screens.length === 1 && !window.screen.isExtended) : !window.screen.isExtended;
    window.setSecurityIndicator('monitor', isSingleMonitor);
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
    setTimeout(() => {
        // If the browser window itself still has focus (focus is on an iframe inside the document), do not flag it
        const activeEl = document.activeElement;
        const isIframeFocus = activeEl && activeEl.tagName === 'IFRAME';
        
        if (document.hasFocus() || isIframeFocus) {
            console.log("[Browser Proctor] Focus shifted internally (likely to iframe). Suppressing blur.");
            return;
        }

        if (!lastFocusLostTime) {
            lastFocusLostTime = Date.now();
            window.setSecurityIndicator('focus', false);
            triggerBrowserAlert('tab_switch', 'warning', { reason: 'Candidate clicked away from browser window.' });
        }
    }, 150);
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
        if (window.showProctorBanner) {
            window.showProctorBanner(`Action blocked: ${shortcutName} is disabled during this assessment.`, 'warning', 4000, 'focus');
        }
        triggerBrowserAlert('copy_paste_attempt', 'warning', { reason: `Attempted to open DevTools via ${shortcutName}.` });
    }
}

function handleClipboardBlock(e, type) {
    e.preventDefault();
    if (window.showProctorBanner) {
        window.showProctorBanner(`Action blocked: Clipboard ${type} is disabled.`, 'warning', 4000, 'focus');
    }
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
        
        // Show non-intrusive warning banner at the top of the screen
        if (window.showProctorBanner) {
            if (alertType === 'tab_switch') {
                window.showProctorBanner("Integrity Anomaly: Tab switch or focus loss detected.", "warning", 4000, "focus");
            } else if (alertType === 'fullscreen_exit') {
                window.showProctorBanner("Assessment Paused: Please enter Fullscreen mode.", "warning", 4000, "fullscreen");
            } else if (alertType === 'cursor_left_screen') {
                window.showProctorBanner("Gaze/Mouse Anomaly: Please keep focus on the assessment screen.", "warning", 4000, "cursor");
            } else if (alertType === 'device_change') {
                window.showProctorBanner("Hardware Alert: Monitor or peripheral connection change detected.", "warning", 4000, "monitor");
            }
        }
    } catch (err) {
        console.error("Failed to send browser proctor alert details to api:", err);
    }
}

