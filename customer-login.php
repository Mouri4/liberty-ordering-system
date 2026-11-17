<?php
// ── Liberty Foodmart — Customer Login ───────────────────────
require_once 'session.php';
require 'db.php';
require_once 'auth.php';

if (!empty($_SESSION['customer_id'])) {
    header('Location: ordering-page.php');
    exit;
}

$error = '';
$notice = (($_GET['reset'] ?? '') === '1') ? 'Your password was changed. Please log in with your new password.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']      ?? '';

    if (!$email || !$password) {
        $error = 'Please enter your email and password.';
    } else {
        // Throttle per email + IP address: 5 wrong tries = 10 minute pause
        // for that device only (nobody else gets locked out).
        $key  = auth_attempt_key('customer', $email);
        $mins = auth_lock_minutes_left($pdo, $key);

        if ($mins > 0) {
            $error = 'Too many failed attempts. Please try again in ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM customers WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $customer = $stmt->fetch();

            if (!$customer || !password_verify($password, $customer['password_hash'])) {
                $fail = auth_record_failure($pdo, $key, 5, 10);
                $error = $fail['locked']
                    ? 'Too many failed attempts. Please try again in 10 minutes.'
                    : 'Incorrect email or password.';
            } else {
                auth_clear_attempts($pdo, $key);

                // New session ID on login (prevents session fixation)
                session_regenerate_id(true);

                $_SESSION['customer_id']       = $customer['id'];
                $_SESSION['customer_name']     = $customer['name'];
                $_SESSION['customer_email']    = $customer['email'];
                $_SESSION['customer_phone']    = $customer['phone'] ?? '';
                $_SESSION['customer_location'] = $customer['default_location'] ?? '';

                // Redirect to where they came from, or shop
                $redirect = $_GET['redirect'] ?? 'ordering-page.php';
                header('Location: ' . $redirect);
                exit;
            }
        }
    }
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Liberty Foodmart</title>
    <style>
        * { box-sizing:border-box; margin:0; padding:0; }
        html { --mx:50%; --my:50%; }
        body {
            height:100vh; width:100%; overflow:hidden;
            background:#FBF6EE;
            display:flex; align-items:center; justify-content:center;
            position:relative;
        }

        /* ── Liquid gradient, flowing continuously ───────────── */
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

        /* ── Cursor-reactive light following the pointer ─────── */
        .cursor-glow {
            position:fixed; inset:0; z-index:1; pointer-events:none;
            background:radial-gradient(650px circle at var(--mx) var(--my), rgba(255,255,255,0.45), transparent 60%);
            mix-blend-mode:overlay;
            transition:opacity .3s ease;
        }
        @media (prefers-reduced-motion: reduce) {
            .liquid-bg { animation:none; }
            .cursor-glow { display:none; }
        }

        .main {
            position:relative; z-index:2;
            border-radius:1.25rem; height:85%; width:65%;
            background-color:rgb(255, 255, 255);
            backdrop-filter:blur(38px) saturate(150%); -webkit-backdrop-filter:blur(38px) saturate(150%);
            border:1px solid rgba(255,255,255,0.45);
            box-shadow:0 12px 40px rgba(43,35,24,0.18), inset 0 1px 0 rgba(255,255,255,0.5);
            padding:2%; display:flex; gap:1%; align-items:center;
            transition:transform .15s ease-out;
            transform-style:preserve-3d; will-change:transform;
        }

        .cont { height:95%; width:50%; border-radius:1rem; overflow:hidden; }
        #image-cont { background-image:url(src/log-image2.jpg); background-repeat:no-repeat; background-size:cover; background-position:center; }

        .title { margin:auto; height:13%; width:90%; overflow:hidden; display:flex; justify-content:space-between; align-items:center; }
        .title img { height:100%; width:20%; }
        .title h3 { margin:0; font-family:Arial,Helvetica,sans-serif; color:#ea8e16; }

        .log-in { height:87%; display:flex; flex-direction:column; align-items:center; }
        .log-in h1 { font-family:Arial,Helvetica,sans-serif; font-weight:790; color:#ea8e16; }
        .log-in > p { margin:0; font-size:0.7rem; color:grey; font-family:arial; }

        .inp-cont { margin-top:10%; width:60%; display:flex; flex-direction:column; align-items:center; gap:12%; height:28%; }
        .inp-cont input { width:100%; padding:3.3%; border-radius:2rem; border:1px solid; font-size:0.85rem; }
        .inp-cont a { font-family:Arial,Helvetica,sans-serif; font-size:0.6rem; margin-left:auto; font-weight:550; cursor:pointer; color:#555; text-decoration:none; }
        .inp-cont button.submit-btn { width:100%; padding:3.3%; border-radius:2rem; border:1px solid; background-color:#ea8e16; color:white; font-weight:bold; cursor:pointer; }
        .inp-cont button.submit-btn:hover { background-color:#c97a10; }

        .pass-wrap { width:100%; position:relative; display:flex; align-items:center; }
        .pass-wrap input { width:100%; padding:3.3% 2.8rem 3.3% 3.3%; border-radius:2rem; border:1px solid; font-size:0.85rem; box-sizing:border-box; }
        .eye-btn { position:absolute; right:12px; background:none; border:none !important; width:auto !important; border-radius:0 !important; cursor:pointer; padding:0; line-height:1; display:flex; align-items:center; }
        .eye-btn img { width:16px; height:16px; object-fit:contain; opacity:0.6; }

        .no-acc { display:flex; width:80%; gap:0px; font-size:0.6rem; font-family:Arial,Helvetica,sans-serif; color: gray; }
        .no-acc a { color:#ea8e16; text-decoration:none; }

        .error-msg { background:#fdecea; color:#c62828; border:1px solid #f5c6cb; border-radius:0.5rem; padding:8px 12px; font-size:0.75rem; width:80%; text-align:center; margin-top:8px; font-family:Arial,Helvetica,sans-serif; }

        .info-msg { background:#e8f5e9; color:#2e7d32; border:1px solid #c8e6c9; border-radius:0.5rem; padding:8px 12px; font-size:0.75rem; width:80%; text-align:center; margin-top:8px; font-family:Arial,Helvetica,sans-serif; }

        /* =========================================================
           RESPONSIVE — the desktop card relies on vh/% sizing and a
           side-by-side image panel that doesn't work on a phone, so
           mobile gets its own single-column, auto-height layout.
           ========================================================= */
        @media (max-width:700px){
            body{
                height:auto;
                min-height:100vh;
                overflow-y:auto;
                padding:2rem 1rem;
            }
            .main{
                width:100%;
                max-width:26rem;
                height:auto;
                flex-direction:column;
                padding:0;
                gap:0;
            }
            .cont{ width:100%; height:auto; border-radius:1.25rem; }
            #image-cont{ display:none; }

            .title{ height:auto; width:100%; padding:1.7rem 1.7rem 0; margin:0; }
            .title img{ height:2.6rem; width:auto; }
            .title h3{ font-size:1rem; }

            .log-in{ height:auto; width:100%; padding:1.2rem 1.7rem 2rem; }
            .log-in h1{ font-size:1.6rem; text-align:center; }
            .log-in > p{ font-size:.82rem; text-align:center; margin-top:.35rem; }

            .inp-cont{ margin-top:1.6rem; width:100%; gap:1rem; height:auto; }
            .inp-cont input{ padding:.9rem 1.1rem; font-size:.95rem; min-height:2.9rem; }
            .inp-cont a{ font-size:.78rem; }
            .pass-wrap input{ padding:.9rem 2.8rem .9rem 1.1rem; font-size:.95rem; min-height:2.9rem; }
            .eye-btn{ padding:.6rem; right:2px; }
            .inp-cont button.submit-btn{ padding:.9rem; font-size:.95rem; margin-top:.4rem; }

            .no-acc{ width:100%; font-size:.78rem; justify-content:center; gap:.3rem; flex-wrap:wrap; }
            .no-acc a{ font-size:.8rem; }

            .error-msg, .info-msg{ width:100%; font-size:.82rem; }
        }

        @media (max-width:380px){
            .title img{ height:2.2rem; }
            .log-in h1{ font-size:1.4rem; }
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
            <h3>LOGIN</h3>
        </div>
        <form class="log-in" method="POST" action="customer-login.php">
            <h1>WELCOME BACK!</h1>
            <p>Log in with your Email and Password.</p>

            <?php if ($error): ?>
                <div class="error-msg"><?= e($error) ?></div>
            <?php elseif ($notice): ?>
                <div class="info-msg"><?= e($notice) ?></div>
            <?php endif; ?>

            <div class="inp-cont">
                <input type="email"    name="email"    placeholder="Email"    value="<?= e($_POST['email'] ?? '') ?>" required autocomplete="email">
                <div class="pass-wrap">
                    <input type="password" id="passInput" name="password" placeholder="Password" required autocomplete="current-password">
                    <button type="button" class="eye-btn" id="eyePass">
                        <img src="src/show-eye-removebg-preview.png" alt="show" id="eyePassImg">
                    </button>
                </div>
                <a href="customer-forgot-password.php">Forgot Password?</a>
                <button type="submit" class="submit-btn">Login</button>
                <div class="no-acc">
                    <p>Did not have any Account?</p>
                    <a href="customer-register.php">Register Now</a>
                </div>
            </div>
        </form>
    </div>
    <div class="cont" id="image-cont"></div>
</main>
<script>
document.getElementById('eyePass').addEventListener('click', function () {
    const inp = document.getElementById('passInput');
    const isHidden = inp.type === 'password';
    inp.type = isHidden ? 'text' : 'password';
    document.getElementById('eyePassImg').src = isHidden
        ? 'src/hide-eye-removebg-preview.png'
        : 'src/show-eye-removebg-preview.png';
});

// Cursor-reactive light + subtle glass card tilt
const root = document.documentElement;
const card = document.querySelector('.main');

document.addEventListener('mousemove', (e) => {
    root.style.setProperty('--mx', (e.clientX / window.innerWidth) * 100 + '%');
    root.style.setProperty('--my', (e.clientY / window.innerHeight) * 100 + '%');
});

card.addEventListener('mousemove', (e) => {
    const r = card.getBoundingClientRect();
    const px = (e.clientX - r.left) / r.width - 0.5;
    const py = (e.clientY - r.top) / r.height - 0.5;
    card.style.transform = `perspective(1400px) rotateX(${(-py * 2.5).toFixed(2)}deg) rotateY(${(px * 2.5).toFixed(2)}deg)`;
});
card.addEventListener('mouseleave', () => {
    card.style.transform = 'perspective(1400px) rotateX(0deg) rotateY(0deg)';
});
</script>
</body>
</html>