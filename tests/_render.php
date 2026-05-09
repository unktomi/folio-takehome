<?php

// Subprocess page renderer for the test harness.
//
// Why a subprocess: public/*.php pages call `exit` (e.g. after a
// POST/redirect). Requiring them in-process would terminate the test
// runner. Running each page in its own PHP process gives us a clean
// request lifecycle and isolates side effects to the shared SQLite file.
//
// Inputs come through env vars because they're binary-safe and don't
// collide with the page's expectations about $_GET / $_POST / argv.

$_GET = json_decode(getenv('TEST_GET') ?: '{}', true) ?: [];
$_POST = json_decode(getenv('TEST_POST') ?: '{}', true) ?: [];
$_SERVER['REQUEST_METHOD'] = getenv('TEST_METHOD') ?: 'GET';
$_SERVER['HTTP_HOST'] = 'localhost:8000';

$script = getenv('TEST_SCRIPT') ?: '';
if ($script === '' || !preg_match('/^[a-z_]+\.php$/', $script)) {
    fwrite(STDERR, "invalid TEST_SCRIPT\n");
    exit(2);
}

require __DIR__ . '/../public/' . $script;
