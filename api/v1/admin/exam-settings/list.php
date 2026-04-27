<?php

require_once dirname(__DIR__, 2) . '/api_bootstrap.php';
require_once dirname(__DIR__, 2) . '/auth_helper.php';
require_once dirname(__DIR__, 2) . '/mock_exam_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    if (empty($auth['user']['is_admin'])) {
        api_error('Admin yetkisi gerekli.', 403);
    }

    $qualificationCols = get_table_columns($pdo, 'qualifications');
    if (!$qualificationCols) {
        throw new RuntimeException('qualifications tablosu bulunamadı.');
    }

    $idCol = mock_exam_pick($qualificationCols, ['id'], true);
    $nameCol = mock_exam_pick($qualificationCols, ['name'], true);
    $isActiveCol = mock_exam_pick($qualificationCols, ['is_active'], false);

    $sql = 'SELECT q.' . mock_exam_q($idCol) . ' AS id, q.' . mock_exam_q($nameCol) . ' AS name';
    if ($isActiveCol) {
        $sql .= ', q.' . mock_exam_q($isActiveCol) . ' AS qualification_is_active';
    } else {
        $sql .= ', 1 AS qualification_is_active';
    }
    $sql .= ' FROM `qualifications` q ORDER BY q.' . mock_exam_q($nameCol) . ' ASC';

    $stmt = $pdo->query($sql);
    $qualifications = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $items = [];
    foreach ($qualifications as $q) {
        $qualificationId = (string)($q['id'] ?? '');
        if ($qualificationId === '') {
            continue;
        }

        $settings = mock_exam_ensure_qualification_exam_settings($pdo, $qualificationId);
        $items[] = [
            'qualification_id' => $qualificationId,
            'qualification_name' => (string)($q['name'] ?? ''),
            'question_count' => (int)($settings['question_count'] ?? 20),
            'passing_score' => (float)($settings['passing_score'] ?? 60),
            'duration_minutes' => (int)($settings['duration_minutes'] ?? 40),
            'is_active' => (int)($settings['is_active'] ?? 1),
            'qualification_is_active' => ((int)($q['qualification_is_active'] ?? 1) === 1) ? 1 : 0,
        ];
    }

    api_success('Sınav ayarları getirildi.', [
        'items' => $items,
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 422);
}
