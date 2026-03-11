<?php

declare(strict_types=1);

function emrebot_send_json(int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function emrebot_safe_b64url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function emrebot_handle_cors(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowlist = [
        'https://emresokullu.com',
        'https://www.emresokullu.com',
        'http://localhost',
        'http://localhost:3000',
        'http://127.0.0.1',
        'http://127.0.0.1:3000',
    ];

    if ($origin !== '' && in_array($origin, $allowlist, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 600');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

emrebot_handle_cors();

$dir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$subscribersFile = $dir . DIRECTORY_SEPARATOR . 'subscribers.txt';
$pendingFile = $dir . DIRECTORY_SEPARATOR . 'pending.json';

if (!is_dir($dir)) {
    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
        emrebot_send_json(500, ['ok' => false, 'error' => 'Server storage not available']);
    }
}

function emrebot_read_json_file(string $path): array {
    if (!file_exists($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function emrebot_write_json_file_locked(string $path, array $data): bool {
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    ftruncate($fp, 0);
    rewind($fp);
    $ok = fwrite($fp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

function emrebot_email_exists_in_list(string $file, string $email): bool {
    if (!file_exists($file)) {
        return false;
    }
    $raw = file_get_contents($file);
    if (!is_string($raw) || $raw === '') {
        return false;
    }
    $lines = preg_split('/\R/u', $raw);
    if (!is_array($lines)) {
        return false;
    }
    foreach ($lines as $line) {
        if (mb_strtolower(trim($line)) === $email) {
            return true;
        }
    }
    return false;
}

function emrebot_append_email_locked(string $file, string $email): bool {
    $fp = fopen($file, 'c+');
    if ($fp === false) {
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    rewind($fp);
    $existing = stream_get_contents($fp);
    $already = false;
    if (is_string($existing) && $existing !== '') {
        $lines = preg_split('/\R/u', $existing);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (mb_strtolower(trim($line)) === $email) {
                    $already = true;
                    break;
                }
            }
        }
    }
    if (!$already) {
        fseek($fp, 0, SEEK_END);
        fwrite($fp, $email . "\n");
    }
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function emrebot_resend_send_email(string $apiKey, string $from, string $to, string $subject, string $text, string $html): array {
    $ch = curl_init('https://api.resend.com/emails');
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'body' => 'curl_init failed'];
    }

    $payload = [
        'from' => $from,
        'to' => [$to],
        'subject' => $subject,
        'text' => $text,
        'html' => $html,
    ];

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 20,
    ]);

    $body = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'status' => $status, 'body' => $err ?: 'curl_exec failed'];
    }

    if ($status < 200 || $status >= 300) {
        return ['ok' => false, 'status' => $status, 'body' => (string)$body];
    }

    return ['ok' => true, 'status' => $status, 'body' => (string)$body];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') {
        emrebot_send_json(400, ['ok' => false, 'error' => 'Missing token']);
    }

    $pending = emrebot_read_json_file($pendingFile);
    $entry = $pending[$token] ?? null;
    if (!is_array($entry)) {
        emrebot_send_json(400, ['ok' => false, 'error' => 'Invalid token']);
    }

    $email = mb_strtolower(trim((string)($entry['email'] ?? '')));
    $ts = (int)($entry['ts'] ?? 0);
    $maxAgeSec = (int)(getenv('EMREBOT_TOKEN_TTL_SECONDS') ?: 86400);
    if ($email === '' || $ts <= 0 || (time() - $ts) > $maxAgeSec) {
        unset($pending[$token]);
        emrebot_write_json_file_locked($pendingFile, $pending);
        emrebot_send_json(400, ['ok' => false, 'error' => 'Expired token']);
    }

    unset($pending[$token]);
    if (!emrebot_write_json_file_locked($pendingFile, $pending)) {
        emrebot_send_json(500, ['ok' => false, 'error' => 'Could not update pending store']);
    }

    if (!emrebot_append_email_locked($subscribersFile, $email)) {
        emrebot_send_json(500, ['ok' => false, 'error' => 'Could not update subscribers list']);
    }

    $redirect = getenv('EMREBOT_REDIRECT_URL');
    if ($redirect === false || trim($redirect) === '') {
        $redirect = 'https://emresokullu.com/';
    }
    $sep = (strpos($redirect, '?') === false) ? '?' : '&';
    $url = $redirect . $sep . 'emrebot_confirmed=1&email=' . rawurlencode($email);
    header('Location: ' . $url, true, 302);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    emrebot_send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) {
    $body = $_POST;
}

$email = trim((string)($body['email'] ?? ''));
$email = mb_strtolower($email);

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    emrebot_send_json(400, ['ok' => false, 'error' => 'Invalid email']);
}

if (emrebot_email_exists_in_list($subscribersFile, $email)) {
    emrebot_send_json(200, ['ok' => true, 'already_confirmed' => true]);
}

$token = emrebot_safe_b64url(random_bytes(32));
$pending = emrebot_read_json_file($pendingFile);
$pending[$token] = ['email' => $email, 'ts' => time()];
if (!emrebot_write_json_file_locked($pendingFile, $pending)) {
    emrebot_send_json(500, ['ok' => false, 'error' => 'Could not create confirmation token']);
}

$confirmUrl = 'https://aaronswtech.com/subscribe.php?token=' . rawurlencode($token);
$from = getenv('EMREBOT_FROM');
if ($from === false || trim($from) === '') {
    $from = 'notifications@mastoturk.org';
}

$resendKey = getenv('RESEND_API_KEY');
if ($resendKey === false || trim($resendKey) === '') {
    $pending = emrebot_read_json_file($pendingFile);
    unset($pending[$token]);
    emrebot_write_json_file_locked($pendingFile, $pending);
    emrebot_send_json(500, ['ok' => false, 'error' => 'Server not configured (missing RESEND_API_KEY)']);
}

$subject = "Confirm your subscription";
$bodyText = "Click to confirm your email and unlock the chat:\n\n{$confirmUrl}\n\nIf you did not request this, ignore this email.";
$bodyHtml = '<p>Click to confirm your email and unlock the chat:</p>' .
    '<p><a href="' . htmlspecialchars($confirmUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' .
    htmlspecialchars($confirmUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></p>' .
    '<p>If you did not request this, ignore this email.</p>';

$sent = emrebot_resend_send_email(trim((string)$resendKey), (string)$from, $email, $subject, $bodyText, $bodyHtml);
if (!$sent['ok']) {
    $pending = emrebot_read_json_file($pendingFile);
    unset($pending[$token]);
    emrebot_write_json_file_locked($pendingFile, $pending);
    emrebot_send_json(500, ['ok' => false, 'error' => 'Could not send confirmation email', 'details' => $sent['body']]);
}

emrebot_send_json(200, ['ok' => true, 'confirmation_sent' => true]);
