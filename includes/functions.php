<?php
require_once __DIR__ . '/../config/config.php';

/**
 * The structured "vector floorplan" schema. Claude reads an image and produces this,
 * or reads this + a description of hand-drawn edits and produces an updated version.
 * Coordinates are in a normalized 0-1000 unit canvas (canvas.width x canvas.height),
 * NOT pixels, so the frontend renderer can scale it to any screen size.
 */
function floorplan_schema_description() {
    return <<<SCHEMA
Return ONLY a single valid JSON object (no markdown fences, no commentary) with this exact shape:

{
  "canvas": { "width": 1000, "height": 700, "unit": "ft", "note": "optional scale note, e.g. '1 unit = 0.05 ft'" },
  "walls": [ { "id": "w1", "x1": 0, "y1": 0, "x2": 0, "y2": 0, "thickness": 6 } ],
  "rooms": [ { "id": "r1", "name": "Living Room", "polygon": [[x,y], [x,y], [x,y]], "label": {"x": 0, "y": 0} } ],
  "doors": [ { "id": "d1", "x": 0, "y": 0, "width": 30, "orientation": "horizontal", "swing": "in" } ],
  "windows": [ { "id": "wn1", "x": 0, "y": 0, "width": 40, "orientation": "horizontal" } ],
  "dimensions": [ { "from": [0,0], "to": [0,0], "label": "12 ft" } ],
  "stairs": [ { "id": "s1", "x": 0, "y": 0, "width": 0, "height": 0, "orientation": "horizontal", "direction": "up", "steps": 12 } ],
  "notes": [ { "x": 0, "y": 0, "text": "free text note" } ]
}

Rules:
- "orientation" is "horizontal" or "vertical" describing which wall the door/window sits on.
- Keep coordinates internally consistent (walls form closed room boundaries where the drawing implies enclosed rooms).
- Use walls.thickness in canvas units (typically 4-10).
- Room polygons should be closed shapes made of the same coordinates as adjoining walls, so rooms and walls line up visually.
- "stairs": "x","y" is the top-left corner of the staircase's bounding box; "width"/"height" are its footprint; "orientation" is "horizontal" if the flight of steps runs left-right or "vertical" if it runs up-down the page; "steps" is the approximate number of treads. "direction" tells which corner of the box is the higher floor: "up" means ascending from the ("x","y") corner toward the opposite ("x"+"width","y"+"height") corner; "down" means the reverse, ascending from the opposite corner toward ("x","y").
- Staircases: if the source shows a staircase — whether drawn as an actual stair symbol (parallel tread lines) or only labeled with text like "Stairs", "Staircase", or "Stairway" — always represent it as an entry in "stairs" with its bounding box, never as a room name or a "notes" entry. Do not put the word "Stairs"/"Staircase" anywhere in a room "name" or in "notes".
- Multi-flight staircases (turning 90°/180° at a landing, e.g. L-shaped or U-shaped): represent as MULTIPLE entries in "stairs" — one entry per straight flight, each with its own bounding box, "orientation", and "direction" so each flight's arrow points the right way — plus one entry per landing (the flat turning pad between flights) with "steps": 0, which renders as a plain outline with no tread lines. A spiral/curved staircase should still be approximated this way (straight flights + landings); do not invent a curved shape.
- Include a dimension line for at least the overall footprint width and height, and for any room whose size the source clearly implies.
- If the source image is a rough hand sketch, infer sensible right angles and straightened walls — the goal is a clean, professional floorplan, not a literal trace of wobbly lines.
- If information is ambiguous or missing (e.g. a room label is unreadable), make a reasonable assumption and note it in "notes".
- Room naming: if the source shows a legible label/text in or near a room, use that exact text verbatim as "name" — do not modify, translate, or append anything to it (e.g. a room labeled "G" must have "name": "G", never "G (Closet 1)"). If a room has no legible label at all, set "name" to an empty string rather than inventing a generic placeholder like "Closet 1" or "Room 1".
- Output nothing except the JSON object.
SCHEMA;
}

/**
 * Call Claude with an uploaded floorplan image and get back the structured JSON.
 */
function claude_analyze_image($image_path, $mime_type) {
    $image_data = base64_encode(file_get_contents($image_path));

    $prompt = "You are an expert architectural drafter. Look at the attached floorplan image. "
        . "It may be a rough hand-drawn sketch or an existing digital/system-made floorplan. "
        . "Extract its structure and re-express it as a clean, professional floorplan using the JSON schema below.\n\n"
        . floorplan_schema_description();

    $body = [
        'model' => ANTHROPIC_MODEL,
        'max_tokens' => 8000,
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime_type, 'data' => $image_data]],
                ['type' => 'text', 'text' => $prompt],
            ],
        ]],
    ];

    return call_claude($body);
}

/**
 * Call Claude with the current floorplan JSON plus a description of hand-drawn edits,
 * and get back an updated floorplan JSON.
 */
function claude_regenerate($current_json, $annotation_summary) {
    $prompt = "Here is the current floorplan, expressed in the JSON schema described below:\n\n"
        . "CURRENT_FLOORPLAN_JSON:\n" . $current_json . "\n\n"
        . "The user has drawn changes on top of this floorplan and/or left notes describing what to change. "
        . "Here is a summary of those changes, including approximate positions in the same 0-1000 canvas coordinate "
        . "system as the floorplan (freehand strokes are simplified to their bounding regions):\n\n"
        . "REQUESTED_CHANGES:\n" . $annotation_summary . "\n\n"
        . "Apply these changes to the floorplan sensibly (e.g. a stroke across a wall may mean 'remove this wall', "
        . "a stroke enclosing new space may mean 'add a room here', a note near a wall like 'move this wall' or "
        . "'make bigger' should resize/reposition the relevant room while keeping the rest of the layout consistent). "
        . "Then output the COMPLETE updated floorplan using the exact same JSON schema:\n\n"
        . floorplan_schema_description();

    $body = [
        'model' => ANTHROPIC_MODEL,
        'max_tokens' => 8000,
        'messages' => [[
            'role' => 'user',
            'content' => $prompt,
        ]],
    ];

    return call_claude($body);
}

/**
 * Low-level call to the Anthropic Messages API. Returns the raw text of Claude's reply,
 * without assuming it must be JSON. Used both by call_claude() (which additionally requires
 * and parses a floorplan-JSON response) and by the connection-test diagnostic.
 * Returns ['ok'=>bool, 'text'=>string|null, 'error'=>string|null, 'debug'=>string|null]
 */
/**
 * Config values like API keys are often pasted from a browser/clipboard and can pick up an
 * invisible trailing newline or stray whitespace without the person noticing — which, if left
 * in an HTTP header value, corrupts the request at the protocol level (a stray \r or \n
 * prematurely terminates the header line). This happened in practice and was very hard to
 * diagnose from the outside since curl still reports the request as "fully sent" — the
 * corruption is invisible until you look at the exact bytes. Always read config values that
 * end up in headers through this helper rather than the raw constant.
 */
function clean_config_value($value) {
    return is_string($value) ? trim($value) : $value;
}

function call_claude_raw($body) {
    $api_key = defined('ANTHROPIC_API_KEY') ? clean_config_value(ANTHROPIC_API_KEY) : '';
    if ($api_key === '' || strpos($api_key, 'sk-ant-xxxx') === 0) {
        return ['ok' => false, 'error' => 'Anthropic API key is not configured. Edit config/config.php.'];
    }

    $payload = json_encode($body);
    if ($payload === false) {
        return ['ok' => false, 'error' => 'Failed to encode request as JSON: ' . json_last_error_msg()];
    }

    $result = call_claude_via_curl($payload, $api_key);

    // If curl reports the exact "body arrived empty/malformed" symptom, it's most likely a
    // quirk of curl/libcurl on this specific host talking to Anthropic's Cloudflare-fronted
    // API — not a problem with the payload itself (already confirmed well-formed above).
    // Retry once using PHP's stream-based HTTP client, a completely different implementation
    // that sidesteps libcurl entirely, before giving up.
    if (!$result['ok'] && preg_match('/not valid JSON|zero-length|empty document|unexpected character/i', $result['error'])) {
        $fallback = call_claude_via_streams($payload, $api_key);
        if ($fallback['ok']) {
            return $fallback;
        }
        return [
            'ok' => false,
            'error' => $result['error'] . ' | Streams fallback also failed: ' . $fallback['error'],
            'debug' => $result['debug'] ?? null,
        ];
    }

    return $result;
}

/**
 * Sends the request via cURL. Captures a redacted wire-level verbose log for diagnostics.
 */
function call_claude_via_curl($payload, $api_key) {
    $payload_len = strlen($payload);
    $verbose = fopen('php://temp', 'w+');

    $ch = curl_init(ANTHROPIC_API_URL);
    $curl_opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        // Deliberately NOT forcing CURLOPT_HTTP_VERSION_1_1 here — Anthropic's API sits behind
        // Cloudflare, which strongly prefers HTTP/2. Forcing HTTP/1.1 was tried previously and
        // is suspected to be the cause of the body silently failing to arrive on some hosts
        // (curl reports "upload completely sent off" locally, but the origin sees zero bytes) —
        // a known failure mode with certain older libcurl/OpenSSL builds against H2-first edges.
        // Letting curl auto-negotiate the protocol via ALPN is the safer default.
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
            // Without this, cURL automatically adds "Expect: 100-continue" for POST bodies
            // over ~1KB. Some hosting providers' outbound proxies/firewalls mishandle that.
            // NOTE: do NOT also set a manual Content-Length header here — curl already sets
            // one correctly from CURLOPT_POSTFIELDS, and adding a second one creates a
            // duplicate header that some proxies mishandle.
            'Expect:',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_FRESH_CONNECT => true,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_VERBOSE => true,
        CURLOPT_STDERR => $verbose,
    ];
    if (defined('OUTBOUND_PROXY') && clean_config_value(OUTBOUND_PROXY) !== '') {
        $curl_opts[CURLOPT_PROXY] = clean_config_value(OUTBOUND_PROXY);
        if (defined('OUTBOUND_PROXY_AUTH') && clean_config_value(OUTBOUND_PROXY_AUTH) !== '') {
            $curl_opts[CURLOPT_PROXYUSERPWD] = clean_config_value(OUTBOUND_PROXY_AUTH);
        }
    }
    curl_setopt_array($ch, $curl_opts);
    $response = curl_exec($ch);
    $curl_err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    rewind($verbose);
    $verbose_log = stream_get_contents($verbose);
    fclose($verbose);
    // Redact the API key in case any of this ever surfaces in logs or error output.
    $verbose_log = str_replace($api_key, '[REDACTED]', $verbose_log);
    $verbose_snippet = substr($verbose_log, 0, 1500);

    if ($response === false) {
        return ['ok' => false, 'error' => 'Request to Claude failed: ' . $curl_err . ' (attempted to send ' . $payload_len . ' bytes)', 'debug' => $verbose_snippet];
    }

    $decoded = json_decode($response, true);

    if ($http_code >= 400) {
        $msg = $decoded['error']['message'] ?? ('HTTP ' . $http_code);
        return ['ok' => false, 'error' => 'Claude API error: ' . $msg . ' (we sent ' . $payload_len . ' bytes via cURL)', 'debug' => $verbose_snippet];
    }

    return ['ok' => true, 'text' => extract_claude_text($decoded), 'stop_reason' => $decoded['stop_reason'] ?? null];
}

/**
 * Sends the request via PHP's built-in stream-based HTTP client (no libcurl involved at all).
 * Used only as a fallback when cURL specifically reports a corrupted/empty body, to rule out
 * a libcurl-vs-host quirk.
 */
function call_claude_via_streams($payload, $api_key) {
    $http_context = [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n"
            . "x-api-key: " . $api_key . "\r\n"
            . "anthropic-version: 2023-06-01\r\n",
        'content' => $payload,
        'timeout' => 120,
        'ignore_errors' => true, // so we still get the response body on 4xx/5xx
    ];
    if (defined('OUTBOUND_PROXY') && clean_config_value(OUTBOUND_PROXY) !== '') {
        $http_context['proxy'] = 'tcp://' . clean_config_value(OUTBOUND_PROXY);
        $http_context['request_fulluri'] = true;
        if (defined('OUTBOUND_PROXY_AUTH') && clean_config_value(OUTBOUND_PROXY_AUTH) !== '') {
            $http_context['header'] .= "Proxy-Authorization: Basic " . base64_encode(clean_config_value(OUTBOUND_PROXY_AUTH)) . "\r\n";
        }
    }
    $context = stream_context_create([
        'http' => $http_context,
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $response = @file_get_contents(ANTHROPIC_API_URL, false, $context);

    if ($response === false) {
        $err = error_get_last();
        return ['ok' => false, 'error' => 'Streams request failed: ' . ($err['message'] ?? 'unknown error')];
    }

    $http_code = 0;
    if (isset($http_response_header[0]) && preg_match('/(\d{3})/', $http_response_header[0], $m)) {
        $http_code = (int)$m[1];
    }

    $decoded = json_decode($response, true);

    if ($http_code >= 400) {
        $msg = $decoded['error']['message'] ?? ('HTTP ' . $http_code);
        return ['ok' => false, 'error' => 'Claude API error: ' . $msg . ' (sent via streams fallback)'];
    }

    return ['ok' => true, 'text' => extract_claude_text($decoded), 'stop_reason' => $decoded['stop_reason'] ?? null];
}

function extract_claude_text($decoded) {
    $text = '';
    if (!empty($decoded['content']) && is_array($decoded['content'])) {
        foreach ($decoded['content'] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
    }
    return $text;
}

/**
 * Calls Claude and requires/parses a floorplan-JSON response (used by the analyze and
 * regenerate flows). Returns ['ok'=>bool, 'json'=>array|null, 'error'=>string|null]
 */
function call_claude($body) {
    $raw = call_claude_raw($body);
    if (!$raw['ok']) {
        return $raw;
    }

    if (($raw['stop_reason'] ?? null) === 'max_tokens') {
        return [
            'ok' => false,
            'error' => 'Claude\'s response was cut off before it finished (hit the max_tokens limit) — the floorplan may be too complex for the current token budget. Try a simpler image, fewer annotations at once, or increase max_tokens in claude_analyze_image()/claude_regenerate() in includes/functions.php.',
            'raw' => $raw['text'],
        ];
    }

    $text = trim($raw['text']);

    // Strip a leading/trailing markdown code fence if present (```json ... ``` or ``` ... ```),
    // even if there's other whitespace/text immediately around the fence markers themselves.
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```\s*$/', '', $text);
    $text = trim($text);

    // More robust than assuming the whole trimmed string is valid JSON: Claude sometimes adds
    // a sentence of preamble or a closing remark despite instructions not to. Find the first
    // "{" and the last "}" and try parsing just that slice — this survives extra text around
    // a single well-formed top-level JSON object, which is what our schema always is.
    $parsed = json_decode($text, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed)) {
        $first_brace = strpos($text, '{');
        $last_brace = strrpos($text, '}');
        if ($first_brace !== false && $last_brace !== false && $last_brace > $first_brace) {
            $candidate = substr($text, $first_brace, $last_brace - $first_brace + 1);
            $parsed = json_decode($candidate, true);
        }
    }

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed)) {
        return ['ok' => false, 'error' => 'Claude did not return valid JSON.', 'raw' => $raw['text']];
    }

    return ['ok' => true, 'json' => $parsed];
}

function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function next_version_number($project_id) {
    $db = get_db();
    $stmt = $db->prepare('SELECT COALESCE(MAX(version_number),0)+1 AS n FROM floorplan_versions WHERE project_id = ?');
    $stmt->bind_param('i', $project_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)$row['n'];
}

function user_owns_project($project_id, $user_id) {
    $db = get_db();
    $stmt = $db->prepare('SELECT id FROM projects WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $project_id, $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() !== null;
}

function thumb_rel_path($original_rel_path) {
    $dir = dirname($original_rel_path);
    $base = basename($original_rel_path);
    return ($dir !== '.' ? $dir . '/' : '') . 'thumb_' . $base;
}

/**
 * Resizes $source_path down to fit within $max_dim x $max_dim (preserving aspect ratio,
 * never upscaling) and writes the result to $dest_path in the same format as the source.
 * Returns false (leaving $dest_path unwritten) if GD or the source format isn't supported,
 * so callers can fall back to the original image.
 */
function create_thumbnail($source_path, $dest_path, $max_dim = 480) {
    if (!function_exists('imagecreatetruecolor')) return false;

    $info = @getimagesize($source_path);
    if (!$info) return false;
    [$width, $height, $type] = $info;

    if ($width <= $max_dim && $height <= $max_dim) {
        return copy($source_path, $dest_path);
    }

    $scale = min($max_dim / $width, $max_dim / $height);
    $new_width = max(1, (int)round($width * $scale));
    $new_height = max(1, (int)round($height * $scale));

    switch ($type) {
        case IMAGETYPE_JPEG:
            $src = imagecreatefromjpeg($source_path);
            break;
        case IMAGETYPE_PNG:
            $src = imagecreatefrompng($source_path);
            break;
        case IMAGETYPE_WEBP:
            if (!function_exists('imagecreatefromwebp')) return false;
            $src = imagecreatefromwebp($source_path);
            break;
        default:
            return false;
    }
    if (!$src) return false;

    $thumb = imagecreatetruecolor($new_width, $new_height);
    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
    }
    imagecopyresampled($thumb, $src, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

    switch ($type) {
        case IMAGETYPE_JPEG:
            $ok = imagejpeg($thumb, $dest_path, 85);
            break;
        case IMAGETYPE_PNG:
            $ok = imagepng($thumb, $dest_path);
            break;
        case IMAGETYPE_WEBP:
            $ok = function_exists('imagewebp') ? imagewebp($thumb, $dest_path, 85) : false;
            break;
        default:
            $ok = false;
    }
    imagedestroy($src);
    imagedestroy($thumb);
    return $ok;
}
