<?php
// ── Liberty Foodmart — Reservation List ─────────────────────
require_once 'session.php';
require 'db.php';
require 'vat.php';
require_once 'reservation-stock.php';

require_once 'auth.php';
require_staff($pdo);

// ── Handle AJAX POST actions ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);

    switch ($_POST['action']) {

        // Toggle PAID / UNPAID
        case 'toggle_payment':
            $row = $pdo->prepare('SELECT payment_status FROM reservations WHERE id = ?');
            $row->execute([$id]);
            $res = $row->fetch();
            if (!$res) { echo json_encode(['ok' => false]); exit; }
            $next = $res['payment_status'] === 'PAID' ? 'UNPAID' : 'PAID';
            $pdo->prepare('UPDATE reservations SET payment_status = ? WHERE id = ?')->execute([$next, $id]);
            echo json_encode(['ok' => true, 'payment_status' => $next]);
            exit;

        // Change the pickup status: Processing <-> Ready for Pickup.
        // ("Done" goes through the Complete action below: it also records the
        //  payment, shows the receipt and counts the sale.)
        case 'set_status':
            $want = (string)($_POST['status'] ?? '');
            $map  = ['Processing' => 'Active', 'Ready for Pickup' => 'Ready for Pickup'];   // label -> value stored in the database
            if (!isset($map[$want])) {
                echo json_encode(['ok' => false, 'error' => 'Invalid status.']);
                exit;
            }
            try {
                $pdo->beginTransaction();
                $cur = reservation_lock($pdo, $id);
                if (!$cur) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'error' => 'Reservation not found.']);
                    exit;
                }
                if (!in_array($cur['status'], ['Active', 'Ready for Pickup'], true)) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'error' => 'This reservation is already ' . strtolower($cur['status']) . ', so its status can no longer be changed.']);
                    exit;
                }
                // Stock stays held the whole time: only the label changes
                $pdo->prepare('UPDATE reservations SET status = ? WHERE id = ?')->execute([$map[$want], $id]);
                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('reservation set_status failed: ' . $e->getMessage());
                echo json_encode(['ok' => false, 'error' => 'Could not update the status. Please try again.']);
                exit;
            }
            echo json_encode(['ok' => true, 'status' => $want]);
            exit;

        // Mark Complete (sets status = Complete, payment = PAID)
        case 'complete':
            try {
                $pdo->beginTransaction();

                // Lock the row: a double-click or a second staff device can't
                // complete the same reservation twice.
                $cur = reservation_lock($pdo, $id);
                if (!$cur) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'error' => 'Reservation not found.']);
                    exit;
                }
                if (!in_array($cur['status'], ['Active', 'Ready for Pickup'], true)) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'error' => 'This reservation is already ' . strtolower($cur['status']) . '.']);
                    exit;
                }

                // Reservations saved before stock-holding existed never had their
                // stock taken. Take it now, once. (Newer ones already hold it.)
                if (!(int)$cur['stock_deducted']) {
                    $li = $pdo->prepare('SELECT product_id, product_name AS name, quantity AS qty FROM reservation_items WHERE reservation_id = ?');
                    $li->execute([$id]);
                    $lines = $li->fetchAll();
                    if (!$lines && $cur['product_id']) {   // very old row with no item breakdown
                        $lines = [['product_id' => $cur['product_id'], 'name' => '', 'qty' => $cur['quantity']]];
                    }
                    // Not strict: the goods are being handed over right now, so
                    // this must not block the pickup. Stock just stops at 0.
                    reservation_deduct_stock($pdo, $lines, false);
                }

                $pdo->prepare("UPDATE reservations SET status = 'Complete', payment_status = 'PAID', stock_deducted = 1 WHERE id = ?")->execute([$id]);
                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('reservation complete failed: ' . $e->getMessage());
                echo json_encode(['ok' => false, 'error' => 'Could not complete the reservation. Please try again.']);
                exit;
            }

            // Fetch updated row to return receipt data
            $row = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
            $row->execute([$id]);
            $reservation = $row->fetch();

            // Full per-product breakdown for the receipt (falls back to the
            // single product_name/quantity if this reservation predates the
            // reservation_items table)
            $items_stmt = $pdo->prepare('SELECT product_name, unit_price, quantity FROM reservation_items WHERE reservation_id = ? ORDER BY id');
            $items_stmt->execute([$id]);
            $reservation['items'] = $items_stmt->fetchAll();

            echo json_encode(['ok' => true, 'reservation' => $reservation]);
            exit;

        // "Delete" in the menu is now a CANCEL: the reservation stays on record
        // (status = Cancelled) and its items go back to stock. A Complete
        // reservation is already part of sales, so it can't be removed.
        case 'delete':
            try {
                $pdo->beginTransaction();
                $cur = reservation_lock($pdo, $id);
                if (!$cur) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'error' => 'Reservation not found.']);
                    exit;
                }
                if ($cur['status'] === 'Complete') {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'error' => 'A completed reservation is part of your sales records and cannot be cancelled or deleted.']);
                    exit;
                }
                if (in_array($cur['status'], ['Active', 'Ready for Pickup'], true)) {
                    if ((int)$cur['stock_deducted'] === 1) {
                        reservation_restore_stock($pdo, $id);
                    }
                    $pdo->prepare("UPDATE reservations SET status = 'Cancelled', stock_deducted = 0 WHERE id = ?")->execute([$id]);
                }
                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('reservation cancel failed: ' . $e->getMessage());
                echo json_encode(['ok' => false, 'error' => 'Could not cancel the reservation. Please try again.']);
                exit;
            }
            echo json_encode(['ok' => true]);
            exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// ── Payment filter: ?filter=PAID or UNPAID (default UNPAID) ─
$filter  = strtoupper($_GET['filter'] ?? 'UNPAID');
if (!in_array($filter, ['PAID', 'UNPAID'])) $filter = 'UNPAID';

// ── Query active reservations ────────────────────────────────
// LEFT JOIN + GROUP_CONCAT pulls in every product in the
// reservation (e.g. "Black Forest Cake x1, Mango Cheesecake x2")
// instead of just the rolled-up "first item (+1 more)" summary.
$stmt = $pdo->prepare("
    SELECT r.*,
        GROUP_CONCAT(CONCAT(ri.product_name, ' x', ri.quantity) ORDER BY ri.id SEPARATOR ', ') AS items_full
    FROM reservations r
    LEFT JOIN reservation_items ri ON ri.reservation_id = r.id
    WHERE r.payment_status = ? AND r.status IN ('Active', 'Ready for Pickup')
    GROUP BY r.id
    ORDER BY r.pickup_datetime ASC
");
$stmt->execute([$filter]);
$reservations = $stmt->fetchAll();

// ── Check for upcoming pickups tomorrow ──────────────────────
$tomorrow_start = date('Y-m-d 00:00:00', strtotime('+1 day'));
$tomorrow_end   = date('Y-m-d 23:59:59', strtotime('+1 day'));
$up_stmt = $pdo->prepare("
    SELECT r.*,
        GROUP_CONCAT(CONCAT(ri.product_name, ' x', ri.quantity) ORDER BY ri.id SEPARATOR ', ') AS items_full
    FROM reservations r
    LEFT JOIN reservation_items ri ON ri.reservation_id = r.id
    WHERE r.status IN ('Active', 'Ready for Pickup')
      AND r.pickup_datetime BETWEEN ? AND ?
    GROUP BY r.id
");
$up_stmt->execute([$tomorrow_start, $tomorrow_end]);
$upcoming = $up_stmt->fetchAll();

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function fmt_price(float $v): string {
    return 'Php ' . number_format($v, 2);
}

function fmt_pickup(string $dt): array {
    $d = new DateTime($dt);
    return [
        'date' => $d->format('d/m/Y'),
        'time' => strtolower($d->format('g:ia')),
    ];
}

// Prefer the real item-by-item breakdown; fall back to the old
// summary field for any reservation saved before this table existed.
function product_display(array $res): string {
    return !empty($res['items_full']) ? $res['items_full'] : $res['product_name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservation List — Liberty Foodmart</title>
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

        /* ── Segmented filter toggle ── */
        .seg-toggle {
            display:flex; background:var(--cream-deep); border-radius:2rem; padding:4px;
        }
        .seg-toggle a {
            padding:.5rem 1.3rem; border-radius:1.6rem; font-size:.8rem; font-weight:700;
            color:var(--choc); text-decoration:none; transition:background .2s, color .2s; cursor:pointer;
        }
        .seg-toggle a.active { background:var(--choc-dark); color:#fff; box-shadow:0 4px 12px rgba(59,36,23,0.3); }

        /* ── Table card ── */
        .table-card {
            flex:1; min-height:0;
            background:var(--surface); border-radius:16px;
            border:1px solid var(--border);
            box-shadow:0 4px 18px rgba(59,36,23,0.08);
            display:flex; flex-direction:column; overflow:hidden;
        }

        .grid-cols {
            display:grid;
            grid-template-columns: 56px 1.5fr 1fr 0.9fr 1.1fr 140px 150px 60px 1fr;
        }

        .table-head {
            background:var(--cream-deep);
            border-bottom:1px solid var(--border);
        }
        .table-head > div {
            padding:11px 10px; font-size:.68rem; font-weight:700;
            color:var(--choc-dark); text-transform:uppercase; letter-spacing:.04em;
            white-space:nowrap;
        }

        .table-body { flex:1; overflow-y:auto; }
        .table-body::-webkit-scrollbar { width:6px; }
        .table-body::-webkit-scrollbar-thumb { background:var(--border); border-radius:3px; }

        .reserve-row {
            border-bottom:1px solid var(--border);
            transition:background .12s;
        }
        .reserve-row:hover { background:var(--cream); }
        .reserve-row > div {
            padding:10px; font-size:.76rem; display:flex; flex-direction:column;
            justify-content:center; overflow:hidden; text-overflow:ellipsis;
        }
        .reserve-row > div:nth-child(2) {
            white-space:normal; line-height:1.35; align-self:center;
        }
        .id-cell { flex-direction:row !important; align-items:center; gap:8px; font-weight:700; color:var(--caramel-deep); }
        .cell-muted { color:var(--muted); font-size:.68rem; margin-top:1px; }
        .cell-mono { font-family:'Courier New',monospace; }

        /* ── Badges ── */
        .payment-badge {
            display:inline-block; text-align:center; padding:3px 10px; border-radius:1rem;
            font-size:.68rem; font-weight:700; cursor:pointer; white-space:nowrap;
            transition:filter .15s;
        }
        .payment-badge:hover { filter:brightness(.92); }
        .badge-paid   { background:var(--green-bg); color:var(--green); }
        .badge-unpaid { background:var(--red-bg);   color:var(--red); }
        .payment-status-col { cursor:pointer; }

        .status-badge {
            display:inline-block; text-align:center; padding:3px 10px; border-radius:1rem;
            font-size:.68rem; font-weight:700; cursor:pointer; white-space:nowrap; transition:filter .15s;
        }
        .status-badge::after { content:' ▾'; font-size:.6rem; opacity:.7; }
        .status-badge:hover { filter:brightness(.92); }
        .st-processing { background:#FFF3CD; color:#856404; }
        .st-ready      { background:#E0F2F1; color:#00695C; }

        .empty-msg { text-align:center; padding:3rem 1rem; font-size:.85rem; color:var(--muted); }

        /* ── Context menu ── */
        .reserve-menu {
            display:none; position:fixed; z-index:1600;
            background:var(--surface); border:1px solid var(--border); border-radius:10px;
            box-shadow:0 10px 30px rgba(59,36,23,0.18); padding:6px 0; width:150px;
        }
        .reserve-menu pre {
            color:var(--muted); font-size:.68rem; font-weight:700; letter-spacing:.03em;
            padding:4px 12px 6px; border-bottom:1px solid var(--border); margin:0 0 4px;
        }
        .reserve-menu p {
            margin:0; padding:7px 12px; font-size:.78rem; font-weight:600; cursor:pointer;
            color:var(--choc-dark); transition:background .12s;
        }
        .reserve-menu p:hover { background:var(--cream); }
        .reserve-menu p.current { color:var(--caramel-deep); font-weight:800; }
        .reserve-menu p.current::after { content:' ✓'; }
        .reserve-menu p.menu-sep { height:1px; padding:0; margin:4px 0; background:var(--border); cursor:default; }
        .reserve-menu p.menu-sep:hover { background:var(--border); }
        .reserve-menu p.close-action { color:var(--muted); }
        .reserve-menu p.cancel-action { color:var(--red); }
        .reserve-menu p.cancel-action:hover { background:var(--red-bg); }

        /* ── Toast ── */
        #toast-notif {
            position:fixed; bottom:24px; left:50%; transform:translateX(-50%);
            background:var(--choc-dark); color:#fff; padding:10px 20px;
            border-radius:2rem; font-size:.78rem; font-weight:600; z-index:9999;
            opacity:0; pointer-events:none; transition:opacity .3s;
            box-shadow:0 8px 24px rgba(59,36,23,0.3);
        }

        /* ── Modals ── */
        .modal-overlay {
            position:fixed; inset:0; z-index:1700;
            background:rgba(30,15,5,0.55);
            display:none; align-items:center; justify-content:center;
        }
        .modal-overlay.show { display:flex; }
        .modal-box {
            background:var(--surface); border-radius:16px; width:360px; max-height:88vh;
            display:flex; flex-direction:column; overflow:hidden;
            box-shadow:0 20px 50px rgba(30,15,5,0.3);
            border-top:4px solid var(--caramel);
        }
        .modal-header {
            background:var(--cream); padding:10px 18px;
            display:flex; justify-content:space-between; align-items:center;
            border-bottom:1px solid var(--border);
        }
        .modal-header span { font-size:.85rem; font-weight:700; color:var(--choc-dark); }
        .modal-close { background:transparent; border:none; font-size:18px; cursor:pointer; color:var(--muted); line-height:1; }
        .modal-close:hover { color:var(--choc-dark); }
        .modal-body { flex:1; overflow-y:auto; padding:20px; font-family:'Courier New',monospace; font-size:13px; }
        .modal-footer { background:var(--cream); padding:10px 16px; border-top:1px solid var(--border); }
        .modal-footer button {
            width:100%; padding:10px; border:none; border-radius:8px; cursor:pointer;
            font-family:'Inter',sans-serif; font-size:13px; font-weight:700;
            background:linear-gradient(135deg, var(--caramel), var(--caramel-deep)); color:#fff;
            box-shadow:0 4px 12px rgba(139,69,19,0.28);
        }
        .receipt-divider { text-align:center; color:var(--border); margin:8px 0; letter-spacing:1px; font-size:12px; }

        /* ── Pickup notification ── */
        .pickup-notif-box {
            background:var(--surface); border-radius:16px; width:360px; max-height:85vh;
            display:flex; flex-direction:column; overflow:hidden;
            box-shadow:0 20px 50px rgba(30,15,5,0.3);
            border-top:4px solid var(--gold);
        }
        .pickup-notif-head {
            background:var(--cream-deep); padding:14px 16px;
            display:flex; align-items:center; gap:10px; border-bottom:1px solid var(--border);
        }
        .pickup-bell {
            width:32px; height:32px; border-radius:50%; background:var(--gold);
            display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:16px;
        }
        .pickup-card {
            background:var(--cream); border-radius:8px; padding:10px 12px;
            border-left:3px solid var(--gold); margin-bottom:8px;
        }

        pre { margin:0; font-family:inherit; }

        /* ── Message dialog (replaces the browser's alert / confirm pop-ups) ── */
        .dlg-overlay {
            position:fixed; inset:0; z-index:3000; background:rgba(30,15,5,0.55);
            display:flex; align-items:center; justify-content:center; padding:1rem;
            opacity:0; visibility:hidden; transition:opacity .2s ease, visibility .2s;
        }
        .dlg-overlay.open { opacity:1; visibility:visible; }
        .dlg-box {
            --dlg-color:#C17817; --dlg-bg:#FBF0DC;
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
    </style>
</head>
<body>

<div id="toast-notif"></div>

<div class="head-nav">
    <div class="menu-icon-cont"><img src="src/menu.png" alt="" onclick="openMenu()"></div>
    <pre>      Liberty Foodmart</pre>
</div>

<main class="main">
    <div class="nav-cont" id="nav-cont">
        <a href="staff-orderingv2.php" class="navs"><div class="icon"><img src="src/checkout.png" alt=""></div><pre>Create Orders</pre></a>
        <a href="staff-online-orders.php" class="navs"><div class="icon"><img src="src/online-orders.png" alt=""></div><pre>Online Orders</pre></a>
        <a href="staff-reservation-list.php" class="navs active"><div class="icon"><img src="src/booking.png" alt=""></div><pre>Reservation List</pre></a>
        <a href="logout.php" class="navs"><div class="icon"><img src="src/logout.png" alt=""></div><pre>Logout</pre></a>
    </div>
    <div class="overlay" id="overlay" onclick="closeMenu()"></div>

    <div class="page">
        <div class="page-head">
            <div>
                <h1>Reservation List</h1>
                <p class="sub">Reservations being prepared or waiting for pickup. Click a status to change it.</p>
            </div>

            <!-- Paid / Unpaid filter -->
            <div class="seg-toggle">
                <a class="<?= $filter === 'UNPAID' ? 'active' : '' ?>" onclick="switchFilter('UNPAID')">Unpaid</a>
                <a class="<?= $filter === 'PAID' ? 'active' : '' ?>" onclick="switchFilter('PAID')">Paid</a>
            </div>
        </div>

        <div class="table-card">
            <div class="table-head grid-cols">
                <div>ID</div>
                <div>Product</div>
                <div>Customer</div>
                <div>Contact</div>
                <div>Pickup Date &amp; Time</div>
                <div>Payment Status</div>
                <div>Status</div>
                <div>Qty</div>
                <div>Total Price</div>
            </div>

            <div class="table-body" id="rows-cont">
                <?php if (empty($reservations)): ?>
                    <p class="empty-msg">No <?= strtolower(e($filter)) ?> reservations.</p>
                <?php else: ?>
                    <?php foreach ($reservations as $res):
                        $pickup = fmt_pickup($res['pickup_datetime']);
                    ?>
                    <div class="reserve-row grid-cols" data-id="<?= $res['id'] ?>">
                        <!-- ID + context menu trigger -->
                        <div class="id-cell">
                            <span>#<?= $res['id'] ?></span>
                        </div>

                        <div title="<?= e(product_display($res)) ?>"><?= e(product_display($res)) ?></div>
                        <div><?= e($res['customer_name']) ?></div>
                        <div><?= e($res['contact']) ?></div>
                        <div><?= e($pickup['date']) ?><span class="cell-muted"><?= e($pickup['time']) ?></span></div>

                        <!-- Payment badge — click to toggle -->
                        <div class="payment-status-col" onclick="togglePayment(<?= $res['id'] ?>)" title="Click to toggle payment status">
                            <span class="payment-badge <?= $res['payment_status'] === 'PAID' ? 'badge-paid' : 'badge-unpaid' ?>"
                                  id="pay-badge-<?= $res['id'] ?>">
                                <?= e($res['payment_status']) ?>
                            </span>
                        </div>

                        <!-- Status badge — click to choose Processing / Ready for Pickup / Done -->
                        <div class="payment-status-col" onclick="openStatusMenu(event, <?= (int)$res['id'] ?>)" title="Click to change the status">
                            <?php $is_ready = $res['status'] === 'Ready for Pickup'; ?>
                            <span class="status-badge <?= $is_ready ? 'st-ready' : 'st-processing' ?>" id="st-badge-<?= (int)$res['id'] ?>"><?= $is_ready ? 'Ready for Pickup' : 'Processing' ?></span>
                        </div>

                        <div class="cell-mono">x<?= (int)$res['quantity'] ?></div>
                        <div class="cell-mono"><?= e(fmt_price((float)$res['total_price'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- Status menu (Processing / Ready for Pickup / Done) -->
<div class="reserve-menu" id="status-menu" style="width:170px;">
    <pre id="status-title">Status</pre>
    <p data-s="Processing" onclick="pickStatus('Processing')">Processing</p>
    <p data-s="Ready for Pickup" onclick="pickStatus('Ready for Pickup')">Ready for Pickup</p>
    <p data-s="Done" onclick="pickStatus('Done')">Done</p>
    <p class="menu-sep"></p>
    <p class="cancel-action" onclick="pickStatus('Cancel')">Cancel Reservation</p>
    <p class="close-action" onclick="closeStatusMenu()">✖ Close</p>
</div>

<!-- Receipt modal -->
<div class="modal-overlay" id="receipt-modal" onclick="if(event.target===this) this.classList.remove('show')">
    <div class="modal-box">
        <div class="modal-header">
            <span>Reservation Receipt</span>
            <button class="modal-close" onclick="document.getElementById('receipt-modal').classList.remove('show')">&times;</button>
        </div>
        <div class="modal-body" id="receipt-body"></div>
        <div class="modal-footer">
            <button onclick="document.getElementById('receipt-modal').classList.remove('show')">Close</button>
        </div>
    </div>
</div>

<!-- Upcoming pickup notification modal -->
<?php if (!empty($upcoming)): ?>
<div class="modal-overlay show" id="pickup-notif" onclick="if(event.target===this) this.classList.remove('show')">
    <div class="pickup-notif-box">
        <div class="pickup-notif-head">
            <div class="pickup-bell">🔔</div>
            <div>
                <p style="font-size:14px;font-weight:700;color:var(--choc-dark);">Upcoming Pickups Tomorrow</p>
                <p style="font-size:12px;color:var(--caramel-deep);margin-top:2px;">
                    <?= count($upcoming) ?> reservation<?= count($upcoming) > 1 ? 's' : '' ?>
                    on <?= date('M j, Y', strtotime('+1 day')) ?>
                </p>
            </div>
            <button onclick="document.getElementById('pickup-notif').classList.remove('show')"
                    style="margin-left:auto;background:transparent;border:none;cursor:pointer;color:var(--caramel-deep);font-size:20px;line-height:1;">&#x2715;</button>
        </div>
        <div style="padding:12px 16px;overflow-y:auto;flex:1;">
            <?php foreach ($upcoming as $u):
                $isPaid = $u['payment_status'] === 'PAID';
                $upPickup = fmt_pickup($u['pickup_datetime']);
            ?>
            <div class="pickup-card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px;">
                    <span style="font-size:13px;font-weight:600;color:var(--choc-dark);"><?= e(product_display($u)) ?></span>
                    <span class="payment-badge <?= $isPaid ? 'badge-paid' : 'badge-unpaid' ?>" style="cursor:default;">
                        <?= e($u['payment_status']) ?>
                    </span>
                </div>
                <p style="font-size:12px;color:var(--muted);">Order #<?= $u['id'] ?> &nbsp;&middot;&nbsp; <?= e($u['customer_name']) ?></p>
                <p style="margin-top:3px;font-size:12px;color:var(--muted);">Pickup: <?= e($upPickup['date']) ?> <?= e($upPickup['time']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="modal-footer">
            <button onclick="document.getElementById('pickup-notif').classList.remove('show')">Dismiss</button>
        </div>
    </div>
</div>
<?php endif; ?>

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
// VAT rate from the settings table (same source the server uses when saving)
const VAT_RATE = <?= json_encode((float)get_vat_settings($pdo)['vat_rate']) ?>;
const round2   = n => Math.round((n + Number.EPSILON) * 100) / 100;
const peso2    = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits:2, maximumFractionDigits:2 });
// ── Nav ────────────────────────────────────────────────────
const navCont = document.getElementById('nav-cont');
const overlay = document.getElementById('overlay');
function openMenu()  { navCont.classList.add('active');    overlay.classList.add('active'); }
function closeMenu() { navCont.classList.remove('active'); overlay.classList.remove('active'); }

// ── Filter tab switch ──────────────────────────────────────
function switchFilter(f) {
    window.location.href = 'staff-reservation-list.php?filter=' + f;
}

// ── Toast ──────────────────────────────────────────────────
let toastTimer;
function showToast(msg) {
    const t = document.getElementById('toast-notif');
    t.textContent  = msg;
    t.style.opacity = '1';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.style.opacity = '0', 2500);
}

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
    if (dlgResolve) dlgResolve(false);

    document.getElementById('dlg-box').className = 'dlg-box t-' + type;
    document.getElementById('dlg-icon').innerHTML = DLG_ICONS[type] || DLG_ICONS.info;
    document.getElementById('dlg-title').textContent = title;
    document.getElementById('dlg-msg').textContent   = message;

    const actions = document.getElementById('dlg-actions');
    actions.innerHTML = '';
    if (cancelText) {
        const c = document.createElement('button');
        c.type = 'button'; c.className = 'dlg-btn-cancel'; c.textContent = cancelText;
        c.onclick = () => closeDialog(false);
        actions.appendChild(c);
    }
    const ok = document.createElement('button');
    ok.type = 'button'; ok.className = 'dlg-btn-main'; ok.textContent = confirmText;
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
const notify   = (message, type = 'info', title = '') => showDialog({ type, title, message });
const netError = () => notify('Could not reach the server. Please check the connection and try again.', 'error', 'Connection problem');

document.getElementById('dlg-overlay').addEventListener('click', e => { if (e.target.id === 'dlg-overlay') closeDialog(false); });
document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && document.getElementById('dlg-overlay').classList.contains('open')) closeDialog(false);
});

// ── Reservation actions (cancel / complete) ────────────────
async function reservationAction(action, id) {
    if (!id) return;

    if (action === 'delete') {
        const ok = await showDialog({
            type: 'error', title: 'Cancel reservation?',
            message: 'Reservation #' + id + ' will be cancelled and its items returned to stock.\nThe customer will see it as Cancelled.',
            confirmText: 'Cancel Reservation', cancelText: 'Keep It'
        });
        if (!ok) return;
    }
    if (action === 'complete') {
        const ok = await showDialog({
            type: 'question', title: 'Mark as complete?',
            message: 'Reservation #' + id + ' will be marked as complete and a receipt will be shown.',
            confirmText: 'Mark Complete', cancelText: 'Cancel'
        });
        if (!ok) return;
    }

    post(action, id, function (data) {
        if (!data.ok) { notify(data.error || 'The action could not be completed. Please try again.', 'error', 'Action failed'); return; }

        if (action === 'delete') {
            removeRow(id);
            showToast('Reservation #' + id + ' cancelled');
        }

        if (action === 'complete') {
            removeRow(id);
            showToast('Reservation #' + id + ' marked complete');
            showReceipt(data.reservation);
        }
    });
}

// ── Status menu (Processing / Ready for Pickup / Done) ─────
let stId = null;
const stMenu = document.getElementById('status-menu');

function openStatusMenu(e, id) {
    e.stopPropagation();
    stId = id;
    const badge   = document.getElementById('st-badge-' + id);
    const current = badge ? badge.textContent.trim() : '';
    document.getElementById('status-title').textContent = 'Order #' + id + ' status';
    stMenu.querySelectorAll('p[data-s]').forEach(p => p.classList.toggle('current', p.dataset.s === current));
    const rect = e.currentTarget.getBoundingClientRect();
    stMenu.style.top  = (rect.bottom + 4) + 'px';
    stMenu.style.left = rect.left + 'px';
    stMenu.style.display = 'block';
}
function closeStatusMenu() { stMenu.style.display = 'none'; stId = null; }
document.addEventListener('click', function (e) {
    if (!stMenu.contains(e.target)) closeStatusMenu();
});

function pickStatus(status) {
    if (!stId) return;
    const id      = stId;
    const badge   = document.getElementById('st-badge-' + id);
    const current = badge ? badge.textContent.trim() : '';
    closeStatusMenu();
    if (status === current) return;

    // "Done" is the Complete flow: confirmation, payment = PAID, receipt, counted in sales
    if (status === 'Done')   { reservationAction('complete', id); return; }
    // "Cancel" asks for confirmation, returns the items to stock and hides the reservation
    if (status === 'Cancel') { reservationAction('delete', id);   return; }

    post('set_status', id, function (data) {
        if (!data.ok) { notify(data.error || 'The status could not be updated. Please try again.', 'error', 'Update failed'); return; }
        if (badge) {
            badge.textContent = data.status;
            badge.className   = 'status-badge ' + (data.status === 'Ready for Pickup' ? 'st-ready' : 'st-processing');
        }
        showToast('Reservation #' + id + ' is now ' + data.status);
    }, { status });
}

// ── Payment toggle ─────────────────────────────────────────
async function togglePayment(id) {
    // Say exactly what will happen: PAID -> UNPAID or UNPAID -> PAID
    const badge   = document.getElementById('pay-badge-' + id);
    const current = badge ? badge.textContent.trim() : '';
    const next    = current === 'PAID' ? 'UNPAID' : 'PAID';

    const ok = await showDialog({
        type: 'question', title: 'Change payment status?',
        message: current
            ? 'Reservation #' + id + ' is currently ' + current + '.\nMark it as ' + next + '?'
            : 'Toggle the payment status of Reservation #' + id + '?',
        confirmText: current ? 'Mark as ' + next : 'Yes, change', cancelText: 'Cancel'
    });
    if (!ok) return;

    post('toggle_payment', id, function (data) {
        if (!data.ok) { notify('The payment status could not be updated. Please try again.', 'error', 'Update failed'); return; }
        const badge = document.getElementById('pay-badge-' + id);
        if (badge) {
            badge.textContent  = data.payment_status;
            badge.className    = 'payment-badge ' + (data.payment_status === 'PAID' ? 'badge-paid' : 'badge-unpaid');
        }
        showToast('Order #' + id + ' marked as ' + data.payment_status);

        // If now PAID and we're on UNPAID tab, remove from list
        const currentFilter = '<?= $filter ?>';
        if (currentFilter === 'UNPAID' && data.payment_status === 'PAID') {
            removeRow(id);
        }
        if (currentFilter === 'PAID' && data.payment_status === 'UNPAID') {
            removeRow(id);
        }
    });
}

// ── HTML escaping ──────────────────────────────────────────
// Anything that came from a customer (name, contact, product text)
// must be escaped before it goes into innerHTML, otherwise a name like
// <img src=x onerror=...> would run inside the staff member's session.
function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
    }[c]));
}

// ── Receipt modal ──────────────────────────────────────────
function showReceipt(res) {
    const now = new Date().toLocaleString('en-PH', {
        year:'numeric', month:'short', day:'numeric',
        hour:'2-digit', minute:'2-digit', second:'2-digit'
    });

    const pickup = new Date(res.pickup_datetime);
    const pickupStr = pickup.toLocaleDateString('en-PH', { month:'short', day:'numeric', year:'numeric' })
        + ' ' + pickup.toLocaleTimeString('en-PH', { hour:'numeric', minute:'2-digit', hour12:true }).toLowerCase();

    // Money breakdown. Prices include VAT, so net + VAT = total.
    // Reservations saved before VAT tracking existed have vat_amount = 0,
    // so for those the split is worked out here from the total.
    const totalNum = parseFloat(res.total_price) || 0;
    let vatAmt = parseFloat(res.vat_amount) || 0;
    let netAmt = parseFloat(res.net_amount) || 0;
    if (!vatAmt && totalNum > 0) {
        netAmt = round2(totalNum / (1 + VAT_RATE));
        vatAmt = round2(totalNum - netAmt);
    }
    const discountAmt = parseFloat(res.discount_amount) || 0;
    const isSenior    = Number(res.is_senior_pwd) === 1 && discountAmt > 0;
    const subtotalAmt = parseFloat(res.subtotal) || (totalNum + discountAmt);

    const discountRows = isSenior
        ? `<div style="display:flex;justify-content:space-between;font-size:12px;color:#444;margin:2px 0;"><span>Subtotal</span><span>${peso2(subtotalAmt)}</span></div>
           <div style="display:flex;justify-content:space-between;font-size:12px;color:#444;margin:2px 0;"><span>Senior/PWD Discount</span><span>-${peso2(discountAmt)}</span></div>`
        : '';
    const total = totalNum.toLocaleString('en-PH', { minimumFractionDigits:2 });

    // Prefer the real item breakdown; fall back to the single summary
    // line for any reservation saved before reservation_items existed.
    const items = (res.items && res.items.length)
        ? res.items
        : [{ product_name: res.product_name, unit_price: res.total_price / res.quantity, quantity: res.quantity }];

    const itemRows = items.map(it => `
                <tr>
                    <td>${esc(it.product_name)}</td>
                    <td style="text-align:center;">${esc(it.quantity)}x</td>
                    <td style="text-align:right;">₱${parseFloat(it.unit_price * it.quantity).toLocaleString('en-PH', { minimumFractionDigits:2 })}</td>
                </tr>`).join('');

    document.getElementById('receipt-body').innerHTML = `
        <div style="text-align:center;margin-bottom:8px;">
            <div style="font-size:18px;font-weight:bold;letter-spacing:1px;color:var(--choc-dark);">Liberty Foodmart</div>
            <div style="font-size:11px;color:var(--muted);margin-top:2px;">Reservation Receipt</div>
            <div style="display:flex;justify-content:space-between;font-size:10px;color:#aaa;margin-top:6px;">
                <span>${now}</span>
                <span>Ref: RES-${esc(res.id)}</span>
            </div>
        </div>
        <div class="receipt-divider">- - - - - - - - - - - - - - - - -</div>
        <div style="font-size:11px;color:#555;margin-bottom:8px;">
            <div><b>Customer:</b> ${esc(res.customer_name)}</div>
            <div><b>Contact:</b> ${esc(res.contact)}</div>
            <div><b>Pickup:</b> ${pickupStr}</div>
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
            <span>TOTAL</span><span>₱${total}</span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:12px;color:#444;margin:2px 0;">
            <span>VATable Sales (net)</span><span>${peso2(netAmt)}</span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:12px;color:#444;margin:2px 0;">
            <span>VAT (${Math.round(VAT_RATE * 100)}%) included</span><span>${peso2(vatAmt)}</span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:12px;color:#444;margin:2px 0;">
            <span>Payment Status</span>
            <span style="color:#2E7D32;font-weight:bold;">PAID </span>
        </div>
        <div class="receipt-divider">- - - - - - - - - - - - - - - - -</div>
        <div style="text-align:center;font-size:11px;color:var(--muted);line-height:1.6;">
            Thank you for your reservation!<br>Liberty Foodmart
        </div>`;

    document.getElementById('receipt-modal').classList.add('show');
}

// ── Helpers ────────────────────────────────────────────────
function removeRow(id) {
    const row = document.querySelector('[data-id="' + id + '"]');
    if (row) row.remove();
    if (document.querySelectorAll('.reserve-row').length === 0) {
        document.getElementById('rows-cont').innerHTML =
            '<p class="empty-msg">No reservations in this list.</p>';
    }
}

function post(action, id, cb, extra = {}) {
    const body = new URLSearchParams({ action, id, ...extra });
    fetch('staff-reservation-list.php', { method:'POST', body })
        .then(r => r.json())
        .then(cb)
        .catch(netError);
}
</script>

</body>
</html>