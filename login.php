<?php
require_once __DIR__ . '/includes/auth.php';

if (current_user_id()) { header('Location: index.php'); exit; }

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (attempt_login($username, $password)) {
        header('Location: index.php');
        exit;
    }
    $error = 'Invalid username/email or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sign in · Floorplan Studio</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-page">
  <div class="auth-card">
    <div class="brand"><span class="brand-mark">⌐</span> Floorplan Studio</div>
    <h1>Sign in</h1>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
      <label>Username or email
        <input type="text" name="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
      </label>
      <label>Password
        <input type="password" name="password" required>
      </label>
      <button type="submit" class="btn btn-primary">Sign in</button>
    </form>
    <p class="auth-switch">No account yet? <a href="register.php">Create one</a></p>
  </div>
</body>
</html>
