<?php
// ── Liberty Foodmart — Inventory Page ───────────────────────
require_once 'session.php';
require 'db.php';

// Guard: only logged-in staff can see this page
require_once 'auth.php';
require_staff($pdo);

// ── Read search / filter from URL ───────────────────────────
$search  = trim($_GET['search']   ?? '');
$cat     = $_GET['category']      ?? '';
$allowed_cats = ['Cake', 'Bread', 'Pastry', 'Drink', 'Other'];
if (!in_array($cat, $allowed_cats)) $cat = '';

// ── Query products from DB ───────────────────────────────────
$sql    = 'SELECT * FROM products WHERE 1=1';
$params = [];

if ($search !== '') {
    $sql      .= ' AND (name LIKE ? OR description LIKE ? OR category LIKE ?)';
    $like      = '%' . $search . '%';
    $params[]  = $like;
    $params[]  = $like;
    $params[]  = $like;
}

if ($cat !== '') {
    $sql      .= ' AND category = ?';
    $params[]  = $cat;
}

$sql .= ' ORDER BY name ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// ── Stock alerts (header badge + side panel) ─────────────────
// Out of stock = 0 (or less). Low stock = still some left, but at/below its
// threshold. Always based on ALL products, not the current search/filter.
$alert_rows = $pdo->query('SELECT id, name, category, quantity, low_stock_threshold FROM products WHERE quantity <= low_stock_threshold ORDER BY quantity ASC, name ASC')->fetchAll();
$out_items  = array_values(array_filter($alert_rows, fn($r) => (int)$r['quantity'] <= 0));
$low_items  = array_values(array_filter($alert_rows, fn($r) => (int)$r['quantity'] >  0));
$out_count  = count($out_items);
$low_count  = count($low_items);

// ── Toast message from redirect ──────────────────────────────
$toast_map = [
    'added'   => ['msg' => 'Product added successfully!',    'type' => 'green'],
    'saved'   => ['msg' => 'Product updated successfully!',  'type' => 'green'],
    'deleted' => ['msg' => 'Product deleted.',               'type' => 'red'],
    'noname'  => ['msg' => 'Please enter a product name.',   'type' => 'red'],
    'nodesc'  => ['msg' => 'Please enter a product description.', 'type' => 'red'],
];
$toast = $toast_map[$_GET['toast'] ?? ($_GET['error'] ?? '')] ?? null;

// ── Edit: pre-fill modal if ?edit=ID is in URL ───────────────
$edit_product = null;
if (!empty($_GET['edit'])) {
    $es = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $es->execute([(int) $_GET['edit']]);
    $edit_product = $es->fetch();
}

// Helper: escape output
function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory — Liberty Foodmart</title>
    <style>
        @font-face { src: url(src/Kavoon-Regular.ttf); font-family: kavoon; }
        @font-face { font-family: jetb; src: url(src/jetb.ttf); }

        :root {
            --choc-dark:#3B2417; --choc:#5C3A24;
            --cream:#FBF3E7; --cream-deep:#F1E4D3;
            --caramel:#C17817; --caramel-deep:#8B4513;
            --gold:#D4A24C; --muted:#A98A6D;
            --surface:#FFFFFF; --border:#E8D5B7;
        }

        body { width:100%; height:100vh; overflow:hidden; margin:0; background:var(--cream); }

        .header {
            height:52px; background:var(--surface);
            border-bottom:1px solid var(--border);
            display:flex; align-items:center; position:relative;
            box-shadow:0 2px 8px rgba(59,36,23,0.08);
        }
        .header p { color:var(--caramel-deep); margin-left:4%; font-family:kavoon; font-size:1.1rem; letter-spacing:.02em; }
        .menu-icon-cont { margin-left:2%; width:2%; height:60%; cursor:pointer; }
        .menu-icon-cont img { width:100%; height:100%; object-fit:cover; }

        .notif-badge { position:absolute; right:1.5%; display:none; align-items:center; gap:6px; background:#fff8ed; border:1px solid var(--border); border-radius:8px; padding:4px 12px; font-family:jetb; font-size:12px; color:var(--caramel-deep); cursor:pointer; transition:background 0.2s; }
        .notif-badge.show { display:flex; }
        .notif-badge:hover { background:var(--cream-deep); }

        .toast { position:fixed; top:68px; right:16px; background:var(--caramel-deep); color:#fff; border-radius:8px; padding:10px 18px; font-size:13px; font-family:jetb; z-index:9999; opacity:0; transform:translateY(-8px); transition:opacity 0.3s ease,transform 0.3s ease; pointer-events:none; max-width:280px; }
        .toast.show { opacity:1; transform:translateY(0); }
        .toast.green { background:#2e7d32; }

        .overlay { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(59,36,23,0.35); z-index:900; opacity:0; visibility:hidden; transition:0.3s; }
        .overlay.active { opacity:1; visibility:visible; }

        .main { width:100%; height:calc(100vh - 52px); display:flex; font-family:jetb; }

        .nav-cont { position:fixed; width:200px; height:100%; padding:68px 10px 20px; background:var(--surface); border-right:1px solid var(--border); display:flex; flex-direction:column; gap:4px; transform:translate(-100%, -10%); transition:transform .3s ease; z-index:1000; box-shadow:4px 0 20px rgba(59,36,23,0.12); }
        .navs { display:flex; padding:11px 10px; width:88%; align-items:center; border-radius:10px; gap:12px; font-size:14px; font-weight:600; text-decoration:none; color:var(--choc); transition:background .15s; }
        .navs.active { background:var(--cream-deep); color:var(--caramel-deep); }
        .navs:hover { background:var(--cream); }
        pre { margin:0%; font-family:inherit; }
        .navs img { width:100%; height:100%; object-fit:cover; }
        .icon { height:22px; width:22px; flex-shrink:0; }
        .nav-cont.active { transform:translate(0%, -10%); }

        .main-inventory { width:100%; height:100%; display:flex; align-items:stretch; gap:1%; }
        .inv-left { flex:1; min-width:0; height:100%; display:flex; align-items:center; flex-direction:column; }

        /* ── Stock alerts side panel ── */
        .stock-panel { width:27%; min-width:15rem; max-width:24rem; margin:1% 1.5% 1% 0; overflow-y:auto; display:flex; flex-direction:column; gap:14px; }
        .stock-panel::-webkit-scrollbar { width:0; }
        .alert-card { background:var(--surface); border:1px solid var(--border); border-radius:12px; box-shadow:0 2px 12px rgba(59,36,23,0.08); overflow:hidden; flex-shrink:0; }
        .alert-head { display:flex; align-items:center; gap:8px; padding:12px 14px; border-bottom:1px solid var(--border); font-size:14px; font-weight:700; color:var(--choc-dark); }
        .alert-count { margin-left:2px; min-width:22px; padding:1px 8px; border-radius:10px; text-align:center; font-size:12px; font-weight:800; color:#fff; }
        .alert-count.out { background:#c62828; }
        .alert-count.low { background:var(--gold); color:var(--choc-dark); }
        .alert-row { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:10px 14px; border-bottom:1px solid var(--cream-deep); cursor:pointer; transition:background .15s; }
        .alert-row:last-child { border-bottom:none; }
        .alert-row:hover { background:var(--cream); }
        .alert-name { font-size:13px; font-weight:700; color:var(--choc-dark); overflow-wrap:anywhere; }
        .alert-cat  { font-size:11px; color:var(--muted); margin-top:2px; }
        .alert-qty  { text-align:right; flex-shrink:0; }
        .alert-qty b { display:block; font-size:13px; }
        .alert-qty small { font-size:11px; color:var(--muted); }
        .alert-qty.out b { color:#c62828; }
        .alert-qty.low b { color:#b7791f; }
        .alert-empty { padding:14px; font-size:12px; color:var(--muted); }
        @media (max-width:900px) { .stock-panel { display:none; } }

        .item-cont.out-of-stock { border-left:4px solid #c62828; background:#fff8f8; }
        .item-cont.flash { box-shadow:0 0 0 3px var(--gold); }
        .out-label { margin-left:4%; margin-top:4px; font-size:11px; color:#c62828; font-weight:700; font-family:jetb; }

        .search-cont { width:85%; height:10%; display:flex; gap:1%; align-items:center; }
        .search-cont input { height:28%; width:25%; padding:0.7%; border-radius:1rem; border:1px solid var(--border); box-shadow:0 2px 8px rgba(59,36,23,0.08); font-family:jetb; font-size:13px; background:var(--surface); }
        .search-cont select { height:28%; padding:0 10px; border-radius:1rem; border:1px solid var(--border); box-shadow:0 2px 8px rgba(59,36,23,0.08); font-family:jetb; font-size:13px; background:var(--surface); cursor:pointer; }

        .inventory-cont { width:85%; height:78%; display:flex; flex-direction:column; gap:3%; overflow-y:scroll; }
        .inventory-cont::-webkit-scrollbar { width:0px; }

        .item-cont { width:95%; min-height:22%; background:var(--surface); box-shadow:0 2px 12px rgba(59,36,23,0.08); display:flex; align-items:center; border-left:4px solid transparent; flex-shrink:0; transition:border-color 0.3s; border-radius:8px; }
        .item-cont.low-stock { border-left:4px solid var(--caramel-deep); }

        .img-cont { margin-left:3%; width:10%; height:80%; display:flex; align-items:center; justify-content:center; }
        .img-cont img { max-width:100%; max-height:100%; width:100%; height:100%; object-fit:cover; }
        .img-placeholder { width:100%; height:100%; background:#e8e8e8; display:flex; align-items:center; justify-content:center; font-size:28px; }

        .info-cont { height:100%; width:87%; display:flex; flex-direction:column; justify-content:center; padding:8px 0; }
        .top-info { height:30%; width:90%; margin-left:2.5%; display:flex; justify-content:space-between; align-items:center; }
        .bot-info { width:50%; margin-left:4%; display:flex; font-size:70%; justify-content:space-between; color:grey; margin-top:4px; }
        .low-label { margin-left:4%; margin-top:4px; font-size:11px; color:#cc0000; font-weight:700; font-family:jetb; }
        .info-edit { color:rgb(94,0,157); cursor:pointer; font-size:13px; text-decoration:none; }
        .info-edit:hover { text-decoration:underline; }
        h3 { margin:0px; }
        p { margin:0px; }

        .add-item { background:linear-gradient(135deg,var(--caramel),var(--caramel-deep)); box-shadow:0 4px 14px rgba(139,69,19,0.3); padding:1.4%; font-family:jetb; border:none; border-radius:7px; font-weight:bolder; color:#fff; margin-top:0.2%; margin-left:62%; font-size:110%; transition:transform .2s,box-shadow .2s; cursor:pointer; }
        .add-item:hover { transform:translateY(-2px); box-shadow:0 8px 20px rgba(139,69,19,0.4); }

        .modal-bg { position:fixed; inset:0; background:rgba(59,36,23,0.4); z-index:800; display:none; align-items:center; justify-content:center; }
        .modal-bg.show { display:flex; }

        .create-item-cont { width:40%; max-height:90vh; overflow-y:auto; border-radius:14px; background:var(--surface); box-shadow:0 20px 50px rgba(59,36,23,0.25); display:flex; flex-direction:column; align-items:center; border-top:4px solid var(--gold); }
        .create-item-cont::-webkit-scrollbar { width:0; }

        .modal-title { font-size:15px; font-weight:700; font-family:jetb; padding:14px 20px; width:100%; border-bottom:1px solid var(--border); box-sizing:border-box; color:var(--choc-dark); }

        /* The form itself needs an explicit width — without it, it shrinks
           to fit its content (shrink-to-fit), and since everything inside
           is sized as a % of the form, dropping in a real <img> (which has
           its own natural pixel size) throws that calculation off and the
           whole form visibly drifts left. Pinning width:100% here fixes it. */
        .create-item-cont form { width:100%; display:flex; flex-direction:column; align-items:center; }

        .add-image { width:100%; height:auto; padding:16px 0 8px; display:flex; flex-direction:column; align-items:center; }
        .add-image-cont { width:25%; aspect-ratio:1; background:var(--cream-deep); margin-bottom:3%; border-radius:8px; overflow:hidden; display:flex; align-items:center; justify-content:center; }
        .add-image-cont img { width:100%; height:100%; object-fit:cover; }
        .img-placeholder-icon { font-size:32px; }
        .add-img-btn { width:auto; padding:0.5rem 1rem; border-radius:6px; border:none; background:var(--caramel); color:white; font-family:jetb; cursor:pointer; font-size:13px; }
        .add-img-btn:hover { background:var(--caramel-deep); }

        .product-name, .product-price, .product-description, .product-category, .product-qty, .product-thresh { margin-top:3%; display:flex; width:90%; gap:2%; align-items:flex-start; }
        .product-name p, .product-price p, .product-description p, .product-category p, .product-qty p, .product-thresh p { width:110px; font-family:jetb; font-size:13px; padding-top:6px; flex-shrink:0; color:var(--choc); }
        .product-name input, .product-price input, .product-qty input, .product-thresh input { flex:1; border:1.5px solid var(--border); background:var(--cream); border-radius:6px; padding:6px 8px; font-family:jetb; font-size:13px; }
        .product-name input:focus, .product-price input:focus, .product-qty input:focus, .product-thresh input:focus { outline:none; border-color:var(--caramel); }
        .product-description textarea { flex:1; height:60px; border:1.5px solid var(--border); background:var(--cream); border-radius:6px; padding:6px 8px; font-family:jetb; font-size:13px; resize:none; }
        .product-category select { flex:1; border:1.5px solid var(--border); background:var(--cream); border-radius:6px; padding:6px 8px; font-family:jetb; font-size:13px; }

        .product-opt-btn { margin-top:4%; margin-bottom:5%; display:flex; gap:10px; }
        .product-opt-btn button { padding:1rem; border-radius:8px; border:none; font-family:jetb; font-size:13px; cursor:pointer; transition:filter .2s; }
        #discard-add { background:var(--cream-deep); color:var(--choc); }
        #confirm-add { background:linear-gradient(135deg,var(--caramel),var(--caramel-deep)); color:#fff; }
        #delete-item { background:#fdecea; color:#c62828; }
        .product-opt-btn button:hover { filter:brightness(0.92); }

        .empty-msg { color:#aaa; font-family:jetb; font-size:14px; text-align:center; margin-top:40px; }
        #cat-filter { height:50%; }
    </style>
</head>
<body>

<div class="toast" id="toast"></div>

<header class="header">
    <div class="menu-icon-cont">
        <img src="src/menu.png" alt="menu" onclick="openMenu()">
    </div>
    <p>Liberty Foodmart</p>
    <?php if ($out_count > 0 || $low_count > 0): ?>
    <div class="notif-badge show" id="notif-badge" onclick="scrollToFirstLow()">
        &#9888;
        <?php if ($out_count > 0): ?><span style="color:#c62828;"><?= $out_count ?> out of stock</span><?php endif; ?>
        <?php if ($out_count > 0 && $low_count > 0): ?>&middot;<?php endif; ?>
        <?php if ($low_count > 0): ?><span><?= $low_count ?> low stock</span><?php endif; ?>
    </div>
    <?php endif; ?>
</header>

<main class="main">

    <div class="nav-cont" id="nav-cont">
        <a href="inventory.php" class="navs active">
            <div class="icon"><img src="src/inventory.png" alt=""></div>
            <pre>Products</pre>
        </a>
        <a href="sales.php" class="navs">
            <div class="icon"><img src="src/sales.png" alt=""></div>
            <pre>Sales Record</pre>
        </a>
        <?php if ($_SESSION['staff_role'] === 'admin'): ?>
        <a href="admin-staff.php" class="navs">
            <div class="icon"><img src="src/checkout.png" alt=""></div>
            <pre>Manage Staff</pre>
        </a>
        <?php endif; ?>
        <a href="logout.php" class="navs">
            <div class="icon"><img src="src/logout.png" alt=""></div>
            <pre>Logout</pre>
        </a>
    </div>
    <div class="overlay" id="overlay" onclick="closeMenu()"></div>

    <!-- ── ADD / EDIT MODAL ─────────────────────────────── -->
    <div class="modal-bg" id="modal-bg">
        <div class="create-item-cont">
            <div class="modal-title" id="modal-title">New Product</div>

            <form method="POST" action="inventory-action.php" enctype="multipart/form-data" id="product-form">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id"     id="f-id"    value="0">
                <input type="hidden" name="existing_image" id="f-existing-image" value="">

                <div class="add-image">
                    <div class="add-image-cont" id="img-preview">
                        <span class="img-placeholder-icon">&#128218;</span>
                    </div>
                    <button type="button" class="add-img-btn" onclick="document.getElementById('img-upload').click()">Add image</button>
                    <input type="file" name="image" id="img-upload" accept="image/*" style="display:none" onchange="previewImage(event)">
                </div>

                <div class="product-name">
                    <p>Name</p>
                    <input type="text" name="name" id="f-name" placeholder="Product name" required>
                </div>
                <div class="product-price">
                    <p>Price</p>
                    <input type="number" name="price" id="f-price" placeholder="0" min="0" step="0.01">
                </div>
                <div class="product-description">
                    <p>Description *</p>
                    <textarea name="desc" id="f-desc" placeholder="Required: ingredients, flavor, size, etc." required></textarea>
                </div>
                <div class="product-category">
                    <p>Category</p>
                    <select name="category" id="f-cat">
                        <option value="Cake">Cake</option>
                        <option value="Bread">Bread</option>
                        <option value="Pastry">Pastry</option>
                        <option value="Drink">Drink</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="product-qty">
                    <p>Quantity</p>
                    <input type="number" name="qty" id="f-qty" placeholder="0" min="0">
                </div>
                <div class="product-thresh">
                    <p>Low stock at</p>
                    <input type="number" name="thresh" id="f-thresh" placeholder="10" min="1" value="10">
                </div>

                <div class="product-opt-btn">
                    <button type="button" id="discard-add" onclick="closeModal()">Discard</button>
                    <button type="button" id="delete-item" onclick="deleteItem()" style="display:none">Delete</button>
                    <button type="submit" id="confirm-add">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── PRODUCT LIST ─────────────────────────────────── -->
    <div class="main-inventory">
    <div class="inv-left">

        <form method="GET" action="inventory.php" class="search-cont">
            <label for="search-input">Search</label>
            <input
                type="text"
                id="search-input"
                name="search"
                placeholder="Search products..."
                value="<?= e($search) ?>"
                oninput="debounceSearch()"
                autocomplete="off">
            <select id="cat-filter" name="category" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach ($allowed_cats as $c): ?>
                <option value="<?= e($c) ?>" <?= $cat === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </form>

        <div class="inventory-cont" id="inventory-cont">
            <?php if (empty($products)): ?>
                <div class="empty-msg">No products found.</div>
            <?php else: ?>
                <?php foreach ($products as $item):
                    $isOut = (int)$item['quantity'] <= 0;
                    $isLow = !$isOut && $item['quantity'] <= $item['low_stock_threshold'];
                ?>
                <div class="item-cont<?= $isOut ? ' out-of-stock' : ($isLow ? ' low-stock' : '') ?>" id="item-<?= $item['id'] ?>">
                    <div class="img-cont">
                        <?php if ($item['image_path'] && file_exists($item['image_path'])): ?>
                            <img src="<?= e($item['image_path']) ?>" alt="<?= e($item['name']) ?>">
                        <?php else: ?>
                            <div class="img-placeholder">&#129361;</div>
                        <?php endif; ?>
                    </div>
                    <div class="info-cont">
                        <div class="top-info">
                            <h3><?= e($item['name']) ?></h3>
                            <a href="#" class="info-edit"
                               onclick="openEditModal(
                                   <?= $item['id'] ?>,
                                   '<?= e(addslashes($item['name'])) ?>',
                                   '<?= $item['price'] ?>',
                                   '<?= e(addslashes($item['description'] ?? '')) ?>',
                                   '<?= e($item['category']) ?>',
                                   <?= $item['quantity'] ?>,
                                   <?= $item['low_stock_threshold'] ?>,
                                   '<?= e($item['image_path'] ?? '') ?>'
                               ); return false;">Edit</a>
                        </div>
                        <div class="bot-info">
                            <p>Qty x<?= $item['quantity'] ?></p>
                            <p>Php <?= number_format($item['price'], 2) ?> each</p>
                            <p><?= e($item['category']) ?></p>
                        </div>
                        <?php if ($isOut): ?>
                            <div class="out-label"> Out of stock!</div>
                        <?php elseif ($isLow): ?>
                            <div class="low-label"> Low stock!</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <button class="add-item" onclick="openAddModal()">New Product</button>
    </div><!-- end .inv-left -->

    <!-- ── STOCK ALERTS ────────────────────────────────── -->
    <aside class="stock-panel">
        <section class="alert-card">
            <div class="alert-head"> Out of Stock <span class="alert-count out"><?= $out_count ?></span></div>
            <?php if (empty($out_items)): ?>
                <p class="alert-empty">Nothing is out of stock.</p>
            <?php else: foreach ($out_items as $a): ?>
                <div class="alert-row" data-name="<?= e($a['name']) ?>" onclick="focusItem(<?= (int)$a['id'] ?>, this.dataset.name)">
                    <div>
                        <div class="alert-name"><?= e($a['name']) ?></div>
                        <div class="alert-cat"><?= e($a['category']) ?></div>
                    </div>
                    <div class="alert-qty out"><b>Out of stock</b><small>threshold: <?= (int)$a['low_stock_threshold'] ?></small></div>
                </div>
            <?php endforeach; endif; ?>
        </section>

        <section class="alert-card">
            <div class="alert-head">&#9888; Low Stock <span class="alert-count low"><?= $low_count ?></span></div>
            <?php if (empty($low_items)): ?>
                <p class="alert-empty">No items are running low.</p>
            <?php else: foreach ($low_items as $a): ?>
                <div class="alert-row" data-name="<?= e($a['name']) ?>" onclick="focusItem(<?= (int)$a['id'] ?>, this.dataset.name)">
                    <div>
                        <div class="alert-name"><?= e($a['name']) ?></div>
                        <div class="alert-cat"><?= e($a['category']) ?></div>
                    </div>
                    <div class="alert-qty low"><b><?= (int)$a['quantity'] ?> left</b><small>threshold: <?= (int)$a['low_stock_threshold'] ?></small></div>
                </div>
            <?php endforeach; endif; ?>
        </section>
    </aside>
    </div><!-- end .main-inventory -->

</main>

<!-- ── DELETE FORM (submitted by JS) ────────────────────── -->
<form method="POST" action="inventory-action.php" id="delete-form" style="display:none">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id"     id="delete-id" value="">
</form>

<script>
// ── Nav menu ───────────────────────────────────────────────
const navCont = document.getElementById('nav-cont');
const overlay = document.getElementById('overlay');
function openMenu()  { navCont.classList.add('active');    overlay.classList.add('active'); }
function closeMenu() { navCont.classList.remove('active'); overlay.classList.remove('active'); }

// ── Toast ──────────────────────────────────────────────────
<?php if ($toast): ?>
(function () {
    const t = document.getElementById('toast');
    t.textContent = '<?= e($toast['msg']) ?>';
    t.className   = 'toast show<?= $toast['type'] === 'green' ? ' green' : '' ?>';
    setTimeout(() => t.classList.remove('show'), 3500);
})();
<?php endif; ?>

// ── Low stock scroll ───────────────────────────────────────
function scrollToFirstLow() {
    const first = document.querySelector('.item-cont.out-of-stock') || document.querySelector('.item-cont.low-stock');
    if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// Click an item in the stock panel -> jump to it in the list
// (if a search/filter is hiding it, search for it by name instead)
function focusItem(id, name) {
    const el = document.getElementById('item-' + id);
    if (!el) { window.location.href = 'inventory.php?search=' + encodeURIComponent(name); return; }
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.classList.add('flash');
    setTimeout(() => el.classList.remove('flash'), 1600);
}

// ── Modal: Open (Add) ──────────────────────────────────────
function openAddModal() {
    document.getElementById('modal-title').textContent = 'New Product';
    document.getElementById('f-id').value             = '0';
    document.getElementById('f-existing-image').value = '';
    document.getElementById('f-name').value           = '';
    document.getElementById('f-price').value          = '';
    document.getElementById('f-desc').value           = '';
    document.getElementById('f-desc').required        = true;   // description is required for a NEW product
    document.getElementById('f-cat').value            = 'Cake';
    document.getElementById('f-qty').value            = '';
    document.getElementById('f-thresh').value         = '10';
    document.getElementById('img-upload').value       = '';
    document.getElementById('img-preview').innerHTML  = '<span class="img-placeholder-icon">&#128218;</span>';
    document.getElementById('delete-item').style.display = 'none';
    document.getElementById('modal-bg').classList.add('show');
}

// ── Modal: Open (Edit) ─────────────────────────────────────
function openEditModal(id, name, price, desc, category, qty, thresh, imagePath) {
    document.getElementById('modal-title').textContent = 'Edit Product';
    document.getElementById('f-id').value             = id;
    document.getElementById('f-existing-image').value = imagePath;
    document.getElementById('f-name').value           = name;
    document.getElementById('f-price').value          = price;
    document.getElementById('f-desc').value           = desc;
    document.getElementById('f-desc').required        = false;  // old products may have none; only new ones need it
    document.getElementById('f-cat').value            = category;
    document.getElementById('f-qty').value            = qty;
    document.getElementById('f-thresh').value         = thresh;
    document.getElementById('img-upload').value       = '';

    const preview = document.getElementById('img-preview');
    if (imagePath) {
        preview.innerHTML = '<img src="' + imagePath + '" alt="preview" style="width:100%;height:100%;object-fit:cover;">';
    } else {
        preview.innerHTML = '<span class="img-placeholder-icon">&#128218;</span>';
    }

    document.getElementById('delete-item').style.display = 'inline-block';
    document.getElementById('modal-bg').classList.add('show');
}

// ── Modal: Close ───────────────────────────────────────────
function closeModal() {
    document.getElementById('modal-bg').classList.remove('show');
}

// Block a description that is only spaces (the browser's "required" allows that)
document.getElementById('product-form').addEventListener('submit', function (e) {
    const d = document.getElementById('f-desc');
    if (d.required && d.value.trim() === '') {
        e.preventDefault();
        d.focus();
        alert('Please enter a product description.');
    }
});

// Close modal when clicking outside
document.getElementById('modal-bg').addEventListener('click', function (e) {
    if (e.target === this) closeModal();
});

// ── Image preview ──────────────────────────────────────────
function previewImage(event) {
    const file = event.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function (e) {
        document.getElementById('img-preview').innerHTML =
            '<img src="' + e.target.result + '" alt="preview" style="width:100%;height:100%;object-fit:cover;">';
    };
    reader.readAsDataURL(file);
}

// ── Delete ─────────────────────────────────────────────────
function deleteItem() {
    const id   = document.getElementById('f-id').value;
    const name = document.getElementById('f-name').value;
    if (!confirm('Delete "' + name + '"? This cannot be undone.')) return;
    document.getElementById('delete-id').value = id;
    document.getElementById('delete-form').submit();
}

// ── Search debounce ────────────────────────────────────────
// Wait 400ms after the user stops typing before submitting.
// This prevents the page from reloading on every keystroke
// which was causing the input to lose focus after each character.
let searchTimer = null;

function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function () {
        document.querySelector('form.search-cont').submit();
    }, 400);
}

// Restore focus + cursor at end of input after page reload
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('search-input');
    if (input && input.value.length > 0) {
        input.focus();
        const len = input.value.length;
        input.setSelectionRange(len, len);
    }
});
</script>

</body>
</html>