<?php
// auth_restrictions.php

/**
 * Checks if the logged in mahal is restricted from modifying records.
 * A mahal is restricted if their register.status is 'inactive' or 'pending'.
 * 
 * Sets a global $is_restricted boolean that can be used to disable UI elements.
 */

$is_restricted = false;
$restriction_message = "Your subscription is currently inactive. You can view existing records but cannot add or modify entries.";

if (isset($_SESSION['user_id']) && isset($conn)) {
    $auth_uid = $_SESSION['user_id'];
    $auth_sql = "SELECT status FROM register WHERE id = ?";
    $auth_stmt = $conn->prepare($auth_sql);
    if ($auth_stmt) {
        $auth_stmt->bind_param("i", $auth_uid);
        $auth_stmt->execute();
        $auth_res = $auth_stmt->get_result();
        if ($auth_res->num_rows > 0) {
            $auth_row = $auth_res->fetch_assoc();
            $current_auth_status = strtolower($auth_row['status'] ?? 'active');
            if ($current_auth_status === 'inactive' || $current_auth_status === 'pending') {
                $is_restricted = true;
            }
        }
        $auth_stmt->close();
    }
}
?>
