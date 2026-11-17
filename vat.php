<?php
// ── Liberty Foodmart — VAT + Senior/PWD Discount helper ─────
//
// Rules this file implements (confirmed with the client):
//   • Product prices are VAT-INCLUSIVE (12% is already baked in)
//   • Senior/PWD discount is 5%, applied PER ITEM
//   • Discount ONLY — the sale is NOT VAT-exempt; VAT is still
//     charged on the discounted amount
//   • Sales reporting is NET-OF-VAT (VAT collected isn't the
//     shop's revenue, it's held for BIR)
//
// Rates are read from the `settings` table rather than hardcoded,
// so changing the VAT rate or discount rate later is a one-row
// database update instead of hunting through every file that does
// this math. Include this file wherever a total needs computing:
//   require 'vat.php';
// ------------------------------------------------------------

/**
 * Read the current VAT and discount rates from the `settings` table.
 * Falls back to the client's stated rates (12% / 5%) if the table is
 * missing or a row hasn't been set — so a broken settings table
 * degrades gracefully instead of breaking every checkout.
 *
 * @return array{vat_rate: float, discount_rate: float}
 */
function get_vat_settings(PDO $pdo): array {
    $rates = ['vat_rate' => 0.12, 'discount_rate' => 0.05];

    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('vat_rate','discount_rate')");
        foreach ($stmt->fetchAll() as $row) {
            $rates[$row['setting_key']] = (float)$row['setting_value'];
        }
    } catch (Exception $e) {
        // settings table missing/unreadable — silently keep the defaults above
    }

    return $rates;
}

/**
 * Price a single order line.
 *
 * $unit_price is VAT-inclusive. The discount is a percentage off
 * this line's gross amount (unit_price * qty) — VAT is NOT removed
 * first, matching the client's "discount only, not VAT-exempt" rule.
 *
 * @return array{gross: float, discount_amount: float, line_total: float}
 */
function price_line(float $unit_price, int $qty, bool $is_senior_pwd, float $discount_rate): array {
    $gross           = round($unit_price * $qty, 2);
    $discount_amount = $is_senior_pwd ? round($gross * $discount_rate, 2) : 0.00;
    $line_total      = round($gross - $discount_amount, 2);

    return [
        'gross'           => $gross,
        'discount_amount' => $discount_amount,
        'line_total'      => $line_total,
    ];
}

/**
 * Price a full cart/order — one call gives you every number needed
 * for the `orders` row AND every `order_items` row in one pass.
 *
 * $items: array of ['unit_price' => float, 'qty' => int, ...] — any
 * extra keys (product_id, name, etc.) are preserved and returned
 * inside each entry of 'lines', merged with its own price breakdown.
 *
 * @return array{
 *   subtotal: float, discount_amount: float, total: float,
 *   net_amount: float, vat_amount: float, lines: array
 * }
 */
function price_order(array $items, bool $is_senior_pwd, array $rates): array {
    $subtotal        = 0.00;
    $discount_amount = 0.00;
    $lines           = [];

    foreach ($items as $item) {
        $priced = price_line((float)$item['unit_price'], (int)$item['qty'], $is_senior_pwd, $rates['discount_rate']);
        $subtotal        += $priced['gross'];
        $discount_amount += $priced['discount_amount'];
        $lines[] = array_merge($item, $priced);
    }

    $subtotal        = round($subtotal, 2);
    $discount_amount = round($discount_amount, 2);
    $total           = round($subtotal - $discount_amount, 2);
    $net_amount      = round($total / (1 + $rates['vat_rate']), 2);
    $vat_amount      = round($total - $net_amount, 2);

    return [
        'subtotal'        => $subtotal,
        'discount_amount' => $discount_amount,
        'total'           => $total,
        'net_amount'      => $net_amount,
        'vat_amount'      => $vat_amount,
        'lines'           => $lines,
    ];
}

/**
 * Save an uploaded Senior/PWD ID photo (online checkout only).
 * Validates the extension AND the actual file content (getimagesize)
 * so a renamed non-image can't slip through — stricter than the
 * existing product-image upload elsewhere in this codebase.
 *
 * @return string|null  the relative path to store in orders.pwd_id_image_path,
 *                       or null if nothing valid was uploaded
 */
function save_pwd_id_upload(array $file): ?string {
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $upload_dir = 'uploads/pwd-ids/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // These are government IDs: make sure the folder can never be
    // opened straight from the browser. Only pwd-id-view.php (admin
    // only) reads from here. Re-created if someone deletes it.
    $htaccess = $upload_dir . '.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess,
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n" .
            "Options -Indexes\n");
    }

    $ext          = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($ext, $allowed_exts, true)) {
        return null;
    }

    // Confirm the upload is actually a readable image, not just a
    // file that was renamed to look like one.
    if (@getimagesize($file['tmp_name']) === false) {
        return null;
    }

    // random_bytes, not uniqid(): uniqid is time-based and guessable.
    $filename = 'pwdid_' . bin2hex(random_bytes(16)) . '.' . $ext;
    $dest     = $upload_dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return null;
    }

    return $dest;
}

/**
 * Consistent 2-decimal peso formatting for the VAT/discount
 * breakdown display (subtotal, discount, VAT, net) — the rest of
 * the site rounds to whole pesos for the main total, but a ₱71.25
 * VAT line loses meaning if rounded to ₱71.
 */
function peso(float $amount): string {
    return '₱' . number_format($amount, 2);
}