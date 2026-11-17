<?php
// ── Liberty Foodmart — Shared login / session helpers ───────
// Include AFTER session.php and db.php:
//   require_once 'session.php';
//   require 'db.php';
//   require_once 'auth.php';
//
// Two jobs:
//   1. require_staff()  — checks, on EVERY staff page load, that the
//      logged-in account still exists in the database, still has the
//      same role, and still has the same password. So deleting a staff
//      account or resetting their password signs them out immediately,
//      instead of leaving their old session working.
//   2. Login throttling — counts failed logins per (username + IP
//      address), not per username alone. Before, anyone could type 3
//      wrong passwords for "admin" and lock the real admin out; now
//      they only lock out their own computer.
// ------------------------------------------------------------

// ── Session signature ───────────────────────────────────────
// A fingerprint of the account's current password hash. Stored in the
// session at login; if the password changes later, it no longer matches.
function auth_pw_sig(string $password_hash): string {
    return hash('sha256', 'liberty-staff-sig|' . $password_hash);
}

// ── Staff session check ─────────────────────────────────────
/**
 * Returns the staff row if the current session is still valid, else null.
 * (Kept separate from require_staff() so it can be tested on its own.)
 */
function staff_session_user(PDO $pdo): ?array {
    $id = (int)($_SESSION['staff_id'] ?? 0);
    if ($id <= 0) return null;

    $stmt = $pdo->prepare('SELECT id, username, role, password_hash FROM staff_users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) return null;                                   // account was deleted

    $sig = (string)($_SESSION['staff_sig'] ?? '');
    if ($sig === '' || !hash_equals(auth_pw_sig($user['password_hash']), $sig)) {
        return null;                                           // password changed (or old-style session)
    }
    return $user;
}

/** Remove only the staff keys from the session (leaves a customer login alone). */
function staff_sign_out(): void {
    unset($_SESSION['staff_id'], $_SESSION['staff_username'], $_SESSION['staff_role'], $_SESSION['staff_sig']);
}

/**
 * Guard for staff pages. Call right after db.php:
 *   require_staff($pdo);            // any staff or admin
 *   require_staff($pdo, 'admin');   // admin only
 */
function require_staff(PDO $pdo, ?string $role = null): void {
    $user = staff_session_user($pdo);

    if (!$user) {
        staff_sign_out();
        header('Location: login.php');
        exit;
    }

    // Keep the session copies in step with the database
    $_SESSION['staff_username'] = $user['username'];
    $_SESSION['staff_role']     = $user['role'];

    if ($role !== null && $user['role'] !== $role) {
        header('Location: inventory.php');   // logged in, just not allowed here
        exit;
    }
}

// ── Login throttling ────────────────────────────────────────
// Uses the existing login_attempts table; no schema change. The
// "username" column simply holds a hash of  scope + name + IP.
function auth_attempt_key(string $scope, string $identity): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return substr(hash('sha256', $scope . '|' . strtolower(trim($identity)) . '|' . $ip), 0, 64);
}

/** Minutes left on an active lockout for this key, or 0 if not locked. */
function auth_lock_minutes_left(PDO $pdo, string $key): int {
    $stmt = $pdo->prepare('SELECT locked_until FROM login_attempts WHERE username = ?');
    $stmt->execute([$key]);
    $until = $stmt->fetchColumn();
    if (!$until) return 0;

    $left = strtotime($until) - time();
    return $left > 0 ? (int)ceil($left / 60) : 0;
}

/**
 * Record one failed attempt.
 * @return array{locked: bool, remaining: int}
 */
function auth_record_failure(PDO $pdo, string $key, int $max_attempts, int $lock_minutes): array {
    // Housekeeping: forget entries untouched for a day, so someone
    // spamming random usernames can't make this table grow forever.
    $pdo->exec('DELETE FROM login_attempts WHERE updated_at < (NOW() - INTERVAL 1 DAY)');

    $stmt = $pdo->prepare('SELECT attempts FROM login_attempts WHERE username = ?');
    $stmt->execute([$key]);
    $attempts = (int)$stmt->fetchColumn() + 1;

    if ($attempts >= $max_attempts) {
        $lock_until = date('Y-m-d H:i:s', time() + $lock_minutes * 60);
        $pdo->prepare('
            INSERT INTO login_attempts (username, attempts, locked_until) VALUES (?, 0, ?)
            ON DUPLICATE KEY UPDATE attempts = 0, locked_until = VALUES(locked_until)
        ')->execute([$key, $lock_until]);
        return ['locked' => true, 'remaining' => 0];
    }

    $pdo->prepare('
        INSERT INTO login_attempts (username, attempts, locked_until) VALUES (?, ?, NULL)
        ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), locked_until = NULL
    ')->execute([$key, $attempts]);
    return ['locked' => false, 'remaining' => $max_attempts - $attempts];
}

/** Forget the failures for this key (call after a successful login). */
function auth_clear_attempts(PDO $pdo, string $key): void {
    $pdo->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$key]);
}
