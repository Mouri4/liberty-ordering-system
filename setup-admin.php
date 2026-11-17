<?php
// ── Liberty Foodmart — First-Time Admin Setup ────────────────
// This script creates the ONE admin account.
// It permanently locks itself after the admin is created.
// ────────────────────────────────────────────────────────────
require 'db.php';
require_once 'password-policy.php';

// ── Check if admin already exists ───────────────────────────
$check = $pdo->query("SELECT COUNT(*) FROM staff_users WHERE role = 'admin'");
$admin_exists = (int)$check->fetchColumn() > 0;

// If admin already exists, this page is permanently locked
if ($admin_exists) {
    http_response_code(403);
    die('
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Setup Locked</title>
            <style>
                body { font-family: Arial, sans-serif; background: #f5f5f5; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
                .box { background: white; border-radius: 12px; padding: 3rem 4rem; text-align: center; box-shadow: 0 4px 24px rgba(0,0,0,0.1); max-width: 480px; }
                .icon { font-size: 3rem; margin-bottom: 1rem; }
                h1 { color: #c62828; font-size: 1.6rem; margin-bottom: 0.8rem; }
                p { color: #666; font-size: 1rem; line-height: 1.6; }
                a { display: inline-block; margin-top: 1.5rem; padding: 0.7rem 1.8rem; background: #0C5A9C; color: white; border-radius: 6px; text-decoration: none; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class="box">
                <div class="icon">🔒</div>
                <h1>Setup Already Complete</h1>
                <p>An admin account already exists. This setup page is permanently locked for security.</p>
                <a href="login.php">Go to Login</a>
            </div>
        </body>
        </html>
    ');
}

// ── Handle form submission ───────────────────────────────────
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstname = trim($_POST['firstname'] ?? '');
    $lastname  = trim($_POST['lastname']  ?? '');
    $email     = trim($_POST['email']     ?? '');
    $password  = $_POST['password']       ?? '';
    $confirm   = $_POST['confirm']        ?? '';

    // Build username
    $username = strtolower(preg_replace('/\s+/', '', $firstname . '.' . $lastname));

    if (!$firstname || !$lastname) {
        $error = 'Please enter your first and last name.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid recovery email address.';
    } elseif ($pw_err = staff_password_error($password, [$firstname, $lastname, $username])) {
        $error = $pw_err;
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // Check if username is taken (edge case)
        $chk = $pdo->prepare('SELECT id FROM staff_users WHERE username = ?');
        $chk->execute([$username]);
        if ($chk->fetch()) {
            $username .= '1'; // append 1 to make it unique
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO staff_users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')")
            ->execute([$username, $email, $hash]);

        $success = $username;
    }
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Setup — Liberty Foodmart</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: bisque;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .setup-wrap {
            background: white;
            border-radius: 1rem;
            width: 100%;
            max-width: 460px;
            padding: 2.5rem 3rem;
            box-shadow: 0 8px 32px rgba(0,0,0,0.12);
        }

        .setup-logo {
            width: 70px;
            display: block;
            margin: 0 auto 1rem;
        }

        h1 {
            text-align: center;
            font-size: 1.4rem;
            color: #0C5A9C;
            margin-bottom: 0.3rem;
        }

        .subtitle {
            text-align: center;
            font-size: 0.8rem;
            color: #888;
            margin-bottom: 1.8rem;
        }

        /* One-time warning banner */
        .warning-banner {
            background: #fff8e1;
            border: 1px solid #ffe082;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 0.78rem;
            color: #7a5c00;
            margin-bottom: 1.5rem;
            line-height: 1.6;
        }
        .warning-banner strong { color: #5a3c00; }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            font-size: 0.78rem;
            font-weight: bold;
            color: #444;
            margin-bottom: 0.3rem;
        }

        .form-group input {
            width: 100%;
            padding: 0.55rem 0.8rem;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 0.9rem;
            background: #fafafa;
        }

        .form-group input:focus {
            border-color: #616ECE;
            outline: none;
            background: #fff;
        }

        /* Password wrap */
        .pw-wrap { position: relative; display: flex; align-items: center; }
        .pw-wrap input { width: 100%; padding-right: 2.2rem; }
        .pw-eye {
            position: absolute; right: 8px;
            background: none; border: none; cursor: pointer;
            padding: 0; display: flex; align-items: center;
        }
        .pw-eye img { width: 15px; height: 15px; opacity: 0.5; }

        /* Strength bar */
        .strength-bar-wrap { height: 4px; background: #eee; border-radius: 2px; overflow: hidden; margin-top: 5px; }
        .strength-fill { height: 100%; width: 0%; border-radius: 2px; transition: width 0.3s, background 0.3s; }
        .strength-label { font-size: 0.7rem; color: gray; margin-top: 3px; }

        .hint-box {
            font-size: 0.7rem; background: #fff; border: 1px solid #ddd;
            border-radius: 5px; padding: 6px 14px; margin-top: 4px;
            list-style: disc; line-height: 1.6; color: #888;
            display: none;
        }
        .match-msg { font-size: 0.7rem; color: red; min-height: 14px; margin-top: 3px; }

        .name-row { display: flex; gap: 0.8rem; }
        .name-row .form-group { flex: 1; }

        .btn-submit {
            width: 100%;
            padding: 0.75rem;
            background: #0C5A9C;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            margin-top: 0.5rem;
        }
        .btn-submit:hover { background: #0a4a80; }

        .alert { padding: 10px 14px; border-radius: 8px; font-size: 0.82rem; margin-bottom: 1.2rem; }
        .alert-error   { background: #fdecea; color: #c62828; border: 1px solid #f5c6cb; }
        .alert-success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }

        .preview-username {
            font-size: 0.75rem;
            color: #888;
            margin-top: 0.4rem;
        }
        .preview-username span { color: #0C5A9C; font-weight: bold; }

        /* Success screen */
        .success-screen {
            text-align: center;
            padding: 1rem 0;
        }
        .success-screen .big-icon { font-size: 3.5rem; margin-bottom: 1rem; }
        .success-screen h2 { color: #2e7d32; font-size: 1.4rem; margin-bottom: 0.5rem; }
        .success-screen p { color: #555; font-size: 0.9rem; line-height: 1.7; }
        .username-box {
            background: #f5f5f5;
            border-radius: 8px;
            padding: 0.8rem 1.2rem;
            margin: 1rem 0;
            font-family: monospace;
            font-size: 1.1rem;
            color: #333;
        }
        .go-login {
            display: inline-block;
            margin-top: 1.2rem;
            padding: 0.75rem 2rem;
            background: #0C5A9C;
            color: white;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
        }
        .go-login:hover { background: #0a4a80; }
    </style>
</head>
<body>
<div class="setup-wrap">
    <img src="src/logo.jpg" alt="Liberty Foodmart" class="setup-logo">
    <h1>Admin Account Setup</h1>
    <p class="subtitle">Liberty Foodmart — First-time setup</p>

    <?php if ($success): ?>
    <!-- ── Success Screen ── -->
    <div class="success-screen">
        <div class="big-icon">✅</div>
        <h2>Admin Account Created!</h2>
        <p>Your admin credentials are:</p>
        <div class="username-box">
            Username: <strong><?= e($success) ?></strong>
        </div>
        <p style="color:#c62828;font-size:0.8rem;">
             Save your username and password now.<br>
            This page is now permanently locked.
        </p>
        <a href="login.php" class="go-login">Go to Login </a>
    </div>

    <?php else: ?>
    <!-- ── Setup Form ── -->

    <div class="warning-banner">
         <strong>One-time setup only.</strong>
        This page will be <strong>permanently locked</strong> after the admin account is created.
        Only one admin account is allowed.
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="setup-admin.php" id="setupForm">

        <div class="name-row">
            <div class="form-group">
                <label>First Name</label>
                <input type="text" name="firstname" id="firstname"
                       placeholder="Juan"
                       value="<?= e($_POST['firstname'] ?? '') ?>"
                       oninput="updateUsernamePreview()" required>
            </div>
            <div class="form-group">
                <label>Last Name</label>
                <input type="text" name="lastname" id="lastname"
                       placeholder="dela Cruz"
                       value="<?= e($_POST['lastname'] ?? '') ?>"
                       oninput="updateUsernamePreview()" required>
            </div>
        </div>

        <p class="preview-username">
            Username will be: <span id="usernamePreview">firstname.lastname</span>
        </p>

        <div class="form-group" style="margin-top:1rem;">
            <label>Recovery Email</label>
            <input type="email" name="email" placeholder="you@gmail.com"
                   value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email" required>
            <p style="font-size:.72rem;color:#888;margin-top:.3rem;">Used to send the PIN if you ever forget the admin password.</p>
        </div>

        <div class="form-group">
            <label>Password</label>
            <div class="pw-wrap">
                <input type="password" name="password" id="passInput"
                       placeholder="Min. 10 chars: upper, lower, number, symbol"
                       maxlength="72" autocomplete="new-password" required>
                <button type="button" class="pw-eye" onclick="togglePw('passInput','eyePassImg')">
                    <img src="src/show-eye-removebg-preview.png" id="eyePassImg" alt="show">
                </button>
            </div>
            <div class="strength-bar-wrap"><div class="strength-fill" id="strengthFill"></div></div>
            <p class="strength-label" id="strengthLabel"></p>
            <ul class="hint-box" id="hintBox"></ul>
        </div>

        <div class="form-group">
            <label>Confirm Password</label>
            <div class="pw-wrap">
                <input type="password" name="confirm" id="confirmInput"
                       placeholder="Repeat your password" required>
                <button type="button" class="pw-eye" onclick="togglePw('confirmInput','eyeConfirmImg')">
                    <img src="src/show-eye-removebg-preview.png" id="eyeConfirmImg" alt="show">
                </button>
            </div>
            <p class="match-msg" id="matchMsg"></p>
        </div>

        <button type="submit" class="btn-submit">Create Admin Account</button>
    </form>
    <?php endif; ?>
</div>

<script>
// ── Username preview ───────────────────────────────────────
function updateUsernamePreview() {
    const f = document.getElementById('firstname').value.trim().toLowerCase().replace(/\s+/g,'');
    const l = document.getElementById('lastname').value.trim().toLowerCase().replace(/\s+/g,'');
    const preview = f && l ? f + '.' + l : 'firstname.lastname';
    document.getElementById('usernamePreview').textContent = preview;
}
updateUsernamePreview();

// ── Show/hide password ─────────────────────────────────────
function togglePw(inputId, imgId) {
    const inp = document.getElementById(inputId);
    const img = document.getElementById(imgId);
    const isHidden = inp.type === 'password';
    inp.type = isHidden ? 'text' : 'password';
    img.src  = isHidden
        ? 'src/hide-eye-removebg-preview.png'
        : 'src/show-eye-removebg-preview.png';
}

// ── Password strength ──────────────────────────────────────
function getIssues(pass) {
    const issues = [];
    if (pass.length < 10)            issues.push('At least 10 characters');
    if (!/[A-Z]/.test(pass))         issues.push('At least 1 uppercase letter');
    if (!/[a-z]/.test(pass))         issues.push('At least 1 lowercase letter');
    if (!/[0-9]/.test(pass))         issues.push('At least 1 number');
    if (!/[^A-Za-z0-9]/.test(pass))  issues.push('At least 1 symbol');
    return issues;
}

const passInput    = document.getElementById('passInput');
const confirmInput = document.getElementById('confirmInput');
const strengthFill = document.getElementById('strengthFill');
const strengthLabel= document.getElementById('strengthLabel');
const hintBox      = document.getElementById('hintBox');
const matchMsg     = document.getElementById('matchMsg');

const levels = [
    { label:'',       color:'#ddd',    width:'0%'   },
    { label:'Weak',   color:'#e74c3c', width:'25%'  },
    { label:'Fair',   color:'#f39c12', width:'50%'  },
    { label:'Good',   color:'#3498db', width:'75%'  },
    { label:'Strong', color:'#2ecc71', width:'100%' },
];

passInput.addEventListener('input', function () {
    const val    = this.value;
    const issues = getIssues(val);
    const score  = Math.floor((5 - issues.length) * 4 / 5);   // 5 rules → 0..4
    const lvl    = levels[score];

    strengthFill.style.width      = lvl.width;
    strengthFill.style.background = lvl.color;
    strengthLabel.style.color     = lvl.color;

    if (issues.length === 0 || val.length === 0) {
        strengthLabel.textContent  = '';
        hintBox.innerHTML          = '';
        hintBox.style.display      = 'none';
        strengthLabel.style.display = 'none';
    } else {
        hintBox.style.display       = 'block';
        strengthLabel.style.display = 'block';
        strengthLabel.textContent   = lvl.label;
        hintBox.innerHTML           = issues.map(i => '<li>' + i + '</li>').join('');
    }
    // Browser blocks the submit until every basic rule is met
    this.setCustomValidity(issues.length ? 'Password does not meet all the requirements.' : '');
    if (confirmInput.value) checkMatch();
});

function checkMatch() {
    if (!confirmInput.value) { matchMsg.textContent = ''; return; }
    const match = confirmInput.value === passInput.value;
    matchMsg.style.color = match ? '#2ecc71' : 'red';
    matchMsg.textContent = match ? '✓ Passwords match' : '✗ Passwords do not match';
}
confirmInput.addEventListener('input', checkMatch);
</script>
</body>
</html>