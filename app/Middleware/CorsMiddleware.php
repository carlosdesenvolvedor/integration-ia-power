<?php

declare(strict_types=1);

namespace App\Middleware;

use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(protected \Hyperf\HttpServer\Contract\ResponseInterface $response)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            return $this->addCorsHeaders($this->response->withStatus(204));
        }

        $response = $handler->handle($request);
        return $this->addCorsHeaders($response);
    }

    protected function addCorsHeaders(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, HEAD')
            ->withHeader('Access-Control-Allow-Headers', '*')
            ->withHeader('Access-Control-Expose-Headers', '*')
            ->withHeader('Access-Control-Max-Age', '3600');
    }
}
