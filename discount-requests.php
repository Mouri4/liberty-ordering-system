<?php
// ── Liberty Foodmart — Admin: Discount Requests ──────────────
// Reviews Senior/PWD discount claims made at ONLINE checkout only.
// Walk-in POS discounts never show up here — staff already checked
// those IDs in person at the register (see staff-orderingv2.php).
require_once 'session.php';
require 'db.php';
require 'vat.php';

// Admin only
require_once 'auth.php';
require_staff($pdo, 'admin');

$admin_id = (int)$_SESSION['staff_id'];
$error    = '';
$success  = '';

// ── Handle approve / reject ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action']   ?? '';
    $order_id = (int)($_POST['order_id'] ?? 0);

    if (!$order_id) {
        $error = 'Missing order.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND discount_status = 'pending'");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();

        if (!$order) {
            $error = 'That request is no longer pending — it may already have been reviewed.';
        } elseif ($action === 'approve') {
            $pdo->prepare("
                UPDATE orders SET discount_status = 'approved', discount_reviewed_at = NOW(), discount_reviewed_by = ?
                WHERE id = ?
            ")->execute([$admin_id, $order_id]);
            $success = 'Order #' . $order_id . ' approved — the 5% discount stays applied.';
        } elseif ($action === 'reject') {
            // Reverse the discount: the order goes back to full price.
            $rates     = get_vat_settings($pdo);
            $new_total = (float)$order['subtotal'];
            $new_net   = round($new_total / (1 + $rates['vat_rate']), 2);
            $new_vat   = round($new_total - $new_net, 2);

            $pdo->beginTransaction();
            try {
                $pdo->prepare("
                    UPDATE orders
                    SET discount_amount = 0.00, total = ?, net_amount = ?, vat_amount = ?,
                        discount_status = 'rejected', discount_reviewed_at = NOW(), discount_reviewed_by = ?
                    WHERE id = ?
                ")->execute([$new_total, $new_net, $new_vat, $admin_id, $order_id]);

                $pdo->prepare('UPDATE order_items SET discount_amount = 0.00, line_total = unit_price * quantity WHERE order_id = ?')
                    ->execute([$order_id]);

                $pdo->commit();
                $success = 'Order #' . $order_id . ' rejected — it now shows the full price (₱' . number_format($new_total, 2) . '). Let the customer know if they were already told the discounted amount.';
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Something went wrong rejecting this request. Please try again.';
            }
        }
    }
}

// ── Load requests for the active tab ──────────────────────────
$tab = $_GET['tab'] ?? 'pending';
if (!in_array($tab, ['pending', 'approved', 'rejected'], true)) $tab = 'pending';

$stmt = $pdo->prepare("
    SELECT o.*, s.username AS reviewed_by_name,
           GROUP_CONCAT(oi.product_name, ' x', oi.quantity ORDER BY oi.id SEPARATOR ', ') AS items_text
    FROM orders o
    LEFT JOIN staff_users  s  ON s.id = o.discount_reviewed_by
    LEFT JOIN order_items  oi ON oi.order_id = o.id
    WHERE o.discount_status = ?
    GROUP BY o.id
    ORDER BY o.created_at DESC
");
$stmt->execute([$tab]);
$requests = $stmt->fetchAll();

// Counts for the tab badges
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($pdo->query("SELECT discount_status, COUNT(*) AS c FROM orders WHERE discount_status != 'none' GROUP BY discount_status") as $row) {
    $counts[$row['discount_status']] = (int)$row['c'];
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discount Requests — Liberty Foodmart</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,600&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
            --blue:#1565C0;  --blue-bg:#E3F2FD;
        }

        @font-face { src:url(src/Kavoon-Regular.ttf); font-family:kavoon; }

        * { box-sizing:border-box; margin:0; padding:0; }
        body { width:100%; min-height:100vh; background:var(--cream); font-family:'Inter', Arial, sans-serif; color:var(--choc-dark); }
        pre { margin:0; font-family:inherit; }

        /* ── Header ── */
        .header {
            height:52px; background:var(--surface); display:flex; align-items:center;
            position:sticky; top:0; z-index:100; border-bottom:1px solid var(--border);
            box-shadow:0 2px 8px rgba(59,36,23,0.08);
        }
        .menu-icon-cont { margin-left:2%; width:2%; height:60%; cursor:pointer; }
        .menu-icon-cont img { width:100%; height:100%; object-fit:cover; }
        .header p { font-family:kavoon; color:var(--caramel-deep); margin-left:4%; font-size:1.1rem; letter-spacing:.02em; }

        /* ── Nav ── */
        .nav-cont {
            position:fixed; top:0; left:0; width:225px; height:100%; padding:55px 10px 20px;
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

        /* ── Page layout ── */
        .page { max-width:70rem; margin:0 auto; padding:2rem 3%; }

        .page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; flex-wrap:wrap; gap:1rem; }
        .page-header h1 { font-family:'Playfair Display', serif; font-weight:700; font-size:1.8rem; color:var(--choc-dark); }
        .page-header p.subtitle { font-size:.85rem; color:var(--muted); margin-top:.2rem; }

        /* ── Alerts ── */
        .alert { padding:12px 16px; border-radius:10px; font-size:.9rem; margin-bottom:1.2rem; font-weight:600; }
        .alert-success { background:var(--green-bg); color:var(--green); border:1px solid #c8e6c9; }
        .alert-error   { background:var(--red-bg);   color:var(--red);   border:1px solid #f5c6cb; }

        /* ── Tabs ── */
        .tabs-row { display:flex; gap:.6rem; margin-bottom:1.6rem; flex-wrap:wrap; }
        .tab-link {
            padding:.55rem 1.1rem; border-radius:2rem; font-size:.82rem; font-weight:700;
            background:var(--surface); color:var(--choc); border:1px solid var(--border);
            text-decoration:none; transition:background .15s;
        }
        .tab-link:hover { background:var(--cream-deep); }
        .tab-link.active { background:var(--choc-dark); color:#fff; border-color:var(--choc-dark); }

        /* ── Request cards ── */
        .card {
            background:var(--surface); border-radius:16px; padding:1.6rem;
            border:1px solid var(--border);
            box-shadow:0 4px 18px rgba(59,36,23,0.08);
            margin-bottom:1.2rem;
        }
        .discount-card-head { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; margin-bottom:.9rem; flex-wrap:wrap; }
        .discount-card-head h2 {
            font-family:'Playfair Display', serif; font-weight:700; font-size:1.15rem; color:var(--choc-dark);
        }
        .date-small { font-size:.78rem; color:var(--muted); margin-top:.15rem; }
        .discount-items { font-size:.85rem; color:var(--choc); margin-bottom:1.1rem; }

        .discount-body { display:flex; gap:1.4rem; flex-wrap:wrap; align-items:flex-start; margin-bottom:1.1rem; }
        .id-photo-box { flex-shrink:0; width:11rem; }
        .btn-view-id {
            width:11rem; padding:.9rem .6rem; border-radius:.6rem;
            border:1.5px dashed var(--border); background:var(--cream);
            color:var(--choc); font-size:.78rem; font-weight:700;
            cursor:pointer; font-family:'Inter',sans-serif;
            display:flex; flex-direction:column; align-items:center; gap:.3rem;
            transition:background .15s, border-color .15s;
        }
        .btn-view-id:hover { background:var(--cream-deep); border-color:var(--caramel); }
        .no-photo {
            width:11rem; padding:.9rem .5rem; border-radius:.6rem; border:1px dashed var(--border);
            display:flex; align-items:center; justify-content:center; text-align:center;
            font-size:.72rem; color:var(--muted);
        }

        .discount-amounts { flex:1; min-width:14rem; }
        .amt-row { display:flex; justify-content:space-between; font-size:.85rem; padding:.25rem 0; color:var(--choc); }
        .amt-discount { color:var(--red); }
        .amt-total { font-weight:800; font-size:.95rem; color:var(--caramel-deep); border-top:1px solid var(--border); margin-top:.3rem; padding-top:.5rem; }

        .discount-actions { display:flex; gap:.7rem; }
        .btn-sm {
            padding:7px 14px; border:none; border-radius:7px; font-size:.78rem;
            cursor:pointer; font-family:'Inter',sans-serif; font-weight:700; transition:filter .15s;
        }
        .btn-sm:hover { filter:brightness(.94); }
        .btn-approve { background:var(--green-bg); color:var(--green); }
        .btn-reject  { background:var(--red-bg);   color:var(--red); }

        .badge-status { padding:4px 12px; border-radius:1rem; font-size:.7rem; font-weight:700; white-space:nowrap; }
        .badge-pending  { background:var(--amber-bg); color:var(--amber); }
        .badge-approved { background:var(--green-bg); color:var(--green); }
        .badge-rejected { background:var(--red-bg);   color:var(--red); }

        .reviewed-note { font-size:.78rem; color:var(--muted); font-style:italic; }

        .empty-state { text-align:center; padding:2.5rem; color:var(--muted); font-size:.9rem; }

        /* ── Modal (reject confirmation) ── */
        .modal-overlay {
            position:fixed; inset:0; background:rgba(30,15,5,0.55); z-index:2000;
            display:none; align-items:center; justify-content:center; padding:1rem;
        }
        .modal-overlay.open { display:flex; }
        .modal-box {
            background:var(--surface); border-radius:16px; padding:1.8rem; width:380px; max-width:100%;
            box-shadow:0 20px 50px rgba(30,15,5,0.3); border-top:4px solid var(--red);
        }
        .modal-box h3 {
            font-family:'Playfair Display', serif; font-weight:700; font-size:1.1rem;
            color:var(--choc-dark); margin-bottom:1rem; padding-bottom:.6rem;
            border-bottom:1px solid var(--border);
        }
        .modal-box p { font-size:.85rem; color:var(--choc); line-height:1.55; margin-bottom:1.2rem; }
        .modal-footer { display:flex; gap:.7rem; }
        .modal-footer button {
            flex:1; padding:.75rem; border:none; border-radius:8px;
            font-size:.85rem; font-weight:700; cursor:pointer; font-family:'Inter',sans-serif;
        }
        .btn-cancel  { background:var(--cream-deep); color:var(--choc); }
        .btn-cancel:hover { background:var(--border); }
        .btn-danger  { background:var(--red); color:#fff; }
        .btn-danger:hover { background:#a01f1f; }

        /* ── Fullsize ID photo lightbox ── */
        .lightbox {
            position:fixed; inset:0; background:rgba(20,10,5,0.85); z-index:3000;
            display:none; align-items:center; justify-content:center; padding:2rem; cursor:zoom-out;
        }
        .lightbox.open { display:flex; }
        .lightbox img { max-width:100%; max-height:100%; border-radius:.8rem; box-shadow:0 20px 60px rgba(0,0,0,0.5); }
    </style>
</head>
<body>

<header class="header">
    <div class="menu-icon-cont"><img src="src/menu.png" alt="" onclick="openMenu()"></div>
    <p>Liberty Foodmart</p>
</header>

<div class="nav-cont" id="nav-cont">
    <a href="inventory.php" class="navs"><div class="icon"><img src="src/inventory.png" alt=""></div><pre>Products</pre></a>
    <a href="sales.php" class="navs"><div class="icon"><img src="src/sales.png" alt=""></div><pre>Sales Record</pre></a>
    <a href="admin-staff.php" class="navs"><div class="icon"><img src="src/checkout.png" alt=""></div><pre>Manage Staff</pre></a>
    <a href="discount-requests.php" class="navs active"><div class="icon"><img src="src/tag.png" alt=""></div><pre>Discount Requests</pre></a>
    <a href="logout.php" class="navs"><div class="icon"><img src="src/logout.png" alt=""></div><pre>Logout</pre></a>
</div>
<div class="overlay" id="overlay" onclick="closeMenu()"></div>

<div class="page">

    <div class="page-header">
        <div>
            <h1>Discount Requests</h1>
            <p class="subtitle">Senior/PWD ID photos submitted at online checkout. Walk-in orders don't appear here — staff already verified those in person.</p>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <div class="tabs-row">
        <a href="?tab=pending"  class="tab-link <?= $tab === 'pending'  ? 'active' : '' ?>">Pending (<?= $counts['pending'] ?>)</a>
        <a href="?tab=approved" class="tab-link <?= $tab === 'approved' ? 'active' : '' ?>">Approved (<?= $counts['approved'] ?>)</a>
        <a href="?tab=rejected" class="tab-link <?= $tab === 'rejected' ? 'active' : '' ?>">Rejected (<?= $counts['rejected'] ?>)</a>
    </div>

    <?php if (empty($requests)): ?>
        <div class="card"><div class="empty-state">No <?= $tab ?> discount requests.</div></div>
    <?php else: ?>
        <?php foreach ($requests as $r): ?>
        <div class="card">
            <div class="discount-card-head">
                <div>
                    <h2>Order #<?= $r['id'] ?></h2>
                    <p class="date-small"><?= e($r['customer_name']) ?> · <?= e($r['phone']) ?> · placed <?= date('M j, Y g:i A', strtotime($r['created_at'])) ?></p>
                </div>
                <span class="badge-status badge-<?= $tab ?>"><?= ucfirst($tab) ?></span>
            </div>

            <p class="discount-items"><?= e($r['items_text'] ?: 'No items on record') ?></p>

            <div class="discount-body">
                <div class="id-photo-box">
                    <?php if ($r['pwd_id_image_path']): ?>
                        <button type="button" class="btn-view-id" onclick="openLightbox('pwd-id-view.php?order_id=<?= (int)$r['id'] ?>')">
                            
                            <br>
                            <span>View ID Photo</span>
                            <br>
                        </button>
                    <?php else: ?>
                        <div class="no-photo">No ID photo on file</div>
                    <?php endif; ?>
                </div>
                <div class="discount-amounts">
                    <div class="amt-row"><span>Subtotal</span><span><?= peso((float)$r['subtotal']) ?></span></div>
                    <div class="amt-row amt-discount"><span>Senior/PWD Discount (5%)</span><span>-<?= peso((float)$r['discount_amount']) ?></span></div>
                    <div class="amt-row amt-total"><span>Total</span><span><?= peso((float)$r['total']) ?></span></div>
                </div>
            </div>

            <?php if ($tab === 'pending'): ?>
                <div class="discount-actions">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="order_id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn-sm btn-approve" onclick="return confirm('Approve the Senior/PWD discount for Order #<?= $r['id'] ?>?');"> Approve</button>
                    </form>
                    <button type="button" class="btn-sm btn-reject"
                        onclick="openRejectModal(<?= $r['id'] ?>, <?= (float)$r['subtotal'] ?>)"> Reject</button>
                </div>
            <?php else: ?>
                <p class="reviewed-note">
                    <?= $tab === 'approved' ? 'Approved' : 'Rejected' ?> by <?= e($r['reviewed_by_name'] ?? 'a staff account that no longer exists') ?>
                    on <?= $r['discount_reviewed_at'] ? date('M j, Y g:i A', strtotime($r['discount_reviewed_at'])) : '—' ?>
                </p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<!-- ── Reject confirmation modal ── -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal-box">
        <h3> Reject Discount Request</h3>
        <p>
            This reverses the discount — Order #<strong id="rejectOrderId"></strong> will revert to full price,
            <strong id="rejectFullPrice"></strong>. If the customer already saw the discounted total, let them
            know the price has changed.
        </p>
        <form method="POST">
            <input type="hidden" name="order_id" id="rejectOrderIdInput">
            <input type="hidden" name="action" value="reject">
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('rejectModal')">Cancel</button>
                <button type="submit" class="btn-danger">Yes, Reject</button>
            </div>
        </form>
    </div>
</div>

<!-- ── ID photo lightbox ── -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
    <img id="lightboxImg" src="" alt="Senior/PWD ID full size">
</div>

<script>
// ── Nav ────────────────────────────────────────────────────
const navCont = document.getElementById('nav-cont');
const overlay = document.getElementById('overlay');
function openMenu()  { navCont.classList.add('active');    overlay.classList.add('active'); }
function closeMenu() { navCont.classList.remove('active'); overlay.classList.remove('active'); }

// ── Modal helpers ──────────────────────────────────────────
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', function (e) { if (e.target === this) this.classList.remove('open'); });
});

function openRejectModal(orderId, subtotal) {
    document.getElementById('rejectOrderId').textContent = orderId;
    document.getElementById('rejectOrderIdInput').value  = orderId;
    document.getElementById('rejectFullPrice').textContent =
        '₱' + subtotal.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('rejectModal').classList.add('open');
}

// ── ID photo lightbox ──────────────────────────────────────
function openLightbox(src) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightbox').classList.add('open');
}
function closeLightbox() { document.getElementById('lightbox').classList.remove('open'); }
</script>

</body>
</html>