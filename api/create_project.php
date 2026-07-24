<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'error' => 'POST required'], 405);

$uid = current_user_id();
$name = trim($_POST['name'] ?? '');
if ($name === '') json_response(['ok' => false, 'error' => 'Project name is required.'], 400);

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'error' => 'Please choose an image file.'], 400);
}

$file = $_FILES['image'];
if ($file['size'] > MAX_UPLOAD_BYTES) {
    json_response(['ok' => false, 'error' => 'Image is too large (max 8MB).'], 400);
}

$allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!isset($allowed[$mime])) {
    json_response(['ok' => false, 'error' => 'Unsupported image type. Use PNG, JPG, or WEBP.'], 400);
}

$db = get_db();
$stmt = $db->prepare('INSERT INTO projects (user_id, name) VALUES (?, ?)');
$stmt->bind_param('is', $uid, $name);
$stmt->execute();
$project_id = $stmt->insert_id;

$project_dir = UPLOAD_DIR . '/' . $project_id;
if (!is_dir($project_dir)) mkdir($project_dir, 0755, true);

$ext = $allowed[$mime];
$filename = 'original_' . time() . '.' . $ext;
$dest = $project_dir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    json_response(['ok' => false, 'error' => 'Failed to save uploaded image.'], 500);
}

$rel_path = $project_id . '/' . $filename;
$version_number = 1;

$stmt = $db->prepare('INSERT INTO floorplan_versions (project_id, version_number, source_type, status, image_path) VALUES (?, ?, "upload", "processing", ?)');
$stmt->bind_param('iis', $project_id, $version_number, $rel_path);
$stmt->execute();
$version_id = $stmt->insert_id;

json_response(['ok' => true, 'project_id' => $project_id, 'version_id' => $version_id]);
