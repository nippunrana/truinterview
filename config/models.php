<?php
// AI provider + per-task model registry. To change a task's model, edit here.
// To switch provider in future: change base_url + api_key_env (any OpenAI-compatible API).
return [
    'provider' => [
        'base_url'    => 'https://api.fireworks.ai/inference/v1',
        'api_key_env' => 'FIREWORKS_API_KEY',
    ],
    'tasks' => [
        // Live interview room (latency-critical)
        'interview_chat'        => ['model' => 'accounts/fireworks/models/deepseek-v4-flash'],
        'interview_chat_vision' => ['model' => 'accounts/fireworks/models/minimax-m3'],
        'intent_classification' => ['model' => 'accounts/fireworks/models/deepseek-v4-flash'],

        // Background monitoring & grading
        // (max_tokens is generous where reasoning models spend completion tokens thinking
        //  before answering, or where outputs are long documents)
        'proctor_vision'        => ['model' => 'accounts/fireworks/models/qwen3p7-plus', 'max_tokens' => 4096],
        'evaluation'            => ['model' => 'accounts/fireworks/models/glm-5p2', 'max_tokens' => 8192],
        'evaluation_vision'     => ['model' => 'accounts/fireworks/models/qwen3p7-plus', 'max_tokens' => 4096],

        // Preparation & taxonomy (reasoning-critical)
        'question_generation'   => ['model' => 'accounts/fireworks/models/deepseek-v4-pro', 'temperature' => 0.7, 'max_tokens' => 8192],
        'taxonomy_match'        => ['model' => 'accounts/fireworks/models/deepseek-v4-pro'],

        // Resume pipeline
        'pdf_extract'           => ['model' => 'accounts/fireworks/models/qwen3p7-plus', 'max_tokens' => 8192],
        'resume_qa'             => ['model' => 'accounts/fireworks/models/qwen3p7-plus', 'max_tokens' => 4096],
        'resume_fix'            => ['model' => 'accounts/fireworks/models/kimi-k2p7-code', 'max_tokens' => 8192],
        'optimizer_analysis'    => ['model' => 'accounts/fireworks/models/deepseek-v4-pro', 'max_tokens' => 4096],
        'resume_rewrite'        => ['model' => 'accounts/fireworks/models/glm-5p2', 'max_tokens' => 8192],
    ],
];
