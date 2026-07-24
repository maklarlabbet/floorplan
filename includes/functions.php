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
  "notes": [ { "x": 0, "y": 0, "text": "free text note" } ]
}

Rules:
- "orientation" is "horizontal" or "vertical" describing which wall the door/window sits on.
- Keep coordinates internally consistent (walls form closed room boundaries where the drawing implies enclosed rooms).
- Use walls.thickness in canvas units (typically 4-10).
- Room polygons should be closed shapes made of the same coordinates as adjoining walls, so rooms and walls line up visually.
- Include a dimension line for at least the overall footprint width and height, and for any room whose size the source clearly implies.
- If the source image is a rough hand sketch, infer sensible right angles and straightened walls — the goal is a clean, professional floorplan, not a literal trace of wobbly lines.
- If information is ambiguous or missing (e.g. a room label is unreadable), make a reasonable assumption and note it in "notes".
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
 * Low-level call to the Anthropic Messages API. Returns ['ok'=>bool, 'json'=>array|null, 'error'=>string|null]
 */
function call_claude($body) {
    if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '' || strpos(ANTHROPIC_API_KEY, 'sk-ant-xxxx') === 0) {
        return ['ok' => false, 'error' => 'Anthropic API key is not configured. Edit config/config.php.'];
    }

    $payload = json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($payload === false) {
        return ['ok' => false, 'error' => 'Failed to encode request for Claude: ' . json_last_error_msg()];
    }

    $ch = curl_init(ANTHROPIC_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 120,
    ]);
    $response = curl_exec($ch);
    $curl_err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => 'Request to Claude failed: ' . $curl_err];
    }

    $decoded = json_decode($response, true);

    if ($http_code >= 400) {
        $msg = $decoded['error']['message'] ?? ('HTTP ' . $http_code);
        return ['ok' => false, 'error' => 'Claude API error: ' . $msg];
    }

    $text = '';
    if (!empty($decoded['content']) && is_array($decoded['content'])) {
        foreach ($decoded['content'] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
    }

    $clean = trim($text);
    $clean = preg_replace('/^```(json)?/i', '', $clean);
    $clean = preg_replace('/```$/', '', $clean);
    $clean = trim($clean);

    $parsed = json_decode($clean, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed)) {
        return ['ok' => false, 'error' => 'Claude did not return valid JSON.', 'raw' => $text];
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
