<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__) . '/mock_exam_helper.php';
require_once dirname(__DIR__, 2) . '/includes/app_runtime_settings_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'mock-exams.availability');

    $qualificationId = api_require_query_param('qualification_id');
    api_qualification_access_log('requested qualification', [
        'context' => 'mock-exams.availability.query',
        'requested_qualification_id' => $qualificationId,
        'current_qualification_id' => $currentQualificationId,
    ]);
    $qualificationId = $currentQualificationId;
    $runtimeSettings = app_runtime_settings_get($pdo);
    $mockExamQuestionCount = app_runtime_settings_int($runtimeSettings, 'mock_exam_question_count', 20);
    $requested = api_get_int_query('requested_question_count', $mockExamQuestionCount, 1, $mockExamQuestionCount);

    $counts = mock_exam_calculate_pool_counts($pdo, $userId, $qualificationId);
    $courses = mock_exam_fetch_qualification_courses($pdo, $qualificationId);
    $candidates = mock_exam_fetch_candidate_questions($pdo, $qualificationId);

    $courseCountMap = [];
    foreach ($candidates as $q) {
        $cid = (string)($q['course_id'] ?? '');
        if ($cid === '') {
            continue;
        }
        $courseCountMap[$cid] = ($courseCountMap[$cid] ?? 0) + 1;
    }

    $distribution = [];
    foreach ($courses as $c) {
        $cid = (string)$c['id'];
        $distribution[] = [
            'course_id' => $cid,
            'course_name' => (string)($c['name'] ?? ''),
            'available_count' => (int)($courseCountMap[$cid] ?? 0),
        ];
    }

    $unseenMsg = null;
    if ($counts['unseen'] <= 0) {
        $unseenMsg = 'Tüm soruları çözdünüz';
    } elseif ($counts['unseen'] < $requested) {
        $unseenMsg = 'Çözülmemiş havuzda ' . $counts['unseen'] . ' soru var. Kalan kısım rastgele tamamlanacaktır.';
    }

    $wrongMsg = null;
    $wrongCount = (int)($counts['wrong'] ?? 0);
    if ($wrongCount <= 0) {
        $wrongMsg = 'Yanlış yaptığınız soru yok';
    } elseif ($wrongCount < $requested) {
        $remain = $requested - $wrongCount;
        $wrongMsg = 'Yanlış yaptığınız ' . $wrongCount . ' soru bulundu. Kalan ' . $remain . ' soru rastgele tamamlanacaktır.';
    }

    api_qualification_access_log('exam qualification returned', [
        'context' => 'mock-exams.availability',
        'exam qualification returned' => $currentQualificationId,
    ]);

    api_success('Deneme uygunluk bilgisi alındı.', [
        'available_pool_counts' => $counts,
        'options' => [
            'random_available' => $counts['total'] > 0,
            'unseen_available' => $counts['unseen'] > 0,
            'seen_available' => $counts['seen'] > 0,
            'wrong_available' => $wrongCount > 0,
        ],
        'unseen_message' => $unseenMsg,
        'wrong_message' => $wrongMsg,
        'requested_question_count' => $requested,
        'qualification_id' => $qualificationId,
        'supported_question_count' => max(0, min($mockExamQuestionCount, (int)$counts['total'])),
        'course_distribution_preview' => $distribution,
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 422);
}
