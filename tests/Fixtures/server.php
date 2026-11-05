<?php

// A server for the transfer tests, run with `php -S`. Each path answers in a way the SDK
// must stop reading.

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
header('X-Request-Id: req_server');

if ($path === '/v1/contacts/gzip') {
    // 8 MiB once decoded, a few KiB as sent.
    header('Content-Encoding: gzip');
    echo gzencode('{"data":"' . str_repeat('a', 8 << 20) . '"}', 1);
    exit;
}

// Bodies that never end, so only stopping the transfer ends the test: one without a length,
// as an error, and one that says it's 1 GiB.
$endless = [
    '/v1/contacts/endless' => [200, []],
    '/v1/contacts/endless-error' => [429, ['Retry-After: 7']],
    '/v1/contacts/long' => [200, ['Content-Length: ' . (1 << 30)]],
];
if (! isset($endless[$path])) {
    http_response_code(404);
    echo '{"error":{"code":"NOT_FOUND","message":"No such fixture"}}';
    exit;
}
[$status, $headers] = $endless[$path];
http_response_code($status);
array_map('header', $headers);
while (ob_get_level() > 0) {
    ob_end_flush();
}
$chunk = str_repeat(' ', 8192);
while (! connection_aborted()) {
    echo $chunk;
    flush();
}
