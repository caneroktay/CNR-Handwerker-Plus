<?php
// ============================================================
//  CORS Middleware — setzt notwendige Header
// ============================================================
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(private array $settings) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        // OPTIONS Preflight sofort beantworten
        if ($request->getMethod() === 'OPTIONS') {
            $response = new \Slim\Psr7\Response();
            return $this->addCorsHeaders($response);
        }

        $response = $handler->handle($request);
        return $this->addCorsHeaders($response);
    }

    private function addCorsHeaders(ResponseInterface $response): ResponseInterface
    {
        $cors = $this->settings['cors'];
        return $response
            ->withHeader('Access-Control-Allow-Origin',  implode(', ', $cors['origins']))
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $cors['methods']))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $cors['headers']))
            ->withHeader('Access-Control-Max-Age',       (string)$cors['max_age']);
    }
}
