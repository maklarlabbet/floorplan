<?php
require_once __DIR__ . '/includes/auth.php';

if (current_user_id()) { header('Location: index.php'); exit; }

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $email === '' || strlen($password) < 6) {
        $error = 'Please fill all fields. Password must be at least 6 characters.';
    } else {
        $result = register_user($username, $email, $password);
        if ($result['ok']) {
            attempt_login($username, $password);
            header('Location: index.php');
            exit;
        } else {
            $error = $result['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Create account · Floorplan Studio</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-page">
  <div class="auth-card">
    <div class="brand"><span class="brand-mark">⌐</span> Floorplan Studio</div>
    <h1>Create your account</h1>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
      <label>Username
        <input type="text" name="username" required maxlength="60" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
      </label>
      <label>Email
        <input type="email" name="email" required maxlength="150" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </label>
      <label>Password
        <input type="password" name="password" required minlength="6">
      </label>
      <button type="submit" class="btn btn-primary">Create account</button>
    </form>
    <p class="auth-switch">Already have an account? <a href="login.php">Sign in</a></p>
  </div>
</body>
</html>
