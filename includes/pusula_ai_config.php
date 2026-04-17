<?php

if (!defined('PUSULA_AI_SETTINGS_TABLE')) {
    define('PUSULA_AI_SETTINGS_TABLE', 'pusula_ai_settings');
}

if (!defined('PUSULA_AI_USAGE_LOGS_TABLE')) {
    define('PUSULA_AI_USAGE_LOGS_TABLE', 'pusula_ai_usage_logs');
}

if (!defined('PUSULA_AI_CONVERSATIONS_TABLE')) {
    define('PUSULA_AI_CONVERSATIONS_TABLE', 'pusula_ai_conversations');
}

if (!defined('PUSULA_AI_MESSAGES_TABLE')) {
    define('PUSULA_AI_MESSAGES_TABLE', 'pusula_ai_messages');
}

function pusula_ai_provider_models(): array
{
    return [
        'openai' => [
            'gpt-5.4-mini',
            'gpt-5.4',
            'gpt-4.1-mini',
            'gpt-4.1',
        ],
        'gemini' => [
            'gemini-2.5-flash',
            'gemini-2.5-pro',
            'gemini-1.5-flash',
        ],
        'claude' => [
            'claude-3-5-haiku-latest',
            'claude-3-5-sonnet-latest',
            'claude-3-7-sonnet-latest',
        ],
        'groq' => [
            'llama-3.3-70b-versatile',
            'llama-3.1-8b-instant',
            'mixtral-8x7b-32768',
        ],
        'cerebras' => [
            'llama3.1-8b',
            'qwen-3-235b-a22b-instruct-2507',
        ],
    ];
}

function pusula_ai_settings_keys(): array
{
    return [
        'provider',
        'model',
        'api_key',
        'base_url',
        'timeout_seconds',
        'temperature',
        'max_tokens',
        'premium_only',
        'internet_required',
        'moderation_enabled',
        'daily_limit',
        'is_active',
    ];
}

function pusula_ai_boolean_keys(): array
{
    return [
        'premium_only',
        'internet_required',
        'moderation_enabled',
        'is_active',
    ];
}

function pusula_ai_numeric_keys(): array
{
    return [
        'timeout_seconds',
        'temperature',
        'max_tokens',
        'daily_limit',
    ];
}

function pusula_ai_default_settings(): array
{
    return [
        'provider' => 'openai',
        'model' => 'gpt-5.4-mini',
        'api_key' => '',
        'base_url' => 'https://api.openai.com/v1',
        'timeout_seconds' => 30,
        'temperature' => 0.30,
        'max_tokens' => 1200,
        'premium_only' => 1,
        'internet_required' => 1,
        'moderation_enabled' => 1,
        'daily_limit' => 30,
        'is_active' => 1,
    ];
}
