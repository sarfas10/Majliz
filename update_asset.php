<?php
// update_asset.php
require_once __DIR__ . '/session_bootstrap.php';

header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized. Please log in again.'
    ]);
    exit;
}

require_once 'db_connection.php';

// Get DB connection
$db_result = get_db_connection();
if (isset($db_result['error'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $db_result['error']
    ]);
    exit;
}

$conn = $db_result['conn'];
$mahal_id = $_SESSION['user_id']; // same as in asset_management.php

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
    exit;
}

// Basic required fields
$asset_id = isset($_POST['asset_id']) ? (int)$_POST['asset_id'] : 0;
$required_fields = ['asset_name', 'category_id', 'acquisition_date', 'purchase_cost', 'current_value', 'condition_status', 'asset_status'];

foreach ($required_fields as $field) {
    if (!isset($_POST[$field]) || $_POST[$field] === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Missing required field: ' . $field
        ]);
        exit;
    }
}

if ($asset_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid asset ID.'
    ]);
    exit;
}

// Make sure this asset belongs to the logged-in mahal
$check = $conn->prepare("SELECT id FROM assets WHERE id = ? AND mahal_id = ?");
$check->bind_param("ii", $asset_id, $mahal_id);
$check->execute();
$check->store_result();

if ($check->num_rows === 0) {
    $check->close();
    echo json_encode([
        'success' => false,
        'message' => 'Asset not found or you do not have permission to edit it.'
    ]);
    exit;
}
$check->close();

// Sanitize / map input
$asset_name   = trim($_POST['asset_name']);
$category_id  = (int)$_POST['category_id'];
$acq_date     = $_POST['acquisition_date'];
$vendor_donor = isset($_POST['vendor_donor']) ? trim($_POST['vendor_donor']) : '';
$purchase_cost = (float)$_POST['purchase_cost'];
$current_value = isset($_POST['current_value']) && $_POST['current_value'] !== ''
    ? (float)$_POST['current_value']
    : $purchase_cost;

$condition_status = $_POST['condition_status']; // excellent/good/fair/...
$asset_status     = $_POST['asset_status'];     // active/inactive/disposed/lost

$location   = isset($_POST['location']) ? trim($_POST['location']) : '';
$assigned_to = (isset($_POST['assigned_to']) && $_POST['assigned_to'] !== '')
    ? (int)$_POST['assigned_to']
    : null;

$maintenance_frequency = (isset($_POST['maintenance_frequency']) && $_POST['maintenance_frequency'] !== '')
    ? $_POST['maintenance_frequency']
    : null;

$description = isset($_POST['description']) ? $_POST['description'] : '';
$notes       = isset($_POST['notes']) ? $_POST['notes'] : '';

// Build update query
$sql = "UPDATE assets 
        SET name = ?, 
            category_id = ?, 
            acquisition_date = ?, 
            vendor_donor = ?, 
            purchase_cost = ?, 
            current_value = ?, 
            condition_status = ?, 
            status = ?, 
            location = ?, 
            assigned_to = ?, 
            maintenance_frequency = ?, 
            description = ?, 
            notes = ?
        WHERE id = ? AND mahal_id = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Prepare failed: ' . $conn->error
    ]);
    exit;
}

$stmt->bind_param(
    "sissddsssisssii",
    $asset_name,
    $category_id,
    $acq_date,
    $vendor_donor,
    $purchase_cost,
    $current_value,
    $condition_status,
    $asset_status,
    $location,
    $assigned_to,
    $maintenance_frequency,
    $description,
    $notes,
    $asset_id,
    $mahal_id
);

if ($stmt->execute()) {
    // Even if affected_rows is 0, it can just mean no change in values
    echo json_encode([
        'success' => true,
        'message' => 'Asset updated successfully.'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Error updating asset: ' . $stmt->error
    ]);
}

$stmt->close();
$conn->close();
