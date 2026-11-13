<?php
session_start();
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/csrf.php';

// Enforce admin-only access
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../pages/dashboard.php');
    exit();
}

$current_admin_id = (int) $_SESSION['user_id'];
$user_name = $_SESSION['name'] ?? 'Admin';

// ── HANDLE ADMIN AJAX ACTIONS ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    csrf_verify_json();

    $action = $_POST['action'] ?? '';
    $target_id = (int) ($_POST['target_id'] ?? 0);

    if ($target_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid user ID', 'csrf_token' => csrf_token()]);
        exit();
    }

    // Safety: prevent admin from modifying/deleting their own account
    if ($target_id === $current_admin_id) {
        echo json_encode(['success' => false, 'message' => 'You cannot modify or delete your own admin account.', 'csrf_token' => csrf_token()]);
        exit();
    }

    if ($action === 'toggle_status') {
        // Fetch current status
        $stmt = $conn->prepare("SELECT status FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $target_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.', 'csrf_token' => csrf_token()]);
            exit();
        }

        $new_status = ($user['status'] === 'active') ? 'suspended' : 'active';
        $up_stmt = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ?");
        $up_stmt->bind_param("si", $new_status, $target_id);
        $ok = $up_stmt->execute();
        $up_stmt->close();

        $msg = ($new_status === 'suspended') ? 'User account suspended (blocked from login).' : 'User account re-activated.';
        echo json_encode(['success' => $ok, 'new_status' => $new_status, 'message' => $msg, 'csrf_token' => csrf_token()]);
        exit();
    }

    if ($action === 'toggle_role') {
        // Fetch current role
        $stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $target_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.', 'csrf_token' => csrf_token()]);
            exit();
        }

        $new_role = ($user['role'] === 'admin') ? 'user' : 'admin';
        $up_stmt = $conn->prepare("UPDATE users SET role = ? WHERE user_id = ?");
        $up_stmt->bind_param("si", $new_role, $target_id);
        $ok = $up_stmt->execute();
        $up_stmt->close();

        $msg = ($new_role === 'admin') ? 'User promoted to Administrator.' : 'User role demoted to standard User.';
        echo json_encode(['success' => $ok, 'new_role' => $new_role, 'message' => $msg, 'csrf_token' => csrf_token()]);
        exit();
    }

    if ($action === 'delete_user') {
        // Clean related notification records
        $conn->query("DELETE FROM budget_over_notifications WHERE user_id = $target_id");
        $conn->query("DELETE FROM budget_alerts WHERE user_id = $target_id");

        // Delete user (transactions and budgets cascade delete via foreign keys)
        $del_stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
        $del_stmt->bind_param("i", $target_id);
        $ok = $del_stmt->execute();
        $del_stmt->close();

        echo json_encode(['success' => $ok, 'message' => 'User and associated data permanently deleted.', 'csrf_token' => csrf_token()]);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Unrecognized action.', 'csrf_token' => csrf_token()]);
    exit();
}

// System aggregates for admin overview
$total_users = $conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
$active_users = $conn->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetch_row()[0];
$total_tx = $conn->query("SELECT COUNT(*) FROM transactions WHERE is_deleted_by_user = 0")->fetch_row()[0];

// Fetch all users with transaction metrics
$users_stmt = $conn->query("
    SELECT 
        u.user_id,
        u.full_name,
        u.name,
        u.email,
        u.phone,
        u.role,
        u.status,
        COUNT(t.transaction_id) AS tx_count,
        COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END), 0) AS total_spent,
        COALESCE(SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE 0 END), 0) AS total_income
    FROM users u
    LEFT JOIN transactions t ON t.user_id = u.user_id AND t.is_deleted_by_user = 0
    GROUP BY u.user_id
    ORDER BY u.user_id ASC
");
$all_users = $users_stmt ? $users_stmt->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Management — Fintrack Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/fintrack.css">
<style>
.admin-badge {
    background: rgba(255,107,53,.15);
    color: #FF6B35;
    border: 1px solid rgba(255,107,53,.3);
    padding: 3px 9px;
    border-radius: 99px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.user-badge {
    background: rgba(255,255,255,.07);
    color: var(--text-2);
    border: 1px solid var(--line);
    padding: 3px 9px;
    border-radius: 99px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 9px;
    border-radius: 99px;
    font-size: 11px;
    font-weight: 600;
}
.status-active {
    background: rgba(16,185,129,.12);
    color: #10B981;
}
.status-active .status-dot {
    background: #10B981;
}
.status-suspended {
    background: rgba(239,68,68,.14);
    color: #EF4444;
}
.status-suspended .status-dot {
    background: #EF4444;
}
.status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
}
.avatar-circle {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #FFD18A, #FF8A5A);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
    color: #1F1B2F;
    flex-shrink: 0;
}
.user-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}
.user-info-name {
    font-weight: 600;
    color: var(--text-1);
    font-size: 13.5px;
}
.user-info-sub {
    font-size: 11px;
    color: var(--text-3);
}
.search-box {
    background: var(--surface-2);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    padding: 9px 14px;
    color: var(--text-1);
    font-size: 13px;
    outline: none;
    width: 260px;
    transition: border-color .15s;
}
.search-box:focus {
    border-color: #FF6B35;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
.btn-action {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all .15s ease;
    background: transparent;
}
.btn-action-warn {
    color: #F59E0B;
    border-color: rgba(245,158,11,.25);
    background: rgba(245,158,11,.08);
}
.btn-action-warn:hover {
    background: rgba(245,158,11,.18);
}
.btn-action-success {
    color: #10B981;
    border-color: rgba(16,185,129,.25);
    background: rgba(16,185,129,.08);
}
.btn-action-success:hover {
    background: rgba(16,185,129,.18);
}
.btn-action-role {
    color: var(--text-2);
    border-color: var(--line);
    background: var(--surface-2);
}
.btn-action-role:hover {
    color: var(--text-1);
    border-color: rgba(255,255,255,.2);
}
.btn-action-danger {
    color: #EF4444;
    border-color: rgba(239,68,68,.25);
    background: rgba(239,68,68,.08);
}
.btn-action-danger:hover {
    background: rgba(239,68,68,.18);
}
.actions-cell {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
}
.you-pill {
    font-size: 11px;
    color: var(--text-3);
    font-style: italic;
    padding: 4px 8px;
}

/* Modal for confirmation */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.7);
    backdrop-filter: blur(8px);
    z-index: 200;
    align-items: center;
    justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    width: 100%;
    max-width: 420px;
    padding: 24px;
    box-shadow: var(--shadow-lg);
}

@media (max-width: 800px) {
    .stats-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<div class="layout">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-mark">
                <div class="brand-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="brand-text">
                    <div class="brand-name">Fintrack</div>
                    <div class="brand-tagline">Personal Finance</div>
                </div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section-label">Overview</div>
            <a href="admin_dashboard.php" class="nav-item" title="Dashboard">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10-3a1 1 0 011-1h4a1 1 0 011 1v7a1 1 0 01-1 1h-4a1 1 0 01-1-1v-7z"/></svg>
                <span class="nav-label">System Overview</span>
            </a>

            <div class="nav-section-label" style="margin-top:16px;">Management</div>
            <a href="users.php" class="nav-item active" title="Users">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                <span class="nav-label">User Directory</span>
            </a>

            <div class="nav-section-label" style="margin-top:16px;">Analytics</div>
            <a href="reports.php" class="nav-item" title="Reports">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0h6m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2zM9 19H5a2 2 0 01-2-2v-3a2 2 0 012-2h2"/></svg>
                <span class="nav-label">System Reports</span>
            </a>

            <div class="nav-section-label" style="margin-top:16px;">Account</div>
            <a href="/finance_system/pages/settings.php" class="nav-item" title="Settings">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <span class="nav-label">Settings</span>
            </a>
            <a href="../auth/logout.php" class="nav-item" title="Logout">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                <span class="nav-label">Logout</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-row">
                <div class="user-avatar"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
                <div class="nav-label">
                    <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
                    <div style="font-size:10px;color:#FF6B35;font-weight:700;letter-spacing:.5px;">ADMINISTRATOR</div>
                </div>
            </div>
        </div>
    </aside>

    <!-- MAIN -->
    <div class="main">

        <!-- TOPBAR -->
        <div class="topbar">
            <div class="topbar-left">
                <div class="breadcrumbs">
                    <a href="admin_dashboard.php" class="breadcrumb-item">Dashboard</a>
                    <span class="breadcrumb-sep">/</span>
                    <span class="breadcrumb-current">Admin Users</span>
                </div>
            </div>
            <div class="topbar-right">
                <a href="admin_dashboard.php" class="btn btn-ghost">← Back to Dashboard</a>
            </div>
        </div>

        <!-- CONTENT -->
        <div class="content">

            <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
                <div>
                    <h1 class="page-title">Registered Users</h1>
                    <p class="page-sub">System-wide account overview, security moderation, and role management</p>
                </div>
                <div>
                    <input type="text" id="userSearch" class="search-box" placeholder="Search by name or email…" oninput="filterUsers(this.value)">
                </div>
            </div>

            <!-- STATS -->
            <div class="stats-grid">
                <div class="card" style="padding:18px 22px;">
                    <div class="card-sub" style="text-transform:uppercase;letter-spacing:.6px;font-size:10.5px;font-weight:700;">Total Registered</div>
                    <div style="font-size:26px;font-weight:800;color:var(--text-1);margin-top:6px;"><span id="statTotalUsers"><?php echo $total_users; ?></span> <span style="font-size:13px;font-weight:500;color:var(--text-3);">Accounts</span></div>
                </div>
                <div class="card" style="padding:18px 22px;">
                    <div class="card-sub" style="text-transform:uppercase;letter-spacing:.6px;font-size:10.5px;font-weight:700;">Active Users</div>
                    <div style="font-size:26px;font-weight:800;color:#10B981;margin-top:6px;"><span id="statActiveUsers"><?php echo $active_users; ?></span> <span style="font-size:13px;font-weight:500;color:var(--text-3);">Active</span></div>
                </div>
                <div class="card" style="padding:18px 22px;">
                    <div class="card-sub" style="text-transform:uppercase;letter-spacing:.6px;font-size:10.5px;font-weight:700;">Total Transactions</div>
                    <div style="font-size:26px;font-weight:800;color:#FF6B35;margin-top:6px;"><?php echo $total_tx; ?> <span style="font-size:13px;font-weight:500;color:var(--text-3);">System Wide</span></div>
                </div>
            </div>

            <!-- USERS TABLE -->
            <div class="card" style="padding:0;overflow:hidden;">
                <div style="padding:20px 24px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <div class="card-title" style="margin:0;">User Accounts Directory</div>
                        <div class="card-sub">Manage user roles, block/unblock accounts, or remove user data</div>
                    </div>
                    <div style="font-size:12px;color:var(--text-3);" id="userCountDisplay">Showing <?php echo count($all_users); ?> users</div>
                </div>

                <div style="overflow-x:auto;">
                    <table class="tx-table" style="margin:0;">
                        <thead>
                            <tr style="background:var(--surface-2);border-bottom:1px solid var(--line);">
                                <th style="padding:12px 24px;text-align:left;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;">User</th>
                                <th style="padding:12px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;">Role</th>
                                <th style="padding:12px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;">Status</th>
                                <th style="padding:12px 14px;text-align:right;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;">Transactions</th>
                                <th style="padding:12px 18px;text-align:right;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;">Total Spent</th>
                                <th style="padding:12px 24px;text-align:right;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;">Admin Actions</th>
                            </tr>
                        </thead>
                        <tbody id="userTableBody">
                            <?php foreach ($all_users as $u): 
                                $uid = (int) $u['user_id'];
                                $displayName = !empty($u['full_name']) ? $u['full_name'] : (!empty($u['name']) ? $u['name'] : 'User #' . $uid);
                                $isSelf = ($uid === $current_admin_id);
                                $isSuspended = ($u['status'] === 'suspended');
                                $isAdmin = ($u['role'] === 'admin');
                            ?>
                            <tr class="user-row-item" id="user-row-<?php echo $uid; ?>" data-search="<?php echo htmlspecialchars(strtolower($displayName . ' ' . $u['email'])); ?>">
                                <td style="padding:14px 24px;">
                                    <div class="user-cell">
                                        <div class="avatar-circle">
                                            <?php echo strtoupper(substr($displayName, 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="user-info-name">
                                                <?php echo htmlspecialchars($displayName); ?>
                                                <?php if ($isSelf): ?>
                                                    <span style="font-size:10px;font-weight:700;color:#FF6B35;margin-left:4px;">(YOU)</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="user-info-sub"><?php echo htmlspecialchars($u['email']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="padding:14px 14px;">
                                    <span id="role-badge-<?php echo $uid; ?>" class="<?php echo $isAdmin ? 'admin-badge' : 'user-badge'; ?>">
                                        <?php echo $isAdmin ? 'Admin' : 'User'; ?>
                                    </span>
                                </td>
                                <td style="padding:14px 14px;">
                                    <span id="status-pill-<?php echo $uid; ?>" class="status-pill <?php echo $isSuspended ? 'status-suspended' : 'status-active'; ?>">
                                        <span class="status-dot"></span>
                                        <span id="status-text-<?php echo $uid; ?>"><?php echo $isSuspended ? 'Suspended' : 'Active'; ?></span>
                                    </span>
                                </td>
                                <td style="padding:14px 14px;text-align:right;font-weight:600;color:var(--text-1);">
                                    <?php echo (int) $u['tx_count']; ?>
                                </td>
                                <td style="padding:14px 18px;text-align:right;font-weight:700;color:var(--text-1);font-family:var(--font-mono);">
                                    KES <?php echo number_format((float)$u['total_spent'], 2); ?>
                                </td>
                                <td style="padding:14px 24px;text-align:right;">
                                    <?php if ($isSelf): ?>
                                        <span class="you-pill">Signed In</span>
                                    <?php else: ?>
                                        <div class="actions-cell">
                                            <!-- Block / Unblock -->
                                            <button 
                                                id="block-btn-<?php echo $uid; ?>" 
                                                class="btn-action <?php echo $isSuspended ? 'btn-action-success' : 'btn-action-warn'; ?>" 
                                                onclick="toggleStatus(<?php echo $uid; ?>)" 
                                                title="<?php echo $isSuspended ? 'Unblock user' : 'Block user from signing in'; ?>">
                                                <?php echo $isSuspended ? '✓ Unblock' : '⊘ Block'; ?>
                                            </button>

                                            <!-- Toggle Role -->
                                            <button 
                                                id="role-btn-<?php echo $uid; ?>" 
                                                class="btn-action btn-action-role" 
                                                onclick="toggleRole(<?php echo $uid; ?>)" 
                                                title="Switch role between User and Admin">
                                                <?php echo $isAdmin ? 'Demote' : 'Make Admin'; ?>
                                            </button>

                                            <!-- Delete User -->
                                            <button 
                                                class="btn-action btn-action-danger" 
                                                onclick="promptDelete(<?php echo $uid; ?>, '<?php echo htmlspecialchars(addslashes($displayName)); ?>')" 
                                                title="Permanently delete user and records">
                                                🗑 Delete
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- /content -->
    </div><!-- /main -->
</div><!-- /layout -->

<!-- DELETE CONFIRMATION MODAL -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-card">
        <h3 style="margin:0 0 8px;font-size:18px;color:var(--text-1);">Confirm User Deletion</h3>
        <p style="font-size:13px;color:var(--text-2);line-height:1.5;margin-bottom:20px;">
            Are you sure you want to permanently delete <strong id="deleteTargetName" style="color:var(--text-1);"></strong>? All their transactions and budgets will also be removed. This action cannot be undone.
        </p>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button class="btn btn-ghost" onclick="closeDeleteModal()">Cancel</button>
            <button class="btn" style="background:#EF4444;color:#fff;" id="confirmDeleteBtn" onclick="executeDelete()">Delete User</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
let CSRF_TOKEN = "<?php echo csrf_token(); ?>";
let pendingDeleteId = null;

function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast ' + type + ' show';
    setTimeout(() => t.classList.remove('show'), 3200);
}

function filterUsers(q) {
    q = q.trim().toLowerCase();
    const rows = document.querySelectorAll('.user-row-item');
    let visible = 0;
    rows.forEach(r => {
        const text = r.getAttribute('data-search') || '';
        if (!q || text.includes(q)) {
            r.style.display = '';
            visible++;
        } else {
            r.style.display = 'none';
        }
    });
    document.getElementById('userCountDisplay').textContent = 'Showing ' + visible + ' users';
}

function toggleStatus(userId) {
    const fd = new FormData();
    fd.append('action', 'toggle_status');
    fd.append('target_id', userId);
    fd.append('csrf_token', CSRF_TOKEN);

    fetch('users.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.csrf_token) CSRF_TOKEN = d.csrf_token;
            if (d.success) {
                showToast(d.message);
                const pill = document.getElementById('status-pill-' + userId);
                const text = document.getElementById('status-text-' + userId);
                const btn  = document.getElementById('block-btn-' + userId);

                if (d.new_status === 'suspended') {
                    pill.className = 'status-pill status-suspended';
                    text.textContent = 'Suspended';
                    btn.className = 'btn-action btn-action-success';
                    btn.textContent = '✓ Unblock';
                    btn.title = 'Unblock user';
                } else {
                    pill.className = 'status-pill status-active';
                    text.textContent = 'Active';
                    btn.className = 'btn-action btn-action-warn';
                    btn.textContent = '⊘ Block';
                    btn.title = 'Block user from signing in';
                }
                updateActiveCount();
            } else {
                showToast(d.message || 'Action failed', 'error');
            }
        })
        .catch(() => showToast('Error communicating with server.', 'error'));
}

function toggleRole(userId) {
    const fd = new FormData();
    fd.append('action', 'toggle_role');
    fd.append('target_id', userId);
    fd.append('csrf_token', CSRF_TOKEN);

    fetch('users.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.csrf_token) CSRF_TOKEN = d.csrf_token;
            if (d.success) {
                showToast(d.message);
                const badge = document.getElementById('role-badge-' + userId);
                const btn   = document.getElementById('role-btn-' + userId);

                if (d.new_role === 'admin') {
                    badge.className = 'admin-badge';
                    badge.textContent = 'Admin';
                    btn.textContent = 'Demote';
                } else {
                    badge.className = 'user-badge';
                    badge.textContent = 'User';
                    btn.textContent = 'Make Admin';
                }
            } else {
                showToast(d.message || 'Action failed', 'error');
            }
        })
        .catch(() => showToast('Error communicating with server.', 'error'));
}

function promptDelete(userId, userName) {
    pendingDeleteId = userId;
    document.getElementById('deleteTargetName').textContent = userName;
    document.getElementById('deleteModal').classList.add('open');
}

function closeDeleteModal() {
    pendingDeleteId = null;
    document.getElementById('deleteModal').classList.remove('open');
}

function executeDelete() {
    if (!pendingDeleteId) return;
    const btn = document.getElementById('confirmDeleteBtn');
    btn.disabled = true;
    btn.textContent = 'Deleting…';

    const fd = new FormData();
    fd.append('action', 'delete_user');
    fd.append('target_id', pendingDeleteId);
    fd.append('csrf_token', CSRF_TOKEN);

    fetch('users.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            btn.textContent = 'Delete User';
            closeDeleteModal();
            if (d.csrf_token) CSRF_TOKEN = d.csrf_token;
            if (d.success) {
                showToast(d.message);
                const row = document.getElementById('user-row-' + pendingDeleteId);
                if (row) row.remove();
                updateTotalCounts();
            } else {
                showToast(d.message || 'Failed to delete user', 'error');
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.textContent = 'Delete User';
            closeDeleteModal();
            showToast('Error communicating with server.', 'error');
        });
}

function updateActiveCount() {
    const active = document.querySelectorAll('.status-active').length;
    const stat = document.getElementById('statActiveUsers');
    if (stat) stat.textContent = active;
}

function updateTotalCounts() {
    const rows = document.querySelectorAll('.user-row-item').length;
    const stat = document.getElementById('statTotalUsers');
    if (stat) stat.textContent = rows;
    const disp = document.getElementById('userCountDisplay');
    if (disp) disp.textContent = 'Showing ' + rows + ' users';
    updateActiveCount();
}
</script>

</body>
</html>
