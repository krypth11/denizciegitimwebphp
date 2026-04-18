<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/pusula_ai_knowledge_helper.php';

require_admin();

function pusula_ai_knowledge_json_response(bool $success, string $message = '', array $data = [], int $status = 200, array $errors = []): void
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function pusula_ai_knowledge_post_payload(array $keys): array
{
    $payload = [];
    foreach ($keys as $key) {
        $payload[$key] = $_POST[$key] ?? null;
    }
    return $payload;
}

function pusula_ai_knowledge_parse_reorder_items($raw): array
{
    if (is_array($raw)) {
        return $raw;
    }

    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

try {
    if ($action === 'get_knowledge') {
        pusula_ai_knowledge_json_response(true, '', [
            'knowledge' => pusula_ai_get_knowledge($pdo),
            'tools' => pusula_ai_get_tool_settings($pdo),
            'examples' => pusula_ai_list_example_conversations($pdo),
        ]);
    }

    if ($action === 'save_general') {
        pusula_ai_save_knowledge_section($pdo, 'general', pusula_ai_knowledge_post_payload([
            'app_name', 'assistant_name', 'app_summary', 'target_users', 'tone_of_voice',
        ]));

        pusula_ai_knowledge_json_response(true, 'Genel bilgi ayarları kaydedildi.', [
            'knowledge' => pusula_ai_get_knowledge($pdo),
        ]);
    }

    if ($action === 'save_features') {
        pusula_ai_save_knowledge_section($pdo, 'features', pusula_ai_knowledge_post_payload([
            'app_features_text', 'premium_features_text', 'offline_features_text', 'community_features_text', 'exam_features_text',
        ]));

        pusula_ai_knowledge_json_response(true, 'Uygulama özellikleri kaydedildi.', [
            'knowledge' => pusula_ai_get_knowledge($pdo),
        ]);
    }

    if ($action === 'save_rules') {
        pusula_ai_save_knowledge_section($pdo, 'rules', pusula_ai_knowledge_post_payload([
            'allowed_topics_text', 'blocked_topics_text', 'response_style_text', 'emotional_style_text', 'short_reply_rules_text', 'long_reply_rules_text',
        ]));

        pusula_ai_knowledge_json_response(true, 'Davranış kuralları kaydedildi.', [
            'knowledge' => pusula_ai_get_knowledge($pdo),
        ]);
    }

    if ($action === 'save_prompts') {
        pusula_ai_save_knowledge_section($pdo, 'prompts', pusula_ai_knowledge_post_payload([
            'system_prompt_base', 'system_prompt_behavior', 'system_prompt_app_knowledge', 'system_prompt_stats_behavior', 'system_prompt_exam_behavior',
        ]));

        pusula_ai_knowledge_json_response(true, 'Sistem prompt katmanları kaydedildi.', [
            'knowledge' => pusula_ai_get_knowledge($pdo),
        ]);
    }

    if ($action === 'list_examples') {
        pusula_ai_knowledge_json_response(true, '', [
            'examples' => pusula_ai_list_example_conversations($pdo),
        ]);
    }

    if ($action === 'save_example') {
        try {
            $example = pusula_ai_save_example_conversation($pdo, [
                'id' => $_POST['id'] ?? '',
                'user_message' => $_POST['user_message'] ?? '',
                'assistant_reply' => $_POST['assistant_reply'] ?? '',
                'conversation_tag' => $_POST['conversation_tag'] ?? '',
                'is_active' => $_POST['is_active'] ?? 1,
                'order_index' => $_POST['order_index'] ?? 0,
            ]);
        } catch (InvalidArgumentException $e) {
            pusula_ai_knowledge_json_response(false, $e->getMessage(), [], 422, ['example' => 'invalid']);
        }

        pusula_ai_knowledge_json_response(true, 'Örnek konuşma kaydedildi.', [
            'example' => $example,
            'examples' => pusula_ai_list_example_conversations($pdo),
        ]);
    }

    if ($action === 'delete_example') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') {
            pusula_ai_knowledge_json_response(false, 'Silinecek kayıt bulunamadı.', [], 422, ['id' => 'required']);
        }

        pusula_ai_delete_example_conversation($pdo, $id);
        pusula_ai_knowledge_json_response(true, 'Örnek konuşma silindi.', [
            'examples' => pusula_ai_list_example_conversations($pdo),
        ]);
    }

    if ($action === 'reorder_examples') {
        $items = pusula_ai_knowledge_parse_reorder_items($_POST['items'] ?? []);
        pusula_ai_reorder_example_conversations($pdo, $items);
        pusula_ai_knowledge_json_response(true, 'Örnek konuşma sırası güncellendi.', [
            'examples' => pusula_ai_list_example_conversations($pdo),
        ]);
    }

    if ($action === 'save_tools') {
        pusula_ai_save_tool_settings($pdo, [
            'tool_stats_enabled' => $_POST['tool_stats_enabled'] ?? 0,
            'tool_exam_recommendation_enabled' => $_POST['tool_exam_recommendation_enabled'] ?? 0,
            'tool_app_info_enabled' => $_POST['tool_app_info_enabled'] ?? 0,
            'tool_action_payload_enabled' => $_POST['tool_action_payload_enabled'] ?? 0,
            'tool_weak_topics_enabled' => $_POST['tool_weak_topics_enabled'] ?? 0,
            'tool_last_exam_enabled' => $_POST['tool_last_exam_enabled'] ?? 0,
        ]);

        pusula_ai_knowledge_json_response(true, 'Tool yetkileri kaydedildi.', [
            'tools' => pusula_ai_get_tool_settings($pdo),
        ]);
    }

    if ($action === 'save_actions') {
        pusula_ai_save_knowledge_section($pdo, 'actions', [
            'action_button_intro_text' => $_POST['action_button_intro_text'] ?? '',
            'action_exam_enabled' => $_POST['action_exam_enabled'] ?? 0,
            'action_plan_enabled' => $_POST['action_plan_enabled'] ?? 0,
            'action_exam_default_question_count' => $_POST['action_exam_default_question_count'] ?? 10,
            'action_exam_default_mode' => $_POST['action_exam_default_mode'] ?? 'mini',
        ]);

        pusula_ai_knowledge_json_response(true, 'Action ayarları kaydedildi.', [
            'knowledge' => pusula_ai_get_knowledge($pdo),
        ]);
    }

    pusula_ai_knowledge_json_response(false, 'Geçersiz işlem.', [], 400, ['action' => 'invalid']);
} catch (Throwable $e) {
    pusula_ai_knowledge_json_response(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500, ['server' => 'error']);
}
