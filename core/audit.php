<?php
// ============================================================
// audit.php — Audit log helper
// Place in: finance_system/audit.php
// Usage: require_once 'audit.php';
//        Audit::log($conn, $user_id, 'transaction.create', 'transaction', $id, null, $data);
// ============================================================

class Audit
{
    /**
     * Log an action to the audit_log table.
     *
     * @param mysqli      $conn
     * @param int         $user_id
     * @param string      $action      e.g. 'transaction.create', 'budget.delete'
     * @param string|null $target_type e.g. 'transaction', 'budget', 'goal'
     * @param int|null    $target_id   The ID of the record affected
     * @param array|null  $old_values  Previous state (for edits/deletes)
     * @param array|null  $new_values  New state (for creates/edits)
     */
    public static function log(
        mysqli  $conn,
        int     $user_id,
        string  $action,
        ?string $target_type = null,
        ?int    $target_id   = null,
        ?array  $old_values  = null,
        ?array  $new_values  = null
    ): void {
        $ip       = self::getIp();
        $old_json = $old_values ? json_encode($old_values) : null;
        $new_json = $new_values ? json_encode($new_values) : null;

        $stmt = $conn->prepare("
            INSERT INTO audit_log
                (user_id, action, target_type, target_id, old_values, new_values, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "issssss",
            $user_id,
            $action,
            $target_type,
            $target_id,
            $old_json,
            $new_json,
            $ip
        );
        $stmt->execute();
    }

    /**
     * Fetch audit log for a user (paginated).
     */
    public static function get(mysqli $conn, int $user_id, int $limit = 50, int $offset = 0): array
    {
        $stmt = $conn->prepare("
            SELECT log_id, action, target_type, target_id,
                   old_values, new_values, ip_address, created_at
            FROM audit_log
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param("iii", $user_id, $limit, $offset);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private static function getIp(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
    }
}