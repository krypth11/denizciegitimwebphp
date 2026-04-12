<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $qualificationId = api_require_current_user_qualification_id($pdo, $auth, 'usage.consume-study-open');

    $payload = api_get_request_data();
    $questionId = trim((string)($payload['question_id'] ?? ''));
    $courseId = trim((string)($payload['course_id'] ?? ''));
    $topicId = trim((string)($payload['topic_id'] ?? ''));
    $source = strtolower(trim((string)($payload['source'] ?? 'study')));

    if ($questionId === '') {
        api_error('question_id zorunludur.', 422);
    }
    if (mb_strlen($questionId) > 191) {
        api_error('Geçersiz question_id.', 422);
    }

    if ($source !== 'study') {
        api_error('Bu endpoint sadece source=study için kullanılabilir.', 422);
    }

    $consume = usage_limits_consume(
        $pdo,
        $userId,
        $qualificationId,
        USAGE_LIMIT_FEATURE_STUDY_QUESTION_OPEN,
        1
    );

    if (empty($consume['consumed']) && empty($consume['is_pro'])) {
        usage_limits_business_error(
            'STUDY_DAILY_LIMIT_REACHED',
            'Günlük çalışma soru açma limitine ulaştınız.',
            429,
            $consume['summary'] ?? null
        );
    }

    api_success('Çalışma kullanım hakkı işlendi.', [
        'question_id' => $questionId,
        'course_id' => ($courseId !== '' ? $courseId : null),
        'topic_id' => ($topicId !== '' ? $topicId : null),
        'source' => 'study',
        'summary' => $consume['summary'] ?? null,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}
