<?php
require_once 'session_bootstrap.php';
require_once 'db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$db_result = get_db_connection();
if (isset($db_result['error'])) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}
$conn = $db_result['conn'];

$mahal_id = $_SESSION['user_id'];
$plan_id = isset($_POST['plan_id']) ? intval($_POST['plan_id']) : 0;
$target_mahal_id = isset($_POST['target_mahal_id']) ? intval($_POST['target_mahal_id']) : 0;

// Determine who the subscription is for
$subscription_mahal_id = $mahal_id; // Default to self

if ($target_mahal_id > 0) {
    // Verify that the logged-in user sponsors this mahal
    $checkSponsor = $conn->prepare("SELECT id FROM register WHERE id = ? AND sponsored_by = ?");
    $checkSponsor->bind_param("ii", $target_mahal_id, $mahal_id);
    $checkSponsor->execute();
    if ($checkSponsor->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid sponsored mahal selected']);
        exit();
    }
    $checkSponsor->close();
    $subscription_mahal_id = $target_mahal_id;
}

// (Connection already checked above)

// 1. Fetch Plan Details
$planStmt = $conn->prepare("SELECT monthly_price, yearly_price FROM plans WHERE id = ?");
$planStmt->bind_param("i", $plan_id);
$planStmt->execute();
$planRes = $planStmt->get_result();

if ($planRes->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Plan not found']);
    exit();
}

$plan = $planRes->fetch_assoc();
$duration_type = isset($_POST['duration_type']) && in_array($_POST['duration_type'], ['month', 'year']) ? $_POST['duration_type'] : 'year';

if ($duration_type === 'month') {
    $amount = floatval($plan['monthly_price']);
} else {
    $amount = floatval($plan['yearly_price']);
}

$planStmt->close();

// 2. Insert Request
$sql = "INSERT INTO subscription_requests (mahal_id, plan_id, duration_type, total_amount, status) VALUES (?, ?, ?, ?, 'pending')";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iisd", $subscription_mahal_id, $plan_id, $duration_type, $amount);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Subscription request submitted successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to submit request: ' . $conn->error]);
}


$stmt->close();
$conn->close();
?>