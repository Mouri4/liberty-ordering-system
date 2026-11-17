<?php
// ── Liberty Foodmart — Stock + reservation helpers ──────────
// A reservation HOLDS its stock from the moment it is saved, so the
// same cake can't be sold to a walk-in while it is waiting for pickup.
//
//   • save / edit reservation  → stock is taken (reservation_deduct_stock)
//   • edit reservation         → old items returned, new items taken
//   • delete an ACTIVE one     → stock is returned (reservation_restore_stock)
//   • Mark Complete            → nothing more to take (already held)
//
// reservations.stock_deducted = 1 means "this reservation's items are
// currently taken out of products.quantity". Reservations saved before
// this fix have 0, so they are never "given back" by mistake, and they
// are deducted once when they are marked Complete.
//
// All of these must be called INSIDE a PDO transaction.

/** Thrown when a product doesn't have enough stock left. Safe to show to the user. */
class StockException extends Exception {}

/**
 * Take stock for $items (each: product_id, qty, name).
 * $strict = true  → throws StockException if any product is short
 *                   (caller rolls back, so nothing is half-deducted).
 * $strict = false → never throws; clamps at 0 (used when completing an old
 *                   reservation whose goods are already handed over).
 */
function reservation_deduct_stock(PDO $pdo, array $items, bool $strict = true): void {
    $strictStmt = $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ? AND quantity >= ?');
    $looseStmt  = $pdo->prepare('UPDATE products SET quantity = GREATEST(quantity - ?, 0) WHERE id = ?');

    foreach ($items as $line) {
        $pid = (int)($line['product_id'] ?? 0);
        $qty = (int)($line['qty'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;

        if ($strict) {
            $strictStmt->execute([$qty, $pid, $qty]);
            // 0 rows = "AND quantity >= ?" failed = not enough left
            if ($strictStmt->rowCount() === 0) {
                throw new StockException(($line['name'] ?? 'An item') . ' does not have enough stock left.');
            }
        } else {
            $looseStmt->execute([$qty, $pid]);
        }
    }
}

/** Give back the stock held by a reservation's items (read from reservation_items). */
function reservation_restore_stock(PDO $pdo, int $reservation_id): void {
    $rows = $pdo->prepare('SELECT product_id, quantity FROM reservation_items WHERE reservation_id = ? AND product_id IS NOT NULL');
    $rows->execute([$reservation_id]);

    $add = $pdo->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?');
    foreach ($rows->fetchAll() as $r) {
        $add->execute([(int)$r['quantity'], (int)$r['product_id']]);
    }
}

/** Lock one reservation row for the rest of the transaction (FOR UPDATE). */
function reservation_lock(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare('SELECT id, product_id, quantity, status, stock_deducted FROM reservations WHERE id = ? FOR UPDATE');
    $s->execute([$id]);
    $row = $s->fetch();
    return $row ?: null;
}

/**
 * Read a pickup date/time from the browser ('2026-10-21T14:30' etc.).
 * Returns [ 'Y-m-d H:i:s' or null, error message or null ].
 * Rejects unreadable values and times in the past (shop time, Asia/Manila;
 * 5 minutes of slack because the picker only has minute precision).
 */
function reservation_pickup(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [null, 'Please enter a valid pickup date and time.'];
    try {
        $tz  = new DateTimeZone('Asia/Manila');
        $dt  = new DateTime($raw, $tz);
        $now = new DateTime('now', $tz);
    } catch (Exception $e) {
        return [null, 'Please enter a valid pickup date and time.'];
    }
    if ($dt->getTimestamp() < $now->getTimestamp() - 300) {
        return [null, 'The pickup date and time cannot be in the past.'];
    }
    return [$dt->format('Y-m-d H:i:s'), null];
}

/** 09171234567 / 0917-123-4567 -> 09X-XXXX-XXXX, or null if not a PH mobile number. */
function normalize_ph_mobile(string $raw): ?string {
    $d = preg_replace('/\D/', '', $raw);
    if (!preg_match('/^09\d{9}$/', $d)) return null;
    return substr($d, 0, 3) . '-' . substr($d, 3, 4) . '-' . substr($d, 7, 4);
}

/** Return an ORDER's items to stock (used when an online order is cancelled). */
function order_restore_stock(PDO $pdo, int $order_id): void {
    $rows = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ? AND product_id IS NOT NULL');
    $rows->execute([$order_id]);
    $add = $pdo->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?');
    foreach ($rows->fetchAll() as $r) {
        $add->execute([(int)$r['quantity'], (int)$r['product_id']]);
    }
}

/** Take an ORDER's items out of stock again (cancelled order being reopened). Throws StockException if short. */
function order_take_stock(PDO $pdo, int $order_id): void {
    $rows = $pdo->prepare('SELECT product_id, product_name AS name, quantity AS qty FROM order_items WHERE order_id = ?');
    $rows->execute([$order_id]);
    reservation_deduct_stock($pdo, $rows->fetchAll());
}