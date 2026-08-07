<?php
session_start();
$is_logged_in = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fintrack — Personal Finance Management & Wealth Tracker</title>
<meta name="description" content="Take total control of your money with Fintrack. Track transactions, automate budget limits, monitor savings goals, and visualize analytics in real-time.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    --page: #080b12;
    --surface: #0e1422;
    --surface-2: #151c2e;
    --surface-3: #1c2540;
    --line: rgba(255,255,255,.08);
    --line-2: rgba(255,255,255,.04);
    --cyan: #00d4ff;
    --cyan-dim: rgba(0,212,255,.12);
    --cyan-glow: 0 0 28px rgba(0,212,255,.35);
    --coral: #ff5e62;
    --coral-dim: rgba(255,94,98,.12);
    --violet: #7c3aed;
    --green: #10f5a0;
    --green-dim: rgba(16,245,160,.12);
    --font: 'Plus Jakarta Sans', sans-serif;
    --font-head: 'Space Grotesk', sans-serif;
    --font-mono: 'Space Mono', monospace;
    --text-1: rgba(255,255,255,.95);
    --text-2: rgba(255,255,255,.6);
    --text-3: rgba(255,255,255,.35);
    --radius-lg: 16px;
    --radius-xl: 24px;
    --shadow-lg: 0 20px 60px rgba(0,0,0,.6);
}

body {
    font-family: var(--font);
    background: var(--page);
    color: var(--text-1);
    font-size: 14.5px;
    line-height: 1.6;
    overflow-x: hidden;
    -webkit-font-smoothing: antialiased;
}

body::before {
    content: '';
    position: fixed;
    inset: 0;
    background: 
        radial-gradient(ellipse 80% 50% at 20% -10%, rgba(0,212,255,.12) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 85% 90%, rgba(124,58,237,.14) 0%, transparent 55%),
        radial-gradient(ellipse 50% 50% at 50% 40%, rgba(255,94,98,.06) 0%, transparent 70%);
    pointer-events: none;
    z-index: 0;
}

body > * { position: relative; z-index: 1; }

/* ── NAVBAR ── */
.navbar {
    height: 72px;
    padding: 0 40px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid var(--line);
    background: rgba(14,20,34,.7);
    backdrop-filter: blur(16px);
    position: sticky;
    top: 0;
    z-index: 100;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
}

.brand-icon {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--cyan), var(--violet));
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: var(--cyan-glow);
}

.brand-icon svg { width: 22px; height: 22px; color: #fff; }

.brand-title {
    font-family: var(--font-head);
    font-size: 21px;
    font-weight: 700;
    color: #fff;
    letter-spacing: -.4px;
}

.nav-links {
    display: flex;
    align-items: center;
    gap: 32px;
    list-style: none;
}

.nav-links a {
    color: var(--text-2);
    text-decoration: none;
    font-weight: 500;
    font-size: 13.5px;
    transition: color .2s;
}

.nav-links a:hover { color: #fff; }

.nav-actions { display: flex; align-items: center; gap: 12px; }

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 12px;
    font-size: 13.5px;
    font-weight: 600;
    font-family: var(--font);
    cursor: pointer;
    text-decoration: none;
    transition: all .2s cubic-bezier(.4,0,.2,1);
    white-space: nowrap;
}

.btn-ghost {
    background: transparent;
    color: var(--text-2);
    border: 1px solid var(--line);
}
.btn-ghost:hover {
    color: #fff;
    background: var(--surface-2);
    border-color: rgba(255,255,255,.2);
}

.btn-primary {
    background: linear-gradient(135deg, var(--cyan), #0099cc);
    color: #080b12;
    box-shadow: var(--cyan-glow);
    border: none;
}
.btn-primary:hover {
    filter: brightness(1.1);
    transform: translateY(-2px);
    box-shadow: 0 8px 30px rgba(0,212,255,.45);
}

.btn-lg { padding: 14px 28px; font-size: 15px; border-radius: 14px; }

/* ── HERO SECTION ── */
.hero {
    padding: 90px 40px 60px;
    max-width: 1200px;
    margin: 0 auto;
    text-align: center;
}

.hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 16px;
    border-radius: 999px;
    background: rgba(0,212,255,.08);
    border: 1px solid rgba(0,212,255,.25);
    color: var(--cyan);
    font-size: 12.5px;
    font-weight: 600;
    margin-bottom: 24px;
}

.hero-title {
    font-family: var(--font-head);
    font-size: 56px;
    font-weight: 800;
    line-height: 1.15;
    letter-spacing: -1.5px;
    max-width: 900px;
    margin: 0 auto 20px;
    background: linear-gradient(180deg, #ffffff 40%, rgba(255,255,255,.7));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.hero-title span {
    background: linear-gradient(135deg, var(--cyan), var(--violet));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.hero-sub {
    font-size: 18px;
    color: var(--text-2);
    max-width: 680px;
    margin: 0 auto 36px;
    font-weight: 400;
}

.hero-buttons {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    margin-bottom: 60px;
}

/* ── DEMO DASHBOARD GRAPHIC ── */
.dashboard-preview {
    background: var(--surface);
    border: 1px solid rgba(255,255,255,.12);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-lg);
    padding: 28px;
    max-width: 1040px;
    margin: 0 auto;
    text-align: left;
    position: relative;
    overflow: hidden;
}

.preview-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 20px;
}

.preview-card {
    background: var(--surface-2);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    padding: 20px;
}

.preview-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}

.preview-title { font-size: 13px; font-weight: 700; color: var(--text-2); text-transform: uppercase; letter-spacing: .5px; }

.stat-val { font-family: var(--font-mono); font-size: 28px; font-weight: 700; color: #fff; margin-bottom: 4px; }
.stat-tag { font-size: 12px; color: var(--green); font-weight: 600; }

.chart-box { height: 180px; width: 100%; position: relative; }

.tx-list { display: flex; flex-direction: column; gap: 12px; }

.tx-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 12px;
    background: var(--surface-3);
    border-radius: 10px;
}

.tx-left { display: flex; align-items: center; gap: 10px; }
.tx-icon { width: 32px; height: 32px; border-radius: 8px; background: rgba(0,212,255,.12); color: var(--cyan); display: flex; align-items: center; justify-content: center; font-size: 14px; }
.tx-name { font-size: 13px; font-weight: 600; color: #fff; }
.tx-date { font-size: 11px; color: var(--text-3); }
.tx-amt { font-family: var(--font-mono); font-weight: 700; font-size: 13px; }
.tx-amt.income { color: var(--green); }
.tx-amt.expense { color: var(--coral); }

/* ── FEATURES GRID ── */
.features-section {
    padding: 90px 40px;
    max-width: 1200px;
    margin: 0 auto;
}

.section-hd {
    text-align: center;
    margin-bottom: 56px;
}

.section-badge {
    font-size: 12px;
    font-weight: 700;
    color: var(--cyan);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 8px;
}

.section-title {
    font-family: var(--font-head);
    font-size: 36px;
    font-weight: 700;
    color: #fff;
    letter-spacing: -.6px;
}

.features-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
}

.feature-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-xl);
    padding: 28px 24px;
    transition: transform .25s, border-color .25s, box-shadow .25s;
}

.feature-card:hover {
    transform: translateY(-4px);
    border-color: rgba(0,212,255,.3);
    box-shadow: 0 12px 32px rgba(0,0,0,.4);
}

.feature-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    background: var(--surface-2);
    border: 1px solid var(--line);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 20px;
    color: var(--cyan);
}

.feature-icon svg { width: 24px; height: 24px; }

.feature-h { font-size: 17px; font-weight: 700; color: #fff; margin-bottom: 8px; }
.feature-p { font-size: 13.5px; color: var(--text-2); line-height: 1.5; }

/* ── CALCULATOR / PROJECTION SECTION ── */
.calc-section {
    padding: 60px 40px 90px;
    max-width: 1040px;
    margin: 0 auto;
}

.calc-card {
    background: linear-gradient(145deg, var(--surface-2), var(--surface));
    border: 1px solid rgba(255,255,255,.12);
    border-radius: var(--radius-xl);
    padding: 40px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 40px;
    align-items: center;
}

.calc-title { font-family: var(--font-head); font-size: 28px; font-weight: 700; color: #fff; margin-bottom: 10px; }
.calc-desc { font-size: 14px; color: var(--text-2); margin-bottom: 24px; }

.calc-input-group { margin-bottom: 20px; }
.calc-label { display: block; font-size: 12px; font-weight: 700; color: var(--text-2); text-transform: uppercase; margin-bottom: 8px; }
.calc-range { width: 100%; accent-color: var(--cyan); }
.calc-val-display { font-family: var(--font-mono); font-size: 18px; font-weight: 700; color: var(--cyan); margin-top: 4px; }

.calc-result-box {
    background: var(--surface-3);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    padding: 28px;
    text-align: center;
}

.calc-result-lbl { font-size: 12px; font-weight: 700; color: var(--text-3); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; }
.calc-result-val { font-family: var(--font-mono); font-size: 38px; font-weight: 800; color: var(--green); letter-spacing: -1px; margin-bottom: 12px; }

/* ── CTA BANNER ── */
.cta-banner {
    background: linear-gradient(135deg, rgba(0,212,255,.15), rgba(124,58,237,.25));
    border: 1px solid rgba(0,212,255,.3);
    border-radius: var(--radius-xl);
    padding: 60px 40px;
    max-width: 1040px;
    margin: 0 auto 90px;
    text-align: center;
    box-shadow: 0 20px 60px rgba(0,0,0,.5);
}

.cta-h { font-family: var(--font-head); font-size: 34px; font-weight: 800; color: #fff; margin-bottom: 12px; }
.cta-p { font-size: 16px; color: var(--text-2); max-width: 540px; margin: 0 auto 28px; }

/* ── FOOTER ── */
.footer {
    border-top: 1px solid var(--line);
    padding: 40px 40px;
    text-align: center;
    color: var(--text-3);
    font-size: 13px;
}

@media (max-width: 900px) {
    .navbar { padding: 0 20px; }
    .hero { padding: 50px 20px; }
    .hero-title { font-size: 38px; }
    .features-grid { grid-template-columns: repeat(2, 1fr); }
    .preview-grid { grid-template-columns: 1fr; }
    .calc-card { grid-template-columns: 1fr; gap: 20px; padding: 24px; }
}

@media (max-width: 600px) {
    .features-grid { grid-template-columns: 1fr; }
    .hero-buttons { flex-direction: column; }
    .btn { width: 100%; justify-content: center; }
}
</style>
</head>
<body>

<!-- NAVBAR -->
<header class="navbar">
    <a href="index.php" class="brand">
        <div class="brand-icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <span class="brand-title">Fintrack</span>
    </a>

    <nav class="nav-links">
        <a href="#features">Features</a>
        <a href="#calculator">Projection</a>
        <a href="#security">Security</a>
    </nav>

    <div class="nav-actions">
        <?php if ($is_logged_in): ?>
            <a href="pages/dashboard.php" class="btn btn-primary">Go to Dashboard →</a>
        <?php else: ?>
            <a href="auth/login.php" class="btn btn-ghost">Sign In</a>
            <a href="auth/register.php" class="btn btn-primary">Get Started Free</a>
        <?php endif; ?>
    </div>
</header>

<!-- HERO -->
<section class="hero">
    <div class="hero-badge">✨ Next-Gen Personal Finance Engine</div>
    <h1 class="hero-title">Master Your Money with <span>Precision & Intelligence.</span></h1>
    <p class="hero-sub">Track daily expenses, set budget limits, automate savings goals, and visualize net worth progress in one high-performance dashboard.</p>
    
    <div class="hero-buttons">
        <?php if ($is_logged_in): ?>
            <a href="pages/dashboard.php" class="btn btn-primary btn-lg">Open Your Dashboard →</a>
        <?php else: ?>
            <a href="auth/register.php" class="btn btn-primary btn-lg">Create Free Account →</a>
            <a href="auth/login.php" class="btn btn-ghost btn-lg">Sign In to Account</a>
        <?php endif; ?>
    </div>

    <!-- DASHBOARD PREVIEW DEMO -->
    <div class="dashboard-preview">
        <div class="preview-grid">
            <div class="preview-card">
                <div class="preview-header">
                    <div>
                        <div class="preview-title">Total Net Worth Overview</div>
                        <div class="stat-val">$4,556,000.00</div>
                    </div>
                    <div class="stat-tag">+12.4% this month</div>
                </div>
                <div class="chart-box">
                    <canvas id="previewChart"></canvas>
                </div>
            </div>
            
            <div class="preview-card" style="display:flex;flex-direction:column;justify-content:space-between;">
                <div>
                    <div class="preview-title" style="margin-bottom:14px;">Recent Activity</div>
                    <div class="tx-list">
                        <div class="tx-item">
                            <div class="tx-left">
                                <div class="tx-icon">💼</div>
                                <div>
                                    <div class="tx-name">Web Consulting</div>
                                    <div class="tx-date">Today</div>
                                </div>
                            </div>
                            <div class="tx-amt income">+$15,000</div>
                        </div>
                        <div class="tx-item">
                            <div class="tx-left">
                                <div class="tx-icon" style="background:rgba(255,94,98,.12);color:var(--coral);">☕</div>
                                <div>
                                    <div class="tx-name">Dining Out</div>
                                    <div class="tx-date">Yesterday</div>
                                </div>
                            </div>
                            <div class="tx-amt expense">-$350</div>
                        </div>
                        <div class="tx-item">
                            <div class="tx-left">
                                <div class="tx-icon" style="background:rgba(124,58,237,.12);color:var(--violet);">🏠</div>
                                <div>
                                    <div class="tx-name">Savings Goal</div>
                                    <div class="tx-date">3 days ago</div>
                                </div>
                            </div>
                            <div class="tx-amt income">+$1,000</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FEATURES -->
<section class="features-section" id="features">
    <div class="section-hd">
        <div class="section-badge">Designed for Financial Success</div>
        <h2 class="section-title">Everything you need to build wealth</h2>
    </div>

    <div class="features-grid">
        <div class="feature-card">
            <div class="feature-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0h6m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2zM9 19H5a2 2 0 01-2-2v-3a2 2 0 012-2h2"/></svg>
            </div>
            <div class="feature-h">Real-Time Analytics</div>
            <div class="feature-p">Gain instant clarity on cashflow, spending breakdown, and recurring monthly commitments.</div>
        </div>

        <div class="feature-card">
            <div class="feature-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
            </div>
            <div class="feature-h">Smart Budget Caps</div>
            <div class="feature-p">Define category limits with visual progress tracking and automated spending alerts.</div>
        </div>

        <div class="feature-card">
            <div class="feature-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9c0 4-4 4-4 4H7v5H3V9a6 6 0 0 1 12 0h2z"/><circle cx="21" cy="14" r="3"/></svg>
            </div>
            <div class="feature-h">Savings Milestones</div>
            <div class="feature-p">Set custom targets for home decor, emergency funds, and vacation plans with easy deposits.</div>
        </div>

        <div class="feature-card" id="security">
            <div class="feature-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            </div>
            <div class="feature-h">Bank-Grade Protection</div>
            <div class="feature-p">Bcrypt password hashing, brute-force lockout, CSRF defense, and session security built-in.</div>
        </div>
    </div>
</section>

<!-- CALCULATOR SECTION -->
<section class="calc-section" id="calculator">
    <div class="calc-card">
        <div>
            <div class="section-badge">Wealth Growth Simulator</div>
            <h2 class="calc-title">Simulate your future savings</h2>
            <p class="calc-desc">Adjust monthly contributions to see how fast your wealth accumulates over time.</p>

            <div class="calc-input-group">
                <label class="calc-label">Monthly Deposit ($)</label>
                <input type="range" class="calc-range" id="monthlyInput" min="100" max="5000" step="100" value="500" oninput="calculateSavings()">
                <div class="calc-val-display" id="monthlyVal">$500 / mo</div>
            </div>

            <div class="calc-input-group">
                <label class="calc-label">Time Horizon (Years)</label>
                <input type="range" class="calc-range" id="yearsInput" min="1" max="10" step="1" value="3" oninput="calculateSavings()">
                <div class="calc-val-display" id="yearsVal">3 Years</div>
            </div>
        </div>

        <div class="calc-result-box">
            <div class="calc-result-lbl">Projected Savings Balance</div>
            <div class="calc-result-val" id="totalResult">$19,440.00</div>
            <p style="font-size:12px;color:var(--text-3);">Based on steady savings + estimated 7% compound growth.</p>
        </div>
    </div>
</section>

<!-- CTA BANNER -->
<section class="cta-banner">
    <h2 class="cta-h">Ready to transform your finances?</h2>
    <p class="cta-p">Join Fintrack today and experience complete financial clarity and control.</p>
    <div style="display:flex;justify-content:center;gap:16px;">
        <?php if ($is_logged_in): ?>
            <a href="pages/dashboard.php" class="btn btn-primary btn-lg">Go to Dashboard →</a>
        <?php else: ?>
            <a href="auth/register.php" class="btn btn-primary btn-lg">Create Free Account →</a>
            <a href="auth/login.php" class="btn btn-ghost btn-lg">Sign In</a>
        <?php endif; ?>
    </div>
</section>

<!-- FOOTER -->
<footer class="footer">
    <p>© <?php echo date('Y'); ?> Fintrack Personal Finance. All rights reserved.</p>
</footer>

<script>
// Chart Preview
const ctx = document.getElementById('previewChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
        datasets: [{
            label: 'Net Worth ($)',
            data: [3200000, 3500000, 3900000, 4056000, 4300000, 4556000],
            borderColor: '#00d4ff',
            borderWidth: 3,
            backgroundColor: 'rgba(0, 212, 255, 0.1)',
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            pointBackgroundColor: '#00d4ff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: 'rgba(255,255,255,0.4)' } },
            y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: 'rgba(255,255,255,0.4)', callback: v => '$' + (v/1000000).toFixed(1) + 'M' } }
        }
    }
});

// Interactive Calculator
function calculateSavings() {
    const monthly = parseFloat(document.getElementById('monthlyInput').value);
    const years = parseInt(document.getElementById('yearsInput').value);
    document.getElementById('monthlyVal').textContent = '$' + monthly.toLocaleString() + ' / mo';
    document.getElementById('yearsVal').textContent = years + (years === 1 ? ' Year' : ' Years');

    const months = years * 12;
    const rate = 0.07 / 12;
    let total = 0;
    for (let i = 0; i < months; i++) {
        total = (total + monthly) * (1 + rate);
    }

    document.getElementById('totalResult').textContent = '$' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>
</body>
</html>