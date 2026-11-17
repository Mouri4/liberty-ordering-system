<?php
// ── Liberty Foodmart — Staff/Admin password policy ──────────
// One place for the rules, shared by:
//   • setup-admin.php           (first admin account)
//   • admin-staff.php           (create / reset staff passwords)
//   • admin-forgot-password.php (admin resets via emailed PIN)
//
// Returns an error message, or null if the password is acceptable.
// $avoid = words the password must not contain (name / username).

function staff_password_error(string $pw, array $avoid = []): ?string {
    if (strlen($pw) < 10)            return 'Password must be at least 10 characters.';
    if (strlen($pw) > 72)            return 'Password must be 72 characters or fewer.';   // bcrypt silently ignores anything past 72
    if (!preg_match('/[A-Z]/', $pw)) return 'Password must contain at least 1 uppercase letter.';
    if (!preg_match('/[a-z]/', $pw)) return 'Password must contain at least 1 lowercase letter.';
    if (!preg_match('/[0-9]/', $pw)) return 'Password must contain at least 1 number.';
    if (!preg_match('/[^A-Za-z0-9]/', $pw)) return 'Password must contain at least 1 symbol (for example ! @ # $ %).';

    $lower = strtolower($pw);

    // Must not contain the person's name or username
    $squashed = preg_replace('/\s+/', '', $lower);
    foreach ($avoid as $word) {
        $word = preg_replace('/\s+/', '', strtolower((string)$word));
        if (strlen($word) >= 3 && strpos($squashed, $word) !== false) {
            return 'Password must not contain the account holder\'s name or username.';
        }
    }

    // Must not be built from easy-to-guess words or runs (also catches P@ssw0rd-style swaps)
    $leet    = strtr($lower, ['0'=>'o','1'=>'l','3'=>'e','4'=>'a','5'=>'s','7'=>'t','@'=>'a','$'=>'s','!'=>'i']);
    $letters = preg_replace('/[^a-z]/', '', $leet);
    foreach (['password','qwerty','letmein','welcome','admin','liberty','pastry','foodmart','iloveyou','monkey','dragon','football','abcdef'] as $bad) {
        if (strpos($letters, $bad) !== false) return 'That password is too easy to guess. Avoid common words like "password" or the shop name.';
    }
    foreach (['12345','23456','34567','45678','56789','67890','09876','54321'] as $run) {
        if (strpos($lower, $run) !== false) return 'Password must not contain an easy number run like 12345.';
    }
    if (preg_match('/(.)\1{3,}/', $pw)) return 'Password must not repeat the same character 4 or more times in a row.';

    return null;
}