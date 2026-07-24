<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login_api();

$uid = current_user_id();
$input = json_decode(file_get_contents('php://input'), true);
$project_id = (int)($input['project_id'] ?? 0);

if (!$project_id || !user_owns_project($project_id, $uid)) {
    json_response(['ok' => false, 'error' => 'Not found'], 404);
}

$db = get_db();
$stmt = $db->prepare('DELETE FROM projects WHERE id = ?');
$stmt->bind_param('i', $project_id);
$stmt->execute();

// Best-effort cleanup of uploaded files
$dir = UPLOAD_DIR . '/' . $project_id;
if (is_dir($dir)) {
    foreach (glob($dir . '/*') as $f) { @unlink($f); }
    @rmdir($dir);
}

json_response(['ok' => true]);
