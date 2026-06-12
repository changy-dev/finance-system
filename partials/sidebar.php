<?php
// ============================================================
// partials/sidebar.php
// Include immediately after header.php on every page.
// Requires: $user_name (set by header.php)
// ============================================================
$current_page = basename($_SERVER['PHP_SELF']);
$collapsed    = isset($_COOKIE['sidebar_collapsed']) && $_COOKIE['sidebar_collapsed'] === '1';
?>
<aside class="sidebar <?= $collapsed ? 'collapsed' : '' ?>" id="sidebar">

    <div class="sidebar-brand">
        <div class="brand-mark">
            <div class="brand-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2"
                          d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div class="brand-text">
                <div class="brand-name">Fintrack</div>
                <div class="brand-tagline">Personal Finance</div>
            </div>
        </div>
        <button class="sidebar-toggle" id="sidebarToggle" title="Collapse sidebar">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="15" height="15">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>
            </svg>
        </button>
    </div>

    <nav class="sidebar-nav">

        <div class="nav-section-label">Overview</div>

        <a href="dashboard.php" class="nav-item <?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M4 5a1 1 0 011-1h4a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10-3a1 1 0 011-1h4a1 1 0 011 1v7a1 1 0 01-1 1h-4a1 1 0 01-1-1v-7z"/>
            </svg>
            <span class="nav-label">Dashboard</span>
            <span class="nav-badge">Live</span>
        </a>

        <div class="nav-section-label">Money</div>

        <a href="transactions.php" class="nav-item <?= $current_page === 'transactions.php' ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
            </svg>
            <span class="nav-label">Transactions</span>
        </a>

        <a href="budget.php" class="nav-item <?= $current_page === 'budget.php' ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
            </svg>
            <span class="nav-label">Budget</span>
        </a>

        <div class="nav-section-label">Account</div>

        <a href="settings.php" class="nav-item <?= $current_page === 'settings.php' ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            <span class="nav-label">Settings</span>
        </a>

        <a href="logout.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
            <span class="nav-label">Logout</span>
        </a>

    </nav>

    <div class="sidebar-footer">
        <div class="user-row">
            <div class="user-avatar"><?= strtoupper(substr($user_name, 0, 1)) ?></div>
            <div class="nav-label">
                <div class="user-name"><?= htmlspecialchars($user_name) ?></div>

            </div>
        </div>
    </div>

</aside>

<style>
/* ── Sidebar styles (defined here, not in header, to keep them co-located) ── */
.sidebar {
    width: var(--sidebar-w);
    flex-shrink: 0;
    background: linear-gradient(180deg,#1E1B4B 0%,#16134A 100%);
    position: fixed;
    top: 0; left: 0;
    height: 100vh;
    display: flex;
    flex-direction: column;
    z-index: 50;
    overflow: hidden;
    transition: width .25s ease, transform .25s ease;
}
.sidebar.collapsed { width: var(--sidebar-collapsed); }

.sidebar-brand {
    padding: 20px 14px 18px;
    border-bottom: 1px solid rgba(255,255,255,.08);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}
.brand-mark  { display: flex; align-items: center; gap: 10px; overflow: hidden; }
.brand-icon  { width: 34px; height: 34px; background: linear-gradient(135deg,#FF6B35,#E8356D); border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 4px 14px rgba(255,107,53,.4); }
.brand-icon svg { width: 18px; height: 18px; color: #fff; }
.brand-text  { transition: opacity .2s, width .2s; overflow: hidden; }
.brand-name  { font-size: 15px; font-weight: 700; color: #fff; letter-spacing: -.2px; white-space: nowrap; }
.brand-tagline { font-size: 11px; color: rgba(255,255,255,.4); margin-top: 1px; white-space: nowrap; }
.sidebar.collapsed .brand-text { opacity: 0; width: 0; pointer-events: none; }

.sidebar-toggle {
    width: 26px; height: 26px; border-radius: 6px;
    background: rgba(255,255,255,.07); border: none;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    color: rgba(255,255,255,.4); flex-shrink: 0; transition: background .15s, transform .25s;
}
.sidebar-toggle:hover { background: rgba(255,255,255,.16); color: #fff; }
.sidebar.collapsed .sidebar-toggle { transform: rotate(180deg); margin: 0 auto; }

.sidebar-nav { padding: 12px 8px; flex: 1; overflow-y: auto; overflow-x: hidden; }

.nav-section-label {
    font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px;
    color: rgba(255,255,255,.25); padding: 0 8px; margin: 8px 0 4px;
    white-space: nowrap; overflow: hidden;
    transition: opacity .2s;
}
.sidebar.collapsed .nav-section-label { opacity: 0; height: 0; margin: 0; padding: 0; }

.nav-item {
    display: flex; align-items: center; gap: 10px; padding: 9px 10px;
    border-radius: 7px; color: rgba(255,255,255,.5); text-decoration: none;
    font-size: 13.5px; font-weight: 400; margin-bottom: 2px;
    white-space: nowrap; overflow: hidden; transition: background .15s, color .15s;
}
.nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }
.nav-item:hover  { background: rgba(255,255,255,.08); color: rgba(255,255,255,.9); }
.nav-item.active { background: linear-gradient(90deg,rgba(255,107,53,.25),rgba(232,53,109,.15)); color: #FFB39A; font-weight: 600; border-left: 2px solid #FF6B35; }
.nav-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; transition: opacity .2s, width .2s; }
.nav-badge { margin-left: auto; background: rgba(255,107,53,.2); color: #FFB39A; font-size: 10px; font-weight: 600; padding: 1px 6px; border-radius: 10px; font-family: var(--font-mono,monospace); transition: opacity .2s; }
.sidebar.collapsed .nav-label,
.sidebar.collapsed .nav-badge { opacity: 0; width: 0; pointer-events: none; }
.sidebar.collapsed .nav-item  { justify-content: center; padding: 10px; }

.sidebar-footer { padding: 10px 8px 14px; border-top: 1px solid rgba(255,255,255,.08); flex-shrink: 0; }
.user-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 7px; transition: background .15s; }
.user-row:hover { background: rgba(255,255,255,.06); }
.user-avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; color: #fff; flex-shrink: 0; }
.user-name { font-size: 12.5px; color: rgba(255,255,255,.7); font-weight: 500; white-space: nowrap; }
.user-role { font-size: 11px; color: rgba(255,255,255,.3); white-space: nowrap; }
.sidebar.collapsed .user-row { justify-content: center; padding: 8px; }

@media (max-width: 900px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .sidebar-overlay.open { display: block; }
}

/* ── Gold theme additions (appended, nothing changed above) ── */
.nav-item.active {
    background: linear-gradient(90deg,rgba(255,107,53,.25),rgba(232,53,109,.15)) !important;
    color: #FFB39A !important;
}
.nav-badge { color: #FFB39A !important; background: rgba(255,107,53,.2) !important; }
.brand-icon { background: linear-gradient(135deg,#FF6B35,#E8356D) !important; box-shadow: 0 4px 14px rgba(255,107,53,.4); }
.user-avatar { background: linear-gradient(135deg,#FF6B35,#E8356D) !important; }
</style>

<script>
// Collapse/expand toggle (desktop)
document.getElementById('sidebarToggle').addEventListener('click', () => {
    const sidebar = document.getElementById('sidebar');
    const layout  = document.getElementById('layout');
    sidebar.classList.toggle('collapsed');
    layout.classList.toggle('sidebar-collapsed');
    document.cookie = 'sidebar_collapsed=' + (sidebar.classList.contains('collapsed') ? 1 : 0) + ';path=/;SameSite=Lax';
});

// Mobile open/close
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('open');
}
</script>