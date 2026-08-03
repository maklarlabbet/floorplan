<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login_api();

// A minimal, tiny request — no image involved — so we can tell whether connectivity/
// the API key works at all, separate from anything specific to larger image payloads.
$body = [
    'model' => ANTHROPIC_MODEL,
    'max_tokens' => 20,
    'messages' => [[
        'role' => 'user',
        'content' => 'Reply with exactly the single word: OK',
    ]],
];

$result = call_claude_raw($body);

if (!$result['ok']) {
    json_response(['ok' => false, 'error' => $result['error']], 502);
}

json_response(['ok' => true, 'message' => 'Connected to Claude successfully.', 'response' => trim($result['text'])]);
