<?php
// ── Liberty Foodmart — Staff Online Orders ───────────────────
require_once 'session.php';
require 'db.php';
require 'vat.php';
require_once 'reservation-stock.php';

require_once 'auth.php';
require_staff($pdo);

$staff_id = (int)$_SESSION['staff_id'];

// ── Handle status update POST ────────────────────────────────
// The edit popup submits here via fetch(); we return JSON.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $id             = (int) ($_POST['order_id']       ?? 0);
    $payment_status = $_POST['payment_status']         ?? '';
    $order_status   = $_POST['order_status']           ?? '';

    $allowed_pay   = ['UNPAID', 'PAID'];
    $allowed_order = ['Processing', 'Ready for Delivery', 'Out for Delivery', 'Done'];   // cancelling has its own action (cancel_order)

    if (!($id > 0 && in_array($payment_status, $allowed_pay, true) && in_array($order_status, $allowed_order, true))) {
        echo json_encode(['ok' => false, 'error' => 'Invalid input']);
        exit;
    }

    // Rule: an order can't be Done until it has been paid.
    if ($order_status === 'Done' && $payment_status !== 'PAID') {
        echo json_encode(['ok' => false, 'error' => 'This order is not paid yet. Set the payment status to PAID before marking it Done.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $cur = $pdo->prepare("SELECT order_status FROM orders WHERE id = ? AND order_type = 'online' FOR UPDATE");
        $cur->execute([$id]);
        $was = $cur->fetchColumn();

        if ($was === false) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'Order not found.']);
            exit;
        }
        if ($was === 'Done') {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'This order is already completed and can no longer be changed.']);
            exit;
        }
        if ($was === 'Cancelled') {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'This order was cancelled and can no longer be changed.']);
            exit;
        }

        $pdo->prepare('UPDATE orders SET payment_status = ?, order_status = ? WHERE id = ?')
            ->execute([$payment_status, $order_status, $id]);

        $pdo->commit();

        // Order just completed → send back everything the receipt needs.
        // (Built after the commit and in its own try, so a hiccup here can
        // never make a saved order look like it failed to save.)
        $receipt = null;
        if ($order_status === 'Done') {
            try {
                $rc = $pdo->prepare('SELECT id, customer_name, phone, location, payment_method, payment_status, subtotal, discount_amount, total, net_amount, vat_amount, is_senior_pwd, created_at FROM orders WHERE id = ?');
                $rc->execute([$id]);
                $receipt = $rc->fetch() ?: null;
                if ($receipt) {
                    $ri = $pdo->prepare('SELECT product_name, unit_price, quantity, discount_amount, line_total FROM order_items WHERE order_id = ? ORDER BY id');
                    $ri->execute([$id]);
                    $receipt['items'] = $ri->fetchAll();
                }
            } catch (Exception $e2) {
                error_log('receipt data failed: ' . $e2->getMessage());
                $receipt = null;
            }
        }

        echo json_encode(['ok' => true, 'receipt' => $receipt]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('update_status failed: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Could not save. Please try again.']);
    }
    exit;
}

// ── Handle CANCEL (staff) ────────────────────────────────────
// Returns the items to stock, marks the order Cancelled by the staff, and
// flags it so the customer sees a cancellation notice the next time they
// open any customer page (see customer-nav.php). Cancelled orders are not
// listed on this page any more.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_order') {
    header('Content-Type: application/json');
    $id = (int)($_POST['order_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        $cur = $pdo->prepare("SELECT order_status FROM orders WHERE id = ? AND order_type = 'online' FOR UPDATE");
        $cur->execute([$id]);
        $was = $cur->fetchColumn();

        if ($was === false) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'Order not found.']);
            exit;
        }
        if ($was === 'Done') {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'This order is already completed and cannot be cancelled.']);
            exit;
        }
        if ($was === 'Cancelled') {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'This order is already cancelled.']);
            exit;
        }

        order_restore_stock($pdo, $id);

        $pdo->prepare("
            UPDATE orders
               SET order_status = 'Cancelled', cancelled_by = 'staff', cancelled_at = NOW(), cancel_notified = 0
             WHERE id = ?
        ")->execute([$id]);

        $pdo->commit();
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('cancel_order failed: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Could not cancel the order. Please try again.']);
    }
    exit;
}

// ── Filters: payment (?filter=PAID|UNPAID) and delivery status (?status=...) ──
// Done and Cancelled orders are never listed, so the page only shows orders
// that still need attention.
$filter = strtoupper($_GET['filter'] ?? 'UNPAID');
if (!in_array($filter, ['PAID', 'UNPAID'], true)) $filter = 'UNPAID';

$statuses      = ['Processing', 'Ready for Delivery', 'Out for Delivery'];
$status_filter = $_GET['status'] ?? '';
if (!in_array($status_filter, $statuses, true)) $status_filter = '';

// ── Query orders from DB ─────────────────────────────────────
// Only show online orders on this page (order_type = 'online')
$where_status = $status_filter !== ''
    ? 'o.order_status = ' . $pdo->quote($status_filter)
    : "o.order_status IN ('Processing','Ready for Delivery','Out for Delivery')";
$where = "o.order_type = 'online' AND {$where_status} AND o.payment_status = " . $pdo->quote($filter);

$orders = $pdo->query("
    SELECT
        o.id,
        o.customer_name,
        o.phone,
        o.location,
        o.payment_method,
        o.payment_status,
        o.order_status,
        o.total,
        o.created_at,
        -- Full product breakdown, e.g. 'Black Forest Cake 1x, Mango Cheesecake 2x'
        GROUP_CONCAT(CONCAT(oi.product_name, ' ', oi.quantity, 'x') ORDER BY oi.id SEPARATOR ', ') AS product_summary,
        SUM(oi.quantity) AS total_qty
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    WHERE {$where}
    GROUP BY o.id
    ORDER BY o.created_at DESC
")->fetchAll();

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function format_price(float $amount): string {
    return 'Php ' . number_format($amount, 2);
}

function format_order_date(string $datetime): array {
    $d = new DateTime($datetime);
    return [
        'date' => $d->format('d/m/Y'),
        'time' => strtolower($d->format('g:ia')),
    ];
}

function order_status_class(string $s): string {
    return match ($s) {
        'Done'               => 'badge-done',
        'Cancelled'          => 'badge-cancelled',
        'Ready for Delivery' => 'badge-ready',
        'Out for Delivery'   => 'badge-out',
        default              => 'badge-processing',
    };
}

/** Link that keeps the other filter when one changes. */
function list_url(string $filter, string $status): string {
    $q = ['filter' => $filter];
    if ($status !== '') $q['status'] = $status;
    return 'staff-online-orders.php?' . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Orders — Liberty Foodmart</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ── Bakery palette (shared with staff-orderingv2.php) ── */
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

            --green:#2E7D32; --green-bg:#E6F4E6;
            --red:#C62828;   --red-bg:#FCEAEA;
            --amber:#8A5A15; --amber-bg:#FBF0DC;
            --blue:#1565C0;  --blue-bg:#E3F2FD;
        }

        @font-face { src:url(src/Kavoon-Regular.ttf); font-family:kavoon; }

        *, p, pre, h1, h2, h3 { margin:0; box-sizing:border-box; }

        body {
            width:100%; height:100vh; overflow:hidden; margin:0;
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
        .navs.active { background:var(--cream-deep); color:var(--caramel-deep); }
        .icon { width:22px; height:22px; flex-shrink:0; }
        .navs img { width:100%; height:100%; object-fit:cover; }
        .overlay {
            position:fixed; inset:0; background:rgba(59,36,23,0.35);
            z-index:900; opacity:0; visibility:hidden; transition:.3s;
        }
        .nav-cont.active { transform:translateX(0); }
        .overlay.active { opacity:1; visibility:visible; }

        /* ── Page shell ── */
        .page {
            width:100%; height:100%; display:flex; flex-direction:column;
            padding:22px 32px 24px; overflow:hidden;
        }
        .page-head { display:flex; align-items:flex-end; justify-content:space-between; margin-bottom:16px; flex-wrap:wrap; gap:12px; }
        .page-head h1 {
            font-family:'Playfair Display', serif; font-weight:700;
            font-size:1.6rem; color:var(--choc-dark);
        }
        .page-head .sub { font-size:.82rem; color:var(--muted); margin-top:2px; }

        /* ── Segmented tab toggle ── */
        .seg-toggle {
            display:flex; background:var(--cream-deep); border-radius:2rem; padding:4px;
        }
        .seg-toggle a {
            padding:.5rem 1.3rem; border-radius:1.6rem; font-size:.8rem; font-weight:700;
            color:var(--choc); text-decoration:none; transition:background .2s, color .2s;
        }
        .seg-toggle a.active { background:var(--choc-dark); color:#fff; box-shadow:0 4px 12px rgba(59,36,23,0.3); }

        /* ── Delivery-status filter chips ── */
        .chips { display:flex; gap:.5rem; flex-wrap:wrap; margin:-4px 0 14px; }
        .chip {
            padding:.38rem .95rem; border-radius:2rem; font-size:.74rem; font-weight:700; text-decoration:none;
            color:var(--choc); background:var(--surface); border:1.5px solid var(--border); transition:background .15s,color .15s;
        }
        .chip:hover { background:var(--cream-deep); }
        .chip.active { background:var(--choc-dark); border-color:var(--choc-dark); color:#fff; }

        /* ── Table card ── */
        .table-card {
            flex:1; min-height:0;
            background:var(--surface); border-radius:16px;
            border:1px solid var(--border);
            box-shadow:0 4px 18px rgba(59,36,23,0.08);
            display:flex; flex-direction:column; overflow:hidden;
        }

        /* One scroll area holds header + rows so columns always line up
           (even with a scrollbar); narrow screens scroll sideways. */
        .table-scroll { flex:1; min-height:0; overflow:auto; }
        .table-scroll::-webkit-scrollbar { width:6px; height:6px; }
        .table-scroll::-webkit-scrollbar-thumb { background:var(--border); border-radius:3px; }
        .table-inner { min-width:1160px; }

        .grid-cols {
            display:grid; align-items:center; column-gap:14px; padding:0 20px;
            /* ID | Product | Order Time | Customer | Payment | Payment Status | Total | Qty | Order Status | Location */
            grid-template-columns: 64px minmax(0,1.7fr) 84px minmax(0,1.1fr) 76px 112px 128px 48px 150px minmax(0,1.9fr);
        }

        .table-head {
            position:sticky; top:0; z-index:2;
            background:var(--cream-deep); border-bottom:1px solid var(--border);
        }
        .table-head > div {
            padding:11px 0; font-size:.68rem; font-weight:700;
            color:var(--choc-dark); text-transform:uppercase; letter-spacing:.04em;
            white-space:nowrap;
        }

        .row { border-bottom:1px solid var(--border); transition:background .12s; }
        .row:last-child { border-bottom:none; }
        .row:hover { background:var(--cream); }
        .row > div {
            padding:12px 0; font-size:.78rem; min-width:0;
            display:flex; flex-direction:column; justify-content:center; align-items:flex-start; gap:3px;
        }
        .c-id { font-weight:700; color:var(--caramel-deep); }
        .c-num { text-align:right; align-items:flex-end !important; }
        .clamp2 {
            display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;
            overflow:hidden; overflow-wrap:anywhere; line-height:1.35;
        }
        /* Shows the whole text, wrapping onto as many lines as it needs (used for the delivery address) */
        .full-text { overflow-wrap:anywhere; line-height:1.4; }
        .cell-muted { color:var(--muted); font-size:.68rem; }
        .cell-mono { font-family:'Courier New',monospace; font-variant-numeric:tabular-nums; }
        .amount { font-weight:700; white-space:nowrap; }

        /* ── Badges ── */
        .badge {
            display:inline-block; padding:3px 10px; border-radius:1rem;
            font-size:.68rem; font-weight:700; white-space:nowrap; font-family:inherit;
        }
        .badge-done       { background:var(--green-bg); color:var(--green); }
        .badge-cancelled  { background:var(--red-bg);   color:var(--red); }
        .badge-processing { background:var(--amber-bg); color:var(--amber); }
        .badge-ready      { background:#E0F2F1; color:#00695C; }
        .badge-out        { background:#EDE7F6; color:#4527A0; }
        .badge-paid       { background:var(--blue-bg);  color:var(--blue); }
        .badge-unpaid     { background:var(--red-bg);   color:var(--red); }

        /* Status badges are buttons: click one to change it, no edit icon needed */
        button.status-btn {
            border:1.5px solid transparent; cursor:pointer; transition:box-shadow .15s, transform .1s, border-color .15s;
        }
        button.status-btn::after { content:" \25BE"; font-size:.7em; opacity:.6; }
        button.status-btn:hover  { border-color:currentColor; box-shadow:0 2px 8px rgba(59,36,23,0.18); }
        button.status-btn:active { transform:scale(.96); }
        button.status-btn:focus-visible { outline:2px solid var(--caramel); outline-offset:2px; }

        .empty-msg { text-align:center; padding:3rem 1rem; font-size:.85rem; color:var(--muted); }

        /* ── Edit modal ── */
        .modal-overlay {
            position:fixed; inset:0; z-index:1500;
            background:rgba(30,15,5,0.55);
            display:none; align-items:center; justify-content:center;
        }
        .modal-overlay.open { display:flex; }
        .modal-box {
            background:var(--surface); border-radius:16px; padding:24px; width:320px;
            box-shadow:0 20px 50px rgba(30,15,5,0.3);
            border-top:4px solid var(--gold);
        }
        .modal-box h3 { margin:0 0 16px; font-size:15px; color:var(--choc-dark); font-weight:700; }
        .modal-label { font-size:12px; font-weight:700; margin-top:12px; display:block; color:var(--choc); }
        .modal-select {
            width:100%; padding:9px 12px; margin-top:4px;
            border:1.5px solid var(--border); border-radius:8px;
            font-size:13px; background:var(--cream); color:var(--choc-dark);
            font-family:'Inter',sans-serif;
        }
        .modal-select:focus { outline:none; border-color:var(--caramel); }
        .modal-btn-row { display:flex; gap:8px; margin-top:20px; }
        .modal-btn-row button {
            flex:1; padding:10px; border:none; border-radius:8px;
            cursor:pointer; font-size:13px; font-weight:700; font-family:'Inter',sans-serif;
        }
        .btn-save { background:linear-gradient(135deg, var(--caramel), var(--caramel-deep)); color:#fff; box-shadow:0 4px 12px rgba(139,69,19,0.28); }
        .btn-cancel { background:var(--cream-deep); color:var(--choc); }

        .btn-danger-link {
            width:100%; margin-top:10px; padding:9px; background:none; border:1.5px solid #f0c4c4; border-radius:8px;
            color:var(--red); font-size:12px; font-weight:700; cursor:pointer; font-family:'Inter',sans-serif;
        }
        .btn-danger-link:hover { background:var(--red-bg); }

        /* ── Receipt (shown after an order is saved as PAID + Done) ── */
        .rcpt-overlay {
            position:fixed; inset:0; z-index:1800; background:rgba(30,15,5,0.55);
            display:none; align-items:center; justify-content:center; padding:16px;
        }
        .rcpt-overlay.show { display:flex; }
        .rcpt-box {
            background:var(--surface); border-radius:16px; width:100%; max-width:360px; max-height:88vh;
            display:flex; flex-direction:column; overflow:hidden;
            box-shadow:0 20px 50px rgba(30,15,5,0.3); border-top:4px solid var(--caramel);
        }
        .rcpt-header {
            background:var(--cream); padding:10px 18px; display:flex; justify-content:space-between; align-items:center;
            border-bottom:1px solid var(--border);
        }
        .rcpt-header span { font-size:.85rem; font-weight:700; color:var(--choc-dark); }
        .rcpt-close { background:transparent; border:none; font-size:18px; cursor:pointer; color:var(--muted); line-height:1; }
        .rcpt-close:hover { color:var(--choc-dark); }
        .rcpt-body { flex:1; overflow-y:auto; padding:20px; font-family:'Courier New',monospace; font-size:13px; }
        .rcpt-footer { background:var(--cream); padding:10px 16px; border-top:1px solid var(--border); }
        .rcpt-footer button {
            width:100%; padding:10px; border:none; border-radius:8px; cursor:pointer;
            font-family:'Inter',sans-serif; font-size:13px; font-weight:700;
            background:linear-gradient(135deg, var(--caramel), var(--caramel-deep)); color:#fff;
            box-shadow:0 4px 12px rgba(139,69,19,0.28);
        }
        .receipt-divider { text-align:center; color:var(--border); margin:8px 0; letter-spacing:1px; font-size:12px; }
        .rcpt-line { display:flex; justify-content:space-between; font-size:12px; color:#444; margin:2px 0; gap:10px; }

        /* ── Confirmation / message dialog (replaces the browser's "localhost says" box) ── */
        .dlg-overlay {
            position:fixed; inset:0; z-index:2000; background:rgba(30,15,5,0.6);
            display:none; align-items:center; justify-content:center; padding:16px;
        }
        .dlg-overlay.open { display:flex; }
        .dlg-box {
            background:var(--surface); border-radius:16px; padding:24px 22px 20px; width:100%; max-width:360px;
            box-shadow:0 20px 50px rgba(30,15,5,0.35); border-top:4px solid var(--gold); text-align:center;
        }
        .dlg-box.danger { border-top-color:var(--red); }
        .dlg-box h3 { font-size:16px; color:var(--choc-dark); margin-bottom:8px; }
        .dlg-box p  { font-size:13px; color:#6b5a4c; line-height:1.55; white-space:pre-line; }
        .dlg-btns { display:flex; gap:8px; margin-top:20px; }
        .dlg-btns button { flex:1; padding:10px; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-weight:700; font-family:'Inter',sans-serif; }
        .dlg-yes { background:linear-gradient(135deg, var(--caramel), var(--caramel-deep)); color:#fff; }
        .dlg-box.danger .dlg-yes { background:var(--red); }

        pre { margin:0; font-family:inherit; }
    </style>
</head>
<body>

<div class="head-nav">
    <div class="menu-icon-cont"><img src="src/menu.png" alt="" onclick="openMenu()"></div>
    <pre>      Liberty Foodmart</pre>
</div>

<main class="main">
    <div class="nav-cont" id="nav-cont">
        <a href="staff-orderingv2.php" class="navs"><div class="icon"><img src="src/checkout.png" alt=""></div><pre>Create Orders</pre></a>
        <a href="staff-online-orders.php" class="navs active"><div class="icon"><img src="src/online-orders.png" alt=""></div><pre>Online Orders</pre></a>
        <a href="staff-reservation-list.php" class="navs"><div class="icon"><img src="src/booking.png" alt=""></div><pre>Reservation List</pre></a>
        <a href="logout.php" class="navs"><div class="icon"><img src="src/logout.png" alt=""></div><pre>Logout</pre></a>
    </div>
    <div class="overlay" id="overlay" onclick="closeMenu()"></div>

    <div class="page">
        <div class="page-head">
            <div>
                <h1>Online Orders</h1>
                <p class="sub">Orders placed through the customer shop.</p>
            </div>

            <!-- Paid / Unpaid filter (Done orders are hidden) -->
            <div class="seg-toggle">
                <a href="<?= e(list_url('UNPAID', $status_filter)) ?>" class="<?= $filter === 'UNPAID' ? 'active' : '' ?>">Unpaid</a>
                <a href="<?= e(list_url('PAID', $status_filter)) ?>" class="<?= $filter === 'PAID' ? 'active' : '' ?>">Paid</a>
            </div>
        </div>

        <!-- Delivery-status filter -->
        <div class="chips">
            <a href="<?= e(list_url($filter, '')) ?>" class="chip <?= $status_filter === '' ? 'active' : '' ?>">All</a>
            <?php foreach ($statuses as $st): ?>
                <a href="<?= e(list_url($filter, $st)) ?>" class="chip <?= $status_filter === $st ? 'active' : '' ?>"><?= e($st) ?></a>
            <?php endforeach; ?>
        </div>

        <div class="table-card">
            <div class="table-scroll">
              <div class="table-inner">
                <div class="table-head grid-cols">
                    <div>ID</div>
                    <div>Product</div>
                    <div>Order Time</div>
                    <div>Customer</div>
                    <div>Payment</div>
                    <div>Payment Status</div>
                    <div class="c-num">Total</div>
                    <div class="c-num">Qty</div>
                    <div>Order Status</div>
                    <div>Location</div>
                </div>

                <div id="ordersRows">
                <?php if (empty($orders)): ?>
                    <p class="empty-msg">No <?= strtolower(e($filter)) ?> orders<?= $status_filter !== '' ? ' that are ' . strtolower(e($status_filter)) : '' ?>.</p>
                <?php else: ?>
                    <?php foreach ($orders as $order):
                        $dt     = format_order_date($order['created_at']);
                    ?>
                    <div class="row grid-cols"
                         data-order-id="<?= $order['id'] ?>"
                         data-pay="<?= e($order['payment_status']) ?>"
                         data-ord="<?= e($order['order_status']) ?>">
                        <div class="c-id">#<?= $order['id'] ?></div>
                        <div title="<?= e($order['product_summary'] ?? '') ?>"><span class="clamp2"><?= e($order['product_summary'] ?? '—') ?></span></div>
                        <div><?= e($dt['date']) ?><span class="cell-muted"><?= e($dt['time']) ?></span></div>
                        <div title="<?= e($order['customer_name']) ?>"><span class="clamp2"><?= e($order['customer_name']) ?></span></div>
                        <div><?= e($order['payment_method']) ?></div>
                        <div id="pay-<?= $order['id'] ?>">
                            <button type="button"
                                    class="badge status-btn <?= $order['payment_status'] === 'PAID' ? 'badge-paid' : 'badge-unpaid' ?>"
                                    title="Click to change payment status"
                                    onclick="openEditPopup(<?= $order['id'] ?>, 'pay')"><?= e($order['payment_status']) ?></button>
                        </div>
                        <div class="c-num">
                            <span class="amount cell-mono" id="amt-<?= $order['id'] ?>"><?= e(format_price((float)$order['total'])) ?></span>
                        </div>
                        <div class="c-num cell-mono">x<?= (int)$order['total_qty'] ?></div>
                        <div id="ord-<?= $order['id'] ?>">
                            <button type="button"
                                    class="badge status-btn <?= order_status_class($order['order_status']) ?>"
                                    title="Click to change order status"
                                    onclick="openEditPopup(<?= $order['id'] ?>, 'order')"><?= e($order['order_status']) ?></button>
                        </div>
                        <div title="<?= e($order['location']) ?>"><span class="full-text"><?= e($order['location']) ?></span></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
              </div>
            </div>
        </div>
    </div>
</main>

<!-- Edit status modal -->
<div class="modal-overlay" id="edit-overlay" onclick="if(event.target===this) closePopup()">
    <div class="modal-box" id="edit-frame">
        <h3 id="edit-title">Edit Status</h3>

        <label class="modal-label">Payment Status</label>
        <select id="select-payment" class="modal-select">
            <option value="UNPAID">UNPAID</option>
            <option value="PAID">PAID</option>
        </select>

        <label class="modal-label">Order Status</label>
        <select id="select-order" class="modal-select">
            <option value="Processing">Processing</option>
            <option value="Ready for Delivery">Ready for Delivery</option>
            <option value="Out for Delivery">Out for Delivery</option>
            <option value="Done">Done</option>
        </select>

        <div class="modal-btn-row">
            <button class="btn-cancel" onclick="closePopup()">Discard</button>
            <button class="btn-save" onclick="saveStatus()">Save</button>
        </div>
        <button type="button" class="btn-danger-link" onclick="askCancelOrder()">Cancel this order…</button>
    </div>
</div>

<!-- Receipt modal -->
<div class="rcpt-overlay" id="receipt-modal" onclick="if(event.target===this) closeReceipt()">
    <div class="rcpt-box">
        <div class="rcpt-header">
            <span>Order Receipt</span>
            <button type="button" class="rcpt-close" onclick="closeReceipt()">&times;</button>
        </div>
        <div class="rcpt-body" id="receipt-body"></div>
        <div class="rcpt-footer">
            <button type="button" onclick="closeReceipt()">Close</button>
        </div>
    </div>
</div>

<!-- Confirmation / message dialog -->
<div class="dlg-overlay" id="dlg-overlay">
    <div class="dlg-box" id="dlg-box" role="dialog" aria-modal="true" aria-labelledby="dlg-title">
        <h3 id="dlg-title"></h3>
        <p id="dlg-msg"></p>
        <div class="dlg-btns">
            <button type="button" class="btn-cancel" id="dlg-no"></button>
            <button type="button" class="dlg-yes" id="dlg-yes"></button>
        </div>
    </div>
</div>

<script>
let activeOrderId = null;
const TAB_FILTER    = <?= json_encode($filter) ?>;
const STATUS_FILTER = <?= json_encode($status_filter) ?>;

// ── Dialog: a styled modal instead of alert()/confirm() ────
// showDialog({title, message, confirmText, cancelText, tone}) → Promise<boolean>
// Leave cancelText out for a message with a single OK button.
function showDialog({ title, message, confirmText = 'OK', cancelText = null, tone = 'info' }) {
    return new Promise(resolve => {
        const ov = document.getElementById('dlg-overlay');
        const yes = document.getElementById('dlg-yes'), no = document.getElementById('dlg-no');
        document.getElementById('dlg-title').textContent = title;
        document.getElementById('dlg-msg').textContent   = message;
        document.getElementById('dlg-box').className     = 'dlg-box' + (tone === 'danger' ? ' danger' : '');
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

function checkEmpty() {
    if (document.querySelectorAll('.row[data-order-id]').length === 0) {
        document.getElementById('ordersRows').innerHTML =
            '<p class="empty-msg">No ' + TAB_FILTER.toLowerCase() + ' orders' +
            (STATUS_FILTER ? ' that are ' + STATUS_FILTER.toLowerCase() : '') + '.</p>';
    }
}

// ── Nav ────────────────────────────────────────────────────
const navCont = document.getElementById('nav-cont');
const overlay = document.getElementById('overlay');
function openMenu()  { navCont.classList.add('active');    overlay.classList.add('active'); }
function closeMenu() { navCont.classList.remove('active'); overlay.classList.remove('active'); }

// ── Edit popup ────────────────────────────────────────────
// Opened by clicking a Payment Status or Order Status badge.
function openEditPopup(orderId, focus) {
    const row = document.querySelector('.row[data-order-id="' + orderId + '"]');
    if (!row) return;

    activeOrderId = orderId;
    document.getElementById('edit-title').textContent = 'Edit Status — Order #' + orderId;
    document.getElementById('select-payment').value   = row.dataset.pay;
    document.getElementById('select-order').value     = row.dataset.ord;
    document.getElementById('edit-overlay').classList.add('open');
    document.getElementById(focus === 'pay' ? 'select-payment' : 'select-order').focus();
}

function closePopup() {
    document.getElementById('edit-overlay').classList.remove('open');
    activeOrderId = null;
}

function payBtn(id, st) {
    return '<button type="button" class="badge status-btn ' + (st === 'PAID' ? 'badge-paid' : 'badge-unpaid') +
           '" title="Click to change payment status" onclick="openEditPopup(' + id + ', \'pay\')">' + st + '</button>';
}
function ordBtn(id, st) {
    const cls = { 'Done':'badge-done', 'Cancelled':'badge-cancelled', 'Ready for Delivery':'badge-ready', 'Out for Delivery':'badge-out' }[st] || 'badge-processing';
    return '<button type="button" class="badge status-btn ' + cls +
           '" title="Click to change order status" onclick="openEditPopup(' + id + ', \'order\')">' + st + '</button>';
}

// ── Save status via fetch (no page reload) ────────────────
function saveStatus() {
    if (!activeOrderId) return;

    const id            = activeOrderId;
    const paymentStatus = document.getElementById('select-payment').value;
    const orderStatus   = document.getElementById('select-order').value;

    // Same rule the server enforces: no "Done" until the order is paid
    if (orderStatus === 'Done' && paymentStatus !== 'PAID') {
        showDialog({
            title: 'Not paid yet',
            message: 'This order is not paid yet.\nSet the payment status to PAID before marking it Done.',
            tone: 'danger'
        });
        return;
    }

    const body = new URLSearchParams({
        action:         'update_status',
        order_id:       id,
        payment_status: paymentStatus,
        order_status:   orderStatus,
    });

    fetch('staff-online-orders.php', { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) {
                closePopup();
                showDialog({ title: 'Could not save', message: data.error || 'Could not save. Please try again.', tone: 'danger' });
                return;
            }

            const row = document.querySelector('.row[data-order-id="' + id + '"]');
            if (row) { row.dataset.pay = paymentStatus; row.dataset.ord = orderStatus; }

            document.getElementById('pay-' + id).innerHTML = payBtn(id, paymentStatus);
            document.getElementById('ord-' + id).innerHTML = ordBtn(id, orderStatus);

            // Done orders vanish from the list; so does an order whose payment
            // status no longer matches the tab you are looking at.
            const leavesList = orderStatus === 'Done'
                || paymentStatus !== TAB_FILTER
                || (STATUS_FILTER !== '' && orderStatus !== STATUS_FILTER);
            if (leavesList && row) {
                row.remove();
                checkEmpty();
            }
            closePopup();

            // Marked PAID + Done → show the receipt as proof it went through
            if (data.receipt) showReceipt(data.receipt);
        })
        .catch(() => {
            showDialog({ title: 'Could not save', message: 'Failed to save. Please try again.', tone: 'danger' });
        });
}

// ── Receipt ───────────────────────────────────────────────
const VAT_RATE = <?= json_encode((float)get_vat_settings($pdo)['vat_rate']) ?>;
const round2   = n => Math.round((n + Number.EPSILON) * 100) / 100;
const peso2    = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits:2, maximumFractionDigits:2 });

// Customer-supplied text must be escaped before it goes into innerHTML
function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
}

function closeReceipt() { document.getElementById('receipt-modal').classList.remove('show'); }

function showReceipt(o) {
    const now = new Date().toLocaleString('en-PH', {
        year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit', second:'2-digit'
    });
    const placed = new Date(String(o.created_at).replace(' ', 'T'));
    const placedStr = isNaN(placed) ? '' : placed.toLocaleDateString('en-PH', { month:'short', day:'numeric', year:'numeric' })
        + ' ' + placed.toLocaleTimeString('en-PH', { hour:'numeric', minute:'2-digit', hour12:true }).toLowerCase();

    // Prices include VAT, so net + VAT = total
    const totalNum = parseFloat(o.total) || 0;
    let vatAmt = parseFloat(o.vat_amount) || 0;
    let netAmt = parseFloat(o.net_amount) || 0;
    if (!vatAmt && totalNum > 0) {            // older orders saved before VAT tracking
        netAmt = round2(totalNum / (1 + VAT_RATE));
        vatAmt = round2(totalNum - netAmt);
    }
    const discountAmt = parseFloat(o.discount_amount) || 0;
    const isSenior    = Number(o.is_senior_pwd) === 1 && discountAmt > 0;
    const subtotalAmt = parseFloat(o.subtotal) || (totalNum + discountAmt);

    const discountRows = isSenior
        ? `<div class="rcpt-line"><span>Subtotal</span><span>${peso2(subtotalAmt)}</span></div>
           <div class="rcpt-line"><span>Senior/PWD Discount</span><span>-${peso2(discountAmt)}</span></div>`
        : '';

    const itemRows = (o.items || []).map(it => `
                <tr>
                    <td>${esc(it.product_name)}</td>
                    <td style="text-align:center;">${esc(it.quantity)}x</td>
                    <td style="text-align:right;">${peso2(parseFloat(it.unit_price) * parseFloat(it.quantity))}</td>
                </tr>`).join('');

    document.getElementById('receipt-body').innerHTML = `
        <div style="text-align:center;margin-bottom:8px;">
            <div style="font-size:18px;font-weight:bold;letter-spacing:1px;color:var(--choc-dark);">Liberty Foodmart</div>
            <div style="font-size:11px;color:var(--muted);margin-top:2px;">Online Order Receipt</div>
            <div style="display:flex;justify-content:space-between;font-size:10px;color:#aaa;margin-top:6px;">
                <span>${esc(now)}</span>
                <span>Ref: ORD-${esc(o.id)}</span>
            </div>
        </div>
        <div class="receipt-divider">- - - - - - - - - - - - - - - - -</div>
        <div style="font-size:11px;color:#555;margin-bottom:8px;line-height:1.5;">
            <div><b>Customer:</b> ${esc(o.customer_name)}</div>
            <div><b>Contact:</b> ${esc(o.phone)}</div>
            <div><b>Deliver to:</b> ${esc(o.location)}</div>
            ${placedStr ? `<div><b>Ordered:</b> ${esc(placedStr)}</div>` : ''}
            <div><b>Payment:</b> ${esc(o.payment_method)}</div>
        </div>
        <div class="receipt-divider">- - - - - - - - - - - - - - - - -</div>
        <table style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead>
                <tr style="border-bottom:1px dashed var(--border);">
                    <th style="text-align:left;padding-bottom:4px;font-size:11px;color:#555;">Item</th>
                    <th style="text-align:center;padding-bottom:4px;font-size:11px;color:#555;">Qty</th>
                    <th style="text-align:right;padding-bottom:4px;font-size:11px;color:#555;">Amount</th>
                </tr>
            </thead>
            <tbody>${itemRows}
            </tbody>
        </table>
        <div class="receipt-divider">- - - - - - - - - - - - - - - - -</div>
        ${discountRows}
        <div style="display:flex;justify-content:space-between;font-weight:bold;font-size:15px;margin:6px 0;color:var(--choc-dark);">
            <span>TOTAL</span><span>${peso2(totalNum)}</span>
        </div>
        <div class="rcpt-line"><span>VATable Sales (net)</span><span>${peso2(netAmt)}</span></div>
        <div class="rcpt-line"><span>VAT (${Math.round(VAT_RATE * 100)}%) included</span><span>${peso2(vatAmt)}</span></div>
        <div class="rcpt-line"><span>Payment Status</span><span style="color:#2E7D32;font-weight:bold;">PAID</span></div>
        <div class="rcpt-line"><span>Order Status</span><span style="color:#2E7D32;font-weight:bold;">DONE</span></div>
        <div class="receipt-divider">- - - - - - - - - - - - - - - - -</div>
        <div style="text-align:center;font-size:11px;color:var(--muted);line-height:1.6;">
            Thank you for your order!<br>Liberty Foodmart
        </div>`;

    document.getElementById('receipt-modal').classList.add('show');
}

// ── Cancel an order (confirmation modal → hide the row) ───
async function askCancelOrder() {
    if (!activeOrderId) return;
    const id  = activeOrderId;
    const row = document.querySelector('.row[data-order-id="' + id + '"]');
    const paid = row && row.dataset.pay === 'PAID';

    const ok = await showDialog({
        title: 'Cancel order #' + id + '?',
        message: 'This order will be cancelled and its items returned to stock.\n' +
                 'The customer will be notified that the staff cancelled it.' +
                 (paid ? '\n\nThis order is marked PAID, so remember to refund the customer.' : ''),
        confirmText: 'Yes, cancel order',
        cancelText:  'Keep order',
        tone: 'danger'
    });
    if (!ok) return;

    const body = new URLSearchParams({ action: 'cancel_order', order_id: id });
    fetch('staff-online-orders.php', { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
            closePopup();
            if (!data.ok) {
                showDialog({ title: 'Could not cancel', message: data.error || 'Please try again.', tone: 'danger' });
                return;
            }
            if (row) row.remove();
            checkEmpty();
            showDialog({
                title: 'Order cancelled',
                message: 'Order #' + id + ' was cancelled and its items were returned to stock.\nThe customer will see a cancellation notice.'
            });
        })
        .catch(() => {
            closePopup();
            showDialog({ title: 'Could not cancel', message: 'Request failed. Please try again.', tone: 'danger' });
        });
}
</script>

</body>
</html>