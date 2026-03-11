<?php
// ============================================================
//  HandwerkerPro — Datenbank-Konfiguration
//  PDO mit Prepared Statements, niemals String-Concatenation!
// ============================================================

return [
    'host'    => $_ENV['DB_HOST']    ?? 'localhost',
    'name'    => $_ENV['DB_NAME']    ?? 'handwerkerpro_db',
    'user'    => $_ENV['DB_USER']    ?? 'root',
    'pass'    => $_ENV['DB_PASS']    ?? '',
    'charset' => 'utf8mb4',
    'options' => [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // Exceptions statt Warnings
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // Assoziative Arrays
        PDO::ATTR_EMULATE_PREPARES   => false,                    // Echte Prepared Statements!
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ],
];
