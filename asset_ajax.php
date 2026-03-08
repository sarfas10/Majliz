<?php
//asset_ajax.php
require_once __DIR__ . '/session_bootstrap.php';
require_once 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$db_result = get_db_connection();
if (isset($db_result['error'])) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get database connection - FIXED to handle both PDO and MySQLi
if (isset($db_result['pdo'])) {
    // Using PDO connection
    $pdo = $db_result['pdo'];
    $db_type = 'pdo';
} elseif (isset($db_result['conn'])) {
    // Using MySQLi connection
    $conn = $db_result['conn'];
    $db_type = 'mysqli';
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid database connection']);
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

header('Content-Type: application/json');

try {
    // Get user's mahal_id - handle both PDO and MySQLi
    if ($db_type === 'pdo') {
        $stmt = $pdo->prepare("SELECT mahal_id FROM register WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $mahal_id = $user['mahal_id'] ?? $user_id; // Fallback to user_id if mahal_id not found
    } else {
        $stmt = $conn->prepare("SELECT mahal_id FROM register WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $mahal_id = $user['mahal_id'] ?? $user_id; // Fallback to user_id if mahal_id not found
        $stmt->close();
    }
    
    switch ($action) {
        case 'update_booking_status':
            $booking_id = $_GET['id'] ?? $_POST['id'] ?? 0;
            $status = $_GET['status'] ?? $_POST['status'] ?? '';
            
            if (empty($booking_id) || empty($status)) {
                echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
                break;
            }
            
            if ($db_type === 'pdo') {
                $stmt = $pdo->prepare("UPDATE asset_bookings SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND mahal_id = ?");
                $stmt->execute([$status, $user_id, $booking_id, $mahal_id]);
            } else {
                $stmt = $conn->prepare("UPDATE asset_bookings SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND mahal_id = ?");
                $stmt->bind_param("siii", $status, $user_id, $booking_id, $mahal_id);
                $stmt->execute();
                $stmt->close();
            }
            
            echo json_encode(['success' => true, 'message' => 'Booking status updated']);
            
            // AUTOMATIC: Create Transaction for Approved Booking
            if ($status === 'approved') {
                $b_details = null;
                $b_asset_name = '';
                
                 // Fetch Booking Details
                if ($db_type === 'pdo') {
                    $stmt = $pdo->prepare("SELECT b.*, a.name as asset_name FROM asset_bookings b JOIN assets a ON b.asset_id = a.id WHERE b.id = ?");
                    $stmt->execute([$booking_id]);
                    $b_details = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $stmt = $conn->prepare("SELECT b.*, a.name as asset_name FROM asset_bookings b JOIN assets a ON b.asset_id = a.id WHERE b.id = ?");
                    $stmt->bind_param("i", $booking_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $b_details = $res->fetch_assoc();
                    $stmt->close();
                }

                if ($b_details && $b_details['booking_amount'] > 0) {
                    $b_amount = $b_details['booking_amount'];
                    $b_asset_name = $b_details['asset_name'];
                    
                     // Calculate Receipt (Income) No
                    $trans_date = date('Y-m-d'); // Approved now
                    $trans_year = date('Y', strtotime($trans_date));
                    $rc_val = 0;
                    if ($db_type === 'pdo') {
                         $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND YEAR(transaction_date) = ?");
                         $stmt->execute([$user_id, $trans_year]);
                         $rc_val = $stmt->fetchColumn();
                    } else {
                         $stmt = $conn->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND YEAR(transaction_date) = ?");
                         $stmt->bind_param("ii", $user_id, $trans_year);
                         $stmt->execute();
                         $stmt->bind_result($c);
                         if($stmt->fetch()) $rc_val = $c;
                         $stmt->close();
                    }
                    $receipt_no = "R" . ($rc_val + 1) . "/" . $trans_year; // R for Income

                    $desc = "Asset Rent: " . $b_asset_name . " (Booked by " . $b_details['booked_by'] . ")";
                    
                    // Insert Transaction
                    if ($db_type === 'pdo') {
                        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, transaction_date, type, category, amount, description, payment_mode, asset_booking_id, receipt_no) VALUES (?, ?, 'INCOME', 'ASSET RENT', ?, ?, 'CASH', ?, ?)");
                        $stmt->execute([$user_id, $trans_date, $b_amount, $desc, $booking_id, $receipt_no]);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO transactions (user_id, transaction_date, type, category, amount, description, payment_mode, asset_booking_id, receipt_no) VALUES (?, ?, 'INCOME', 'ASSET RENT', ?, ?, 'CASH', ?, ?)");
                        $stmt->bind_param("isdssis", $user_id, $trans_date, $b_amount, $desc, $booking_id, $receipt_no);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
            break;
            
        case 'update_maintenance_status':
            $maintenance_id = $_GET['id'] ?? $_POST['id'] ?? 0;
            $status = $_GET['status'] ?? $_POST['status'] ?? '';
            
            if (empty($maintenance_id) || empty($status)) {
                echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
                break;
            }
            
            if ($db_type === 'pdo') {
                $sql = "UPDATE asset_maintenance SET status = :status";
                if ($status === 'completed') {
                    $sql .= ", completed_date = CURDATE()";
                }
                $sql .= " WHERE id = :id AND mahal_id = :mahal_id";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'status' => $status,
                    'id' => $maintenance_id,
                    'mahal_id' => $mahal_id
                ]);
            } else {
                $sql = "UPDATE asset_maintenance SET status = ?";
                if ($status === 'completed') {
                    $sql .= ", completed_date = CURDATE()";
                }
                $sql .= " WHERE id = ? AND mahal_id = ?";
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sii", $status, $maintenance_id, $mahal_id);
                $stmt->execute();
                $stmt->close();
            }
            
            echo json_encode(['success' => true, 'message' => 'Maintenance status updated']);
            
            // AUTOMATIC: Create Transaction for Completed Maintenance
            if ($status === 'completed') {
                $m_details = null;
                $m_asset_name = '';
                $m_cost = 0;
                
                // Fetch Maintenance Details
                if ($db_type === 'pdo') {
                    $stmt = $pdo->prepare("SELECT m.*, m.actual_cost, m.estimated_cost, a.name as asset_name FROM asset_maintenance m JOIN assets a ON m.asset_id = a.id WHERE m.id = ?");
                    $stmt->execute([$maintenance_id]);
                    $m_details = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $stmt = $conn->prepare("SELECT m.*, m.actual_cost, m.estimated_cost, a.name as asset_name FROM asset_maintenance m JOIN assets a ON m.asset_id = a.id WHERE m.id = ?");
                    $stmt->bind_param("i", $maintenance_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $m_details = $res->fetch_assoc();
                    $stmt->close();
                }

                if ($m_details) {
                    $m_cost = ($m_details['actual_cost'] > 0) ? $m_details['actual_cost'] : $m_details['estimated_cost'];
                    $m_asset_name = $m_details['asset_name'];
                    
                    if ($m_cost > 0) {
                        // Calculate Receipt (Voucher) No
                        $trans_date = date('Y-m-d'); // Completed now
                        $trans_year = date('Y', strtotime($trans_date));
                        $rc_val = 0;
                        if ($db_type === 'pdo') {
                             $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND YEAR(transaction_date) = ?");
                             $stmt->execute([$user_id, $trans_year]);
                             $rc_val = $stmt->fetchColumn();
                        } else {
                             $stmt = $conn->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND YEAR(transaction_date) = ?");
                             $stmt->bind_param("ii", $user_id, $trans_year);
                             $stmt->execute();
                             $stmt->bind_result($c);
                             if($stmt->fetch()) $rc_val = $c;
                             $stmt->close();
                        }
                        $receipt_no = "V" . ($rc_val + 1) . "/" . $trans_year;

                        $desc = "Asset Maintenance: " . $m_asset_name . " (" . $m_details['maintenance_type'] . ")";
                        $other_detail = "Asset Maint: " . $m_asset_name;

                        // Insert Transaction
                        if ($db_type === 'pdo') {
                            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, transaction_date, type, category, amount, description, other_expense_detail, payment_mode, asset_maintenance_id, receipt_no) VALUES (?, ?, 'EXPENSE', 'OTHER EXPENSES', ?, ?, ?, 'CASH', ?, ?)");
                            $stmt->execute([$user_id, $trans_date, $m_cost, $desc, $other_detail, $maintenance_id, $receipt_no]);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO transactions (user_id, transaction_date, type, category, amount, description, other_expense_detail, payment_mode, asset_maintenance_id, receipt_no) VALUES (?, ?, 'EXPENSE', 'OTHER EXPENSES', ?, ?, ?, 'CASH', ?, ?)");
                            $stmt->bind_param("isdssis", $user_id, $trans_date, $m_cost, $desc, $other_detail, $maintenance_id, $receipt_no);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }
                }
            }
            break;
            
        case 'view_asset':
            $asset_id = $_GET['id'] ?? $_POST['id'] ?? 0;
            
            if (empty($asset_id)) {
                echo json_encode(['success' => false, 'message' => 'Asset ID is required']);
                break;
            }
            
            if ($db_type === 'pdo') {
                $stmt = $pdo->prepare("SELECT a.*, ac.category_name FROM assets a JOIN asset_categories ac ON a.category_id = ac.id WHERE a.id = ? AND a.mahal_id = ?");
                $stmt->execute([$asset_id, $mahal_id]);
                $asset = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $stmt = $conn->prepare("SELECT a.*, ac.category_name FROM assets a JOIN asset_categories ac ON a.category_id = ac.id WHERE a.id = ? AND a.mahal_id = ?");
                $stmt->bind_param("ii", $asset_id, $mahal_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $asset = $result->fetch_assoc();
                $stmt->close();
            }
            
            if ($asset) {
                echo json_encode(['success' => true, 'asset' => $asset]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Asset not found']);
            }
            break;
            
        case 'delete_asset':
            $asset_id = $_GET['id'] ?? $_POST['id'] ?? 0;
            
            if (empty($asset_id)) {
                echo json_encode(['success' => false, 'message' => 'Asset ID is required']);
                break;
            }
            
            // Check if asset exists and belongs to user's mahal
            if ($db_type === 'pdo') {
                $stmt = $pdo->prepare("SELECT id FROM assets WHERE id = ? AND mahal_id = ?");
                $stmt->execute([$asset_id, $mahal_id]);
                $exists = $stmt->fetch();
            } else {
                $stmt = $conn->prepare("SELECT id FROM assets WHERE id = ? AND mahal_id = ?");
                $stmt->bind_param("ii", $asset_id, $mahal_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $exists = $result->fetch_assoc();
                $stmt->close();
            }
            
            if ($exists) {
                // Soft delete by setting status to disposed
                if ($db_type === 'pdo') {
                    $stmt = $pdo->prepare("UPDATE assets SET status = 'disposed', updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$asset_id]);
                } else {
                    $stmt = $conn->prepare("UPDATE assets SET status = 'disposed', updated_at = NOW() WHERE id = ?");
                    $stmt->bind_param("i", $asset_id);
                    $stmt->execute();
                    $stmt->close();
                }
                echo json_encode(['success' => true, 'message' => 'Asset marked as disposed']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Asset not found or access denied']);
            }
            break;
            
        case 'delete_category':
            $category_id = $_GET['id'] ?? $_POST['id'] ?? 0;
            
            if (empty($category_id)) {
                echo json_encode(['success' => false, 'message' => 'Category ID is required']);
                break;
            }
            
            // Check if category exists and belongs to user's mahal
            if ($db_type === 'pdo') {
                $stmt = $pdo->prepare("SELECT id FROM asset_categories WHERE id = ? AND mahal_id = ?");
                $stmt->execute([$category_id, $mahal_id]);
                $exists = $stmt->fetch();
            } else {
                $stmt = $conn->prepare("SELECT id FROM asset_categories WHERE id = ? AND mahal_id = ?");
                $stmt->bind_param("ii", $category_id, $mahal_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $exists = $result->fetch_assoc();
                $stmt->close();
            }
            
            if ($exists) {
                // Check if category has active assets
                if ($db_type === 'pdo') {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE category_id = ? AND status = 'active'");
                    $stmt->execute([$category_id]);
                    $asset_count = $stmt->fetchColumn();
                } else {
                    $stmt = $conn->prepare("SELECT COUNT(*) FROM assets WHERE category_id = ? AND status = 'active'");
                    $stmt->bind_param("i", $category_id);
                    $stmt->execute();
                    $stmt->bind_result($asset_count);
                    $stmt->fetch();
                    $stmt->close();
                }
                
                if ($asset_count > 0) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete category with active assets. Move or delete assets first.']);
                } else {
                    // Soft delete by setting status to inactive
                    if ($db_type === 'pdo') {
                        $stmt = $pdo->prepare("UPDATE asset_categories SET status = 'inactive' WHERE id = ?");
                        $stmt->execute([$category_id]);
                    } else {
                        $stmt = $conn->prepare("UPDATE asset_categories SET status = 'inactive' WHERE id = ?");
                        $stmt->bind_param("i", $category_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                    echo json_encode(['success' => true, 'message' => 'Category deleted']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Category not found or access denied']);
            }
            break;
            
        case 'get_reports_data':
            // Get data for charts
            $data = [];
            
            // Asset value by category
            if ($db_type === 'pdo') {
                $stmt = $pdo->prepare("SELECT ac.category_name, SUM(a.current_value) as total_value FROM assets a JOIN asset_categories ac ON a.category_id = ac.id WHERE a.mahal_id = ? AND a.status = 'active' GROUP BY ac.id");
                $stmt->execute([$mahal_id]);
                $data['category_values'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $conn->prepare("SELECT ac.category_name, SUM(a.current_value) as total_value FROM assets a JOIN asset_categories ac ON a.category_id = ac.id WHERE a.mahal_id = ? AND a.status = 'active' GROUP BY ac.id");
                $stmt->bind_param("i", $mahal_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $data['category_values'] = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
            
            // Maintenance status
            if ($db_type === 'pdo') {
                $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM asset_maintenance WHERE mahal_id = ? GROUP BY status");
                $stmt->execute([$mahal_id]);
                $data['maintenance_stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $conn->prepare("SELECT status, COUNT(*) as count FROM asset_maintenance WHERE mahal_id = ? GROUP BY status");
                $stmt->bind_param("i", $mahal_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $data['maintenance_stats'] = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
            
            // Booking statistics (last 6 months)
            if ($db_type === 'pdo') {
                $stmt = $pdo->prepare("SELECT DATE_FORMAT(booking_date, '%b') as month, COUNT(*) as count FROM asset_bookings WHERE mahal_id = ? AND booking_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY DATE_FORMAT(booking_date, '%Y-%m') ORDER BY booking_date");
                $stmt->execute([$mahal_id]);
                $data['booking_stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $conn->prepare("SELECT DATE_FORMAT(booking_date, '%b') as month, COUNT(*) as count FROM asset_bookings WHERE mahal_id = ? AND booking_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY DATE_FORMAT(booking_date, '%Y-%m') ORDER BY booking_date");
                $stmt->bind_param("i", $mahal_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $data['booking_stats'] = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
            
            // Asset condition
            if ($db_type === 'pdo') {
                $stmt = $pdo->prepare("SELECT condition_status, COUNT(*) as count FROM assets WHERE mahal_id = ? AND status = 'active' GROUP BY condition_status");
                $stmt->execute([$mahal_id]);
                $data['condition_stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $conn->prepare("SELECT condition_status, COUNT(*) as count FROM assets WHERE mahal_id = ? AND status = 'active' GROUP BY condition_status");
                $stmt->bind_param("i", $mahal_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $data['condition_stats'] = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
            
            echo json_encode(['success' => true, 'data' => $data]);
            break;
            
        case 'edit_asset':
            // Handle asset editing
            $asset_id = $_POST['asset_id'] ?? 0;
            $asset_name = $_POST['asset_name'] ?? '';
            $category_id = $_POST['category_id'] ?? 0;
            $acquisition_date = $_POST['acquisition_date'] ?? '';
            $vendor_donor = $_POST['vendor_donor'] ?? '';
            $purchase_cost = $_POST['purchase_cost'] ?? 0;
            $current_value = $_POST['current_value'] ?? 0;
            $condition_status = $_POST['condition_status'] ?? '';
            $asset_status = $_POST['asset_status'] ?? '';
            $location = $_POST['location'] ?? '';
            $assigned_to = $_POST['assigned_to'] ?? NULL;
            $maintenance_frequency = $_POST['maintenance_frequency'] ?? NULL;
            $description = $_POST['description'] ?? '';
            $notes = $_POST['notes'] ?? '';
            
            if (empty($asset_id) || empty($asset_name) || empty($category_id) || empty($acquisition_date) || empty($purchase_cost) || empty($current_value) || empty($condition_status) || empty($asset_status)) {
                echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
                break;
            }
            
            if ($db_type === 'pdo') {
                $stmt = $pdo->prepare("UPDATE assets SET 
                    name = ?, 
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
                    notes = ?, 
                    updated_at = NOW() 
                    WHERE id = ? AND mahal_id = ?");
                
                $stmt->execute([
                    $asset_name,
                    $category_id,
                    $acquisition_date,
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
                ]);
                
                $affected = $stmt->rowCount();
            } else {
                $stmt = $conn->prepare("UPDATE assets SET 
                    name = ?, 
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
                    notes = ?, 
                    updated_at = NOW() 
                    WHERE id = ? AND mahal_id = ?");
                
                $stmt->bind_param(
                    "sisssdssssissii",
                    $asset_name,
                    $category_id,
                    $acquisition_date,
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
                
                $stmt->execute();
                $affected = $conn->affected_rows;
                $stmt->close();
            }
            
            if ($affected > 0) {
                echo json_encode(['success' => true, 'message' => 'Asset updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update asset or asset not found']);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch(Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>