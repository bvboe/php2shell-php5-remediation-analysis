<?php
header('Content-Type: text/plain');
echo "runtime = " . (defined('HHVM_VERSION') ? 'HHVM ' . HHVM_VERSION : 'PHP ' . PHP_VERSION) . "\n";
foreach (array('memory_limit',
               'hhvm.server.request_memory_max_bytes',
               'hhvm.server.request_memory_oom_kill_bytes') as $k) {
    $v = ini_get($k);
    echo str_pad($k, 44) . " = " . var_export($v, true) . "\n";
}
echo "\n-- allocating in 8MB steps, reporting tracked usage --\n";
$buf = array();
for ($mb = 8; $mb <= 256; $mb += 8) {
    for ($i = 0; $i < 8; $i++) { $buf[] = str_repeat('x', 1024 * 1024); }
    printf("  allocated %3dMB   memory_get_usage=%6.1fMB\n",
        $mb, memory_get_usage(true) / 1048576);
    flush();
}
echo "REACHED 256MB WITHOUT ENFORCEMENT\n";
