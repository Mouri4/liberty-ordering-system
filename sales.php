<?php
// ── Liberty Foodmart — Sales Analytics ──────────────────────
require_once 'session.php';
require 'db.php';
require 'vat.php';

require_once 'auth.php';
require_staff($pdo);

$rates    = get_vat_settings($pdo);
$vat_rate = $rates['vat_rate'];

// ── Date range from GET, default to current month ────────────
$today      = date('Y-m-d');
$first      = date('Y-m-01');
$start      = $_GET['start']  ?? $first;
$end        = $_GET['end']    ?? $today;
$status_filter = $_GET['status'] ?? 'all';

// Sanitize dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = $first;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = $today;

// A backwards range (start after end) used to be silently "fixed" by setting
// start = end, so the page showed something the person never asked for.
// Now it's reported, the report falls back to the default range, and the
// date boxes keep what was typed so the mistake is visible and easy to fix.
$date_error = '';
$in_start   = $start;    // what the date boxes display
$in_end     = $end;
if ($start > $end) {
    $date_error = 'Start date cannot be later than the end date. The report below is showing the current month instead.';
    $start = $first;
    $end   = $today;
}

// Map UI status filter to DB value
// For orders:      Done / Processing / Cancelled
// For reservations: Complete / Active / Cancelled
// 'all' shows everything, 'Done' shows both Done orders + Complete reservations
$allowed_statuses = ['all', 'Done', 'Processing', 'Cancelled'];
if (!in_array($status_filter, $allowed_statuses)) $status_filter = 'all';

// ── 1. Query orders + items (item-level, for aggregates) ─────
// oi.line_total is already net-of-discount (VAT still included) —
// it's what price_order() computed and stored at checkout time,
// so charts built from it automatically reflect any Senior/PWD
// discount without recalculating anything here.
$order_rows = $pdo->prepare("
    SELECT
        o.id,
        o.order_status,
        o.created_at,
        DATE(o.created_at)                    AS order_date,
        oi.product_name,
        oi.quantity                           AS qty,
        oi.line_total                         AS line_total
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    WHERE DATE(o.created_at) BETWEEN ? AND ?
    ORDER BY o.created_at DESC
");
$order_rows->execute([$start, $end]);
$order_rows = $order_rows->fetchAll();

// ── 2. Query completed reservations (item-level equivalent) ──
// LEFT JOIN reservation_items so a reservation with several products
// contributes one row per product (accurate per-product revenue),
// falling back to the reservation's own summary fields for any
// reservation saved before the reservation_items table existed.
//
// reservation_items has no per-line discount column (the Senior/PWD
// discount on a reservation is one flat 5% off the whole thing, not
// tracked per product) — so each item's share of the discount is
// prorated using the reservation's own total/subtotal ratio, which
// distributes it exactly with no rounding drift across items.
$res_rows = $pdo->prepare("
    SELECT
        r.id,
        r.status,
        r.created_at,
        r.subtotal                                       AS res_subtotal,
        r.total_price                                     AS res_total,
        DATE(r.created_at)                              AS order_date,
        COALESCE(ri.product_name, r.product_name)        AS product_name,
        COALESCE(ri.quantity, r.quantity)                 AS qty,
        COALESCE(ri.unit_price * ri.quantity, r.subtotal) AS gross_line
    FROM reservations r
    LEFT JOIN reservation_items ri ON ri.reservation_id = r.id
    WHERE DATE(r.created_at) BETWEEN ? AND ?
    ORDER BY r.created_at DESC
");
$res_rows->execute([$start, $end]);
$res_rows = $res_rows->fetchAll();

// ── 3. Orders table rows (one per order, for the table display) ─
$orders_table_raw = $pdo->prepare("
    SELECT
        o.id,
        o.order_status                        AS status,
        DATE(o.created_at)                    AS order_date,
        TIME_FORMAT(o.created_at, '%h:%i %p') AS order_time,
        SUM(oi.quantity)                      AS total_qty,
        GROUP_CONCAT(CONCAT(oi.product_name, ' ', oi.quantity, 'x') ORDER BY oi.id SEPARATOR ', ') AS products,
        o.subtotal, o.discount_amount, o.total AS total_price, o.net_amount, o.vat_amount, o.is_senior_pwd,
        'order'                               AS source
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    WHERE DATE(o.created_at) BETWEEN ? AND ?
    GROUP BY o.id
    ORDER BY o.created_at DESC
");
$orders_table_raw->execute([$start, $end]);
$orders_table_raw = $orders_table_raw->fetchAll();

// ── 4. Reservations table rows (one per reservation) ─────────
$res_table_raw = $pdo->prepare("
    SELECT
        r.id,
        r.status,
        DATE(r.created_at)                      AS order_date,
        TIME_FORMAT(r.created_at, '%h:%i %p')   AS order_time,
        COALESCE(SUM(ri.quantity), r.quantity)                        AS total_qty,
        COALESCE(GROUP_CONCAT(CONCAT(ri.product_name, ' ', ri.quantity, 'x') ORDER BY ri.id SEPARATOR ', '), CONCAT(r.product_name, ' ', r.quantity, 'x')) AS products,
        r.subtotal, r.discount_amount, r.total_price, r.net_amount, r.vat_amount, r.is_senior_pwd,
        'reservation'                            AS source
    FROM reservations r
    LEFT JOIN reservation_items ri ON ri.reservation_id = r.id
    WHERE DATE(r.created_at) BETWEEN ? AND ?
    GROUP BY r.id
    ORDER BY r.created_at DESC
");
$res_table_raw->execute([$start, $end]);
$res_table_raw = $res_table_raw->fetchAll();

// ── 5. Merge into one unified table list ─────────────────────
// Normalise both sets to the same keys so the table HTML can
// loop over a single array.
$orders_table = [];

foreach ($orders_table_raw as $o) {
    $orders_table[] = [
        'id'              => 'ORD-' . $o['id'],
        'raw_id'          => $o['id'],
        // Ready for Delivery / Out for Delivery are still "in progress" for sales purposes
        'order_status'    => in_array($o['status'], ['Ready for Delivery', 'Out for Delivery'], true) ? 'Processing' : $o['status'],   // Done / Processing / Cancelled
        'order_date'      => $o['order_date'],
        'order_time'      => $o['order_time'],
        'total_qty'       => $o['total_qty'],
        'total_price'     => $o['total_price'],
        'subtotal'        => $o['subtotal'],
        'discount_amount' => $o['discount_amount'],
        'net_amount'      => $o['net_amount'],
        'vat_amount'      => $o['vat_amount'],
        'is_senior_pwd'   => (bool)$o['is_senior_pwd'],
        'products'        => $o['products'],
        'source'          => 'order',
    ];
}

foreach ($res_table_raw as $r) {
    // Map reservation status to the same labels as orders
    // Complete → Done,  Active → Processing,  Cancelled → Cancelled
    $mapped_status = match($r['status']) {
        'Complete'  => 'Done',
        'Cancelled' => 'Cancelled',
        default     => 'Processing',
    };
    $orders_table[] = [
        'id'              => 'RES-' . $r['id'],
        'raw_id'          => $r['id'],
        'order_status'    => $mapped_status,
        'order_date'      => $r['order_date'],
        'order_time'      => $r['order_time'],
        'total_qty'       => $r['total_qty'],
        'total_price'     => $r['total_price'],
        'subtotal'        => $r['subtotal'],
        'discount_amount' => $r['discount_amount'],
        'net_amount'      => $r['net_amount'],
        'vat_amount'      => $r['vat_amount'],
        'is_senior_pwd'   => (bool)$r['is_senior_pwd'],
        'products'        => $r['products'],
        'source'          => 'reservation',
    ];
}

// Sort merged table by date desc
usort($orders_table, fn($a, $b) =>
    strcmp($b['order_date'] . $b['order_time'], $a['order_date'] . $a['order_time'])
);

// ── 6. Build aggregates ──────────────────────────────────────
// Total Revenue is reported NET-OF-VAT (the shop's actual take —
// VAT collected is held for BIR, not revenue) and AFTER the
// Senior/PWD discount. VAT Collected and Discounts Given are
// their own metrics so nothing here hides where the money went.
//
// The summary cards use the authoritative net_amount/vat_amount/
// discount_amount already computed and stored at checkout time
// (via price_order() in vat.php) — no re-deriving, no rounding
// drift. The day/product CHARTS need item-level granularity that
// only exists by re-deriving from each line, so those use a
// separate pass below.
$total_revenue     = 0.00;   // net-of-VAT, after discount
$vat_collected     = 0.00;
$discounts_given   = 0.00;
$completed_count   = 0;
$units_sold        = 0;

foreach ($orders_table as $row) {
    if ($row['order_status'] === 'Done') {
        $total_revenue   += (float)$row['net_amount'];
        $vat_collected   += (float)$row['vat_amount'];
        $discounts_given += (float)$row['discount_amount'];
        $units_sold      += (int)$row['total_qty'];
        $completed_count++;
    }
}

$total_revenue   = round($total_revenue, 2);
$vat_collected   = round($vat_collected, 2);
$discounts_given = round($discounts_given, 2);

// ── Day / product breakdown (net-of-VAT, discount already applied) ──
$revenue_by_day  = [];
$revenue_by_prod = [];

// Orders: oi.line_total is already discount-adjusted (VAT-inclusive) —
// just convert to net for the chart.
foreach ($order_rows as $row) {
    if ($row['order_status'] === 'Done') {
        $net = round((float)$row['line_total'] / (1 + $vat_rate), 2);
        $revenue_by_day[$row['order_date']]    = ($revenue_by_day[$row['order_date']] ?? 0) + $net;
        $revenue_by_prod[$row['product_name']] = ($revenue_by_prod[$row['product_name']] ?? 0) + $net;
    }
}

// Reservations: no per-item discount column, so each item's share of
// the reservation's flat discount is prorated using that reservation's
// own total/subtotal ratio — this distributes it exactly, with the
// per-item amounts always summing back to the reservation's real total.
foreach ($res_rows as $row) {
    if ($row['status'] === 'Complete') {
        $ratio           = ((float)$row['res_subtotal'] > 0) ? ((float)$row['res_total'] / (float)$row['res_subtotal']) : 1.0;
        $discounted_line = round((float)$row['gross_line'] * $ratio, 2);
        $net             = round($discounted_line / (1 + $vat_rate), 2);
        $revenue_by_day[$row['order_date']]    = ($revenue_by_day[$row['order_date']] ?? 0) + $net;
        $revenue_by_prod[$row['product_name']] = ($revenue_by_prod[$row['product_name']] ?? 0) + $net;
    }
}

$total_entries = count($orders_table);

// ── Build daily revenue array for chart (fill gaps with 0) ──
function dates_in_range(string $start, string $end): array {
    $dates = [];
    $cur   = new DateTime($start);
    $last  = new DateTime($end);
    while ($cur <= $last) {
        $dates[] = $cur->format('Y-m-d');
        $cur->modify('+1 day');
    }
    return $dates;
}

$all_dates      = dates_in_range($start, $end);
$rev_labels     = [];
$rev_data       = [];
$rev_colors     = [];

foreach ($all_dates as $d) {
    $rev_labels[] = date('m/d', strtotime($d));
    $val          = $revenue_by_day[$d] ?? 0;
    $rev_data[]   = $val;
    $rev_colors[] = $val > 0 ? '#f7ce00ba' : '#85B7EB';
}

// ── Product chart data ───────────────────────────────────────
arsort($revenue_by_prod);
$prod_labels = array_keys($revenue_by_prod);
$prod_data   = array_values($revenue_by_prod);
$prod_colors = ['#185FA5', '#3B6D11', '#D85A30', '#854F0B', '#9932CC'];

// ── Status badge helper ──────────────────────────────────────
function status_badge(string $status): string {
    $map = [
        'Done'       => ['Completed', 'badge-done'],
        'Processing' => ['Pending',   'badge-pend'],
        'Cancelled'  => ['Voided',    'badge-void'],
    ];
    [$label, $cls] = $map[$status] ?? [$status, ''];
    return '<span class="status-badge ' . $cls . '">' . htmlspecialchars($label) . '</span>';
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// Filter merged table by status
$filtered_table = $status_filter === 'all'
    ? $orders_table
    : array_filter($orders_table, fn($o) => $o['order_status'] === $status_filter);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Analytics — Liberty Foodmart</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
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
        }

        @font-face { src:url(src/Kavoon-Regular.ttf); font-family:kavoon; }

        * { box-sizing:border-box; margin:0; padding:0; }
        body { width:100%; min-height:100vh; background:var(--cream); font-family:'Inter', Arial, sans-serif; color:var(--choc-dark); }
        pre { margin:0; font-family:inherit; }

        /* ── Header ── */
        .header {
            height:52px; background:var(--surface); display:flex; align-items:center;
            position:sticky; top:0; z-index:800; border-bottom:1px solid var(--border);
            box-shadow:0 2px 8px rgba(59,36,23,0.08);
        }
        .header p { font-family:kavoon; color:var(--caramel-deep); margin-left:4%; font-size:1.1rem; letter-spacing:.02em; }
        .menu-icon-cont { margin-left:2%; width:2%; height:60%; cursor:pointer; }
        .menu-icon-cont img { width:100%; height:100%; object-fit:cover; }

        .main { width:100%; display:flex; }

        /* ── Nav ── */
        .nav-cont {
            position:fixed; top:0; left:0; width:220px; height:100%; padding:55px 14px 20px;
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

        .main-sales { width:100%; display:flex; flex-direction:column; align-items:center; padding-bottom:40px; }

        /* ── Report header ── */
        .Report-date { margin-top:1.8rem; width:90%; display:flex; }
        .Report-date-l { display:flex; flex-direction:column; width:50%; gap:.2rem; }
        .Report-date-l h3 { font-family:'Playfair Display', serif; font-weight:700; font-size:1.5rem; color:var(--choc-dark); }
        .Report-date-l p { color:var(--muted); font-size:.85rem; }

        /* ── Filter bar ── */
        .filter-bar { display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px; width:90%; margin:1.2rem auto; }
        .filter-group { display:flex; flex-direction:column; gap:4px; }
        .filter-group label { font-size:.7rem; font-weight:700; color:var(--choc); }
        .filter-group input[type="date"],
        .filter-group select {
            padding:8px 12px; border-radius:8px; border:1.5px solid var(--border);
            background:var(--surface); font-family:'Inter',sans-serif; cursor:pointer; font-size:.8rem;
            color:var(--choc-dark);
        }
        .filter-group input[type="date"]:focus,
        .filter-group select:focus { outline:none; border-color:var(--caramel); }
        .filter-btn {
            height:36px; padding:0 20px; border-radius:8px; border:none; cursor:pointer;
            font-family:'Inter',sans-serif; font-size:.8rem; font-weight:700; color:#fff;
            background:linear-gradient(135deg, var(--caramel), var(--caramel-deep));
            box-shadow:0 4px 12px rgba(139,69,19,0.28);
        }
        .filter-btn:hover { filter:brightness(1.05); }
        .filter-btn:disabled { opacity:.45; cursor:not-allowed; filter:none; }
        .filter-group input[type="date"].invalid { border-color:var(--red); background:var(--red-bg); }
        .date-error {
            flex-basis:100%; background:var(--red-bg); color:var(--red); border:1px solid #f0c4c4;
            border-radius:8px; padding:.55rem .85rem; font-size:.78rem; font-weight:600; line-height:1.4;
        }
        .date-error[hidden] { display:none; }
        .download-btn { background:var(--choc-dark); box-shadow:0 4px 12px rgba(59,36,23,0.28); }

        /* ── Metric cards ── */
        .metrics-grid { width:90%; display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; margin:0 auto 1.6rem auto; }
        .metric-card {
            background:var(--surface); border-radius:14px; border:1px solid var(--border);
            box-shadow:0 4px 14px rgba(59,36,23,0.08); padding:16px 18px;
            display:flex; flex-direction:column;          /* same layout in every card */
        }
        /* keep the 1px border (transparent) so this card's content lines up with the others */
        .metric-card#revenue-cont {
            background:linear-gradient(135deg, var(--caramel), var(--caramel-deep)); color:#fff; border-color:transparent;
        }
        .metric-label { font-size:.7rem; color:var(--muted); margin-bottom:6px; font-weight:600; line-height:1.3; }
        #revenue-cont .metric-label { color:rgba(255,255,255,0.85); }
        .metric-value { font-size:1.4rem; font-weight:800; color:var(--choc-dark); line-height:1.2; margin-top:auto; }  /* numbers share one baseline even if a label wraps */
        #revenue-cont .metric-value { color:#fff; }
        .metric-sub { font-size:.68rem; color:var(--muted); margin-top:3px; line-height:1.3; }
        #revenue-cont .metric-sub { color:rgba(255,255,255,0.75); }

        /* ── Chart sections ── */
        .sales-tag { width:90%; }
        .sales-tag h3 { font-family:'Playfair Display', serif; font-weight:700; font-size:1.1rem; color:var(--choc-dark); }
        .sales-tag hr { border:none; border-top:1px solid var(--border); width:100%; margin-top:.4rem; }
        .report-dl { width:90%; display:flex; justify-content:space-between; margin-bottom:8px; align-items:flex-end; }
        .report-dl-l p { font-size:.8rem; color:var(--muted); }
        .report-dl-r h3 { color:var(--caramel-deep); cursor:pointer; font-size:.85rem; font-weight:700; }
        .report-dl-r h3:hover { text-decoration:underline; }

        .chart-card {
            width:90%; height:260px; margin-top:1rem; background:var(--surface);
            border:1px solid var(--border); border-radius:14px; padding:16px;
            box-shadow:0 4px 14px rgba(59,36,23,0.06);
        }

        /* ── Orders table ── */
        .table-card {
            margin-top:1.8rem; width:90%; border-radius:16px; background:var(--surface);
            border:1px solid var(--border); box-shadow:0 4px 18px rgba(59,36,23,0.08);
            overflow:hidden;
        }
        .title-recent { padding:14px 20px; display:flex; align-items:center; justify-content:space-between; gap:8px; border-bottom:1px solid var(--border); flex-wrap:wrap; }
        .title-recent p { font-family:'Playfair Display', serif; font-weight:700; font-size:1.1rem; color:var(--choc-dark); }
        .title-recent .meta { font-size:.75rem; color:var(--muted); }

        /* One scroll container for header + rows, so columns always line up
           (even with a scrollbar) and narrow screens scroll sideways. */
        .table-scroll { max-height:460px; overflow:auto; }
        .table-scroll::-webkit-scrollbar { width:6px; height:6px; }
        .table-scroll::-webkit-scrollbar-thumb { background:var(--border); border-radius:3px; }
        .table-inner { min-width:760px; }

        .grid-cols {
            display:grid; align-items:center; column-gap:12px; padding:0 20px;
            grid-template-columns: 110px minmax(0,2fr) 110px 104px 56px 150px;
        }
        .table-head {
            position:sticky; top:0; z-index:2;
            background:var(--cream-deep); border-bottom:1px solid var(--border);
        }
        .table-head > div {
            padding:11px 0; font-size:.68rem; font-weight:700; color:var(--choc-dark);
            text-transform:uppercase; letter-spacing:.04em;
        }

        .row { border-bottom:1px solid var(--border); transition:background .12s; }
        .row:last-child { border-bottom:none; }
        .row:hover { background:var(--cream); }
        .row > div { padding:12px 0; font-size:.8rem; min-width:0; display:flex; flex-direction:column; justify-content:center; gap:2px; }

        .c-qty, .c-price { text-align:right; align-items:flex-end; }
        .row .id-main { font-weight:700; color:var(--caramel-deep); }
        .row .id-sub { font-size:.68rem; color:var(--muted); }
        .c-prod .prod-text {
            line-height:1.35; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;
            overflow:hidden; overflow-wrap:anywhere;
        }
        .c-qty, .c-price .amount { font-family:'Courier New',monospace; font-variant-numeric:tabular-nums; }
        .c-price .amount { font-weight:700; white-space:nowrap; }
        .mini-tag { display:inline-block; font-size:.62rem; font-weight:800; padding:2px 7px; border-radius:.5rem; background:var(--green-bg); color:var(--green); font-family:'Inter',sans-serif; }

        /* ── Status badges ── */
        .status-badge { display:inline-block; text-align:center; font-size:.68rem; padding:3px 10px; border-radius:1rem; font-weight:700; white-space:nowrap; }
        .badge-done { background:var(--green-bg); color:var(--green); }
        .badge-pend { background:var(--amber-bg); color:var(--amber); }
        .badge-void { background:var(--red-bg);   color:var(--red); }

        .empty-msg { text-align:center; padding:2.5rem; color:var(--muted); font-size:.85rem; }

        /* ── Phones: each order becomes a small card instead of a squeezed row ── */
        @media (max-width:700px) {
            .table-card { width:94%; }
            .table-scroll { max-height:none; overflow-x:hidden; }
            .table-inner { min-width:0; }
            .table-head { display:none; }
            .grid-cols { padding:0 16px; }
            .row.grid-cols {
                grid-template-columns:1fr auto;
                grid-template-areas:
                    "id    status"
                    "prod  prod"
                    "date  price"
                    "qty   price";
                row-gap:4px; padding-top:12px; padding-bottom:12px;
            }
            .row > div { padding:0; }
            .row .c-id     { grid-area:id; }
            .row .c-status { grid-area:status; align-items:flex-end; }
            .row .c-prod   { grid-area:prod; }
            .row .c-date   { grid-area:date; }
            .row .c-qty    { grid-area:qty; text-align:left; align-items:flex-start; color:var(--muted); }
            .row .c-price  { grid-area:price; justify-content:flex-end; }
            .chart-card, .metrics-grid, .filter-bar { width:94%; }
        }
    </style>
</head>
<body>

<header class="header">
    <div class="menu-icon-cont"><img src="src/menu.png" alt="" onclick="openMenu()"></div>
    <p>Liberty Foodmart</p>
</header>

<main class="main">
    <div class="nav-cont" id="nav-cont">
        <a href="inventory.php" class="navs"><div class="icon"><img src="src/inventory.png" alt=""></div><pre>Products</pre></a>
        <a href="sales.php" class="navs active"><div class="icon"><img src="src/sales.png" alt=""></div><pre>Sales Record</pre></a>
        <?php if ($_SESSION['staff_role'] === 'admin'): ?>
        <a href="admin-staff.php" class="navs"><div class="icon"><img src="src/checkout.png" alt=""></div><pre>Manage Staff</pre></a>
        <?php endif; ?>
        <a href="logout.php" class="navs"><div class="icon"><img src="src/logout.png" alt=""></div><pre>Logout</pre></a>
    </div>
    <div class="overlay" id="overlay" onclick="closeMenu()"></div>

    <div class="main-sales">

        <div class="Report-date">
            <div class="Report-date-l">
                <h3>Sales Analytics</h3>
                <p>Analyze your performance with these insights</p>
            </div>
        </div>

        <!-- ── Filter bar — submits as GET so URL is shareable ── -->
        <form method="GET" action="sales.php" class="filter-bar">
            <div class="filter-group">
                <label for="startDate">Start Date</label>
                <input type="date" id="startDate" name="start" value="<?= e($in_start) ?>">
            </div>
            <div class="filter-group">
                <label for="endDate">End Date</label>
                <input type="date" id="endDate" name="end" value="<?= e($in_end) ?>">
            </div>
            <div class="filter-group">
                <label for="statusFilter">Status</label>
                <select id="statusFilter" name="status" onchange="if(!document.getElementById('applyBtn').disabled)this.form.submit()">
                    <option value="all"        <?= $status_filter === 'all'        ? 'selected' : '' ?>>All Status</option>
                    <option value="Done"       <?= $status_filter === 'Done'       ? 'selected' : '' ?>>Completed</option>
                    <option value="Processing" <?= $status_filter === 'Processing' ? 'selected' : '' ?>>Pending</option>
                    <option value="Cancelled"  <?= $status_filter === 'Cancelled'  ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <button type="submit" class="filter-btn" id="applyBtn">Apply</button>
            <button type="button" class="filter-btn download-btn" onclick="downloadPdf()">Download PDF</button>
            <button type="button" class="filter-btn download-btn" onclick="downloadCsv()">Download CSV</button>

            <div class="date-error" id="dateError" role="alert" <?= $date_error ? 'data-server="1"' : 'hidden' ?>><?= e($date_error) ?></div>
        </form>

        <!-- ── Metric cards ── -->
        <div class="metrics-grid">
            <div class="metric-card" id="revenue-cont">
                <div class="metric-label">Total Revenue (Net of VAT)</div>
                <div class="metric-value">Php <?= number_format($total_revenue, 0, '.', ',') ?></div>
                <div class="metric-sub"><?= count($all_dates) ?>-day period</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">VAT Collected</div>
                <div class="metric-value">Php <?= number_format($vat_collected, 0, '.', ',') ?></div>
                <div class="metric-sub">held for BIR, not revenue</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Senior/PWD Discounts Given</div>
                <div class="metric-value">Php <?= number_format($discounts_given, 0, '.', ',') ?></div>
                <div class="metric-sub">across completed orders</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Completed</div>
                <div class="metric-value"><?= $completed_count ?></div>
                <div class="metric-sub">of <?= $total_entries ?> orders &amp; reservations</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Units Sold</div>
                <div class="metric-value"><?= number_format($units_sold) ?></div>
                <div class="metric-sub">across all products</div>
            </div>
        </div>

        <!-- ── Daily Revenue chart ── -->
        <div class="sales-tag">
            <h3>Daily Revenue</h3>
            <hr>
        </div>
        <div class="report-dl">
            <div class="report-dl-l">
                <p>Breakdown of total sales per day within the selected date range.</p>
            </div>
            <div class="report-dl-r">
               
            </div>
        </div>

        <div class="chart-card">
            <canvas id="revenueChart" role="img" aria-label="Bar chart showing daily revenue"></canvas>
        </div>

        <!-- ── Sales by Product chart ── -->
        <div class="sales-tag" style="margin-top:1.8rem;">
            <h3>Sales by Product</h3>
            <hr>
        </div>

        <div class="chart-card" style="height:240px;margin-top:.8rem;">
            <canvas id="productChart" role="img" aria-label="Bar chart showing revenue by product"></canvas>
        </div>

        <!-- ── Orders table ── -->
        <div class="table-card">
            <div class="title-recent">
                <p>Orders</p>
                <span class="meta"><?= count($filtered_table) ?> records &nbsp;·&nbsp; Filter: <?= ['all'=>'All','Done'=>'Completed','Processing'=>'Pending','Cancelled'=>'Voided'][$status_filter] ?></span>
            </div>

            <div class="table-scroll">
                <div class="table-inner">
                    <div class="table-head grid-cols">
                        <div>Order ID</div>
                        <div>Product</div>
                        <div>Date / Time</div>
                        <div>Status</div>
                        <div class="c-qty">Qty</div>
                        <div class="c-price">Total Price</div>
                    </div>

                    <?php if (empty($filtered_table)): ?>
                        <div class="empty-msg">No records found for this selection.</div>
                    <?php else: ?>
                        <?php foreach ($filtered_table as $o): ?>
                        <div class="row grid-cols">
                            <div class="c-id">
                                <span class="id-main"><?= e($o['id']) ?></span>
                                <span class="id-sub"><?= $o['source'] === 'reservation' ? 'Reservation' : 'Order' ?></span>
                            </div>
                            <div class="c-prod" title="<?= e($o['products']) ?>"><span class="prod-text"><?= e($o['products']) ?></span></div>
                            <div class="c-date">
                                <span><?= e($o['order_date']) ?></span>
                                <span class="id-sub"><?= e($o['order_time']) ?></span>
                            </div>
                            <div class="c-status"><?= status_badge($o['order_status']) ?></div>
                            <div class="c-qty">x<?= (int)$o['total_qty'] ?></div>
                            <div class="c-price">
                                <span class="amount">Php <?= number_format($o['total_price'], 2) ?></span>
                                <?php if ($o['is_senior_pwd']): ?><span class="mini-tag">SR/PWD</span><?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div><!-- end .main-sales -->
</main>

<!-- Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
// ── Date range check: the end date must be on or after the start date ──
(function () {
    const startEl = document.getElementById('startDate');
    const endEl   = document.getElementById('endDate');
    const box     = document.getElementById('dateError');
    const form    = startEl.form;
    const apply   = document.getElementById('applyBtn');
    const downloads = form.querySelectorAll('.download-btn');

    function check(fromUser) {
        const bad = !!(startEl.value && endEl.value && startEl.value > endEl.value);

        // The date pickers grey out dates that would break the rule
        endEl.min   = startEl.value || '';
        startEl.max = endEl.value   || '';

        startEl.classList.toggle('invalid', bad);
        endEl.classList.toggle('invalid', bad);
        startEl.setAttribute('aria-invalid', bad);
        endEl.setAttribute('aria-invalid', bad);
        apply.disabled = bad;
        // The downloads follow what the page is showing, so hold them while the range is wrong
        downloads.forEach(b => { b.disabled = bad; });

        if (bad) {
            // Keep the server's wording on first load; once they edit, show the live message
            if (fromUser || !box.dataset.server) {
                box.textContent = 'Start date cannot be later than the end date. Please choose an end date on or after the start date.';
            }
            box.hidden = false;
        } else {
            box.hidden = true;
            delete box.dataset.server;
        }
        return !bad;
    }

    startEl.addEventListener('input',  () => check(true));
    endEl.addEventListener('input',    () => check(true));
    startEl.addEventListener('change', () => check(true));
    endEl.addEventListener('change',   () => check(true));
    form.addEventListener('submit', ev => { if (!check(true)) ev.preventDefault(); });
    check(false);
})();

// ── Nav ────────────────────────────────────────────────────
const navCont = document.getElementById('nav-cont');
const overlay = document.getElementById('overlay');
function openMenu()  { navCont.classList.add('active');    overlay.classList.add('active'); }
function closeMenu() { navCont.classList.remove('active'); overlay.classList.remove('active'); }

// ── Chart data from PHP ────────────────────────────────────
const revLabels  = <?= json_encode($rev_labels) ?>;
const revData    = <?= json_encode($rev_data) ?>;
const prodLabels = <?= json_encode($prod_labels) ?>;
const prodData   = <?= json_encode(array_map('floatval', $prod_data)) ?>;

// Bakery-palette colors for the charts (replaces the old blue/green/purple set)
const revColorActive = '#C17817';
const revColorEmpty  = '#F1E4D3';
const revColors      = revData.map(v => v > 0 ? revColorActive : revColorEmpty);
const prodPalette    = ['#8B4513', '#C17817', '#D4A24C', '#5C3A24', '#B9895A'];
const prodColors     = prodLabels.map((_, i) => prodPalette[i % prodPalette.length]);

function fmt(n) {
    return 'Php ' + Math.round(n).toLocaleString('en-PH');
}

// ── Daily Revenue Chart ────────────────────────────────────
new Chart(document.getElementById('revenueChart'), {
    type: 'bar',
    data: {
        labels: revLabels,
        datasets: [{
            label: 'Revenue',
            data: revData,
            backgroundColor: revColors,
            borderRadius: 6,
            borderSkipped: false,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ' ' + fmt(ctx.parsed.y) } }
        },
        scales: {
            x: {
                grid: { display: false },
                ticks: { autoSkip: true, maxTicksLimit: 14, maxRotation: 45 }
            },
            y: {
                ticks: { callback: v => 'Php ' + (v >= 1000 ? (v/1000).toFixed(0)+'k' : v) },
                grid: { color: 'rgba(59,36,23,0.06)' }
            }
        }
    }
});

// ── Sales by Product Chart ─────────────────────────────────
<?php if (!empty($prod_labels)): ?>
new Chart(document.getElementById('productChart'), {
    type: 'bar',
    data: {
        labels: prodLabels,
        datasets: [{
            data: prodData,
            backgroundColor: prodColors,
            borderRadius: 6,
            borderSkipped: false,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ' ' + fmt(ctx.parsed.y) } }
        },
        scales: {
            x: { grid: { display: false } },
            y: {
                ticks: { callback: v => 'Php ' + (v >= 1000 ? (v/1000).toFixed(0)+'k' : v) },
                grid: { color: 'rgba(59,36,23,0.06)' }
            }
        }
    }
});
<?php endif; ?>

// ═════════════════════════════════════════════════════════════
//  CSV SALES RECORD
//  One line per order / reservation, exactly the rows in the table
//  below (so the Status filter above applies to the file too).
// ═════════════════════════════════════════════════════════════
const CSV_ROWS = <?= json_encode(array_map(function ($o) {
    return [
        $o['id'],
        $o['source'] === 'reservation' ? 'Reservation' : 'Order',
        (string)$o['products'],
        $o['order_date'],
        $o['order_time'],
        ['Done' => 'Completed', 'Processing' => 'Pending', 'Cancelled' => 'Voided'][$o['order_status']] ?? $o['order_status'],
        (int)$o['total_qty'],
        number_format((float)$o['subtotal'],        2, '.', ''),
        number_format((float)$o['discount_amount'], 2, '.', ''),
        number_format((float)$o['total_price'],     2, '.', ''),
        number_format((float)$o['net_amount'],      2, '.', ''),
        number_format((float)$o['vat_amount'],      2, '.', ''),
        $o['is_senior_pwd'] ? 'Yes' : 'No',
    ];
}, array_values($filtered_table)), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const CSV_HEADER = ['ID','Type','Products','Date','Time','Status','Qty','Subtotal','Discount','Total (PHP)','Net of VAT','VAT','Senior/PWD'];

function csvCell(v) {
    let s = String(v);
    // A cell starting with = + - @ can run as a formula when opened in Excel
    if (/^[=+\-@]/.test(s) && isNaN(Number(s))) s = "'" + s;
    return '"' + s.replace(/"/g, '""') + '"';
}

function downloadCsv() {
    if (!CSV_ROWS.length) { alert('There are no records to download for this selection.'); return; }
    const lines = [CSV_HEADER, ...CSV_ROWS].map(r => r.map(csvCell).join(','));
    // BOM so Excel reads the file as UTF-8
    const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url;
    a.download = 'sales-records_<?= e($start) ?>_to_<?= e($end) ?>.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

// ═════════════════════════════════════════════════════════════
//  PDF SALES REPORT
//  Page 1 = the 5 headline numbers (numbers only, no graphs),
//  then the Daily Revenue graph and the Revenue-by-Product graph.
//  Uses the same data already on this page, so the PDF always
//  matches what the staff member is looking at.
// ═════════════════════════════════════════════════════════════
const REPORT = <?= json_encode([
    'start'     => $start,
    'end'       => $end,
    'days'      => count($all_dates),
    'revenue'   => $total_revenue,
    'vat'       => $vat_collected,
    'discount'  => $discounts_given,
    'completed' => $completed_count,
    'entries'   => $total_entries,
    'units'     => $units_sold,
    'by'        => (string)($_SESSION['staff_username'] ?? ''),
]) ?>;

// The built-in PDF fonts only know basic Latin characters — swap anything else for "?"
function pdfText(s)  { return String(s).replace(/[^\x20-\x7E\u00A0-\u00FF]/g, '?'); }
function money2(n)   { return 'Php ' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function niceDate(s) { return new Date(s + 'T00:00:00').toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }); }
function nowStamp()  {
    const d = new Date(); let h = d.getHours();
    const m = String(d.getMinutes()).padStart(2, '0'), ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) + ' ' + h + ':' + m + ' ' + ap;
}

// Draw a chart off-screen at a fixed size and hand back a PNG for the PDF
function chartImage(config, w, h) {
    const c = document.createElement('canvas');
    c.width = w; c.height = h;
    config.options = Object.assign({ responsive: false, animation: false, devicePixelRatio: 2 }, config.options);
    const ch  = new Chart(c, config);
    const url = c.toDataURL('image/png');
    ch.destroy();
    return url;
}

// Prints "Php 1,234" at the end of each horizontal bar
const valueLabels = {
    id: 'valueLabels',
    afterDatasetsDraw(chart) {
        const ctx = chart.ctx;
        ctx.save();
        ctx.font = '600 13px Arial';
        ctx.fillStyle = '#3B2417';
        ctx.textBaseline = 'middle';
        chart.getDatasetMeta(0).data.forEach((bar, i) => {
            ctx.fillText(fmt(chart.data.datasets[0].data[i]), bar.x + 8, bar.y);
        });
        ctx.restore();
    }
};

const kTick = v => 'Php ' + (v >= 1000 ? (v / 1000).toFixed(v % 1000 === 0 ? 0 : 1) + 'k' : v);

function downloadPdf() {
    if (!window.jspdf || !window.Chart) {
        alert('The PDF tool could not be loaded. Please check your internet connection and try again.');
        return;
    }

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'mm', format: 'a4' });
    const PW = 210, PH = 297, M = 14, CW = PW - 2 * M;
    const C = { dark: [59,36,23], caramel: [193,120,23], cream: [251,243,231], border: [232,213,183], muted: [169,138,109] };
    let y = 0;

    const ensure = h => { if (y + h > PH - 18) { doc.addPage(); y = M + 2; } };

    // ── Header band ──
    doc.setFillColor(...C.dark);    doc.rect(0, 0, PW, 30, 'F');
    doc.setFillColor(...C.caramel); doc.rect(0, 30, PW, 1.5, 'F');
    doc.setFont('helvetica', 'bold');   doc.setFontSize(18); doc.setTextColor(255, 255, 255);
    doc.text('Liberty Foodmart', M, 14);
    doc.setFont('helvetica', 'normal'); doc.setFontSize(10); doc.setTextColor(241, 228, 211);
    doc.text('Sales Report', M, 21);
    doc.setFontSize(9);
    doc.text(niceDate(REPORT.start) + ' to ' + niceDate(REPORT.end), PW - M, 14, { align: 'right' });
    doc.text(REPORT.days + '-day period', PW - M, 21, { align: 'right' });

    y = 40;
    doc.setFontSize(8); doc.setTextColor(...C.muted);
    doc.text('Generated ' + nowStamp() + (REPORT.by ? ' by ' + pdfText(REPORT.by) : ''), M, y);
    y += 9;

    // ── Section heading helper ──
    const section = (title, note) => {
        doc.setFont('helvetica', 'bold'); doc.setFontSize(12); doc.setTextColor(...C.dark);
        doc.text(title, M, y); y += 2;
        doc.setDrawColor(...C.border); doc.setLineWidth(0.3); doc.line(M, y, PW - M, y); y += 5;
        if (note) {
            doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5); doc.setTextColor(...C.muted);
            doc.text(note, M, y); y += 4.5;
        }
    };

    // ── 1. The 5 main numbers (numbers only) ──
    section('Summary');
    const card = (x, yy, w, label, value, sub) => {
        const h = 27;
        doc.setFillColor(...C.cream); doc.setDrawColor(...C.border); doc.setLineWidth(0.3);
        doc.roundedRect(x, yy, w, h, 2, 2, 'FD');
        doc.setFont('helvetica', 'bold'); doc.setFontSize(7.5); doc.setTextColor(...C.muted);
        doc.text(label.toUpperCase(), x + 4, yy + 7);
        doc.setFontSize(15); doc.setTextColor(...C.dark);
        doc.text(value, x + 4, yy + 15.5);
        doc.setFont('helvetica', 'normal'); doc.setFontSize(7.5); doc.setTextColor(...C.muted);
        doc.text(doc.splitTextToSize(sub, w - 8), x + 4, yy + 20.5);
    };
    const gap = 5, w3 = (CW - 2 * gap) / 3, w2 = (CW - gap) / 2;
    card(M,                    y, w3, 'Total Revenue (Net of VAT)',     money2(REPORT.revenue),  REPORT.days + '-day period');
    card(M + w3 + gap,         y, w3, 'VAT Collected',                  money2(REPORT.vat),      'Held for BIR, not revenue');
    card(M + 2 * (w3 + gap),   y, w3, 'Senior/PWD Discounts Given',     money2(REPORT.discount), 'Across completed orders');
    y += 27 + gap;
    card(M,                    y, w2, 'Completed', String(REPORT.completed), 'of ' + REPORT.entries + ' orders & reservations');
    card(M + w2 + gap,         y, w2, 'Units Sold',                     REPORT.units.toLocaleString('en-PH'), 'Across all products');
    y += 27 + 5;

    doc.setFont('helvetica', 'italic'); doc.setFontSize(7.5); doc.setTextColor(...C.muted);
    doc.text(doc.splitTextToSize('Revenue is net of VAT and after Senior/PWD discounts. Only completed orders and reservations are counted.', CW), M, y);
    y += 11;

    // ── 2. Daily revenue graph ──
    const hasDaily = revData.some(v => v > 0);
    const dailyH   = CW * 380 / 1000;
    ensure(22 + (hasDaily ? dailyH : 6));
    section('Daily Revenue', 'Net-of-VAT revenue per day within the selected date range.');
    if (hasDaily) {
        const img = chartImage({
            type: 'bar',
            data: { labels: revLabels, datasets: [{ data: revData, backgroundColor: '#C17817', borderRadius: 4, borderSkipped: false }] },
            options: {
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { autoSkip: true, maxTicksLimit: 16, maxRotation: 0, font: { size: 12 } } },
                    y: { beginAtZero: true, ticks: { callback: kTick, font: { size: 12 } }, grid: { color: 'rgba(59,36,23,0.08)' } }
                }
            }
        }, 1000, 380);
        doc.addImage(img, 'PNG', M, y, CW, dailyH);
        y += dailyH + 9;
    } else {
        doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.setTextColor(...C.muted);
        doc.text('No completed sales in this period.', M, y + 3);
        y += 14;
    }

    // ── 3. Revenue by product graph (top 10) ──
    const top     = prodLabels.slice(0, 10);
    const topVals = prodData.slice(0, 10);
    const prodPxH = Math.max(240, 80 + top.length * 46);
    const prodH   = CW * prodPxH / 1000;
    ensure(22 + (top.length ? prodH : 6));
    section('Sales by Product', 'Net-of-VAT revenue per product' + (prodLabels.length > 10 ? ' (top 10 of ' + prodLabels.length + ' shown).' : '.'));
    if (top.length) {
        const img = chartImage({
            type: 'bar',
            data: { labels: top, datasets: [{ data: topVals, backgroundColor: top.map((_, i) => prodPalette[i % prodPalette.length]), borderRadius: 4, borderSkipped: false, barThickness: 28 }] },
            options: {
                indexAxis: 'y',
                layout: { padding: { right: 120 } },
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, ticks: { callback: kTick, font: { size: 12 } }, grid: { color: 'rgba(59,36,23,0.08)' } },
                    y: { grid: { display: false }, ticks: { font: { size: 13 } } }
                }
            },
            plugins: [valueLabels]
        }, 1000, prodPxH);
        doc.addImage(img, 'PNG', M, y, CW, prodH);
        y += prodH + 6;
    } else {
        doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.setTextColor(...C.muted);
        doc.text('No completed sales in this period.', M, y + 3);
    }

    // ── Footer on every page ──
    const pages = doc.getNumberOfPages();
    for (let i = 1; i <= pages; i++) {
        doc.setPage(i);
        doc.setDrawColor(...C.border); doc.setLineWidth(0.3); doc.line(M, PH - 12, PW - M, PH - 12);
        doc.setFont('helvetica', 'normal'); doc.setFontSize(7.5); doc.setTextColor(...C.muted);
        doc.text('Liberty Foodmart - Sales Report', M, PH - 7);
        doc.text('Page ' + i + ' of ' + pages, PW - M, PH - 7, { align: 'right' });
    }

    doc.save('sales-report_' + REPORT.start + '_to_' + REPORT.end + '.pdf');
}
</script>

</body>
</html>