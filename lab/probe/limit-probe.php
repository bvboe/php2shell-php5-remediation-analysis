<?php
// Bounded test: set memory_limit to 32M, then try to allocate ~64M.
// Enforcing runtime -> fatal. Non-enforcing runtime -> prints SUCCESS.
header('Content-Type: text/plain');
ini_set('memory_limit', '32M');
echo "reported memory_limit=" . ini_get('memory_limit') . "\n";
$buf = array();
for ($i = 0; $i < 64; $i++) { $buf[] = str_repeat('x', 1024 * 1024); }
echo "SUCCESS: allocated ~64MB despite a 32M memory_limit (limit NOT enforced)\n";
