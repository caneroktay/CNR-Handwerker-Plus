<?php
// ============================================================
//  HandwerkerPro — App-Einstellungen
//
//  Sicherheitsregel: Keine Secrets im Code!
//  API-Keys und CORS-Origins werden aus der .env Datei geladen.
//  → Siehe .env.example für alle erforderlichen Variablen.
// ============================================================

// ── Hilfsfunktion: kommagetrennte .env-Liste → Array ────────
$envList = function (string $key, string $default = ''): array {
    $raw = $_ENV[$key] ?? $default;
    if (empty(trim($raw))) return [];
    return array_map('trim', explode(',', $raw));
};

// ── API-Keys aus .env laden ──────────────────────────────────
// Jeder Key wird nur registriert wenn er in .env gesetzt ist.
// Leere oder fehlende Keys werden sicher ignoriert.
$apiKeys = [];
$keyMap  = [
    'API_KEY_ADMIN'   => ['mitarbeiter_id' => 1, 'rolle' => 'admin'],
    'API_KEY_MEISTER' => ['mitarbeiter_id' => 2, 'rolle' => 'meister'],
    'API_KEY_GESELLE' => ['mitarbeiter_id' => 3, 'rolle' => 'geselle'],
];
foreach ($keyMap as $envVar => $meta) {
    $key = trim($_ENV[$envVar] ?? '');
    if ($key !== '') {
        $apiKeys[$key] = $meta;
    }
}

// ── CORS-Origins aus .env laden ──────────────────────────────
// Entwicklung:   CORS_ORIGINS=*
// Sprint 7:      CORS_ORIGINS=http://localhost:3000
// Produktion:    CORS_ORIGINS=https://mein-frontend.de
$corsOrigins = $envList('CORS_ORIGINS', '*');

return [
    'app' => [
        'name'    => 'HandwerkerPro REST-API',
        'version' => '1.0.0',
        'env'     => $_ENV['APP_ENV']    ?? 'development',
        'debug'   => ($_ENV['APP_DEBUG'] ?? 'true') === 'true',
    ],

    // CORS — Origin wird aus .env gesteuert, nie hardcoded
    'cors' => [
        'origins'  => $corsOrigins,
        'methods'  => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'headers'  => ['Content-Type', 'X-API-Key', 'Authorization'],
        'max_age'  => 86400,
    ],

    // API-Keys — aus .env, niemals im Code!
    'api_keys' => $apiKeys,

    // Rollenhierarchie (höher = mehr Rechte)
    'role_hierarchy' => [
        'buero'   => 1,
        'azubi'   => 2,
        'geselle' => 3,
        'meister' => 4,
        'admin'   => 5,
    ],

    // Passwort-Hashing: PASSWORD_BCRYPT oder PASSWORD_ARGON2ID
    'password_algo' => PASSWORD_ARGON2ID,

    // Demo-Passwort — nur aktiv wenn APP_ENV=development
    'demo_password' => $_ENV['DEMO_PASSWORD'] ?? 'demo123',

    // Logging
    'log_path' => __DIR__ . '/../logs/app.log',

    // Mail-Konfiguration — aus .env geladen
    'mail' => [
        'smtp_host'   => $_ENV['MAIL_SMTP_HOST']   ?? 'smtp.mailtrap.io',
        'smtp_port'   => $_ENV['MAIL_SMTP_PORT']   ?? '587',
        'smtp_user'   => $_ENV['MAIL_SMTP_USER']   ?? '',
        'smtp_pass'   => $_ENV['MAIL_SMTP_PASS']   ?? '',
        'smtp_secure' => $_ENV['MAIL_SMTP_SECURE'] ?? 'tls',
        'from_email'  => $_ENV['MAIL_FROM_EMAIL']  ?? 'noreply@klausmeier-gmbh.de',
        'from_name'   => $_ENV['MAIL_FROM_NAME']   ?? 'Klaus Meier GmbH',
        'debug'       => ($_ENV['MAIL_DEBUG']      ?? 'false') === 'true',

        // SSL-Zertifikatsprüfung:
        //   Produktion (APP_ENV=production): IMMER true — echte Zertifikate werden geprüft.
        //   Entwicklung (APP_ENV=development): Kann per MAIL_SSL_VERIFY=false deaktiviert
        //   werden, z.B. für Mailtrap oder lokale SMTP-Server ohne gültiges Zertifikat.
        //   WICHTIG: Niemals MAIL_SSL_VERIFY=false in Produktion setzen!
        'ssl_verify'  => ($_ENV['APP_ENV'] ?? 'development') === 'production'
                         ? true  // In Produktion immer erzwingen, .env-Wert wird ignoriert
                         : ($_ENV['MAIL_SSL_VERIFY'] ?? 'true') === 'true',
    ],
];
