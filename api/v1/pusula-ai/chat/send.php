<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/pusula_ai_chat_helper.php';

api_require_method('POST');

try {
    $authContext = pusula_ai_api_require_auth_context($pdo);
    $userId = (string)$authContext['user_id'];

    $settings = pusula_ai_api_settings($pdo);
    if (!pusula_ai_api_feature_enabled($settings)) {
        api_error('Pusula Ai özelliği şu anda aktif değil.', 403);
    }

    $isPremium = pusula_ai_api_is_user_premium($pdo, $userId);
    if (pusula_ai_api_requires_premium($settings) && !$isPremium) {
        api_error('Bu özellik için premium üyelik gerekli.', 403);
    }

    $dailyLimit = pusula_ai_api_daily_limit($settings);
    $todayCount = pusula_ai_chat_count_today_success($pdo, $userId);
    $remainingBefore = max(0, $dailyLimit - $todayCount);
    if ($remainingBefore <= 0) {
        api_error('Günlük Pusula Ai limitin doldu.', 429);
    }

    $payload = api_get_request_data();
    $validated = pusula_ai_chat_validate_request(is_array($payload) ? $payload : []);
    if (empty($validated['valid'])) {
        api_error((string)($validated['error'] ?? 'Mesaj geçersiz.'), 422);
    }

    $message = (string)$validated['message'];
    $mode = (string)$validated['mode'];
    $conversationId = (string)$validated['conversation_id'];
    $knowledgeBundle = pusula_ai_chat_get_knowledge_bundle($pdo);

    $conversationId = pusula_ai_chat_resolve_conversation($pdo, $userId, $conversationId, $mode, $message);
    $actionPayload = pusula_ai_chat_detect_action_payload_from_bundle($message, $knowledgeBundle);

    $moderation = pusula_ai_chat_moderate_message($message);
    $userIntent = trim((string)($moderation['intent'] ?? ''));
    if (empty($moderation['allowed'])) {
        $reply = (string)($moderation['reply'] ?? pusula_ai_chat_rejection_text());

        $pdo->beginTransaction();
        try {
            pusula_ai_chat_insert_message($pdo, $conversationId, $userId, 'user', $message, null, 0, 0);
            $assistantMessageId = pusula_ai_chat_insert_message($pdo, $conversationId, $userId, 'assistant', $reply, $actionPayload, 0, 0);
            $pdo->commit();
        } catch (Throwable $txe) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $txe;
        }

        pusula_ai_chat_log_usage($pdo, $userId, [
            'conversation_id' => $conversationId,
            'provider' => (string)($settings['provider'] ?? ''),
            'model' => (string)($settings['model'] ?? ''),
            'token_in' => 0,
            'token_out' => 0,
            'estimated_cost' => 0,
            'success' => false,
            'error_code' => 'moderation_blocked',
            'error_message' => (string)($moderation['reason'] ?? 'moderation_blocked'),
        ]);

        api_success('Mesaj işlendi.', [
            'conversation_id' => $conversationId,
            'reply' => $reply,
            'mode' => $mode,
            'provider' => (string)($settings['provider'] ?? ''),
            'model' => (string)($settings['model'] ?? ''),
            'remaining_limit' => $remainingBefore,
            'message_id' => $assistantMessageId,
            'created_at' => date('c'),
            'action_payload' => $actionPayload,
        ]);
    }

    $userContext = pusula_ai_chat_fetch_user_context($pdo, $userId);
    $normalizedIntent = detectIntent($message);
    if ($userIntent === '') {
        $userIntent = $normalizedIntent;
    }

    $systemPrompt = pusula_ai_chat_build_system_prompt($mode, $userContext, [
        'user_intent' => $userIntent,
        'moderation_reason' => (string)($moderation['reason'] ?? ''),
        'user_message_length' => mb_strlen($message, 'UTF-8'),
        'user_wants_detailed' => pusula_ai_chat_user_wants_detailed_reply($message),
        'provider' => (string)($settings['provider'] ?? ''),
        'model' => (string)($settings['model'] ?? ''),
        'knowledge_bundle' => $knowledgeBundle,
    ]);

    $recent = pusula_ai_chat_fetch_recent_messages($pdo, $conversationId, 8);
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
    ];
    foreach ($recent as $item) {
        $messages[] = $item;
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    $client = pusula_ai_make_client($settings);
    $providerResult = $client->generateChatReply($messages, [
        'temperature' => (float)($settings['temperature'] ?? 0.3),
        'max_tokens' => (int)($settings['max_tokens'] ?? 800),
        'mode' => $mode,
    ]);

    if (empty($providerResult['success'])) {
        $errorCode = trim((string)($providerResult['error_code'] ?? 'provider_error'));
        $errorMessage = trim((string)($providerResult['error_message'] ?? 'Provider error'));

        pusula_ai_chat_log_usage($pdo, $userId, [
            'conversation_id' => $conversationId,
            'provider' => (string)($settings['provider'] ?? ''),
            'model' => (string)($settings['model'] ?? ''),
            'token_in' => (int)($providerResult['input_tokens'] ?? 0),
            'token_out' => (int)($providerResult['output_tokens'] ?? 0),
            'estimated_cost' => 0,
            'success' => false,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ]);

        error_log('[pusula_ai_chat][provider_error] ' . json_encode([
            'user_id' => $userId,
            'provider' => (string)($settings['provider'] ?? ''),
            'model' => (string)($settings['model'] ?? ''),
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        api_error('Şu anda Pusula Ai’ye ulaşılamıyor. Lütfen biraz sonra tekrar dene.', 503);
    }

    $reply = trim((string)($providerResult['reply'] ?? ''));
    if ($reply === '') {
        pusula_ai_chat_log_usage($pdo, $userId, [
            'conversation_id' => $conversationId,
            'provider' => (string)($settings['provider'] ?? ''),
            'model' => (string)($settings['model'] ?? ''),
            'token_in' => (int)($providerResult['input_tokens'] ?? 0),
            'token_out' => (int)($providerResult['output_tokens'] ?? 0),
            'estimated_cost' => 0,
            'success' => false,
            'error_code' => 'empty_reply',
            'error_message' => 'Provider boş yanıt döndü.',
        ]);

        api_error('Şu anda Pusula Ai’ye ulaşılamıyor. Lütfen biraz sonra tekrar dene.', 503);
    }

    $inputTokens = max(0, (int)($providerResult['input_tokens'] ?? 0));
    $outputTokens = max(0, (int)($providerResult['output_tokens'] ?? 0));

    $pdo->beginTransaction();
    try {
        pusula_ai_chat_insert_message($pdo, $conversationId, $userId, 'user', $message, null, 0, 0);
        $assistantMessageId = pusula_ai_chat_insert_message($pdo, $conversationId, $userId, 'assistant', $reply, $actionPayload, $inputTokens, $outputTokens);
        $pdo->commit();
    } catch (Throwable $txe) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $txe;
    }

    pusula_ai_chat_log_usage($pdo, $userId, [
        'conversation_id' => $conversationId,
        'provider' => (string)($settings['provider'] ?? ''),
        'model' => (string)($settings['model'] ?? ''),
        'token_in' => $inputTokens,
        'token_out' => $outputTokens,
        'estimated_cost' => 0,
        'success' => true,
        'error_code' => '',
        'error_message' => '',
    ]);

    $remainingAfter = max(0, $remainingBefore - 1);

    api_success('Mesaj işlendi.', [
        'conversation_id' => $conversationId,
        'reply' => $reply,
        'mode' => $mode,
        'provider' => (string)($settings['provider'] ?? ''),
        'model' => (string)($settings['model'] ?? ''),
        'remaining_limit' => $remainingAfter,
        'message_id' => $assistantMessageId,
        'created_at' => date('c'),
        'action_payload' => $actionPayload,
    ]);
} catch (Throwable $e) {
    error_log('[pusula_ai_chat][fatal] ' . $e->getMessage());
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}
