
<?php
// ── Liberty Foodmart — Customer Register ────────────────────
require_once 'session.php';
require 'db.php';

if (!empty($_SESSION['customer_id'])) {
    header('Location: ordering-page.php');
    exit;
}

$error   = '';
$success = '';

$legal_email   = 'your-email@example.com';
$legal_address = 'Your store address here';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']     ?? '');
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']      ?? '';
    $confirm  = $_POST['confirm']       ?? '';
    $agreed   = ($_POST['agree']        ?? '') === '1';

    if (!$name || !$email || !$password) {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (!$agreed) {
        $error = 'Please read and agree to the Terms of Service and Privacy Policy to register.';
    } else {
        $check = $pdo->prepare('SELECT id FROM customers WHERE email = ?');
        $check->execute([$email]);

        if ($check->fetch()) {
            $error = 'An account with that email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $pdo->prepare(
                'INSERT INTO customers (name, email, password_hash) VALUES (?, ?, ?)'
            )->execute([$name, $email, $hash]);

            $id = $pdo->lastInsertId();

            session_regenerate_id(true);
            $_SESSION['customer_id']    = $id;
            $_SESSION['customer_name']  = $name;
            $_SESSION['customer_email'] = $email;

            header('Location: ordering-page.php');
            exit;
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
    <title>Signup — Liberty Foodmart</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            --mx: 50%;
            --my: 50%;
        }

        body {
            width: 100%;
            height: 100vh;
            height: 100dvh;
            overflow: hidden;
            background: #FBF6EE;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            font-family: Arial, Helvetica, sans-serif;
        }

        /* ── Animated liquid background ── */
        .liquid-bg {
            position: fixed;
            inset: -10%;
            z-index: 0;
            background:
                radial-gradient(at 80% 20%, #c899c6 0px, transparent 70%),
                radial-gradient(at 15% 15%, #EA8E16 0px, transparent 55%),
                radial-gradient(at 75% 85%, #E85C4A 0px, transparent 55%),
                radial-gradient(at 20% 80%, #F4C430 0px, transparent 55%),
                linear-gradient(135deg, #FBF6EE, #FDEFE0);
            background-size:
                200% 200%,
                200% 200%,
                200% 200%,
                200% 200%,
                100% 100%;
            filter: blur(70px) saturate(135%);
            animation: liquidFlow 24s ease-in-out infinite;
        }

        @keyframes liquidFlow {
            0% {
                background-position:
                    80% 20%, 15% 15%, 75% 85%, 20% 80%, 0 0;
            }
            25% {
                background-position:
                    55% 45%, 40% 40%, 55% 60%, 45% 55%, 0 0;
            }
            50% {
                background-position:
                    25% 75%, 70% 25%, 25% 25%, 75% 20%, 0 0;
            }
            75% {
                background-position:
                    65% 30%, 30% 65%, 60% 40%, 35% 70%, 0 0;
            }
            100% {
                background-position:
                    80% 20%, 15% 15%, 75% 85%, 20% 80%, 0 0;
            }
        }

        /* ── Cursor glow ── */
        .cursor-glow {
            position: fixed;
            inset: 0;
            z-index: 1;
            pointer-events: none;
            background: radial-gradient(
                650px circle at var(--mx) var(--my),
                rgba(255, 255, 255, 0.45),
                transparent 60%
            );
            mix-blend-mode: overlay;
            transition: opacity 0.3s ease;
        }

        @media (prefers-reduced-motion: reduce) {
            .liquid-bg {
                animation: none;
            }

            .cursor-glow {
                display: none;
            }
        }

        /* ── Main card ── */
        .main {
            position: relative;
            z-index: 2;
            width: 65%;
            height: 85%;
            border-radius: 1.25rem;
            background-color: #fff;
            backdrop-filter: blur(38px) saturate(150%);
            -webkit-backdrop-filter: blur(38px) saturate(150%);
            border: 1px solid rgba(255, 255, 255, 0.45);
            box-shadow:
                0 12px 40px rgba(43, 35, 24, 0.18),
                inset 0 1px 0 rgba(255, 255, 255, 0.5);
            padding: 2%;
            display: flex;
            align-items: center;
            gap: 1%;
            transition: transform 0.15s ease-out;
            transform-style: preserve-3d;
            will-change: transform;
        }

        .cont {
            width: 50%;
            height: 95%;
            border-radius: 1rem;
            overflow: hidden;
        }

        #image-cont {
            background-image: url(src/log-image.jpg);
            background-repeat: no-repeat;
            background-size: cover;
            background-position: center;
        }

        /* ── Logo ── */
        .title {
            width: 90%;
            height: 13%;
            margin: auto;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .title img {
            width: 20%;
            height: 100%;
            object-fit: contain;
        }

        .title h3 {
            margin: 0;
            color: #ea8e16;
        }

        /* ── Registration form: no internal scrolling ── */
        .log-in {
            width: 100%;
            height: 87%;
            display: flex;
            flex-direction: column;
            align-items: center;
            overflow: visible;
            overflow-y: visible;
            overflow-x: hidden;
            scrollbar-width: none;
        }

        .log-in::-webkit-scrollbar {
            display: none;
        }

        .log-in h1 {
            font-family: Arial, Helvetica, sans-serif;
            font-weight: 790;
            color: #ea8e16;
        }

        .log-in > p {
            margin: 0;
            font-size: 0.7rem;
            color: grey;
        }

        /* ── Inputs ── */
        .inp-cont {
            width: 60%;
            height: auto;
            margin-top: 4%;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 3%;
        }

        .inp-cont input {
            width: 100%;
            padding: 3.3%;
            border: 1px solid #777;
            border-radius: 2rem;
            font-size: 0.85rem;
        }

        .inp-cont button.submit-btn {
            width: 100%;
            padding: 3.3%;
            margin-top: 4%;
            border: 1px solid #ea8e16;
            border-radius: 2rem;
            background-color: #ea8e16;
            color: white;
            font-weight: bold;
            cursor: pointer;
        }

        .inp-cont button.submit-btn:hover {
            background-color: #c97a10;
        }

        .pass-wrap {
            width: 100%;
            position: relative;
            display: flex;
            align-items: center;
        }

        .pass-wrap input {
            width: 100%;
            padding: 3.3% 2.8rem 3.3% 3.3%;
            border-radius: 2rem;
            border: 1px solid #777;
            font-size: 0.85rem;
        }

        .eye-btn {
            position: absolute;
            right: 12px;
            width: auto !important;
            padding: 0;
            border: none !important;
            border-radius: 0 !important;
            background: none;
            cursor: pointer;
            line-height: 1;
            display: flex;
            align-items: center;
        }

        .eye-btn img {
            width: 16px;
            height: 16px;
            object-fit: contain;
            opacity: 0.6;
        }

        /* ── Existing account link ── */
        .no-acc {
            width: 80%;
            margin-top: 3%;
            display: flex;
            gap: 4px;
            font-size: 0.6rem;
        }

        .no-acc a {
            color: #ea8e16;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.7rem;
        }

        .error-msg {
            width: 80%;
            margin-top: 8px;
            padding: 8px 12px;
            border: 1px solid #f5c6cb;
            border-radius: 0.5rem;
            background: #fdecea;
            color: #c62828;
            font-size: 0.75rem;
            text-align: center;
        }

        /* ── Terms and privacy agreement ── */
        .agree-row {
            width: 100%;
            margin-top: 2%;
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            color: #555;
            font-size: 0.68rem;
            line-height: 1.5;
            text-align: left;
            cursor: pointer;
        }

        .inp-cont .agree-row input[type="checkbox"] {
            flex: 0 0 1rem;
            width: 1rem;
            height: 1rem;
            min-height: 0;
            padding: 0;
            margin-top: 0.12rem;
            border-radius: 0.2rem;
            accent-color: #ea8e16;
            cursor: pointer;
        }

        .legal-link {
            color: #ea8e16;
            font-weight: 700;
            text-decoration: underline;
            cursor: pointer;
        }

        .legal-link:hover {
            color: #c97a10;
        }

        /* ── Terms and privacy modal ── */
        .legal-overlay {
            position: fixed;
            inset: 0;
            z-index: 100;
            padding: 1rem;
            background: rgba(30, 15, 5, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
        }

        .legal-overlay.open {
            display: flex;
        }

        .legal-box {
            width: 100%;
            max-width: 42rem;
            max-height: 86vh;
            max-height: 86dvh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: #fff;
            border-top: 4px solid #ea8e16;
            border-radius: 1rem;
            box-shadow: 0 20px 50px rgba(30, 15, 5, 0.35);
            font-family: Arial, Helvetica, sans-serif;
        }

        .legal-head {
            padding: 0.7rem 0.9rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            border-bottom: 1px solid #eee;
        }

        .legal-tabs {
            display: flex;
            gap: 0.3rem;
            padding: 0.2rem;
            border-radius: 2rem;
            background: #f6efe4;
        }

        .legal-tab {
            padding: 0.4rem 0.9rem;
            border: none;
            border-radius: 1.6rem;
            background: transparent;
            color: #5c3a24;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
        }

        .legal-tab.active {
            background: #3b2417;
            color: #fff;
        }

        .legal-x {
            flex-shrink: 0;
            width: 2rem;
            height: 2rem;
            border: none;
            border-radius: 0.5rem;
            background: #f6efe4;
            color: #3b2417;
            font-size: 1.3rem;
            line-height: 1;
            cursor: pointer;
        }

        .legal-body {
            min-height: 0;
            padding: 1rem 1.3rem;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            color: #444;
            font-size: 0.82rem;
            line-height: 1.65;
        }

        .legal-doc {
            display: none;
        }

        .legal-doc.show {
            display: block;
        }

        .legal-doc h2 {
            margin-bottom: 0.15rem;
            color: #3b2417;
            font-size: 1.15rem;
        }

        .legal-doc .updated {
            margin-bottom: 0.8rem;
            color: #999;
            font-size: 0.72rem;
        }

        .legal-doc h3 {
            margin: 1.1rem 0 0.3rem;
            color: #8b4513;
            font-size: 0.9rem;
        }

        .legal-doc p {
            margin-bottom: 0.5rem;
        }

        .legal-doc ul {
            margin-bottom: 0.5rem;
            padding-left: 1.2rem;
        }

        .legal-doc li {
            margin-bottom: 0.25rem;
        }

        .legal-foot {
            padding: 0.7rem 0.9rem;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            border-top: 1px solid #eee;
        }

        .legal-btn {
            padding: 0.6rem 1.3rem;
            border: none;
            border-radius: 2rem;
            background: #ea8e16;
            color: #fff;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
        }

        .legal-btn:hover {
            background: #c97a10;
        }

        .legal-btn.ghost {
            background: #f6efe4;
            color: #5c3a24;
        }

        .legal-btn.ghost:hover {
            background: #efe3d0;
        }

        /* ==================================================
           MOBILE LAYOUT
           Compact registration card, no page/form scrolling
           ================================================== */
        @media (max-width: 700px) {
            body {
                width: 100%;
                height: 100vh;
                height: 100dvh;
                min-height: 0;
                padding: 0.5rem;
                overflow: hidden;
            }

            .main {
                width: 100%;
                max-width: 25rem;
                height: auto;
                max-height: calc(100dvh - 1rem);
                min-height: 0;
                padding: 0.7rem 0;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                gap: 0;
                overflow: visible;
                border-radius: 1.1rem;
                transform: none !important;
            }

            .cont {
                width: 100%;
                height: auto;
                min-height: 0;
                overflow: visible;
                border-radius: 0;
            }

            #image-cont {
                display: none;
            }

            .title {
                width: 100%;
                height: auto;
                margin: 0;
                padding: 0.35rem 1rem 0.6rem;
                justify-content: center;
            }

            .title img {
                width: auto;
                max-width: 70%;
                height: 2rem;
                object-fit: contain;
            }

            .log-in {
                width: 100%;
                height: auto;
                min-height: 0;
                padding: 0 1.15rem 0.5rem;
                display: flex;
                flex-direction: column;
                align-items: center;
                overflow: visible;
                overflow-y: visible;
            }

            .log-in h1 {
                font-size: 1.35rem;
                line-height: 1.25;
                text-align: center;
            }

            .log-in > p {
                margin-top: 0.2rem;
                font-size: 0.72rem;
                line-height: 1.4;
                text-align: center;
            }

            .error-msg {
                width: 100%;
                margin-top: 0.5rem;
                padding: 0.45rem 0.6rem;
                font-size: 0.72rem;
            }

            .inp-cont {
                width: 100%;
                height: auto;
                margin-top: 0.8rem;
                display: flex;
                flex-direction: column;
                align-items: stretch;
                gap: 0.55rem;
            }

            .inp-cont input {
                width: 100%;
                height: 2.45rem;
                min-height: 2.45rem;
                padding: 0 0.9rem;
                border-radius: 1.5rem;
                font-size: 0.88rem;
            }

            .pass-wrap {
                width: 100%;
                min-width: 0;
            }

            .pass-wrap input {
                width: 100%;
                height: 2.45rem;
                min-height: 2.45rem;
                padding: 0 2.6rem 0 0.9rem;
                font-size: 0.88rem;
            }

            .eye-btn {
                right: 0.35rem;
                padding: 0.4rem;
            }

            .eye-btn img {
                width: 15px;
                height: 15px;
            }

            .agree-row {
                width: 100%;
                margin-top: 0.15rem;
                gap: 0.45rem;
                align-items: flex-start;
                font-size: 0.68rem;
                line-height: 1.4;
            }

            .inp-cont .agree-row input[type="checkbox"] {
                flex: 0 0 1rem;
                width: 1rem;
                height: 1rem;
                min-height: 1rem;
                margin-top: 0.05rem;
                padding: 0;
            }

            .inp-cont button.submit-btn {
                width: 100%;
                min-height: 2.5rem;
                margin-top: 0.15rem;
                padding: 0.65rem;
                font-size: 0.9rem;
            }

            .no-acc {
                width: 100%;
                margin-top: 0.45rem;
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                align-items: center;
                gap: 0.3rem;
                font-size: 0.7rem;
            }

            .no-acc a {
                font-size: 0.75rem;
            }

            /* The policy popup remains scrollable */
            .legal-overlay {
                padding: 0.5rem;
            }

            .legal-box {
                width: 100%;
                max-height: 92vh;
                max-height: 92dvh;
            }

            .legal-head {
                padding: 0.6rem;
            }

            .legal-tabs {
                min-width: 0;
            }

            .legal-tab {
                padding: 0.45rem 0.5rem;
                font-size: 0.68rem;
            }

            .legal-body {
                min-height: 0;
                padding: 0.8rem;
                overflow-y: auto;
                font-size: 0.8rem;
            }

            .legal-foot {
                padding: 0.6rem;
            }
        }

        /* Extra-small phones */
        @media (max-width: 380px) {
            .main {
                padding: 0.45rem 0;
            }

            .title {
                padding-top: 0.2rem;
                padding-bottom: 0.4rem;
            }

            .title img {
                height: 1.7rem;
            }

            .log-in {
                padding-right: 0.85rem;
                padding-left: 0.85rem;
            }

            .log-in h1 {
                font-size: 1.2rem;
            }

            .log-in > p {
                font-size: 0.68rem;
            }

            .inp-cont {
                margin-top: 0.6rem;
                gap: 0.45rem;
            }

            .inp-cont input,
            .pass-wrap input {
                height: 2.3rem;
                min-height: 2.3rem;
                font-size: 0.84rem;
            }

            .agree-row {
                font-size: 0.64rem;
            }

            .inp-cont button.submit-btn {
                min-height: 2.3rem;
                padding: 0.5rem;
            }
        }

        /* Short screens: tighten vertical spacing */
        @media (max-height: 650px) and (max-width: 700px) {
            .main {
                padding: 0.35rem 0;
            }

            .title {
                padding-top: 0.15rem;
                padding-bottom: 0.3rem;
            }

            .title img {
                height: 1.5rem;
            }

            .log-in h1 {
                font-size: 1.15rem;
            }

            .log-in > p {
                font-size: 0.65rem;
            }

            .inp-cont {
                margin-top: 0.45rem;
                gap: 0.35rem;
            }

            .inp-cont input,
            .pass-wrap input {
                height: 2.1rem;
                min-height: 2.1rem;
            }

            .agree-row {
                font-size: 0.62rem;
                line-height: 1.3;
            }

            .inp-cont button.submit-btn {
                min-height: 2.1rem;
                padding: 0.4rem;
            }

            .no-acc {
                margin-top: 0.25rem;
            }
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
        </div>

        <form class="log-in" method="POST" action="customer-register.php">
            <h1>Create an account</h1>
            <p>Signup with your valid Email and Password.</p>

            <?php if ($error): ?>
                <div class="error-msg"><?= e($error) ?></div>
            <?php endif; ?>

            <div class="inp-cont">
                <input
                    type="text"
                    name="name"
                    placeholder="Username"
                    value="<?= e($_POST['name'] ?? '') ?>"
                    required
                >

                <input
                    type="email"
                    name="email"
                    placeholder="Email"
                    value="<?= e($_POST['email'] ?? '') ?>"
                    required
                >

                <div class="pass-wrap">
                    <input
                        type="password"
                        id="passInput"
                        name="password"
                        placeholder="Password"
                        required
                    >

                    <button type="button" class="eye-btn" id="eyePass">
                        <img
                            src="src/show-eye-removebg-preview.png"
                            alt="Show password"
                            id="eyePassImg"
                        >
                    </button>
                </div>

                <div class="pass-wrap">
                    <input
                        type="password"
                        id="confirmInput"
                        name="confirm"
                        placeholder="Confirm Password"
                        required
                    >

                    <button type="button" class="eye-btn" id="eyeConfirm">
                        <img
                            src="src/show-eye-removebg-preview.png"
                            alt="Show password"
                            id="eyeConfirmImg"
                        >
                    </button>
                </div>

                <label class="agree-row">
                    <input
                        type="checkbox"
                        name="agree"
                        id="agreeBox"
                        value="1"
                        required
                        <?= !empty($_POST['agree']) ? 'checked' : '' ?>
                    >

                    <span>
                        I have read and agree to Liberty Foodmart's
                        <a href="#" class="legal-link" data-doc="terms">Terms of Service</a>
                        and
                        <a href="#" class="legal-link" data-doc="privacy">Privacy Policy</a>.
                    </span>
                </label>

                <button type="submit" class="submit-btn">Signup</button>

                <div class="no-acc">
                    <p>Already have an account?</p>
                    <a href="customer-login.php">Login</a>
                </div>
            </div>
        </form>
    </div>

    <div class="cont" id="image-cont"></div>
</main>

<!-- ── Terms of Service + Privacy Policy popup ── -->
<div
    class="legal-overlay"
    id="legalOverlay"
    role="dialog"
    aria-modal="true"
    aria-label="Terms of Service and Privacy Policy"
>
    <div class="legal-box">
        <div class="legal-head">
            <div class="legal-tabs">
                <button type="button" class="legal-tab" data-doc="terms">
                    Terms of Service
                </button>

                <button type="button" class="legal-tab" data-doc="privacy">
                    Privacy Policy
                </button>
            </div>

            <button
                type="button"
                class="legal-x"
                id="legalClose"
                aria-label="Close"
            >&times;</button>
        </div>

        <div class="legal-body" id="legalBody">

            <!-- TERMS OF SERVICE -->
            <article class="legal-doc" id="doc-terms">
                <h2>Terms of Service</h2>
                <p class="updated">Last updated: October 23, 2026</p>

                <p>
                    Welcome to Liberty Foodmart (also called "PastryLiberty",
                    "we", "us"). By creating an account or placing an order
                    or reservation on this website, you agree to these Terms.
                    If you do not agree, please do not signup.
                </p>

                <h3>1. Your account</h3>
                <ul>
                    <li>Give us accurate information (name, email, contact number and delivery address) and keep it up to date.</li>
                    <li>You are responsible for keeping your password private and for everything done through your account.</li>
                    <li>One account per person. We may suspend or close an account that is used to cheat, abuse or harm the shop or other customers.</li>
                </ul>

                <h3>2. Orders and reservations</h3>
                <ul>
                    <li>An order or reservation sent through the website is a request. Our staff will process it and its status will be updated in My Orders.</li>
                    <li>Products are subject to availability. If an item sells out, we may adjust or cancel that part of your order and let you know.</li>
                    <li><strong>Orders</strong> are delivered to the address you provide. <strong>Reservations</strong> are picked up in person at the date and time you choose, so please arrive on schedule and show your confirmation.</li>
                    <li>Many of our items are made to order. Once preparation has started we may not be able to cancel or change your order. Please contact us as early as possible if you need a change.</li>
                </ul>

                <h3>3. Prices and payment</h3>
                <ul>
                    <li>All prices are in Philippine Pesos (PHP) and already include 12% VAT.</li>
                    <li>You can pay using the payment methods shown at checkout (for example, Cash on Delivery). Your payment status is updated by our staff once payment is received.</li>
                    <li>We may correct pricing mistakes. If this affects an order you have already placed, we will contact you before going ahead.</li>
                </ul>

                <h3>4. Senior Citizen / PWD discount</h3>
                <ul>
                    <li>Eligible Senior Citizens and Persons with Disability may receive the discount shown at checkout (currently 5%, applied per item). VAT is still charged on the discounted amount.</li>
                    <li><strong>Online orders:</strong> you must upload a clear photo of a valid Senior Citizen or PWD ID. The discounted total is shown right away, but the discount stays <em>pending</em> until our admin verifies the ID.</li>
                    <li>If the ID cannot be verified, or the discount is rejected, the order goes back to the full price and you will need to pay the difference.</li>
                    <li><strong>Reservations:</strong> bring your valid ID at pickup. Our staff will verify it in person.</li>
                    <li>The discount is for the ID holder's own purchase only. Using someone else's ID or a false ID may lead to cancellation of the order and suspension of your account.</li>
                </ul>

                <h3>5. Delivery</h3>
                <p>
                    Please make sure your address and phone number are correct
                    and that someone can receive the order. We may call or
                    message you about your delivery. If delivery fails because
                    of wrong or incomplete details, or because nobody could
                    receive the order, we may not be able to refund or
                    re-deliver it.
                </p>

                <h3>6. Reviews</h3>
                <p>
                    You can rate an order after it is completed. Please keep
                    reviews honest and respectful. We may remove reviews that
                    are abusive, false or unrelated. By posting a review you
                    allow us to show it on our product pages together with
                    your first name and the first letter of your last name,
                    and the date.
                </p>

                <h3>7. Acceptable use</h3>
                <p>
                    Do not misuse the website: no attempts to hack, overload
                    or disrupt it, no automated scraping, no fake orders,
                    and no use of another person's account.
                </p>

                <h3>8. Our responsibility</h3>
                <p>
                    We work hard to keep the website available and accurate,
                    but it is provided "as is" and may sometimes be
                    unavailable. To the extent allowed by law, Liberty
                    Foodmart is not liable for indirect or consequential
                    losses. Nothing in these Terms limits any right you
                    have under Philippine consumer protection laws.
                </p>

                <h3>9. Changes to these Terms</h3>
                <p>
                    We may update these Terms from time to time. The latest
                    version will always be available on this website, and
                    continuing to use the website after a change means you
                    accept the updated Terms.
                </p>

                <h3>10. Contact us</h3>
                <p>
                    Questions about these Terms? Reach us at
                    <strong><?= e($legal_email) ?></strong> or visit us at
                    <?= e($legal_address) ?>.
                </p>
            </article>

            <!-- PRIVACY POLICY -->
            <article class="legal-doc" id="doc-privacy">
                <h2>Privacy Policy</h2>
                <p class="updated">Last updated: October 23, 2026</p>

                <p>
                    Liberty Foodmart ("we", "us") respects your privacy.
                    This policy explains what personal information we collect,
                    why we collect it, and how we protect it, in line with
                    the Philippine Data Privacy Act of 2012 (Republic Act No.
                    10173). By signing up, you consent to the handling of
                    your information as described here.
                </p>

                <h3>1. What we collect</h3>
                <ul>
                    <li><strong>Account details:</strong> your name (username), email address and password. Your password is stored only as a scrambled (hashed) value. We cannot read it.</li>
                    <li><strong>Contact and delivery details:</strong> contact number and delivery address, which you enter at checkout or in My Account.</li>
                    <li><strong>Order and reservation records:</strong> items, quantities, totals, payment method and status, delivery or pickup details, and the date and time.</li>
                    <li><strong>Senior Citizen / PWD ID photo</strong> (only if you ask for the discount on an online order). This is a government-issued ID and counts as <em>sensitive personal information</em>.</li>
                    <li><strong>Reviews and ratings</strong> you submit.</li>
                    <li><strong>Technical data:</strong> a session cookie that keeps you logged in, your shopping cart (kept in your browser until you close it), and a record of failed login attempts, stored as a one-way hash of your email and device address to block password guessing and deleted automatically after about a day.</li>
                </ul>

                <h3>2. Why we use it</h3>
                <ul>
                    <li>To create and manage your account and keep it secure.</li>
                    <li>To prepare, deliver and confirm your orders and reservations, and to contact you about them.</li>
                    <li>To check your eligibility for the Senior Citizen / PWD discount.</li>
                    <li>To show your reviews and to improve our products and service.</li>
                    <li>To keep sales, VAT and accounting records and to follow the law.</li>
                </ul>

                <p>We do not use your information for advertising and we do not sell or rent it.</p>

                <h3>3. Who can see it</h3>
                <ul>
                    <li><strong>Our staff</strong> can see order details (name, contact number, address and items) so they can prepare and deliver your order.</li>
                    <li><strong>Only our admin</strong> can view uploaded ID photos. They are kept in a protected folder that cannot be opened by a direct web link.</li>
                    <li><strong>Delivery or payment partners</strong> receive only what they need to complete your order, for example if you choose an online payment method.</li>
                    <li>We may disclose information if required by law or by a government authority.</li>
                    <li>Other customers can see only your review, shown with a shortened name (for example "Juan C.").</li>
                </ul>

                <h3>4. How we protect it</h3>
                <p>
                    We use reasonable organisational, physical and technical
                    safeguards, including hashed passwords, restricted staff
                    access, protected storage of ID photos and login-attempt
                    limits. No system is perfectly secure, but if a breach
                    that puts your information at risk ever happens, we will
                    act on it and notify you and the National Privacy
                    Commission as the law requires.
                </p>

                <h3>5. How long we keep it</h3>
                <p>
                    We keep your account information while your account is
                    active. Order and sales records are kept for as long as
                    needed to run the shop and to meet accounting and tax
                    requirements. ID photos are kept only as long as needed
                    to verify the discount and for our record of that
                    transaction.
                </p>

                <h3>6. Your rights</h3>
                <p>Under the Data Privacy Act you have the right to:</p>
                <ul>
                    <li>be informed about how your data is used;</li>
                    <li>access the personal data we hold about you;</li>
                    <li>correct it (you can update your details anytime in My Account);</li>
                    <li>object to certain uses, or withdraw your consent;</li>
                    <li>ask us to suspend, remove or block your data, where allowed by law;</li>
                    <li>receive a copy of your data in a common format; and</li>
                    <li>complain to the National Privacy Commission (privacy.gov.ph) or claim damages if your rights are violated.</li>
                </ul>

                <p>To use any of these rights, contact us using the details below.</p>

                <h3>7. Cookies and local storage</h3>
                <p>
                    We use only what the website needs to work: a session
                    cookie to keep you logged in, and browser storage to
                    remember your cart. We do not use advertising or
                    tracking cookies.
                </p>

                <h3>8. Children</h3>
                <p>
                    This website is meant for adults. If you are under 18,
                    please ask a parent or guardian to signup and order
                    for you.
                </p>

                <h3>9. Changes to this policy</h3>
                <p>
                    We may update this policy from time to time. The latest
                    version will always be available on this website, with
                    its "last updated" date.
                </p>

                <h3>10. Contact us</h3>
                <p>
                    For privacy questions or requests, email
                    <strong><?= e($legal_email) ?></strong> or write to us at
                    <?= e($legal_address) ?>.
                </p>
            </article>
        </div>

        <div class="legal-foot">
            <button type="button" class="legal-btn ghost" id="legalCancel">
                Close
            </button>

            <button type="button" class="legal-btn" id="legalAgree">
                I Agree
            </button>
        </div>
    </div>
</div>

<script>
/* ── Show/hide password ── */
document.getElementById('eyePass').addEventListener('click', function () {
    const inp = document.getElementById('passInput');
    const isHidden = inp.type === 'password';

    inp.type = isHidden ? 'text' : 'password';

    document.getElementById('eyePassImg').src = isHidden
        ? 'src/hide-eye-removebg-preview.png'
        : 'src/show-eye-removebg-preview.png';
});

document.getElementById('eyeConfirm').addEventListener('click', function () {
    const inp = document.getElementById('confirmInput');
    const isHidden = inp.type === 'password';

    inp.type = isHidden ? 'text' : 'password';

    document.getElementById('eyeConfirmImg').src = isHidden
        ? 'src/hide-eye-removebg-preview.png'
        : 'src/show-eye-removebg-preview.png';
});

/* ── Password confirmation ── */
const passInput = document.getElementById('passInput');
const confirmInput = document.getElementById('confirmInput');
const registerForm = document.querySelector('form.log-in');

const matchMsg = document.createElement('p');
matchMsg.style.cssText =
    'color:#c62828;font-size:.7rem;width:100%;margin:.3rem 0 0;';

confirmInput.closest('.pass-wrap').insertAdjacentElement(
    'afterend',
    matchMsg
);

function checkPasswordsMatch() {
    if (confirmInput.value && passInput.value !== confirmInput.value) {
        matchMsg.textContent = 'Passwords do not match.';
        return false;
    }

    matchMsg.textContent = '';
    return true;
}

passInput.addEventListener('input', checkPasswordsMatch);
confirmInput.addEventListener('input', checkPasswordsMatch);

registerForm.addEventListener('submit', function (e) {
    if (!checkPasswordsMatch()) {
        e.preventDefault();
        confirmInput.focus();
    }
});

/* ── Terms and Privacy popup ── */
const legalOverlay = document.getElementById('legalOverlay');
const legalBody = document.getElementById('legalBody');
const agreeBox = document.getElementById('agreeBox');

function showLegalDoc(doc) {
    document.querySelectorAll('.legal-doc').forEach(d => {
        d.classList.toggle('show', d.id === 'doc-' + doc);
    });

    document.querySelectorAll('.legal-tab').forEach(t => {
        t.classList.toggle('active', t.dataset.doc === doc);
    });

    legalBody.scrollTop = 0;
}

function openLegal(doc) {
    showLegalDoc(doc);
    legalOverlay.classList.add('open');
}

function closeLegal() {
    legalOverlay.classList.remove('open');
}

document.querySelectorAll('.legal-link').forEach(a => {
    a.addEventListener('click', e => {
        e.preventDefault();
        openLegal(a.dataset.doc);
    });
});

document.querySelectorAll('.legal-tab').forEach(t => {
    t.addEventListener('click', () => showLegalDoc(t.dataset.doc));
});

document.getElementById('legalClose').addEventListener('click', closeLegal);
document.getElementById('legalCancel').addEventListener('click', closeLegal);

legalOverlay.addEventListener('click', e => {
    if (e.target === legalOverlay) {
        closeLegal();
    }
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeLegal();
    }
});

/* "I Agree" checks the agreement box */
document.getElementById('legalAgree').addEventListener('click', () => {
    agreeBox.checked = true;
    agreeBox.setCustomValidity('');
    closeLegal();
});

agreeBox.addEventListener('invalid', () => {
    agreeBox.setCustomValidity(
        'Please agree to the Terms of Service and Privacy Policy to register.'
    );
});

agreeBox.addEventListener('change', () => {
    agreeBox.setCustomValidity('');
});

/* ── Cursor glow ── */
const root = document.documentElement;
const card = document.querySelector('.main');

document.addEventListener('mousemove', e => {
    root.style.setProperty(
        '--mx',
        (e.clientX / window.innerWidth) * 100 + '%'
    );

    root.style.setProperty(
        '--my',
        (e.clientY / window.innerHeight) * 100 + '%'
    );
});

/* ── Desktop card tilt; disabled on touch devices ── */
if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
    card.addEventListener('mousemove', e => {
        const r = card.getBoundingClientRect();
        const px = (e.clientX - r.left) / r.width - 0.5;
        const py = (e.clientY - r.top) / r.height - 0.5;

        card.style.transform =
            `perspective(1400px) rotateX(${(-py * 2.5).toFixed(2)}deg) rotateY(${(px * 2.5).toFixed(2)}deg)`;
    });

    card.addEventListener('mouseleave', () => {
        card.style.transform =
            'perspective(1400px) rotateX(0deg) rotateY(0deg)';
    });
}
</script>
</body>
</html>
