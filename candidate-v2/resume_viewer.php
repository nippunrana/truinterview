<?php
// candidate-v2/resume_viewer.php - Resume Split View Editor
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

// Enforce role
requireAuth(['candidate', 'recruiter']);
$user = getCurrentUser();

$path = $_GET['path'] ?? '';
if (empty($path)) {
    die("Error: No document path provided.");
}

$db = getDB();
$userId = $user['id'];

$found = false;
$textVersion = '';
$detectedRole = '';
$needsHumanReview = false;
$ext = strtoupper(pathinfo($path, PATHINFO_EXTENSION));

if ($user['role'] === 'candidate') {
    // Retrieve resume text version and name/metadata from database
    // Search users.resume_path
    $stmt = $db->prepare("SELECT resume_path FROM users WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $userFull = $stmt->fetch(PDO::FETCH_ASSOC);
    $resumes = getCandidateResumes($userFull['resume_path'] ?? '');

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
} else if ($user['role'] === 'recruiter') {
    // Find candidate profile matching the path
    $stmt = $db->prepare("SELECT * FROM candidate_profiles WHERE optimized_resume_path = :path");
    $stmt->execute(['path' => $path]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$profile) {
        die("Error: Resume document not found.");
    }
    
    // Check access match
    $company = getRecruiterCompany($userId);
    if (!$company) {
        die("Access denied. No company profile found.");
    }
    
    // Get all company active links
    $stmtLinks = $db->prepare("SELECT * FROM interview_links WHERE company_id = :company_id AND status = 'active'");
    $stmtLinks->execute(['company_id' => $company['id']]);
    $companyLinks = $stmtLinks->fetchAll(PDO::FETCH_ASSOC);
    
    $hasMatch = false;
    foreach ($companyLinks as $pLink) {
        $profileSlug = preg_replace('/\s+/', '-', strtolower(trim($profile['role_title'])));
        $profileSlug = preg_replace('/[^a-zA-Z0-9\-]/', '', $profileSlug);
        $profileSlug = preg_replace('/-+/', '-', $profileSlug);
        $profileSlug = trim($profileSlug, '-');

        $isCategoryMatch = (!empty($profile['category_id']) && !empty($pLink['category_id']) && $profile['category_id'] === $pLink['category_id']);
        
        $pLinkSlug = preg_replace('/\s+/', '-', strtolower(trim($pLink['job_role'])));
        $pLinkSlug = preg_replace('/[^a-zA-Z0-9\-]/', '', $pLinkSlug);
        $pLinkSlug = preg_replace('/-+/', '-', $pLinkSlug);
        $pLinkSlug = trim($pLinkSlug, '-');
        
        $isRoleMatch = ($profileSlug === $pLinkSlug);
        
        if ($isCategoryMatch || $isRoleMatch) {
            $hasMatch = true;
            break;
        }
    }
    
    if (!$hasMatch) {
        die("Access denied. You do not have an active matching job for this candidate.");
    }
    
    $textVersion = $profile['text_version'] ?? '';
    $detectedRole = $profile['role_title'] ?? 'Role Resume';
    $needsHumanReview = !empty($profile['needs_human_review']);
    $found = true;
}

if (!$found) {
    die("Error: Resume document not found.");
}

function parseMarkdownToHtml($markdown) {
    $html = htmlspecialchars($markdown);
    
    // Headers (#, ##, ###)
    $html = preg_replace('/^# (.*?)$/m', '<h1 class="md-h1">$1</h1>', $html);
    $html = preg_replace('/^## (.*?)$/m', '<h2 class="md-h2">$1</h2>', $html);
    $html = preg_replace('/^### (.*?)$/m', '<h3 class="md-h3">$1</h3>', $html);
    
    // Bold (**text**)
    $html = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $html);
    
    // Unordered lists (- item or * item)
    $html = preg_replace('/^\* (.*?)$/m', '<li>$1</li>', $html);
    $html = preg_replace('/^- (.*?)$/m', '<li>$1</li>', $html);
    
    // Group list items and handle line breaks
    $blocks = explode("\n\n", $html);
    foreach ($blocks as &$block) {
        $block = trim($block);
        if ($block === '') continue;
        
        if (preg_match('/^<(h1|h2|h3|li)/', $block)) {
            if (strpos($block, '<li>') !== false) {
                $block = '<ul class="md-ul">' . $block . '</ul>';
            }
        } else {
            $block = '<p class="md-p">' . str_replace("\n", "<br>", $block) . '</p>';
        }
    }
    return implode("\n", $blocks);
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
  <?php if ($ext === 'DOCX'): ?>
    <!-- docx-preview dependencies -->
    <script src="https://unpkg.com/jszip/dist/jszip.min.js"></script>
    <script src="https://unpkg.com/docx-preview@0.1.15/dist/docx-preview.js"></script>
  <?php endif; ?>
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
        
        <?php if ($user['role'] === 'candidate'): ?>
        <button id="save-btn" onclick="saveText()" class="btn btn-primary" style="padding: 8px 16px; font-size: 0.85rem; border-radius: 8px;">
          <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path>
          </svg>
          Save Changes
        </button>
        <?php endif; ?>
      </div>
    </header>

    <div class="viewer-split-container">
      
      <!-- Left side: Doc Viewer -->
      <div class="viewer-preview-panel">
        <?php if ($ext === 'PDF'): ?>
          <iframe src="../<?php echo htmlspecialchars($path); ?>#toolbar=0&navpanes=0&view=FitW"></iframe>
        <?php elseif ($ext === 'MD'): 
          $fullPath = __DIR__ . '/../' . $path;
          $rawMarkdown = file_exists($fullPath) ? file_get_contents($fullPath) : '';
        ?>
          <div class="viewer-markdown-preview">
            <?php echo parseMarkdownToHtml($rawMarkdown); ?>
          </div>
        <?php elseif ($ext === 'DOCX'): ?>
          <div id="docx-preview-container" style="height: 100%; overflow: auto; background: #fff; padding: 20px; box-sizing: border-box;">
            <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: var(--color-text-secondary); font-size: var(--text-sm);">
              <span class="spinner" style="border-top-color: var(--color-brand-primary); margin-right: 8px;"></span> Loading Document Preview...
            </div>
          </div>
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
          <div class="line-numbers" id="line-numbers">1</div>
          <textarea id="editor-textarea" class="editor-textarea" spellcheck="false" placeholder="Paste or edit plain text resume here..." onscroll="syncScroll()" oninput="updateLineNumbers()" <?php if ($user['role'] !== 'candidate') echo 'readonly'; ?>><?php echo htmlspecialchars($textVersion); ?></textarea>
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
        const lineNumbers = document.getElementById('line-numbers');
        if (enabled) {
            textarea.classList.add('word-wrapped');
            lineNumbers.style.display = 'none';
        } else {
            textarea.classList.remove('word-wrapped');
            lineNumbers.style.display = 'block';
            updateLineNumbers();
        }
    }

    function updateLineNumbers() {
        const textarea = document.getElementById('editor-textarea');
        const lineNumbers = document.getElementById('line-numbers');
        if (!textarea || !lineNumbers) return;
        const lines = textarea.value.split('\n');
        const lineCount = Math.max(1, lines.length);
        
        let html = '';
        for (let i = 1; i <= lineCount; i++) {
            html += i + '\n';
        }
        lineNumbers.textContent = html;
        syncScroll();
    }

    function syncScroll() {
        const textarea = document.getElementById('editor-textarea');
        const lineNumbers = document.getElementById('line-numbers');
        if (!textarea || !lineNumbers) return;
        lineNumbers.scrollTop = textarea.scrollTop;
    }

    // Initialize line numbers on page load
    document.addEventListener('DOMContentLoaded', () => {
        updateLineNumbers();
        
        <?php if ($ext === 'DOCX'): ?>
        const docxPath = <?php echo json_encode('../' . $path); ?>;
        fetch(docxPath)
            .then(response => {
                if (!response.ok) throw new Error('Failed to fetch document');
                return response.arrayBuffer();
            })
            .then(arrayBuffer => {
                const container = document.getElementById('docx-preview-container');
                container.innerHTML = ''; // Clear loading screen
                docx.renderAsync(arrayBuffer, container)
                    .catch(err => {
                        console.error('Error rendering DOCX:', err);
                        showDocxError();
                    });
            })
            .catch(err => {
                console.error(err);
                showDocxError();
            });
        <?php endif; ?>
    });

    <?php if ($ext === 'DOCX'): ?>
    function showDocxError() {
        const container = document.getElementById('docx-preview-container');
        container.innerHTML = `
            <div class="viewer-preview-fallback">
                <svg style="width: 48px; height: 48px;" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m.75 12l3 3m0 0l3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"></path>
                </svg>
                <h3>Render Failed</h3>
                <p>Unable to preview this DOCX file. Please download it directly.</p>
                <a href="../<?php echo htmlspecialchars($path); ?>" download class="btn btn-outline" style="font-size: 0.8rem; padding: 8px 16px;">
                  Download DOCX
                </a>
            </div>
        `;
    }
    <?php endif; ?>

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
