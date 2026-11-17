<?php
// ── Liberty Foodmart — Inventory Action Handler ─────────────
// Handles POST from the inventory modal (add / edit / delete).
// Always redirects back to inventory.php when done.
// ------------------------------------------------------------

require_once 'session.php';
require 'db.php';

// Only staff/admin may access this
require_once 'auth.php';
require_staff($pdo);

$action = $_POST['action'] ?? '';

// ── DELETE ────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);

    if ($id > 0) {
        // Delete the image file from disk if it exists
        $stmt = $pdo->prepare('SELECT image_path FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $product = $stmt->fetch();

        if ($product && $product['image_path'] && file_exists($product['image_path'])) {
            unlink($product['image_path']);
        }

        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
    }

    header('Location: inventory.php?toast=deleted');
    exit;
}

// ── ADD or EDIT ───────────────────────────────────────────
if ($action === 'save') {
    $id       = (int) ($_POST['id'] ?? 0);   // 0 = new product
    $name     = trim($_POST['name']     ?? '');
    $price    = (float) ($_POST['price']  ?? 0);
    $desc     = trim($_POST['desc']     ?? '');
    $category = $_POST['category'] ?? 'Other';
    $qty      = (int) ($_POST['qty']      ?? 0);
    $thresh   = (int) ($_POST['thresh']   ?? 10);

    // Validate allowed categories
    $allowed_cats = ['Cake', 'Bread', 'Pastry', 'Drink', 'Other'];
    if (!in_array($category, $allowed_cats)) $category = 'Other';

    if ($name === '') {
        header('Location: inventory.php?error=noname');
        exit;
    }

    // A description is required when adding a new product
    if ($id === 0 && $desc === '') {
        header('Location: inventory.php?error=nodesc');
        exit;
    }

    // ── Handle image upload ──────────────────────────────
    $image_path = $_POST['existing_image'] ?? ''; // keep old image by default

    if (!empty($_FILES['image']['name'])) {
        $upload_dir = 'uploads/products/';

        // Create folder if it doesn't exist
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $ext          = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        if (in_array($ext, $allowed_exts) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            // Make a unique filename so two products can't overwrite each other
            $filename   = uniqid('product_', true) . '.' . $ext;
            $dest       = $upload_dir . $filename;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
                // Delete old image if replacing
                if ($image_path && file_exists($image_path)) {
                    unlink($image_path);
                }
                $image_path = $dest;
            }
        }
    }

    // ── INSERT or UPDATE ─────────────────────────────────
    if ($id === 0) {
        // New product
        $stmt = $pdo->prepare('
            INSERT INTO products (name, price, description, category, quantity, low_stock_threshold, image_path)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$name, $price, $desc, $category, $qty, $thresh, $image_path ?: null]);
        $toast = 'added';
    } else {
        // Edit existing
        $stmt = $pdo->prepare('
            UPDATE products
            SET name = ?, price = ?, description = ?, category = ?,
                quantity = ?, low_stock_threshold = ?, image_path = ?
            WHERE id = ?
        ');
        $stmt->execute([$name, $price, $desc, $category, $qty, $thresh, $image_path ?: null, $id]);
        $toast = 'saved';
    }

    header('Location: inventory.php?toast=' . $toast);
    exit;
}

// Unknown action — just go back
header('Location: inventory.php');
exit;