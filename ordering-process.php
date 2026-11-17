<?php
// ── Liberty Foodmart — Product Detail Page ───────────────────
require_once 'session.php';
require 'db.php';

// Get product id from URL
$product_id = (int)($_GET['id'] ?? 0);
$product    = null;

if ($product_id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();
}

// Fallback: build from URL params if product not found in DB
// (handles the case where ordering-page passes params directly)
if (!$product && isset($_GET['name'])) {
    $product = [
        'id'          => 0,
        'name'        => $_GET['name']        ?? 'Product',
        'price'       => (float)($_GET['price'] ?? 0),
        'description' => $_GET['desc']        ?? '',
        'category'    => $_GET['category']    ?? 'Cake',
        'image_path'  => $_GET['image']       ?? '',
        'quantity'    => (int)($_GET['stock']  ?? 99),
        'low_stock_threshold' => 10,
    ];
}

if (!$product) {
    header('Location: ordering-page.php');
    exit;
}

// Load other products for the "Other available" section
$others = $pdo->query("SELECT * FROM products ORDER BY category, name")->fetchAll();
$others_by_cat = [];
foreach ($others as $o) {
    $cat = strtolower($o['category']);
    if ($cat === 'cake')   $others_by_cat['cakes'][]  = $o;
    if ($cat === 'pastry') $others_by_cat['pastry'][] = $o;
    if (in_array($cat, ['bread','drink','other'])) $others_by_cat['bread'][] = $o;
}

// ── Ratings & reviews ────────────────────────────────────────
// Reviews are saved per ORDER or per RESERVATION (one each, only after it's
// completed). So a product's reviews = the reviews left on orders or
// reservations that contained that product.

// Average + count for EVERY product in one query. Used by the main
// rating row and by the "Other available" cards. DISTINCT makes sure
// an order that lists the same product twice still counts once.
$rating_map = [];
$rating_rows = $pdo->query("
    SELECT x.product_id, COUNT(*) AS cnt, AVG(x.stars) AS avg_stars
      FROM (SELECT DISTINCT oi.product_id, r.id AS review_id, r.stars
              FROM reviews r
              JOIN order_items oi ON oi.order_id = r.order_id
             WHERE oi.product_id IS NOT NULL
            UNION
            SELECT DISTINCT ri.product_id, r.id AS review_id, r.stars
              FROM reviews r
              JOIN reservation_items ri ON ri.reservation_id = r.reservation_id
             WHERE ri.product_id IS NOT NULL) x
     GROUP BY x.product_id
")->fetchAll();
foreach ($rating_rows as $row) {
    $rating_map[(int)$row['product_id']] = [
        'avg'   => (float)$row['avg_stars'],
        'count' => (int)$row['cnt'],
    ];
}

$product_rating  = $rating_map[(int)$product['id']] ?? ['avg' => 0.0, 'count' => 0];
$product_reviews = [];
$REVIEW_LOAD_MAX = 200;   // safety cap so a very popular product can't make the page huge

if ((int)$product['id'] > 0) {
    $rv_stmt = $pdo->prepare("
        SELECT r.id, r.stars, r.comment, r.rated_at, r.customer_id, c.name AS customer_name
          FROM reviews r
          LEFT JOIN customers c ON c.id = r.customer_id
         WHERE EXISTS (SELECT 1 FROM order_items oi
                        WHERE oi.order_id = r.order_id AND oi.product_id = ?)
            OR EXISTS (SELECT 1 FROM reservation_items ri
                        WHERE ri.reservation_id = r.reservation_id AND ri.product_id = ?)
         ORDER BY r.rated_at DESC, r.id DESC
         LIMIT " . (int)$REVIEW_LOAD_MAX);
    $rv_stmt->execute([(int)$product['id'], (int)$product['id']]);
    $product_reviews = $rv_stmt->fetchAll();
}

$cust_name     = $_SESSION['customer_name']     ?? '';
$cust_phone    = $_SESSION['customer_phone']    ?? '';
$cust_location = $_SESSION['customer_location'] ?? '';
$is_logged_in  = !empty($_SESSION['customer_id']);
$cust_id       = $_SESSION['customer_id']       ?? null;

$img = ($product['image_path'] && file_exists($product['image_path']))
       ? $product['image_path']
       : 'src/black-forestv2.webp';

$isOut = (int)$product['quantity'] <= 0;   // zero stock: shown greyed out, can't be added

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/** "Juan Dela Cruz" -> "Juan C."  (other customers' reviews show a shortened name) */
function review_display_name(?string $name): string {
    $name = trim((string)$name);
    if ($name === '') return 'Customer';
    $parts = preg_split('/\s+/u', $name);
    if (count($parts) === 1) return $parts[0];
    $last = end($parts);
    return $parts[0] . ' ' . (preg_match('/^./u', $last, $m) ? upper_first_char($m[0]) : '') . '.';
}
function upper_first_char(string $ch): string { return function_exists('mb_strtoupper') ? mb_strtoupper($ch, 'UTF-8') : strtoupper($ch); }
function review_initial(string $display): string {
    return preg_match('/^./u', $display, $m) ? upper_first_char($m[0]) : '?';
}
/** Width (%) of the gold overlay so partial ratings show partial stars, e.g. 4.3 -> 86% */
function star_fill_pct(float $avg): float { return max(0, min(100, round($avg / 5 * 100, 1))); }
function review_count_label(int $n): string { return $n . ' review' . ($n === 1 ? '' : 's'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($product['name']) ?> — PastryLiberty</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,600&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{
            --choc-dark:#3B2417; --choc:#5C3A24; --cream:#FBF3E7; --cream-deep:#F1E4D3;
            --caramel:#C17817; --caramel-deep:#8B4513; --gold:#D4A24C; --muted:#A98A6D;
            --surface:#FFFFFF; --border:#E8D5B7; --heart:#B23A48;
            --shadow:rgba(59,36,23,.10);
        }
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',Arial,sans-serif;outline:none;border:none;text-decoration:none;color:inherit;}
        html{scroll-behavior:smooth;}
        html,body{overflow-x:hidden;max-width:100%;}
        body{background:var(--cream);}
        h1,h2{font-family:'Playfair Display',serif;}

        @keyframes fadeUp{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);}}
        @keyframes pop{0%{transform:scale(1);}40%{transform:scale(1.18);}100%{transform:scale(1);}}

        /* CART DRAWER */
        .cart-overlay{position:fixed;inset:0;background:rgba(30,15,5,.5);z-index:1400;opacity:0;pointer-events:none;transition:opacity .3s;}
        .cart-overlay.open{opacity:1;pointer-events:all;}
        .cart-drawer{position:fixed;top:0;right:0;bottom:0;width:23rem;max-width:92vw;background:var(--surface);z-index:1500;display:flex;flex-direction:column;transform:translateX(100%);transition:transform .35s cubic-bezier(.4,0,.2,1);box-shadow:-8px 0 32px rgba(59,36,23,.2);}
        .cart-drawer.open{transform:translateX(0);}
        .cart-header{ display:flex;align-items:center;justify-content:space-between;padding:1.2rem 1.4rem;border-bottom:1px solid var(--border);}
        .cart-header-left{display:flex;align-items:center;gap:.7rem;}
        .cart-title{font-family:'Playfair Display',serif;font-size:1.15rem;font-weight:700;color:var(--choc-dark);}
        .cart-count-badge{background:var(--cream-deep);color:var(--caramel-deep);font-size:.68rem;font-weight:700;padding:.25rem .7rem;border-radius:2rem;}
        .cart-close-btn{width:2.1rem;height:2.1rem;background:var(--cream);border-radius:.5rem;display:flex;align-items:center;justify-content:center;cursor:pointer;}
        .cart-close-btn:hover{background:var(--cream-deep);}
        .cart-close-btn svg{width:1.1rem;height:1.1rem;fill:none;stroke:var(--choc-dark);stroke-width:2.5;stroke-linecap:round;}
        .cart-items{flex:1;overflow-y:auto;padding:1rem 1.2rem;display:flex;flex-direction:column;gap:.8rem;}
        .cart-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.8rem;color:var(--muted);padding:3rem 1.4rem;}
        .cart-empty svg{width:3.6rem;height:3.6rem;}
        .cart-empty p{font-size:.85rem;}
        .cart-item{display:flex;align-items:center;background:var(--cream);border-radius:.8rem;padding:.7rem;gap:.8rem;border:1px solid var(--border);animation:fadeUp .25s ease;}
        .cart-item-img{width:3.6rem;height:3.6rem;border-radius:.6rem;flex-shrink:0;overflow:hidden;background:var(--cream-deep);}
        .cart-item-img img{width:100%;height:100%;object-fit:cover;}
        .cart-item-info{flex:1;min-width:0;}
        .cart-item-name{font-size:.82rem;font-weight:700;color:var(--choc-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .cart-item-price{font-size:.74rem;color:var(--muted);margin-top:.1rem;}
        .cart-item-qty{display:flex;align-items:center;gap:.5rem;margin-top:.4rem;}
        .qty-btn{width:1.6rem;height:1.6rem;border-radius:50%;background:var(--choc-dark);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.95rem;font-weight:700;flex-shrink:0;user-select:none;transition:background .15s;}
        .qty-btn:hover{background:var(--caramel-deep);}
        .qty-num{font-size:.8rem;font-weight:700;color:var(--choc-dark);min-width:1.2rem;text-align:center;}
        .qty-input{width:2.8rem;height:1.6rem;padding:0;text-align:center;font-size:.8rem;font-weight:700;color:var(--choc-dark);background:var(--surface);border:1.5px solid var(--border);border-radius:.4rem;-moz-appearance:textfield;appearance:textfield;}
        .qty-input::-webkit-outer-spin-button,.qty-input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
        .qty-input:focus{border-color:var(--caramel);}
        .cart-item-delete{width:1.8rem;height:1.8rem;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;}
        .cart-item-delete svg{width:1.1rem;height:1.1rem;fill:none;stroke:var(--muted);stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;}
        .cart-item-delete:hover svg{stroke:var(--heart);}
        .cart-footer{border-top:1px solid var(--border);padding:1.1rem 1.4rem 1.3rem;background:var(--surface);display:flex;flex-direction:column;gap:.7rem;}
        .cart-totals{display:flex;flex-direction:column;gap:.3rem;margin-bottom:.2rem;}
        .cart-total-row{display:flex;justify-content:space-between;align-items:center;}
        .cart-total-row .label{font-size:.78rem;color:var(--muted);}
        .cart-total-row .label.bold{font-size:.9rem;font-weight:700;color:var(--choc-dark);}
        .cart-total-row .val{font-size:.78rem;color:var(--choc-dark);font-weight:600;}
        .cart-total-row .val.bold{font-size:1.05rem;font-weight:800;color:var(--caramel-deep);}
        .btn-checkout{width:100%;padding:.85rem;background:linear-gradient(135deg,var(--caramel),var(--caramel-deep));color:#fff;font-size:.88rem;font-weight:700;border-radius:.7rem;cursor:pointer;text-align:center;box-shadow:0 6px 16px rgba(139,69,19,.3);transition:filter .15s,transform .15s;}
        .btn-checkout:hover{filter:brightness(1.05);transform:translateY(-1px);}
        .btn-clear{width:100%;padding:.7rem;background:transparent;color:var(--choc);font-size:.78rem;font-weight:700;border-radius:.7rem;cursor:pointer;text-align:center;border:1.5px solid var(--border);}
        .btn-clear:hover{background:var(--cream);}

        /* CHECKOUT MODAL */
        .checkout-overlay{position:fixed;inset:0;background:rgba(30,15,5,.55);z-index:2500;display:none;}
        .proceed-checkout{max-height:92vh;width:27rem;max-width:92vw;position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);border-radius:1.1rem;background:var(--surface);padding:1.4rem;display:none;flex-direction:column;align-items:center;z-index:3000;overflow-y:auto;border-top:4px solid var(--gold);box-shadow:0 24px 60px rgba(30,15,5,.35);}
        .checkout-mode-tabs{display:flex;width:100%;gap:.6rem;margin-bottom:1.2rem;}
        .checkout-tab{flex:1;padding:.7rem;border-radius:.7rem;font-size:.82rem;font-weight:700;cursor:pointer;text-align:center;background:var(--cream);color:var(--choc);border:1.5px solid var(--border);transition:.15s;}
        .checkout-tab.active{background:var(--choc-dark);color:#fff;border-color:var(--choc-dark);}
        .checkout-panel-title{font-family:'Playfair Display',serif;font-size:1.1rem;font-weight:700;color:var(--choc-dark);margin-bottom:.8rem;align-self:flex-start;}
        .back-btn{font-size:1.3rem;cursor:pointer;align-self:flex-start;color:var(--choc);margin-bottom:.4rem;}
        .checkout-field{width:100%;margin-top:.85rem;}
        .checkout-field p{font-size:.78rem;font-weight:700;color:var(--choc);margin-bottom:.3rem;}
        .checkout-field input,.checkout-field select{padding:.65rem .8rem;width:100%;background:var(--cream);border:1.5px solid var(--border);border-radius:.6rem;font-size:.84rem;font-family:'Inter',sans-serif;color:var(--choc-dark);}
        .checkout-field input:focus,.checkout-field select:focus{border-color:var(--caramel);}
        .checkout-field p.checkout-hint{font-size:.7rem;font-weight:500;color:var(--muted);line-height:1.45;margin:.3rem 0 0;}
        .checkout-total-box{width:100%;border-radius:.8rem;background:var(--cream-deep);color:var(--choc-dark);padding:.9rem 1rem;display:flex;margin-top:1.1rem;}
        .checkout-total-box p{font-size:1rem;font-weight:800;color:var(--caramel-deep);}
        .place-order-btn{margin-top:1.1rem;width:100%;padding:.9rem;background:linear-gradient(135deg,var(--caramel),var(--caramel-deep));color:#fff;font-size:.9rem;font-weight:700;border-radius:.8rem;cursor:pointer;text-align:center;box-shadow:0 6px 16px rgba(139,69,19,.3);transition:filter .15s;}
        .place-order-btn:hover{filter:brightness(1.05);}
        .place-order-btn.reserve-mode{background:linear-gradient(135deg,var(--gold),var(--caramel-deep));}
.place-order-btn.disabled{background:#b9a898;box-shadow:none;cursor:not-allowed;filter:none;}
.place-order-btn.disabled:hover{filter:none;}

/* GCash preview (design only, not functional yet) */
.gcash-panel{width:100%;margin-top:.85rem;background:#f3f8ff;border:1.5px solid #cfe0ff;border-radius:.8rem;padding:.9rem;text-align:center;}
.gcash-panel[hidden]{display:none;}
.gcash-head{display:flex;align-items:center;gap:.6rem;text-align:left;}
.gcash-logo{width:2rem;height:2rem;border-radius:50%;background:#0057d9;color:#fff;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1rem;}
.gcash-head strong{display:block;font-size:.84rem;color:#0b3d91;}
.gcash-head small{display:block;font-size:.7rem;color:#5b6f8f;line-height:1.3;}
.gcash-soon{margin-left:auto;background:#fff3cd;color:#8a6100;font-size:.62rem;font-weight:800;padding:.2rem .55rem;border-radius:1rem;white-space:nowrap;}
.gcash-qr{width:11.5rem;max-width:70%;margin:.8rem auto .4rem;background:#fff;padding:.5rem;border-radius:.7rem;border:1px solid #cfe0ff;box-shadow:0 4px 14px rgba(0,87,217,.12);}
.gcash-qr svg{display:block;width:100%;height:auto;}
.gcash-name{font-size:.8rem;font-weight:700;color:#0b3d91;}
.gcash-note{font-size:.7rem;color:#6b5a4c;line-height:1.5;margin-top:.4rem;}
        .pickup-field{width:100%;margin-top:.85rem;display:none;}
        .pickup-field.show{display:block;}
        .pickup-field p{font-size:.78rem;font-weight:700;color:var(--choc);margin-bottom:.3rem;}
        .pickup-field input{width:100%;padding:.65rem .8rem;border:1.5px solid var(--border);border-radius:.6rem;font-size:.84rem;background:var(--cream);}

        /* PRODUCT DETAIL LAYOUT */
        .order-page{max-width:76rem;margin:0 auto;padding:1.4rem 5% 2rem;display:flex;flex-direction:column;gap:2rem;}
        .breadcrumb{font-size:.78rem;color:var(--muted);}
        .breadcrumb a{color:var(--caramel-deep);font-weight:600;cursor:pointer;}
        .breadcrumb a:hover{text-decoration:underline;}

        .order-detail{display:flex;gap:2rem;align-items:flex-start;animation:fadeUp .5s ease;}
        .order-detail-img{width:20rem;height:20rem;object-fit:cover;border-radius:1.2rem;box-shadow:0 12px 34px var(--shadow);flex-shrink:0;border:1px solid var(--border);}
        .order-info{flex:1;display:flex;flex-direction:column;gap:.9rem;}
        .order-meta{display:flex;justify-content:space-between;align-items:center;}
        .order-category{font-size:.78rem;color:var(--caramel-deep);font-weight:700;text-transform:uppercase;letter-spacing:.04em;background:var(--cream-deep);padding:.3rem .8rem;border-radius:1rem;}
        .order-price{font-size:1.5rem;font-weight:800;color:var(--caramel-deep);}
        .order-title{font-size:1.7rem;font-weight:700;color:var(--choc-dark);line-height:1.2;}
        .order-desc-text{font-size:.86rem;color:var(--muted);line-height:1.65;}
        .order-rating{display:flex;align-items:center;gap:.6rem;font-size:.82rem;color:var(--choc);}
        .stars{color:var(--gold);font-size:1.05rem;}
        .see-reviews{color:var(--caramel-deep);font-weight:700;cursor:pointer;}
        .see-reviews:hover{text-decoration:underline;}
        .stock-info{font-size:.8rem;color:#2e7d32;font-weight:600;}
        .stock-info.low{color:#c0392b;font-weight:700;}

        .order-actions{display:flex;align-items:center;gap:1.2rem;margin-top:.4rem;flex-wrap:wrap;}
        .order-heart-btn{width:2.5rem;height:2.5rem;border-radius:50%;background:var(--cream);border:1.5px solid var(--border);display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;transition:.15s;}
        .order-heart-btn:hover{border-color:var(--heart);}
        .order-heart-btn svg{width:1.2rem;height:1.2rem;fill:none;stroke:var(--muted);stroke-width:2;}
        .order-heart-btn.liked svg{fill:var(--heart);stroke:var(--heart);animation:pop .3s ease;}
        .order-actions-label{font-size:.82rem;font-weight:700;color:var(--choc);}
        .product-qty-controls{display:flex;align-items:center;gap:.7rem;background:var(--cream);border-radius:2rem;padding:.35rem .9rem;border:1.5px solid var(--border);}
        .product-qty-controls .qty-btn{width:1.9rem;height:1.9rem;font-size:1.1rem;}
        .product-qty-num{font-size:.9rem;font-weight:800;color:var(--choc-dark);min-width:1.4rem;text-align:center;}
        .btn-add-cart{padding:.7rem 1.5rem;background:linear-gradient(135deg,var(--caramel),var(--caramel-deep));color:#fff;font-size:.86rem;font-weight:700;border-radius:.8rem;cursor:pointer;box-shadow:0 6px 16px rgba(139,69,19,.3);transition:transform .15s,filter .15s;}
        .btn-add-cart:hover{transform:translateY(-1px);filter:brightness(1.05);}
        .btn-add-cart.just-added{background:#3E8E52;}

        /* Highlights (replaces the old fake "20%/50% off" tags) */
        .order-section-title{font-size:1.15rem;font-weight:700;color:var(--choc-dark);margin-bottom:.8rem;}
        .highlight-row{display:flex;gap:1rem;flex-wrap:wrap;}
        .highlight-card{display:flex;align-items:center;gap:.7rem;background:var(--surface);border:1px solid var(--border);border-radius:.9rem;padding:.9rem 1.2rem;box-shadow:0 3px 12px var(--shadow);flex:1;min-width:14rem;}
        .highlight-card .ic{font-size:1.4rem;}
        .highlight-card p{font-size:.8rem;font-weight:700;color:var(--choc-dark);}
        .highlight-card span{font-size:.72rem;color:var(--muted);display:block;margin-top:.1rem;font-weight:400;}

        /* OTHER AVAILABLE */
        .other-controls{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.3rem;flex-wrap:wrap;gap:1rem;}
        .other-tabs{display:flex;align-items:center;gap:.4rem;background:var(--cream-deep);border-radius:2rem;padding:.3rem;}
        .other-tab{font-size:.8rem;font-weight:700;color:var(--choc);cursor:pointer;padding:.5rem 1.1rem;border-radius:1.6rem;transition:.15s;}
        .other-tab.active{background:var(--choc-dark);color:#fff;}
        .other-search{display:flex;align-items:center;background:var(--surface);border-radius:.8rem;border:1.5px solid var(--border);padding:.6rem 1rem;gap:.5rem;box-shadow:0 2px 8px var(--shadow);}
        .other-search input{font-size:.82rem;color:var(--choc-dark);background:transparent;width:14rem;}
        .other-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(9.5rem,1fr));gap:.9rem;}
        .other-card{display:flex;flex-direction:column;align-items:center;background:var(--surface);border-radius:.9rem;padding:.9rem;cursor:pointer;border:1px solid var(--border);transition:transform .18s,box-shadow .18s;}
        .other-card:hover{transform:translateY(-3px);box-shadow:0 8px 22px var(--shadow);}
        .other-card-img{width:100%;aspect-ratio:1;border-radius:.6rem;object-fit:cover;margin-bottom:.6rem;background:var(--cream-deep);}
        .other-card-info{text-align:center;}
        .other-card-name{font-size:.8rem;font-weight:700;color:var(--choc-dark);line-height:1.3;}
        .other-card-rating{font-size:.76rem;color:var(--muted);margin-top:.2rem;}
        .star{color:var(--gold);}
        .other-card-add{width:100%;margin-top:.7rem;padding:.5rem;border-radius:.5rem;background:var(--choc-dark);color:#fff;font-size:.76rem;font-weight:700;cursor:pointer;transition:background .2s;}
        .other-card-add:hover{background:var(--caramel-deep);}
        .other-card-add.just-added{background:#3E8E52;}

        /* ZERO STOCK */
        .order-detail-img.oos{filter:grayscale(1);opacity:.75;}
        .product-qty-controls.disabled{opacity:.45;pointer-events:none;}
        .btn-add-cart.disabled{background:#8d8d8d;box-shadow:none;cursor:not-allowed;}
        .btn-add-cart.disabled:hover{transform:none;filter:none;}
        .other-card.out-of-stock{filter:grayscale(1);opacity:.7;}
        .other-card.out-of-stock:hover{transform:none;box-shadow:none;}
        .other-card-oos{font-size:.72rem;font-weight:800;color:#c0392b;margin-top:.2rem;text-transform:uppercase;letter-spacing:.03em;}
        .other-card-add:disabled{background:#8d8d8d;cursor:not-allowed;}

        /* DESCRIPTION & INGREDIENTS */
        .order-desc-block{background:var(--surface);border:1px solid var(--border);border-radius:.9rem;padding:1.1rem 1.3rem;box-shadow:0 3px 12px var(--shadow);}
        .order-desc-block .order-section-title{margin-bottom:.5rem;}
        .order-desc-block .order-desc-text{font-size:.9rem;color:var(--choc);line-height:1.7;overflow-wrap:anywhere;}

        /* RATINGS + REVIEWS */
        .stars-wrap{position:relative;display:inline-block;line-height:1;white-space:nowrap;width:max-content;align-self:flex-start;}
        .stars-base{color:#E3D6C6;}
        .stars-fill{position:absolute;left:0;top:0;overflow:hidden;white-space:nowrap;color:var(--gold);}
        .star.dim{color:#D8CBB9;}

        .order-reviews{margin-top:1rem;scroll-margin-top:5rem;}
        .rv-summary{display:flex;align-items:center;gap:1rem;background:var(--surface);border:1px solid var(--border);border-radius:.9rem;padding:.9rem 1.3rem;box-shadow:0 3px 12px var(--shadow);margin-bottom:1.1rem;width:fit-content;max-width:100%;}
        .rv-summary-score{font-size:1.7rem;font-weight:800;color:var(--caramel-deep);line-height:1;}
        .rv-summary-score small{font-size:.85rem;font-weight:600;color:var(--muted);}
        .rv-summary-meta{display:flex;flex-direction:column;gap:.25rem;font-size:.78rem;color:var(--muted);}
        .rv-summary-meta .stars{font-size:1.1rem;}
        .rv-list{display:flex;flex-direction:column;gap:.9rem;}
        .rv-card{display:flex;gap:.9rem;background:var(--surface);border:1px solid var(--border);border-radius:.9rem;padding:1rem 1.2rem;box-shadow:0 3px 12px var(--shadow);}
        .rv-avatar{width:2.5rem;height:2.5rem;border-radius:50%;background:var(--cream-deep);color:var(--caramel-deep);font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
        .rv-body{flex:1;min-width:0;}
        .rv-top{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;}
        .rv-name{font-size:.86rem;font-weight:700;color:var(--choc-dark);}
        .rv-badge{font-size:.66rem;font-weight:700;color:#2e7d32;background:#e8f5e9;border-radius:1rem;padding:.12rem .55rem;}
        .rv-badge.you{color:var(--caramel-deep);background:var(--cream-deep);}
        .rv-date{font-size:.72rem;color:var(--muted);margin-left:auto;}
        .rv-stars{color:var(--gold);font-size:.95rem;margin:.15rem 0 .35rem;}
        .rv-stars .off{color:#E3D6C6;}
        .rv-comment{font-size:.86rem;color:var(--choc);line-height:1.6;overflow-wrap:anywhere;}
        .rv-extra{display:none;}
        .rv-list.show-all .rv-extra{display:flex;}
        .rv-toggle{margin-top:1rem;padding:.65rem 1.5rem;border-radius:2rem;border:1.5px solid var(--caramel-deep);background:transparent;color:var(--caramel-deep);font-size:.82rem;font-weight:700;cursor:pointer;transition:.15s;}
        .rv-toggle:hover{background:var(--caramel-deep);color:#fff;}
        .rv-empty{font-size:.88rem;color:var(--muted);background:var(--surface);border:1px dashed var(--border);border-radius:.9rem;padding:1.2rem 1.4rem;}
        .rv-note{font-size:.72rem;color:var(--muted);margin-top:.6rem;}
        @media (max-width:700px){
            .rv-card{padding:.85rem .9rem;gap:.7rem;}
            .rv-date{margin-left:0;width:100%;}
            .rv-summary{width:100%;}
        }

        footer{background:var(--surface);border-top:1px solid var(--border);padding:1.4rem 6%;margin-top:1rem;display:flex;flex-direction:column;align-items:center;gap:1rem;}
        .footer-top{width:100%;display:flex;align-items:center;justify-content:center;}
        .footer-links a{font-size:.82rem;font-weight:600;color:var(--choc);cursor:pointer;}
        .footer-links .sep{font-size:.82rem;color:var(--border);margin:0 1rem;}
        .footer-copy{font-size:.74rem;color:var(--muted);}

/* ── Responsive ── */
@media (hover:none){ .card-heart{opacity:1;transform:scale(1);} }
@media (min-width:761px) and (max-width:1100px){
    .order-detail{gap:1.5rem;}
    .order-detail-img{width:17rem;height:17rem;}
}
@media (max-width:760px){
    .order-page{padding:1rem 4% 1.5rem;gap:1.4rem;}
    .breadcrumb{font-size:.72rem;line-height:1.5;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

    .order-detail{flex-direction:column;gap:.9rem;}
    .order-detail-img{width:100%;height:auto;aspect-ratio:4/3;border-radius:.9rem;}
    .order-info{gap:.65rem;width:100%;}
    .order-category{font-size:.66rem;padding:.25rem .6rem;}
    .order-price{font-size:1.3rem;}
    .order-title{font-size:1.45rem;line-height:1.18;}
    .order-desc-text{font-size:.82rem;line-height:1.55;}
    .order-rating{flex-wrap:wrap;font-size:.76rem;gap:.35rem .55rem;}
    .stars{font-size:.95rem;}
    .stock-info{font-size:.76rem;}

    .order-actions{display:grid;grid-template-columns:auto 1fr;gap:.6rem;align-items:center;}
    .order-actions-label{grid-column:1/-1;font-size:.76rem;margin-bottom:-.2rem;}
    .product-qty-controls{justify-content:space-between;min-height:2.7rem;padding:.25rem .5rem;}
    .product-qty-controls .qty-btn{width:2rem;height:2rem;}
    .btn-add-cart{grid-column:1/-1;min-height:2.8rem;padding:.7rem;text-align:center;}

    .highlight-row{flex-direction:column;gap:.55rem;}
    .highlight-card{width:100%;min-width:0;padding:.7rem .9rem;}
    .highlight-card p{font-size:.76rem;}
    .highlight-card span{font-size:.68rem;}

    .order-section-title{font-size:1.05rem;margin-bottom:.65rem;}
    .other-controls{flex-direction:column;align-items:stretch;gap:.6rem;}
    .other-tabs{width:100%;overflow-x:auto;scrollbar-width:none;}
    .other-tabs::-webkit-scrollbar{display:none;}
    .other-tab{flex:1;text-align:center;font-size:.74rem;padding:.5rem .6rem;white-space:nowrap;}
    .other-search{width:100%;padding:.5rem .85rem;}
    .other-search input{width:100%;min-width:0;font-size:16px;}
    .other-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:.6rem;}
    .other-card{min-width:0;padding:.6rem;border-radius:.75rem;}
    .other-card-name{font-size:.74rem;}
    .other-card-rating{font-size:.68rem;}
    .other-card-add{min-height:2.3rem;font-size:.72rem;padding:.4rem;}

    .rv-card{padding:.8rem .9rem;gap:.65rem;}
    .rv-date{margin-left:0;width:100%;}
    .rv-summary{width:100%;}

    .cart-drawer{width:100%;max-width:100%;top:auto;border-radius:1rem 1rem 0 0;height:min(85vh,640px);height:min(85dvh,640px);transform:translateY(100%);}
    .cart-drawer.open{transform:translateY(0);}
    .cart-header{padding:.9rem 1rem;}
    .cart-items{padding:.75rem;}
    .cart-footer{padding:.85rem 1rem 1rem;}
    .cart-item-img{width:3.2rem;height:3.2rem;}
    .qty-btn{width:1.9rem;height:1.9rem;}
    .qty-input{height:1.9rem;font-size:16px;}

    .proceed-checkout{width:calc(100vw - 1rem);max-width:none;max-height:calc(100vh - 1rem);max-height:calc(100dvh - 1rem);padding:1.1rem;border-radius:1rem;}
    .checkout-field input,.checkout-field select,.pickup-field input{min-height:2.6rem;font-size:16px;}
    .place-order-btn{min-height:2.8rem;padding:.75rem;}

    footer{padding:1.2rem 5%;}
    .footer-links{display:flex;flex-wrap:wrap;justify-content:center;gap:.4rem .65rem;}
    .footer-links .sep{margin:0;}
}
@media (max-width:360px){
    .order-title{font-size:1.3rem;}
    .other-grid{gap:.5rem;}
    .other-card{padding:.5rem;}
}


/* Message / confirmation modal (replaces the browser's "localhost says" box) */
.lf-modal{position:fixed;inset:0;z-index:4000;background:rgba(30,15,5,.55);display:none;align-items:center;justify-content:center;padding:1rem;}
.lf-modal.open{display:flex;}
.lf-modal-box{background:#fff;border-radius:1rem;padding:1.5rem 1.4rem 1.2rem;width:100%;max-width:22rem;text-align:center;box-shadow:0 24px 60px rgba(30,15,5,.35);border-top:4px solid var(--caramel);animation:lfPop .18s ease;}
.lf-modal-box.danger{border-top-color:#c62828;}
.lf-modal-box h3{font-size:1.05rem;color:var(--choc-dark);margin-bottom:.4rem;}
.lf-modal-box p{font-size:.86rem;color:#6b5a4c;line-height:1.55;white-space:pre-line;}
.lf-modal-btns{display:flex;gap:.6rem;margin-top:1.1rem;}
.lf-modal-btns button{flex:1;padding:.65rem;border:none;border-radius:.6rem;font-size:.84rem;font-weight:700;cursor:pointer;font-family:inherit;}
.lf-no{background:var(--cream-deep);color:var(--choc-dark);}
.lf-yes{background:var(--choc-dark);color:#fff;}
.lf-modal-box.danger .lf-yes{background:#c62828;}
@keyframes lfPop{from{transform:scale(.94);opacity:0;}to{transform:scale(1);opacity:1;}}
    </style>
</head>
<body>

<div class="checkout-overlay" id="checkoutOverlay" onclick="hideCheck()"></div>
<div class="proceed-checkout" id="checkoutPanel">
    <span class="back-btn" onclick="hideCheck()">←</span>
    <p class="checkout-panel-title">Complete Your Order</p>
    <div class="checkout-mode-tabs">
        <div class="checkout-tab active" id="tab-order"   onclick="setMode('order')">🛒 Place Order</div>
        <div class="checkout-tab"        id="tab-reserve" onclick="setMode('reserve')">📅 Reserve Pickup</div>
    </div>
    <div class="checkout-field"><p>Customer Name</p><input type="text" id="checkoutName" placeholder="Your full name" value="<?= e($cust_name) ?>"></div>
    <div class="checkout-field"><p>Contact Number</p><input type="tel" id="checkoutPhone" placeholder="09X-XXXX-XXXX" maxlength="13" inputmode="numeric" autocomplete="tel" oninput="formatPhoneInput(this)" value="<?= e($cust_phone) ?>"><p class="checkout-hint">Format: 09X-XXXX-XXXX</p></div>
    <div class="checkout-field"><p>Delivery Location</p><input type="text" id="checkoutLocation" placeholder="Province-City-Brgy-Street-Blk,Lot-Other information" value="<?= e($cust_location) ?>"><p class="checkout-hint">Format: Province-City-Brgy-Street-Blk,Lot-Other information<br>Example: Laguna-Calamba-Real-Rizal St-Blk 5,Lot 3-Near the chapel</p></div>
    <div class="checkout-field"><p>Payment Method</p>
        <select id="checkoutPayment" onchange="refreshPaymentUI()">
            <option value="COD">Cash on Delivery (COD)</option>
            <option value="GCash">GCash (coming soon)</option>
        </select>
    </div>

    <!-- GCash preview: a design for a planned feature, not functional yet -->
    <div class="gcash-panel" id="gcashPanel" hidden>
        <div class="gcash-head">
            <span class="gcash-logo">G</span>
            <div><strong>Pay with GCash</strong><small>Scan the QR code using your GCash app</small></div>
            <span class="gcash-soon">Coming soon</span>
        </div>
        <div class="gcash-qr" id="gcashQr"></div>
        <p class="gcash-name">Liberty Foodmart</p>
        <p class="gcash-note">This is a preview of a planned feature. GCash payments are not available yet, so please choose another payment method to place your order.</p>
    </div>
    <div class="pickup-field" id="pickupField"><p>Pickup Date &amp; Time</p><input type="datetime-local" id="checkoutPickup"></div>
    <div class="checkout-total-box"><p id="checkoutTotal">Total: ₱0</p></div>
    <div class="place-order-btn" id="placeOrderBtn" onclick="submitCheckout()">Place Order</div>
</div>

<div class="cart-overlay" id="cartOverlay" onclick="closeCart()"></div>
<div class="cart-drawer" id="cartDrawer">
    <div class="cart-header">
        <div class="cart-header-left">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="var(--choc-dark)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
            <span class="cart-title">My Cart</span>
        </div>
        <div style="display:flex;align-items:center;gap:.8rem;">
            <span class="cart-count-badge" id="cartCountBadge">0 items</span>
            <div class="cart-close-btn" onclick="closeCart()"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></div>
        </div>
    </div>
    <div class="cart-items" id="cartItemsList">
        <div class="cart-empty" id="cartEmpty"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="var(--border)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg><p>Your cart is empty</p></div>
    </div>
    <div class="cart-footer" id="cartFooter" style="display:none;">
        <div class="cart-totals">
            <div class="cart-total-row"><span class="label">Subtotal</span><span class="val" id="cartSubtotal">₱0</span></div>
            <div class="cart-total-row"><span class="label bold">Total</span><span class="val bold" id="cartTotal">₱0</span></div>
        </div>
        <div class="btn-checkout" onclick="showCheck()">Proceed to Checkout</div>
        <div class="btn-clear" onclick="clearCart()">Clear Cart</div>
    </div>
</div>

<?php $nav_active = 'home'; $nav_cart = 'drawer'; require 'customer-nav.php'; ?>

<div class="order-page">
    <p class="breadcrumb"><a onclick="window.location.href='ordering-page.php'">Shop</a> / <?= e($product['category']) ?> / <?= e($product['name']) ?></p>

    <div class="order-detail">
        <img class="order-detail-img<?= $isOut ? ' oos' : '' ?>" src="<?= e($img) ?>" alt="<?= e($product['name']) ?>">
        <div class="order-info">
            <div class="order-meta">
                <span class="order-category"><?= e($product['category']) ?></span>
                <span class="order-price">₱<?= number_format($product['price'], 0) ?></span>
            </div>
            <h1 class="order-title"><?= e($product['name']) ?></h1>
            <div class="order-rating">
                <?php if ($product_rating['count'] > 0): ?>
                    <span class="stars stars-wrap" title="<?= number_format($product_rating['avg'], 1) ?> out of 5">
                        <span class="stars-base">★★★★★</span><span class="stars-fill" style="width:<?= star_fill_pct($product_rating['avg']) ?>%">★★★★★</span>
                    </span>
                    <span><strong><?= number_format($product_rating['avg'], 1) ?></strong>/5 · <?= review_count_label($product_rating['count']) ?></span>
                <?php else: ?>
                    <span class="stars stars-wrap"><span class="stars-base">★★★★★</span></span>
                    <span>No reviews yet</span>
                <?php endif; ?>
                <span class="see-reviews" onclick="document.getElementById('reviews').scrollIntoView({behavior:'smooth'})">See reviews</span>
            </div>
            <?php $isLow = !$isOut && $product['quantity'] <= $product['low_stock_threshold']; ?>
            <p class="stock-info <?= ($isLow || $isOut) ? 'low' : '' ?>">
                <?= $isOut
                    ? '✖ Out of stock'
                    : ($isLow
                        ? '⚠ Only ' . $product['quantity'] . ' left in stock!'
                        : '✔ In stock (' . $product['quantity'] . ' available)') ?>
            </p>
            <div class="order-actions">
                <div class="order-heart-btn" id="orderHeart" onclick="this.classList.toggle('liked')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                </div>
                <span class="order-actions-label">Quantity</span>
                <div class="product-qty-controls<?= $isOut ? ' disabled' : '' ?>">
                    <div class="qty-btn" onclick="changeProductQty(-1)">−</div>
                    <span class="product-qty-num" id="productQty">1</span>
                    <div class="qty-btn" onclick="changeProductQty(1)">+</div>
                </div>
                <?php if ($isOut): ?>
                    <div class="btn-add-cart disabled" id="addCartBtn">Out of Stock</div>
                <?php else: ?>
                    <div class="btn-add-cart" id="addCartBtn" onclick="addMainProduct()">Add to Cart</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Full description / ingredients. This is the only page that shows it. -->
    <div class="order-desc-block">
        <h2 class="order-section-title">Description &amp; Ingredients</h2>
        <p class="order-desc-text"><?= nl2br(e(trim((string)($product['description'] ?? '')) !== '' ? (string)$product['description'] : 'Freshly baked with premium ingredients.')) ?></p>
    </div>

    <!-- Honest highlights, replacing the old placeholder "20%/50% off" tags -->
    <div class="order-highlights">
        <h2 class="order-section-title">Why you'll love it</h2>
        <div class="highlight-row">
            <div class="highlight-card"><span class="ic">📦</span><div><p>Made to Order<span>Prepared once you check out</span></p></div></div>
            <div class="highlight-card"><span class="ic">🔒</span><div><p>Secure Checkout<span>Cash on delivery, or cash at pickup</span></p></div></div>
        </div>
    </div>

    <div class="order-other">
        <h2 class="order-section-title">Other available</h2>
        <div class="other-controls">
            <div class="other-tabs">
                <span class="other-tab active" onclick="switchOtherTab(this,'cakes')">Cakes</span>
                <span class="other-tab" onclick="switchOtherTab(this,'pastry')">Pastry</span>
                <span class="other-tab" onclick="switchOtherTab(this,'bread')">Bread</span>
            </div>
            <div class="other-search">
                <span>🔍</span>
                <input type="text" placeholder="Search" id="otherSearchInput" oninput="filterOther()">
            </div>
        </div>

        <div class="other-grid-wrap">
            <?php foreach (['cakes','pastry','bread'] as $idx => $cat): ?>
            <div class="other-grid" id="other-<?= $cat ?>" <?= $idx > 0 ? 'style="display:none;"' : '' ?>>
                <?php $items = $others_by_cat[$cat] ?? []; ?>
                <?php if (empty($items)): ?>
                    <p style="font-size:.85rem;color:var(--muted);">No products yet.</p>
                <?php else: foreach ($items as $o):
                    $oImg = ($o['image_path'] && file_exists($o['image_path'])) ? $o['image_path'] : 'src/black-forestv2.webp';
                    $oOut = (int)$o['quantity'] <= 0;
                ?>
                <div class="other-card<?= $oOut ? ' out-of-stock' : '' ?>"
                     data-id="<?= $o['id'] ?>"
                     data-name="<?= e($o['name']) ?>"
                     data-price="<?= $o['price'] ?>"
                     data-image="<?= e($oImg) ?>"
                     data-stock="<?= $o['quantity'] ?>"
                     onclick="window.location.href='ordering-process.php?id=<?= $o['id'] ?>'">
                    <img class="other-card-img" src="<?= e($oImg) ?>" alt="<?= e($o['name']) ?>">
                    <div class="other-card-info">
                        <div class="other-card-name"><?= e($o['name']) ?></div>
                        <?php $oR = $rating_map[(int)$o['id']] ?? null; ?>
                        <div class="other-card-rating">
                            <?php if ($oR): ?>
                                <span class="star">★</span> <?= number_format($oR['avg'], 1) ?> · ₱<?= number_format($o['price'], 0) ?>
                            <?php else: ?>
                                <span class="star dim">☆</span> No ratings · ₱<?= number_format($o['price'], 0) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($oOut): ?>
                        <button type="button" class="other-card-add" disabled onclick="event.stopPropagation()">Out of Stock</button>
                    <?php else: ?>
                        <button type="button" class="other-card-add" onclick="event.stopPropagation();addFromOtherCard(this)">+ Add</button>
                    <?php endif; ?>
                </div>
                <?php endforeach; endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- CUSTOMER REVIEWS (bottom of the page) -->
    <div class="order-reviews" id="reviews">
        <h2 class="order-section-title">Customer reviews</h2>

        <?php if (empty($product_reviews)): ?>
            <p class="rv-empty">No reviews for this product yet. Reviews show up here after customers rate a completed order.</p>
        <?php else: ?>
            <div class="rv-summary">
                <div class="rv-summary-score"><?= number_format($product_rating['avg'], 1) ?><small> / 5</small></div>
                <div class="rv-summary-meta">
                    <span class="stars stars-wrap">
                        <span class="stars-base">★★★★★</span><span class="stars-fill" style="width:<?= star_fill_pct($product_rating['avg']) ?>%">★★★★★</span>
                    </span>
                    <span>Based on <?= review_count_label($product_rating['count']) ?></span>
                </div>
            </div>

            <div class="rv-list" id="rvList">
                <?php foreach ($product_reviews as $i => $rv):
                    $shown   = review_display_name($rv['customer_name'] ?? '');
                    $stars   = max(1, min(5, (int)$rv['stars']));
                    $isMine  = $is_logged_in && $rv['customer_id'] !== null && (int)$rv['customer_id'] === (int)$cust_id;
                ?>
                <div class="rv-card<?= $i >= 3 ? ' rv-extra' : '' ?>">
                    <div class="rv-avatar"><?= e(review_initial($shown)) ?></div>
                    <div class="rv-body">
                        <div class="rv-top">
                            <span class="rv-name"><?= e($shown) ?></span>
                            <span class="rv-badge">Verified purchase</span>
                            <?php if ($isMine): ?><span class="rv-badge you">Your review</span><?php endif; ?>
                            <span class="rv-date"><?= e(date('M j, Y', strtotime($rv['rated_at']))) ?></span>
                        </div>
                        <div class="rv-stars" aria-label="<?= $stars ?> out of 5 stars"><?= str_repeat('★', $stars) ?><span class="off"><?= str_repeat('★', 5 - $stars) ?></span></div>
                        <?php if (trim((string)$rv['comment']) !== ''): ?>
                            <p class="rv-comment"><?= nl2br(e((string)$rv['comment'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (count($product_reviews) > 3): ?>
                <button type="button" class="rv-toggle" id="rvToggle"
                        data-label="View all <?= count($product_reviews) ?> reviews"
                        onclick="toggleReviews()">View all <?= count($product_reviews) ?> reviews</button>
            <?php endif; ?>
            <?php if ($product_rating['count'] > count($product_reviews)): ?>
                <p class="rv-note">Showing the latest <?= count($product_reviews) ?> of <?= (int)$product_rating['count'] ?> reviews.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <footer>
        <div class="footer-top">
            <div class="footer-links">
                <a href="#">About Us</a><span class="sep">|</span>
                <a href="#">Contact</a><span class="sep">|</span>
                <a href="#">Terms and Conditions</a>
            </div>
        </div>
        <p class="footer-copy">© 2026 Liberty Foodmart | All Rights Reserved</p>
    </footer>
</div>

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
const IS_LOGGED_IN = <?= $is_logged_in ? 'true' : 'false' ?>;

// ── Product data from PHP ──────────────────────────────────
const PRODUCT = {
    id:    <?= (int)$product['id'] ?>,
    name:  <?= json_encode($product['name']) ?>,
    price: <?= (float)$product['price'] ?>,
    image: <?= json_encode($img) ?>,
    stock: <?= (int)$product['quantity'] ?>,
};

let productQty = 1;

// Same sessionStorage-backed cart as ordering-page.php (same key),
// so items added here or on the shop page share one cart.
const CART_KEY = 'lf_cart';
function loadCart(){
    try { return JSON.parse(sessionStorage.getItem(CART_KEY)) || {}; }
    catch(e){ return {}; }
}
function saveCartStorage(){
    sessionStorage.setItem(CART_KEY, JSON.stringify(cart));
}

let cart = loadCart();
let checkoutMode = 'order';

function fmt(n){ return '₱' + Number(n).toLocaleString('en-PH'); }
function getTotal(){ return Object.values(cart).reduce((s,i)=>s+i.price*i.qty,0); }
function getCount(){ return Object.values(cart).reduce((s,i)=>s+i.qty,0); }

// ── Product qty on detail page ─────────────────────────────
function changeProductQty(delta) {
    productQty = Math.max(1, Math.min(productQty + delta, PRODUCT.stock));
    document.getElementById('productQty').textContent = productQty;
}

function addMainProduct() {
    if (PRODUCT.stock <= 0) return;
    const added = addToCart(PRODUCT.id, PRODUCT.name, PRODUCT.price, PRODUCT.image, PRODUCT.stock, productQty);
    if (!added) return;   // nothing went into the cart → no green "Added ✓"
    const btn = document.getElementById('addCartBtn');
    const original = btn.textContent;
    btn.textContent = 'Added ✓ (' + added + ')';   // the amount that was really added
    btn.classList.add('just-added');
    setTimeout(() => { btn.textContent = original; btn.classList.remove('just-added'); }, 1200);
    productQty = 1;
    document.getElementById('productQty').textContent = 1;
}

// ── Cart ───────────────────────────────────────────────────
// ── Modal (instead of alert) ───────────────────────────────
// showModal({title, message, confirmText, cancelText, tone}) → Promise<boolean>
function showModal({title,message,confirmText='OK',cancelText=null,tone='info'}){
    return new Promise(resolve=>{
        const ov=document.getElementById('lfModal'),yes=document.getElementById('lfYes'),no=document.getElementById('lfNo');
        document.getElementById('lfModalTitle').textContent=title;
        document.getElementById('lfModalMsg').textContent=message;
        document.getElementById('lfModalBox').className='lf-modal-box'+(tone==='danger'?' danger':'');
        yes.textContent=confirmText;
        no.textContent=cancelText||'';
        no.style.display=cancelText?'':'none';
        ov.classList.add('open');
        function done(v){ov.classList.remove('open');yes.onclick=no.onclick=null;document.removeEventListener('keydown',onKey);resolve(v);}
        function onKey(ev){if(ev.key==='Escape')done(false);}
        document.addEventListener('keydown',onKey);
        yes.onclick=()=>done(true);
        no.onclick=()=>done(false);
        yes.focus();
    });
}
function showError(title,message){return showModal({title,message,tone:'danger'});}
// Shown when the customer tries to put more in the cart than the shop has
function showStockLimit(name,stock){
    return showModal({
        title:'Stock limit reached',
        message:'Only '+stock+' '+name+' in stock, and '+(stock===1?'it is':'all are')+' already in your cart.',
        confirmText:'View Cart',cancelText:'Keep Shopping'
    }).then(v=>{if(v)openCart();});
}

// Returns how many were actually added (0 = nothing, stock limit reached)
function addToCart(id, name, price, image, stock, qty) {
    stock = parseInt(stock) || 0;
    qty = qty || 1;
    if (stock <= 0) { showError('Out of stock', name + ' is out of stock.'); return 0; }

    const have = cart[id] ? cart[id].qty : 0;
    const room = stock - have;
    if (room <= 0) { showStockLimit(name, stock); return 0; }

    const add = Math.min(qty, room);
    if (cart[id]) { cart[id].qty = have + add; }
    else          { cart[id] = { name, price, qty: add, image, stock }; }
    renderCart();

    if (add < qty) {   // only part of what they asked for fit
        showModal({
            title: 'Added fewer than you asked',
            message: 'Only ' + stock + ' ' + name + ' in stock, so we added ' + add + '. You now have ' + (have + add) + ' in your cart.',
            confirmText: 'View Cart', cancelText: 'OK'
        }).then(v => { if (v) openCart(); });
    }
    return add;
}

// Quick-add for the "Other available" cards — adds without navigating
// away, so a multi-product order can be built straight from here too.
function addFromOtherCard(btn){
    const card=btn.closest('.other-card');
    const d=card.dataset;
    if(!addToCart(d.id,d.name,parseFloat(d.price),d.image,parseInt(d.stock),1))return;   // nothing added → no green "Added ✓"
    btn.textContent='Added ✓';
    btn.classList.add('just-added');
    setTimeout(()=>{btn.textContent='+ Add';btn.classList.remove('just-added');},1000);
}

function renderCart() {
    const list=document.getElementById('cartItemsList'),empty=document.getElementById('cartEmpty'),footer=document.getElementById('cartFooter');
    list.querySelectorAll('.cart-item').forEach(el=>el.remove());
    const keys=Object.keys(cart);
    empty.style.display=keys.length?'none':'flex';
    footer.style.display=keys.length?'flex':'none';
    keys.forEach(id=>{
        const item=cart[id],row=document.createElement('div');
        row.className='cart-item';
        row.innerHTML=`<div class="cart-item-img"><img src="${item.image}" alt="${item.name}"></div><div class="cart-item-info"><div class="cart-item-name">${item.name}</div><div class="cart-item-price">${fmt(item.price)}</div><div class="cart-item-qty"><div class="qty-btn" onclick="changeQty('${id}',-1)">−</div><input type="number" class="qty-input" inputmode="numeric" min="1" max="${item.stock||''}" value="${item.qty}" aria-label="Quantity" onfocus="this.select()" onkeydown="if(event.key==='Enter')this.blur()" onchange="setQty('${id}',this.value)"><div class="qty-btn" onclick="changeQty('${id}',1)">+</div><div class="cart-item-delete" onclick="removeFromCart('${id}')"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg></div></div></div>`;
        list.appendChild(row);
    });
    const total=getTotal(),count=getCount();
    document.getElementById('cartSubtotal').textContent=fmt(total);
    document.getElementById('cartTotal').textContent=fmt(total);
    document.getElementById('cartCountBadge').textContent=count+' item'+(count!==1?'s':'');
    const nb=document.getElementById('navCartBadge');
    nb.textContent=count; nb.classList.toggle('visible',count>0);
    document.getElementById('checkoutTotal').textContent='Total: '+fmt(total);
    saveCartStorage();
}

// Lets the customer type a quantity straight into the cart (between − and +).
// Runs when they leave the box or press Enter, so the cart isn't redrawn mid-typing.
function setQty(id,val){
    if(!cart[id])return;
    let n=parseInt(val,10);
    if(isNaN(n)||n<1)n=1;
    const max=parseInt(cart[id].stock)||0;
    if(max&&n>max){showModal({title:'Stock limit reached',message:'Only '+max+' '+cart[id].name+' in stock. We set the quantity to '+max+'.'});n=max;}
    cart[id].qty=n;
    renderCart();
}
function changeQty(id,delta){if(!cart[id])return;cart[id].qty+=delta;if(cart[id].qty<=0)delete cart[id];else if(cart[id].qty>cart[id].stock)cart[id].qty=cart[id].stock;renderCart();}
function removeFromCart(id){delete cart[id];renderCart();}
function clearCart(){Object.keys(cart).forEach(k=>delete cart[k]);renderCart();}
function openCart(){document.getElementById('cartDrawer').classList.add('open');document.getElementById('cartOverlay').classList.add('open');document.body.style.overflow='hidden';}
function closeCart(){document.getElementById('cartDrawer').classList.remove('open');document.getElementById('cartOverlay').classList.remove('open');document.body.style.overflow='';}

// ── Checkout ───────────────────────────────────────────────
// ── Checkout input formats ───────────────────────────────────
// Phone:    09X-XXXX-XXXX  (11 digits, typed digits are dashed automatically)
// Location: Province-City-Brgy-Street-Blk,Lot-Other information
function formatPhone(v){
    const d=String(v).replace(/\D/g,'').slice(0,11);
    let out=d.slice(0,3);
    if(d.length>3) out+='-'+d.slice(3,7);
    if(d.length>7) out+='-'+d.slice(7,11);
    return out;
}
function formatPhoneInput(el){ el.value=formatPhone(el.value); }
function isValidPhone(v){ return /^09\d-\d{4}-\d{4}$/.test(v); }
function isValidLocation(v){
    const parts=v.split('-').map(p=>p.trim());
    return parts.length>=5 && parts.slice(0,5).every(p=>p.length>0);
}

function showCheck(){
    if(!Object.keys(cart).length){showError('Your cart is empty','Add something to your cart first.');return;}
    if(!IS_LOGGED_IN){window.location.href='customer-login.php?redirect=ordering-process.php';return;}
    closeCart();
    const ph=document.getElementById('checkoutPhone'); ph.value=formatPhone(ph.value);
    document.getElementById('checkoutPanel').style.display='flex';
    document.getElementById('checkoutOverlay').style.display='block';
    document.getElementById('checkoutTotal').textContent='Total: '+fmt(getTotal());
}
function hideCheck(){document.getElementById('checkoutPanel').style.display='none';document.getElementById('checkoutOverlay').style.display='none';}

function setMode(mode){
    checkoutMode=mode;
    document.getElementById('tab-order').classList.toggle('active',mode==='order');
    document.getElementById('tab-reserve').classList.toggle('active',mode==='reserve');
    document.getElementById('pickupField').classList.toggle('show',mode==='reserve');
    const btn=document.getElementById('placeOrderBtn');
    if(mode==='reserve'){btn.textContent='📅 Reserve Order';btn.classList.add('reserve-mode');}
    else{btn.textContent='Place Order';btn.classList.remove('reserve-mode');}
    // Delivery is Cash on Delivery, pickup (reservation) is plain Cash.
    // GCash is listed as a coming-soon preview (it can't be used to place an order).
    document.getElementById('checkoutPayment').innerHTML = (mode==='reserve'
        ? '<option value="Cash">Cash (pay at pickup)</option>'
        : '<option value="COD">Cash on Delivery (COD)</option>')
        + '<option value="GCash">GCash (coming soon)</option>';
    refreshPaymentUI();
}

// Shows the GCash QR preview and greys out the order button while GCash is selected
function refreshPaymentUI(){
    const isGcash=document.getElementById('checkoutPayment').value==='GCash';
    document.getElementById('gcashPanel').hidden=!isGcash;
    const qr=document.getElementById('gcashQr');
    if(isGcash&&!qr.firstChild)qr.innerHTML=buildPreviewQr();
    const btn=document.getElementById('placeOrderBtn');
    btn.classList.toggle('disabled',isGcash);
    btn.setAttribute('aria-disabled',isGcash?'true':'false');
}

// A decorative QR-style graphic for the GCash preview. It is NOT a real QR code and
// will not scan: it only shows how the screen will look once GCash is added.
function buildPreviewQr(){
    const N=25,cell=8,quiet=2,size=(N+quiet*2)*cell;
    let seed=20261031;   // fixed seed, so the pattern looks the same every time
    const rand=()=>{seed|=0;seed=seed+0x6D2B79F5|0;let t=Math.imul(seed^seed>>>15,1|seed);t=t+Math.imul(t^t>>>7,61|t)^t;return((t^t>>>14)>>>0)/4294967296;};
    const g=Array.from({length:N},()=>Array.from({length:N},()=>rand()<0.5?1:0));
    const clear=(r0,c0,h,w)=>{for(let r=r0;r<r0+h;r++)for(let c=c0;c<c0+w;c++)if(r>=0&&r<N&&c>=0&&c<N)g[r][c]=0;};
    const finder=(r0,c0)=>{clear(r0-1,c0-1,9,9);for(let r=0;r<7;r++)for(let c=0;c<7;c++){g[r0+r][c0+c]=(r===0||r===6||c===0||c===6||(r>=2&&r<=4&&c>=2&&c<=4))?1:0;}};
    finder(0,0);finder(0,N-7);finder(N-7,0);                                  // the three corner squares
    for(let i=8;i<N-8;i++){g[6][i]=i%2===0?1:0;g[i][6]=i%2===0?1:0;}          // timing lines
    clear(16,16,5,5);for(let r=0;r<5;r++)for(let c=0;c<5;c++){g[16+r][16+c]=(Math.max(Math.abs(r-2),Math.abs(c-2))===1)?0:1;}   // small square near the corner
    const m=Math.floor(N/2);clear(m-2,m-2,5,5);                               // room for the GCash badge
    let d='';
    for(let r=0;r<N;r++)for(let c=0;c<N;c++)if(g[r][c])d+='M'+((c+quiet)*cell)+' '+((r+quiet)*cell)+'h'+cell+'v'+cell+'h-'+cell+'z';
    const mid=size/2;
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '+size+' '+size+'" role="img" aria-label="Sample GCash QR code (preview only)">'
        +'<rect width="'+size+'" height="'+size+'" fill="#fff"/><path d="'+d+'" fill="#0b3d91"/>'
        +'<rect x="'+(mid-17)+'" y="'+(mid-17)+'" width="34" height="34" rx="8" fill="#fff"/>'
        +'<circle cx="'+mid+'" cy="'+mid+'" r="12" fill="#0057d9"/>'
        +'<text x="'+mid+'" y="'+(mid+5)+'" text-anchor="middle" font-family="Arial,sans-serif" font-size="15" font-weight="800" fill="#fff">G</text></svg>';
}

function submitCheckout(){
    const name=document.getElementById('checkoutName').value.trim();
    const phone=document.getElementById('checkoutPhone').value.trim();
    const location=document.getElementById('checkoutLocation').value.trim();
    const payment=document.getElementById('checkoutPayment').value;
    const pickup=document.getElementById('checkoutPickup').value;
    const keys=Object.keys(cart);
    // GCash is only a preview for now: it can't be used to place an order
    if(payment==='GCash'){showError('GCash is not available yet','GCash payment is coming soon.\nPlease choose '+(checkoutMode==='reserve'?'Cash (pay at pickup)':'Cash on Delivery')+' to continue.');return;}
    if(!name){showError('Name needed','Please enter your name.');return;}
    if(!isValidPhone(phone)){showError('Check your contact number','Please enter your contact number in this format:\n09X-XXXX-XXXX');return;}
    if(!isValidLocation(location)){showError('Check your location','Please enter your location in this format:\nProvince-City-Brgy-Street-Blk,Lot-Other information');return;}
    if(!keys.length){showError('Your cart is empty','Add something to your cart first.');return;}
    if(checkoutMode==='reserve'&&!pickup){showError('Pickup time needed','Please select a pickup date and time.');return;}
    const items=keys.map(id=>({product_id:id,name:cart[id].name,price:cart[id].price,qty:cart[id].qty}));
    const body=new URLSearchParams({action:checkoutMode,customer_name:name,phone,location,payment_method:payment,items:JSON.stringify(items),pickup_datetime:pickup});
    fetch('ordering-page.php',{method:'POST',body})
        .then(r=>r.json())
        .then(data=>{
            if(!data.ok){
                if(data.auth_required){window.location.href='customer-login.php?redirect=ordering-process.php';return;}
                showError('Could not complete your request',data.error||'Unknown error.');return;
            }
            clearCart();hideCheck();
            if(data.type==='order') window.location.href='order-confirmation.php?order_id='+data.order_id;
            else window.location.href='order-confirmation.php?reservation_id='+data.reservation_id;
        })
        .catch(()=>showError('Request failed','Please check your connection and try again.'));
}

// ── Other products section ─────────────────────────────────
// Reviews: show the first 3, reveal the rest with "View all"
function toggleReviews(){
    const list = document.getElementById('rvList');
    const btn  = document.getElementById('rvToggle');
    if (!list || !btn) return;
    const open = list.classList.toggle('show-all');
    btn.textContent = open ? 'Show less' : btn.dataset.label;
    if (!open) document.getElementById('reviews').scrollIntoView({behavior:'smooth', block:'start'});
}

function switchOtherTab(el, cat) {
    document.querySelectorAll('.other-tab').forEach(t=>t.classList.remove('active'));
    el.classList.add('active');
    ['cakes','pastry','bread'].forEach(c=>{
        const g=document.getElementById('other-'+c);
        if(g) g.style.display=c===cat?'grid':'none';
    });
    document.getElementById('otherSearchInput').value='';
}

function filterOther(){
    const q=document.getElementById('otherSearchInput').value.toLowerCase().trim();
    const activeTab=document.querySelector('.other-tab.active');
    if(!activeTab) return;
    const onclick=activeTab.getAttribute('onclick');
    const match=onclick ? onclick.match(/'(\w+)'\)/) : null;
    const cat=match ? match[1] : 'cakes';
    const grid=document.getElementById('other-'+cat);
    if(!grid) return;
    grid.querySelectorAll('.other-card').forEach(card=>{
        const name=card.querySelector('.other-card-name').textContent.toLowerCase();
        card.style.display=(!q||name.includes(q))?'':'none';
    });
}

// Reflect any cart restored from sessionStorage as soon as the page loads
renderCart();
</script>
</body>
</html>