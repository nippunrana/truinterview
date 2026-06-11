<?php
// candidate-v2/resume_viewer.php - Resume Split View Editor
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

// Enforce Candidate role
requireAuth(['candidate']);
$user = getCurrentUser();

$path = $_GET['path'] ?? '';
if (empty($path)) {
    die("Error: No document path provided.");
}

$db = getDB();
$userId = $user['id'];

// Retrieve resume text version and name/metadata from database
// Search users.resume_path
$stmt = $db->prepare("SELECT resume_path FROM users WHERE id = :id");
$stmt->execute(['id' => $userId]);
$userFull = $stmt->fetch(PDO::FETCH_ASSOC);
$resumes = getCandidateResumes($userFull['resume_path'] ?? '');

$found = false;
$textVersion = '';
$detectedRole = '';
$needsHumanReview = false;
$ext = strtoupper(pathinfo($path, PATHINFO_EXTENSION));

foreach ($resumes as $r) {
    if ($r['path'] === $path) {
        $textVersion = $r['text_version'] ?? '';
        $detectedRole = $r['detected_role'] ?? 'Resume';
        $needsHumanReview = !empty($r['needs_human_review']);
        $found = true;
        break;
    }
}

if (!$found) {
    // Search candidate_profiles
    $stmt = $db->prepare("SELECT * FROM candidate_profiles WHERE user_id = :user_id AND optimized_resume_path = :path");
    $stmt->execute(['user_id' => $userId, 'path' => $path]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($profile) {
        $textVersion = $profile['text_version'] ?? '';
        $detectedRole = $profile['role_title'] ?? 'Role Resume';
        $needsHumanReview = !empty($profile['needs_human_review']);
        $found = true;
    }
}

if (!$found) {
    die("Error: Resume document not found.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Verify Resume - TruInterview</title>
  <link rel="stylesheet" href="../assets/css/candidate-v2.css">
  <link rel="stylesheet" href="../assets/css/resume-viewer.css">
</head>
<body>

  <div class="viewer-layout">
    
    <header class="viewer-header">
      <div class="viewer-header-left">
        <a href="index.php" class="btn btn-outline" style="padding: 8px 12px; font-size: 0.8rem; border-radius: 8px;">
          <svg style="width: 16px; height: 16px; margin-right: 4px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
          </svg>
          Back to Hub
        </a>
        <div class="viewer-title-group">
          <span class="viewer-title"><?php echo htmlspecialchars($detectedRole); ?></span>
          <span class="viewer-subtitle">Verify and correct the AI-extracted text version</span>
        </div>
      </div>
      
      <div class="viewer-header-right">
        <?php if ($needsHumanReview): ?>
          <span style="font-size: 11px; padding: 4px 8px; border-radius: 6px; background: rgba(239, 68, 68, 0.1); color: var(--color-danger); font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
            <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
            Review Recommended
          </span>
        <?php else: ?>
          <span style="font-size: 11px; padding: 4px 8px; border-radius: 6px; background: rgba(16, 185, 129, 0.1); color: var(--color-success); font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
            <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            Extraction Clean
          </span>
        <?php endif; ?>
        
        <button id="save-btn" onclick="saveText()" class="btn btn-primary" style="padding: 8px 16px; font-size: 0.85rem; border-radius: 8px;">
          <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path>
          </svg>
          Save Changes
        </button>
      </div>
    </header>

    <div class="viewer-split-container">
      
      <!-- Left side: Doc Viewer -->
      <div class="viewer-preview-panel">
        <?php if ($ext === 'PDF'): ?>
          <iframe src="../<?php echo htmlspecialchars($path); ?>#toolbar=0&navpanes=0&view=FitW"></iframe>
        <?php else: ?>
          <div class="viewer-preview-fallback">
            <svg style="width: 48px; height: 48px;" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m.75 12l3 3m0 0l3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"></path>
            </svg>
            <h3>Preview Not Available</h3>
            <p>Browsers cannot render <?php echo htmlspecialchars($ext); ?> files directly. Please download the document to view the original formatting.</p>
            <a href="../<?php echo htmlspecialchars($path); ?>" download class="btn btn-outline" style="font-size: 0.8rem; padding: 8px 16px;">
              <svg style="width: 16px; height: 16px; margin-right: 4px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
              </svg>
              Download <?php echo htmlspecialchars($ext); ?>
            </a>
          </div>
        <?php endif; ?>
      </div>
      
      <!-- Right side: Text Editor -->
      <div class="viewer-editor-panel">
        <div class="editor-info-alert">
          <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
          <span>This plain text version is analyzed by our AI system for role optimization and interview preps. Ensure spelling, formatting, and details match your original resume correctly.</span>
        </div>
        
        <div class="editor-controls">
          <span class="editor-controls-title">Parsed Text Version</span>
          <label class="wordwrap-toggle">
            <input type="checkbox" id="wordwrap-checkbox" onchange="toggleWordWrap(this.checked)">
            <span>Word Wrap</span>
          </label>
        </div>
        
        <div class="editor-textarea-wrapper">
          <textarea id="editor-textarea" class="editor-textarea" spellcheck="false" placeholder="Paste or edit plain text resume here..."><?php echo htmlspecialchars($textVersion); ?></textarea>
        </div>
      </div>

    </div>

  </div>

  <div class="toast-container" id="toast-container"></div>

  <script>
    function saveText() {
        const saveBtn = document.getElementById('save-btn');
        const originalText = saveBtn.innerHTML;
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner"></span> Saving...';
        
        const text = document.getElementById('editor-textarea').value;
        const path = <?php echo json_encode($path); ?>;
        
        const formData = new FormData();
        formData.append('action', 'update_resume_text');
        formData.append('resume_path', path);
        formData.append('text_version', text);
        
        fetch('ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalText;
            if (data.success) {
                showToast('Text version updated successfully!', 'success');
            } else {
                showToast(data.message || 'Failed to update text version.', 'error');
            }
        })
        .catch(err => {
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalText;
            showToast('A network error occurred.', 'error');
        });
    }

    function toggleWordWrap(enabled) {
        const textarea = document.getElementById('editor-textarea');
        if (enabled) {
            textarea.classList.add('word-wrapped');
        } else {
            textarea.classList.remove('word-wrapped');
        }
    }

    function showToast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <svg style="width: 18px; height: 18px; color: ${type === 'success' ? 'var(--color-success)' : 'var(--color-danger)'};" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="${type === 'success' ? 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' : 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z'}"></path>
            </svg>
            <span>${message}</span>
        `;
        container.appendChild(toast);
        
        // Stagger enter animation
        setTimeout(() => {
            toast.style.opacity = '1';
        }, 10);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
  </script>
</body>
</html>
