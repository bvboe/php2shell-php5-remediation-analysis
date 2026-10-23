<?php
/**
 * Supporting-document upload.
 *
 * This is the multipart/form-data (RFC1867) surface. It is present because a
 * realistic mortgage portal has one, and because RFC1867 parsing plus
 * session.upload_progress is the request-startup path that the php2shell
 * memory-disclosure variant abuses. Note that the engine parses the multipart
 * body BEFORE this file executes -- none of the validation below is reached.
 */
require_once __DIR__ . '/lib/bootstrap.php';
$u = require_role('customer');

$appId = isset($_GET['app']) ? (int)$_GET['app'] : (isset($_POST['app']) ? (int)$_POST['app'] : 0);
$st = db()->prepare("SELECT * FROM applications WHERE id = ? AND customer_id = ?");
$st->execute([$appId, $u['id']]);
$app = $st->fetch();
if (!$app) { http_response_code(404); layout_head('Not found');
    echo '<div class="card"><h1>Not found</h1></div>'; layout_foot(); exit; }

$err = ''; $ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        $err = 'Your session expired. Please try again.';
    } elseif (!isset($_FILES['doc']) || $_FILES['doc']['error'] !== UPLOAD_ERR_OK) {
        $err = 'Upload failed. Please attach a file under 8 MB.';
    } else {
        $f = $_FILES['doc'];
        $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = $fi->file($f['tmp_name']);
        if (!isset($allowed[$mime])) {
            $err = 'Only PDF, JPEG or PNG documents are accepted.';
        } else {
            $stored = bin2hex(openssl_random_pseudo_bytes(12)) . '.' . $allowed[$mime];
            if (move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $stored)) {
                $ins = db()->prepare("INSERT INTO documents (application_id,orig_name,stored,bytes,uploaded_at) VALUES (?,?,?,?,?)");
                $ins->execute([$app['id'], $f['name'], $stored, (int)$f['size'], date('c')]);
                $ok = 'Uploaded ' . $f['name'] . '.';
            } else {
                $err = 'Could not store the document.';
            }
        }
    }
}

$docs = db()->prepare("SELECT * FROM documents WHERE application_id = ? ORDER BY id DESC");
$docs->execute([$app['id']]);
$docs = $docs->fetchAll();

layout_head('Upload documents');
?>
<div class="card">
  <h1>Supporting documents</h1>
  <h2>NBS-<?php echo str_pad($app['id'], 5, '0', STR_PAD_LEFT); ?> &middot; <?php echo e($app['property']); ?></h2>
  <?php if ($err): ?><div class="err"><?php echo e($err); ?></div><p></p><?php endif; ?>
  <?php if ($ok):  ?><div class="ok"><?php echo e($ok); ?></div><p></p><?php endif; ?>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="app" value="<?php echo (int)$app['id']; ?>">
    <label for="doc">Payslip, bank statement or ID (PDF/JPEG/PNG, max 8 MB)</label>
    <input id="doc" name="doc" type="file" required>
    <p></p><button type="submit">Upload</button>
  </form>
  <?php if ($docs): ?>
  <h2 style="margin-top:22px">On file</h2>
  <table><tr><th>Document</th><th>Size</th><th>Uploaded</th></tr>
  <?php foreach ($docs as $d): ?>
    <tr><td><?php echo e($d['orig_name']); ?></td>
        <td><?php echo number_format($d['bytes'] / 1024, 1); ?> KB</td>
        <td class="muted"><?php echo e($d['uploaded_at']); ?></td></tr>
  <?php endforeach; ?>
  </table>
  <?php endif; ?>
  <p><a href="/customer.php">Back to your applications</a></p>
</div>
<?php layout_foot();
