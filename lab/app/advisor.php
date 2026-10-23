<?php
require_once __DIR__ . '/lib/bootstrap.php';
$u = require_role('advisor');

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
    $id = (int)$_POST['app'];
    $decision = $_POST['decision'] === 'approved' ? 'approved' : 'declined';
    $st = db()->prepare("UPDATE applications SET status = ?, decided_by = ? WHERE id = ?");
    $st->execute([$decision, $u['id'], $id]);
    $msg = 'Application NBS-' . str_pad($id, 5, '0', STR_PAD_LEFT) . ' marked ' . $decision . '.';
}

$rows = db()->query("
    SELECT a.*, u.full_name customer
    FROM applications a JOIN users u ON u.id = a.customer_id
    ORDER BY CASE a.status WHEN 'review' THEN 0 ELSE 1 END, a.id
")->fetchAll();

layout_head('Adviser desk');
?>
<div class="card">
  <h1>Adviser desk</h1>
  <h2>All mortgage applications</h2>
  <?php if ($msg): ?><div class="ok"><?php echo e($msg); ?></div><p></p><?php endif; ?>
  <table>
    <tr><th>Ref</th><th>Customer</th><th>Property</th><th>Amount</th><th>Status</th><th>Decision</th></tr>
    <?php foreach ($rows as $a): ?>
      <tr>
        <td>NBS-<?php echo str_pad($a['id'], 5, '0', STR_PAD_LEFT); ?></td>
        <td><?php echo e($a['customer']); ?></td>
        <td><?php echo e($a['property']); ?></td>
        <td><?php echo money($a['amount_pence']); ?></td>
        <td><span class="pill p-<?php echo e($a['status']); ?>"><?php echo e(ucfirst($a['status'])); ?></span></td>
        <td>
          <?php if ($a['status'] === 'review'): ?>
          <form method="post" style="display:flex;gap:6px">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="app" value="<?php echo (int)$a['id']; ?>">
            <button name="decision" value="approved">Approve</button>
            <button name="decision" value="declined" style="background:#8c3b3b">Decline</button>
          </form>
          <?php else: ?><span class="muted">Settled</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php layout_foot();
