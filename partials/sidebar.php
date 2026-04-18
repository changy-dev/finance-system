<?php $page = basename($_SERVER['PHP_SELF']); ?>
<div class="sidebar">
    <h2>Finance</h2>

    <a class="<?= $page=='dashboard.php'?'active':'' ?>" href="dashboard.php">📊 Dashboard</a>
    <a class="<?= $page=='transactions.php'?'active':'' ?>" href="transactions.php">💸 Transactions</a>
    <a class="<?= $page=='settings.php'?'active':'' ?>" href="settings.php">⚙️ Settings</a>
</div>