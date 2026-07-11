<?php
// optimizer_service.php - Resume Optimizer Backend Services

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai_service.php';

/**
 * Extract plain text representation from a resume file.
 * For PDF files, calls the vision model once to convert it to clean markdown.
 */
function optimizer_extract_text($filePath, $ext) {
    if (!file_exists($filePath)) {
        throw new Exception("Resume file not found at path: " . $filePath);
    }

    if ($ext === 'pdf') {
        $prompt = "<context>\n" .
                  "You are a precision data-extraction engine. Your task is to convert a visual resume document into clean, structurally identical Markdown.\n" .
                  "</context>\n" .
                  "<task>\n" .
                  "Transcribe the provided document exactly into Markdown. Preserve all headings, dates, experience details, and lists as written.\n" .
                  "</task>\n" .
                  "<constraints>\n" .
                  "- Do NOT summarize, reword, or omit any professional experience, education, or skills.\n" .
                  "- Ignore document headers, footers, and page numbers.\n" .
                  "- If a word or phrase is completely illegible, output `[ILLEGIBLE]` rather than guessing.\n" .
                  "</constraints>\n" .
                  "<output_format>\n" .
                  "Output ONLY valid Markdown text. Do not include conversational filler.\n" .
                  "</output_format>";

        $messages = [
            [
                "role" => "user",
                "content" => array_merge(
                    [["type" => "text", "text" => $prompt]],
                    pdfToContentParts($filePath)
                )
            ]
        ];

        return callAI($messages, 'pdf_extract');
    } elseif ($ext === 'docx') {
        return extractTextFromDocx($filePath);
    } elseif ($ext === 'doc') {
        return extractTextFromDoc($filePath);
    } else {
        return file_get_contents($filePath);
    }
}

/**
 * Phase 1: Reality Check (Blind Analysis)
 */
function optimizer_reality_check($resumeText) {
    $systemPrompt = "You are a professional technical recruiter. Analyze the provided resume text blindly (without any job description context) to identify the target role and seniority level that the resume currently conveys based on keywords, experience weighting, and accomplishments. Output a JSON object with 'target_role', 'seniority', and 'summary' keys.";

    $response = callAI([
        ["role" => "system", "content" => $systemPrompt],
        ["role" => "user", "content" => "Analyze this resume text and output the results:\n\n" . $resumeText]
    ], 'optimizer_analysis', [
        'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'result', 'schema' => [
            "type" => "object",
            "properties" => [
                "target_role" => ["type" => "string"],
                "seniority" => ["type" => "string"],
                "summary" => ["type" => "string"]
            ],
            "required" => ["target_role", "seniority", "summary"]
        ]]]
    ]);
    return json_decode($response, true);
}

/**
 * Phase 2: Gap Analysis & Skill Gathering
 */
function optimizer_gap_analysis($resumeText, $targetRole, $jobDescription) {
    $systemPrompt = "You are an expert recruiter. Compare the candidate's resume text against the target role and the job description. Identify up to 4 major missing skills, tools, methodologies, or experiences. For each missing item, write a direct question asking the candidate if they have experience with it, offering context on why it is important. Output a JSON object containing a 'gaps' array with keys: 'skill', 'importance', and 'question'.";

    $userContent = "Resume:\n" . $resumeText . "\n\nTarget Role: " . $targetRole . "\n\nJob Description:\n" . $jobDescription;

    $response = callAI([
        ["role" => "system", "content" => $systemPrompt],
        ["role" => "user", "content" => $userContent]
    ], 'optimizer_analysis', [
        'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'result', 'schema' => [
            "type" => "object",
            "properties" => [
                "gaps" => [
                    "type" => "array",
                    "items" => [
                        "type" => "object",
                        "properties" => [
                            "skill" => ["type" => "string"],
                            "importance" => ["type" => "string", "enum" => ["high", "medium"]],
                            "question" => ["type" => "string"]
                        ],
                        "required" => ["skill", "importance", "question"]
                    ]
                ]
            ],
            "required" => ["gaps"]
        ]]]
    ]);
    return json_decode($response, true);
}

/**
 * Phase 3: Extract Timeline Dates
 */
function optimizer_extract_dates($resumeText) {
    $systemPrompt = "Analyze the resume text and extract all professional experience entries. For each entry, extract the company name, role title, start date, end date, and the raw date string as written. Format start_date and end_date as YYYY-MM. If the job is current, use 'Present' for the end_date. If a date cannot be parsed, use null for start_date or end_date. Output a JSON object containing an 'experiences' array.";

    $response = callAI([
        ["role" => "system", "content" => $systemPrompt],
        ["role" => "user", "content" => $resumeText]
    ], 'optimizer_analysis', [
        'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'result', 'schema' => [
            "type" => "object",
            "properties" => [
                "experiences" => [
                    "type" => "array",
                    "items" => [
                        "type" => "object",
                        "properties" => [
                            "company" => ["type" => "string"],
                            "role" => ["type" => "string"],
                            "start_date" => ["type" => "string"],
                            "end_date" => ["type" => "string"],
                            "raw_date_string" => ["type" => "string"]
                        ],
                        "required" => ["company", "role", "start_date", "end_date", "raw_date_string"]
                    ]
                ]
            ],
            "required" => ["experiences"]
        ]]]
    ]);
    return json_decode($response, true);
}

/**
 * Phase 3 helper: Perform mathematical verification of extracted dates.
 */
function optimizer_verify_dates_math($experiencesData) {
    if (!isset($experiencesData['experiences']) || !is_array($experiencesData['experiences'])) {
        return [
            'total_experience_months' => 0,
            'total_experience_formatted' => '0 months',
            'overlaps' => [],
            'warnings' => [],
            'experience_details' => []
        ];
    }

    $now = strtotime('2026-06-10'); // Freeze time to current mock local time
    $intervals = [];
    $warnings = [];
    $overlaps = [];
    $experienceDetails = [];

    foreach ($experiencesData['experiences'] as $index => $exp) {
        $company = $exp['company'] ?? 'Unknown Company';
        $role = $exp['role'] ?? 'Unknown Role';
        $rawDate = $exp['raw_date_string'] ?? '';

        $startDateStr = $exp['start_date'] ?? '';
        $endDateStr = $exp['end_date'] ?? '';

        $startTs = null;
        $endTs = null;

        // Parse start date
        if (!empty($startDateStr)) {
            $dt = DateTime::createFromFormat('Y-m', trim($startDateStr));
            if ($dt) {
                $startTs = $dt->getTimestamp();
            } else {
                $startTs = strtotime($startDateStr);
            }
        }

        // Parse end date
        if (strtolower(trim($endDateStr)) === 'present') {
            $endTs = $now;
        } elseif (!empty($endDateStr)) {
            $dt = DateTime::createFromFormat('Y-m', trim($endDateStr));
            if ($dt) {
                $endTs = $dt->getTimestamp();
            } else {
                $endTs = strtotime($endDateStr);
            }
        }

        if (!$startTs || !$endTs) {
            $warnings[] = "Could not parse dates for {$role} at {$company} ('{$rawDate}').";
            $experienceDetails[] = [
                'company' => $company,
                'role' => $role,
                'raw_date_string' => $rawDate,
                'duration_months' => 0,
                'duration_formatted' => 'Unparseable'
            ];
            continue;
        }

        if ($startTs > $endTs) {
            $warnings[] = "Start date is after end date for {$role} at {$company} ('{$rawDate}').";
            // Swap to calculate
            $temp = $startTs;
            $startTs = $endTs;
            $endTs = $temp;
        }

        // Calculate months
        $startDt = new DateTime();
        $startDt->setTimestamp($startTs);
        $endDt = new DateTime();
        $endDt->setTimestamp($endTs);
        $diff = $startDt->diff($endDt);
        $months = ($diff->y * 12) + $diff->m;
        if ($diff->d > 15) {
            $months += 1;
        }
        $months = max(1, $months);

        $intervals[] = [
            'start' => $startTs,
            'end' => $endTs,
            'company' => $company,
            'role' => $role,
            'index' => $index
        ];

        // Format job duration
        $jobYears = floor($months / 12);
        $jobMonths = $months % 12;
        $jobDurationFormatted = "";
        if ($jobYears > 0) {
            $jobDurationFormatted .= $jobYears . " yr" . ($jobYears > 1 ? 's' : '') . " ";
        }
        $jobDurationFormatted .= $jobMonths . " mo" . ($jobMonths != 1 ? 's' : '');

        $experienceDetails[] = [
            'company' => $company,
            'role' => $role,
            'raw_date_string' => $rawDate,
            'start_date' => date('Y-m', $startTs),
            'end_date' => $endDateStr === 'Present' ? 'Present' : date('Y-m', $endTs),
            'duration_months' => $months,
            'duration_formatted' => trim($jobDurationFormatted)
        ];
    }

    // 1. Calculate Overlaps
    $numInt = count($intervals);
    for ($i = 0; $i < $numInt; $i++) {
        for ($j = $i + 1; $j < $numInt; $j++) {
            $a = $intervals[$i];
            $b = $intervals[$j];

            // Check intersection
            if ($a['start'] < $b['end'] && $b['start'] < $a['end']) {
                $overlapStart = max($a['start'], $b['start']);
                $overlapEnd = min($a['end'], $b['end']);
                
                $startDt = new DateTime();
                $startDt->setTimestamp($overlapStart);
                $endDt = new DateTime();
                $endDt->setTimestamp($overlapEnd);
                $diff = $startDt->diff($endDt);
                $overlapMonths = ($diff->y * 12) + $diff->m;
                if ($diff->d > 15) {
                    $overlapMonths += 1;
                }
                
                if ($overlapMonths > 0) {
                    $overlaps[] = [
                        'job1' => "{$a['role']} at {$a['company']}",
                        'job2' => "{$b['role']} at {$b['company']}",
                        'months' => $overlapMonths
                    ];
                }
            }
        }
    }

    // 2. Calculate Total Experience (Excluding Overlaps)
    $totalMonths = 0;
    if (!empty($intervals)) {
        // Sort intervals by start timestamp
        usort($intervals, function($x, $y) {
            return $x['start'] - $y['start'];
        });

        // Merge intervals
        $merged = [];
        foreach ($intervals as $interval) {
            if (empty($merged)) {
                $merged[] = [
                    'start' => $interval['start'],
                    'end' => $interval['end']
                ];
            } else {
                $lastIdx = count($merged) - 1;
                if ($interval['start'] <= $merged[$lastIdx]['end']) {
                    // Overlap, extend the end date
                    $merged[$lastIdx]['end'] = max($merged[$lastIdx]['end'], $interval['end']);
                } else {
                    $merged[] = [
                        'start' => $interval['start'],
                        'end' => $interval['end']
                    ];
                }
            }
        }

        // Sum durations of merged non-overlapping intervals
        foreach ($merged as $m) {
            $startDt = new DateTime();
            $startDt->setTimestamp($m['start']);
            $endDt = new DateTime();
            $endDt->setTimestamp($m['end']);
            $diff = $startDt->diff($endDt);
            $mMonths = ($diff->y * 12) + $diff->m;
            if ($diff->d > 15) {
                $mMonths += 1;
            }
            $totalMonths += max(1, $mMonths);
        }
    }

    // Format total experience
    $totalYears = floor($totalMonths / 12);
    $remMonths = $totalMonths % 12;
    $totalExpFormatted = "";
    if ($totalYears > 0) {
        $totalExpFormatted .= $totalYears . " year" . ($totalYears > 1 ? 's' : '') . " ";
    }
    if ($remMonths > 0 || $totalMonths == 0) {
        $totalExpFormatted .= $remMonths . " month" . ($remMonths != 1 ? 's' : '');
    }

    return [
        'total_experience_months' => $totalMonths,
        'total_experience_formatted' => trim($totalExpFormatted),
        'overlaps' => $overlaps,
        'warnings' => $warnings,
        'experience_details' => $experienceDetails
    ];
}

/**
 * Phase 4: Final Rewrite & Deep Analysis
 */
function optimizer_generate_rewrite($resumeText, $targetRole, $jobDescription, $gapAnswers, $verifiedDatesData) {
    $systemPrompt = "You are a world-class resume optimizer and technical career consultant. Your goal is to optimize a candidate's resume for Applicant Tracking Systems (ATS) and human recruiters.

CORE PILLARS:
1. ATS Optimization (Machine Readability):
   - Output clean Markdown headers (Work Experience, Education, Skills).
   - Use standard sans-serif structure. No charts, graphs, tables, or complex visuals.
   - Naturally integrate key terms from the job description (e.g. acronyms + long-form like 'Search Engine Optimization (SEO)').
2. Human-Centric Recruiter Impact (The 5-Second Skim):
   - Rewrite work bullet points using the Google XYZ Formula: 'Accomplished [X] as measured by [Y], by doing [Z]'.
   - Focus on business momentum, impact, and revenue metrics ('sizzle') instead of basic duties ('silverware').
   - Address employment gaps or title modifications honestly and clearly.
3. Appropriate Role Name Detection:
   - Study the target role entered by the user, the job description, and the candidate's actual work experience.
   - Refine the user's target role into a standardized, recognizable, and concise industry-standard job title (e.g., \"Senior Frontend Engineer\" instead of a raw target role like \"react developer\").
   - Follow these strict rules for generating the \"ai_refined_role\":
     a. Keep it concise (2-4 words maximum). Avoid long/bloated hybrid titles (e.g., do not output \"Senior Prompt Engineer & AI Automation Architect\", instead prefer a singular focus like \"Senior Prompt Engineer\" or \"AI Automation Engineer\" depending on the primary target and experience).
     b. Ground seniority level: Check the verified experience duration. Add \"Senior\" if experience is 5-8 years, and \"Lead\" or \"Principal\" if experience is 8+ years, matching the seniority in their resume and target role. Do not add seniority prefixes if experience is less than 4 years.
     c. No punctuation, slashes, or ampersands in the title (use spaces or standard phrasing, e.g. \"Full Stack Developer\", not \"Full-Stack/Graphic-Designer\").
     d. Standardize spelling and casing (e.g., capitalization of each word).

Use the mathematically verified experience details provided to set accurate dates and flags. Ensure the output conforms exactly to the requested JSON structure.";

    $gapInput = "";
    if (!empty($gapAnswers) && is_array($gapAnswers)) {
        foreach ($gapAnswers as $ga) {
            $gapInput .= "- Skill: {$ga['skill']}\n  Candidate context: {$ga['answer']}\n";
        }
    }

    $datesInput = "Verified Total Experience: {$verifiedDatesData['total_experience_formatted']}\nExperiences:\n";
    foreach ($verifiedDatesData['experience_details'] as $ed) {
        $datesInput .= "- {$ed['role']} at {$ed['company']} ({$ed['start_date']} to {$ed['end_date']}) - Duration: {$ed['duration_formatted']}\n";
    }

    $userContent = "<resume_text>\n" . $resumeText . "\n</resume_text>\n\n" .
                "<target_role>\n" . $targetRole . "\n</target_role>\n\n" .
                "<job_description>\n" . $jobDescription . "\n</job_description>\n\n" .
                "<additional_candidate_input_on_skills_gaps>\n" . $gapInput . "</additional_candidate_input_on_skills_gaps>\n\n" .
                "<mathematically_verified_timeline_details>\n" . $datesInput . "</mathematically_verified_timeline_details>";

    $response = callAI([
        ["role" => "system", "content" => $systemPrompt],
        ["role" => "user", "content" => $userContent]
    ], 'resume_rewrite', [
        'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'result', 'schema' => [
            "type" => "object",
            "properties" => [
                "rating" => ["type" => "integer"],
                "alignment_summary" => ["type" => "string"],
                "rewritten_resume_markdown" => ["type" => "string"],
                "ai_refined_role" => ["type" => "string"],
                "changes" => [
                    "type" => "array",
                    "items" => [
                        "type" => "object",
                        "properties" => [
                            "original_point" => ["type" => "string"],
                            "optimized_point" => ["type" => "string"],
                            "reasoning" => ["type" => "string"]
                        ],
                        "required" => ["original_point", "optimized_point", "reasoning"]
                    ]
                ]
            ],
            "required" => ["rating", "alignment_summary", "rewritten_resume_markdown", "ai_refined_role", "changes"]
        ]]]
    ]);
    return json_decode($response, true);
}

/**
 * Save optimized resume to database and create a Markdown file on disk.
 */
function optimizer_save_to_profile($userId, $optimizedMarkdown, $changes = null, $originalPath = null) {
    $db = getDB();
    
    // Fetch current user details
    $stmt = $db->prepare("SELECT resume_path FROM users WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $userFull = $stmt->fetch(PDO::FETCH_ASSOC);

    $resumes = getCandidateResumes($userFull['resume_path'] ?? '');
    if (count($resumes) >= 5) {
        throw new Exception("Maximum limit of 5 resumes reached. Please delete an older resume before saving a new one.");
    }

    $uploadDir = __DIR__ . '/uploads/resumes/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $finalFileName = 'optimized_' . $userId . '_' . time() . '.md';
    $finalDest = $uploadDir . $finalFileName;

    // Write markdown to disk
    if (file_put_contents($finalDest, $optimizedMarkdown) === false) {
        throw new Exception("Failed to write optimized resume file on disk.");
    }

    $resumePath = 'uploads/resumes/' . $finalFileName;
    
    // Add to list and sort to make it active (active is index 0)
    $resumes[] = [
        'path' => $resumePath,
        'date' => time(),
        'text_version' => $optimizedMarkdown,
        'short_description' => 'AI Optimized Resume Version',
        'detected_role' => 'Optimized Resume',
        'is_base' => false,
        'optimization_changes' => $changes,
        'original_path' => $originalPath
    ];

    // Re-sort to put newest first
    usort($resumes, function($a, $b) {
        return ($b['date'] ?? 0) - ($a['date'] ?? 0);
    });

    $jsonVal = json_encode(array_values($resumes));

    $stmt = $db->prepare("UPDATE users SET resume_path = :path WHERE id = :id");
    $stmt->execute(['path' => $jsonVal, 'id' => $userId]);

    return [
        'success' => true,
        'path' => $resumePath,
        'filename' => $finalFileName
    ];
}

/**
 * Save optimized resume to V2 candidate profile and create a Markdown file on disk.
 */
function optimizer_save_to_candidate_profile($profileId, $userId, $optimizedMarkdown, $changes = null, $detectedRole = null, $originalPath = null, $userEnteredRole = null, $userEnteredDescription = null) {
    $db = getDB();
    $uploadDir = __DIR__ . '/uploads/resumes/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $finalFileName = 'optimized_v2_' . $userId . '_' . time() . '.md';
    $finalDest = $uploadDir . $finalFileName;

    // Write markdown to disk
    if (file_put_contents($finalDest, $optimizedMarkdown) === false) {
        throw new Exception("Failed to write optimized resume file on disk.");
    }

    $resumePath = 'uploads/resumes/' . $finalFileName;
    updateCandidateProfileResume($profileId, $userId, $resumePath, $optimizedMarkdown, false, $changes, $detectedRole, $originalPath, $userEnteredRole, $userEnteredDescription);

    return [
        'success' => true,
        'path' => $resumePath,
        'filename' => $finalFileName
    ];
}
