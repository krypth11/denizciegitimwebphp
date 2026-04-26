<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/study_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'questions.count');

    $questionColumns = get_table_columns($pdo, 'questions');
    if (!$questionColumns) {
        api_error('Sorular tablosu bulunamadı.', 500);
    }

    $hasQ = static fn(string $col): bool => in_array($col, $questionColumns, true);
    if (!$hasQ('id')) {
        api_error('Sorular tablosu şeması uyumsuz.', 500);
    }

    $courseId = api_validate_optional_id((string)($_GET['course_id'] ?? ''), 'course_id', 191);
    $topicId = api_validate_optional_id((string)($_GET['topic_id'] ?? ''), 'topic_id', 191);
    $questionType = trim((string)($_GET['question_type'] ?? ''));
    $poolType = strtolower(trim((string)($_GET['pool_type'] ?? 'all')));

    $supportedPoolTypes = ['all', 'unanswered', 'answered', 'most_wrong', 'bookmarked'];
    if (!in_array($poolType, $supportedPoolTypes, true)) {
        api_error('Geçersiz pool_type.', 422);
    }

    if ($questionType !== '' && mb_strlen($questionType) > 50) {
        api_error('Geçersiz question_type.', 422);
    }

    // Guard: course belongs to user's current qualification
    if ($courseId !== '') {
        $courseGuardStmt = $pdo->prepare('SELECT qualification_id FROM courses WHERE id = ? LIMIT 1');
        $courseGuardStmt->execute([$courseId]);
        $courseGuardRow = $courseGuardStmt->fetch(PDO::FETCH_ASSOC);
        if (!$courseGuardRow) {
            api_error('Kurs bulunamadı.', 404);
        }

        api_assert_requested_qualification_matches_current(
            $pdo,
            $auth,
            (string)($courseGuardRow['qualification_id'] ?? ''),
            'questions.count.course_guard'
        );
    }

    // Guard: topic belongs to user's current qualification
    if ($topicId !== '') {
        $topicGuardStmt = $pdo->prepare(
            'SELECT c.qualification_id
             FROM topics t
             INNER JOIN courses c ON t.course_id = c.id
             WHERE t.id = ?
             LIMIT 1'
        );
        $topicGuardStmt->execute([$topicId]);
        $topicGuardRow = $topicGuardStmt->fetch(PDO::FETCH_ASSOC);
        if (!$topicGuardRow) {
            api_error('Konu bulunamadı.', 404);
        }

        api_assert_requested_qualification_matches_current(
            $pdo,
            $auth,
            (string)($topicGuardRow['qualification_id'] ?? ''),
            'questions.count.topic_guard'
        );
    }

    $baseWhere = [];
    $baseParams = [];

    // Qualification filter on questions.
    if ($hasQ('qualification_id')) {
        $baseWhere[] = 'q.`qualification_id` = ?';
        $baseParams[] = $currentQualificationId;
    } elseif ($hasQ('course_id')) {
        $baseWhere[] = 'q.`course_id` IN (SELECT id FROM courses WHERE qualification_id = ?)';
        $baseParams[] = $currentQualificationId;
    } else {
        api_error('Qualification guard için gerekli kolonlar bulunamadı.', 500);
    }

    if ($courseId !== '' && $hasQ('course_id')) {
        $baseWhere[] = 'q.`course_id` = ?';
        $baseParams[] = $courseId;
    }

    if ($topicId !== '' && $hasQ('topic_id')) {
        $baseWhere[] = 'q.`topic_id` = ?';
        $baseParams[] = $topicId;
    }

    if ($questionType !== '' && $hasQ('question_type')) {
        $baseWhere[] = 'q.`question_type` = ?';
        $baseParams[] = $questionType;
    }

    $countQuery = static function (PDO $pdo, string $sql, array $params): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    };

    $availableCount = 0;

    if ($poolType === 'all') {
        $sql = 'SELECT COUNT(DISTINCT q.`id`) FROM `questions` q';
        if ($baseWhere) {
            $sql .= ' WHERE ' . implode(' AND ', $baseWhere);
        }
        $availableCount = $countQuery($pdo, $sql, $baseParams);
    } elseif ($poolType === 'most_wrong') {
        $ws = study_get_wrong_score_schema($pdo);
        $wsCount = 0;

        if (!empty($ws) && !empty($ws['user_id']) && !empty($ws['question_id']) && !empty($ws['wrong_score'])) {
            $wsWhere = $baseWhere;
            $wsWhere[] = 'ws.' . study_q($ws['wrong_score']) . ' > 0';

            $wsSql = 'SELECT COUNT(DISTINCT q.`id`)'
                . ' FROM `questions` q'
                . ' INNER JOIN ' . study_q($ws['table']) . ' ws'
                . ' ON ws.' . study_q($ws['question_id']) . ' = q.`id`'
                . ' AND ws.' . study_q($ws['user_id']) . ' = ?';
            $wsParams = [$userId];

            if (!empty($ws['qualification_id'])) {
                $wsSql .= ' AND ws.' . study_q($ws['qualification_id']) . ' = ?';
                $wsParams[] = $currentQualificationId;
            }

            if ($wsWhere) {
                $wsSql .= ' WHERE ' . implode(' AND ', $wsWhere);
            }

            $wsCount = $countQuery($pdo, $wsSql, array_merge($wsParams, $baseParams));
        }

        if ($wsCount > 0) {
            $availableCount = $wsCount;
        } else {
            $upColumns = get_table_columns($pdo, 'user_progress');
            if ($upColumns) {
                $up = study_get_user_progress_schema($pdo);
                $fallbackConditions = [];

                if (!empty($up['wrong_answer_count'])) {
                    $fallbackConditions[] = 'COALESCE(up.' . study_q($up['wrong_answer_count']) . ', 0) > 0';
                }

                if (!empty($up['is_answered']) && !empty($up['is_correct'])) {
                    $fallbackConditions[] = '(COALESCE(up.' . study_q($up['is_answered']) . ', 0) = 1 AND COALESCE(up.' . study_q($up['is_correct']) . ', 1) = 0)';
                }

                if ($fallbackConditions) {
                    $sql = 'SELECT COUNT(DISTINCT q.`id`)'
                        . ' FROM `questions` q'
                        . ' INNER JOIN ' . study_q($up['table']) . ' up'
                        . ' ON up.' . study_q($up['question_id']) . ' = q.`id`'
                        . ' AND up.' . study_q($up['user_id']) . ' = ?';

                    $where = $baseWhere;
                    $where[] = '(' . implode(' OR ', $fallbackConditions) . ')';
                    if ($where) {
                        $sql .= ' WHERE ' . implode(' AND ', $where);
                    }

                    $availableCount = $countQuery($pdo, $sql, array_merge([$userId], $baseParams));
                }
            }
        }
    } else {
        $upColumns = get_table_columns($pdo, 'user_progress');
        if (!$upColumns) {
            if ($poolType === 'unanswered') {
                $sql = 'SELECT COUNT(DISTINCT q.`id`) FROM `questions` q';
                if ($baseWhere) {
                    $sql .= ' WHERE ' . implode(' AND ', $baseWhere);
                }
                $availableCount = $countQuery($pdo, $sql, $baseParams);
            } else {
                $availableCount = 0;
            }
        } else {
            $up = study_get_user_progress_schema($pdo);

            $answeredExpr = '';
            if (!empty($up['is_answered'])) {
                $answeredExpr = 'COALESCE(up.' . study_q($up['is_answered']) . ', 0) = 1';
            } elseif (!empty($up['total_answer_count'])) {
                $answeredExpr = 'COALESCE(up.' . study_q($up['total_answer_count']) . ', 0) > 0';
            } elseif (!empty($up['question_id'])) {
                $answeredExpr = 'up.' . study_q($up['question_id']) . ' IS NOT NULL';
            }

            if ($poolType === 'unanswered') {
                $sql = 'SELECT COUNT(DISTINCT q.`id`)'
                    . ' FROM `questions` q'
                    . ' LEFT JOIN ' . study_q($up['table']) . ' up'
                    . ' ON up.' . study_q($up['question_id']) . ' = q.`id`'
                    . ' AND up.' . study_q($up['user_id']) . ' = ?';

                $where = $baseWhere;
                if ($answeredExpr !== '') {
                    $where[] = '(up.' . study_q($up['question_id']) . ' IS NULL OR NOT (' . $answeredExpr . '))';
                } else {
                    $where[] = 'up.' . study_q($up['question_id']) . ' IS NULL';
                }

                if ($where) {
                    $sql .= ' WHERE ' . implode(' AND ', $where);
                }

                $availableCount = $countQuery($pdo, $sql, array_merge([$userId], $baseParams));
            } elseif ($poolType === 'answered') {
                if ($answeredExpr === '') {
                    $availableCount = 0;
                } else {
                    $sql = 'SELECT COUNT(DISTINCT q.`id`)'
                        . ' FROM `questions` q'
                        . ' INNER JOIN ' . study_q($up['table']) . ' up'
                        . ' ON up.' . study_q($up['question_id']) . ' = q.`id`'
                        . ' AND up.' . study_q($up['user_id']) . ' = ?';

                    $where = $baseWhere;
                    $where[] = $answeredExpr;
                    if ($where) {
                        $sql .= ' WHERE ' . implode(' AND ', $where);
                    }

                    $availableCount = $countQuery($pdo, $sql, array_merge([$userId], $baseParams));
                }
            } elseif ($poolType === 'bookmarked') {
                if (empty($up['is_bookmarked'])) {
                    $availableCount = 0;
                } else {
                    $sql = 'SELECT COUNT(DISTINCT q.`id`)'
                        . ' FROM `questions` q'
                        . ' INNER JOIN ' . study_q($up['table']) . ' up'
                        . ' ON up.' . study_q($up['question_id']) . ' = q.`id`'
                        . ' AND up.' . study_q($up['user_id']) . ' = ?';

                    $where = $baseWhere;
                    $where[] = 'COALESCE(up.' . study_q($up['is_bookmarked']) . ', 0) = 1';
                    if ($where) {
                        $sql .= ' WHERE ' . implode(' AND ', $where);
                    }

                    $availableCount = $countQuery($pdo, $sql, array_merge([$userId], $baseParams));
                }
            }
        }
    }

    api_success('Müsait soru sayısı getirildi.', [
        'available_count' => max(0, (int)$availableCount),
        'pool_type' => $poolType,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}
