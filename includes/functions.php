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
