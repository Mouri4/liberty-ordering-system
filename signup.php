<?php
// ── Staff self-registration is disabled ─────────────────────
// Staff accounts are now created by the admin only.
// Redirect anyone who lands here back to login.
require_once 'session.php';
if (!empty($_SESSION['staff_id'])) {
    header('Location: inventory.php');
} else {
    header('Location: login.php?notice=admin_only');
}
exit;