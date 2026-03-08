<?php
require_once 'db_connection.php';

$db = get_db_connection();
if (isset($db['error'])) {
    die($db['error']);
}
$conn = $db['conn'];

echo "Starting subscription backfill...\n";

// 1. Fetch Default Plan Settings
$planSettingsVars = [];
$psSql = "SELECT setting_key, setting_value FROM admin_settings WHERE setting_key IN ('default_plan', 'default_plan_duration_type', 'default_plan_duration_custom')";
$psRes = $conn->query($psSql);
if ($psRes) {
    while ($row = $psRes->fetch_assoc()) {
        $planSettingsVars[$row['setting_key']] = $row['setting_value'];
    }
}

$defaultPlanId = isset($planSettingsVars['default_plan']) ? intval($planSettingsVars['default_plan']) : 0;

if ($defaultPlanId <= 0) {
    die("Error: No default plan configured in Admin Settings. Please configure a default plan first.\n");
}

// Get Plan Details
$planTitle = '';
$planQ = $conn->prepare("SELECT title FROM plans WHERE id = ?");
if ($planQ) {
    $planQ->bind_param("i", $defaultPlanId);
    $planQ->execute();
    $planQ->bind_result($planTitle);
    $planQ->fetch();
    $planQ->close();
}

if (!$planTitle) {
    die("Error: Default plan ID $defaultPlanId not found in plans table.\n");
}

echo "Using Default Plan: $planTitle (ID: $defaultPlanId)\n";

// 2. Fetch Duration Settings
$durType = isset($planSettingsVars['default_plan_duration_type']) ? intval($planSettingsVars['default_plan_duration_type']) : 1; // 1=Month
$durCustom = isset($planSettingsVars['default_plan_duration_custom']) ? intval($planSettingsVars['default_plan_duration_custom']) : 30;

// 3. Find Mahals without subscriptions
$sqlM = "SELECT id, name, created_at FROM register WHERE role = 'user' AND id NOT IN (SELECT mahal_id FROM subscriptions)";
$resM = $conn->query($sqlM);

if ($resM && $resM->num_rows > 0) {
    $count = 0;
    while ($mahal = $resM->fetch_assoc()) {
        $mahalId = $mahal['id'];
        $createdAt = $mahal['created_at']; // e.g. 2024-01-01 12:00:00

        // Calculate dates
        $startDate = date('Y-m-d', strtotime($createdAt));
        $endDate = $startDate;

        if ($durType == 1) { // Month
            $endDate = date('Y-m-d', strtotime('+1 month', strtotime($startDate)));
        } elseif ($durType == 2) { // Year
            $endDate = date('Y-m-d', strtotime('+1 year', strtotime($startDate)));
        } elseif ($durType == 3) { // Custom Days
            $endDate = date('Y-m-d', strtotime("+$durCustom days", strtotime($startDate)));
        }

        // Determine status
        $today = date('Y-m-d');
        $status = ($today > $endDate) ? 'expired' : 'active';

        // Insert Subscription
        $insSub = $conn->prepare("INSERT INTO subscriptions (mahal_id, plan_id, start_date, end_date, status, created_at) VALUES (?, ?, ?, ?, ?, ?)");
        if ($insSub) {
            $insSub->bind_param("iissss", $mahalId, $defaultPlanId, $startDate, $endDate, $status, $createdAt);
            if ($insSub->execute()) {
                // Update Register table with Plan Name
                $updReg = $conn->prepare("UPDATE register SET plan = ? WHERE id = ?");
                if ($updReg) {
                    $updReg->bind_param("si", $planTitle, $mahalId);
                    $updReg->execute();
                    $updReg->close();
                }
                echo "Processed Mahal: " . $mahal['name'] . " (Status: $status)\n";
                $count++;
            } else {
                echo "Failed to insert for Mahal: " . $mahal['name'] . " - " . $insSub->error . "\n";
            }
            $insSub->close();
        }
    }
    echo "Backfill completed. Processed $count mahals.\n";
} else {
    echo "No mahals found needing backfill.\n";
}

$conn->close();
?>