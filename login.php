<?php
// ── Liberty Foodmart — Staff Login ───────────────────────────
require_once 'session.php';
require 'db.php';
require_once 'auth.php';

// Already logged in — go straight to inventory
if (!empty($_SESSION['staff_id'])) {
    header('Location: inventory.php');
    exit;
}

$error  = '';
$notice = '';
if (($_GET['notice'] ?? '') === 'admin_only') {
    $notice = 'Staff accounts are created by the admin. Please log in with your assigned credentials.';
}
if (($_GET['reset'] ?? '') === '1') {
    $notice = 'The admin password was changed. Please log in with your new password.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password']       ?? '';
    $role_sel = $_POST['role']           ?? ''; // 'admin' or 'staff'

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        // ── Throttle per username + IP address ──────────────────
        // (Per username alone would let anyone lock the real admin out
        // just by typing wrong passwords for "admin".)
        $key  = auth_attempt_key('staff', $username);
        $mins = auth_lock_minutes_left($pdo, $key);

        // A locked login behaves the same whether the password typed is
        // right or wrong — otherwise that would leak information.
        if ($mins > 0) {
            $error = 'Too many failed attempts. Please try again in ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM staff_users WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $fail = auth_record_failure($pdo, $key, 3, 10);
                if ($fail['locked']) {
                    $error = 'Too many failed attempts. Login from this device is locked for 10 minutes.';
                } else {
                    $r = $fail['remaining'];
                    $error = 'Incorrect username or password. ' . $r . ' attempt' . ($r === 1 ? '' : 's') . ' remaining before lockout.';
                }
            } elseif ($role_sel !== '' && $user['role'] !== $role_sel) {
                // They selected a role radio that doesn't match their actual role
                $error = 'Account role does not match the selected role.';
            } else {
                // ✅ Login success
                auth_clear_attempts($pdo, $key);

                // New session ID on login, so an ID planted before login
                // (session fixation) is useless afterwards.
                session_regenerate_id(true);

                $_SESSION['staff_id']       = $user['id'];
                $_SESSION['staff_username'] = $user['username'];
                $_SESSION['staff_role']     = $user['role'];
                // Fingerprint of the current password: if it's changed or the
                // account is deleted, require_staff() signs this session out.
                $_SESSION['staff_sig']      = auth_pw_sig($user['password_hash']);

                // Redirect based on role
                if ($user['role'] === 'admin') {
                    header('Location: sales.php');
                } else {
                    header('Location: staff-orderingv2.php');
                }
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Liberty Foodmart</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --choc-dark:#3B2417;
            --choc:#5C3A24;
            --cream:#FBF3E7;
            --cream-deep:#F1E4D3;
            --caramel:#C17817;
            --caramel-deep:#8B4513;
            --cherry:#B23A48;
            --gold:#D4A24C;
            --muted:#A98A6D;
        }

        * { box-sizing:border-box; }
        body {
            background-color:var(--cream);
            margin:0; height:100%;
            font-family:'Inter', Arial, sans-serif;
        }

        .main { display:flex; width:100%; height:100vh; overflow:hidden; }

        /* ── Left: form panel ─────────────────────────────────── */
        #p1 {
            width:38%; background-color:var(--cream); height:100vh;
            align-items:center; display:flex; flex-direction:column;
            overflow:auto; padding-top:4%; position:relative;
        }

        #logo { width:46%; margin-bottom:2%; }

        .eyebrow {
            font-size:0.65rem; letter-spacing:0.2em; font-weight:700;
            color:var(--caramel-deep); text-transform:uppercase; margin-bottom:0.4rem;
        }
        #h2 {
            font-family:'Playfair Display', serif; font-weight:700;
            font-size:1.9rem; color:var(--choc-dark); margin:0 0 0.2rem;
        }
        .subcopy { font-size:0.75rem; color:var(--muted); margin:0 0 1.2rem; }

        @keyframes slideFade {
            from { opacity:0; transform:translateY(-8px); }
            to   { opacity:1; transform:translateY(0); }
        }
        .error-msg, .notice-msg { animation:slideFade .4s ease; }

        .error-msg {
            background:#fdecea; color:#c62828; border:1px solid #f5c6cb;
            border-radius:10px; padding:8px 14px; font-size:12px;
            width:70%; text-align:center; margin-top:8px;
        }
        .notice-msg {
            background:#FBF0DC; color:#8a5a15; border:1px solid #E8CFA0;
            border-radius:10px; padding:8px 14px; font-size:12px;
            width:70%; text-align:center; margin-top:8px;
        }

        .inputs {
            margin-top:8%; display:flex; flex-direction:column;
            align-items:center; gap:6%; margin-bottom:2%; width:70%;
        }

        .inp {
            padding:0.85rem 1rem; border-radius:12px; border:1.5px solid transparent;
            background-color:#fff; width:100%; color:var(--choc-dark);
            font-family:'Inter', sans-serif; font-size:0.9rem;
            box-shadow:0 2px 8px rgba(59,36,23,0.06);
            transition:border-color .2s ease, box-shadow .2s ease, transform .2s ease;
        }
        .inp:focus {
            outline:none; border-color:var(--caramel);
            box-shadow:0 0 0 4px rgba(193,120,23,0.15);
            transform:translateY(-1px);
        }
        ::placeholder { color:var(--muted); }

        .pass-wrap { width:100%; position:relative; display:flex; align-items:center; }
        .pass-wrap .inp { width:100%; padding-right:2.6rem; }
        .eye-btn {
            position:absolute; right:10px; background:none; border:none;
            cursor:pointer; padding:0; line-height:1;
        }
        .eye-btn img { width:16px; height:16px; object-fit:contain; opacity:0.5; transition:opacity .2s; }
        .eye-btn:hover img { opacity:0.85; }

        /* ── Segmented Admin / Staff toggle ──────────────────── */
        .log-as {
            display:flex; width:70%; margin-top:6%;
            background:var(--cream-deep); border-radius:2rem; padding:4px; gap:0;
        }
        .log-as > div { flex:1; position:relative; text-align:center; }
        .log-as input {
            position:absolute; inset:0; opacity:0; margin:0; cursor:pointer;
        }
        .log-as label {
            display:block; padding:0.5rem 0; border-radius:1.5rem;
            font-size:0.72rem; font-weight:600; letter-spacing:0.02em;
            color:var(--choc); cursor:pointer; position:relative; z-index:1;
            transition:background .25s ease, color .25s ease, box-shadow .25s ease;
        }
        .log-as input:checked + label {
            background:var(--choc-dark); color:#fff;
            box-shadow:0 4px 12px rgba(59,36,23,0.35);
        }
        .log-as input:focus-visible + label { outline:2px solid var(--caramel); outline-offset:2px; }

        #par1 {
            font-size:11px; margin-left:auto; margin-right:15%;
            color:var(--caramel-deep); margin-top:4%; text-decoration:none;
        }
        #par1:hover { text-decoration:underline; }

        #btn1 {
            margin-top:7%; padding:0.9rem 0; border:none; border-radius:2rem;
            width:45%; color:white; cursor:pointer; font-weight:600; font-size:0.9rem;
            background:linear-gradient(135deg, var(--caramel), var(--caramel-deep));
            box-shadow:0 8px 20px rgba(139,69,19,0.3);
            transition:transform .2s ease, box-shadow .2s ease;
        }
        #btn1:hover { transform:translateY(-2px); box-shadow:0 12px 26px rgba(139,69,19,0.4); }
        #btn1:active { transform:translateY(0); }

        .dont { font-size:11px; text-align:center; margin-top:12px; color:var(--muted); }
        .dont a { color:var(--caramel-deep); font-weight:600; text-decoration:none; }
        .dont a:hover { text-decoration:underline; }

        /* ── Right: bakery showcase panel ─────────────────────── */
        #p2 {
            width:62%; height:100vh; position:relative; overflow:hidden;
            background-image:url(src/breadboard.jpg);
            background-repeat:no-repeat; background-position:50% 50%;
            animation:kenBurns 26s ease-in-out infinite;
        }
        @keyframes kenBurns {
            0%   { background-size:112% 112%; background-position:48% 45%; }
            50%  { background-size:124% 124%; background-position:55% 55%; }
            100% { background-size:112% 112%; background-position:48% 45%; }
        }

        #insidep2 {
            width:100%; height:100%; position:absolute; inset:0; z-index:1;
            background-image:linear-gradient(190deg, rgba(59,36,23,0.10) 0%, rgba(59,36,23,0.35) 55%, rgba(30,17,10,0.72) 100%);
        }

        .tagline { position:absolute; z-index:2; left:8%; bottom:9%; max-width:70%; }
        .tagline .eyebrow-light {
            font-size:0.7rem; letter-spacing:0.25em; font-weight:700;
            color:var(--gold); text-transform:uppercase; margin-bottom:0.5rem;
        }
        .tagline h1 {
            font-family:'Playfair Display', serif; font-style:italic; font-weight:600;
            color:#fff; font-size:2.4rem; line-height:1.15; margin:0;
            text-shadow:0 4px 18px rgba(0,0,0,0.45);
        }

        .showcase-card {
            position:absolute; z-index:3; border-radius:14px; overflow:hidden;
            border:4px solid rgba(255,255,255,0.92);
            box-shadow:0 18px 38px rgba(20,10,5,0.45);
            animation:floatCard 7s ease-in-out infinite;
            transition:transform .3s ease, box-shadow .3s ease;
        }
        .showcase-card:hover {
            transform:translateY(-6px) scale(1.05) rotate(0deg) !important;
            box-shadow:0 24px 48px rgba(20,10,5,0.55);
            z-index:4;
        }
        .showcase-card img { width:100%; height:100%; object-fit:cover; display:block; }

        @keyframes floatCard {
            0%, 100% { transform:translateY(0) rotate(var(--r, 0deg)); }
            50%      { transform:translateY(-14px) rotate(var(--r, 0deg)); }
        }

        .card-1 { top:7%;  right:7%;  width:170px; height:150px; --r:-6deg; animation-delay:0s; }
        .card-2 { top:33%; right:24%; width:135px; height:150px; --r:5deg;  animation-delay:-2.3s; }
        .card-3 { top:19%; right:34%; width:120px; height:120px; --r:-3deg; animation-delay:-4.6s; display:none; }
        .card-4 { bottom:32%; right:5%; width:150px; height:130px; --r:4deg; animation-delay:-1.4s; }

        @media (prefers-reduced-motion: reduce) {
            #p2 { animation:none; }
            .showcase-card { animation:none; }
        }

        @media (max-width: 900px) {
            .card-2, .card-4 { display:none; }
            .card-1 { width:130px; height:115px; }
        }
    </style>
</head>
<body>
<div class="main">

    <form method="POST" action="login.php" id="p1">

        <p class="eyebrow">Employee Portal</p>
        <h2 id="h2">Welcome back</h2>
        <p class="subcopy">Sign in with your assigned credentials.</p>

        <?php if ($notice !== ''): ?>
            <div class="notice-msg"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="inputs">
            <input
                class="inp"
                type="text"
                name="username"
                placeholder="Username"
                value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                required
                autocomplete="username">
            <div class="pass-wrap">
                <input
                    class="inp"
                    type="password"
                    id="passInput"
                    name="password"
                    placeholder="Password"
                    required
                    autocomplete="current-password">
                <button type="button" class="eye-btn" id="eyePass">
                    <img src="src/show-eye-removebg-preview.png" alt="show" id="eyePassImg">
                </button>
            </div>
        </div>

        <div class="log-as">
            <div>
                <input type="radio" name="role" id="role-admin" value="admin"
                    <?= (($_POST['role'] ?? '') === 'admin') ? 'checked' : '' ?>>
                <label for="role-admin">Admin</label>
            </div>
            <div>
                <input type="radio" name="role" id="role-staff" value="staff"
                    <?= (($_POST['role'] ?? '') === 'staff') ? 'checked' : '' ?>>
                <label for="role-staff">Staff</label>
            </div>
        </div>

        <a href="admin-forgot-password.php" id="par1" title="Admin account only. Staff: ask your admin to reset your password.">Forgot password</a>

        <button id="btn1" type="submit">Log in</button>
        <div class="dont"><p>Don't have an Admin Account? <a href="setup-admin.php">Sign up</a></p></div>

    </form>

    <div id="p2">
        <div id="insidep2"></div>

        <div class="tagline">
            <p class="eyebrow-light">Liberty Foodmart</p>
            <h1>Baked fresh.<br>Served with pride.</h1>
        </div>

        <div class="showcase-card card-1"><img src="src/best-cake.jpg" alt="Chocolate drip cake"></div>
        <div class="showcase-card card-2"><img src="src/black-forestv2.webp" alt="Black forest cake"></div>
        <div class="showcase-card card-3"><img src="src/biskwit.jpeg" alt="Mille-feuille pastry"></div>
        <div class="showcase-card card-4"><img src="src/redv2.jpg" alt="Red velvet cake"></div>
    </div>

</div>
<script>
document.getElementById('eyePass').addEventListener('click', function () {
    const inp = document.getElementById('passInput');
    const isHidden = inp.type === 'password';
    inp.type = isHidden ? 'text' : 'password';
    document.getElementById('eyePassImg').src = isHidden
        ? 'src/hide-eye-removebg-preview.png'
        : 'src/show-eye-removebg-preview.png';
});
</script>
</body>
</html>