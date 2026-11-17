-- ============================================================
--  Liberty Foodmart — MySQL Database Schema  (v6)
--  Includes: staff login lockout, and VAT + Senior/PWD discount
--
--  CHANGES FROM v5
--    • orders.payment_method now allows 'Cash' (walk-in POS sales).
--      Before, the POS sent 'Cash' but the column only allowed
--      COD / GCash / Card, so cash sales failed to save.
--
--  HOW TO USE THIS FILE
--    • NEW / EMPTY database  → import this whole file.
--    • EXISTING database with real data → do NOT import this file
--      (the CREATE TABLE lines would fail because the tables already
--      exist). Run fix-04-add-cash-payment.sql instead, which makes
--      the same change in place without touching your data.
-- ============================================================

CREATE DATABASE IF NOT EXISTS liberty_foodmart
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE liberty_foodmart;


-- ============================================================
--  1. STAFF USERS
--     Powers: login.html / signup.html
--     Role determines which nav items are shown (admin vs staff)
-- ============================================================

CREATE TABLE staff_users (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    username      VARCHAR(80)     NOT NULL UNIQUE,
    email         VARCHAR(255)    NULL UNIQUE,            -- admin recovery email (PIN for "Forgot password")
    password_hash VARCHAR(255)    NOT NULL,               -- use PHP password_hash()
    role          ENUM('admin','staff') NOT NULL DEFAULT 'staff',
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id)
) ENGINE=InnoDB;


-- ============================================================
--  1.5 LOGIN ATTEMPTS
--     Powers: login.php account lockout
--
--     Keyed by the username string typed at the login form —
--     NOT by staff_users.id — so a lockout is tracked the same
--     way whether or not that username actually exists. This
--     avoids leaking "this account exists" / "this one doesn't"
--     through different lockout behavior.
--
--     attempts resets to 0 the moment a lockout is triggered or
--     a login succeeds; locked_until is NULL when not locked.
-- ============================================================

CREATE TABLE login_attempts (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    username     VARCHAR(80)   NOT NULL,
    attempts     INT UNSIGNED  NOT NULL DEFAULT 0,
    locked_until DATETIME      NULL,
    updated_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_username (username)
) ENGINE=InnoDB;


-- ============================================================
--  1.6 STAFF PASSWORD RESETS
--     Powers: admin-forgot-password.php (emailed 6-digit PIN)
--     One row per admin; the PIN is stored hashed only.
-- ============================================================
CREATE TABLE staff_password_resets (
    staff_id   INT UNSIGNED  NOT NULL,
    pin_hash   VARCHAR(255)  NOT NULL,
    attempts   INT UNSIGNED  NOT NULL DEFAULT 0,
    verified   TINYINT(1)    NOT NULL DEFAULT 0,
    expires_at DATETIME      NOT NULL,
    created_at DATETIME      NOT NULL,

    PRIMARY KEY (staff_id),
    CONSTRAINT fk_staff_resets_staff
        FOREIGN KEY (staff_id) REFERENCES staff_users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


-- ============================================================
--  2. CUSTOMERS
--     Powers: customer-login.html / customer-register.html /
--             customer-profile.html
--     Phone is indexed because customer-orders.php filters by it
-- ============================================================

CREATE TABLE customers (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name             VARCHAR(120) NOT NULL,
    email            VARCHAR(160) NOT NULL UNIQUE,
    phone            VARCHAR(20)  NULL,
    password_hash    VARCHAR(255) NOT NULL,               -- use PHP password_hash()
    default_location TEXT         NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_phone (phone)
) ENGINE=InnoDB;


-- ============================================================
--  3. PRODUCTS
--     Powers: inventory.html / ordering-page.html /
--             ordering-process.html / staff-orderingv2.html
--
--     price is VAT-INCLUSIVE (12% already baked into the sticker
--     price) — this is what checkout math assumes everywhere.
--
--     image_path stores a file path like "uploads/products/cake.jpg"
--     NOT base64 — upload the file to the server, save the path here
-- ============================================================

CREATE TABLE products (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name                VARCHAR(120)    NOT NULL,
    price               DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    description         TEXT            NULL,
    category            ENUM('Cake','Bread','Pastry','Drink','Other') NOT NULL DEFAULT 'Other',
    quantity            INT             NOT NULL DEFAULT 0,
    low_stock_threshold INT             NOT NULL DEFAULT 10,
    image_path          VARCHAR(255)    NULL,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_category (category)
) ENGINE=InnoDB;


-- ============================================================
--  4. ORDERS
--     Powers: checkout flow / staff-online-orders.html /
--             order-confirmation.html / customer-orders.html /
--             sales.html
--
--     customer_id is NULL for walk-in/guest orders — those
--     are still tracked by customer_name + phone directly
--
--     order_type separates walk-in POS (staff-orderingv2)
--     from online orders (ordering-page customer checkout)
--
--     AUTO_INCREMENT starts at 1001 to match your JS counter
--
--     ── Money breakdown (VAT + Senior/PWD discount) ──
--     Prices are VAT-inclusive. Senior/PWD discount is 5%,
--     discount ONLY (the sale still carries VAT — not exempt).
--       subtotal        = gross of all lines BEFORE discount
--       discount_amount = total 5% discount taken off
--       total           = what the customer PAYS (subtotal - discount)
--                         ^ meaning UNCHANGED from before this
--                           feature existed, so every page already
--                           reading `total` keeps working as-is
--       net_amount       = total / 1.12  (the shop's actual revenue,
--                          used for sales reporting)
--       vat_amount       = total - net_amount
--     Invariant:  subtotal - discount_amount = total = net_amount + vat_amount
--
--     ── Senior/PWD discount request workflow (online only) ──
--     Walk-in POS orders: staff sees the physical ID, ticks the
--     box, discount_status goes straight to 'approved'.
--     Online orders: customer uploads a photo of their ID at
--     checkout, discount is applied immediately (shown to the
--     customer right away), order sits as 'pending' until an
--     admin reviews the photo on the Discount Requests page.
--     If rejected, the order reverts to full price.
--       discount_status: none | pending | approved | rejected
-- ============================================================

CREATE TABLE orders (
    id                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    customer_id           INT UNSIGNED    NULL,                 -- NULL = guest/walk-in
    customer_name         VARCHAR(120)    NOT NULL,
    phone                 VARCHAR(20)     NOT NULL,
    location              TEXT            NOT NULL,
    order_type            ENUM('online','walk_in') NOT NULL DEFAULT 'online',
    order_status          ENUM('Processing','Ready for Delivery','Out for Delivery','Done','Cancelled') NOT NULL DEFAULT 'Processing',
    cancelled_by          ENUM('customer','staff') NULL,            -- who cancelled it
    cancelled_at          DATETIME        NULL,
    cancel_notified       TINYINT(1)      NOT NULL DEFAULT 1,       -- 0 = staff cancelled and the customer hasn't seen the notice yet
    payment_method        ENUM('COD','GCash','Card','Cash') NOT NULL DEFAULT 'COD',   -- 'Cash' = walk-in POS
    payment_status        ENUM('UNPAID','PAID') NOT NULL DEFAULT 'UNPAID',

    subtotal              DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    discount_amount       DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    total                 DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    net_amount            DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    vat_amount            DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    is_senior_pwd         TINYINT(1)      NOT NULL DEFAULT 0,

    discount_status       ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
    pwd_id_image_path     VARCHAR(255)    NULL,
    discount_reviewed_at  DATETIME        NULL,
    discount_reviewed_by  INT UNSIGNED    NULL,                 -- staff_users.id of the admin who reviewed

    created_at            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_phone            (phone),
    INDEX idx_order_status     (order_status),
    INDEX idx_created_at       (created_at),
    INDEX idx_discount_status  (discount_status),

    CONSTRAINT fk_orders_customer
        FOREIGN KEY (customer_id) REFERENCES customers (id)
        ON DELETE SET NULL,

    CONSTRAINT fk_orders_reviewer
        FOREIGN KEY (discount_reviewed_by) REFERENCES staff_users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1001;


-- ============================================================
--  5. ORDER ITEMS
--     Each row is one product line inside an order.
--
--     product_name and unit_price are COPIED from the product
--     at the time of ordering — same as your JS stores item.name
--     and item.price inside the order object. This way old orders
--     are not affected if you later change a product's price.
--
--     product_id is kept as a reference but is nullable in case
--     the product gets deleted later.
--
--     discount_amount / line_total: the 5% Senior/PWD discount is
--     applied per item, so each line keeps its own cut of the
--     discount — this is what lets a receipt show a per-line
--     breakdown instead of just one lump discount on the order.
--       line_total = (unit_price * quantity) - discount_amount
-- ============================================================

CREATE TABLE order_items (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    order_id        INT UNSIGNED  NOT NULL,
    product_id      INT UNSIGNED  NULL,                      -- nullable: product may be deleted
    product_name    VARCHAR(120)  NOT NULL,                  -- snapshot at order time
    unit_price      DECIMAL(10,2) NOT NULL,                  -- snapshot at order time
    quantity        INT           NOT NULL DEFAULT 1,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    line_total      DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    PRIMARY KEY (id),
    INDEX idx_order_id (order_id),

    CONSTRAINT fk_items_order
        FOREIGN KEY (order_id) REFERENCES orders (id)
        ON DELETE CASCADE,

    CONSTRAINT fk_items_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE SET NULL
) ENGINE=InnoDB;


-- ============================================================
--  6. RESERVATIONS
--     Powers: staff-reservation-list.html
--
--     Separate from orders because reservations have a future
--     pickup_datetime and the Paid / Unpaid tab filter.
--     No customer account required.
--
--     Reservations are walk-in/counter only (no online booking
--     flow), so the Senior/PWD discount here is always staff-
--     verified on the spot — no discount_status / ID upload
--     workflow needed, unlike orders.
-- ============================================================

CREATE TABLE reservations (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    customer_id     INT UNSIGNED    NULL,                 -- NULL = guest/walk-in reservation
    customer_name   VARCHAR(120)    NOT NULL,
    contact         VARCHAR(20)     NOT NULL,
    product_id      INT UNSIGNED    NULL,
    product_name    VARCHAR(120)    NOT NULL,              -- snapshot
    quantity        INT             NOT NULL DEFAULT 1,

    subtotal        DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    total_price     DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    net_amount      DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    vat_amount      DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    is_senior_pwd   TINYINT(1)      NOT NULL DEFAULT 0,

    payment_method  ENUM('COD','GCash','Card','Cash') NOT NULL DEFAULT 'COD',   -- reservations are paid in 'Cash' at pickup
    payment_status  ENUM('UNPAID','PAID') NOT NULL DEFAULT 'UNPAID',
    pickup_datetime DATETIME        NOT NULL,
    status          ENUM('Active','Ready for Pickup','Complete','Cancelled') NOT NULL DEFAULT 'Active',   -- shown to staff/customers as Processing / Ready for Pickup / Done / Cancelled
    stock_deducted  TINYINT(1)      NOT NULL DEFAULT 0,   -- 1 = this reservation's items are currently held out of products.quantity
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_pickup      (pickup_datetime),
    INDEX idx_status      (status),
    INDEX idx_customer_id (customer_id),

    CONSTRAINT fk_res_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE SET NULL,

    CONSTRAINT fk_res_customer
        FOREIGN KEY (customer_id) REFERENCES customers (id)
        ON DELETE SET NULL
) ENGINE=InnoDB;


-- ============================================================
--  6.5 RESERVATION ITEMS
--     Powers: staff-reservation-list.php / staff-orderingv2.php
--
--     Mirrors order_items — one row per product inside a
--     reservation. Needed because a reservation can contain more
--     than one product; the reservations table itself only keeps
--     product_id/product_name/quantity as a rolled-up summary
--     (e.g. "Black Forest Cake (+1 more)") for quick display in
--     places like customer-orders.php. This table holds the real
--     per-product breakdown so staff can see every item ordered.
-- ============================================================

CREATE TABLE reservation_items (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    reservation_id  INT UNSIGNED  NOT NULL,
    product_id      INT UNSIGNED  NULL,                   -- nullable: product may be deleted
    product_name    VARCHAR(120)  NOT NULL,                -- snapshot at reservation time
    unit_price      DECIMAL(10,2) NOT NULL,                -- snapshot at reservation time
    quantity        INT           NOT NULL DEFAULT 1,

    PRIMARY KEY (id),
    INDEX idx_reservation_id (reservation_id),

    CONSTRAINT fk_resitems_reservation
        FOREIGN KEY (reservation_id) REFERENCES reservations (id)
        ON DELETE CASCADE,

    CONSTRAINT fk_resitems_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE SET NULL
) ENGINE=InnoDB;


-- ============================================================
--  7. REVIEWS
--     Powers: customer-reviews.html / rating section in
--             customer-orders.html
--
--     One review per order (UNIQUE on order_id).
--     customer_id nullable for guest orders.
--     stars is 1–5.
-- ============================================================

CREATE TABLE reviews (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id       INT UNSIGNED NULL UNIQUE,               -- one review per order   (NULL when it reviews a reservation)
    reservation_id INT UNSIGNED NULL UNIQUE,               -- one review per reservation (NULL when it reviews an order)
    customer_id INT UNSIGNED NULL,
    stars       TINYINT      NOT NULL CHECK (stars BETWEEN 1 AND 5),
    comment     TEXT         NULL,
    rated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    CONSTRAINT fk_reviews_order
        FOREIGN KEY (order_id) REFERENCES orders (id)
        ON DELETE CASCADE,

    CONSTRAINT fk_reviews_reservation
        FOREIGN KEY (reservation_id) REFERENCES reservations (id)
        ON DELETE CASCADE,

    CONSTRAINT fk_reviews_customer
        FOREIGN KEY (customer_id) REFERENCES customers (id)
        ON DELETE SET NULL
) ENGINE=InnoDB;


-- ============================================================
--  7.5 SETTINGS
--     Powers: VAT + Senior/PWD discount math, wherever a total
--     is calculated (checkout, POS, sales reporting).
--
--     Rates live here instead of being hardcoded in PHP, so BIR
--     changing VAT or the client changing the discount rate is a
--     one-row update instead of hunting through multiple files.
-- ============================================================

CREATE TABLE settings (
    setting_key   VARCHAR(60)  NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (setting_key)
) ENGINE=InnoDB;

INSERT INTO settings (setting_key, setting_value) VALUES
    ('vat_rate',      '0.12'),
    ('discount_rate', '0.05');


-- ============================================================
--  SAMPLE DATA — delete this block before going live
-- ============================================================

-- Default admin account (password: admin123 — CHANGE THIS)
INSERT INTO staff_users (username, password_hash, role) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('staff1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'staff');

-- Sample products (mirrors your JS seed data in inventory.js)
INSERT INTO products (name, price, description, category, quantity, low_stock_threshold) VALUES
('Chocolate Walnut', 9999.00, 'Rich walnut chocolate cake',  'Cake',  210, 10),
('Black Forest',     9999.00, 'Classic black forest cake',   'Cake',  3,   10),
('Sourdough Loaf',   350.00,  'Artisan sourdough bread',     'Bread', 8,   10),
('Walnut Bread',     9999.00, 'Freshly baked walnut bread',  'Bread', 15,  10);

-- Sample customer
INSERT INTO customers (name, email, phone, password_hash, default_location) VALUES
('Junicen Tawlo', 'junicen@example.com', '09171234567',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 'Crossing Calamba Laguna');

-- Sample order (mirrors your JS seedSampleOrder in orders-store.js)
-- No discount on this one: subtotal = total, net/vat derived at 12%.
INSERT INTO orders (id, customer_id, customer_name, phone, location, order_type, order_status, payment_method, payment_status, subtotal, discount_amount, total, net_amount, vat_amount, is_senior_pwd, discount_status, created_at)
VALUES (1001, 1, 'Junicen Tawlo', '09171234567', 'Crossing Calamba Laguna', 'online', 'Processing', 'COD', 'UNPAID', 9999.00, 0.00, 9999.00, 8927.68, 1071.32, 0, 'none', '2026-03-27 12:38:00');

INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, discount_amount, line_total)
VALUES (1001, 4, 'Walnut Bread', 9999.00, 1, 0.00, 9999.00);

-- ============================================================
--  PASSWORD RESETS  (customer "Forgot password" PIN)
--     Powers: customer-forgot-password.php
-- ============================================================
CREATE TABLE IF NOT EXISTS password_resets (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id  INT UNSIGNED NOT NULL,
    pin_hash     VARCHAR(255) NOT NULL,               -- password_hash() of the 6-digit PIN
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0, -- wrong PIN tries so far
    verified     TINYINT(1)   NOT NULL DEFAULT 0,     -- 1 once the correct PIN was entered
    expires_at   DATETIME     NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_customer (customer_id),
    CONSTRAINT fk_pwreset_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB;