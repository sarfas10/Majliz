<?php
// finance-tracking.php - Financial Management System
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
// Include the centralized database connection
require_once 'db_connection.php';

// Get database connection
$db_result = get_db_connection();

// Check if connection was successful
if (isset($db_result['error'])) {
    die("Database connection failed: " . $db_result['error']);
}

$conn = $db_result['conn'];

// Fetch logged-in mahal details
$user_id = $_SESSION['user_id'];
$sql = "SELECT name, address, registration_no, email FROM register WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $mahal = $result->fetch_assoc();
} else {
    echo "<script>alert('Unable to fetch mahal details. Please log in again.'); window.location.href='index.php';</script>";
    exit();
}

$stmt->close();

// Define logo path
$logo_path = "logo.jpeg";


?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Finance Tracking - <?php echo htmlspecialchars($mahal['name']); ?></title>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Amiri:wght@400;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.12);
            --radius: 16px;
            --radius-sm: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
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

        /* ─────────────────────────────────────────────
       SIDEBAR • Clean Design
       ───────────────────────────────────────────── */
        /* ─────────────────────────────────────────────
       SIDEBAR • Enhanced Design
       ───────────────────────────────────────────── */
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

        /* Close button in sidebar */
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
            overflow: hidden;
            position: relative;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .profile-avatar i {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1;
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

        .main {
            margin-left: 0;
            min-height: 100vh;
            background: var(--bg);
            display: flex;
            flex-direction: column;
            width: 100%;
        }

        .top-row {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 20px 24px;
            background: var(--card);
            border-bottom: 1px solid var(--border);
            box-shadow: var(--shadow);
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

        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title i {
            color: var(--primary);
            font-size: 20px;
        }

        .container {
            padding: 24px;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 20px;
            border-radius: var(--radius-sm);
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: var(--transition);
            line-height: 1;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn.green {
            background: var(--success);
            color: #fff;
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.3);
        }

        .btn.green:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(39, 174, 96, 0.4);
            background: #219653;
        }

        .btn.blue {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 4px 12px rgba(74, 111, 165, 0.3);
        }

        .btn.blue:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(74, 111, 165, 0.4);
            background: var(--primary-dark);
        }

        .btn.white {
            background: var(--card);
            border: 1px solid var(--border);
            color: var(--text);
            box-shadow: var(--shadow);
        }

        .btn.white:hover {
            background: var(--card-alt);
            transform: translateY(-1px);
            box-shadow: var(--shadow-lg);
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 4px 12px rgba(74, 111, 165, 0.25);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            box-shadow: 0 6px 20px rgba(74, 111, 165, 0.4);
            transform: translateY(-1px);
        }

        .stats {
            font-size: 16px;
            color: var(--text-light);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stats span {
            color: var(--primary);
            font-weight: 800;
            font-size: 18px;
        }

        .dashboard-section {
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 22px;
            font-weight: 800;
            margin-bottom: 16px;
            color: var(--text);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            padding: 18px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            min-height: 96px;
            border: 1px solid var(--border);
        }

        .stat-card.income {
            border-left: 4px solid var(--success);
        }

        .stat-card.expense {
            border-left: 4px solid var(--error);
        }

        .stat-card.balance {
            border-left: 4px solid var(--primary);
        }

        .stat-card.cash {
            border-left: 4px solid var(--warning);
        }

        .stat-card.gpay {
            border-left: 4px solid #8b5cf6;
        }

        .stat-info h3 {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
            opacity: 0.95;
            letter-spacing: .3px;
            text-transform: uppercase;
        }

        .stat-amount {
            font-size: 28px;
            font-weight: 800;
        }

        .stat-icon {
            font-size: 34px;
            opacity: 0.9;
        }

        .transactions-container {
            background: var(--card);
            padding: 20px;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }

        .transactions-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .transactions-title {
            font-size: 18px;
            font-weight: 800;
            color: var(--text);
        }

        .view-toggle {
            display: flex;
            gap: 10px;
        }

        .filter-controls {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 12px;
        }

        .filter-select {
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 14px;
            background: var(--card);
            min-height: 42px;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            padding: 16px 20px;
            text-align: left;
            font-weight: 700;
            font-size: 13px;
            color: white;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--primary-dark);
            white-space: nowrap;
            position: relative;
        }

        th::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0.1));
        }

        td {
            padding: 14px 12px;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
            vertical-align: top;
            /* Change from white-space: normal to nowrap for type column */
            white-space: nowrap;
            /* Changed from 'normal' */
            overflow-wrap: anywhere;
            word-break: break-word;
            word-wrap: break-word;
        }

        tbody tr:hover {
            background: var(--card-alt);
        }

        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-income {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-expense {
            background: #fee2e2;
            color: #991b1b;
        }

        .amount-positive {
            color: #065f46;
            font-weight: 800;
        }

        .amount-negative {
            color: #b91c1c;
            font-weight: 800;
        }

        .no-data {
            text-align: center;
            padding: 28px;
            color: var(--text-light);
        }

        .action-btn {
            background: none;
            border: none;
            color: var(--text-light);
            cursor: pointer;
            padding: 6px 8px;
            transition: var(--transition);
            font-size: 18px;
        }

        /* But allow wrapping only for specific columns */
        td:nth-child(5),
        /* Description column */
        td:nth-child(8)

        /* Donor column */
            {
            white-space: normal;
            min-width: 200px;
            max-width: 300px;
        }

        /* For type column specifically */
        td:nth-child(3) {
            white-space: nowrap;
            width: 120px;
        }

        /* For category column */
        td:nth-child(4) {
            white-space: nowrap;
            width: 150px;
        }

        /* For amount column */
        td:nth-child(6) {
            white-space: nowrap;
            width: 120px;
        }

        /* For payment mode column */
        td:nth-child(7) {
            white-space: nowrap;
            width: 100px;
        }

        /* Make badges inline-block without breaking */
        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            /* Add this */
            line-height: 1.2;
            /* Adjust line height */
        }

        .action-btn:hover {
            color: var(--error);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--card);
            padding: 20px;
            border-radius: var(--radius);
            width: 100%;
            max-width: 520px;
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 800;
            color: var(--text);
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: var(--text-light);
            line-height: 1;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 700;
            color: var(--text);
            font-size: 13px;
        }

        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: inherit;
            min-height: 48px;
            background: var(--card);
            transition: var(--transition);
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }

        .form-textarea {
            resize: vertical;
            min-height: 90px;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn-cancel {
            background: var(--card-alt);
            color: var(--text);
            border: 1px solid var(--border);
        }

        .btn-cancel:hover {
            background: var(--border);
        }

        #donorResults {
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
        }

        .donor-row:hover {
            background: var(--card-alt);
            padding-left: 8px;
        }

        #donorInfoBox {
            display: none;
            margin-top: 8px;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px dashed var(--border);
            background: var(--card-alt);
            font-size: 13px;
            color: var(--text);
        }

        #donorInfoBox strong {
            font-weight: 700;
        }

        td br {
            display: inline-block;
            margin-bottom: 6px;
        }

        .filter-section {
            background: var(--card);
            padding: 20px;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            margin-bottom: 24px;
            box-shadow: var(--shadow);
        }

        .stat-amount {
            font-size: 24px;
            /* Reduced from 28px */
            font-weight: 800;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
            letter-spacing: -0.5px;
            font-family: 'Courier New', monospace;
            /* Fixed-width font for better alignment */
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(220px, 1fr));
            /* Increased min width */
            gap: 16px;
            margin-bottom: 20px;
        }

        .filter-row {
            display: grid;
            grid-template-columns: auto auto auto auto;
            gap: 12px;
            align-items: center;
        }

        .filter-row input[type="search"] {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 14px;
            transition: var(--transition);
            min-height: 48px;
            background: var(--card);
        }

        /* Add these styles to fix the stat card layout */
        /* Add these styles to fix the stat card layout */
        .stat-info {
            flex: 1;
            min-width: 0;
            /* This prevents flex items from overflowing */
            overflow: hidden;
        }

        .stat-amount {
            font-size: 28px;
            font-weight: 800;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
            letter-spacing: -0.5px;
            /* Slightly tighter letter spacing for large numbers */
        }

        .stat-icon {
            font-size: 34px;
            opacity: 0.9;
            flex-shrink: 0;
            /* Prevent the icon from shrinking */
            margin-left: 12px;
        }

        /* Adjust the stats grid for better responsiveness */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            padding: 18px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            min-height: 96px;
            border: 1px solid var(--border);
            overflow: hidden;
            /* Prevent any content from overflowing the card */
        }

        /* Media query adjustments for smaller screens */
        @media (max-width: 1023.98px) {
            .stats-grid {
                grid-template-columns: repeat(3, minmax(160px, 1fr));
            }

            .stat-amount {
                font-size: 24px;
            }

            .stat-icon {
                font-size: 28px;
            }
        }

        /* Update mobile view */
        @media (max-width: 768px) {
            table {
                border: 0;
            }

            thead {
                display: none;
            }

            tbody {
                display: grid;
                gap: 10px;
            }

            tbody tr {
                display: grid;
                background: var(--card);
                border: 1px solid var(--border);
                border-radius: var(--radius-sm);
                padding: 10px 12px;
                box-shadow: var(--shadow);
            }

            tbody td {
                display: grid;
                grid-template-columns: 40% 60%;
                padding: 8px 0;
                border-bottom: 0;
                white-space: normal !important;
                /* Override for mobile */
            }

            .stat-card {
                padding: 14px;
                min-height: 84px;
            }

            .stat-amount {
                font-size: 20px;
            }

            /* Add to your existing CSS */
            .truncated-desc {
                cursor: pointer;
                color: var(--primary);
                text-decoration: underline dotted;
                transition: all 0.2s ease;
            }

            .truncated-desc:hover {
                color: var(--primary-dark);
                text-decoration: underline;
            }

            /* Ensure description cell can expand when needed */
            td:nth-child(5) {
                white-space: normal;
                min-width: 200px;
                max-width: 300px;
                word-wrap: break-word;
                max-height: 100px;
                overflow: hidden;
                transition: max-height 0.3s ease;
            }

            /* When expanded, allow more height */
            td:nth-child(5) .expanded {
                max-height: none;
                overflow: visible;
                white-space: pre-wrap;
            }

            .stat-icon {
                font-size: 24px;
                margin-left: 8px;
            }

            .stat-info h3 {
                font-size: 11px;
                margin-bottom: 4px;
            }
        }

        .stat-amount {
            font-size: 24px;
            font-weight: 800;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
            letter-spacing: -0.5px;
            font-family: 'Inter', sans-serif;
            /* Clean font for Cr/L/K display */
        }

        /* Make stat cards a bit wider */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        /* Tooltip for full amount on hover */
        .stat-amount[title] {
            cursor: help;
            position: relative;
        }

        /* Add full amount as tooltip */
        function addTooltips() {
            document.querySelectorAll('.stat-amount').forEach(el=> {
                    // Store the full formatted amount as title attribute
                    const text=el.textContent;

                    if (text.includes('Cr') || text.includes('L') || text.includes('K')) {
                        // Get the original number by parsing the text
                        let amount=0;

                        if (text.includes('Cr')) {
                            amount=parseFloat(text.replace('₹', '').replace('Cr', '')) * 10000000;
                        }

                        else if (text.includes('L')) {
                            amount=parseFloat(text.replace('₹', '').replace('L', '')) * 100000;
                        }

                        else if (text.includes('K')) {
                            amount=parseFloat(text.replace('₹', '').replace('K', '')) * 1000;
                        }

                        // Format full amount with commas
                        const fullAmount='₹' + amount.toLocaleString('en-IN', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        });
                    el.setAttribute('title', fullAmount);
                }
            });
        }

        // Call this after updating stats
        setTimeout(addTooltips, 100);

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .stat-amount {
                font-size: 22px;
            }
        }

        .filter-row input[type="search"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }

        .filter-row select {
            padding: 12px 16px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 14px;
            transition: var(--transition);
            min-height: 48px;
            background: var(--card);
            cursor: pointer;
        }

        .filter-row select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }

        .date-input {
            padding: 12px 16px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 14px;
            transition: var(--transition);
            min-height: 48px;
            background: var(--card);
        }

        .date-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }

        .date-separator {
            color: var(--text-light);
            font-weight: 600;
            text-align: center;
        }

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
            .filter-row {
                grid-template-columns: 1fr auto auto;
                grid-auto-rows: auto;
            }

            .actions {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: repeat(3, minmax(200px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 16px;
            }

            .page-header {
                gap: 12px;
            }

            .stats {
                width: 100%;
                text-align: left;
            }

            .filter-row {
                grid-template-columns: 1fr;
            }

            .btn {
                padding: 10px 16px;
                font-size: 13px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(200px, 1fr));
            }

            .top-row {
                padding: 16px 20px;
            }

            .page-title {
                font-size: 20px;
            }

            table {
                border: 0;
            }

            thead {
                display: none;
            }

            tbody {
                display: grid;
                gap: 10px;
            }

            tbody tr {
                display: grid;
                background: var(--card);
                border: 1px solid var(--border);
                border-radius: var(--radius-sm);
                padding: 10px 12px;
                box-shadow: var(--shadow);
            }

            tbody td {
                display: grid;
                grid-template-columns: 40% 60%;
                padding: 8px 0;
                border-bottom: 0;
            }

            tbody td::before {
                content: attr(data-label);
                font-weight: 700;
                color: var(--text-light);
                padding-right: 10px;
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: .4px;
            }

            .action-btn {
                justify-self: end;
            }
        }

        @media (max-width: 640px) {
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .stat-card {
                padding: 16px;
                min-height: 84px;
            }

            .stat-amount {
                font-size: 24px;
            }

            .stat-icon {
                font-size: 28px;
            }

            .transactions-container {
                padding: 14px;
                border-radius: 14px;
            }

            .transactions-header {
                gap: 8px;
            }

            .view-toggle {
                width: 100%;
            }

            .view-toggle .btn {
                flex: 1;
            }

            .filter-controls {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-select {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 12px;
            }

            .btn {
                font-size: 12.5px;
                padding: 8px 12px;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .actions {
                width: 100%;
                justify-content: space-between;
            }
        }
    </style>
</head>

<body>
    <div class="sidebar-overlay" id="sidebarOverlay" hidden></div>

    <div id="app">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar" aria-hidden="true" aria-label="Main navigation">
            <button class="sidebar-close" id="sidebarClose" aria-label="Close menu" type="button">
                <i class="fas fa-times"></i>
            </button>
            <div class="sidebar-inner">
                <!-- Profile with Logo -->
                <div class="profile" onclick="window.location.href='dashboard.php'">
                    <div class="profile-avatar">
                        <img src="<?php echo htmlspecialchars($logo_path); ?>"
                            alt="<?php echo htmlspecialchars($mahal['name']); ?> Logo"
                            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <i class="fas fa-mosque" style="display: none;"></i>
                    </div>
                    <div class="name"><?php echo htmlspecialchars($mahal['name']); ?></div>
                    <div class="role">Administrator</div>
                </div>

                <nav class="menu" role="menu">
                    <button class="menu-btn" type="button" onclick="window.location.href='dashboard.php'">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Admin Panel</span>
                    </button>

                    <button class="menu-btn active" type="button">
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

                    <button class="menu-btn" type="button" onclick="window.location.href='asset_management.php'">
                        <i class="fas fa-boxes"></i>
                        <span>Asset Management</span>
                    </button>

                    <button class="menu-btn" type="button" onclick="window.location.href='academics.php'">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Academics</span>
                    </button>

                    <button class="menu-btn" type="button" onclick="window.location.href='certificate.php'">
                        <i class="fas fa-certificate"></i>
                        <span>Certificate Management</span>
                    </button>

                    <button class="menu-btn" type="button" onclick="window.location.href='offerings.php'">
                        <i class="fas fa-hand-holding-heart"></i>
                        <span>Offerings</span>
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

        <!-- Main Content -->
        <main class="main" id="main">
            <!-- Top Bar -->
            <section class="top-row">
                <button class="floating-menu-btn" id="menuToggle" aria-controls="sidebar" aria-expanded="false"
                    aria-label="Open menu" type="button">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="page-title">
                    <i class="fas fa-chart-line"></i>
                    Finance Tracking
                </div>
            </section>

            <div class="container">
                <!-- Stats Section -->
                <div class="dashboard-section">
                    <h2 class="section-title">Financial Dashboard - <?php echo htmlspecialchars($mahal['name']); ?></h2>
                    <div class="stats-grid">
                        <div class="stat-card income">
                            <div class="stat-info">
                                <h3>Total Income</h3>
                                <div class="stat-amount" id="totalIncome">₹0</div>
                            </div>
                            <div class="stat-icon">📈</div>
                        </div>
                        <div class="stat-card expense">
                            <div class="stat-info">
                                <h3>Total Expenses</h3>
                                <div class="stat-amount" id="totalExpense">₹0</div>
                            </div>
                            <div class="stat-icon">💸</div>
                        </div>
                        <div class="stat-card balance">
                            <div class="stat-info">
                                <h3>Current Balance</h3>
                                <div class="stat-amount" id="currentBalance">₹0</div>
                            </div>
                            <div class="stat-icon">💰</div>
                        </div>
                        <div class="stat-card cash">
                            <div class="stat-info">
                                <h3>Cash Balance</h3>
                                <div class="stat-amount" id="cashBalance">₹0</div>
                            </div>
                            <div class="stat-icon">💵</div>
                        </div>
                        <div class="stat-card gpay">
                            <div class="stat-info">
                                <h3>G Pay Balance</h3>
                                <div class="stat-amount" id="gpayBalance">₹0</div>
                            </div>
                            <div class="stat-icon">📱</div>
                        </div>
                    </div>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div class="actions">
                        <button class="btn green" onclick="openModal()">
                            <i class="fas fa-plus"></i>
                            New Entry
                        </button>

                        <button class="btn blue" onclick="openBulkModal()">
                            <i class="fas fa-layer-group"></i>
                            Bulk Transact
                        </button>
                        <button class="btn white" onclick="exportToExcel()" id="exportBtn">
                            <i class="fas fa-file-excel"></i>
                            Export to Excel
                        </button>
                    </div>
                    <div class="stats">
                        <i class="fas fa-exchange-alt"></i>
                        Transactions: <span id="count">0</span>
                    </div>
                </div>

                <!-- Transactions -->
                <div class="transactions-container">
                    <div class="transactions-header">
                        <h3 class="transactions-title" id="transactionsTitle">Recent Transactions</h3>
                        <div class="view-toggle">
                            <button class="btn white active" onclick="showRecentTransactions()">Recent</button>
                            <button class="btn white" onclick="showAllTransactions()">All Transactions</button>
                        </div>
                    </div>

                    <!-- Date Range Filter Section -->
                    <div class="filter-section">
                        <div class="filter-row">
                            <input type="date" id="filterDateFrom" class="date-input"
                                value="<?php echo date('Y-m-d', strtotime('-6 months')); ?>" />
                            <span class="date-separator">to</span>
                            <input type="date" id="filterDateTo" class="date-input"
                                value="<?php echo date('Y-m-d'); ?>" />
                            <button class="btn blue" onclick="applyFilters()">
                                <i class="fas fa-filter"></i>
                                Apply Filter
                            </button>
                        </div>
                    </div>

                    <!-- Additional Filters -->
                    <div class="filter-controls" id="filterControls">
                        <select id="typeFilter" class="filter-select" onchange="applyFilters()">
                            <option value="all">All Types</option>
                            <option value="INCOME">Income Only</option>
                            <option value="EXPENSE">Expense Only</option>
                        </select>

                        <select id="categoryFilter" class="filter-select" onchange="applyFilters()">
                            <option value="all">All Categories</option>
                        </select>

                        <select id="paymentModeFilter" class="filter-select" onchange="applyFilters()">
                            <option value="all">All Payment Modes</option>
                            <option value="CASH">Cash</option>
                            <option value="G PAY">G Pay</option>
                        </select>
                    </div>

                    <div class="table-container">
                        <table id="transactionsTable">
                            <thead id="transactionsTableHead">
                                <tr>
                                    <th>RECEIPT NO</th>
                                    <th>DATE</th>
                                    <th>TYPE</th>
                                    <th>CATEGORY</th>
                                    <th>DESCRIPTION</th>
                                    <th>AMOUNT</th>
                                    <th>PAYMENT MODE</th>
                                    <th>DONOR</th>
                                    <th>ACTION</th>
                                </tr>
                            </thead>
                            <tbody id="transactionsTableBody">
                                <tr>
                                    <td colspan="9" class="no-data">Loading transactions...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- New Entry Modal -->
    <div id="entryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">New Transaction</h3>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <form id="transactionForm">

                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" id="entryDate" class="form-input" required
                        value="<?php echo date('Y-m-d'); ?>" />
                </div>
                <div class="form-group">
                    <label class="form-label">Type</label>
                    <select id="entryType" class="form-select" required onchange="updateCategoryOptions()">
                        <option value="">Select Type</option>
                        <option value="INCOME">Income</option>
                        <option value="EXPENSE">Expense</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select id="entryCategory" class="form-select" required onchange="handleCategoryChange()">
                        <option value="">Select Category</option>
                    </select>
                </div>

                <!-- Hidden Group for Asset Booking Linking (Rent) -->
                <div class="form-group" id="linkedBookingGroup" style="display:none;">

                    <!-- NEW: Asset Category Filter -->
                    <label class="form-label">Filter by Asset Category</label>
                    <select id="rentalAssetCategoryFilter" class="form-select"
                        onchange="filterRentalAssetsByCategory()">
                        <option value="">All Categories</option>
                    </select>

                    <div style="margin-top: 8px;"></div>

                    <label class="form-label">Select Rental Asset</label>
                    <select id="linkedBookingSelect" class="form-select" onchange="onRentalAssetSelect()">
                        <option value="">-- Manual Entry --</option>
                    </select>

                    <!-- We use the same hidden input for asset_id, NOT booking_id -->
                    <input type="hidden" id="entryAssetBookingId" name="asset_booking_id" value="">
                </div>
                <!-- Hidden Group for Asset Tax Linking -->
                <div class="form-group" id="linkedAssetTaxGroup" style="display:none;">
                    <label class="form-label">Select Taxable Asset <small>(Auto-refills taxable amount)</small></label>
                    <select id="linkedAssetTaxSelect" class="form-select" onchange="onAssetTaxSelect()">
                        <option value="">-- Manual Entry --</option>
                    </select>
                    <input type="hidden" id="entryAssetId" name="asset_id" value="">
                </div>


                <div class="form-group">
                    <label class="form-label">Payment Mode</label>
                    <select id="entryPaymentMode" class="form-select" required>
                        <option value="CASH" selected>Cash</option>
                        <option value="G PAY">G Pay</option>
                    </select>
                </div>

                <div class="form-group" id="donorSelectorGroup" style="display:none;">
                    <label class="form-label">Donor</label>

                    <div style="display:flex; gap:12px; margin-bottom:8px; font-size:13px;">
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="radio" name="donorMode" value="member" checked
                                onclick="setDonorMode('member')">
                            <span>Member / Family</span>
                        </label>
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="radio" name="donorMode" value="non" onclick="setDonorMode('non')">
                            <span>Non-member</span>
                        </label>
                    </div>

                    <div id="donorMemberWrap">
                        <input type="text" id="donorSearch" class="form-input"
                            placeholder="Search member / family member..." oninput="debouncedSearch(this.value)"
                            autocomplete="off" />
                        <div id="donorResults"
                            style="max-height:180px; overflow:auto; border:1px solid #e5e7eb; margin-top:6px; display:none; background:#fff; border-radius:10px;">
                        </div>
                        <input type="hidden" id="donor_member_id" name="donor_member_id" value="">
                        <input type="hidden" id="donor_person_name" name="donor_person_name" value="">
                        <div id="donorInfoBox"></div>
                        <small style="color:#6b7280; display:block; margin-top:6px;">
                            If you select a family member, donation will be attributed to their family head.
                        </small>
                    </div>

                    <div id="donorNonMemberWrap" style="display:none; margin-top:4px;">
                        <input type="text" id="donorNonMemberName" class="form-input"
                            placeholder="Enter donor name (non-member)" />
                    </div>
                </div>

                <div class="form-group" id="staffSalaryGroup" style="display:none;">
                    <label class="form-label">Select Staff *</label>
                    <select id="staffSalarySelect" class="form-select" onchange="handleStaffSelection()">
                        <option value="">Select Staff</option>
                    </select>
                </div>

                <div class="form-group" id="salaryAmountTypeGroup" style="display:none;">
                    <label class="form-label">Salary Payment Type *</label>
                    <select id="salaryAmountType" class="form-select" onchange="handleSalaryAmountType()">
                        <option value="full">Full Amount (₹<span id="fixedSalaryAmount">0</span>)</option>
                        <option value="partial">Custom Amount</option>
                    </select>
                </div>

                <div class="form-group" id="customSalaryAmountGroup" style="display:none;">
                    <label class="form-label">Enter Amount *</label>
                    <input type="number" id="customSalaryAmount" class="form-input" min="0" step="0.01"
                        onchange="document.getElementById('entryAmount').value = this.value" />
                </div>

                <div class="form-group" id="yearSelectGroup" style="display: none;">
                    <label class="form-label">Select Year</label>
                    <select id="entryYear" class="form-select">
                        <option value="">Select Year</option>
                    </select>
                </div>

                <div class="form-group" id="otherExpenseDetailGroup" style="display:none;">
                    <label class="form-label">Other Expense Detail</label>
                    <input type="text" id="otherExpenseDetail" class="form-input"
                        placeholder="e.g., plumber charge, minor repair, etc." />
                </div>

                <div class="form-group" id="generalAmountGroup">
                    <label class="form-label">Amount (₹)</label>
                    <input type="number" id="entryAmount" class="form-input" required min="0" step="0.01" />
                </div>

                <div class="form-group">
                    <label class="form-label">Description (Optional)</label>
                    <textarea id="entryDescription" class="form-textarea"
                        placeholder="Additional details..."></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Transaction</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk modal -->
    <div id="bulkModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Bulk Transaction</h3>
                <button class="close-btn" onclick="closeBulkModal()">&times;</button>
            </div>
            <form id="bulkForm">
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" id="bulkDate" class="form-input" required value="<?php echo date('Y-m-d'); ?>" />
                </div>
                <div class="form-group">
                    <label class="form-label">Type</label>
                    <select id="bulkType" class="form-select" required onchange="bulkUpdateCategoryOptions()">
                        <option value="">Select Type</option>
                        <option value="INCOME">Income</option>
                        <option value="EXPENSE">Expense</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select id="bulkCategory" class="form-select" required onchange="bulkHandleCategoryChange()">
                        <option value="">Select Category</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Mode</label>
                    <select id="bulkPaymentMode" class="form-select" required>
                        <option value="CASH">Cash</option>
                        <option value="G PAY">G Pay</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Member Type</label>
                    <select id="bulkMemberType" class="form-select" onchange="loadBulkMembers()">
                        <option value="REGULAR">Regular Members</option>
                        <option value="SAHAKARI">Sahakari Members</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Amount (applied to all by default)</label>
                    <input type="number" id="bulkAmount" class="form-input" min="0" step="0.01" value="0" />
                    <small class="help">If you need per-member amounts, you can override in the list below.</small>
                </div>

                <div class="form-group" id="bulkOtherExpenseGroup" style="display:none;">
                    <label class="form-label">Other Expense Detail</label>
                    <input type="text" id="bulkOtherExpenseDetail" class="form-input" />
                </div>

                <div class="form-group">
                    <label class="form-label">Select Members (Bulk Enabled)</label>
                    <div id="bulkMembersList"
                        style="max-height:250px; overflow:auto; border:1px solid #e5e7eb; padding:8px; border-radius:10px;">
                        Loading members...
                    </div>
                    <small class="help">You can uncheck members or set per-member amount in the input next to each
                        name.</small>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeBulkModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Bulk</button>
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

        document.querySelectorAll('.menu .menu-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (window.matchMedia('(max-width: 1023.98px)').matches) closeSidebar();
            });
        });

        const userId = <?php echo (int) $user_id; ?>;
        let allTransactions = [];
        let filteredTransactions = [];
        let currentView = 'recent';

        function computeAdvanceUntil(monthlyFeeAdv, monthlyFee) {
            const adv = Number(monthlyFeeAdv || 0);
            const fee = Number(monthlyFee || 0);
            if (!fee || !adv || adv <= 0) return null;

            const months = Math.floor(adv / fee);
            if (months <= 0) return null;

            const today = new Date();
            const endDate = new Date(today.getFullYear(), today.getMonth() + months - 1, 1);
            const monthName = endDate.toLocaleString('en-US', { month: 'long' });
            const year = endDate.getFullYear();
            return {
                text: `Until ${monthName} ${year}`,
                months: months
            };
        }

        let searchTimer = null;
        function debouncedSearch(q) {
            clearTimeout(searchTimer);

            const value = (q || '').trim();
            const isNumeric = /^[0-9]+$/.test(value);

            if (!value || (!isNumeric && value.length < 2)) {
                const dr = document.getElementById('donorResults');
                if (dr) dr.style.display = 'none';
                return;
            }

            searchTimer = setTimeout(() => searchMembers(value), 300);
        }

        function searchMembers(q) {
            const resultsBox = document.getElementById('donorResults');
            resultsBox.style.display = 'block';
            resultsBox.innerHTML = '<div style="padding:10px;">Searching...</div>';

            fetch(`finance-api.php?action=search_members&q=${encodeURIComponent(q)}`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        resultsBox.innerHTML = '<div style="padding:10px;color:#ef4444;">Search failed</div>';
                        return;
                    }
                    const items = data.results || [];
                    if (items.length === 0) {
                        resultsBox.innerHTML = '<div style="padding:10px;color:#6b7280;">No results</div>';
                        return;
                    }
                    resultsBox.innerHTML = items.map(it => {
                        const display = (it.display || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        const personName = (it.person_name || '').replace(/"/g, '&quot;');
                        const addressEnc = encodeURIComponent(it.address || '');
                        const phone = (it.phone || '').replace(/"/g, '&quot;');
                        const totalDue = it.total_due !== undefined ? it.total_due : '';
                        const monthlyFeeAdv = it.monthly_fee_adv !== undefined ? it.monthly_fee_adv : '';
                        const monthlyFee = it.monthly_fee !== undefined ? it.monthly_fee : '';
                        const memberNumber = (it.member_number !== undefined && it.member_number !== null) ? String(it.member_number) : '';
                        const personType = it.person_type || '';
                        const personId = it.person_id ? it.person_id : '';
                        const headId = it.head_id ? it.head_id : '';

                        return `<div class="donor-row" style="padding:10px;border-bottom:1px solid #f3f4f6;cursor:pointer;"
                                 data-person-type="${personType}"
                                 data-person-id="${personId}"
                                 data-head-id="${headId}"
                                 data-person-name="${personName}"
                                 data-address="${addressEnc}"
                                 data-phone="${phone}"
                                 data-total-due="${totalDue}"
                                 data-monthly-fee-adv="${monthlyFeeAdv}"
                                 data-monthly-fee="${monthlyFee}"
                                 data-member-number="${memberNumber}">
                                ${display}
                            </div>`;
                    }).join('');

                    resultsBox.querySelectorAll('.donor-row').forEach(el => {
                        el.addEventListener('click', function () {
                            const personType = this.getAttribute('data-person-type') || '';
                            const personId = this.getAttribute('data-person-id') || '';
                            const headId = this.getAttribute('data-head-id') || '';
                            const personName = this.getAttribute('data-person-name');
                            const addrEnc = this.getAttribute('data-address') || '';
                            const phone = this.getAttribute('data-phone') || '';
                            const totalDue = this.getAttribute('data-total-due') || '0';
                            const monthlyFeeAdv = this.getAttribute('data-monthly-fee-adv') || '0';
                            const monthlyFee = this.getAttribute('data-monthly-fee') || '0';
                            const memberNumber = this.getAttribute('data-member-number') || '';

                            let donorMemberId = headId;

                            document.getElementById('donor_member_id').value = donorMemberId;
                            document.getElementById('donor_person_name').value = personName;
                            document.getElementById('donorSearch').value = this.textContent.trim();
                            resultsBox.style.display = 'none';

                            const donorInfoBox = document.getElementById('donorInfoBox');
                            if (donorInfoBox) {
                                const addr = addrEnc ? decodeURIComponent(addrEnc) : '';

                                let advanceLine = '';
                                const advInfo = computeAdvanceUntil(monthlyFeeAdv, monthlyFee);
                                if (advInfo) {
                                    advanceLine = `<div><strong>Advance Covers:</strong> ${escapeHtmlBasic(advInfo.text)} (approx. ${advInfo.months} month(s))</div>`;
                                }

                                let idLabelHtml;
                                if (memberNumber) {
                                    idLabelHtml = `<div><strong>Member Number:</strong> ${escapeHtmlBasic(memberNumber)}</div>`;
                                } else if (donorMemberId) {
                                    idLabelHtml = `<div><strong>Member ID:</strong> ${escapeHtmlBasic(donorMemberId)}</div>`;
                                } else {
                                    idLabelHtml = `<div><strong>Member:</strong> -</div>`;
                                }

                                donorInfoBox.innerHTML = `
                                    ${idLabelHtml}
                                    <div><strong>Address:</strong> ${escapeHtmlBasic(addr || '-')}</div>
                                    <div><strong>Phone:</strong> ${escapeHtmlBasic(phone || '-')}</div>
                                    <div><strong>Total Due:</strong> ₹${Number(totalDue || 0).toLocaleString('en-IN')}</div>
                                    <div><strong>Monthly Fee Advance:</strong> ₹${Number(monthlyFeeAdv || 0).toLocaleString('en-IN')}</div>
                                    ${advanceLine}
                                `;
                                donorInfoBox.style.display = 'block';
                            }
                        });
                    });
                })
                .catch(err => {
                    console.error('Search error:', err);
                    resultsBox.innerHTML = '<div style="padding:10px;color:#ef4444;">Error</div>';
                });
        }

        function tidyFormBeforeSubmit(form) {
            [...form.querySelectorAll('[required]')].forEach(el => {
                const style = getComputedStyle(el);
                const isNotFocusable = style.display === 'none' || style.visibility === 'hidden' || el.disabled || el.closest('fieldset[disabled]');
                if (isNotFocusable) {
                    el.dataset._wasRequired = '1';
                    el.required = false;
                    el.disabled = true;
                } else if (el.dataset._wasRequired) {
                    el.required = true;
                    el.disabled = false;
                    delete el.dataset._wasRequired;
                }
            });
        }

        (function attachTidySubmit() {
            const form = document.getElementById('transactionForm');
            if (!form) return;
            form.addEventListener('submit', function (e) {
                tidyFormBeforeSubmit(form);
            }, true);
        })();

        function exportToExcel() {
            const exportBtn = document.getElementById('exportBtn');
            const originalText = exportBtn.innerHTML;

            exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
            exportBtn.disabled = true;

            const dateFrom = document.getElementById('filterDateFrom').value;
            const dateTo = document.getElementById('filterDateTo').value;
            const typeFilter = document.getElementById('typeFilter').value;
            const categoryFilter = document.getElementById('categoryFilter').value;
            const paymentModeFilter = document.getElementById('paymentModeFilter').value;

            const params = new URLSearchParams({
                user_id: userId,
                date_from: dateFrom || '',
                date_to: dateTo || '',
                type_filter: typeFilter,
                category_filter: categoryFilter,
                payment_mode_filter: paymentModeFilter
            });

            fetch(`finance-api.php?action=export_excel&${params}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    exportBtn.innerHTML = originalText;
                    exportBtn.disabled = false;

                    if (data.success && data.export_url) {
                        const link = document.createElement('a');
                        link.href = data.export_url;
                        link.download = '';
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                    } else {
                        alert('Error: ' + (data.message || 'Failed to generate export'));
                    }
                })
                .catch(err => {
                    console.error('Export error:', err);
                    exportBtn.innerHTML = originalText;
                    exportBtn.disabled = false;
                    alert('Failed to export: ' + err.message);
                });
        }

        document.addEventListener('DOMContentLoaded', function () {
            loadTransactions();

            // Set default dates for filter
            const sixMonthsAgo = new Date();
            sixMonthsAgo.setMonth(sixMonthsAgo.getMonth() - 6);
            document.getElementById('filterDateFrom').value = sixMonthsAgo.toISOString().slice(0, 10);

            const today = new Date();
            const year = today.getFullYear();
            const month = String(today.getMonth() + 1).padStart(2, '0');
            const day = String(today.getDate()).padStart(2, '0');
            document.getElementById('filterDateTo').value = `${year}-${month}-${day}`;

            document.getElementById('transactionForm').addEventListener('submit', function (e) {
                e.preventDefault();
                saveTransaction();
            });

            document.addEventListener('click', function (e) {
                const dr = document.getElementById('donorResults');
                if (!dr) return;
                if (!e.target.closest('#donorSelectorGroup')) {
                    dr.style.display = 'none';
                }
            });

            const staffSelect = document.getElementById('staffSalarySelect');
            const salaryAmountType = document.getElementById('salaryAmountType');
            if (staffSelect) { staffSelect.disabled = true; staffSelect.required = false; }
            if (salaryAmountType) { salaryAmountType.disabled = true; salaryAmountType.required = false; }

            document.getElementById('bulkForm').addEventListener('submit', handleBulkSubmit);
        });

        function showRecentTransactions() {
            currentView = 'recent';
            updateViewToggle();
            document.getElementById('transactionsTitle').textContent = 'Recent Transactions';
            document.getElementById('filterControls').style.display = 'flex';
            applyFilters();
        }

        function showAllTransactions() {
            currentView = 'all';
            updateViewToggle();
            document.getElementById('transactionsTitle').textContent = 'All Transactions';
            document.getElementById('filterControls').style.display = 'flex';
            applyFilters();
        }

        function updateViewToggle() {
            const recentBtn = document.querySelector('.view-toggle .btn:nth-child(1)');
            const allBtn = document.querySelector('.view-toggle .btn:nth-child(2)');
            if (currentView === 'recent') {
                recentBtn.classList.add('active');
                allBtn.classList.remove('active');
            } else {
                recentBtn.classList.remove('active');
                allBtn.classList.add('active');
            }
        }

        function openModal() {
            document.getElementById('entryModal').classList.add('active');
            resetEntryForm();
        }

        function closeModal() {
            document.getElementById('entryModal').classList.remove('active');
        }

        function resetEntryForm() {
            const form = document.getElementById('transactionForm');
            form.reset();
            document.getElementById('entryDate').value = new Date().toISOString().slice(0, 10);

            document.getElementById('yearSelectGroup').style.display = 'none';
            document.getElementById('entryYear').required = false;

            document.getElementById('donor_member_id').value = '';
            document.getElementById('donor_person_name').value = '';
            document.getElementById('donorSearch').value = '';
            document.getElementById('donorSelectorGroup').style.display = 'none';

            const donorInfoBox = document.getElementById('donorInfoBox');
            if (donorInfoBox) {
                donorInfoBox.innerHTML = '';
                donorInfoBox.style.display = 'none';
            }

            const nonMemberInput = document.getElementById('donorNonMemberName');
            if (nonMemberInput) nonMemberInput.value = '';

            const donorModeRadioMember = document.querySelector('input[name="donorMode"][value="member"]');
            if (donorModeRadioMember) donorModeRadioMember.checked = true;
            setDonorMode('member');

            document.getElementById('entryPaymentMode').value = 'CASH';
            document.getElementById('otherExpenseDetailGroup').style.display = 'none';
            document.getElementById('otherExpenseDetail').value = '';

            // Reset Booking Link
            document.getElementById('linkedBookingGroup').style.display = 'none';
            document.getElementById('linkedBookingSelect').innerHTML = '<option value="">-- Manual Entry --</option>';
            // Reset Category Filter
            const rcf = document.getElementById('rentalAssetCategoryFilter');
            if (rcf) rcf.innerHTML = '<option value="">All Categories</option>';

            document.getElementById('entryAssetBookingId').value = '';
            // Reset Asset Tax Link
            document.getElementById('linkedAssetTaxGroup').style.display = 'none';
            document.getElementById('linkedAssetTaxSelect').innerHTML = '<option value="">-- Manual Entry --</option>';
            document.getElementById('entryAssetId').value = '';
            document.getElementById('staffSalaryGroup').style.display = 'none';
            document.getElementById('salaryAmountTypeGroup').style.display = 'none';
            document.getElementById('customSalaryAmountGroup').style.display = 'none';

            const generalAmountGroup = document.getElementById('generalAmountGroup');
            if (generalAmountGroup) generalAmountGroup.style.display = 'block';
            document.getElementById('entryAmount').value = '';

            const dr = document.getElementById('donorResults');
            if (dr) dr.style.display = 'none';

            const categorySelect = document.getElementById('entryCategory');
            categorySelect.innerHTML = '<option value="">Select Category</option>';
            document.getElementById('entryType').focus();

            const staffSelect = document.getElementById('staffSalarySelect');
            const salaryAmountType = document.getElementById('salaryAmountType');
            if (staffSelect) { staffSelect.disabled = true; staffSelect.required = false; staffSelect.value = ''; }
            if (salaryAmountType) { salaryAmountType.disabled = true; salaryAmountType.required = false; salaryAmountType.value = 'full'; }

        }

        function saveTransaction() {
            const type = document.getElementById('entryType').value;
            const categoryRaw = document.getElementById('entryCategory').value || '';
            const category = categoryRaw.toUpperCase();
            const year = document.getElementById('entryYear').value;

            let finalCategory = category;
            if (year && needsYear(category)) finalCategory = `${category} ${year}`;

            const amount = parseFloat(document.getElementById('entryAmount').value || 0);
            if (isNaN(amount) || amount <= 0) {
                alert('Please enter a valid amount');
                return;
            }

            let staff_id = null;
            if (type === 'EXPENSE' && category === 'SALARY') {
                const staffSelect = document.getElementById('staffSalarySelect');
                if (!staffSelect.value) {
                    alert('Please select a staff member for salary payment');
                    return;
                }
                staff_id = staffSelect.value;
            }

            let donor_member_id = null;
            let donor_details = null;
            const donorExemptCategories = ['FRIDAY INCOME', 'ROOM RENT', 'CASH DEPOSIT', 'NERCHE PETTI'];

            if (!donorExemptCategories.includes(category) && type === 'INCOME') {
                const donorModeEl = document.querySelector('input[name="donorMode"]:checked');
                const donorMode = donorModeEl ? donorModeEl.value : 'member';

                if (donorMode === 'member') {
                    const donorHeadVal = document.getElementById('donor_member_id').value;
                    const donorPersonNameVal = document.getElementById('donor_person_name').value;
                    if (donorHeadVal) donor_member_id = parseInt(donorHeadVal);
                    if (donorPersonNameVal) donor_details = donorPersonNameVal;
                } else {
                    const nonMemberName = (document.getElementById('donorNonMemberName').value || '').trim();
                    if (nonMemberName) {
                        donor_member_id = null;
                        donor_details = nonMemberName;
                    }
                }
            }

            const payment_mode = document.getElementById('entryPaymentMode').value || 'CASH';

            // Get asset_id (for Asset Tax)
            let asset_id = null;
            const assetIdInput = document.getElementById('entryAssetId');
            if (assetIdInput && assetIdInput.value) {
                asset_id = parseInt(assetIdInput.value);
            }

            // Get asset_booking_id (for Asset Rent)
            let asset_booking_id = null;
            const bookingIdInput = document.getElementById('entryAssetBookingId');
            if (bookingIdInput && bookingIdInput.value) {
                asset_booking_id = parseInt(bookingIdInput.value);
            }

            let other_expense_detail = null;
            if (type === 'EXPENSE' && needsDetail(category)) {
                const v = (document.getElementById('otherExpenseDetail').value || '').trim();
                if (!v) {
                    alert('Please specify the Other Expense Detail');
                    return;
                }
                other_expense_detail = v;
            }

            const data = {
                user_id: userId,
                date: document.getElementById('entryDate').value,
                type: type,
                category: finalCategory,
                amount: amount,
                description: document.getElementById('entryDescription').value,
                donor_member_id: donor_member_id,
                donor_details: donor_details,
                payment_mode: payment_mode,
                other_expense_detail: other_expense_detail,
                staff_id: staff_id,
                asset_id: asset_id,
                asset_booking_id: asset_booking_id
            };

            const saveBtn = document.querySelector('#transactionForm button[type="submit"]');
            const originalText = saveBtn.textContent;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            saveBtn.disabled = true;

            fetch('finance-api.php?action=save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(result => {
                    saveBtn.innerHTML = originalText;
                    saveBtn.disabled = false;

                    if (result.success) {
                        loadTransactions();
                        resetEntryForm();

                        try {
                            const docLabel = type === 'EXPENSE' ? 'voucher' : 'receipt';
                            const wantPrint = confirm(`Transaction saved successfully. Do you want to print the ${docLabel} now?`);
                            if (wantPrint) {
                                if (result.receipt_url) {
                                    window.open(result.receipt_url, '_blank');
                                } else if (result.id) {
                                    openReceipt(result.id);
                                }
                            }
                        } catch (e) {
                            console.error('Print error:', e);
                        }

                    } else {
                        alert('Error: ' + (result.message || 'Unknown error'));
                    }
                })
                .catch(err => {
                    console.error('Save error:', err);
                    saveBtn.innerHTML = originalText;
                    saveBtn.disabled = false;
                    alert('Failed to save transaction: ' + err.message);
                });
        }

        function needsYear(category) {
            const yearCategories = ['NABIDHINAM', 'CHERIYA PERUNAL', 'BALI PERUNAL', 'BARATH'];
            return yearCategories.includes(category);
        }

        function needsDetail(category) {
            const detailCategories = [
                'OFFICE EXPENSE', 'PURCHASE', 'BUILDING EXPENSE', 'STATIONARY EXPENSE',
                'ELECTRICITY BILL', 'USTHAD FOOD', 'CLEANING EXPENSE', 'CHERIYA PERUNAL',
                'BALI PERUNAL', 'NABIDHINAM', 'OTHER EXPENSES'
            ];
            return detailCategories.includes(category);
        }

        function getDetailLabel(category) {
            if (category === 'USTHAD FOOD') return 'Which Hotel / Payee';
            if (category === 'CLEANING EXPENSE') return 'Paid To / Details';
            if (category === 'ELECTRICITY BILL') return 'Consumer No / Details';
            return 'Recipient';
        }

        function updateCategoryOptions() {
            const type = document.getElementById('entryType').value;
            const categorySelect = document.getElementById('entryCategory');
            const yearGroup = document.getElementById('yearSelectGroup');
            const staffSalaryGroup = document.getElementById('staffSalaryGroup');

            categorySelect.innerHTML = '<option value="">Select Category</option>';
            yearGroup.style.display = 'none';
            document.getElementById('entryYear').value = '';
            staffSalaryGroup.style.display = 'none';

            if (type === 'INCOME') {
                const incomeCategories = [
                    'MONTHLY FEE', 'DONATION', 'FRIDAY INCOME', 'NABIDHINAM', 'CHERIYA PERUNAL',
                    'BALI PERUNAL', 'ASSET RENT', 'SAHAKARI', 'NERCHE PETTI', 'BARATH', 'LELAM', 'FOOD INCOME', 'CASH DEPOSIT'
                ];
                incomeCategories.forEach(cat => {
                    const opt = document.createElement('option');
                    opt.value = cat;
                    opt.textContent = cat;
                    categorySelect.appendChild(opt);
                });
            } else if (type === 'EXPENSE') {
                const expenseCategories = [
                    'SALARY', 'OFFICE EXPENSE', 'PURCHASE', 'ASSET TAX', 'BUILDING EXPENSE', 'STATIONARY EXPENSE', 'ELECTRICITY BILL',
                    'USTHAD FOOD', 'CLEANING EXPENSE', 'CHERIYA PERUNAL', 'BALI PERUNAL', 'NABIDHINAM',
                    'BANK DEPOSITE', 'OTHER EXPENSES'
                ];
                expenseCategories.forEach(cat => {
                    const opt = document.createElement('option');
                    opt.value = cat;
                    opt.textContent = cat;
                    categorySelect.appendChild(opt);
                });
            }
        }

        function handleCategoryChange() {
            const category = (document.getElementById('entryCategory').value || '').toUpperCase();
            const entryType = document.getElementById('entryType').value;
            const yearGroup = document.getElementById('yearSelectGroup');
            const donorGroup = document.getElementById('donorSelectorGroup');
            const otherGroup = document.getElementById('otherExpenseDetailGroup');
            const staffSalaryGroup = document.getElementById('staffSalaryGroup');
            const donorInfoBox = document.getElementById('donorInfoBox');
            const generalAmountGroup = document.getElementById('generalAmountGroup');
            const linkedBookingGroup = document.getElementById('linkedBookingGroup');
            const linkedAssetTaxGroup = document.getElementById('linkedAssetTaxGroup');

            // Reset both Booking and Asset Tax Group display first
            linkedBookingGroup.style.display = 'none';
            linkedAssetTaxGroup.style.display = 'none';

            if (needsYear(category)) {
                yearGroup.style.display = 'block';
                document.getElementById('entryYear').required = true;
                populateYearDropdown();
            } else {
                yearGroup.style.display = 'none';
                document.getElementById('entryYear').required = false;
                document.getElementById('entryYear').value = '';
            }

            const donorExemptCategories = ['FRIDAY INCOME', 'CASH DEPOSIT', 'NERCHE PETTI'];
            const nonMemberRadio = document.querySelector('input[name="donorMode"][value="non"]');
            const nonMemberLabel = nonMemberRadio ? nonMemberRadio.closest('label') : null;
            const memberRadio = document.querySelector('input[name="donorMode"][value="member"]');

            if (entryType === 'INCOME' && !donorExemptCategories.includes(category)) {
                donorGroup.style.display = 'block';

                if (category === 'MONTHLY FEE') {
                    if (nonMemberLabel) nonMemberLabel.style.display = 'none';
                    if (memberRadio) memberRadio.checked = true;
                    setDonorMode('member');
                } else {
                    if (nonMemberLabel) nonMemberLabel.style.display = '';
                }
            } else {
                donorGroup.style.display = 'none';
                document.getElementById('donor_member_id').value = '';
                document.getElementById('donor_person_name').value = '';
                document.getElementById('donorSearch').value = '';
                const nonMemberInput = document.getElementById('donorNonMemberName');
                if (nonMemberInput) nonMemberInput.value = '';
                if (donorInfoBox) {
                    donorInfoBox.innerHTML = '';
                    donorInfoBox.style.display = 'none';
                }
                if (memberRadio) memberRadio.checked = true;
                setDonorMode('member');
                if (nonMemberLabel) nonMemberLabel.style.display = '';
            }

            // Independent check for Asset Rent to show rental asset picker
            if (category === 'ASSET RENT' && entryType === 'INCOME') {
                linkedBookingGroup.style.display = 'block';
                fetchRentalAssets();
            }

            // Show Asset Tax Dropdown for Asset Tax Expense
            if (category === 'ASSET TAX' && entryType === 'EXPENSE') {
                linkedAssetTaxGroup.style.display = 'block';
                fetchTaxableAssets();
            }

            const isOtherExpense = entryType === 'EXPENSE' && needsDetail(category);
            otherGroup.style.display = isOtherExpense ? 'block' : 'none';

            if (isOtherExpense) {
                const labelEl = otherGroup.querySelector('label');
                if (labelEl) labelEl.textContent = getDetailLabel(category);
            } else {
                document.getElementById('otherExpenseDetail').value = '';
            }

            const isSalaryExpense = entryType === 'EXPENSE' && category === 'SALARY';
            staffSalaryGroup.style.display = isSalaryExpense ? 'block' : 'none';

            const staffSelect = document.getElementById('staffSalarySelect');
            const salaryAmountType = document.getElementById('salaryAmountType');

            if (isSalaryExpense) {
                if (staffSelect) { staffSelect.required = true; staffSelect.disabled = false; }
                if (salaryAmountType) { salaryAmountType.required = true; salaryAmountType.disabled = false; }
                document.getElementById('salaryAmountTypeGroup').style.display = 'block';
                document.getElementById('customSalaryAmountGroup').style.display = 'none';
                if (generalAmountGroup) generalAmountGroup.style.display = 'block';
                loadStaffForSalary();
            } else {
                if (staffSelect) { staffSelect.required = false; staffSelect.disabled = true; staffSelect.value = ''; }
                if (salaryAmountType) { salaryAmountType.required = false; salaryAmountType.disabled = true; salaryAmountType.value = 'full'; }
                document.getElementById('salaryAmountTypeGroup').style.display = 'none';
                document.getElementById('customSalaryAmountGroup').style.display = 'none';
                if (generalAmountGroup) generalAmountGroup.style.display = 'block';
            }
        }

        // --- Asset Rental Linking Logic ---
        let allRentalAssets = [];
        let rentalAssetCategories = new Set();

        function fetchRentalAssets() {
            const sel = document.getElementById('linkedBookingSelect');
            const catFilter = document.getElementById('rentalAssetCategoryFilter');

            sel.innerHTML = '<option value="">Loading...</option>';
            catFilter.innerHTML = '<option value="">All Categories</option>';

            fetch('finance-api.php?action=get_rental_assets&user_id=' + encodeURIComponent(userId))
                .then(r => r.json())
                .then(data => {
                    sel.innerHTML = '<option value="">-- Select Rental Asset --</option>';
                    if (data.success && Array.isArray(data.assets)) {
                        allRentalAssets = data.assets;

                        // Populate categories
                        rentalAssetCategories.clear();
                        allRentalAssets.forEach(a => {
                            if (a.category_name) rentalAssetCategories.add(a.category_name);
                        });

                        // Fill Filter Dropdown
                        const sortedCats = Array.from(rentalAssetCategories).sort();
                        sortedCats.forEach(cat => {
                            const opt = document.createElement('option');
                            opt.value = cat;
                            opt.textContent = cat;
                            catFilter.appendChild(opt);
                        });

                        // Initial Populate of Assets
                        renderRentalAssets();
                    }
                })
                .catch(err => {
                    console.error('Error fetching rental assets:', err);
                    sel.innerHTML = '<option value="">Error loading assets</option>';
                });
        }

        function filterRentalAssetsByCategory() {
            renderRentalAssets();
        }

        function renderRentalAssets() {
            const sel = document.getElementById('linkedBookingSelect');
            const filterVal = document.getElementById('rentalAssetCategoryFilter').value;

            // Keep the "Manual Entry" or "Select" as first option?
            // If user wanted "Manual Entry", let's keep it index 0. 
            // However, usually we want users to link to an asset. 
            sel.innerHTML = '<option value="">-- Select Rental Asset --</option>';

            const filtered = filterVal
                ? allRentalAssets.filter(a => a.category_name === filterVal)
                : allRentalAssets;

            filtered.forEach(a => {
                const opt = document.createElement('option');
                opt.value = a.id;
                opt.textContent = `${a.name} (${a.asset_code})`;
                sel.appendChild(opt);
            });
        }

        function onRentalAssetSelect() {
            const sel = document.getElementById('linkedBookingSelect');
            const assetId = sel.value;
            const hiddenAssetId = document.getElementById('entryAssetId');
            const hiddenBookingId = document.getElementById('entryAssetBookingId');
            const descInput = document.getElementById('entryDescription');

            // Asset Rent DOES NOT link to bookings anymore, but directly to Asset ID
            // So we use entryAssetId.
            // entryAssetBookingId should be CLEARED.

            if (!assetId) {
                hiddenAssetId.value = '';
                hiddenBookingId.value = '';
                return;
            }

            hiddenAssetId.value = assetId;
            hiddenBookingId.value = ''; // No booking ID for ad-hoc rent

            const asset = allRentalAssets.find(a => a.id == assetId);
            if (asset) {
                // Description update
                descInput.value = `Asset Rent: ${asset.name}`;
                // We do NOT auto-fill amount as rent is variable/negotiated per transaction usually, 
                // unless we had a 'default_rent' in DB which we don't.
            }
        }
        // --- Asset Tax Linking Logic ---
        function fetchTaxableAssets() {
            const sel = document.getElementById('linkedAssetTaxSelect');
            sel.innerHTML = '<option value="">Loading...</option>';

            fetch('finance-api.php?action=get_taxable_assets&user_id=' + encodeURIComponent(userId))
                .then(r => r.json())
                .then(data => {
                    sel.innerHTML = '<option value="">-- Manual Entry --</option>';
                    if (data.success && Array.isArray(data.assets)) {
                        taxableAssets = data.assets;
                        taxableAssets.forEach(asset => {
                            const opt = document.createElement('option');
                            opt.value = asset.id;
                            opt.textContent = `${asset.name} (${asset.asset_code}) - ₹${asset.taxable_amount}`;
                            sel.appendChild(opt);
                        });
                    }
                })
                .catch(err => {
                    console.error('Error fetching taxable assets:', err);
                    sel.innerHTML = '<option value="">Error loading assets</option>';
                });
        }

        function onAssetTaxSelect() {
            const sel = document.getElementById('linkedAssetTaxSelect');
            const assetId = sel.value;
            const hiddenId = document.getElementById('entryAssetId');
            const amountInput = document.getElementById('entryAmount');
            const descInput = document.getElementById('entryDescription');

            if (!assetId) {
                hiddenId.value = '';
                return; // User selected Manual Entry
            }

            hiddenId.value = assetId;

            const asset = taxableAssets.find(a => a.id == assetId);
            if (asset) {
                amountInput.value = asset.taxable_amount || 0;
                descInput.value = `Asset Tax: ${asset.name} (${asset.asset_code})`;
            }
        }

        function loadStaffForSalary() {
            const staffSelect = document.getElementById('staffSalarySelect');
            const amountTypeGroup = document.getElementById('salaryAmountTypeGroup');

            staffSelect.innerHTML = '<option value="">Select Staff</option>';
            amountTypeGroup.style.display = 'none';

            fetch(`finance-api.php?action=get_staff_for_salary&user_id=${encodeURIComponent(userId)}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.staff.length > 0) {
                        data.staff.forEach(staff => {
                            const opt = document.createElement('option');
                            opt.value = staff.staff_id;
                            opt.textContent = `${staff.name} - ₹${staff.fixed_salary} (${staff.salary_payment_status})`;
                            opt.setAttribute('data-fixed-salary', staff.fixed_salary);
                            staffSelect.appendChild(opt);
                        });
                        amountTypeGroup.style.display = 'block';
                    } else {
                        staffSelect.innerHTML = '<option value="">No active staff found</option>';
                        amountTypeGroup.style.display = 'none';
                    }
                })
                .catch(err => {
                    console.error('Error loading staff:', err);
                    staffSelect.innerHTML = '<option value="">Error loading staff</option>';
                    amountTypeGroup.style.display = 'none';
                });
        }

        function handleStaffSelection() {
            const staffSelect = document.getElementById('staffSalarySelect');
            const amountTypeGroup = document.getElementById('salaryAmountTypeGroup');
            const selectedOption = staffSelect.options[staffSelect.selectedIndex];

            if (selectedOption && selectedOption.value) {
                amountTypeGroup.style.display = 'block';
                const fixedSalary = parseFloat(selectedOption.getAttribute('data-fixed-salary') || 0);
                document.getElementById('fixedSalaryAmount').textContent = fixedSalary.toFixed(2);
                document.getElementById('entryAmount').value = fixedSalary;
            } else {
                amountTypeGroup.style.display = 'none';
            }
        }

        function handleSalaryAmountType() {
            const amountType = document.getElementById('salaryAmountType').value;
            const customAmountGroup = document.getElementById('customSalaryAmountGroup');
            const generalAmountGroup = document.getElementById('generalAmountGroup');
            const staffSelect = document.getElementById('staffSalarySelect');
            const selectedOption = staffSelect.options[staffSelect.selectedIndex];

            if (amountType === 'full') {
                if (customAmountGroup) customAmountGroup.style.display = 'none';
                if (generalAmountGroup) generalAmountGroup.style.display = 'block';

                const fixedSalary = parseFloat(selectedOption ? (selectedOption.getAttribute('data-fixed-salary') || 0) : 0);
                document.getElementById('entryAmount').value = fixedSalary;
            } else {
                if (customAmountGroup) customAmountGroup.style.display = 'block';
                if (generalAmountGroup) generalAmountGroup.style.display = 'none';

                document.getElementById('customSalaryAmount').value = '';
                document.getElementById('entryAmount').value = '';
            }
        }

        function populateYearDropdown() {
            const yearSelect = document.getElementById('entryYear');
            yearSelect.innerHTML = '<option value="">Select Year</option>';
            const currentYear = new Date().getFullYear();
            const startYear = currentYear - 10;
            const endYear = currentYear + 5;
            for (let y = endYear; y >= startYear; y--) {
                const opt = document.createElement('option');
                opt.value = y;
                opt.textContent = y;
                yearSelect.appendChild(opt);
            }
        }

        function deleteTransaction(id) {
            if (!confirm('Are you sure you want to delete this transaction?')) return;

            fetch('finance-api.php?action=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id, user_id: userId })
            })
                .then(r => r.json())
                .then(result => {
                    if (result.success) {
                        loadTransactions();
                        alert('Transaction deleted successfully!');
                    } else {
                        alert('Error: ' + (result.message || 'Unknown error'));
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Failed to delete transaction');
                });
        }

        function openReceipt(id) {
            const url = `finance-api.php?action=receipt&id=${encodeURIComponent(id)}&user_id=${encodeURIComponent(userId)}`;
            window.open(url, '_blank');
        }

        function loadTransactions() {
            document.getElementById('transactionsTableBody').innerHTML =
                '<tr><td colspan="9" class="no-data">Loading transactions...</td></tr>';

            fetch('finance-api.php?action=get&user_id=' + encodeURIComponent(userId) + '&t=' + new Date().getTime())
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        try {
                            allTransactions = Array.isArray(data.transactions) ? data.transactions : [];
                            filteredTransactions = [...allTransactions];
                            document.getElementById('count').textContent = allTransactions.length;

                            populateCategoryFilter(allTransactions);

                            // Apply default date filter (Start from the very first transaction)
                            if (allTransactions.length > 0) {
                                // API returns sorted by date DESC, so the last item is the oldest
                                const oldest = allTransactions[allTransactions.length - 1];
                                document.getElementById('filterDateFrom').value = oldest.date;
                            } else {
                                // Fallback to start of current month if no transactions
                                const startOfMonth = new Date();
                                startOfMonth.setDate(1);
                                document.getElementById('filterDateFrom').value = startOfMonth.toISOString().slice(0, 10);
                            }

                            const today = new Date();
                            const year = today.getFullYear();
                            const month = String(today.getMonth() + 1).padStart(2, '0');
                            const day = String(today.getDate()).padStart(2, '0');
                            document.getElementById('filterDateTo').value = `${year}-${month}-${day}`;

                            filterDashboard();
                        } catch (e) {
                            console.error('Render error:', e);
                            document.getElementById('transactionsTableBody').innerHTML =
                                '<tr><td colspan="9" class="no-data">Display Error: ' + e.message + '</td></tr>';
                        }
                    } else {
                        document.getElementById('transactionsTableBody').innerHTML =
                            '<tr><td colspan="9" class="no-data">Error loading transactions: ' + (data.message || 'Unknown error') + '</td></tr>';
                    }
                })
                .catch(err => {
                    console.error('Load transactions error:', err);
                    document.getElementById('transactionsTableBody').innerHTML =
                        '<tr><td colspan="9" class="no-data">Error loading transactions: ' + err.message + '</td></tr>';
                });
        }

        function filterDashboard() {
            const dateFromVal = document.getElementById('filterDateFrom').value;
            const dateToVal = document.getElementById('filterDateTo').value;

            let filtered = allTransactions;
            if (dateFromVal && dateToVal) {
                const dateFrom = new Date(dateFromVal);
                const dateTo = new Date(dateToVal);
                filtered = allTransactions.filter(t => {
                    const tDate = new Date(t.date);
                    return tDate >= dateFrom && tDate <= dateTo;
                });
            }

            updateStats(filtered);

            if (dateFromVal && dateToVal) {
                fetch(`finance-api.php?action=get_balance_by_mode&user_id=${encodeURIComponent(userId)}&from=${encodeURIComponent(dateFromVal)}&to=${encodeURIComponent(dateToVal)}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) updateBalanceStats(data.balances);
                    })
                    .catch(err => {
                        console.error('Error fetching balance by mode:', err);
                    });
            }

            applyFilters();
        }

        function formatAmountWithSuffix(amount) {
            // Convert to number if it's a string
            amount = parseFloat(amount) || 0;

            if (amount >= 10000000) { // 1 crore = 1,00,00,000
                const crores = amount / 10000000;
                return '₹' + crores.toFixed(crores >= 10 ? 0 : 1) + 'Cr';
            } else if (amount >= 100000) { // 1 lakh = 1,00,000
                const lakhs = amount / 100000;
                return '₹' + lakhs.toFixed(lakhs >= 10 ? 0 : 1) + 'L';
            } else if (amount >= 1000) { // 1 thousand = 1,000
                const thousands = amount / 1000;
                return '₹' + thousands.toFixed(thousands >= 10 ? 0 : 1) + 'K';
            } else {
                // For amounts less than 1000, show with 2 decimal places
                return '₹' + amount.toLocaleString('en-IN', {
                    minimumFractionDigits: amount % 1 !== 0 ? 2 : 0,
                    maximumFractionDigits: 2
                });
            }
        }

        function updateStats(transactions) {
            const income = transactions.filter(t => t.type === 'INCOME').reduce((s, t) => s + parseFloat(t.amount || 0), 0);
            const expense = transactions.filter(t => t.type === 'EXPENSE').reduce((s, t) => s + parseFloat(t.amount || 0), 0);
            const balance = income - expense;

            // Use the new formatting function
            document.getElementById('totalIncome').textContent = formatAmountWithSuffix(income);
            document.getElementById('totalExpense').textContent = formatAmountWithSuffix(expense);
            document.getElementById('currentBalance').textContent = formatAmountWithSuffix(balance);
        }

        function updateBalanceStats(balances) {
            let cashBalance = 0;
            let gpayBalance = 0;

            balances.forEach(balance => {
                if (balance.payment_mode === 'CASH') {
                    cashBalance = balance.balance;
                } else if (balance.payment_mode === 'G PAY') {
                    gpayBalance = balance.balance;
                }
            });

            // Use the new formatting function
            document.getElementById('cashBalance').textContent = formatAmountWithSuffix(cashBalance);
            document.getElementById('gpayBalance').textContent = formatAmountWithSuffix(gpayBalance);
        }

        function td(label, value) {
            return `<td data-label="${label}">${value}</td>`;
        }

        function escapeHtmlBasic(str) {
            return String(str).replace(/[&<>"']/g, s => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[s]));
        }

        function setDonorMode(mode) {
            const memberWrap = document.getElementById('donorMemberWrap');
            const nonMemberWrap = document.getElementById('donorNonMemberWrap');
            const donorInfoBox = document.getElementById('donorInfoBox');

            if (mode === 'member') {
                if (memberWrap) memberWrap.style.display = 'block';
                if (nonMemberWrap) nonMemberWrap.style.display = 'none';
                if (donorInfoBox) {
                    donorInfoBox.innerHTML = '';
                    donorInfoBox.style.display = 'none';
                }
                const nm = document.getElementById('donorNonMemberName');
                if (nm) nm.value = '';
            } else {
                if (memberWrap) memberWrap.style.display = 'none';
                if (nonMemberWrap) nonMemberWrap.style.display = 'block';

                document.getElementById('donor_member_id').value = '';
                document.getElementById('donor_person_name').value = '';
                document.getElementById('donorSearch').value = '';
                const dr = document.getElementById('donorResults');
                if (dr) dr.style.display = 'none';
                if (donorInfoBox) {
                    donorInfoBox.innerHTML = '';
                    donorInfoBox.style.display = 'none';
                }
            }
        }
        function displayTransactions(transactions, showActions = false) {
            const tbody = document.getElementById('transactionsTableBody');

            if (!transactions || transactions.length === 0) {
                const colSpan = showActions ? 9 : 8;
                tbody.innerHTML = `<tr><td colspan="${colSpan}" class="no-data">No transactions found</td></tr>`;
                return;
            }

            const rows = transactions.map(t => {
                // Create compact type badge
                const typeBadge = t.type === 'INCOME'
                    ? `<span class="badge badge-income" title="Income">INC</span>`
                    : `<span class="badge badge-expense" title="Expense">EXP</span>`;

                let rawDesc;

                if (t.category === 'OTHER EXPENSES' && t.other_expense_detail) {
                    rawDesc = (t.description ? (t.description + ' — ') : '') + t.other_expense_detail;
                } else {
                    rawDesc = t.description || '-';
                }

                // Truncate description for table view (keep full for receipt)
                const maxDescLength = 50; // Adjust as needed
                let displayDesc = '-';
                let fullDesc = rawDesc;

                if (rawDesc !== '-') {
                    const escapedDesc = escapeHtmlBasic(rawDesc);
                    if (escapedDesc.length > maxDescLength) {
                        displayDesc = escapedDesc.substring(0, maxDescLength) + '...';
                        // Store full description in data attribute for tooltip
                        displayDesc = `<span class="truncated-desc" title="${escapedDesc}" data-full-desc="${escapedDesc.replace(/"/g, '&quot;')}">${displayDesc}</span>`;
                    } else {
                        displayDesc = escapedDesc;
                    }
                    fullDesc = escapedDesc; // Store full escaped version
                }

                let linkedBadge = '';
                if (t.asset_id) {
                    linkedBadge = `<div><span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:10px;padding:2px 6px;white-space:nowrap;">Asset</span></div>`;
                } else if (t.asset_booking_id) {
                    linkedBadge = `<div><span class="badge" style="background:#dcfce7;color:#15803d;font-size:10px;padding:2px 6px;white-space:nowrap;">Rent</span></div>`;
                } else if (t.asset_maintenance_id) {
                    linkedBadge = `<div><span class="badge" style="background:#fce7f3;color:#be185d;font-size:10px;padding:2px 6px;white-space:nowrap;">Maint.</span></div>`;
                }

                const descContent = displayDesc + linkedBadge;

                const amt = Number(t.amount || 0);
                const amtFormatted = `₹${amt.toLocaleString('en-IN')}`;
                const receiptNo = t.receipt_no || '-';

                // Truncate category if too long
                const category = t.category || '';
                const displayCategory = category.length > 20
                    ? category.substring(0, 17) + '...'
                    : category;

                let donorColumn = '-';
                if (t.donor_member_id && t.donor_details) {
                    donorColumn = `<div style="white-space:nowrap;"><strong>#${escapeHtmlBasic(t.member_number || t.donor_member_id)}</strong></div>
                           <div style="white-space:normal;">${escapeHtmlBasic(t.donor_details)}</div>`;
                } else if (t.donor_details) {
                    donorColumn = `<div style="color:#6b7280;white-space:normal;">${escapeHtmlBasic(t.donor_details)}</div>
                           <div style="font-size:11px;color:#9ca3af;white-space:nowrap;">(Non-member)</div>`;
                }

                if (showActions) {
                    return `
            <tr>
                ${td('Receipt No', receiptNo)}
                ${td('Date', formatDate(t.date))}
                ${td('Type', typeBadge)}
                ${td('Category', escapeHtmlBasic(displayCategory))}
                ${td('Description', descContent)}
                ${td('Amount', amtFormatted)}
                ${td('Payment Mode', escapeHtmlBasic(t.payment_mode))}
                ${td('Donor', donorColumn)}
                ${td('Action', `
                    <button class="action-btn" onclick="openReceipt(${t.id})" title="View Receipt">🧾</button>
                    <button class="action-btn" onclick="deleteTransaction(${t.id})" title="Delete">🗑️</button>
                `)}
            </tr>`;
                } else {
                    return `
            <tr>
                ${td('Receipt No', receiptNo)}
                ${td('Date', formatDate(t.date))}
                ${td('Type', typeBadge)}
                ${td('Category', escapeHtmlBasic(displayCategory))}
                ${td('Description', descContent)}
                ${td('Amount', amtFormatted)}
                ${td('Payment Mode', escapeHtmlBasic(t.payment_mode))}
                ${td('Donor', donorColumn)}
            </tr>`;
                }
            });

            tbody.innerHTML = rows.join('');

            // Add click handler to expand truncated descriptions
            tbody.addEventListener('click', function (e) {
                const descElement = e.target.closest('.truncated-desc');
                if (descElement) {
                    e.preventDefault();
                    e.stopPropagation();

                    // Toggle between truncated and full view
                    if (descElement.classList.contains('expanded')) {
                        descElement.classList.remove('expanded');
                        const fullDesc = descElement.getAttribute('data-full-desc');
                        const truncated = fullDesc.length > 50 ? fullDesc.substring(0, 50) + '...' : fullDesc;
                        descElement.innerHTML = truncated;
                    } else {
                        descElement.classList.add('expanded');
                        const fullDesc = descElement.getAttribute('data-full-desc');
                        descElement.innerHTML = fullDesc;
                    }
                }
            });
        }
        function applyFilters() {
            const typeFilter = document.getElementById('typeFilter').value;
            const categoryFilter = document.getElementById('categoryFilter').value;
            const paymentModeFilter = document.getElementById('paymentModeFilter').value;
            const filterFrom = document.getElementById('filterDateFrom').value;
            const filterTo = document.getElementById('filterDateTo').value;

            filteredTransactions = allTransactions.filter(t => {
                const tDate = new Date(t.date);

                if (filterFrom) {
                    const fromDate = new Date(filterFrom);
                    if (tDate < fromDate) return false;
                }
                if (filterTo) {
                    const toDate = new Date(filterTo);
                    toDate.setHours(23, 59, 59, 999);
                    if (tDate > toDate) return false;
                }

                if (typeFilter !== 'all' && t.type !== typeFilter) return false;

                if (categoryFilter !== 'all' && t.category !== categoryFilter) return false;

                if (paymentModeFilter !== 'all' && t.payment_mode !== paymentModeFilter) return false;

                return true;
            });

            // Update stats based on filtered transactions
            updateStats(filteredTransactions);

            // Update balance stats based on filtered date range
            if (filterFrom && filterTo) {
                fetch(`finance-api.php?action=get_balance_by_mode&user_id=${encodeURIComponent(userId)}&from=${encodeURIComponent(filterFrom)}&to=${encodeURIComponent(filterTo)}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) updateBalanceStats(data.balances);
                    })
                    .catch(err => {
                        console.error('Error fetching balance by mode:', err);
                    });
            }

            if (currentView === 'recent') {
                // If user wants to see all, they should switch to 'All Transactions' tab or we increase this limit
                // checking if specific user requirement is to show ALL even in recent?
                // The user said "14 transactions... only 10 visible".
                // I will increase the 'recent' limit to 20 or just show all if filters are applied.

                // Better approach: If filters are active (dates changed from default, or category selected), show ALL match.
                // But typically 'recent' means limited.
                // Let's change the default "Recent" behavior to show 50 items to cover the 14.
                displayTransactions(filteredTransactions.slice(0, 50), true);
            } else {
                displayTransactions(filteredTransactions, true);
            }
        }

        function formatDate(dateString) {
            const date = new Date(dateString);
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        function openBulkModal() {
            document.getElementById('bulkModal').classList.add('active');
            document.getElementById('bulkForm').reset();
            document.getElementById('bulkDate').value = new Date().toISOString().slice(0, 10);
            document.getElementById('bulkMembersList').innerHTML = '<div style="padding: 12px; color: #666; text-align: center;">Loading members...</div>';
            const mt = document.getElementById('bulkMemberType');
            if (mt) mt.value = 'REGULAR';
            loadBulkMembers();
        }

        function closeBulkModal() {
            document.getElementById('bulkModal').classList.remove('active');
        }

        function bulkUpdateCategoryOptions() {
            const type = document.getElementById('bulkType').value;
            const sel = document.getElementById('bulkCategory');
            sel.innerHTML = '<option value="">Select Category</option>';
            if (type === 'INCOME') {
                const incomeCategories = ['MONTHLY FEE', 'DONATION', 'FRIDAY INCOME', 'NABIDHINAM', 'CHERIYA PERUNAL', 'BALI PERUNAL', 'ASSET RENT', 'SAHAKARI', 'NERCHE PETTI', 'BARATH', 'LELAM', 'FOOD INCOME', 'CASH DEPOSIT'];
                incomeCategories.forEach(c => { const o = document.createElement('option'); o.value = c; o.textContent = c; sel.appendChild(o); });
            } else if (type === 'EXPENSE') {
                const expenseCategories = ['SALARY', 'OFFICE EXPENSE', 'PURCHASE', 'ASSET TAX', 'BUILDING EXPENSE', 'STATIONARY EXPENSE', 'ELECTRICITY BILL', 'USTHAD FOOD', 'CLEANING EXPENSE', 'CHERIYA PERUNAL', 'BALI PERUNAL', 'NABIDHINAM', 'BANK DEPOSITE', 'OTHER EXPENSES'];
                expenseCategories.forEach(c => { const o = document.createElement('option'); o.value = c; o.textContent = c; sel.appendChild(o); });
            }
            document.getElementById('bulkOtherExpenseGroup').style.display = 'none';
        }

        function bulkHandleCategoryChange() {
            const cat = (document.getElementById('bulkCategory').value || '').toUpperCase();
            const other = document.getElementById('bulkOtherExpenseGroup');
            const isDetailNeeded = document.getElementById('bulkType').value === 'EXPENSE' && needsDetail(cat);

            other.style.display = isDetailNeeded ? 'block' : 'none';

            if (isDetailNeeded) {
                const labelEl = other.querySelector('label');
                if (labelEl) labelEl.textContent = getDetailLabel(cat);
            }

            const inputs = document.querySelectorAll('.bulk-member-amount');
            inputs.forEach(input => {
                const def = input.getAttribute('data-default-amount') || '';
                if (cat === 'MONTHLY FEE') {
                    input.value = def;
                } else {
                    input.value = '';
                }
            });
        }

        function handleBulkSubmit(e) {
            e.preventDefault();

            const date = document.getElementById('bulkDate').value;
            const type = document.getElementById('bulkType').value;
            let category = (document.getElementById('bulkCategory').value || '').toUpperCase();
            const payment_mode = document.getElementById('bulkPaymentMode').value || 'CASH';
            const bulkAmountRaw = document.getElementById('bulkAmount').value;
            const bulkAmount = bulkAmountRaw ? parseFloat(bulkAmountRaw) : 0;
            const otherExpenseDetail = document.getElementById('bulkOtherExpenseDetail') ? document.getElementById('bulkOtherExpenseDetail').value.trim() : null;
            const memberType = (document.getElementById('bulkMemberType')?.value || 'REGULAR');

            if (!type) return alert('Please select transaction type.');
            if (!category) return alert('Please select a category.');

            // Validate Other Expenses
            if (type === 'EXPENSE' && needsDetail(category) && !otherExpenseDetail) {
                return alert('Please provide details for ' + category);
            }

            // Get selected members
            const checkedBoxes = Array.from(document.querySelectorAll('.bulk-member-checkbox')).filter(cb => cb.checked);
            if (checkedBoxes.length === 0) {
                return alert('Please select at least one member for bulk transaction.');
            }

            const members = [];
            for (const cb of checkedBoxes) {
                const id = parseInt(cb.getAttribute('data-id'), 10);
                const amtInput = document.querySelector(`.bulk-member-amount[data-id="${id}"]`);
                let amt = null;
                if (amtInput) {
                    const v = amtInput.value;
                    if (v !== null && v !== '') amt = parseFloat(v);
                }

                if (!amt || isNaN(amt) || amt <= 0) {
                    if (bulkAmount && !isNaN(bulkAmount) && bulkAmount > 0) {
                        amt = bulkAmount;
                    } else {
                        return alert('Please provide an amount either in the bulk amount field or per-member for each selected member.');
                    }
                }
                members.push({ member_id: id, amount: parseFloat(amt.toFixed(2)) });
            }

            const payload = {
                user_id: userId,
                date: date,
                type: type,
                category: category,
                payment_mode: payment_mode,
                amount: bulkAmount > 0 ? bulkAmount : null,
                other_expense_detail: otherExpenseDetail,
                members: members,
                description: `Bulk ${type.toLowerCase()} (${category})`,
                member_type: memberType
            };

            const submitBtn = document.querySelector('#bulkForm button[type="submit"]');
            const origTxt = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            fetch('finance-api.php?action=save_bulk', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(async res => {
                    let json;
                    try {
                        json = await res.json();
                    } catch (e) {
                        throw new Error('Invalid JSON response from server');
                    }
                    if (!res.ok || !json.success) {
                        throw new Error(json.message || 'Server returned an error saving bulk transactions');
                    }
                    return json;
                })
                .then(result => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = origTxt;
                    closeBulkModal();
                    loadTransactions();

                    if (result.receipt_url) {
                        window.open(result.receipt_url, '_blank');
                    } else if (result.inserted_ids && result.inserted_ids.length) {
                        const firstId = result.inserted_ids[0];
                        window.open(`finance-api.php?action=receipt&id=${encodeURIComponent(firstId)}&user_id=${encodeURIComponent(userId)}`, '_blank');
                    }

                    alert(result.message || 'Bulk transactions saved successfully');
                })
                .catch(err => {
                    console.error('Bulk save error:', err);
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = origTxt;
                    alert('Failed to save bulk transactions: ' + err.message);
                });
        }

        function escapeHtmlBasicForBulk(str) {
            return String(str || '').replace(/[&<>"']/g, s => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[s]));
        }

        function loadBulkMembers() {
            const container = document.getElementById('bulkMembersList');
            container.innerHTML = '<div style="padding: 12px; color: #666; text-align: center;">Loading members...</div>';
            const memberType = (document.getElementById('bulkMemberType')?.value || 'REGULAR');

            fetch(`finance-api.php?action=get_bulk_members&user_id=${encodeURIComponent(userId)}&member_type=${encodeURIComponent(memberType)}`)
                .then(r => {
                    if (!r.ok) {
                        throw new Error(`HTTP error! Status: ${r.status}`);
                    }
                    return r.json();
                })
                .then(data => {
                    console.log('Bulk members data:', data);

                    if (!data.success) {
                        const errorMsg = data.message || 'Unknown error loading members';
                        container.innerHTML = `<div style="color: #ef4444; padding: 10px; background: #fee2e2; border-radius: 8px; border: 1px solid #fca5a5;">
                            <strong>Error:</strong> ${errorMsg}
                        </div>`;
                        return;
                    }

                    const members = Array.isArray(data.members) ? data.members : [];

                    if (members.length === 0) {
                        container.innerHTML = `<div style="padding: 16px; text-align: center; color: #6b7280; background: #f9fafb; border-radius: 8px; border: 1px dashed #d1d5db;">
                            <i class="fas fa-users-slash" style="font-size: 24px; margin-bottom: 8px; display: block;"></i>
                            No members found for bulk processing.<br>
                            <small style="font-size: 12px;">Check if members have bulk_enabled = 1 in database</small>
                        </div>`;
                        return;
                    }

                    container.innerHTML = members.map(m => {
                        const defaultAmt = (m.default_amount !== undefined && m.default_amount !== null)
                            ? parseFloat(m.default_amount).toFixed(2)
                            : '';

                        // Use correct field names based on expected API response
                        const name = m.name || m.head_name || m.full_name || `Member #${m.id}`;
                        const memberNumber = m.member_number || m.member_code || '';

                        return `
                        <div style="display:flex; align-items:center; gap: 12px; padding: 10px; border-bottom: 1px solid #e5e7eb;">
                            <label style="flex: 1; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" class="bulk-member-checkbox" data-id="${m.id}" checked>
                                <span style="font-weight: 500;">${escapeHtmlBasicForBulk(name)}</span>
                                ${memberNumber ? `<span style="color: #6b7280; font-size: 12px;">(${escapeHtmlBasicForBulk(memberNumber)})</span>` : ''}
                            </label>
                            <input type="number" 
                                   class="bulk-member-amount" 
                                   data-id="${m.id}" 
                                   data-default-amount="${defaultAmt}"
                                   value="${defaultAmt}"
                                   placeholder="Amount"
                                   min="0" step="0.01"
                                   style="width: 120px; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px;">
                        </div>`;
                    }).join('');

                    // Add event listeners for checkboxes
                    document.querySelectorAll('.bulk-member-checkbox').forEach(cb => {
                        cb.addEventListener('change', function () {
                            const amtInput = document.querySelector(`.bulk-member-amount[data-id="${this.getAttribute('data-id')}"]`);
                            if (amtInput) {
                                amtInput.disabled = !this.checked;
                                if (!this.checked) {
                                    amtInput.value = '';
                                } else {
                                    const defaultAmt = amtInput.getAttribute('data-default-amount');
                                    if (defaultAmt) {
                                        amtInput.value = defaultAmt;
                                    }
                                }
                            }
                        });
                    });

                    // Initialize disabled state based on checkbox
                    document.querySelectorAll('.bulk-member-checkbox').forEach(cb => {
                        const amtInput = document.querySelector(`.bulk-member-amount[data-id="${cb.getAttribute('data-id')}"]`);
                        if (amtInput && !cb.checked) {
                            amtInput.disabled = true;
                        }
                    });
                })
                .catch(err => {
                    console.error('loadBulkMembers error:', err);
                    container.innerHTML = `<div style="color: #ef4444; padding: 12px; background: #fee2e2; border-radius: 8px; border: 1px solid #fca5a5;">
                        <strong>Network Error:</strong> ${err.message}<br>
                        <small>Please check your connection and try again.</small>
                    </div>`;
                });
        }

        function formatCompactAmount(amount) {
            if (amount >= 10000000) { // 1 crore
                return '₹' + (amount / 10000000).toFixed(1) + 'Cr';
            } else if (amount >= 100000) { // 1 lakh
                return '₹' + (amount / 100000).toFixed(1) + 'L';
            } else if (amount >= 1000) { // 1 thousand
                return '₹' + (amount / 1000).toFixed(1) + 'K';
            }
            return '₹' + amount.toLocaleString('en-IN');
        }

        // Update the updateStats function to use compact formatting for large numbers
        function updateStats(transactions) {
            const income = transactions.filter(t => t.type === 'INCOME').reduce((s, t) => s + parseFloat(t.amount || 0), 0);
            const expense = transactions.filter(t => t.type === 'EXPENSE').reduce((s, t) => s + parseFloat(t.amount || 0), 0);
            const balance = income - expense;

            // Use compact formatting for very large numbers
            document.getElementById('totalIncome').textContent = formatCompactAmount(income);
            document.getElementById('totalExpense').textContent = formatCompactAmount(expense);
            document.getElementById('currentBalance').textContent = formatCompactAmount(balance);
        }

        // Also update the updateBalanceStats function
        function updateBalanceStats(balances) {
            let cashBalance = 0;
            let gpayBalance = 0;

            balances.forEach(balance => {
                if (balance.payment_mode === 'CASH') {
                    cashBalance = balance.balance;
                } else if (balance.payment_mode === 'G PAY') {
                    gpayBalance = balance.balance;
                }
            });

            document.getElementById('cashBalance').textContent = formatCompactAmount(cashBalance);
            document.getElementById('gpayBalance').textContent = formatCompactAmount(gpayBalance);
        }
        function addTooltips() {
            document.querySelectorAll('.stat-amount').forEach(el => {
                // Store the full formatted amount as title attribute
                const text = el.textContent;
                if (text.includes('Cr') || text.includes('L') || text.includes('K')) {
                    // Get the original number by parsing the text
                    let amount = 0;
                    if (text.includes('Cr')) {
                        amount = parseFloat(text.replace('₹', '').replace('Cr', '')) * 10000000;
                    } else if (text.includes('L')) {
                        amount = parseFloat(text.replace('₹', '').replace('L', '')) * 100000;
                    } else if (text.includes('K')) {
                        amount = parseFloat(text.replace('₹', '').replace('K', '')) * 1000;
                    }

                    // Format full amount with commas
                    const fullAmount = '₹' + amount.toLocaleString('en-IN', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                    el.setAttribute('title', fullAmount);
                    el.style.cursor = 'help';
                }
            });
        }

        // Call this after updating stats
        function updateStats(transactions) {
            const income = transactions.filter(t => t.type === 'INCOME').reduce((s, t) => s + parseFloat(t.amount || 0), 0);
            const expense = transactions.filter(t => t.type === 'EXPENSE').reduce((s, t) => s + parseFloat(t.amount || 0), 0);
            const balance = income - expense;

            // Use the new formatting function
            document.getElementById('totalIncome').textContent = formatAmountWithSuffix(income);
            document.getElementById('totalExpense').textContent = formatAmountWithSuffix(expense);
            document.getElementById('currentBalance').textContent = formatAmountWithSuffix(balance);

            // Add tooltips
            setTimeout(addTooltips, 100);
        }

        function populateCategoryFilter(transactions) {
            const select = document.getElementById('categoryFilter');
            if (!select) return;

            // Clear existing options (keeping "All Categories")
            while (select.options.length > 1) {
                select.remove(1);
            }

            // Get unique categories from transactions
            const categoriesSet = new Set();
            (transactions || []).forEach(t => {
                if (t.category) categoriesSet.add(t.category);
            });

            // Sort categories alphabetically
            const categories = Array.from(categoriesSet).sort((a, b) => a.localeCompare(b));

            // Add category options
            categories.forEach(cat => {
                const opt = document.createElement('option');
                opt.value = cat;
                opt.textContent = cat;
                select.appendChild(opt);
            });
        }
    </script>
</body>

</html>
<?php $conn->close(); ?>