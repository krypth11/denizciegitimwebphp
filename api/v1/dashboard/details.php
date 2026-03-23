<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

function dd_q(string $column): string
{
    return '`' . str_replace('`', '', $column) . '`';
}

function dd_first_existing(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
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
        ],
    ];

    $upCols = get_table_columns($pdo, 'user_progress');
    $qCols = get_table_columns($pdo, 'questions');
    $cCols = get_table_columns($pdo, 'courses');
    $qualCols = get_table_columns($pdo, 'qualifications');

    if (empty($upCols) || empty($qCols) || !in_array('user_id', $upCols, true)) {
        api_success('Dashboard detay istatistikleri alındı.', [
            'details' => $details,
        ]);
    }

    $upTable = 'user_progress';
    $qTable = 'questions';

    $upQuestionId = dd_first_existing($upCols, ['question_id']);
    $upIsAnswered = dd_first_existing($upCols, ['is_answered']);
    $upIsCorrect = dd_first_existing($upCols, ['is_correct']);
    $upIsBookmarked = dd_first_existing($upCols, ['is_bookmarked', 'bookmarked']);
    $upCorrectCount = dd_first_existing($upCols, ['correct_answer_count', 'correct_count']);
    $upWrongCount = dd_first_existing($upCols, ['wrong_answer_count', 'wrong_count', 'incorrect_count']);
    $upTotalCount = dd_first_existing($upCols, ['total_answer_count', 'answer_count', 'total_answers']);
    $upDateCol = dd_first_existing($upCols, ['answered_at', 'last_answered_at', 'updated_at', 'created_at']);

    $qId = dd_first_existing($qCols, ['id']);
    $qCourseId = dd_first_existing($qCols, ['course_id']);
    $qType = dd_first_existing($qCols, ['question_type']);

    if (!$upQuestionId || !$qId) {
        api_success('Dashboard detay istatistikleri alındı.', [
            'details' => $details,
        ]);
    }

    $answeredExpr = '0';
    if ($upTotalCount) {
        $answeredExpr = 'COALESCE(up.' . dd_q($upTotalCount) . ', 0)';
    } elseif ($upCorrectCount || $upWrongCount) {
        $correctPart = $upCorrectCount ? 'COALESCE(up.' . dd_q($upCorrectCount) . ', 0)' : '0';
        $wrongPart = $upWrongCount ? 'COALESCE(up.' . dd_q($upWrongCount) . ', 0)' : '0';
        $answeredExpr = '(' . $correctPart . ' + ' . $wrongPart . ')';
    } elseif ($upIsAnswered) {
        $answeredExpr = 'CASE WHEN up.' . dd_q($upIsAnswered) . ' = 1 THEN 1 ELSE 0 END';
    }

    $correctExpr = '0';
    if ($upCorrectCount) {
        $correctExpr = 'COALESCE(up.' . dd_q($upCorrectCount) . ', 0)';
    } elseif ($upIsCorrect) {
        $correctExpr = 'CASE WHEN up.' . dd_q($upIsCorrect) . ' = 1 THEN 1 ELSE 0 END';
    }

    $wrongExpr = '0';
    if ($upWrongCount) {
        $wrongExpr = 'COALESCE(up.' . dd_q($upWrongCount) . ', 0)';
    } elseif ($upIsCorrect) {
        if ($upIsAnswered) {
            $wrongExpr = 'CASE WHEN up.' . dd_q($upIsAnswered) . ' = 1 AND up.' . dd_q($upIsCorrect) . ' = 0 THEN 1 ELSE 0 END';
        } else {
            $wrongExpr = 'CASE WHEN up.' . dd_q($upIsCorrect) . ' = 0 THEN 1 ELSE 0 END';
        }
    }

    // A) activity_last_30_days
    if ($upDateCol) {
        $activitySql = 'SELECT DATE(up.' . dd_q($upDateCol) . ') AS activity_date, '
            . 'COALESCE(SUM(' . $answeredExpr . '), 0) AS answered, '
            . 'COALESCE(SUM(' . $correctExpr . '), 0) AS correct '
            . 'FROM ' . dd_q($upTable) . ' up '
            . 'WHERE up.' . dd_q('user_id') . ' = ? '
            . 'AND up.' . dd_q($upDateCol) . ' >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) '
            . 'GROUP BY DATE(up.' . dd_q($upDateCol) . ') '
            . 'ORDER BY activity_date ASC';

        $stmt = $pdo->prepare($activitySql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $map = [];
        foreach ($rows as $r) {
            $map[(string)$r['activity_date']] = [
                'date' => (string)$r['activity_date'],
                'answered' => (int)($r['answered'] ?? 0),
                'correct' => (int)($r['correct'] ?? 0),
            ];
        }

        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime('-' . $i . ' day'));
            $series[] = $map[$d] ?? ['date' => $d, 'answered' => 0, 'correct' => 0];
        }
        $details['activity_last_30_days'] = $series;
    }

    // G) bookmarked_summary
    if ($upIsBookmarked) {
        $bookmarkSql = 'SELECT COALESCE(SUM(CASE WHEN ' . dd_q($upIsBookmarked) . ' = 1 THEN 1 ELSE 0 END), 0) '
            . 'FROM ' . dd_q($upTable) . ' WHERE ' . dd_q('user_id') . ' = ?';
        $stmt = $pdo->prepare($bookmarkSql);
        $stmt->execute([$userId]);
        $details['bookmarked_summary']['total_bookmarked'] = (int)$stmt->fetchColumn();
    }

    // D) question_type_distribution
    if ($qType) {
        $typeSql = 'SELECT '
            . 'CASE '
            . 'WHEN LOWER(COALESCE(q.' . dd_q($qType) . ', "")) LIKE "%say%" OR LOWER(COALESCE(q.' . dd_q($qType) . ', "")) LIKE "%num%" THEN "sayisal" '
            . 'WHEN LOWER(COALESCE(q.' . dd_q($qType) . ', "")) LIKE "%soz%" OR LOWER(COALESCE(q.' . dd_q($qType) . ', "")) LIKE "%verbal%" THEN "sozel" '
            . 'ELSE "diger" END AS type_group, '
            . 'COUNT(*) AS total '
            . 'FROM ' . dd_q($upTable) . ' up '
            . 'INNER JOIN ' . dd_q($qTable) . ' q ON up.' . dd_q($upQuestionId) . ' = q.' . dd_q($qId) . ' '
            . 'WHERE up.' . dd_q('user_id') . ' = ? '
            . 'GROUP BY type_group';

        $stmt = $pdo->prepare($typeSql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $dist = [
            'sayisal' => 0,
            'sozel' => 0,
            'diger' => 0,
        ];
        foreach ($rows as $r) {
            $key = (string)($r['type_group'] ?? 'diger');
            if (!isset($dist[$key])) {
                $key = 'diger';
            }
            $dist[$key] += (int)($r['total'] ?? 0);
        }

        $details['question_type_distribution'] = [
            ['type' => 'sayisal', 'count' => (int)$dist['sayisal']],
            ['type' => 'sozel', 'count' => (int)$dist['sozel']],
            ['type' => 'diger', 'count' => (int)$dist['diger']],
        ];
    }

    $courseStats = [];
    if ($qCourseId && !empty($cCols)) {
        $cId = dd_first_existing($cCols, ['id']);
        $cName = dd_first_existing($cCols, ['name', 'title']);
        $cQualId = dd_first_existing($cCols, ['qualification_id']);

        if ($cId && $cName) {
            $courseSql = 'SELECT '
                . 'c.' . dd_q($cId) . ' AS course_id, '
                . 'c.' . dd_q($cName) . ' AS course_name, '
                . ($cQualId ? 'c.' . dd_q($cQualId) . ' AS qualification_id, ' : 'NULL AS qualification_id, ')
                . 'COALESCE(SUM(' . $answeredExpr . '), 0) AS answered, '
                . 'COALESCE(SUM(' . $correctExpr . '), 0) AS correct, '
                . 'COALESCE(SUM(' . $wrongExpr . '), 0) AS wrong '
                . 'FROM ' . dd_q($upTable) . ' up '
                . 'INNER JOIN ' . dd_q($qTable) . ' q ON up.' . dd_q($upQuestionId) . ' = q.' . dd_q($qId) . ' '
                . 'INNER JOIN ' . dd_q('courses') . ' c ON q.' . dd_q($qCourseId) . ' = c.' . dd_q($cId) . ' '
                . 'WHERE up.' . dd_q('user_id') . ' = ? '
                . 'GROUP BY c.' . dd_q($cId) . ', c.' . dd_q($cName)
                . ($cQualId ? ', c.' . dd_q($cQualId) : '')
                . ' ORDER BY answered DESC, c.' . dd_q($cName) . ' ASC';

            $stmt = $pdo->prepare($courseSql);
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $r) {
                $answered = (int)($r['answered'] ?? 0);
                $correct = (int)($r['correct'] ?? 0);
                $wrong = (int)($r['wrong'] ?? 0);
                $den = $correct + $wrong;
                $successRate = $den > 0 ? round(($correct / $den) * 100, 2) : 0;

                $courseStats[] = [
                    'course_id' => (string)($r['course_id'] ?? ''),
                    'course_name' => (string)($r['course_name'] ?? ''),
                    'qualification_id' => $r['qualification_id'] ?? null,
                    'answered' => $answered,
                    'correct' => $correct,
                    'wrong' => $wrong,
                    'success_rate' => $successRate,
                ];
            }
        }
    }

    $details['course_stats'] = $courseStats;

    // B) qualification_stats
    if (!empty($courseStats) && !empty($qualCols)) {
        $qualIdCol = dd_first_existing($qualCols, ['id']);
        $qualNameCol = dd_first_existing($qualCols, ['name', 'title']);

        $qualNames = [];
        if ($qualIdCol && $qualNameCol) {
            $qSql = 'SELECT ' . dd_q($qualIdCol) . ' AS id, ' . dd_q($qualNameCol) . ' AS name FROM ' . dd_q('qualifications');
            $rows = $pdo->query($qSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $qualNames[(string)$r['id']] = (string)$r['name'];
            }
        }

        $agg = [];
        foreach ($courseStats as $row) {
            $qid = (string)($row['qualification_id'] ?? '');
            if ($qid === '') {
                continue;
            }
            if (!isset($agg[$qid])) {
                $agg[$qid] = [
                    'qualification_id' => $qid,
                    'qualification_name' => $qualNames[$qid] ?? '',
                    'answered' => 0,
                    'correct' => 0,
                    'wrong' => 0,
                ];
            }
            $agg[$qid]['answered'] += (int)$row['answered'];
            $agg[$qid]['correct'] += (int)$row['correct'];
            $agg[$qid]['wrong'] += (int)$row['wrong'];
        }

        $qualStats = [];
        foreach ($agg as $qRow) {
            $den = (int)$qRow['correct'] + (int)$qRow['wrong'];
            $qRow['success_rate'] = $den > 0 ? round(((int)$qRow['correct'] / $den) * 100, 2) : 0;
            $qualStats[] = $qRow;
        }

        usort($qualStats, static function (array $a, array $b): int {
            return $b['answered'] <=> $a['answered'];
        });

        $details['qualification_stats'] = $qualStats;
    }

    // E/F) weak_areas / strong_areas (course bazlı)
    if (!empty($courseStats)) {
        $scored = array_values(array_filter($courseStats, static function (array $row): bool {
            return ((int)$row['answered']) > 0;
        }));

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

        $details['weak_areas'] = array_slice(array_map(static function (array $r): array {
            return [
                'area_type' => 'course',
                'area_id' => $r['course_id'],
                'area_name' => $r['course_name'],
                'answered' => (int)$r['answered'],
                'correct' => (int)$r['correct'],
                'wrong' => (int)$r['wrong'],
                'success_rate' => (float)$r['success_rate'],
            ];
        }, $weak), 0, 5);

        $details['strong_areas'] = array_slice(array_map(static function (array $r): array {
            return [
                'area_type' => 'course',
                'area_id' => $r['course_id'],
                'area_name' => $r['course_name'],
                'answered' => (int)$r['answered'],
                'correct' => (int)$r['correct'],
                'wrong' => (int)$r['wrong'],
                'success_rate' => (float)$r['success_rate'],
            ];
        }, $strong), 0, 5);
    }

    api_success('Dashboard detay istatistikleri alındı.', [
        'details' => $details,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}
