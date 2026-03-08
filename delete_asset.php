<?php
// delete_asset.php
require_once __DIR__ . '/session_bootstrap.php';

// Set header for JSON response
header('Content-Type: application/json');

/* --- auth gate --- */
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include the centralized database connection
require_once 'db_connection.php';

// Get database connection
$db_result = get_db_connection();
if (isset($db_result['error'])) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}
$conn = $db_result['conn'];

// Get logged-in user details
$user_id = $_SESSION['user_id'];
$mahal_id = $user_id;

// Get asset ID from query string
$asset_id = isset($_GET['asset_id']) ? intval($_GET['asset_id']) : 0;

if ($asset_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid asset ID']);
    exit();
}

// Verify asset belongs to this mahal
$stmt = $conn->prepare("SELECT id FROM assets WHERE id = ? AND mahal_id = ?");
$stmt->bind_param("ii", $asset_id, $mahal_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Asset not found or you do not have permission to delete it']);
    exit();
}
$stmt->close();

// Delete related records first (maintain referential integrity)
try {
    // Delete maintenance records
    $stmt = $conn->prepare("DELETE FROM asset_maintenance WHERE asset_id = ?");
    $stmt->bind_param("i", $asset_id);
    $stmt->execute();
    $stmt->close();
    
    // Delete booking records
    $stmt = $conn->prepare("DELETE FROM asset_bookings WHERE asset_id = ?");
    $stmt->bind_param("i", $asset_id);
    $stmt->execute();
    $stmt->close();
    
    // Delete document records
    $stmt = $conn->prepare("DELETE FROM asset_documents WHERE asset_id = ?");
    $stmt->bind_param("i", $asset_id);
    $stmt->execute();
    $stmt->close();
    
    // Delete tax records (renamed from depreciation)
    $stmt = $conn->prepare("DELETE FROM asset_tax WHERE asset_id = ?");
    $stmt->bind_param("i", $asset_id);
    $stmt->execute();
    $stmt->close();
    
    // Finally, delete the asset itself
    $stmt = $conn->prepare("DELETE FROM assets WHERE id = ? AND mahal_id = ?");
    $stmt->bind_param("ii", $asset_id, $mahal_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Asset deleted successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error deleting asset: ' . $conn->error]);
    }
    $stmt->close();
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error deleting asset: ' . $e->getMessage()]);
}

$conn->close();
?>