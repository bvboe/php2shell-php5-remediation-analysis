<?php
/**
 * Northwind Building Society - mortgage portal
 * Target runtime: PHP 5.6.40 (mod_php). Deliberately written to 5.6-era
 * idioms: no scalar type hints, no return types, no null coalescing.
 */
define('APP_NAME', 'Northwind Building Society');
define('DATA_DIR', '/var/www/data');
define('UPLOAD_DIR', '/var/www/uploads');

ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);
session_name('PHPSESSID');
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function e($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function money($pence) {
    return '£' . number_format($pence / 100, 2);
}

function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(openssl_random_pseudo_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check($t) {
    return !empty($_SESSION['csrf']) && is_string($t) && hash_equals($_SESSION['csrf'], $t);
}

function redirect($to) { header('Location: ' . $to); exit; }

function layout_head($title) {
    $t = e($title);
    $app = e(APP_NAME);
    echo <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><title>$t - $app</title>
<style>
 body{font:15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;margin:0;background:#f4f6f8;color:#16202a}
 header{background:#0b3d5a;color:#fff;padding:14px 22px;display:flex;justify-content:space-between;align-items:center}
 header a{color:#cfe3f5;text-decoration:none;font-size:14px}
 .wrap{max-width:900px;margin:26px auto;padding:0 18px}
 .card{background:#fff;border:1px solid #dde3e9;border-radius:8px;padding:20px;margin-bottom:18px}
 h1{font-size:20px;margin:0 0 4px} h2{font-size:16px;margin:0 0 14px;color:#33475b}
 table{border-collapse:collapse;width:100%} th,td{text-align:left;padding:9px 8px;border-bottom:1px solid #eceff2}
 th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#6b7c8d}
 input,select{padding:9px;border:1px solid #c6ced6;border-radius:5px;font-size:14px;width:100%;box-sizing:border-box}
 label{display:block;font-size:13px;color:#4a5b6c;margin:12px 0 5px}
 button{background:#0b6ec7;color:#fff;border:0;border-radius:5px;padding:10px 16px;font-size:14px;cursor:pointer}
 .muted{color:#6b7c8d;font-size:13px} .err{background:#fdecec;border:1px solid #f5b5b5;color:#962020;padding:10px;border-radius:5px}
 .ok{background:#e9f7ee;border:1px solid #a8dcbb;color:#1d6b38;padding:10px;border-radius:5px}
 .pill{display:inline-block;padding:2px 9px;border-radius:11px;font-size:12px}
 .p-review{background:#fff3d6;color:#8a6100}.p-approved{background:#e2f5e9;color:#1d6b38}.p-declined{background:#fdecec;color:#962020}
</style></head><body>
<header><strong>$app</strong><span>
HTML;
    if (current_user()) {
        $u = current_user();
        echo e($u['full_name']) . ' (' . e($u['role']) . ') &nbsp; <a href="/logout.php">Sign out</a>';
    }
    echo '</span></header><div class="wrap">';
}

function layout_foot() {
    echo '<p class="muted">Lab build. PHP ' . e(PHP_VERSION) . ' / SAPI ' . e(php_sapi_name()) . '</p>';
    echo '</div></body></html>';
}
