<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

function ddd_q(string $column): string
{
    return '`' . str_replace('`', '', $column) . '`';
}

function ddd_first(array $cols, array $candidates): ?string
{
    foreach ($candidates as $c) {
        if (in_array($c, $cols, true)) {
            return $c;
        }
    }
    return null;
}

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $details = [
        'activity_last_30_days' => [],
        'qualification_stats' => [],
        'course_stats' => [],
        'question_type_distribution' => [],
        'weak_areas' => [],
        'strong_areas' => [],
        'bookmarked_summary' => [
            'total_bookmarked' => 0,
            'bookmarked_by_course' => [],
        ],
        'last_activity' => null,
        'last_answered_at' => null,
    ];

    $upCols = get_table_columns($pdo, 'user_progress');
    $qCols = get_table_columns($pdo, 'questions');
    $cCols = get_table_columns($pdo, 'courses');
    $qualCols = get_table_columns($pdo, 'qualifications');
    $ssCols = get_table_columns($pdo, 'study_sessions');

    if (empty($upCols) || empty($qCols) || !in_array('user_id', $upCols, true)) {
        api_success('Dashboard detay istatistikleri alındı.', ['details' => $details]);
    }

    $upQuestionId = ddd_first($upCols, ['question_id']);
    $upIsAnswered = ddd_first($upCols, ['is_answered']);
    $upIsCorrect = ddd_first($upCols, ['is_correct']);
    $upIsBookmarked = ddd_first($upCols, ['is_bookmarked', 'bookmarked']);
    $upCorrectCount = ddd_first($upCols, ['correct_answer_count', 'correct_count']);
    $upWrongCount = ddd_first($upCols, ['wrong_answer_count', 'wrong_count', 'incorrect_count']);
    $upAnsweredAt = ddd_first($upCols, ['answered_at', 'last_answered_at']);
    $upUpdatedAt = ddd_first($upCols, ['updated_at']);

    $qId = ddd_first($qCols, ['id']);
    $qCourseId = ddd_first($qCols, ['course_id']);
    $qType = ddd_first($qCols, ['question_type']);

    if (!$upQuestionId || !$qId) {
        api_success('Dashboard detay istatistikleri alındı.', ['details' => $details]);
    }

    $answeredExpr = $upIsAnswered
        ? 'CASE WHEN up.' . ddd_q($upIsAnswered) . ' = 1 THEN 1 ELSE 0 END'
        : '1';
    $correctExpr = $upCorrectCount
        ? 'COALESCE(up.' . ddd_q($upCorrectCount) . ', 0)'
        : ($upIsCorrect ? 'CASE WHEN up.' . ddd_q($upIsCorrect) . ' = 1 THEN 1 ELSE 0 END' : '0');
    $wrongExpr = $upWrongCount
        ? 'COALESCE(up.' . ddd_q($upWrongCount) . ', 0)'
        : ($upIsCorrect ? 'CASE WHEN up.' . ddd_q($upIsCorrect) . ' = 0 THEN 1 ELSE 0 END' : '0');

    // A) activity_last_30_days
    $dateCol = $upAnsweredAt ?: ($upUpdatedAt ?: null);
    if ($dateCol) {
        $sql = 'SELECT DATE(up.' . ddd_q($dateCol) . ') AS d, '
            . 'COALESCE(SUM(' . $answeredExpr . '),0) AS answered, '
            . 'COALESCE(SUM(' . $correctExpr . '),0) AS correct '
            . 'FROM `user_progress` up '
            . 'WHERE up.`user_id` = ? '
            . 'AND up.' . ddd_q($dateCol) . ' >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) '
            . 'GROUP BY DATE(up.' . ddd_q($dateCol) . ') ORDER BY d ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $map = [];
        foreach ($rows as $r) {
            $map[(string)$r['d']] = [
                'date' => (string)$r['d'],
                'answered' => (int)($r['answered'] ?? 0),
                'correct' => (int)($r['correct'] ?? 0),
            ];
        }

        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime('-' . $i . ' day'));
            $details['activity_last_30_days'][] = $map[$d] ?? [
                'date' => $d,
                'answered' => 0,
                'correct' => 0,
            ];
        }
    }

    // H) last_activity / last_answered_at
    if ($upAnsweredAt) {
        $stmt = $pdo->prepare('SELECT MAX(' . ddd_q($upAnsweredAt) . ') FROM `user_progress` WHERE `user_id` = ?');
        $stmt->execute([$userId]);
        $details['last_answered_at'] = $stmt->fetchColumn() ?: null;
    }

    $lastSessionAt = null;
    if (!empty($ssCols) && in_array('user_id', $ssCols, true)) {
        $ssDate = ddd_first($ssCols, ['created_at', 'updated_at']);
        if ($ssDate) {
            $stmt = $pdo->prepare('SELECT MAX(' . ddd_q($ssDate) . ') FROM `study_sessions` WHERE `user_id` = ?');
            $stmt->execute([$userId]);
            $lastSessionAt = $stmt->fetchColumn() ?: null;
        }
    }

    $details['last_activity'] = $details['last_answered_at'];
    if ($lastSessionAt && (!$details['last_activity'] || strtotime((string)$lastSessionAt) > strtotime((string)$details['last_activity']))) {
        $details['last_activity'] = $lastSessionAt;
    }

    // Base join for named stats
    $courseStats = [];
    if ($qCourseId && !empty($cCols)) {
        $cId = ddd_first($cCols, ['id']);
        $cName = ddd_first($cCols, ['name', 'title']);
        $cQualId = ddd_first($cCols, ['qualification_id']);
        $qualId = ddd_first($qualCols, ['id']);
        $qualName = ddd_first($qualCols, ['name', 'title']);

        if ($cId && $cName) {
            $sql = 'SELECT '
                . 'c.' . ddd_q($cId) . ' AS course_id, '
                . 'c.' . ddd_q($cName) . ' AS course_name, '
                . ($cQualId ? 'c.' . ddd_q($cQualId) . ' AS qualification_id, ' : 'NULL AS qualification_id, ')
                . (($cQualId && $qualId && $qualName) ? 'qf.' . ddd_q($qualName) . ' AS qualification_name, ' : "'' AS qualification_name, ")
                . 'COALESCE(SUM(' . $answeredExpr . '),0) AS answered, '
                . 'COALESCE(SUM(' . $correctExpr . '),0) AS correct, '
                . 'COALESCE(SUM(' . $wrongExpr . '),0) AS wrong '
                . 'FROM `user_progress` up '
                . 'INNER JOIN `questions` q ON up.' . ddd_q($upQuestionId) . ' = q.' . ddd_q($qId) . ' '
                . 'INNER JOIN `courses` c ON q.' . ddd_q($qCourseId) . ' = c.' . ddd_q($cId) . ' '
                . (($cQualId && $qualId && $qualName) ? 'LEFT JOIN `qualifications` qf ON c.' . ddd_q($cQualId) . ' = qf.' . ddd_q($qualId) . ' ' : '')
                . 'WHERE up.`user_id` = ? '
                . 'GROUP BY c.' . ddd_q($cId) . ', c.' . ddd_q($cName)
                . ($cQualId ? ', c.' . ddd_q($cQualId) : '')
                . (($cQualId && $qualId && $qualName) ? ', qf.' . ddd_q($qualName) : '')
                . ' ORDER BY answered DESC, course_name ASC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $r) {
                $answered = (int)($r['answered'] ?? 0);
                $correct = (int)($r['correct'] ?? 0);
                $wrong = (int)($r['wrong'] ?? 0);
                $den = $correct + $wrong;
                $courseStats[] = [
                    'course_id' => (string)($r['course_id'] ?? ''),
                    'course_name' => (string)($r['course_name'] ?? 'Bilinmiyor'),
                    'qualification_id' => $r['qualification_id'] ?? null,
                    'qualification_name' => (string)($r['qualification_name'] ?? ''),
                    'answered' => $answered,
                    'correct' => $correct,
                    'wrong' => $wrong,
                    'success_rate' => $den > 0 ? round(($correct / $den) * 100, 2) : 0,
                ];
            }
        }
    }
    $details['course_stats'] = $courseStats;

    // B) qualification_stats
    if (!empty($courseStats)) {
        $qAgg = [];
        foreach ($courseStats as $row) {
            $qid = (string)($row['qualification_id'] ?? '');
            if ($qid === '') {
                $qid = '__unknown__';
            }
            if (!isset($qAgg[$qid])) {
                $qAgg[$qid] = [
                    'qualification_id' => $qid === '__unknown__' ? null : $qid,
                    'qualification_name' => $row['qualification_name'] !== '' ? $row['qualification_name'] : 'Bilinmiyor',
                    'answered' => 0,
                    'correct' => 0,
                    'wrong' => 0,
                    'success_rate' => 0,
                ];
            }
            $qAgg[$qid]['answered'] += (int)$row['answered'];
            $qAgg[$qid]['correct'] += (int)$row['correct'];
            $qAgg[$qid]['wrong'] += (int)$row['wrong'];
        }

        $stats = array_values($qAgg);
        foreach ($stats as &$s) {
            $den = (int)$s['correct'] + (int)$s['wrong'];
            $s['success_rate'] = $den > 0 ? round(((int)$s['correct'] / $den) * 100, 2) : 0;
        }
        unset($s);

        usort($stats, static fn(array $a, array $b): int => $b['answered'] <=> $a['answered']);
        $details['qualification_stats'] = $stats;
    }

    // D) question_type_distribution (total vs answered/correct/wrong tutarlı)
    if ($qType) {
        $sql = 'SELECT '
            . 'COALESCE(q.' . ddd_q($qType) . ', "diger") AS question_type, '
            . 'COUNT(*) AS total_questions, '
            . 'COALESCE(SUM(' . $answeredExpr . '),0) AS answered, '
            . 'COALESCE(SUM(' . $correctExpr . '),0) AS correct, '
            . 'COALESCE(SUM(' . $wrongExpr . '),0) AS wrong '
            . 'FROM `user_progress` up '
            . 'INNER JOIN `questions` q ON up.' . ddd_q($upQuestionId) . ' = q.' . ddd_q($qId) . ' '
            . 'WHERE up.`user_id` = ? '
            . 'GROUP BY COALESCE(q.' . ddd_q($qType) . ', "diger") '
            . 'ORDER BY total_questions DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $dist = [];
        foreach ($rows as $r) {
            $correct = (int)($r['correct'] ?? 0);
            $wrong = (int)($r['wrong'] ?? 0);
            $den = $correct + $wrong;
            $dist[] = [
                'question_type' => (string)($r['question_type'] ?? 'diger'),
                'total_questions' => (int)($r['total_questions'] ?? 0),
                'answered' => (int)($r['answered'] ?? 0),
                'correct' => $correct,
                'wrong' => $wrong,
                'success_rate' => $den > 0 ? round(($correct / $den) * 100, 2) : 0,
            ];
        }
        $details['question_type_distribution'] = $dist;
    }

    // E/F) weak/strong areas (isimli ve fallback kontrollü)
    if (!empty($courseStats)) {
        $scored = array_values(array_filter($courseStats, static fn(array $r): bool => ((int)$r['answered']) > 0));

        $weak = $scored;
        usort($weak, static function (array $a, array $b): int {
            if ($a['success_rate'] == $b['success_rate']) {
                return $b['answered'] <=> $a['answered'];
            }
            return $a['success_rate'] <=> $b['success_rate'];
        });

        $strong = $scored;
        usort($strong, static function (array $a, array $b): int {
            if ($a['success_rate'] == $b['success_rate']) {
                return $b['answered'] <=> $a['answered'];
            }
            return $b['success_rate'] <=> $a['success_rate'];
        });

        $mapArea = static function (array $r): array {
            $name = trim((string)($r['course_name'] ?? ''));
            if ($name === '') {
                $name = 'Bilinmiyor';
            }
            return [
                'area_type' => 'course',
                'area_id' => (string)($r['course_id'] ?? ''),
                'area_name' => $name,
                'answered' => (int)($r['answered'] ?? 0),
                'correct' => (int)($r['correct'] ?? 0),
                'wrong' => (int)($r['wrong'] ?? 0),
                'success_rate' => (float)($r['success_rate'] ?? 0),
            ];
        };

        $details['weak_areas'] = array_slice(array_map($mapArea, $weak), 0, 5);
        $details['strong_areas'] = array_slice(array_map($mapArea, $strong), 0, 5);
    }

    // G) bookmarked_summary (+ kısa course özeti)
    if ($upIsBookmarked) {
        $sql = 'SELECT COALESCE(SUM(CASE WHEN up.' . ddd_q($upIsBookmarked) . ' = 1 THEN 1 ELSE 0 END),0) '
            . 'FROM `user_progress` up WHERE up.`user_id` = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $details['bookmarked_summary']['total_bookmarked'] = (int)$stmt->fetchColumn();

        if ($qCourseId && !empty($cCols)) {
            $cId = ddd_first($cCols, ['id']);
            $cName = ddd_first($cCols, ['name', 'title']);
            if ($cId && $cName) {
                $sql = 'SELECT c.' . ddd_q($cId) . ' AS course_id, c.' . ddd_q($cName) . ' AS course_name, '
                    . 'COUNT(*) AS total '
                    . 'FROM `user_progress` up '
                    . 'INNER JOIN `questions` q ON up.' . ddd_q($upQuestionId) . ' = q.' . ddd_q($qId) . ' '
                    . 'INNER JOIN `courses` c ON q.' . ddd_q($qCourseId) . ' = c.' . ddd_q($cId) . ' '
                    . 'WHERE up.`user_id` = ? AND up.' . ddd_q($upIsBookmarked) . ' = 1 '
                    . 'GROUP BY c.' . ddd_q($cId) . ', c.' . ddd_q($cName) . ' '
                    . 'ORDER BY total DESC, course_name ASC LIMIT 5';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$userId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $details['bookmarked_summary']['bookmarked_by_course'] = array_map(static function (array $r): array {
                    return [
                        'course_id' => (string)($r['course_id'] ?? ''),
                        'course_name' => (string)($r['course_name'] ?? 'Bilinmiyor'),
                        'total' => (int)($r['total'] ?? 0),
                    ];
                }, $rows);
            }
        }
    }

    api_success('Dashboard detay istatistikleri alındı.', [
        'details' => $details,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}
