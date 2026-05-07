<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/response_helper.php';

api_require_method('GET');

function legal_public_default_document($docKey)
{
    if ($docKey === 'terms') {
        return [
            'doc_key' => 'terms',
            'title' => 'Denizci Eğitim – Kullanım Koşulları',
            'content' => '<h3>Denizci Eğitim – Kullanım Koşulları</h3><p>Bu alan için güncel kullanım koşulları metnini admin panelden güncelleyebilirsiniz.</p><p><em>Placeholder metin:</em> Kullanıcı tarafından sağlanacak güncel sözleşme metni burada yayınlanacaktır.</p>',
            'status' => 'published',
            'version' => 1,
            'updated_at' => null,
        ];
    }

    return [
        'doc_key' => 'privacy',
        'title' => 'Denizci Eğitim – Gizlilik Politikası',
        'content' => '<h3>Denizci Eğitim – Gizlilik Politikası</h3><p>Bu alan için güncel gizlilik politikası metnini admin panelden güncelleyebilirsiniz.</p><p><em>Placeholder metin:</em> Denizci Eğitim / DIGITALAND LTD bilgilerine göre hazırlanacak metin burada yayınlanacaktır.</p>',
        'status' => 'published',
        'version' => 1,
        'updated_at' => null,
    ];
}

try {
    $docKey = 'privacy';
    $stmt = $pdo->prepare('SELECT doc_key, title, content, status, version, updated_at FROM legal_documents WHERE doc_key = ? AND status = ? LIMIT 1');
    $stmt->execute([$docKey, 'published']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        api_success('Yasal metin getirildi.', legal_public_default_document($docKey));
    }

    api_success('Yasal metin getirildi.', [
        'doc_key' => (string)$row['doc_key'],
        'title' => (string)$row['title'],
        'content' => (string)$row['content'],
        'status' => (string)$row['status'],
        'version' => max(1, (int)$row['version']),
        'updated_at' => $row['updated_at'] ?? null,
    ]);
} catch (Throwable $e) {
    api_error('Yasal metin alınamadı.', 500);
}
