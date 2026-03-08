<?php
// delete_sahakari_member.php
require_once __DIR__ . '/session_bootstrap.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Verify it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Invalid request method.";
    header("Location: sahakari_management.php");
    exit();
}

// Validate CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['error'] = "Invalid CSRF token.";
    header("Location: sahakari_management.php");
    exit();
}

// Get member ID
$member_id = isset($_POST['member_id']) ? (int)$_POST['member_id'] : 0;

if ($member_id <= 0) {
    $_SESSION['error'] = "Invalid member ID.";
    header("Location: sahakari_management.php");
    exit();
}

// Include database connection
require_once 'db_connection.php';

try {
    $db_result = get_db_connection();
    if (isset($db_result['error'])) {
        throw new Exception("Database connection failed: " . $db_result['error']);
    }
    $conn = $db_result['conn'];

    // Start transaction to ensure all deletions happen together
    $conn->begin_transaction();

    // First, verify the member belongs to the current user's mahal
    $stmt = $conn->prepare("SELECT id, head_name FROM sahakari_members WHERE id = ? AND mahal_id = ?");
    $stmt->bind_param("ii", $member_id, $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $member = $result->fetch_assoc();
    $stmt->close();

    if (!$member) {
        throw new Exception("Sahakari member not found or access denied.");
    }

    $member_name = $member['head_name'];

    // Step 1: Delete family member documents
    $stmt = $conn->prepare("
        DELETE md FROM sahakari_member_documents md
        INNER JOIN sahakari_family_members fm ON md.family_member_id = fm.id
        WHERE fm.member_id = ?
    ");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $stmt->close();

    // Step 2: Delete head documents
    $stmt = $conn->prepare("DELETE FROM sahakari_member_documents WHERE member_id = ?");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $stmt->close();

    // Step 3: Delete family members
    $stmt = $conn->prepare("DELETE FROM sahakari_family_members WHERE member_id = ?");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $stmt->close();

    // Step 4: Remove member reference from transactions (set donor_member_id to NULL)
    $stmt = $conn->prepare("UPDATE transactions SET donor_member_id = NULL WHERE donor_member_id = ?");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $stmt->close();

    // Step 5: Finally delete the main member record
    $stmt = $conn->prepare("DELETE FROM sahakari_members WHERE id = ?");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $affected_rows = $stmt->affected_rows;
    $stmt->close();

    // Commit transaction
    $conn->commit();
    $conn->close();

    if ($affected_rows > 0) {
        $_SESSION['success'] = "Sahakari member '$member_name' and all associated data have been deleted successfully. Their transactions have been preserved but are no longer linked to this member.";
    } else {
        $_SESSION['error'] = "Failed to delete sahakari member.";
    }

} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($conn) && $conn) {
        $conn->rollback();
        $conn->close();
    }
    
    error_log("Error deleting sahakari member: " . $e->getMessage());
    $_SESSION['error'] = "Error deleting sahakari member: " . $e->getMessage();
}

// Redirect back to sahakari management page
header("Location: member-management.php");
exit();
?>