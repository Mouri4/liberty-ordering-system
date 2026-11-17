<?php
// ── Liberty Foodmart — Customer Forgot Password ─────────────
// Three steps on one page:
//   1. Enter the account email  → a 6-digit PIN is emailed
//   2. Enter the PIN            → proves the person owns the email
//   3. Choose a new password    → saved, then back to the login page
//
// Safety rules built in:
//   • The PIN is stored hashed, expires after 10 minutes, and dies after
//     5 wrong tries.
//   • Asking for a PIN is throttled (5 requests, then a 15-minute pause)
//     plus a 60-second wait between "Resend" clicks.
//   • The page never says whether an email is registered or not.
//   • The new-password step only opens after the PIN was verified.
// ------------------------------------------------------------
require_once 'session.php';
require 'db.php';
require_once 'auth.php';
require_once 'mailer.php';

// Already logged in? No reason to be here.
if (!empty($_SESSION['customer_id'])) {
    header('Location: ordering-page.php');
    exit;
}

const PIN_MINUTES   = 10;   // how long a PIN stays valid
const PIN_MAX_TRIES = 5;    // wrong PINs allowed per PIN
const RESEND_SECS   = 60;   // wait between "send PIN" requests
const MIN_PASSWORD  = 6;    // same rule as registration

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** j***@gmail.com — shows the person which inbox to check without exposing it fully. */
function mask_email(string $email): string {
    [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return substr($user, 0, 1) . str_repeat('*', max(2, strlen($user) - 1)) . '@' . $domain;
}

function reset_clear_session(): void {
    unset($_SESSION['reset_email'], $_SESSION['reset_sent_at'], $_SESSION['reset_verified_cid'], $_SESSION['reset_flash']);
}

$error = '';
$info  = '';
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : '';

// ── Start over ───────────────────────────────────────────────
if ($action === 'restart') {
    reset_clear_session();
    header('Location: customer-forgot-password.php');
    exit;
}

// ── STEP 1 (and Resend): email the PIN ───────────────────────
if ($action === 'send_pin' || $action === 'resend') {
    $email = $action === 'resend'
        ? (string)($_SESSION['reset_email'] ?? '')
        : trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $wait = RESEND_SECS - (time() - (int)($_SESSION['reset_sent_at'] ?? 0));
        $key  = auth_attempt_key('reset-send', $email);
        $mins = auth_lock_minutes_left($pdo, $key);

        if ($action === 'resend' && $wait > 0) {
            $error = 'Please wait ' . $wait . ' second' . ($wait === 1 ? '' : 's') . ' before asking for another PIN.';
        } elseif ($mins > 0) {
            $error = 'Too many PIN requests. Please try again in ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.';
        } else {
            // Count this request toward the limit (5 requests → 15-minute pause)
            auth_record_failure($pdo, $key, 5, 15);

            // Tidy up very old rows
            $pdo->exec('DELETE FROM password_resets WHERE expires_at < (NOW() - INTERVAL 1 DAY)');

            $stmt = $pdo->prepare('SELECT id, name, email FROM customers WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $customer = $stmt->fetch();

            $sent_ok = true;
            if ($customer) {
                $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

                // One active PIN per customer: a new request replaces the old one
                $pdo->prepare('
                    INSERT INTO password_resets (customer_id, pin_hash, attempts, verified, expires_at, created_at)
                    VALUES (?, ?, 0, 0, DATE_ADD(NOW(), INTERVAL ' . PIN_MINUTES . ' MINUTE), NOW())
                    ON DUPLICATE KEY UPDATE pin_hash = VALUES(pin_hash), attempts = 0, verified = 0,
                                            expires_at = VALUES(expires_at), created_at = NOW()
                ')->execute([(int)$customer['id'], password_hash($pin, PASSWORD_DEFAULT)]);

                $name = e((string)$customer['name']);
                $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:auto;color:#3B2417;">'
                      . '<h2 style="color:#8B4513;margin-bottom:4px;">PastryLiberty</h2>'
                      . '<p>Hi ' . $name . ',</p>'
                      . '<p>Use this PIN to reset your password:</p>'
                      . '<p style="font-size:32px;font-weight:bold;letter-spacing:8px;background:#FBF3E7;border:1px solid #E8D5B7;border-radius:10px;padding:14px 0;text-align:center;">' . $pin . '</p>'
                      . '<p>This PIN expires in ' . PIN_MINUTES . ' minutes. If you did not ask to reset your password, you can ignore this email — your password will stay the same.</p>'
                      . '<p style="color:#A98A6D;font-size:12px;">Never share this PIN with anyone.</p></div>';
                $text = "Hi {$customer['name']},\n\nYour PastryLiberty password reset PIN is: $pin\n"
                      . "It expires in " . PIN_MINUTES . " minutes. If you did not ask for this, ignore this email.\n";

                $sent_ok = send_mail((string)$customer['email'], 'Your PastryLiberty password reset PIN', $html, $text);
                if (!$sent_ok) {
                    $pdo->prepare('DELETE FROM password_resets WHERE customer_id = ?')->execute([(int)$customer['id']]);
                }
            }

            if (!$sent_ok) {
                $error = 'We could not send the email right now. Please try again later.';
            } else {
                // Same answer whether or not the email is registered
                $_SESSION['reset_email']   = $email;
                $_SESSION['reset_sent_at'] = time();
                unset($_SESSION['reset_verified_cid']);
                // Redirect so a browser refresh can't send another PIN
                $_SESSION['reset_flash'] = 'If that email is registered, we sent a 6-digit PIN to it. Check your inbox (and spam folder).';
                header('Location: customer-forgot-password.php');
                exit;
            }
        }
    }
}

// ── STEP 2: check the PIN ────────────────────────────────────
if ($action === 'verify_pin' && !empty($_SESSION['reset_email'])) {
    $pin = preg_replace('/\D/', '', (string)($_POST['pin'] ?? ''));

    if (strlen($pin) !== 6) {
        $error = 'Please enter the 6-digit PIN.';
    } else {
        $stmt = $pdo->prepare('
            SELECT pr.customer_id, pr.pin_hash, pr.attempts
            FROM password_resets pr
            JOIN customers c ON c.id = pr.customer_id
            WHERE c.email = ? AND pr.expires_at > NOW()
            LIMIT 1
        ');
        $stmt->execute([$_SESSION['reset_email']]);
        $row = $stmt->fetch();

        if (!$row) {
            $error = 'That PIN is incorrect or has expired.';
        } elseif ((int)$row['attempts'] >= PIN_MAX_TRIES) {
            $pdo->prepare('DELETE FROM password_resets WHERE customer_id = ?')->execute([(int)$row['customer_id']]);
            $error = 'Too many wrong tries. Please request a new PIN.';
        } elseif (!password_verify($pin, $row['pin_hash'])) {
            $tries = (int)$row['attempts'] + 1;
            if ($tries >= PIN_MAX_TRIES) {
                $pdo->prepare('DELETE FROM password_resets WHERE customer_id = ?')->execute([(int)$row['customer_id']]);
                $error = 'Too many wrong tries. Please request a new PIN.';
            } else {
                $pdo->prepare('UPDATE password_resets SET attempts = ? WHERE customer_id = ?')->execute([$tries, (int)$row['customer_id']]);
                $error = 'That PIN is incorrect or has expired.';
            }
        } else {
            // Correct. Give them a fresh window to type the new password.
            $pdo->prepare('UPDATE password_resets SET verified = 1, expires_at = DATE_ADD(NOW(), INTERVAL ' . PIN_MINUTES . ' MINUTE) WHERE customer_id = ?')
                ->execute([(int)$row['customer_id']]);
            session_regenerate_id(true);
            $_SESSION['reset_verified_cid'] = (int)$row['customer_id'];
            $_SESSION['reset_flash'] = 'PIN verified. Choose your new password.';
            header('Location: customer-forgot-password.php');
            exit;
        }
    }
}

// ── STEP 3: save the new password ────────────────────────────
if ($action === 'set_password') {
    $cid = (int)($_SESSION['reset_verified_cid'] ?? 0);
    $new = (string)($_POST['password'] ?? '');
    $cfm = (string)($_POST['confirm']  ?? '');

    $row = null;
    if ($cid > 0) {
        $stmt = $pdo->prepare('SELECT customer_id FROM password_resets WHERE customer_id = ? AND verified = 1 AND expires_at > NOW()');
        $stmt->execute([$cid]);
        $row = $stmt->fetch();
    }

    if ($cid <= 0) {
        $error = 'Please verify your PIN first.';
    } elseif (!$row) {
        reset_clear_session();
        $error = 'Your verification expired. Please start again.';
    } elseif (strlen($new) < MIN_PASSWORD) {
        $error = 'Password must be at least ' . MIN_PASSWORD . ' characters.';
    } elseif ($new !== $cfm) {
        $error = 'Passwords do not match.';
    } else {
        $pdo->prepare('UPDATE customers SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $cid]);
        $pdo->prepare('DELETE FROM password_resets WHERE customer_id = ?')->execute([$cid]);

        // They may have been locked out by failed logins — let them straight in
        $who = $pdo->prepare('SELECT name, email FROM customers WHERE id = ?');
        $who->execute([$cid]);
        $cust = $who->fetch();
        if ($cust) {
            auth_clear_attempts($pdo, auth_attempt_key('customer', $cust['email']));
            auth_clear_attempts($pdo, auth_attempt_key('reset-send', $cust['email']));

            // Heads-up email in case it wasn't really them (failure here is ignored)
            send_mail(
                (string)$cust['email'],
                'Your PastryLiberty password was changed',
                '<div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:auto;color:#3B2417;"><h2 style="color:#8B4513;">PastryLiberty</h2><p>Hi ' . e((string)$cust['name']) . ',</p><p>Your password was just changed. If this was you, no action is needed. If it was not you, please reset your password again right away.</p></div>',
                "Hi {$cust['name']},\n\nYour PastryLiberty password was just changed. If this was not you, please reset it again right away.\n"
            );
        }

        reset_clear_session();
        session_regenerate_id(true);
        header('Location: customer-login.php?reset=1');
        exit;
    }
}

// ── Message carried over from the redirect ───────────────────
if (isset($_SESSION['reset_flash'])) {
    $info = (string)$_SESSION['reset_flash'];
    unset($_SESSION['reset_flash']);
}

// ── Which step to show ───────────────────────────────────────
$step = 'email';
if (!empty($_SESSION['reset_email']))        $step = 'pin';
if (!empty($_SESSION['reset_verified_cid'])) $step = 'password';

$resend_in = max(0, RESEND_SECS - (time() - (int)($_SESSION['reset_sent_at'] ?? 0)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — Liberty Foodmart</title>
    <style>
        * { box-sizing:border-box; margin:0; padding:0; }
        html { --mx:50%; --my:50%; }
        body {
            height:100vh; width:100%; overflow:hidden;
            background:#FBF6EE;
            display:flex; align-items:center; justify-content:center;
            position:relative; font-family:Arial,Helvetica,sans-serif;
        }

        /* ── Same flowing background as the login page ───────── */
        .liquid-bg {
            position:fixed; inset:-10%; z-index:0;
            background:
                radial-gradient(at 20% 30%, #EA8E16 0px, transparent 55%),
                radial-gradient(at 80% 15%, #E85C4A 0px, transparent 55%),
                radial-gradient(at 25% 80%, #F4C430 0px, transparent 55%),
                radial-gradient(at 85% 85%, #d19965 0px, transparent 55%),
                linear-gradient(135deg, #FBF6EE, #FDEFE0);
            background-size:200% 200%, 200% 200%, 200% 200%, 200% 200%, 100% 100%;
            filter:blur(70px) saturate(135%);
            animation:liquidFlow 22s ease-in-out infinite;
        }
        @keyframes liquidFlow {
            0%   { background-position:20% 30%, 80% 15%, 25% 80%, 85% 85%, 0 0; }
            25%  { background-position:45% 65%, 55% 35%, 65% 25%, 35% 70%, 0 0; }
            50%  { background-position:75% 20%, 20% 75%, 80% 60%, 15% 25%, 0 0; }
            75%  { background-position:30% 75%, 70% 25%, 35% 15%, 70% 65%, 0 0; }
            100% { background-position:20% 30%, 80% 15%, 25% 80%, 85% 85%, 0 0; }
        }
        .cursor-glow {
            position:fixed; inset:0; z-index:1; pointer-events:none;
            background:radial-gradient(650px circle at var(--mx) var(--my), rgba(255,255,255,0.45), transparent 60%);
            mix-blend-mode:overlay;
        }
        @media (prefers-reduced-motion: reduce) {
            .liquid-bg { animation:none; }
            .cursor-glow { display:none; }
        }

        .main {
            position:relative; z-index:2;
            border-radius:1.25rem; height:85%; width:65%; min-height:30rem;
            background-color:#fff;
            border:1px solid rgba(255,255,255,0.45);
            box-shadow:0 12px 40px rgba(43,35,24,0.18), inset 0 1px 0 rgba(255,255,255,0.5);
            padding:2%; display:flex; gap:1%; align-items:center;
        }
        .cont { height:95%; width:50%; border-radius:1rem; overflow:hidden; }
        #image-cont { background-image:url(src/log-image2.jpg); background-repeat:no-repeat; background-size:cover; background-position:center; }

        .title { margin:auto; height:13%; width:90%; overflow:hidden; display:flex; justify-content:space-between; align-items:center; }
        .title img { height:100%; width:20%; }
        .title h3 { margin:0; color:#ea8e16; }

        .panel { height:87%; display:flex; flex-direction:column; align-items:center; overflow-y:auto; padding:0 4% 1rem; }
        .panel h1 { font-weight:790; color:#ea8e16; text-align:center; font-size:1.6rem; }
        .panel > p.sub { margin-top:.4rem; font-size:.75rem; color:grey; text-align:center; line-height:1.5; }

        .steps { display:flex; gap:.4rem; margin:.9rem 0 0; }
        .steps span { width:2rem; height:.3rem; border-radius:1rem; background:#eadfce; }
        .steps span.on { background:#ea8e16; }

        .form { margin-top:1.4rem; width:80%; display:flex; flex-direction:column; align-items:stretch; gap:.9rem; }
        .form input { width:100%; padding:.8rem 1.1rem; border-radius:2rem; border:1px solid; font-size:.9rem; }
        .form input.pin { text-align:center; font-size:1.5rem; letter-spacing:.6rem; font-weight:bold; padding-left:1.6rem; }
        .submit-btn { width:100%; padding:.8rem; border-radius:2rem; border:1px solid; background-color:#ea8e16; color:white; font-weight:bold; cursor:pointer; font-size:.9rem; }
        .submit-btn:hover { background-color:#c97a10; }

        .pass-wrap { width:100%; position:relative; display:flex; align-items:center; }
        .pass-wrap input { padding-right:2.8rem; }
        .eye-btn { position:absolute; right:12px; background:none; border:none; cursor:pointer; padding:0; line-height:1; display:flex; align-items:center; }
        .eye-btn img { width:16px; height:16px; object-fit:contain; opacity:.6; }

        .links { margin-top:1rem; width:80%; display:flex; flex-direction:column; align-items:center; gap:.45rem; font-size:.72rem; color:gray; text-align:center; }
        .links a, .linklike { color:#ea8e16; text-decoration:none; font-weight:600; background:none; border:none; cursor:pointer; font-size:.72rem; font-family:inherit; }
        .linklike:disabled { color:#b9b0a3; cursor:not-allowed; }
        .links form { display:inline; }

        .error-msg { background:#fdecea; color:#c62828; border:1px solid #f5c6cb; border-radius:.5rem; padding:8px 12px; font-size:.75rem; width:80%; text-align:center; margin-top:.9rem; }
        .info-msg  { background:#e8f5e9; color:#2e7d32; border:1px solid #c8e6c9; border-radius:.5rem; padding:8px 12px; font-size:.75rem; width:80%; text-align:center; margin-top:.9rem; line-height:1.45; }
        .hint { font-size:.68rem; color:grey; text-align:center; }

        /* ── Phones: single column, auto height ─────────────── */
        @media (max-width:700px){
            body{ height:auto; min-height:100vh; overflow-y:auto; padding:2rem 1rem; }
            .main{ width:100%; max-width:26rem; height:auto; min-height:0; flex-direction:column; padding:0; gap:0; }
            .cont{ width:100%; height:auto; border-radius:1.25rem; }
            #image-cont{ display:none; }
            .title{ height:auto; width:100%; padding:1.7rem 1.7rem 0; margin:0; }
            .title img{ height:2.6rem; width:auto; }
            .title h3{ font-size:1rem; }
            .panel{ height:auto; padding:1.2rem 1.7rem 2rem; overflow:visible; }
            .form, .links, .error-msg, .info-msg { width:100%; }
            .form input{ font-size:16px; min-height:2.9rem; }
            .submit-btn{ min-height:2.9rem; font-size:.95rem; }
            .links, .linklike, .links a{ font-size:.8rem; }
        }
    </style>
</head>
<body>
<div class="liquid-bg"></div>
<div class="cursor-glow"></div>
<main class="main">
    <div class="cont">
        <div class="title">
            <img src="src/logo.jpg" alt="Liberty Foodmart">
            <h3>RESET PASSWORD</h3>
        </div>

        <div class="panel">
            <div class="steps">
                <span class="on"></span>
                <span class="<?= $step !== 'email' ? 'on' : '' ?>"></span>
                <span class="<?= $step === 'password' ? 'on' : '' ?>"></span>
            </div>

            <?php if ($step === 'email'): ?>
                <h1>FORGOT PASSWORD?</h1>
                <p class="sub">Enter the email you registered with and we will send you a 6-digit PIN.</p>
            <?php elseif ($step === 'pin'): ?>
                <h1>ENTER THE PIN</h1>
                <p class="sub">We sent a 6-digit PIN to <strong><?= e(mask_email((string)$_SESSION['reset_email'])) ?></strong>.<br>It is valid for <?= PIN_MINUTES ?> minutes.</p>
            <?php else: ?>
                <h1>NEW PASSWORD</h1>
                <p class="sub">Choose a new password for your account.</p>
            <?php endif; ?>

            <?php if ($error): ?><div class="error-msg"><?= e($error) ?></div><?php endif; ?>
            <?php if ($info):  ?><div class="info-msg"><?= e($info) ?></div><?php endif; ?>

            <?php if ($step === 'email'): ?>
                <form class="form" method="POST" action="customer-forgot-password.php">
                    <input type="hidden" name="action" value="send_pin">
                    <input type="email" name="email" placeholder="Email" value="<?= e($_POST['email'] ?? '') ?>" required autofocus autocomplete="email">
                    <button type="submit" class="submit-btn">Send PIN</button>
                </form>

            <?php elseif ($step === 'pin'): ?>
                <form class="form" method="POST" action="customer-forgot-password.php">
                    <input type="hidden" name="action" value="verify_pin">
                    <input class="pin" type="text" name="pin" placeholder="••••••" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required autofocus>
                    <button type="submit" class="submit-btn">Verify PIN</button>
                </form>
                <div class="links">
                    <form method="POST" action="customer-forgot-password.php">
                        <input type="hidden" name="action" value="resend">
                        Did not get it?
                        <button type="submit" class="linklike" id="resendBtn" <?= $resend_in > 0 ? 'disabled' : '' ?>>Resend PIN</button>
                        <span id="resendTimer" class="hint"></span>
                    </form>
                    <form method="POST" action="customer-forgot-password.php">
                        <input type="hidden" name="action" value="restart">
                        <button type="submit" class="linklike">Use a different email</button>
                    </form>
                </div>

            <?php else: ?>
                <form class="form" method="POST" action="customer-forgot-password.php">
                    <input type="hidden" name="action" value="set_password">
                    <div class="pass-wrap">
                        <input type="password" id="newPass" name="password" placeholder="New password" minlength="<?= MIN_PASSWORD ?>" required autofocus autocomplete="new-password">
                        <button type="button" class="eye-btn" data-target="newPass"><img src="src/show-eye-removebg-preview.png" alt="show"></button>
                    </div>
                    <div class="pass-wrap">
                        <input type="password" id="confirmPass" name="confirm" placeholder="Confirm new password" minlength="<?= MIN_PASSWORD ?>" required autocomplete="new-password">
                        <button type="button" class="eye-btn" data-target="confirmPass"><img src="src/show-eye-removebg-preview.png" alt="show"></button>
                    </div>
                    <p class="hint">At least <?= MIN_PASSWORD ?> characters.</p>
                    <button type="submit" class="submit-btn">Change Password</button>
                </form>
            <?php endif; ?>

            <div class="links">
                <a href="customer-login.php">&larr; Back to Login</a>
            </div>
        </div>
    </div>
    <div class="cont" id="image-cont"></div>
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

// Cursor-reactive light
document.addEventListener('mousemove', function (e) {
    const r = document.documentElement;
    r.style.setProperty('--mx', (e.clientX / window.innerWidth) * 100 + '%');
    r.style.setProperty('--my', (e.clientY / window.innerHeight) * 100 + '%');
});
</script>
</body>
</html>