<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login_api();

$uid = current_user_id();
$input = json_decode(file_get_contents('php://input'), true);

$project_id = (int)($input['project_id'] ?? 0);
$base_version_id = (int)($input['base_version_id'] ?? 0);
$annotations = $input['annotations'] ?? null; // array of {type, ...}

if (!$project_id || !user_owns_project($project_id, $uid)) {
    json_response(['ok' => false, 'error' => 'Not found'], 404);
}
if (!$base_version_id || !$annotations) {
    json_response(['ok' => false, 'error' => 'Missing base_version_id or annotations'], 400);
}

$db = get_db();
$stmt = $db->prepare('SELECT floorplan_json FROM floorplan_versions WHERE id = ? AND project_id = ?');
$stmt->bind_param('ii', $base_version_id, $project_id);
$stmt->execute();
$base = $stmt->get_result()->fetch_assoc();

if (!$base || !$base['floorplan_json']) {
    json_response(['ok' => false, 'error' => 'Base version has no floorplan data yet.'], 400);
}

// Turn the raw annotation data (strokes + text notes with coordinates) into a readable summary for Claude.
$summary_lines = [];
foreach ($annotations as $a) {
    if ($a['type'] === 'stroke') {
        $xs = array_column($a['points'], 'x');
        $ys = array_column($a['points'], 'y');
        $summary_lines[] = sprintf(
            '- Freehand mark (color %s) spanning roughly from (%d,%d) to (%d,%d) [bounding box x:%d-%d, y:%d-%d]',
            $a['color'] ?? '#e85d2f', $a['points'][0]['x'], $a['points'][0]['y'],
            end($a['points'])['x'], end($a['points'])['y'],
            min($xs), max($xs), min($ys), max($ys)
        );
    } elseif ($a['type'] === 'note') {
        $summary_lines[] = sprintf('- Text note at (%d,%d): "%s"', $a['x'], $a['y'], $a['text']);
    }
}
$summary = implode("\n", $summary_lines);
if ($summary === '') $summary = 'No specific marks provided.';

$result = claude_regenerate($base['floorplan_json'], $summary);

if (!$result['ok']) {
    json_response(['ok' => false, 'error' => $result['error']], 502);
}

$new_version_number = next_version_number($project_id);
$json_str = json_encode($result['json']);
$annotation_str = json_encode($annotations);

$stmt = $db->prepare('INSERT INTO floorplan_versions (project_id, version_number, source_type, status, floorplan_json, annotation_json) VALUES (?, ?, "ai_generated", "ready", ?, ?)');
$stmt->bind_param('iiss', $project_id, $new_version_number, $json_str, $annotation_str);
$stmt->execute();
$new_version_id = $stmt->insert_id;

$stmt = $db->prepare('UPDATE projects SET updated_at = NOW() WHERE id = ?');
$stmt->bind_param('i', $project_id);
$stmt->execute();

json_response(['ok' => true, 'version_id' => $new_version_id, 'version_number' => $new_version_number, 'floorplan' => $result['json']]);
