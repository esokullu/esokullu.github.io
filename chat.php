<?php

declare(strict_types=1);

function emrebot_send_json(int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
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

function emrebot_strip_markdown(string $md): string {
    $md = preg_replace('/```[\s\S]*?```/u', ' ', $md) ?? $md;
    $md = preg_replace('/`[^`]*`/u', ' ', $md) ?? $md;
    $md = preg_replace('/^\s{0,3}#{1,6}\s+/mu', '', $md) ?? $md;
    $md = preg_replace('/!\[[^\]]*\]\([^\)]*\)/u', ' ', $md) ?? $md;
    $md = preg_replace('/\[[^\]]*\]\(([^\)]*)\)/u', ' ', $md) ?? $md;
    $md = preg_replace('/[*_~>]/u', ' ', $md) ?? $md;
    $md = preg_replace('/\R{2,}/u', "\n\n", $md) ?? $md;
    return trim($md);
}

function emrebot_parse_post(string $path): array {
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return ['title' => basename($path), 'text' => '', 'path' => $path];
    }
    $title = basename($path);
    $body = $raw;
    if (preg_match('/\A---\s*\R([\s\S]*?)\R---\s*\R?/u', $raw, $m)) {
        $front = (string)$m[1];
        $body = (string)substr($raw, strlen((string)$m[0]));
        if (preg_match('/^title:\s*(.+)\s*$/mi', $front, $tm)) {
            $t = trim((string)$tm[1]);
            $t = trim($t, "\"'");
            if ($t !== '') {
                $title = $t;
            }
        }
    }
    $text = emrebot_strip_markdown($body);
    return ['title' => $title, 'text' => $text, 'path' => $path];
}

function emrebot_keywords(string $q): array {
    $q = mb_strtolower($q);
    $q = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $q) ?? $q;
    $parts = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts)) {
        return [];
    }
    $stop = [
        'the','a','an','and','or','but','if','then','else','when','what','why','how','who','where',
        'to','of','in','on','for','from','with','without','about','as','at','by','is','are','was','were',
        'i','you','we','they','he','she','it','my','your','our','their',
    ];
    $out = [];
    foreach ($parts as $p) {
        if (mb_strlen($p) < 3) {
            continue;
        }
        if (in_array($p, $stop, true)) {
            continue;
        }
        $out[] = $p;
    }
    return array_values(array_unique($out));
}

function emrebot_score_post(array $post, array $keywords): int {
    $title = mb_strtolower((string)($post['title'] ?? ''));
    $text = mb_strtolower((string)($post['text'] ?? ''));
    $score = 0;
    foreach ($keywords as $kw) {
        if ($kw === '') {
            continue;
        }
        $score += 8 * substr_count($title, $kw);
        $score += 1 * substr_count($text, $kw);
    }
    return $score;
}

function emrebot_build_blog_context(string $postsDir, string $query, int $maxPosts = 3, int $maxChars = 2200): string {
    if (!is_dir($postsDir)) {
        return '';
    }

    $keywords = emrebot_keywords($query);
    if (count($keywords) === 0) {
        return '';
    }

    $paths = glob(rtrim($postsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.md');
    if (!is_array($paths) || count($paths) === 0) {
        return '';
    }

    $candidates = [];
    $limitFiles = 120;
    $n = 0;
    foreach ($paths as $p) {
        if ($n >= $limitFiles) {
            break;
        }
        $n++;
        $post = emrebot_parse_post($p);
        if (!is_string($post['text']) || $post['text'] === '') {
            continue;
        }
        $score = emrebot_score_post($post, $keywords);
        if ($score <= 0) {
            continue;
        }
        $post['score'] = $score;
        $candidates[] = $post;
    }

    if (count($candidates) === 0) {
        return '';
    }

    usort($candidates, function($a, $b){
        return (int)($b['score'] ?? 0) <=> (int)($a['score'] ?? 0);
    });

    $picked = array_slice($candidates, 0, $maxPosts);
    $out = "Context from Emre's blog posts (excerpts):\n";
    $used = 0;
    foreach ($picked as $post) {
        $title = (string)($post['title'] ?? '');
        $text = (string)($post['text'] ?? '');
        $path = (string)($post['path'] ?? '');
        $excerpt = mb_substr($text, 0, 700);
        $block = "\nTITLE: {$title}\nFILE: {$path}\nEXCERPT: {$excerpt}\n";
        if ($used + mb_strlen($block) > $maxChars) {
            break;
        }
        $out .= $block;
        $used += mb_strlen($block);
    }

    return trim($out);
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

function emrebot_http_post_json(string $url, array $payload, array $headers = []): array {
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'body' => 'curl_init failed'];
    }

    $defaultHeaders = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $allHeaders = array_merge($defaultHeaders, $headers);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $allHeaders,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);

    $body = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'status' => $status, 'body' => $err ?: 'curl_exec failed'];
    }

    return ['ok' => true, 'status' => $status, 'body' => (string)$body];
}

emrebot_handle_cors();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    emrebot_send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) {
    $body = $_POST;
}

$message = trim((string)($body['message'] ?? ''));
$email = mb_strtolower(trim((string)($body['email'] ?? '')));

if ($message === '') {
    emrebot_send_json(400, ['ok' => false, 'error' => 'Missing message']);
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    emrebot_send_json(401, ['ok' => false, 'error' => 'Email not verified']);
}

$subscribersFile = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'subscribers.txt';
if (!emrebot_email_exists_in_list($subscribersFile, $email)) {
    emrebot_send_json(401, ['ok' => false, 'error' => 'Email not verified']);
}

$apiKey = getenv('MINIMAX_API_KEY');
if ($apiKey === false || trim($apiKey) === '') {
    emrebot_send_json(500, ['ok' => false, 'error' => 'Server not configured (missing MINIMAX_API_KEY)']);
}

$baseUrl = getenv('MINIMAX_BASE_URL');
if ($baseUrl === false || trim($baseUrl) === '') {
    $baseUrl = 'https://api.minimax.io/v1';
}
$baseUrl = rtrim($baseUrl, '/');

$model = getenv('MINIMAX_MODEL');
if ($model === false || trim($model) === '') {
    $model = 'MiniMax-M2.5';
}

$system = "You are Emre Sokullu, writing as me in first person. Be concise, direct, and practical. " .
    "When asked about my opinions, ground them in my published writing if provided. " .
    "If the question goes beyond my writing or you are unsure, say so and ask a clarifying question. " .
    "Do not invent quotes or claim I wrote something unless it appears in the provided context.";

$blogDir = getenv('EMREBOT_BLOG_POSTS_DIR');
if ($blogDir === false || trim($blogDir) === '') {
    $blogDir = '/var/www/myblog/_posts';
}
$blogContext = emrebot_build_blog_context((string)$blogDir, $message);

$messages = [
    ['role' => 'system', 'content' => $system],
];
if ($blogContext !== '') {
    $messages[] = ['role' => 'system', 'content' => $blogContext];
}
$messages[] = ['role' => 'system', 'content' => "The user's verified email is: {$email}." ];
$messages[] = ['role' => 'user', 'content' => $message];

$payload = [
    'model' => $model,
    'messages' => $messages,
];

$resp = emrebot_http_post_json($baseUrl . '/chat/completions', $payload, [
    'Authorization: Bearer ' . trim($apiKey),
]);

if (!$resp['ok']) {
    emrebot_send_json(502, ['ok' => false, 'error' => 'Upstream request failed', 'details' => $resp['body']]);
}

$decoded = json_decode((string)$resp['body'], true);
if (!is_array($decoded)) {
    emrebot_send_json(502, ['ok' => false, 'error' => 'Invalid upstream response']);
}

$reply = '';
if (isset($decoded['choices'][0]['message']['content']) && is_string($decoded['choices'][0]['message']['content'])) {
    $reply = $decoded['choices'][0]['message']['content'];
}

$reply = trim($reply);
if ($reply === '') {
    emrebot_send_json(502, ['ok' => false, 'error' => 'Empty reply from model']);
}

emrebot_send_json(200, ['ok' => true, 'reply' => $reply]);
