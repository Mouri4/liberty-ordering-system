<?php
// ── Liberty Foodmart — Admin Forgot Password ────────────────
// Same three steps as the customer page, for the ADMIN account only:
//   1. Enter the admin's recovery email → a 6-digit PIN is emailed
//   2. Enter the PIN                    → proves the person owns the email
//   3. Choose a new password            → saved, then back to login.php
//
// Safety rules built in:
//   • The PIN is stored hashed, expires after 10 minutes, and dies after
//     5 wrong tries.
//   • PIN requests are throttled per email + device (5, then a 15-minute
//     pause), per email across ALL devices (8 per hour), and there is a
//     60-second wait between "Resend" clicks. The all-devices limit stops
//     someone from grinding PIN guesses from many computers.
//   • The page never says whether an email belongs to an admin or not.
//   • Only accounts with role = 'admin' can use this. Staff accounts are
//     reset by the admin on admin-staff.php.
//   • The new-password step only opens after the PIN was verified, and the
//     new password must pass the same strong policy as everywhere else.
//   • Changing the password signs out any other open session of that
//     account automatically (see auth.php).
// ------------------------------------------------------------
require_once 'session.php';
require 'db.php';
require_once 'auth.php';
require_once 'mailer.php';
require_once 'password-policy.php';

// Already signed in? No reason to be here.
if (!empty($_SESSION['staff_id'])) {
    header('Location: inventory.php');
    exit;
}

const PIN_MINUTES   = 10;   // how long a PIN stays valid
const PIN_MAX_TRIES = 5;    // wrong PINs allowed per PIN
const RESEND_SECS   = 60;   // wait between "send PIN" requests

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** j***@gmail.com — shows which inbox to check without exposing it fully. */
function mask_email(string $email): string {
    [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return substr($user, 0, 1) . str_repeat('*', max(2, strlen($user) - 1)) . '@' . $domain;
}

function sreset_clear_session(): void {
    unset($_SESSION['sreset_email'], $_SESSION['sreset_sent_at'], $_SESSION['sreset_verified_id'], $_SESSION['sreset_flash']);
}

$error  = '';
$info   = '';
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : '';

// ── Start over ───────────────────────────────────────────────
if ($action === 'restart') {
    sreset_clear_session();
    header('Location: admin-forgot-password.php');
    exit;
}

// ── STEP 1 (and Resend): email the PIN ───────────────────────
if ($action === 'send_pin' || $action === 'resend') {
    $email = $action === 'resend'
        ? (string)($_SESSION['sreset_email'] ?? '')
        : trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $wait  = RESEND_SECS - (time() - (int)($_SESSION['sreset_sent_at'] ?? 0));
        $key   = auth_attempt_key('staff-reset-send', $email);                                   // this email + this device
        $akey  = substr(hash('sha256', 'staff-reset-acct|' . strtolower($email)), 0, 64);        // this email, any device
        $mins  = max(auth_lock_minutes_left($pdo, $key), auth_lock_minutes_left($pdo, $akey));

        if ($action === 'resend' && $wait > 0) {
            $error = 'Please wait ' . $wait . ' second' . ($wait === 1 ? '' : 's') . ' before asking for another PIN.';
        } elseif ($mins > 0) {
            $error = 'Too many PIN requests. Please try again in ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.';
        } else {
            // Count this request toward both limits (counted whether or not the email exists)
            auth_record_failure($pdo, $key, 5, 15);
            auth_record_failure($pdo, $akey, 8, 60);

            // Tidy up very old rows
            $pdo->exec('DELETE FROM staff_password_resets WHERE expires_at < (NOW() - INTERVAL 1 DAY)');

            $stmt = $pdo->prepare("SELECT id, username, email FROM staff_users WHERE email = ? AND role = 'admin' LIMIT 1");
            $stmt->execute([$email]);
            $admin = $stmt->fetch();

            $sent_ok = true;
            if ($admin) {
                $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

                // One active PIN per admin: a new request replaces the old one
                $pdo->prepare('
                    INSERT INTO staff_password_resets (staff_id, pin_hash, attempts, verified, expires_at, created_at)
                    VALUES (?, ?, 0, 0, DATE_ADD(NOW(), INTERVAL ' . PIN_MINUTES . ' MINUTE), NOW())
                    ON DUPLICATE KEY UPDATE pin_hash = VALUES(pin_hash), attempts = 0, verified = 0,
                                            expires_at = VALUES(expires_at), created_at = NOW()
                ')->execute([(int)$admin['id'], password_hash($pin, PASSWORD_DEFAULT)]);

                $uname = e((string)$admin['username']);
                $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:auto;color:#3B2417;">'
                      . '<h2 style="color:#8B4513;margin-bottom:4px;">PastryLiberty — Admin</h2>'
                      . '<p>Hi ' . $uname . ',</p>'
                      . '<p>Use this PIN to reset the admin password:</p>'
                      . '<p style="font-size:32px;font-weight:bold;letter-spacing:8px;background:#FBF3E7;border:1px solid #E8D5B7;border-radius:10px;padding:14px 0;text-align:center;">' . $pin . '</p>'
                      . '<p>This PIN expires in ' . PIN_MINUTES . ' minutes. If you did not ask to reset the admin password, ignore this email — the password will stay the same.</p>'
                      . '<p style="color:#A98A6D;font-size:12px;">Never share this PIN with anyone.</p></div>';
                $text = "Hi {$admin['username']},\n\nYour PastryLiberty admin password reset PIN is: $pin\n"
                      . "It expires in " . PIN_MINUTES . " minutes. If you did not ask for this, ignore this email.\n";

                $sent_ok = send_mail((string)$admin['email'], 'Your PastryLiberty admin password reset PIN', $html, $text);
                if (!$sent_ok) {
                    $pdo->prepare('DELETE FROM staff_password_resets WHERE staff_id = ?')->execute([(int)$admin['id']]);
                }
            }

            if (!$sent_ok) {
                $error = 'We could not send the email right now. Please try again later.';
            } else {
                // Same answer whether or not the email belongs to an admin
                $_SESSION['sreset_email']   = $email;
                $_SESSION['sreset_sent_at'] = time();
                unset($_SESSION['sreset_verified_id']);
                // Redirect so a browser refresh can't send another PIN
                $_SESSION['sreset_flash'] = 'If that email belongs to the admin account, we sent a 6-digit PIN to it. Check your inbox (and spam folder).';
                header('Location: admin-forgot-password.php');
                exit;
            }
        }
    }
}

// ── STEP 2: check the PIN ────────────────────────────────────
if ($action === 'verify_pin' && !empty($_SESSION['sreset_email'])) {
    $pin = preg_replace('/\D/', '', (string)($_POST['pin'] ?? ''));

    if (strlen($pin) !== 6) {
        $error = 'Please enter the 6-digit PIN.';
    } else {
        $stmt = $pdo->prepare("
            SELECT pr.staff_id, pr.pin_hash, pr.attempts
            FROM staff_password_resets pr
            JOIN staff_users s ON s.id = pr.staff_id
            WHERE s.email = ? AND s.role = 'admin' AND pr.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$_SESSION['sreset_email']]);
        $row = $stmt->fetch();

        if (!$row) {
            $error = 'That PIN is incorrect or has expired.';
        } elseif ((int)$row['attempts'] >= PIN_MAX_TRIES) {
            $pdo->prepare('DELETE FROM staff_password_resets WHERE staff_id = ?')->execute([(int)$row['staff_id']]);
            $error = 'Too many wrong tries. Please request a new PIN.';
        } elseif (!password_verify($pin, $row['pin_hash'])) {
            $tries = (int)$row['attempts'] + 1;
            if ($tries >= PIN_MAX_TRIES) {
                $pdo->prepare('DELETE FROM staff_password_resets WHERE staff_id = ?')->execute([(int)$row['staff_id']]);
                $error = 'Too many wrong tries. Please request a new PIN.';
            } else {
                $pdo->prepare('UPDATE staff_password_resets SET attempts = ? WHERE staff_id = ?')->execute([$tries, (int)$row['staff_id']]);
                $error = 'That PIN is incorrect or has expired.';
            }
        } else {
            // Correct. Give them a fresh window to type the new password.
            $pdo->prepare('UPDATE staff_password_resets SET verified = 1, expires_at = DATE_ADD(NOW(), INTERVAL ' . PIN_MINUTES . ' MINUTE) WHERE staff_id = ?')
                ->execute([(int)$row['staff_id']]);
            session_regenerate_id(true);
            $_SESSION['sreset_verified_id'] = (int)$row['staff_id'];
            $_SESSION['sreset_flash'] = 'PIN verified. Choose your new password.';
            header('Location: admin-forgot-password.php');
            exit;
        }
    }
}

// ── STEP 3: save the new password ────────────────────────────
if ($action === 'set_password') {
    $sid = (int)($_SESSION['sreset_verified_id'] ?? 0);
    $new = (string)($_POST['password'] ?? '');
    $cfm = (string)($_POST['confirm']  ?? '');

    $row = null;
    if ($sid > 0) {
        $stmt = $pdo->prepare("
            SELECT s.id, s.username, s.email, s.password_hash
            FROM staff_password_resets pr
            JOIN staff_users s ON s.id = pr.staff_id
            WHERE pr.staff_id = ? AND pr.verified = 1 AND pr.expires_at > NOW() AND s.role = 'admin'
        ");
        $stmt->execute([$sid]);
        $row = $stmt->fetch();
    }

    if ($sid <= 0) {
        $error = 'Please verify your PIN first.';
    } elseif (!$row) {
        sreset_clear_session();
        $error = 'Your verification expired. Please start again.';
    } elseif ($pw_err = staff_password_error($new, [(string)$row['username']])) {
        $error = $pw_err;
    } elseif ($new !== $cfm) {
        $error = 'Passwords do not match.';
    } elseif (password_verify($new, $row['password_hash'])) {
        $error = 'Your new password must be different from the current one.';
    } else {
        $pdo->prepare('UPDATE staff_users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $sid]);
        $pdo->prepare('DELETE FROM staff_password_resets WHERE staff_id = ?')->execute([$sid]);

        // They may have been locked out by failed logins — let them straight in
        auth_clear_attempts($pdo, auth_attempt_key('staff', (string)$row['username']));
        auth_clear_attempts($pdo, auth_attempt_key('staff-reset-send', (string)$row['email']));

        // Heads-up email in case it wasn't really them (failure here is ignored)
        send_mail(
            (string)$row['email'],
            'Your PastryLiberty admin password was changed',
            '<div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:auto;color:#3B2417;"><h2 style="color:#8B4513;">PastryLiberty — Admin</h2><p>Hi ' . e((string)$row['username']) . ',</p><p>The admin password was just changed. If this was you, no action is needed. If it was not you, reset the password again right away.</p></div>',
            "Hi {$row['username']},\n\nThe PastryLiberty admin password was just changed. If this was not you, reset it again right away.\n"
        );

        sreset_clear_session();
        session_regenerate_id(true);
        header('Location: login.php?reset=1');
        exit;
    }
}

// ── Message carried over from the redirect ───────────────────
if (isset($_SESSION['sreset_flash'])) {
    $info = (string)$_SESSION['sreset_flash'];
    unset($_SESSION['sreset_flash']);
}

// ── Which step to show ───────────────────────────────────────
$step = 'email';
if (!empty($_SESSION['sreset_email']))       $step = 'pin';
if (!empty($_SESSION['sreset_verified_id'])) $step = 'password';

$resend_in = max(0, RESEND_SECS - (time() - (int)($_SESSION['sreset_sent_at'] ?? 0)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Forgot Password — Liberty Foodmart</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --choc-dark:#3B2417; --choc:#5C3A24; --cream:#FBF3E7; --cream-deep:#F1E4D3;
            --caramel:#C17817; --caramel-deep:#8B4513; --muted:#A98A6D; --border:#E8D5B7;
        }
        * { box-sizing:border-box; margin:0; padding:0; }
        body {
            min-height:100vh; background:var(--cream); font-family:'Inter',Arial,sans-serif;
            display:flex; align-items:center; justify-content:center; padding:1.5rem;
        }
        .card {
            width:100%; max-width:26rem; background:#fff; border:1px solid var(--border);
            border-radius:1.1rem; padding:2rem 1.8rem; box-shadow:0 10px 32px rgba(59,36,23,.12);
        }
        .eyebrow { font-size:.65rem; letter-spacing:.2em; font-weight:700; color:var(--caramel-deep); text-transform:uppercase; text-align:center; }
        h1 { font-family:'Playfair Display',serif; font-size:1.7rem; color:var(--choc-dark); text-align:center; margin:.3rem 0 .3rem; }
        .sub { font-size:.8rem; color:var(--muted); text-align:center; line-height:1.5; margin-bottom:.4rem; }
        .steps { display:flex; justify-content:center; gap:.4rem; margin:.8rem 0 1rem; }
        .steps span { width:2rem; height:.3rem; border-radius:1rem; background:#eadfce; }
        .steps span.on { background:var(--caramel); }

        .error-msg, .info-msg { border-radius:.6rem; padding:.6rem .8rem; font-size:.8rem; text-align:center; margin-bottom:.8rem; }
        .error-msg { background:#fdecea; color:#c62828; border:1px solid #f5c6cb; }
        .info-msg  { background:#e8f5e9; color:#2e7d32; border:1px solid #c8e6c9; }

        .form { display:flex; flex-direction:column; gap:.8rem; }
        .inp {
            width:100%; padding:.8rem 1rem; border-radius:.7rem; border:1.5px solid var(--border);
            background:var(--cream); color:var(--choc-dark); font-family:inherit; font-size:.9rem;
        }
        .inp:focus { outline:none; border-color:var(--caramel); box-shadow:0 0 0 4px rgba(193,120,23,.15); }
        .inp.pin { text-align:center; font-size:1.6rem; letter-spacing:.6rem; font-weight:700; }
        .pass-wrap { position:relative; display:flex; align-items:center; }
        .pass-wrap .inp { padding-right:2.6rem; }
        .eye-btn { position:absolute; right:10px; background:none; border:none; cursor:pointer; padding:0; display:flex; }
        .eye-btn img { width:16px; height:16px; opacity:.55; }
        .submit-btn {
            padding:.8rem; border:none; border-radius:.7rem; background:var(--choc-dark); color:#fff;
            font-weight:700; font-size:.9rem; cursor:pointer; font-family:inherit;
        }
        .submit-btn:hover { background:var(--caramel-deep); }

        .links { display:flex; justify-content:space-between; align-items:center; margin-top:1rem; font-size:.78rem; flex-wrap:wrap; gap:.4rem; }
        .links form { display:inline; }
        .linklike { background:none; border:none; color:var(--caramel-deep); font-size:.78rem; cursor:pointer; font-family:inherit; padding:0; }
        .linklike:hover:not(:disabled) { text-decoration:underline; }
        .linklike:disabled { color:var(--muted); cursor:not-allowed; }
        .back { color:var(--caramel-deep); text-decoration:none; font-size:.78rem; }
        .back:hover { text-decoration:underline; }
        .hint { font-size:.74rem; color:var(--muted); }
        .note { margin-top:1rem; font-size:.72rem; color:var(--muted); text-align:center; line-height:1.5; }

        .pw-rules { list-style:none; font-size:.74rem; color:var(--muted); }
        .pw-rules li { margin-bottom:.15rem; }
        .pw-rules li::before { content:'○ '; }
        .pw-rules li.ok { color:#2e7d32; font-weight:600; }
        .pw-rules li.ok::before { content:'✓ '; }
    </style>
</head>
<body>
<main class="card">
    <p class="eyebrow">Employee Portal</p>
    <h1>Forgot Password</h1>

    <div class="steps">
        <span class="on"></span>
        <span class="<?= $step !== 'email' ? 'on' : '' ?>"></span>
        <span class="<?= $step === 'password' ? 'on' : '' ?>"></span>
    </div>

    <?php if ($step === 'email'): ?>
        <p class="sub">Enter the admin's recovery email and we will send a 6-digit PIN to it.</p>
    <?php elseif ($step === 'pin'): ?>
        <p class="sub">We sent a 6-digit PIN to <strong><?= e(mask_email((string)$_SESSION['sreset_email'])) ?></strong>.<br>It is valid for <?= PIN_MINUTES ?> minutes.</p>
    <?php else: ?>
        <p class="sub">Choose a new password for the admin account.</p>
    <?php endif; ?>

    <?php if ($error): ?><div class="error-msg"><?= e($error) ?></div><?php endif; ?>
    <?php if ($info):  ?><div class="info-msg"><?= e($info) ?></div><?php endif; ?>

    <?php if ($step === 'email'): ?>
        <form class="form" method="POST" action="admin-forgot-password.php">
            <input type="hidden" name="action" value="send_pin">
            <input class="inp" type="email" name="email" placeholder="Admin recovery email" value="<?= e($_POST['email'] ?? '') ?>" required autofocus autocomplete="email">
            <button type="submit" class="submit-btn">Send PIN</button>
        </form>
        <p class="note">Only the admin account can reset its password here.<br>Staff: please ask your admin to reset your password.</p>

    <?php elseif ($step === 'pin'): ?>
        <form class="form" method="POST" action="admin-forgot-password.php">
            <input type="hidden" name="action" value="verify_pin">
            <input class="inp pin" type="text" name="pin" placeholder="••••••" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required autofocus>
            <button type="submit" class="submit-btn">Verify PIN</button>
        </form>
        <div class="links">
            <form method="POST" action="admin-forgot-password.php">
                <input type="hidden" name="action" value="resend">
                <button type="submit" class="linklike" id="resendBtn" <?= $resend_in > 0 ? 'disabled' : '' ?>>Resend PIN</button>
                <span id="resendTimer" class="hint"></span>
            </form>
            <form method="POST" action="admin-forgot-password.php">
                <input type="hidden" name="action" value="restart">
                <button type="submit" class="linklike">Use a different email</button>
            </form>
        </div>

    <?php else: ?>
        <form class="form" method="POST" action="admin-forgot-password.php">
            <input type="hidden" name="action" value="set_password">
            <div class="pass-wrap">
                <input class="inp" type="password" id="newPass" name="password" placeholder="New password" maxlength="72" autocomplete="new-password" required autofocus>
                <button type="button" class="eye-btn" data-target="newPass"><img src="src/show-eye-removebg-preview.png" alt="show"></button>
            </div>
            <ul class="pw-rules" id="newRules"></ul>
            <div class="pass-wrap">
                <input class="inp" type="password" id="confirmPass" name="confirm" placeholder="Confirm new password" maxlength="72" autocomplete="new-password" required>
                <button type="button" class="eye-btn" data-target="confirmPass"><img src="src/show-eye-removebg-preview.png" alt="show"></button>
            </div>
            <button type="submit" class="submit-btn">Change Password</button>
        </form>
    <?php endif; ?>

    <div class="links" style="justify-content:center;">
        <a class="back" href="login.php">← Back to login</a>
    </div>
</main>

<script>
// Show / hide password
document.querySelectorAll('.eye-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const inp = document.getElementById(btn.dataset.target);
        const hide = inp.type === 'password';
        inp.type = hide ? 'text' : 'password';
        btn.querySelector('img').src = hide ? 'src/hide-eye-removebg-preview.png' : 'src/show-eye-removebg-preview.png';
    });
});

// PIN box: digits only
const pinBox = document.querySelector('input.pin');
if (pinBox) pinBox.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 6); });

// Resend countdown (the server enforces the same wait)
let wait = <?= (int)$resend_in ?>;
const rb = document.getElementById('resendBtn'), rt = document.getElementById('resendTimer');
if (rb && wait > 0) {
    rt.textContent = '(' + wait + 's)';
    const t = setInterval(function () {
        wait--;
        if (wait <= 0) { clearInterval(t); rb.disabled = false; rt.textContent = ''; }
        else rt.textContent = '(' + wait + 's)';
    }, 1000);
}

// Live password checklist (the server checks the same rules, plus common words and names)
const rules = document.getElementById('newRules');
if (rules) {
    const PW_RULES = [
        ['At least 10 characters',    p => p.length >= 10],
        ['An uppercase letter (A–Z)', p => /[A-Z]/.test(p)],
        ['A lowercase letter (a–z)',  p => /[a-z]/.test(p)],
        ['A number (0–9)',            p => /[0-9]/.test(p)],
        ['A symbol (! @ # $ % …)',    p => /[^A-Za-z0-9]/.test(p)],
    ];
    const inp = document.getElementById('newPass');
    const items = PW_RULES.map(function (r) {
        const li = document.createElement('li');
        li.textContent = r[0];
        rules.appendChild(li);
        return li;
    });
    function update() {
        let all = true;
        PW_RULES.forEach(function (r, i) {
            const ok = r[1](inp.value);
            items[i].classList.toggle('ok', ok);
            if (!ok) all = false;
        });
        inp.setCustomValidity(all ? '' : 'Password does not meet all the requirements.');
    }
    inp.addEventListener('input', update);
    update();
}
</script>
</body>
</html>