<?php
// ============================================================
// partials/notifications.php — Budget alert bell
// Include inside the topbar-right div on any page that needs it.
// Requires: $conn and $_SESSION already set (header.php handles this).
// ============================================================

$notif_alerts = [];

if (isset($conn) && isset($_SESSION['user_id'])) {
    $uid = (int) $_SESSION['user_id'];

    $nstmt = $conn->prepare("
        SELECT
            b.category,
            b.amount_limit,
            b.current_spent,
            COALESCE(ba.threshold, 80) AS threshold
        FROM budgets b
        LEFT JOIN budget_alerts ba
            ON ba.budget_id = b.budget_id AND ba.user_id = b.user_id
        WHERE b.user_id = ?
          AND b.amount_limit > 0
          AND COALESCE(ba.is_active, 1) = 1
    ");
    $nstmt->bind_param("i", $uid);
    $nstmt->execute();
    $nrows = $nstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $nstmt->close();

    foreach ($nrows as $row) {
        $pct = (float) $row['amount_limit'] > 0
            ? round((float) $row['current_spent'] / (float) $row['amount_limit'] * 100)
            : 0;

        if ($pct >= (int) $row['threshold']) {
            $notif_alerts[] = [
                'category' => $row['category'],
                'pct'      => (int) $pct,
                'spent'    => (float) $row['current_spent'],
                'limit'    => (float) $row['amount_limit'],
                'exceeded' => (float) $row['current_spent'] > (float) $row['amount_limit'],
            ];
        }
    }
}

$notif_count = count($notif_alerts);
?>

<div class="notif-wrap" id="notifWrap">
    <button class="notif-bell" id="notifBtn" onclick="toggleNotif()" title="Budget Alerts" aria-label="Budget Alerts (<?= $notif_count ?> alerts)">
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 00-9.33-4.979A6 6 0 006 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
        </svg>
        <?php if ($notif_count > 0): ?>
            <span class="notif-badge"><?= $notif_count ?></span>
        <?php endif; ?>
    </button>

    <div class="notif-dropdown" id="notifDropdown">
        <div class="notif-header">
            <span>Budget Alerts</span>
            <?php if ($notif_count > 0): ?>
                <span class="notif-count-label"><?= $notif_count ?> active</span>
            <?php endif; ?>
        </div>

        <?php if ($notif_count === 0): ?>
            <div class="notif-empty">
                <svg width="32" height="32" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color:var(--ink-3)">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p>All budgets on track</p>
            </div>
        <?php else: ?>
            <div class="notif-list">
                <?php foreach ($notif_alerts as $a): ?>
                    <a class="notif-item <?= $a['exceeded'] ? 'notif-exceeded' : 'notif-warning' ?>" href="budget.php">
                        <div class="notif-icon"><?= $a['exceeded'] ? '🚨' : '⚠️' ?></div>
                        <div class="notif-body">
                            <div class="notif-title">
                                <?= $a['exceeded']
                                    ? htmlspecialchars($a['category']) . ' — Over budget!'
                                    : htmlspecialchars($a['category']) . ' at ' . $a['pct'] . '%' ?>
                            </div>
                            <div class="notif-sub">
                                KES <?= number_format($a['spent'], 0) ?> of
                                KES <?= number_format($a['limit'], 0) ?> spent
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            <a class="notif-footer" href="budget.php">View Budget →</a>
        <?php endif; ?>
    </div>
</div>

<style>
.notif-wrap { position: relative; }
.notif-bell {
    position: relative; background: none;
    border: 1px solid var(--line); border-radius: 8px;
    padding: 7px 9px; cursor: pointer;
    color: var(--ink-2); display: flex; align-items: center;
    transition: background .15s, color .15s;
}
.notif-bell:hover { background: var(--line-2); color: var(--ink); }
.notif-badge {
    position: absolute; top: -5px; right: -5px;
    background: #ef4444; color: #fff;
    font-size: 10px; font-weight: 700;
    min-width: 17px; height: 17px; border-radius: 99px;
    display: flex; align-items: center; justify-content: center;
    padding: 0 4px; border: 2px solid var(--surface);
}
.notif-dropdown {
    display: none; position: absolute;
    top: calc(100% + 8px); right: 0;
    width: 300px; background: var(--surface);
    border: 1px solid var(--line); border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,.12); z-index: 200; overflow: hidden;
}
.notif-dropdown.open { display: block; }
.notif-header {
    padding: 12px 16px; font-size: 12.5px; font-weight: 700;
    color: var(--ink); border-bottom: 1px solid var(--line);
    display: flex; justify-content: space-between; align-items: center;
}
.notif-count-label { color: #ef4444; font-size: 11px; }
.notif-empty { padding: 28px 16px; text-align: center; color: var(--ink-3); font-size: 13px; display: flex; flex-direction: column; align-items: center; gap: 8px; }
.notif-list { max-height: 280px; overflow-y: auto; }
.notif-item { display: flex; align-items: flex-start; gap: 10px; padding: 12px 16px; border-bottom: 1px solid var(--line); text-decoration: none; color: inherit; transition: background .12s; }
.notif-item:hover { background: var(--line-2); }
.notif-item:last-child { border-bottom: none; }
.notif-exceeded { border-left: 3px solid #ef4444; }
.notif-warning  { border-left: 3px solid #f59e0b; }
.notif-icon  { font-size: 18px; line-height: 1; margin-top: 1px; }
.notif-body  { flex: 1; min-width: 0; }
.notif-title { font-size: 13px; font-weight: 600; color: var(--ink); }
.notif-sub   { font-size: 11.5px; color: var(--ink-3); margin-top: 2px; font-family: 'DM Mono', monospace; }
.notif-footer { display: block; padding: 10px 16px; text-align: center; font-size: 12px; font-weight: 600; color: var(--accent); border-top: 1px solid var(--line); text-decoration: none; background: var(--line-2); }
.notif-footer:hover { background: var(--line); }
</style>

<script>
function toggleNotif() {
    document.getElementById('notifDropdown').classList.toggle('open');
}
document.addEventListener('click', function(e) {
    const wrap = document.getElementById('notifWrap');
    if (wrap && !wrap.contains(e.target)) {
        document.getElementById('notifDropdown')?.classList.remove('open');
    }
});
</script>