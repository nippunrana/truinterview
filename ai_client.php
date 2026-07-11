<?php
// ai_client.php - Provider-agnostic AI client (OpenAI-compatible chat completions).
// Provider endpoint and per-task models are defined in config/models.php.

/**
 * Load and cache the AI provider/task configuration.
 */
function aiConfig() {
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config/models.php';
    }
    return $config;
}

/**
 * Resolve a task key to its model settings (model id + optional default params).
 */
function aiModelForTask($task) {
    $config = aiConfig();
    if (!isset($config['tasks'][$task])) {
        throw new Exception("Unknown AI task '{$task}'. Add it to config/models.php.");
    }
    return $config['tasks'][$task];
}

/**
 * Execute a chat completion request for a task. Returns the decoded response array.
 * $options passthrough: temperature, max_tokens, tools, response_format, etc.
 */
function callAIRaw($messages, $task, $options = []) {
    $config = aiConfig();
    $taskSettings = aiModelForTask($task);

    $apiKey = getenv($config['provider']['api_key_env']);
    if (!$apiKey) {
        $apiKey = $_ENV[$config['provider']['api_key_env']] ?? '';
    }
    if (empty($apiKey)) {
        throw new Exception("AI API key is not configured ({$config['provider']['api_key_env']}).");
    }

    $model = $taskSettings['model'];
    unset($taskSettings['model']);
    $body = array_merge($taskSettings, $options, [
        'model' => $model,
        'messages' => $messages,
    ]);

    $url = rtrim($config['provider']['base_url'], '/') . '/chat/completions';

    $data = null;
    $errorMessage = null;
    try {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Curl error when calling AI API: " . $error);
        }

        if ($httpCode !== 200) {
            throw new Exception("AI API returned HTTP code {$httpCode}: " . $response);
        }

        $data = json_decode($response, true);
        if (!$data) {
            throw new Exception("Invalid JSON response from AI API: " . $response);
        }
        return $data;
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
        throw $e;
    } finally {
        if (aiDebugEnabled()) {
            logAIDebugCall($task, $model, $messages, $body, $data, $errorMessage);
        }
    }
}

/**
 * Whether AI debug logging is enabled (toggle via AI_DEBUG=true in .env).
 */
function aiDebugEnabled() {
    static $enabled = null;
    if ($enabled === null) {
        $enabled = strtolower((string) getenv('AI_DEBUG')) === 'true';
    }
    return $enabled;
}

/**
 * Write the full request/response of one AI call to uploads/ai_debug/<call-folder>/.
 * Any inline base64 images are saved as separate viewable image files rather than
 * dumped into the JSON, so the exact bytes sent to the model can be inspected directly.
 */
function logAIDebugCall($task, $model, $messages, $requestBody, $responseData, $errorMessage) {
    $baseDir = __DIR__ . '/uploads/ai_debug';
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0755, true);
    }

    $callDir = $baseDir . '/' . date('Ymd_His') . '_' . $task . '_' . substr(uniqid('', true), -8);
    mkdir($callDir, 0755, true);

    $imageIndex = 0;
    $sanitizedMessages = array_map(function ($msg) use ($callDir, &$imageIndex) {
        if (is_array($msg['content'] ?? null)) {
            $msg['content'] = array_map(function ($part) use ($callDir, &$imageIndex) {
                if (($part['type'] ?? '') === 'image_url' && isset($part['image_url']['url'])) {
                    if (preg_match('/^data:(image\/[a-zA-Z]+);base64,(.+)$/', $part['image_url']['url'], $m)) {
                        $filename = 'image_' . $imageIndex . '.' . str_replace('image/', '', $m[1]);
                        file_put_contents($callDir . '/' . $filename, base64_decode($m[2]));
                        $part['image_url']['url'] = '[saved as ' . $filename . ']';
                        $imageIndex++;
                    }
                }
                return $part;
            }, $msg['content']);
        }
        return $msg;
    }, $messages);

    file_put_contents($callDir . '/request.json', json_encode([
        'timestamp' => date('c'),
        'task' => $task,
        'model' => $model,
        'options' => array_diff_key($requestBody, ['model' => 1, 'messages' => 1]),
        'messages' => $sanitizedMessages,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    file_put_contents($callDir . '/response.json', json_encode([
        'error' => $errorMessage,
        'response' => $responseData,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * Execute a chat completion and return the assistant's text content.
 */
function callAI($messages, $task, $options = []) {
    $data = callAIRaw($messages, $task, $options);
    return extractAIText($data);
}

/**
 * Extract the assistant text from a chat completion response.
 * Strips inline <think> blocks some reasoning models may emit.
 */
function extractAIText($data) {
    $content = $data['choices'][0]['message']['content'] ?? null;
    if ($content === null || $content === '') {
        throw new Exception("Unexpected response format from AI API: " . json_encode($data));
    }
    $content = preg_replace('/<think>.*?<\/think>/s', '', $content);
    return trim($content);
}

/**
 * Convert a PDF into image_url content parts (one JPEG per page) for vision models.
 * The provider does not accept raw PDF data URIs, so pages are rendered via Imagick.
 */
function pdfToContentParts($filePath, $maxPages = 6) {
    $imagick = new Imagick();
    $imagick->setResolution(150, 150);
    $imagick->readImage($filePath);

    $parts = [];
    $pageCount = min($imagick->getNumberImages(), $maxPages);
    for ($i = 0; $i < $pageCount; $i++) {
        // foreach/current() on Imagick returns the SAME shared object across iterations,
        // so mergeImageLayers() on it would merge/consume the whole sequence instead of
        // one page. setIteratorIndex()+getImage() gives an independent single-page copy.
        $imagick->setIteratorIndex($i);
        $pageImage = $imagick->getImage();
        $pageImage->setImageBackgroundColor('white');
        $pageImage = $pageImage->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        $pageImage->setImageFormat('jpeg');
        $pageImage->setImageCompressionQuality(85);
        $parts[] = [
            'type' => 'image_url',
            'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode($pageImage->getImageBlob())]
        ];
        $pageImage->clear();
    }
    $imagick->clear();

    if (empty($parts)) {
        throw new Exception("Could not render any pages from PDF: " . basename($filePath));
    }
    return $parts;
}
