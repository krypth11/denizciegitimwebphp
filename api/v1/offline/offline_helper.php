<?php

function offline_q(string $column): string
{
    return '`' . str_replace('`', '', $column) . '`';
}

function offline_pick_column(array $columns, array $candidates, bool $required = false): ?string
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

function offline_schema_qualifications(PDO $pdo): array
{
    $cols = get_table_columns($pdo, 'qualifications');
    if (!$cols) {
        throw new RuntimeException('qualifications tablosu okunamadı.');
    }

    return [
        'table' => 'qualifications',
        'id' => offline_pick_column($cols, ['id'], true),
        'name' => offline_pick_column($cols, ['name'], true),
        'description' => offline_pick_column($cols, ['description'], false),
        'updated_at' => offline_pick_column($cols, ['updated_at'], false),
    ];
}

function offline_schema_courses(PDO $pdo): array
{
    $cols = get_table_columns($pdo, 'courses');
    if (!$cols) {
        throw new RuntimeException('courses tablosu okunamadı.');
    }

    return [
        'table' => 'courses',
        'id' => offline_pick_column($cols, ['id'], true),
        'qualification_id' => offline_pick_column($cols, ['qualification_id'], true),
        'name' => offline_pick_column($cols, ['name'], true),
        'description' => offline_pick_column($cols, ['description'], false),
        'order_index' => offline_pick_column($cols, ['order_index'], false),
        'updated_at' => offline_pick_column($cols, ['updated_at'], false),
    ];
}

function offline_schema_topics(PDO $pdo): array
{
    $cols = get_table_columns($pdo, 'topics');
    if (!$cols) {
        throw new RuntimeException('topics tablosu okunamadı.');
    }

    return [
        'table' => 'topics',
        'id' => offline_pick_column($cols, ['id'], true),
        'course_id' => offline_pick_column($cols, ['course_id'], true),
        'name' => offline_pick_column($cols, ['name'], true),
        'content' => offline_pick_column($cols, ['content'], false),
        'order_index' => offline_pick_column($cols, ['order_index'], false),
        'updated_at' => offline_pick_column($cols, ['updated_at'], false),
    ];
}

function offline_schema_questions(PDO $pdo): array
{
    $cols = get_table_columns($pdo, 'questions');
    if (!$cols) {
        throw new RuntimeException('questions tablosu okunamadı.');
    }

    return [
        'table' => 'questions',
        'id' => offline_pick_column($cols, ['id'], true),
        'qualification_id' => offline_pick_column($cols, ['qualification_id'], false),
        'course_id' => offline_pick_column($cols, ['course_id'], false),
        'topic_id' => offline_pick_column($cols, ['topic_id'], false),
        'question_type' => offline_pick_column($cols, ['question_type'], false),
        'question_text' => offline_pick_column($cols, ['question_text'], true),
        'option_a' => offline_pick_column($cols, ['option_a'], false),
        'option_b' => offline_pick_column($cols, ['option_b'], false),
        'option_c' => offline_pick_column($cols, ['option_c'], false),
        'option_d' => offline_pick_column($cols, ['option_d'], false),
        'option_e' => offline_pick_column($cols, ['option_e'], false),
        'correct_answer' => offline_pick_column($cols, ['correct_answer'], false),
        'explanation' => offline_pick_column($cols, ['explanation'], false),
        'image_url' => offline_pick_column($cols, ['image_url'], false),
        'updated_at' => offline_pick_column($cols, ['updated_at'], false),
    ];
}

function offline_schema_sync_receipts(PDO $pdo): array
{
    $cols = get_table_columns($pdo, 'offline_sync_receipts');
    if (!$cols) {
        throw new RuntimeException('offline_sync_receipts tablosu okunamadı.');
    }

    return [
        'table' => 'offline_sync_receipts',
        'id' => offline_pick_column($cols, ['id'], false),
        'user_id' => offline_pick_column($cols, ['user_id'], true),
        'client_event_id' => offline_pick_column($cols, ['client_event_id'], true),
        'event_type' => offline_pick_column($cols, ['event_type', 'type'], false),
        'device_id' => offline_pick_column($cols, ['device_id'], false),
        'status' => offline_pick_column($cols, ['status'], false),
        'payload_json' => offline_pick_column($cols, ['payload_json', 'event_payload'], false),
        'response_json' => offline_pick_column($cols, ['response_json', 'result_payload'], false),
        'created_at' => offline_pick_column($cols, ['created_at'], false),
        'processed_at' => offline_pick_column($cols, ['processed_at'], false),
        'updated_at' => offline_pick_column($cols, ['updated_at'], false),
    ];
}

function offline_base_url(): string
{
    return 'https://admin.denizciegitim.com';
}

function offline_normalize_image_url(?string $rawUrl): ?string
{
    $url = trim((string)$rawUrl);
    if ($url === '') {
        return null;
    }

    if (preg_match('/^https?:\/\//i', $url)) {
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    if (str_starts_with($url, '//')) {
        $candidate = 'https:' . $url;
        return filter_var($candidate, FILTER_VALIDATE_URL) ? $candidate : null;
    }

    $relative = ltrim($url, '/');
    if ($relative === '') {
        return null;
    }

    return rtrim(offline_base_url(), '/') . '/' . $relative;
}

function offline_image_manifest_entry(string $questionId, string $remoteUrl): array
{
    $pathPart = parse_url($remoteUrl, PHP_URL_PATH) ?: '';
    $base = basename((string)$pathPart);
    if ($base === '' || $base === '/' || $base === '.') {
        $base = 'q_' . $questionId . '_' . substr(sha1($remoteUrl), 0, 10) . '.img';
    }

    $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $base) ?: ('img_' . substr(sha1($remoteUrl), 0, 12));

    return [
        'question_id' => $questionId,
        'remote_url' => $remoteUrl,
        'file_name' => $safeName,
        'local_relative_path' => 'offline_assets/questions/' . $safeName,
    ];
}

function offline_fetch_courses_by_qualification(PDO $pdo, string $qualificationId): array
{
    $c = offline_schema_courses($pdo);
    $sql = 'SELECT '
        . offline_q($c['id']) . ' AS id, '
        . offline_q($c['qualification_id']) . ' AS qualification_id, '
        . offline_q($c['name']) . ' AS name, '
        . ($c['description'] ? offline_q($c['description']) : "''") . ' AS description, '
        . ($c['order_index'] ? offline_q($c['order_index']) : '0') . ' AS order_index, '
        . ($c['updated_at'] ? offline_q($c['updated_at']) : 'NULL') . ' AS updated_at '
        . 'FROM ' . offline_q($c['table'])
        . ' WHERE ' . offline_q($c['qualification_id']) . ' = ?'
        . ' ORDER BY COALESCE(' . ($c['order_index'] ? offline_q($c['order_index']) : '0') . ', 0) ASC, ' . offline_q($c['name']) . ' ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$qualificationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function offline_fetch_topics_by_course_ids(PDO $pdo, array $courseIds): array
{
    if (!$courseIds) {
        return [];
    }

    $t = offline_schema_topics($pdo);
    $ph = implode(',', array_fill(0, count($courseIds), '?'));
    $sql = 'SELECT '
        . offline_q($t['id']) . ' AS id, '
        . offline_q($t['course_id']) . ' AS course_id, '
        . offline_q($t['name']) . ' AS name, '
        . ($t['content'] ? offline_q($t['content']) : "''") . ' AS content, '
        . ($t['order_index'] ? offline_q($t['order_index']) : '0') . ' AS order_index, '
        . ($t['updated_at'] ? offline_q($t['updated_at']) : 'NULL') . ' AS updated_at '
        . 'FROM ' . offline_q($t['table'])
        . ' WHERE ' . offline_q($t['course_id']) . ' IN (' . $ph . ')'
        . ' ORDER BY COALESCE(' . ($t['order_index'] ? offline_q($t['order_index']) : '0') . ', 0) ASC, ' . offline_q($t['name']) . ' ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($courseIds);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function offline_fetch_questions_for_qualification(PDO $pdo, string $qualificationId, array $courseIds, array $topicIds): array
{
    $q = offline_schema_questions($pdo);

    $select = [
        offline_q($q['id']) . ' AS id',
        ($q['qualification_id'] ? offline_q($q['qualification_id']) : 'NULL') . ' AS qualification_id',
        ($q['course_id'] ? offline_q($q['course_id']) : 'NULL') . ' AS course_id',
        ($q['topic_id'] ? offline_q($q['topic_id']) : 'NULL') . ' AS topic_id',
        ($q['question_type'] ? offline_q($q['question_type']) : "''") . ' AS question_type',
        offline_q($q['question_text']) . ' AS question_text',
        ($q['option_a'] ? offline_q($q['option_a']) : "''") . ' AS option_a',
        ($q['option_b'] ? offline_q($q['option_b']) : "''") . ' AS option_b',
        ($q['option_c'] ? offline_q($q['option_c']) : "''") . ' AS option_c',
        ($q['option_d'] ? offline_q($q['option_d']) : "''") . ' AS option_d',
        ($q['option_e'] ? offline_q($q['option_e']) : 'NULL') . ' AS option_e',
        ($q['correct_answer'] ? offline_q($q['correct_answer']) : "''") . ' AS correct_answer',
        ($q['explanation'] ? offline_q($q['explanation']) : "''") . ' AS explanation',
        ($q['image_url'] ? offline_q($q['image_url']) : 'NULL') . ' AS image_url',
        ($q['updated_at'] ? offline_q($q['updated_at']) : 'NULL') . ' AS updated_at',
    ];

    $where = [];
    $params = [];

    if ($q['qualification_id']) {
        $where[] = offline_q($q['qualification_id']) . ' = ?';
        $params[] = $qualificationId;
    }

    if ($courseIds && $q['course_id']) {
        $ph = implode(',', array_fill(0, count($courseIds), '?'));
        $where[] = offline_q($q['course_id']) . ' IN (' . $ph . ')';
        $params = array_merge($params, $courseIds);
    }

    if ($topicIds && $q['topic_id']) {
        $ph = implode(',', array_fill(0, count($topicIds), '?'));
        $where[] = offline_q($q['topic_id']) . ' IN (' . $ph . ')';
        $params = array_merge($params, $topicIds);
    }

    if (!$where) {
        return [];
    }

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM ' . offline_q($q['table'])
        . ' WHERE (' . implode(' OR ', $where) . ')'
        . ' ORDER BY ' . offline_q($q['id']) . ' ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function offline_latest_timestamp(array $timestamps): ?string
{
    $best = null;
    foreach ($timestamps as $ts) {
        $v = trim((string)$ts);
        if ($v === '') {
            continue;
        }
        if ($best === null || strtotime($v) > strtotime($best)) {
            $best = $v;
        }
    }
    return $best;
}

function offline_compute_package_version(string $qualificationId, array $meta): string
{
    $payload = [
        'qualification_id' => $qualificationId,
        'last_updated_at' => $meta['last_updated_at'] ?? null,
        'question_count' => (int)($meta['question_count'] ?? 0),
        'course_count' => (int)($meta['course_count'] ?? 0),
        'topic_count' => (int)($meta['topic_count'] ?? 0),
        'image_count' => (int)($meta['image_count'] ?? 0),
    ];

    return sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function offline_get_qualification_package_data(PDO $pdo, string $qualificationId): ?array
{
    $qual = offline_schema_qualifications($pdo);
    $stmtQ = $pdo->prepare('SELECT '
        . offline_q($qual['id']) . ' AS id, '
        . offline_q($qual['name']) . ' AS name, '
        . ($qual['description'] ? offline_q($qual['description']) : "''") . ' AS description, '
        . ($qual['updated_at'] ? offline_q($qual['updated_at']) : 'NULL') . ' AS updated_at '
        . 'FROM ' . offline_q($qual['table']) . ' WHERE ' . offline_q($qual['id']) . ' = ? LIMIT 1');
    $stmtQ->execute([$qualificationId]);
    $qualification = $stmtQ->fetch(PDO::FETCH_ASSOC);
    if (!$qualification) {
        return null;
    }

    $courses = offline_fetch_courses_by_qualification($pdo, $qualificationId);
    $courseIds = array_values(array_filter(array_map(static fn($r) => (string)($r['id'] ?? ''), $courses)));

    $topics = offline_fetch_topics_by_course_ids($pdo, $courseIds);
    $topicIds = array_values(array_filter(array_map(static fn($r) => (string)($r['id'] ?? ''), $topics)));

    $questions = offline_fetch_questions_for_qualification($pdo, $qualificationId, $courseIds, $topicIds);

    $assetsByUrl = [];
    foreach ($questions as &$question) {
        $questionId = (string)($question['id'] ?? '');
        $normalized = offline_normalize_image_url($question['image_url'] ?? null);
        $question['image_url'] = $normalized;
        if ($normalized && !isset($assetsByUrl[$normalized])) {
            $assetsByUrl[$normalized] = offline_image_manifest_entry($questionId, $normalized);
        }
    }
    unset($question);

    $assets = array_values($assetsByUrl);

    $lastUpdatedAt = offline_latest_timestamp(array_merge(
        [$qualification['updated_at'] ?? null],
        array_map(static fn($r) => $r['updated_at'] ?? null, $courses),
        array_map(static fn($r) => $r['updated_at'] ?? null, $topics),
        array_map(static fn($r) => $r['updated_at'] ?? null, $questions)
    ));

    $meta = [
        'question_count' => count($questions),
        'course_count' => count($courses),
        'topic_count' => count($topics),
        'image_count' => count($assets),
        'last_updated_at' => $lastUpdatedAt,
    ];

    $version = offline_compute_package_version($qualificationId, $meta);

    return [
        'qualification' => [
            'id' => (string)$qualification['id'],
            'name' => (string)($qualification['name'] ?? ''),
            'description' => (string)($qualification['description'] ?? ''),
            'updated_at' => $qualification['updated_at'] ?? null,
        ],
        'courses' => array_map(static fn($r) => [
            'id' => (string)($r['id'] ?? ''),
            'qualification_id' => (string)($r['qualification_id'] ?? ''),
            'name' => (string)($r['name'] ?? ''),
            'description' => (string)($r['description'] ?? ''),
            'order_index' => (int)($r['order_index'] ?? 0),
            'updated_at' => $r['updated_at'] ?? null,
        ], $courses),
        'topics' => array_map(static fn($r) => [
            'id' => (string)($r['id'] ?? ''),
            'course_id' => (string)($r['course_id'] ?? ''),
            'name' => (string)($r['name'] ?? ''),
            'content' => (string)($r['content'] ?? ''),
            'order_index' => (int)($r['order_index'] ?? 0),
            'updated_at' => $r['updated_at'] ?? null,
        ], $topics),
        'questions' => array_map(static fn($r) => [
            'id' => (string)($r['id'] ?? ''),
            'qualification_id' => ($r['qualification_id'] ?? null) ?: $qualificationId,
            'course_id' => $r['course_id'] ?? null,
            'topic_id' => $r['topic_id'] ?? null,
            'question_type' => (string)($r['question_type'] ?? ''),
            'question_text' => (string)($r['question_text'] ?? ''),
            'option_a' => (string)($r['option_a'] ?? ''),
            'option_b' => (string)($r['option_b'] ?? ''),
            'option_c' => (string)($r['option_c'] ?? ''),
            'option_d' => (string)($r['option_d'] ?? ''),
            'option_e' => $r['option_e'] ?? null,
            'correct_answer' => (string)($r['correct_answer'] ?? ''),
            'explanation' => (string)($r['explanation'] ?? ''),
            'image_url' => $r['image_url'] ?? null,
            'updated_at' => $r['updated_at'] ?? null,
        ], $questions),
        'assets' => $assets,
        'metadata' => $meta,
        'package_version' => $version,
        'package_generated_at' => date('Y-m-d H:i:s'),
    ];
}

function offline_get_downloadable_qualifications(PDO $pdo, ?string $qualificationIdFilter = null): array
{
    $qual = offline_schema_qualifications($pdo);
    $sql = 'SELECT '
        . offline_q($qual['id']) . ' AS id, '
        . offline_q($qual['name']) . ' AS name, '
        . ($qual['description'] ? offline_q($qual['description']) : "''") . ' AS description '
        . 'FROM ' . offline_q($qual['table']);

    $params = [];
    $qualificationIdFilter = trim((string)$qualificationIdFilter);
    if ($qualificationIdFilter !== '') {
        $sql .= ' WHERE ' . offline_q($qual['id']) . ' = ?';
        $params[] = $qualificationIdFilter;
    }

    $sql .= ' ORDER BY ' . offline_q($qual['name']) . ' ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = [];

    foreach ($rows as $row) {
        $qid = (string)($row['id'] ?? '');
        if ($qid === '') {
            continue;
        }

        $pkg = offline_get_qualification_package_data($pdo, $qid);
        if (!$pkg) {
            continue;
        }

        $meta = $pkg['metadata'];
        $items[] = [
            'id' => $qid,
            'name' => (string)($row['name'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'question_count' => (int)$meta['question_count'],
            'course_count' => (int)$meta['course_count'],
            'topic_count' => (int)$meta['topic_count'],
            'image_count' => (int)$meta['image_count'],
            'last_updated_at' => $meta['last_updated_at'] ?? null,
            'package_version' => (string)$pkg['package_version'],
        ];
    }

    return $items;
}

function offline_sync_receipt_exists(PDO $pdo, string $userId, string $clientEventId): bool
{
    $s = offline_schema_sync_receipts($pdo);
    $sql = 'SELECT COUNT(*) FROM ' . offline_q($s['table'])
        . ' WHERE ' . offline_q($s['user_id']) . ' = ? AND ' . offline_q($s['client_event_id']) . ' = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $clientEventId]);
    return (int)$stmt->fetchColumn() > 0;
}

function offline_sync_get_receipt(PDO $pdo, string $userId, string $clientEventId): ?array
{
    $s = offline_schema_sync_receipts($pdo);
    $select = [
        offline_q($s['user_id']) . ' AS user_id',
        offline_q($s['client_event_id']) . ' AS client_event_id',
    ];

    if ($s['event_type']) {
        $select[] = offline_q($s['event_type']) . ' AS event_type';
    } else {
        $select[] = "'' AS event_type";
    }

    if ($s['status']) {
        $select[] = offline_q($s['status']) . ' AS status';
    } else {
        $select[] = "'' AS status";
    }

    if ($s['payload_json']) {
        $select[] = offline_q($s['payload_json']) . ' AS payload_json';
    } else {
        $select[] = 'NULL AS payload_json';
    }

    if ($s['response_json']) {
        $select[] = offline_q($s['response_json']) . ' AS response_json';
    } else {
        $select[] = 'NULL AS response_json';
    }

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM ' . offline_q($s['table'])
        . ' WHERE ' . offline_q($s['user_id']) . ' = ? AND ' . offline_q($s['client_event_id']) . ' = ?'
        . ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $clientEventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function offline_sync_write_receipt(
    PDO $pdo,
    string $userId,
    string $clientEventId,
    string $eventType,
    ?string $deviceId,
    array $eventPayload,
    array $resultPayload,
    string $status = 'processed'
): void
{
    $s = offline_schema_sync_receipts($pdo);
    $cols = [];
    $holders = [];
    $vals = [];

    $add = static function (array &$cols, array &$holders, array &$vals, string $col, $val): void {
        $cols[] = offline_q($col);
        $holders[] = '?';
        $vals[] = $val;
    };

    if ($s['id']) {
        $add($cols, $holders, $vals, $s['id'], generate_uuid());
    }

    $add($cols, $holders, $vals, $s['user_id'], $userId);
    $add($cols, $holders, $vals, $s['client_event_id'], $clientEventId);

    if ($s['event_type']) {
        $add($cols, $holders, $vals, $s['event_type'], $eventType);
    }
    if ($s['device_id']) {
        $add($cols, $holders, $vals, $s['device_id'], $deviceId);
    }
    if ($s['status']) {
        $add($cols, $holders, $vals, $s['status'], $status);
    }
    if ($s['payload_json']) {
        $add($cols, $holders, $vals, $s['payload_json'], json_encode($eventPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    if ($s['response_json']) {
        $add($cols, $holders, $vals, $s['response_json'], json_encode($resultPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    if ($s['created_at']) {
        $cols[] = offline_q($s['created_at']);
        $holders[] = 'NOW()';
    }
    if ($s['processed_at']) {
        $cols[] = offline_q($s['processed_at']);
        $holders[] = 'NOW()';
    }
    if ($s['updated_at']) {
        $cols[] = offline_q($s['updated_at']);
        $holders[] = 'NOW()';
    }

    $sql = 'INSERT INTO ' . offline_q($s['table'])
        . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $holders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($vals);
}
