<?php
// ============================================================
//  Auth Middleware — API-Key & Session, Rollenhierarchie
// ============================================================
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private array $settings,
        private string $minRole = 'geselle'
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $user = $this->authenticate($request);

        if (!$user) {
            return $this->unauthorized('Nicht autorisiert. X-API-Key Header oder gültige Session erforderlich.');
        }

        if (!$this->hasRole($user['rolle'], $this->minRole)) {
            return $this->forbidden(
                "Zugriff verweigert. Mindestrolle '{$this->minRole}' erforderlich. Ihre Rolle: '{$user['rolle']}'."
            );
        }

        // User-Info für Controller verfügbar machen
        $request = $request->withAttribute('auth_user', $user);
        return $handler->handle($request);
    }

    /**
     * Benutzer authentifizieren
     * Priorität: 1. X-API-Key Header  2. Authorization Bearer  3. Session
     */
    private function authenticate(ServerRequestInterface $request): ?array
    {
        $apiKeys = $this->settings['api_keys'];

        // 1. X-API-Key Header
        $key = $request->getHeaderLine('X-API-Key');
        if ($key && isset($apiKeys[$key])) {
            return $apiKeys[$key];
        }

        // 2. Authorization: Bearer <key>
        $auth = $request->getHeaderLine('Authorization');
        if (str_starts_with($auth, 'Bearer ')) {
            $key = substr($auth, 7);
            if (isset($apiKeys[$key])) {
                return $apiKeys[$key];
            }
        }

        // 3. Session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!empty($_SESSION['api_user'])) {
            return $_SESSION['api_user'];
        }

        return null;
    }

    /**
     * Rollenhierarchie prüfen
     */
    private function hasRole(string $userRole, string $minRole): bool
    {
        $hierarchy = $this->settings['role_hierarchy'];
        $userLevel = $hierarchy[$userRole] ?? 0;
        $minLevel  = $hierarchy[$minRole]  ?? 99;
        return $userLevel >= $minLevel;
    }

    // ── Helper: JSON-Fehlerantworten ────────────────────────

    private function unauthorized(string $message): ResponseInterface
    {
        return $this->jsonError(401, $message);
    }

    private function forbidden(string $message): ResponseInterface
    {
        return $this->jsonError(403, $message);
    }

    private function jsonError(int $code, string $message): ResponseInterface
    {
        $response = new Response();
        $response->getBody()->write(json_encode([
            'status'  => 'error',
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withStatus($code);
    }
}
