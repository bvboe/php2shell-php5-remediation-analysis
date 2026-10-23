<?php
// Do shutdown handlers still run for ORDINARY fatals on the patched build,
// or has the patch killed them for every fatal?
register_shutdown_function(function () {
    echo "\nSHUTDOWN-HANDLER-RAN\n";
});
$kind = isset($_GET['k']) ? $_GET['k'] : 'undef';
header('Content-Type: text/plain');
echo "kind=$kind\n";
if ($kind === 'undef')     { this_function_does_not_exist(); }
if ($kind === 'uncaught')  { throw new RuntimeException('boom'); }
if ($kind === 'oom')       { ini_set('memory_limit','32M'); $a=array(); while(true){$a[]=str_repeat('x',1048576);} }
