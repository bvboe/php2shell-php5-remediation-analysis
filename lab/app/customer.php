<?php
require_once __DIR__ . '/lib/bootstrap.php';
$u = require_role('customer');

$st = db()->prepare("SELECT * FROM applications WHERE customer_id = ? ORDER BY id");
$st->execute([$u['id']]);
$apps = $st->fetchAll();

layout_head('Your mortgage');
?>
<div class="card">
  <h1>Welcome back, <?php echo e($u['full_name']); ?></h1>
  <h2>Your mortgage applications</h2>
  <?php if (!$apps): ?>
    <p class="muted">You have no applications on file.</p>
  <?php else: ?>
  <table>
    <tr><th>Ref</th><th>Property</th><th>Amount</th><th>Term</th><th>Status</th><th>Documents</th></tr>
    <?php foreach ($apps as $a):
        $d = db()->prepare("SELECT COUNT(*) c FROM documents WHERE application_id = ?");
        $d->execute([$a['id']]);
        $n = $d->fetch(); ?>
      <tr>
        <td>NBS-<?php echo str_pad($a['id'], 5, '0', STR_PAD_LEFT); ?></td>
        <td><?php echo e($a['property']); ?></td>
        <td><?php echo money($a['amount_pence']); ?></td>
        <td><?php echo (int)$a['term_years']; ?> yrs</td>
        <td><span class="pill p-<?php echo e($a['status']); ?>"><?php echo e(ucfirst($a['status'])); ?></span></td>
        <td><?php echo (int)$n['c']; ?> &nbsp;<a href="/upload.php?app=<?php echo (int)$a['id']; ?>">Upload</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php layout_foot();
