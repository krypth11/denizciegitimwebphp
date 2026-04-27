<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/question_scope_helper.php';

$user = require_admin();
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

function question_scope_bulk_json($success, $message = '', $data = [], $status = 200)
{
    http_response_code($status);
    echo json_encode([
        'success' => (bool)$success,
        'message' => (string)$message,
        'data' => is_array($data) ? $data : [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function question_scope_bulk_normalize_id($value): string
{
    return trim((string)$value);
}

function question_scope_bulk_parse_filters(): array
{
    $sourceQualificationId = question_scope_bulk_normalize_id($_REQUEST['source_qualification_id'] ?? '');
    $sourceCourseId = question_scope_bulk_normalize_id($_REQUEST['source_course_id'] ?? '');
    $sourceTopicId = question_scope_normalize_topic_id($_REQUEST['source_topic_id'] ?? '');
    $questionType = trim((string)($_REQUEST['question_type'] ?? ''));
    $search = trim((string)($_REQUEST['search'] ?? ''));

    return [
        'source_qualification_id' => $sourceQualificationId,
        'source_course_id' => $sourceCourseId,
        'source_topic_id' => $sourceTopicId,
        'question_type' => $questionType,
        'search' => $search,
    ];
}

function question_scope_bulk_parse_target_scope(): array
{
    $targetQualificationId = question_scope_bulk_normalize_id($_REQUEST['target_qualification_id'] ?? '');
    $targetCourseId = question_scope_bulk_normalize_id($_REQUEST['target_course_id'] ?? '');
    $targetTopicId = question_scope_normalize_topic_id($_REQUEST['target_topic_id'] ?? '');

    return [
        'target_qualification_id' => $targetQualificationId,
        'target_course_id' => $targetCourseId,
        'target_topic_id' => $targetTopicId,
    ];
}

function question_scope_bulk_validate_source_filters(array $filters): void
{
    if (($filters['source_qualification_id'] ?? '') === '' || ($filters['source_course_id'] ?? '') === '') {
        throw new RuntimeException('Kaynak yeterlilik ve kaynak ders zorunludur.');
    }
}

function question_scope_bulk_validate_target_scope(PDO $pdo, array $target): void
{
    $targetQualificationId = (string)($target['target_qualification_id'] ?? '');
    $targetCourseId = (string)($target['target_course_id'] ?? '');
    $targetTopicId = (string)($target['target_topic_id'] ?? '');

    if ($targetQualificationId === '' || $targetCourseId === '') {
        throw new RuntimeException('Hedef yeterlilik ve hedef ders zorunludur.');
    }

    question_scope_validate_scope($pdo, $targetQualificationId, $targetCourseId, $targetTopicId);
}

function question_scope_bulk_validate_source_target_not_same(array $filters, array $target): void
{
    $sameQualification = ($filters['source_qualification_id'] ?? '') === ($target['target_qualification_id'] ?? '');
    $sameCourse = ($filters['source_course_id'] ?? '') === ($target['target_course_id'] ?? '');
    $sameTopic = question_scope_normalize_topic_id($filters['source_topic_id'] ?? '') === question_scope_normalize_topic_id($target['target_topic_id'] ?? '');

    if ($sameQualification && $sameCourse && $sameTopic) {
        throw new RuntimeException('Kaynak ve hedef kapsam aynı olamaz.');
    }
}

/**
 * Kaynak filtreye göre soru id listesi döndürür.
 * Öncelik question_scope_links kapsamı; fallback questions(course/topic) kapsamı da desteklenir.
 */
function bulk_scope_find_source_question_ids(PDO $pdo, array $filters): array
{
    if (!question_scope_has_links_table($pdo)) {
        throw new RuntimeException('question_scope_links tablosu bulunamadı.');
    }

    question_scope_bulk_validate_source_filters($filters);

    $sourceQualificationId = (string)$filters['source_qualification_id'];
    $sourceCourseId = (string)$filters['source_course_id'];
    $sourceTopicId = question_scope_normalize_topic_id($filters['source_topic_id'] ?? '');
    $questionType = trim((string)($filters['question_type'] ?? ''));
    $search = trim((string)($filters['search'] ?? ''));

    $questionCols = get_table_columns($pdo, 'questions');
    $hasTopicId = is_array($questionCols) && in_array('topic_id', $questionCols, true);

    $where = [];
    $params = [];

    $scopeWhere = 'qsl.qualification_id = ? AND qsl.course_id = ?';
    $scopeParams = [$sourceQualificationId, $sourceCourseId];
    if ($sourceTopicId !== '') {
        $scopeWhere .= ' AND qsl.topic_id = ?';
        $scopeParams[] = $sourceTopicId;
    }
    $where[] = '(' . $scopeWhere . ')';
    $params = array_merge($params, $scopeParams);

    $fallbackWhere = 'qc.qualification_id = ? AND q.course_id = ?';
    $fallbackParams = [$sourceQualificationId, $sourceCourseId];
    if ($sourceTopicId !== '') {
        if ($hasTopicId) {
            $fallbackWhere .= ' AND q.topic_id = ?';
            $fallbackParams[] = $sourceTopicId;
        } else {
            $fallbackWhere .= ' AND 1=0';
        }
    }
    $where[] = '(' . $fallbackWhere . ')';
    $params = array_merge($params, $fallbackParams);

    $sql = 'SELECT DISTINCT q.id
            FROM questions q
            LEFT JOIN question_scope_links qsl ON qsl.question_id = q.id
            LEFT JOIN courses qc ON qc.id = q.course_id
            WHERE (' . implode(' OR ', $where) . ')';

    if ($questionType !== '') {
        $sql .= ' AND q.question_type = ?';
        $params[] = $questionType;
    }

    if ($search !== '') {
        $sql .= ' AND q.question_text LIKE ?';
        $params[] = '%' . $search . '%';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $ids = [];
    while ($id = $stmt->fetchColumn()) {
        $id = trim((string)$id);
        if ($id !== '') {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

function question_scope_bulk_count_existing_target_links(PDO $pdo, array $questionIds, array $target): int
{
    if (empty($questionIds)) {
        return 0;
    }

    $targetQualificationId = (string)$target['target_qualification_id'];
    $targetCourseId = (string)$target['target_course_id'];
    $targetTopicId = question_scope_normalize_topic_id($target['target_topic_id'] ?? '');

    $count = 0;
    $chunks = array_chunk($questionIds, 500);
    foreach ($chunks as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $sql = 'SELECT COUNT(DISTINCT question_id)
                FROM question_scope_links
                WHERE qualification_id = ? AND course_id = ? AND topic_id = ?
                  AND question_id IN (' . $placeholders . ')';
        $params = array_merge([$targetQualificationId, $targetCourseId, $targetTopicId], $chunk);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $count += (int)$stmt->fetchColumn();
    }

    return $count;
}

function question_scope_bulk_insert_links(PDO $pdo, array $questionIds, array $target): int
{
    if (empty($questionIds)) {
        return 0;
    }

    $targetQualificationId = (string)$target['target_qualification_id'];
    $targetCourseId = (string)$target['target_course_id'];
    $targetTopicId = question_scope_normalize_topic_id($target['target_topic_id'] ?? '');

    $inserted = 0;
    $chunks = array_chunk($questionIds, 300);
    foreach ($chunks as $chunk) {
        $valuesSql = [];
        $params = [];
        foreach ($chunk as $questionId) {
            $valuesSql[] = '(?, ?, ?, ?, ?, 0)';
            $params[] = generate_uuid();
            $params[] = $questionId;
            $params[] = $targetQualificationId;
            $params[] = $targetCourseId;
            $params[] = $targetTopicId;
        }

        if (empty($valuesSql)) {
            continue;
        }

        $sql = 'INSERT IGNORE INTO question_scope_links (id, question_id, qualification_id, course_id, topic_id, is_primary) VALUES ' . implode(', ', $valuesSql);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $inserted += (int)$stmt->rowCount();
    }

    return $inserted;
}

function question_scope_bulk_remove_links(PDO $pdo, array $questionIds, array $target): array
{
    if (empty($questionIds)) {
        return [
            'matched_links' => 0,
            'deleted_count' => 0,
            'skipped_primary_count' => 0,
        ];
    }

    $targetQualificationId = (string)$target['target_qualification_id'];
    $targetCourseId = (string)$target['target_course_id'];
    $targetTopicId = question_scope_normalize_topic_id($target['target_topic_id'] ?? '');

    $matchedLinks = 0;
    $deletedCount = 0;
    $skippedPrimaryCount = 0;

    $chunks = array_chunk($questionIds, 500);
    foreach ($chunks as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));

        $countSql = 'SELECT
                        COUNT(*) AS matched_links,
                        SUM(CASE WHEN is_primary = 1 THEN 1 ELSE 0 END) AS skipped_primary_count
                     FROM question_scope_links
                     WHERE qualification_id = ? AND course_id = ? AND topic_id = ?
                       AND question_id IN (' . $placeholders . ')';
        $countParams = array_merge([$targetQualificationId, $targetCourseId, $targetTopicId], $chunk);
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($countParams);
        $countRow = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $matchedLinks += (int)($countRow['matched_links'] ?? 0);
        $skippedPrimaryCount += (int)($countRow['skipped_primary_count'] ?? 0);

        $deleteSql = 'DELETE FROM question_scope_links
                      WHERE qualification_id = ? AND course_id = ? AND topic_id = ?
                        AND is_primary = 0
                        AND question_id IN (' . $placeholders . ')';
        $deleteParams = array_merge([$targetQualificationId, $targetCourseId, $targetTopicId], $chunk);
        $deleteStmt = $pdo->prepare($deleteSql);
        $deleteStmt->execute($deleteParams);
        $deletedCount += (int)$deleteStmt->rowCount();
    }

    return [
        'matched_links' => $matchedLinks,
        'deleted_count' => $deletedCount,
        'skipped_primary_count' => $skippedPrimaryCount,
    ];
}

try {
    if (!question_scope_has_links_table($pdo)) {
        question_scope_bulk_json(false, 'question_scope_links tablosu bulunamadı.', [], 422);
    }

    switch ($action) {
        case 'preview':
            $filters = question_scope_bulk_parse_filters();
            $target = question_scope_bulk_parse_target_scope();

            question_scope_bulk_validate_source_filters($filters);
            question_scope_bulk_validate_target_scope($pdo, $target);
            question_scope_bulk_validate_source_target_not_same($filters, $target);

            $sourceQuestionIds = bulk_scope_find_source_question_ids($pdo, $filters);
            $totalSourceQuestions = count($sourceQuestionIds);
            $alreadyLinkedCount = question_scope_bulk_count_existing_target_links($pdo, $sourceQuestionIds, $target);
            $willAddCount = max(0, $totalSourceQuestions - $alreadyLinkedCount);

            $sampleQuestions = [];
            if (!empty($sourceQuestionIds)) {
                $sampleIds = array_slice($sourceQuestionIds, 0, 10);
                $placeholders = implode(',', array_fill(0, count($sampleIds), '?'));
                $sampleSql = 'SELECT id, question_text FROM questions WHERE id IN (' . $placeholders . ') LIMIT 10';
                $sampleStmt = $pdo->prepare($sampleSql);
                $sampleStmt->execute($sampleIds);
                $sampleQuestions = $sampleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            question_scope_bulk_json(true, '', [
                'summary' => [
                    'total_source_questions' => $totalSourceQuestions,
                    'already_linked_count' => $alreadyLinkedCount,
                    'will_add_count' => $willAddCount,
                    'primary_count' => $totalSourceQuestions,
                ],
                'sample_questions' => $sampleQuestions,
            ]);
            break;

        case 'bulk_add':
            @set_time_limit(180);

            $filters = question_scope_bulk_parse_filters();
            $target = question_scope_bulk_parse_target_scope();

            question_scope_bulk_validate_source_filters($filters);
            question_scope_bulk_validate_target_scope($pdo, $target);
            question_scope_bulk_validate_source_target_not_same($filters, $target);

            $sourceQuestionIds = bulk_scope_find_source_question_ids($pdo, $filters);
            $totalSourceQuestions = count($sourceQuestionIds);

            if ($totalSourceQuestions === 0) {
                question_scope_bulk_json(true, 'Toplu kapsam ekleme tamamlandı.', [
                    'total_source_questions' => 0,
                    'inserted_count' => 0,
                    'skipped_existing_count' => 0,
                ]);
            }

            $pdo->beginTransaction();
            try {
                $insertedCount = question_scope_bulk_insert_links($pdo, $sourceQuestionIds, $target);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            $skippedExistingCount = max(0, $totalSourceQuestions - $insertedCount);

            question_scope_bulk_json(true, 'Toplu kapsam ekleme tamamlandı.', [
                'total_source_questions' => $totalSourceQuestions,
                'inserted_count' => $insertedCount,
                'skipped_existing_count' => $skippedExistingCount,
            ]);
            break;

        case 'bulk_remove':
            @set_time_limit(180);

            $filters = question_scope_bulk_parse_filters();
            $target = question_scope_bulk_parse_target_scope();

            question_scope_bulk_validate_source_filters($filters);
            question_scope_bulk_validate_target_scope($pdo, $target);
            question_scope_bulk_validate_source_target_not_same($filters, $target);

            $sourceQuestionIds = bulk_scope_find_source_question_ids($pdo, $filters);

            if (empty($sourceQuestionIds)) {
                question_scope_bulk_json(true, 'Toplu kapsam kaldırma tamamlandı.', [
                    'matched_links' => 0,
                    'deleted_count' => 0,
                    'skipped_primary_count' => 0,
                ]);
            }

            $pdo->beginTransaction();
            try {
                $result = question_scope_bulk_remove_links($pdo, $sourceQuestionIds, $target);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            question_scope_bulk_json(true, 'Toplu kapsam kaldırma tamamlandı.', $result);
            break;

        default:
            question_scope_bulk_json(false, 'Geçersiz işlem!', [], 400);
            break;
    }
} catch (RuntimeException $e) {
    question_scope_bulk_json(false, $e->getMessage(), [], 422);
} catch (Throwable $e) {
    error_log('question-scope-bulk ajax error: ' . $e->getMessage());
    question_scope_bulk_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}
