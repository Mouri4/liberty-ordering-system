<?php
// ── Liberty Foodmart — Customer Logout ──────────────────────
require_once 'session.php';

// Only clear customer keys, leave staff session alone if any
unset(
    $_SESSION['customer_id'],
    $_SESSION['customer_name'],
    $_SESSION['customer_email'],
    $_SESSION['customer_phone'],
    $_SESSION['customer_location']
);

// If no staff session either, destroy fully
if (empty($_SESSION['staff_id'])) {
    session_destroy();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
}

header('Location: customer-login.php');
exit;