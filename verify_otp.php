-- ============================================================
-- migration_new_features.sql
-- Run this against your finance_db to support all new features
-- ============================================================

-- ── 1. 2FA: Add otp_expiry column if not already present ────
ALTER TABLE `users`
    MODIFY COLUMN `otp_code` varchar(255) DEFAULT NULL  -- was varchar(10); now stores bcrypt hash
    COMMENT '2FA OTP bcrypt hash';

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `otp_expiry` datetime DEFAULT NULL
    COMMENT '2FA OTP expiry timestamp';

-- ── 2. Activity / Audit log ──────────────────────────────────
-- The audit_log table already exists in finance_db (5).sql
-- If it is missing the user_agent column, add it:
ALTER TABLE `audit_log`
    ADD COLUMN IF NOT EXISTS `user_agent` varchar(512) DEFAULT NULL
    AFTER `ip_address`;

-- Ensure the audit_logs table used by log_activity.php also exists
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     int(11)    NOT NULL,
  `action`      varchar(255) NOT NULL,
  `ip_address`  varchar(45)  DEFAULT NULL,
  `user_agent`  varchar(512) DEFAULT NULL,
  `created_at`  timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── 3. Dark mode ─────────────────────────────────────────────
-- users.theme already exists ('dark'|'light'|'auto') — no schema change needed.

-- ── 4. Reports / PDF export ──────────────────────────────────
-- Uses existing transactions table — no schema change needed.

-- ── 5. CSV Import ────────────────────────────────────────────
-- Uses existing transactions table — no schema change needed.
-- Audit log entry via audit_log table (already exists).

-- ── 6. Ensure subscriptions exist for free users ─────────────
-- Insert a default free subscription for any user who has none:
INSERT IGNORE INTO `subscriptions` (user_id, plan_id, created_at)
SELECT u.user_id, (SELECT plan_id FROM plans WHERE name='Free' LIMIT 1), NOW()
FROM users u
WHERE NOT EXISTS (
    SELECT 1 FROM subscriptions s WHERE s.user_id = u.user_id
);

-- ── 7. Indexes for performance ───────────────────────────────
ALTER TABLE `audit_log`
    ADD INDEX IF NOT EXISTS `idx_user_created` (`user_id`, `created_at`);

ALTER TABLE `transactions`
    ADD INDEX IF NOT EXISTS `idx_user_date` (`user_id`, `date`);

SELECT 'Migration complete ✅' AS status;
