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
            // Check if an ACTIVE subscription already exists
            $activeSql = "SELECT id, end_date FROM subscriptions WHERE mahal_id = ? AND status = 'active' ORDER BY end_date DESC LIMIT 1";
            $chkActive = $conn->prepare($activeSql);
            $chkActive->bind_param("i", $mahal_id);
            $chkActive->execute();
            $activeRes = $chkActive->get_result();

            if ($activeRes->num_rows > 0) {
                // ── EXTEND existing active subscription ──────────────────────
                // Extend end_date from wherever it currently ends, and update plan
                $activeSub = $activeRes->fetch_assoc();
                $currentEnd = $activeSub['end_date'];
                $activeId   = $activeSub['id'];
                $chkActive->close();

                // Start extension from day after current end_date
                $new_end = date('Y-m-d', strtotime($currentEnd . " + $months months"));
                $new_end = date('Y-m-d', strtotime($new_end . " - 1 day")); // inclusive end

                $upd = $conn->prepare(
                    "UPDATE subscriptions SET plan_id = ?, end_date = ? WHERE id = ?"
                );
                $upd->bind_param("isi", $plan_id, $new_end, $activeId);
                $upd->execute();
                $upd->close();

            } else {
                // ── No active subscription — check inactive/expired for start date ──
                $chkActive->close();

                $lastSql = "SELECT end_date FROM subscriptions 
                            WHERE mahal_id = ? AND status IN ('inactive', 'expired') 
                            ORDER BY end_date DESC LIMIT 1";
                $chkLast = $conn->prepare($lastSql);
                $chkLast->bind_param("i", $mahal_id);
                $chkLast->execute();
                $lastRes = $chkLast->get_result();

                $start_date = date('Y-m-d');
                if ($lastRes->num_rows > 0) {
                    $lastEnd = $lastRes->fetch_assoc()['end_date'];
                    if ($lastEnd > $start_date) {
                        $start_date = date('Y-m-d', strtotime($lastEnd . ' + 1 day'));
                    }
                }
                $chkLast->close();

                $end_date = date('Y-m-d', strtotime($start_date . " + $months months"));
                $end_date = date('Y-m-d', strtotime($end_date . " - 1 day")); // inclusive end

                $ins = $conn->prepare(
                    "INSERT INTO subscriptions (mahal_id, plan_id, start_date, end_date, status) VALUES (?, ?, ?, ?, 'active')"
                );
                $ins->bind_param("iiss", $mahal_id, $plan_id, $start_date, $end_date);
                $ins->execute();
                $ins->close();
            }
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

        // Sync register.status to 'active'
        $updStatus = $conn->prepare("UPDATE register SET status = 'active' WHERE id = ?");
        $updStatus->bind_param("i", $request['mahal_id']);
        $updStatus->execute();
        $updStatus->close();

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Request approved and subscription updated.']);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error processing approval: ' . $e->getMessage()]);
    }
}

$conn->close();
?>