
<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// asset_details.php
require_once __DIR__ . '/session_bootstrap.php';

/* --- auth gate --- */
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Include the centralized database connection
require_once 'db_connection.php';

// Get database connection
$db_result = get_db_connection();
if (isset($db_result['error'])) {
    die("Database connection failed: " . $db_result['error']);
}
$conn = $db_result['conn'];

// Get asset ID from URL
$asset_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($asset_id <= 0) {
    header("Location: asset_management.php");
    exit();
}

// Get logged-in user details
$user_id = $_SESSION['user_id'];

// Get user details for header and sidebar
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

// Fetch asset details
$stmt = $conn->prepare("SELECT a.*, ac.category_name, s.name as staff_name FROM assets a 
                       JOIN asset_categories ac ON a.category_id = ac.id 
                       LEFT JOIN staff s ON a.assigned_to = s.id 
                       WHERE a.id = ? AND a.mahal_id = ?");
$stmt->bind_param("ii", $asset_id, $mahal_id);
$stmt->execute();
$result = $stmt->get_result();
$asset = $result->fetch_assoc();
$stmt->close();

if (!$asset) {
    echo "<script>alert('Asset not found or you do not have permission to view it.'); window.location.href='asset_management.php';</script>";
    exit();
}

// Fetch maintenance history for this asset
$stmt = $conn->prepare("SELECT * FROM asset_maintenance WHERE asset_id = ? ORDER BY due_date DESC");
$stmt->bind_param("i", $asset_id);
$stmt->execute();
$maintenance_history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch booking history for this asset
$stmt = $conn->prepare("SELECT * FROM asset_bookings WHERE asset_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->bind_param("i", $asset_id);
$stmt->execute();
$booking_history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch documents for this asset
$stmt = $conn->prepare("SELECT * FROM asset_documents WHERE asset_id = ? AND mahal_id = ? ORDER BY created_at DESC");
$stmt->bind_param("ii", $asset_id, $mahal_id);
$stmt->execute();
$documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch categories for maintenance scheduling
$stmt = $conn->prepare("SELECT id, category_name FROM asset_categories WHERE mahal_id = ? AND status = 'active' ORDER BY category_name");
$stmt->bind_param("i", $mahal_id);
$stmt->execute();
$categories_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch assets for maintenance scheduling
$stmt = $conn->prepare("SELECT id, asset_code, name FROM assets WHERE mahal_id = ? AND status = 'active' ORDER BY name");
$stmt->bind_param("i", $mahal_id);
$stmt->execute();
$assets_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch staff for assignment
$stmt = $conn->prepare("SELECT id, name, staff_id FROM staff WHERE mahal_id = ? AND salary_status = 'active' ORDER BY name");
$stmt->bind_param("i", $mahal_id);
$stmt->execute();
$staff_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Handle maintenance scheduling form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'schedule_maintenance') {
    try {
        $stmt = $conn->prepare("INSERT INTO asset_maintenance (mahal_id, asset_id, maintenance_type, description, scheduled_date, due_date, priority, assigned_to, estimated_cost, created_by) VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?)");
        
        $assigned_to = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : NULL;
        $estimated_cost = !empty($_POST['estimated_cost']) ? floatval($_POST['estimated_cost']) : 0.00;
        $description = $_POST['description'] ?? '';
        
        $stmt->bind_param(
            "iissssidi",
            $mahal_id,
            $asset_id,
            $_POST['maintenance_type'],
            $description,
            $_POST['due_date'],
            $_POST['priority'],
            $assigned_to,
            $estimated_cost,
            $user_id
        );
        
        $stmt->execute();
        $stmt->close();
        
        echo "<script>alert('Maintenance scheduled successfully!');</script>";
    } catch(Exception $e) {
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "');</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($asset['name']); ?> - Asset Details</title>
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

        /* Header for asset details */
        .header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow);
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .asset-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }

        .card {
            background: var(--card);
            border-radius: var(--radius);
            padding: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            border: 1px solid var(--border);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--primary);
        }

        .card-header h3 {
            color: var(--text);
            font-size: 18px;
            font-weight: 600;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(74, 111, 165, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #229954);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(39, 174, 96, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.3);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .info-label {
            font-size: 13px;
            color: var(--text-light);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--text);
        }

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }

        .badge-success {
            background: rgba(39, 174, 96, 0.15);
            color: var(--success);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.15);
            color: var(--warning);
        }

        .badge-danger {
            background: rgba(231, 76, 60, 0.15);
            color: var(--error);
        }

        .badge-info {
            background: rgba(107, 140, 192, 0.15);
            color: var(--primary-light);
        }

        .badge-secondary {
            background: var(--card-alt);
            color: #64748b;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        th {
            background: var(--card-alt);
            font-weight: 600;
            color: var(--text);
        }

        tr:hover {
            background: var(--card-alt);
        }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        .modal {
            background: var(--card);
            border-radius: var(--radius);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border-radius: var(--radius) var(--radius) 0 0;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 20px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: white;
        }

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
            border-radius: var(--radius-sm);
            font-size: 14px;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        /* Document item styles */
        .document-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            background: var(--card-alt);
            border-radius: var(--radius-sm);
            margin-bottom: 8px;
            border-left: 4px solid var(--primary);
            transition: var(--transition);
        }

        .document-item:hover {
            background: var(--card);
            transform: translateX(5px);
        }

        .document-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }

        .document-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }

        .document-details {
            flex: 1;
            min-width: 0;
        }

        .document-name {
            font-weight: 600;
            color: var(--text);
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .document-meta {
            font-size: 12px;
            color: var(--text-light);
        }

        .document-actions {
            display: flex;
            gap: 5px;
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

        @media (max-width: 768px) {
            .asset-grid {
                grid-template-columns: 1fr;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .document-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .document-actions {
                align-self: flex-end;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 10px;
            }
            
            .header {
                padding: 15px;
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
                <!-- Logo Row -->
                <div class="logo-row">
                    <button class="floating-menu-btn" id="menuToggle" aria-controls="sidebar" aria-expanded="false" aria-label="Open menu" type="button">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="logo-container">
                        <div class="name-container">
                            <div class="name-ar"><?php echo htmlspecialchars($mahal['name']); ?></div>
                            <div class="name-subtitle">
                                <i class="fas fa-map-marker-alt"></i>
                                <?php echo htmlspecialchars($mahal['address'] ?? 'Registered Mosque'); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Asset Header -->
                <div class="header">
                    <div>
                        <h1><?php echo htmlspecialchars($asset['name']); ?></h1>
                        <p style="opacity: 0.9; margin-top: 5px;"><?php echo htmlspecialchars($asset['asset_code']); ?> • <?php echo htmlspecialchars($asset['category_name']); ?></p>
                    </div>
                    <button class="btn back-btn" onclick="window.history.back()">
                        <i class="fas fa-arrow-left"></i> Back
                    </button>
                </div>

                <!-- Main Content Grid -->
                <div class="asset-grid">
                    <!-- Left Column: Asset Details -->
                    <div>
                        <!-- Basic Information Card -->
                        <div class="card">
                            <div class="card-header">
                                <h3><i class="fas fa-info-circle"></i> Asset Information</h3>
                                <button class="btn btn-warning" onclick="window.location.href='edit_asset.php?id=<?php echo $asset_id; ?>'">
                                    <i class="fas fa-edit"></i> Edit Asset
                                </button>
                            </div>
                            
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label">Asset Code</span>
                                    <span class="info-value"><?php echo htmlspecialchars($asset['asset_code']); ?></span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Category</span>
                                    <span class="info-value"><?php echo htmlspecialchars($asset['category_name']); ?></span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Purchase Cost</span>
                                    <span class="info-value">₹<?php echo number_format($asset['purchase_cost'], 2); ?></span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Current Value</span>
                                    <span class="info-value">₹<?php echo number_format($asset['current_value'], 2); ?></span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Taxable Amount</span>
                                    <span class="info-value">₹<?php echo number_format($asset['taxable_amount'], 2); ?></span>
                                </div>
                                
                                <!-- ADDED: Rental Status Field -->
                                <div class="info-item">
                                    <span class="info-label">Rental Status</span>
                                    <span class="info-value">
                                        <?php 
                                        $rental_badge = $asset['rental_status'] == 'rental' ? 'badge-info' : 'badge-secondary';
                                        $rental_text = $asset['rental_status'] == 'rental' ? 'Rental' : 'Non-Rental';
                                        ?>
                                        <span class="badge <?php echo $rental_badge; ?>">
                                            <?php echo $rental_text; ?>
                                        </span>
                                    </span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Acquisition Date</span>
                                    <span class="info-value"><?php echo date('F j, Y', strtotime($asset['acquisition_date'])); ?></span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Vendor/Donor</span>
                                    <span class="info-value"><?php echo htmlspecialchars($asset['vendor_donor'] ?: 'Not specified'); ?></span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Condition</span>
                                    <span class="info-value">
                                        <?php 
                                        $condition_badge = '';
                                        switch ($asset['condition_status']) {
                                            case 'excellent': $condition_badge = 'badge-success'; break;
                                            case 'good': $condition_badge = 'badge-success'; break;
                                            case 'fair': $condition_badge = 'badge-warning'; break;
                                            case 'needs_repair': $condition_badge = 'badge-danger'; break;
                                            case 'out_of_service': $condition_badge = 'badge-info'; break;
                                        }
                                        ?>
                                        <span class="badge <?php echo $condition_badge; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $asset['condition_status'])); ?>
                                        </span>
                                    </span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Status</span>
                                    <span class="info-value">
                                        <?php 
                                        $status_badge = '';
                                        switch ($asset['status']) {
                                            case 'active': $status_badge = 'badge-success'; break;
                                            case 'inactive': $status_badge = 'badge-warning'; break;
                                            case 'disposed': $status_badge = 'badge-info'; break;
                                            case 'lost': $status_badge = 'badge-danger'; break;
                                        }
                                        ?>
                                        <span class="badge <?php echo $status_badge; ?>">
                                            <?php echo ucfirst($asset['status']); ?>
                                        </span>
                                    </span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Location</span>
                                    <span class="info-value"><?php echo htmlspecialchars($asset['location'] ?: 'Not specified'); ?></span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Assigned To</span>
                                    <span class="info-value"><?php echo htmlspecialchars($asset['staff_name'] ?: 'Not assigned'); ?></span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Maintenance Frequency</span>
                                    <span class="info-value"><?php echo htmlspecialchars($asset['maintenance_frequency'] ? ucfirst(str_replace('_', ' ', $asset['maintenance_frequency'])) : 'As needed'); ?></span>
                                </div>
                            </div>
                            
                            <?php if ($asset['description']): ?>
                            <div class="info-item" style="margin-top: 15px;">
                                <span class="info-label">Description</span>
                                <div style="background: var(--card-alt); padding: 15px; border-radius: var(--radius-sm); margin-top: 5px;">
                                    <?php echo nl2br(htmlspecialchars($asset['description'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($asset['notes']): ?>
                            <div class="info-item" style="margin-top: 15px;">
                                <span class="info-label">Internal Notes</span>
                                <div style="background: #fff3cd; padding: 15px; border-radius: var(--radius-sm); margin-top: 5px; border: 1px solid #ffeaa7;">
                                    <?php echo nl2br(htmlspecialchars($asset['notes'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Maintenance History Card -->
                        <div class="card">
                            <div class="card-header">
                                <h3><i class="fas fa-history"></i> Maintenance History</h3>
                                <button class="btn btn-success" onclick="openScheduleMaintenanceModal()">
                                    <i class="fas fa-calendar-plus"></i> Schedule Maintenance
                                </button>
                            </div>
                            
                            <?php if (count($maintenance_history) > 0): ?>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Type</th>
                                            <th>Scheduled</th>
                                            <th>Due Date</th>
                                            <th>Status</th>
                                            <th>Cost</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($maintenance_history as $maintenance): ?>
                                            <?php 
                                            $status_badge = '';
                                            switch ($maintenance['status']) {
                                                case 'scheduled': $status_badge = 'badge-info'; break;
                                                case 'in_progress': $status_badge = 'badge-warning'; break;
                                                case 'completed': $status_badge = 'badge-success'; break;
                                                case 'cancelled': $status_badge = 'badge-secondary'; break;
                                            }
                                            ?>
                                            <tr>
                                                <td><?php echo ucfirst(str_replace('_', ' ', $maintenance['maintenance_type'])); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($maintenance['scheduled_date'])); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($maintenance['due_date'])); ?></td>
                                                <td><span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst(str_replace('_', ' ', $maintenance['status'])); ?></span></td>
                                                <td>₹<?php echo number_format($maintenance['actual_cost'] ?: $maintenance['estimated_cost'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-tools"></i>
                                    <p>No maintenance history found for this asset.</p>
                                    <button class="btn btn-success" onclick="openScheduleMaintenanceModal()">
                                        <i class="fas fa-calendar-plus"></i> Schedule First Maintenance
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Right Column: Quick Actions & Booking History -->
                    <div>
                        <!-- Quick Actions Card -->
                        <div class="card">
                            <div class="card-header">
                                <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 10px;">
                                <button class="btn btn-primary" onclick="openModal('bookAssetModal')">
                                    <i class="fas fa-book"></i> Book This Asset
                                </button>
                                <button class="btn btn-success" onclick="openScheduleMaintenanceModal()">
                                    <i class="fas fa-calendar-plus"></i> Schedule Maintenance
                                </button>
                                <button class="btn btn-warning" onclick="window.location.href='edit_asset.php?id=<?php echo $asset_id; ?>'">
                                    <i class="fas fa-edit"></i> Edit Asset Details
                                </button>
                                <button class="btn btn-primary" onclick="window.print()">
                                    <i class="fas fa-print"></i> Print Asset Details
                                </button>
                            </div>
                        </div>

                        <!-- Recent Bookings Card -->
                        <div class="card">
                            <div class="card-header">
                                <h3><i class="fas fa-calendar-check"></i> Recent Bookings</h3>
                            </div>
                            
                            <?php if (count($booking_history) > 0): ?>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Booked By</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($booking_history as $booking): ?>
                                            <?php 
                                            $status_badge = '';
                                            switch ($booking['status']) {
                                                case 'pending': $status_badge = 'badge-warning'; break;
                                                case 'approved': $status_badge = 'badge-success'; break;
                                                case 'rejected': $status_badge = 'badge-danger'; break;
                                                case 'cancelled': $status_badge = 'badge-secondary'; break;
                                            }
                                            ?>
                                            <tr>
                                                <td><?php echo date('M j', strtotime($booking['booking_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($booking['booked_by']); ?></td>
                                                <td><span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst($booking['status']); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state" style="padding: 20px;">
                                    <i class="fas fa-calendar"></i>
                                    <p style="margin-top: 10px;">No bookings found</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Asset Statistics Card -->
                        <div class="card">
                            <div class="card-header">
                                <h3><i class="fas fa-chart-line"></i> Asset Statistics</h3>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 15px;">
                                <div class="info-item">
                                    <span class="info-label">Asset Age</span>
                                    <span class="info-value">
                                        <?php 
                                        $acquisition_date = new DateTime($asset['acquisition_date']);
                                        $current_date = new DateTime();
                                        $interval = $current_date->diff($acquisition_date);
                                        echo $interval->y . ' years, ' . $interval->m . ' months';
                                        ?>
                                    </span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Taxable Amount</span>
                                    <span class="info-value">₹<?php echo number_format($asset['taxable_amount'], 2); ?></span>
                                </div>
                                
                                <!-- ADDED: Rental Status in Statistics -->
                                <div class="info-item">
                                    <span class="info-label">Rental Type</span>
                                    <span class="info-value">
                                        <?php echo $asset['rental_status'] == 'rental' ? 'Rental Asset' : 'Non-Rental Asset'; ?>
                                    </span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Maintenance Count</span>
                                    <span class="info-value"><?php echo count($maintenance_history); ?> records</span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Booking Count</span>
                                    <span class="info-value"><?php echo count($booking_history); ?> recent bookings</span>
                                </div>
                            </div>
                        </div>

                        <!-- Documents Card -->
                        <div class="card">
                            <div class="card-header">
                                <h3><i class="fas fa-file-alt"></i> Asset Documents</h3>
                                <button class="btn btn-primary" onclick="openDocumentUploadModal()">
                                    <i class="fas fa-upload"></i> Upload Document
                                </button>
                            </div>
                            <div class="card-body">
                                <div id="documentsList">
                                    <?php if (count($documents) > 0): ?>
                                        <?php foreach ($documents as $document): ?>
                                            <div class="document-item" id="document-<?php echo $document['id']; ?>">
                                                <div class="document-info">
                                                    <div class="document-icon">
                                                        <i class="fas fa-file"></i>
                                                    </div>
                                                    <div class="document-details">
                                                        <div class="document-name"><?php echo htmlspecialchars($document['document_name']); ?></div>
                                                        <div class="document-meta">
                                                            <?php echo ucfirst(str_replace('_', ' ', $document['document_type'])); ?> • 
                                                            <?php echo date('M j, Y', strtotime($document['created_at'])); ?> • 
                                                            <?php echo round($document['file_size'] / 1024, 2); ?> KB
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="document-actions">
                                                    <a href="<?php echo htmlspecialchars($document['file_path']); ?>" 
                                                       target="_blank" 
                                                       class="btn btn-sm btn-info"
                                                       title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="<?php echo htmlspecialchars($document['file_path']); ?>" 
                                                       download="<?php echo htmlspecialchars($document['document_name']); ?>"
                                                       class="btn btn-sm btn-success"
                                                       title="Download">
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                    <button class="btn btn-sm btn-danger" 
                                                            onclick="deleteDocument(<?php echo $document['id']; ?>, '<?php echo addslashes($document['document_name']); ?>')" 
                                                            title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="empty-state">
                                            <i class="fas fa-folder-open" style="font-size: 48px; margin-bottom: 15px; opacity: 0.3;"></i>
                                            <p>No documents uploaded yet.</p>
                                            <button class="btn btn-primary" onclick="openDocumentUploadModal()">
                                                <i class="fas fa-upload"></i> Upload First Document
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Schedule Maintenance Modal -->
    <div class="modal-overlay" id="scheduleMaintenanceModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-plus"></i> Schedule Maintenance</h3>
                <button class="close-modal" onclick="closeModal('scheduleMaintenanceModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="schedule_maintenance">
                <input type="hidden" name="asset_id" value="<?php echo $asset_id; ?>">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="maintenance_type">Maintenance Type *</label>
                        <select id="maintenance_type" name="maintenance_type" class="form-control" required>
                            <option value="">Select Type</option>
                            <option value="routine_inspection">Routine Inspection</option>
                            <option value="cleaning">Cleaning</option>
                            <option value="repair">Repair</option>
                            <option value="replacement">Replacement</option>
                            <option value="safety_check">Safety Check</option>
                            <option value="servicing">Servicing</option>
                            <option value="calibration">Calibration</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="due_date">Due Date *</label>
                        <input type="date" id="due_date" name="due_date" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="priority">Priority *</label>
                        <select id="priority" name="priority" class="form-control" required>
                            <option value="">Select Priority</option>
                            <option value="low">Low</option>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="assigned_to">Assigned Staff</label>
                        <select id="assigned_to" name="assigned_to" class="form-control">
                            <option value="">Select Staff (Optional)</option>
                            <?php foreach ($staff_list as $staff_member): ?>
                                <option value="<?php echo $staff_member['id']; ?>"><?php echo htmlspecialchars($staff_member['name'] . ' (' . $staff_member['staff_id'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="estimated_cost">Estimated Cost (₹)</label>
                        <input type="number" id="estimated_cost" name="estimated_cost" class="form-control" step="0.01" min="0">
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" class="form-control" rows="3" placeholder="Describe the maintenance work needed"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('scheduleMaintenanceModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-calendar-check"></i> Schedule Maintenance
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Book Asset Modal -->
    <div class="modal-overlay" id="bookAssetModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-book"></i> Book This Asset</h3>
                <button class="close-modal" onclick="closeModal('bookAssetModal')">&times;</button>
            </div>
            <form method="POST" action="book_asset.php">
                <input type="hidden" name="asset_id" value="<?php echo $asset_id; ?>">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="booking_date">Booking Date *</label>
                        <input type="date" id="booking_date" name="booking_date" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="start_time">Start Time *</label>
                        <input type="time" id="start_time" name="start_time" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="end_time">End Time *</label>
                        <input type="time" id="end_time" name="end_time" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="booked_by">Booked By *</label>
                        <input type="text" id="booked_by" name="booked_by" class="form-control" required placeholder="Enter name or member ID">
                    </div>

                    <div class="form-group">
                        <label for="purpose">Purpose *</label>
                        <input type="text" id="purpose" name="purpose" class="form-control" required placeholder="e.g., Wedding, Conference, Class">
                    </div>

                    <div class="form-group">
                        <label for="attendees">Expected Attendees</label>
                        <input type="number" id="attendees" name="attendees" class="form-control" min="1" value="1">
                    </div>

                    <div class="form-group">
                        <label for="requirements">Additional Requirements</label>
                        <textarea id="requirements" name="requirements" class="form-control" rows="3" placeholder="Any special requirements or notes"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('bookAssetModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check"></i> Submit Booking Request
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Document Upload Modal -->
    <div class="modal-overlay" id="documentUploadModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-upload"></i> Upload Document</h3>
                <button class="close-modal" onclick="closeModal('documentUploadModal')">&times;</button>
            </div>
            <form id="documentUploadForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="asset_id" value="<?php echo $asset_id; ?>">
                <input type="hidden" name="action" value="upload_document">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label for="document_type">Document Type *</label>
                        <select id="document_type" name="document_type" class="form-control" required>
                            <option value="">Select Type</option>
                            <option value="purchase_invoice">Purchase Invoice</option>
                            <option value="warranty">Warranty</option>
                            <option value="manual">Manual</option>
                            <option value="insurance">Insurance</option>
                            <option value="permit">Permit</option>
                            <option value="contract">Contract</option>
                            <option value="title_deed">Title Deed</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="document_name">Document Name *</label>
                        <input type="text" id="document_name" name="document_name" class="form-control" required placeholder="e.g., Invoice #12345, User Manual">
                    </div>

                    <div class="form-group">
                        <label for="document_file">Select File *</label>
                        <input type="file" id="document_file" name="document_file" class="form-control" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.txt">
                        <small class="text-muted">Allowed: PDF, DOC, DOCX, JPG, PNG, GIF, TXT (Max: 10MB)</small>
                    </div>

                    <div class="form-group">
                        <label for="expiry_date">Expiry Date (Optional)</label>
                        <input type="date" id="expiry_date" name="expiry_date" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="description">Description (Optional)</label>
                        <textarea id="description" name="description" class="form-control" rows="3" placeholder="Describe this document"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('documentUploadModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="uploadDocumentBtn">
                        <i class="fas fa-upload"></i> Upload Document
                    </button>
                </div>
            </form>
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

        // Modal Functions
        function openScheduleMaintenanceModal() {
            const modal = document.getElementById('scheduleMaintenanceModal');
            if (modal) {
                modal.style.display = 'flex';
                
                // Set default date to 7 days from now
                const today = new Date();
                const nextWeek = new Date(today);
                nextWeek.setDate(today.getDate() + 7);
                const formattedDate = nextWeek.toISOString().split('T')[0];
                
                const dueDateField = document.getElementById('due_date');
                if (dueDateField && !dueDateField.value) {
                    dueDateField.value = formattedDate;
                }
            }
        }

        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'flex';
            }
        }

        function openDocumentUploadModal() {
            const modal = document.getElementById('documentUploadModal');
            if (modal) {
                modal.style.display = 'flex';
                // Reset form
                document.getElementById('documentUploadForm').reset();
                
                // Set default date to today
                const today = new Date().toISOString().split('T')[0];
                const expiryDateField = document.getElementById('expiry_date');
                if (expiryDateField && !expiryDateField.value) {
                    expiryDateField.value = today;
                }
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
                // Reset form if exists
                const form = modal.querySelector('form');
                if (form) form.reset();
            }
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                event.target.style.display = 'none';
                // Reset form if exists
                const form = event.target.querySelector('form');
                if (form) form.reset();
            }
        });

        // Document Upload Handling
        document.getElementById('documentUploadForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const uploadBtn = document.getElementById('uploadDocumentBtn');
            const originalBtnText = uploadBtn.innerHTML;
            
            // Show loading state
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
            
            fetch('asset_documents.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    closeModal('documentUploadModal');
                    loadDocuments(); // Reload documents list
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to upload document. Please try again.');
            })
            .finally(() => {
                // Reset button state
                uploadBtn.disabled = false;
                uploadBtn.innerHTML = originalBtnText;
            });
        });

        // Delete Document Function
        function deleteDocument(documentId, documentName) {
            if (confirm(`Are you sure you want to delete document "${documentName}"? This action cannot be undone.`)) {
                const formData = new FormData();
                formData.append('action', 'delete_document');
                formData.append('document_id', documentId);
                formData.append('asset_id', <?php echo $asset_id; ?>);
                
                fetch('asset_documents.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        // Remove document from list
                        const documentElement = document.getElementById('document-' + documentId);
                        if (documentElement) {
                            documentElement.remove();
                        }
                        // Reload documents if empty
                        loadDocuments();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to delete document. Please try again.');
                });
            }
        }

        // Load Documents Function
        function loadDocuments() {
            fetch(`asset_documents.php?action=get_documents&asset_id=<?php echo $asset_id; ?>`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const documentsList = document.getElementById('documentsList');
                    
                    if (data.documents.length === 0) {
                        documentsList.innerHTML = `
                            <div class="empty-state">
                                <i class="fas fa-folder-open" style="font-size: 48px; margin-bottom: 15px; opacity: 0.3;"></i>
                                <p>No documents uploaded yet.</p>
                                <button class="btn btn-primary" onclick="openDocumentUploadModal()">
                                    <i class="fas fa-upload"></i> Upload First Document
                                </button>
                            </div>
                        `;
                    } else {
                        let html = '';
                        data.documents.forEach(document => {
                            const fileSizeKB = Math.round(document.file_size / 1024 * 100) / 100;
                            const createdDate = new Date(document.created_at).toLocaleDateString('en-US', { 
                                month: 'short', 
                                day: 'numeric', 
                                year: 'numeric' 
                            });
                            
                            html += `
                                <div class="document-item" id="document-${document.id}">
                                    <div class="document-info">
                                        <div class="document-icon">
                                            <i class="fas fa-file"></i>
                                        </div>
                                        <div class="document-details">
                                            <div class="document-name">${escapeHtml(document.document_name)}</div>
                                            <div class="document-meta">
                                                ${document.document_type.replace('_', ' ').toLowerCase().replace(/\b\w/g, l => l.toUpperCase())} • 
                                                ${createdDate} • 
                                                ${fileSizeKB} KB
                                            </div>
                                        </div>
                                    </div>
                                    <div class="document-actions">
                                        <a href="${escapeHtml(document.file_path)}" 
                                           target="_blank" 
                                           class="btn btn-sm btn-info"
                                           title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="${escapeHtml(document.file_path)}" 
                                           download="${escapeHtml(document.document_name)}"
                                           class="btn btn-sm btn-success"
                                           title="Download">
                                            <i class="fas fa-download"></i>
                                        </a>
                                        <button class="btn btn-sm btn-danger" 
                                                onclick="deleteDocument(${document.id}, '${escapeHtml(document.document_name)}')" 
                                                title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            `;
                        });
                        documentsList.innerHTML = html;
                    }
                }
            })
            .catch(error => {
                console.error('Error loading documents:', error);
            });
        }

        // Helper function to escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Set default dates for modals
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            
            const bookingDateField = document.getElementById('booking_date');
            if (bookingDateField && !bookingDateField.value) {
                bookingDateField.value = today;
            }

            // Set default time to current time + 1 hour
            const now = new Date();
            const startTime = now.toTimeString().substr(0, 5);
            const endTime = new Date(now.getTime() + 60 * 60 * 1000).toTimeString().substr(0, 5);
            
            const startTimeField = document.getElementById('start_time');
            const endTimeField = document.getElementById('end_time');
            
            if (startTimeField && !startTimeField.value) {
                startTimeField.value = startTime;
            }
            if (endTimeField && !endTimeField.value) {
                endTimeField.value = endTime;
            }
        });
    </script>
</body>
</html>
