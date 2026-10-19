<?php declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/PayrollApi.php';
require __DIR__ . '/src/HttpHandler.php';

return new PayrollHttpHandler();
