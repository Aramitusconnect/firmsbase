<?php

// Router script for `php -S`, used only by VerifyWebHealthScriptTest to
// simulate /readyz responses (healthy JSON, unhealthy JSON, non-JSON HTML
// failure) without making any real network or AWS call. REPLY_MODE is set
// by the test via an environment variable passed to the `php -S` process.

$mode = getenv('REPLY_MODE') ?: 'healthy';

switch ($mode) {
    case 'healthy':
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ready', 'checks' => ['database' => 'ok', 'redis' => 'ok']]);
        break;

    case 'unhealthy_json':
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'not_ready', 'checks' => ['database' => 'ok', 'redis' => 'error']]);
        break;

    case 'html_500':
        http_response_code(500);
        header('Content-Type: text/html');
        echo '<html><body><h1>Server Error</h1><p>Whoops, something went wrong.</p></body></html>';
        break;

    default:
        http_response_code(500);
        echo 'unknown REPLY_MODE';
}
