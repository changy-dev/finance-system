<?php
// ============================================================
// mailer.php — Transactional email helper
// Place in: finance_system/mailer.php
// ============================================================

class Mailer
{
    private static string $from_name  = '';
    private static string $from_email = '';
    private static string $app_name   = '';
    private static string $app_url    = '';

    public static function init(): void
    {
        self::$app_name   = $_ENV['APP_NAME']      ?? 'Royal Matrix';
        self::$app_url    = $_ENV['APP_URL']        ?? 'http://localhost/finance_system';
        self::$from_email = $_ENV['MAIL_FROM']      ?? 'noreply@royalmatrix.com';
        self::$from_name  = $_ENV['MAIL_FROM_NAME'] ?? self::$app_name;
    }

    // ── Queue an email (saved to DB, sent by cron) ─────────
    public static function queue(
        mysqli $conn,
        string $to_email,
        string $subject,
        string $body_html,
        ?int   $user_id = null
    ): bool {
        $stmt = $conn->prepare("
            INSERT INTO email_queue (user_id, to_email, subject, body_html)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("isss", $user_id, $to_email, $subject, $body_html);
        return $stmt->execute();
    }

    // ── Send immediately ───────────────────────────────────
    public static function send(string $to, string $subject, string $html): bool
    {
        self::init();
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . self::$from_name . " <" . self::$from_email . ">\r\n";
        $headers .= "Reply-To: " . self::$from_email . "\r\n";
        return mail($to, $subject, $html, $headers);
    }

    // ── Process queue (call via cron: php process_email.php) ─
    public static function processQueue(mysqli $conn, int $limit = 20): array
    {
        self::init();
        $results = ['sent' => 0, 'failed' => 0];

        $stmt = $conn->prepare("
            SELECT queue_id, to_email, subject, body_html
            FROM email_queue
            WHERE status='pending' AND attempts < 3 AND scheduled_at <= NOW()
            ORDER BY scheduled_at ASC LIMIT ?
        ");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($rows as $row) {
            $ok     = self::send($row['to_email'], $row['subject'], $row['body_html']);
            $status = $ok ? 'sent' : 'failed';
            $sent_at = $ok ? date('Y-m-d H:i:s') : null;

            $upd = $conn->prepare("
                UPDATE email_queue SET status=?, sent_at=?, attempts=attempts+1
                WHERE queue_id=?
            ");
            $upd->bind_param("ssi", $status, $sent_at, $row['queue_id']);
            $upd->execute();
            $ok ? $results['sent']++ : $results['failed']++;
        }
        return $results;
    }

    // ── TEMPLATES ──────────────────────────────────────────

    public static function welcome(string $name): string
    {
        self::init();
        return self::wrap("Welcome to " . self::$app_name . "! 🎉", "
            <p>Hi <strong>" . htmlspecialchars($name) . "</strong>,</p>
            <p>Welcome to <strong>" . self::$app_name . "</strong> — your personal finance command centre.</p>
            <ul>
                <li>📊 Track income and expenses</li>
                <li>💰 Set savings goals</li>
                <li>📅 Manage recurring transactions</li>
                <li>🏦 Track debts and loans</li>
            </ul>
            <p style='text-align:center;margin:30px 0;'>
                <a href='" . self::$app_url . "/dashboard.php'
                   style='background:#7c3aed;color:#fff;padding:14px 28px;border-radius:8px;text-decoration:none;font-weight:700;'>
                    Go to Dashboard →
                </a>
            </p>
            <p style='color:#64748b;font-size:13px;'>You are on the <strong>Free plan</strong>. Upgrade anytime to unlock exports, alerts, and AI insights.</p>
        ");
    }

    public static function budgetAlert(string $name, string $category, float $spent, float $limit, int $pct): string
    {
        self::init();
        $color = $pct >= 100 ? '#ef4444' : '#f59e0b';
        $msg   = $pct >= 100
            ? "You have <strong>exceeded</strong> your {$category} budget!"
            : "You have used <strong>{$pct}%</strong> of your {$category} budget.";

        return self::wrap("⚠️ Budget Alert: {$category}", "
            <p>Hi <strong>" . htmlspecialchars($name) . "</strong>,</p>
            <p>{$msg}</p>
            <div style='background:#f8fafc;border-radius:10px;padding:20px;margin:20px 0;border:1px solid #e2e8f0;'>
                <p style='margin:0 0 6px;font-size:12px;color:#64748b;text-transform:uppercase;'>Category</p>
                <p style='margin:0 0 14px;font-size:20px;font-weight:700;'>{$category}</p>
                <div style='background:#e2e8f0;border-radius:99px;height:8px;overflow:hidden;'>
                    <div style='background:{$color};width:" . min(100, $pct) . "%;height:8px;border-radius:99px;'></div>
                </div>
                <div style='display:flex;justify-content:space-between;margin-top:8px;font-size:13px;'>
                    <span style='color:{$color};font-weight:700;'>KES " . number_format($spent, 2) . " spent</span>
                    <span style='color:#64748b;'>Limit: KES " . number_format($limit, 2) . "</span>
                </div>
            </div>
            <p style='text-align:center;margin:24px 0;'>
                <a href='" . self::$app_url . "/budget.php'
                   style='background:#7c3aed;color:#fff;padding:14px 28px;border-radius:8px;text-decoration:none;font-weight:700;'>
                    View Budget →
                </a>
            </p>
        ");
    }

    public static function paymentSuccess(string $name, string $plan, float $amount, string $ref): string
    {
        self::init();
        return self::wrap("✅ Payment Confirmed — {$plan} Plan", "
            <p>Hi <strong>" . htmlspecialchars($name) . "</strong>,</p>
            <p>Your payment was successful. Your account has been upgraded to <strong>{$plan}</strong>.</p>
            <div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:20px;margin:20px 0;'>
                <table style='width:100%;font-size:14px;'>
                    <tr><td style='color:#64748b;padding:4px 0;'>Plan</td><td style='text-align:right;font-weight:700;'>{$plan}</td></tr>
                    <tr><td style='color:#64748b;padding:4px 0;'>Amount</td><td style='text-align:right;font-weight:700;'>KES " . number_format($amount, 2) . "</td></tr>
                    <tr><td style='color:#64748b;padding:4px 0;'>Reference</td><td style='text-align:right;font-family:monospace;font-size:12px;'>" . htmlspecialchars($ref) . "</td></tr>
                </table>
            </div>
            <p style='text-align:center;margin:24px 0;'>
                <a href='" . self::$app_url . "/dashboard.php'
                   style='background:#10b981;color:#fff;padding:14px 28px;border-radius:8px;text-decoration:none;font-weight:700;'>
                    Explore Premium Features →
                </a>
            </p>
        ");
    }

    // ── HTML email wrapper ─────────────────────────────────
    private static function wrap(string $title, string $content): string
    {
        $app = self::$app_name;
        $url = self::$app_url;
        return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f1f5f9;font-family:system-ui,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='padding:40px 20px;'>
<tr><td align='center'>
<table width='580' cellpadding='0' cellspacing='0' style='max-width:580px;width:100%;'>
  <tr><td style='background:linear-gradient(135deg,#7c3aed,#06b6d4);border-radius:12px 12px 0 0;padding:28px 32px;text-align:center;'>
    <h1 style='margin:0;color:#fff;font-size:22px;font-weight:800;'>{$app}</h1>
    <p style='margin:6px 0 0;color:rgba(255,255,255,.8);font-size:13px;'>{$title}</p>
  </td></tr>
  <tr><td style='background:#fff;padding:32px;border:1px solid #e2e8f0;border-top:none;font-size:15px;line-height:1.7;color:#1e293b;'>
    {$content}
  </td></tr>
  <tr><td style='background:#f8fafc;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;padding:20px 32px;text-align:center;'>
    <p style='margin:0;font-size:12px;color:#94a3b8;'>© " . date('Y') . " {$app} · <a href='{$url}' style='color:#7c3aed;text-decoration:none;'>Visit App</a></p>
  </td></tr>
</table></td></tr></table></body></html>";
    }
}