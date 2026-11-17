<?php
// ── Liberty Foodmart — Database Connection ──────────────────
// Include this file at the top of every PHP page:
//   require 'db.php';
// Then use $pdo to run queries.
// ------------------------------------------------------------

define('DB_HOST', 'localhost');
define('DB_NAME', 'liberty_foodmart');
define('DB_USER', 'root');        // ← change to your DB username
define('DB_PASS', '');            // ← change to your DB password

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // In production, log this instead of showing it
    die('Database connection failed: ' . $e->getMessage());
}