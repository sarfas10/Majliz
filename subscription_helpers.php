<?php
// subscription_helpers.php

/**
 * Updates the status of subscriptions that have passed their end date.
 * Sets status from 'active' to 'expired'.
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

    // Update query: Set status to 'expired' where end_date is before today AND status is 'active'
    $sql = "UPDATE subscriptions SET status = 'expired' WHERE end_date < ? AND status = 'active'";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $today);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected;
    }

    return 0;
}
?>