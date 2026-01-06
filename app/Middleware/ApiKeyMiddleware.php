<?php

declare(strict_types=1);

namespace App\Middleware;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ApiKeyMiddleware implements MiddlewareInterface
{
    protected ContainerInterface $container;

    protected RequestInterface $request;

    protected HttpResponse $response;

    public function __construct(ContainerInterface $container, HttpResponse $response, RequestInterface $request)
    {
        $this->container = $container;
        $this->response = $response;
        $this->request = $request;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Obter token configurado no ambiente
        $validToken = getenv('API_ACCESS_TOKEN');

        // Se não tiver token configurado, deixa passar (modo dev inseguro) ou bloqueia?
        // Vamos bloquear por segurança em produção.
        if (empty($validToken)) {
            // Se estiver em prod e sem token configurado, é erro de config.
            if (getenv('APP_ENV') === 'prod') {
                return $this->response->json([
                    'error' => 'Server misconfiguration: API_ACCESS_TOKEN not set'
                ])->withStatus(500);
            }
            return $handler->handle($request);
        }

        // Tenta ler o header X-API-KEY ou Authorization
        $authHeader = $request->getHeaderLine('X-API-KEY');
        if (empty($authHeader)) {
            $authHeader = $request->getHeaderLine('Authorization');
            // Remove 'Bearer ' se existir
            if (str_starts_with($authHeader, 'Bearer ')) {
                $authHeader = substr($authHeader, 7);
            }
        }

        if ($authHeader !== $validToken) {
            return $this->response->json([
                'error' => 'Unauthorized: Invalid API Key'
            ])->withStatus(401);
        }

        return $handler->handle($request);
    }
}
