<?php

declare(strict_types=1);

function emrebot_chat_log(string $email, string $message, string $reply, array $meta = []): bool {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
    }

    $file = $dir . DIRECTORY_SEPARATOR . 'chat.jsonl';

    $message = trim($message);
    $reply = trim($reply);
    if (mb_strlen($message) > 8000) {
        $message = mb_substr($message, 0, 8000);
    }
    if (mb_strlen($reply) > 12000) {
        $reply = mb_substr($reply, 0, 12000);
    }

    $row = [
        'ts' => gmdate('c'),
        'email' => $email,
        'message' => $message,
        'reply' => $reply,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'meta' => $meta,
    ];

    $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($line)) {
        return false;
    }

    $fp = fopen($file, 'ab');
    if ($fp === false) {
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }

    $ok = fwrite($fp, $line . "\n") !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}
