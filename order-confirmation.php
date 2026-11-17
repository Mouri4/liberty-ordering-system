<?php
// ── Liberty Foodmart — Order / Reservation Confirmation ──────
require_once 'session.php';
require 'db.php';
require 'vat.php';

$order       = null;
$reservation = null;
$type        = '';

// ── Access control ───────────────────────────────────────────
// Order/reservation IDs are sequential, so without this check anyone
// could walk ?order_id=1001, 1002, ... and read other people's names,
// phone numbers and delivery addresses. A page like this must only
// show a record to the customer who owns it (or to logged-in staff).
$viewer_cust_id = (int)($_SESSION['customer_id'] ?? 0);
$viewer_is_staff = !empty($_SESSION['staff_id']);

if (!$viewer_cust_id && !$viewer_is_staff) {
    header('Location: customer-login.php');
    exit;
}

// ── Load order ───────────────────────────────────────────────
if (!empty($_GET['order_id'])) {
    $id   = (int)$_GET['order_id'];
    $stmt = $pdo->prepare("
        SELECT o.*,
               GROUP_CONCAT(oi.product_name, ' x', oi.quantity, ' @ ₱', FORMAT(oi.unit_price,0) ORDER BY oi.id SEPARATOR '||') AS items_raw,
               GROUP_CONCAT(oi.product_name ORDER BY oi.id SEPARATOR ', ') AS items_text
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        WHERE o.id = ?" . ($viewer_is_staff ? '' : ' AND o.customer_id = ?') . "
        GROUP BY o.id
    ");
    $stmt->execute($viewer_is_staff ? [$id] : [$id, $viewer_cust_id]);
    $order = $stmt->fetch();

    // Also get items as array for display
    if ($order) {
        $type = 'order';
        $items_stmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $items_stmt->execute([$id]);
        $order['items'] = $items_stmt->fetchAll();
    }
}

// ── Load reservation ─────────────────────────────────────────
if (!empty($_GET['reservation_id'])) {
    $id   = (int)$_GET['reservation_id'];
    $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ?' . ($viewer_is_staff ? '' : ' AND customer_id = ?'));
    $stmt->execute($viewer_is_staff ? [$id] : [$id, $viewer_cust_id]);
    $reservation = $stmt->fetch();
    if ($reservation) $type = 'reservation';
}

$not_found = !$order && !$reservation;

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $type === 'reservation' ? 'Reservation Confirmed' : 'Order Confirmed' ?> — Liberty Foodmart</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,600&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--cream:#FBF3E7;--cream-deep:#F1E4D3;--tan:#E8D5B7;--dark-brown:#3B2417;--brown:#8B4513;--text:#3B2417;--muted:#A98A6D;--white:#fff;--shadow:rgba(59,36,23,.10);}
        *{box-sizing:border-box;margin:0;padding:0;}
        html,body{overflow-x:hidden;max-width:100%;}
        body{font-family:'Inter',Arial,sans-serif;background:var(--cream);color:var(--text);min-height:100vh;}
        .wrap{padding:1.6rem 5% 3rem;}

        .confirm-wrap{max-width:44rem;margin:0 auto;background:var(--white);border:1px solid var(--tan);border-radius:1rem;padding:1.8rem;box-shadow:0 6px 22px var(--shadow);}
        .confirm-badge{display:inline-block;padding:.35rem .9rem;border-radius:2rem;font-size:.78rem;font-weight:700;margin-bottom:.9rem;}
        .badge-order{background:#e8f5e9;color:#2e7d32;}
        .badge-reservation{background:#fff8e1;color:#f57f17;}
        .confirm-wrap h1{font-family:'Playfair Display',serif;font-size:1.7rem;color:var(--dark-brown);margin-bottom:.4rem;line-height:1.2;}
        .confirm-wrap .sub{font-size:.9rem;color:var(--muted);margin-bottom:1.4rem;line-height:1.5;}

        .order-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.7rem;margin-bottom:1.3rem;}
        .meta-box{background:var(--cream);border-radius:.7rem;padding:.7rem .9rem;min-width:0;}
        .meta-box span{display:block;font-size:.7rem;color:var(--muted);margin-bottom:.2rem;font-weight:600;}
        .meta-box strong{font-size:.9rem;color:var(--dark-brown);overflow-wrap:anywhere;}

        .status-pill{display:inline-block;padding:.2rem .7rem;border-radius:1rem;font-size:.72rem;font-weight:700;}
        .pill-processing{background:#fff3cd;color:#856404;}
        .pill-reservation{background:#fff8e1;color:#f57f17;}
        .pill-paid{background:#e8f5e9;color:#2e7d32;}
        .pill-unpaid{background:#fce4ec;color:#ad1457;}

        .items-section h2{font-family:'Playfair Display',serif;font-size:1.15rem;color:var(--dark-brown);margin-bottom:.5rem;}
        .item-row{display:flex;justify-content:space-between;gap:1rem;padding:.65rem 0;border-bottom:1px solid var(--tan);font-size:.88rem;line-height:1.45;}
        .item-row span:first-child{min-width:0;overflow-wrap:anywhere;}
        .item-row span:last-child{white-space:nowrap;}
        .item-row.total{border-bottom:none;font-weight:800;font-size:1.1rem;color:var(--dark-brown);margin-top:.2rem;}
        .item-row.discount-row{color:#2e7d32;font-weight:700;}
        .item-discount-note{color:#2e7d32;font-size:.74rem;font-weight:600;}
        .vat-note{font-size:.74rem;color:var(--muted);margin-top:.6rem;text-align:right;}

        .discount-pending-box,.pickup-box{background:#fff8e1;border:1px solid #ffe082;border-radius:.7rem;padding:.8rem 1rem;margin-bottom:1.1rem;}
        .discount-pending-box p{font-size:.82rem;color:#7a5c00;line-height:1.5;margin:0;}
        .discount-rejected-box{background:#fdecea;border:1px solid #f5c6cb;border-radius:.7rem;padding:.8rem 1rem;margin-bottom:1.1rem;}
        .discount-rejected-box p{font-size:.82rem;color:#c62828;line-height:1.5;margin:0;}
        .pickup-box p{font-size:.82rem;color:#7a5c00;margin:0;}
        .pickup-box strong{display:block;font-size:1rem;color:#5a4000;margin-top:.25rem;}

        .actions{display:flex;gap:.6rem;margin-top:1.5rem;flex-wrap:wrap;}
        .btn{padding:.7rem 1.4rem;border-radius:.65rem;font-size:.84rem;font-weight:700;text-decoration:none;cursor:pointer;border:none;display:inline-block;text-align:center;font-family:inherit;}
        .btn-primary{background:var(--dark-brown);color:var(--white);}
        .btn-primary:hover{background:var(--brown);}
        .btn-secondary{background:var(--cream-deep);color:var(--dark-brown);}

        .error-msg{text-align:center;padding:2.5rem 1.5rem;font-size:.95rem;}

        @media (max-width:640px){
            .wrap{padding:1rem 4% 2rem;}
            .confirm-wrap{padding:1.15rem;border-radius:.9rem;}
            .confirm-wrap h1{font-size:1.4rem;}
            .confirm-wrap .sub{font-size:.82rem;margin-bottom:1.1rem;}
            .order-meta{grid-template-columns:1fr;gap:.5rem;}
            .actions{flex-direction:column;gap:.5rem;}
            .btn{width:100%;min-height:2.8rem;display:flex;align-items:center;justify-content:center;}
        }
    </style>
</head>
<body>
<?php $nav_active = 'orders'; $nav_cart = 'link'; require 'customer-nav.php'; ?>
<div class="wrap">

<?php if ($not_found): ?>
    <div class="confirm-wrap error-msg">
        <p>Order not found. It may have expired or the link is invalid.</p>
        <div class="actions" style="justify-content:center;margin-top:2rem;">
            <a href="ordering-page.php" class="btn btn-primary">Back to Shop</a>
        </div>
    </div>

<?php elseif ($type === 'order' && $order): ?>
    <div class="confirm-wrap">
        <span class="confirm-badge badge-order">Order placed successfully</span>
        <h1>Thank you for your order!</h1>
        <p class="sub">Your order has been sent to our staff. We will process it shortly.</p>

        <?php if ($order['is_senior_pwd'] && $order['discount_status'] === 'pending'): ?>
            <div class="discount-pending-box">
                <p><strong>Senior/PWD discount pending verification.</strong> The total below already reflects your 5% discount. Our admin is reviewing the ID photo you uploaded — if it can't be verified, this order will revert to full price and you'll need to settle the difference.</p>
            </div>
        <?php elseif ($order['is_senior_pwd'] && $order['discount_status'] === 'rejected'): ?>
            <div class="discount-rejected-box">
                <p><strong>Senior/PWD discount was not approved.</strong> This order now reflects the full price shown below.</p>
            </div>
        <?php endif; ?>

        <div class="order-meta">
            <div class="meta-box">
                <span>Order ID</span>
                <strong>#<?= $order['id'] ?></strong>
            </div>
            <div class="meta-box">
                <span>Status</span>
                <strong><span class="status-pill pill-processing"><?= e($order['order_status']) ?></span></strong>
            </div>
            <div class="meta-box">
                <span>Delivery Location</span>
                <strong><?= e($order['location']) ?></strong>
            </div>
            <div class="meta-box">
                <span>Contact</span>
                <strong><?= e($order['phone']) ?></strong>
            </div>
            <div class="meta-box">
                <span>Payment Method</span>
                <strong><?= e($order['payment_method']) ?></strong>
            </div>
            <div class="meta-box">
                <span>Payment Status</span>
                <strong>
                    <span class="status-pill <?= $order['payment_status'] === 'PAID' ? 'pill-paid' : 'pill-unpaid' ?>">
                        <?= e($order['payment_status']) ?>
                    </span>
                </strong>
            </div>
            <?php if ($order['is_senior_pwd']): ?>
            <div class="meta-box">
                <span>Senior/PWD Discount</span>
                <strong>
                    <?php if ($order['discount_status'] === 'pending'): ?>
                        <span class="status-pill pill-processing">Pending Verification</span>
                    <?php elseif ($order['discount_status'] === 'approved'): ?>
                        <span class="status-pill pill-paid">Approved</span>
                    <?php elseif ($order['discount_status'] === 'rejected'): ?>
                        <span class="status-pill pill-unpaid">Rejected</span>
                    <?php endif; ?>
                </strong>
            </div>
            <?php endif; ?>
        </div>

        <div class="items-section">
            <h2>Order Summary</h2>
            <?php foreach ($order['items'] as $item): ?>
            <div class="item-row">
                <span>
                    <?= e($item['product_name']) ?> ×<?= (int)$item['quantity'] ?>
                    <?php if ($item['discount_amount'] > 0): ?>
                        <br><span class="item-discount-note">Senior/PWD -<?= peso((float)$item['discount_amount']) ?></span>
                    <?php endif; ?>
                </span>
                <span>₱<?= number_format($item['line_total'], 2) ?></span>
            </div>
            <?php endforeach; ?>

            <?php if ($order['discount_amount'] > 0): ?>
            <div class="item-row">
                <span>Subtotal</span>
                <span>₱<?= number_format($order['subtotal'], 2) ?></span>
            </div>
            <div class="item-row discount-row">
                <span>Senior/PWD Discount (5%)</span>
                <span>-₱<?= number_format($order['discount_amount'], 2) ?></span>
            </div>
            <?php endif; ?>

            <div class="item-row total">
                <span>Total</span>
                <span>₱<?= number_format($order['total'], 2) ?></span>
            </div>
            <p class="vat-note">Prices are inclusive of 12% VAT (₱<?= number_format($order['vat_amount'], 2) ?> VAT included).</p>
        </div>

        <div class="actions">
            <a href="customer-orders.php" class="btn btn-primary">View My Orders</a>
            <a href="ordering-page.php"   class="btn btn-secondary">Continue Shopping</a>
        </div>
    </div>

<?php elseif ($type === 'reservation' && $reservation): ?>
    <?php
    $pickup_dt  = new DateTime($reservation['pickup_datetime']);
    $pickup_str = $pickup_dt->format('F j, Y') . ' at ' . strtolower($pickup_dt->format('g:i a'));
    ?>
    <div class="confirm-wrap">
        <span class="confirm-badge badge-reservation"> Reservation confirmed!</span>
        <h1>Your reservation is set!</h1>
        <p class="sub">Please arrive at the pickup time below. Show this confirmation to our staff.</p>

        <div class="pickup-box">
            <p> Pickup Schedule</p>
            <strong><?= e($pickup_str) ?></strong>
        </div>

        <div class="order-meta">
            <div class="meta-box">
                <span>Reservation ID</span>
                <strong>#<?= $reservation['id'] ?></strong>
            </div>
            <div class="meta-box">
                <span>Status</span>
                <strong><span class="status-pill pill-reservation"><?= e($reservation['status']) ?></span></strong>
            </div>
            <div class="meta-box">
                <span>Customer Name</span>
                <strong><?= e($reservation['customer_name']) ?></strong>
            </div>
            <div class="meta-box">
                <span>Contact</span>
                <strong><?= e($reservation['contact']) ?></strong>
            </div>
            <div class="meta-box">
                <span>Payment Method</span>
                <strong><?= e($reservation['payment_method']) ?></strong>
            </div>
            <div class="meta-box">
                <span>Payment Status</span>
                <strong>
                    <span class="status-pill <?= $reservation['payment_status'] === 'PAID' ? 'pill-paid' : 'pill-unpaid' ?>">
                        <?= e($reservation['payment_status']) ?>
                    </span>
                </strong>
            </div>
        </div>

        <div class="items-section">
            <h2>Reservation Summary</h2>
            <div class="item-row">
                <span><?= e($reservation['product_name']) ?> ×<?= (int)$reservation['quantity'] ?></span>
                <span>₱<?= number_format($reservation['subtotal'], 2) ?></span>
            </div>

            <?php if ($reservation['discount_amount'] > 0): ?>
            <div class="item-row discount-row">
                <span>Senior/PWD Discount (5%)</span>
                <span>-₱<?= number_format($reservation['discount_amount'], 2) ?></span>
            </div>
            <?php endif; ?>

            <div class="item-row total">
                <span>Total</span>
                <span>₱<?= number_format($reservation['total_price'], 2) ?></span>
            </div>
            <p class="vat-note">Prices are inclusive of 12% VAT (₱<?= number_format($reservation['vat_amount'], 2) ?> VAT included).</p>
            <?php if ($reservation['is_senior_pwd']): ?>
                <p class="vat-note" style="color:#7a5c00;">Please bring your Senior Citizen or PWD ID at pickup for staff to verify.</p>
            <?php endif; ?>
        </div>

        <div class="actions">
            <a href="customer-orders.php" class="btn btn-primary">View My Orders</a>
            <a href="ordering-page.php"   class="btn btn-secondary">Continue Shopping</a>
        </div>
    </div>
<?php endif; ?>

</div>
</body>
</html>