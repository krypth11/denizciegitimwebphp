<?php

function question_normalize_youtube_video_id($value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $raw) === 1) {
        return $raw;
    }

    $url = filter_var($raw, FILTER_VALIDATE_URL);
    if ($url === false) {
        throw new InvalidArgumentException('Geçerli bir YouTube video bağlantısı girin.');
    }

    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
    if ($scheme !== 'https') {
        throw new InvalidArgumentException('YouTube bağlantısı HTTPS olmalıdır.');
    }

    $allowedHosts = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtu.be'];
    if (!in_array($host, $allowedHosts, true)) {
        throw new InvalidArgumentException('Yalnızca YouTube bağlantıları kabul edilir.');
    }

    $candidate = '';
    $path = trim((string)($parts['path'] ?? ''), '/');
    if ($host === 'youtu.be' || $host === 'www.youtu.be') {
        $candidate = explode('/', $path)[0] ?? '';
    } elseif ($path === 'watch') {
        parse_str((string)($parts['query'] ?? ''), $query);
        $candidate = (string)($query['v'] ?? '');
    } elseif (preg_match('~^(?:embed|shorts|live)/([^/?#]+)~', $path, $match) === 1) {
        $candidate = (string)$match[1];
    }

    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate) !== 1) {
        throw new InvalidArgumentException('YouTube video kimliği bağlantıdan okunamadı.');
    }

    return $candidate;
}

function question_youtube_watch_url($videoId): ?string
{
    $id = trim((string)$videoId);
    return preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1
        ? 'https://www.youtube.com/watch?v=' . $id
        : null;
}
