<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$uid = current_user_id();
$project_id = (int)($_GET['project_id'] ?? 0);
if (!$project_id || !user_owns_project($project_id, $uid)) {
    header('Location: index.php');
    exit;
}

$db = get_db();
$stmt = $db->prepare('SELECT id, name FROM projects WHERE id = ?');
$stmt->bind_param('i', $project_id);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($project['name']) ?> · Floorplan Studio</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="editor-body" data-project-id="<?= (int)$project_id ?>">
<?php include __DIR__ . '/includes/nav.php'; ?>

<div class="editor-layout">

  <aside class="sidebar">
    <div class="sidebar-header">
      <p class="eyebrow">Project</p>
      <h2 id="project-title"><?= htmlspecialchars($project['name']) ?></h2>
    </div>

    <div class="sidebar-section">
      <p class="eyebrow">Versions</p>
      <div id="version-list" class="version-list"><!-- populated by JS --></div>
    </div>

    <div class="sidebar-section">
      <p class="eyebrow">Source Image</p>
      <div id="source-thumb" class="source-thumb"></div>
    </div>
  </aside>

  <main class="canvas-area">

    <div class="toolbar" id="toolbar">
      <div class="tool-group">
        <button class="tool-btn active" data-tool="pen" title="Draw / mark changes">✎ Pen</button>
        <button class="tool-btn" data-tool="note" title="Add a text note">💬 Note</button>
        <button class="tool-btn" data-tool="pan" title="Pan / select">🖐 Select</button>
      </div>
      <div class="tool-group">
        <label class="color-swatch" style="--swatch:#e85d2f;"><input type="radio" name="color" value="#e85d2f" checked></label>
        <label class="color-swatch" style="--swatch:#1d2b3a;"><input type="radio" name="color" value="#1d2b3a"></label>
        <label class="color-swatch" style="--swatch:#2f6f4f;"><input type="radio" name="color" value="#2f6f4f"></label>
      </div>
      <div class="tool-group">
        <button class="btn btn-ghost" id="btn-undo-mark">Undo mark</button>
        <button class="btn btn-ghost" id="btn-clear-marks">Clear marks</button>
      </div>
      <div class="tool-group tool-group-right">
        <button class="btn btn-ghost" id="btn-download">Download SVG</button>
        <button class="btn btn-primary" id="btn-regenerate">Apply changes with Claude</button>
      </div>
    </div>

    <div class="stage-wrap">
      <div id="loading-overlay" class="loading-overlay" hidden>
        <div class="spinner"></div>
        <p id="loading-text">Claude is drafting your floorplan…</p>
      </div>
      <div id="empty-overlay" class="loading-overlay" hidden>
        <p>No floorplan data yet for this version.</p>
      </div>
      <div id="stage" class="stage">
        <svg id="floorplan-svg" viewBox="0 0 1000 700" preserveAspectRatio="xMidYMid meet"></svg>
        <canvas id="annotation-canvas"></canvas>
      </div>
    </div>

    <p class="hint">Draw over walls to remove them, draw a loop to suggest a new room, or use the note tool to describe a change (e.g. "make this bedroom bigger"). Then click <strong>Apply changes with Claude</strong>.</p>
  </main>
</div>

<!-- Note input popup -->
<div class="note-popup" id="note-popup" hidden>
  <textarea id="note-text" placeholder="Describe the change…" rows="2"></textarea>
  <div class="note-popup-actions">
    <button class="btn btn-ghost" id="note-cancel">Cancel</button>
    <button class="btn btn-primary" id="note-save">Add</button>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="assets/js/floorplan-renderer.js"></script>
<script src="assets/js/draw-tool.js"></script>
<script src="assets/js/editor.js"></script>
</body>
</html>
