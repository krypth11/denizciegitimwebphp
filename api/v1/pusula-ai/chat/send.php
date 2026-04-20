<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/pusula_ai_chat_helper.php';

api_require_method('POST');

try {
    $authContext = pusula_ai_api_require_auth_context($pdo);
    $userId = (string)$authContext['user_id'];

    $settings = pusula_ai_api_settings($pdo);
    if (!pusula_ai_api_feature_enabled($settings)) {
        pusula_ai_api_send_feature_disabled($settings, 403);
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

    $finalizeReply = static function (string $candidateReply, string $intentValue, string $userMessageValue, ?array $actionPayloadValue = null): string {
        $raw = trim($candidateReply);
        $originalLen = function_exists('mb_strlen') ? mb_strlen($raw, 'UTF-8') : strlen($raw);

        $sanitized = pusula_ai_sanitize_output($raw);
        $markersRemoved = ($sanitized !== $raw);

        $polished = pusula_ai_polish_response($sanitized, [
            'intent' => $intentValue,
            'user_message' => $userMessageValue,
            'strict_preservation' => true,
        ]);

        $final = trim($polished !== '' ? $polished : $sanitized);
        $final = pusula_ai_chat_guard_fake_action_claims($final, $actionPayloadValue);
        $finalLen = function_exists('mb_strlen') ? mb_strlen($final, 'UTF-8') : strlen($final);

        pusula_ai_chat_debug_trace('response_finalize', [
            'markers_removed' => $markersRemoved,
            'polished' => ($polished !== $sanitized),
            'strict_preserved' => true,
            'original_len' => $originalLen,
            'final_len' => $finalLen,
        ]);

        return $final;
    };

    $conversationId = pusula_ai_chat_resolve_conversation($pdo, $userId, $conversationId, $mode, $message);
    $actionPayload = null;
    $resolvedNavigationTargetForResponse = '';

    $shortFollowupResolution = pusula_ai_chat_resolve_short_followup_action(
        $pdo,
        $conversationId,
        $userId,
        $message,
        $knowledgeBundle
    );
    if (!empty($shortFollowupResolution['matched'])) {
        $shortReply = trim((string)($shortFollowupResolution['reply'] ?? ''));
        $shortActionPayload = is_array($shortFollowupResolution['action_payload'] ?? null)
            ? $shortFollowupResolution['action_payload']
            : null;
        $shortIntent = trim((string)($shortFollowupResolution['intent'] ?? 'short_followup_action'));

        if ($shortReply === '') {
            $shortReply = 'İstersen şimdi başlatabilirim.';
        }
        $shortReply = $finalizeReply($shortReply, $shortIntent, $message, $shortActionPayload);

        pusula_ai_chat_debug_trace('deterministic_short_followup_action', [
            'intent' => $shortIntent,
            'action_payload_generated' => is_array($shortActionPayload),
        ]);

        $pdo->beginTransaction();
        try {
            pusula_ai_chat_insert_message($pdo, $conversationId, $userId, 'user', $message, null, 0, 0);
            $assistantMessageId = pusula_ai_chat_insert_message($pdo, $conversationId, $userId, 'assistant', $shortReply, $shortActionPayload, 0, 0);
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
            'success' => true,
            'error_code' => '',
            'error_message' => '',
        ]);

        $remainingAfter = max(0, $remainingBefore - 1);

        api_success('Mesaj işlendi.', [
            'conversation_id' => $conversationId,
            'reply' => $shortReply,
            'mode' => $mode,
            'provider' => (string)($settings['provider'] ?? ''),
            'model' => (string)($settings['model'] ?? ''),
            'remaining_limit' => $remainingAfter,
            'message_id' => $assistantMessageId,
            'created_at' => date('c'),
            'action_payload' => $shortActionPayload,
        ]);
    }

    $policyHardBlock = pusula_ai_chat_detect_policy_hard_block($message);
    if (is_array($policyHardBlock)) {
        $reply = (string)($policyHardBlock['reply'] ?? pusula_ai_chat_policy_hard_block_reply());
        $reply = $finalizeReply($reply, 'policy_hard_block', $message, null);

        $pdo->beginTransaction();
        try {
            pusula_ai_chat_insert_message($pdo, $conversationId, $userId, 'user', $message, null, 0, 0);
            $assistantMessageId = pusula_ai_chat_insert_message($pdo, $conversationId, $userId, 'assistant', $reply, null, 0, 0);
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
            'success' => true,
            'error_code' => 'policy_hard_block',
            'error_message' => (string)($policyHardBlock['category'] ?? 'policy_hard_block'),
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
            'action_payload' => null,
        ]);
    }

    $moderation = pusula_ai_chat_moderate_message($message);
    $userIntent = trim((string)($moderation['intent'] ?? ''));
    $normalizedIntent = detectIntent($message, $knowledgeBundle);
    if ($userIntent === '') {
        $userIntent = $normalizedIntent;
    }

    $softIntentResolution = [
        'intent' => '',
        'target' => '',
        'confidence' => 0.0,
        'reason' => '',
        'clarification_needed' => false,
        'clarification_question' => '',
    ];
    $forceRecommendedExamFromSoftIntent = false;

    $navigationResolution = pusula_ai_chat_resolve_navigation_action($message, $knowledgeBundle, [
        'intent' => $userIntent,
        'debug' => true,
    ]);
    $navigationTarget = is_array($navigationResolution['target'] ?? null)
        ? (string)(($navigationResolution['target']['target'] ?? '') ?: '')
        : '';

    $hardNavigationTargets = [
        'study',
        'statistics',
        'community',
        'offline',
        'maritime_english',
        'word_game',
        'card_game',
        'exams',
        'pusula_ai',
    ];
    $isHardNavigationRoute = is_array($navigationResolution['target'] ?? null)
        && $navigationTarget !== ''
        && in_array($navigationTarget, $hardNavigationTargets, true);

    // HARD PRIORITY: navigation fiili + target çözüldüyse intent kesin navigation_request olmalı.
    if (!empty($navigationResolution['intent_detected']) && $navigationTarget !== '') {
        $userIntent = 'navigation_request';
    }

    pusula_ai_chat_debug_trace('intent_routing', [
        'hard_navigation_detected' => $isHardNavigationRoute,
        'hard_navigation_target' => $navigationTarget,
    ]);

    if ($isHardNavigationRoute) {
        $userIntent = 'navigation_request';
        $resolvedNavigationTargetForResponse = $navigationTarget;
        $actionPayload = is_array($navigationResolution['payload'] ?? null)
            ? $navigationResolution['payload']
            : null;
        if (!is_array($actionPayload) && $navigationTarget !== '') {
            $actionPayload = pusula_ai_chat_build_navigation_payload($navigationTarget);
        }

        if (!is_array($actionPayload)) {
            pusula_ai_chat_debug_trace('navigation_payload_missing_unexpected', [
                'intent' => $userIntent,
                'target' => $navigationTarget,
                'source' => 'navigation_hard_route',
            ]);
        }

        $reply = trim((string)($navigationResolution['reply'] ?? ''));
        if ($reply === '') {
            $reply = pusula_ai_chat_build_navigation_reply_from_target(
                is_array($navigationResolution['target'] ?? null) ? $navigationResolution['target'] : null
            );
        }

        pusula_ai_chat_debug_trace('navigation_hard_route_taken', [
            'intent' => $userIntent,
            'target' => $navigationTarget,
            'payload_generated' => is_array($actionPayload),
        ]);
        pusula_ai_chat_debug_trace('llm_skipped_for_navigation', [
            'target' => $navigationTarget,
            'reason' => 'hard_navigation_route',
        ]);

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
            'success' => true,
            'error_code' => '',
            'error_message' => '',
        ]);

        $remainingAfter = max(0, $remainingBefore - 1);

        if ($resolvedNavigationTargetForResponse !== '' && !is_array($actionPayload)) {
            $actionPayload = pusula_ai_chat_build_navigation_payload($resolvedNavigationTargetForResponse);
        }

        $responseData = [
            'conversation_id' => $conversationId,
            'reply' => $reply,
            'mode' => $mode,
            'provider' => (string)($settings['provider'] ?? ''),
            'model' => (string)($settings['model'] ?? ''),
            'remaining_limit' => $remainingAfter,
            'message_id' => $assistantMessageId,
            'created_at' => date('c'),
            'action_payload' => $actionPayload,
        ];

        pusula_ai_chat_debug_trace('navigation_response_payload_status', [
            'navigation_response_payload_attached' => is_array($responseData['action_payload'] ?? null),
            'navigation_target' => (string)(
                (is_array($responseData['action_payload'] ?? null) ? ($responseData['action_payload']['target'] ?? '') : '')
                ?: $resolvedNavigationTargetForResponse
            ),
            'response_keys' => array_keys($responseData),
        ]);

        api_success('Mesaj işlendi.', $responseData);
    }

    // SOFT PRIORITY: hard route yoksa doğal niyet kümelerinden aksiyon çıkar.
    $softIntentResolution = pusula_ai_chat_resolve_soft_action_intent($message, $knowledgeBundle, [
        'intent' => $userIntent,
        'debug' => true,
    ]);

    $softIntentDetected = (string)($softIntentResolution['intent'] ?? '') === 'soft_navigation_request';
    $softTarget = trim((string)($softIntentResolution['target'] ?? ''));
    $softConfidence = (float)($softIntentResolution['confidence'] ?? 0);
    $softClarificationNeeded = !empty($softIntentResolution['clarification_needed']);

    pusula_ai_chat_debug_trace('intent_routing', [
        'hard_navigation_detected' => false,
        'soft_intent_detected' => $softIntentDetected,
        'soft_target' => $softTarget,
        'soft_confidence' => $softConfidence,
        'clarification_needed' => $softClarificationNeeded,
    ]);

    if ($softIntentDetected && $softClarificationNeeded && $softConfidence >= 0.45 && $softConfidence < 0.75) {
        $reply = trim((string)($softIntentResolution['clarification_question'] ?? ''));
        if ($reply === '') {
            $reply = 'Seni çalışma alanına mı, yoksa deneme alanına mı yönlendireyim?';
        }
        $reply = $finalizeReply($reply, 'soft_navigation_clarification', $message, null);

        $pdo->beginTransaction();
        try {
            pusula_ai_chat_insert_message($pdo, $conversationId, $userId, 'user', $message, null, 0, 0);
            $assistantMessageId = pusula_ai_chat_insert_message($pdo, $conversationId, $userId, 'assistant', $reply, null, 0, 0);
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
            'action_payload' => null,
        ]);
    }

    if ($softIntentDetected && $softConfidence >= 0.75 && $softTarget !== '') {
        if ($softTarget === 'exams' && pusula_ai_chat_soft_exam_prefers_recommended($message)) {
            $userIntent = 'exam_request';
            $forceRecommendedExamFromSoftIntent = true;
        } else {
            $actionPayload = pusula_ai_chat_build_navigation_payload($softTarget);
            $reply = pusula_ai_chat_build_soft_navigation_reply($softTarget);
            $reply = $finalizeReply($reply, 'soft_navigation_request', $message, $actionPayload);

            pusula_ai_chat_debug_trace('intent_routing', [
                'soft_intent_detected' => true,
                'soft_target' => $softTarget,
                'soft_confidence' => $softConfidence,
                'clarification_needed' => false,
                'action_payload_generated' => is_array($actionPayload),
            ]);

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
        }
    }

    $userContext = pusula_ai_chat_fetch_user_context($pdo, $userId);
    $trustedContext = pusula_ai_chat_build_trusted_context($pdo, $userId, $userIntent, $knowledgeBundle, $message);
    $actionPayload = pusula_ai_chat_detect_action_payload_from_bundle($message, $knowledgeBundle, [
        'intent' => $userIntent,
        'user_context' => $userContext,
        'trusted_context' => $trustedContext,
        'pdo' => $pdo,
        'user_id' => $userId,
        'debug_navigation' => true,
    ]);

    if ($forceRecommendedExamFromSoftIntent && !is_array($actionPayload)) {
        $actionPayload = pusula_ai_chat_detect_action_payload_from_bundle($message, $knowledgeBundle, [
            'intent' => 'exam_request',
            'user_context' => $userContext,
            'trusted_context' => $trustedContext,
            'pdo' => $pdo,
            'user_id' => $userId,
            'debug_navigation' => true,
        ]);

        if (!is_array($actionPayload)) {
            $actionPayload = pusula_ai_chat_build_navigation_payload('exams');
        }
    }

    if ($userIntent === 'navigation_request' && is_array($navigationResolution['target'] ?? null)) {
        $resolvedNavigationTarget = (string)(($navigationResolution['target']['target'] ?? '') ?: '');
        $resolvedNavigationTargetForResponse = $resolvedNavigationTarget;

        // navigation_request + target çözüldüyse payload zorunlu akış.
        $actionPayload = is_array($navigationResolution['payload'] ?? null)
            ? $navigationResolution['payload']
            : null;
        if (!is_array($actionPayload) && $resolvedNavigationTarget !== '') {
            $actionPayload = pusula_ai_chat_build_navigation_payload($resolvedNavigationTarget);
        }

        pusula_ai_chat_debug_trace('navigation_request_resolution', [
            'navigation_request_target' => $resolvedNavigationTarget,
            'navigation_payload_generated' => is_array($actionPayload),
            'navigation_fell_back_to_info' => false,
            'source' => 'send_enforcement',
        ]);

        if (!is_array($actionPayload)) {
            pusula_ai_chat_debug_trace('navigation_payload_missing_unexpected', [
                'intent' => $userIntent,
                'target' => $resolvedNavigationTarget,
                'source' => 'send_enforcement',
            ]);
        }
    }

    $selectedBlockTitles = [];
    $selectedBlockCount = 0;
    $masterContextText = trim((string)(($knowledgeBundle['knowledge']['master_context_text'] ?? '') ?: ''));
    if ($masterContextText !== '') {
        $masterLayersDebug = pusula_ai_chat_prepare_master_context_layers(
            (array)($knowledgeBundle['knowledge'] ?? []),
            $message,
            $userIntent
        );
        $selectedBlockTitles = is_array($masterLayersDebug['selected_titles'] ?? null)
            ? $masterLayersDebug['selected_titles']
            : [];
        $selectedBlockCount = (int)($masterLayersDebug['selected_block_count'] ?? count($selectedBlockTitles));
    }

    pusula_ai_chat_debug_trace('intent_resolution', [
        'resolved_intent' => $userIntent,
        'resolved_target' => $navigationTarget,
        'selected_block_titles' => $selectedBlockTitles,
        'selected_block_count' => $selectedBlockCount,
        'navigation_payload_generated' => is_array($actionPayload),
    ]);
    pusula_ai_chat_debug_trace('selected_block_titles', [
        'intent' => $userIntent,
        'titles' => $selectedBlockTitles,
    ]);
    pusula_ai_chat_debug_trace('selected_block_count', [
        'intent' => $userIntent,
        'count' => $selectedBlockCount,
    ]);

    pusula_ai_chat_debug_trace('pre_response', [
        'intent' => $userIntent,
        'trusted_context_available' => !empty($trustedContext['available']),
        'action_payload_generated' => is_array($actionPayload),
        'hard_navigation_detected' => $isHardNavigationRoute,
        'soft_intent_detected' => $softIntentDetected,
        'soft_target' => $softTarget,
        'soft_confidence' => $softConfidence,
        'clarification_needed' => $softClarificationNeeded,
    ]);

    if (empty($moderation['allowed'])) {
        $reply = (string)($moderation['reply'] ?? pusula_ai_chat_rejection_text());
        $reply = $finalizeReply($reply, $userIntent, $message, $actionPayload);
        $actionPayload = null;

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

    $intentSafeReply = pusula_ai_chat_build_intent_safe_reply($userIntent, $trustedContext, $knowledgeBundle, $message);

    if ($userIntent === 'exam_request' && !is_array($actionPayload)) {
        $actionPayload = pusula_ai_chat_detect_action_payload_from_bundle($message, $knowledgeBundle, [
            'intent' => 'exam_request',
            'user_context' => $userContext,
            'trusted_context' => $trustedContext,
            'pdo' => $pdo,
            'user_id' => $userId,
            'debug_navigation' => true,
        ]);
    }

    if (is_string($intentSafeReply) && trim($intentSafeReply) !== '') {
        $reply = pusula_ai_chat_enforce_reply_style($userIntent, $intentSafeReply, $trustedContext);
        $reply = pusula_ai_chat_sanitize_reply_links($userIntent, $reply, $trustedContext);
        $reply = pusula_ai_chat_enforce_action_card_language($userIntent, $reply, is_array($actionPayload));
        $reply = $finalizeReply($reply, $userIntent, $message, $actionPayload);
        $inputTokens = 0;
        $outputTokens = 0;

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

        pusula_ai_chat_debug_trace('short_circuit_reply', [
            'intent' => $userIntent,
            'trusted_context_available' => !empty($trustedContext['available']),
            'action_payload_generated' => is_array($actionPayload),
        ]);

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
    }

    $systemPrompt = pusula_ai_chat_build_system_prompt($mode, $userContext, [
        'user_intent' => $userIntent,
        'user_message' => $message,
        'moderation_reason' => (string)($moderation['reason'] ?? ''),
        'user_message_length' => mb_strlen($message, 'UTF-8'),
        'user_wants_detailed' => pusula_ai_chat_user_wants_detailed_reply($message),
        'provider' => (string)($settings['provider'] ?? ''),
        'model' => (string)($settings['model'] ?? ''),
        'knowledge_bundle' => $knowledgeBundle,
        'trusted_context' => $trustedContext,
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
    $reply = pusula_ai_chat_enforce_reply_style($userIntent, $reply, $trustedContext);
    $reply = pusula_ai_chat_sanitize_reply_links($userIntent, $reply, $trustedContext);
    $reply = pusula_ai_chat_enforce_action_card_language($userIntent, $reply, is_array($actionPayload));
    $reply = $finalizeReply($reply, $userIntent, $message, $actionPayload);
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

    pusula_ai_chat_debug_trace('provider_reply', [
        'intent' => $userIntent,
        'trusted_context_available' => !empty($trustedContext['available']),
        'action_payload_generated' => is_array($actionPayload),
    ]);

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
