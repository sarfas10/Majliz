<?php
// maintenance_view_all.php
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
// Use mysqli connection (rename for clarity)
$conn = $db_result['conn'];

// Get logged-in user details
$user_id = $_SESSION['user_id'];

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

// Get all maintenance records
$maintenance_list = [];
$total_upcoming = 0;
$total_in_progress = 0;
$total_completed = 0;

try {
    // Get maintenance count by status
    $stmt = $conn->prepare("SELECT status, COUNT(*) as count FROM asset_maintenance WHERE mahal_id = ? GROUP BY status");
    $stmt->bind_param("i", $mahal_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        switch ($row['status']) {
            case 'scheduled':
                $total_upcoming = $row['count'];
                break;
            case 'in_progress':
                $total_in_progress = $row['count'];
                break;
            case 'completed':
                $total_completed = $row['count'];
                break;
        }
    }
    $stmt->close();
    
    // Get all maintenance records with details
    $stmt = $conn->prepare("
        SELECT m.*, a.name as asset_name, a.asset_code, s.name as staff_name 
        FROM asset_maintenance m 
        JOIN assets a ON m.asset_id = a.id 
        LEFT JOIN staff s ON m.assigned_to = s.id 
        WHERE m.mahal_id = ? 
        ORDER BY m.due_date ASC
    ");
    $stmt->bind_param("i", $mahal_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $maintenance_list[] = $row;
    }
    $stmt->close();
    
} catch(Exception $e) {
    error_log("Maintenance View Error: " . $e->getMessage());
}

// Handle status update if requested
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    try {
        $stmt = $conn->prepare("UPDATE asset_maintenance SET status = ?, completed_date = CASE WHEN ? = 'completed' THEN CURDATE() ELSE completed_date END WHERE id = ? AND mahal_id = ?");
        $stmt->bind_param("ssii", $_POST['status'], $_POST['status'], $_POST['maintenance_id'], $mahal_id);
        $stmt->execute();
        $stmt->close();
        
        echo "<script>alert('Maintenance status updated successfully!'); window.location.href = window.location.href;</script>";
        exit;
    } catch(Exception $e) {
        echo "<script>alert('Error updating status: " . addslashes($e->getMessage()) . "');</script>";
    }
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Maintenance - <?php echo htmlspecialchars($mahal['name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Amiri:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Copy all CSS styles from asset_management.php */
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

        /* Sidebar styles - identical to asset_management.php */
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

        /* Top Row */
        .top-row {
            display: flex;
            gap: 24px;
            padding: 24px;
            align-items: flex-start;
            flex-wrap: wrap;
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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--card);
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow);
            transition: var(--transition);
            border: 1px solid var(--border);
            display: flex;
            align-items: flex-start;
            gap: 16px;
            position: relative;
            overflow: hidden;
            min-height: 120px;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .stat-details {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
            line-height: 1;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-light);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Color variants for stat icons */
        .stat-icon.primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        }

        .stat-icon.success {
            background: linear-gradient(135deg, var(--success), #229954);
        }

        .stat-icon.info {
            background: linear-gradient(135deg, var(--info), #2980b9);
        }

        .stat-icon.warning {
            background: linear-gradient(135deg, var(--warning), #d97706);
        }

        .stat-icon.danger {
            background: linear-gradient(135deg, var(--error), #c0392b);
        }

        /* Card Styles */
        .card {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .card-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
        }

        .card-body {
            padding: 20px;
        }

        /* Table Styles */
        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table th,
        table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        table th {
            background: var(--card-alt);
            font-weight: 600;
            color: var(--text);
        }

        table tr:hover {
            background: var(--card-alt);
        }

        /* Button Styles */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
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

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: white;
        }

        .btn-info {
            background: linear-gradient(135deg, var(--primary-light), #4a8bc6);
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

        .btn-secondary {
            background: linear-gradient(135deg, var(--text-light), #95a5a6);
            color: white;
        }

        /* Badge Styles */
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
            background: rgba(149, 165, 166, 0.15);
            color: var(--text-light);
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 2px solid var(--border);
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .tab {
            padding: 12px 24px;
            cursor: pointer;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            font-weight: 500;
            color: var(--text-light);
            transition: var(--transition);
            white-space: nowrap;
        }

        .tab:hover {
            color: var(--primary);
        }

        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
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
            border-radius: var(--radius-sm);
            font-size: 14px;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }

        /* Status filter chips */
        .status-filters {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .status-chip {
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: var(--transition);
            border: 1px solid var(--border);
            background: var(--card);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .status-chip:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .status-chip.active {
            border-color: var(--primary);
            background: rgba(74, 111, 165, 0.1);
            color: var(--primary);
        }

        /* Back button */
        .back-button {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            margin-bottom: 20px;
            transition: var(--transition);
        }

        .back-button:hover {
            color: var(--primary-dark);
            transform: translateX(-3px);
        }

        /* Empty state */
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

        .text-center {
            text-align: center;
        }

        /* Responsive Design */
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

        @media (max-width: 1023.98px) {
            .main {
                margin-left: 0;
            }
        }

        @media (max-width: 768px) {
            .top-row {
                padding: 16px;
            }
            .tabs {
                overflow-x: auto;
            }
            .table-container {
                font-size: 14px;
            }
            table th,
            table td {
                padding: 8px 10px;
            }
        }

        @media (max-width: 480px) {
            .card-header {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
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
            .status-filters {
                justify-content: center;
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

                <!-- Navigation - identical to asset_management.php -->
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
            <!-- Logo Row -->
           

            <!-- Main Content -->
            <section class="top-row">
                <div class="main-left">
                    <!-- Back Button -->
                    <a href="asset_management.php" class="back-button">
                        <i class="fas fa-arrow-left"></i>
                        Back to Asset Management
                    </a>

                    <!-- Page Title and Stats -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-tools"></i> Maintenance Management</h3>
                            <div class="status-filters">
                                <span class="badge badge-info">Total: <?php echo count($maintenance_list); ?> records</span>
                                <span class="badge badge-warning">Upcoming: <?php echo $total_upcoming; ?></span>
                                <span class="badge badge-info">In Progress: <?php echo $total_in_progress; ?></span>
                                <span class="badge badge-success">Completed: <?php echo $total_completed; ?></span>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- Tabs for filtering -->
                            <div class="tabs">
                                <button class="tab active" onclick="filterMaintenance('all')">All Maintenance</button>
                                <button class="tab" onclick="filterMaintenance('scheduled')">Upcoming</button>
                                <button class="tab" onclick="filterMaintenance('in_progress')">In Progress</button>
                                <button class="tab" onclick="filterMaintenance('completed')">Completed</button>
                                <button class="tab" onclick="filterMaintenance('cancelled')">Cancelled</button>
                            </div>

                            <!-- Search and Filter -->
                            <div style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap;">
                                <div style="flex: 1; min-width: 200px;">
                                    <input type="text" id="searchMaintenance" class="form-control" placeholder="Search maintenance records..." onkeyup="searchMaintenance()">
                                </div>
                                <div style="min-width: 150px;">
                                    <select id="priorityFilter" class="form-control" onchange="filterMaintenanceByPriority()">
                                        <option value="">All Priorities</option>
                                        <option value="low">Low</option>
                                        <option value="normal">Normal</option>
                                        <option value="high">High</option>
                                        <option value="critical">Critical</option>
                                    </select>
                                </div>
                                <a href="asset_management.php#maintenance-section" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> Schedule New
                                </a>
                            </div>

                            <!-- Maintenance Table -->
                            <div class="table-container">
                                <table id="maintenanceTable">
                                    <thead>
                                        <tr>
                                            <th>Asset</th>
                                            <th>Type</th>
                                            <th>Scheduled</th>
                                            <th>Due Date</th>
                                            <th>Priority</th>
                                            <th>Assigned To</th>
                                            <th>Cost</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($maintenance_list) > 0): ?>
                                            <?php foreach ($maintenance_list as $maintenance): ?>
                                                <?php
                                                $priority_badge = '';
                                                switch ($maintenance['priority']) {
                                                    case 'low': $priority_badge = 'badge-info'; break;
                                                    case 'normal': $priority_badge = 'badge-info'; break;
                                                    case 'high': $priority_badge = 'badge-warning'; break;
                                                    case 'critical': $priority_badge = 'badge-danger'; break;
                                                }
                                                
                                                $status_badge = '';
                                                switch ($maintenance['status']) {
                                                    case 'scheduled': $status_badge = 'badge-info'; break;
                                                    case 'in_progress': $status_badge = 'badge-warning'; break;
                                                    case 'completed': $status_badge = 'badge-success'; break;
                                                    case 'cancelled': $status_badge = 'badge-secondary'; break;
                                                }
                                                
                                                $staff_name = $maintenance['staff_name'] ?: 'Not Assigned';
                                                $cost = $maintenance['actual_cost'] > 0 ? $maintenance['actual_cost'] : $maintenance['estimated_cost'];
                                                $isOverdue = $maintenance['due_date'] < date('Y-m-d') && $maintenance['status'] !== 'completed' && $maintenance['status'] !== 'cancelled';
                                                ?>
                                                <tr class="maintenance-row" 
                                                    data-status="<?php echo $maintenance['status']; ?>"
                                                    data-priority="<?php echo $maintenance['priority']; ?>"
                                                    data-asset="<?php echo htmlspecialchars($maintenance['asset_name']); ?>">
                                                    <td>
                                                        <div><strong><?php echo $maintenance['asset_code']; ?></strong></div>
                                                        <div style="font-size: 12px; color: var(--text-light);"><?php echo $maintenance['asset_name']; ?></div>
                                                        <?php if ($isOverdue): ?>
                                                            <span class="badge badge-danger" style="font-size: 10px; padding: 2px 6px;">OVERDUE</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo ucfirst(str_replace('_', ' ', $maintenance['maintenance_type'])); ?></td>
                                                    <td><?php echo date('M j, Y', strtotime($maintenance['scheduled_date'])); ?></td>
                                                    <td><?php echo date('M j, Y', strtotime($maintenance['due_date'])); ?></td>
                                                    <td><span class="badge <?php echo $priority_badge; ?>"><?php echo ucfirst($maintenance['priority']); ?></span></td>
                                                    <td><?php echo $staff_name; ?></td>
                                                    <td>₹<?php echo number_format($cost, 2); ?></td>
                                                    <td><span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst(str_replace('_', ' ', $maintenance['status'])); ?></span></td>
                                                    <td>
                                                        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                                            <?php if ($maintenance['status'] === 'scheduled'): ?>
                                                                <form method="POST" style="display: inline;">
                                                                    <input type="hidden" name="action" value="update_status">
                                                                    <input type="hidden" name="maintenance_id" value="<?php echo $maintenance['id']; ?>">
                                                                    <input type="hidden" name="status" value="in_progress">
                                                                    <button type="submit" class="btn btn-sm btn-success" title="Start Progress">
                                                                        <i class="fas fa-play"></i>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                            
                                                            <?php if ($maintenance['status'] === 'in_progress'): ?>
                                                                <form method="POST" style="display: inline;">
                                                                    <input type="hidden" name="action" value="update_status">
                                                                    <input type="hidden" name="maintenance_id" value="<?php echo $maintenance['id']; ?>">
                                                                    <input type="hidden" name="status" value="completed">
                                                                    <button type="submit" class="btn btn-sm btn-success" title="Mark Complete">
                                                                        <i class="fas fa-check"></i>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                            
                                                            <?php if ($maintenance['status'] !== 'completed' && $maintenance['status'] !== 'cancelled'): ?>
                                                                <form method="POST" style="display: inline;">
                                                                    <input type="hidden" name="action" value="update_status">
                                                                    <input type="hidden" name="maintenance_id" value="<?php echo $maintenance['id']; ?>">
                                                                    <input type="hidden" name="status" value="cancelled">
                                                                    <button type="submit" class="btn btn-sm btn-danger" title="Cancel" onclick="return confirm('Are you sure you want to cancel this maintenance?')">
                                                                        <i class="fas fa-times"></i>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                            
                                                            <a href="asset_management.php#maintenance-section" class="btn btn-sm btn-info" title="View Details">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="9" class="empty-state">
                                                    <i class="fas fa-tools"></i>
                                                    <h3>No Maintenance Records Found</h3>
                                                    <p>You haven't scheduled any maintenance yet.</p>
                                                    <a href="asset_management.php#maintenance-section" class="btn btn-primary">
                                                        <i class="fas fa-calendar-plus"></i> Schedule Your First Maintenance
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>
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

        // Filter maintenance by status
        function filterMaintenance(status) {
            const rows = document.querySelectorAll('.maintenance-row');
            rows.forEach(row => {
                const rowStatus = row.getAttribute('data-status');
                if (status === 'all' || rowStatus === status) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Update active tab
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
        }

        // Filter by priority
        function filterMaintenanceByPriority() {
            const priority = document.getElementById('priorityFilter').value;
            const rows = document.querySelectorAll('.maintenance-row');
            rows.forEach(row => {
                const rowPriority = row.getAttribute('data-priority');
                if (!priority || rowPriority === priority) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Search functionality
        function searchMaintenance() {
            const query = document.getElementById('searchMaintenance').value.toLowerCase();
            const rows = document.querySelectorAll('.maintenance-row');
            
            rows.forEach(row => {
                const assetName = row.getAttribute('data-asset').toLowerCase();
                const cells = row.querySelectorAll('td');
                let found = false;
                
                if (assetName.includes(query)) {
                    found = true;
                } else {
                    for (let cell of cells) {
                        if (cell.textContent.toLowerCase().includes(query)) {
                            found = true;
                            break;
                        }
                    }
                }
                
                row.style.display = found ? '' : 'none';
            });
        }

        // Initialize date inputs
        document.addEventListener('DOMContentLoaded', function() {
            // Set today's date for any date inputs
            const today = new Date().toISOString().split('T')[0];
            document.querySelectorAll('input[type="date"]').forEach(input => {
                if (!input.value) {
                    input.value = today;
                }
            });
        });

        // Add confirmation for status changes
        document.querySelectorAll('form[method="POST"]').forEach(form => {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn && !submitBtn.hasAttribute('onclick')) {
                submitBtn.addEventListener('click', function(e) {
                    const status = form.querySelector('input[name="status"]').value;
                    const confirmMessage = status === 'cancelled' 
                        ? 'Are you sure you want to cancel this maintenance?' 
                        : 'Are you sure you want to update the status?';
                    
                    if (!confirm(confirmMessage)) {
                        e.preventDefault();
                    }
                });
            }
        });
    </script>
</body>
</html>