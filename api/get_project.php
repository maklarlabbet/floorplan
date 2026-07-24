<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login_api();

$uid = current_user_id();
$project_id = (int)($_GET['project_id'] ?? 0);
if (!$project_id || !user_owns_project($project_id, $uid)) {
    json_response(['ok' => false, 'error' => 'Not found'], 404);
}

$db = get_db();

$stmt = $db->prepare('SELECT id, name FROM projects WHERE id = ?');
$stmt->bind_param('i', $project_id);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();

$stmt = $db->prepare('SELECT id, version_number, source_type, status, image_path, floorplan_json, error_message, created_at
                       FROM floorplan_versions WHERE project_id = ? ORDER BY version_number ASC');
$stmt->bind_param('i', $project_id);
$stmt->execute();
$versions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($versions as &$v) {
    if ($v['floorplan_json']) {
        $v['floorplan'] = json_decode($v['floorplan_json'], true);
    }
    unset($v['floorplan_json']);
    if ($v['image_path']) {
        $v['image_url'] = UPLOAD_URL_BASE . '/' . $v['image_path'];
    }
}

json_response(['ok' => true, 'project' => $project, 'versions' => $versions]);
