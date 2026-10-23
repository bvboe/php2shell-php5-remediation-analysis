<?php
// Reads whatever the ENGINE put in the session. This is the exact pattern an
// upload progress bar uses: poll this endpoint while the upload is in flight.
session_start();
header('Content-Type: text/plain');
$found = array();
foreach ($_SESSION as $k => $v) {
    if (strpos($k, ini_get('session.upload_progress.prefix')) === 0) $found[$k] = $v;
}
if ($found) {
    foreach ($found as $k => $v) {
        echo "PROGRESS KEY PRESENT: $k\n";
        echo "   bytes_processed=" . (isset($v['bytes_processed']) ? $v['bytes_processed'] : '?')
           . " content_length="   . (isset($v['content_length'])   ? $v['content_length']   : '?') . "\n";
    }
} else {
    echo "NO PROGRESS KEY IN SESSION\n";
}
echo "session keys: " . (count($_SESSION) ? implode(',', array_keys($_SESSION)) : '(none)') . "\n";
