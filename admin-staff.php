<?php
// ── Liberty Foodmart — Admin: Manage Staff ───────────────────
require_once 'session.php';
require 'db.php';

// Admin only
require_once 'auth.php';
require_staff($pdo, 'admin');

$error   = '';
$success = '';

// Password rules live in one shared file (also used by setup + admin forgot-password)
require_once 'password-policy.php';

// ── Handle POST actions ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── CREATE staff account ─────────────────────────────────
    if ($action === 'create') {
        $firstname = trim($_POST['firstname'] ?? '');
        $lastname  = trim($_POST['lastname']  ?? '');
        $password  = $_POST['password']       ?? '';
        $confirm   = $_POST['confirm']        ?? '';

        $username = strtolower($firstname . '.' . $lastname);
        $username = preg_replace('/\s+/', '', $username);

        if (!$firstname || !$lastname) {
            $error = 'Please enter first and last name.';
        } elseif ($pw_err = staff_password_error($password, [$firstname, $lastname, $username])) {
            $error = $pw_err;
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            // ── Max 4 staff accounts allowed ─────────────────
            $count_stmt = $pdo->query("SELECT COUNT(*) FROM staff_users WHERE role = 'staff'");
            if ((int)$count_stmt->fetchColumn() >= 4) {
                $error = 'Maximum of 4 staff accounts reached. Delete an existing account before creating a new one.';
            } else {
            // Make username unique
            $base = $username;
            $n = 1;
            while (true) {
                $chk = $pdo->prepare('SELECT id FROM staff_users WHERE username = ?');
                $chk->execute([$username]);
                if (!$chk->fetch()) break;
                $username = $base . $n++;
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO staff_users (username, password_hash, role) VALUES (?, ?, "staff")')
                ->execute([$username, $hash]);

            $success = 'Staff account created. Username: <strong>' . htmlspecialchars($username) . '</strong>';
            }
        }
    }

    // ── DELETE staff account ─────────────────────────────────
    if ($action === 'delete') {
        $id = (int)($_POST['staff_id'] ?? 0);
        // Never delete own account or another admin
        $chk = $pdo->prepare('SELECT role FROM staff_users WHERE id = ?');
        $chk->execute([$id]);
        $target = $chk->fetch();

        if (!$target) {
            $error = 'Account not found.';
        } elseif ($target['role'] === 'admin') {
            $error = 'Admin accounts cannot be deleted here.';
        } elseif ($id === (int)$_SESSION['staff_id']) {
            $error = 'You cannot delete your own account.';
        } else {
            $pdo->prepare('DELETE FROM staff_users WHERE id = ?')->execute([$id]);
            $success = 'Staff account deleted.';
        }
    }

    // ── RESET PASSWORD ───────────────────────────────────────
    if ($action === 'reset_password') {
        $id       = (int)($_POST['staff_id']  ?? 0);
        $password = $_POST['new_password']    ?? '';
        $confirm  = $_POST['confirm_password']?? '';

        // Look up the account's username so the password can't just be the username
        $un = $pdo->prepare('SELECT username FROM staff_users WHERE id = ?');
        $un->execute([$id]);
        $pw_err = staff_password_error($password, [(string)$un->fetchColumn()]);

        if ($pw_err) {
            $error = $pw_err;
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            // Don't reset another admin's password
            $chk = $pdo->prepare('SELECT role FROM staff_users WHERE id = ?');
            $chk->execute([$id]);
            $target = $chk->fetch();
            if ($target && $target['role'] === 'admin' && $id !== (int)$_SESSION['staff_id']) {
                $error = 'Cannot reset another admin\'s password.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE staff_users SET password_hash = ? WHERE id = ?')
                    ->execute([$hash, $id]);
                // The reset signs that account out everywhere (see auth.php).
                // If the admin reset their OWN password, keep this session valid.
                if ($id === (int)$_SESSION['staff_id']) {
                    $_SESSION['staff_sig'] = auth_pw_sig($hash);
                }
                $success = 'Password reset successfully.';
            }
        }
    }

    // ── EDIT username ────────────────────────────────────────
    if ($action === 'edit_username') {
        $id          = (int)($_POST['staff_id']   ?? 0);
        $new_username = trim($_POST['new_username'] ?? '');

        if (!$new_username) {
            $error = 'Username cannot be empty.';
        } else {
            // Check uniqueness excluding self
            $chk = $pdo->prepare('SELECT id FROM staff_users WHERE username = ? AND id != ?');
            $chk->execute([$new_username, $id]);
            if ($chk->fetch()) {
                $error = 'That username is already taken.';
            } else {
                // Don't let admin edit another admin's username
                $chk2 = $pdo->prepare('SELECT role FROM staff_users WHERE id = ?');
                $chk2->execute([$id]);
                $target = $chk2->fetch();
                if ($target && $target['role'] === 'admin' && $id !== (int)$_SESSION['staff_id']) {
                    $error = 'Cannot edit another admin\'s username.';
                } else {
                    $pdo->prepare('UPDATE staff_users SET username = ? WHERE id = ?')
                        ->execute([$new_username, $id]);
                    $success = 'Username updated to <strong>' . htmlspecialchars($new_username) . '</strong>.';
                }
            }
        }
    }
}

// ── Load all staff (not admin) ───────────────────────────────
$staff_list = $pdo->query("SELECT * FROM staff_users WHERE role = 'staff' ORDER BY created_at DESC")->fetchAll();
$total_staff = count($staff_list);

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Staff — Liberty Foodmart</title>
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

        /* ── Page layout ── */
        .page { max-width:96rem; margin:0 auto; padding:2rem 3%; }

        .page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.6rem; flex-wrap:wrap; gap:1rem; }
        .page-header h1 { font-family:'Playfair Display', serif; font-weight:700; font-size:1.8rem; color:var(--choc-dark); }
        .page-header .stat { font-size:.9rem; color:var(--muted); }

        /* ── Alerts ── */
        .alert { padding:12px 16px; border-radius:10px; font-size:.9rem; margin-bottom:1.2rem; font-weight:600; }
        .alert-success { background:var(--green-bg); color:var(--green); border:1px solid #c8e6c9; }
        .alert-error   { background:var(--red-bg);   color:var(--red);   border:1px solid #f5c6cb; }

        /* ── Cards layout ── */
        .two-col { display:grid; grid-template-columns:1fr 1.8fr; gap:1.6rem; align-items:flex-start; }
        @media (max-width:900px) { .two-col { grid-template-columns:1fr; } }

        .card {
            background:var(--surface); border-radius:16px; padding:1.8rem;
            border:1px solid var(--border);
            box-shadow:0 4px 18px rgba(59,36,23,0.08);
        }
        .card h2 {
            font-family:'Playfair Display', serif; font-weight:700; font-size:1.2rem;
            color:var(--choc-dark); margin-bottom:1.2rem; padding-bottom:.7rem;
            border-bottom:1px solid var(--border);
        }
        .form-group { margin-bottom:1rem; }
        .form-group label { display:block; font-size:.78rem; font-weight:700; color:var(--choc); margin-bottom:.35rem; }
        .form-group input {
            width:100%; padding:.65rem .9rem; border:1.5px solid var(--border); border-radius:8px;
            font-size:.85rem; font-family:'Inter',sans-serif; background:var(--cream); color:var(--choc-dark);
        }
        .form-group input:focus { border-color:var(--caramel); outline:none; background:var(--surface); }

        /* Password wrap inside form */
        .pw-wrap { position:relative; display:flex; align-items:center; }
        .pw-wrap input { width:100%; padding-right:2.5rem; }
        .pw-eye { position:absolute; right:10px; background:none; border:none; cursor:pointer; padding:0; display:flex; align-items:center; }
        .pw-eye img { width:16px; height:16px; opacity:.5; }

        /* Live password checklist */
        .pw-rules { list-style:none; margin:.5rem 0 0; padding:0; font-size:.74rem; color:var(--muted); }
        .pw-rules li { margin-bottom:.15rem; }
        .pw-rules li::before { content:'○ '; }
        .pw-rules li.ok { color:#2e7d32; font-weight:600; }
        .pw-rules li.ok::before { content:'✓ '; }

        .btn-create {
            width:100%; padding:.8rem; border:none; border-radius:8px;
            font-size:.9rem; font-weight:700; cursor:pointer; font-family:'Inter',sans-serif;
            background:linear-gradient(135deg, var(--caramel), var(--caramel-deep)); color:#fff;
            box-shadow:0 4px 12px rgba(139,69,19,0.28); margin-top:.3rem;
        }
        .btn-create:hover { filter:brightness(1.05); }

        /* ── Staff table ── */
        .staff-table { width:100%; border-collapse:collapse; font-size:.85rem; }
        .staff-table th {
            text-align:left; padding:.8rem 1rem; background:var(--cream-deep);
            color:var(--choc-dark); font-weight:700; font-size:.68rem;
            text-transform:uppercase; letter-spacing:.04em; border-bottom:1px solid var(--border);
        }
        .staff-table th:first-child { border-top-left-radius:10px; }
        .staff-table th:last-child { border-top-right-radius:10px; }
        .staff-table td { padding:.85rem 1rem; border-bottom:1px solid var(--border); vertical-align:middle; }
        .staff-table tr:last-child td { border-bottom:none; }
        .staff-table tr:hover td { background:var(--cream); }

        .badge-staff {
            display:inline-block; background:var(--cream-deep); color:var(--caramel-deep);
            padding:3px 10px; border-radius:1rem; font-size:.68rem; font-weight:700;
        }
        .date-small { font-size:.75rem; color:var(--muted); }

        /* Action buttons */
        .action-btns { display:flex; gap:6px; flex-wrap:wrap; }
        .btn-sm {
            padding:5px 11px; border:none; border-radius:7px; font-size:.72rem;
            cursor:pointer; font-family:'Inter',sans-serif; font-weight:700; transition:filter .15s;
        }
        .btn-sm:hover { filter:brightness(.94); }
        .btn-edit   { background:var(--amber-bg); color:var(--amber); }
        .btn-reset  { background:var(--blue-bg);  color:var(--blue); }
        .btn-delete { background:var(--red-bg);   color:var(--red); }

        .empty-state { text-align:center; padding:2.5rem; color:var(--muted); font-size:.9rem; }

        /* ── Modals ── */
        .modal-overlay {
            position:fixed; inset:0; background:rgba(30,15,5,0.55); z-index:2000;
            display:none; align-items:center; justify-content:center;
        }
        .modal-overlay.open { display:flex; }
        .modal-box {
            background:var(--surface); border-radius:16px; padding:1.8rem; width:360px;
            box-shadow:0 20px 50px rgba(30,15,5,0.3); border-top:4px solid var(--gold);
        }
        .modal-box h3 {
            font-family:'Playfair Display', serif; font-weight:700; font-size:1.1rem;
            color:var(--choc-dark); margin-bottom:1.2rem; padding-bottom:.6rem;
            border-bottom:1px solid var(--border);
        }
        .modal-box .form-group { margin-bottom:.9rem; }
        .modal-footer { display:flex; gap:.7rem; margin-top:1.2rem; }
        .modal-footer button {
            flex:1; padding:.75rem; border:none; border-radius:8px;
            font-size:.85rem; font-weight:700; cursor:pointer; font-family:'Inter',sans-serif;
        }
        .btn-confirm { background:linear-gradient(135deg, var(--caramel), var(--caramel-deep)); color:#fff; box-shadow:0 4px 12px rgba(139,69,19,0.28); }
        .btn-confirm:hover { filter:brightness(1.05); }
        .btn-cancel  { background:var(--cream-deep); color:var(--choc); }
        .btn-cancel:hover { background:var(--border); }
        .btn-danger  { background:var(--red); color:#fff; }
        .btn-danger:hover { background:#a01f1f; }
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
    <a href="admin-staff.php" class="navs active"><div class="icon"><img src="src/checkout.png" alt=""></div><pre>Manage Staff</pre></a>
    <a href="logout.php" class="navs"><div class="icon"><img src="src/logout.png" alt=""></div><pre>Logout</pre></a>
</div>
<div class="overlay" id="overlay" onclick="closeMenu()"></div>

<div class="page">

    <div class="page-header">
        <h1>Manage Staff</h1>
        <span class="stat">
            <?= $total_staff ?> / 4 staff accounts
            <?php if ($total_staff >= 4): ?>
                &nbsp;— <span style="color:var(--red);font-weight:700;">Limit reached</span>
            <?php else: ?>
                &nbsp;— <span style="color:var(--green);"><?= 4 - $total_staff ?> slot<?= (4 - $total_staff) !== 1 ? 's' : '' ?> remaining</span>
            <?php endif; ?>
        </span>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="two-col">

        <!-- ── Create Staff Form ── -->
        <div class="card">
            <h2> Create Staff Account</h2>
            <form method="POST" action="admin-staff.php">
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label>First Name</label>
                    <input type="text" name="firstname" placeholder="e.g. Juan" value="<?= e($_POST['firstname'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" name="lastname" placeholder="e.g. dela Cruz" value="<?= e($_POST['lastname'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <div class="pw-wrap">
                        <input type="password" name="password" id="createPass" placeholder="Min. 10 characters" maxlength="72" autocomplete="new-password" required>
                        <button type="button" class="pw-eye" onclick="togglePw('createPass','createPassImg')">
                            <img src="src/show-eye-removebg-preview.png" id="createPassImg" alt="show">
                        </button>
                    </div>
                    <ul class="pw-rules" id="createRules"></ul>
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <div class="pw-wrap">
                        <input type="password" name="confirm" id="createConfirm" placeholder="Repeat password" required>
                        <button type="button" class="pw-eye" onclick="togglePw('createConfirm','createConfirmImg')">
                            <img src="src/show-eye-removebg-preview.png" id="createConfirmImg" alt="show">
                        </button>
                    </div>
                </div>
                <p style="font-size:.78rem;color:var(--muted);margin-bottom:1rem;">
                    Username will be auto-generated as <strong>firstname.lastname</strong>
                </p>
                <?php if ($total_staff >= 4): ?>
                    <p style="font-size:.78rem;color:var(--red);font-weight:700;margin-bottom:1rem;">
                        Staff limit reached (4/4). Delete an account to create a new one.
                    </p>
                <?php endif; ?>
                <button type="submit" class="btn-create" <?= $total_staff >= 4 ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : '' ?>>
                    Create Staff Account
                </button>
            </form>
        </div>

        <!-- ── Staff List ── -->
        <div class="card">
            <h2> Staff Accounts</h2>
            <?php if (empty($staff_list)): ?>
                <div class="empty-state">No staff accounts yet. Create one on the left.</div>
            <?php else: ?>
            <table class="staff-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staff_list as $s): ?>
                    <tr>
                        <td><strong><?= e($s['username']) ?></strong></td>
                        <td><span class="badge-staff">Staff</span></td>
                        <td><span class="date-small"><?= date('M j, Y', strtotime($s['created_at'])) ?></span></td>
                        <td>
                            <div class="action-btns">
                                <button class="btn-sm btn-edit"
                                    onclick="openEditModal(<?= $s['id'] ?>, '<?= e($s['username']) ?>')">
                                     Edit
                                </button>
                                <button class="btn-sm btn-reset"
                                    onclick="openResetModal(<?= $s['id'] ?>, '<?= e($s['username']) ?>')">
                                     Reset PW
                                </button>
                                <button class="btn-sm btn-delete"
                                    onclick="openDeleteModal(<?= $s['id'] ?>, '<?= e($s['username']) ?>')">
                                     Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- ── Edit Username Modal ── -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <h3> Edit Username</h3>
        <form method="POST" action="admin-staff.php">
            <input type="hidden" name="action"   value="edit_username">
            <input type="hidden" name="staff_id" id="editStaffId">
            <div class="form-group">
                <label>New Username for <strong id="editStaffName"></strong></label>
                <input type="text" name="new_username" id="editNewUsername" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn-confirm">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Reset Password Modal ── -->
<div class="modal-overlay" id="resetModal">
    <div class="modal-box">
        <h3> Reset Password</h3>
        <form method="POST" action="admin-staff.php">
            <input type="hidden" name="action"   value="reset_password">
            <input type="hidden" name="staff_id" id="resetStaffId">
            <p style="font-size:.85rem;color:var(--muted);margin-bottom:1rem;">
                Resetting password for <strong id="resetStaffName" style="color:var(--choc-dark);"></strong>
            </p>
            <div class="form-group">
                <label>New Password</label>
                <div class="pw-wrap">
                    <input type="password" name="new_password" id="resetPass" placeholder="Min. 10 characters" maxlength="72" autocomplete="new-password" required>
                    <button type="button" class="pw-eye" onclick="togglePw('resetPass','resetPassImg')">
                        <img src="src/show-eye-removebg-preview.png" id="resetPassImg" alt="show">
                    </button>
                </div>
                <ul class="pw-rules" id="resetRules"></ul>
            </div>
            <div class="form-group">
                <label>Confirm New Password</label>
                <div class="pw-wrap">
                    <input type="password" name="confirm_password" id="resetConfirm" placeholder="Repeat password" required>
                    <button type="button" class="pw-eye" onclick="togglePw('resetConfirm','resetConfirmImg')">
                        <img src="src/show-eye-removebg-preview.png" id="resetConfirmImg" alt="show">
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('resetModal')">Cancel</button>
                <button type="submit" class="btn-confirm">Reset Password</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Delete Confirm Modal ── -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box">
        <h3> Delete Staff Account</h3>
        <form method="POST" action="admin-staff.php">
            <input type="hidden" name="action"   value="delete">
            <input type="hidden" name="staff_id" id="deleteStaffId">
            <p style="font-size:.9rem;color:var(--choc);margin-bottom:1.2rem;">
                Are you sure you want to delete <strong id="deleteStaffName" style="color:var(--choc-dark);"></strong>?
                <br><span style="font-size:.78rem;color:var(--red);">This cannot be undone.</span>
            </p>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" class="btn-danger">Yes, Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Nav ────────────────────────────────────────────────────
const navCont = document.getElementById('nav-cont');
const overlay = document.getElementById('overlay');
function openMenu()  { navCont.classList.add('active');    overlay.classList.add('active'); }
function closeMenu() { navCont.classList.remove('active'); overlay.classList.remove('active'); }

// ── Password eye toggle ────────────────────────────────────
function togglePw(inputId, imgId) {
    const inp = document.getElementById(inputId);
    const img = document.getElementById(imgId);
    const isHidden = inp.type === 'password';
    inp.type = isHidden ? 'text' : 'password';
    img.src  = isHidden ? 'src/hide-eye-removebg-preview.png' : 'src/show-eye-removebg-preview.png';
}

// ── Live password checklist ────────────────────────────────
// Mirrors the server rules so staff see what's missing as they type.
// (The server still checks everything, plus names and common passwords.)
const PW_RULES = [
    ['At least 10 characters',    p => p.length >= 10],
    ['An uppercase letter (A–Z)', p => /[A-Z]/.test(p)],
    ['A lowercase letter (a–z)',  p => /[a-z]/.test(p)],
    ['A number (0–9)',            p => /[0-9]/.test(p)],
    ['A symbol (! @ # $ % …)',    p => /[^A-Za-z0-9]/.test(p)],
];
function bindPwRules(inputId, listId) {
    const inp  = document.getElementById(inputId);
    const list = document.getElementById(listId);
    list.innerHTML = '';
    const items = PW_RULES.map(([label]) => {
        const li = document.createElement('li');
        li.textContent = label;
        list.appendChild(li);
        return li;
    });
    function update() {
        let all = true;
        PW_RULES.forEach(([, test], i) => {
            const ok = test(inp.value);
            items[i].classList.toggle('ok', ok);
            if (!ok) all = false;
        });
        // Stops the form from submitting until every rule is met
        inp.setCustomValidity(all ? '' : 'Password does not meet all the requirements.');
    }
    inp.addEventListener('input', update);
    update();
}
bindPwRules('createPass', 'createRules');
bindPwRules('resetPass',  'resetRules');

// ── Modal helpers ──────────────────────────────────────────
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', function (e) {
        if (e.target === this) this.classList.remove('open');
    });
});

// ── Edit username modal ────────────────────────────────────
function openEditModal(id, username) {
    document.getElementById('editStaffId').value      = id;
    document.getElementById('editStaffName').textContent = username;
    document.getElementById('editNewUsername').value  = username;
    document.getElementById('editModal').classList.add('open');
    setTimeout(() => document.getElementById('editNewUsername').focus(), 100);
}

// ── Reset password modal ───────────────────────────────────
function openResetModal(id, username) {
    document.getElementById('resetStaffId').value       = id;
    document.getElementById('resetStaffName').textContent = username;
    document.getElementById('resetPass').value          = '';
    document.getElementById('resetConfirm').value       = '';
    document.getElementById('resetPass').dispatchEvent(new Event('input'));
    document.getElementById('resetModal').classList.add('open');
}

// ── Delete modal ───────────────────────────────────────────
function openDeleteModal(id, username) {
    document.getElementById('deleteStaffId').value        = id;
    document.getElementById('deleteStaffName').textContent = username;
    document.getElementById('deleteModal').classList.add('open');
}
</script>

</body>
</html>