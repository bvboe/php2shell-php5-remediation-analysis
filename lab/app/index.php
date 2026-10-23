<?php
require_once __DIR__ . '/lib/bootstrap.php';

$u = current_user();
if ($u) redirect($u['role'] === 'advisor' ? '/advisor.php' : '/customer.php');

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = isset($_POST['username']) ? trim($_POST['username']) : '';
    $pass = isset($_POST['password']) ? $_POST['password'] : '';
    if (!csrf_check(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        $err = 'Your session expired. Please try again.';
    } else {
        $found = attempt_login($user, $pass);
        if ($found) redirect($found['role'] === 'advisor' ? '/advisor.php' : '/customer.php');
        $err = 'Incorrect username or password.';
    }
}

layout_head('Sign in');
?>
<div class="card" style="max-width:420px;margin:40px auto">
  <h1>Sign in</h1>
  <h2>Mortgage portal</h2>
  <?php if ($err): ?><div class="err"><?php echo e($err); ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <label for="username">Username</label>
    <input id="username" name="username" required autofocus>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required>
    <p></p><button type="submit">Sign in</button>
  </form>
  <p class="muted">Customers and advisers both sign in here. Access is
  determined by your account role.</p>
</div>
<?php layout_foot();
