<?php
// ── Liberty Foodmart — Email (SMTP) settings ────────────────
// Used to send the password-reset PIN. Fill in the shop's Gmail below.
//
// HOW TO GET THE APP PASSWORD (Gmail will NOT accept the normal password):
//   1. Sign in to the shop's Google account → Security.
//   2. Turn ON "2-Step Verification".
//   3. Search "App passwords" → create one (name it "Liberty Foodmart").
//   4. Google shows a 16-letter code — paste it into MAIL_PASSWORD
//      (spaces are fine).
//
// Keep this file private: never upload it to GitHub or share it.
// ------------------------------------------------------------

define('MAIL_HOST',       'smtp.gmail.com');
define('MAIL_PORT',       587);                         // 587 = STARTTLS
define('MAIL_USERNAME',   'jhonlingling630@gmail.com');       // ← the shop's Gmail address
define('MAIL_PASSWORD',   'wgre kyjo eehv tkcs');       // ← the Gmail APP PASSWORD
define('MAIL_FROM_EMAIL', MAIL_USERNAME);
define('MAIL_FROM_NAME',  'PastryLiberty — Liberty Foodmart');

// For testing on your own computer without Gmail: set to true and the email
// (with the PIN) is written to PHP's error log instead of being sent.
// MUST be false on the real site.
define('MAIL_DEV_LOG_ONLY', false);