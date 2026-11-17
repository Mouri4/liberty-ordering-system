<?php
// ── Liberty Foodmart — Customer Profile ─────────────────────
require_once 'session.php';
require 'db.php';

if (empty($_SESSION['customer_id'])) {
    header('Location: customer-login.php');
    exit;
}

$id      = (int)$_SESSION['customer_id'];
$success = false;
$error   = '';

// ── Handle save ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']     ?? '');
    $email    = trim($_POST['email']    ?? '');
    $phone    = trim($_POST['phone']    ?? '');
    $location = trim($_POST['location'] ?? '');

    if (!$name) {
        $error = 'Please enter your name.';
    } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Check email uniqueness (excluding self)
        if ($email) {
            $chk = $pdo->prepare('SELECT id FROM customers WHERE email = ? AND id != ?');
            $chk->execute([$email, $id]);
            if ($chk->fetch()) {
                $error = 'That email is already used by another account.';
            }
        }

        if (!$error) {
            $pdo->prepare('UPDATE customers SET name = ?, email = ?, phone = ?, default_location = ? WHERE id = ?')
                ->execute([$name, $email ?: null, $phone ?: null, $location ?: null, $id]);

            // Update session
            $_SESSION['customer_name']     = $name;
            $_SESSION['customer_email']    = $email;
            $_SESSION['customer_phone']    = $phone;
            $_SESSION['customer_location'] = $location;

            $success = true;
        }
    }
}

// ── Load current data ────────────────────────────────────────
$stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
$stmt->execute([$id]);
$customer = $stmt->fetch();

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account — Liberty Foodmart</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,600&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --cream:#FBF3E7; --cream-deep:#F1E4D3; --tan:#E8D5B7; --dark-brown:#3B2417; --brown:#8B4513;
                --text:#3B2417; --muted:#A98A6D; --white:#fff; --caramel:#C17817; --shadow:rgba(59,36,23,.10); }
        * { box-sizing:border-box; margin:0; padding:0; }
        html, body { overflow-x:hidden; max-width:100%; }
        body { font-family:'Inter',Arial,sans-serif; background:var(--cream); color:var(--text); min-height:100vh; }

        .page { max-width:36rem; margin:0 auto; padding:1.8rem 5% 3rem; }
        .page h1 { font-family:'Playfair Display',serif; font-size:1.7rem; color:var(--dark-brown); margin-bottom:.2rem; }
        .page .intro { font-size:.88rem; color:var(--muted); margin-bottom:1.2rem; line-height:1.5; }

        .profile-form { background:var(--white); border:1px solid var(--tan); border-radius:.9rem; padding:1.4rem; box-shadow:0 2px 10px var(--shadow); }
        .form-group { margin-bottom:1rem; }
        .form-group label { display:block; font-size:.78rem; font-weight:700; margin-bottom:.3rem; color:var(--dark-brown); }
        .form-group input, .form-group textarea { width:100%; padding:.65rem .8rem; border:1.5px solid var(--tan); border-radius:.6rem; font-size:.88rem; font-family:inherit; background:var(--cream); color:var(--text); }
        .form-group input:focus, .form-group textarea:focus { outline:none; border-color:var(--caramel); }
        .form-group textarea { min-height:5.5rem; resize:vertical; }

        .form-actions { display:flex; gap:.6rem; flex-wrap:wrap; margin-top:1.2rem; }
        .btn { padding:.65rem 1.3rem; border-radius:.6rem; font-size:.82rem; font-weight:700; cursor:pointer; border:none; text-decoration:none; display:inline-block; font-family:inherit; text-align:center; }
        .btn-primary   { background:var(--dark-brown); color:var(--white); }
        .btn-primary:hover { background:var(--brown); }
        .btn-secondary { background:var(--cream-deep); color:var(--dark-brown); }
        .btn-danger    { background:#fdecea; color:#c62828; }

        .success-msg { margin-bottom:1rem; padding:.7rem .9rem; background:#e8f5e9; color:#2e7d32; border-radius:.6rem; font-size:.84rem; font-weight:600; }
        .error-msg   { margin-bottom:1rem; padding:.7rem .9rem; background:#fdecea; color:#c62828; border-radius:.6rem; font-size:.84rem; font-weight:600; }

        @media (max-width:640px) {
            .page { padding:1.1rem 4% 2rem; }
            .page h1 { font-size:1.45rem; }
            .page .intro { font-size:.82rem; margin-bottom:.9rem; }
            .profile-form { padding:1.1rem; }
            .form-group input, .form-group textarea { font-size:16px; min-height:2.6rem; }
            .form-group textarea { min-height:5rem; }
            .form-actions { flex-direction:column; }
            .btn { width:100%; min-height:2.7rem; display:flex; align-items:center; justify-content:center; }
        }
    </style>
</head>
<body>
<?php $nav_active = 'account'; $nav_cart = 'link'; require 'customer-nav.php'; ?>

<main class="page">
    <h1>My Account</h1>
    <p class="intro">Update your personal details used for checkout and order tracking.</p>

    <?php if ($success): ?>
        <div class="success-msg">Profile saved successfully.</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="error-msg"><?= e($error) ?></div>
    <?php endif; ?>

    <form class="profile-form" method="POST" action="customer-profile.php">
        <div class="form-group">
            <label for="profileName">Full Name</label>
            <input type="text" id="profileName" name="name"
                   value="<?= e($customer['name'] ?? '') ?>" placeholder="Your full name" required>
        </div>
        <div class="form-group">
            <label for="profileEmail">Email</label>
            <input type="email" id="profileEmail" name="email"
                   value="<?= e($customer['email'] ?? '') ?>" placeholder="your@email.com">
        </div>
        <div class="form-group">
            <label for="profilePhone">Phone Number</label>
            <input type="text" id="profilePhone" name="phone"
                   value="<?= e($customer['phone'] ?? '') ?>" placeholder="09XXXXXXXXX">
        </div>
        <div class="form-group">
            <label for="profileLocation">Default Delivery Location</label>
            <textarea id="profileLocation" name="location" placeholder="Your usual delivery address"><?= e($customer['default_location'] ?? '') ?></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a href="ordering-page.php" class="btn btn-secondary">Back to Shop</a>
            <a href="customer-logout.php" class="btn btn-danger">Logout</a>
        </div>
    </form>
</main>
</body>
</html>