<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';
require_once __DIR__ . '/performance_comparison_helper.php';

api_require_method('GET');

function pc_validate_scope(?string $scopeRaw): string
{
    $scope = strtolower(trim((string)$scopeRaw));
    if ($scope === '') {
        $scope = 'qualification';
    }

    if (!in_array($scope, ['qualification', 'course', 'topic'], true)) {
        api_error('Geçersiz scope parametresi.', 422);
    }

    return $scope;
}

function pc_optional_id_from_query(string $key): string
{
    return api_validate_optional_id((string)($_GET[$key] ?? ''), $key);
}

function pc_normalize_numeric_summary(array $userSummary, array $benchmarkSummary): array
{
    $userSummary['solved_count'] = (int)($userSummary['solved_count'] ?? 0);
    $userSummary['correct_count'] = (int)($userSummary['correct_count'] ?? 0);
    $userSummary['wrong_count'] = (int)($userSummary['wrong_count'] ?? 0);
    $userSummary['success_rate'] = round((float)($userSummary['success_rate'] ?? 0), 2);
    $userSummary['delta_vs_benchmark'] = isset($userSummary['delta_vs_benchmark']) && $userSummary['delta_vs_benchmark'] !== null
        ? round((float)$userSummary['delta_vs_benchmark'], 2)
        : null;
    $userSummary['percentile'] = isset($userSummary['percentile']) && $userSummary['percentile'] !== null
        ? round((float)$userSummary['percentile'], 2)
        : null;

    $benchmarkSummary['participants_count'] = (int)($benchmarkSummary['participants_count'] ?? 0);
    $benchmarkSummary['avg_solved_count'] = round((float)($benchmarkSummary['avg_solved_count'] ?? 0), 2);
    $benchmarkSummary['avg_correct_count'] = round((float)($benchmarkSummary['avg_correct_count'] ?? 0), 2);
    $benchmarkSummary['avg_wrong_count'] = round((float)($benchmarkSummary['avg_wrong_count'] ?? 0), 2);
    $benchmarkSummary['avg_success_rate'] = isset($benchmarkSummary['avg_success_rate']) && $benchmarkSummary['avg_success_rate'] !== null
        ? round((float)$benchmarkSummary['avg_success_rate'], 2)
        : null;
    $benchmarkSummary['top20_success_rate'] = isset($benchmarkSummary['top20_success_rate']) && $benchmarkSummary['top20_success_rate'] !== null
        ? round((float)$benchmarkSummary['top20_success_rate'], 2)
        : null;

    return [$userSummary, $benchmarkSummary];
}

function pc_build_delta_bars(string $scope, array $courseBreakdown, array $topicBreakdown): array
{
    $items = $scope === 'qualification' ? $courseBreakdown : ($scope === 'course' ? $topicBreakdown : []);
    if (empty($items)) {
        return [];
    }

    usort($items, static function (array $a, array $b): int {
        return abs((float)($b['delta_vs_benchmark'] ?? 0)) <=> abs((float)($a['delta_vs_benchmark'] ?? 0));
    });

    $bars = [];
    foreach (array_slice($items, 0, 8) as $item) {
        $userValue = round((float)($item['success_rate'] ?? 0), 2);
        $benchmarkValue = isset($item['benchmark_success_rate']) && $item['benchmark_success_rate'] !== null
            ? round((float)$item['benchmark_success_rate'], 2)
            : 0.0;
        $delta = isset($item['delta_vs_benchmark']) && $item['delta_vs_benchmark'] !== null
            ? round((float)$item['delta_vs_benchmark'], 2)
            : round($userValue - $benchmarkValue, 2);

        $bars[] = [
            'id' => $item['id'] ?? null,
            'label' => (string)($item['name'] ?? ''),
            'user_value' => $userValue,
            'benchmark_value' => $benchmarkValue,
            'delta' => $delta,
            'workload' => (int)($item['solved_count'] ?? 0),
        ];
    }

    return $bars;
}

function pc_build_workload_bars(string $scope, array $courseBreakdown, array $topicBreakdown): array
{
    $items = $scope === 'topic' ? [] : ($scope === 'course' ? $topicBreakdown : $courseBreakdown);
    if (empty($items)) {
        return [];
    }

    usort($items, static fn(array $a, array $b): int => ((int)($b['solved_count'] ?? 0)) <=> ((int)($a['solved_count'] ?? 0)));
    $bars = [];
    foreach (array_slice($items, 0, 8) as $item) {
        $solvedCount = (int)($item['solved_count'] ?? 0);
        $bars[] = [
            'id' => $item['id'] ?? null,
            'label' => (string)($item['name'] ?? ''),
            'user_value' => $solvedCount,
            'benchmark_value' => 0,
            'delta' => 0,
            'workload' => $solvedCount,
        ];
    }

    return $bars;
}

function pc_build_status_label(?float $delta): string
{
    if ($delta === null) {
        return 'Veri yetersiz';
    }
    if ($delta >= 3) {
        return 'Benchmark üstü';
    }
    if ($delta <= -3) {
        return 'Benchmark altı';
    }

    return 'Benchmarka yakın';
}

function pc_normalize_breakdown_items(array $items): array
{
    $result = [];
    foreach ($items as $item) {
        $userRate = round((float)($item['success_rate'] ?? 0), 2);
        $benchmarkRate = isset($item['benchmark_success_rate']) && $item['benchmark_success_rate'] !== null
            ? round((float)$item['benchmark_success_rate'], 2)
            : null;
        $delta = isset($item['delta_vs_benchmark']) && $item['delta_vs_benchmark'] !== null
            ? round((float)$item['delta_vs_benchmark'], 2)
            : ($benchmarkRate !== null ? round($userRate - $benchmarkRate, 2) : null);

        $item['success_rate'] = $userRate;
        $item['benchmark_success_rate'] = $benchmarkRate;
        $item['delta_vs_benchmark'] = $delta;
        $item['user_success_rate'] = $userRate;
        $item['delta'] = $delta;
        $item['solved_count'] = (int)($item['solved_count'] ?? 0);
        $item['status_label'] = pc_build_status_label($delta);

        $result[] = $item;
    }

    return $result;
}

function pc_fetch_context_collections(PDO $pdo, array $context): array
{
    $qualificationId = trim((string)($context['qualification_id'] ?? ''));
    $courseId = trim((string)($context['course_id'] ?? ''));

    $courses = [];
    if ($qualificationId !== '') {
        $courseStmt = $pdo->prepare('SELECT id, name FROM courses WHERE qualification_id = ? ORDER BY name ASC');
        $courseStmt->execute([$qualificationId]);
        foreach (($courseStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $courses[] = [
                'id' => (string)($row['id'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
            ];
        }
    }

    $topics = [];
    if ($courseId !== '') {
        $topicStmt = $pdo->prepare('SELECT id, name FROM topics WHERE course_id = ? ORDER BY name ASC');
        $topicStmt->execute([$courseId]);
        foreach (($topicStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $topics[] = [
                'id' => (string)($row['id'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
            ];
        }
    }

    return [
        'courses' => $courses,
        'topics' => $topics,
    ];
}

function pc_normalize_insights(array $insights): array
{
    $strongAreas = array_values(array_filter(array_map(static function (array $item): string {
        return trim((string)($item['name'] ?? ''));
    }, $insights['strongest_items'] ?? []), static fn(string $name): bool => $name !== ''));

    $weakAreas = array_values(array_filter(array_map(static function (array $item): string {
        return trim((string)($item['name'] ?? ''));
    }, $insights['weakest_items'] ?? []), static fn(string $name): bool => $name !== ''));

    return [
        'headline' => (string)($insights['summary_text'] ?? ''),
        'strong_areas' => $strongAreas,
        'weak_areas' => $weakAreas,
        'focus_suggestion' => (string)($insights['focus_text'] ?? ''),
        'trend_comment' => (string)($insights['trend_text'] ?? ''),
    ];
}

function pc_build_empty_comparison(string $scope, array $window, array $context, bool $topicSupport): array
{
    $contextCollections = [
        'courses' => [],
        'topics' => [],
    ];

    $comparison = [
        'scope' => $scope,
        'range' => (string)$window['range'],
        'topic_support' => $topicSupport,
        'context' => [
            'qualification_id' => $context['qualification_id'] ?? null,
            'qualification_name' => $context['qualification_name'] ?? null,
            'course_id' => $context['course_id'] ?? null,
            'course_name' => $context['course_name'] ?? null,
            'topic_id' => $context['topic_id'] ?? null,
            'topic_name' => $context['topic_name'] ?? null,
            'courses' => $contextCollections['courses'],
            'topics' => $contextCollections['topics'],
        ],
        'user_summary' => [
            'success_rate' => 0,
            'solved_count' => 0,
            'correct_count' => 0,
            'wrong_count' => 0,
            'delta_vs_benchmark' => null,
            'percentile' => null,
            'rank_label' => 'Yeterli veri yok',
            'qualification_id' => $context['qualification_id'] ?? null,
            'qualification_name' => $context['qualification_name'] ?? null,
            'course_id' => $context['course_id'] ?? null,
            'course_name' => $context['course_name'] ?? null,
            'topic_id' => $context['topic_id'] ?? null,
            'topic_name' => $context['topic_name'] ?? null,
        ],
        'benchmark_summary' => [
            'success_rate' => 0,
            'solved_count' => 0,
            'participants_count' => 0,
            'avg_solved_count' => 0,
            'avg_correct_count' => 0,
            'avg_wrong_count' => 0,
            'avg_success_rate' => null,
            'top20_success_rate' => null,
            'benchmark_label' => 'Yeterli benchmark verisi yok',
        ],
        'course_breakdown' => [],
        'topic_breakdown' => [],
        'trend_points' => [],
        'comparison_bars' => [],
        'delta_bars' => [],
        'workload_bars' => [],
        'insights' => [
            'headline' => 'Bu aralıkta çözüm verin yok. Insight üretilemedi.',
            'strong_areas' => [],
            'weak_areas' => [],
            'focus_suggestion' => 'Önce düzenli çözüm yaparak veri oluşmasını bekleyin.',
            'trend_comment' => 'Trend üretilemedi.',
        ],
    ];

    return $comparison;
}

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $subscription = usage_limits_get_user_subscription_status($pdo, $userId);
    if (!usage_limits_is_subscription_active($subscription)) {
        api_error('Bu endpoint yalnızca premium kullanıcılar için kullanılabilir.', 403);
    }

    $scope = pc_validate_scope((string)($_GET['scope'] ?? 'qualification'));
    $window = pc_resolve_window((string)($_GET['range'] ?? '30d'));
    $courseId = pc_optional_id_from_query('course_id');
    $topicId = pc_optional_id_from_query('topic_id');

    $context = pc_resolve_scope_context($pdo, $userId, $scope, $courseId, $topicId);
    $contextCollections = pc_fetch_context_collections($pdo, $context);
    $eventSchema = pc_get_event_schema($pdo);
    $scopeFilters = pc_build_scope_filters($eventSchema, $scope, $window, $context);

    $topicSupport = true;
    if ($scope === 'course') {
        $topicSupport = pc_has_topics_for_course($pdo, (string)($context['course_id'] ?? ''));
    }

    $userSummary = pc_fetch_user_summary($pdo, $userId, $eventSchema, $scopeFilters, $context);
    $benchmarkRows = pc_fetch_benchmark_user_rows($pdo, $userId, $eventSchema, $scopeFilters);
    $benchmarkSummary = pc_fetch_benchmark_summary($benchmarkRows);

    $userSummary['percentile'] = pc_fetch_percentile((float)($userSummary['success_rate'] ?? 0), $benchmarkRows);
    $userSummary['rank_label'] = pc_build_rank_label($userSummary['percentile']);
    $userSummary['delta_vs_benchmark'] = isset($benchmarkSummary['avg_success_rate']) && $benchmarkSummary['avg_success_rate'] !== null
        ? round((float)$userSummary['success_rate'] - (float)$benchmarkSummary['avg_success_rate'], 2)
        : null;

    $courseBreakdown = [];
    $topicBreakdown = [];
    if ($scope === 'qualification') {
        $courseBreakdown = pc_fetch_course_breakdown($pdo, $userId, $eventSchema, $scopeFilters);
    }
    if ($scope === 'course' && $topicSupport) {
        $topicBreakdown = pc_fetch_topic_breakdown($pdo, $userId, $eventSchema, $scopeFilters);
    }

    $trendPoints = pc_fetch_trend_points($pdo, $userId, $eventSchema, $scopeFilters, $window);

    [$userSummary, $benchmarkSummary] = pc_normalize_numeric_summary($userSummary, $benchmarkSummary);
    $benchmarkSummary['success_rate'] = $benchmarkSummary['avg_success_rate'] ?? 0;
    $benchmarkSummary['solved_count'] = $benchmarkSummary['avg_solved_count'] ?? 0;

    $courseBreakdown = pc_normalize_breakdown_items($courseBreakdown);
    $topicBreakdown = pc_normalize_breakdown_items($topicBreakdown);

    $insightItems = $scope === 'qualification' ? $courseBreakdown : ($scope === 'course' ? $topicBreakdown : []);
    $insightsRaw = pc_build_insights($userSummary, $benchmarkSummary, $insightItems, $trendPoints);
    $insights = pc_normalize_insights($insightsRaw);

    $comparison = pc_build_empty_comparison($scope, $window, $context, $topicSupport);
    $comparison['context']['courses'] = $contextCollections['courses'];
    $comparison['context']['topics'] = $contextCollections['topics'];
    $comparison['user_summary'] = $userSummary;
    $comparison['benchmark_summary'] = $benchmarkSummary;
    $comparison['course_breakdown'] = $courseBreakdown;
    $comparison['topic_breakdown'] = $scope === 'course' ? $topicBreakdown : [];
    $comparison['trend_points'] = $trendPoints;
    $comparison['comparison_bars'] = [[
        'id' => 'user_vs_benchmark',
        'label' => 'Başarı',
        'user_value' => round((float)($userSummary['success_rate'] ?? 0), 2),
        'benchmark_value' => isset($benchmarkSummary['avg_success_rate']) && $benchmarkSummary['avg_success_rate'] !== null
            ? round((float)$benchmarkSummary['avg_success_rate'], 2)
            : 0.0,
        'delta' => isset($userSummary['delta_vs_benchmark']) && $userSummary['delta_vs_benchmark'] !== null
            ? round((float)$userSummary['delta_vs_benchmark'], 2)
            : 0.0,
        'workload' => (int)($userSummary['solved_count'] ?? 0),
    ]];
    $comparison['delta_bars'] = pc_build_delta_bars($scope, $courseBreakdown, $topicBreakdown);
    $comparison['workload_bars'] = pc_build_workload_bars($scope, $courseBreakdown, $topicBreakdown);
    $comparison['insights'] = $insights;

    api_success('Karşılaştırmalı performans verisi alındı.', [
        'comparison' => $comparison,
    ]);
} catch (RuntimeException $e) {
    api_error($e->getMessage() !== '' ? $e->getMessage() : 'Karşılaştırma verisi üretilemedi.', 422);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}
