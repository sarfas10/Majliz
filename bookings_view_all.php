<?php
// bookings_view_all.php
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

// Get all booking records
$bookings_list = [];
$total_pending = 0;
$total_approved = 0;
$total_rejected = 0;
$total_cancelled = 0;

try {
    // Get booking count by status
    $stmt = $conn->prepare("SELECT status, COUNT(*) as count FROM asset_bookings WHERE mahal_id = ? GROUP BY status");
    $stmt->bind_param("i", $mahal_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        switch ($row['status']) {
            case 'pending':
                $total_pending = $row['count'];
                break;
            case 'approved':
                $total_approved = $row['count'];
                break;
            case 'rejected':
                $total_rejected = $row['count'];
                break;
            case 'cancelled':
                $total_cancelled = $row['count'];
                break;
        }
    }
    $stmt->close();

    // Get all booking records with details
    $stmt = $conn->prepare("
        SELECT b.*, a.name as asset_name, a.asset_code 
        FROM asset_bookings b 
        LEFT JOIN assets a ON b.asset_id = a.id 
        WHERE b.mahal_id = ? 
        ORDER BY b.start_date DESC
    ");
    $stmt->bind_param("i", $mahal_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $bookings_list[] = $row;
    }
    $stmt->close();

} catch (Exception $e) {
    error_log("Bookings View Error: " . $e->getMessage());
}

// Handle booking status update if requested
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_booking_status') {
    try {
        $status = $_POST['status'];
        $booking_id = $_POST['booking_id'];

        $stmt = $conn->prepare("UPDATE asset_bookings SET status = ?, approved_by = ?, approved_at = CASE WHEN ? IN ('approved', 'rejected') THEN NOW() ELSE approved_at END WHERE id = ? AND mahal_id = ?");
        $stmt->bind_param("sissi", $status, $user_id, $status, $booking_id, $mahal_id);
        $stmt->execute();
        $stmt->close();

        echo "<script>alert('Booking status updated successfully!'); window.location.href = window.location.href;</script>";
        exit;
    } catch (Exception $e) {
        echo "<script>alert('Error updating booking status: " . addslashes($e->getMessage()) . "');</script>";
    }
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Bookings - <?php echo htmlspecialchars($mahal['name']); ?></title>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Amiri:wght@400;700&display=swap"
        rel="stylesheet">
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

        html,
        body {
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

        /* Enhanced Back Button in Top Left Corner */
        .back-button-container {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 100;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .back-button-prominent {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 12px 20px;
            border-radius: var(--radius);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: var(--shadow-lg);
            transition: var(--transition);
            border: 2px solid rgba(255, 255, 255, 0.2);
        }

        .back-button-prominent:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(74, 111, 165, 0.4);
            color: white;
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
        }

        .back-button-prominent i {
            font-size: 16px;
        }

        /* Full Page Layout */
        .main.full-page {
            margin-left: 0;
            min-height: 100vh;
            position: relative;
            width: 100%;
        }

        /* Fixed Logo Row for full page */
        .logo-row.full-page {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 90;
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 0;
            padding: 15px 20px;
            background: linear-gradient(135deg, var(--card), var(--card-alt));
            border-radius: 0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border-bottom: 2px solid var(--border);
            position: relative;
            overflow: hidden;
        }

        .logo-row.full-page::before {
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

        .logo-row.full-page img {
            width: 50px;
            height: 50px;
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
            font-size: 20px;
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
            font-size: 12px;
            color: var(--text-light);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .name-subtitle i {
            color: var(--secondary);
            font-size: 11px;
        }

        /* Full Page Container */
        .full-page-container {
            margin: 0;
            padding: 0;
            width: 100%;
            min-height: 100vh;
            background: var(--bg);
        }

        .full-page-content {
            padding: 100px 20px 40px 20px;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
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

        .card.full-width {
            margin: 0 0 30px 0;
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
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
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: var(--card);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        table th,
        table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        table th {
            background: var(--card-alt);
            font-weight: 600;
            color: var(--text);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        table tr:hover {
            background: var(--card-alt);
        }

        table tr:last-child td {
            border-bottom: none;
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
            overflow-x: auto;
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

        /* Search and Filter Container */
        .search-filter-container {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            padding: 20px;
            background: var(--card-alt);
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }

        .search-filter-container>div {
            flex: 1;
            min-width: 200px;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 10px;
            color: var(--text);
        }

        .empty-state p {
            font-size: 14px;
            margin-bottom: 20px;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }

        .text-center {
            text-align: center;
        }

        /* Calendar icon for dates */
        .date-cell {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .date-icon {
            color: var(--primary);
            font-size: 14px;
        }

        /* Time slot styling */
        .time-slot {
            background: rgba(74, 111, 165, 0.1);
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
            margin-top: 4px;
        }

        /* View toggle */
        .view-toggle {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            padding: 10px;
            background: var(--card-alt);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            max-width: fit-content;
        }

        .view-btn {
            padding: 8px 16px;
            border: 1px solid var(--border);
            background: var(--card);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .view-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .view-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        /* Calendar View Styles */
        .calendar-container {
            background: var(--card);
            border-radius: var(--radius);
            padding: 20px;
            border: 1px solid var(--border);
            min-height: 500px;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
            backdrop-filter: blur(4px);
        }

        .modal {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            max-width: 90%;
            width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalFadeIn 0.3s ease;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }

        .close-modal {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: var(--transition);
        }

        .close-modal:hover {
            background: rgba(255, 255, 255, 0.1);
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

        /* Responsive Design */
        @media (min-width: 1024px) {
            .sidebar {
                transform: none;
            }

            .sidebar-overlay {
                display: none;
            }

            .main.full-page {
                margin-left: 288px;
                width: calc(100% - 288px);
            }

            .floating-menu-btn {
                display: none !important;
            }

            .sidebar-close {
                display: none;
            }

            /* Enhanced Back Button in Top Right Corner */
            .back-button-container {
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 100;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .full-page-content {
                padding: 120px 40px 60px 40px;
            }

            .logo-row.full-page {
                padding: 15px 40px;
            }
        }

        @media (max-width: 1023.98px) {
            .main.full-page {
                margin-left: 0;
            }
        }

        @media (max-width: 768px) {
            .back-button-container {
                left: 15px;
                top: 15px;
            }

            .back-button-prominent {
                background: linear-gradient(135deg, var(--primary), var(--primary-dark));
                color: white;
                padding: 12px 20px;
                border-radius: var(--radius);
                text-decoration: none;
                font-weight: 600;
                font-size: 14px;
                display: flex;
                align-items: center;
                gap: 8px;
                box-shadow: var(--shadow-lg);
                transition: var(--transition);
                border: 2px solid rgba(255, 255, 255, 0.2);
            }


            .full-page-content {
                padding: 90px 15px 30px 15px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .card-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }

            .status-filters {
                justify-content: center;
            }

            .search-filter-container {
                padding: 15px;
            }

            .search-filter-container>div {
                min-width: 100%;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .tabs {
                justify-content: flex-start;
            }

            .tab {
                padding: 10px 15px;
                font-size: 13px;
            }

            .logo-row.full-page {
                padding: 12px 15px;
            }

            .logo-row.full-page img {
                width: 40px;
                height: 40px;
            }

            .name-ar {
                font-size: 16px;
            }

            .name-subtitle {
                font-size: 11px;
            }
        }

        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--card-alt);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary-light);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary);
        }

        /* Animation for table rows */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .booking-row {
            animation: fadeIn 0.3s ease;
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
                        <img src="<?php echo htmlspecialchars($logo_path); ?>"
                            alt="<?php echo htmlspecialchars($mahal['name']); ?> Logo"
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

        <main class="main full-page" id="main">
            <!-- Fixed Logo Row -->


            <!-- Prominent Back Button in Top Right Corner -->
            <div class="back-button-container">
                <a href="asset_management.php" class="back-button-prominent">
                    Back to Asset Management
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <!-- Full Page Container -->
            <div class="full-page-container">
                <div class="full-page-content">

                    <!-- Stats Overview Cards -->


                    <!-- Main Bookings Card -->
                    <div class="card full-width">
                        <div class="card-header">
                            <h3><i class="fas fa-calendar-check"></i> All Bookings - Complete Overview</h3>
                            <div class="status-filters">
                                <span class="badge badge-info">Total: <?php echo count($bookings_list); ?>
                                    bookings</span>
                                <span class="badge badge-warning">Pending: <?php echo $total_pending; ?></span>
                                <span class="badge badge-success">Approved: <?php echo $total_approved; ?></span>
                                <span class="badge badge-danger">Rejected: <?php echo $total_rejected; ?></span>
                                <span class="badge badge-secondary">Cancelled: <?php echo $total_cancelled; ?></span>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- Tabs for filtering -->
                            <div class="tabs">
                                <button class="tab active" onclick="filterBookings('all')">All Bookings</button>
                                <button class="tab" onclick="filterBookings('pending')">Pending</button>
                                <button class="tab" onclick="filterBookings('approved')">Approved</button>
                                <button class="tab" onclick="filterBookings('rejected')">Rejected</button>
                                <button class="tab" onclick="filterBookings('cancelled')">Cancelled</button>
                                <button class="tab" onclick="filterBookings('today')">Today</button>
                                <button class="tab" onclick="filterBookings('upcoming')">Upcoming</button>
                            </div>

                            <!-- Search and Filter Controls -->
                            <div class="search-filter-container">
                                <div>
                                    <input type="text" id="searchBookings" class="form-control"
                                        placeholder="Search by name, purpose, or asset..." onkeyup="searchBookings()">
                                </div>
                                <div>
                                    <input type="date" id="dateFilter" class="form-control" onchange="filterByDate()">
                                </div>
                                <div>
                                    <select id="assetFilter" class="form-control" onchange="filterByAsset()">
                                        <option value="">All Assets</option>
                                        <?php
                                        // Get unique assets
                                        $unique_assets = [];
                                        foreach ($bookings_list as $booking) {
                                            $unique_assets[$booking['asset_id']] = $booking['asset_name'];
                                        }
                                        foreach ($unique_assets as $asset_id => $asset_name):
                                            ?>
                                            <option value="<?php echo $asset_id; ?>">
                                                <?php echo htmlspecialchars($asset_name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div style="display: flex; align-items: center;">
                                    <a href="asset_management.php#bookings-section" class="btn btn-primary"
                                        style="width: 100%; justify-content: center;">
                                        <i class="fas fa-plus"></i> New Booking
                                    </a>
                                </div>
                            </div>

                            <!-- View Toggle (List vs Calendar) -->
                            <div class="view-toggle">
                                <button class="view-btn active" onclick="setView('list')">
                                    <i class="fas fa-list"></i> List View
                                </button>
                                <button class="view-btn" onclick="setView('calendar')">
                                    <i class="fas fa-calendar"></i> Calendar View
                                </button>
                            </div>

                            <!-- Bookings Table -->
                            <div class="table-container" id="listView">
                                <table id="bookingsTable">
                                    <thead>
                                        <tr>
                                            <th>Asset</th>
                                            <th>Date Range</th>
                                            <th>Booked By</th>
                                            <th>Purpose</th>

                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($bookings_list) > 0): ?>
                                            <?php
                                            $today = date('Y-m-d');
                                            foreach ($bookings_list as $booking):

                                                $status_badge = '';
                                                switch ($booking['status']) {
                                                    case 'pending':
                                                        $status_badge = 'badge-warning';
                                                        break;
                                                    case 'approved':
                                                        $status_badge = 'badge-success';
                                                        break;
                                                    case 'rejected':
                                                        $status_badge = 'badge-danger';
                                                        break;
                                                    case 'cancelled':
                                                        $status_badge = 'badge-secondary';
                                                        break;
                                                }

                                                $is_today = ($booking['start_date'] <= $today && $booking['end_date'] >= $today);
                                                $is_past = ($booking['end_date'] < $today);
                                                ?>
                                                <tr class="booking-row" data-status="<?php echo $booking['status']; ?>"
                                                    data-date="<?php echo $booking['start_date']; ?>"
                                                    data-asset="<?php echo $booking['asset_id']; ?>"
                                                    data-bookedby="<?php echo htmlspecialchars(strtolower($booking['booked_by'])); ?>"
                                                    data-purpose="<?php echo htmlspecialchars(strtolower($booking['purpose'])); ?>">
                                                    <td>
                                                        <div><strong><?php echo $booking['asset_code'] ?? 'N/A'; ?></strong>
                                                        </div>
                                                        <div style="font-size: 12px; color: var(--text-light);">
                                                            <?php echo $booking['asset_name'] ?? '<span class="text-muted">Deleted Asset</span>'; ?>
                                                        </div>
                                                        <?php if ($is_today): ?>
                                                            <span class="badge badge-info"
                                                                style="font-size: 10px; padding: 2px 6px; margin-top: 4px; display: inline-block;">TODAY</span>
                                                        <?php elseif ($is_past && $booking['status'] === 'approved'): ?>
                                                            <span class="badge badge-secondary"
                                                                style="font-size: 10px; padding: 2px 6px; margin-top: 4px; display: inline-block;">PAST</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="date-cell">
                                                            <i class="fas fa-calendar date-icon"></i>
                                                            <?php 
                                                            $start = date('M j, Y', strtotime($booking['start_date']));
                                                            $end = date('M j, Y', strtotime($booking['end_date']));
                                                            if ($start == $end) {
                                                                echo $start;
                                                            } else {
                                                                echo $start . ' - ' . $end;
                                                            }
                                                            ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($booking['booked_by']); ?></strong>
                                                        <?php if ($booking['booked_for']): ?>
                                                            <div
                                                                style="font-size: 11px; color: var(--text-light); margin-top: 4px;">
                                                                For: Member #<?php echo $booking['booked_for']; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($booking['contact_number'])): ?>
                                                            <div
                                                                style="font-size: 11px; color: var(--text-light); margin-top: 2px;">
                                                                <i class="fas fa-phone"></i>
                                                                <?php echo htmlspecialchars($booking['contact_number']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo htmlspecialchars($booking['purpose']); ?>
                                                        <?php if (!empty($booking['requirements'])): ?>
                                                            <div
                                                                style="font-size: 11px; color: var(--text-light); margin-top: 4px;">
                                                                <i class="fas fa-info-circle"></i> Has special requirements
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?php echo $status_badge; ?>">
                                                            <?php echo ucfirst($booking['status']); ?>
                                                        </span>
                                                        <?php if ($booking['approved_by']): ?>
                                                            <div
                                                                style="font-size: 11px; color: var(--text-light); margin-top: 4px;">
                                                                <i class="fas fa-user-check"></i> Approved by Admin
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                                            <?php if ($booking['status'] === 'pending'): ?>
                                                                <form method="POST" style="display: inline;">
                                                                    <input type="hidden" name="action"
                                                                        value="update_booking_status">
                                                                    <input type="hidden" name="booking_id"
                                                                        value="<?php echo $booking['id']; ?>">
                                                                    <input type="hidden" name="status" value="approved">
                                                                    <button type="submit" class="btn btn-sm btn-success"
                                                                        title="Approve Booking">
                                                                        <i class="fas fa-check"></i>
                                                                    </button>
                                                                </form>
                                                                <form method="POST" style="display: inline;">
                                                                    <input type="hidden" name="action"
                                                                        value="update_booking_status">
                                                                    <input type="hidden" name="booking_id"
                                                                        value="<?php echo $booking['id']; ?>">
                                                                    <input type="hidden" name="status" value="rejected">
                                                                    <button type="submit" class="btn btn-sm btn-danger"
                                                                        title="Reject Booking"
                                                                        onclick="return confirm('Are you sure you want to reject this booking?')">
                                                                        <i class="fas fa-times"></i>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>

                                                            <?php if ($booking['status'] === 'approved'): ?>
                                                                <form method="POST" style="display: inline;">
                                                                    <input type="hidden" name="action"
                                                                        value="update_booking_status">
                                                                    <input type="hidden" name="booking_id"
                                                                        value="<?php echo $booking['id']; ?>">
                                                                    <input type="hidden" name="status" value="cancelled">
                                                                    <button type="submit" class="btn btn-sm btn-warning"
                                                                        title="Cancel Booking"
                                                                        onclick="return confirm('Are you sure you want to cancel this approved booking?')">
                                                                        <i class="fas fa-ban"></i>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>

                                                            <button class="btn btn-sm btn-info" title="View Details"
                                                                onclick="viewBookingDetails(<?php echo $booking['id']; ?>)">
                                                                <i class="fas fa-eye"></i>
                                                            </button>

                                                            <?php if (!empty($booking['requirements'])): ?>
                                                                <button class="btn btn-sm btn-secondary" title="View Requirements"
                                                                    onclick="viewRequirements('<?php echo htmlspecialchars(addslashes($booking['requirements'])); ?>')">
                                                                    <i class="fas fa-clipboard-list"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="6">
                                                    <div class="empty-state">
                                                        <i class="fas fa-calendar-times"></i>
                                                        <h3>No Booking Records Found</h3>
                                                        <p>You haven't received any booking requests yet. Create your first
                                                            booking or check back later.</p>
                                                        <a href="asset_management.php#bookings-section"
                                                            class="btn btn-primary">
                                                            <i class="fas fa-book"></i> Create Your First Booking
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Calendar View (Hidden by default) -->
                            <div id="calendarView" style="display: none;">
                                <div class="calendar-container">
                                    <div
                                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                        <h4 style="margin: 0; color: var(--text);"><i class="fas fa-calendar-alt"></i>
                                            Booking Calendar</h4>
                                        <div style="display: flex; gap: 10px; align-items: center;">
                                            <button class="btn btn-sm btn-secondary" onclick="prevMonth()"
                                                title="Previous Month">
                                                <i class="fas fa-chevron-left"></i>
                                            </button>
                                            <span id="currentMonth"
                                                style="font-weight: 600; min-width: 150px; text-align: center; color: var(--text);"></span>
                                            <button class="btn btn-sm btn-secondary" onclick="nextMonth()"
                                                title="Next Month">
                                                <i class="fas fa-chevron-right"></i>
                                            </button>
                                            <button class="btn btn-sm btn-primary" onclick="goToToday()"
                                                title="Go to Today">
                                                Today
                                            </button>
                                        </div>
                                    </div>
                                    <div
                                        style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; background: var(--card-alt); padding: 10px; border-radius: var(--radius-sm);">
                                        <!-- Calendar will be generated by JavaScript -->
                                    </div>
                                    <div
                                        style="margin-top: 20px; padding: 15px; background: var(--card-alt); border-radius: var(--radius-sm); border: 1px solid var(--border);">
                                        <h5 style="margin-bottom: 10px; color: var(--text);"><i
                                                class="fas fa-info-circle"></i> Calendar Legend</h5>
                                        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                                            <div style="display: flex; align-items: center; gap: 5px;">
                                                <div
                                                    style="width: 12px; height: 12px; background: var(--success); border-radius: 2px;">
                                                </div>
                                                <span style="font-size: 12px;">Approved</span>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 5px;">
                                                <div
                                                    style="width: 12px; height: 12px; background: var(--warning); border-radius: 2px;">
                                                </div>
                                                <span style="font-size: 12px;">Pending</span>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 5px;">
                                                <div
                                                    style="width: 12px; height: 12px; background: var(--error); border-radius: 2px;">
                                                </div>
                                                <span style="font-size: 12px;">Rejected</span>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 5px;">
                                                <div
                                                    style="width: 12px; height: 12px; background: var(--text-light); border-radius: 2px;">
                                                </div>
                                                <span style="font-size: 12px;">Cancelled</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Booking Details Modal -->
    <div class="modal-overlay" id="bookingDetailsModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-info-circle"></i> Booking Details</h3>
                <button class="close-modal" onclick="closeModal('bookingDetailsModal')">&times;</button>
            </div>
            <div class="modal-body" id="bookingDetailsContent">
                <!-- Content will be loaded via JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary"
                    onclick="closeModal('bookingDetailsModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Requirements Modal -->
    <div class="modal-overlay" id="requirementsModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-clipboard-list"></i> Special Requirements</h3>
                <button class="close-modal" onclick="closeModal('requirementsModal')">&times;</button>
            </div>
            <div class="modal-body" id="requirementsContent">
                <!-- Content will be loaded via JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('requirementsModal')">Close</button>
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

        // Filter bookings by status or date range
        function filterBookings(filterType) {
            const rows = document.querySelectorAll('.booking-row');
            const today = new Date().toISOString().split('T')[0];
            const tabs = document.querySelectorAll('.tab');

            rows.forEach(row => {
                const rowStatus = row.getAttribute('data-status');
                const rowDate = row.getAttribute('data-date');
                let show = false;

                switch (filterType) {
                    case 'all':
                        show = true;
                        break;
                    case 'pending':
                    case 'approved':
                    case 'rejected':
                    case 'cancelled':
                        show = rowStatus === filterType;
                        break;
                    case 'today':
                        show = rowDate === today;
                        break;
                    case 'upcoming':
                        show = rowDate >= today && rowStatus === 'approved';
                        break;
                    case 'past':
                        show = rowDate < today;
                        break;
                }

                row.style.display = show ? '' : 'none';
            });

            // Update active tab
            tabs.forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
        }

        // Filter by specific date
        function filterByDate() {
            const selectedDate = document.getElementById('dateFilter').value;
            if (!selectedDate) return;

            const rows = document.querySelectorAll('.booking-row');
            rows.forEach(row => {
                const rowDate = row.getAttribute('data-date');
                row.style.display = rowDate === selectedDate ? '' : 'none';
            });

            // Update tabs
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
        }

        // Filter by asset
        function filterByAsset() {
            const selectedAsset = document.getElementById('assetFilter').value;
            const rows = document.querySelectorAll('.booking-row');
            rows.forEach(row => {
                const rowAsset = row.getAttribute('data-asset');
                row.style.display = (!selectedAsset || rowAsset === selectedAsset) ? '' : 'none';
            });

            // Update tabs
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
        }

        // Search functionality
        function searchBookings() {
            const query = document.getElementById('searchBookings').value.toLowerCase();
            const rows = document.querySelectorAll('.booking-row');

            rows.forEach(row => {
                const bookedBy = row.getAttribute('data-bookedby');
                const purpose = row.getAttribute('data-purpose');
                const cells = row.querySelectorAll('td');
                let found = false;

                if (bookedBy.includes(query) || purpose.includes(query)) {
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

            // Update tabs
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
        }

        // View toggle functionality
        function setView(viewType) {
            const listView = document.getElementById('listView');
            const calendarView = document.getElementById('calendarView');
            const viewBtns = document.querySelectorAll('.view-btn');

            if (viewType === 'list') {
                listView.style.display = 'block';
                calendarView.style.display = 'none';
                viewBtns[0].classList.add('active');
                viewBtns[1].classList.remove('active');
            } else {
                listView.style.display = 'none';
                calendarView.style.display = 'block';
                viewBtns[0].classList.remove('active');
                viewBtns[1].classList.add('active');
                generateCalendar();
            }
        }

        // Calendar functionality
        let currentMonth = new Date().getMonth();
        let currentYear = new Date().getFullYear();

        function generateCalendar() {
            const calendarEl = document.querySelector('.calendar-container > div:nth-child(2)');
            const monthYearEl = document.getElementById('currentMonth');

            // Clear previous calendar
            calendarEl.innerHTML = '';

            // Set month-year display
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'];
            monthYearEl.textContent = `${monthNames[currentMonth]} ${currentYear}`;

            // Get first day of month and number of days
            const firstDay = new Date(currentYear, currentMonth, 1);
            const lastDay = new Date(currentYear, currentMonth + 1, 0);
            const daysInMonth = lastDay.getDate();
            const startingDay = firstDay.getDay(); // 0 = Sunday, 1 = Monday, etc.

            // Day headers
            const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            dayNames.forEach(day => {
                const dayHeader = document.createElement('div');
                dayHeader.style.textAlign = 'center';
                dayHeader.style.fontWeight = '600';
                dayHeader.style.color = 'var(--primary)';
                dayHeader.style.padding = '10px 5px';
                dayHeader.style.background = 'var(--card)';
                dayHeader.style.borderRadius = '4px';
                dayHeader.textContent = day;
                calendarEl.appendChild(dayHeader);
            });

            // Empty cells for days before the first day of the month
            for (let i = 0; i < startingDay; i++) {
                const emptyCell = document.createElement('div');
                emptyCell.style.minHeight = '100px';
                emptyCell.style.border = '1px solid var(--border)';
                emptyCell.style.borderRadius = '4px';
                emptyCell.style.background = 'var(--card-alt)';
                calendarEl.appendChild(emptyCell);
            }

            // Days of the month
            for (let day = 1; day <= daysInMonth; day++) {
                const dayCell = document.createElement('div');
                dayCell.style.minHeight = '100px';
                dayCell.style.border = '1px solid var(--border)';
                dayCell.style.borderRadius = '4px';
                dayCell.style.padding = '8px';
                dayCell.style.overflow = 'hidden';
                dayCell.style.position = 'relative';
                dayCell.style.cursor = 'pointer';
                dayCell.style.background = 'white';
                dayCell.style.transition = 'all 0.2s ease';

                // Date number
                const dateNumber = document.createElement('div');
                dateNumber.style.fontWeight = '600';
                dateNumber.style.marginBottom = '4px';
                dateNumber.style.fontSize = '14px';
                dateNumber.textContent = day;

                // Check for bookings on this day
                const dateStr = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const bookingsOnDay = Array.from(document.querySelectorAll('.booking-row')).filter(row => {
                    return row.getAttribute('data-date') === dateStr;
                });

                // Highlight today
                const today = new Date();
                if (currentYear === today.getFullYear() && currentMonth === today.getMonth() && day === today.getDate()) {
                    dayCell.style.background = 'rgba(74, 111, 165, 0.1)';
                    dayCell.style.borderColor = 'var(--primary)';
                    dayCell.style.borderWidth = '2px';
                }

                // Add booking indicators
                if (bookingsOnDay.length > 0) {
                    const bookingIndicator = document.createElement('div');
                    bookingIndicator.style.fontSize = '11px';
                    bookingIndicator.style.overflow = 'hidden';
                    bookingIndicator.style.textOverflow = 'ellipsis';
                    bookingIndicator.style.whiteSpace = 'nowrap';

                    // Show count of bookings
                    const bookingCount = document.createElement('div');
                    bookingCount.style.background = 'var(--primary)';
                    bookingCount.style.color = 'white';
                    bookingCount.style.borderRadius = '10px';
                    bookingCount.style.padding = '2px 6px';
                    bookingCount.style.fontSize = '10px';
                    bookingCount.style.display = 'inline-block';
                    bookingCount.style.marginLeft = '5px';
                    bookingCount.textContent = bookingsOnDay.length;
                    dateNumber.appendChild(bookingCount);

                    // Show booking status indicators
                    const statusCounts = {};
                    bookingsOnDay.forEach(booking => {
                        const status = booking.getAttribute('data-status');
                        statusCounts[status] = (statusCounts[status] || 0) + 1;
                    });

                    let statusHtml = '';
                    Object.keys(statusCounts).forEach(status => {
                        let color = 'var(--text-light)';
                        let label = status;

                        switch (status) {
                            case 'approved': color = 'var(--success)'; label = 'Approved'; break;
                            case 'pending': color = 'var(--warning)'; label = 'Pending'; break;
                            case 'rejected': color = 'var(--error)'; label = 'Rejected'; break;
                            case 'cancelled': color = 'var(--text-light)'; label = 'Cancelled'; break;
                        }

                        statusHtml += `<div style="display: flex; align-items: center; gap: 4px; margin-top: 2px;">
                            <div style="width: 8px; height: 8px; border-radius: 50%; background: ${color};"></div>
                            <span style="font-size: 10px;">${statusCounts[status]} ${label}</span>
                        </div>`;
                    });

                    bookingIndicator.innerHTML = statusHtml;
                    dayCell.appendChild(bookingIndicator);

                    // Add hover effect
                    dayCell.addEventListener('mouseenter', () => {
                        dayCell.style.transform = 'translateY(-2px)';
                        dayCell.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
                    });

                    dayCell.addEventListener('mouseleave', () => {
                        dayCell.style.transform = '';
                        dayCell.style.boxShadow = '';
                    });

                    // Add click event to show bookings for this day
                    dayCell.addEventListener('click', () => {
                        showDayBookings(dateStr, bookingsOnDay);
                    });
                }

                dayCell.prepend(dateNumber);
                calendarEl.appendChild(dayCell);
            }
        }

        function prevMonth() {
            currentMonth--;
            if (currentMonth < 0) {
                currentMonth = 11;
                currentYear--;
            }
            generateCalendar();
        }

        function nextMonth() {
            currentMonth++;
            if (currentMonth > 11) {
                currentMonth = 0;
                currentYear++;
            }
            generateCalendar();
        }

        function goToToday() {
            const today = new Date();
            currentMonth = today.getMonth();
            currentYear = today.getFullYear();
            generateCalendar();
        }

        function showDayBookings(dateStr, bookingRows) {
            if (bookingRows.length === 0) return;

            // Create modal content
            const formattedDate = new Date(dateStr).toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });

            let modalContent = `<h4 style="margin-bottom: 15px; color: var(--text);"><i class="fas fa-calendar-day"></i> Bookings on ${formattedDate}</h4>`;
            modalContent += `<div style="display: flex; flex-direction: column; gap: 10px;">`;

            bookingRows.forEach((row, index) => {
                const status = row.getAttribute('data-status');
                const assetName = row.querySelector('td:first-child div:nth-child(2)').textContent;
                const dateRange = row.querySelector('.date-cell').textContent.trim();
                const bookedBy = row.querySelector('td:nth-child(3) strong').textContent;
                const purpose = row.querySelector('td:nth-child(4)').childNodes[0].textContent.trim();


                let statusColor = 'var(--text-light)';
                switch (status) {
                    case 'approved': statusColor = 'var(--success)'; break;
                    case 'pending': statusColor = 'var(--warning)'; break;
                    case 'rejected': statusColor = 'var(--error)'; break;
                    case 'cancelled': statusColor = 'var(--text-light)'; break;
                }

                modalContent += `
                    <div style="padding: 12px; background: var(--card-alt); border-radius: var(--radius-sm); border-left: 4px solid ${statusColor};">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <div>
                                <div style="font-weight: 600; color: var(--text);">${assetName}</div>
                                <div style="font-size: 12px; color: var(--text-light); margin-top: 4px;">
                                    <i class="far fa-calendar"></i> ${dateRange}
                                </div>
                            </div>

                            <span style="font-size: 11px; padding: 2px 8px; border-radius: 10px; background: ${statusColor}20; color: ${statusColor};">
                                ${status.charAt(0).toUpperCase() + status.slice(1)}
                            </span>
                        </div>
                        <div style="margin-top: 8px; font-size: 13px;">
                            <div><strong>Booked by:</strong> ${bookedBy}</div>
                            <div><strong>Purpose:</strong> ${purpose}</div>
                        </div>
                    </div>
                `;
            });

            modalContent += `</div>`;

            // Use existing booking details modal
            document.getElementById('bookingDetailsContent').innerHTML = modalContent;
            document.getElementById('bookingDetailsModal').style.display = 'flex';
        }

        // Modal functions
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function viewBookingDetails(bookingId) {
            // This would typically make an AJAX call to get detailed booking info
            // For now, we'll show a simple message
            document.getElementById('bookingDetailsContent').innerHTML = `
                <div style="text-align: center; padding: 20px;">
                    <div style="width: 40px; height: 40px; border: 3px solid var(--border); border-top-color: var(--primary); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 20px;"></div>
                    <p>Loading booking details...</p>
                </div>
                <style>@keyframes spin { to { transform: rotate(360deg); } }</style>
            `;
            document.getElementById('bookingDetailsModal').style.display = 'flex';

            // Simulate loading
            setTimeout(() => {
                document.getElementById('bookingDetailsContent').innerHTML = `
                    <h4 style="color: var(--text);">Booking #${bookingId}</h4>
                    <p style="color: var(--text-light); margin-bottom: 15px;">Complete booking information would be displayed here.</p>
                    <div style="background: var(--card-alt); padding: 15px; border-radius: var(--radius-sm); border: 1px solid var(--border);">
                        <h5 style="margin-top: 0; color: var(--text);">Features that would be included:</h5>
                        <ul style="color: var(--text-light); padding-left: 20px; margin-bottom: 0;">
                            <li>Complete booking information</li>
                            <li>Contact details of the person who booked</li>
                            <li>Payment status (if applicable)</li>
                            <li>Booking history</li>
                            <li>Special notes and requirements</li>
                            <li>Timeline of status changes</li>
                            <li>Attachments or documents</li>
                        </ul>
                    </div>
                    <p style="margin-top: 15px; font-size: 13px; color: var(--text-light);">
                        This would be implemented with a proper AJAX call to fetch booking details from the server.
                    </p>
                `;
            }, 800);
        }

        function viewRequirements(requirements) {
            document.getElementById('requirementsContent').innerHTML = `
                <div style="background: var(--card-alt); padding: 20px; border-radius: var(--radius); border: 1px solid var(--border);">
                    <h5 style="margin-top: 0; margin-bottom: 15px; color: var(--text);">Special Requirements:</h5>
                    <div style="background: white; padding: 15px; border-radius: var(--radius-sm); border: 1px solid var(--border);">
                        <p style="margin: 0; white-space: pre-line; line-height: 1.6; color: var(--text);">${requirements}</p>
                    </div>
                    <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--border);">
                        <div style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-light);">
                            <i class="fas fa-info-circle"></i>
                            <span>These requirements will be considered when preparing the asset for use.</span>
                        </div>
                    </div>
                </div>
            `;
            document.getElementById('requirementsModal').style.display = 'flex';
        }

        // Close modal when clicking outside
        document.addEventListener('click', function (event) {
            if (event.target.classList.contains('modal-overlay')) {
                event.target.style.display = 'none';
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                document.querySelectorAll('.modal-overlay').forEach(modal => {
                    modal.style.display = 'none';
                });
            }
        });

        // Initialize date filter with today's date
        document.addEventListener('DOMContentLoaded', function () {
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('dateFilter').value = today;
            document.getElementById('dateFilter').max = today;

            // Smooth scroll to top when opening page
            window.scrollTo({ top: 0, behavior: 'smooth' });

            // Make back button more prominent on scroll
            const backButton = document.querySelector('.back-button-prominent');
            window.addEventListener('scroll', function () {
                if (window.scrollY > 100) {
                    backButton.style.transform = 'scale(1.05) translateY(-3px)';
                    backButton.style.boxShadow = '0 10px 30px rgba(74, 111, 165, 0.5)';
                } else {
                    backButton.style.transform = '';
                    backButton.style.boxShadow = '';
                }
            });

            // Add confirmation for status changes
            document.querySelectorAll('form[method="POST"]').forEach(form => {
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn && !submitBtn.hasAttribute('onclick')) {
                    submitBtn.addEventListener('click', function (e) {
                        const status = form.querySelector('input[name="status"]').value;
                        let confirmMessage = '';

                        switch (status) {
                            case 'approved':
                                confirmMessage = 'Are you sure you want to approve this booking?';
                                break;
                            case 'rejected':
                                confirmMessage = 'Are you sure you want to reject this booking?';
                                break;
                            case 'cancelled':
                                confirmMessage = 'Are you sure you want to cancel this booking?';
                                break;
                            default:
                                confirmMessage = 'Are you sure you want to update the booking status?';
                        }

                        if (!confirm(confirmMessage)) {
                            e.preventDefault();
                        }
                    });
                }
            });

            // Add loading animation to table rows
            const bookingRows = document.querySelectorAll('.booking-row');
            bookingRows.forEach((row, index) => {
                row.style.animationDelay = `${index * 0.05}s`;
            });
        });
    </script>
</body>

</html>