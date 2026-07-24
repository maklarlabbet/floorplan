<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login_api();

$uid = current_user_id();
$version_id = (int)($_POST['version_id'] ?? $_GET['version_id'] ?? 0);
if (!$version_id) json_response(['ok' => false, 'error' => 'Missing version_id'], 400);

$db = get_db();
$stmt = $db->prepare('SELECT v.*, p.user_id FROM floorplan_versions v JOIN projects p ON p.id = v.project_id WHERE v.id = ?');
$stmt->bind_param('i', $version_id);
$stmt->execute();
$version = $stmt->get_result()->fetch_assoc();

if (!$version || (int)$version['user_id'] !== (int)$uid) {
    json_response(['ok' => false, 'error' => 'Not found'], 404);
}

if (empty($version['image_path'])) {
    json_response(['ok' => false, 'error' => 'This version has no source image.'], 400);
}

$full_path = UPLOAD_DIR . '/' . $version['image_path'];
if (!file_exists($full_path)) {
    json_response(['ok' => false, 'error' => 'Source image file missing on server.'], 404);
}

$ext = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));
$mime_map = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
$mime = $mime_map[$ext] ?? 'image/png';

$result = claude_analyze_image($full_path, $mime);

if (!$result['ok']) {
    $stmt = $db->prepare('UPDATE floorplan_versions SET status = "failed", error_message = ? WHERE id = ?');
    $stmt->bind_param('si', $result['error'], $version_id);
    $stmt->execute();
    json_response(['ok' => false, 'error' => $result['error']], 502);
}

$json_str = json_encode($result['json']);
$stmt = $db->prepare('UPDATE floorplan_versions SET status = "ready", floorplan_json = ? WHERE id = ?');
$stmt->bind_param('si', $json_str, $version_id);
$stmt->execute();

$stmt = $db->prepare('UPDATE projects SET updated_at = NOW() WHERE id = ?');
$stmt->bind_param('i', $version['project_id']);
$stmt->execute();

json_response(['ok' => true, 'floorplan' => $result['json']]);
