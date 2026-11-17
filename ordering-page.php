<?php
// ── Liberty Foodmart — Shop / Ordering Page ──
require_once 'session.php';
require 'db.php';
require 'vat.php';
require_once 'reservation-stock.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    $cust_id   = $_SESSION['customer_id'] ?? null;

    // ── Must be logged in to place an online order or reservation ──
    // Guest checkout is not allowed — this is enforced server-side so it
    // can't be bypassed by skipping the login-redirect on the client.
    if (!$cust_id) {
        echo json_encode(['ok' => false, 'auth_required' => true, 'error' => 'Please log in to place an order.']);
        exit;
    }

    $name      = trim($_POST['customer_name'] ?? '');
    $phone     = trim($_POST['phone']         ?? '');
    $location  = trim($_POST['location']      ?? '');
    $raw_items = json_decode($_POST['items']  ?? '[]', true);

    // Cash only. A delivered order is Cash on Delivery ('COD'); a reservation is
    // picked up in the shop and paid in 'Cash'. Decided here on the server, never
    // from what the browser sends.
    $payment = ($_POST['action'] === 'reserve') ? 'Cash' : 'COD';

    // GCash is shown at checkout as a preview of a planned feature, but it is not
    // wired up. Refuse it here too, so it can't be placed by editing the page.
    if (strcasecmp(trim($_POST['payment_method'] ?? ''), 'GCash') === 0) {
        echo json_encode(['ok' => false, 'error' => 'GCash payment is not available yet. Please choose ' . ($_POST['action'] === 'reserve' ? 'Cash (pay at pickup)' : 'Cash on Delivery') . '.']);
        exit;
    }

    // Online ordering has no Senior/PWD discount.
    $is_senior_pwd = false;

    if (!$name || !$phone || !$location || empty($raw_items)) {
        echo json_encode(['ok' => false, 'error' => 'Please fill in all fields.']);
        exit;
    }

    // Contact number must be 09X-XXXX-XXXX (11 digits). Stored in that dashed form.
    $phone_digits = preg_replace('/\D/', '', $phone);
    if (!preg_match('/^09\d{9}$/', $phone_digits)) {
        echo json_encode(['ok' => false, 'error' => 'Please enter your contact number in this format: 09X-XXXX-XXXX']);
        exit;
    }
    $phone = substr($phone_digits, 0, 3) . '-' . substr($phone_digits, 3, 4) . '-' . substr($phone_digits, 7, 4);

    // Location must follow: Province-City-Brgy-Street-Blk,Lot-Other information
    $loc_parts = array_map('trim', explode('-', $location));
    if (count($loc_parts) < 5 || in_array('', array_slice($loc_parts, 0, 5), true)) {
        echo json_encode(['ok' => false, 'error' => 'Please enter your location in this format: Province-City-Brgy-Street-Blk,Lot-Other information']);
        exit;
    }

    // ── Re-price every cart line from the DB ─────────────────
    // The client is only trusted for product_id + qty. Name and price are
    // looked up fresh here so a tampered request can't check out at an
    // arbitrary price. Never trust $_POST for money.
    $items = [];
    $price_lookup = $pdo->prepare('SELECT id, name, price FROM products WHERE id = ?');
    foreach ($raw_items as $raw) {
        $pid = (int)($raw['product_id'] ?? 0);
        $qty = (int)($raw['qty'] ?? 0);

        if ($pid <= 0 || $qty <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid item in cart.']);
            exit;
        }

        $price_lookup->execute([$pid]);
        $product = $price_lookup->fetch();

        if (!$product) {
            echo json_encode(['ok' => false, 'error' => 'One of the items in your cart is no longer available.']);
            exit;
        }

        $items[] = [
            'product_id' => (int)$product['id'],
            'name'       => $product['name'],
            'unit_price' => (float)$product['price'],
            'qty'        => $qty,
        ];
    }

    // ── Price the whole order ─────────────────────────────────
    // Prices are VAT-inclusive. There is no discount on online orders.
    // The VAT rate comes from the settings table, never from the client.
    $rates  = get_vat_settings($pdo);
    $priced = price_order($items, $is_senior_pwd, $rates);
    $total  = $priced['total'];

    if ($_POST['action'] === 'reserve') {
        [$pickup, $pickup_err] = reservation_pickup($_POST['pickup_datetime'] ?? '');
        if ($pickup_err) { echo json_encode(['ok' => false, 'error' => $pickup_err]); exit; }
        $qty     = array_reduce($items, fn($s, $i) => $s + $i['qty'], 0);
        $first   = $items[0]['name'];
        $summary = count($items) > 1 ? $first . ' (+' . (count($items) - 1) . ' more)' : $first;
        $pid     = (int)($items[0]['product_id'] ?? 0);

        // Transaction: the reservation and its stock hold succeed together or
        // not at all. If any item is short, nothing is saved.
        try {
            $pdo->beginTransaction();

            $pdo->prepare("
                INSERT INTO reservations
                    (customer_id, customer_name, contact, product_id, product_name, quantity,
                     subtotal, discount_amount, total_price, net_amount, vat_amount, is_senior_pwd,
                     payment_method, payment_status, pickup_datetime, status, stock_deducted)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'UNPAID', ?, 'Active', 1)
            ")->execute([
                $cust_id, $name, $phone, $pid ?: null, $summary, $qty,
                $priced['subtotal'], $priced['discount_amount'], $total, $priced['net_amount'], $priced['vat_amount'], $is_senior_pwd ? 1 : 0,
                $payment, $pickup,
            ]);
            $res_id = $pdo->lastInsertId();

            // Save the full per-product breakdown so staff can see every item
            $ins_res_item = $pdo->prepare('INSERT INTO reservation_items (reservation_id, product_id, product_name, unit_price, quantity) VALUES (?,?,?,?,?)');
            foreach ($items as $item) {
                $item_pid = (int)($item['product_id'] ?? 0);
                $ins_res_item->execute([$res_id, $item_pid ?: null, $item['name'], $item['unit_price'], $item['qty']]);
            }

            // Hold the stock so it can't be sold to someone else meanwhile
            reservation_deduct_stock($pdo, $items);

            if ($cust_id) {
                $pdo->prepare('UPDATE customers SET phone = ?, default_location = ? WHERE id = ?')->execute([$phone, $location, $cust_id]);
            }

            $pdo->commit();
        } catch (StockException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => $e->getMessage() . ' Please update your cart.']);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('online reserve failed: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Could not save your reservation. Please try again.']);
            exit;
        }

        if ($cust_id) { $_SESSION['customer_phone'] = $phone; $_SESSION['customer_location'] = $location; }
        echo json_encode(['ok' => true, 'type' => 'reserve', 'reservation_id' => $res_id]);
        exit;
    }

    if ($_POST['action'] === 'order') {
        $pwd_id_path     = null;
        $discount_status = 'none';

        // Wrapped in a transaction: if any item is out of stock, the whole
        // order rolls back instead of leaving a partially-deducted order.
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                INSERT INTO orders
                    (customer_id, customer_name, phone, location, order_type, order_status, payment_method, payment_status,
                     subtotal, discount_amount, total, net_amount, vat_amount, is_senior_pwd, discount_status, pwd_id_image_path)
                VALUES (?, ?, ?, ?, 'online', 'Processing', ?, 'UNPAID', ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $cust_id, $name, $phone, $location, $payment,
                $priced['subtotal'], $priced['discount_amount'], $total, $priced['net_amount'], $priced['vat_amount'],
                $is_senior_pwd ? 1 : 0, $discount_status, $pwd_id_path,
            ]);
            $order_id = $pdo->lastInsertId();
            $ins    = $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, discount_amount, line_total) VALUES (?,?,?,?,?,?,?)');
            $deduct = $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ? AND quantity >= ?');

            foreach ($priced['lines'] as $line) {
                $deduct->execute([$line['qty'], $line['product_id'], $line['qty']]);
                // WHERE quantity >= ? means 0 rows affected = not enough stock left —
                // don't record the item as if it were fulfilled.
                if ($deduct->rowCount() === 0) {
                    throw new RuntimeException($line['name'] . ' just sold out. Please update your cart.');
                }
                $ins->execute([$order_id, $line['product_id'], $line['name'], $line['unit_price'], $line['qty'], $line['discount_amount'], $line['line_total']]);
            }

            if ($cust_id) {
                $pdo->prepare('UPDATE customers SET phone = ?, default_location = ? WHERE id = ?')->execute([$phone, $location, $cust_id]);
                $_SESSION['customer_phone'] = $phone; $_SESSION['customer_location'] = $location;
            }

            $pdo->commit();
            echo json_encode(['ok' => true, 'type' => 'order', 'order_id' => $order_id]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    echo json_encode(['ok' => false, 'error' => 'Unknown action.']); exit;
}

// Every product is listed. Zero-stock ones are shown greyed out with an
// "Out of Stock" label instead of being hidden.
$products_raw = $pdo->query("SELECT * FROM products ORDER BY name")->fetchAll();

// "Recommended" = the 10 best-selling products (most units sold, cancelled
// orders ignored). $best_rank maps product id => rank 1..10.
$best_rank = [];
$best_rows = $pdo->query("
    SELECT oi.product_id, SUM(oi.quantity) AS units
      FROM order_items oi
      JOIN orders   o ON o.id = oi.order_id
      JOIN products p ON p.id = oi.product_id
     WHERE o.order_status <> 'Cancelled'
     GROUP BY oi.product_id
     ORDER BY units DESC, oi.product_id ASC
     LIMIT 10
")->fetchAll();
foreach ($best_rows as $i => $row) { $best_rank[(int)$row['product_id']] = $i + 1; }
$by_cat = [];
foreach ($products_raw as $p) { $by_cat[$p['category']][] = $p; }

// Only show filter checkboxes for categories that actually have products,
// in the schema's canonical order.
$cat_order    = ['Cake', 'Bread', 'Pastry', 'Drink', 'Other'];
$allowed_cats = array_values(array_intersect($cat_order, array_keys($by_cat)));

$cust_name     = $_SESSION['customer_name']     ?? '';
$cust_phone    = $_SESSION['customer_phone']    ?? '';
$cust_location = $_SESSION['customer_location'] ?? '';
$is_logged_in  = !empty($_SESSION['customer_id']);

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PastryLiberty — Shop</title>
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
h1,h2,h3{font-family:'Playfair Display',serif;}

@keyframes fadeUp{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);}}
@keyframes fadeIn{from{opacity:0;}to{opacity:1;}}
@keyframes pop{0%{transform:scale(1);}40%{transform:scale(1.18);}100%{transform:scale(1);}}
@keyframes shimmer{0%{background-position:-200% 0;}100%{background-position:200% 0;}}

/* ── Cart drawer ───────────────────────────────────────── */
.cart-overlay{position:fixed;inset:0;background:rgba(30,15,5,.5);z-index:1400;opacity:0;pointer-events:none;transition:opacity .3s;}
.cart-overlay.open{opacity:1;pointer-events:all;}
.cart-drawer{position:fixed;top:0;right:0;bottom:0;width:23rem;max-width:92vw;background:var(--surface);z-index:1500;display:flex;flex-direction:column;transform:translateX(100%);transition:transform .35s cubic-bezier(.4,0,.2,1);box-shadow:-8px 0 32px rgba(59,36,23,.2);}
.cart-drawer.open{transform:translateX(0);}
.cart-header{display:flex;align-items:center;justify-content:space-between;padding:1.2rem 1.4rem;border-bottom:1px solid var(--border);}
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

/* ── Checkout modal ────────────────────────────────────── */
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
.checkout-total-box{width:100%;border-radius:.8rem;background:var(--cream-deep);color:var(--choc-dark);padding:.9rem 1rem;display:flex;flex-direction:column;gap:.45rem;margin-top:1.1rem;}
.checkout-total-row{display:flex;align-items:center;justify-content:space-between;font-size:.85rem;font-weight:600;color:var(--choc);}

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

/* ── Hero ──────────────────────────────────────────────── */
.hero{position:relative;padding:2rem 6% 1.8rem;background:linear-gradient(135deg,var(--choc-dark),var(--choc));overflow:hidden;display:flex;align-items:center;justify-content:space-between;gap:1.5rem;}
.hero::before{content:'';position:absolute;top:-50%;right:-6%;width:26rem;height:26rem;border-radius:50%;background:radial-gradient(circle,rgba(212,162,76,.28),transparent 70%);animation:heroGlow 8s ease-in-out infinite;}
@keyframes heroGlow{0%,100%{transform:scale(1);}50%{transform:scale(1.15);}}
.hero-text{position:relative;z-index:2;max-width:34rem;animation:fadeUp .7s ease;}
.hero-text .eyebrow{color:var(--gold);font-size:.72rem;font-weight:700;letter-spacing:.18em;text-transform:uppercase;margin-bottom:.45rem;}
.hero-text h1{font-size:2rem;color:#fff;line-height:1.15;font-weight:700;font-style:italic;}
.hero-text p{font-size:.88rem;color:rgba(255,255,255,.75);margin-top:.6rem;max-width:30rem;line-height:1.5;}
.hero-img{position:relative;z-index:2;width:10rem;height:10rem;flex-shrink:0;animation:fadeUp .8s ease .1s both;}
.hero-img img{width:100%;height:100%;object-fit:contain;filter:drop-shadow(0 16px 24px rgba(0,0,0,.35));}

/* ── Layout ────────────────────────────────────────────── */
.shop-layout{display:flex;gap:1.5rem;padding:1.6rem 5% 3rem;align-items:flex-start;max-width:88rem;margin:0 auto;}
.sidebar{width:14.5rem;min-width:13.5rem;position:sticky;top:calc(var(--cnav-h,3.5rem) + 1rem);}
.filter-box{background:var(--surface);border-radius:.9rem;padding:1.1rem;border:1px solid var(--border);box-shadow:0 3px 12px var(--shadow);}
.filter-box-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;padding-bottom:.6rem;border-bottom:1px solid var(--border);}
.filter-box-head h3{font-size:.95rem;font-weight:700;color:var(--choc-dark);}
.filter-head-left{display:flex;align-items:center;gap:.5rem;}
.filter-head-right{display:flex;align-items:center;gap:.9rem;}
.filter-count-badge{background:var(--caramel-deep);color:#fff;font-size:.64rem;font-weight:800;padding:.12rem .45rem;border-radius:1rem;line-height:1.4;}
.filter-chevron{display:none;width:1.1rem;height:1.1rem;color:var(--choc);flex-shrink:0;transition:transform .25s ease;}
.filter-body{overflow:hidden;}
.clear-filters{font-size:.7rem;font-weight:700;color:var(--caramel-deep);cursor:pointer;}
.clear-filters:hover{text-decoration:underline;}
.filter-group{margin-bottom:1rem;}
.filter-group h4{font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem;}
.filter-group label{display:flex;align-items:center;gap:.55rem;font-size:.8rem;color:var(--choc);margin-bottom:.45rem;cursor:pointer;}
.filter-group input[type="checkbox"]{width:1rem;height:1rem;accent-color:var(--caramel);cursor:pointer;}
.sort-select{width:100%;padding:.5rem .65rem;border:1.5px solid var(--border);border-radius:.5rem;font-size:.8rem;background:var(--cream);color:var(--choc-dark);font-family:'Inter',sans-serif;cursor:pointer;}

.products-area{flex:1;display:flex;flex-direction:column;gap:1rem;min-width:0;}
.search-bar-row{display:flex;align-items:center;gap:.8rem;}
.search-wrap{flex:1;min-width:0;display:flex;align-items:center;background:var(--surface);border-radius:.7rem;border:1.5px solid var(--border);padding:.55rem .9rem;gap:.5rem;box-shadow:0 2px 6px var(--shadow);}
.search-wrap input{flex:1;min-width:0;font-size:.84rem;color:var(--choc-dark);background:transparent;}
.btn-view-cart{display:flex;align-items:center;gap:.5rem;background:var(--choc-dark);color:#fff;padding:.65rem 1.2rem;border-radius:.7rem;font-size:.8rem;font-weight:700;cursor:pointer;white-space:nowrap;transition:background .15s;}
.btn-view-cart:hover{background:var(--caramel-deep);}

.active-chips{display:flex;flex-wrap:wrap;gap:.45rem;min-height:0;}
.chip{display:flex;align-items:center;gap:.4rem;background:var(--cream-deep);color:var(--caramel-deep);font-size:.7rem;font-weight:700;padding:.3rem .65rem;border-radius:1rem;animation:fadeUp .2s ease;}
.chip span.x{cursor:pointer;font-weight:800;opacity:.7;}
.chip span.x:hover{opacity:1;}
.result-meta{font-size:.76rem;color:var(--muted);}

.products-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(13rem,1fr));gap:1rem;}
.product-card{background:var(--surface);border-radius:.9rem;overflow:hidden;box-shadow:0 2px 10px var(--shadow);cursor:pointer;border:1px solid var(--border);transition:transform .2s,box-shadow .2s;animation:fadeUp .4s both;min-width:0;}
.product-card:hover{transform:translateY(-4px);box-shadow:0 10px 22px var(--shadow);}
.product-card-img{width:100%;height:9.5rem;background:var(--cream-deep);display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;}
.product-card-img img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .35s;}
.product-card:hover .product-card-img img{transform:scale(1.06);}
.card-heart{position:absolute;top:.6rem;right:.6rem;width:1.9rem;height:1.9rem;background:var(--surface);border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,.15);cursor:pointer;z-index:2;opacity:0;transform:scale(.75);transition:.2s;}
.card-heart.liked{opacity:1!important;transform:scale(1)!important;}
.product-card:hover .card-heart{opacity:1;transform:scale(1);}
.card-heart svg{width:1rem;height:1rem;fill:none;stroke:var(--muted);stroke-width:2;}
.card-heart.liked svg{fill:var(--heart);stroke:var(--heart);animation:pop .3s ease;}
.category-tag{position:absolute;top:.6rem;left:.6rem;background:rgba(59,36,23,.75);color:#fff;font-size:.6rem;font-weight:700;padding:.2rem .55rem;border-radius:1rem;text-transform:uppercase;letter-spacing:.03em;z-index:2;}
.product-info{padding:.75rem .85rem .85rem;}
.product-name-row{display:flex;justify-content:space-between;align-items:flex-start;gap:.5rem;margin-bottom:.25rem;}
.product-name{font-size:.86rem;font-weight:700;color:var(--choc-dark);line-height:1.3;}
.product-price{font-size:.86rem;font-weight:800;color:var(--caramel-deep);white-space:nowrap;}
.stock-low{font-size:.7rem;color:#c0392b;font-weight:700;margin-top:.3rem;}
.card-add-btn{width:100%;margin-top:.6rem;padding:.55rem;border-radius:.55rem;background:var(--choc-dark);color:#fff;font-size:.76rem;font-weight:700;cursor:pointer;transition:background .2s,transform .15s;}
.card-add-btn:hover{background:var(--caramel-deep);transform:translateY(-1px);}
.card-add-btn:active{transform:translateY(0);}
.card-add-btn.just-added{background:#3E8E52;}
.card-add-btn.at-max{background:#8a6d58;}
.card-add-btn:disabled{background:#8d8d8d;cursor:not-allowed;transform:none;}

/* Zero stock: whole card greyed out, with an "Out of Stock" label on the photo */
.product-card.out-of-stock{filter:grayscale(1);opacity:.72;}
.product-card.out-of-stock:hover{transform:none;box-shadow:0 2px 10px var(--shadow);}
.product-card.out-of-stock:hover .product-card-img img{transform:none;}
.oos-badge{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);z-index:3;white-space:nowrap;background:rgba(59,36,23,.9);color:#fff;font-size:.78rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;padding:.45rem .95rem;border-radius:2rem;}

/* All / Recommended switch */
.view-tabs{display:flex;gap:.5rem;}
.view-tab{padding:.5rem 1.3rem;border-radius:2rem;font-size:.8rem;font-weight:700;cursor:pointer;background:var(--surface);color:var(--choc);border:1.5px solid var(--border);transition:.15s;user-select:none;}
.view-tab:hover{background:var(--cream-deep);}
.view-tab.active{background:var(--choc-dark);color:#fff;border-color:var(--choc-dark);}

.no-results{grid-column:1/-1;text-align:center;padding:3rem 1.5rem;color:var(--muted);}
.no-results svg{width:3rem;height:3rem;margin-bottom:.8rem;opacity:.4;}
.no-results p{font-size:.88rem;}

footer{background:var(--surface);border-top:1px solid var(--border);padding:1.5rem 6%;margin-top:1rem;display:flex;flex-direction:column;align-items:center;gap:.7rem;}
.footer-top{width:100%;display:flex;align-items:center;justify-content:center;}
.footer-links{display:flex;flex-wrap:wrap;justify-content:center;align-items:center;gap:.4rem 0;}
.footer-links a{font-size:.8rem;font-weight:600;color:var(--choc);cursor:pointer;}
.footer-links .sep{font-size:.8rem;color:var(--border);margin:0 .9rem;}
.footer-copy{font-size:.72rem;color:var(--muted);}

/* Phones/tablets have no hover, so show the heart all the time */
@media (hover:none){ .card-heart{opacity:1;transform:scale(1);} }

/* ── Tablet & below: filter becomes a collapsible bar on top ── */
@media (max-width:900px){
    .shop-layout{flex-direction:column;gap:1rem;padding:1.2rem 4% 2.5rem;}
    .sidebar{width:100%;min-width:0;position:static;}
    .filter-box-head{cursor:pointer;margin-bottom:0;padding-bottom:0;border-bottom:none;}
    .filter-chevron{display:block;}
    .filter-box-head.open{margin-bottom:.8rem;padding-bottom:.6rem;border-bottom:1px solid var(--border);}
    .filter-box-head.open .filter-chevron{transform:rotate(180deg);}
    .filter-body{max-height:0;transition:max-height .3s ease;}
    .filter-body.open{max-height:40rem;}
}
@media (min-width:761px) and (max-width:1100px){
    .products-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:.9rem;}
}

/* ── Phones ── */
@media (max-width:760px){
    .hero{padding:1.2rem 5%;}
    .hero-img{display:none;}
    .hero-text .eyebrow{font-size:.64rem;margin-bottom:.3rem;}
    .hero-text h1{font-size:1.5rem;}
    .hero-text p{font-size:.8rem;margin-top:.4rem;}

    .shop-layout{padding:1rem 4% 2rem;gap:.8rem;}
    .filter-box{padding:.85rem 1rem;}
    .search-bar-row{gap:.6rem;}
    .search-wrap{padding:.5rem .8rem;}
    .search-wrap input{font-size:16px;}           /* 16px stops iOS zooming in on focus */
    .btn-view-cart{display:none;}                  /* the nav already has a Cart button */

    .products-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:.6rem;}
    .product-card{border-radius:.75rem;}
    .product-card-img{height:auto;aspect-ratio:4/3;}
    .category-tag{top:.45rem;left:.45rem;font-size:.55rem;padding:.18rem .45rem;}
    .card-heart{top:.45rem;right:.45rem;width:1.75rem;height:1.75rem;}
    .product-info{padding:.6rem .65rem .7rem;}
    .product-name-row{flex-direction:column;gap:.1rem;margin-bottom:.2rem;}
    .product-name{font-size:.78rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
    .product-price{font-size:.82rem;}
        .stock-low{font-size:.64rem;}
    .card-add-btn{margin-top:.5rem;padding:.5rem;font-size:.74rem;}

    /* cart becomes a bottom sheet */
    .cart-drawer{width:100%;max-width:100%;top:auto;border-radius:1rem 1rem 0 0;height:min(85vh,640px);height:min(85dvh,640px);transform:translateY(100%);}
    .cart-drawer.open{transform:translateY(0);}
    .cart-header{padding:.9rem 1rem;}
    .cart-items{padding:.75rem;}
    .cart-footer{padding:.85rem 1rem 1rem;}
    .cart-item{padding:.6rem;}
    .cart-item-img{width:3.2rem;height:3.2rem;}
    .qty-btn{width:1.9rem;height:1.9rem;}
    .qty-input{height:1.9rem;font-size:16px;}

    .checkout-overlay{backdrop-filter:blur(2px);}
    .proceed-checkout{width:calc(100vw - 1rem);max-width:none;max-height:calc(100vh - 1rem);max-height:calc(100dvh - 1rem);padding:1.1rem;border-radius:1rem;}
    .checkout-field input,.checkout-field select,.pickup-field input{min-height:2.6rem;font-size:16px;}
    .place-order-btn{min-height:2.8rem;padding:.75rem;}
    .checkout-tab{font-size:.76rem;padding:.6rem .4rem;}

    footer{padding:1.2rem 5%;}
    .footer-links .sep{margin:0 .6rem;}
}
@media (max-width:360px){
    .hero-text h1{font-size:1.35rem;}
        .product-name{font-size:.74rem;}
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
        <div class="checkout-tab active" id="tab-order"   onclick="setMode('order')">Place Order</div>
        <div class="checkout-tab"        id="tab-reserve" onclick="setMode('reserve')">Reserve Pickup</div>
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

    <div class="checkout-total-box">
        <div class="checkout-total-row" style="font-size:1.05rem;font-weight:800;color:var(--caramel-deep);"><span>Total</span><span id="checkoutTotalAmt">₱0</span></div>
    </div>
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
        <div class="cart-empty" id="cartEmpty">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="var(--border)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
            <p>Your cart is empty</p>
        </div>
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

<section class="hero">
    <div class="hero-text">
        <p class="eyebrow">Liberty Foodmart</p>
        <h1>Baked fresh.<br>Ordered easy.</h1>
        <p>Browse our cakes, breads, and pastries — made fresh daily and ready for delivery or pickup.</p>
    </div>
    <div class="hero-img"><img src="src/land-choc.png" alt="Featured bake"></div>
</section>

<div class="shop-layout" id="productsTop">
    <aside class="sidebar">
        <div class="filter-box">
            <div class="filter-box-head" id="filterToggle" onclick="toggleFilters()">
                <div class="filter-head-left">
                    <h3>Filter by</h3>
                    <span class="filter-count-badge" id="filterCountBadge" style="display:none;"></span>
                </div>
                <div class="filter-head-right">
                    <span class="clear-filters" onclick="event.stopPropagation();clearFilters()">Clear all</span>
                    <svg class="filter-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
            </div>

            <div class="filter-body" id="filterBody">
                <div class="filter-group">
                    <h4>Category</h4>
                    <?php foreach ($allowed_cats as $c): ?>
                    <label><input type="checkbox" class="filter-cat" value="<?= e($c) ?>" onchange="applyFilters()"> <?= e($c) ?></label>
                    <?php endforeach; ?>
                </div>

                <div class="filter-group">
                    <h4>Price Range</h4>
                    <label><input type="checkbox" class="filter-price" data-min="0"    data-max="999"    onchange="applyFilters()"> Under ₱1,000</label>
                    <label><input type="checkbox" class="filter-price" data-min="1000" data-max="2999"   onchange="applyFilters()"> ₱1,000 – ₱3,000</label>
                    <label><input type="checkbox" class="filter-price" data-min="3000" data-max="5999"   onchange="applyFilters()"> ₱3,000 – ₱6,000</label>
                    <label><input type="checkbox" class="filter-price" data-min="6000" data-max="999999" onchange="applyFilters()"> Over ₱6,000</label>
                </div>

                <div class="filter-group" style="margin-bottom:0;">
                    <h4>Sort By</h4>
                    <select class="sort-select" id="sortSelect" onchange="applyFilters()">
                        <option value="name-asc">Name (A–Z)</option>
                        <option value="price-asc">Price: Low to High</option>
                        <option value="price-desc">Price: High to Low</option>
                    </select>
                </div>
            </div>
        </div>
    </aside>

    <div class="products-area">
        <div class="search-bar-row">
            <div class="search-wrap">
                <span>🔍</span>
                <input type="text" placeholder="Search products..." id="searchInput" oninput="applyFilters()">
            </div>
            <div class="btn-view-cart" onclick="openCart()">My Cart</div>
        </div>

        <div class="view-tabs">
            <div class="view-tab active" id="viewTab-all" onclick="setView('all')">All</div>
            <div class="view-tab" id="viewTab-recommended" onclick="setView('recommended')">Recommended</div>
        </div>

        <div class="active-chips" id="activeChips"></div>
        <p class="result-meta" id="resultMeta"></p>

        <div class="products-grid" id="productsGrid">
            <?php if (empty($products_raw)): ?>
                <div class="no-results"><p>No products available yet.</p></div>
            <?php else: $i = 0; foreach ($products_raw as $p):
                $isOut = (int)$p['quantity'] <= 0;
                $isLow = !$isOut && $p['quantity'] <= $p['low_stock_threshold'];
                $rank  = $best_rank[(int)$p['id']] ?? '';
                $img   = ($p['image_path'] && file_exists($p['image_path'])) ? $p['image_path'] : 'src/black-forestv2.webp';
                $i++;
            ?>
            <div class="product-card<?= $isOut ? ' out-of-stock' : '' ?>"
                 style="animation-delay:<?= min($i * 0.04, 0.4) ?>s"
                 data-id="<?= $p['id'] ?>"
                 data-name="<?= e($p['name']) ?>"
                 data-price="<?= $p['price'] ?>"
                 data-image="<?= e($img) ?>"
                 data-rank="<?= $rank ?>"
                 data-category="<?= e($p['category']) ?>"
                 data-stock="<?= $p['quantity'] ?>"
                 onclick="goToProductPage(this)">
                <div class="product-card-img">
                    <span class="category-tag"><?= e($p['category']) ?></span>
                    <?php if ($isOut): ?><span class="oos-badge">Out of Stock</span><?php endif; ?>
                    <img src="<?= e($img) ?>" alt="<?= e($p['name']) ?>">
                    <div class="card-heart" onclick="toggleHeart(event,this)">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                    </div>
                </div>
                <div class="product-info">
                    <div class="product-name-row"><span class="product-name"><?= e($p['name']) ?></span><span class="product-price">₱<?= number_format($p['price'], 0) ?></span></div>
                    <?php if ($isLow): ?><p class="stock-low">⚠ Only <?= $p['quantity'] ?> left!</p><?php endif; ?>
                    <?php if ($isOut): ?>
                        <button type="button" class="card-add-btn" disabled onclick="event.stopPropagation()">Out of Stock</button>
                    <?php else: ?>
                        <button type="button" class="card-add-btn" onclick="event.stopPropagation();addFromCard(this)">+ Add to Cart</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
        <div class="no-results" id="noResults" style="display:none;">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <p>No products match your filters.</p>
        </div>
    </div>
</div>

<footer>
    <div class="footer-top">
        <div class="footer-links"><a href="#">About Us</a><span class="sep">|</span><a href="#">Contact</a><span class="sep">|</span><a href="#">Terms and Conditions</a></div>
    </div>
    <p class="footer-copy">© 2026 Liberty Foodmart | All Rights Reserved</p>
</footer>

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

// ── Cart persistence ─────────────────────────────────────────
// Cart lives in sessionStorage so it survives navigating to a
// product's detail page and back (a plain JS variable would reset
// to empty on every page load).
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
let viewMode = 'all';   // 'all' | 'recommended' (top 10 best sellers)
function fmt(n){ return '₱' + Number(n).toLocaleString('en-PH'); }
function getTotal(){ return Object.values(cart).reduce((s,i)=>s+i.price*i.qty,0); }
function getCount(){ return Object.values(cart).reduce((s,i)=>s+i.qty,0); }

function updateCheckoutSummary(){
    document.getElementById('checkoutTotalAmt').textContent = fmt(getTotal());
}

function renderCart(){
    const list=document.getElementById('cartItemsList'),empty=document.getElementById('cartEmpty'),footer=document.getElementById('cartFooter');
    list.querySelectorAll('.cart-item').forEach(el=>el.remove());
    const keys=Object.keys(cart);
    empty.style.display=keys.length?'none':'flex';
    footer.style.display=keys.length?'flex':'none';
    keys.forEach(id=>{
        const item=cart[id],row=document.createElement('div');
        row.className='cart-item';
        row.innerHTML=`<div class="cart-item-img"><img src="${item.image||'src/black-forestv2.webp'}" alt="${item.name}"></div><div class="cart-item-info"><div class="cart-item-name">${item.name}</div><div class="cart-item-price">${fmt(item.price)}</div><div class="cart-item-qty"><div class="qty-btn" onclick="changeQty('${id}',-1)">−</div><input type="number" class="qty-input" inputmode="numeric" min="1" max="${item.stock||''}" value="${item.qty}" aria-label="Quantity" onfocus="this.select()" onkeydown="if(event.key==='Enter')this.blur()" onchange="setQty('${id}',this.value)"><div class="qty-btn" onclick="changeQty('${id}',1)">+</div><div class="cart-item-delete" onclick="removeFromCart('${id}')"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg></div></div></div>`;
        list.appendChild(row);
    });
    const total=getTotal(),count=getCount();
    document.getElementById('cartSubtotal').textContent=fmt(total);
    document.getElementById('cartTotal').textContent=fmt(total);
    document.getElementById('cartCountBadge').textContent=count+' item'+(count!==1?'s':'');
    const nb=document.getElementById('navCartBadge');
    nb.textContent=count; nb.classList.toggle('visible',count>0);
    updateCheckoutSummary();
    syncCardButtons();
    saveCartStorage();
}

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

// Returns true only if something was actually added to the cart
function addToCart(id,name,price,image,stock){
    stock=parseInt(stock)||0;
    if(stock<=0){showError('Out of stock',name+' is out of stock.');return false;}
    if(cart[id]){
        if(cart[id].qty>=stock){showStockLimit(name,stock);return false;}
        cart[id].qty++;
    }else{
        cart[id]={name,price:parseFloat(price),qty:1,image,stock};
    }
    renderCart();
    return true;
}

// Keeps each card's button honest: once the whole stock is in the cart it says so
function syncCardButtons(){
    document.querySelectorAll('.product-card').forEach(card=>{
        const btn=card.querySelector('.card-add-btn');
        if(!btn||btn.disabled||btn.classList.contains('just-added'))return;
        const id=card.dataset.id,stock=parseInt(card.dataset.stock)||0;
        const atMax=stock>0&&cart[id]&&cart[id].qty>=stock;
        btn.textContent=atMax?'Max in cart':'+ Add to Cart';
        btn.classList.toggle('at-max',!!atMax);
    });
}

function addFromCard(btn){
    const card=btn.closest('.product-card');
    const d=card.dataset;
    // Nothing was added (stock limit) → no green "Added ✓", the modal already explained why
    if(!addToCart(d.id,d.name,d.price,d.image,d.stock))return;
    btn.textContent='Added ✓';
    btn.classList.add('just-added');
    setTimeout(()=>{btn.classList.remove('just-added');syncCardButtons();},1000);
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
    if(!IS_LOGGED_IN){window.location.href='customer-login.php?redirect=ordering-page.php';return;}
    closeCart();
    const ph=document.getElementById('checkoutPhone'); ph.value=formatPhone(ph.value);
    document.getElementById('checkoutPanel').style.display='flex';
    document.getElementById('checkoutOverlay').style.display='block';
    updateCheckoutSummary();
}
function hideCheck(){document.getElementById('checkoutPanel').style.display='none';document.getElementById('checkoutOverlay').style.display='none';}

function setMode(mode){
    checkoutMode=mode;
    document.getElementById('tab-order').classList.toggle('active',mode==='order');
    document.getElementById('tab-reserve').classList.toggle('active',mode==='reserve');
    document.getElementById('pickupField').classList.toggle('show',mode==='reserve');
    const btn=document.getElementById('placeOrderBtn');
    if(mode==='reserve'){btn.textContent='Reserve Order';btn.classList.add('reserve-mode');}
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

    const fd=new FormData();
    fd.append('action',checkoutMode);
    fd.append('customer_name',name);
    fd.append('phone',phone);
    fd.append('location',location);
    fd.append('payment_method',payment);
    fd.append('items',JSON.stringify(items));
    fd.append('pickup_datetime',pickup);

    fetch('ordering-page.php',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(data=>{
            if(!data.ok){
                if(data.auth_required){window.location.href='customer-login.php?redirect=ordering-page.php';return;}
                showError('Could not complete your request',data.error||'Unknown error.');return;
            }
            clearCart();hideCheck();
            if(data.type==='order') window.location.href='order-confirmation.php?order_id='+data.order_id;
            else window.location.href='order-confirmation.php?reservation_id='+data.reservation_id;
        })
        .catch(()=>showError('Request failed','Please check your connection and try again.'));
}

function goToProductPage(card){
    const p=new URLSearchParams({id:card.dataset.id,name:card.dataset.name,price:card.dataset.price,image:card.dataset.image,category:card.dataset.category,stock:card.dataset.stock});
    window.location.href='ordering-process.php?'+p.toString();
}

function setView(mode){
    viewMode = mode;
    document.getElementById('viewTab-all').classList.toggle('active', mode==='all');
    document.getElementById('viewTab-recommended').classList.toggle('active', mode==='recommended');
    applyFilters();
}

function toggleHeart(event,el){event.stopPropagation();el.classList.toggle('liked');}

function toggleFilters(){
    document.getElementById('filterBody').classList.toggle('open');
    document.getElementById('filterToggle').classList.toggle('open');
}

// ── Filters (real, working) ──────────────────────────────────
// Category + Price Range are actual product fields, so these
// checkboxes genuinely filter the grid. Sort re-orders it live.
const priceLabels = { '0-999':'Under ₱1,000', '1000-2999':'₱1,000 – ₱3,000', '3000-5999':'₱3,000 – ₱6,000', '6000-999999':'Over ₱6,000' };

function applyFilters(){
    const search = document.getElementById('searchInput').value.toLowerCase().trim();
    const catBoxes   = [...document.querySelectorAll('.filter-cat:checked')];
    const priceBoxes = [...document.querySelectorAll('.filter-price:checked')];
    const cats   = catBoxes.map(cb => cb.value);
    const ranges = priceBoxes.map(cb => ({min:+cb.dataset.min, max:+cb.dataset.max}));
    const sortVal = document.getElementById('sortSelect').value;

    const grid = document.getElementById('productsGrid');
    const cards = [...grid.querySelectorAll('.product-card')];
    let visible = 0;

    cards.forEach(card => {
        const name = (card.dataset.name || '').toLowerCase();
        const cat  = card.dataset.category;
        const price = parseFloat(card.dataset.price);

        const matchSearch = !search || name.includes(search);
        const matchCat   = cats.length === 0 || cats.includes(cat);
        const matchPrice = ranges.length === 0 || ranges.some(r => price >= r.min && price <= r.max);
        const matchView  = viewMode === 'all' || card.dataset.rank !== '';

        const show = matchSearch && matchCat && matchPrice && matchView;
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    // Sort visible + hidden together so order stays correct if filters change
    cards.sort((a,b) => {
        // Recommended always lists the best seller first
        if (viewMode === 'recommended') return parseInt(a.dataset.rank) - parseInt(b.dataset.rank);
        if (sortVal === 'price-asc')  return parseFloat(a.dataset.price) - parseFloat(b.dataset.price);
        if (sortVal === 'price-desc') return parseFloat(b.dataset.price) - parseFloat(a.dataset.price);
        return a.dataset.name.localeCompare(b.dataset.name);
    });
    cards.forEach(c => grid.appendChild(c));

    document.getElementById('noResults').style.display = visible === 0 ? 'flex' : 'none';
    document.querySelector('#noResults p').textContent =
        (viewMode === 'recommended' && !search && !cats.length && !ranges.length)
            ? 'No best sellers yet. They will show up here once orders come in.'
            : 'No products match your filters.';
    document.getElementById('resultMeta').textContent = visible + ' product' + (visible !== 1 ? 's' : '') + ' found';

    // Active filter count badge (shown next to "Filter by" on mobile)
    const activeCount = catBoxes.length + priceBoxes.length;
    const badge = document.getElementById('filterCountBadge');
    badge.textContent = activeCount;
    badge.style.display = activeCount > 0 ? 'inline-block' : 'none';

    // Active filter chips
    const chipsWrap = document.getElementById('activeChips');
    chipsWrap.innerHTML = '';
    catBoxes.forEach(cb => addChip(chipsWrap, cb.value, () => { cb.checked = false; applyFilters(); }));
    priceBoxes.forEach(cb => addChip(chipsWrap, priceLabels[cb.dataset.min+'-'+cb.dataset.max] || 'Price', () => { cb.checked = false; applyFilters(); }));
    if (search) addChip(chipsWrap, '"' + document.getElementById('searchInput').value + '"', () => { document.getElementById('searchInput').value=''; applyFilters(); });
}

function addChip(wrap, label, onRemove){
    const chip = document.createElement('div');
    chip.className = 'chip';
    chip.innerHTML = '<span>' + label + '</span><span class="x">✕</span>';
    chip.querySelector('.x').onclick = onRemove;
    wrap.appendChild(chip);
}

function clearFilters(){
    document.querySelectorAll('.filter-cat, .filter-price').forEach(cb => cb.checked = false);
    document.getElementById('searchInput').value = '';
    document.getElementById('sortSelect').value = 'name-asc';
    applyFilters();
}

// Reflect any cart restored from a previous page, and run filters once
// so the result count / sort order is correct on first paint.
renderCart();
applyFilters();

// Sent here from another page's Cart button (?cart=1) -> open the cart straight away
if (new URLSearchParams(location.search).has('cart')) {
    openCart();
    history.replaceState(null, '', 'ordering-page.php');
}
</script>
</body>
</html>