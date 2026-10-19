<?php declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/PayrollApi.php';

$result = (new PayrollApi())->dispatch(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'
);

http_response_code($result['statusCode']);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
