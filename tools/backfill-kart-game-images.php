<?php

if (php_sapi_name() !== 'cli') {
    exit("Only CLI allowed.\n");
}

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/upload_helper.php';
require_once dirname(__DIR__) . '/includes/kart_game_helper.php';

function backfill_parse_limit(array $argv): int
{
    $limit = 100;

    foreach ($argv as $arg) {
        $arg = trim((string)$arg);
        if ($arg === '') {
            continue;
        }

        if (preg_match('/^--limit=(\d+)$/i', $arg, $m)) {
            return max(1, (int)$m[1]);
        }

        if (preg_match('/^limit=(\d+)$/i', $arg, $m)) {
            return max(1, (int)$m[1]);
        }

        if (preg_match('/^\?(.*)$/', $arg, $m)) {
            parse_str($m[1], $q);
            if (isset($q['limit'])) {
                return max(1, (int)$q['limit']);
            }
        }
    }

    return $limit;
}

$limit = backfill_parse_limit($argv ?? []);

$sql = 'SELECT id, image_path, image_url, image_large_path, image_large_url, image_thumb_path, image_thumb_url '
    . 'FROM kart_game_questions '
    . 'WHERE (image_large_path IS NULL OR image_large_path = "" OR image_thumb_path IS NULL OR image_thumb_path = "") '
    . 'AND (image_path IS NOT NULL AND image_path <> "") '
    . 'LIMIT ' . (int)$limit;

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$updated = 0;
$skipped = 0;
$failed = 0;

foreach ($rows as $row) {
    $id = (string)($row['id'] ?? '');
    if ($id === '') {
        $skipped++;
        continue;
    }

    $sourceCandidate = (string)($row['image_path'] ?? $row['image_url'] ?? '');
    $sourceAbs = upload_relative_path_to_abs($sourceCandidate);
    if ($sourceAbs === '' || !is_file($sourceAbs)) {
        echo "[SKIP] {$id} source not found: {$sourceCandidate}\n";
        $skipped++;
        continue;
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'kgbf_');
    if ($tmpFile === false || !@copy($sourceAbs, $tmpFile)) {
        echo "[FAIL] {$id} temp copy failed\n";
        $failed++;
        continue;
    }

    try {
        $fake = [
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => $tmpFile,
            'size' => (int)@filesize($tmpFile),
        ];

        // upload_validate_image_file is_uploaded_file kontrolü yaptığı için doğrudan kullanmıyoruz.
        // Backfill için kaynak dosyadan manuel variant üretiyoruz.
        $dim = @getimagesize($tmpFile);
        if (!is_array($dim)) {
            throw new RuntimeException('Kaynak görsel okunamadı.');
        }

        $variants = [];
        $uuid = str_replace('-', '', function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16)));
        $base = upload_module_base_paths('kart-oyunu');
        $subDir = 'images';
        $absDir = $base['base_abs'] . DIRECTORY_SEPARATOR . 'images';
        upload_ensure_directory_ready($absDir);

        $specs = [
            'large' => ['w' => 800, 'h' => 1000, 'q' => 82],
            'thumb' => ['w' => 320, 'h' => 400, 'q' => 74],
        ];

        foreach ($specs as $name => $s) {
            $filename = 'kart-game-' . $uuid . '-' . $name . '.webp';
            $abs = $absDir . DIRECTORY_SEPARATOR . $filename;
            upload_create_webp_variant($tmpFile, $abs, $s['w'], $s['h'], $s['q']);
            $rel = upload_sanitize_relative_path($base['base_rel'] . '/' . $subDir . '/' . $filename);
            $variants[$name] = [
                'path' => $rel,
                'url' => upload_build_public_url($rel),
            ];
        }

        $stmt = $pdo->prepare('UPDATE kart_game_questions SET image_url = ?, image_path = ?, image_large_url = ?, image_large_path = ?, image_thumb_url = ?, image_thumb_path = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([
            $variants['large']['url'],
            $variants['large']['path'],
            $variants['large']['url'],
            $variants['large']['path'],
            $variants['thumb']['url'],
            $variants['thumb']['path'],
            $id,
        ]);

        $updated++;
        echo "[OK] {$id}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "[FAIL] {$id} " . $e->getMessage() . "\n";
    } finally {
        @unlink($tmpFile);
    }
}

echo "Done. scanned=" . count($rows) . " updated={$updated} skipped={$skipped} failed={$failed}\n";
