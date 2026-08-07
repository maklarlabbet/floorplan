<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login_api();

$uid = current_user_id();
$input = json_decode(file_get_contents('php://input'), true);

$project_id = (int)($input['project_id'] ?? 0);
$base_version_id = (int)($input['base_version_id'] ?? 0);
$floorplan = $input['floorplan'] ?? null;

if (!$project_id || !user_owns_project($project_id, $uid)) {
    json_response(['ok' => false, 'error' => 'Not found'], 404);
}
if (!$base_version_id || !$floorplan) {
    json_response(['ok' => false, 'error' => 'Missing base_version_id or floorplan'], 400);
}

$db = get_db();
$stmt = $db->prepare('SELECT id FROM floorplan_versions WHERE id = ? AND project_id = ?');
$stmt->bind_param('ii', $base_version_id, $project_id);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    json_response(['ok' => false, 'error' => 'Base version not found'], 400);
}

$new_version_number = next_version_number($project_id);
$json_str = json_encode($floorplan);

$stmt = $db->prepare('INSERT INTO floorplan_versions (project_id, version_number, source_type, status, floorplan_json) VALUES (?, ?, "manual_edit", "ready", ?)');
$stmt->bind_param('iis', $project_id, $new_version_number, $json_str);
$stmt->execute();
$new_version_id = $stmt->insert_id;

$stmt = $db->prepare('UPDATE projects SET updated_at = NOW() WHERE id = ?');
$stmt->bind_param('i', $project_id);
$stmt->execute();

json_response(['ok' => true, 'version_id' => $new_version_id, 'version_number' => $new_version_number, 'floorplan' => $floorplan]);
