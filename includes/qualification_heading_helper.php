<?php

require_once __DIR__ . '/functions.php';

function qualification_heading_list(PDO $pdo): array
{
    $sql = 'SELECT h.id, h.name, h.order_index, h.is_active, h.created_at, h.updated_at,
                   COUNT(i.id) AS item_count
            FROM qualification_headings h
            LEFT JOIN qualification_heading_items i ON i.heading_id = h.id
            GROUP BY h.id, h.name, h.order_index, h.is_active, h.created_at, h.updated_at
            ORDER BY COALESCE(h.order_index, 0) ASC, h.name ASC';

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        return [
            'id' => (string)($row['id'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'order_index' => (int)($row['order_index'] ?? 0),
            'is_active' => ((int)($row['is_active'] ?? 0) === 1),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'item_count' => (int)($row['item_count'] ?? 0),
        ];
    }, $rows);
}

function qualification_heading_create(PDO $pdo, array $data): array
{
    $name = trim((string)($data['name'] ?? ''));
    $orderIndex = (int)($data['order_index'] ?? 0);
    $isActive = isset($data['is_active']) ? ((int)$data['is_active'] === 1) : true;

    if ($name === '') {
        throw new InvalidArgumentException('Başlık adı boş olamaz.');
    }

    $id = generate_uuid();
    $stmt = $pdo->prepare('INSERT INTO qualification_headings (id, name, order_index, is_active, created_at, updated_at)
                           VALUES (?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$id, $name, $orderIndex, $isActive ? 1 : 0]);

    error_log('[QUALIFICATION_HEADING] heading created | id=' . $id . ' | name=' . $name);

    return qualification_heading_detail($pdo, $id);
}

function qualification_heading_update(PDO $pdo, string $id, array $data): array
{
    $id = trim($id);
    $name = trim((string)($data['name'] ?? ''));
    $orderIndex = (int)($data['order_index'] ?? 0);

    if ($id === '') {
        throw new InvalidArgumentException('Başlık ID alanı zorunludur.');
    }
    if ($name === '') {
        throw new InvalidArgumentException('Başlık adı boş olamaz.');
    }

    $hasStmt = $pdo->prepare('SELECT COUNT(*) FROM qualification_headings WHERE id = ?');
    $hasStmt->execute([$id]);
    if ((int)$hasStmt->fetchColumn() < 1) {
        throw new RuntimeException('Başlık bulunamadı.');
    }

    $stmt = $pdo->prepare('UPDATE qualification_headings
                           SET name = ?, order_index = ?, updated_at = NOW()
                           WHERE id = ?');
    $stmt->execute([$name, $orderIndex, $id]);

    error_log('[QUALIFICATION_HEADING] heading updated | id=' . $id . ' | name=' . $name);

    return qualification_heading_detail($pdo, $id);
}

function qualification_heading_delete(PDO $pdo, string $id): void
{
    $id = trim($id);
    if ($id === '') {
        throw new InvalidArgumentException('Başlık ID alanı zorunludur.');
    }

    $stmt = $pdo->prepare('DELETE FROM qualification_headings WHERE id = ?');
    $stmt->execute([$id]);
}

function qualification_heading_toggle_active(PDO $pdo, string $id, bool $isActive): void
{
    $id = trim($id);
    if ($id === '') {
        throw new InvalidArgumentException('Başlık ID alanı zorunludur.');
    }

    $stmt = $pdo->prepare('UPDATE qualification_headings SET is_active = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$isActive ? 1 : 0, $id]);
}

function qualification_heading_attach_item(PDO $pdo, string $headingId, string $qualificationId, int $orderIndex = 0): array
{
    $headingId = trim($headingId);
    $qualificationId = trim($qualificationId);

    if ($headingId === '' || $qualificationId === '') {
        throw new InvalidArgumentException('Başlık ve yeterlilik alanları zorunludur.');
    }

    $headingStmt = $pdo->prepare('SELECT COUNT(*) FROM qualification_headings WHERE id = ?');
    $headingStmt->execute([$headingId]);
    if ((int)$headingStmt->fetchColumn() < 1) {
        throw new RuntimeException('Başlık bulunamadı.');
    }

    $qualSql = qualification_heading_qualification_base_sql($pdo, true);
    $qualStmt = $pdo->prepare('SELECT q.id, q.name, q.description FROM qualifications q WHERE q.id = ? AND ' . $qualSql['where']);
    $qualStmt->execute([$qualificationId]);
    $qualification = $qualStmt->fetch(PDO::FETCH_ASSOC);
    if (!$qualification) {
        throw new RuntimeException('Seçilen yeterlilik aktif/geçerli değil veya bulunamadı.');
    }

    $dupStmt = $pdo->prepare('SELECT COUNT(*) FROM qualification_heading_items WHERE heading_id = ? AND qualification_id = ?');
    $dupStmt->execute([$headingId, $qualificationId]);
    if ((int)$dupStmt->fetchColumn() > 0) {
        throw new RuntimeException('Bu yeterlilik bu başlık altında zaten mevcut.');
    }

    if ($orderIndex <= 0) {
        $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(order_index), 0) FROM qualification_heading_items WHERE heading_id = ?');
        $maxStmt->execute([$headingId]);
        $orderIndex = ((int)$maxStmt->fetchColumn()) + 1;
    }

    $itemId = generate_uuid();
    $stmt = $pdo->prepare('INSERT INTO qualification_heading_items (id, heading_id, qualification_id, order_index, created_at)
                           VALUES (?, ?, ?, ?, NOW())');
    $stmt->execute([$itemId, $headingId, $qualificationId, $orderIndex]);

    error_log('[QUALIFICATION_HEADING] qualification attached | heading_id=' . $headingId . ' | qualification_id=' . $qualificationId);

    return [
        'id' => $itemId,
        'heading_id' => $headingId,
        'qualification_id' => $qualificationId,
        'order_index' => $orderIndex,
    ];
}

function qualification_heading_detach_item(PDO $pdo, string $headingId, string $qualificationId): void
{
    $headingId = trim($headingId);
    $qualificationId = trim($qualificationId);

    if ($headingId === '' || $qualificationId === '') {
        throw new InvalidArgumentException('Başlık ve yeterlilik alanları zorunludur.');
    }

    $stmt = $pdo->prepare('DELETE FROM qualification_heading_items WHERE heading_id = ? AND qualification_id = ?');
    $stmt->execute([$headingId, $qualificationId]);
}

function qualification_heading_detail(PDO $pdo, string $headingId): array
{
    $headingId = trim($headingId);
    if ($headingId === '') {
        throw new InvalidArgumentException('Başlık ID alanı zorunludur.');
    }

    $headingStmt = $pdo->prepare('SELECT id, name, order_index, is_active, created_at, updated_at
                                  FROM qualification_headings
                                  WHERE id = ?
                                  LIMIT 1');
    $headingStmt->execute([$headingId]);
    $heading = $headingStmt->fetch(PDO::FETCH_ASSOC);

    if (!$heading) {
        throw new RuntimeException('Başlık bulunamadı.');
    }

    $itemsStmt = $pdo->prepare('SELECT i.id, i.heading_id, i.qualification_id, i.order_index,
                                       q.name AS qualification_name, q.description AS qualification_description
                                FROM qualification_heading_items i
                                INNER JOIN qualifications q ON q.id = i.qualification_id
                                WHERE i.heading_id = ?
                                ORDER BY COALESCE(i.order_index, 0) ASC, q.name ASC');
    $itemsStmt->execute([$headingId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return [
        'id' => (string)$heading['id'],
        'name' => (string)$heading['name'],
        'order_index' => (int)($heading['order_index'] ?? 0),
        'is_active' => ((int)($heading['is_active'] ?? 0) === 1),
        'created_at' => $heading['created_at'] ?? null,
        'updated_at' => $heading['updated_at'] ?? null,
        'items' => array_map(static function (array $item): array {
            return [
                'id' => (string)($item['id'] ?? ''),
                'heading_id' => (string)($item['heading_id'] ?? ''),
                'qualification_id' => (string)($item['qualification_id'] ?? ''),
                'qualification_name' => (string)($item['qualification_name'] ?? ''),
                'qualification_description' => $item['qualification_description'] ?? null,
                'order_index' => (int)($item['order_index'] ?? 0),
            ];
        }, $items),
    ];
}

function qualification_onboarding_groups(PDO $pdo): array
{
    $where = qualification_heading_qualification_base_sql($pdo, false)['where'];
    $sql = 'SELECT h.id AS heading_id,
                   h.name AS heading_name,
                   h.order_index AS heading_order_index,
                   i.order_index AS item_order_index,
                   q.id AS qualification_id,
                   q.name AS qualification_name,
                   q.description AS qualification_description
            FROM qualification_headings h
            INNER JOIN qualification_heading_items i ON i.heading_id = h.id
            INNER JOIN qualifications q ON q.id = i.qualification_id
            WHERE h.is_active = 1 AND ' . $where . '
            ORDER BY COALESCE(h.order_index, 0) ASC, COALESCE(i.order_index, 0) ASC, q.name ASC';

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $groups = [];
    foreach ($rows as $row) {
        $headingId = (string)($row['heading_id'] ?? '');
        if ($headingId === '') {
            continue;
        }

        if (!isset($groups[$headingId])) {
            $groups[$headingId] = [
                'id' => $headingId,
                'name' => (string)($row['heading_name'] ?? ''),
                'order_index' => (int)($row['heading_order_index'] ?? 0),
                'qualifications' => [],
            ];
        }

        $groups[$headingId]['qualifications'][] = [
            'id' => (string)($row['qualification_id'] ?? ''),
            'name' => (string)($row['qualification_name'] ?? ''),
            'description' => $row['qualification_description'] ?? null,
            'order_index' => (int)($row['item_order_index'] ?? 0),
        ];
    }

    return array_values($groups);
}

function qualification_heading_active_qualifications(PDO $pdo, ?string $headingId = null): array
{
    $headingId = $headingId !== null ? trim($headingId) : null;
    $meta = qualification_heading_qualification_base_sql($pdo, true);
    $sql = 'SELECT q.id, q.name, q.description, q.order_index
            FROM qualifications q
            WHERE ' . $meta['where'];

    $params = [];
    if ($headingId !== null && $headingId !== '') {
        $sql .= ' AND NOT EXISTS (
                    SELECT 1 FROM qualification_heading_items i
                    WHERE i.heading_id = ? AND i.qualification_id = q.id
                 )';
        $params[] = $headingId;
    }

    $sql .= ' ORDER BY COALESCE(q.order_index, 0) ASC, q.name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function qualification_heading_reorder_heading(PDO $pdo, string $id, int $orderIndex): void
{
    $id = trim($id);
    if ($id === '') {
        throw new InvalidArgumentException('Başlık ID alanı zorunludur.');
    }
    $stmt = $pdo->prepare('UPDATE qualification_headings SET order_index = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$orderIndex, $id]);
}

function qualification_heading_reorder_item(PDO $pdo, string $headingId, string $qualificationId, int $orderIndex): void
{
    $headingId = trim($headingId);
    $qualificationId = trim($qualificationId);
    if ($headingId === '' || $qualificationId === '') {
        throw new InvalidArgumentException('Başlık ve yeterlilik alanları zorunludur.');
    }

    $stmt = $pdo->prepare('UPDATE qualification_heading_items SET order_index = ? WHERE heading_id = ? AND qualification_id = ?');
    $stmt->execute([$orderIndex, $headingId, $qualificationId]);
}

function qualification_heading_qualification_base_sql(PDO $pdo, bool $includeOrderColumn): array
{
    $columns = function_exists('get_table_columns') ? get_table_columns($pdo, 'qualifications') : [];
    $where = ['1=1'];

    if (in_array('is_active', $columns, true)) {
        $where[] = 'COALESCE(q.is_active, 0) = 1';
    }
    if (in_array('is_deleted', $columns, true)) {
        $where[] = 'COALESCE(q.is_deleted, 0) = 0';
    }
    if (in_array('deleted_at', $columns, true)) {
        $where[] = 'q.deleted_at IS NULL';
    }
    if (in_array('status', $columns, true)) {
        $where[] = "(q.status = 1 OR LOWER(CAST(q.status AS CHAR)) = 'active')";
    }

    $result = [
        'where' => implode(' AND ', $where),
    ];

    if ($includeOrderColumn) {
        $result['order_column'] = in_array('order_index', $columns, true) ? 'q.order_index' : '0';
    }

    return $result;
}
