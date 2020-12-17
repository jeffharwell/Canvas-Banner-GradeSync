<?php
declare(strict_types=1);
include 'administrative_grades.php';

if (array_key_exists('HTTP_HOST', $_SERVER) || array_key_exists('REQUEST_URI', $_SERVER)) {
    echo "This is a test script, it cannot, and should not, be executed from the web server, dying\n";
    exit;
}

// Parse our config file to get credentials
// This test only works locally, when the config is in the same directory, this help to prevent us
// actually making it available via a web server, which would be bad.
if (file_exists("./config.ini")) {
    $c = parse_ini_file("./config.ini");
} else {
    echo "Error: was unable to find config.ini in current directory\n";
    exit(1);
}

## Functions
function logmsg($msg) {
  echo "$msg\n";
}

## Set up the canvas connection info
$canvas_token = $c['token'];
$canvas_host = $c['canvas_host'];

$crnterm = '15104.201901';
$crosslist = False;
echo "Testing $crnterm against $canvas_host\n";

## This is exactly how these functions are call in sis_export.php
$column_id = get_administrative_grades_id($canvas_token, $canvas_host, $crnterm, $crosslist);
$admin_grade_by_gnumber = get_administrative_grades($column_id, $canvas_token, $canvas_host, $crnterm, $crosslist);
echo "Retrieved ".count($admin_grade_by_gnumber)." grades.\n";
foreach ($admin_grade_by_gnumber as $gnumber => $grade) {
    echo("Found grade $grade for $gnumber\n");
}


?>
