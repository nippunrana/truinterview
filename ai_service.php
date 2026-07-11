<?php
// ai_service.php - AI service helpers (transport lives in ai_client.php)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/ai_client.php';

/**
 * Multimodal vision check on a screen capture
 */
function queryVision($imagePath, $prompt, $context) {
    if (!file_exists($imagePath)) {
        throw new Exception("Image file not found: " . $imagePath);
    }

    $imageData = base64_encode(file_get_contents($imagePath));

    $messages = [
        [
            "role" => "system",
            "content" => getInterviewSystemPrompt()
        ],
        [
            "role" => "user",
            "content" => [
                [
                    "type" => "text",
                    "text" => "Here is the candidate's latest screen capture context and dialog history.\n\n" .
                              "Dialog History:\n" . $context . "\n\n" .
                              "Candidate's latest utterance: \"" . $prompt . "\"\n\n" .
                              "Analyze the screenshot image relative to their utterance and continue the technical interview conversation."
                ],
                [
                    "type" => "image_url",
                    "image_url" => ["url" => "data:image/jpeg;base64," . $imageData]
                ]
            ]
        ]
    ];

    return callAI($messages, 'interview_chat_vision');
}

/**
 * Text-only dialog chat turn progression
 */
function queryChat($messages, $systemPrompt = null) {
    if (empty($systemPrompt)) {
        $systemPrompt = getInterviewSystemPrompt();
    }

    $chatMessages = [
        [
            "role" => "system",
            "content" => $systemPrompt
        ]
    ];
    foreach ($messages as $msg) {
        $role = strtoupper($msg['speaker'] ?? $msg['role'] ?? '');
        $text = $msg['message'] ?? $msg['text'] ?? '';

        if ($role === 'USER' || $role === 'CLIENT') {
            $role = 'user';
        } elseif ($role === 'AGENT' || $role === 'MODEL') {
            $role = 'assistant';
        } else {
            continue; // Skip SYSTEM or metadata rows to preserve alternating rules
        }

        $chatMessages[] = [
            "role" => $role,
            "content" => $text
        ];
    }

    // Ensure we have at least one valid user entry
    if (count($chatMessages) === 1) {
        $chatMessages[] = [
            "role" => "user",
            "content" => "Hello"
        ];
    }

    return callAI($chatMessages, 'interview_chat');
}

/**
 * Standard default system prompt for the Interviewer Agent
 */
function getInterviewSystemPrompt() {
    return "# PERSONA & ROLE
- You are Alex, a senior technical interviewer for Acme Corp.
- Tone: Professional, encouraging, objective.
- Goal: Assess candidate's experience, communication, and technical alignment.

# INTERVIEW STRUCTURE
1. Introduction: Greet the candidate and state the purpose of the call.
2. Ask Question 1: Experience with WebRTC or real-time systems.
3. Ask Question 2: Handling high-pressure technical debt.
4. Close: Ask if they have questions, thank them, and explain next steps.
*Keep responses limited to one question at a time.*

# SECURITY & ROLE INTEGRITY
- Do NOT answer the question for the candidate, even if they explicitly ask for the answer, explanation, or help.
- Do NOT reveal or indicate whether the candidate's answer is correct or incorrect.
- If the candidate struggles, asks to explain a concept, or asks for the answer, you must NOT give it. Instead, you may reframe the question in simpler terms or ask if they would like to skip the question.

# TTS OUTPUT FORMATTING (MANDATORY)
- Speak in plain, continuous conversational text.
- NEVER output emojis, asterisks, hashtags, or markdown formatting.
- Spell out all symbols (e.g., say 'percent' instead of '%', 'dollars' instead of '$').
- Use standard punctuation to introduce brief pauses for natural turn-taking.";
}

/**
 * Extract text from DOCX files
 */
function extractTextFromDocx($filePath) {
    if (!class_exists('ZipArchive')) {
        return "";
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) === true) {
        if (($index = $zip->locateName('word/document.xml')) !== false) {
            $data = $zip->getFromIndex($index);
            $zip->close();
            
            preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>|<\/w:p>|<w:br\s*\/?>|<w:tab\s*\/?>/i', $data, $matches, PREG_SET_ORDER);
            $text = "";
            foreach ($matches as $match) {
                $full = strtolower($match[0]);
                if ($full === '</w:p>' || strpos($full, '<w:br') === 0) {
                    $text .= "\n";
                } elseif (strpos($full, '<w:tab') === 0) {
                    $text .= "\t";
                } elseif (isset($match[1])) {
                    $text .= $match[1];
                }
            }
            
            $text = preg_replace("/\n{3,}/", "\n\n", $text);
            return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $zip->close();
    }
    return "";
}

/**
 * Extract readable text from old binary DOC files
 */
function extractTextFromDoc($filePath) {
    $file_content = @file_get_contents($filePath);
    if ($file_content === false) {
        return "";
    }
    $lines = explode("\n", $file_content);
    $extracted_text = "";
    foreach ($lines as $line) {
        $line = preg_replace('/[^a-zA-Z0-9\s,\.\-\_\@\:\/\(\)\'\"]/', '', $line);
        $line = trim(preg_replace('/\s+/', ' ', $line));
        if (strlen($line) > 10) {
            $extracted_text .= $line . "\n";
        }
    }
    return $extracted_text;
}

/**
 * Robust system prompt for checking resumes, built following context engineering guidelines (SKILL.md)
 */
function getResumeAnalyzerSystemPrompt($profileName) {
    return "<context>
You are an expert resume analyzer. Your job is to inspect an uploaded document, verify if it is indeed a resume/CV (and not some other file type), extract the candidate's name, compare it with the candidate's profile name, convert it to clean plain text markdown, identify their primary job role, and generate a brief professional summary.
</context>

<task>
Analyze the uploaded document contents.
1. Determine if the document represents a professional resume or curriculum vitae (CV).
2. If it is a valid resume/CV, extract the full name of the candidate as written in the resume.
3. Compare the extracted name from the resume with the profile name: \"" . $profileName . "\". Check if they match.
4. Extract the primary job title or detected role and refine it into a standardized, recognizable, and concise industry-standard job title (e.g., \"Senior Frontend Engineer\", \"Full Stack Developer\").
5. Generate a professional summary/short description (1-2 sentences summarizing their primary skills and background).
</task>

<constraints>
- A valid resume must contain sections like work experience, education, skills, contact info, or summary. If the file is just code, a generic text file, list of tasks, essay, or other unrelated document, classify it as NOT a valid resume.
- For name matching:
  - First name match is critical.
  - A first name match should be case-insensitive.
  - Nicknames or shortened names that refer to the same name should count as matching (e.g. \"Mike\" matches \"Michael\", \"Dave\" matches \"David\", \"Rob\" matches \"Robert\").
  - Do not require a 100% exact full name match (e.g. middle names or last names might be slightly different or missing, and that is okay, but the first name must match).
- For detected_role:
  - Refine it to be a standardized, clean, and concise job title of 2-4 words maximum (e.g. \"Senior Prompt Engineer\", not a long hybrid list like \"Senior Prompt Engineer & AI Automation Architect\").
  - No punctuation, slashes, or ampersands in the title.
- Return ONLY a valid JSON object. Do not include any explanation or markdown formatting outside the JSON block.
</constraints>

<output_format>
Return ONLY this JSON (no prose):
{
  \"is_valid_resume\": boolean,
  \"extracted_name\": string | null,
  \"is_name_match\": boolean,
  \"confidence\": number,
  \"detected_role\": string | null,
  \"short_description\": string | null
}
</output_format>

<verification>
- If the document is not a resume/CV, set is_valid_resume to false and other fields to null.
- Be honest with the confidence score. If the name is missing or extremely ambiguous, keep confidence low.
</verification>";
}

/**
 * Build the user message content for a document + prompt.
 * PDFs are sent as rendered page images; other formats as extracted text.
 */
function buildDocumentUserContent($filePath, $ext, $prompt) {
    if ($ext === 'pdf') {
        return array_merge(
            [["type" => "text", "text" => $prompt]],
            pdfToContentParts($filePath)
        );
    }

    if ($ext === 'docx') {
        $text = extractTextFromDocx($filePath);
    } elseif ($ext === 'doc') {
        $text = extractTextFromDoc($filePath);
    } else {
        $text = file_get_contents($filePath);
    }

    return $prompt . "\n\nDocument Content:\n" . $text;
}

/**
 * Verify uploaded resume
 */
function verifyUploadedResume($filePath, $ext, $profileName) {
    if (!file_exists($filePath)) {
        return [
            'is_valid_resume' => false,
            'extracted_name' => null,
            'is_name_match' => false,
            'confidence' => 0,
            'error' => 'File not found on server.'
        ];
    }
    
    $prompt = "Please analyze the uploaded document and verify if it matches the profile name: \"$profileName\".";

    try {
        $messages = [
            [
                "role" => "system",
                "content" => getResumeAnalyzerSystemPrompt($profileName)
            ],
            [
                "role" => "user",
                "content" => buildDocumentUserContent($filePath, $ext, $prompt)
            ]
        ];

        $responseJson = callAI($messages, 'pdf_extract', [
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'result', 'schema' => [
                'type' => 'object',
                'properties' => [
                    'is_valid_resume' => ['type' => 'boolean'],
                    'extracted_name' => ['type' => ['string', 'null']],
                    'is_name_match' => ['type' => 'boolean'],
                    'confidence' => ['type' => 'number'],
                    'detected_role' => ['type' => ['string', 'null']],
                    'short_description' => ['type' => ['string', 'null']]
                ],
                'required' => ['is_valid_resume', 'extracted_name', 'is_name_match', 'confidence', 'detected_role', 'short_description']
            ]]]
        ]);
        $result = json_decode($responseJson, true);
        if (!$result || !isset($result['is_valid_resume'])) {
            preg_match('/\{.*\}/s', $responseJson, $matches);
            if (isset($matches[0])) {
                $result = json_decode($matches[0], true);
            }
        }
        return $result ?: [
            'is_valid_resume' => false,
            'extracted_name' => null,
            'is_name_match' => false,
            'confidence' => 0
        ];
    } catch (Exception $e) {
        return [
            'is_valid_resume' => false,
            'extracted_name' => null,
            'is_name_match' => false,
            'confidence' => 0,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Quality Assurance Assessor for resume text extraction
 */
function qa_assess_resume_extraction($filePath, $ext, $markdownText) {
    if (!file_exists($filePath)) {
        throw new Exception("Resume file not found: " . $filePath);
    }
    
    $prompt = "<context>\n" .
              "You are an automated Quality Assurance auditor. You verify if a Markdown transcription of a resume missed critical factual blocks from the original document.\n" .
              "</context>\n" .
              "<task>\n" .
              "Compare the original document to the transcribed Markdown provided. Determine if there are SEVERE omissions or hallucinations.\n" .
              "</task>\n" .
              "<constraints>\n" .
              "- IGNORE all styling, layout, formatting, bolding, bullet points, and font differences.\n" .
              "- ONLY flag if a major factual block (an entire job role, company name, educational degree, or distinct skills section) was completely omitted or falsely invented.\n" .
              "- If the transcription contains all factual blocks, set needs_fix to false.\n" .
              "</constraints>\n" .
              "<source>\n" .
              "The attached document is the original.\n" .
              "=== TRANSCRIBED MARKDOWN BELOW ===\n" .
              $markdownText . "\n" .
              "</source>\n" .
              "<output_format>\n" .
              "Return ONLY this JSON (no prose):\n" .
              "{\n" .
              "  \"needs_fix\": boolean,\n" .
              "  \"issues\": [\n" .
              "    \"Describe the specific missing/hallucinated block (e.g., 'Missing the 2018-2020 Software Engineer role at Google')\"\n" .
              "  ]\n" .
              "}\n" .
              "</output_format>";

    try {
        $messages = [
            [
                "role" => "user",
                "content" => buildDocumentUserContent($filePath, $ext, $prompt)
            ]
        ];

        $responseJson = callAI($messages, 'resume_qa', [
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'result', 'schema' => [
                'type' => 'object',
                'properties' => [
                    'needs_fix' => ['type' => 'boolean'],
                    'issues' => ['type' => 'array', 'items' => ['type' => 'string']]
                ],
                'required' => ['needs_fix', 'issues']
            ]]]
        ]);
        $result = json_decode($responseJson, true);
        if (!$result || !isset($result['needs_fix'])) {
            preg_match('/\{.*\}/s', $responseJson, $matches);
            if (isset($matches[0])) {
                $result = json_decode($matches[0], true);
            }
        }
        return $result ?: ['needs_fix' => false, 'issues' => []];
    } catch (Exception $e) {
        return ['needs_fix' => false, 'issues' => [], 'error' => $e->getMessage()];
    }
}

/**
 * Fixer for resume text extraction based on QA issues
 */
function fix_resume_extraction($filePath, $ext, $markdownText, $issues) {
    if (!file_exists($filePath)) {
        throw new Exception("Resume file not found: " . $filePath);
    }

    $issuesText = is_array($issues) ? implode("\n- ", $issues) : $issues;
    
    $prompt = "<context>\n" .
              "You are a precision Markdown editor. A QA auditor found critical omissions in a resume transcription.\n" .
              "</context>\n" .
              "<task>\n" .
              "Produce a revised version of the Markdown transcription that integrates the missing information identified in the issues list.\n" .
              "</task>\n" .
              "<constraints>\n" .
              "- Do NOT rewrite or alter the parts of the Markdown that are already correct.\n" .
              "- Only insert the missing blocks or correct the specific hallucinations identified by the auditor.\n" .
              "</constraints>\n" .
              "<source>\n" .
              "The attached document is the original.\n" .
              "=== CURRENT TRANSCRIBED MARKDOWN ===\n" .
              $markdownText . "\n" .
              "=== ISSUES TO FIX ===\n" .
              "- " . $issuesText . "\n" .
              "</source>\n" .
              "<output_format>\n" .
              "Output ONLY valid Markdown text. Do not include conversational filler.\n" .
              "</output_format>";

    try {
        $messages = [
            [
                "role" => "user",
                "content" => buildDocumentUserContent($filePath, $ext, $prompt)
            ]
        ];

        return callAI($messages, 'resume_fix');
    } catch (Exception $e) {
        return $markdownText;
    }
}

/**
 * Match a role title to the best fitting category
 */
function matchRoleToCategory($roleTitle, $categories) {
    if (empty($categories)) {
        return ['category_id' => null, 'match_percentage' => 0];
    }
    
    $categoriesJson = json_encode($categories);
    
    $prompt = "<context>\n" .
              "You are an expert HR taxonomy analyst mapping raw job titles to a standardized category list.\n" .
              "</context>\n" .
              "<task>\n" .
              "Analyze the raw job title provided in <role> and evaluate it against the category definitions in <categories>. Select the single category that best matches the role, and assign a match score based on the rubric.\n" .
              "</task>\n" .
              "<input_schemaspec>\n" .
              "The categories list is a JSON array of objects, where each object has:\n" .
              "- uuid: String (The unique identifier for the category. This must be the value returned as category_id).\n" .
              "- name: String (The category title).\n" .
              "- description: String (Details about what skills, languages, or responsibilities are covered under this category).\n" .
              "</input_schemaspec>\n" .
              "<constraints>\n" .
              "1. Grounding Rule: The category_id returned MUST exist in the provided <categories> list. Do NOT invent, guess, or hallucinate a UUID.\n" .
              "2. Select the single best category. If no category represents a fit of 50% or more, set category_id to null and match_percentage to 0.\n" .
              "3. Score Calibration Rubric:\n" .
              "   - 100: Exact Match (Synonymous title, exact same core function and seniority/level).\n" .
              "   - 75: Strong Match (Same core function/domain, but slight specialization variation, e.g. web vs mobile, or specific tool/framework).\n" .
              "   - 50: Partial Match (Related field, tangential overlap, or sibling department with distinct primary focus).\n" .
              "   - 0: No Match (Does not fit into any provided category).\n" .
              "4. Return ONLY valid JSON matching the schema in <output_format>. Do not output markdown, preambles, or post-text.\n" .
              "</constraints>\n" .
              "<few_shot_examples>\n" .
              "Example 1:\n" .
              "- Input Role: \"React Native Developer\"\n" .
              "- Input Categories: [\n" .
              "    {\"uuid\": \"cfaa3cae-5ba0-4158-aea8-74753c2872d5\", \"name\": \"Frontend Development\", \"description\": \"Focuses on creating user interfaces and web experiences using HTML, CSS, JavaScript, and modern frameworks like React or Vue.\"},\n" .
              "    {\"uuid\": \"b2b2b2b2-b2b2-b2b2-b2b2-b2b2b2b2b2b2\", \"name\": \"Backend Development\", \"description\": \"Focuses on database design, server-side APIs, systems logic...\"}\n" .
              "  ]\n" .
              "- Expected Output:\n" .
              "{\n" .
              "  \"rationale\": \"React Native is a framework for building user interfaces (Frontend), but specialized for mobile instead of traditional web.\",\n" .
              "  \"category_id\": \"cfaa3cae-5ba0-4158-aea8-74753c2872d5\",\n" .
              "  \"match_percentage\": 75\n" .
              "}\n\n" .
              "Example 2:\n" .
              "- Input Role: \"Sales Representative\"\n" .
              "- Input Categories: [\n" .
              "    {\"uuid\": \"cfaa3cae-5ba0-4158-aea8-74753c2872d5\", \"name\": \"Frontend Development\", \"description\": \"Focuses on creating user interfaces...\"},\n" .
              "    {\"uuid\": \"b2b2b2b2-b2b2-b2b2-b2b2-b2b2b2b2b2b2\", \"name\": \"Backend Development\", \"description\": \"Focuses on database design...\"}\n" .
              "  ]\n" .
              "- Expected Output:\n" .
              "{\n" .
              "  \"rationale\": \"A sales representative role has no overlaps with frontend or backend software development.\",\n" .
              "  \"category_id\": null,\n" .
              "  \"match_percentage\": 0\n" .
              "}\n" .
              "</few_shot_examples>\n" .
              "<role>\n" .
              $roleTitle . "\n" .
              "</role>\n" .
              "<categories>\n" .
              $categoriesJson . "\n" .
              "</categories>\n" .
              "<output_format>\n" .
              "{\n" .
              "  \"rationale\": \"string (1-2 sentences explaining why this category and score were chosen)\",\n" .
              "  \"category_id\": \"string (uuid) | null\",\n" .
              "  \"match_percentage\": 100 | 75 | 50 | 0\n" .
              "}\n" .
              "</output_format>\n" .
              "<verification>\n" .
              "- Ensure category_id is either null or a string matching one of the UUIDs in <categories> exactly.\n" .
              "- Confirm match_percentage is exactly one of [100, 75, 50, 0].\n" .
              "</verification>";

    try {
        $responseJson = callAI([
            ["role" => "user", "content" => $prompt]
        ], 'taxonomy_match', [
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'result', 'schema' => [
                'type' => 'object',
                'properties' => [
                    'rationale' => ['type' => 'string'],
                    'category_id' => ['type' => ['string', 'null']],
                    'match_percentage' => ['type' => 'number']
                ],
                'required' => ['rationale', 'category_id', 'match_percentage']
            ]]]
        ]);
        $result = json_decode($responseJson, true);
        
        if (!$result || (!isset($result['category_id']) && !array_key_exists('category_id', $result))) {
            preg_match('/\{.*\}/s', $responseJson, $matches);
            if (isset($matches[0])) {
                $result = json_decode($matches[0], true);
            }
        }
        
        if ($result && array_key_exists('category_id', $result)) {
            return [
                'category_id' => $result['category_id'],
                'match_percentage' => isset($result['match_percentage']) ? (int)$result['match_percentage'] : 0
            ];
        }
        
        return ['category_id' => null, 'match_percentage' => 0];
    } catch (Exception $e) {
        return ['category_id' => null, 'match_percentage' => 0];
    }
}




