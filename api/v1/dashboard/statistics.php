<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

function stats_dbg(string $message, array $context = []): void
{
    $suffix = $context ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
    error_log('[dashboard.statistics] ' . $message . $suffix);
}

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $statistics = [
        'total_solved' => 0,
        'total_correct' => 0,
        'total_wrong' => 0,
    ];

    $sqlSolved = 'SELECT COUNT(*) FROM `question_attempt_events` WHERE `user_id` = ?';
    $stmtSolved = $pdo->prepare($sqlSolved);
    $stmtSolved->execute([$userId]);
    $statistics['total_solved'] = (int)$stmtSolved->fetchColumn();

    $sqlCorrect = 'SELECT COUNT(*) FROM `question_attempt_events` WHERE `user_id` = ? AND `is_correct` = 1';
    $stmtCorrect = $pdo->prepare($sqlCorrect);
    $stmtCorrect->execute([$userId]);
    $statistics['total_correct'] = (int)$stmtCorrect->fetchColumn();

    $sqlWrong = 'SELECT COUNT(*) FROM `question_attempt_events` WHERE `user_id` = ? AND `is_correct` = 0';
    $stmtWrong = $pdo->prepare($sqlWrong);
    $stmtWrong->execute([$userId]);
    $statistics['total_wrong'] = (int)$stmtWrong->fetchColumn();

    // İstenen kesin tutarlılık
    $statistics['total_solved'] = $statistics['total_correct'] + $statistics['total_wrong'];

    // Debug: 0 durumunda query + user_id context logla
    if ($statistics['total_solved'] === 0) {
        stats_dbg('total_solved is zero', [
            'user_id' => $userId,
            'query_solved' => $sqlSolved,
            'query_correct' => $sqlCorrect,
            'query_wrong' => $sqlWrong,
            'total_correct' => $statistics['total_correct'],
            'total_wrong' => $statistics['total_wrong'],
        ]);
    }

    api_success('Dashboard istatistikleri alındı.', [
        'statistics' => $statistics,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}
