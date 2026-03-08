<?php
require_once 'session_bootstrap.php';
require_once 'db_connection.php';

header('Content-Type: application/json');

// Admin Auth Check
if (!isset($_SESSION['user_id'])) {
    // Ideally verify admin role here
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$request_id = isset($_POST['request_id']) ? intval($_POST['request_id']) : 0;
$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($request_id <= 0 || !in_array($action, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit();
}

$db_result = get_db_connection();
if (isset($db_result['error'])) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}
$conn = $db_result['conn'];

// Fetch Request
$reqSql = "SELECT * FROM subscription_requests WHERE id = ?";
$stmt = $conn->prepare($reqSql);
$stmt->bind_param("i", $request_id);
$stmt->execute();
$reqRes = $stmt->get_result();

if ($reqRes->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Request not found']);
    exit();
}

$request = $reqRes->fetch_assoc();

if ($request['status'] !== 'pending') {
    echo json_encode(['success' => false, 'message' => 'Request is already processed']);
    exit();
}

if ($action === 'reject') {
    $upd = $conn->prepare("UPDATE subscription_requests SET status = 'rejected' WHERE id = ?");
    $upd->bind_param("i", $request_id);
    if ($upd->execute()) {
        echo json_encode(['success' => true, 'message' => 'Request rejected']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to reject request']);
    }
    $conn->close();
    exit();
}

if ($action === 'approve') {
    // 1. Get Plan Duration based on request type
    // New Logic: Check duration_type column. 
    // If user hasn't updated DB yet/old request, default to 'year' (12 months).
    $duration_type = $request['duration_type'] ?? 'year';
    $duration_months = ($duration_type === 'month') ? 1 : 12;

    $conn->begin_transaction();

    try {
        // Function to create/renew subscription
        function renewSubscription($conn, $mahal_id, $plan_id, $months)
        {
            // Check for existing active/expired subscription to determine start date
            $checkSql = "SELECT end_date FROM subscriptions WHERE mahal_id = ? AND status IN ('active', 'expired') ORDER BY end_date DESC LIMIT 1";
            $chk = $conn->prepare($checkSql);
            $chk->bind_param("i", $mahal_id);
            $chk->execute();
            $chkRes = $chk->get_result();

            $start_date = date('Y-m-d');
            if ($chkRes->num_rows > 0) {
                $lastSub = $chkRes->fetch_assoc();
                $lastEnd = $lastSub['end_date'];
                if ($lastEnd > $start_date) {
                    // Extend from last end date
                    $start_date = date('Y-m-d', strtotime($lastEnd . ' + 1 day'));
                }
            }
            $chk->close();

            $end_date = date('Y-m-d', strtotime($start_date . " + $months months"));
            // Adjust to end of day logic (Jan 1 to Jan 31 or Jan 1 to Dec 31)
            $end_date = date('Y-m-d', strtotime($end_date . " - 1 day"));

            $ins = $conn->prepare("INSERT INTO subscriptions (mahal_id, plan_id, start_date, end_date, status) VALUES (?, ?, ?, ?, 'active')");
            $ins->bind_param("iiss", $mahal_id, $plan_id, $start_date, $end_date);
            $ins->execute();
            $ins->close();
        }

        // Renew Main Mahal
        renewSubscription($conn, $request['mahal_id'], $request['plan_id'], $duration_months);

        // NOTE: Request to renew sponsored mahal logic is removed from this flow as per new requirement ("independant from others").
        // Even if 'is_sponsored_renewal' is 1 (legacy), we ignore it or user prompt suggested not to do it.
        // If we want to strictly follow "independent", we just do the main mahal.

        // Update Request Status
        $updReq = $conn->prepare("UPDATE subscription_requests SET status = 'approved' WHERE id = ?");
        $updReq->bind_param("i", $request_id);
        $updReq->execute();

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Request approved and subscription updated.']);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error processing approval: ' . $e->getMessage()]);
    }
}

$conn->close();
?>