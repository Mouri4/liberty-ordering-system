<?php
// ── Liberty Foodmart — My Reviews ───────────────────────────
require_once 'session.php';
require 'db.php';

if (empty($_SESSION['customer_id'])) {
    header('Location: customer-login.php?redirect=customer-reviews.php');
    exit;
}

$cust_id       = (int)$_SESSION['customer_id'];
$cust_name     = $_SESSION['customer_name'] ?? '';
$is_logged_in  = true;

// ── Query all reviews by this customer ───────────────────────
// Reviews can belong to an ORDER or to a RESERVATION, so both are
// selected and combined into one list (newest first).
$reviews = $pdo->prepare("
    (SELECT
        rv.id, rv.stars, rv.comment, rv.rated_at,
        'Order'  AS kind,
        o.id     AS ref_id,
        GROUP_CONCAT(oi.product_name ORDER BY oi.id SEPARATOR ', ') AS products,
        (SELECT oi2.product_name FROM order_items oi2
          WHERE oi2.order_id = o.id ORDER BY oi2.id LIMIT 1)        AS first_product,
        COUNT(oi.id) - 1                                            AS extra_count
     FROM reviews rv
     JOIN orders o       ON o.id = rv.order_id
     JOIN order_items oi ON oi.order_id = o.id
     WHERE rv.customer_id = ?
     GROUP BY rv.id)
    UNION ALL
    (SELECT
        rv.id, rv.stars, rv.comment, rv.rated_at,
        'Reservation' AS kind,
        res.id        AS ref_id,
        COALESCE(GROUP_CONCAT(ri.product_name ORDER BY ri.id SEPARATOR ', '), res.product_name) AS products,
        COALESCE((SELECT ri2.product_name FROM reservation_items ri2
                   WHERE ri2.reservation_id = res.id ORDER BY ri2.id LIMIT 1), res.product_name) AS first_product,
        GREATEST(COUNT(ri.id) - 1, 0) AS extra_count
     FROM reviews rv
     JOIN reservations res          ON res.id = rv.reservation_id
     LEFT JOIN reservation_items ri ON ri.reservation_id = res.id
     WHERE rv.customer_id = ?
     GROUP BY rv.id, res.id, res.product_name)
    ORDER BY rated_at DESC
");
$reviews->execute([$cust_id, $cust_id]);
$reviews = $reviews->fetchAll();

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function product_summary(string $first, int $extras): string
{
    return $extras > 0 ? $first . ' (+' . $extras . ' more)' : $first;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reviews — Liberty Foodmart</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,600&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --cream:#FBF3E7; --cream-deep:#F1E4D3; --tan:#E8D5B7; --dark-brown:#3B2417; --brown:#8B4513;
                --text:#3B2417; --muted:#A98A6D; --white:#fff; --shadow:rgba(59,36,23,.10); }
        * { box-sizing:border-box; margin:0; padding:0; }
        html, body { overflow-x:hidden; max-width:100%; }
        body { font-family:'Inter',Arial,sans-serif; background:var(--cream); color:var(--text); min-height:100vh; }

        .page { max-width:56rem; margin:0 auto; padding:1.8rem 5% 3rem; }
        .page h1 { font-family:'Playfair Display',serif; font-size:1.7rem; color:var(--dark-brown); margin-bottom:.2rem; }
        .page .intro { font-size:.88rem; color:var(--muted); margin-bottom:1.2rem; }

        .reviews-list { display:flex; flex-direction:column; gap:.9rem; }
        .review-card { background:var(--white); border:1px solid var(--tan); border-radius:.9rem; padding:1.15rem 1.35rem; box-shadow:0 2px 10px var(--shadow); }
        .review-card-head { display:flex; justify-content:space-between; align-items:flex-start; gap:.8rem; margin-bottom:.4rem; flex-wrap:wrap; }
        .review-card-head h2 { font-size:1.02rem; font-weight:700; color:var(--dark-brown); overflow-wrap:anywhere; }
        .review-date { font-size:.74rem; color:var(--muted); white-space:nowrap; }
        .order-id-tag { font-size:.74rem; color:var(--muted); margin-top:.15rem; }
        .stars-display { color:#f5a623; font-size:1.3rem; letter-spacing:.1rem; margin:.4rem 0; }
        .review-products { font-size:.82rem; margin-bottom:.4rem; color:#6b5a4c; overflow-wrap:anywhere; }
        .review-comment { font-size:.86rem; color:#6b5a4c; line-height:1.55; font-style:italic; overflow-wrap:anywhere; }

        .empty-state { text-align:center; padding:3rem 1.5rem; background:var(--white); border:1px dashed var(--tan); border-radius:.9rem; font-size:.92rem; color:var(--muted); line-height:1.7; }
        .empty-state a { color:var(--brown); font-weight:700; }

        @media (max-width:640px) {
            .page { padding:1.1rem 4% 2rem; }
            .page h1 { font-size:1.45rem; }
            .page .intro { font-size:.82rem; margin-bottom:.9rem; }
            .reviews-list { gap:.7rem; }
            .review-card { padding:1rem; border-radius:.8rem; }
            .review-card-head h2 { font-size:.98rem; }
            .stars-display { font-size:1.2rem; }
            .empty-state { padding:2.2rem 1.1rem; font-size:.88rem; }
        }
    </style>
</head>

<body>
<?php $nav_active = 'reviews'; $nav_cart = 'link'; require 'customer-nav.php'; ?>

    <main class="page">
        <h1>My Reviews</h1>
        <p class="intro">Reviews you submitted for completed orders and reservations.</p>

        <div class="reviews-list">
            <?php if (empty($reviews)): ?>
                <div class="empty-state">
                    You have not submitted any reviews yet.
                    Rate a completed order from <a href="customer-orders.php?tab=done">My Orders</a>.
                </div>
            <?php else: ?>
                <?php foreach ($reviews as $r):
                    $rated  = new DateTime($r['rated_at']);
                    $summary = product_summary($r['first_product'], (int)$r['extra_count']);
                ?>
                    <article class="review-card">
                        <div class="review-card-head">
                            <div>
                                <h2><?= e($summary) ?></h2>
                                <p class="order-id-tag"><?= e($r['kind']) ?> #<?= (int)$r['ref_id'] ?></p>
                            </div>
                            <span class="review-date"><?= $rated->format('M j, Y') ?></span>
                        </div>
                        <div class="stars-display">
                            <?= str_repeat('★', (int)$r['stars']) . str_repeat('☆', 5 - (int)$r['stars']) ?>
                        </div>
                        <p class="review-products"><?= e($r['products']) ?></p>
                        <?php if ($r['comment']): ?>
                            <p class="review-comment">"<?= e($r['comment']) ?>"</p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

</body>

</html>