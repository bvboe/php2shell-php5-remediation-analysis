<?php
// SAFE probe: reports worker identity, and separately exhausts memory_limit
// with ordinary string allocation. No exploit content.
$a = isset($_GET['a']) ? $_GET['a'] : 'id';
if ($a === 'id') {
    header('Content-Type: text/plain');
    echo "PID=" . getmypid()
       . " RUNTIME=" . (defined('HHVM_VERSION') ? 'HHVM-' . HHVM_VERSION : 'PHP-' . PHP_VERSION)
       . "\n";
    exit;
}
if ($a === 'oom') {
    register_shutdown_function(function () {
        // If this is reachable, the engine kept running PHP after the fatal.
        @file_put_contents('/tmp/shutdown-ran', date('c') . " pid=" . getmypid() . "\n", FILE_APPEND);
    });
    ini_set('memory_limit', '32M');
    $buf = array();
    while (true) { $buf[] = str_repeat('x', 1024 * 1024); }
}
