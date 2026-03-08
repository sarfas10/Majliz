<?php
// get_asset_details.php
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

// Fetch asset details
$stmt = $conn->prepare("SELECT a.*, ac.category_name, s.name as staff_name 
                       FROM assets a 
                       JOIN asset_categories ac ON a.category_id = ac.id 
                       LEFT JOIN staff s ON a.assigned_to = s.id 
                       WHERE a.id = ? AND a.mahal_id = ?");
$stmt->bind_param("ii", $asset_id, $mahal_id);
$stmt->execute();
$result = $stmt->get_result();
$asset = $result->fetch_assoc();
$stmt->close();

if (!$asset) {
    echo json_encode(['success' => false, 'message' => 'Asset not found or you do not have permission to view it']);
    exit();
}

// Return success response
echo json_encode(['success' => true, 'asset' => $asset]);
exit();
?>