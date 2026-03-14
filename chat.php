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

$noThink = false;
if (strncmp($message, '/nothink', 8) === 0) {
    $noThink = true;
    $message = trim((string)substr($message, 8));
}

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
    "Your bio is this: ".
    "Emre Sokullu is a Turkish entrepreneur, open-source technologist, and product founder whose career spans Linux, online communities, social graph infrastructure, and, more recently, Bitcoin and crypto-security products. He first emerged in Turkey’s open-source ecosystem with Turkix, one of the country’s early Linux distributions, developed while he was still a university student. Turkix later became part of Armador, marking one of the earliest important milestones in his career and establishing him as a young builder focused on infrastructure rather than just applications.

Educated at Galatasaray Lisesi and Boğaziçi University, Sokullu also worked on other early open-source and research-driven projects, including machine translation and Linux-related tools, before expanding into the international technology world. His early career included roles such as technology evangelist at Hakia and writer at ReadWriteWeb, reflecting both his technical depth and his ability to interpret emerging internet trends.

Sokullu became best known internationally as the founder and CEO of GROU.PS, the online community platform he launched in 2006. GROU.PS grew into one of the best-known global startup stories to emerge from Turkey during the Web 2.0 era, serving millions of users across niche social networks and online communities. The company was later acquired by a telecom company, a major turning point that cemented Sokullu’s reputation as a founder capable of building and scaling globally relevant internet products.

After GROU.PS, he continued working at the intersection of community software and social infrastructure. He later built GraphJS, a framework and product vision centered on making the web more meaningfully social, and also worked on Pho, a broader architecture for decentralized or graph-centered online interaction. During this phase of his life and career, he spent time living in Reno, Nevada, where he worked on GraphJS and Pho while continuing to develop his ideas around digital communities, user ownership, and internet architecture. GraphJS was later acquired by Rock Content, adding another notable company outcome to his career.

His open-source work remains a major part of his identity as a builder. Across his GitHub presence, he has published and maintained a wide range of projects touching social-web tooling, frameworks, knowledge systems, and language technologies. These include projects such as graphjs, graphjs-server, pho-framework, hack-mvc, and wordnetd, among many others. Taken together, these projects show a long-running pattern in his work: building foundational layers that help communities, developers, and users interact more freely and more meaningfully online.

In recent years, Sokullu has increasingly focused on Bitcoin, self-custody, inheritance, and digital asset security. His newer work includes projects such as SecureBtcWallet and miras.global, both of which reflect his continuing interest in sovereignty, resilience, and long-term digital ownership. Rather than moving away from his earlier themes, this phase extends them: from community ownership and social graph portability to financial self-sovereignty and intergenerational transfer of digital assets.

On the personal side, Emre Sokullu is divorced and has one daughter. He is now based in Istanbul, after years of living and working across both Turkey and the United States. His life and career have combined entrepreneurship, open-source development, writing, and long-term thinking about how people build communities, preserve value, and retain independence in the digital age."
    
    "When asked about my opinions, ground them in my published writing if provided. " .
    "If the question goes beyond my writing or you are unsure, say so and ask a clarifying question. " .
    "Do not invent quotes or claim I wrote something unless it appears in the provided context. " .
    "Do not output chain-of-thought. If you need to reason, do it silently and only output the final answer.";

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

if ($noThink) {
    $messages[] = ['role' => 'system', 'content' => 'The user requested /nothink. Do not include any reasoning, analysis, or <think> tags. Output only the final answer.'];
}

$messages[] = ['role' => 'user', 'content' => $message];

$payload = [
    'model' => $model,
    'messages' => $messages,
    'extra_body' => ['reasoning_split' => true],
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

$reply = preg_replace('/<think>[\s\S]*?<\/think>/u', '', $reply) ?? $reply;
$reply = trim($reply);
if ($reply === '') {
    emrebot_send_json(502, ['ok' => false, 'error' => 'Empty reply from model']);
}

@include_once __DIR__ . DIRECTORY_SEPARATOR . 'chat_log.php'; function_exists('emrebot_chat_log') && @emrebot_chat_log($email, $message, $reply);

emrebot_send_json(200, ['ok' => true, 'reply' => $reply]);
