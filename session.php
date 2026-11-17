<?php
// ── Liberty Foodmart — Hardened session start ───────────────
// Every page loads this instead of calling session_start() directly,
// so the session cookie gets the same safe settings everywhere:
//
//   • HttpOnly  — JavaScript can't read the cookie (limits XSS damage)
//   • SameSite=Lax — the browser won't send the cookie on cross-site
//                    POSTs, which blocks most CSRF attacks
//   • Secure    — only added automatically when the site runs on HTTPS
//   • strict mode — PHP refuses session IDs it didn't create itself,
//                   which helps against session fixation
//
// Safe to include more than once.

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_set_cookie_params([
        'lifetime' => 0,          // ends when the browser closes
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);

    session_start();
}
