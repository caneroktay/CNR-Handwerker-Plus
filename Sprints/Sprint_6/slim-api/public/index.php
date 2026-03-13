<?php
// ============================================================
//  HandwerkerPro REST-API — Entry Point (Slim Framework 4)
//  public/index.php — Alle Anfragen hier eingehend
// ============================================================
declare(strict_types=1);

use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use App\Controllers\{AuthController, KundenController, AuftraegeController,
                     RechnungenController, MaterialController, DashboardController,
                     PdfController, MailController, ExportController, ReportingController};
use App\Middleware\{AuthMiddleware, CorsMiddleware};
use App\Models\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// ── Autoloader ───────────────────────────────────────────────
require __DIR__ . '/../vendor/autoload.php';

// ── .env Datei laden ─────────────────────────────────────────
// Liest .env aus dem Projekt-Root und setzt $_ENV Variablen.
// Secrets (API-Keys, Passwörter) gehören in .env, niemals in den Code!
(function () {
    $envFile = __DIR__ . '/../.env';
    if (!file_exists($envFile)) return;

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Inline-Kommentare entfernen (z.B.: value # kommentar)
        if (str_contains($value, ' #')) {
            $value = trim(explode(' #', $value, 2)[0]);
        }

        if (!isset($_ENV[$key])) {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
})();

// ── Konfiguration laden ──────────────────────────────────────
$settings = require __DIR__ . '/../config/settings.php';
$dbConfig = require __DIR__ . '/../config/database.php';

// ── Slim App erstellen ───────────────────────────────────────
$app = AppFactory::create();

// ── Middleware ───────────────────────────────────────────────
// 1. CORS (muss als erstes!)
$app->add(new CorsMiddleware($settings));

// 2. Body-Parser (JSON, Form-Data)
$app->addBodyParsingMiddleware();

// 3. Error-Handler (JSON-Fehlerantworten)
$errorMiddleware = $app->addErrorMiddleware(
    displayErrorDetails: $settings['app']['debug'],
    logErrors: true,
    logErrorDetails: $settings['app']['debug']
);

// Custom Error Handler — immer JSON, niemals HTML
$errorMiddleware->setDefaultErrorHandler(
    function (Request $request, \Throwable $exception, bool $displayErrorDetails) use ($app): Response {
        $code    = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500;
        $message = $displayErrorDetails
            ? $exception->getMessage()
            : 'Interner Serverfehler.';

        $response = $app->getResponseFactory()->createResponse();
        $response->getBody()->write(json_encode([
            'status'  => 'error',
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withStatus($code);
    }
);

// ── Controller-Factory ───────────────────────────────────────
$db      = new Database($dbConfig);
$makeCtrl = fn(string $class) => new $class($db, $settings);

// ── Routen ───────────────────────────────────────────────────

// Auth-Middleware für verschiedene Rollen
$authAll     = new AuthMiddleware($settings, 'buero');
$authGeselle = new AuthMiddleware($settings, 'geselle');
$authMeister = new AuthMiddleware($settings, 'meister');
$authAdmin   = new AuthMiddleware($settings, 'admin');

$app->group('/api', function (RouteCollectorProxy $api) use (
    $makeCtrl, $authAll, $authGeselle, $authMeister, $authAdmin
) {

    // ── AUTH ──────────────────────────────────────────────────
    $api->post('/auth/login',         fn(Request $req, Response $res)       => $makeCtrl(AuthController::class)->login($req, $res));
    $api->post('/auth/logout',        fn(Request $req, Response $res)       => $makeCtrl(AuthController::class)->logout($req, $res));
    $api->get('/auth/me',             fn(Request $req, Response $res)       => $makeCtrl(AuthController::class)->me($req, $res))
        ->add($authAll);
    $api->post('/auth/hash-passwort', fn(Request $req, Response $res)       => $makeCtrl(AuthController::class)->hashPasswort($req, $res))
        ->add($authAdmin);

    // ── DASHBOARD ─────────────────────────────────────────────
    $api->get('/dashboard',           fn(Request $req, Response $res)       => $makeCtrl(DashboardController::class)->index($req, $res))
        ->add($authAll);

    // ── KUNDEN ────────────────────────────────────────────────
    $api->group('/kunden', function (RouteCollectorProxy $g) use ($makeCtrl, $authAll, $authGeselle, $authMeister) {
        $g->get('',         fn(Request $req, Response $res)              => $makeCtrl(KundenController::class)->index($req, $res))
          ->add($authAll);
        $g->get('/{id:\d+}', fn(Request $req, Response $res, array $args) => $makeCtrl(KundenController::class)->show($req, $res, $args))
          ->add($authAll);
        $g->get('/{id:\d+}/auftraege', fn(Request $req, Response $res, array $args) => $makeCtrl(KundenController::class)->auftraege($req, $res, $args))
          ->add($authAll);
        $g->post('',        fn(Request $req, Response $res)              => $makeCtrl(KundenController::class)->store($req, $res))
          ->add($authGeselle);
        $g->put('/{id:\d+}', fn(Request $req, Response $res, array $args) => $makeCtrl(KundenController::class)->update($req, $res, $args))
          ->add($authGeselle);
        $g->delete('/{id:\d+}', fn(Request $req, Response $res, array $args) => $makeCtrl(KundenController::class)->destroy($req, $res, $args))
          ->add($authMeister);
    });

    // ── AUFTRÄGE ──────────────────────────────────────────────
    $api->group('/auftraege', function (RouteCollectorProxy $g) use ($makeCtrl, $authAll, $authGeselle, $authMeister) {
        $g->get('',          fn(Request $req, Response $res)              => $makeCtrl(AuftraegeController::class)->index($req, $res))
          ->add($authAll);
        $g->get('/{id:\d+}', fn(Request $req, Response $res, array $args) => $makeCtrl(AuftraegeController::class)->show($req, $res, $args))
          ->add($authAll);
        $g->post('',         fn(Request $req, Response $res)              => $makeCtrl(AuftraegeController::class)->store($req, $res))
          ->add($authGeselle);
        $g->put('/{id:\d+}', fn(Request $req, Response $res, array $args) => $makeCtrl(AuftraegeController::class)->update($req, $res, $args))
          ->add($authGeselle);
        $g->put('/{id:\d+}/status', fn(Request $req, Response $res, array $args) => $makeCtrl(AuftraegeController::class)->updateStatus($req, $res, $args))
          ->add($authGeselle);
        $g->delete('/{id:\d+}', fn(Request $req, Response $res, array $args) => $makeCtrl(AuftraegeController::class)->destroy($req, $res, $args))
          ->add($authMeister);
    });

    // ── RECHNUNGEN ────────────────────────────────────────────
    $api->group('/rechnungen', function (RouteCollectorProxy $g) use ($makeCtrl, $authAll, $authGeselle) {
        $g->get('',          fn(Request $req, Response $res)              => $makeCtrl(RechnungenController::class)->index($req, $res))
          ->add($authAll);
        $g->get('/{id:\d+}', fn(Request $req, Response $res, array $args) => $makeCtrl(RechnungenController::class)->show($req, $res, $args))
          ->add($authAll);
        $g->get('/{id:\d+}/pdf', fn(Request $req, Response $res, array $args) => $makeCtrl(PdfController::class)->rechnung($req, $res, $args))
          ->add($authAll);
        $g->post('/{id:\d+}/email', fn(Request $req, Response $res, array $args) => $makeCtrl(MailController::class)->sendRechnung($req, $res, $args))
          ->add($authGeselle);
        $g->post('/mahnungen', fn(Request $req, Response $res) => $makeCtrl(MailController::class)->sendMahnungen($req, $res))
          ->add($authGeselle);
        $g->post('/{id:\d+}/bezahlen', fn(Request $req, Response $res, array $args) => $makeCtrl(RechnungenController::class)->bezahlen($req, $res, $args))
          ->add($authAll);
        $g->put('/{id:\d+}', fn(Request $req, Response $res, array $args) => $makeCtrl(RechnungenController::class)->update($req, $res, $args))
          ->add($authGeselle);
    });

    // ── ANGEBOTE PDF ──────────────────────────────────────────
    $api->group('/angebote', function (RouteCollectorProxy $g) use ($makeCtrl, $authAll) {
        $g->get('/{id:\d+}/pdf', fn(Request $req, Response $res, array $args) => $makeCtrl(PdfController::class)->angebot($req, $res, $args))
          ->add($authAll);
    });

    // ── REPORTS ───────────────────────────────────────────────
    $api->group('/reports', function (RouteCollectorProxy $g) use ($makeCtrl, $authAll) {
        $g->get('/monat/{jahr:\d{4}}/{monat:\d{1,2}}', fn(Request $req, Response $res, array $args) => $makeCtrl(PdfController::class)->monatsreport($req, $res, $args))
          ->add($authAll);
    });

    // ── MATERIAL ──────────────────────────────────────────────
    $api->group('/material', function (RouteCollectorProxy $g) use ($makeCtrl, $authAll, $authGeselle, $authMeister, $authAdmin) {
        $g->get('',          fn(Request $req, Response $res)              => $makeCtrl(MaterialController::class)->index($req, $res))
          ->add($authAll);
        $g->get('/{id:\d+}', fn(Request $req, Response $res, array $args) => $makeCtrl(MaterialController::class)->show($req, $res, $args))
          ->add($authAll);
        $g->post('',         fn(Request $req, Response $res)              => $makeCtrl(MaterialController::class)->store($req, $res))
          ->add($authMeister);
        $g->post('/{id:\d+}/nachbestellen', fn(Request $req, Response $res, array $args) => $makeCtrl(MaterialController::class)->nachbestellen($req, $res, $args))
          ->add($authGeselle);
        $g->put('/{id:\d+}', fn(Request $req, Response $res, array $args) => $makeCtrl(MaterialController::class)->update($req, $res, $args))
          ->add($authMeister);
        $g->delete('/{id:\d+}', fn(Request $req, Response $res, array $args) => $makeCtrl(MaterialController::class)->destroy($req, $res, $args))
          ->add($authAdmin);
    });

    // ── NOTIFICATIONS ─────────────────────────────────────────
    $api->group('/notifications', function (RouteCollectorProxy $g) use ($makeCtrl, $authGeselle) {
        $g->post('/auftrag/{id:\d+}', fn(Request $req, Response $res, array $args) => $makeCtrl(MailController::class)->auftragNotification($req, $res, $args))
          ->add($authGeselle);
    });

    // ── MAIL TEST ─────────────────────────────────────────────
    $api->post('/mail/test', fn(Request $req, Response $res) => $makeCtrl(MailController::class)->testSmtp($req, $res))
        ->add($authMeister);

    // ── EXPORT ────────────────────────────────────────────────
    $api->group('/export', function (RouteCollectorProxy $g) use ($makeCtrl, $authMeister) {
        $g->get('/kunden',      fn(Request $req, Response $res) => $makeCtrl(ExportController::class)->kunden($req, $res))
          ->add($authMeister);
        $g->get('/auftraege',   fn(Request $req, Response $res) => $makeCtrl(ExportController::class)->auftraege($req, $res))
          ->add($authMeister);
        $g->get('/rechnungen',  fn(Request $req, Response $res) => $makeCtrl(ExportController::class)->rechnungen($req, $res))
          ->add($authMeister);
        $g->get('/material',    fn(Request $req, Response $res) => $makeCtrl(ExportController::class)->material($req, $res))
          ->add($authMeister);
        $g->get('/mitarbeiter', fn(Request $req, Response $res) => $makeCtrl(ExportController::class)->mitarbeiter($req, $res))
          ->add($authMeister);
        $g->get('/backup',      fn(Request $req, Response $res) => $makeCtrl(ExportController::class)->backup($req, $res))
          ->add($authMeister);
    });

    // ── REPORTING ─────────────────────────────────────────────
    $api->group('/reporting', function (RouteCollectorProxy $g) use ($makeCtrl, $authAll) {
        $g->get('/kennzahlen',
            fn(Request $req, Response $res) => $makeCtrl(ReportingController::class)->kennzahlen($req, $res))
          ->add($authAll);
        $g->get('/umsatz',
            fn(Request $req, Response $res) => $makeCtrl(ReportingController::class)->umsatz($req, $res))
          ->add($authAll);
        $g->get('/mitarbeiter/auslastung',
            fn(Request $req, Response $res) => $makeCtrl(ReportingController::class)->mitarbeiterAuslastung($req, $res))
          ->add($authAll);
        $g->get('/top-kunden',
            fn(Request $req, Response $res) => $makeCtrl(ReportingController::class)->topKunden($req, $res))
          ->add($authAll);
    });

    // ── API INFO ──────────────────────────────────────────────
    $api->get('', function (Request $req, Response $res): Response {
        $res->getBody()->write(json_encode([
            'status'  => 'success',
            'name'    => 'HandwerkerPro REST-API',
            'version' => '1.0.0',
            'framework' => 'Slim 4',
            'endpoints' => [
                'POST   /api/auth/login',
                'GET    /api/dashboard',
                'GET    /api/kunden',
                'GET    /api/kunden/{id}',
                'POST   /api/kunden',
                'PUT    /api/kunden/{id}',
                'DELETE /api/kunden/{id}',
                'GET    /api/auftraege',
                'GET    /api/auftraege/{id}',
                'POST   /api/auftraege',
                'PUT    /api/auftraege/{id}',
                'PUT    /api/auftraege/{id}/status',
                'DELETE /api/auftraege/{id}',
                'GET    /api/rechnungen',
                'GET    /api/rechnungen/{id}',
                'POST   /api/rechnungen/{id}/bezahlen',
                'GET    /api/material',
                'GET    /api/material/{id}',
                'POST   /api/material',
                'POST   /api/material/{id}/nachbestellen',
                'PUT    /api/material/{id}',
                'DELETE /api/material/{id}',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $res->withHeader('Content-Type', 'application/json; charset=UTF-8');
    });
});

$app->run();
