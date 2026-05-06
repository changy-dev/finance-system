<?php
session_start();
require_once 'db.php';
require_once 'security.php';
require_once 'mailer.php';


$user_stmt = $conn->prepare("
    SELECT u.full_name, u.email,
           p.name AS plan_name, p.plan_id,
           p.has_exports, p.has_alerts, p.has_ai,
           s.status AS sub_status, s.expires_at
    FROM users u
    LEFT JOIN subscriptions s ON s.user_id = u.user_id
    LEFT JOIN plans p ON p.plan_id = s.plan_id
    WHERE u.user_id = ?
");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();


$plans_stmt = $conn->query("SELECT * FROM plans WHERE is_active=1 ORDER BY price_kes ASC");
$plans = $plans_stmt->fetch_all(MYSQLI_ASSOC);


$paystack_key = $_ENV['PAYSTACK_PUBLIC_KEY'] ?? 'pk_test_your_paystack_key_here';
$stripe_key   = $_ENV['STRIPE_PUBLIC_KEY']   ?? 'pk_test_your_stripe_key_here';

$current_plan = $user['plan_name'] ?? 'Free';
$flash = '';
if (!empty($_SESSION['plan_flash'])) {
    $flash = $_SESSION['plan_flash'];
    unset($_SESSION['plan_flash']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upgrade Plan | Royal Matrix</title>
    <script src="https://js.paystack.co/v1/inline.js"></script>
    <script src="https://js.stripe.com/v3/"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: #f4f6f9; color: #1a1a2e; padding: 2rem 1rem; }
        .container { max-width: 900px; margin: 0 auto; }
        h2 { font-size: 1.5rem; margin-bottom: .4rem; }
        .subtitle { color: #64748b; margin-bottom: 2rem; font-size: .95rem; }

        .alert { padding: .75rem 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: .9rem; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

        /* Current plan banner */
        .current-banner {
            background: linear-gradient(135deg, #7c3aed, #06b6d4);
            color: #fff;
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .current-banner .plan-name { font-size: 1.1rem; font-weight: 700; }
        .current-banner .plan-label { font-size: .8rem; opacity: .8; margin-bottom: .25rem; }
        .current-banner .status-pill {
            background: rgba(255,255,255,.2);
            border-radius: 99px;
            padding: .3rem .9rem;
            font-size: .8rem;
            font-weight: 600;
        }

        /* Plans grid */
        .plans-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1.25rem; margin-bottom: 2rem; }

        .plan-card {
            background: #fff;
            border: 1.5px solid #e2e8f0;
            border-radius: 16px;
            padding: 1.75rem;
            position: relative;
            transition: box-shadow .2s, border-color .2s;
        }
        .plan-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,.08); }
        .plan-card.current { border-color: #7c3aed; }
        .plan-card.popular { border-color: #06b6d4; }

        .popular-badge {
            position: absolute;
            top: -12px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, #7c3aed, #06b6d4);
            color: #fff;
            font-size: .72rem;
            font-weight: 700;
            padding: .25rem .9rem;
            border-radius: 99px;
            white-space: nowrap;
        }
        .current-badge {
            position: absolute;
            top: -12px;
            left: 50%;
            transform: translateX(-50%);
            background: #7c3aed;
            color: #fff;
            font-size: .72rem;
            font-weight: 700;
            padding: .25rem .9rem;
            border-radius: 99px;
            white-space: nowrap;
        }

        .plan-name { font-size: 1.1rem; font-weight: 700; margin-bottom: .5rem; }
        .plan-price { font-size: 2rem; font-weight: 800; color: #1a1a2e; margin-bottom: .2rem; }
        .plan-price span { font-size: .9rem; font-weight: 400; color: #64748b; }
        .plan-price-usd { font-size: .8rem; color: #94a3b8; margin-bottom: 1.25rem; }
        .plan-divider { border: none; border-top: 1px solid #f1f5f9; margin: 1rem 0; }

        .plan-features { list-style: none; margin-bottom: 1.5rem; }
        .plan-features li {
            font-size: .875rem;
            color: #475569;
            padding: .35rem 0;
            display: flex;
            gap: .5rem;
            align-items: flex-start;
        }
        .plan-features li::before { content: "✓"; color: #10b981; font-weight: 700; flex-shrink: 0; }
        .plan-features li.no::before { content: "✗"; color: #cbd5e1; }
        .plan-features li.no { color: #94a3b8; }

        .btn { width: 100%; padding: .75rem; border: none; border-radius: 10px; font-weight: 700; font-size: .9rem; cursor: pointer; transition: opacity .15s, transform .1s; }
        .btn:hover { opacity: .9; transform: translateY(-1px); }
        .btn:active { transform: scale(.98); }
        .btn-primary { background: linear-gradient(135deg, #7c3aed, #06b6d4); color: #fff; }
        .btn-disabled { background: #e2e8f0; color: #94a3b8; cursor: default; }
        .btn-disabled:hover { opacity: 1; transform: none; }

        /* Payment method selector */
        .payment-modal-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,.5);
            z-index: 100;
            align-items: center;
            justify-content: center;
        }
        .payment-modal-overlay.open { display: flex; }
        .payment-modal {
            background: #fff;
            border-radius: 16px;
            padding: 2rem;
            width: min(420px, 95vw);
            box-shadow: 0 20px 60px rgba(0,0,0,.2);
            position: relative;
        }
        .payment-modal h3 { font-size: 1.1rem; margin-bottom: .4rem; }
        .payment-modal p  { font-size: .875rem; color: #64748b; margin-bottom: 1.5rem; }
        .modal-close { position: absolute; top: 1rem; right: 1rem; background: none; border: none; font-size: 1.4rem; cursor: pointer; color: #94a3b8; }

        .pay-option {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            cursor: pointer;
            margin-bottom: .75rem;
            transition: border-color .15s, background .15s;
        }
        .pay-option:hover { border-color: #7c3aed; background: #faf5ff; }
        .pay-option .icon { font-size: 1.5rem; }
        .pay-option .info { flex: 1; }
        .pay-option .info strong { display: block; font-size: .9rem; }
        .pay-option .info small { font-size: .8rem; color: #64748b; }
        .pay-option .arrow { color: #94a3b8; }

        /* FAQ */
        .faq { background: #fff; border-radius: 12px; padding: 1.5rem; border: 1px solid #e2e8f0; }
        .faq h3 { font-size: 1rem; margin-bottom: 1rem; }
        .faq-item { border-bottom: 1px solid #f1f5f9; padding: .75rem 0; }
        .faq-item:last-child { border-bottom: none; }
        .faq-q { font-size: .875rem; font-weight: 600; cursor: pointer; display: flex; justify-content: space-between; }
        .faq-a { font-size: .85rem; color: #64748b; margin-top: .5rem; display: none; line-height: 1.6; }
        .faq-item.open .faq-a { display: block; }
        .faq-item.open .faq-q::after { content: "−"; }
        .faq-q::after { content: "+"; font-size: 1.1rem; color: #94a3b8; }
    </style>
</head>
<body>
<div class="container">
    <h2>Choose Your Plan</h2>
    <p class="subtitle">Start free, upgrade when you're ready.</p>

    <?php if ($flash): ?>
        <div class="alert alert-<?= strpos($flash,'success') !== false ? 'success' : 'error' ?>">
            <?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    
    <div class="current-banner">
        <div>
            <div class="plan-label">YOUR CURRENT PLAN</div>
            <div class="plan-name"><?= htmlspecialchars($current_plan, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <span class="status-pill">✓ Active</span>
    </div>

    
    <div class="plans-grid">
        <?php foreach ($plans as $plan):
            $is_current = strtolower($plan['name']) === strtolower($current_plan);
            $is_popular = $plan['name'] === 'Pro';
        ?>
        <div class="plan-card <?= $is_current ? 'current' : ($is_popular ? 'popular' : '') ?>">
            <?php if ($is_current): ?>
                <div class="current-badge">Current Plan</div>
            <?php elseif ($is_popular): ?>
                <div class="popular-badge">Most Popular</div>
            <?php endif; ?>

            <div class="plan-name"><?= htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="plan-price">
                KES <?= number_format((float)$plan['price_kes'], 0) ?>
                <span>/mo</span>
            </div>
            <div class="plan-price-usd">
                <?= $plan['price_usd'] > 0 ? '≈ $' . number_format((float)$plan['price_usd'], 2) . ' USD' : 'Always free' ?>
            </div>

            <hr class="plan-divider">

            <ul class="plan-features">
                <li><?= $plan['max_transactions'] ? number_format($plan['max_transactions']) . ' transactions/mo' : 'Unlimited transactions' ?></li>
                <li><?= $plan['max_budgets'] ? $plan['max_budgets'] . ' budget categories' : 'Unlimited budgets' ?></li>
                <li><?= $plan['max_goals'] ? $plan['max_goals'] . ' savings goals' : 'Unlimited savings goals' ?></li>
                <li class="<?= $plan['has_exports'] ? '' : 'no' ?>">CSV &amp; PDF exports</li>
                <li class="<?= $plan['has_alerts'] ? '' : 'no' ?>">Budget alerts via email</li>
                <li class="<?= $plan['has_ai'] ? '' : 'no' ?>">AI spending insights</li>
                <?php if ($plan['name'] === 'Business'): ?>
                    <li>Priority support</li>
                    <li>Team accounts (coming soon)</li>
                <?php endif; ?>
            </ul>

            <?php if ($is_current): ?>
                <button class="btn btn-disabled" disabled>Current Plan</button>
            <?php elseif ($plan['price_kes'] == 0): ?>
                <button class="btn btn-disabled" disabled>Free Forever</button>
            <?php else: ?>
                <button class="btn btn-primary"
                        onclick="openPaymentModal(<?= $plan['plan_id'] ?>, '<?= htmlspecialchars($plan['name'], ENT_QUOTES) ?>', <?= $plan['price_kes'] ?>, <?= $plan['price_usd'] ?>)">
                    Upgrade to <?= htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8') ?>
                </button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    
    <div class="faq">
        <h3>Frequently Asked Questions</h3>
        <div class="faq-item">
            <div class="faq-q" onclick="this.parentElement.classList.toggle('open')">Can I cancel anytime?</div>
            <div class="faq-a">Yes. You can cancel your subscription at any time. You will keep access until the end of your billing period.</div>
        </div>
        <div class="faq-item">
            <div class="faq-q" onclick="this.parentElement.classList.toggle('open')">Which payment methods are accepted?</div>
            <div class="faq-a">We accept M-Pesa, cards, and bank transfers via Paystack (Kenya/Africa), and all major credit/debit cards via Stripe (international).</div>
        </div>
        <div class="faq-item">
            <div class="faq-q" onclick="this.parentElement.classList.toggle('open')">What happens to my data if I downgrade?</div>
            <div class="faq-a">Your data is never deleted. If you exceed the Free plan limits after downgrading, older records become read-only until you reduce your count or upgrade again.</div>
        </div>
        <div class="faq-item">
            <div class="faq-q" onclick="this.parentElement.classList.toggle('open')">Is my payment information secure?</div>
            <div class="faq-a">Yes. We never store your card details. Payments are processed by Paystack and Stripe, both PCI-DSS compliant providers.</div>
        </div>
    </div>
</div>

<!div 
<div class="payment-modal-overlay" id="payModal">
    <div class="payment-modal">
        <button class="modal-close" onclick="closePayModal()">&times;</button>
        <h3>Choose Payment Method</h3>
        <p id="payModalDesc">Select how you would like to pay.</p>

        
        <div class="pay-option" onclick="payWithPaystack()">
            <div class="icon">🇰🇪</div>
            <div class="info">
                <strong>Paystack</strong>
                <small>M-Pesa, Cards, Bank Transfer — Kenya &amp; Africa</small>
            </div>
            <span class="arrow">→</span>
        </div>

        
        <div class="pay-option" onclick="payWithStripe()">
            <div class="icon">💳</div>
            <div class="info">
                <strong>Stripe</strong>
                <small>Visa, Mastercard, Amex — International</small>
            </div>
            <span class="arrow">→</span>
        </div>
    </div>
</div>

<script>
const USER_EMAIL = <?= json_encode($user['email'] ?? '') ?>;
const USER_NAME  = <?= json_encode($user['full_name'] ?? '') ?>;
const PAYSTACK_KEY = <?= json_encode($paystack_key) ?>;
const STRIPE_KEY   = <?= json_encode($stripe_key) ?>;

let selectedPlan = { id: null, name: '', kes: 0, usd: 0 };

function openPaymentModal(planId, planName, kes, usd) {
    selectedPlan = { id: planId, name: planName, kes, usd };
    document.getElementById('payModalDesc').textContent =
        `Upgrading to ${planName} — KES ${kes.toLocaleString()}/month`;
    document.getElementById('payModal').classList.add('open');
}
function closePayModal() {
    document.getElementById('payModal').classList.remove('open');
}
document.getElementById('payModal').addEventListener('click', function(e) {
    if (e.target === this) closePayModal();
});


function payWithPaystack() {
    closePayModal();
    const handler = PaystackPop.setup({
        key:       PAYSTACK_KEY,
        email:     USER_EMAIL,
        amount:    selectedPlan.kes * 100, // Paystack uses kobo/cents
        currency:  'KES',
        ref:       'RM_' + Date.now(),
        metadata:  { plan_id: selectedPlan.id, user_name: USER_NAME },
        callback:  function(response) {

            window.location.href = 'verify_payment.php?provider=paystack&ref=' +
                response.reference + '&plan_id=' + selectedPlan.id;
        },
        onClose: function() {}
    });
    handler.openIframe();
}


function payWithStripe() {
    closePayModal();

    fetch('create_stripe_session.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ plan_id: selectedPlan.id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.url) {
            window.location.href = data.url;
        } else {
            alert('Could not initiate Stripe payment. Please try Paystack.');
        }
    })
    .catch(() => alert('Network error. Please try again.'));
}
</script>
</body>
</html>