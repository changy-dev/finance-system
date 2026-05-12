<?php
include 'db.php';

$result = $conn->query("
SELECT *
FROM audit_logs
ORDER BY created_at DESC
");
?>

<table>
<tr>
<th>Action</th>
<th>IP</th>
<th>Date</th>
</tr>

<?php while($row = $result->fetch_assoc()): ?>

<tr>
<td><?= $row['action'] ?></td>
<td><?= $row['ip_address'] ?></td>
<td><?= $row['created_at'] ?></td>
</tr>

<?php endwhile; ?>

</table>