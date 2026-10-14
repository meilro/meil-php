<?php

// Router for PHP's built-in server: describes the request it got.
declare(strict_types=1);

if ($_SERVER['REQUEST_URI'] === '/slow') {
    sleep(3);
}
if ($_SERVER['REQUEST_URI'] === '/html') {
    http_response_code(502);
    header('Content-Type: text/html');
    echo '<html>Bad gateway</html>';
    return true;
}
http_response_code(201);
header('Content-Type: application/json');
header('Retry-After: 7');
echo json_encode([
    'method'         => $_SERVER['REQUEST_METHOD'],
    'uri'            => $_SERVER['REQUEST_URI'],
    'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
    'authorization'  => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'idempotency'    => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null,
    'body'           => file_get_contents('php://input'),
]);
return true;
