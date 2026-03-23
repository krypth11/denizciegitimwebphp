<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/daily_quiz_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $payload = api_get_request_data();

    $correctAnswers = filter_var($payload['correct_answers'] ?? null, FILTER_VALIDATE_INT);
    $totalQuestions = filter_var($payload['total_questions'] ?? null, FILTER_VALIDATE_INT);

    if ($correctAnswers === false || $totalQuestions === false) {
        api_error('correct_answers ve total_questions sayısal olmalıdır.', 422);
    }

    if ($correctAnswers < 0 || $totalQuestions < 0) {
        api_error('Negatif değer gönderilemez.', 422);
    }

    if ($correctAnswers > $totalQuestions) {
        api_error('correct_answers, total_questions değerinden büyük olamaz.', 422);
    }

    $progress = dq_save_today_progress($pdo, $userId, (int)$correctAnswers, (int)$totalQuestions);

    api_success('Daily quiz progress kaydedildi.', [
        'progress' => $progress,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}
