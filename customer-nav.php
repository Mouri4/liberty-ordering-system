<?php
// ── Liberty Foodmart — Shared customer navigation bar ───────
// One nav for every customer page, so Home / Orders / Cart / Account are
// always in the same place. Include it right after <body>:
//
//   $nav_active = 'orders'; $nav_cart = 'link'; require 'customer-nav.php';
//   (put that line inside a php block right after the body tag)
//
//   $nav_active : 'home' | 'orders' | 'reviews' | 'account' | ''   (highlights that item)
//   $nav_cart   : 'drawer' on ordering-page.php (opens its cart drawer; the page
//                 must define openCart()), 'link' everywhere else (goes to the
//                 shop with the cart open).
//
// Needs session.php to have been loaded already. Styles are scoped to
// .cnav so they can't clash with the page's own CSS.
$nav_active = $nav_active ?? '';
$nav_cart   = $nav_cart   ?? 'link';
$__logged   = !empty($_SESSION['customer_id']);
$__name     = htmlspecialchars((string)($_SESSION['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$__act      = fn(string $k): string => $nav_active === $k ? ' active' : '';

// Orders the shop staff cancelled that this customer has not been told about yet.
// Shown once as a pop-up on whatever customer page they open next.
$__cancels = [];
if ($__logged && isset($pdo)) {
    try {
        $__q = $pdo->prepare("SELECT id FROM orders WHERE customer_id = ? AND order_status = 'Cancelled' AND cancelled_by = 'staff' AND cancel_notified = 0 ORDER BY cancelled_at DESC");
        $__q->execute([(int)$_SESSION['customer_id']]);
        $__cancels = $__q->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $__e) { /* columns not added yet — just skip the notice */ }
}
?>
<style>
:root{--cnav-h:3.5rem;}
.cnav{
    --cn-choc-dark:#3B2417; --cn-choc:#5C3A24; --cn-cream:#FBF3E7; --cn-border:#E8D5B7;
    --cn-caramel:#C17817; --cn-caramel-deep:#8B4513; --cn-muted:#A98A6D; --cn-heart:#B23A48;
    position:fixed; top:0; left:0; right:0; height:var(--cnav-h); z-index:900;
    display:flex; align-items:center; justify-content:space-between; padding:0 4%;
    background:#fff; border-bottom:1px solid var(--cn-border); box-shadow:0 2px 10px rgba(59,36,23,.08);
    font-family:'Inter',Arial,sans-serif;
}
.cnav, .cnav *{ box-sizing:border-box; }
.cnav-spacer{ height:var(--cnav-h); }
.cnav-logo{ font-family:'Playfair Display',Georgia,serif; font-weight:700; font-size:1.4rem; color:var(--cn-caramel-deep); text-decoration:none; white-space:nowrap; }
.cnav-items{ display:flex; align-items:center; gap:.15rem; }
.cnav-wrap{ position:relative; }
.cnav-item{
    position:relative; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:.12rem;
    min-width:3.2rem; padding:.3rem .55rem; border-radius:.6rem;
    background:none; border:0; cursor:pointer; text-decoration:none; font-family:inherit;
    color:var(--cn-choc); transition:background .15s,color .15s;
}
.cnav-item:hover{ background:var(--cn-cream); color:var(--cn-caramel); }
.cnav-item.active{ color:var(--cn-caramel-deep); }
.cnav-item.active::after{ content:''; position:absolute; left:28%; right:28%; bottom:-.2rem; height:2px; border-radius:2px; background:var(--cn-caramel); }
.cnav-ico{ position:relative; display:block; width:1.3rem; height:1.3rem; }
.cnav-ico svg{ width:100%; height:100%; fill:currentColor; display:block; }
.cnav-lbl{ font-size:.62rem; font-weight:700; line-height:1.1; color:inherit; }
.cnav-badge{
    position:absolute; top:-.45rem; right:-.6rem; min-width:1.1rem; height:1.1rem; padding:0 .25rem;
    background:var(--cn-heart); color:#fff; font-size:.6rem; font-weight:800; line-height:1;
    border-radius:1rem; border:2px solid #fff; display:none; align-items:center; justify-content:center;
}
.cnav-badge.visible{ display:flex; }

.cnav-dd{
    position:absolute; top:calc(100% + .45rem); right:0; min-width:14rem; overflow:hidden; z-index:950;
    background:#fff; border:1px solid var(--cn-border); border-radius:.8rem; box-shadow:0 10px 28px rgba(59,36,23,.15);
    opacity:0; pointer-events:none; transform:translateY(-6px); transition:.16s; text-align:left;
}
.cnav-dd.open{ opacity:1; pointer-events:auto; transform:none; }
.cnav-dd > *:not(:last-child){ border-bottom:1px solid var(--cn-border); }
.cnav-dd a, .cnav-dd-row{ display:block; padding:.65rem 1rem; font-size:.82rem; font-weight:600; color:var(--cn-choc-dark); text-decoration:none; }
.cnav-dd a:hover{ background:var(--cn-cream); }
.cnav-dd-muted{ color:var(--cn-muted) !important; }
.cnav-dd-danger{ color:#c62828 !important; }

@media (max-width:760px){
    .cnav{ padding:0 3%; }
    .cnav-logo{ font-size:1.15rem; }
    .cnav-hide-sm{ display:none; }              /* Reviews lives in the Account menu on phones */
    .cnav-item{ min-width:2.9rem; padding:.3rem .4rem; }
    .cnav-dd{ min-width:13rem; max-width:calc(100vw - 1rem); }
}
@media (max-width:360px){
    .cnav-logo{ font-size:1rem; }
    .cnav-item{ min-width:2.6rem; padding:.3rem .25rem; }
}

.cnav-notice{ position:fixed; inset:0; z-index:2100; background:rgba(30,15,5,.55); display:flex; align-items:center; justify-content:center; padding:1rem; font-family:'Inter',Arial,sans-serif; }
.cnav-notice[hidden]{ display:none; }
.cnav-notice-box{ background:#fff; width:100%; max-width:22rem; border-radius:1rem; padding:1.5rem 1.4rem 1.2rem; text-align:center; border-top:4px solid #c62828; box-shadow:0 20px 50px rgba(30,15,5,.3); }
.cnav-notice-box h3{ font-size:1.05rem; color:#3B2417; margin-bottom:.45rem; }
.cnav-notice-box p{ font-size:.86rem; color:#6b5a4c; line-height:1.55; }
.cnav-notice-btns{ display:flex; gap:.6rem; margin-top:1.1rem; }
.cnav-notice-btns a, .cnav-notice-btns button{ flex:1; padding:.65rem; border:none; border-radius:.6rem; font-size:.84rem; font-weight:700; cursor:pointer; text-decoration:none; font-family:inherit; text-align:center; }
.cnav-notice-btns a{ background:#F1E4D3; color:#3B2417; }
.cnav-notice-btns button{ background:#3B2417; color:#fff; }
</style>

<?php if ($__cancels): ?>
<div class="cnav-notice" id="cnavNotice" role="dialog" aria-modal="true" aria-labelledby="cnavNoticeTitle">
    <div class="cnav-notice-box">
        <h3 id="cnavNoticeTitle">Order cancelled by the shop</h3>
        <p>
            <?php if (count($__cancels) === 1): ?>
                Your order #<?= (int)$__cancels[0] ?> was cancelled by the shop staff.
            <?php else: ?>
                Your orders <?= implode(', ', array_map(fn($i) => '#' . (int)$i, $__cancels)) ?> were cancelled by the shop staff.
            <?php endif; ?>
            If you already paid, please contact the shop about your refund.
        </p>
        <div class="cnav-notice-btns">
            <a href="customer-orders.php" onclick="cnavAckNotice()">View my orders</a>
            <button type="button" onclick="cnavAckNotice(true)">OK</button>
        </div>
    </div>
</div>
<?php endif; ?>

<nav class="cnav" aria-label="Main">
    <a href="ordering-page.php" class="cnav-logo">PastryLiberty</a>

    <div class="cnav-items">
        <a href="ordering-page.php" class="cnav-item<?= $__act('home') ?>">
            <span class="cnav-ico"><svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg></span>
            <span class="cnav-lbl">Home</span>
        </a>

        <a href="customer-orders.php" class="cnav-item<?= $__act('orders') ?>">
            <span class="cnav-ico"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-2h2v2zm0-4H7v-2h2v2zm0-4H7V7h2v2zm8 8h-6v-2h6v2zm0-4h-6v-2h6v2zm0-4h-6V7h6v2z"/></svg></span>
            <span class="cnav-lbl">Orders</span>
        </a>

        <a href="customer-reviews.php" class="cnav-item cnav-hide-sm<?= $__act('reviews') ?>">
            <span class="cnav-ico"><svg viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg></span>
            <span class="cnav-lbl">Reviews</span>
        </a>

        <?php $__cart_svg = '<span class="cnav-ico"><svg viewBox="0 0 24 24"><path d="M18 6h-2c0-2.21-1.79-4-4-4S8 3.79 8 6H6c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-8 4c0 .55-.45 1-1 1s-1-.45-1-1V8h2v2zm2-6c1.1 0 2 .9 2 2h-4c0-1.1.9-2 2-2zm4 6c0 .55-.45 1-1 1s-1-.45-1-1V8h2v2z"/></svg><span class="cnav-badge" id="navCartBadge">0</span></span><span class="cnav-lbl">Cart</span>'; ?>
        <?php if ($nav_cart === 'drawer'): ?>
            <button type="button" class="cnav-item" onclick="openCart()"><?= $__cart_svg ?></button>
        <?php else: ?>
            <a href="ordering-page.php?cart=1" class="cnav-item"><?= $__cart_svg ?></a>
        <?php endif; ?>

        <div class="cnav-wrap">
            <button type="button" class="cnav-item<?= $__act('account') ?>" onclick="toggleDropdown('profileDropdown',event)">
                <span class="cnav-ico"><svg viewBox="0 0 24 24"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg></span>
                <span class="cnav-lbl">Account</span>
            </button>
            <div class="cnav-dd" id="profileDropdown" onclick="event.stopPropagation()">
                <?php if ($__logged): ?>
                    <div class="cnav-dd-row cnav-dd-muted">👋 <?= $__name ?></div>
                    <a href="customer-profile.php">My Account</a>
                    <a href="customer-orders.php">My Orders</a>
                    <a href="customer-reviews.php">My Reviews</a>
                    <a href="customer-logout.php" class="cnav-dd-danger">Logout</a>
                <?php else: ?>
                    <a href="customer-login.php">Login</a>
                    <a href="customer-register.php">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
<div class="cnav-spacer"></div>

<script>
// Tell the server the customer has seen the cancellation notice, then close it
function cnavAckNotice(close){
    try { fetch('customer-orders.php', { method:'POST', body:new URLSearchParams({ action:'ack_cancel_notice' }), keepalive:true }); } catch (e) {}
    if (close) { var n = document.getElementById('cnavNotice'); if (n) n.hidden = true; }
}
function toggleDropdown(id, e){
    e.stopPropagation();
    var t = document.getElementById(id), wasOpen = t.classList.contains('open');
    document.querySelectorAll('.cnav-dd').forEach(function(d){ d.classList.remove('open'); });
    if (!wasOpen) t.classList.add('open');
}
document.addEventListener('click', function(){
    document.querySelectorAll('.cnav-dd').forEach(function(d){ d.classList.remove('open'); });
});
<?php if ($nav_cart !== 'drawer'): ?>
// Show the cart count on every page (the cart lives in sessionStorage)
(function(){
    try {
        var c = JSON.parse(sessionStorage.getItem('lf_cart')) || {}, n = 0;
        for (var k in c) n += (c[k].qty || 0);
        var b = document.getElementById('navCartBadge');
        if (b && n > 0) { b.textContent = n; b.classList.add('visible'); }
    } catch (e) {}
})();
<?php endif; ?>
</script>