<?php
// delete_member.php
require_once __DIR__ . '/session_bootstrap.php';

/* --- no-cache headers --- */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

/* --- auth gate --- */
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

/* --- basic validation --- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: member-management.php");
    exit();
}

$member_id = isset($_POST['member_id']) ? (int)$_POST['member_id'] : 0;
$csrf_token = $_POST['csrf_token'] ?? '';

if ($member_id <= 0) {
    header("Location: member-management.php?msg=" . urlencode("Invalid member."));
    exit();
}

/* --- CSRF check --- */
if (empty($csrf_token) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
    header("Location: member-management.php?msg=" . urlencode("Security check failed."));
    exit();
}

require_once 'db_connection.php';

try {
    $db_result = get_db_connection();
    if (isset($db_result['error'])) {
        throw new Exception("Database connection failed: " . $db_result['error']);
    }
    $conn = $db_result['conn'];

    // Make sure the member belongs to the logged-in mahal
    $stmt = $conn->prepare("SELECT id FROM members WHERE id = ? AND mahal_id = ?");
    $stmt->bind_param("ii", $member_id, $_SESSION['user_id']);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res->fetch_assoc();
    $stmt->close();

    if (!$exists) {
        throw new Exception("Member not found or access denied.");
    }

    $conn->begin_transaction();

    // 1) Unlink transactions (keep financial history)
    $stmt = $conn->prepare("UPDATE transactions SET donor_member_id = NULL WHERE donor_member_id = ?");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $stmt->close();

    // 2) Delete member_documents for family members under this head
    // First collect family member IDs
    $family_ids = [];
    $stmt = $conn->prepare("SELECT id FROM family_members WHERE member_id = ?");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $family_ids[] = (int)$row['id'];
    }
    $stmt->close();

    if (!empty($family_ids)) {
        // Delete documents tied to those family members
        $in = implode(',', array_fill(0, count($family_ids), '?'));
        $types = str_repeat('i', count($family_ids));
        $sql = "DELETE FROM member_documents WHERE family_member_id IN ($in)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$family_ids);
        $stmt->execute();
        $stmt->close();

        // Delete the family members themselves
        $sql = "DELETE FROM family_members WHERE id IN ($in)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$family_ids);
        $stmt->execute();
        $stmt->close();
    }

    // 3) Delete head documents (owner_type=head)
    $stmt = $conn->prepare("DELETE FROM member_documents WHERE member_id = ? AND (owner_type = 'head' OR family_member_id IS NULL)");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $stmt->close();

    // 4) Finally delete the member
    $stmt = $conn->prepare("DELETE FROM members WHERE id = ? AND mahal_id = ?");
    $stmt->bind_param("ii", $member_id, $_SESSION['user_id']);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected <= 0) {
        throw new Exception("Delete failed or not permitted.");
    }

    $conn->commit();
    $conn->close();

    // Optional: rotate CSRF token after a sensitive action
    unset($_SESSION['csrf_token']);

    header("Location: member-management.php?msg=" . urlencode("Member deleted successfully."));
    exit();

} catch (Throwable $e) {
    if (isset($conn) && $conn->errno === 0) {
        // try rollback if transaction was started
        try { $conn->rollback(); } catch (Throwable $ignored) {}
    }
    if (isset($conn)) { $conn->close(); }
    error_log("Delete member error: " . $e->getMessage());
    header("Location: member-management.php?msg=" . urlencode("Error: " . $e->getMessage()));
    exit();
}
