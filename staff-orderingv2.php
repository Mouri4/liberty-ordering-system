<?php
// ── Liberty Foodmart — Staff POS / Create Orders ─────────────
require_once 'session.php';
require 'db.php';
require 'vat.php';
require_once 'reservation-stock.php';

require_once 'auth.php';
require_staff($pdo);

// ── Helpers for reservations ─────────────────────────────────
/**
 * Re-price a cart from the database. The browser only tells us WHICH
 * products and HOW MANY; names and prices always come from the products
 * table, so a tampered request can't change what something costs.
 */
function reprice_cart_from_db(PDO $pdo, $raw_items): array {
    $items = [];
    if (!is_array($raw_items)) return $items;

    $lookup = $pdo->prepare('SELECT id, name, price FROM products WHERE id = ?');
    foreach ($raw_items as $raw) {
        if (!is_array($raw)) continue;
        $pid = (int)($raw['product_id'] ?? 0);
        $qty = (int)($raw['qty'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;

        $lookup->execute([$pid]);
        $product = $lookup->fetch();
        if (!$product) continue;

        $items[] = [
            'product_id' => (int)$product['id'],
            'name'       => $product['name'],
            'unit_price' => (float)$product['price'],
            'qty'        => $qty,
        ];
    }
    return $items;
}

/** Trim to at most $max characters (UTF-8 safe, no mbstring extension needed). */
function clip_text(string $s, int $max): string {
    return preg_match('/^.{0,' . $max . '}/us', $s, $m) ? $m[0] : substr($s, 0, $max);
}

/** '2026-10-21T14:30' (datetime-local) -> '2026-10-21 14:30:00', or null if unreadable. */
function normalize_pickup(string $raw): ?string {
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

// ── Handle AJAX POSTs ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // ── Record walk-in order ─────────────────────────────────
    if ($_POST['action'] === 'record_order') {
        $raw_items      = json_decode($_POST['items'] ?? '[]', true);
        $payment_method = $_POST['payment_method'] ?? 'Cash';
        $received       = (float)($_POST['received'] ?? 0);
        $is_senior_pwd  = ($_POST['is_senior_pwd'] ?? '0') === '1';

        if (empty($raw_items)) {
            echo json_encode(['ok' => false, 'error' => 'No items.']);
            exit;
        }

        $allowed_pay = ['Cash', 'GCash', 'Card'];
        if (!in_array($payment_method, $allowed_pay)) $payment_method = 'Cash';

        // ── Re-price every line from the DB ──────────────────
        // Same protection as the online checkout: prices are looked up
        // fresh rather than trusted from the client, and it's also what
        // makes the discount math below actually correct.
        $items = [];
        $price_lookup = $pdo->prepare('SELECT id, name, price FROM products WHERE id = ?');
        foreach ($raw_items as $raw) {
            $pid = (int)($raw['product_id'] ?? 0);
            $qty = (int)($raw['qty'] ?? 0);
            if ($pid <= 0 || $qty <= 0) continue;

            $price_lookup->execute([$pid]);
            $product = $price_lookup->fetch();
            if (!$product) continue;

            $items[] = [
                'product_id' => (int)$product['id'],
                'name'       => $product['name'],
                'unit_price' => (float)$product['price'],
                'qty'        => $qty,
            ];
        }

        if (empty($items)) {
            echo json_encode(['ok' => false, 'error' => 'No valid items.']);
            exit;
        }

        // Staff has already checked the physical Senior/PWD ID before
        // ticking the box at the register, so this goes straight to
        // 'approved' — no online review step like the customer flow.
        $rates            = get_vat_settings($pdo);
        $priced           = price_order($items, $is_senior_pwd, $rates);
        $total            = $priced['total'];
        $discount_status  = $is_senior_pwd ? 'approved' : 'none';

        // One transaction: the order, its items and the stock deduction all
        // succeed together or not at all. If any item is short on stock the
        // whole sale is cancelled, instead of being recorded as paid with
        // nothing taken out of inventory.
        try {
            $pdo->beginTransaction();

            // Insert order — walk_in type, paid immediately
            $pdo->prepare("
                INSERT INTO orders
                    (customer_name, phone, location, order_type, order_status, payment_method, payment_status,
                     subtotal, discount_amount, total, net_amount, vat_amount, is_senior_pwd, discount_status)
                VALUES ('Walk-in Customer', 'N/A', 'Walk-in', 'walk_in', 'Done', ?, 'PAID', ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $payment_method,
                $priced['subtotal'], $priced['discount_amount'], $total, $priced['net_amount'], $priced['vat_amount'],
                $is_senior_pwd ? 1 : 0, $discount_status,
            ]);

            $order_id = $pdo->lastInsertId();

            // Insert order items + deduct stock
            $ins    = $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, discount_amount, line_total) VALUES (?,?,?,?,?,?,?)');
            $deduct = $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ? AND quantity >= ?');

            foreach ($priced['lines'] as $line) {
                $deduct->execute([$line['qty'], $line['product_id'], $line['qty']]);
                // 0 rows = "AND quantity >= ?" failed = not enough stock left
                if ($deduct->rowCount() === 0) {
                    throw new StockException($line['name'] . ' does not have enough stock left. Please refresh the page and check the quantities.');
                }
                $ins->execute([$order_id, $line['product_id'], $line['name'], $line['unit_price'], $line['qty'], $line['discount_amount'], $line['line_total']]);
            }

            $pdo->commit();
        } catch (StockException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('record_order failed: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Could not record the order. Please try again.']);
            exit;
        }

        echo json_encode(['ok' => true, 'order_id' => $order_id]);
        exit;
    }

    // ── Save reservation ─────────────────────────────────────
    if ($_POST['action'] === 'save_reservation') {
        $raw_items      = json_decode($_POST['items'] ?? '[]', true);
        $customer_name  = clip_text(trim($_POST['customer_name'] ?? ''), 120);
        $contact        = clip_text(trim($_POST['contact']       ?? ''), 20);
        $payment_status = $_POST['payment_status'] ?? 'UNPAID';
        // Staff ticks this only after checking the physical ID in person
        $is_senior_pwd  = ($_POST['is_senior_pwd'] ?? '0') === '1';

        if (empty($raw_items) || !$customer_name || !$contact) {
            echo json_encode(['ok' => false, 'error' => 'Missing required fields.']);
            exit;
        }

        // Contact must be a PH mobile number (09XXXXXXXXX), saved as 09X-XXXX-XXXX
        $contact = normalize_ph_mobile($contact);
        if (!$contact) {
            echo json_encode(['ok' => false, 'error' => 'Please enter a valid contact number, e.g. 09171234567 (11 digits).']);
            exit;
        }

        // Pickup must be readable and not in the past
        [$pickup, $pickup_err] = reservation_pickup($_POST['pickup_datetime'] ?? '');
        if ($pickup_err) {
            echo json_encode(['ok' => false, 'error' => $pickup_err]);
            exit;
        }

        if (!in_array($payment_status, ['PAID', 'UNPAID'], true)) $payment_status = 'UNPAID';

        // Names and prices come from the DB, never from the browser
        $items = reprice_cart_from_db($pdo, $raw_items);
        if (empty($items)) {
            echo json_encode(['ok' => false, 'error' => 'No valid items.']);
            exit;
        }

        $priced  = price_order($items, $is_senior_pwd, get_vat_settings($pdo));
        $qty     = array_sum(array_column($items, 'qty'));
        $first   = $items[0]['name'];
        $summary = count($items) > 1 ? $first . ' (+' . (count($items) - 1) . ' more)' : $first;

        try {
            $pdo->beginTransaction();

            $pdo->prepare("
                INSERT INTO reservations
                    (customer_name, contact, product_id, product_name, quantity,
                     subtotal, discount_amount, total_price, net_amount, vat_amount, is_senior_pwd,
                     payment_method, payment_status, pickup_datetime, status, stock_deducted)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Cash', ?, ?, 'Active', 1)
            ")->execute([
                $customer_name, $contact, $items[0]['product_id'], $summary, $qty,
                $priced['subtotal'], $priced['discount_amount'], $priced['total'],
                $priced['net_amount'], $priced['vat_amount'], $is_senior_pwd ? 1 : 0,
                $payment_status, $pickup,
            ]);
            $res_id = $pdo->lastInsertId();

            // Full per-product breakdown so the reservation list can show every item
            $ins_item = $pdo->prepare('INSERT INTO reservation_items (reservation_id, product_id, product_name, unit_price, quantity) VALUES (?,?,?,?,?)');
            foreach ($items as $line) {
                $ins_item->execute([$res_id, $line['product_id'], $line['name'], $line['unit_price'], $line['qty']]);
            }

            // Hold the stock. If any product is short this throws, and the
            // rollback below removes the reservation too (nothing half-saved).
            reservation_deduct_stock($pdo, $items);

            $pdo->commit();
        } catch (StockException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('save_reservation failed: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Could not save the reservation. Please try again.']);
            exit;
        }

        echo json_encode(['ok' => true, 'reservation_id' => $res_id]);
        exit;
    }

    // ── Update existing reservation (edit mode) ──────────────
    if ($_POST['action'] === 'update_reservation') {
        $res_id        = (int)($_POST['reservation_id'] ?? 0);
        $raw_items     = json_decode($_POST['items'] ?? '[]', true);
        $is_senior_pwd = ($_POST['is_senior_pwd'] ?? '0') === '1';

        if (!$res_id || empty($raw_items)) {
            echo json_encode(['ok' => false, 'error' => 'Missing data.']);
            exit;
        }

        $items = reprice_cart_from_db($pdo, $raw_items);
        if (empty($items)) {
            echo json_encode(['ok' => false, 'error' => 'No valid items.']);
            exit;
        }

        $priced  = price_order($items, $is_senior_pwd, get_vat_settings($pdo));
        $qty     = array_sum(array_column($items, 'qty'));
        $first   = $items[0]['name'];
        $summary = count($items) > 1 ? $first . ' (+' . (count($items) - 1) . ' more)' : $first;

        try {
            $pdo->beginTransaction();

            // Lock the row so "Mark Complete" in another tab can't slip in mid-edit.
            $existing = reservation_lock($pdo, $res_id);
            if (!$existing) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => 'Reservation not found.']);
                exit;
            }
            // Only reservations still being processed can be edited. One that is
            // Ready for Pickup has already been prepared (the customer was told),
            // and a completed one is already counted in sales.
            if ($existing['status'] !== 'Active') {
                $pdo->rollBack();
                $why = $existing['status'] === 'Ready for Pickup'
                    ? 'This reservation is already marked Ready for Pickup. Set it back to Processing in the Reservation List first, then edit it.'
                    : 'Only reservations that are still being processed can be edited.';
                echo json_encode(['ok' => false, 'error' => $why]);
                exit;
            }

            // Give the old items back first, then take the new ones below.
            if ((int)$existing['stock_deducted'] === 1) {
                reservation_restore_stock($pdo, $res_id);
            }

            // Totals, VAT and discount are rewritten together so they can
            // never drift apart from the item list.
            $pdo->prepare("
                UPDATE reservations
                   SET product_id = ?, product_name = ?, quantity = ?,
                       subtotal = ?, discount_amount = ?, total_price = ?,
                       net_amount = ?, vat_amount = ?, is_senior_pwd = ?,
                       stock_deducted = 1
                 WHERE id = ?
            ")->execute([
                $items[0]['product_id'], $summary, $qty,
                $priced['subtotal'], $priced['discount_amount'], $priced['total'],
                $priced['net_amount'], $priced['vat_amount'], $is_senior_pwd ? 1 : 0,
                $res_id,
            ]);

            // Replace the item breakdown to match the edited cart
            $pdo->prepare('DELETE FROM reservation_items WHERE reservation_id = ?')->execute([$res_id]);
            $ins_item = $pdo->prepare('INSERT INTO reservation_items (reservation_id, product_id, product_name, unit_price, quantity) VALUES (?,?,?,?,?)');
            foreach ($items as $line) {
                $ins_item->execute([$res_id, $line['product_id'], $line['name'], $line['unit_price'], $line['qty']]);
            }

            reservation_deduct_stock($pdo, $items);

            $pdo->commit();
        } catch (StockException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('update_reservation failed: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Could not update the reservation. Please try again.']);
            exit;
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// ── VAT / discount rates for the receipt preview ─────────────
// Same source of truth the server uses when it saves the order.
$page_rates = get_vat_settings($pdo);

// ── Load products from DB for the product grid ───────────────
$products = $pdo->query("SELECT * FROM products WHERE quantity > 0 ORDER BY category, name")->fetchAll();

// ── Edit reservation mode: ?editReservation=ID ───────────────
$edit_reservation = null;
if (!empty($_GET['editReservation'])) {
    $es = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
    $es->execute([(int)$_GET['editReservation']]);
    $edit_reservation = $es->fetch();

    // This reservation is already holding its items out of stock. While editing,
    // add them back to what the grid shows, otherwise a product that is now at 0
    // would vanish and the same reservation couldn't be rebuilt.
    if ($edit_reservation && (int)($edit_reservation['stock_deducted'] ?? 0) === 1) {
        $hs = $pdo->prepare('SELECT product_id, SUM(quantity) AS q FROM reservation_items WHERE reservation_id = ? AND product_id IS NOT NULL GROUP BY product_id');
        $hs->execute([(int)$edit_reservation['id']]);
        $held = [];
        foreach ($hs->fetchAll() as $h) $held[(int)$h['product_id']] = (int)$h['q'];

        $products = [];
        foreach ($pdo->query('SELECT * FROM products ORDER BY category, name')->fetchAll() as $row) {
            $row['quantity'] = (int)$row['quantity'] + ($held[(int)$row['id']] ?? 0);
            if ($row['quantity'] > 0) $products[] = $row;
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
    <title>Create Orders — Liberty Foodmart</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ── Bakery palette (shared with login.php) ── */
        :root {
            --choc-dark:#3B2417;
            --choc:#5C3A24;
            --cream:#FBF3E7;
            --cream-deep:#F1E4D3;
            --caramel:#C17817;
            --caramel-deep:#8B4513;
            --gold:#D4A24C;
            --muted:#A98A6D;
            --surface:#FFFFFF;
            --border:#E8D5B7;
        }

        @font-face { src:url(src/Kavoon-Regular.ttf); font-family:kavoon; }

        *, p, pre, h2 { margin:0; box-sizing:border-box; }

        body {
            width:100%; height:100vh; overflow:hidden;
            background:var(--cream);
            font-family:'Inter', Arial, sans-serif;
            color:var(--choc-dark);
        }

        /* ── Header ── */
        .head-nav {
            height:52px; width:100%;
            background:var(--surface);
            border-bottom:1px solid var(--border);
            display:flex; align-items:center;
            box-shadow:0 2px 8px rgba(59,36,23,0.08);
        }
        .head-nav pre {
            font-family:kavoon; color:var(--caramel-deep);
            font-size:1.1rem; letter-spacing:.02em;
        }
        .menu-icon-cont { margin-left:2%; width:2%; height:60%; cursor:pointer; }
        .menu-icon-cont img { width:100%; height:100%; object-fit:cover; }

        .main { width:100%; height:calc(100vh - 52px); display:flex; }

        /* ── Left panel: cart ── */
        .main-calc {
            width:30%; height:100%; display:flex; flex-direction:column;
            border-right:1px solid var(--border);
            background:var(--surface);
        }

        .top {
            height:57%; width:100%; overflow-y:scroll;
            background:var(--cream);
        }
        .top::-webkit-scrollbar { width:0px; }

        .total-cont {
            width:100%; padding:12px 14px;
            background:var(--choc-dark);
            color:#fff;
        }
        .total-top {
            display:flex; justify-content:flex-end; align-items:center;
            gap:6px; font-size:1.15rem; font-weight:700;
        }
        .change-bot {
            display:flex; justify-content:flex-end; align-items:center;
            gap:6px; font-size:0.9rem; margin-top:4px;
            color:var(--gold);
        }
        .subtotal-row {
            display:flex; justify-content:flex-end; align-items:center;
            gap:6px; font-size:0.85rem; font-weight:600;
            color:#e0d4c4; margin-bottom:2px;
        }
        .discount-line {
            display:flex; justify-content:flex-end; align-items:center;
            gap:6px; font-size:0.85rem; font-weight:600;
            color:#ffb74d; margin-bottom:4px;
        }

        .discount-toggle-row { margin-bottom:12px; }
        .discount-toggle-label {
            display:flex; align-items:center; gap:8px;
            font-size:12px; font-weight:700; color:var(--choc-dark);
            cursor:pointer;
        }
        .discount-toggle-label input {
            width:16px; height:16px; accent-color:var(--caramel-deep); flex-shrink:0;
        }

        .food-info {
            width:100%; padding:10px 14px;
            border-bottom:1px solid var(--border);
            background:var(--surface);
        }
        .info-top { display:flex; justify-content:space-between; font-weight:600; font-size:13px; color:var(--choc-dark); }
        .info-bot { display:flex; justify-content:space-between; margin-top:4px; }
        .edit-btns { display:flex; gap:5px; }
        .i-btn {
            border-radius:2rem; border:none; cursor:pointer; padding:2px 10px;
            font-size:11px; font-weight:600;
            background:var(--cream-deep); color:var(--choc);
            transition:background .15s;
        }
        .i-btn:hover { background:var(--border); }

        .bot {
            height:43%; width:100%; padding:14px;
            border-top:2px solid var(--border);
            background:var(--surface);
            display:flex; flex-direction:column;
        }
        .received-cont {
            display:flex; align-items:center; gap:8px;
            font-size:13px; font-weight:600; color:var(--choc);
        }
        .received-cont input {
            flex:1; padding:7px 10px;
            border:1.5px solid var(--border); border-radius:8px;
            font-size:13px; background:var(--cream);
            color:var(--choc-dark);
        }
        .received-cont input:focus { outline:none; border-color:var(--caramel); }

        .btns2 { display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; }

        .record-cont {
            padding:12px; border-radius:10px; flex:1; border:none;
            background:linear-gradient(135deg, var(--caramel-deep), var(--choc));
            color:#fff; display:flex; flex-direction:column;
            align-items:center; cursor:pointer; font-size:13px; font-weight:700;
            gap:6px; transition:transform .2s, box-shadow .2s;
            box-shadow:0 4px 14px rgba(139,69,19,0.3);
        }
        .record-cont:hover { transform:translateY(-2px); box-shadow:0 8px 20px rgba(139,69,19,0.4); }
        .record-img { height:36px; width:36px; }
        .record-img img { width:100%; height:100%; object-fit:cover; filter:brightness(0) invert(1); }

        .reserve-cont {
            padding:12px; border-radius:10px; flex:1;
            background:linear-gradient(135deg, var(--gold), var(--caramel));
            color:var(--choc-dark); display:flex; justify-content:center;
            align-items:center; cursor:pointer; font-weight:700; font-size:13px;
            text-align:center; transition:transform .2s, box-shadow .2s;
            box-shadow:0 4px 14px rgba(212,162,76,0.35);
        }
        .reserve-cont:hover { transform:translateY(-2px); box-shadow:0 8px 20px rgba(212,162,76,0.5); }

        /* ── Right panel: product grid ── */
        .main-goods {
            margin-left:10px; width:67%; height:100%;
            background:var(--cream); border-radius:1rem;
            overflow:hidden; border:1px solid var(--border);
        }

        .main-nav {
            height:50px; width:100%; display:flex; align-items:center;
            background:var(--choc-dark);
            padding:0 16px; gap:12px;
        }
        .main-search { width:100%; display:flex; align-items:center; gap:10px; }
        .main-title { font-weight:700; color:var(--gold); font-size:14px; letter-spacing:.03em; white-space:nowrap; }
        .search {
            height:32px; flex:1; max-width:260px;
            border:none; border-radius:2rem; padding:0 14px;
            font-size:13px; background:rgba(255,255,255,0.12);
            color:#fff; outline:none;
        }
        .search::placeholder { color:rgba(255,255,255,0.5); }
        .search:focus { background:rgba(255,255,255,0.2); }

        .goods-frame {
            height:calc(100% - 50px); width:100%;
            background:var(--cream-deep);
            overflow:hidden;
        }
        /* Grid instead of flexwrap — every card same size, no exceptions */
        .center {
            width:100%; height:100%;
            display:grid;
            grid-template-columns: repeat(3, 1fr);
            grid-auto-rows: 180px; /* fixed row height = consistent cards */
            gap:10px;
            overflow-y:scroll;
            padding:12px;
            align-content:flex-start;
        }
        .center::-webkit-scrollbar { width:0px; }

        /* Card: fixed height via grid-auto-rows, internal layout fixed too */
        .food-cont {
            height:100%; /* fills the grid cell exactly */
            background:var(--surface);
            border:1px solid var(--border); border-radius:14px;
            display:flex; flex-direction:column;
            padding:10px;
            font-family:'Inter',sans-serif;
            box-shadow:0 2px 10px rgba(59,36,23,0.08);
            transition:transform .2s, box-shadow .2s;
            overflow:hidden; /* clip anything that would overflow */
        }
        .food-cont:hover { transform:translateY(-3px); box-shadow:0 8px 20px rgba(59,36,23,0.14); }
        .food-cont.out-of-stock { opacity:0.45; pointer-events:none; }

        /* Top section takes all remaining space minus the fixed bottom bar */
        .food-cont-top {
            flex:1; min-height:0; /* let it shrink */
            width:100%; display:flex; gap:8px;
        }
        .img-cont {
            width:44%; flex-shrink:0;
            border-radius:10px; overflow:hidden;
            background:var(--cream-deep);
        }
        .img-cont img { width:100%; height:100%; object-fit:cover; display:block; }

        .food-cont-desc {
            flex:1; min-width:0;
            display:flex; flex-direction:column;
            overflow:hidden;
        }
        .productname {
            font-weight:700; font-size:.82rem; color:var(--choc-dark);
            /* clamp to 2 lines max so long names don't push layout */
            display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;
            overflow:hidden;
        }
        .productDesc {
            margin-top:3px; color:var(--muted); font-size:.72rem; line-height:1.35;
            display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;
            overflow:hidden;
        }
        .product-status {
            margin-top:auto; padding-top:4px;
            font-size:.72rem; color:var(--muted); white-space:nowrap;
        }
        .status-low { color:#c62828; font-weight:700; }

        /* Bottom bar: fixed height, never grows */
        .food-cont-bot {
            width:100%; flex-shrink:0;
            height:36px; margin-top:8px;
            display:flex; align-items:center;
            border-top:1px solid var(--border); padding-top:6px;
        }
        .food-cont-price {
            flex:1; font-size:1rem; font-weight:800;
            color:var(--caramel-deep); font-family:monospace;
            white-space:nowrap;
        }
        .food-cont-btn { display:flex; align-items:center; gap:5px; flex-shrink:0; }
        .food-cont-btn button {
            width:26px; height:26px; font-size:1.2rem; border-radius:50%;
            border:none; cursor:pointer;
            background:var(--cream-deep); color:var(--choc-dark);
            transition:background .15s; line-height:1;
        }
        .food-cont-btn button:hover { background:var(--border); }
        .add-btn { background:var(--caramel) !important; color:#fff !important; }
        .add-btn:hover { background:var(--caramel-deep) !important; }
        .food-cont-qty {
            font-family:monospace; font-weight:700; font-size:13px;
            min-width:18px; text-align:center;
        }

        /* ── Slide-in nav ── */
        .nav-cont {
            position:fixed; width:220px; height:100%; padding:68px 14px 20px;
            background:var(--surface); border-right:1px solid var(--border);
            display:flex; flex-direction:column; gap:4px;
            transform:translateX(-100%); transition:transform .3s ease; z-index:1000;
            box-shadow:4px 0 20px rgba(59,36,23,0.12);
        }
        .navs {
            display:flex; padding:11px 14px; width:100%;
            align-items:center; border-radius:10px; gap:12px;
            font-size:14px; font-weight:600; text-decoration:none; color:var(--choc);
            transition:background .15s;
        }
        .navs:hover { background:var(--cream); }
        .icon { width:22px; height:22px; flex-shrink:0; }
        .navs img { width:100%; height:100%; object-fit:cover; }
        .overlay {
            position:fixed; inset:0; background:rgba(59,36,23,0.35);
            z-index:900; opacity:0; visibility:hidden; transition:.3s;
        }
        .nav-cont.active { transform:translateX(0); }
        .overlay.active { opacity:1; visibility:visible; }

        /* ── Receipt modal ── */
        .receipt-overlay { position:fixed; inset:0; background:rgba(30,15,5,0.6); z-index:1100; opacity:0; visibility:hidden; transition:.3s; }
        .receipt-overlay.active { opacity:1; visibility:visible; }
        .receipt-modal {
            position:fixed; top:50%; left:50%; transform:translate(-50%,-60%);
            z-index:1200; width:380px; max-height:95vh;
            background:var(--surface); border-radius:16px;
            box-shadow:0 24px 64px rgba(30,15,5,0.4);
            display:flex; flex-direction:column;
            opacity:0; visibility:hidden;
            transition:transform .3s ease, opacity .3s ease; overflow:hidden;
            border-top:4px solid var(--caramel);
        }
        .receipt-modal.active { opacity:1; visibility:visible; transform:translate(-50%,-50%); }
        .receipt-actions-top { display:flex; justify-content:flex-end; padding:8px 14px; background:var(--cream); border-bottom:1px solid var(--border); }
        .receipt-close-btn { background:none; border:none; font-size:14px; cursor:pointer; color:var(--muted); font-weight:700; }
        .receipt-close-btn:hover { color:var(--choc-dark); }
        .receipt-paper { flex:1; overflow-y:auto; padding:16px 20px; font-family:'Courier New',monospace; font-size:13px; scrollbar-width:none; }
        .receipt-paper::-webkit-scrollbar { width:0; }
        .receipt-header { text-align:center; margin-bottom:6px; }
        .receipt-store { font-size:18px; font-weight:bold; letter-spacing:1px; color:var(--choc-dark); }
        .receipt-sub { font-size:11px; color:var(--muted); margin-top:2px; }
        .receipt-meta { display:flex; justify-content:space-between; font-size:10px; color:#aaa; margin-top:6px; }
        .receipt-divider { text-align:center; color:var(--border); margin:8px 0; font-size:12px; }
        .receipt-table { width:100%; border-collapse:collapse; font-size:12px; }
        .receipt-table th { text-align:left; border-bottom:1px dashed var(--border); padding-bottom:4px; font-size:11px; color:var(--muted); }
        .receipt-qty { text-align:center; }
        .receipt-amt { text-align:right; }
        .receipt-total-row { display:flex; justify-content:space-between; font-weight:bold; font-size:15px; margin:6px 0 4px; color:var(--choc-dark); }
        .receipt-cashrow { display:flex; justify-content:space-between; font-size:12px; margin:2px 0; color:#555; }
        .cashless-note { justify-content:center; font-style:italic; color:var(--caramel); }
        .receipt-footer { text-align:center; font-size:11px; color:var(--muted); margin-top:10px; line-height:1.6; }
        .receipt-actions-bot { display:flex; gap:8px; padding:10px 14px; background:var(--cream); border-top:1px solid var(--border); justify-content:center; }
        .receipt-clear-btn {
            padding:9px 20px; border:none; border-radius:8px; cursor:pointer;
            font-size:13px; font-weight:700;
            background:linear-gradient(135deg, var(--caramel), var(--caramel-deep));
            color:#fff; box-shadow:0 4px 12px rgba(139,69,19,0.3);
            transition:transform .15s;
        }
        .receipt-clear-btn:hover { transform:translateY(-1px); }

        /* ── Reserve modal ── */
        .modal-overlay { position:fixed; inset:0; z-index:1500; background:rgba(30,15,5,0.55); display:flex; align-items:center; justify-content:center; }
        .modal-box {
            background:var(--surface); border-radius:16px; padding:24px; width:340px;
            box-shadow:0 20px 50px rgba(30,15,5,0.3);
            border-top:4px solid var(--gold);
        }
        .modal-box h3 { margin:0 0 16px; font-size:16px; color:var(--choc-dark); font-weight:700; }
        .modal-input {
            width:100%; padding:9px 12px;
            border:1.5px solid var(--border); border-radius:8px;
            font-size:13px; box-sizing:border-box; margin-top:4px;
            background:var(--cream); color:var(--choc-dark);
        }
        .modal-input:focus { outline:none; border-color:var(--caramel); }
        .modal-label { font-size:12px; font-weight:700; margin-top:12px; display:block; color:var(--choc); }
        .modal-btn-row { display:flex; gap:8px; margin-top:20px; }
        .modal-btn-row button {
            flex:1; padding:10px; border:none; border-radius:8px;
            cursor:pointer; font-size:13px; font-weight:700;
        }
        .btn-save {
            background:linear-gradient(135deg, var(--caramel), var(--caramel-deep));
            color:#fff; box-shadow:0 4px 12px rgba(139,69,19,0.28);
        }
        .btn-cancel { background:var(--cream-deep); color:var(--choc); }

        /* ── Edit reservation banner ── */
        #edit-banner {
            position:fixed; top:0; left:0; right:0; z-index:2000;
            background:var(--choc-dark); color:#fff;
            text-align:center; padding:10px 20px;
            font-size:13px; font-weight:600;
            display:flex; justify-content:center; align-items:center; gap:16px;
            border-bottom:3px solid var(--gold);
        }
        #edit-banner button { padding:5px 14px; border-radius:6px; cursor:pointer; font-weight:700; font-size:12px; }
        .btn-save-edit   { background:var(--gold); color:var(--choc-dark); border:none; }
        .btn-cancel-edit { background:transparent; color:#fff; border:1px solid rgba(255,255,255,0.5); }

        /* ── Message dialog (replaces the browser's alert / confirm pop-ups) ── */
        .dlg-overlay {
            position:fixed; inset:0; z-index:3000; background:rgba(30,15,5,0.55);
            display:flex; align-items:center; justify-content:center; padding:1rem;
            opacity:0; visibility:hidden; transition:opacity .2s ease, visibility .2s;
        }
        .dlg-overlay.open { opacity:1; visibility:visible; }
        .dlg-box {
            --dlg-color:var(--caramel); --dlg-bg:var(--amber-bg);
            width:340px; max-width:100%; background:var(--surface); border-radius:16px;
            padding:26px 22px 20px; text-align:center; border-top:4px solid var(--dlg-color);
            box-shadow:0 24px 64px rgba(30,15,5,0.4);
            transform:translateY(10px) scale(.97); transition:transform .2s ease;
        }
        .dlg-overlay.open .dlg-box { transform:none; }
        .dlg-box.t-success  { --dlg-color:#2E7D32; --dlg-bg:#E6F4E6; }
        .dlg-box.t-error    { --dlg-color:#C62828; --dlg-bg:#FCEAEA; }
        .dlg-box.t-warning  { --dlg-color:#D4A24C; --dlg-bg:#FBF0DC; }
        .dlg-box.t-info,
        .dlg-box.t-question { --dlg-color:#C17817; --dlg-bg:#FBF0DC; }
        .dlg-icon {
            width:48px; height:48px; margin:0 auto 12px; border-radius:50%;
            background:var(--dlg-bg); color:var(--dlg-color);
            display:flex; align-items:center; justify-content:center;
        }
        .dlg-icon svg { width:26px; height:26px; fill:none; stroke:currentColor; stroke-width:2.4; stroke-linecap:round; stroke-linejoin:round; }
        .dlg-box h3 { font-size:16px; font-weight:700; color:var(--choc-dark); margin:0 0 6px; }
        .dlg-box p  { font-size:13px; line-height:1.55; color:var(--choc); white-space:pre-line; margin:0; }
        .dlg-actions { display:flex; gap:8px; margin-top:20px; }
        .dlg-actions button {
            flex:1; padding:10px; border:none; border-radius:8px; cursor:pointer;
            font-size:13px; font-weight:700; font-family:inherit; transition:filter .15s, transform .1s;
        }
        .dlg-actions button:hover  { filter:brightness(.95); }
        .dlg-actions button:active { transform:scale(.97); }
        .dlg-actions button:focus-visible { outline:2px solid var(--caramel); outline-offset:2px; }
        .dlg-btn-main   { background:var(--dlg-color); color:#fff; }
        .dlg-btn-cancel { background:var(--cream-deep); color:var(--choc); }

        /* ── Empty cart ── */
        .empty-cart { text-align:center; color:var(--muted); font-size:12px; padding:24px 16px; }

        pre { margin:0; font-family:inherit; }
    </style>
</head>
<body>

<?php if ($edit_reservation): ?>
<div id="edit-banner">
    <span>Editing Reservation #<?= $edit_reservation['id'] ?> — <?= e($edit_reservation['customer_name']) ?> | <?= e($edit_reservation['product_name']) ?></span>
    <button class="btn-save-edit"   onclick="saveEditedReservation()">Save Changes</button>
    <button class="btn-cancel-edit" onclick="cancelEdit()">Cancel</button>
</div>
<?php endif; ?>

<div class="head-nav" id="head-nav">
    <div class="menu-icon-cont"><img src="src/menu.png" alt="" onclick="openMenu()"></div>
    <pre>      Liberty Foodmart</pre>
</div>

<main class="main">
    <div class="nav-cont" id="nav-cont">
        <a href="staff-orderingv2.php" class="navs"><div class="icon"><img src="src/checkout.png" alt=""></div><pre>Create Orders</pre></a>
        <a href="staff-online-orders.php" class="navs"><div class="icon"><img src="src/online-orders.png" alt=""></div><pre>Online Orders</pre></a>
        <a href="staff-reservation-list.php" class="navs"><div class="icon"><img src="src/booking.png" alt=""></div><pre>Reservation List</pre></a>
        <a href="logout.php" class="navs"><div class="icon"><img src="src/logout.png" alt=""></div><pre>Logout</pre></a>
    </div>
    <div class="overlay" id="overlay" onclick="closeMenu()"></div>

    <!-- ── Left panel: order cart ── -->
    <div class="main-calc">
        <div class="top" id="top">
            <div class="total-cont">
                <span class="subtotal-row" id="subtotal-row" style="display:none;"><pre>Subtotal: Php </pre><pre id="subtotal-display">0</pre></span>
                <span class="discount-line" id="discount-row" style="display:none;"><pre>Discount (5%): -Php </pre><pre id="discount-display">0</pre></span>
                <span class="total-top"><pre>Total: Php </pre><pre id="total-display">0</pre></span>
                <span class="change-bot"><pre>Change: Php </pre><pre id="change-display">0</pre></span>
            </div>
            <p class="empty-cart" id="empty-cart">No items added yet.</p>
        </div>

        <div class="bot">
            
            <div class="received-cont">
                <pre>Received:</pre>
                <input type="text" id="received-input" placeholder="Ex. 9999" inputmode="decimal" autocomplete="off" oninput="onReceivedInput(this)">
            </div>
            <br>
            <div class="discount-toggle-row">
                <label class="discount-toggle-label">
                    <input type="checkbox" id="pos-senior-pwd" onchange="render()">
                    <span>Senior Citizen / PWD - 5% discount (ID verified)</span>
                </label>
            </div>

            <div class="btns2">
                <div class="record-cont" onclick="openReceipt('Cash')">
                    <div class="record-img"><img src="src/record.png" alt=""></div>
                    <pre>Record</pre>
                </div>
                <div class="reserve-cont" onclick="openReserveModal()">Reserve Order</div>
            </div>
        </div>
    </div>

    <!-- ── Right panel: product grid ── -->
    <div class="main-goods">
        <div class="main-nav">
            <div class="main-search">
                <pre class="main-title">Breads and Cakes</pre>
                <pre>Search</pre>
                <input type="text" class="search" id="search-input" placeholder="Search Product" oninput="filterProducts()">
            </div>
        </div>
        <div class="goods-frame">
            <div class="center" id="products-grid">
                <?php foreach ($products as $p):
                    $isLow = $p['quantity'] <= $p['low_stock_threshold'];
                    $slug  = 'qty-' . $p['id'];
                ?>
                <div class="food-cont" id="card-<?= $p['id'] ?>"
                     data-name="<?= e($p['name']) ?>"
                     data-category="<?= e($p['category']) ?>">
                    <div class="food-cont-top">
                        <div class="img-cont">
                            <?php if ($p['image_path'] && file_exists($p['image_path'])): ?>
                                <img src="<?= e($p['image_path']) ?>" alt="<?= e($p['name']) ?>">
                            <?php else: ?>
                                <img src="src/black-forestv2.webp" alt="<?= e($p['name']) ?>">
                            <?php endif; ?>
                        </div>
                        <div class="food-cont-desc">
                            <p class="productname"><?= e($p['name']) ?></p>
                            <p class="productDesc"><?= e($p['description'] ?? '') ?></p>
                            <p class="product-status <?= $isLow ? 'status-low' : '' ?>">
                                <?= $isLow ? '⚠ Low stock (' . $p['quantity'] . ')' : 'Available (' . $p['quantity'] . ')' ?>
                            </p>
                        </div>
                    </div>
                    <div class="food-cont-bot">
                        <div class="food-cont-price">₱<?= number_format($p['price'], 2) ?></div>
                        <div class="food-cont-btn">
                            <button onclick="decreaseItem(<?= $p['id'] ?>)">-</button>
                            <p class="food-cont-qty" id="<?= $slug ?>">0</p>
                            <button class="add-btn" onclick="addItem(<?= $p['id'] ?>, '<?= e(addslashes($p['name'])) ?>', <?= $p['price'] ?>, '<?= $slug ?>', <?= $p['quantity'] ?>)">+</button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($products)): ?>
                    <p style="color:white;text-align:center;margin-top:20px;font-family:monospace;">No products available.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- Receipt modal -->
<div class="receipt-overlay" id="receipt-overlay" onclick="closeReceipt()"></div>
<div class="receipt-modal" id="receipt-modal">
    <div class="receipt-actions-top">
        <button class="receipt-close-btn" onclick="closeReceipt()">Close ✕</button>
    </div>
    <div class="receipt-paper" id="receipt-body"></div>
    <div class="receipt-actions-bot">
        <button class="receipt-clear-btn" onclick="confirmAndClear()"> Confirm &amp; Clear Order</button>
    </div>
</div>

<!-- Reserve modal (injected by JS) -->

<!-- ── Message dialog ── -->
<div class="dlg-overlay" id="dlg-overlay">
    <div class="dlg-box" id="dlg-box" role="alertdialog" aria-modal="true" aria-labelledby="dlg-title" aria-describedby="dlg-msg">
        <div class="dlg-icon" id="dlg-icon"></div>
        <h3 id="dlg-title"></h3>
        <p id="dlg-msg"></p>
        <div class="dlg-actions" id="dlg-actions"></div>
    </div>
</div>

<script>
// ── VAT / discount maths (mirrors vat.php so the receipt matches the saved order) ──
// Prices are VAT-inclusive. The Senior/PWD discount is taken per line,
// rounded to centavos per line (like price_line()), and VAT is still
// charged on the discounted total. Net = total / (1 + VAT rate).
const VAT_RATE      = <?= json_encode((float)$page_rates['vat_rate']) ?>;
const DISCOUNT_RATE = <?= json_encode((float)$page_rates['discount_rate']) ?>;
const round2 = n => Math.round((n + Number.EPSILON) * 100) / 100;
const peso2  = n => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits:2, maximumFractionDigits:2 });

function calcTotals() {
    const isSeniorPwd = document.getElementById('pos-senior-pwd').checked;
    let subtotal = 0, discount = 0;
    Object.values(orders).forEach(i => {
        const gross = round2(i.price * i.qty);
        subtotal += gross;
        if (isSeniorPwd) discount += round2(gross * DISCOUNT_RATE);
    });
    subtotal = round2(subtotal);
    discount = round2(discount);
    const total = round2(subtotal - discount);
    const net   = round2(total / (1 + VAT_RATE));
    const vat   = round2(total - net);
    return { isSeniorPwd, subtotal, discount, total, net, vat };
}
// ── State ─────────────────────────────────────────────────
// orders = { productId: { name, price, qty, stock } }
const orders = {};
<?php if ($edit_reservation): ?>
const EDIT_RESERVATION_ID = <?= (int)$edit_reservation['id'] ?>;
<?php if (!empty($edit_reservation['is_senior_pwd'])): ?>
document.getElementById('pos-senior-pwd').checked = true;
<?php endif; ?>
document.getElementById('head-nav').style.marginTop = '40px';
<?php else: ?>
const EDIT_RESERVATION_ID = null;
<?php endif; ?>

// ── Nav ────────────────────────────────────────────────────
const navCont = document.getElementById('nav-cont');
const overlay = document.getElementById('overlay');
function openMenu()  { navCont.classList.add('active');    overlay.classList.add('active'); }
function closeMenu() { navCont.classList.remove('active'); overlay.classList.remove('active'); }

// ── Message dialog (replaces alert / confirm) ─────────────
const DLG_ICONS = {
    success:  '<svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>',
    error:    '<svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    warning:  '<svg viewBox="0 0 24 24"><path d="M12 7v6M12 17v.01"/></svg>',
    info:     '<svg viewBox="0 0 24 24"><path d="M12 11v6M12 7.5v.01"/></svg>',
    question: '<svg viewBox="0 0 24 24"><path d="M9.5 9.2a2.6 2.6 0 1 1 3.6 2.4c-.8.4-1.1 1-1.1 1.8M12 17v.01"/></svg>',
};
let dlgResolve = null;

// Shows the dialog and returns a Promise: true = main button, false = cancelled / dismissed
function showDialog({ type = 'info', title = '', message = '', confirmText = 'OK', cancelText = null }) {
    if (dlgResolve) dlgResolve(false);                       // never leave an old one hanging

    const box = document.getElementById('dlg-box');
    box.className = 'dlg-box t-' + type;
    document.getElementById('dlg-icon').innerHTML = DLG_ICONS[type] || DLG_ICONS.info;
    document.getElementById('dlg-title').textContent = title;
    document.getElementById('dlg-msg').textContent   = message;     // textContent: customer names can't inject HTML

    const actions = document.getElementById('dlg-actions');
    actions.innerHTML = '';
    if (cancelText) {
        const c = document.createElement('button');
        c.type = 'button'; c.className = 'dlg-btn-cancel'; c.textContent = cancelText;
        c.onclick = () => closeDialog(false);
        actions.appendChild(c);
    }
    const ok = document.createElement('button');
    ok.type = 'button'; ok.className = 'dlg-btn-main'; ok.id = 'dlg-ok'; ok.textContent = confirmText;
    ok.onclick = () => closeDialog(true);
    actions.appendChild(ok);

    document.getElementById('dlg-overlay').classList.add('open');
    setTimeout(() => ok.focus(), 30);
    return new Promise(resolve => { dlgResolve = resolve; });
}
function closeDialog(result) {
    document.getElementById('dlg-overlay').classList.remove('open');
    if (dlgResolve) { const r = dlgResolve; dlgResolve = null; r(result); }
}
const notify     = (message, type = 'info', title = '') => showDialog({ type, title: title || ({ success:'Done', error:'Something went wrong', warning:'Please check', info:'Notice' }[type]), message });
const netError   = () => notify('Could not reach the server. Please check the connection and try again.', 'error', 'Connection problem');

document.getElementById('dlg-overlay').addEventListener('click', e => { if (e.target.id === 'dlg-overlay') closeDialog(false); });
document.addEventListener('keydown', e => {
    if (!document.getElementById('dlg-overlay').classList.contains('open')) return;
    if (e.key === 'Escape') closeDialog(false);
});

// ── Add / Decrease / Remove ────────────────────────────────
function addItem(id, name, price, qtyId, stock) {
    if (!orders[id]) {
        orders[id] = { name, price, qty: 0, stock };
    }
    if (orders[id].qty >= stock) {
        notify(name + ' — only ' + stock + ' in stock.', 'warning', 'Not enough stock');
        return;
    }
    orders[id].qty++;
    document.getElementById(qtyId).textContent = orders[id].qty;
    render();
}

function decreaseItem(id) {
    if (!orders[id]) return;
    const qtyEl = document.getElementById('qty-' + id);
    if (orders[id].qty > 1) {
        orders[id].qty--;
    } else {
        delete orders[id];
    }
    if (qtyEl) qtyEl.textContent = orders[id] ? orders[id].qty : 0;
    render();
}

function removeItem(id) {
    const qtyEl = document.getElementById('qty-' + id);
    if (qtyEl) qtyEl.textContent = 0;
    delete orders[id];
    render();
}

// ── Render cart ────────────────────────────────────────────
function render() {
    const top        = document.getElementById('top');
    const totalDisp  = document.getElementById('total-display');
    const emptyMsg   = document.getElementById('empty-cart');
    const totalCont  = top.querySelector('.total-cont');

    top.querySelectorAll('.food-info').forEach(el => el.remove());

    const keys = Object.keys(orders);
    emptyMsg.style.display = keys.length === 0 ? 'block' : 'none';

    let subtotal = 0;
    keys.forEach(id => {
        const item  = orders[id];
        subtotal   += item.price * item.qty;
        const row   = document.createElement('div');
        row.className = 'food-info';
        row.innerHTML = `
            <div class="info-top">
                <p>${item.name}</p>
                <p>Php ${(item.price * item.qty).toLocaleString('en-PH')}</p>
            </div>
            <div class="info-bot">
                <div class="edit-btns">
                    <button class="i-btn" onclick="addItem(${id},'${item.name.replace(/'/g,"\\'")}',${item.price},'qty-${id}',${item.stock})">+</button>
                    <button class="i-btn" onclick="decreaseItem(${id})">-</button>
                    <button class="i-btn" onclick="removeItem(${id})">x</button>
                </div>
                <p>${item.qty}x</p>
            </div>`;
        top.insertBefore(row, totalCont);
    });

    // Live preview only — the server always recalculates this itself from
    // the database and never trusts what's sent here.
    const t           = calcTotals();
    const isSeniorPwd = t.isSeniorPwd;
    const discount    = t.discount;
    const total       = t.total;

    document.getElementById('subtotal-row').style.display = isSeniorPwd ? 'flex' : 'none';
    document.getElementById('subtotal-display').textContent = subtotal.toLocaleString('en-PH');
    document.getElementById('discount-row').style.display = isSeniorPwd ? 'flex' : 'none';
    document.getElementById('discount-display').textContent = discount.toLocaleString('en-PH');
    totalDisp.textContent = total.toLocaleString('en-PH');
    calcChange(total);
}

// ── Change calculator ──────────────────────────────────────
function getTotal() {
    return calcTotals().total;
}

// Change shows 0 until enough money has been received (never a negative number)
function calcChange(total) {
    total = total !== undefined ? total : getTotal();
    const received = parseFloat(document.getElementById('received-input').value) || 0;
    const change   = Math.max(0, received - total);
    document.getElementById('change-display').textContent = change.toLocaleString('en-PH');
}

// Runs on every keystroke in the Received box: keep digits + one decimal point, then update Change
function onReceivedInput(el) {
    let v = el.value.replace(/[^0-9.]/g, '');
    const dot = v.indexOf('.');
    if (dot !== -1) v = v.slice(0, dot + 1) + v.slice(dot + 1).replace(/\./g, '');
    if (v !== el.value) el.value = v;
    calcChange();
}

// ── Product search filter ──────────────────────────────────
function filterProducts() {
    const q = document.getElementById('search-input').value.toLowerCase();
    document.querySelectorAll('.food-cont').forEach(card => {
        const name = (card.dataset.name || '').toLowerCase();
        const cat  = (card.dataset.category || '').toLowerCase();
        card.style.display = (!q || name.includes(q) || cat.includes(q)) ? '' : 'none';
    });
}

// ── Receipt modal ──────────────────────────────────────────
function openReceipt(paymentMethod) {
    const keys = Object.keys(orders);
    if (keys.length === 0) { notify('Add at least one item before recording the order.', 'warning', 'No items yet'); return; }

    const { isSeniorPwd, subtotal, discount, total, net, vat } = calcTotals();
    const received = parseFloat(document.getElementById('received-input').value) || 0;
    const change   = received - total;

    if (paymentMethod === 'Cash') {
        if (received <= 0) { notify('Enter the amount received from the customer first.', 'warning', 'Amount received'); return; }
        if (change < 0)    { notify('The total is ' + peso2(total) + ' but only ' + peso2(received) + ' was received.\nShort by ' + peso2(-change) + '.', 'error', 'Insufficient payment'); return; }
    }

    const now = new Date().toLocaleString('en-PH', { year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit', second:'2-digit' });
    const ref = 'ORD-' + Date.now().toString().slice(-6);

    let rows = '';
    keys.forEach(id => {
        const i = orders[id];
        rows += `<tr><td>${i.name}</td><td class="receipt-qty">${i.qty}x</td><td class="receipt-amt">₱${(i.price*i.qty).toLocaleString('en-PH')}</td></tr>`;
    });

    const discountSection = isSeniorPwd
        ? `<div class="receipt-cashrow"><span>Subtotal:</span><span>${peso2(subtotal)}</span></div>
           <div class="receipt-cashrow"><span>Senior/PWD Discount (${Math.round(DISCOUNT_RATE * 100)}%):</span><span>-${peso2(discount)}</span></div>`
        : '';

    // Prices already include VAT, so this is a breakdown of the total,
    // not an extra charge.
    const vatSection = `
        <div class="receipt-cashrow"><span>VATable Sales (net):</span><span>${peso2(net)}</span></div>
        <div class="receipt-cashrow"><span>VAT (${Math.round(VAT_RATE * 100)}%) included:</span><span>${peso2(vat)}</span></div>`;

    const cashSection = paymentMethod === 'Cash'
        ? `<div class="receipt-cashrow"><span>Received:</span><span>₱${received.toLocaleString('en-PH')}</span></div>
           <div class="receipt-cashrow"><span>Change:</span><span>₱${change.toLocaleString('en-PH')}</span></div>`
        : `<div class="receipt-cashrow cashless-note"><span>Payment via ${paymentMethod}</span></div>`;

    document.getElementById('receipt-body').innerHTML = `
        <div class="receipt-header">
            <div class="receipt-store">Liberty Foodmart</div>
            <div class="receipt-sub">Official Receipt</div>
            <div class="receipt-meta"><span>${now}</span><span>Ref: ${ref}</span></div>
        </div>
        <div class="receipt-divider">- - - - - - - - - - - - - - - - - -</div>
        <table class="receipt-table">
            <thead><tr><th>Item</th><th>Qty</th><th>Amount</th></tr></thead>
            <tbody>${rows}</tbody>
        </table>
        <div class="receipt-divider">- - - - - - - - - - - - - - - - - -</div>
        ${discountSection}
        <div class="receipt-total-row"><span>TOTAL</span><span>${peso2(total)}</span></div>
        ${vatSection}
        ${cashSection}
        <div class="receipt-divider">- - - - - - - - - - - - - - - - - -</div>
        <div class="receipt-footer">Thank you for shopping!<br>Liberty Foodmart</div>`;

    document.getElementById('receipt-modal').classList.add('active');
    document.getElementById('receipt-overlay').classList.add('active');
}

function closeReceipt() {
    document.getElementById('receipt-modal').classList.remove('active');
    document.getElementById('receipt-overlay').classList.remove('active');
}

// ── Confirm & record walk-in order to DB ──────────────────
function confirmAndClear() {
    const keys = Object.keys(orders);
    if (keys.length === 0) return;

    const paymentMethod = document.getElementById('received-input').value ? 'Cash' : 'GCash';
    const received      = parseFloat(document.getElementById('received-input').value) || 0;
    const isSeniorPwd   = document.getElementById('pos-senior-pwd').checked;

    const items = keys.map(id => ({
        product_id: id,
        name:  orders[id].name,
        price: orders[id].price,
        qty:   orders[id].qty,
    }));

    const body = new URLSearchParams({
        action:         'record_order',
        items:          JSON.stringify(items),
        payment_method: paymentMethod,
        received:       received,
        is_senior_pwd:  isSeniorPwd ? '1' : '0',
    });

    fetch('staff-orderingv2.php', { method:'POST', body })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                clearOrder();
                notify('Order #' + data.order_id + ' has been recorded.', 'success', 'Order recorded');
            } else {
                notify(data.error || 'Unknown error', 'error', 'Could not record the order');
            }
        })
        .catch(netError);
}

function clearOrder() {
    Object.keys(orders).forEach(k => delete orders[k]);
    document.querySelectorAll('.food-cont-qty').forEach(el => el.textContent = '0');
    document.getElementById('received-input').value = '';
    document.getElementById('pos-senior-pwd').checked = false;
    render();
    closeReceipt();
}

// ── Reserve modal ──────────────────────────────────────────
function openReserveModal() {
    if (Object.keys(orders).length === 0) { notify('Add the items to reserve first.', 'warning', 'No items yet'); return; }

    const existing = document.getElementById('reserve-modal');
    if (existing) existing.remove();

    const total       = getTotal();
    const itemSummary = Object.values(orders).map(i => i.name + ' x' + i.qty).join(', ');

    const modal = document.createElement('div');
    modal.id    = 'reserve-modal';
    modal.className = 'modal-overlay';
    modal.innerHTML = `
        <div class="modal-box">
            <h3>New Reservation</h3>
            <p style="font-size:12px;color:#666;margin-bottom:16px;">Items: ${itemSummary} — Total: ₱${total.toLocaleString('en-PH')}</p>

            <label class="modal-label">Customer Name</label>
            <input id="res-name" type="text" class="modal-input" placeholder="e.g. Juan dela Cruz">

            <label class="modal-label">Contact Number</label>
            <input id="res-contact" type="text" class="modal-input" placeholder="e.g. 09171234567">

            <label class="modal-label">Pickup Date &amp; Time</label>
            <input id="res-datetime" type="datetime-local" class="modal-input">

            <label class="modal-label">Payment Status</label>
            <select id="res-payment" class="modal-input">
                <option value="UNPAID">Unpaid</option>
                <option value="PAID">Paid</option>
            </select>

            <div class="modal-btn-row">
                <button class="btn-save"   onclick="saveNewReservation()">Save Reservation</button>
                <button class="btn-cancel" onclick="document.getElementById('reserve-modal').remove()">Cancel</button>
            </div>
        </div>`;

    modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
    document.body.appendChild(modal);
}

function saveNewReservation() {
    const name          = document.getElementById('res-name').value.trim();
    const contact       = document.getElementById('res-contact').value.trim();
    const datetimeRaw   = document.getElementById('res-datetime').value;
    const paymentStatus = document.getElementById('res-payment').value;

    if (!name || !contact || !datetimeRaw) { notify('Please fill in the name, contact number and pickup date & time.', 'warning', 'Missing details'); return; }

    const items = Object.keys(orders).map(id => ({
        product_id: id,
        name:  orders[id].name,
        price: orders[id].price,
        qty:   orders[id].qty,
    }));

    const body = new URLSearchParams({
        action:           'save_reservation',
        items:            JSON.stringify(items),
        customer_name:    name,
        contact:          contact,
        pickup_datetime:  datetimeRaw,
        payment_status:   paymentStatus,
        is_senior_pwd:    document.getElementById('pos-senior-pwd').checked ? '1' : '0',
    });

    fetch('staff-orderingv2.php', { method:'POST', body })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                document.getElementById('reserve-modal').remove();
                showDialog({
                    type: 'success', title: 'Reservation saved',
                    message: 'Reservation #' + data.reservation_id + ' was saved for ' + name + '.\nClear the current order from the cart?',
                    confirmText: 'Clear Order', cancelText: 'Keep Order'
                }).then(clear => { if (clear) clearOrder(); });
            } else {
                notify(data.error || 'Unknown error', 'error', 'Could not save the reservation');
            }
        })
        .catch(netError);
}

// ── Edit reservation mode ─────────────────────────────────
function saveEditedReservation() {
    if (!EDIT_RESERVATION_ID) return;
    const keys = Object.keys(orders);
    if (keys.length === 0) { notify('Add at least one item to the reservation.', 'warning', 'No items yet'); return; }

    const items = keys.map(id => ({
        product_id: id,
        name:  orders[id].name,
        price: orders[id].price,
        qty:   orders[id].qty,
    }));

    const body = new URLSearchParams({
        action:          'update_reservation',
        reservation_id:  EDIT_RESERVATION_ID,
        items:           JSON.stringify(items),
        is_senior_pwd:   document.getElementById('pos-senior-pwd').checked ? '1' : '0',
    });

    fetch('staff-orderingv2.php', { method:'POST', body })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                notify('Reservation #' + EDIT_RESERVATION_ID + ' has been updated.', 'success', 'Reservation updated')
                    .then(() => { window.location.href = 'staff-reservation-list.php'; });
            } else {
                notify(data.error || 'Unknown error', 'error', 'Could not update the reservation');
            }
        })
        .catch(netError);
}

function cancelEdit() {
    window.location.href = 'staff-reservation-list.php';
}
</script>

</body>
</html>