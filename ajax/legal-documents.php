<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/legal_html_sanitizer.php';

$authUser = require_admin();

const LEGAL_DOCS_TABLE = 'legal_documents';

function legal_json($success, $message = '', $data = [], $status = 200, $errors = [])
{
    http_response_code($status);
    echo json_encode([
        'success' => (bool)$success,
        'message' => $message,
        'data' => $data,
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function legal_default_document($docKey)
{
    if ($docKey === 'terms') {
        return [
            'doc_key' => 'terms',
            'title' => 'Denizci Eğitim – Kullanım Koşulları',
            'content' => '<h3>Denizci Eğitim – Kullanım Koşulları</h3><p>Bu alan için güncel kullanım koşulları metnini admin panelden güncelleyebilirsiniz.</p><p><em>Placeholder metin:</em> Kullanıcı tarafından sağlanacak güncel sözleşme metni burada yayınlanacaktır.</p>',
            'status' => 'draft',
            'version' => 1,
            'updated_at' => null,
            'updated_by' => null,
            'updated_by_label' => '-',
        ];
    }

    if ($docKey === 'cookie_policy') {
        return [
            'doc_key' => 'cookie_policy',
            'title' => 'Denizci Eğitim – Çerez Politikası',
            'content' => '<h3>Denizci Eğitim – Çerez Politikası</h3><p>Bu alan için güncel çerez politikası metnini admin panelden güncelleyebilirsiniz.</p><p>Çerez politikası; zorunlu çerezler, tercih çerezleri, analitik/performance çerezleri ve kullanıcı tercih yönetimi hakkında bilgilendirme içerir.</p>',
            'status' => 'draft',
            'version' => 1,
            'updated_at' => null,
            'updated_by' => null,
            'updated_by_label' => '-',
        ];
    }

    return [
        'doc_key' => 'privacy',
        'title' => 'Denizci Eğitim – Gizlilik Politikası',
        'content' => '<h3>Denizci Eğitim – Gizlilik Politikası</h3><p>Bu alan için güncel gizlilik politikası metnini admin panelden güncelleyebilirsiniz.</p><p><em>Placeholder metin:</em> Denizci Eğitim / DIGITALAND LTD bilgilerine göre hazırlanacak metin burada yayınlanacaktır.</p><p>İletişim: support@denizciegitim.com</p>',
        'status' => 'draft',
        'version' => 1,
        'updated_at' => null,
        'updated_by' => null,
        'updated_by_label' => '-',
    ];
}

function legal_validate_doc_key($docKey)
{
    return in_array($docKey, ['terms', 'privacy', 'cookie_policy'], true);
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

try {
    if ($action === 'list') {
        $stmt = $pdo->prepare(
            'SELECT ld.doc_key, ld.title, ld.content, ld.status, ld.version, ld.updated_at, ld.updated_by, up.email AS updated_by_email
             FROM ' . LEGAL_DOCS_TABLE . ' ld
             LEFT JOIN user_profiles up ON up.id = ld.updated_by
             WHERE ld.doc_key IN (\'terms\', \'privacy\', \'cookie_policy\')'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $mapped = [
            'terms' => legal_default_document('terms'),
            'privacy' => legal_default_document('privacy'),
            'cookie_policy' => legal_default_document('cookie_policy'),
        ];

        foreach ($rows as $row) {
            $key = (string)($row['doc_key'] ?? '');
            if (!legal_validate_doc_key($key)) {
                continue;
            }

            $mapped[$key] = [
                'doc_key' => $key,
                'title' => (string)($row['title'] ?? ''),
                'content' => (string)($row['content'] ?? ''),
                'status' => (string)($row['status'] ?? 'draft'),
                'version' => max(1, (int)($row['version'] ?? 1)),
                'updated_at' => $row['updated_at'] ?? null,
                'updated_by' => $row['updated_by'] ?? null,
                'updated_by_label' => (string)($row['updated_by_email'] ?? '-'),
            ];
        }

        legal_json(true, '', [
            'terms' => $mapped['terms'],
            'privacy' => $mapped['privacy'],
            'cookie_policy' => $mapped['cookie_policy'],
        ]);
    }

    if ($action === 'save') {
        $docKey = trim((string)($_POST['doc_key'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $status = trim((string)($_POST['status'] ?? 'draft'));

        $errors = [];
        if (!legal_validate_doc_key($docKey)) {
            $errors['doc_key'] = 'invalid';
        }
        if ($title === '') {
            $errors['title'] = 'required';
        }
        if ($content === '') {
            $errors['content'] = 'required';
        }
        if (!in_array($status, ['draft', 'published'], true)) {
            $errors['status'] = 'invalid';
        }

        if (!empty($errors)) {
            legal_json(false, 'Validasyon hatası.', [], 422, $errors);
        }
        $content = legal_sanitize_html($content);

        $adminId = (string)($authUser['user_id'] ?? ($_SESSION['user_id'] ?? ''));

        $findStmt = $pdo->prepare('SELECT id, version FROM ' . LEGAL_DOCS_TABLE . ' WHERE doc_key = ? LIMIT 1');
        $findStmt->execute([$docKey]);
        $existing = $findStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $newVersion = ((int)($existing['version'] ?? 1)) + 1;
            $updateStmt = $pdo->prepare(
                'UPDATE ' . LEGAL_DOCS_TABLE . '
                 SET title = ?, content = ?, status = ?, version = ?, updated_by = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $updateStmt->execute([
                $title,
                $content,
                $status,
                $newVersion,
                $adminId,
                $existing['id'],
            ]);
        } else {
            $insertStmt = $pdo->prepare(
                'INSERT INTO ' . LEGAL_DOCS_TABLE . ' (id, doc_key, title, content, status, version, updated_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, ?, NOW(), NOW())'
            );
            $insertStmt->execute([
                generate_uuid(),
                $docKey,
                $title,
                $content,
                $status,
                $adminId,
            ]);
        }

        legal_json(true, 'Yasal metin kaydedildi.');
    }

    legal_json(false, 'Geçersiz işlem.', [], 400);
} catch (Throwable $e) {
    legal_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}
