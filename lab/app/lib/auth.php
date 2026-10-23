<?php
function current_user() {
    if (empty($_SESSION['uid'])) return null;
    static $cache = null;
    if ($cache !== null && $cache['id'] == $_SESSION['uid']) return $cache;
    $st = db()->prepare("SELECT id,username,role,full_name FROM users WHERE id = ?");
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch();
    $cache = $u ? $u : null;
    return $cache;
}

function attempt_login($username, $password) {
    $st = db()->prepare("SELECT * FROM users WHERE username = ?");
    $st->execute([$username]);
    $u = $st->fetch();
    if (!$u || !password_verify($password, $u['pw_hash'])) return false;
    // Rotate the session id on privilege change.
    session_regenerate_id(true);
    $_SESSION['uid']  = $u['id'];
    $_SESSION['role'] = $u['role'];
    return $u;
}

function require_role($role) {
    $u = current_user();
    if (!$u) redirect('/index.php');
    if ($u['role'] !== $role) {
        http_response_code(403);
        layout_head('Forbidden');
        echo '<div class="card"><h1>Forbidden</h1><p class="muted">Your account does not have access to this area.</p></div>';
        layout_foot();
        exit;
    }
    return $u;
}
