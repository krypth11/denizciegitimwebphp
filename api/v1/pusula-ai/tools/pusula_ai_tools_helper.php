<?php

require_once dirname(__DIR__) . '/bootstrap.php';

function pusula_ai_tool_q(string $column): string
{
    return '`' . str_replace('`', '', $column) . '`';
}

function pusula_ai_tool_pick(array $columns, array $candidates, bool $required = false): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    if ($required) {
        throw new RuntimeException('Gerekli kolon bulunamadı: ' . implode(', ', $candidates));
    }

    return null;
}

function pusula_ai_tool_safe_percent(int $correct, int $total): ?float
{
    if ($total <= 0) {
        return null;
    }
    return round(($correct / $total) * 100, 2);
}

function pusula_ai_tool_parse_float($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    return is_numeric($value) ? (float)$value : null;
}

function pusula_ai_tool_default_stats(?string $qualificationId = null): array
{
    return [
        'total_questions_solved' => 0,
        'total_correct' => 0,
        'total_wrong' => 0,
        'total_blank' => 0,
        'accuracy_percent' => null,
        'last_7_days_questions' => 0,
        'last_30_days_questions' => 0,
        'active_days_last_7' => 0,
        'active_days_last_30' => 0,
        'current_qualification_id' => $qualificationId,
        'weak_topics' => [],
        'strong_topics' => [],
        'last_exam' => null,
    ];
}

function pusula_ai_tool_get_current_qualification_id(PDO $pdo, string $userId): ?string
{
    try {
        $profileCols = get_table_columns($pdo, 'user_profiles');
        if (!$profileCols) {
            return null;
        }

        $idCol = pusula_ai_tool_pick($profileCols, ['id'], true);
        $qualificationCol = pusula_ai_tool_pick($profileCols, ['current_qualification_id', 'qualification_id'], false);
        if (!$qualificationCol) {
            return null;
        }

        $sql = 'SELECT ' . pusula_ai_tool_q($qualificationCol) . ' AS qualification_id '
            . 'FROM `user_profiles` WHERE ' . pusula_ai_tool_q($idCol) . ' = :user_id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $qualificationId = trim((string)($row['qualification_id'] ?? ''));
        return $qualificationId !== '' ? $qualificationId : null;
    } catch (Throwable $e) {
        return null;
    }
}

function pusula_ai_tool_get_attempt_question_aggregates(PDO $pdo, string $attemptId): ?array
{
    try {
        $aqCols = get_table_columns($pdo, 'mock_exam_attempt_questions');
        if (!$aqCols) {
            return null;
        }

        $aqAttempt = pusula_ai_tool_pick($aqCols, ['attempt_id'], true);
        $aqIsCorrect = pusula_ai_tool_pick($aqCols, ['is_correct'], false);
        $aqSelected = pusula_ai_tool_pick($aqCols, ['selected_answer'], false);
        if (!$aqIsCorrect && !$aqSelected) {
            return null;
        }

        $correctExpr = $aqIsCorrect
            ? 'SUM(CASE WHEN ' . pusula_ai_tool_q($aqIsCorrect) . ' = 1 THEN 1 ELSE 0 END)'
            : '0';
        $wrongExpr = $aqIsCorrect
            ? 'SUM(CASE WHEN ' . pusula_ai_tool_q($aqIsCorrect) . ' = 0 THEN 1 ELSE 0 END)'
            : '0';
        $blankExpr = $aqSelected
            ? 'SUM(CASE WHEN ' . pusula_ai_tool_q($aqSelected) . ' IS NULL OR ' . pusula_ai_tool_q($aqSelected) . " = '' THEN 1 ELSE 0 END)"
            : ($aqIsCorrect
                ? 'SUM(CASE WHEN ' . pusula_ai_tool_q($aqIsCorrect) . ' IS NULL THEN 1 ELSE 0 END)'
                : '0');

        $sql = 'SELECT '
            . 'COUNT(*) AS question_count, '
            . $correctExpr . ' AS correct_count, '
            . $wrongExpr . ' AS wrong_count, '
            . $blankExpr . ' AS blank_count '
            . 'FROM `mock_exam_attempt_questions` '
            . 'WHERE ' . pusula_ai_tool_q($aqAttempt) . ' = :attempt_id';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':attempt_id' => $attemptId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'question_count' => max(0, (int)($row['question_count'] ?? 0)),
            'correct_count' => max(0, (int)($row['correct_count'] ?? 0)),
            'wrong_count' => max(0, (int)($row['wrong_count'] ?? 0)),
            'blank_count' => max(0, (int)($row['blank_count'] ?? 0)),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function pusula_ai_tool_get_last_exam_summary(PDO $pdo, string $userId): ?array
{
    try {
        $aCols = get_table_columns($pdo, 'mock_exam_attempts');
        if (!$aCols) {
            return null;
        }

        $userCol = pusula_ai_tool_pick($aCols, ['user_id'], true);
        $idCol = pusula_ai_tool_pick($aCols, ['id'], true);
        $statusCol = pusula_ai_tool_pick($aCols, ['status'], false);
        $finishedCol = pusula_ai_tool_pick($aCols, ['submitted_at', 'finished_at', 'completed_at', 'updated_at', 'created_at'], false);
        if (!$finishedCol) {
            return null;
        }

        $scoreCol = pusula_ai_tool_pick($aCols, ['success_rate', 'score_percent'], false);
        $correctCol = pusula_ai_tool_pick($aCols, ['correct_count', 'total_correct'], false);
        $wrongCol = pusula_ai_tool_pick($aCols, ['wrong_count', 'incorrect_count', 'total_wrong'], false);
        $blankCol = pusula_ai_tool_pick($aCols, ['blank_count'], false);
        $questionCountCol = pusula_ai_tool_pick($aCols, ['actual_question_count', 'question_count', 'total_questions', 'requested_question_count'], false);

        $where = ['a.' . pusula_ai_tool_q($userCol) . ' = :user_id'];
        if ($statusCol) {
            $where[] = 'a.' . pusula_ai_tool_q($statusCol) . " IN ('completed','submitted')";
        } else {
            $where[] = 'a.' . pusula_ai_tool_q($finishedCol) . ' IS NOT NULL';
        }

        $select = [
            'a.' . pusula_ai_tool_q($idCol) . ' AS attempt_id',
            'a.' . pusula_ai_tool_q($finishedCol) . ' AS finished_at',
            ($scoreCol ? ('a.' . pusula_ai_tool_q($scoreCol)) : 'NULL') . ' AS score_percent',
            ($correctCol ? ('a.' . pusula_ai_tool_q($correctCol)) : 'NULL') . ' AS correct_count',
            ($wrongCol ? ('a.' . pusula_ai_tool_q($wrongCol)) : 'NULL') . ' AS wrong_count',
            ($blankCol ? ('a.' . pusula_ai_tool_q($blankCol)) : 'NULL') . ' AS blank_count',
            ($questionCountCol ? ('a.' . pusula_ai_tool_q($questionCountCol)) : 'NULL') . ' AS question_count',
        ];

        $sql = 'SELECT ' . implode(', ', $select)
            . ' FROM `mock_exam_attempts` a'
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY a.' . pusula_ai_tool_q($finishedCol) . ' DESC LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $attemptId = (string)($row['attempt_id'] ?? '');
        if ($attemptId === '') {
            return null;
        }

        $counts = [
            'correct_count' => max(0, (int)($row['correct_count'] ?? 0)),
            'wrong_count' => max(0, (int)($row['wrong_count'] ?? 0)),
            'blank_count' => max(0, (int)($row['blank_count'] ?? 0)),
            'question_count' => max(0, (int)($row['question_count'] ?? 0)),
        ];

        $needsFallback = ($counts['question_count'] <= 0) || (($counts['correct_count'] + $counts['wrong_count'] + $counts['blank_count']) <= 0);
        if ($needsFallback) {
            $fromQuestions = pusula_ai_tool_get_attempt_question_aggregates($pdo, $attemptId);
            if ($fromQuestions) {
                $counts = $fromQuestions;
            }
        }

        if ($counts['question_count'] <= 0) {
            $counts['question_count'] = $counts['correct_count'] + $counts['wrong_count'] + $counts['blank_count'];
        }
        if ($counts['blank_count'] <= 0 && $counts['question_count'] > 0) {
            $counts['blank_count'] = max(0, $counts['question_count'] - ($counts['correct_count'] + $counts['wrong_count']));
        }

        $scorePercent = pusula_ai_tool_parse_float($row['score_percent'] ?? null);
        if ($scorePercent === null) {
            $scorePercent = pusula_ai_tool_safe_percent($counts['correct_count'], $counts['question_count']);
        }

        return [
            'attempt_id' => $attemptId,
            'score_percent' => $scorePercent,
            'correct_count' => $counts['correct_count'],
            'wrong_count' => $counts['wrong_count'],
            'blank_count' => $counts['blank_count'],
            'finished_at' => (string)($row['finished_at'] ?? ''),
            'question_count' => $counts['question_count'],
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function pusula_ai_tool_get_topic_accuracy_rows(PDO $pdo, string $userId, int $minQuestionCount = 3): array
{
    $minQuestionCount = max(1, $minQuestionCount);

    try {
        $evCols = get_table_columns($pdo, 'question_attempt_events');
        if (!$evCols) {
            return [];
        }

        $evUser = pusula_ai_tool_pick($evCols, ['user_id'], true);
        $evTopic = pusula_ai_tool_pick($evCols, ['topic_id'], false);
        $evQuestion = pusula_ai_tool_pick($evCols, ['question_id'], false);
        $evCorrect = pusula_ai_tool_pick($evCols, ['is_correct'], false);
        if (!$evCorrect) {
            return [];
        }

        $topicCols = get_table_columns($pdo, 'topics');
        if ($topicCols && $evTopic) {
            $topicId = pusula_ai_tool_pick($topicCols, ['id'], true);
            $topicName = pusula_ai_tool_pick($topicCols, ['name', 'title'], false);
            if ($topicName) {
                $sql = 'SELECT '
                    . 't.' . pusula_ai_tool_q($topicId) . ' AS topic_id, '
                    . 't.' . pusula_ai_tool_q($topicName) . ' AS topic_name, '
                    . 'SUM(CASE WHEN e.' . pusula_ai_tool_q($evCorrect) . ' = 1 THEN 1 ELSE 0 END) AS total_correct, '
                    . 'SUM(CASE WHEN e.' . pusula_ai_tool_q($evCorrect) . ' IN (0,1) THEN 1 ELSE 0 END) AS question_count '
                    . 'FROM `question_attempt_events` e '
                    . 'INNER JOIN `topics` t ON e.' . pusula_ai_tool_q($evTopic) . ' = t.' . pusula_ai_tool_q($topicId) . ' '
                    . 'WHERE e.' . pusula_ai_tool_q($evUser) . ' = :user_id '
                    . 'GROUP BY t.' . pusula_ai_tool_q($topicId) . ', t.' . pusula_ai_tool_q($topicName) . ' '
                    . 'HAVING question_count >= :min_count';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':user_id' => $userId,
                    ':min_count' => $minQuestionCount,
                ]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if ($rows) {
                    return $rows;
                }
            }
        }

        $qCols = get_table_columns($pdo, 'questions');
        if ($qCols && $evQuestion) {
            $qId = pusula_ai_tool_pick($qCols, ['id'], true);
            $qTopic = pusula_ai_tool_pick($qCols, ['topic_id'], false);
            if ($topicCols && $qTopic) {
                $topicId = pusula_ai_tool_pick($topicCols, ['id'], true);
                $topicName = pusula_ai_tool_pick($topicCols, ['name', 'title'], false);
                if ($topicName) {
                    $sql = 'SELECT '
                        . 't.' . pusula_ai_tool_q($topicId) . ' AS topic_id, '
                        . 't.' . pusula_ai_tool_q($topicName) . ' AS topic_name, '
                        . 'SUM(CASE WHEN e.' . pusula_ai_tool_q($evCorrect) . ' = 1 THEN 1 ELSE 0 END) AS total_correct, '
                        . 'SUM(CASE WHEN e.' . pusula_ai_tool_q($evCorrect) . ' IN (0,1) THEN 1 ELSE 0 END) AS question_count '
                        . 'FROM `question_attempt_events` e '
                        . 'INNER JOIN `questions` q ON e.' . pusula_ai_tool_q($evQuestion) . ' = q.' . pusula_ai_tool_q($qId) . ' '
                        . 'INNER JOIN `topics` t ON q.' . pusula_ai_tool_q($qTopic) . ' = t.' . pusula_ai_tool_q($topicId) . ' '
                        . 'WHERE e.' . pusula_ai_tool_q($evUser) . ' = :user_id '
                        . 'GROUP BY t.' . pusula_ai_tool_q($topicId) . ', t.' . pusula_ai_tool_q($topicName) . ' '
                        . 'HAVING question_count >= :min_count';
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':user_id' => $userId,
                        ':min_count' => $minQuestionCount,
                    ]);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    if ($rows) {
                        return $rows;
                    }
                }
            }

            $courseCols = get_table_columns($pdo, 'courses');
            $qCourse = pusula_ai_tool_pick($qCols, ['course_id'], false);
            if ($courseCols && $qCourse) {
                $cId = pusula_ai_tool_pick($courseCols, ['id'], true);
                $cName = pusula_ai_tool_pick($courseCols, ['name', 'title'], false);
                if ($cName) {
                    $sql = 'SELECT '
                        . 'c.' . pusula_ai_tool_q($cId) . ' AS topic_id, '
                        . 'c.' . pusula_ai_tool_q($cName) . ' AS topic_name, '
                        . 'SUM(CASE WHEN e.' . pusula_ai_tool_q($evCorrect) . ' = 1 THEN 1 ELSE 0 END) AS total_correct, '
                        . 'SUM(CASE WHEN e.' . pusula_ai_tool_q($evCorrect) . ' IN (0,1) THEN 1 ELSE 0 END) AS question_count '
                        . 'FROM `question_attempt_events` e '
                        . 'INNER JOIN `questions` q ON e.' . pusula_ai_tool_q($evQuestion) . ' = q.' . pusula_ai_tool_q($qId) . ' '
                        . 'INNER JOIN `courses` c ON q.' . pusula_ai_tool_q($qCourse) . ' = c.' . pusula_ai_tool_q($cId) . ' '
                        . 'WHERE e.' . pusula_ai_tool_q($evUser) . ' = :user_id '
                        . 'GROUP BY c.' . pusula_ai_tool_q($cId) . ', c.' . pusula_ai_tool_q($cName) . ' '
                        . 'HAVING question_count >= :min_count';
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':user_id' => $userId,
                        ':min_count' => $minQuestionCount,
                    ]);
                    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    return [];
}

function pusula_ai_tool_get_weak_topics(PDO $pdo, string $userId, int $limit = 3): array
{
    $rows = pusula_ai_tool_get_topic_accuracy_rows($pdo, $userId, 3);
    if (!$rows) {
        return [];
    }

    $items = [];
    foreach ($rows as $row) {
        $questionCount = max(0, (int)($row['question_count'] ?? 0));
        if ($questionCount < 3) {
            continue;
        }
        $correct = max(0, (int)($row['total_correct'] ?? 0));
        $accuracy = pusula_ai_tool_safe_percent($correct, $questionCount);
        if ($accuracy === null) {
            continue;
        }

        $topicName = trim((string)($row['topic_name'] ?? ''));
        if ($topicName === '') {
            continue;
        }

        $items[] = [
            'topic_id' => (string)($row['topic_id'] ?? ''),
            'topic_name' => $topicName,
            'accuracy_percent' => $accuracy,
            'question_count' => $questionCount,
        ];
    }

    usort($items, static function (array $a, array $b): int {
        $cmp = ($a['accuracy_percent'] <=> $b['accuracy_percent']);
        if ($cmp !== 0) {
            return $cmp;
        }
        return ($b['question_count'] <=> $a['question_count']);
    });

    return array_slice($items, 0, max(1, $limit));
}

function pusula_ai_tool_get_strong_topics(PDO $pdo, string $userId, int $limit = 3): array
{
    $rows = pusula_ai_tool_get_topic_accuracy_rows($pdo, $userId, 3);
    if (!$rows) {
        return [];
    }

    $items = [];
    foreach ($rows as $row) {
        $questionCount = max(0, (int)($row['question_count'] ?? 0));
        if ($questionCount < 3) {
            continue;
        }
        $correct = max(0, (int)($row['total_correct'] ?? 0));
        $accuracy = pusula_ai_tool_safe_percent($correct, $questionCount);
        if ($accuracy === null) {
            continue;
        }

        $topicName = trim((string)($row['topic_name'] ?? ''));
        if ($topicName === '') {
            continue;
        }

        $items[] = [
            'topic_id' => (string)($row['topic_id'] ?? ''),
            'topic_name' => $topicName,
            'accuracy_percent' => $accuracy,
            'question_count' => $questionCount,
        ];
    }

    usort($items, static function (array $a, array $b): int {
        $cmp = ($b['accuracy_percent'] <=> $a['accuracy_percent']);
        if ($cmp !== 0) {
            return $cmp;
        }
        return ($b['question_count'] <=> $a['question_count']);
    });

    return array_slice($items, 0, max(1, $limit));
}

function pusula_ai_tool_get_user_stats(PDO $pdo, string $userId): array
{
    $qualificationId = pusula_ai_tool_get_current_qualification_id($pdo, $userId);
    $stats = pusula_ai_tool_default_stats($qualificationId);

    try {
        $evCols = get_table_columns($pdo, 'question_attempt_events');
        if ($evCols) {
            $userCol = pusula_ai_tool_pick($evCols, ['user_id'], true);
            $correctCol = pusula_ai_tool_pick($evCols, ['is_correct'], false);
            $attemptedCol = pusula_ai_tool_pick($evCols, ['attempted_at', 'answered_at', 'created_at', 'updated_at'], false);

            if ($correctCol) {
                $sql = 'SELECT '
                    . 'SUM(CASE WHEN ' . pusula_ai_tool_q($correctCol) . ' = 1 THEN 1 ELSE 0 END) AS total_correct, '
                    . 'SUM(CASE WHEN ' . pusula_ai_tool_q($correctCol) . ' = 0 THEN 1 ELSE 0 END) AS total_wrong, '
                    . 'SUM(CASE WHEN ' . pusula_ai_tool_q($correctCol) . ' IS NULL THEN 1 ELSE 0 END) AS total_blank '
                    . 'FROM `question_attempt_events` '
                    . 'WHERE ' . pusula_ai_tool_q($userCol) . ' = :user_id';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':user_id' => $userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $stats['total_correct'] = max(0, (int)($row['total_correct'] ?? 0));
                $stats['total_wrong'] = max(0, (int)($row['total_wrong'] ?? 0));
                $stats['total_blank'] = max(0, (int)($row['total_blank'] ?? 0));
                $stats['total_questions_solved'] = $stats['total_correct'] + $stats['total_wrong'] + $stats['total_blank'];
            } else {
                $sql = 'SELECT COUNT(*) FROM `question_attempt_events` WHERE ' . pusula_ai_tool_q($userCol) . ' = :user_id';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':user_id' => $userId]);
                $stats['total_questions_solved'] = max(0, (int)$stmt->fetchColumn());
            }

            if ($attemptedCol) {
                $sqlPeriods = 'SELECT '
                    . 'SUM(CASE WHEN ' . pusula_ai_tool_q($attemptedCol) . ' >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS last_7_days_questions, '
                    . 'SUM(CASE WHEN ' . pusula_ai_tool_q($attemptedCol) . ' >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS last_30_days_questions, '
                    . 'COUNT(DISTINCT CASE WHEN ' . pusula_ai_tool_q($attemptedCol) . ' >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN DATE(' . pusula_ai_tool_q($attemptedCol) . ') END) AS active_days_last_7, '
                    . 'COUNT(DISTINCT CASE WHEN ' . pusula_ai_tool_q($attemptedCol) . ' >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN DATE(' . pusula_ai_tool_q($attemptedCol) . ') END) AS active_days_last_30 '
                    . 'FROM `question_attempt_events` '
                    . 'WHERE ' . pusula_ai_tool_q($userCol) . ' = :user_id';
                $stmtPeriods = $pdo->prepare($sqlPeriods);
                $stmtPeriods->execute([':user_id' => $userId]);
                $periods = $stmtPeriods->fetch(PDO::FETCH_ASSOC) ?: [];

                $stats['last_7_days_questions'] = max(0, (int)($periods['last_7_days_questions'] ?? 0));
                $stats['last_30_days_questions'] = max(0, (int)($periods['last_30_days_questions'] ?? 0));
                $stats['active_days_last_7'] = max(0, (int)($periods['active_days_last_7'] ?? 0));
                $stats['active_days_last_30'] = max(0, (int)($periods['active_days_last_30'] ?? 0));
            }
        }
    } catch (Throwable $e) {
        // güvenli fallback
    }

    $stats['last_exam'] = pusula_ai_tool_get_last_exam_summary($pdo, $userId);
    $stats['weak_topics'] = pusula_ai_tool_get_weak_topics($pdo, $userId, 3);
    $stats['strong_topics'] = pusula_ai_tool_get_strong_topics($pdo, $userId, 3);

    if ($stats['total_questions_solved'] <= 0 && is_array($stats['last_exam'])) {
        $stats['total_correct'] = max(0, (int)($stats['last_exam']['correct_count'] ?? 0));
        $stats['total_wrong'] = max(0, (int)($stats['last_exam']['wrong_count'] ?? 0));
        $stats['total_blank'] = max(0, (int)($stats['last_exam']['blank_count'] ?? 0));
        $stats['total_questions_solved'] = max(0, (int)($stats['last_exam']['question_count'] ?? 0));
    }

    if ($stats['total_questions_solved'] <= 0) {
        $stats['total_questions_solved'] = $stats['total_correct'] + $stats['total_wrong'] + $stats['total_blank'];
    }

    $stats['accuracy_percent'] = pusula_ai_tool_safe_percent(
        (int)$stats['total_correct'],
        (int)$stats['total_questions_solved']
    );

    return $stats;
}

function pusula_ai_tool_map_default_exam_mode(string $mode): string
{
    $mode = strtolower(trim($mode));
    $map = [
        'mini' => 'motivation_warmup',
        'standard' => 'mixed_review',
        'classic' => 'mixed_review',
        'mixed' => 'mixed_review',
        'weak_topics' => 'weak_topics',
        'last_exam_mistakes' => 'last_exam_mistakes',
        'mixed_review' => 'mixed_review',
        'motivation_warmup' => 'motivation_warmup',
        'one_week_focus' => 'one_week_focus',
    ];
    return $map[$mode] ?? 'mixed_review';
}

function pusula_ai_tool_build_recommended_exam(PDO $pdo, string $userId, array $settings, array $toolSettings): ?array
{
    if ((int)($toolSettings['tool_exam_recommendation_enabled'] ?? 1) !== 1) {
        return null;
    }
    if ((int)($settings['action_exam_enabled'] ?? 1) !== 1) {
        return null;
    }

    $message = mb_strtolower(trim((string)($settings['user_message'] ?? '')), 'UTF-8');
    $defaultCount = max(1, min(100, (int)($settings['action_exam_default_question_count'] ?? 20)));
    $defaultMode = pusula_ai_tool_map_default_exam_mode((string)($settings['action_exam_default_mode'] ?? 'mixed_review'));

    $examMode = $defaultMode;
    $reason = 'admin_default_mode';
    $title = 'Önerilen Deneme';
    $questionCount = $defaultCount;

    if ($message !== '') {
        if (strpos($message, 'mini') !== false || strpos($message, 'kısa') !== false) {
            $examMode = 'motivation_warmup';
            $reason = 'mini_exam_request';
            $title = 'Mini Isınma Denemesi';
            $questionCount = max(1, min(20, $defaultCount));
        } elseif (strpos($message, 'yanlış') !== false || strpos($message, 'hata') !== false) {
            $examMode = 'last_exam_mistakes';
            $reason = 'last_exam_mistakes';
            $title = 'Son Hatalara Odaklı Deneme';
        } elseif (strpos($message, 'zayıf') !== false || strpos($message, 'eksik') !== false) {
            $examMode = 'weak_topics';
            $reason = 'recent_weak_topics';
            $title = 'Zayıf Alanlara Odaklı Deneme';
        } elseif (strpos($message, 'hafta') !== false) {
            $examMode = 'one_week_focus';
            $reason = 'one_week_focus';
            $title = '1 Haftalık Odağa Göre Deneme';
        }
    }

    $weakTopics = pusula_ai_tool_get_weak_topics($pdo, $userId, 3);
    $lastExam = pusula_ai_tool_get_last_exam_summary($pdo, $userId);
    $stats = pusula_ai_tool_get_user_stats($pdo, $userId);

    if ($examMode === 'weak_topics' && empty($weakTopics)) {
        $examMode = 'mixed_review';
        $reason = 'insufficient_weak_topic_data';
        $title = 'Karma Tekrar Denemesi';
    }

    if ($examMode === 'last_exam_mistakes' && !$lastExam) {
        if (!empty($weakTopics)) {
            $examMode = 'weak_topics';
            $reason = 'last_exam_missing_fallback_weak';
            $title = 'Zayıf Alanlara Odaklı Deneme';
        } else {
            $examMode = 'mixed_review';
            $reason = 'last_exam_missing';
            $title = 'Karma Tekrar Denemesi';
        }
    }

    if ((int)($stats['last_7_days_questions'] ?? 0) <= 5 && $examMode === 'mixed_review') {
        $examMode = 'motivation_warmup';
        $reason = 'low_recent_activity';
        $title = 'Isınma Denemesi';
        $questionCount = max(1, min(20, $defaultCount));
    }

    if ((int)($stats['last_7_days_questions'] ?? 0) >= 35 && $examMode === 'mixed_review') {
        $examMode = 'one_week_focus';
        $reason = 'one_week_focus';
        $title = '1 Haftalık Odağa Göre Deneme';
    }

    if ((int)($stats['total_questions_solved'] ?? 0) <= 0 && !$lastExam && empty($weakTopics)) {
        $examMode = 'mixed_review';
        $reason = 'no_personal_data';
        $title = 'Genel Tekrar Denemesi';
    }

    return [
        'type' => 'recommended_exam',
        'title' => $title,
        'exam_mode' => $examMode,
        'question_count' => max(1, min(100, $questionCount)),
        'reason' => $reason,
    ];
}
