<?php
// candidate-v2/optimized_resume_viewer.php - Side-by-side Resume Comparison
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

$stmt = $db->prepare("SELECT resume_path FROM users WHERE id = :id");
$stmt->execute(['id' => $userId]);
$userFull = $stmt->fetch(PDO::FETCH_ASSOC);
$resumes = getCandidateResumes($userFull['resume_path'] ?? '');

$optimizedResume = null;
$originalResume = null;

// 1. Find the optimized resume by path
foreach ($resumes as $r) {
    if ($r['path'] === $path) {
        $optimizedResume = $r;
        break;
    }
}

if (!$optimizedResume) {
    die("Error: Optimized resume not found.");
}

// 2. Find the original resume. 
// It should be the base resume being optimized. If the optimized resume has original_path, look for it.
if (!empty($optimizedResume['original_path'])) {
    foreach ($resumes as $r) {
        if ($r['path'] === $optimizedResume['original_path']) {
            $originalResume = $r;
            break;
        }
    }
}

// Fallback 1: look for any base resume that is not the optimized one
if (!$originalResume) {
    foreach ($resumes as $r) {
        if (!empty($r['is_base']) && $r['path'] !== $path) {
            $originalResume = $r;
            break;
        }
    }
}

// Fallback 2: get the oldest resume
if (!$originalResume && !empty($resumes)) {
    $originalResume = $resumes[count($resumes) - 1];
}

$changes = $optimizedResume['optimization_changes'] ?? [];
$optimizedText = $optimizedResume['text_version'] ?? '';

// Custom Markdown Parser with Highlighting
function parseOptimizedMarkdown($markdown, $changes) {
    // Escape HTML first to prevent XSS
    $html = htmlspecialchars($markdown);
    
    // Inject highlights
    if (!empty($changes) && is_array($changes)) {
        foreach ($changes as $idx => $change) {
            $optText = $change['optimized_point'] ?? '';
            if (!empty($optText)) {
                $escapedOptText = htmlspecialchars($optText);
                // Simple string replacement for highlighting
                if (strpos($html, $escapedOptText) !== false) {
                    $html = str_replace($escapedOptText, '<mark class="highlight-change" data-idx="' . $idx . '" title="Optimized Content">' . $escapedOptText . '</mark>', $html);
                }
            }
        }
    }
    
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

// Function to render original markdown (borrowed from resume_viewer)
function parseMarkdownToHtml($markdown) {
    $html = htmlspecialchars($markdown);
    $html = preg_replace('/^# (.*?)$/m', '<h1 class="md-h1">$1</h1>', $html);
    $html = preg_replace('/^## (.*?)$/m', '<h2 class="md-h2">$1</h2>', $html);
    $html = preg_replace('/^### (.*?)$/m', '<h3 class="md-h3">$1</h3>', $html);
    $html = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $html);
    $html = preg_replace('/^\* (.*?)$/m', '<li>$1</li>', $html);
    $html = preg_replace('/^- (.*?)$/m', '<li>$1</li>', $html);
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

$origExt = strtoupper(pathinfo($originalResume['path'], PATHINFO_EXTENSION));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>View Optimized Resume - TruInterview</title>
  <link rel="stylesheet" href="../assets/css/candidate-v2.css">
  <link rel="stylesheet" href="../assets/css/resume-viewer.css">
  <link rel="stylesheet" href="../assets/css/optimized-viewer.css">
  <?php if ($origExt === 'DOCX'): ?>
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
          <span class="viewer-title">Comparison View</span>
          <span class="viewer-subtitle">Original Document vs. Optimized Version</span>
        </div>
      </div>
      
      <div class="viewer-header-right" style="display: flex; gap: 8px; align-items: center;">
        <a href="../<?php echo htmlspecialchars($optimizedResume['path']); ?>" download class="btn btn-outline" style="padding: 8px 16px; font-size: 0.85rem; border-radius: 8px; display: inline-flex; align-items: center; gap: 4px;">
          <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
          </svg>
          Download Optimized Markdown
        </a>
        <a href="export_pdf.php?path=<?php echo urlencode($path); ?>" target="_blank" class="btn btn-primary" style="padding: 8px 16px; font-size: 0.85rem; border-radius: 8px; display: inline-flex; align-items: center; gap: 4px;">
          <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
          </svg>
          Download PDF
        </a>
      </div>
    </header>

    <div class="viewer-split-container">
      
      <!-- Left side: Original Doc Viewer -->
      <div class="viewer-preview-panel">
        <div style="background: var(--color-bg-subtle); padding: 8px 16px; border-bottom: 1px solid var(--color-border); font-weight: 700; font-size: 11px; text-transform: uppercase; color: var(--color-text-secondary); letter-spacing: 0.05em; display: flex; justify-content: space-between;">
            <span>Original Document</span>
            <span><?php echo htmlspecialchars($origExt); ?></span>
        </div>
        <?php if ($origExt === 'PDF'): ?>
          <iframe src="../<?php echo htmlspecialchars($originalResume['path']); ?>#toolbar=0&navpanes=0&view=FitW"></iframe>
        <?php elseif ($origExt === 'MD'): 
          $fullPath = __DIR__ . '/../' . $originalResume['path'];
          $rawMarkdown = file_exists($fullPath) ? file_get_contents($fullPath) : ($originalResume['text_version'] ?? '');
        ?>
          <div class="viewer-markdown-preview">
            <?php echo parseMarkdownToHtml($rawMarkdown); ?>
          </div>
        <?php elseif ($origExt === 'DOCX'): ?>
          <div id="docx-preview-container" style="height: 100%; overflow: auto; background: #fff; padding: 20px; box-sizing: border-box;">
            <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: var(--color-text-secondary); font-size: var(--text-sm);">
              <span class="spinner" style="border-top-color: var(--color-brand-primary); margin-right: 8px;"></span> Loading Original Document...
            </div>
          </div>
        <?php else: ?>
          <div class="viewer-preview-fallback">
            <svg style="width: 48px; height: 48px;" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m.75 12l3 3m0 0l3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"></path>
            </svg>
            <h3>Preview Not Available</h3>
            <p>Browsers cannot render <?php echo htmlspecialchars($origExt); ?> files directly. Please download the document to view the original formatting.</p>
          </div>
        <?php endif; ?>
      </div>
      
      <!-- Right side: Optimized Panel -->
      <div class="viewer-optimized-panel">
        
        <?php if (!empty($changes)): ?>
        <div class="rationale-accordion" id="rationale-accordion">
            <div class="rationale-header" onclick="toggleAccordion()">
                <div class="rationale-title">
                    <svg style="width: 18px; height: 18px; color: var(--color-brand-primary);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path></svg>
                    AI Optimization Rationale
                    <span class="rationale-badge"><?php echo count($changes); ?> Changes</span>
                </div>
                <svg class="rationale-toggle-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path>
                </svg>
            </div>
            <div class="rationale-content">
                <ul class="rationale-list">
                    <?php foreach ($changes as $idx => $change): ?>
                    <li class="rationale-item" data-idx="<?php echo $idx; ?>">
                        <div>
                            <div class="rationale-col-title">Original Text</div>
                            <div class="rationale-original-text"><?php echo htmlspecialchars($change['original_point']); ?></div>
                        </div>
                        <div>
                            <div class="rationale-col-title">Optimized Revision</div>
                            <div class="rationale-optimized-text"><?php echo htmlspecialchars($change['optimized_point']); ?></div>
                            <div class="rationale-reason">
                                <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                <span><?php echo htmlspecialchars($change['reasoning']); ?></span>
                            </div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <div class="optimized-markdown-container viewer-markdown-preview">
            <?php echo parseOptimizedMarkdown($optimizedText, $changes); ?>
        </div>
      </div>

    </div>

  </div>

  <script>
    function toggleAccordion() {
        const accordion = document.getElementById('rationale-accordion');
        if (accordion) {
            accordion.classList.toggle('is-open');
        }
    }

    // Initialize docx preview if needed
    document.addEventListener('DOMContentLoaded', () => {
        <?php if ($origExt === 'DOCX'): ?>
        const docxPath = <?php echo json_encode('../' . $originalResume['path']); ?>;
        fetch(docxPath)
            .then(response => {
                if (!response.ok) throw new Error('Failed to fetch document');
                return response.arrayBuffer();
            })
            .then(arrayBuffer => {
                const container = document.getElementById('docx-preview-container');
                container.innerHTML = ''; 
                docx.renderAsync(arrayBuffer, container)
                    .catch(err => {
                        console.error('Error rendering DOCX:', err);
                    });
            })
            .catch(err => {
                console.error(err);
            });
        <?php endif; ?>

        // Highlight interaction
        const marks = document.querySelectorAll('mark.highlight-change');
        marks.forEach(mark => {
            mark.addEventListener('click', (e) => {
                const idx = mark.getAttribute('data-idx');
                const accordion = document.getElementById('rationale-accordion');
                if (accordion && !accordion.classList.contains('is-open')) {
                    accordion.classList.add('is-open');
                }
                const item = document.querySelector('.rationale-item[data-idx="' + idx + '"]');
                if (item) {
                    item.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    item.style.backgroundColor = 'rgba(16, 185, 129, 0.05)';
                    setTimeout(() => {
                        item.style.transition = 'background-color 0.5s';
                        item.style.backgroundColor = 'transparent';
                    }, 2000);
                }
            });
        });
    });
  </script>
</body>
</html>
