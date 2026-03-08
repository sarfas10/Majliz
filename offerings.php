<?php
// offerings.php - Offering Management
require_once __DIR__ . '/session_bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

require_once 'db_connection.php';
$db_result = get_db_connection();
if (isset($db_result['error'])) {
    die("Database connection failed: " . $db_result['error']);
}
$conn = $db_result['conn'];

// Fetch logged-in mahal details for sidebar
$user_id = $_SESSION['user_id'];
$stmt_m = $conn->prepare("SELECT name, address, registration_no, email FROM register WHERE id = ?");
$stmt_m->bind_param("i", $user_id);
$stmt_m->execute();
$result_m = $stmt_m->get_result();
if ($result_m->num_rows > 0) {
    $mahal = $result_m->fetch_assoc();
} else {
    echo "<script>alert('Unable to fetch mahal details. Please log in again.'); window.location.href='index.php';</script>";
    exit();
}
$stmt_m->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offerings Management -
        <?php echo htmlspecialchars($mahal['name']); ?>
    </title>
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

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            height: 100%;
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
        }

        body.no-scroll {
            overflow: hidden;
        }

        /* ── SIDEBAR ─────────────────────────────────────── */
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
        }

        .sidebar-close:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: rotate(90deg);
        }

        .profile {
            padding: 24px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            text-align: center;
            margin-bottom: 16px;
            cursor: pointer;
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

        /* ── MAIN ─────────────────────────────────────────── */
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

        .top-actions {
            margin-left: auto;
        }

        .container {
            padding: 24px;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
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
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-family: inherit;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            box-shadow: 0 4px 12px rgba(74, 111, 165, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(74, 111, 165, 0.4);
        }

        .btn-secondary {
            background: var(--card);
            border: 1px solid var(--border);
            color: var(--text);
            box-shadow: var(--shadow);
        }

        .btn-secondary:hover {
            background: var(--card-alt);
            transform: translateY(-1px);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--error), #c0392b);
            color: #fff;
        }

        .btn-danger:hover {
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 7px 14px;
            font-size: 13px;
        }

        .btn-icon {
            padding: 8px;
            border-radius: 8px;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #1e8449);
            color: #fff;
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.3);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(39, 174, 96, 0.4);
        }

        /* ── FILTER BAR ──────────────────────────────────── */
        .filter-bar {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-bar input,
        .filter-bar select {
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 10px 14px;
            font-size: 14px;
            font-family: inherit;
            background: var(--bg);
            color: var(--text);
            outline: none;
            transition: var(--transition);
        }

        .filter-bar input:focus,
        .filter-bar select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }

        .filter-bar input[type="search"] {
            flex: 1;
            min-width: 200px;
        }

        /* ── TABLE ─────────────────────────────────────────── */
        .table-wrap {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .table-header {
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
            background: var(--card-alt);
        }

        .table-header h2 {
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .table-header h2 i {
            color: var(--primary);
        }

        .count-badge {
            background: var(--primary);
            color: white;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .table-scroll {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 860px;
        }

        thead th {
            background: var(--card-alt);
            padding: 12px 16px;
            text-align: left;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--border);
        }

        tbody tr {
            transition: background 0.2s;
        }

        tbody tr:hover {
            background: var(--card-alt);
        }

        tbody td {
            padding: 13px 16px;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
            vertical-align: middle;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        .empty-state {
            padding: 60px 16px;
            text-align: center;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 12px;
            opacity: 0.4;
            display: block;
        }

        /* ── STATUS BADGES ────────────────────────── */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .badge-pending {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #f59e0b;
        }

        .badge-fulfilled {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #10b981;
        }

        .badge-cancelled {
            background: #f3f4f6;
            color: #6b7280;
            border: 1px solid #9ca3af;
        }

        .badge-member {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #3b82f6;
        }

        .badge-nonmember {
            background: #fce7f3;
            color: #9d174d;
            border: 1px solid #ec4899;
        }

        .member-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 13px;
        }

        .member-chip i {
            color: var(--primary);
        }

        .non-member-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 13px;
            color: var(--text-light);
        }

        .non-member-chip i {
            color: var(--accent);
        }

        .action-group {
            display: flex;
            gap: 6px;
        }

        /* ── MODAL ────────────────────────────────────────── */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            backdrop-filter: blur(4px);
        }

        .modal-backdrop.open {
            display: flex;
        }

        .modal {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 560px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalIn 0.25s ease;
        }

        @keyframes modalIn {
            from {
                opacity: 0;
                transform: scale(0.95) translateY(10px);
            }

            to {
                opacity: 1;
                transform: none;
            }
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
            color: var(--text-light);
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 8px;
            transition: var(--transition);
        }

        .modal-close:hover {
            background: var(--bg);
            color: var(--text);
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        /* ── FORM ─────────────────────────────────────────── */
        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 7px;
        }

        .form-label .req {
            color: var(--error);
        }

        .form-control {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 12px 14px;
            font-size: 14px;
            font-family: inherit;
            color: var(--text);
            background: var(--bg);
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
            background: white;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        /* Toggle: member / non-member */
        .toggle-group {
            display: flex;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            overflow: hidden;
            margin-bottom: 18px;
        }

        .toggle-opt {
            flex: 1;
            text-align: center;
            padding: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            user-select: none;
        }

        .toggle-opt.sel-member {
            background: var(--primary);
            color: white;
        }

        .toggle-opt.sel-nonmember {
            background: var(--accent);
            color: white;
        }

        .toggle-opt:not(.sel-member):not(.sel-nonmember):hover {
            background: var(--card-alt);
        }

        /* Member search autocomplete */
        .member-search-wrap {
            position: relative;
        }

        .member-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-lg);
            z-index: 100;
            max-height: 200px;
            overflow-y: auto;
            display: none;
        }

        .member-dropdown-item {
            padding: 10px 14px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.15s;
        }

        .member-dropdown-item:hover {
            background: var(--card-alt);
        }

        /* ── RESPONSIVE ───────────────────────── */
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

        @media (max-width: 640px) {
            .form-row {
                grid-template-columns: 1fr;
            }

            .filter-bar {
                flex-direction: column;
            }

            .filter-bar input[type="search"] {
                min-width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="sidebar" id="sidebar" aria-hidden="true">
        <button class="sidebar-close" id="sidebarClose" type="button"><i class="fas fa-times"></i></button>
        <div class="sidebar-inner">
            <div class="profile" onclick="window.location.href='dashboard.php'">
                <div class="profile-avatar">
                    <img src="logo.jpeg" alt="Logo"
                        onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                    <i class="fas fa-mosque" style="display:none;"></i>
                </div>
                <div class="name">
                    <?php echo htmlspecialchars($mahal['name']); ?>
                </div>
                <div class="role">Administrator</div>
            </div>

            <nav class="menu" role="menu">
                <button class="menu-btn" type="button" onclick="window.location.href='dashboard.php'">
                    <i class="fas fa-tachometer-alt"></i><span>Admin Panel</span>
                </button>
                <button class="menu-btn" type="button" onclick="window.location.href='finance-tracking.php'">
                    <i class="fas fa-chart-line"></i><span>Finance Tracking</span>
                </button>
                <button class="menu-btn" type="button" onclick="window.location.href='member-management.php'">
                    <i class="fas fa-users"></i><span>Member Management</span>
                </button>
                <button class="menu-btn" type="button" onclick="window.location.href='staff-management.php'">
                    <i class="fas fa-user-tie"></i><span>Staff Management</span>
                </button>
                <button class="menu-btn" type="button" onclick="window.location.href='asset_management.php'">
                    <i class="fas fa-boxes"></i><span>Asset Management</span>
                </button>
                <button class="menu-btn" type="button" onclick="window.location.href='academics.php'">
                    <i class="fas fa-graduation-cap"></i><span>Academics</span>
                </button>
                <button class="menu-btn" type="button" onclick="window.location.href='certificate.php'">
                    <i class="fas fa-certificate"></i><span>Certificate Management</span>
                </button>
                <button class="menu-btn active" type="button" onclick="window.location.href='offerings.php'">
                    <i class="fas fa-hand-holding-heart"></i><span>Offerings</span>
                </button>
                <button class="menu-btn" type="button" onclick="window.location.href='mahal_profile.php'">
                    <i class="fas fa-building"></i><span>Mahal Profile</span>
                </button>
            </nav>

            <div class="sidebar-bottom">
                <form action="logout.php" method="post" style="margin:0">
                    <button type="submit" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <div class="main" id="main">
        <!-- Top Bar -->
        <div class="top-row">
            <button class="floating-menu-btn" id="menuToggle" type="button" aria-controls="sidebar"
                aria-expanded="false">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title"><i class="fas fa-hand-holding-heart"></i> Offerings Management</div>
            <div class="top-actions">
                <button class="btn btn-primary" onclick="openModal()">
                    <i class="fas fa-plus"></i> Add Offering
                </button>
            </div>
        </div>

        <div class="container">
            <!-- Filter Bar -->
            <div class="filter-bar">
                <input type="search" id="searchInput" placeholder="Search by name or value…" oninput="renderTable()">
                <select id="filterType" onchange="renderTable()">
                    <option value="">All Types</option>
                    <option value="Money">Money</option>
                    <option value="Bricks">Bricks</option>
                    <option value="Materials">Materials</option>
                    <option value="Labour">Labour</option>
                    <option value="Other">Other</option>
                </select>
                <select id="filterStatus" onchange="renderTable()">
                    <option value="">All Statuses</option>
                    <option value="pending">Pending</option>
                    <option value="fulfilled">Fulfilled</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <select id="filterOffererType" onchange="renderTable()">
                    <option value="">Members & Non-Members</option>
                    <option value="member">Members Only</option>
                    <option value="nonmember">Non-Members Only</option>
                </select>
            </div>

            <!-- Table -->
            <div class="table-wrap">
                <div class="table-header">
                    <h2><i class="fas fa-list"></i> Offerings <span class="count-badge" id="tableCount">0</span></h2>
                </div>
                <div class="table-scroll">
                    <table id="offeringsTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Offered By</th>
                                <th>Type</th>
                                <th>Value / Amount</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Notes</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="offeringsBody">
                            <tr>
                                <td colspan="8" class="empty-state"><i class="fas fa-spinner fa-spin"></i><br>Loading…
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add / Edit Modal -->
    <div class="modal-backdrop" id="modalBackdrop">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
            <div class="modal-header">
                <h3 id="modalTitle">Add Offering</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editId" value="">

                <!-- Member / Non-Member Toggle -->
                <div class="form-group">
                    <div class="form-label">Offered By <span class="req">*</span></div>
                    <div class="toggle-group">
                        <div class="toggle-opt" id="optNonMember" onclick="setOffererType('nonmember')">
                            <i class="fas fa-user-times"></i> Non-Member
                        </div>
                        <div class="toggle-opt" id="optMember" onclick="setOffererType('member')">
                            <i class="fas fa-user-check"></i> Member
                        </div>
                    </div>
                </div>

                <!-- Non-member name -->
                <div class="form-group" id="nonMemberGroup">
                    <label class="form-label" for="offeredBy">Name <span class="req">*</span></label>
                    <input class="form-control" type="text" id="offeredBy" placeholder="Enter full name">
                </div>

                <!-- Member picker -->
                <div class="form-group" id="memberGroup" style="display:none;">
                    <label class="form-label" for="memberSearch">Search Member <span class="req">*</span></label>
                    <div class="member-search-wrap">
                        <input class="form-control" type="text" id="memberSearch" placeholder="Type to search…"
                            autocomplete="off" oninput="searchMembers(this.value)">
                        <div class="member-dropdown" id="memberDropdown"></div>
                    </div>
                    <input type="hidden" id="memberId" value="">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="offeringType">Type <span class="req">*</span></label>
                        <select class="form-control" id="offeringType">
                            <option value="Money">Money</option>
                            <option value="Bricks">Bricks</option>
                            <option value="Materials">Materials</option>
                            <option value="Labour">Labour</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="offeringValue">Value / Amount <span class="req">*</span></label>
                        <input class="form-control" type="text" id="offeringValue"
                            placeholder="e.g. 10000 Rs or 100 Bricks">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="offeringDate">Date <span class="req">*</span></label>
                        <input class="form-control" type="date" id="offeringDate">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="offeringStatus">Status</label>
                        <select class="form-control" id="offeringStatus">
                            <option value="pending">Pending</option>
                            <option value="fulfilled">Fulfilled</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="offeringDesc">Description</label>
                    <input class="form-control" type="text" id="offeringDesc"
                        placeholder="Short description (optional)">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" for="offeringNotes">Notes</label>
                    <textarea class="form-control" id="offeringNotes" rows="3"
                        placeholder="Additional notes…"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-primary" onclick="saveOffering()"><i class="fas fa-save"></i> Save</button>
            </div>
        </div>
    </div>

    <!-- Delete Confirm Modal -->
    <div class="modal-backdrop" id="deleteBackdrop">
        <div class="modal" style="max-width:400px;">
            <div class="modal-header">
                <h3>Delete Offering</h3>
                <button class="modal-close"
                    onclick="document.getElementById('deleteBackdrop').classList.remove('open')">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this offering? This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary"
                    onclick="document.getElementById('deleteBackdrop').classList.remove('open')">Cancel</button>
                <button class="btn btn-danger" id="confirmDeleteBtn" onclick="confirmDelete()"><i
                        class="fas fa-trash"></i> Delete</button>
            </div>
        </div>
    </div>

    <!-- Insert to Transactions Modal -->
    <div class="modal-backdrop" id="txBackdrop">
        <div class="modal" style="max-width:480px;">
            <div class="modal-header">
                <h3><i class="fas fa-receipt" style="color:var(--success);"></i> Record as Cash Income</h3>
                <button class="modal-close" onclick="closeTxModal()">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="txOfferingId" value="">

                <div class="form-group">
                    <label class="form-label">Offered By</label>
                    <input class="form-control" type="text" id="txOfferedBy" readonly
                        style="background:var(--card-alt);">
                </div>

                <div class="form-group">
                    <label class="form-label">Offering Type &amp; Original Value</label>
                    <input class="form-control" type="text" id="txOriginalValue" readonly
                        style="background:var(--card-alt);">
                </div>

                <div class="form-group">
                    <label class="form-label" for="txAmount">Cash Amount (₹) <span class="req">*</span></label>
                    <input class="form-control" type="number" id="txAmount" min="1" step="0.01"
                        placeholder="Enter the cash equivalent amount">
                    <small style="color:var(--text-light);font-size:12px;margin-top:4px;display:block;">Enter the
                        monetary value for this offering (e.g. cost of bricks).</small>
                </div>

                <div class="form-group">
                    <label class="form-label" for="txDate">Transaction Date <span class="req">*</span></label>
                    <input class="form-control" type="date" id="txDate">
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" for="txDesc">Description / Note</label>
                    <input class="form-control" type="text" id="txDesc" placeholder="Optional description">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeTxModal()">Cancel</button>
                <button class="btn btn-success" onclick="insertToTransactions()">
                    <i class="fas fa-check"></i> Save Income Transaction
                </button>
            </div>
        </div>
    </div>

    <script>
        let allOfferings = [];
        let offererType = 'nonmember';
        let deleteTargetId = null;

        // ── Bootstrap ───────────────────────────────────────────────
        (async function init() {
            await loadOfferings();
            document.getElementById('offeringDate').value = new Date().toISOString().split('T')[0];
        })();

        async function loadOfferings() {
            try {
                const urlParams = new URLSearchParams(window.location.search);
                const memberId = urlParams.get('member_id');
                const apiUrl = memberId
                    ? `offerings_api.php?action=list&member_id=${encodeURIComponent(memberId)}`
                    : 'offerings_api.php?action=list';
                const res = await fetch(apiUrl);
                const json = await res.json();
                allOfferings = json.success ? json.data : [];

                // Pre-filter UI if member_id was specified
                if (memberId) {
                    const ot = document.getElementById('filterOffererType');
                    if (ot) ot.value = 'member';
                }
            } catch (e) {
                allOfferings = [];
            }
            renderTable();
        }

        // ── Render Table ─────────────────────────────────────────────
        function renderTable() {
            const q = document.getElementById('searchInput').value.toLowerCase();
            const type = document.getElementById('filterType').value;
            const stat = document.getElementById('filterStatus').value;
            const ot = document.getElementById('filterOffererType').value;

            const filtered = allOfferings.filter(o => {
                const name = (o.member_name || o.offered_by || '').toLowerCase();
                const val = (o.offering_value || '').toLowerCase();
                if (q && !name.includes(q) && !val.includes(q)) return false;
                if (type && o.offering_type !== type) return false;
                if (stat && o.status !== stat) return false;
                if (ot === 'member' && !o.member_id) return false;
                if (ot === 'nonmember' && o.member_id) return false;
                return true;
            });

            document.getElementById('tableCount').textContent = filtered.length;
            const tbody = document.getElementById('offeringsBody');

            if (!filtered.length) {
                tbody.innerHTML = `<tr><td colspan="8" class="empty-state">
            <i class="fas fa-hand-holding-heart"></i><br>No offerings found
        </td></tr>`;
                return;
            }

            tbody.innerHTML = filtered.map((o, i) => {
                const isMember = !!o.member_id;
                const byHtml = isMember
                    ? `<span class="member-chip"><i class="fas fa-user-check"></i> ${esc(o.member_name || 'Member')}</span>`
                    : `<span class="non-member-chip"><i class="fas fa-user"></i> ${esc(o.offered_by || '—')}</span>`;
                const badge = statusBadge(o.status);
                const date = o.offering_date ? new Date(o.offering_date).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

                return `<tr>
            <td style="color:var(--text-lighter);font-weight:600;">${i + 1}</td>
            <td>${byHtml}<br><small style="color:var(--text-lighter);">${isMember ? '<span class="badge badge-member" style="font-size:10px;">Member</span>' : '<span class="badge badge-nonmember" style="font-size:10px;">Non-Member</span>'}</small></td>
            <td><strong>${esc(o.offering_type)}</strong></td>
            <td style="font-weight:700;color:var(--primary);">${esc(o.offering_value)}</td>
            <td>${date}</td>
            <td>${badge}</td>
            <td style="max-width:180px;color:var(--text-light);font-size:13px;">${esc(o.notes || o.description || '—')}</td>
            <td>
                <div class="action-group">
                    <button class="btn btn-secondary btn-sm btn-icon" title="Edit" onclick="editOffering(${o.id})">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-success btn-sm" title="Insert to Transactions" onclick="openTxModal(${o.id})" style="font-size:12px;padding:7px 10px;">
                        <i class="fas fa-receipt"></i> Record
                    </button>
                    <button class="btn btn-danger btn-sm btn-icon" title="Delete" onclick="askDelete(${o.id})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>`;
            }).join('');
        }

        function statusBadge(s) {
            const map = {
                pending: '<span class="badge badge-pending"><i class="fas fa-clock"></i> Pending</span>',
                fulfilled: '<span class="badge badge-fulfilled"><i class="fas fa-check-circle"></i> Fulfilled</span>',
                cancelled: '<span class="badge badge-cancelled"><i class="fas fa-times-circle"></i> Cancelled</span>',
            };
            return map[s] || `<span class="badge">${s}</span>`;
        }

        function esc(str) {
            const d = document.createElement('div');
            d.appendChild(document.createTextNode(str || ''));
            return d.innerHTML;
        }

        // ── Modal ─────────────────────────────────────────────────────
        function openModal(data = null) {
            document.getElementById('editId').value = data ? data.id : '';
            document.getElementById('modalTitle').textContent = data ? 'Edit Offering' : 'Add Offering';
            document.getElementById('offeringType').value = data ? data.offering_type : 'Money';
            document.getElementById('offeringValue').value = data ? data.offering_value : '';
            document.getElementById('offeringDate').value = data ? data.offering_date : new Date().toISOString().split('T')[0];
            document.getElementById('offeringStatus').value = data ? data.status : 'pending';
            document.getElementById('offeringDesc').value = data ? (data.description || '') : '';
            document.getElementById('offeringNotes').value = data ? (data.notes || '') : '';

            if (data && data.member_id) {
                setOffererType('member');
                document.getElementById('memberSearch').value = data.member_name || '';
                document.getElementById('memberId').value = data.member_id;
                document.getElementById('offeredBy').value = '';
            } else {
                setOffererType('nonmember');
                document.getElementById('offeredBy').value = data ? (data.offered_by || '') : '';
                document.getElementById('memberSearch').value = '';
                document.getElementById('memberId').value = '';
            }

            document.getElementById('modalBackdrop').classList.add('open');
        }

        function closeModal() {
            document.getElementById('modalBackdrop').classList.remove('open');
            document.getElementById('memberDropdown').style.display = 'none';
        }

        function setOffererType(type) {
            offererType = type;
            document.getElementById('nonMemberGroup').style.display = type === 'nonmember' ? '' : 'none';
            document.getElementById('memberGroup').style.display = type === 'member' ? '' : 'none';
            document.getElementById('optNonMember').className = 'toggle-opt' + (type === 'nonmember' ? ' sel-nonmember' : '');
            document.getElementById('optMember').className = 'toggle-opt' + (type === 'member' ? ' sel-member' : '');
        }

        // Init toggle
        setOffererType('nonmember');

        // ── Member Autocomplete ──────────────────────────────────────
        let memberSearchTimeout = null;
        async function searchMembers(q) {
            clearTimeout(memberSearchTimeout);
            if (q.length < 1) { document.getElementById('memberDropdown').style.display = 'none'; return; }
            memberSearchTimeout = setTimeout(async () => {
                const res = await fetch(`offerings_api.php?action=get_members&q=${encodeURIComponent(q)}`);
                const json = await res.json();
                const dd = document.getElementById('memberDropdown');
                if (!json.success || !json.data.length) { dd.style.display = 'none'; return; }
                dd.innerHTML = json.data.map(m =>
                    `<div class="member-dropdown-item" onclick="selectMember(${m.id}, '${m.head_name.replace(/'/g, "\\'")}')">
                <i class="fas fa-user" style="color:var(--primary);margin-right:6px;"></i>${esc(m.head_name)}
            </div>`
                ).join('');
                dd.style.display = 'block';
            }, 300);
        }

        function selectMember(id, name) {
            document.getElementById('memberId').value = id;
            document.getElementById('memberSearch').value = name;
            document.getElementById('memberDropdown').style.display = 'none';
        }

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.member-search-wrap')) {
                document.getElementById('memberDropdown').style.display = 'none';
            }
        });

        // ── Save ─────────────────────────────────────────────────────
        async function saveOffering() {
            const id = document.getElementById('editId').value;
            const isMember = offererType === 'member';
            const memberId = document.getElementById('memberId').value;
            const offeredBy = document.getElementById('offeredBy').value.trim();
            const value = document.getElementById('offeringValue').value.trim();

            if (!value) { alert('Please enter the offering value / amount.'); return; }
            if (isMember && !memberId) { alert('Please select a member.'); return; }
            if (!isMember && !offeredBy) { alert('Please enter the name.'); return; }

            const payload = {
                offering_type: document.getElementById('offeringType').value,
                offering_value: value,
                description: document.getElementById('offeringDesc').value.trim(),
                offered_by: isMember ? '' : offeredBy,
                member_id: isMember ? memberId : '',
                offering_date: document.getElementById('offeringDate').value,
                status: document.getElementById('offeringStatus').value,
                notes: document.getElementById('offeringNotes').value.trim(),
            };

            const action = id ? 'update' : 'create';
            if (id) payload.id = id;

            const url = `offerings_api.php?action=${action}`;
            const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            const json = await res.json();

            if (json.success) {
                closeModal();
                await loadOfferings();
            } else {
                alert('Error: ' + (json.message || 'Could not save'));
            }
        }

        // ── Edit ─────────────────────────────────────────────────────
        function editOffering(id) {
            const o = allOfferings.find(x => x.id == id);
            if (o) openModal(o);
        }

        // ── Insert to Transactions ────────────────────────────────────
        let txTargetId = null;

        function openTxModal(id) {
            const o = allOfferings.find(x => x.id == id);
            if (!o) return;
            txTargetId = id;
            document.getElementById('txOfferingId').value = id;
            const byName = o.member_name || o.offered_by || '—';
            document.getElementById('txOfferedBy').value = byName;
            document.getElementById('txOriginalValue').value = `${o.offering_type}: ${o.offering_value}`;
            // Pre-fill amount only if it looks numeric (money type)
            const numericVal = parseFloat((o.offering_value || '').replace(/[^0-9.]/g, ''));
            document.getElementById('txAmount').value = (!isNaN(numericVal) && numericVal > 0) ? numericVal : '';
            document.getElementById('txDate').value = o.offering_date || new Date().toISOString().split('T')[0];
            document.getElementById('txDesc').value = `Offering from ${byName} – ${o.offering_type}: ${o.offering_value}`;
            document.getElementById('txBackdrop').classList.add('open');
        }

        function closeTxModal() {
            document.getElementById('txBackdrop').classList.remove('open');
            txTargetId = null;
        }

        async function insertToTransactions() {
            const amount = parseFloat(document.getElementById('txAmount').value);
            const date = document.getElementById('txDate').value;
            const desc = document.getElementById('txDesc').value.trim();

            if (!amount || amount <= 0) { alert('Please enter a valid cash amount.'); return; }
            if (!date) { alert('Please select a transaction date.'); return; }

            const payload = {
                user_id: <?php echo $user_id; ?>,
                date: date,
                type: 'INCOME',
                category: 'OFFERINGS',
                amount: amount,
                description: desc || null,
                payment_mode: 'CASH',
                donor_member_id: null,
                donor_details: document.getElementById('txOfferedBy').value || null
            };

            try {
                const res = await fetch('finance-api.php?action=save', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const json = await res.json();
                if (json.success) {
                    closeTxModal();
                    alert('✅ Income transaction recorded successfully!');
                } else {
                    alert('Error: ' + (json.message || 'Could not save transaction'));
                }
            } catch (e) {
                alert('Network error. Please try again.');
            }
        }

        // ── Delete ────────────────────────────────────────────────────
        function askDelete(id) {
            deleteTargetId = id;
            document.getElementById('deleteBackdrop').classList.add('open');
        }

        async function confirmDelete() {
            if (!deleteTargetId) return;
            const res = await fetch('offerings_api.php?action=delete', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: deleteTargetId })
            });
            const json = await res.json();
            document.getElementById('deleteBackdrop').classList.remove('open');
            if (json.success) {
                await loadOfferings();
            } else {
                alert('Error: ' + (json.message || 'Could not delete'));
            }
            deleteTargetId = null;
        }

        // ── Sidebar toggle ────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const toggle = document.getElementById('menuToggle');
            const closeBtn = document.getElementById('sidebarClose');

            function open() { sidebar.classList.add('open'); overlay.classList.add('show'); document.body.classList.add('no-scroll'); }
            function close() { sidebar.classList.remove('open'); overlay.classList.remove('show'); document.body.classList.remove('no-scroll'); }

            toggle?.addEventListener('click', open);
            closeBtn?.addEventListener('click', close);
            overlay?.addEventListener('click', close);
            document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
        });
    </script>
</body>

</html>