<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'auth' => env('REDIS_AUTH', ''),
        'port' => (int) env('REDIS_PORT', 6379),
        'db' => (int) env('REDIS_DB', 0),
        'timeout' => (float) env('REDIS_TIMEOUT', 0.0),
        'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 0.0),
        'retry_interval' => (int) env('REDIS_RETRY_INTERVAL', 0),
    ],
];

