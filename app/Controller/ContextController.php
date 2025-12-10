<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\Context;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\DeleteMapping;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;

#[Controller]
class ContextController
{
    #[GetMapping(path: '/contexts')]
    public function index(ResponseInterface $response)
    {
        return $response->json(Context::orderBy('created_at', 'desc')->get());
    }

    #[PostMapping(path: '/contexts')]
    public function store(RequestInterface $request, ResponseInterface $response)
    {
        $data = $request->all();
        
        if (empty($data['name'])) {
            return $response->json(['error' => 'Name is required'])->withStatus(400);
        }

        try {
            $context = Context::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'content' => $data['content'] ?? [],
                'is_default' => $data['is_default'] ?? false,
            ]);

            return $response->json($context);
        } catch (\Throwable $e) {
            return $response->json([
                'error' => 'Failed to save context: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ])->withStatus(500);
        }
    }

    #[GetMapping(path: '/contexts/{id}')]
    public function show(int $id, ResponseInterface $response)
    {
        $context = Context::find($id);
        if (!$context) {
            return $response->json(['error' => 'Context not found'])->withStatus(404);
        }
        return $response->json($context);
    }

    #[PutMapping(path: '/contexts/{id}')]
    public function update(int $id, RequestInterface $request, ResponseInterface $response)
    {
        $context = Context::find($id);
        if (!$context) {
            return $response->json(['error' => 'Context not found'])->withStatus(404);
        }

        $data = $request->all();
        $context->update($data);

        return $response->json($context);
    }

    #[DeleteMapping(path: '/contexts/{id}')]
    public function destroy(int $id, ResponseInterface $response)
    {
        $context = Context::find($id);
        if ($context) {
            $context->delete();
        }
        return $response->json(['message' => 'Context deleted']);
    }

    #[PostMapping(path: '/contexts/setup')]
    public function setup(ResponseInterface $response)
    {
        try {
            Db::statement("
                CREATE TABLE IF NOT EXISTS contexts (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    description TEXT NULL,
                    content JSON NULL,
                    is_default BOOLEAN DEFAULT 0,
                    created_at TIMESTAMP NULL DEFAULT NULL,
                    updated_at TIMESTAMP NULL DEFAULT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            return $response->json(['message' => 'Contexts table created successfully']);
        } catch (\Throwable $e) {
            return $response->json(['error' => $e->getMessage()])->withStatus(500);
        }
    }
}

