<?php
header('Content-Type: text/plain');
echo "post_vars=" . count($_POST) . " files=" . count($_FILES) . "\n";
echo "max_input_vars=" . ini_get('max_input_vars')
   . " max_file_uploads=" . ini_get('max_file_uploads')
   . " max_multipart_body_parts=" . var_export(ini_get('max_multipart_body_parts'), true) . "\n";
