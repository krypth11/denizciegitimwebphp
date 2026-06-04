<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('POST');

function api_stats_reset_progress_condition(string $userColumnPrefix = ''): string
{
    $p = $userColumnPrefix;
    return '(
        ' . $p . 'is_answered = 1
        OR ' . $p . 'total_answer_count > 0
        OR ' . $p . 'correct_answer_count > 0
        OR ' . $p . 'wrong_answer_count > 0
        OR ' . $p . 'answered_at IS NOT NULL
        OR ' . $p . 'first_answered_at IS NOT NULL
        OR ' . $p . 'last_answered_at IS NOT NULL
    )';
}

function api_stats_reset_count(PDO $pdo, string $sql, array $params): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function api_stats_reset_generate_archive_email(string $originalUserId): string
{
    $safeUserId = preg_replace('/[^a-zA-Z0-9_-]/', '', $originalUserId) ?: 'user';
    return 'archive+' . $safeUserId . '+' . time() . '+' . bin2hex(random_bytes(6)) . '@local.archive';
}

function api_stats_reset_insert_archive_profile(PDO $pdo, array $profileSchema, array $profile, string $archiveUserId, string $originalUserId): void
{
    $columns = [];
    $holders = [];
    $params = [];

    $addValue = static function (?string $column, $value) use (&$columns, &$holders, &$params): void {
        if (!$column) {
            return;
        }
        $columns[] = '`' . $column . '`';
        $holders[] = '?';
        $params[] = $value;
    };

    $addNow = static function (?string $column) use (&$columns, &$holders): void {
        if (!$column) {
            return;
        }
        $columns[] = '`' . $column . '`';
        $holders[] = 'NOW()';
    };

    $addValue($profileSchema['id'], $archiveUserId);
    $addValue($profileSchema['email'], api_stats_reset_generate_archive_email($originalUserId));
    $addValue($profileSchema['full_name'], 'İstatistik Arşivi');
    $addValue($profileSchema['password'], password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT));
    $addValue($profileSchema['is_admin'], 0);
    $addValue($profileSchema['is_guest'], 0);
    $addValue($profileSchema['is_deleted'], 0);
    $addValue($profileSchema['is_stats_archive'], 1);
    $addValue($profileSchema['archived_from_user_profile_id'], $originalUserId);
    $addNow($profileSchema['archived_at']);
    $addValue($profileSchema['current_qualification_id'], $profile['current_qualification_id'] ?? null);
    $addValue($profileSchema['target_qualification_id'], $profile['target_qualification_id'] ?? null);
    $addValue($profileSchema['onboarding_completed'], 1);
    $addValue($profileSchema['email_verified'], 0);
    $addValue($profileSchema['email_verified_at'], null);
    $addValue($profileSchema['pending_email'], null);
    $addValue($profileSchema['avatar_type'], 'default');
    $addValue($profileSchema['avatar_id'], 'avatar_01');
    $addValue($profileSchema['profile_photo_url'], null);
    $addNow($profileSchema['created_at']);
    $addNow($profileSchema['updated_at']);

    $sql = 'INSERT INTO `' . $profileSchema['table'] . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $holders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function api_stats_reset_insert_batch_log(PDO $pdo, string $originalUserId, string $archiveUserId, array $counts): void
{
    $columns = get_table_columns($pdo, 'user_stats_archive_batches');
    if (!$columns) {
        throw new RuntimeException('user_stats_archive_batches tablosu okunamadı.');
    }

    $insertColumns = [];
    $holders = [];
    $params = [];

    $addValue = static function (string $column, $value) use ($columns, &$insertColumns, &$holders, &$params): void {
        if (!in_array($column, $columns, true)) {
            return;
        }
        $insertColumns[] = '`' . $column . '`';
        $holders[] = '?';
        $params[] = $value;
    };

    $addNow = static function (string $column) use ($columns, &$insertColumns, &$holders): void {
        if (!in_array($column, $columns, true)) {
            return;
        }
        $insertColumns[] = '`' . $column . '`';
        $holders[] = 'NOW()';
    };

    $addValue('id', generate_uuid());
    $addValue('original_user_id', $originalUserId);
    $addValue('archive_user_id', $archiveUserId);
    $addNow('archived_at');
    $addValue('question_attempt_event_count', $counts['question_attempt_events']);
    $addValue('study_session_count', $counts['study_sessions']);
    $addValue('mock_exam_attempt_count', $counts['mock_exam_attempts']);
    $addValue('user_progress_archived_count', $counts['user_progress_archived']);
    $addValue('user_progress_deleted_count', $counts['user_progress_deleted']);
    $addValue('user_progress_bookmark_reset_count', $counts['user_progress_bookmark_reset']);
    $addValue('wrong_score_count', $counts['wrong_scores']);

    $required = [
        'original_user_id',
        'archive_user_id',
        'question_attempt_event_count',
        'study_session_count',
        'mock_exam_attempt_count',
        'user_progress_archived_count',
        'user_progress_deleted_count',
        'user_progress_bookmark_reset_count',
        'wrong_score_count',
    ];
    foreach ($required as $column) {
        if (!in_array($column, $columns, true)) {
            throw new RuntimeException('user_stats_archive_batches eksik kolon: ' . $column);
        }
    }

    $sql = 'INSERT INTO user_stats_archive_batches (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $holders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    if ($userId === '') {
        api_error('Yetkisiz erişim.', 401);
    }

    $profile = api_find_profile_by_user_id($pdo, $userId);
    if (!$profile) {
        api_error('Profil bulunamadı.', 404);
    }

    if (!empty($profile['is_guest'])) {
        api_error('Misafir kullanıcılar istatistik sıfırlama işlemi yapamaz.', 403);
    }

    if (!empty($profile['is_stats_archive'])) {
        api_error('Arşiv kullanıcılar istatistik sıfırlama işlemi yapamaz.', 403);
    }

    $progressCondition = api_stats_reset_progress_condition();
    $counts = [
        'question_attempt_events' => api_stats_reset_count($pdo, 'SELECT COUNT(*) FROM question_attempt_events WHERE user_id = ?', [$userId]),
        'study_sessions' => api_stats_reset_count($pdo, 'SELECT COUNT(*) FROM study_sessions WHERE user_id = ?', [$userId]),
        'mock_exam_attempts' => api_stats_reset_count($pdo, 'SELECT COUNT(*) FROM mock_exam_attempts WHERE user_id = ?', [$userId]),
        'wrong_scores' => api_stats_reset_count($pdo, 'SELECT COUNT(*) FROM user_question_wrong_scores WHERE user_id = ?', [$userId]),
        'user_progress_archived' => api_stats_reset_count($pdo, 'SELECT COUNT(*) FROM user_progress WHERE user_id = ? AND ' . $progressCondition, [$userId]),
        'user_progress_deleted' => api_stats_reset_count($pdo, 'SELECT COUNT(*) FROM user_progress WHERE user_id = ? AND is_bookmarked = 0', [$userId]),
        'user_progress_bookmark_reset' => api_stats_reset_count($pdo, 'SELECT COUNT(*) FROM user_progress WHERE user_id = ? AND is_bookmarked = 1 AND ' . $progressCondition, [$userId]),
    ];

    $hasStatsToArchive = ($counts['question_attempt_events'] + $counts['study_sessions'] + $counts['mock_exam_attempts'] + $counts['wrong_scores'] + $counts['user_progress_archived']) > 0;
    if (!$hasStatsToArchive) {
        api_success('İstatistikleriniz zaten sıfır durumda.', [
            'archive_created' => false,
        ]);
    }

    $profileSchema = api_get_profile_schema($pdo);
    $archiveUserId = generate_uuid();

    $pdo->beginTransaction();
    try {
        api_stats_reset_insert_archive_profile($pdo, $profileSchema, $profile, $archiveUserId, $userId);

        $stmt = $pdo->prepare('UPDATE question_attempt_events SET user_id = ? WHERE user_id = ?');
        $stmt->execute([$archiveUserId, $userId]);

        $stmt = $pdo->prepare('UPDATE study_sessions SET user_id = ? WHERE user_id = ?');
        $stmt->execute([$archiveUserId, $userId]);

        $stmt = $pdo->prepare('UPDATE mock_exam_attempts SET user_id = ? WHERE user_id = ?');
        $stmt->execute([$archiveUserId, $userId]);

        $stmt = $pdo->prepare('UPDATE user_question_wrong_scores SET user_id = ? WHERE user_id = ?');
        $stmt->execute([$archiveUserId, $userId]);

        $insertProgressSql = 'INSERT INTO user_progress (
                id,
                user_id,
                question_id,
                is_answered,
                is_correct,
                is_bookmarked,
                answered_at,
                created_at,
                total_answer_count,
                correct_answer_count,
                wrong_answer_count,
                last_selected_answer,
                first_answered_at,
                last_answered_at,
                updated_at
            )
            SELECT
                UUID(),
                ?,
                question_id,
                is_answered,
                is_correct,
                0,
                answered_at,
                created_at,
                total_answer_count,
                correct_answer_count,
                wrong_answer_count,
                last_selected_answer,
                first_answered_at,
                last_answered_at,
                updated_at
            FROM user_progress
            WHERE user_id = ? AND ' . $progressCondition;
        $stmt = $pdo->prepare($insertProgressSql);
        $stmt->execute([$archiveUserId, $userId]);

        $resetBookmarkSql = 'UPDATE user_progress
            SET
                is_answered = 0,
                is_correct = NULL,
                answered_at = NULL,
                total_answer_count = 0,
                correct_answer_count = 0,
                wrong_answer_count = 0,
                last_selected_answer = NULL,
                first_answered_at = NULL,
                last_answered_at = NULL,
                updated_at = NOW()
            WHERE user_id = ? AND is_bookmarked = 1 AND ' . $progressCondition;
        $stmt = $pdo->prepare($resetBookmarkSql);
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare('DELETE FROM user_progress WHERE user_id = ? AND is_bookmarked = 0');
        $stmt->execute([$userId]);

        api_stats_reset_insert_batch_log($pdo, $userId, $archiveUserId, $counts);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    api_success('İstatistikleriniz sıfırlandı.', [
        'archive_created' => true,
        'archive_user_id' => $archiveUserId,
        'archived_counts' => $counts,
    ]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[profile.reset-statistics] ' . $e->getMessage());
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}