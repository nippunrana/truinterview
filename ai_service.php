<?php
// ai_service.php - Gemini API client integration

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Base utility to execute request against Gemini API
 */
function callGeminiRaw($payload, $model = 'gemini-3.5-flash', $apiKeyOverride = null) {
    // Map model names to actual supported Google Gemini API models
    $modelMap = [
        'gemini-3.5-flash'      => 'gemini-3.5-flash',
        'gemini-3.5-flash-lite' => 'gemini-3.1-flash-lite',
        'gemini-3.5-pro'        => 'gemini-3.1-pro-preview',
        'gemini-3.1-pro'        => 'gemini-3.1-pro-preview',
    ];
    if (isset($modelMap[$model])) {
        $model = $modelMap[$model];
    }

    $apiKey = $apiKeyOverride ?: getenv('GEMINI_API_KEY');
    if (!$apiKey) {
        $apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
    }
    if (empty($apiKey)) {
        throw new Exception("Gemini API key is not configured.");
    }
    
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("Curl error when calling Gemini API: " . $error);
    }
    
    if ($httpCode !== 200) {
        throw new Exception("Gemini API returned HTTP code {$httpCode}: " . $response);
    }
    
    $data = json_decode($response, true);
    if (!$data) {
        throw new Exception("Invalid JSON response from Gemini API: " . $response);
    }
    return $data;
}

function callGemini($payload, $model = 'gemini-3.5-flash', $apiKeyOverride = null) {
    $data = callGeminiRaw($payload, $model, $apiKeyOverride);
    if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        throw new Exception("Unexpected response format from Gemini API: " . json_encode($data));
    }
    
    return trim($data['candidates'][0]['content']['parts'][0]['text']);
}

/**
 * Multimodal vision check using Gemini
 */
function queryGeminiVision($imagePath, $prompt, $context, $apiKeyOverride = null, $model = 'gemini-3.5-flash') {
    if (!file_exists($imagePath)) {
        throw new Exception("Image file not found: " . $imagePath);
    }
    
    $imageData = base64_encode(file_get_contents($imagePath));
    $mimeType = 'image/jpeg';
    
    $systemPrompt = getInterviewSystemPrompt();
    
    $payload = [
        "contents" => [
            [
                "parts" => [
                    [
                        "text" => "Here is the candidate's latest screen capture context and dialog history.\n\n" . 
                                  "Dialog History:\n" . $context . "\n\n" .
                                  "Candidate's latest utterance: \"" . $prompt . "\"\n\n" .
                                  "Analyze the screenshot image relative to their utterance and continue the technical interview conversation."
                    ],
                    [
                        "inlineData" => [
                            "mimeType" => $mimeType,
                            "data" => $imageData
                        ]
                    ]
                ]
            ]
        ],
        "systemInstruction" => [
            "parts" => [
                [
                    "text" => $systemPrompt
                ]
            ]
        ]
    ];
    
    return callGemini($payload, $model, $apiKeyOverride);
}

/**
 * Text-only dialog chat turn progression using Gemini
 */
function queryGeminiChat($messages, $systemPrompt = null, $apiKeyOverride = null, $model = 'gemini-3.5-flash') {
    if (empty($systemPrompt)) {
        $systemPrompt = getInterviewSystemPrompt();
    }
    
    $contents = [];
    foreach ($messages as $msg) {
        $role = strtoupper($msg['speaker'] ?? $msg['role'] ?? '');
        $text = $msg['message'] ?? $msg['text'] ?? '';
        
        if ($role === 'USER' || $role === 'CLIENT') {
            $role = 'user';
        } elseif ($role === 'AGENT' || $role === 'MODEL') {
            $role = 'model';
        } else {
            continue; // Skip SYSTEM or metadata rows to preserve alternating rules
        }
        
        $contents[] = [
            "role" => $role,
            "parts" => [
                [
                    "text" => $text
                ]
            ]
        ];
    }
    
    // Ensure we have at least one valid user entry
    if (empty($contents)) {
        $contents[] = [
            "role" => "user",
            "parts" => [
                [
                    "text" => "Hello"
                ]
            ]
        ];
    }
    
    $payload = [
        "contents" => $contents,
        "systemInstruction" => [
            "parts" => [
                [
                    "text" => $systemPrompt
                ]
            ]
        ]
    ];
    
    return callGemini($payload, $model, $apiKeyOverride);
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
            preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/', $data, $matches);
            $text = implode(" ", $matches[1]);
            return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
4. Extract the primary job title or detected role (e.g., \"Senior Frontend Developer\", \"Full-Stack Engineer\").
5. Generate a professional summary/short description (1-2 sentences summarizing their primary skills and background).
</task>

<constraints>
- A valid resume must contain sections like work experience, education, skills, contact info, or summary. If the file is just code, a generic text file, list of tasks, essay, or other unrelated document, classify it as NOT a valid resume.
- For name matching:
  - First name match is critical.
  - A first name match should be case-insensitive.
  - Nicknames or shortened names that refer to the same name should count as matching (e.g. \"Mike\" matches \"Michael\", \"Dave\" matches \"David\", \"Rob\" matches \"Robert\").
  - Do not require a 100% exact full name match (e.g. middle names or last names might be slightly different or missing, and that is okay, but the first name must match).
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
 * Verify uploaded resume using Gemini
 */
function verifyUploadedResume($filePath, $ext, $profileName, $model = 'gemini-3.5-flash', $apiKey = null) {
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
    
    if ($ext === 'pdf') {
        $pdfData = base64_encode(file_get_contents($filePath));
        $contents = [
            [
                "role" => "user",
                "parts" => [
                    [
                        "text" => $prompt
                    ],
                    [
                        "inlineData" => [
                            "mimeType" => "application/pdf",
                            "data" => $pdfData
                        ]
                    ]
                ]
            ]
        ];
    } else {
        $text = "";
        if ($ext === 'docx') {
            $text = extractTextFromDocx($filePath);
        } elseif ($ext === 'doc') {
            $text = extractTextFromDoc($filePath);
        } else {
            $text = file_get_contents($filePath);
        }
        
        $contents = [
            [
                "role" => "user",
                "parts" => [
                    [
                        "text" => $prompt . "\n\nDocument Content:\n" . $text
                    ]
                ]
            ]
        ];
    }
    
    $systemPrompt = getResumeAnalyzerSystemPrompt($profileName);
    
    $payload = [
        "contents" => $contents,
        "systemInstruction" => [
            "parts" => [
                [
                    "text" => $systemPrompt
                ]
            ]
        ],
        "generationConfig" => [
            "responseMimeType" => "application/json"
        ]
    ];
    
    try {
        $responseJson = callGemini($payload, $model, $apiKey);
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
function qa_assess_resume_extraction($filePath, $ext, $markdownText, $model = 'gemini-3.5-flash', $apiKey = null) {
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

    if ($ext === 'pdf') {
        $pdfData = base64_encode(file_get_contents($filePath));
        $contents = [
            [
                "role" => "user",
                "parts" => [
                    ["text" => $prompt],
                    [
                        "inlineData" => [
                            "mimeType" => "application/pdf",
                            "data" => $pdfData
                        ]
                    ]
                ]
            ]
        ];
    } else {
        $text = "";
        if ($ext === 'docx') {
            $text = extractTextFromDocx($filePath);
        } elseif ($ext === 'doc') {
            $text = extractTextFromDoc($filePath);
        } else {
            $text = file_get_contents($filePath);
        }
        
        $contents = [
            [
                "role" => "user",
                "parts" => [
                    ["text" => $prompt . "\n\nOriginal Document Plain Text:\n" . $text]
                ]
            ]
        ];
    }

    $payload = [
        "contents" => $contents,
        "generationConfig" => [
            "responseMimeType" => "application/json"
        ]
    ];

    try {
        $responseJson = callGemini($payload, $model, $apiKey);
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
function fix_resume_extraction($filePath, $ext, $markdownText, $issues, $model = 'gemini-3.5-flash', $apiKey = null) {
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

    if ($ext === 'pdf') {
        $pdfData = base64_encode(file_get_contents($filePath));
        $contents = [
            [
                "role" => "user",
                "parts" => [
                    ["text" => $prompt],
                    [
                        "inlineData" => [
                            "mimeType" => "application/pdf",
                            "data" => $pdfData
                        ]
                    ]
                ]
            ]
        ];
    } else {
        $text = "";
        if ($ext === 'docx') {
            $text = extractTextFromDocx($filePath);
        } elseif ($ext === 'doc') {
            $text = extractTextFromDoc($filePath);
        } else {
            $text = file_get_contents($filePath);
        }
        
        $contents = [
            [
                "role" => "user",
                "parts" => [
                    ["text" => $prompt . "\n\nOriginal Document Plain Text:\n" . $text]
                ]
            ]
        ];
    }

    $payload = [
        "contents" => $contents
    ];

    try {
        return callGemini($payload, $model, $apiKey);
    } catch (Exception $e) {
        return $markdownText;
    }
}




