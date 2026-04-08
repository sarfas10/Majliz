<?php
// subscription_helpers.php

/**
 * Updates the status of subscriptions that have passed their end date.
 * Sets status from 'active' to 'inactive', and syncs register.status accordingly.
 *
 * @param mysqli $conn The database connection object.
 * @return int The number of subscriptions updated.
 */
function update_expired_subscriptions($conn)
{
    if (!$conn) {
        return 0;
    }

    // Check for subscriptions table existence to avoid errors on fresh installs
    $tableCheck = $conn->query("SHOW TABLES LIKE 'subscriptions'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        return 0;
    }

    $today = date('Y-m-d');

    // 1. Collect the mahal_ids that are about to expire BEFORE updating
    $findSql = "SELECT DISTINCT mahal_id FROM subscriptions WHERE end_date < ? AND status = 'active'";
    $findStmt = $conn->prepare($findSql);
    $expiredMahalIds = [];
    if ($findStmt) {
        $findStmt->bind_param("s", $today);
        $findStmt->execute();
        $findRes = $findStmt->get_result();
        while ($row = $findRes->fetch_assoc()) {
            $expiredMahalIds[] = (int) $row['mahal_id'];
        }
        $findStmt->close();
    }

    // 2. Mark subscriptions as inactive
    $sql = "UPDATE subscriptions SET status = 'inactive' WHERE end_date < ? AND status = 'active'";
    $stmt = $conn->prepare($sql);
    $affected = 0;
    if ($stmt) {
        $stmt->bind_param("s", $today);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
    }

    // 3. Sync register.status → 'inactive' for each expired mahal
    //    (only if they don't still have another active subscription — safety check)
    if (!empty($expiredMahalIds)) {
        foreach ($expiredMahalIds as $mid) {
            // Check if any other active subscription still exists for this mahal
            $chk = $conn->prepare(
                "SELECT COUNT(*) as cnt FROM subscriptions WHERE mahal_id = ? AND status = 'active'"
            );
            $chk->bind_param("i", $mid);
            $chk->execute();
            $cnt = $chk->get_result()->fetch_assoc()['cnt'];
            $chk->close();

            if ($cnt === 0) {
                // No more active sub → mark register row as inactive
                $upd = $conn->prepare("UPDATE register SET status = 'inactive' WHERE id = ?");
                $upd->bind_param("i", $mid);
                $upd->execute();
                $upd->close();
            }
        }
    }

    return $affected;
}
?>