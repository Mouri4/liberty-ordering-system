<?php
// ── Liberty Foodmart — My Orders ────────────────────────────
require_once 'session.php';
require 'db.php';
require_once 'reservation-stock.php';

// ── Handle order CANCEL by the customer (AJAX) ───────────────
// Only allowed while the order is still "Processing". Once the shop marks it
// Ready for Delivery / Out for Delivery it is on its way and can't be cancelled.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_order') {
    header('Content-Type: application/json');

    if (empty($_SESSION['customer_id'])) {
        echo json_encode(['ok' => false, 'error' => 'Please log in again.']);
        exit;
    }
    $cust_id  = (int)$_SESSION['customer_id'];
    $order_id = (int)($_POST['order_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        // Lock the row: the shop changing the status at the same moment can't race this
        $st = $pdo->prepare("SELECT order_status FROM orders WHERE id = ? AND customer_id = ? AND order_type = 'online' FOR UPDATE");
        $st->execute([$order_id, $cust_id]);
        $status = $st->fetchColumn();

        if ($status === false) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'Order not found.']);
            exit;
        }
        if ($status !== 'Processing') {
            $pdo->rollBack();
            $why = match ($status) {
                'Cancelled' => 'This order is already cancelled.',
                'Done'      => 'This order is already completed.',
                default     => 'This order is already ' . strtolower($status) . ', so it can no longer be cancelled.',
            };
            echo json_encode(['ok' => false, 'error' => $why]);
            exit;
        }

        order_restore_stock($pdo, $order_id);   // items go back to the shelf

        $pdo->prepare("
            UPDATE orders
               SET order_status = 'Cancelled', cancelled_by = 'customer', cancelled_at = NOW(), cancel_notified = 1
             WHERE id = ?
        ")->execute([$order_id]);

        $pdo->commit();
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('customer cancel_order failed: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Could not cancel the order. Please try again.']);
    }
    exit;
}

// ── Customer dismissed the "cancelled by staff" notice (AJAX) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ack_cancel_notice') {
    header('Content-Type: application/json');
    if (!empty($_SESSION['customer_id'])) {
        $pdo->prepare("UPDATE orders SET cancel_notified = 1 WHERE customer_id = ? AND order_status = 'Cancelled' AND cancelled_by = 'staff'")
            ->execute([(int)$_SESSION['customer_id']]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ── Handle rating POST (AJAX) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_rating') {
    header('Content-Type: application/json');

    if (empty($_SESSION['customer_id'])) {
        echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
        exit;
    }

    $order_id   = (int)($_POST['order_id'] ?? 0);
    $stars      = (int)($_POST['stars']    ?? 0);
    $comment    = trim($_POST['comment']   ?? '');
    $cust_id    = (int)$_SESSION['customer_id'];

    if ($stars < 1 || $stars > 5) {
        echo json_encode(['ok' => false, 'error' => 'Invalid star rating.']);
        exit;
    }

    $reservation_id = (int)($_POST['reservation_id'] ?? 0);

    if ($reservation_id > 0) {
        // Reservation review: must be this customer's, and already picked up (Complete)
        $chk = $pdo->prepare("SELECT id FROM reservations WHERE id = ? AND customer_id = ? AND status = 'Complete'");
        $chk->execute([$reservation_id, $cust_id]);
        if (!$chk->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'Reservation not found or not completed.']);
            exit;
        }

        // Insert or update review (UNIQUE on reservation_id)
        $pdo->prepare("
            INSERT INTO reviews (reservation_id, customer_id, stars, comment)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE stars = VALUES(stars), comment = VALUES(comment), rated_at = NOW()
        ")->execute([$reservation_id, $cust_id, $stars, $comment ?: null]);

        echo json_encode(['ok' => true]);
        exit;
    }

    // Verify the order belongs to this customer and is Done
    $chk = $pdo->prepare("SELECT id FROM orders WHERE id = ? AND customer_id = ? AND order_status = 'Done'");
    $chk->execute([$order_id, $cust_id]);
    if (!$chk->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Order not found or not completed.']);
        exit;
    }

    // Insert or update review (UNIQUE on order_id)
    $pdo->prepare("
        INSERT INTO reviews (order_id, customer_id, stars, comment)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE stars = VALUES(stars), comment = VALUES(comment), rated_at = NOW()
    ")->execute([$order_id, $cust_id, $stars, $comment ?: null]);

    echo json_encode(['ok' => true]);
    exit;
}

// ── Require login ────────────────────────────────────────────
if (empty($_SESSION['customer_id'])) {
    header('Location: customer-login.php?redirect=customer-orders.php');
    exit;
}

$cust_id       = (int)$_SESSION['customer_id'];
$cust_name     = $_SESSION['customer_name'] ?? '';
$is_logged_in  = true;
$tab     = ($_GET['tab'] ?? 'active') === 'done' ? 'done' : 'active';

// ── Query orders ─────────────────────────────────────────────
$where = $tab === 'done'
    ? "o.order_status = 'Done'"
    : "o.order_status != 'Done'";

$orders = $pdo->prepare("
    SELECT
        o.*,
        GROUP_CONCAT(CONCAT(oi.product_name, ' ', oi.quantity, 'x') ORDER BY oi.id SEPARATOR ', ') AS items_text,
        r.stars        AS review_stars,
        r.comment      AS review_comment,
        r.rated_at     AS review_rated_at
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN reviews r ON r.order_id = o.id
    WHERE o.customer_id = ? AND {$where}
    GROUP BY o.id
    ORDER BY o.created_at DESC
");
$orders->execute([$cust_id]);
$orders = $orders->fetchAll();

// ── Query reservations made by this customer ─────────────────
// Reservation "Active" maps to the Active tab; "Complete" maps
// to the Done tab. "Cancelled" is also shown under Active so it
// isn't hidden from the customer.
$res_where = $tab === 'done'
    ? "r.status = 'Complete'"
    : "r.status != 'Complete'";

$reservations = $pdo->prepare("
    SELECT r.*,
           rv.stars   AS review_stars,
           rv.comment AS review_comment
    FROM reservations r
    LEFT JOIN reviews rv ON rv.reservation_id = r.id
    WHERE r.customer_id = ? AND {$res_where}
    ORDER BY r.created_at DESC
");
$reservations->execute([$cust_id]);
$reservations = $reservations->fetchAll();

// ── Merge orders + reservations into one timeline ─────────────
$entries = [];
foreach ($orders as $o) {
    $entries[] = ['kind' => 'order', 'sort_at' => $o['created_at'], 'data' => $o];
}
foreach ($reservations as $r) {
    $entries[] = ['kind' => 'reservation', 'sort_at' => $r['created_at'], 'data' => $r];
}
usort($entries, fn($a, $b) => strcmp($b['sort_at'], $a['sort_at']));

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// Reservation statuses in the database -> what the customer sees.
// (Active = Processing, Complete = Done; the same wording as orders.)
function reservation_status_tag(string $s): string {
    $map = [
        'Active'           => ['processing', 'Processing'],
        'Ready for Pickup' => ['ready',      'Ready for Pickup'],
        'Complete'         => ['done',       'Done'],
        'Cancelled'        => ['cancelled',  'Cancelled'],
    ];
    [$cls, $label] = $map[$s] ?? ['processing', $s];
    return '<span class="tag ' . $cls . '">' . e($label) . '</span>';
}

function status_tag(string $s): string {
    $map = ['Processing' => 'processing', 'Ready for Delivery' => 'ready', 'Out for Delivery' => 'out', 'Done' => 'done', 'Cancelled' => 'cancelled'];
    $cls = $map[$s] ?? 'processing';
    return '<span class="tag ' . $cls . '">' . e($s) . '</span>';
}

function payment_tag(string $s): string {
    $cls = $s === 'PAID' ? 'paid' : 'unpaid';
    return '<span class="tag ' . $cls . '">' . e($s) . '</span>';
}

function discount_status_tag(string $s): string {
    $map = [
        'pending'  => ['processing', 'Discount: Pending'],
        'approved' => ['done',       'Discount: Approved'],
        'rejected' => ['cancelled',  'Discount: Rejected'],
    ];
    [$cls, $label] = $map[$s] ?? ['processing', 'Discount: ' . ucfirst($s)];
    return '<span class="tag ' . $cls . '">' . e($label) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders — Liberty Foodmart</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,600&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --cream:#FBF3E7; --cream-deep:#F1E4D3; --tan:#E8D5B7; --dark-brown:#3B2417; --brown:#8B4513;
                --text:#3B2417; --muted:#A98A6D; --white:#fff; --choc:#5C3A24; --caramel:#C17817; --shadow:rgba(59,36,23,.10); }
        * { box-sizing:border-box; margin:0; padding:0; }
        html, body { overflow-x:hidden; max-width:100%; }
        body { font-family:'Inter',Arial,sans-serif; background:var(--cream); color:var(--text); min-height:100vh; }

        .page { max-width:56rem; margin:0 auto; padding:1.8rem 5% 3rem; }
        .page h1 { font-family:'Playfair Display',serif; font-size:1.7rem; color:var(--dark-brown); margin-bottom:.2rem; }
        .page .intro { font-size:.88rem; color:var(--muted); margin-bottom:1.2rem; }

        .tabs { display:flex; gap:.3rem; margin-bottom:1.2rem; background:var(--cream-deep); padding:.25rem; border-radius:2rem; width:fit-content; }
        .tab-btn { padding:.5rem 1.5rem; border-radius:1.6rem; font-size:.82rem; font-weight:700; color:var(--choc); text-decoration:none; transition:background .15s,color .15s; }
        .tab-btn.active { background:var(--dark-brown); color:var(--white); box-shadow:0 3px 10px rgba(59,36,23,.25); }

        .orders-list { display:flex; flex-direction:column; gap:.9rem; }

        .order-card { background:var(--white); border:1px solid var(--tan); border-radius:.9rem; padding:1.15rem 1.35rem; box-shadow:0 2px 10px var(--shadow); }
        .order-card-head { display:flex; justify-content:space-between; align-items:flex-start; gap:.8rem; margin-bottom:.6rem; flex-wrap:wrap; }
        .order-card-head h2 { font-size:1.02rem; font-weight:700; color:var(--dark-brown); }
        .order-date { font-size:.74rem; color:var(--muted); margin-top:.15rem; }

        .status-tags { display:flex; gap:.4rem; flex-wrap:wrap; }
        .tag { padding:.2rem .65rem; border-radius:1rem; font-size:.68rem; font-weight:700; white-space:nowrap; }
        .tag.processing { background:#fff3cd; color:#856404; }
        .tag.ready      { background:#e0f2f1; color:#00695c; }
        .tag.out        { background:#ede7f6; color:#4527a0; }
        .tag.done       { background:#e8f5e9; color:#2e7d32; }
        .tag.cancelled  { background:#fdecea; color:#c62828; }
        .tag.unpaid     { background:#fce4ec; color:#ad1457; }
        .tag.paid       { background:#e3f2fd; color:#1565c0; }

        /* Delivery progress */
        .tracker { list-style:none; display:flex; margin:.9rem 0 .4rem; padding:0; }
        .tracker li { flex:1; position:relative; text-align:center; font-size:.64rem; font-weight:600; color:var(--muted); }
        .tracker .dot { display:block; width:.8rem; height:.8rem; border-radius:50%; background:#e6d8c3; margin:0 auto .3rem; position:relative; z-index:1; }
        .tracker li::before { content:''; position:absolute; top:.37rem; left:-50%; width:100%; height:2px; background:#e6d8c3; }
        .tracker li:first-child::before { display:none; }
        .tracker li.done .dot, .tracker li.current .dot { background:var(--caramel); }
        .tracker li.done::before, .tracker li.current::before { background:var(--caramel); }
        .tracker li.done { color:var(--choc); }
        .tracker li.current { color:var(--dark-brown); font-weight:800; }
        .tracker li.current .dot { box-shadow:0 0 0 4px rgba(193,120,23,.22); }

        .ready-note { margin-top:.7rem; padding:.65rem .85rem; background:#e0f2f1; color:#00574f; border-radius:.6rem; font-size:.8rem; line-height:1.55; }
        .cancel-note { margin-top:.7rem; padding:.55rem .8rem; background:#fdecea; color:#c62828; border-radius:.6rem; font-size:.8rem; font-weight:600; }
        .cancel-order-btn { margin-top:.8rem; padding:.55rem 1.2rem; background:#fff; color:#c62828; border:1.5px solid #f0c4c4; border-radius:.6rem; font-size:.8rem; font-weight:700; cursor:pointer; font-family:inherit; }
        .cancel-order-btn:hover { background:#fdecea; }
        .no-cancel-note { margin-top:.7rem; font-size:.74rem; color:var(--muted); }

        /* Message / confirmation modal */
        .lf-modal { position:fixed; inset:0; z-index:2000; background:rgba(30,15,5,.55); display:none; align-items:center; justify-content:center; padding:1rem; }
        .lf-modal.open { display:flex; }
        .lf-modal-box { background:#fff; border-radius:1rem; padding:1.5rem 1.4rem 1.2rem; width:100%; max-width:22rem; text-align:center; box-shadow:0 20px 50px rgba(30,15,5,.3); border-top:4px solid var(--caramel); }
        .lf-modal-box.danger { border-top-color:#c62828; }
        .lf-modal-box h3 { font-size:1.05rem; color:var(--dark-brown); margin-bottom:.4rem; }
        .lf-modal-box p { font-size:.86rem; color:#6b5a4c; line-height:1.55; white-space:pre-line; }
        .lf-modal-btns { display:flex; gap:.6rem; margin-top:1.1rem; }
        .lf-modal-btns button { flex:1; padding:.65rem; border:none; border-radius:.6rem; font-size:.84rem; font-weight:700; cursor:pointer; font-family:inherit; }
        .lf-no  { background:var(--cream-deep); color:var(--dark-brown); }
        .lf-yes { background:var(--dark-brown); color:#fff; }
        .lf-modal-box.danger .lf-yes { background:#c62828; }

        .order-items { margin:.5rem 0 .6rem; font-size:.88rem; font-weight:600; line-height:1.5; overflow-wrap:anywhere; }
        .order-meta-row { font-size:.8rem; color:#6b5a4c; margin-bottom:.2rem; overflow-wrap:anywhere; }
        .order-meta-row.discount-line { color:#2e7d32; font-weight:700; }
        .order-total { font-size:1rem; font-weight:800; color:var(--brown); margin-top:.6rem; padding-top:.6rem; border-top:1px dashed var(--tan); }

        .rating-section { margin-top:1rem; padding-top:1rem; border-top:1px solid var(--tan); }
        .rating-section h3 { font-size:.92rem; margin-bottom:.5rem; color:var(--dark-brown); }
        .stars { display:flex; gap:.2rem; margin-bottom:.7rem; }
        .star-btn { background:none; border:none; font-size:1.8rem; cursor:pointer; color:#ddd; line-height:1; padding:0 .1rem; }
        .star-btn.active, .star-btn:hover { color:#f5a623; }
        .rating-section textarea { width:100%; min-height:5rem; padding:.7rem .8rem; border:1.5px solid var(--tan); border-radius:.6rem; font-size:.85rem; font-family:inherit; resize:vertical; background:var(--cream); color:var(--text); }
        .rating-section textarea:focus { outline:none; border-color:var(--caramel); }
        .submit-rating { margin-top:.7rem; padding:.6rem 1.3rem; background:var(--dark-brown); color:var(--white); border:none; border-radius:.6rem; font-size:.82rem; font-weight:700; cursor:pointer; font-family:inherit; }
        .submit-rating:hover { background:var(--brown); }
        .stars-display { color:#f5a623; font-size:1.3rem; letter-spacing:.1rem; }
        .review-comment-text { margin-top:.4rem; font-size:.84rem; color:#6b5a4c; font-style:italic; line-height:1.5; overflow-wrap:anywhere; }
        .rating-success { color:#2e7d32; font-size:.78rem; margin-top:.4rem; display:none; }

        .empty-state { text-align:center; padding:3rem 1.5rem; background:var(--white); border:1px dashed var(--tan); border-radius:.9rem; font-size:.92rem; color:var(--muted); line-height:1.7; }
        .empty-state a { color:var(--brown); font-weight:700; }

        @media (max-width:640px) {
            .page { padding:1.1rem 4% 2rem; }
            .page h1 { font-size:1.45rem; }
            .page .intro { font-size:.82rem; margin-bottom:.9rem; }
            .tabs { width:100%; position:sticky; top:calc(var(--cnav-h) + .4rem); z-index:10; box-shadow:0 6px 12px -8px rgba(59,36,23,.35); }
            .tab-btn { flex:1; text-align:center; padding:.55rem .5rem; }
            .orders-list { gap:.7rem; }
            .order-card { padding:1rem; border-radius:.8rem; }
            .order-card-head { flex-direction:column; gap:.5rem; }
            .order-card-head h2 { font-size:.98rem; }
            .star-btn { font-size:1.9rem; min-width:2.5rem; min-height:2.5rem; touch-action:manipulation; }
            .rating-section textarea { font-size:16px; }
            .submit-rating { width:100%; min-height:2.7rem; }
            .empty-state { padding:2.2rem 1.1rem; font-size:.88rem; }
        }
    </style>
</head>
<body>
<?php $nav_active = 'orders'; $nav_cart = 'link'; require 'customer-nav.php'; ?>

<main class="page">
    <h1>My Orders</h1>
    <p class="intro">Track your orders and rate completed deliveries.</p>

    <div class="tabs">
        <a href="customer-orders.php?tab=active"
           class="tab-btn <?= $tab === 'active' ? 'active' : '' ?>">Active</a>
        <a href="customer-orders.php?tab=done"
           class="tab-btn <?= $tab === 'done'   ? 'active' : '' ?>">Done</a>
    </div>

    <div class="orders-list">
        <?php if (empty($entries)): ?>
            <div class="empty-state">
                No orders here yet.
                <a href="ordering-page.php">Go shopping</a>
            </div>
        <?php else: ?>
            <?php foreach ($entries as $entry): ?>

                <?php if ($entry['kind'] === 'order'):
                    $o  = $entry['data'];
                    $dt = new DateTime($o['created_at']);
                ?>
                <article class="order-card" id="order-<?= $o['id'] ?>">
                    <div class="order-card-head">
                        <div>
                            <h2>Order #<?= $o['id'] ?></h2>
                            <p class="order-date"><?= $dt->format('M j, Y') ?> &middot; <?= strtolower($dt->format('g:i a')) ?></p>
                        </div>
                        <div class="status-tags">
                            <?= status_tag($o['order_status']) ?>
                            <?= payment_tag($o['payment_status']) ?>
                            <?php if ($o['is_senior_pwd']): ?><?= discount_status_tag($o['discount_status']) ?><?php endif; ?>
                        </div>
                    </div>

                    <?php if ($o['order_status'] !== 'Cancelled'):
                        $steps = ['Processing', 'Ready for Delivery', 'Out for Delivery', 'Done'];
                        $cur   = array_search($o['order_status'], $steps, true);
                        $cur   = $cur === false ? 0 : $cur;
                        $all   = $o['order_status'] === 'Done';
                    ?>
                        <ol class="tracker" aria-label="Order progress">
                            <?php foreach ($steps as $i => $label): ?>
                                <li class="<?= ($all || $i < $cur) ? 'done' : ($i === $cur ? 'current' : '') ?>">
                                    <span class="dot"></span><?= $label === 'Done' ? 'Delivered' : e($label) ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php elseif (($o['cancelled_by'] ?? '') === 'staff'): ?>
                        <div class="cancel-note">This order was cancelled by the shop staff.</div>
                    <?php elseif (($o['cancelled_by'] ?? '') === 'customer'): ?>
                        <div class="cancel-note">You cancelled this order.</div>
                    <?php endif; ?>

                    

                    <div class="order-items"><?= e($o['items_text']) ?></div>
                    <div class="order-meta-row">Location: <?= e($o['location']) ?></div>
                    <div class="order-meta-row">Payment: <?= e($o['payment_method']) ?></div>
                    <?php if ($o['discount_amount'] > 0): ?>
                        <div class="order-meta-row">Subtotal: Php <?= number_format($o['subtotal'], 2) ?></div>
                        <div class="order-meta-row discount-line">Senior/PWD Discount (5%): -Php <?= number_format($o['discount_amount'], 2) ?></div>
                    <?php endif; ?>
                    <div class="order-total">Total: Php <?= number_format($o['total'], 2) ?></div>

                    <?php if ($o['order_status'] === 'Processing'): ?>
                        <button type="button" class="cancel-order-btn" onclick="askCancel(<?= (int)$o['id'] ?>)">Cancel Order</button>
                    <?php elseif (in_array($o['order_status'], ['Ready for Delivery', 'Out for Delivery'], true)): ?>
                        <p class="no-cancel-note">This order is already being delivered, so it can no longer be cancelled.</p>
                    <?php endif; ?>

                    <?php if ($o['order_status'] === 'Done'): ?>
                        <div class="rating-section">
                            <?php if ($o['review_stars']): ?>
                                <!-- Already rated -->
                                <h3>Your rating</h3>
                                <div class="stars-display">
                                    <?= str_repeat('★', (int)$o['review_stars']) . str_repeat('☆', 5 - (int)$o['review_stars']) ?>
                                </div>
                                <?php if ($o['review_comment']): ?>
                                    <p class="review-comment-text">"<?= e($o['review_comment']) ?>"</p>
                                <?php endif; ?>
                            <?php else: ?>
                                <!-- Rate this order -->
                                <h3>Rate this order</h3>
                                <div class="stars" id="stars-<?= $o['id'] ?>">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <button type="button" class="star-btn"
                                                data-order="<?= $o['id'] ?>"
                                                data-star="<?= $i ?>"
                                                onclick="selectStar(<?= $o['id'] ?>, <?= $i ?>)">★</button>
                                    <?php endfor; ?>
                                </div>
                                <textarea id="comment-<?= $o['id'] ?>" placeholder="Share your experience (optional)"></textarea>
                                <button type="button" class="submit-rating"
                                        onclick="submitRating(<?= $o['id'] ?>)">Submit Rating</button>
                                <p class="rating-success" id="rating-ok-<?= $o['id'] ?>">✓ Rating submitted!</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </article>

                <?php else:
                    $r  = $entry['data'];
                    $dt = new DateTime($r['created_at']);
                    $pickup = new DateTime($r['pickup_datetime']);
                ?>
                <article class="order-card" id="res-<?= $r['id'] ?>">
                    <div class="order-card-head">
                        <div>
                            <h2>Reservation #<?= $r['id'] ?></h2>
                            <p class="order-date"><?= $dt->format('M j, Y') ?> &middot; <?= strtolower($dt->format('g:i a')) ?></p>
                        </div>
                        <div class="status-tags">
                            <?= reservation_status_tag($r['status']) ?>
                            <?= payment_tag($r['payment_status']) ?>
                        </div>
                    </div>

                    <?php if ($r['status'] !== 'Cancelled'):
                        $rsteps = ['Processing', 'Ready for Pickup', 'Done'];
                        $rcur   = ['Active' => 0, 'Ready for Pickup' => 1, 'Complete' => 2][$r['status']] ?? 0;
                    ?>
                        <ol class="tracker" aria-label="Reservation progress">
                            <?php foreach ($rsteps as $i => $label): ?>
                                <li class="<?= $i < $rcur || $rcur === 2 ? 'done' : ($i === $rcur ? 'current' : '') ?>">
                                    <span class="dot"></span><?= e($label) ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                        <?php if ($r['status'] === 'Ready for Pickup'): ?>
                            <div class="ready-note">
                                 <strong>Your reservation is ready for pickup!</strong>
                                Please visit the shop at your pickup time and tell the staff your
                                <strong>Reservation #<?= (int)$r['id'] ?></strong> and your <strong>name</strong>.
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="cancel-note">This reservation was cancelled by the shop.</div>
                    <?php endif; ?>

                    <div class="order-items"><?= e($r['product_name']) ?> &times;<?= (int)$r['quantity'] ?></div>
                    <div class="order-meta-row">Pickup: <?= $pickup->format('M j, Y') ?> at <?= strtolower($pickup->format('g:i a')) ?></div>
                    <div class="order-meta-row">Payment: <?= e($r['payment_method']) ?></div>
                    <?php if ($r['discount_amount'] > 0): ?>
                        <div class="order-meta-row">Subtotal: Php <?= number_format($r['subtotal'], 2) ?></div>
                        <div class="order-meta-row discount-line">Senior/PWD Discount (5%): -Php <?= number_format($r['discount_amount'], 2) ?></div>
                    <?php endif; ?>
                    <div class="order-total">Total: Php <?= number_format($r['total_price'], 2) ?></div>

                    <?php if ($r['status'] === 'Complete'): ?>
                        <div class="rating-section">
                            <?php if ($r['review_stars']): ?>
                                <!-- Already rated -->
                                <h3>Your rating</h3>
                                <div class="stars-display">
                                    <?= str_repeat('★', (int)$r['review_stars']) . str_repeat('☆', 5 - (int)$r['review_stars']) ?>
                                </div>
                                <?php if ($r['review_comment']): ?>
                                    <p class="review-comment-text">"<?= e($r['review_comment']) ?>"</p>
                                <?php endif; ?>
                            <?php else: ?>
                                <!-- Rate this reservation -->
                                <h3>Rate this reservation</h3>
                                <div class="stars" id="stars-r<?= $r['id'] ?>">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <button type="button" class="star-btn"
                                                data-star="<?= $i ?>"
                                                onclick="selectStar('r<?= $r['id'] ?>', <?= $i ?>)">★</button>
                                    <?php endfor; ?>
                                </div>
                                <textarea id="comment-r<?= $r['id'] ?>" placeholder="Share your experience (optional)"></textarea>
                                <button type="button" class="submit-rating"
                                        onclick="submitRating('r<?= $r['id'] ?>')">Submit Rating</button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </article>
                <?php endif; ?>

            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<!-- Message / confirmation modal -->
<div class="lf-modal" id="lfModal">
    <div class="lf-modal-box" id="lfModalBox" role="dialog" aria-modal="true" aria-labelledby="lfModalTitle">
        <h3 id="lfModalTitle"></h3>
        <p id="lfModalMsg"></p>
        <div class="lf-modal-btns">
            <button type="button" class="lf-no" id="lfNo"></button>
            <button type="button" class="lf-yes" id="lfYes"></button>
        </div>
    </div>
</div>

<script>
const selectedStars = {};

// showModal({title, message, confirmText, cancelText, tone}) → Promise<boolean>
function showModal({ title, message, confirmText = 'OK', cancelText = null, tone = 'info' }) {
    return new Promise(resolve => {
        const ov = document.getElementById('lfModal');
        const yes = document.getElementById('lfYes'), no = document.getElementById('lfNo');
        document.getElementById('lfModalTitle').textContent = title;
        document.getElementById('lfModalMsg').textContent   = message;
        document.getElementById('lfModalBox').className     = 'lf-modal-box' + (tone === 'danger' ? ' danger' : '');
        yes.textContent = confirmText;
        no.textContent  = cancelText || '';
        no.style.display = cancelText ? '' : 'none';
        ov.classList.add('open');
        function done(v) {
            ov.classList.remove('open');
            yes.onclick = no.onclick = null;
            document.removeEventListener('keydown', onKey);
            resolve(v);
        }
        function onKey(ev) { if (ev.key === 'Escape') done(false); }
        document.addEventListener('keydown', onKey);
        yes.onclick = () => done(true);
        no.onclick  = () => done(false);
        yes.focus();
    });
}

// Customer cancels an order (only offered while it is still Processing)
async function askCancel(id) {
    const ok = await showModal({
        title: 'Cancel order #' + id + '?',
        message: 'Are you sure you want to cancel this order?\nThis cannot be undone.',
        confirmText: 'Yes, cancel order',
        cancelText:  'Keep order',
        tone: 'danger'
    });
    if (!ok) return;

    fetch('customer-orders.php', { method: 'POST', body: new URLSearchParams({ action: 'cancel_order', order_id: id }) })
        .then(r => r.json())
        .then(async data => {
            if (data.ok) {
                await showModal({ title: 'Order cancelled', message: 'Order #' + id + ' has been cancelled.' });
            } else {
                await showModal({ title: 'Could not cancel', message: data.error || 'Please try again.', tone: 'danger' });
            }
            location.reload();   // show the order's real, current status
        })
        .catch(() => showModal({ title: 'Could not cancel', message: 'Request failed. Please try again.', tone: 'danger' }));
}

function selectStar(orderId, star) {
    selectedStars[orderId] = star;
    const btns = document.querySelectorAll('#stars-' + orderId + ' .star-btn');
    btns.forEach(btn => {
        btn.classList.toggle('active', parseInt(btn.dataset.star) <= star);
    });
}

function submitRating(key) {
    const stars   = selectedStars[key];
    const comment = document.getElementById('comment-' + key)?.value.trim() || '';

    if (!stars || stars < 1) {
        showModal({ title: 'Select a rating', message: 'Please select a star rating.', tone: 'danger' });
        return;
    }

    // Orders use a plain number (12); reservations use 'r12'
    const isRes = String(key).charAt(0) === 'r';
    const refId = isRes ? String(key).slice(1) : String(key);

    const body = new URLSearchParams({
        action:  'submit_rating',
        stars:   stars,
        comment: comment,
    });
    body.set(isRes ? 'reservation_id' : 'order_id', refId);

    fetch('customer-orders.php', { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                // Show success, replace the rating form
                const section = document.querySelector((isRes ? '#res-' : '#order-') + refId + ' .rating-section');
                section.innerHTML = '<h3>Your rating</h3><div class="stars-display"></div>';
                section.querySelector('.stars-display').textContent = '★'.repeat(stars) + '☆'.repeat(5 - stars);
                if (comment) {
                    const p = document.createElement('p');
                    p.className = 'review-comment-text';
                    p.textContent = '"' + comment + '"';      // textContent: comment can't inject HTML
                    section.appendChild(p);
                }
            } else {
                showModal({ title: 'Could not submit', message: data.error || 'Unknown error', tone: 'danger' });
            }
        })
        .catch(() => showModal({ title: 'Could not submit', message: 'Request failed. Please try again.', tone: 'danger' }));
}
</script>
</body>
</html>