<?php
require_once __DIR__ . '/db.php';

function current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

function require_login() {
    if (!current_user_id()) {
        header('Location: login.php');
        exit;
    }
}

function require_login_api() {
    if (!current_user_id()) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        exit;
    }
}

function register_user($username, $email, $password) {
    $db = get_db();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $username, $email, $hash);
    if (!$stmt->execute()) {
        return ['ok' => false, 'error' => $stmt->error === '' ? 'Could not register. Username or email may already be in use.' : $stmt->error];
    }
    return ['ok' => true, 'id' => $stmt->insert_id];
}

function attempt_login($username, $password) {
    $db = get_db();
    $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->bind_param('ss', $username, $username);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    if ($row && password_verify($password, $row['password_hash'])) {
        $_SESSION['user_id'] = $row['id'];
        return true;
    }
    return false;
}
