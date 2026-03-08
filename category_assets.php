<?php
// category_assets.php
require_once __DIR__ . '/session_bootstrap.php';
require_once 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$db_result = get_db_connection();
if (isset($db_result['error'])) {
    die("Database connection failed: " . $db_result['error']);
}
$conn = $db_result['conn'];

$user_id = $_SESSION['user_id'];

// Get user details for sidebar
$stmt = $conn->prepare("SELECT name, address, registration_no, email FROM register WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows == 0) {
    echo "<script>alert('Unable to fetch user details. Please log in again.'); window.location.href='index.php';</script>";
    exit();
}

$stmt->bind_result($user_name, $user_address, $registration_no, $user_email);
$stmt->fetch();
$stmt->close();

// Use the logged-in user's id as mahal_id
$mahal_id = $user_id;

// Create $mahal array for consistency with dashboard
$mahal = [
    'name' => $user_name,
    'address' => $user_address,
    'registration_no' => $registration_no,
    'email' => $user_email
];

// Define logo path
$logo_path = "logo.jpeg";

// Get category details from URL
$category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
$category_name = isset($_GET['category_name']) ? urldecode($_GET['category_name']) : 'Category';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_category':
                // Update category
                $stmt = $conn->prepare("
                    UPDATE asset_categories SET 
                        category_name = ?,
                        description = ?,
                        depreciation_rate = ?,
                        status = ?
                    WHERE id = ? AND mahal_id = ?
                ");
                
                $depreciation_rate = !empty($_POST['depreciation_rate']) ? floatval($_POST['depreciation_rate']) : 10.00;
                
                $stmt->bind_param(
                    "ssdsii",
                    $_POST['category_name'],
                    $_POST['category_description'],
                    $depreciation_rate,
                    $_POST['status'],
                    $category_id,
                    $mahal_id
                );
                
                if ($stmt->execute()) {
                    $success_message = "Category updated successfully!";
                    $category_name = $_POST['category_name']; // Update displayed name
                } else {
                    $error_message = "Failed to update category.";
                }
                $stmt->close();
                break;
                
            case 'delete_category':
                // Delete category and all its assets
                // First, delete all assets in this category
                $conn->query("DELETE FROM assets WHERE category_id = $category_id AND mahal_id = $mahal_id");
                
                // Then delete the category
                $stmt = $conn->prepare("DELETE FROM asset_categories WHERE id = ? AND mahal_id = ?");
                $stmt->bind_param("ii", $category_id, $mahal_id);
                
                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: asset_management.php?message=Category+deleted+successfully");
                    exit();
                } else {
                    $error_message = "Failed to delete category.";
                }
                $stmt->close();
                break;
                
            case 'update_asset':
                // Update asset
                $asset_id = isset($_POST['asset_id']) ? intval($_POST['asset_id']) : 0;
                
                if ($asset_id > 0) {
                    $stmt = $conn->prepare("
                        UPDATE assets SET 
                            name = ?,
                            description = ?,
                            location = ?,
                            acquisition_date = ?,
                            condition_status = ?,
                            status = ?,
                            updated_at = NOW()
                        WHERE id = ? AND mahal_id = ?
                    ");
                    
                    $stmt->bind_param(
                        "ssssssii",
                        $_POST['asset_name'],
                        $_POST['description'],
                        $_POST['location'],
                        $_POST['acquisition_date'],
                        $_POST['condition_status'],
                        $_POST['status'],
                        $asset_id,
                        $mahal_id
                    );
                    
                    if ($stmt->execute()) {
                        $success_message = "Asset updated successfully!";
                    } else {
                        $error_message = "Failed to update asset.";
                    }
                    $stmt->close();
                }
                break;
                
            case 'delete_asset':
                // Delete asset
                $asset_id = isset($_POST['asset_id']) ? intval($_POST['asset_id']) : 0;
                
                if ($asset_id > 0) {
                    $stmt = $conn->prepare("DELETE FROM assets WHERE id = ? AND mahal_id = ?");
                    $stmt->bind_param("ii", $asset_id, $mahal_id);
                    
                    if ($stmt->execute()) {
                        // Also delete related records
                        $conn->query("DELETE FROM asset_maintenance WHERE asset_id = $asset_id AND mahal_id = $mahal_id");
                        $conn->query("DELETE FROM asset_bookings WHERE asset_id = $asset_id AND mahal_id = $mahal_id");
                        $conn->query("DELETE FROM asset_documents WHERE asset_id = $asset_id AND mahal_id = $mahal_id");
                        $conn->query("DELETE FROM asset_depreciation WHERE asset_id = $asset_id AND mahal_id = $mahal_id");
                        
                        $success_message = "Asset deleted successfully!";
                    } else {
                        $error_message = "Failed to delete asset.";
                    }
                    $stmt->close();
                }
                break;
                
            case 'add_asset':
                // Add new asset
                try {
                    // Generate asset code
                    $stmt = $conn->prepare("SELECT COUNT(*) FROM assets WHERE mahal_id = ? AND category_id = ?");
                    $stmt->bind_param("ii", $mahal_id, $category_id);
                    $stmt->execute();
                    $stmt->bind_result($existing_asset_count);
                    $stmt->fetch();
                    $stmt->close();
                    
                    $next_asset_number = $existing_asset_count + 1;
                    $asset_code = "AST" . str_pad($mahal_id, 3, '0', STR_PAD_LEFT) . 
                                 "CAT" . str_pad($category_id, 3, '0', STR_PAD_LEFT) . 
                                 str_pad($next_asset_number, 4, '0', STR_PAD_LEFT);
                    
                    // Get category depreciation rate
                    $depreciation_rate = 10.00;
                    $stmt = $conn->prepare("SELECT depreciation_rate FROM asset_categories WHERE id = ?");
                    $stmt->bind_param("i", $category_id);
                    $stmt->execute();
                    $stmt->bind_result($fetched_depreciation_rate);
                    if ($stmt->fetch()) {
                        $depreciation_rate = $fetched_depreciation_rate;
                    }
                    $stmt->close();
                    
                    // Calculate current value based on depreciation
                    $purchase_cost = floatval($_POST['purchase_cost']);
                    $acquisition_date = new DateTime($_POST['acquisition_date']);
                    $current_date = new DateTime();
                    
                    // Calculate months difference
                    $months_diff = ($current_date->format('Y') - $acquisition_date->format('Y')) * 12;
                    $months_diff += $current_date->format('n') - $acquisition_date->format('n');
                    
                    // Ensure months_diff is not negative (future acquisition date)
                    $months_diff = max($months_diff, 0);
                    
                    $monthly_depreciation = ($purchase_cost * $depreciation_rate) / (100 * 12);
                    $current_value = max($purchase_cost - ($monthly_depreciation * $months_diff), 0);
                    
                    // Insert asset
                    $stmt = $conn->prepare("
                        INSERT INTO assets (
                            mahal_id, asset_code, name, category_id, description, 
                            location, acquisition_date, vendor_donor, purchase_cost, 
                            current_value, condition_status, maintenance_frequency, 
                            assigned_to, notes, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $assigned_to = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : NULL;
                    $maintenance_frequency = !empty($_POST['maintenance_frequency']) ? $_POST['maintenance_frequency'] : NULL;
                    $description = $_POST['description'] ?? '';
                    $location = $_POST['location'] ?? '';
                    $vendor_donor = $_POST['vendor_donor'] ?? '';
                    $notes = $_POST['notes'] ?? '';
                    
                    $stmt->bind_param(
                        "ississsddsssisi",
                        $mahal_id,
                        $asset_code,
                        $_POST['asset_name'],
                        $category_id,
                        $description,
                        $location,
                        $_POST['acquisition_date'],
                        $vendor_donor,
                        $purchase_cost,
                        $current_value,
                        $_POST['condition_status'],
                        $maintenance_frequency,
                        $assigned_to,
                        $notes,
                        $user_id
                    );
                    
                    if ($stmt->execute()) {
                        $asset_id = $conn->insert_id;
                        $stmt->close();
                        
                        // Log depreciation entry
                        $stmt = $conn->prepare("
                            INSERT INTO asset_depreciation (
                                mahal_id, asset_id, depreciation_date, 
                                previous_value, new_value, depreciation_amount, 
                                depreciation_rate, calculated_by
                            ) VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?)
                        ");
                        
                        $depreciation_amount = $purchase_cost - $current_value;
                        $stmt->bind_param("iiddddi", 
                            $mahal_id, $asset_id, $purchase_cost, 
                            $current_value, $depreciation_amount, 
                            $depreciation_rate, $user_id
                        );
                        $stmt->execute();
                        $stmt->close();
                        
                        $success_message = "Asset added successfully! Asset Code: $asset_code";
                    } else {
                        $error_message = "Failed to add asset: " . $conn->error;
                    }
                    
                } catch(Exception $e) {
                    $error_message = "Error adding asset: " . $e->getMessage();
                }
                break;
        }
    }
}

// Get category details
$category_details = [];
if ($category_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM asset_categories WHERE id = ? AND mahal_id = ?");
    $stmt->bind_param("ii", $category_id, $mahal_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $category_details = $result->fetch_assoc();
    $stmt->close();
    
    if ($category_details) {
        $category_name = $category_details['category_name'];
    }
}

// Get assets for this category
$category_assets = [];
if ($category_id > 0) {
    $stmt = $conn->prepare("
        SELECT 
            a.*, 
            s.name as staff_name,
            ac.category_name
        FROM assets a 
        LEFT JOIN staff s ON a.assigned_to = s.id 
        LEFT JOIN asset_categories ac ON a.category_id = ac.id
        WHERE a.category_id = ? AND a.mahal_id = ? 
        ORDER BY a.name
    ");
    $stmt->bind_param("ii", $category_id, $mahal_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $category_assets[] = $row;
    }
    $stmt->close();
}

// Get total assets count
$total_assets = count($category_assets);

// Get staff list for dropdown
$staff_list = [];
$stmt = $conn->prepare("SELECT id, name, staff_id FROM staff WHERE mahal_id = ? AND salary_status = 'active' ORDER BY name");
$stmt->bind_param("i", $mahal_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $staff_list[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($category_name); ?> - Assets</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Amiri:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #4a6fa5;
            --primary-dark: #3a5984;
            --primary-light: #6b8cc0;
            --secondary: #6bbaa7;
            --accent: #f18f8f;
            --text: #2c3e50;
            --text-light: #7f8c8d;
            --text-lighter: #bdc3c7;
            --bg: #f8fafc;
            --card: #ffffff;
            --card-alt: #f1f5f9;
            --border: #e2e8f0;
            --success: #27ae60;
            --warning: #f59e0b;
            --error: #e74c3c;
            --info: #3498db;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.12);
            --radius: 16px;
            --radius-sm: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html, body {
            height: 100%;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
        }

        body.no-scroll {
            overflow: hidden;
        }

        #app {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar styles */
        .sidebar {
            width: 288px;
            background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary) 100%);
            border-right: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
            position: fixed;
            inset: 0 auto 0 0;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            z-index: 1000;
            box-shadow: 8px 0 32px rgba(0, 0, 0, 0.15);
        }

        .sidebar.open {
            transform: translateX(0);
        }

        .sidebar-inner {
            padding: 24px;
            display: flex;
            flex-direction: column;
            min-height: 100%;
        }

        .sidebar-close {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.15);
            border: none;
            color: white;
            border-radius: var(--radius-sm);
            padding: 8px;
            cursor: pointer;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            backdrop-filter: blur(10px);
        }

        .sidebar-close:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: rotate(90deg);
        }

        /* Profile block */
        .profile {
            padding: 24px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            text-align: center;
            margin-bottom: 16px;
        }

        .profile-avatar {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary), var(--accent));
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 28px;
            color: white;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
            border: 3px solid rgba(255, 255, 255, 0.2);
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .profile .name {
            color: #fff;
            font-weight: 700;
            font-size: 18px;
            line-height: 1.2;
            margin-bottom: 4px;
        }

        .profile .role {
            color: rgba(255, 255, 255, 0.8);
            font-size: 13px;
            font-weight: 500;
        }

        /* Navigation */
        .menu {
            padding: 16px 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
        }

        .menu-btn {
            appearance: none;
            background: transparent;
            border: none;
            color: rgba(255, 255, 255, 0.8);
            padding: 14px 16px;
            border-radius: var(--radius-sm);
            width: 100%;
            text-align: left;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .menu-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.5s;
        }

        .menu-btn:hover::before {
            left: 100%;
        }

        .menu-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            transform: translateX(4px);
        }

        .menu-btn.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        }

        .menu-btn i {
            width: 20px;
            text-align: center;
            font-size: 16px;
            opacity: 0.9;
        }

        /* Logout button */
        .sidebar-bottom {
            margin-top: auto;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.15);
        }

        .logout-btn {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border: none;
            color: white;
            padding: 14px 20px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: var(--transition);
            box-shadow: 0 4px 16px rgba(239, 68, 68, 0.3);
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
        }

        /* Overlay for mobile */
        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
            z-index: 999;
            backdrop-filter: blur(4px);
        }

        .sidebar-overlay.show {
            opacity: 1;
            pointer-events: auto;
        }

        /* Main layout */
        .main {
            margin-left: 0;
            min-height: 100vh;
            background: var(--bg);
            display: flex;
            flex-direction: column;
            width: 100%;
        }

        /* Logo Row */
        .logo-row {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
            padding: 20px;
            background: linear-gradient(135deg, var(--card), var(--card-alt));
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }

        .logo-row::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 6px;
            height: 100%;
            background: linear-gradient(135deg, var(--primary), var(--secondary), var(--accent));
        }

        .floating-menu-btn {
            background: var(--card);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
            transition: var(--transition);
            box-shadow: var(--shadow);
            flex-shrink: 0;
            z-index: 2;
        }

        .floating-menu-btn:hover {
            background: var(--card-alt);
            transform: translateY(-1px);
            box-shadow: var(--shadow-lg);
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 16px;
            flex: 1;
        }

        .logo-row img {
            width: 64px;
            height: 64px;
            object-fit: contain;
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow);
            border: 2px solid var(--border);
            background: white;
            padding: 4px;
        }

        .name-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .name-ar {
            font-size: 24px;
            font-weight: 800;
            font-family: 'Amiri', 'Scheherazade New', serif;
            color: var(--text);
            background: linear-gradient(135deg, var(--primary), var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1.2;
        }

        .name-subtitle {
            font-size: 14px;
            color: var(--text-light);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .name-subtitle i {
            color: var(--secondary);
            font-size: 12px;
        }

        /* Container styles */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            width: 100%;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px;
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .header-left h1 {
            color: var(--primary);
            margin-bottom: 5px;
            font-size: 24px;
        }

        .header-left .subtitle {
            color: var(--text-light);
            font-size: 14px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(74, 111, 165, 0.3);
        }

        .btn-secondary {
            background: var(--border);
            color: var(--text);
        }

        .btn-secondary:hover {
            background: #e2e8f0;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--error), #c0392b);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .main-card {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 30px;
            width: 100%;
        }

        .card-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
        }

        .card-body {
            padding: 20px;
        }

        .assets-count {
            font-size: 14px;
            background: rgba(255, 255, 255, 0.2);
            padding: 4px 12px;
            border-radius: 20px;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 1;
        }

        .modal-content {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(-20px);
            transition: transform 0.3s ease;
        }

        .modal.active .modal-content {
            transform: translateY(0);
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: var(--radius) var(--radius) 0 0;
        }

        .modal-header h3 {
            font-size: 20px;
            font-weight: 600;
        }

        .modal-body {
            padding: 25px;
        }

        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.3s;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text);
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
        }

        /* Search Bar */
        .search-section {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border);
            background: var(--bg);
        }

        .search-container {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-input-group {
            position: relative;
            flex: 1;
            min-width: 250px;
        }

        .search-input {
            width: 100%;
            padding: 10px 15px 10px 40px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
        }

        .clear-btn {
            background: #f1f5f9;
            color: var(--text);
            border: 1px solid var(--border);
            padding: 8px 15px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
        }

        .clear-btn:hover {
            background: #e2e8f0;
        }

        /* Table Styles */
        .table-container {
            padding: 20px;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table th, table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        table th {
            background: var(--card-alt);
            font-weight: 600;
            color: var(--text);
            font-size: 14px;
        }

        table tr:hover {
            background: var(--card-alt);
        }

        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            display: inline-block;
        }

        .badge-success { background: rgba(39, 174, 96, 0.15); color: var(--success); }
        .badge-warning { background: rgba(245, 158, 11, 0.15); color: var(--warning); }
        .badge-danger { background: rgba(231, 76, 60, 0.15); color: var(--error); }
        .badge-info { background: rgba(107, 140, 192, 0.15); color: var(--primary-light); }
        .badge-secondary { background: #f1f5f9; color: #64748b; }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.3;
        }

        .asset-code {
            font-family: monospace;
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .edit-btn {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
            min-width: 60px;
        }

        .edit-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(74, 111, 165, 0.2);
        }

        .delete-btn {
            background: linear-gradient(135deg, var(--error), #c0392b);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
            min-width: 70px;
        }

        .delete-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(231, 76, 60, 0.2);
        }

        /* Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }

        .alert-success {
            background: rgba(39, 174, 96, 0.15);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.15);
            color: var(--error);
            border-left: 4px solid var(--error);
        }

        @keyframes slideIn {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        /* No results message */
        .no-results {
            display: none;
            text-align: center;
            padding: 30px;
            color: var(--text-light);
            border-top: 1px solid var(--border);
        }

        .no-results i {
            font-size: 36px;
            margin-bottom: 10px;
            opacity: 0.3;
        }

        /* Category Info Grid */
        .category-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .info-section h4 {
            color: var(--primary);
            margin-bottom: 10px;
            font-size: 16px;
        }

        .info-details {
            font-size: 14px;
        }

        .info-details p {
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
        }

        .info-details strong {
            color: var(--text);
        }

        .info-details span {
            color: var(--text-light);
        }

        /* Clickable table rows */
        .clickable-row {
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .clickable-row:hover {
            background: #f0f7ff !important;
            box-shadow: inset 0 0 0 1px var(--primary-light);
            transform: translateY(-1px);
        }

        .clickable-row td:first-child {
            border-left: 3px solid transparent;
        }

        .clickable-row:hover td:first-child {
            border-left-color: var(--primary);
        }

        /* Responsive */
        @media (min-width: 1024px) {
            .sidebar {
                transform: none;
            }
            .sidebar-overlay {
                display: none;
            }
            .main {
                margin-left: 288px;
                width: calc(100% - 288px);
            }
            .floating-menu-btn {
                display: none !important;
            }
            .sidebar-close {
                display: none;
            }
        }

        .asset-row {
            cursor: pointer;
            position: relative;
        }

        .asset-row:hover {
            background: #f0f7ff !important;
        }

        .asset-row td:first-child {
            border-left: 3px solid transparent;
        }

        .asset-row:hover td:first-child {
            border-left-color: var(--primary);
        }

        .action-buttons {
            position: relative;
            z-index: 10;
        }

        .action-buttons button {
            pointer-events: auto;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .search-container {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-input-group {
                min-width: 100%;
            }
            
            .clear-btn {
                width: 100%;
                justify-content: center;
            }
            
            table {
                font-size: 13px;
            }
            
            table th, table td {
                padding: 8px;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 5px;
            }
            
            .edit-btn, .delete-btn {
                width: 100%;
                justify-content: center;
            }
            
            .modal-content {
                width: 95%;
                max-height: 85vh;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .category-info-grid {
                grid-template-columns: 1fr;
            }
            
            .logo-row {
                flex-direction: column;
                text-align: center;
                gap: 16px;
            }
            
            .logo-container {
                flex-direction: column;
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 10px;
            }
            
            .header {
                padding: 15px;
            }
        }
        
        /* Card header layout */
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            flex-wrap: wrap;
            gap: 15px;
        }

        .card-header-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header-title h3 {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
        }

        .card-header-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        @media (max-width: 768px) {
            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .card-header-actions {
                width: 100%;
                justify-content: space-between;
            }
        }

        @media (max-width: 480px) {
            .card-header-actions {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .assets-count {
                align-self: flex-end;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay" hidden></div>

    <div id="app">
        <aside class="sidebar" id="sidebar" aria-hidden="true" aria-label="Main navigation">
            <button class="sidebar-close" id="sidebarClose" aria-label="Close menu" type="button">
                <i class="fas fa-times"></i>
            </button>
            <div class="sidebar-inner">
                <!-- Profile -->
                <div class="profile">
                    <div class="profile-avatar">
                        <img src="<?php echo htmlspecialchars($logo_path); ?>" alt="<?php echo htmlspecialchars($mahal['name']); ?> Logo" 
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <i class="fas fa-mosque" style="display: none;"></i>
                    </div>
                    <div class="name">Majliz</div>
                    <div class="role">Administrator</div>
                </div>

                <!-- Navigation -->
                <nav class="menu" role="menu">
                    <button class="menu-btn" type="button" onclick="window.location.href='dashboard.php'">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Admin Panel</span>
                    </button>

                    <button class="menu-btn" type="button" onclick="window.location.href='finance-tracking.php'">
                        <i class="fas fa-chart-line"></i>
                        <span>Finance Tracking</span>
                    </button>

                    <button class="menu-btn" type="button" onclick="window.location.href='member-management.php'">
                        <i class="fas fa-users"></i>
                        <span>Member Management</span>
                    </button>

                    <button class="menu-btn" type="button" onclick="window.location.href='staff-management.php'">
                        <i class="fas fa-user-tie"></i>
                        <span>Staff Management</span>
                    </button>

                    <button class="menu-btn active" type="button" onclick="window.location.href='asset_management.php'">
                        <i class="fas fa-boxes"></i>
                        <span>Asset Management</span>
                    </button>

                    <button class="menu-btn" type="button">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Academics</span>
                    </button>

                    <button class="menu-btn" type="button" onclick="window.location.href='certificate.php'">
                        <i class="fas fa-certificate"></i>
                        <span>Certificate Management</span>
                    </button>

                    <button class="menu-btn" type="button" onclick="window.location.href='mahal_profile.php'">
                        <i class="fas fa-building"></i>
                        <span>Mahal Profile</span>
                    </button>
                </nav>

                <div class="sidebar-bottom">
                    <form action="logout.php" method="post" style="margin:0">
                        <button type="submit" class="logout-btn">
                            <i class="fas fa-sign-out-alt"></i>
                            Logout
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <main class="main" id="main">
            <div class="container">
                <!-- Messages -->
                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <span><?php echo $success_message; ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo $error_message; ?></span>
                    </div>
                <?php endif; ?>

                <!-- Header -->
                <div class="header">
                    <div class="header-left">
                        <h1><i class="fas fa-folder"></i> <?php echo htmlspecialchars($category_name); ?></h1>
                        <div class="subtitle">
                            Category Assets Management
                        </div>
                    </div>
                    <div>
                        <a href="asset_management.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Assets
                        </a>
                        <button class="btn btn-primary" onclick="openAddAssetModal()" style="margin-left: 10px;">
                            <i class="fas fa-plus"></i> Add Asset
                        </button>
                    </div>
                </div>

                <!-- Category Information (First) -->
                <div class="main-card">
                    <div class="card-header">
                        <h3><i class="fas fa-info-circle"></i> Category Information</h3>
                        <div style="display: flex; gap: 10px;">
                            <button class="btn btn-warning btn-sm" onclick="openCategoryModal()" id="editCategoryBtn">
                                <i class="fas fa-edit"></i> Edit Category
                            </button>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this category and ALL its assets? This action cannot be undone!')" style="display: inline;">
                                <input type="hidden" name="action" value="delete_category">
                                <button type="submit" class="btn btn-danger btn-sm">
                                    <i class="fas fa-trash"></i> Delete Category
                                </button>
                            </form>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="category-info-grid">
                            <div class="info-section">
                                <h4>Basic Details</h4>
                                <div class="info-details">
                                    <p>
                                        <strong>Category Name:</strong>
                                        <span><?php echo htmlspecialchars($category_name); ?></span>
                                    </p>
                                    <?php if (!empty($category_details['depreciation_rate'])): ?>
                                    <p>
                                        <strong>Depreciation Rate:</strong>
                                        <span><?php echo $category_details['depreciation_rate']; ?>% per year</span>
                                    </p>
                                    <?php endif; ?>
                                    <p>
                                        <strong>Status:</strong>
                                        <span><span class="badge badge-success"><?php echo ucfirst($category_details['status'] ?? 'active'); ?></span></span>
                                    </p>
                                    <?php if (!empty($category_details['created_at'])): ?>
                                    <p>
                                        <strong>Created:</strong>
                                        <span><?php echo date('M j, Y', strtotime($category_details['created_at'])); ?></span>
                                    </p>
                                    <?php endif; ?>
                                    <p>
                                        <strong>Total Assets:</strong>
                                        <span><strong><?php echo $total_assets; ?></strong> asset<?php echo $total_assets != 1 ? 's' : ''; ?></span>
                                    </p>
                                </div>
                            </div>
                            
                            <?php if (!empty($category_details['description'])): ?>
                            <div class="info-section">
                                <h4>Description</h4>
                                <p style="font-size: 14px; color: var(--text);">
                                    <?php echo nl2br(htmlspecialchars($category_details['description'])); ?>
                                </p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Assets List (Second) -->
                <div class="main-card">
                    <div class="card-header">
                        <div class="card-header-title">
                            <h3><i class="fas fa-list"></i> Assets in <?php echo htmlspecialchars($category_name); ?></h3>
                        </div>
                        <div class="card-header-actions">
                         
                            <div class="assets-count">
                                <?php echo $total_assets; ?> Asset<?php echo $total_assets != 1 ? 's' : ''; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Search Section -->
                    <div class="search-section">
                        <div class="search-container">
                            <div class="search-input-group">
                                <input type="text" id="searchInput" class="search-input" 
                                       placeholder="Search assets by name, code, location, or description...">
                                <i class="fas fa-search search-icon"></i>
                            </div>
                            <button class="clear-btn" onclick="clearSearch()">
                                <i class="fas fa-redo"></i> Clear Search
                            </button>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <?php if ($total_assets > 0): ?>
                            <div class="table-container">
                                <table id="assetsTable">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>Name</th>
                                            <th>Description</th>
                                            <th>Location</th>
                                            <th>Purchase Date</th>
                                            <th>Condition</th>
                                            <th>Status</th>
                                          
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($category_assets as $asset): ?>
                                            <?php
                                            // Condition badge
                                            $condition_badge = '';
                                            switch ($asset['condition_status']) {
                                                case 'excellent': $condition_badge = 'badge-success'; break;
                                                case 'good': $condition_badge = 'badge-success'; break;
                                                case 'fair': $condition_badge = 'badge-warning'; break;
                                                case 'needs_repair': $condition_badge = 'badge-danger'; break;
                                                case 'out_of_service': $condition_badge = 'badge-secondary'; break;
                                            }
                                            
                                            // Status badge
                                            $status_badge = '';
                                            switch ($asset['status']) {
                                                case 'active': $status_badge = 'badge-success'; break;
                                                case 'inactive': $status_badge = 'badge-warning'; break;
                                                case 'disposed': $status_badge = 'badge-secondary'; break;
                                                case 'lost': $status_badge = 'badge-danger'; break;
                                            }
                                            ?>
                                            <tr class="asset-row clickable-row" 
                                                data-asset-id="<?php echo $asset['id']; ?>"
                                                data-asset-name="<?php echo htmlspecialchars($asset['name']); ?>"
                                                data-asset-code="<?php echo htmlspecialchars($asset['asset_code']); ?>"
                                                data-asset-description="<?php echo htmlspecialchars($asset['description'] ?? ''); ?>"
                                                data-asset-location="<?php echo htmlspecialchars($asset['location'] ?? ''); ?>"
                                                data-asset-acquisition="<?php echo $asset['acquisition_date']; ?>"
                                                data-asset-condition="<?php echo $asset['condition_status']; ?>"
                                                data-asset-status="<?php echo $asset['status']; ?>"
                                                onclick="navigateToAsset(event, <?php echo $asset['id']; ?>)">
                                                <td>
                                                    <span class="asset-code"><?php echo htmlspecialchars($asset['asset_code']); ?></span>
                                                </td>
                                                <td style="font-weight: 500;">
                                                    <span style="color: var(--primary); font-weight: 600;">
                                                        <?php echo htmlspecialchars($asset['name']); ?>
                                                        <i class="fas fa-external-link-alt" style="margin-left: 5px; font-size: 12px; opacity: 0.7;"></i>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($asset['description'])): ?>
                                                        <span class="asset-description" style="font-size: 13px; color: var(--text-light);">
                                                            <?php echo htmlspecialchars(substr($asset['description'], 0, 50)); ?>
                                                            <?php if (strlen($asset['description']) > 50): ?>...<?php endif; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="color: var(--text-light); font-size: 13px;">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="asset-location">
                                                    <?php if (!empty($asset['location'])): ?>
                                                        <span style="font-size: 13px;"><?php echo htmlspecialchars($asset['location']); ?></span>
                                                    <?php else: ?>
                                                        <span style="color: var(--text-light); font-size: 13px;">Not specified</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($asset['acquisition_date'])); ?></td>
                                                <td>
                                                    <span class="badge <?php echo $condition_badge; ?> asset-condition">
                                                        <?php echo ucfirst(str_replace('_', ' ', $asset['condition_status'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $status_badge; ?> asset-status">
                                                        <?php echo ucfirst($asset['status']); ?>
                                                    </span>
                                                </td>

                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                
                                <!-- No results message -->
                                <div id="noResults" class="no-results">
                                    <i class="fas fa-search"></i>
                                    <h4>No assets found</h4>
                                    <p>Try adjusting your search terms</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-box-open"></i>
                                <p>No assets found in this category</p>
                                <button class="btn btn-primary" onclick="openAddAssetModal()" style="margin-top: 15px;">
                                    <i class="fas fa-plus"></i> Add Your First Asset
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Category Edit Modal -->
    <div id="categoryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Category</h3>
                <button class="modal-close" onclick="closeCategoryModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="categoryEditForm">
                    <input type="hidden" name="action" value="update_category">
                    
                    <div class="form-group">
                        <label for="edit_category_name">Category Name *</label>
                        <input type="text" id="edit_category_name" name="category_name" class="form-control" 
                               value="<?php echo htmlspecialchars($category_name); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="edit_category_description">Description</label>
                        <textarea id="edit_category_description" name="category_description" class="form-control" 
                                  rows="4" placeholder="Category description"><?php echo htmlspecialchars($category_details['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_depreciation_rate">Annual Depreciation Rate (%)</label>
                            <input type="number" id="edit_depreciation_rate" name="depreciation_rate" class="form-control" 
                                   step="0.01" min="0" max="100" value="<?php echo $category_details['depreciation_rate'] ?? '10.00'; ?>">
                        </div>

                        <div class="form-group">
                            <label for="edit_category_status">Status</label>
                            <select id="edit_category_status" name="status" class="form-control">
                                <option value="active" <?php echo ($category_details['status'] ?? 'active') == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($category_details['status'] ?? 'active') == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeCategoryModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Asset Edit Modal -->
    <div id="assetModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Asset</h3>
                <button class="modal-close" onclick="closeAssetModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="assetEditForm">
                    <input type="hidden" name="action" value="update_asset">
                    <input type="hidden" id="edit_asset_id" name="asset_id" value="">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_asset_name">Asset Name *</label>
                            <input type="text" id="edit_asset_name" name="asset_name" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_asset_code">Asset Code</label>
                            <input type="text" id="edit_asset_code" class="form-control" disabled>
                            <small style="color: var(--text-light); font-size: 12px;">Asset code cannot be changed</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="edit_description">Description</label>
                        <textarea id="edit_description" name="description" class="form-control" rows="3"></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_location">Location</label>
                            <input type="text" id="edit_location" name="location" class="form-control" 
                                   placeholder="e.g., Room 101">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_acquisition_date">Acquisition Date</label>
                            <input type="date" id="edit_acquisition_date" name="acquisition_date" class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_condition_status">Condition *</label>
                            <select id="edit_condition_status" name="condition_status" class="form-control" required>
                                <option value="excellent">Excellent</option>
                                <option value="good">Good</option>
                                <option value="fair">Fair</option>
                                <option value="needs_repair">Needs Repair</option>
                                <option value="out_of_service">Out of Service</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_status">Status *</label>
                            <select id="edit_status" name="status" class="form-control" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="disposed">Disposed</option>
                                <option value="lost">Lost</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeAssetModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Asset Modal -->
    <div id="addAssetModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Add New Asset</h3>
                <button class="modal-close" onclick="closeAddAssetModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addAssetForm">
                    <input type="hidden" name="action" value="add_asset">
                    
                    <div class="form-group">
                        <label for="asset_name">Asset Name *</label>
                        <input type="text" id="asset_name" name="asset_name" class="form-control" required placeholder="e.g., Projector, Chair, Table">
                    </div>

                    <div class="form-group">
                        <label>Category</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($category_name); ?>" disabled>
                        <input type="hidden" name="category_id" value="<?php echo $category_id; ?>">
                        <small style="color: var(--text-light); font-size: 12px;">Asset will be added to <?php echo htmlspecialchars($category_name); ?> category</small>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="acquisition_date">Acquisition/Purchase Date *</label>
                            <input type="date" id="acquisition_date" name="acquisition_date" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="vendor_donor">Vendor/Donor</label>
                            <input type="text" id="vendor_donor" name="vendor_donor" class="form-control" placeholder="Optional">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="purchase_cost">Purchase Cost (₹) *</label>
                            <input type="number" id="purchase_cost" name="purchase_cost" class="form-control" step="0.01" required min="0" placeholder="0.00">
                        </div>

                        <div class="form-group">
                            <label for="condition_status">Current Condition *</label>
                            <select id="condition_status" name="condition_status" class="form-control" required>
                                <option value="">Select Condition</option>
                                <option value="excellent">Excellent</option>
                                <option value="good">Good</option>
                                <option value="fair">Fair</option>
                                <option value="needs_repair">Needs Repair</option>
                                <option value="out_of_service">Out of Service</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="location">Location</label>
                        <input type="text" id="location" name="location" class="form-control" placeholder="e.g., Ground Floor, Room 101">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="assigned_to">Assigned Staff (Optional)</label>
                            <select id="assigned_to" name="assigned_to" class="form-control">
                                <option value="">Select Staff (Optional)</option>
                                <?php foreach ($staff_list as $staff_member): ?>
                                    <option value="<?php echo $staff_member['id']; ?>"><?php echo htmlspecialchars($staff_member['name'] . ' (' . $staff_member['staff_id'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="maintenance_frequency">Maintenance Frequency</label>
                            <select id="maintenance_frequency" name="maintenance_frequency" class="form-control">
                                <option value="">Select Schedule (Optional)</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="semi_annual">Semi-Annual</option>
                                <option value="annual">Annual</option>
                                <option value="as_needed">As Needed</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" class="form-control" rows="3" placeholder="Additional details about the asset"></textarea>
                    </div>

                    <div class="form-group">
                        <label for="notes">Internal Notes</label>
                        <textarea id="notes" name="notes" class="form-control" rows="2" placeholder="Internal notes for administrators"></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeAddAssetModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Add Asset
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Sidebar functionality
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const toggle = document.getElementById('menuToggle');
        const closeBtn = document.getElementById('sidebarClose');

        function openSidebar() {
            sidebar.classList.add('open');
            overlay.classList.add('show');
            overlay.hidden = false;
            document.body.classList.add('no-scroll');
            toggle.setAttribute('aria-expanded', 'true');
            sidebar.setAttribute('aria-hidden', 'false');
        }

        function closeSidebar() {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
            document.body.classList.remove('no-scroll');
            toggle.setAttribute('aria-expanded', 'false');
            sidebar.setAttribute('aria-hidden', 'true');
            setTimeout(() => { overlay.hidden = true; }, 200);
        }

        toggle.addEventListener('click', () => {
            sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
        });

        closeBtn.addEventListener('click', closeSidebar);
        overlay.addEventListener('click', closeSidebar);
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && sidebar.classList.contains('open')) closeSidebar();
        });

        // Category Modal Functions
        function openCategoryModal() {
            const modal = document.getElementById('categoryModal');
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeCategoryModal() {
            const modal = document.getElementById('categoryModal');
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        // Asset Modal Functions
        function openAssetModal(assetId) {
            const row = document.querySelector(`tr[data-asset-id="${assetId}"]`);
            if (!row) return;
            
            document.getElementById('edit_asset_id').value = assetId;
            document.getElementById('edit_asset_name').value = row.dataset.assetName;
            document.getElementById('edit_asset_code').value = row.dataset.assetCode;
            document.getElementById('edit_description').value = row.dataset.assetDescription;
            document.getElementById('edit_location').value = row.dataset.assetLocation;
            document.getElementById('edit_acquisition_date').value = row.dataset.assetAcquisition;
            document.getElementById('edit_condition_status').value = row.dataset.assetCondition;
            document.getElementById('edit_status').value = row.dataset.assetStatus;
            
            const modal = document.getElementById('assetModal');
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeAssetModal() {
            const modal = document.getElementById('assetModal');
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        // Add Asset Modal Functions
        function openAddAssetModal() {
            const modal = document.getElementById('addAssetModal');
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Set default date to today
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('acquisition_date').value = today;
        }

        function closeAddAssetModal() {
            const modal = document.getElementById('addAssetModal');
            modal.classList.remove('active');
            document.body.style.overflow = '';
            // Reset form
            document.getElementById('addAssetForm').reset();
        }

        function navigateToAsset(event, assetId) {
            // Check if the click was on an action button or its parent
            if (event.target.closest('.action-buttons')) {
                return;
            }
            
            // Navigate to asset details page
            window.location.href = 'asset_details.php?id=' + assetId;
        }

        // Delete asset with confirmation
        function deleteAsset(assetId, assetName) {
            if (confirm('Are you sure you want to delete "' + assetName + '"? This action cannot be undone!')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_asset';
                
                const assetIdInput = document.createElement('input');
                assetIdInput.type = 'hidden';
                assetIdInput.name = 'asset_id';
                assetIdInput.value = assetId;
                
                form.appendChild(actionInput);
                form.appendChild(assetIdInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Close modals when clicking outside
        document.addEventListener('click', function(event) {
            const categoryModal = document.getElementById('categoryModal');
            const assetModal = document.getElementById('assetModal');
            const addAssetModal = document.getElementById('addAssetModal');
            
            if (event.target === categoryModal) closeCategoryModal();
            if (event.target === assetModal) closeAssetModal();
            if (event.target === addAssetModal) closeAddAssetModal();
        });

        // Close modals with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeCategoryModal();
                closeAssetModal();
                closeAddAssetModal();
            }
        });

        // Search functionality
        function searchAssets() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('assetsTable');
            const rows = table.getElementsByClassName('asset-row');
            const noResults = document.getElementById('noResults');
            let foundCount = 0;
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const cells = row.getElementsByTagName('td');
                let found = false;
                
                for (let j = 0; j < cells.length - 1; j++) {
                    const cell = cells[j];
                    if (cell) {
                        const text = cell.textContent || cell.innerText;
                        if (text.toLowerCase().includes(filter)) {
                            found = true;
                            break;
                        }
                    }
                }
                
                if (found) {
                    row.style.display = '';
                    foundCount++;
                } else {
                    row.style.display = 'none';
                }
            }
            
            if (foundCount === 0 && rows.length > 0) {
                noResults.style.display = 'block';
            } else {
                noResults.style.display = 'none';
            }
        }
        
        // Clear search
        function clearSearch() {
            document.getElementById('searchInput').value = '';
            searchAssets();
        }
        
        // Initialize search on page load
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('keyup', searchAssets);
            }
            
            // Auto-hide success messages after 5 seconds
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    alert.style.opacity = '0';
                    alert.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => alert.remove(), 500);
                });
            }, 5000);
            
            // Focus on first input when modal opens
            const categoryModal = document.getElementById('categoryModal');
            const assetModal = document.getElementById('assetModal');
            const addAssetModal = document.getElementById('addAssetModal');
            
            categoryModal.addEventListener('transitionend', function() {
                if (categoryModal.classList.contains('active')) {
                    document.getElementById('edit_category_name').focus();
                }
            });
            
            assetModal.addEventListener('transitionend', function() {
                if (assetModal.classList.contains('active')) {
                    document.getElementById('edit_asset_name').focus();
                }
            });
            
            addAssetModal.addEventListener('transitionend', function() {
                if (addAssetModal.classList.contains('active')) {
                    document.getElementById('asset_name').focus();
                }
            });
            
            // Set the active menu item
            document.querySelectorAll('.menu-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            const assetManagementBtn = document.querySelector('.menu-btn[onclick*="asset_management.php"]');
            if (assetManagementBtn) {
                assetManagementBtn.classList.add('active');
            }
        });
    </script>
</body>
</html>