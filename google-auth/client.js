// client.js - Google Identity Services client integration and role selection UI handler

document.addEventListener('DOMContentLoaded', function() {
    const btnContainer = document.getElementById('google-signin-container');
    if (!btnContainer) return;

    // Inject the role selection modal HTML into the DOM dynamically
    injectRoleModal();

    // Verify Google Client ID configuration
    if (!window.GOOGLE_CLIENT_ID || window.GOOGLE_CLIENT_ID.trim() === '' || window.GOOGLE_CLIENT_ID.indexOf('your_google_client_id') !== -1) {
        console.warn('Google Sign-In: GOOGLE_CLIENT_ID environment variable is missing or placeholder. Google Sign-In button will not be rendered.');
        btnContainer.innerHTML = '<div style="font-size: 0.85rem; padding: 10px; border: 1px dashed #cbd5e1; border-radius: 8px; color: #64748b; text-align: center;">Google Sign-In not configured. Set GOOGLE_CLIENT_ID in your .env file.</div>';
        return;
    }

    // Check if google library is already loaded
    if (typeof google !== 'undefined' && google.accounts && google.accounts.id) {
        initGoogleSignIn();
    } else {
        // Expose callback function that Google API calls once loaded
        window.onGoogleLibraryLoad = function() {
            initGoogleSignIn();
        };
    }
});

function initGoogleSignIn() {
    const btnContainer = document.getElementById('google-signin-container');
    if (!btnContainer) return;

    // Prevent double initialization
    if (btnContainer.dataset.initialized === 'true') return;
    btnContainer.dataset.initialized = 'true';

    try {
        google.accounts.id.initialize({
            client_id: window.GOOGLE_CLIENT_ID,
            callback: handleCredentialResponse
        });

        google.accounts.id.renderButton(
            btnContainer,
            { 
                theme: "outline", 
                size: "large", 
                width: btnContainer.clientWidth || 380,
                text: "continue_with",
                shape: "rectangular"
            }
        );
    } catch (e) {
        console.error('Google Sign-In initialization failed:', e);
    }
}

/**
 * Handle ID Token response from Google GIS
 */
function handleCredentialResponse(response) {
    if (!response.credential) {
        showGlobalError('Invalid response received from Google authentication.');
        return;
    }

    // Clear any existing global errors
    clearGlobalError();

    // Post ID Token to backend verification script
    fetch('google-auth/handler.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: 'verify',
            credential: response.credential
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            if (data.is_new) {
                // First-time login: show role selection modal
                showModal();
            } else {
                // Existing user: redirect to dashboard
                window.location.href = data.redirect;
            }
        } else {
            showGlobalError(data.message || 'Google authentication failed.');
        }
    })
    .catch(err => {
        console.error('Error verifying Google credentials:', err);
        showGlobalError('Connection error verifying Google account. Please try again.');
    });
}

/**
 * Dynamically Inject the Role Selection Modal structure
 */
function injectRoleModal() {
    if (document.getElementById('google-role-modal-overlay')) return;

    const modalHTML = `
        <div id="google-role-modal-overlay" class="auth-modal-overlay">
            <div class="auth-modal">
                <div class="auth-modal-header">
                    <h3 class="auth-modal-title">Complete Your Profile</h3>
                    <p class="auth-modal-subtitle">Choose your user type to finalize registration</p>
                </div>
                <div id="google-modal-error" class="modal-error" style="display: none;"></div>
                <form id="google-role-form">
                    <div class="modal-role-selector">
                        <div id="modal-role-candidate" class="modal-role-card active" onclick="setModalRole('candidate')">
                            <div class="role-icon">
                                <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                            </div>
                            <div class="role-title">Candidate</div>
                            <div class="role-desc">Practice code & take AI screening rounds</div>
                        </div>
                        <div id="modal-role-recruiter" class="modal-role-card" onclick="setModalRole('recruiter')">
                            <div class="role-icon">
                                <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            <div class="role-title">Recruiter</div>
                            <div class="role-desc">Generate assessments & hire technical talent</div>
                        </div>
                    </div>
                    
                    <input type="hidden" name="role" id="modal-role-input" value="candidate">
                    
                    <div class="form-group" id="modal-company-group" style="display: none; margin-bottom: 24px;">
                        <label for="modal_company_name" style="text-align: left;">Company Name</label>
                        <input type="text" name="company_name" id="modal_company_name" class="form-input" placeholder="e.g. Acme Corp">
                    </div>
                    
                    <button type="submit" class="btn-modal-submit">Complete Setup</button>
                </form>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', modalHTML);

    // Bind form submit listener
    const form = document.getElementById('google-role-form');
    if (form) {
        form.addEventListener('submit', handleRoleFormSubmit);
    }
}

/**
 * Handle role toggle inside the modal
 */
window.setModalRole = function(role) {
    const candidateCard = document.getElementById('modal-role-candidate');
    const recruiterCard = document.getElementById('modal-role-recruiter');
    const companyGroup = document.getElementById('modal-company-group');
    const companyInput = document.getElementById('modal_company_name');
    const roleInput = document.getElementById('modal-role-input');
    
    if (!candidateCard || !recruiterCard || !companyGroup || !companyInput || !roleInput) return;
    
    roleInput.value = role;
    
    if (role === 'candidate') {
        candidateCard.classList.add('active');
        recruiterCard.classList.remove('active');
        companyGroup.style.display = 'none';
        companyInput.removeAttribute('required');
        companyInput.value = '';
    } else {
        candidateCard.classList.remove('active');
        recruiterCard.classList.add('active');
        companyGroup.style.display = 'flex';
        companyInput.setAttribute('required', 'required');
        companyInput.focus();
    }
};

/**
 * Handle role/company form submission
 */
function handleRoleFormSubmit(e) {
    e.preventDefault();
    const roleInput = document.getElementById('modal-role-input');
    const companyInput = document.getElementById('modal_company_name');
    const modalError = document.getElementById('google-modal-error');
    
    if (!roleInput || !modalError) return;

    modalError.style.display = 'none';
    modalError.textContent = '';

    const role = roleInput.value;
    const companyName = companyInput ? companyInput.value : '';

    fetch('google-auth/handler.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: 'complete_registration',
            role: role,
            company_name: companyName
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            window.location.href = data.redirect;
        } else {
            modalError.textContent = data.message || 'Failed to complete registration.';
            modalError.style.display = 'block';
        }
    })
    .catch(err => {
        console.error('Error completing Google registration:', err);
        modalError.textContent = 'Connection error. Please try again.';
        modalError.style.display = 'block';
    });
}

function showModal() {
    const overlay = document.getElementById('google-role-modal-overlay');
    if (overlay) {
        overlay.classList.add('active');
    }
}

function showGlobalError(msg) {
    // Attempt to write to existing page error banners
    const pageError = document.querySelector('.auth-error');
    if (pageError) {
        pageError.textContent = msg;
        pageError.style.display = 'block';
    } else {
        // Find wrapper and inject a new error banner
        const formWrapper = document.querySelector('.auth-form-wrapper');
        if (formWrapper) {
            let newErr = document.getElementById('injected-auth-error');
            if (!newErr) {
                newErr = document.createElement('div');
                newErr.id = 'injected-auth-error';
                newErr.className = 'auth-error';
                const authHeader = formWrapper.querySelector('.auth-header');
                if (authHeader) {
                    authHeader.after(newErr);
                } else {
                    formWrapper.prepend(newErr);
                }
            }
            newErr.textContent = msg;
            newErr.style.display = 'block';
        } else {
            alert(msg);
        }
    }
}

function clearGlobalError() {
    const pageError = document.querySelector('.auth-error');
    if (pageError) {
        pageError.style.display = 'none';
        pageError.textContent = '';
    }
    const injectedErr = document.getElementById('injected-auth-error');
    if (injectedErr) {
        injectedErr.style.display = 'none';
        injectedErr.textContent = '';
    }
}

// Expose showModal for developer debugging/testing via the console
window.debugGoogleRoleModal = showModal;

