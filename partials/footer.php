<?php
// ============================================================
// partials/footer.php
// Include at the BOTTOM of every authenticated page.
// Closes the .main and .layout divs opened by header.php.
// ============================================================
?>

</div><!-- /.main -->
</div><!-- /.layout -->

<!-- Global toast element (one per page, used by showToast()) -->
<div class="toast" id="globalToast"></div>

<script>
// ── Global toast ──────────────────────────────────────────
function showToast(msg, type = 'success') {
    const t = document.getElementById('globalToast');
    if (!t) return;
    t.textContent = msg;
    t.className = 'toast show ' + type;
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.classList.remove('show'), 3200);
}

// ── Global confirm helper ─────────────────────────────────
function confirmAction(msg) {
    return confirm(msg);
}

// ── Close any open modal on Escape ────────────────────────
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open')
            .forEach(m => m.classList.remove('open'));
    }
});
</script>

</body>
</html>