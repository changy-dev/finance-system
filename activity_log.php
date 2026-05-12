<?php
// ============================================================
// activity_log.php — Login history & audit trail
// ============================================================
session_start();
require_once 'db.php';
require_once 'audit.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

$user_id  = (int) $_SESSION['user_id'];
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

// Pagination
$per_page = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

// Filter
$filter_action = $_GET['action'] ?? '';
$filter_date   = $_GET['date']   ?? '';

// Fetch target user (admin can view anyone)
$view_user_id = $is_admin && isset($_GET['uid']) ? (int)$_GET['uid'] : $user_id;

// Build query
$where  = "WHERE al.user_id = ?";
$params = [$view_user_id];
$types  = "i";

if ($filter_action) {
    $where  .= " AND al.action LIKE ?";
    $like    = $filter_action . '%';
    $params[] = $like;
    $types   .= "s";
}
if ($filter_date) {
    $where  .= " AND DATE(al.created_at) = ?";
    $params[] = $filter_date;
    $types   .= "s";
}

// Count
$count_stmt = $conn->prepare("SELECT COUNT(*) c FROM audit_log al $where");
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total = (int)$count_stmt->get_result()->fetch_assoc()['c'];
$total_pages = max(1, ceil($total / $per_page));

// Fetch rows
$params_paged  = array_merge($params, [$per_page, $offset]);
$types_paged   = $types . "ii";
$stmt = $conn->prepare("
    SELECT al.log_id, al.action, al.target_type, al.target_id,
           al.old_values, al.new_values, al.ip_address, al.created_at,
           u.full_name
    FROM audit_log al
    LEFT JOIN users u ON u.user_id = al.user_id
    $where
    ORDER BY al.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param($types_paged, ...$params_paged);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Action icon map
function actionIcon(string $action): string {
    $map = [
        'auth.login'          => ['🔑', 'icon-login'],
        'auth.logout'         => ['👋', 'icon-logout'],
        'auth.2fa_verified'   => ['🛡️', 'icon-2fa'],
        'auth.failed'         => ['🚫', 'icon-failed'],
        'transaction.create'  => ['➕', 'icon-create'],
        'transaction.edit'    => ['✏️', 'icon-edit'],
        'transaction.delete'  => ['🗑️', 'icon-delete'],
        'budget.create'       => ['📊', 'icon-create'],
        'budget.delete'       => ['🗑️', 'icon-delete'],
        'export.csv'          => ['📄', 'icon-export'],
        'export.pdf'          => ['📑', 'icon-export'],
        'settings.update'     => ['⚙️', 'icon-settings'],
        'password.change'     => ['🔒', 'icon-security'],
        'import.csv'          => ['📥', 'icon-import'],
    ];
    $prefix = explode('.', $action)[0] . '.';
    foreach ($map as $key => [$icon, $cls]) {
        if ($action === $key || strpos($action, $key) === 0) return "<span class='badge {$cls}'>{$icon}</span>";
    }
    return "<span class='badge icon-default'>📋</span>";
}

function actionLabel(string $action): string {
    $labels = [
        'auth.login'         => 'Logged In',
        'auth.logout'        => 'Logged Out',
        'auth.2fa_verified'  => '2FA Verified',
        'auth.failed'        => 'Failed Login Attempt',
        'transaction.create' => 'Transaction Added',
        'transaction.edit'   => 'Transaction Updated',
        'transaction.delete' => 'Transaction Deleted',
        'budget.create'      => 'Budget Created',
        'budget.delete'      => 'Budget Deleted',
        'export.csv'         => 'CSV Export',
        'export.pdf'         => 'PDF Export',
        'settings.update'    => 'Settings Updated',
        'password.change'    => 'Password Changed',
        'import.csv'         => 'CSV Import',
    ];
    return $labels[$action] ?? ucwords(str_replace(['.','_'], ' ', $action));
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Activity Log | Royal Matrix</title>
<style>
:root {
  --bg:#0f172a; --bg2:#1e293b; --bg3:#334155; --border:rgba(255,255,255,.08);
  --text:#f1f5f9; --muted:#94a3b8; --purple:#7c3aed; --cyan:#06b6d4; --gold:#fbbf24;
  --green:#22c55e; --red:#ef4444;
}
[data-theme="light"] {
  --bg:#f8fafc; --bg2:#fff; --bg3:#e2e8f0; --border:rgba(0,0,0,.07);
  --text:#0f172a; --muted:#64748b;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.sidebar{width:240px;height:100vh;position:fixed;background:var(--bg2);border-right:1px solid var(--border);
         padding:24px 16px;display:flex;flex-direction:column;gap:4px}
.sidebar .logo{font-size:18px;font-weight:800;padding:8px 12px 20px;color:var(--purple)}
.sidebar a{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:10px;
           text-decoration:none;color:var(--muted);font-size:14px;font-weight:500;transition:.2s}
.sidebar a:hover{background:var(--bg3);color:var(--text)}
.sidebar a.active{background:linear-gradient(135deg,#7c3aed22,#06b6d422);color:var(--purple);border:1px solid #7c3aed44}
.main{margin-left:240px;padding:32px;min-height:100vh}
.page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:16px}
.page-title h1{font-size:22px;font-weight:700}
.page-title p{color:var(--muted);font-size:14px;margin-top:4px}
.filters{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.filters select,.filters input{padding:9px 14px;background:var(--bg2);border:1px solid var(--border);
    border-radius:10px;color:var(--text);font-size:13px;outline:none}
.filters select:focus,.filters input:focus{border-color:var(--purple)}
.filters button{padding:9px 18px;background:var(--purple);border:none;border-radius:10px;
    color:#fff;font-size:13px;font-weight:600;cursor:pointer}
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:28px}
.stat-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:18px 20px}
.stat-card .num{font-size:26px;font-weight:800;margin-bottom:4px}
.stat-card .lbl{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.05em}
.log-table{background:var(--bg2);border:1px solid var(--border);border-radius:16px;overflow:hidden}
.log-table table{width:100%;border-collapse:collapse}
.log-table th{padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;
    letter-spacing:.06em;color:var(--muted);background:var(--bg);border-bottom:1px solid var(--border);text-align:left}
.log-table td{padding:13px 18px;font-size:13px;border-bottom:1px solid var(--border);vertical-align:middle}
.log-table tr:last-child td{border-bottom:none}
.log-table tr:hover td{background:var(--bg3)}
.badge{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;
       border-radius:8px;font-size:14px}
.icon-login{background:#22c55e18;border:1px solid #22c55e33}
.icon-logout{background:#94a3b818;border:1px solid #94a3b833}
.icon-2fa{background:#7c3aed18;border:1px solid #7c3aed33}
.icon-failed{background:#ef444418;border:1px solid #ef444433}
.icon-create{background:#06b6d418;border:1px solid #06b6d433}
.icon-edit{background:#fbbf2418;border:1px solid #fbbf2433}
.icon-delete{background:#ef444418;border:1px solid #ef444433}
.icon-export{background:#8b5cf618;border:1px solid #8b5cf633}
.icon-import{background:#10b98118;border:1px solid #10b98133}
.icon-security{background:#f5920418;border:1px solid #f5920433}
.icon-settings{background:#64748b18;border:1px solid #64748b33}
.icon-default{background:#33415518;border:1px solid #33415533}
.action-label{font-weight:600;color:var(--text)}
.action-detail{font-size:12px;color:var(--muted);margin-top:2px}
.ip-badge{font-family:monospace;font-size:12px;color:var(--muted);background:var(--bg);
          padding:3px 8px;border-radius:6px;border:1px solid var(--border)}
.time-ago{font-size:12px;color:var(--muted)}
.time-full{font-size:11px;color:var(--muted);margin-top:2px}
.pagination{display:flex;gap:8px;justify-content:center;align-items:center;padding:20px}
.pagination a,.pagination span{padding:8px 14px;border-radius:8px;text-decoration:none;
    font-size:13px;font-weight:600;border:1px solid var(--border)}
.pagination a{color:var(--text);background:var(--bg2)}
.pagination a:hover{background:var(--bg3)}
.pagination .current{background:var(--purple);color:#fff;border-color:var(--purple)}
.empty{padding:60px;text-align:center;color:var(--muted)}
.empty .big{font-size:42px;margin-bottom:12px}
@media(max-width:900px){.main{margin-left:0}.sidebar{display:none}}
</style>
</head>
<body>
<div class="sidebar">
    <div class="logo">💎 Royal Matrix</div>
    <?php $p = basename($_SERVER['PHP_SELF']); ?>
    <a href="dashboard.php">📊 Dashboard</a>
    <a href="transactions.php">💸 Transactions</a>
    <a href="budget.php">🎯 Budget</a>
    <a href="savings_goals.php">💰 Savings</a>
    <a href="reports.php">📈 Reports</a>
    <a href="activity_log.php" class="active">🕵️ Activity Log</a>
    <a href="settings.php">⚙️ Settings</a>
    <a href="logout.php" style="margin-top:auto;color:var(--red)">🚪 Logout</a>
</div>

<div class="main">
    <div class="page-header">
        <div class="page-title">
            <h1>🕵️ Activity Log</h1>
            <p>Your login history, changes, and system events</p>
        </div>
    </div>

    <!-- Stats -->
    <?php
    $s1 = $conn->prepare("SELECT COUNT(*) c FROM audit_log WHERE user_id=? AND action LIKE 'auth.login%'");
    $s1->bind_param("i", $user_id); $s1->execute();
    $login_count = $s1->get_result()->fetch_assoc()['c'];

    $s2 = $conn->prepare("SELECT COUNT(*) c FROM audit_log WHERE user_id=? AND DATE(created_at)=CURDATE()");
    $s2->bind_param("i", $user_id); $s2->execute();
    $today_count = $s2->get_result()->fetch_assoc()['c'];

    $s3 = $conn->prepare("SELECT COUNT(DISTINCT ip_address) c FROM audit_log WHERE user_id=?");
    $s3->bind_param("i", $user_id); $s3->execute();
    $ip_count = $s3->get_result()->fetch_assoc()['c'];

    $s4 = $conn->prepare("SELECT COUNT(*) c FROM audit_log WHERE user_id=? AND action='auth.failed'");
    $s4->bind_param("i", $user_id); $s4->execute();
    $fail_count = $s4->get_result()->fetch_assoc()['c'];
    ?>
    <div class="stats-row">
        <div class="stat-card"><div class="num"><?= number_format($total) ?></div><div class="lbl">Total Events</div></div>
        <div class="stat-card"><div class="num"><?= number_format($login_count) ?></div><div class="lbl">Logins</div></div>
        <div class="stat-card"><div class="num"><?= number_format($today_count) ?></div><div class="lbl">Today</div></div>
        <div class="stat-card"><div class="num"><?= number_format($ip_count) ?></div><div class="lbl">Unique IPs</div></div>
        <div class="stat-card" style="<?= $fail_count > 0 ? 'border-color:#ef444444' : '' ?>">
            <div class="num" style="<?= $fail_count > 0 ? 'color:var(--red)' : '' ?>"><?= $fail_count ?></div>
            <div class="lbl">Failed Attempts</div>
        </div>
    </div>

    <!-- Filters -->
    <form class="filters" method="GET">
        <select name="action">
            <option value="">All Events</option>
            <option value="auth" <?= $filter_action==='auth'?'selected':'' ?>>Authentication</option>
            <option value="transaction" <?= $filter_action==='transaction'?'selected':'' ?>>Transactions</option>
            <option value="budget" <?= $filter_action==='budget'?'selected':'' ?>>Budget</option>
            <option value="export" <?= $filter_action==='export'?'selected':'' ?>>Exports</option>
            <option value="import" <?= $filter_action==='import'?'selected':'' ?>>Imports</option>
            <option value="settings" <?= $filter_action==='settings'?'selected':'' ?>>Settings</option>
        </select>
        <input type="date" name="date" value="<?= htmlspecialchars($filter_date) ?>" placeholder="Filter by date">
        <button type="submit">Filter</button>
        <?php if ($filter_action || $filter_date): ?>
            <a href="activity_log.php" style="padding:9px 14px;color:var(--muted);text-decoration:none;font-size:13px;">✕ Clear</a>
        <?php endif; ?>
    </form>

    <!-- Log Table -->
    <div class="log-table">
        <table>
            <thead>
                <tr>
                    <th style="width:40px">#</th>
                    <th>Event</th>
                    <th>IP Address</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="4">
                    <div class="empty">
                        <div class="big">📭</div>
                        <div>No activity events found</div>
                    </div>
                </td></tr>
            <?php else: ?>
            <?php foreach ($logs as $i => $log):
                $label   = actionLabel($log['action']);
                $icon    = actionIcon($log['action']);
                $created = new DateTime($log['created_at']);
                $now     = new DateTime();
                $diff    = $now->diff($created);
                if ($diff->days > 0)      $ago = $diff->days . 'd ago';
                elseif ($diff->h > 0)     $ago = $diff->h . 'h ago';
                elseif ($diff->i > 0)     $ago = $diff->i . 'm ago';
                else                       $ago = 'just now';

                $detail = '';
                if ($log['new_values']) {
                    $nv = json_decode($log['new_values'], true);
                    if (isset($nv['count']))    $detail = $nv['count'] . ' records';
                    if (isset($nv['from']))     $detail = $nv['from'] . ' → ' . ($nv['to'] ?? '');
                }
            ?>
            <tr>
                <td style="color:var(--muted);font-size:12px"><?= $offset + $i + 1 ?></td>
                <td>
                    <div style="display:flex;align-items:flex-start;gap:12px">
                        <?= $icon ?>
                        <div>
                            <div class="action-label"><?= htmlspecialchars($label) ?></div>
                            <?php if ($detail): ?><div class="action-detail"><?= htmlspecialchars($detail) ?></div><?php endif; ?>
                            <?php if ($log['target_type'] && $log['target_id']): ?>
                                <div class="action-detail"><?= htmlspecialchars(ucfirst($log['target_type'])) ?> #<?= $log['target_id'] ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td><span class="ip-badge"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></span></td>
                <td>
                    <div class="time-ago"><?= $ago ?></div>
                    <div class="time-full"><?= $created->format('d M Y, H:i') ?></div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page-1 ?>&action=<?= urlencode($filter_action) ?>&date=<?= urlencode($filter_date) ?>">← Prev</a>
            <?php endif; ?>
            <?php for ($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="current"><?= $i ?></span>
                <?php else: ?>
                    <a href="?page=<?= $i ?>&action=<?= urlencode($filter_action) ?>&date=<?= urlencode($filter_date) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
                <a href="?page=<?= $page+1 ?>&action=<?= urlencode($filter_action) ?>&date=<?= urlencode($filter_date) ?>">Next →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
