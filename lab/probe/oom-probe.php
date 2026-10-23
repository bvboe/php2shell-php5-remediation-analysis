<?php
/**
 * php2shell architectural probe -- SAFE, NO EXPLOIT CONTENT.
 *
 * The php2shell bug class (CVE-2026-109618 / CVE-2026-109619) requires that,
 * after a fatal out-of-memory error destroys the request heap, the engine
 * KEEPS RUNNING CODE -- shutdown handlers, cleanup, the next request on the
 * same worker -- while engine globals still point into that freed heap.
 *
 * So the single question that decides whether a runtime has the vulnerable
 * SHAPE is: after OOM, does the engine run more PHP code, or does it stop?
 *
 * This probe answers exactly that and nothing more. It allocates ordinary
 * strings until memory_limit is reached. No heap grooming, no payload, no
 * control-flow manipulation.
 */
register_shutdown_function(function () {
    fwrite(STDERR, "RESULT: SHUTDOWN-HANDLER-RAN (engine continued executing PHP after OOM)\n");
});

ini_set('memory_limit', '32M');
fwrite(STDERR, "probe: runtime=" . (defined('HHVM_VERSION') ? 'HHVM ' . HHVM_VERSION : 'PHP ' . PHP_VERSION) . "\n");
fwrite(STDERR, "probe: memory_limit=" . ini_get('memory_limit') . " pid=" . getmypid() . "\n");
fwrite(STDERR, "probe: allocating until limit...\n");

$a = array();
while (true) {
    $a[] = str_repeat('x', 1024 * 1024);
}
