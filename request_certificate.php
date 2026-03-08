<?php
// request_certificate.php
declare(strict_types=1);

require_once __DIR__ . '/session_bootstrap.php';

/* --- Member auth gate (this is called from member_dashboard.php) --- */
if (empty($_SESSION['member_login']) || $_SESSION['member_login'] !== true || empty($_SESSION['member'])) {
    header("Location: index.php");
    exit();
}

$memberSess = $_SESSION['member']; // ['member_type','member_id','parent_member_id','mahal_id',...]

// Household head id (same logic as in member_dashboard.php)
$household_member_id = (isset($memberSess['member_type']) && $memberSess['member_type'] === 'family' && !empty($memberSess['parent_member_id']))
    ? (int)$memberSess['parent_member_id']
    : (int)$memberSess['member_id'];

/* --- Only accept POST --- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: member_dashboard.php");
    exit();
}

/* --- CSRF check (same token as in member_dashboard.php) --- */
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403);
    echo "Invalid request token.";
    exit();
}

/* --- Read form values --- */
$post_member_id    = (int)($_POST['member_id'] ?? 0);
$certificate_type  = trim((string)($_POST['certificate_type'] ?? ''));
$notes             = trim((string)($_POST['notes'] ?? ''));

/* --- Basic security: ensure posted member_id matches this household --- */
if ($post_member_id !== $household_member_id) {
    http_response_code(403);
    echo "Invalid member reference.";
    exit();
}

/* --- Prevent duplicate pending requests for this member+type --- */
if ($certificate_type !== '') {
    require_once __DIR__ . '/db_connection.php';

    try {
        $db_result = get_db_connection();
        if (isset($db_result['error'])) {
            throw new Exception("Database connection failed: " . $db_result['error']);
        }
        /** @var mysqli $conn */
        $conn = $db_result['conn'];

        // cert_requests table with status = 'pending'
        $stmt = $conn->prepare("
            SELECT id
            FROM cert_requests
            WHERE member_id = ?
              AND certificate_type = ?
              AND status = 'pending'
            LIMIT 1
        ");
        $stmt->bind_param("is", $household_member_id, $certificate_type);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $stmt->close();
            $conn->close();

            $_SESSION['member_warning'] =
                'You already have a pending ' . ucfirst($certificate_type) .
                ' certificate request. Please wait until it is processed.';

            header("Location: member_dashboard.php");
            exit();
        }

        $stmt->close();
        $conn->close();
    } catch (Throwable $e) {
        error_log("Certificate request duplicate check failed: " . $e->getMessage());
        $_SESSION['member_warning'] =
            'Unable to verify existing certificate requests right now. Please try again later.';

        header("Location: member_dashboard.php");
        exit();
    }
}

/* --- Marriage Certificate → redirect to detailed page --- */
if ($certificate_type === 'marriage') {
    $_SESSION['pending_cert_member_id'] = $household_member_id;
    $_SESSION['pending_cert_notes']     = $notes;

    header("Location: member_marriage_request.php");
    exit();
}

/* --- Caste Certificate → redirect to full member form --- */
if ($certificate_type === 'caste') {
    $_SESSION['pending_cert_member_id'] = $household_member_id;
    $_SESSION['pending_cert_notes']     = $notes;

    header("Location: member_caste_request.php");
    exit();
}

/* --- Termination Certificate → redirect to termination form --- */
if ($certificate_type === 'termination') {
    $_SESSION['pending_cert_member_id'] = $household_member_id;
    $_SESSION['pending_cert_notes']     = $notes;

    header("Location: member_termination_request.php");
    exit();
}

/* --- Unknown certificate type (or empty) --- */
header("Location: member_dashboard.php");
exit();
