<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__) . '/mock_exam_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'mock-exams.availability');
    $courseId = trim((string)($_GET['course_id'] ?? ''));

    $qualificationId = $currentQualificationId;
    if ($courseId !== '') {
        $courseValidation = mock_exam_validate_course_for_qualification($pdo, $qualificationId, $courseId);
        if (empty($courseValidation['is_valid'])) {
            throw new RuntimeException((string)($courseValidation['message'] ?? 'Seçilen ders bu yeterliliğe ait değil.'));
        }
    }

    $examSettings = mock_exam_get_qualification_exam_settings($pdo, $qualificationId);
    $counts = mock_exam_calculate_pool_counts($pdo, $userId, $qualificationId, $courseId !== '' ? $courseId : null);
    $coursesRaw = mock_exam_get_qualification_courses_for_exam($pdo, $qualificationId);
    $courses = array_map(static function (array $course): array {
        return [
            'id' => (string)($course['id'] ?? ''),
            'name' => (string)($course['name'] ?? ''),
            'available_count' => (int)($course['available_count'] ?? 0),
        ];
    }, $coursesRaw);

    api_qualification_access_log('exam qualification returned', [
        'context' => 'mock-exams.availability',
        'exam qualification returned' => $currentQualificationId,
    ]);

    api_success('Deneme uygunluk bilgisi alındı.', [
        'qualification_id' => $qualificationId,
        'exam_settings' => [
            'question_count' => (int)($examSettings['question_count'] ?? 20),
            'passing_score' => (float)($examSettings['passing_score'] ?? 60),
            'duration_minutes' => (int)($examSettings['duration_minutes'] ?? 40),
            'is_active' => (int)($examSettings['is_active'] ?? 1),
        ],
        'courses' => $courses,

        'available_pool_counts' => $counts,
        'options' => [
            'random_available' => $counts['total'] > 0,
            'unseen_available' => $counts['unseen'] > 0,
            'seen_available' => $counts['seen'] > 0,
            'wrong_available' => ((int)($counts['wrong'] ?? 0)) > 0,
        ],
        'requested_question_count' => (int)($examSettings['question_count'] ?? 20),
        'supported_question_count' => min((int)($examSettings['question_count'] ?? 20), (int)($counts['total'] ?? 0)),
        'course_distribution_preview' => array_map(static function (array $c): array {
            return [
                'course_id' => $c['id'],
                'course_name' => $c['name'],
                'available_count' => $c['available_count'],
            ];
        }, $courses),
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 422);
}
