<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$db = get_db();
$uid = current_user_id();

$stmt = $db->prepare('SELECT p.id, p.name, p.updated_at,
                        (SELECT COUNT(*) FROM floorplan_versions v WHERE v.project_id = p.id) AS version_count
                       FROM projects p WHERE p.user_id = ? ORDER BY p.updated_at DESC');
$stmt->bind_param('i', $uid);
$stmt->execute();
$projects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Floorplan Studio</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>

<main class="page">
  <div class="page-header">
    <div>
      <p class="eyebrow">Title Block</p>
      <h1>Your Projects</h1>
    </div>
    <button class="btn btn-primary" id="btn-new-project">+ New Floorplan</button>
  </div>

  <?php if (empty($projects)): ?>
    <div class="empty-state">
      <p>No projects yet. Upload a hand-drawn or digital floorplan to get started — Claude will redraw it as a clean, editable floorplan.</p>
      <button class="btn btn-primary" id="btn-new-project-2">Upload your first floorplan</button>
    </div>
  <?php else: ?>
    <div class="project-grid">
      <?php foreach ($projects as $p): ?>
        <a class="project-card" href="editor.php?project_id=<?= (int)$p['id'] ?>">
          <div class="project-card-thumb">⌂</div>
          <div class="project-card-body">
            <h3><?= htmlspecialchars($p['name']) ?></h3>
            <p><?= (int)$p['version_count'] ?> version<?= $p['version_count'] == 1 ? '' : 's' ?> · updated <?= date('M j, Y', strtotime($p['updated_at'])) ?></p>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>

<!-- New project modal -->
<div class="modal" id="new-project-modal" hidden>
  <div class="modal-card">
    <h2>New floorplan</h2>
    <form id="new-project-form">
      <label>Project name
        <input type="text" name="name" id="project-name" required placeholder="e.g. Main Street Apartment">
      </label>
      <label>Floorplan image (hand-drawn or digital)
        <input type="file" name="image" id="project-image" accept="image/png,image/jpeg,image/webp" required>
      </label>
      <div class="upload-preview" id="upload-preview" hidden><img id="upload-preview-img" alt="preview"></div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" id="btn-cancel-new">Cancel</button>
        <button type="submit" class="btn btn-primary" id="btn-submit-new">Upload &amp; Analyze</button>
      </div>
      <p class="form-note" id="upload-status"></p>
    </form>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
