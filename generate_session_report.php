<?php
// generate_session_report.php
// CLI tool to compile a comprehensive debug and transcript report for any interview session.
// Usage: php generate_session_report.php <session_id>

require_once __DIR__ . '/db.php';

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

$sessionId = $argv[1] ?? '';
if (empty($sessionId)) {
    // Attempt to auto-detect the latest session
    $db = getDB();
    $stmt = $db->query("SELECT id FROM sessions ORDER BY started_at DESC LIMIT 1");
    $sessionId = $stmt->fetchColumn();
    if (!$sessionId) {
        die("Usage: php generate_session_report.php <session_id>\nNo sessions found in database.\n");
    }
    echo "No session ID specified. Auto-detecting latest session: {$sessionId}\n";
}

$db = getDB();

// 1. Fetch Session Details
$stmt = $db->prepare("SELECT * FROM sessions WHERE id = :id");
$stmt->execute(['id' => $sessionId]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    die("Error: Session with ID '{$sessionId}' not found.\n");
}

// 2. Fetch Transcripts
$stmt = $db->prepare("SELECT * FROM transcripts WHERE session_id = :session_id ORDER BY id ASC");
$stmt->execute(['session_id' => $sessionId]);
$transcripts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch Proctor Alerts
$stmt = $db->prepare("SELECT * FROM proctor_alerts WHERE session_id = :session_id ORDER BY id ASC");
$stmt->execute(['session_id' => $sessionId]);
$alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Locate completions logs in uploads/ai_debug/
$aiDebugDir = __DIR__ . '/uploads/ai_debug';
$relatedLogs = [];
$sessionStart = strtotime($session['started_at']);
$sessionEnd = !empty($session['completed_at']) ? strtotime($session['completed_at']) : ($sessionStart + 3600);

if (is_dir($aiDebugDir)) {
    $dirs = scandir($aiDebugDir);
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;
        $path = $aiDebugDir . '/' . $dir;
        if (is_dir($path)) {
            // Parse directory timestamp (format: YYYYMMDD_HHMMSS)
            if (preg_match('/^(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/', $dir, $m)) {
                $logTimeStr = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]} UTC";
                $logTimestamp = strtotime($logTimeStr);
                
                // If the log timestamp falls within the session active window (with a buffer)
                if ($logTimestamp >= ($sessionStart - 120) && $logTimestamp <= ($sessionEnd + 120)) {
                    $reqFile = $path . '/request.json';
                    $respFile = $path . '/response.json';
                    if (file_exists($reqFile)) {
                        $reqContent = file_get_contents($reqFile);
                        $reqData = json_decode($reqContent, true);
                        $respData = file_exists($respFile) ? json_decode(file_get_contents($respFile), true) : null;
                        
                        $relatedLogs[] = [
                            'folder' => $dir,
                            'timestamp' => $reqData['timestamp'] ?? $logTimeStr,
                            'task' => $reqData['task'] ?? '',
                            'model' => $reqData['model'] ?? '',
                            'messages' => $reqData['messages'] ?? [],
                            'response' => $respData['response'] ?? null,
                            'error' => $respData['error'] ?? null
                        ];
                    }
                }
            }
        }
    }
}

// Sort related logs by timestamp
usort($relatedLogs, function($a, $b) {
    return strcmp($a['timestamp'], $b['timestamp']);
});

// 5. Generate Report Markdown
$report = [];
$report[] = "# Interview Session Diagnostics & Debug Report";
$report[] = "";
$report[] = "## Session Summary";
$report[] = "- **Session ID:** `{$session['id']}`";
$report[] = "- **Candidate:** **{$session['candidate_name']}** ({$session['email']})";
$report[] = "- **Status:** `{$session['current_status']}`";
$report[] = "- **Started At:** {$session['started_at']}";
$report[] = "- **Completed At:** " . ($session['completed_at'] ?? 'N/A');
$report[] = "- **MCQ Preference:** `{$session['mcq_preference']}`";
$report[] = "- **Warnings Count:** {$session['conduct_warnings']}/2";
$report[] = "- **Closure Reason:** " . ($session['closure_reason'] ?? 'None');
$report[] = "";

$report[] = "## Proctoring Integrity Alerts";
if (empty($alerts)) {
    $report[] = "*No proctoring integrity alerts were triggered during this session.*";
} else {
    $report[] = "| ID | Time | Alert Type | Severity | AI Confirmed | Verification Details |";
    $report[] = "|---|---|---|---|---|---|";
    foreach ($alerts as $alert) {
        $confirmed = $alert['ai_confirmed'] ? "Yes" : "No";
        $report[] = "| `{$alert['id']}` | " . ($alert['created_at'] ?? '') . " | `{$alert['alert_type']}` | `{$alert['severity']}` | **{$confirmed}** | " . str_replace("\n", " ", $alert['ai_verdict'] ?? '') . " |";
    }
}
$report[] = "";

$report[] = "## Dialogue & Event Timeline";
if (empty($transcripts)) {
    $report[] = "*No dialogue or system events logged in this session.*";
} else {
    $report[] = "| ID | Timestamp | Speaker | Message |";
    $report[] = "|---|---|---|---|";
    foreach ($transcripts as $t) {
        $speakerBadge = $t['speaker'];
        if ($t['speaker'] === 'SYSTEM') {
            $speakerBadge = "⚙️ SYSTEM";
        } elseif ($t['speaker'] === 'USER') {
            $speakerBadge = "👤 CANDIDATE";
        } elseif ($t['speaker'] === 'AGENT') {
            $speakerBadge = "🤖 AI INTERVIEWER";
        }
        $report[] = "| `{$t['id']}` | {$t['timestamp']} | {$speakerBadge} | " . str_replace("\n", "<br>", htmlspecialchars($t['message'])) . " |";
    }
}
$report[] = "";

$report[] = "## Raw LLM Completions Traces";
if (empty($relatedLogs)) {
    $report[] = "*No raw LLM completions logs found for this session.*";
} else {
    foreach ($relatedLogs as $index => $log) {
        $num = $index + 1;
        $report[] = "### Trace #{$num}: `{$log['folder']}`";
        $report[] = "- **Timestamp:** {$log['timestamp']}";
        $report[] = "- **Task:** `{$log['task']}`";
        $report[] = "- **Model:** `{$log['model']}`";
        if ($log['error']) {
            $report[] = "- <span style='color:red;'>**API Error:** {$log['error']}</span>";
        }
        $report[] = "";
        $report[] = "#### Request Messages Sent to Model";
        $report[] = "```json";
        $report[] = json_encode($log['messages'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $report[] = "```";
        $report[] = "";
        $report[] = "#### Response Received from Model";
        $report[] = "```json";
        $report[] = json_encode($log['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $report[] = "```";
        $report[] = "---";
        $report[] = "";
    }
}

// Write report to uploads/ai_debug/
$reportFilename = "session_" . $sessionId . "_debug_report.md";
$reportPath = __DIR__ . '/uploads/ai_debug/' . $reportFilename;
file_put_contents($reportPath, implode("\n", $report));

echo "\n==================================================\n";
echo "SUCCESS: Diagnostic debug report generated!\n";
echo "File Path: {$reportPath}\n";
echo "==================================================\n\n";
