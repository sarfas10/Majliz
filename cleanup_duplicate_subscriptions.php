<?php
/**
 * One-time cleanup: if a mahal has multiple 'active' subscription rows,
 * keep the one with the latest end_date and mark the rest as 'inactive'.
 *
 * Run once via browser or CLI, then delete this file.
 */
require_once 'db_connection.php';

$db = get_db_connection();
if (isset($db['error'])) {
    die("DB error: " . $db['error']);
}
$conn = $db['conn'];

// Find mahals with more than one active subscription
$findSql = "
    SELECT mahal_id, COUNT(*) as cnt, MAX(end_date) as latest_end
    FROM subscriptions
    WHERE status = 'active'
    GROUP BY mahal_id
    HAVING cnt > 1
";
$result = $conn->query($findSql);

$fixed = 0;
$details = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $mahalId   = (int) $row['mahal_id'];
        $latestEnd = $row['latest_end'];
        $count     = (int) $row['cnt'];

        // Keep the single row with the highest end_date; expire all others
        $upd = $conn->prepare("
            UPDATE subscriptions
            SET status = 'inactive'
            WHERE mahal_id = ?
              AND status   = 'active'
              AND end_date < ?
        ");
        $upd->bind_param("is", $mahalId, $latestEnd);
        $upd->execute();
        $affected = $upd->affected_rows;
        $upd->close();

        $fixed += $affected;
        $details[] = "Mahal ID $mahalId: had $count active rows → expired $affected duplicates (kept end_date $latestEnd)";
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Cleanup Duplicate Subscriptions</title>
    <style>
        body { font-family: monospace; padding: 30px; background: #f9fafb; }
        h2   { color: #1f2937; }
        .ok  { color: #065f46; background: #d1fae5; padding: 4px 10px; border-radius: 4px; }
        .row { padding: 6px 0; border-bottom: 1px solid #e5e7eb; color: #374151; }
    </style>
</head>
<body>
    <h2>Duplicate Active Subscription Cleanup</h2>
    <?php if ($fixed === 0): ?>
        <p class="ok">✓ No duplicate active subscriptions found. Nothing to clean up.</p>
    <?php else: ?>
        <p class="ok">✓ Fixed <strong><?= $fixed ?></strong> duplicate row(s).</p>
        <?php foreach ($details as $line): ?>
            <div class="row"><?= htmlspecialchars($line) ?></div>
        <?php endforeach; ?>
    <?php endif; ?>
    <p style="margin-top:20px; color:#6b7280; font-size:12px;">
        ⚠ Delete this file after running it.
    </p>
</body>
</html>
