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
    $from = 'noreply@aaronswtech.com';
}

$subject = "Confirm your subscription";
$bodyText = "Click to confirm your email and unlock the chat:\n\n{$confirmUrl}\n\nIf you did not request this, ignore this email.";

$headers = "From: {$from}\r\n";
$headers .= "Reply-To: {$from}\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$sent = @mail($email, $subject, $bodyText, $headers);
if (!$sent) {
    $pending = emrebot_read_json_file($pendingFile);
    unset($pending[$token]);
    emrebot_write_json_file_locked($pendingFile, $pending);
    emrebot_send_json(500, ['ok' => false, 'error' => 'Could not send confirmation email']);
}

emrebot_send_json(200, ['ok' => true, 'confirmation_sent' => true]);
