<?php
// candidate-v2/export_pdf.php - Print-friendly PDF generator for optimized resumes
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
foreach ($resumes as $r) {
    if ($r['path'] === $path) {
        $optimizedResume = $r;
        break;
    }
}

if (!$optimizedResume) {
    die("Error: Optimized resume not found.");
}

$optimizedText = $optimizedResume['text_version'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Export Resume - TruInterview</title>
  <!-- Google Fonts for Premium Typography -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  
  <style>
    :root {
      --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      --color-primary: #0f172a; /* Slate 900 */
      --color-secondary: #475569; /* Slate 600 */
      --color-accent: #2563eb; /* Professional Blue */
      --color-border: #cbd5e1; /* Slate 300 */
      
      /* Guide UI Colors */
      --color-guide-bg: rgba(15, 23, 42, 0.9);
      --color-guide-text: #f8fafc;
      --color-brand-purple: #6366f1;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      padding: 0;
      background-color: #f1f5f9;
      font-family: var(--font-sans);
      color: var(--color-primary);
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }

    /* Floating Interactive Banner (Guide) */
    .instruction-banner {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      background: var(--color-guide-bg);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      color: var(--color-guide-text);
      z-index: 9999;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
      padding: 16px 24px;
    }

    .instruction-container {
      max-width: 1100px;
      margin: 0 auto;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 24px;
    }

    .instruction-info {
      flex: 1;
    }

    .instruction-title {
      font-size: 1.05rem;
      font-weight: 700;
      margin: 0 0 6px 0;
      display: flex;
      align-items: center;
      gap: 8px;
      color: #e2e8f0;
    }

    .instruction-steps {
      display: flex;
      flex-wrap: wrap;
      gap: 16px 24px;
      margin: 0;
      padding: 0;
      list-style: none;
      font-size: 0.82rem;
      color: #94a3b8;
    }

    .instruction-steps li {
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .instruction-steps li strong {
      color: #f1f5f9;
      font-weight: 600;
    }

    .instruction-steps .icon-bullet {
      background: rgba(99, 102, 241, 0.2);
      color: #818cf8;
      border-radius: 50%;
      width: 18px;
      height: 18px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 0.7rem;
      font-weight: bold;
    }

    .instruction-actions {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .btn {
      font-family: var(--font-sans);
      font-size: 0.85rem;
      font-weight: 600;
      padding: 10px 18px;
      border-radius: 8px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s ease;
      text-decoration: none;
    }

    .btn-primary {
      background-color: var(--color-brand-purple);
      color: #ffffff;
      border: none;
      box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
    }

    .btn-primary:hover {
      background-color: #4f46e5;
      transform: translateY(-1px);
    }

    .btn-secondary {
      background-color: transparent;
      color: #cbd5e1;
      border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .btn-secondary:hover {
      background-color: rgba(255, 255, 255, 0.05);
      color: #ffffff;
    }

    /* Page Setup for Screen Preview */
    .page-wrapper {
      padding-top: 110px; /* Space for the floating header */
      padding-bottom: 40px;
      display: flex;
      justify-content: center;
    }

    .page-sheet {
      background: #ffffff;
      width: 210mm; /* A4 Width */
      min-height: 297mm; /* A4 Height */
      padding: 20mm 20mm;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08), 0 1px 3px rgba(0, 0, 0, 0.02);
      border-radius: 6px;
      box-sizing: border-box;
      position: relative;
    }

    /* Resume Styling & Layout */
    .resume-container {
      color: var(--color-primary);
      line-height: 1.45;
      font-size: 10.5pt;
    }

    /* Dynamic header wrapped via JavaScript */
    .resume-pdf-header {
      text-align: center;
      margin-bottom: 22px;
      border-bottom: 2px solid var(--color-primary);
      padding-bottom: 14px;
    }

    .resume-pdf-header h1 {
      font-size: 26pt;
      font-weight: 800;
      margin: 0 0 6px 0;
      letter-spacing: -0.03em;
      color: #0f172a;
      text-transform: uppercase;
    }

    .resume-pdf-header p {
      margin: 4px 0;
      font-size: 9.5pt;
      color: var(--color-secondary);
    }

    .resume-pdf-header p strong {
      font-size: 12pt;
      color: var(--color-primary);
      letter-spacing: 0.05em;
      font-weight: 600;
      text-transform: uppercase;
      display: inline-block;
      margin-bottom: 2px;
    }

    /* Section Headings */
    .resume-container h3 {
      font-size: 11pt;
      font-weight: 700;
      color: #0f172a;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      border-bottom: 1.5px solid var(--color-border);
      padding-bottom: 3px;
      margin-top: 20px;
      margin-bottom: 10px;
    }

    /* Paragraphs and job details */
    .resume-container p {
      margin: 0 0 8px 0;
    }

    /* Lists */
    .resume-container ul {
      margin: 0 0 10px 0;
      padding-left: 18px;
      list-style-type: square;
    }

    .resume-container li {
      margin-bottom: 4px;
      line-height: 1.4;
      color: #334155; /* Slate 700 */
    }

    /* Text formatting matches */
    .resume-container strong {
      color: #0f172a;
    }

    .resume-container em {
      color: var(--color-secondary);
      font-style: italic;
    }

    /* Print styling overrides */
    @media print {
      body {
        background-color: #ffffff !important;
        color: #000000 !important;
      }

      .no-print {
        display: none !important;
      }

      .page-wrapper {
        padding: 0 !important;
        margin: 0 !important;
      }

      .page-sheet {
        width: 100% !important;
        min-height: auto !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        padding: 0 !important;
        margin: 0 !important;
      }

      @page {
        size: A4;
        margin: 15mm; /* Clean native margins */
      }
    }
  </style>

  <!-- Marked Markdown Parser CDN -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/marked/4.3.0/marked.min.js"></script>
</head>
<body>

  <!-- FLOATING INSTRUCTION BANNER (no-print) -->
  <div class="instruction-banner no-print">
    <div class="instruction-container">
      <div class="instruction-info">
        <h4 class="instruction-title">
          <svg style="width: 18px; height: 18px; color: #818cf8;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
          </svg>
          PDF Download & Save Instructions
        </h4>
        <ul class="instruction-steps">
          <li>
            <span class="icon-bullet">1</span>
            Set Destination to <strong>Save as PDF</strong>
          </li>
          <li>
            <span class="icon-bullet">2</span>
            Enable <strong>Background graphics</strong>
          </li>
          <li>
            <span class="icon-bullet">3</span>
            Disable <strong>Headers and footers</strong>
          </li>
          <li>
            <span class="icon-bullet">4</span>
            Set Margins to <strong>Default</strong>
          </li>
        </ul>
      </div>
      
      <div class="instruction-actions">
        <button onclick="window.close()" class="btn btn-secondary">
          Go Back
        </button>
        <button onclick="window.print()" class="btn btn-primary">
          <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
          </svg>
          Save PDF
        </button>
      </div>
    </div>
  </div>

  <!-- PAGE PREVIEW CONTAINER -->
  <div class="page-wrapper">
    <div class="page-sheet">
      <div id="resume-target" class="resume-container">
        <!-- Rendered markdown content will be inserted here -->
      </div>
    </div>
  </div>

  <!-- Raw Markdown Container -->
  <script id="markdown-raw" type="text/plain"><?php echo $optimizedText; ?></script>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const markdown = document.getElementById('markdown-raw').textContent;
      
      // Parse markdown to HTML
      let html = marked.parse(markdown);
      
      // Load into target container
      const target = document.getElementById('resume-target');
      target.innerHTML = html;
      
      // Structurally identify and format the header (everything before the first h3)
      const firstH3 = target.querySelector('h2, h3');
      if (firstH3) {
        const headerWrapper = document.createElement('header');
        headerWrapper.className = 'resume-pdf-header';
        
        while (target.firstChild && target.firstChild !== firstH3) {
          headerWrapper.appendChild(target.firstChild);
        }
        target.insertBefore(headerWrapper, firstH3);
      }
      
      // Trigger native print setup auto-load with a short delay for font rendering
      setTimeout(() => {
        window.print();
      }, 350);
    });
  </script>
</body>
</html>
