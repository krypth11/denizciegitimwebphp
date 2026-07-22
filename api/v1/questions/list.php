<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/study_helper.php';
require_once dirname(__DIR__, 3) . '/includes/app_runtime_settings_helper.php';
require_once __DIR__ . '/question_filters_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    questions_send_private_no_store_headers();

    $userId = (string)$auth['user']['id'];
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'questions.list');

    $columns = get_table_columns($pdo, 'questions');
    if (!$columns) {
        api_error('Sorular tablosu bulunamadı.', 500);
    }

    $hasCol = static fn(string $col): bool => in_array($col, $columns, true);

    $qualificationId = api_validate_optional_id((string)($_GET['qualification_id'] ?? ''), 'qualification_id', 191);
    $courseId = api_validate_optional_id((string)($_GET['course_id'] ?? ''), 'course_id', 191);
    $topicId = api_validate_optional_id((string)($_GET['topic_id'] ?? ''), 'topic_id', 191);
    $topicIdsRaw = trim((string)($_GET['topic_ids'] ?? ''));
    $topicIds = $topicIdsRaw !== '' ? explode(',', $topicIdsRaw) : [];
    $questionType = trim((string)($_GET['question_type'] ?? ''));
    $sourceType = (string)($_GET['source_type'] ?? '');
    $poolTypeRaw = (string)($_GET['pool_type'] ?? 'all');
    $poolType = questions_normalize_pool_type($poolTypeRaw);
    if ($poolType === null) {
        api_error('Geçersiz pool_type.', 422);
    }
    $orderParam = strtolower(trim((string)($_GET['order'] ?? 'desc')));
    $runtime = app_runtime_settings_get($pdo);

    $allQuestionsPoolTypes = ['all', 'all_questions', 'all-questions', 'tum_sorular', 'tum-sorular'];
    if (in_array(strtolower(trim($poolTypeRaw)), $allQuestionsPoolTypes, true)) {
        $allQuestionsMaxLimit = app_runtime_settings_int($runtime, 'study_all_questions_max_limit', 1000);
        $limit = api_get_int_query('limit', $allQuestionsMaxLimit, 1, $allQuestionsMaxLimit);
    } else {
        $limit = api_get_int_query('limit', 200, 1, 10000);
    }

    $isRandomOrder = in_array($orderParam, ['random', 'rand'], true);
    $order = in_array($orderParam, ['asc', 'desc'], true) ? strtoupper($orderParam) : 'DESC';

    $q = 'q';
    $qc = static fn(string $col): string => $q . '.`' . str_replace('`', '', $col) . '`';

    $filterBuild = build_question_filters($pdo, [
        'auth' => $auth,
        'current_qualification_id' => $currentQualificationId,
        'qualification_id' => $qualificationId,
        'course_id' => $courseId,
        'topic_id' => $topicId,
        'topic_ids' => $topicIds,
        'question_type' => $questionType,
        'source_type' => $sourceType,
        'question_columns' => $columns,
        'question_alias' => 'q',
        'qualification_guard_context' => 'questions.list.qualification_guard',
        'course_guard_context' => 'questions.list.course_guard',
        'topic_guard_context' => 'questions.list.topic_guard',
    ]);

    $where = $filterBuild['where'];
    $params = $filterBuild['params'];
    $scopeLinksAvailable = !empty($filterBuild['scope_links_available']);
    $normalizedTopicIds = questions_normalize_topic_ids($topicIds);
    $filterContext = questions_build_filter_context($filterBuild, $poolType, $courseId, $normalizedTopicIds);
    $filterSignature = questions_build_filter_signature($filterContext);

    $scopedCourseExpr = $hasCol('course_id') ? $qc('course_id') : 'NULL';
    $scopedTopicExpr = $hasCol('topic_id') ? $qc('topic_id') : 'NULL';
    if ($scopeLinksAvailable && $hasCol('course_id')) {
        $scopeQualificationSql = $pdo->quote((string)$filterBuild['requested_qualification_id']);
        $scopeCourseFilterSql = ($courseId !== '') ? (' AND qsl.course_id = ' . $pdo->quote($courseId)) : '';
        if ($normalizedTopicIds) {
            $scopeTopicFilterSql = ' AND qsl.topic_id IN (' . implode(', ', array_map([$pdo, 'quote'], $normalizedTopicIds)) . ')';
        } else {
            $scopeTopicFilterSql = ($topicId !== '') ? (' AND qsl.topic_id = ' . $pdo->quote($topicId)) : '';
        }

        $scopeBase = 'qsl.question_id = ' . $qc('id')
            . ' AND qsl.qualification_id = ' . $scopeQualificationSql
            . $scopeCourseFilterSql
            . $scopeTopicFilterSql;

        $scopedCourseExpr = 'COALESCE((SELECT qsl.course_id FROM question_scope_links qsl WHERE ' . $scopeBase . ' ORDER BY qsl.is_primary DESC, qsl.id ASC LIMIT 1), ' . $qc('course_id') . ')';
        if ($hasCol('topic_id')) {
            $scopedTopicExpr = 'COALESCE((SELECT qsl.topic_id FROM question_scope_links qsl WHERE ' . $scopeBase . ' ORDER BY qsl.is_primary DESC, qsl.id ASC LIMIT 1), ' . $qc('topic_id') . ')';
        }
    }

    $select = [
        $hasCol('id') ? ($qc('id') . ' AS id') : "'' AS id",
        $scopedTopicExpr . ' AS topic_id',
        $scopedCourseExpr . ' AS course_id',
        $hasCol('topic_id') ? ($qc('topic_id') . ' AS original_topic_id') : 'NULL AS original_topic_id',
        $hasCol('course_id') ? ($qc('course_id') . ' AS original_course_id') : 'NULL AS original_course_id',
        $hasCol('question_type') ? ($qc('question_type') . ' AS question_type') : "'' AS question_type",
        $hasCol('source_type') ? ($qc('source_type') . ' AS source_type') : "'' AS source_type",
        $hasCol('question_text') ? ($qc('question_text') . ' AS question_text') : "'' AS question_text",
        $hasCol('option_a') ? ($qc('option_a') . ' AS option_a') : "'' AS option_a",
        $hasCol('option_b') ? ($qc('option_b') . ' AS option_b') : "'' AS option_b",
        $hasCol('option_c') ? ($qc('option_c') . ' AS option_c') : "'' AS option_c",
        $hasCol('option_d') ? ($qc('option_d') . ' AS option_d') : "'' AS option_d",
        $hasCol('option_e') ? ($qc('option_e') . ' AS option_e') : 'NULL AS option_e',
        $hasCol('correct_answer') ? ($qc('correct_answer') . ' AS correct_answer') : "'' AS correct_answer",
        $hasCol('explanation') ? ($qc('explanation') . ' AS explanation') : "'' AS explanation",
        $hasCol('video_solution_id') ? ($qc('video_solution_id') . ' AS video_solution_id') : 'NULL AS video_solution_id',
        $hasCol('image_url') ? ($qc('image_url') . ' AS image_url') : 'NULL AS image_url',
        $hasCol('question_image_large_url') ? ($qc('question_image_large_url') . ' AS question_image_large_url') : 'NULL AS question_image_large_url',
        $hasCol('question_image_thumb_url') ? ($qc('question_image_thumb_url') . ' AS question_image_thumb_url') : 'NULL AS question_image_thumb_url',
        $hasCol('explanation_image_large_url') ? ($qc('explanation_image_large_url') . ' AS explanation_image_large_url') : 'NULL AS explanation_image_large_url',
        $hasCol('explanation_image_thumb_url') ? ($qc('explanation_image_thumb_url') . ' AS explanation_image_thumb_url') : 'NULL AS explanation_image_thumb_url',
        $hasCol('difficulty') ? ($qc('difficulty') . ' AS difficulty') : 'NULL AS difficulty',
        $hasCol('question_badge_type') ? ($qc('question_badge_type') . ' AS question_badge_type') : "'normal' AS question_badge_type",
        $hasCol('created_at') ? ($qc('created_at') . ' AS created_at') : 'NULL AS created_at',
    ];

    $countQuery = static function (PDO $pdo, string $sql, array $params): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    };

    $totalAvailableCount = 0;

    $rows = [];
    if ($poolType === 'most_wrong') {
        $ws = study_get_wrong_score_schema($pdo);
        $wsCount = 0;
        if ($ws && !empty($ws['user_id']) && !empty($ws['question_id']) && !empty($ws['wrong_score'])) {
            $whereMostWrong = $where;
            $whereMostWrong[] = 'COALESCE(ws.' . study_q($ws['wrong_score']) . ', 0) > 0';

            $countSql = 'SELECT COUNT(DISTINCT ' . $qc('id') . ')'
                . ' FROM questions ' . $q
                . ' INNER JOIN ' . study_q($ws['table']) . ' ws'
                . ' ON ws.' . study_q($ws['question_id']) . ' = ' . $qc('id')
                . ' AND ws.' . study_q($ws['user_id']) . ' = ?';

            $countParams = [$userId];
            if (!empty($ws['qualification_id'])) {
                $countSql .= ' AND ws.' . study_q($ws['qualification_id']) . ' = ?';
                $countParams[] = $currentQualificationId;
            }

            if ($whereMostWrong) {
                $countSql .= ' WHERE ' . implode(' AND ', $whereMostWrong);
            }

            $wsCount = $countQuery($pdo, $countSql, array_merge($countParams, $params));

            $selectWithWrongScore = $select;
            $selectWithWrongScore[] = 'COALESCE(ws.' . study_q($ws['wrong_score']) . ', 0) AS wrong_score';

            $sql = 'SELECT ' . implode(', ', $selectWithWrongScore)
                . ' FROM questions ' . $q
                . ' INNER JOIN ' . study_q($ws['table']) . ' ws'
                . ' ON ws.' . study_q($ws['question_id']) . ' = ' . $qc('id')
                . ' AND ws.' . study_q($ws['user_id']) . ' = ?';

            $joinParams = [$userId];
            if (!empty($ws['qualification_id'])) {
                $sql .= ' AND ws.' . study_q($ws['qualification_id']) . ' = ?';
                $joinParams[] = $currentQualificationId;
            }
            $paramsMostWrong = array_merge($joinParams, $params);

            if ($whereMostWrong) {
                $sql .= ' WHERE ' . implode(' AND ', $whereMostWrong);
            }

            $orderByMostWrong = ['COALESCE(ws.' . study_q($ws['wrong_score']) . ', 0) DESC'];
            if (!empty($ws['last_answered_at'])) {
                $orderByMostWrong[] = 'ws.' . study_q($ws['last_answered_at']) . ' DESC';
            }
            $sql .= ' ORDER BY ' . implode(', ', $orderByMostWrong);

            $stmt = $pdo->prepare($sql);
            $stmt->execute($paramsMostWrong);
            $rankedRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $topRows = array_slice($rankedRows, 0, 50);
            $remainingRows = array_slice($rankedRows, 50);

            if (count($topRows) > 1) {
                shuffle($topRows);
            }
            if (count($remainingRows) > 1) {
                shuffle($remainingRows);
            }

            $rows = array_merge($topRows, $remainingRows);
            if ($limit > 0) {
                $rows = array_slice($rows, 0, $limit);
            }
        }

        if (!$rows) {
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
                    $selectFallback = $select;
                    if (!empty($up['wrong_answer_count'])) {
                        $selectFallback[] = 'COALESCE(up.' . study_q($up['wrong_answer_count']) . ', 0) AS wrong_score';
                    }

                    $sql = 'SELECT DISTINCT ' . implode(', ', $selectFallback)
                        . ' FROM questions ' . $q
                        . ' INNER JOIN ' . study_q($up['table']) . ' up'
                        . ' ON up.' . study_q($up['question_id']) . ' = ' . $qc('id')
                        . ' AND up.' . study_q($up['user_id']) . ' = ?';

                    $whereFallback = $where;
                    $whereFallback[] = '(' . implode(' OR ', $fallbackConditions) . ')';

                    if ($whereFallback) {
                        $sql .= ' WHERE ' . implode(' AND ', $whereFallback);
                    }

                    $orderBy = $hasCol('created_at') ? $qc('created_at') : ($hasCol('id') ? $qc('id') : '1');
                    $sql .= ' ORDER BY ' . $orderBy . ' DESC LIMIT ' . (int)$limit;

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute(array_merge([$userId], $params));
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    $countSql = 'SELECT COUNT(DISTINCT ' . $qc('id') . ')'
                        . ' FROM questions ' . $q
                        . ' INNER JOIN ' . study_q($up['table']) . ' up'
                        . ' ON up.' . study_q($up['question_id']) . ' = ' . $qc('id')
                        . ' AND up.' . study_q($up['user_id']) . ' = ?';

                    if ($whereFallback) {
                        $countSql .= ' WHERE ' . implode(' AND ', $whereFallback);
                    }

                    $totalAvailableCount = $countQuery($pdo, $countSql, array_merge([$userId], $params));
                }
            }
        }

        if ($wsCount > 0) {
            $totalAvailableCount = $wsCount;
        }
    } elseif ($poolType === 'all') {
        $countSql = 'SELECT COUNT(DISTINCT ' . $qc('id') . ') FROM questions ' . $q;
        if ($where) {
            $countSql .= ' WHERE ' . implode(' AND ', $where);
        }
        $totalAvailableCount = $countQuery($pdo, $countSql, $params);

        $sql = 'SELECT ' . implode(', ', $select) . ' FROM questions ' . $q;
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        if ($isRandomOrder) {
            $sql .= ' ORDER BY RAND() LIMIT ' . (int)$limit;
        } else {
            $orderBy = $hasCol('created_at') ? $qc('created_at') : ($hasCol('id') ? $qc('id') : '1');
            $sql .= ' ORDER BY ' . $orderBy . ' ' . $order . ' LIMIT ' . (int)$limit;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $upColumns = get_table_columns($pdo, 'user_progress');
        if (!$upColumns) {
            if ($poolType === 'unanswered') {
                $countSql = 'SELECT COUNT(DISTINCT ' . $qc('id') . ') FROM questions ' . $q;
                if ($where) {
                    $countSql .= ' WHERE ' . implode(' AND ', $where);
                }
                $totalAvailableCount = $countQuery($pdo, $countSql, $params);

                $sql = 'SELECT ' . implode(', ', $select) . ' FROM questions ' . $q;
                if ($where) {
                    $sql .= ' WHERE ' . implode(' AND ', $where);
                }

                if ($isRandomOrder) {
                    $sql .= ' ORDER BY RAND() LIMIT ' . (int)$limit;
                } else {
                    $orderBy = $hasCol('created_at') ? $qc('created_at') : ($hasCol('id') ? $qc('id') : '1');
                    $sql .= ' ORDER BY ' . $orderBy . ' ' . $order . ' LIMIT ' . (int)$limit;
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } else {
                $rows = [];
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
                $sql = 'SELECT DISTINCT ' . implode(', ', $select)
                    . ' FROM questions ' . $q
                    . ' LEFT JOIN ' . study_q($up['table']) . ' up'
                    . ' ON up.' . study_q($up['question_id']) . ' = ' . $qc('id')
                    . ' AND up.' . study_q($up['user_id']) . ' = ?';

                $whereUnanswered = $where;
                if ($answeredExpr !== '') {
                    $whereUnanswered[] = '(up.' . study_q($up['question_id']) . ' IS NULL OR NOT (' . $answeredExpr . '))';
                } else {
                    $whereUnanswered[] = 'up.' . study_q($up['question_id']) . ' IS NULL';
                }

                if ($whereUnanswered) {
                    $sql .= ' WHERE ' . implode(' AND ', $whereUnanswered);
                }

                if ($isRandomOrder) {
                    $sql .= ' ORDER BY RAND() LIMIT ' . (int)$limit;
                } else {
                    $orderBy = $hasCol('created_at') ? $qc('created_at') : ($hasCol('id') ? $qc('id') : '1');
                    $sql .= ' ORDER BY ' . $orderBy . ' ' . $order . ' LIMIT ' . (int)$limit;
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge([$userId], $params));
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $countSql = 'SELECT COUNT(DISTINCT ' . $qc('id') . ')'
                    . ' FROM questions ' . $q
                    . ' LEFT JOIN ' . study_q($up['table']) . ' up'
                    . ' ON up.' . study_q($up['question_id']) . ' = ' . $qc('id')
                    . ' AND up.' . study_q($up['user_id']) . ' = ?';

                if ($whereUnanswered) {
                    $countSql .= ' WHERE ' . implode(' AND ', $whereUnanswered);
                }

                $totalAvailableCount = $countQuery($pdo, $countSql, array_merge([$userId], $params));
            } elseif ($poolType === 'answered') {
                if ($answeredExpr === '') {
                    $rows = [];
                } else {
                    $sql = 'SELECT DISTINCT ' . implode(', ', $select)
                        . ' FROM questions ' . $q
                        . ' INNER JOIN ' . study_q($up['table']) . ' up'
                        . ' ON up.' . study_q($up['question_id']) . ' = ' . $qc('id')
                        . ' AND up.' . study_q($up['user_id']) . ' = ?';

                    $whereAnswered = $where;
                    $whereAnswered[] = $answeredExpr;

                    if ($whereAnswered) {
                        $sql .= ' WHERE ' . implode(' AND ', $whereAnswered);
                    }

                    if ($isRandomOrder) {
                        $sql .= ' ORDER BY RAND() LIMIT ' . (int)$limit;
                    } else {
                        $orderBy = $hasCol('created_at') ? $qc('created_at') : ($hasCol('id') ? $qc('id') : '1');
                        $sql .= ' ORDER BY ' . $orderBy . ' ' . $order . ' LIMIT ' . (int)$limit;
                    }

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute(array_merge([$userId], $params));
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    $countSql = 'SELECT COUNT(DISTINCT ' . $qc('id') . ')'
                        . ' FROM questions ' . $q
                        . ' INNER JOIN ' . study_q($up['table']) . ' up'
                        . ' ON up.' . study_q($up['question_id']) . ' = ' . $qc('id')
                        . ' AND up.' . study_q($up['user_id']) . ' = ?';

                    if ($whereAnswered) {
                        $countSql .= ' WHERE ' . implode(' AND ', $whereAnswered);
                    }

                    $totalAvailableCount = $countQuery($pdo, $countSql, array_merge([$userId], $params));
                }
            } elseif ($poolType === 'bookmarked') {
                if (empty($up['is_bookmarked'])) {
                    $rows = [];
                } else {
                    $sql = 'SELECT DISTINCT ' . implode(', ', $select)
                        . ' FROM questions ' . $q
                        . ' INNER JOIN ' . study_q($up['table']) . ' up'
                        . ' ON up.' . study_q($up['question_id']) . ' = ' . $qc('id')
                        . ' AND up.' . study_q($up['user_id']) . ' = ?';

                    $whereBookmarked = $where;
                    $whereBookmarked[] = 'COALESCE(up.' . study_q($up['is_bookmarked']) . ', 0) = 1';

                    if ($whereBookmarked) {
                        $sql .= ' WHERE ' . implode(' AND ', $whereBookmarked);
                    }

                    if ($isRandomOrder) {
                        $sql .= ' ORDER BY RAND() LIMIT ' . (int)$limit;
                    } else {
                        $orderBy = $hasCol('created_at') ? $qc('created_at') : ($hasCol('id') ? $qc('id') : '1');
                        $sql .= ' ORDER BY ' . $orderBy . ' ' . $order . ' LIMIT ' . (int)$limit;
                    }

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute(array_merge([$userId], $params));
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    $countSql = 'SELECT COUNT(DISTINCT ' . $qc('id') . ')'
                        . ' FROM questions ' . $q
                        . ' INNER JOIN ' . study_q($up['table']) . ' up'
                        . ' ON up.' . study_q($up['question_id']) . ' = ' . $qc('id')
                        . ' AND up.' . study_q($up['user_id']) . ' = ?';

                    if ($whereBookmarked) {
                        $countSql .= ' WHERE ' . implode(' AND ', $whereBookmarked);
                    }

                    $totalAvailableCount = $countQuery($pdo, $countSql, array_merge([$userId], $params));
                }
            }
        }
    }

    $questions = array_map(static function (array $row): array {
        $explanation = (string)($row['explanation'] ?? '');
        $legacyImageUrl = $row['image_url'] ?? null;
        $questionImageUrl = $row['question_image_large_url'] ?: $legacyImageUrl;
        $badgeType = ((string)($row['question_badge_type'] ?? 'normal') === 'new') ? 'new' : 'normal';
        return [
            'id' => (string)($row['id'] ?? ''),
            'topic_id' => (($row['topic_id'] ?? null) === '' ? null : ($row['topic_id'] ?? null)),
            'course_id' => $row['course_id'] ?? null,
            'original_topic_id' => (($row['original_topic_id'] ?? null) === '' ? null : ($row['original_topic_id'] ?? null)),
            'original_course_id' => $row['original_course_id'] ?? null,
            'question_type' => (string)($row['question_type'] ?? ''),
            'source_type' => (string)($row['source_type'] ?? ''),
            'question_text' => (string)($row['question_text'] ?? ''),
            'option_a' => (string)($row['option_a'] ?? ''),
            'option_b' => (string)($row['option_b'] ?? ''),
            'option_c' => (string)($row['option_c'] ?? ''),
            'option_d' => (string)($row['option_d'] ?? ''),
            'option_e' => $row['option_e'] ?? null,
            'correct_answer' => (string)($row['correct_answer'] ?? ''),
            'explanation' => $explanation,
            'formatted_explanation' => format_explanation_text($explanation),
            'image_url' => $questionImageUrl,
            'question_image_url' => $questionImageUrl,
            'question_image_thumb_url' => $row['question_image_thumb_url'] ?? null,
            'explanation_image_url' => $row['explanation_image_large_url'] ?? null,
            'explanation_image_thumb_url' => $row['explanation_image_thumb_url'] ?? null,
            'difficulty' => $row['difficulty'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'question_badge_type' => $badgeType,
            'is_new_question' => $badgeType === 'new',
            'wrong_score' => array_key_exists('wrong_score', $row) ? (int)$row['wrong_score'] : null,
        ];
    }, $rows);

    api_qualification_access_log('study qualifications returned count', [
        'context' => 'questions.list',
        'count' => count($questions),
        'current_qualification_id' => $currentQualificationId,
    ]);

    api_qualification_access_log('study qualification returned', [
        'context' => 'questions.list',
        'study qualification returned' => $currentQualificationId,
    ]);

    api_success('Soru listesi getirildi.', [
        'questions' => $questions,
        'available_count' => max(0, (int)$totalAvailableCount),
        'total_count' => max(0, (int)$totalAvailableCount),
        'pagination' => [
            'total' => max(0, (int)$totalAvailableCount),
        ],
        'filter_context' => $filterContext,
        'filter_signature' => $filterSignature,
    ]);
} catch (Throwable $e) {
    error_log('questions.list failed: ' . $e->getMessage());
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}
