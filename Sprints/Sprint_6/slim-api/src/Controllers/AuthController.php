<?php
// ============================================================
//  Auth Controller
//  Passwort-Hashing: Argon2ID (sicherer als bcrypt)
//  Fallback: demo123 für Entwicklungsumgebung
// ============================================================
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AuthController extends BaseController
{
    /**
     * POST /api/auth/login
     * Authentifiziert Mitarbeiter per E-Mail + Passwort
     */
    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body  = $this->sanitize($this->getBody($request));
        $email = $body['email']    ?? '';
        $pass  = $body['password'] ?? '';

        // Validierung
        if (!$email || !$pass) {
            return $this->unprocessable($response, 'E-Mail und Passwort sind erforderlich.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->unprocessable($response, 'Ungültige E-Mail-Adresse.');
        }

        // Mitarbeiter aus DB holen — NUR über Prepared Statement!
        $user = $this->db->fetchOne(
            'SELECT * FROM mitarbeiter WHERE email = ? AND aktiv = 1',
            [$email]
        );

        // Passwort prüfen:
        // 1. Echten Hash via password_verify() prüfen (Argon2ID oder bcrypt)
        // 2. In Entwicklung: Demo-Passwort akzeptieren
        $isDev       = ($this->settings['app']['env'] === 'development');
        $demoPass    = $this->settings['demo_password'];
        $validPass   = $user && (
            password_verify($pass, $user['password_hash']) ||
            ($isDev && $pass === $demoPass)
        );

        if (!$validPass) {
            // Login-Fehler loggen (ohne Passwort!)
            if ($user) {
                $this->db->execute(
                    'INSERT INTO login_log (mitarbeiter_id, ip_adresse, user_agent, erfolgreich) VALUES (?, ?, ?, 0)',
                    [
                        $user['mitarbeiter_id'],
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                    ]
                );
            }
            // 401 — Keine Details über Existenz des Users (Security!)
            return $this->unauthorized($response, 'E-Mail oder Passwort falsch.');
        }

        // Session starten und User speichern
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['api_user'] = [
            'mitarbeiter_id' => $user['mitarbeiter_id'],
            'rolle'          => $user['rolle'],
        ];

        // Login loggen
        $this->db->execute(
            'INSERT INTO login_log (mitarbeiter_id, ip_adresse, user_agent, erfolgreich) VALUES (?, ?, ?, 1)',
            [
                $user['mitarbeiter_id'],
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]
        );

        // letzte_login aktualisieren
        $this->db->execute(
            'UPDATE mitarbeiter SET letzte_login = NOW() WHERE mitarbeiter_id = ?',
            [$user['mitarbeiter_id']]
        );

        // Antwort — NIEMALS password_hash zurückgeben!
        return $this->ok($response, [
            'mitarbeiter_id' => $user['mitarbeiter_id'],
            'vorname'        => $user['vorname'],
            'nachname'       => $user['nachname'],
            'email'          => $user['email'],
            'rolle'          => $user['rolle'],
            'session_aktiv'  => true,
        ], 'Login erfolgreich.');
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        session_destroy();
        return $this->ok($response, null, 'Erfolgreich abgemeldet.');
    }

    /**
     * GET /api/auth/me
     * Gibt den aktuell authentifizierten Benutzer zurück
     */
    public function me(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->getAuthUser($request);
        // Sensible Felder entfernen
        unset($user['password_hash']);
        return $this->ok($response, $user);
    }

    /**
     * POST /api/auth/hash-passwort
     * Hilfsmethode um Passwörter zu hashen (nur für Admin/Entwicklung)
     */
    public function hashPasswort(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->getBody($request);
        $pass = $body['password'] ?? '';

        if (strlen($pass) < 8) {
            return $this->unprocessable($response, 'Passwort muss mindestens 8 Zeichen lang sein.');
        }

        $algo    = $this->settings['password_algo']; // PASSWORD_ARGON2ID
        $hashed  = password_hash($pass, $algo);

        return $this->ok($response, [
            'hash'       => $hashed,
            'algorithmus' => $algo === PASSWORD_ARGON2ID ? 'Argon2ID' : 'bcrypt',
            'hinweis'    => 'Diesen Hash in der Spalte password_hash der Mitarbeiter-Tabelle speichern.',
        ]);
    }
}
