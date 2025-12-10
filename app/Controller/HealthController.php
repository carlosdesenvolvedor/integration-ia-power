<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

#[Controller]
class HealthController
{
    public function __construct(private CacheInterface $cache)
    {
    }

    #[GetMapping(path: '/health')]
    public function health(ResponseInterface $response)
    {
        $payload = [
            'status' => 'ok',
            'time' => microtime(true),
        ];

        // DB check
        try {
            Db::select('SELECT 1');
            $payload['db'] = 'ok';
        } catch (\Throwable $e) {
            $payload['db'] = 'error';
            $payload['db_error'] = $e->getMessage();
        }

        // Cache check (Redis)
        try {
            $key = 'health:ping';
            $this->cache->set($key, '1', 5);
            $this->cache->get($key);
            $payload['cache'] = 'ok';
        } catch (\Throwable $e) {
            $payload['cache'] = 'error';
            $payload['cache_error'] = $e->getMessage();
        }

        return $response->json($payload);
    }
}

