<?php

require_once __DIR__ . '/pusula_ai_clients/PusulaAiClientInterface.php';
require_once __DIR__ . '/pusula_ai_clients/OpenAiPusulaAiClient.php';
require_once __DIR__ . '/pusula_ai_clients/GeminiPusulaAiClient.php';
require_once __DIR__ . '/pusula_ai_clients/ClaudePusulaAiClient.php';
require_once __DIR__ . '/pusula_ai_clients/GroqPusulaAiClient.php';
require_once __DIR__ . '/pusula_ai_clients/CerebrasPusulaAiClient.php';

function pusula_ai_make_client(array $settings): PusulaAiClientInterface
{
    $provider = strtolower(trim((string)($settings['provider'] ?? 'openai')));

    switch ($provider) {
        case 'openai':
            return new OpenAiPusulaAiClient($settings);
        case 'gemini':
            return new GeminiPusulaAiClient($settings);
        case 'claude':
            return new ClaudePusulaAiClient($settings);
        case 'groq':
            return new GroqPusulaAiClient($settings);
        case 'cerebras':
            return new CerebrasPusulaAiClient($settings);
        default:
            throw new InvalidArgumentException('Desteklenmeyen Pusula AI provider: ' . $provider);
    }
}
