<?php
// ============================================================
//  Base Controller — gemeinsame Hilfsmethoden
//  Alle Antworten: JSON, korrekte HTTP-Statuscodes
// ============================================================
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Models\Database;

abstract class BaseController
{
    public function __construct(protected Database $db, protected array $settings) {}

    // ── JSON-Antworten ───────────────────────────────────────

    /** 200 OK */
    protected function ok(ResponseInterface $response, mixed $data, string $message = ''): ResponseInterface
    {
        return $this->json($response, 200, $data, $message);
    }

    /** 201 Created */
    protected function created(ResponseInterface $response, mixed $data, string $message = ''): ResponseInterface
    {
        return $this->json($response, 201, $data, $message);
    }

    /** 400 Bad Request */
    protected function badRequest(ResponseInterface $response, string $message, array $details = []): ResponseInterface
    {
        return $this->jsonError($response, 400, $message, $details);
    }

    /** 401 Unauthorized */
    protected function unauthorized(ResponseInterface $response, string $message): ResponseInterface
    {
        return $this->jsonError($response, 401, $message);
    }

    /** 404 Not Found */
    protected function notFound(ResponseInterface $response, string $message): ResponseInterface
    {
        return $this->jsonError($response, 404, $message);
    }

    /** 409 Conflict */
    protected function conflict(ResponseInterface $response, string $message): ResponseInterface
    {
        return $this->jsonError($response, 409, $message);
    }

    /** 422 Unprocessable Entity (Validierungsfehler) */
    protected function unprocessable(ResponseInterface $response, string $message, array $details = []): ResponseInterface
    {
        return $this->jsonError($response, 422, $message, $details);
    }

    /** 500 Internal Server Error */
    protected function serverError(ResponseInterface $response, string $message = 'Interner Serverfehler.'): ResponseInterface
    {
        return $this->jsonError($response, 500, $message);
    }

    // ── Kern-Methoden ────────────────────────────────────────

    protected function json(ResponseInterface $response, int $status, mixed $data, string $message = ''): ResponseInterface
    {
        $body = ['status' => 'success'];
        if ($message) $body['message'] = $message;
        if ($data !== null) $body['data'] = $data;

        $response->getBody()->write(
            json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        return $response
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withStatus($status);
    }

    protected function jsonError(ResponseInterface $response, int $status, string $message, array $details = []): ResponseInterface
    {
        $body = ['status' => 'error', 'message' => $message];
        if ($details) $body['details'] = $details;

        $response->getBody()->write(
            json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        return $response
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withStatus($status);
    }

    // ── Eingabe-Hilfsmethoden ────────────────────────────────

    /**
     * Request-Body als Array lesen und validieren
     */
    protected function getBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_array($body)) return $body;

        $raw = (string) $request->getBody();
        if (empty($raw)) return [];

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) return [];

        return $decoded ?? [];
    }

    /**
     * Eingaben bereinigen — XSS-Schutz
     * Niemals rohe User-Inputs in SQL einbauen!
     */
    protected function sanitize(mixed $value): mixed
    {
        if (is_string($value)) {
            return trim(strip_tags($value));
        }
        if (is_array($value)) {
            return array_map([$this, 'sanitize'], $value);
        }
        return $value;
    }

    /**
     * Pflichtfelder prüfen
     */
    protected function validateRequired(array $data, array $fields): array
    {
        $missing = [];
        foreach ($fields as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    /**
     * Pagination-Parameter aus Query auslesen
     */
    protected function getPagination(ServerRequestInterface $request): array
    {
        $params = $request->getQueryParams();
        $page   = max(1, (int)($params['page']  ?? 1));
        $limit  = min(100, max(1, (int)($params['limit'] ?? 25)));
        return ['page' => $page, 'limit' => $limit, 'offset' => ($page - 1) * $limit];
    }

    /**
     * Authentifizierten User aus Request-Attributen holen
     */
    protected function getAuthUser(ServerRequestInterface $request): array
    {
        return $request->getAttribute('auth_user', []);
    }

    /**
     * Datum validieren (Format: YYYY-MM-DD)
     */
    protected function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}
