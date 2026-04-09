<?php
// includes/functions.php

function sanitize_input($data)
{
    return htmlspecialchars(strip_tags(trim((string)$data)), ENT_QUOTES, 'UTF-8');
}

function generate_uuid()
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

function format_date($date)
{
    if (!$date) {
        return '-';
    }
    return date('d.m.Y H:i', strtotime($date));
}

function success_response($message, $data = [])
{
    return json_response([
        'success' => true,
        'message' => $message,
        'data' => $data,
    ]);
}

function error_response($message, $status = 400)
{
    return json_response([
        'success' => false,
        'message' => $message,
    ], $status);
}

function normalize_optional_uuid($value)
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function validate_topic_belongs_to_course(PDO $pdo, $topicId, $courseId)
{
    $normalizedTopicId = normalize_optional_uuid($topicId);
    if ($normalizedTopicId === null) {
        return true;
    }

    $normalizedCourseId = trim((string)$courseId);
    if ($normalizedCourseId === '') {
        return false;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM topics WHERE id = ? AND course_id = ?');
    $stmt->execute([$normalizedTopicId, $normalizedCourseId]);
    return (int)$stmt->fetchColumn() > 0;
}

function format_explanation_text($rawExplanation): string
{
    if ($rawExplanation === null) {
        return '';
    }

    $text = (string)$rawExplanation;
    if ($text === '') {
        return '';
    }

    // CRLF/CR farklarını normalize et
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    // Önce cümle sonlarından sonra gelen şıkları daha okunur bir blok olarak ayır
    $text = preg_replace('/([.!?…])\s+([A-E]\)\s)/u', "$1\n\n$2", $text) ?? $text;

    // Kalan A) ... E) marker'larını tek satırda akıyorsa yeni satıra al
    $text = preg_replace('/([^\n])\s+([A-E]\)\s)/u', "$1\n$2", $text) ?? $text;

    // Doğru Cevap: bloğunu ayrı satıra al
    $text = preg_replace('/([^\n])\s+(Doğru\s*Cevap\s*:)/iu', "$1\n\n$2", $text) ?? $text;

    // Satır sonlarındaki gereksiz boşlukları temizle, aşırı boşluk üretimini engelle
    $text = preg_replace('/[ \t]+\n/u', "\n", $text) ?? $text;
    $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

    return trim($text);
}

function format_explanation_html($rawExplanation): string
{
    $formatted = format_explanation_text($rawExplanation);
    if ($formatted === '') {
        return '';
    }

    $safe = htmlspecialchars($formatted, ENT_QUOTES, 'UTF-8');
    return nl2br($safe, false);
}
