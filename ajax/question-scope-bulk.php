<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/question_scope_helper.php';

$user = require_admin();
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

function question_scope_bulk_has_mappings_table(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cols = get_table_columns($pdo, 'question_scope_bulk_mappings');
    if (!$cols) {
        $cache = false;
        return false;
    }

    $required = [
        'id',
        'source_qualification_id',
        'source_course_id',
        'source_topic_id',
        'question_type',
        'search_text',
        'target_qualification_id',
        'target_course_id',
        'target_topic_id',
        'last_source_count',
        'last_target_linked_count',
        'last_missing_count',
        'last_inserted_count',
        'last_removed_count',
        'last_synced_at',
    ];

    foreach ($required as $col) {
        if (!in_array($col, $cols, true)) {
            $cache = false;
            return false;
        }
    }

    $cache = true;
    return true;
}

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

function question_scope_bulk_parse_ids($value): array
{
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }

        if ($trimmed[0] === '[') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        } else {
            $value = explode(',', $trimmed);
        }
    }

    if (!is_array($value)) {
        return [];
    }

    $ids = [];
    foreach ($value as $id) {
        $id = trim((string)$id);
        if ($id !== '') {
            $ids[$id] = true;
        }
    }

    return array_keys($ids);
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

function question_scope_bulk_mapping_normalize_filters(array $filters, array $target): array
{
    $searchText = trim((string)($filters['search_text'] ?? ($filters['search'] ?? '')));

    return [
        'source_qualification_id' => question_scope_bulk_normalize_id($filters['source_qualification_id'] ?? ''),
        'source_course_id' => question_scope_bulk_normalize_id($filters['source_course_id'] ?? ''),
        'source_topic_id' => question_scope_normalize_topic_id($filters['source_topic_id'] ?? ''),
        'question_type' => trim((string)($filters['question_type'] ?? '')),
        'search_text' => $searchText,
        'target_qualification_id' => question_scope_bulk_normalize_id($target['target_qualification_id'] ?? ''),
        'target_course_id' => question_scope_bulk_normalize_id($target['target_course_id'] ?? ''),
        'target_topic_id' => question_scope_normalize_topic_id($target['target_topic_id'] ?? ''),
    ];
}

function question_scope_bulk_mapping_unique_params(array $normalized): array
{
    return [
        (string)$normalized['source_qualification_id'],
        (string)$normalized['source_course_id'],
        (string)$normalized['source_topic_id'],
        (string)$normalized['question_type'],
        (string)$normalized['search_text'],
        (string)$normalized['target_qualification_id'],
        (string)$normalized['target_course_id'],
        (string)$normalized['target_topic_id'],
    ];
}

function question_scope_bulk_mapping_hash_key(array $normalized): string
{
    return sha1(json_encode(question_scope_bulk_mapping_unique_params($normalized), JSON_UNESCAPED_UNICODE));
}

function question_scope_bulk_update_mapping_stats(PDO $pdo, string $mappingId, array $summary, bool $touchSyncedAt): void
{
    $cols = get_table_columns($pdo, 'question_scope_bulk_mappings');
    $sets = [];
    $params = [];

    $pairs = [
        'last_source_count' => (int)($summary['source_count'] ?? 0),
        'last_target_linked_count' => (int)($summary['target_linked_count'] ?? 0),
        'last_missing_count' => (int)($summary['missing_count'] ?? 0),
        'last_inserted_count' => (int)($summary['inserted_count'] ?? 0),
        'last_removed_count' => (int)($summary['removed_count'] ?? 0),
    ];

    foreach ($pairs as $col => $value) {
        if (in_array($col, $cols, true)) {
            $sets[] = $col . ' = ?';
            $params[] = $value;
        }
    }

    if ($touchSyncedAt && in_array('last_synced_at', $cols, true)) {
        $sets[] = 'last_synced_at = NOW()';
    }
    if (in_array('updated_at', $cols, true)) {
        $sets[] = 'updated_at = NOW()';
    }

    if (empty($sets)) {
        return;
    }

    $params[] = $mappingId;
    $sql = 'UPDATE question_scope_bulk_mappings SET ' . implode(', ', $sets) . ' WHERE id = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function question_scope_bulk_upsert_mapping(PDO $pdo, array $filters, array $target, array $summary): string
{
    if (!question_scope_bulk_has_mappings_table($pdo)) {
        throw new RuntimeException('question_scope_bulk_mappings tablosu bulunamadı.');
    }

    $normalized = question_scope_bulk_mapping_normalize_filters($filters, $target);
    question_scope_bulk_validate_source_filters($normalized);
    question_scope_bulk_validate_target_scope($pdo, $normalized);

    $findSql = 'SELECT id FROM question_scope_bulk_mappings
                WHERE source_qualification_id = ?
                  AND source_course_id = ?
                  AND source_topic_id = ?
                  AND question_type = ?
                  AND search_text = ?
                  AND target_qualification_id = ?
                  AND target_course_id = ?
                  AND target_topic_id = ?
                LIMIT 1';
    $findStmt = $pdo->prepare($findSql);
    $findStmt->execute(question_scope_bulk_mapping_unique_params($normalized));
    $existingId = trim((string)$findStmt->fetchColumn());

    if ($existingId !== '') {
        question_scope_bulk_update_mapping_stats($pdo, $existingId, $summary, true);
        return $existingId;
    }

    $cols = get_table_columns($pdo, 'question_scope_bulk_mappings');
    $mappingId = generate_uuid();
    $insertCols = ['id'];
    $placeholders = ['?'];
    $params = [$mappingId];

    $pairs = [
        'source_qualification_id' => $normalized['source_qualification_id'],
        'source_course_id' => $normalized['source_course_id'],
        'source_topic_id' => $normalized['source_topic_id'],
        'question_type' => $normalized['question_type'],
        'search_text' => $normalized['search_text'],
        'target_qualification_id' => $normalized['target_qualification_id'],
        'target_course_id' => $normalized['target_course_id'],
        'target_topic_id' => $normalized['target_topic_id'],
        'last_source_count' => (int)($summary['source_count'] ?? 0),
        'last_target_linked_count' => (int)($summary['target_linked_count'] ?? 0),
        'last_missing_count' => (int)($summary['missing_count'] ?? 0),
        'last_inserted_count' => (int)($summary['inserted_count'] ?? 0),
        'last_removed_count' => (int)($summary['removed_count'] ?? 0),
    ];

    foreach ($pairs as $col => $value) {
        if (in_array($col, $cols, true)) {
            $insertCols[] = $col;
            $placeholders[] = '?';
            $params[] = $value;
        }
    }

    if (in_array('last_synced_at', $cols, true)) {
        $insertCols[] = 'last_synced_at';
        $placeholders[] = 'NOW()';
    }
    if (in_array('created_at', $cols, true)) {
        $insertCols[] = 'created_at';
        $placeholders[] = 'NOW()';
    }
    if (in_array('updated_at', $cols, true)) {
        $insertCols[] = 'updated_at';
        $placeholders[] = 'NOW()';
    }

    $sql = 'INSERT INTO question_scope_bulk_mappings (' . implode(', ', $insertCols) . ')
            VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $mappingId;
}

function question_scope_bulk_count_removable_target_links(PDO $pdo, array $questionIds, array $target): int
{
    if (empty($questionIds)) {
        return 0;
    }

    $targetQualificationId = (string)$target['target_qualification_id'];
    $targetCourseId = (string)$target['target_course_id'];
    $targetTopicId = question_scope_normalize_topic_id($target['target_topic_id'] ?? '');

    $count = 0;
    foreach (array_chunk($questionIds, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $sql = 'SELECT COUNT(*)
                FROM question_scope_links
                WHERE qualification_id = ? AND course_id = ? AND topic_id = ?
                  AND is_primary = 0
                  AND question_id IN (' . $placeholders . ')';
        $params = array_merge([$targetQualificationId, $targetCourseId, $targetTopicId], $chunk);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $count += (int)$stmt->fetchColumn();
    }

    return $count;
}

function question_scope_bulk_get_existing_target_question_id_map(PDO $pdo, array $questionIds, array $target): array
{
    if (empty($questionIds)) {
        return [];
    }

    $targetQualificationId = (string)$target['target_qualification_id'];
    $targetCourseId = (string)$target['target_course_id'];
    $targetTopicId = question_scope_normalize_topic_id($target['target_topic_id'] ?? '');

    $exists = [];
    foreach (array_chunk($questionIds, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $sql = 'SELECT DISTINCT question_id
                FROM question_scope_links
                WHERE qualification_id = ? AND course_id = ? AND topic_id = ?
                  AND question_id IN (' . $placeholders . ')';
        $params = array_merge([$targetQualificationId, $targetCourseId, $targetTopicId], $chunk);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $qid = trim((string)($row['question_id'] ?? ''));
            if ($qid !== '') {
                $exists[$qid] = true;
            }
        }
    }

    return $exists;
}

function question_scope_bulk_compute_mapping_summary(PDO $pdo, array $mapping): array
{
    $filters = [
        'source_qualification_id' => (string)($mapping['source_qualification_id'] ?? ''),
        'source_course_id' => (string)($mapping['source_course_id'] ?? ''),
        'source_topic_id' => (string)($mapping['source_topic_id'] ?? ''),
        'question_type' => (string)($mapping['question_type'] ?? ''),
        'search' => (string)($mapping['search_text'] ?? ''),
    ];

    $target = [
        'target_qualification_id' => (string)($mapping['target_qualification_id'] ?? ''),
        'target_course_id' => (string)($mapping['target_course_id'] ?? ''),
        'target_topic_id' => (string)($mapping['target_topic_id'] ?? ''),
    ];

    $sourceQuestionIds = bulk_scope_find_source_question_ids($pdo, $filters);
    $sourceCount = count($sourceQuestionIds);
    $targetLinkedCount = question_scope_bulk_count_existing_target_links($pdo, $sourceQuestionIds, $target);
    $missingCount = max(0, $sourceCount - $targetLinkedCount);
    $removableCount = question_scope_bulk_count_removable_target_links($pdo, $sourceQuestionIds, $target);

    $statusLabel = 'Güncel';
    if ($sourceCount === 0) {
        $statusLabel = 'Kaynak boş';
    } elseif ($missingCount > 0) {
        $statusLabel = 'Güncelleme var';
    }

    return [
        'source_count' => $sourceCount,
        'target_linked_count' => $targetLinkedCount,
        'missing_count' => $missingCount,
        'removable_count' => $removableCount,
        'status_label' => $statusLabel,
        'source_question_ids' => $sourceQuestionIds,
    ];
}

function question_scope_bulk_get_mapping(PDO $pdo, string $id): ?array
{
    if (!question_scope_bulk_has_mappings_table($pdo)) {
        throw new RuntimeException('question_scope_bulk_mappings tablosu bulunamadı.');
    }

    $id = trim($id);
    if ($id === '') {
        return null;
    }

    $sql = 'SELECT m.*,
                   sq.name AS source_qualification_name,
                   sc.name AS source_course_name,
                   st.name AS source_topic_name,
                   tq.name AS target_qualification_name,
                   tc.name AS target_course_name,
                   tt.name AS target_topic_name
            FROM question_scope_bulk_mappings m
            LEFT JOIN qualifications sq ON sq.id = m.source_qualification_id
            LEFT JOIN courses sc ON sc.id = m.source_course_id
            LEFT JOIN topics st ON st.id = NULLIF(m.source_topic_id, \'\')
            LEFT JOIN qualifications tq ON tq.id = m.target_qualification_id
            LEFT JOIN courses tc ON tc.id = m.target_course_id
            LEFT JOIN topics tt ON tt.id = NULLIF(m.target_topic_id, \'\')
            WHERE m.id = ?
            LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function question_scope_bulk_list_mappings(PDO $pdo): array
{
    if (!question_scope_bulk_has_mappings_table($pdo)) {
        throw new RuntimeException('question_scope_bulk_mappings tablosu bulunamadı.');
    }

    $sql = 'SELECT m.*,
                   sq.name AS source_qualification_name,
                   sc.name AS source_course_name,
                   st.name AS source_topic_name,
                   tq.name AS target_qualification_name,
                   tc.name AS target_course_name,
                   tt.name AS target_topic_name
            FROM question_scope_bulk_mappings m
            LEFT JOIN qualifications sq ON sq.id = m.source_qualification_id
            LEFT JOIN courses sc ON sc.id = m.source_course_id
            LEFT JOIN topics st ON st.id = NULLIF(m.source_topic_id, \'\')
            LEFT JOIN qualifications tq ON tq.id = m.target_qualification_id
            LEFT JOIN courses tc ON tc.id = m.target_course_id
            LEFT JOIN topics tt ON tt.id = NULLIF(m.target_topic_id, \'\')
            ORDER BY COALESCE(m.updated_at, m.last_synced_at) DESC, m.id DESC';
    $stmt = $pdo->query($sql);
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $out = [];
    foreach ($rows as $row) {
        $summary = question_scope_bulk_compute_mapping_summary($pdo, $row);
        $out[] = [
            'id' => (string)$row['id'],
            'source_qualification_name' => (string)($row['source_qualification_name'] ?? ''),
            'source_course_name' => (string)($row['source_course_name'] ?? ''),
            'source_topic_name' => (string)($row['source_topic_name'] ?? ''),
            'target_qualification_name' => (string)($row['target_qualification_name'] ?? ''),
            'target_course_name' => (string)($row['target_course_name'] ?? ''),
            'target_topic_name' => (string)($row['target_topic_name'] ?? ''),
            'question_type' => (string)($row['question_type'] ?? ''),
            'search_text' => (string)($row['search_text'] ?? ''),
            'source_count' => (int)$summary['source_count'],
            'target_linked_count' => (int)$summary['target_linked_count'],
            'missing_count' => (int)$summary['missing_count'],
            'removable_count' => (int)$summary['removable_count'],
            'status_label' => (string)$summary['status_label'],
            'last_synced_at' => $row['last_synced_at'] ?? null,
        ];
    }

    return $out;
}

function question_scope_bulk_sync_mapping(PDO $pdo, string $mappingId): array
{
    $mapping = question_scope_bulk_get_mapping($pdo, $mappingId);
    if (!$mapping) {
        throw new OutOfBoundsException('Mapping bulunamadı.');
    }

    $filters = [
        'source_qualification_id' => (string)$mapping['source_qualification_id'],
        'source_course_id' => (string)$mapping['source_course_id'],
        'source_topic_id' => (string)$mapping['source_topic_id'],
        'question_type' => (string)$mapping['question_type'],
        'search' => (string)$mapping['search_text'],
    ];
    $target = [
        'target_qualification_id' => (string)$mapping['target_qualification_id'],
        'target_course_id' => (string)$mapping['target_course_id'],
        'target_topic_id' => (string)$mapping['target_topic_id'],
    ];

    question_scope_bulk_validate_source_filters($filters);
    question_scope_bulk_validate_target_scope($pdo, $target);
    question_scope_bulk_validate_source_target_not_same($filters, $target);

    $sourceQuestionIds = bulk_scope_find_source_question_ids($pdo, $filters);
    $sourceCount = count($sourceQuestionIds);
    $existingMap = question_scope_bulk_get_existing_target_question_id_map($pdo, $sourceQuestionIds, $target);
    $alreadyLinkedCount = count($existingMap);

    $missingIds = [];
    foreach ($sourceQuestionIds as $questionId) {
        if (!isset($existingMap[$questionId])) {
            $missingIds[] = $questionId;
        }
    }

    $pdo->beginTransaction();
    try {
        $insertedCount = question_scope_bulk_insert_links($pdo, $missingIds, $target);
        $targetLinkedAfter = question_scope_bulk_count_existing_target_links($pdo, $sourceQuestionIds, $target);
        $missingAfter = max(0, $sourceCount - $targetLinkedAfter);
        question_scope_bulk_update_mapping_stats($pdo, (string)$mapping['id'], [
            'source_count' => $sourceCount,
            'target_linked_count' => $targetLinkedAfter,
            'missing_count' => $missingAfter,
            'inserted_count' => $insertedCount,
            'removed_count' => 0,
        ], true);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'source_count' => $sourceCount,
        'already_linked_count' => $alreadyLinkedCount,
        'inserted_count' => $insertedCount,
        'missing_count_after' => max(0, $sourceCount - ($alreadyLinkedCount + $insertedCount)),
    ];
}

function question_scope_bulk_cancel_mapping(PDO $pdo, string $mappingId): array
{
    $mapping = question_scope_bulk_get_mapping($pdo, $mappingId);
    if (!$mapping) {
        throw new OutOfBoundsException('Mapping bulunamadı.');
    }

    $filters = [
        'source_qualification_id' => (string)$mapping['source_qualification_id'],
        'source_course_id' => (string)$mapping['source_course_id'],
        'source_topic_id' => (string)$mapping['source_topic_id'],
        'question_type' => (string)$mapping['question_type'],
        'search' => (string)$mapping['search_text'],
    ];
    $target = [
        'target_qualification_id' => (string)$mapping['target_qualification_id'],
        'target_course_id' => (string)$mapping['target_course_id'],
        'target_topic_id' => (string)$mapping['target_topic_id'],
    ];

    question_scope_bulk_validate_source_filters($filters);
    question_scope_bulk_validate_target_scope($pdo, $target);

    $sourceQuestionIds = bulk_scope_find_source_question_ids($pdo, $filters);
    $sourceCount = count($sourceQuestionIds);

    $pdo->beginTransaction();
    try {
        $result = question_scope_bulk_remove_links($pdo, $sourceQuestionIds, $target);
        $targetLinkedAfter = question_scope_bulk_count_existing_target_links($pdo, $sourceQuestionIds, $target);
        $missingAfter = max(0, $sourceCount - $targetLinkedAfter);
        question_scope_bulk_update_mapping_stats($pdo, (string)$mapping['id'], [
            'source_count' => $sourceCount,
            'target_linked_count' => $targetLinkedAfter,
            'missing_count' => $missingAfter,
            'inserted_count' => 0,
            'removed_count' => (int)$result['deleted_count'],
        ], false);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $result;
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

    if (!question_scope_bulk_has_mappings_table($pdo)) {
        question_scope_bulk_json(false, 'question_scope_bulk_mappings tablosu bulunamadı.', [], 422);
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

            $pdo->beginTransaction();
            try {
                $insertedCount = question_scope_bulk_insert_links($pdo, $sourceQuestionIds, $target);
                $targetLinkedCount = question_scope_bulk_count_existing_target_links($pdo, $sourceQuestionIds, $target);
                $missingCount = max(0, $totalSourceQuestions - $targetLinkedCount);
                question_scope_bulk_upsert_mapping($pdo, $filters, $target, [
                    'source_count' => $totalSourceQuestions,
                    'target_linked_count' => $targetLinkedCount,
                    'missing_count' => $missingCount,
                    'inserted_count' => $insertedCount,
                    'removed_count' => 0,
                ]);
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

        case 'mappings':
            $mappings = question_scope_bulk_list_mappings($pdo);
            question_scope_bulk_json(true, '', [
                'mappings' => $mappings,
            ]);
            break;

        case 'sync_mapping':
            @set_time_limit(180);
            $mappingId = trim((string)($_REQUEST['mapping_id'] ?? ''));
            if ($mappingId === '') {
                question_scope_bulk_json(false, 'mapping_id zorunludur.', [], 422);
            }
            try {
                $result = question_scope_bulk_sync_mapping($pdo, $mappingId);
            } catch (OutOfBoundsException $e) {
                question_scope_bulk_json(false, $e->getMessage(), [], 404);
            }
            question_scope_bulk_json(true, 'Eşzamanlama tamamlandı.', $result);
            break;

        case 'bulk_sync_mappings':
            @set_time_limit(300);
            $ids = question_scope_bulk_parse_ids($_REQUEST['ids'] ?? []);
            if (empty($ids)) {
                question_scope_bulk_json(false, 'ids alanı zorunludur.', [], 422);
            }

            $processed = 0;
            $failed = 0;
            $totalInserted = 0;
            $errors = [];

            foreach ($ids as $id) {
                try {
                    $res = question_scope_bulk_sync_mapping($pdo, $id);
                    $processed++;
                    $totalInserted += (int)($res['inserted_count'] ?? 0);
                } catch (Throwable $e) {
                    $failed++;
                    $errors[] = [
                        'id' => $id,
                        'message' => $e->getMessage(),
                    ];
                }
            }

            question_scope_bulk_json(true, 'Toplu eşzamanlama tamamlandı.', [
                'processed' => $processed,
                'total_inserted' => $totalInserted,
                'failed' => $failed,
                'errors' => $errors,
            ]);
            break;

        case 'cancel_mapping':
            @set_time_limit(180);
            $mappingId = trim((string)($_REQUEST['mapping_id'] ?? ''));
            if ($mappingId === '') {
                question_scope_bulk_json(false, 'mapping_id zorunludur.', [], 422);
            }
            try {
                $result = question_scope_bulk_cancel_mapping($pdo, $mappingId);
            } catch (OutOfBoundsException $e) {
                question_scope_bulk_json(false, $e->getMessage(), [], 404);
            }
            question_scope_bulk_json(true, 'Kaynak-hedef bağlantısı iptal edildi.', $result);
            break;

        case 'bulk_cancel_mappings':
            @set_time_limit(300);
            $ids = question_scope_bulk_parse_ids($_REQUEST['ids'] ?? []);
            if (empty($ids)) {
                question_scope_bulk_json(false, 'ids alanı zorunludur.', [], 422);
            }

            $processed = 0;
            $failed = 0;
            $totalRemoved = 0;
            $errors = [];

            foreach ($ids as $id) {
                try {
                    $res = question_scope_bulk_cancel_mapping($pdo, $id);
                    $processed++;
                    $totalRemoved += (int)($res['deleted_count'] ?? 0);
                } catch (Throwable $e) {
                    $failed++;
                    $errors[] = [
                        'id' => $id,
                        'message' => $e->getMessage(),
                    ];
                }
            }

            question_scope_bulk_json(true, 'Toplu iptal işlemi tamamlandı.', [
                'processed' => $processed,
                'total_removed' => $totalRemoved,
                'failed' => $failed,
                'errors' => $errors,
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
