<?php
// admin_dashboard.php
// Modern Admin Dashboard with sidebar navigation

require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db_connection.php';

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

/* --- logout handler --- */
if (isset($_GET['logout']) && $_GET['logout'] == '1') {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit();
}

/* --- AJAX handler for status updates --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    header('Content-Type: application/json');

    $userId = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $newStatus = isset($_POST['status']) ? trim($_POST['status']) : '';

    $validStatuses = ['pending', 'active', 'inactive', 'suspended'];

    if ($userId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
        exit();
    }

    if (!in_array($newStatus, $validStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit();
    }

    // Get database connection
    $db_result = get_db_connection();
    if (isset($db_result['error'])) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit();
    }

    $conn = $db_result['conn'];

    // Update the status
    $stmt = $conn->prepare("UPDATE register SET status = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("si", $newStatus, $userId);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'No changes made or user not found']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update status']);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }

    $conn->close();
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

// --- Auto-update subscription statuses ---
require_once __DIR__ . '/subscription_helpers.php';
update_expired_subscriptions($conn);
// -----------------------------------------

// Table names
$plansTable = 'plans';
$featuresTable = 'plan_features';
$settingsTable = 'admin_settings';

// Core features (exact strings)
$baseFeatures = [
    "Finance Tracking",
    "Member Management",
    "Asset Management",
    "Academics",
    "Certification Management"
];

// Create tables if missing
$createPlans = "
CREATE TABLE IF NOT EXISTS {$plansTable} (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    short_description VARCHAR(255) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    monthly_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    yearly_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if (!$conn->query($createPlans)) {
    die("Error creating plans table: " . $conn->error);
}

$createFeatures = "
CREATE TABLE IF NOT EXISTS {$featuresTable} (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_id INT NOT NULL,
    feature_name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plan_id) REFERENCES {$plansTable}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if (!$conn->query($createFeatures)) {
    die("Error creating features table: " . $conn->error);
}

// Create admin settings table
$createSettings = "
CREATE TABLE IF NOT EXISTS {$settingsTable} (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if (!$conn->query($createSettings)) {
    die("Error creating settings table: " . $conn->error);
}

// helper
function s($v)
{
    return htmlspecialchars(trim((string) $v));
}

// --------- Settings handler ----------
$settingsSuccess = '';
$settingsErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $settings = [
        'admin_phone' => trim($_POST['admin_phone'] ?? ''),
        'admin_phone_alt' => trim($_POST['admin_phone_alt'] ?? ''),
        'admin_email' => trim($_POST['admin_email'] ?? ''),
        'admin_address' => trim($_POST['admin_address'] ?? ''),
        'registration_charge' => trim($_POST['registration_charge'] ?? '0'),
        'default_plan' => trim($_POST['default_plan'] ?? ''),
        'default_plan_duration_type' => trim($_POST['default_plan_duration_type'] ?? '1'),
        'default_plan_duration_custom' => trim($_POST['default_plan_duration_custom'] ?? ''),
        'bank_name' => trim($_POST['bank_name'] ?? ''),
        'bank_account_name' => trim($_POST['bank_account_name'] ?? ''),
        'bank_account_number' => trim($_POST['bank_account_number'] ?? ''),
        'bank_ifsc' => trim($_POST['bank_ifsc'] ?? ''),
        'upi_id' => trim($_POST['upi_id'] ?? ''),
    ];

    // Handle QR code upload
    // Handle QR code upload - REMOVED as per user request (Dynamic generation now used)
    /*
    if (isset($_FILES['qr_code']) && $_FILES['qr_code']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $fileExt = strtolower(pathinfo($_FILES['qr_code']['name'], PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif'];

        if (in_array($fileExt, $allowedExts)) {
            $fileName = 'qr_code_' . time() . '.' . $fileExt;
            $uploadPath = $uploadDir . $fileName;

            if (move_uploaded_file($_FILES['qr_code']['tmp_name'], $uploadPath)) {
                $settings['qr_code_path'] = 'uploads/' . $fileName;
            } else {
                $settingsErrors[] = "Failed to upload QR code.";
            }
        } else {
            $settingsErrors[] = "Invalid QR code file type. Only JPG, JPEG, PNG, and GIF are allowed.";
        }
    }
    */

    // Validate required fields
    if (empty($settings['admin_email']) || !filter_var($settings['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $settingsErrors[] = "Valid admin email is required.";
    }

    if (empty($settingsErrors)) {
        // Save settings to database
        $stmt = $conn->prepare("INSERT INTO {$settingsTable} (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");

        foreach ($settings as $key => $value) {
            if ($stmt) {
                $stmt->bind_param("sss", $key, $value, $value);
                $stmt->execute();
            }
        }

        if ($stmt) {
            $stmt->close();
        }

        $settingsSuccess = "Settings saved successfully!";
        header("Location: " . $_SERVER['PHP_SELF'] . "?view=settings&saved=1");
        exit;
    }
}

// Load settings from database
function getSettings($conn, $settingsTable)
{
    $settings = [];
    $result = $conn->query("SELECT setting_key, setting_value FROM {$settingsTable}");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }

    return $settings;
}

$adminSettings = getSettings($conn, $settingsTable);

// --------- Delete handler ----------
if (isset($_GET['delete'])) {
    $delId = intval($_GET['delete']);
    if ($delId > 0) {
        $stmt = $conn->prepare("DELETE FROM {$plansTable} WHERE id = ?");
        $stmt->bind_param("i", $delId);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// --------- Create/Update handler ----------
$errors = [];
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_plan'])) {
    $isEdit = isset($_POST['plan_id']) && intval($_POST['plan_id']) > 0;
    $planId = $isEdit ? intval($_POST['plan_id']) : 0;

    $title = trim($_POST['title'] ?? '');
    $short_description = trim($_POST['short_description'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $monthly = isset($_POST['monthly_price']) && $_POST['monthly_price'] !== '' ? (float) $_POST['monthly_price'] : 0.0;
    $discount = isset($_POST['discount']) && $_POST['discount'] !== '' ? (float) $_POST['discount'] : 0.0;
    $yearly = isset($_POST['yearly_price']) && $_POST['yearly_price'] !== '' ? (float) $_POST['yearly_price'] : round($monthly * 12 * (1 - $discount / 100), 2);

    $checkedCore = [];
    foreach ($baseFeatures as $i => $bf) {
        $field = 'core_' . $i;
        if (isset($_POST[$field]) && ($_POST[$field] === '1' || $_POST[$field] === 'on')) {
            $checkedCore[] = $bf;
        }
    }

    $extraIncluded = [];
    if (isset($_POST['extra_included']) && is_array($_POST['extra_included'])) {
        foreach ($_POST['extra_included'] as $val) {
            $v = trim((string) $val);
            if ($v !== '')
                $extraIncluded[] = $v;
        }
    }

    $featuresToSave = array_values(array_unique(array_merge($checkedCore, $extraIncluded)));

    if ($title === '')
        $errors[] = "Plan title is required.";
    if ($monthly <= 0)
        $errors[] = "Monthly price must be greater than 0.";

    if (empty($errors)) {
        if ($isEdit) {
            $stmt = $conn->prepare("UPDATE {$plansTable} SET title=?, short_description=?, description=?, monthly_price=?, discount_percent=?, yearly_price=? WHERE id=?");
            if (!$stmt)
                $errors[] = "Prepare failed: " . $conn->error;
            else {
                $stmt->bind_param("sssdddi", $title, $short_description, $description, $monthly, $discount, $yearly, $planId);
                if (!$stmt->execute())
                    $errors[] = "Update failed: " . $stmt->error;
                $stmt->close();
            }

            if (empty($errors)) {
                $d = $conn->prepare("DELETE FROM {$featuresTable} WHERE plan_id = ?");
                $d->bind_param("i", $planId);
                $d->execute();
                $d->close();

                if (!empty($featuresToSave)) {
                    $ins = $conn->prepare("INSERT INTO {$featuresTable} (plan_id, feature_name) VALUES (?, ?)");
                    foreach ($featuresToSave as $f) {
                        $ins->bind_param("is", $planId, $f);
                        $ins->execute();
                    }
                    $ins->close();
                }

                $success = "Plan updated successfully.";
                header("Location: " . $_SERVER['PHP_SELF'] . "?view=plans&edited=1");
                exit;
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO {$plansTable} (title, short_description, description, monthly_price, discount_percent, yearly_price) VALUES (?, ?, ?, ?, ?, ?)");
            if (!$stmt)
                $errors[] = "Prepare failed: " . $conn->error;
            else {
                $stmt->bind_param("sssddd", $title, $short_description, $description, $monthly, $discount, $yearly);
                if (!$stmt->execute())
                    $errors[] = "Insert failed: " . $stmt->error;
                else {
                    $newId = $stmt->insert_id;
                    $stmt->close();

                    if (!empty($featuresToSave)) {
                        $ins = $conn->prepare("INSERT INTO {$featuresTable} (plan_id, feature_name) VALUES (?, ?)");
                        foreach ($featuresToSave as $f) {
                            $ins->bind_param("is", $newId, $f);
                            $ins->execute();
                        }
                        $ins->close();
                    }
                    $success = "Plan created successfully.";
                    header("Location: " . $_SERVER['PHP_SELF'] . "?view=plans&created=1");
                    exit;
                }
            }
        }
    }
}

// --------- Load edit plan if requested ----------
$editPlan = null;
if (isset($_GET['edit'])) {
    $eid = intval($_GET['edit']);
    if ($eid > 0) {
        $stmt = $conn->prepare("SELECT * FROM {$plansTable} WHERE id = ?");
        $stmt->bind_param("i", $eid);
        $stmt->execute();
        $res = $stmt->get_result();
        $editPlan = $res->fetch_assoc();
        $stmt->close();

        if ($editPlan) {
            $stmt = $conn->prepare("SELECT feature_name FROM {$featuresTable} WHERE plan_id = ? ORDER BY id ASC");
            $stmt->bind_param("i", $eid);
            $stmt->execute();
            $rs = $stmt->get_result();
            $farr = [];
            while ($r = $rs->fetch_assoc())
                $farr[] = $r['feature_name'];
            $stmt->close();
            $editPlan['features'] = $farr;
        }
    }
}

// --------- Load all plans + features ----------
$plans = [];
$res = $conn->query("SELECT * FROM {$plansTable} ORDER BY id DESC");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $r['monthly_price'] = isset($r['monthly_price']) ? (float) $r['monthly_price'] : 0.0;
        $r['discount_percent'] = isset($r['discount_percent']) ? (float) $r['discount_percent'] : 0.0;
        $r['yearly_price'] = isset($r['yearly_price']) ? (float) $r['yearly_price'] : round($r['monthly_price'] * 12 * (1 - $r['discount_percent'] / 100), 2);

        $stmt = $conn->prepare("SELECT feature_name FROM {$featuresTable} WHERE plan_id = ? ORDER BY id ASC");
        $stmt->bind_param("i", $r['id']);
        $stmt->execute();
        $rsf = $stmt->get_result();
        $farr = [];
        while ($fr = $rsf->fetch_assoc())
            $farr[] = $fr['feature_name'];
        $stmt->close();
        $r['features'] = $farr;
        $plans[] = $r;
    }
}

// Calculate stats
$totalRevenue = 0;
foreach ($plans as $p) {
    $totalRevenue += $p['monthly_price'] * 10;
}

// View control
$currentView = $_GET['view'] ?? 'dashboard';
?><!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>Admin Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .sidebar-link {
            transition: all 0.2s;
        }

        .sidebar-link:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .sidebar-link.active {
            background: rgba(99, 102, 241, 0.2);
            border-left: 3px solid #6366f1;
        }

        /* Submenu styles */
        .submenu {
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .submenu.hidden {
            max-height: 0;
            opacity: 0;
        }

        .submenu:not(.hidden) {
            max-height: 500px;
            opacity: 1;
        }

        #plansChevron {
            transition: transform 0.3s ease;
        }

        #plansChevron.rotate-180 {
            transform: rotate(180deg);
        }
    </style>
</head>

<body class="bg-gray-50">

    <!-- Sidebar -->
    <div class="fixed left-0 top-0 h-full w-64 bg-gray-900 text-white flex flex-col z-50">
        <!-- Header -->
        <div class="p-6 border-b border-gray-800">
            <h1 class="text-xl font-bold">Admin</h1>
        </div>

        <!-- Navigation -->
        <nav class="flex-1 p-4 overflow-y-auto">
            <a href="?view=dashboard"
                class="sidebar-link flex items-center gap-3 px-4 py-3 rounded-lg mb-1 <?= $currentView === 'dashboard' ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i>
                <span>Dashboard</span>
            </a>

            <!-- Plans Menu with Submenu -->
            <div class="mb-1">
                <div class="sidebar-link flex items-center gap-3 px-4 py-3 rounded-lg cursor-pointer <?= in_array($currentView, ['plans', 'create-plan']) || isset($_GET['edit']) ? 'active' : '' ?>"
                    id="plansMenuToggle">
                    <i class="bi bi-card-list"></i>
                    <span>Plans</span>
                    <i class="bi bi-chevron-down ml-auto text-sm transition-transform" id="plansChevron"></i>
                </div>
                <div class="submenu pl-12 mt-1 space-y-1 <?= in_array($currentView, ['plans', 'create-plan']) || isset($_GET['edit']) ? '' : 'hidden' ?>"
                    id="plansSubmenu">
                    <a href="?view=create-plan"
                        class="block px-4 py-2 text-sm text-gray-300 hover:text-white hover:bg-gray-800 rounded-lg <?= ($currentView === 'create-plan' || isset($_GET['edit'])) ? 'bg-gray-800 text-white' : '' ?>">
                        <i class="bi bi-plus-circle mr-2"></i>Create Plan
                    </a>
                    <a href="?view=plans"
                        class="block px-4 py-2 text-sm text-gray-300 hover:text-white hover:bg-gray-800 rounded-lg <?= $currentView === 'plans' ? 'bg-gray-800 text-white' : '' ?>">
                        <i class="bi bi-list-ul mr-2"></i>View All Plans
                    </a>
                </div>
            </div>

            <a href="?view=payments"
                class="sidebar-link flex items-center gap-3 px-4 py-3 rounded-lg mb-1 <?= $currentView === 'payments' ? 'active' : '' ?>">
                <i class="bi bi-credit-card"></i>
                <span>Subscription requests</span>
            </a>

            <a href="?view=tickets"
                class="sidebar-link flex items-center gap-3 px-4 py-3 rounded-lg mb-1 <?= $currentView === 'tickets' ? 'active' : '' ?>">
                <i class="bi bi-ticket-perforated"></i>
                <span>Tickets</span>
                <span class="ml-auto bg-red-500 text-white text-xs px-2 py-0.5 rounded-full">3</span>
            </a>

            <a href="?view=users"
                class="sidebar-link flex items-center gap-3 px-4 py-3 rounded-lg mb-1 <?= $currentView === 'users' ? 'active' : '' ?>">
                <i class="bi bi-people"></i>
                <span>Manage Users</span>
            </a>

            <a href="?view=revenue"
                class="sidebar-link flex items-center gap-3 px-4 py-3 rounded-lg mb-1 <?= $currentView === 'revenue' ? 'active' : '' ?>">
                <i class="bi bi-bar-chart"></i>
                <span>Revenue</span>
            </a>

            <a href="?view=settings"
                class="sidebar-link flex items-center gap-3 px-4 py-3 rounded-lg mb-1 <?= $currentView === 'settings' ? 'active' : '' ?>">
                <i class="bi bi-gear"></i>
                <span>Settings</span>
            </a>
        </nav>

        <!-- Logout -->
        <div class="p-4 border-t border-gray-800">
            <a href="?logout=1" class="sidebar-link flex items-center gap-3 px-4 py-3 rounded-lg">
                <i class="bi bi-box-arrow-right"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="ml-64 min-h-screen">
        <!-- Top Bar -->
        <div class="bg-white border-b px-8 py-4 flex justify-between items-center">
            <div>
                <h2 class="text-2xl font-semibold text-gray-800">
                    <?php
                    if ($editPlan) {
                        echo 'Edit Plan';
                    } else {
                        switch ($currentView) {
                            case 'dashboard':
                                echo 'Dashboard';
                                break;
                            case 'create-plan':
                                echo 'Create Plan';
                                break;
                            case 'plans':
                                echo 'Plans Management';
                                break;
                            case 'payments':
                                echo 'Payments';
                                break;
                            case 'tickets':
                                echo 'Support Tickets';
                                break;
                            case 'users':
                                echo 'User Management';
                                break;
                            case 'revenue':
                                echo 'Revenue Analytics';
                                break;
                            case 'settings':
                                echo 'Settings';
                                break;
                            default:
                                echo 'Dashboard';
                        }
                    }
                    ?>
                </h2>
            </div>
            <div class="text-sm text-gray-600">
                Signed in as <span class="font-semibold">Admin</span>
            </div>
        </div>

        <!-- Content Area -->
        <div class="p-8">
            <?php if ($currentView === 'dashboard' && !isset($_GET['edit'])): ?>
                <!-- Dashboard Stats -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white rounded-xl border p-6">
                        <div class="text-gray-600 text-sm font-medium mb-2">Monthly Revenue</div>
                        <div class="text-3xl font-bold text-gray-900 mb-2">₹<?= number_format($totalRevenue, 0) ?></div>
                        <div class="text-sm text-green-600">Compared to last month: +8%</div>
                    </div>

                    <div class="bg-white rounded-xl border p-6">
                        <div class="text-gray-600 text-sm font-medium mb-2">Active Users (30d)</div>
                        <div class="text-3xl font-bold text-gray-900 mb-2"><?= rand(1000, 5000) ?></div>
                        <div class="text-sm text-gray-600">New signups: 32</div>
                    </div>

                    <div class="bg-white rounded-xl border p-6">
                        <div class="text-gray-600 text-sm font-medium mb-2">Total Subscriptions</div>
                        <div class="text-3xl font-bold text-gray-900 mb-2"><?= count($plans) * rand(10, 50) ?></div>
                        <div class="text-sm text-red-600">Churn: 2.1%</div>
                    </div>

                    <div class="bg-white rounded-xl border p-6">
                        <div class="text-gray-600 text-sm font-medium mb-2">Total Plans</div>
                        <div class="text-3xl font-bold text-gray-900 mb-2"><?= count($plans) ?></div>
                        <a href="?view=create-plan"
                            class="inline-block mt-2 px-4 py-2 bg-green-500 text-white text-sm rounded-lg hover:bg-green-600">Create
                            Plan</a>
                    </div>
                </div>

                <!-- Chart Area -->
                <div class="bg-white rounded-xl border p-6 mb-8">
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-1">Revenue & Signups Overview</h3>
                        <p class="text-sm text-gray-500">Last 6 months performance</p>
                    </div>

                    <div class="flex items-center gap-6 mb-6">
                        <div class="flex items-center gap-2">
                            <div class="w-4 h-4 bg-blue-500 rounded"></div>
                            <span class="text-sm text-gray-700 font-medium">Revenue (₹)</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-4 h-4 bg-green-500 rounded"></div>
                            <span class="text-sm text-gray-700 font-medium">New Signups</span>
                        </div>
                    </div>

                    <div class="relative" style="height: 300px;">
                        <canvas id="chartCanvas" class="w-full h-full"></canvas>
                        <div id="tooltip"
                            class="absolute hidden bg-gray-900 text-white text-xs rounded py-2 px-3 pointer-events-none"
                            style="z-index: 1000;"></div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="bg-white rounded-xl border p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-semibold text-gray-800">Recent Activity</h3>
                        <a href="#" class="text-sm text-blue-600 hover:underline">View all</a>
                    </div>
                    <div class="space-y-4">
                        <div class="flex items-start justify-between py-3 border-b">
                            <div>
                                <div class="font-medium text-gray-900">john@example.com</div>
                                <div class="text-sm text-gray-500">2025-11-07 11:00</div>
                            </div>
                            <span class="px-3 py-1 bg-green-100 text-green-700 text-sm rounded">Open</span>
                        </div>
                        <div class="flex items-start justify-between py-3 border-b">
                            <div>
                                <div class="font-medium text-gray-900">amy@example.com</div>
                                <div class="text-sm text-gray-500">2025-11-06 09:30</div>
                            </div>
                            <span class="px-3 py-1 bg-yellow-100 text-yellow-700 text-sm rounded">Pending</span>
                        </div>
                        <div class="flex items-start justify-between py-3">
                            <div>
                                <div class="font-medium text-gray-900">sam@example.com</div>
                                <div class="text-sm text-gray-500">2025-10-30 14:20</div>
                            </div>
                            <span class="px-3 py-1 bg-gray-100 text-gray-700 text-sm rounded">Resolved</span>
                        </div>
                    </div>
                </div>

                <script>
                    // Enhanced chart drawing with hover tooltips
                    const canvas = document.getElementById('chartCanvas');
                    const ctx = canvas.getContext('2d');
                    const tooltip = document.getElementById('tooltip');

                    // Set canvas size
                    canvas.width = canvas.offsetWidth;
                    canvas.height = canvas.offsetHeight;

                    // Data
                    const revenueData = [25000, 30000, 28000, 40000, 42000, 45000];
                    const signupData = [45, 60, 55, 75, 80, 85];
                    const labels = ['Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov'];

                    // Chart configuration
                    const padding = { top: 20, right: 40, bottom: 40, left: 60 };
                    const chartWidth = canvas.width - padding.left - padding.right;
                    const chartHeight = canvas.height - padding.top - padding.bottom;
                    const barWidth = chartWidth / revenueData.length;

                    // Scales
                    const maxRevenue = 50000;
                    const maxSignups = 100;

                    // Store point positions for hover detection
                    const points = [];
                    const bars = [];

                    // Helper functions
                    function getX(index) {
                        return padding.left + index * barWidth + barWidth / 2;
                    }

                    function getYRevenue(value) {
                        return padding.top + chartHeight - (value / maxRevenue) * chartHeight;
                    }

                    function getYSignup(value) {
                        return padding.top + chartHeight - (value / maxSignups) * chartHeight;
                    }

                    function drawChart() {
                        // Clear canvas
                        ctx.clearRect(0, 0, canvas.width, canvas.height);

                        // Draw Y-axis gridlines and labels (Revenue)
                        ctx.strokeStyle = '#e5e7eb';
                        ctx.fillStyle = '#9ca3af';
                        ctx.font = '11px sans-serif';
                        ctx.textAlign = 'right';
                        ctx.lineWidth = 1;

                        for (let i = 0; i <= 5; i++) {
                            const value = (maxRevenue / 5) * i;
                            const y = getYRevenue(value);

                            // Gridline
                            ctx.beginPath();
                            ctx.moveTo(padding.left, y);
                            ctx.lineTo(canvas.width - padding.right, y);
                            ctx.stroke();

                            // Label
                            ctx.fillText('₹' + (value / 1000) + 'K', padding.left - 10, y + 4);
                        }

                        // Draw bars (Revenue)
                        ctx.fillStyle = '#3b82f6';
                        bars.length = 0;
                        revenueData.forEach((val, i) => {
                            const height = (val / maxRevenue) * chartHeight;
                            const x = padding.left + i * barWidth + barWidth * 0.25;
                            const y = getYRevenue(val);
                            const width = barWidth * 0.5;

                            // Store bar position
                            bars.push({ x, y, width, height, value: val, label: labels[i] });

                            // Bar with rounded top
                            ctx.beginPath();
                            ctx.roundRect(x, y, width, height, [4, 4, 0, 0]);
                            ctx.fill();
                        });

                        // Draw line (Signups)
                        ctx.strokeStyle = '#10b981';
                        ctx.lineWidth = 3;
                        ctx.beginPath();
                        signupData.forEach((val, i) => {
                            const x = getX(i);
                            const y = getYSignup(val);
                            if (i === 0) ctx.moveTo(x, y);
                            else ctx.lineTo(x, y);
                        });
                        ctx.stroke();

                        // Draw points
                        points.length = 0;
                        signupData.forEach((val, i) => {
                            const x = getX(i);
                            const y = getYSignup(val);

                            // Store point position
                            points.push({ x, y, value: val, revenue: revenueData[i], label: labels[i] });

                            // Outer circle (white)
                            ctx.fillStyle = '#ffffff';
                            ctx.beginPath();
                            ctx.arc(x, y, 6, 0, Math.PI * 2);
                            ctx.fill();

                            // Inner circle (green)
                            ctx.fillStyle = '#10b981';
                            ctx.beginPath();
                            ctx.arc(x, y, 4, 0, Math.PI * 2);
                            ctx.fill();
                        });

                        // Draw X-axis labels
                        ctx.fillStyle = '#6b7280';
                        ctx.font = '12px sans-serif';
                        ctx.textAlign = 'center';
                        labels.forEach((label, i) => {
                            const x = getX(i);
                            ctx.fillText(label, x, canvas.height - padding.bottom + 20);
                        });

                        // Draw axis lines
                        ctx.strokeStyle = '#d1d5db';
                        ctx.lineWidth = 2;

                        // X-axis
                        ctx.beginPath();
                        ctx.moveTo(padding.left, canvas.height - padding.bottom);
                        ctx.lineTo(canvas.width - padding.right, canvas.height - padding.bottom);
                        ctx.stroke();

                        // Y-axis
                        ctx.beginPath();
                        ctx.moveTo(padding.left, padding.top);
                        ctx.lineTo(padding.left, canvas.height - padding.bottom);
                        ctx.stroke();
                    }

                    // Initial draw
                    drawChart();

                    // Mouse move handler for tooltip
                    canvas.addEventListener('mousemove', (e) => {
                        const rect = canvas.getBoundingClientRect();
                        const mouseX = e.clientX - rect.left;
                        const mouseY = e.clientY - rect.top;

                        let found = false;

                        // Check hover on points (signups)
                        for (const point of points) {
                            const distance = Math.sqrt(Math.pow(mouseX - point.x, 2) + Math.pow(mouseY - point.y, 2));
                            if (distance < 10) {
                                tooltip.innerHTML = `<strong>${point.label}</strong><br>Signups: ${point.value}<br>Revenue: ₹${(point.revenue / 1000).toFixed(0)}K`;
                                tooltip.style.left = (e.clientX - rect.left + 15) + 'px';
                                tooltip.style.top = (e.clientY - rect.top - 15) + 'px';
                                tooltip.classList.remove('hidden');
                                canvas.style.cursor = 'pointer';
                                found = true;
                                break;
                            }
                        }

                        // Check hover on bars (revenue)
                        if (!found) {
                            for (const bar of bars) {
                                if (mouseX >= bar.x && mouseX <= bar.x + bar.width &&
                                    mouseY >= bar.y && mouseY <= bar.y + bar.height) {
                                    tooltip.innerHTML = `<strong>${bar.label}</strong><br>Revenue: ₹${(bar.value / 1000).toFixed(0)}K`;
                                    tooltip.style.left = (e.clientX - rect.left + 15) + 'px';
                                    tooltip.style.top = (e.clientY - rect.top - 15) + 'px';
                                    tooltip.classList.remove('hidden');
                                    canvas.style.cursor = 'pointer';
                                    found = true;
                                    break;
                                }
                            }
                        }

                        if (!found) {
                            tooltip.classList.add('hidden');
                            canvas.style.cursor = 'default';
                        }
                    });

                    // Hide tooltip when mouse leaves canvas
                    canvas.addEventListener('mouseleave', () => {
                        tooltip.classList.add('hidden');
                        canvas.style.cursor = 'default';
                    });
                </script>

            <?php elseif ($currentView === 'create-plan' || isset($_GET['edit'])): ?>
                <!-- Create/Edit Plan Form -->
                <div class="max-w-4xl">
                    <div class="bg-white rounded-xl border p-8">
                        <h3 class="text-xl font-semibold mb-6"><?= $editPlan ? "Edit Plan" : "Create New Plan" ?></h3>

                        <?php if (!empty($errors)): ?>
                            <div class="mb-4 p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg">
                                <ul class="list-disc pl-5"><?php foreach ($errors as $e)
                                    echo "<li>" . s($e) . "</li>"; ?></ul>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($success)): ?>
                            <div class="mb-4 p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg">
                                <?= s($success) ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" id="planForm">
                            <input type="hidden" name="plan_id" value="<?= $editPlan ? (int) $editPlan['id'] : '' ?>">

                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Plan Title</label>
                                <input name="title"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                    required value="<?= $editPlan ? s($editPlan['title']) : '' ?>">
                            </div>

                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Short Description</label>
                                <input name="short_description"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                    value="<?= $editPlan ? s($editPlan['short_description']) : '' ?>">
                            </div>

                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Full Description</label>
                                <textarea name="description"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                    rows="3"><?= $editPlan ? s($editPlan['description']) : '' ?></textarea>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Monthly Price (₹)</label>
                                    <input name="monthly_price" id="monthly_price" type="number" step="0.01" min="0"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                        required value="<?= $editPlan ? (float) $editPlan['monthly_price'] : '' ?>">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Discount (%)</label>
                                    <input name="discount" id="discount" type="number" step="0.01" min="0" max="100"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                        value="<?= $editPlan ? (float) ($editPlan['discount_percent'] ?? 5) : 5 ?>">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Yearly Price (auto)</label>
                                    <input name="yearly_price" id="yearly_price" type="number" step="0.01"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-gray-50" readonly
                                        value="<?= $editPlan ? (float) $editPlan['yearly_price'] : '' ?>">
                                </div>
                            </div>

                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-3">Core Features</label>
                                <div class="space-y-2">
                                    <?php
                                    $includedNow = $editPlan['features'] ?? [];
                                    foreach ($baseFeatures as $i => $bf):
                                        $isChecked = in_array($bf, $includedNow) ? 'checked' : '';
                                        ?>
                                        <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg">
                                            <input type="checkbox" name="<?= 'core_' . $i ?>" value="1" <?= $isChecked ?>
                                                class="h-4 w-4 text-indigo-600 rounded focus:ring-indigo-500">
                                            <span class="text-sm font-medium text-gray-700"><?= s($bf) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-3">Custom Features</label>
                                <div id="extraFeaturesWrapper" class="space-y-2 mb-3">
                                    <?php
                                    $existing = $editPlan['features'] ?? [];
                                    $extraExisting = [];
                                    foreach ($existing as $ef) {
                                        if (!in_array($ef, $baseFeatures))
                                            $extraExisting[] = $ef;
                                    }

                                    if (!empty($extraExisting)) {
                                        foreach ($extraExisting as $f) {
                                            echo '<div class="flex gap-2 items-center"><input name="extra_features[]" class="flex-1 px-4 py-2 border border-gray-300 rounded-lg" value="' . s($f) . '"><label class="inline-flex items-center gap-2"><input type="checkbox" name="extra_included[]" value="' . s($f) . '" checked class="h-4 w-4"> Included</label><button type="button" class="removeExtra px-3 py-2 bg-red-100 text-red-700 rounded-lg hover:bg-red-200">Remove</button></div>';
                                        }
                                    } else {
                                        echo '<div class="flex gap-2 items-center"><input name="extra_features[]" class="flex-1 px-4 py-2 border border-gray-300 rounded-lg" placeholder="Feature name"><label class="inline-flex items-center gap-2"><input type="checkbox" name="extra_included[]" value="" class="h-4 w-4"> Included</label><button type="button" class="removeExtra px-3 py-2 bg-red-100 text-red-700 rounded-lg hover:bg-red-200">Remove</button></div>';
                                    }
                                    ?>
                                </div>

                                <div class="flex gap-2">
                                    <button id="addExtra" type="button"
                                        class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">+ Add
                                        Feature</button>
                                    <button id="clearExtra" type="button"
                                        class="px-4 py-2 bg-gray-50 text-gray-600 rounded-lg hover:bg-gray-100">Clear
                                        Empty</button>
                                </div>
                            </div>

                            <div class="flex gap-3 pt-4 border-t">
                                <button name="save_plan" type="submit"
                                    class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium">
                                    <?= $editPlan ? "Update Plan" : "Create Plan" ?>
                                </button>
                                <?php if ($editPlan): ?>
                                    <a href="?view=create-plan"
                                        class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 font-medium">Cancel</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        const wrapper = document.getElementById('extraFeaturesWrapper');
                        const addBtn = document.getElementById('addExtra');
                        const clearBtn = document.getElementById('clearExtra');

                        addBtn.addEventListener('click', () => {
                            const div = document.createElement('div');
                            div.className = 'flex gap-2 items-center';
                            div.innerHTML = '<input name="extra_features[]" class="flex-1 px-4 py-2 border border-gray-300 rounded-lg" placeholder="Feature name"><label class="inline-flex items-center gap-2"><input type="checkbox" name="extra_included[]" value="" class="h-4 w-4"> Included</label><button type="button" class="removeExtra px-3 py-2 bg-red-100 text-red-700 rounded-lg hover:bg-red-200">Remove</button>';
                            wrapper.appendChild(div);
                        });

                        clearBtn.addEventListener('click', () => {
                            document.querySelectorAll('#extraFeaturesWrapper input[name="extra_features[]"]').forEach(i => {
                                if (i.value.trim() === '') i.parentElement.remove();
                            });
                        });

                        wrapper.addEventListener('click', (e) => {
                            if (e.target && e.target.classList.contains('removeExtra')) {
                                e.target.parentElement.remove();
                            }
                        });

                        const form = document.getElementById('planForm');
                        form.addEventListener('submit', function (e) {
                            const inputs = Array.from(document.querySelectorAll('input[name="extra_features[]"]'));
                            const checkboxes = Array.from(document.querySelectorAll('input[name="extra_included[]"]'));
                            for (let i = 0; i < inputs.length; i++) {
                                const text = inputs[i].value.trim();
                                if (checkboxes[i]) {
                                    checkboxes[i].value = text;
                                    if (text === '') checkboxes[i].checked = false;
                                }
                            }
                        });

                        // YEARLY calc
                        const monthly = document.getElementById('monthly_price');
                        const discount = document.getElementById('discount');
                        const yearly = document.getElementById('yearly_price');

                        function recalc() {
                            let m = parseFloat(monthly?.value || 0);
                            let d = parseFloat(discount?.value || 0);
                            if (!isNaN(m) && m > 0) {
                                let base = m * 12;
                                if (!isNaN(d)) base = base * (1 - d / 100);
                                yearly.value = base.toFixed(2);
                            } else yearly.value = '';
                        }

                        monthly && monthly.addEventListener('input', recalc);
                        discount && discount.addEventListener('input', recalc);
                        recalc();
                    });
                </script>

            <?php elseif ($currentView === 'plans'): ?>
                <!-- Plans List -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($plans as $p): ?>
                        <div class="bg-white rounded-xl border p-6">
                            <div class="mb-4">
                                <h3 class="text-lg font-semibold text-gray-900"><?= s($p['title']) ?></h3>
                                <p class="text-sm text-gray-500 mt-1"><?= s($p['short_description']) ?></p>
                            </div>

                            <div class="mb-4">
                                <div class="text-3xl font-bold text-gray-900">₹<?= number_format($p['monthly_price'], 2) ?>
                                </div>
                                <div class="text-sm text-gray-500">per month</div>
                                <div class="text-sm text-green-600 mt-1"><?= number_format($p['discount_percent'], 0) ?>% off
                                    yearly</div>
                            </div>

                            <?php if (!empty($p['features'])): ?>
                                <div class="mb-4 space-y-2">
                                    <?php foreach (array_slice($p['features'], 0, 3) as $feat): ?>
                                        <div class="flex items-center gap-2 text-sm text-gray-600">
                                            <i class="bi bi-check-circle-fill text-green-500"></i>
                                            <?= s($feat) ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($p['features']) > 3): ?>
                                        <div class="text-xs text-gray-500">+ <?= count($p['features']) - 3 ?> more features</div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="flex gap-2 pt-4 border-t">
                                <a href="?edit=<?= (int) $p['id'] ?>"
                                    class="flex-1 px-4 py-2 bg-indigo-50 text-indigo-600 text-center text-sm rounded-lg hover:bg-indigo-100 font-medium">
                                    <i class="bi bi-pencil"></i> Edit
                                </a>
                                <a href="?delete=<?= (int) $p['id'] ?>" onclick="return confirm('Delete this plan?')"
                                    class="flex-1 px-4 py-2 bg-red-50 text-red-600 text-center text-sm rounded-lg hover:bg-red-100 font-medium">
                                    <i class="bi bi-trash"></i> Delete
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (empty($plans)): ?>
                        <div class="col-span-full text-center py-12">
                            <i class="bi bi-inbox text-6xl text-gray-300"></i>
                            <p class="text-gray-500 mt-4">No plans created yet</p>
                            <a href="?view=create-plan"
                                class="inline-block mt-4 px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Create
                                Your First Plan</a>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($currentView === 'payments'): ?>
                <?php
                $reqQuery = "
                    SELECT sr.*, r.name as mahal_name, r.registration_no, p.title as plan_title 
                    FROM subscription_requests sr
                    JOIN register r ON sr.mahal_id = r.id
                    JOIN plans p ON sr.plan_id = p.id
                    ORDER BY sr.created_at DESC
                ";
                $reqResult = $conn->query($reqQuery);
                $requests = [];
                if ($reqResult) {
                    while ($row = $reqResult->fetch_assoc()) {
                        $requests[] = $row;
                    }
                }
                ?>
                <div class="bg-white rounded-xl border p-6">
                    <h3 class="text-lg font-semibold mb-6">Subscription Requests</h3>

                    <?php if (empty($requests)): ?>
                        <div class="text-center py-12">
                            <i class="bi bi-inbox text-6xl text-gray-300"></i>
                            <p class="text-gray-500 mt-4">No pending requests</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="border-b text-gray-500 text-sm">
                                        <th class="p-4 font-medium">Date</th>
                                        <th class="p-4 font-medium">Mahal Name</th>
                                        <th class="p-4 font-medium">Plan</th>
                                        <th class="p-4 font-medium">Duration</th>
                                        <th class="p-4 font-medium">Total Amount</th>
                                        <th class="p-4 font-medium">Status</th>
                                        <th class="p-4 font-medium">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($requests as $req): ?>
                                        <tr class="border-b last:border-0 hover:bg-gray-50">
                                            <td class="p-4 text-sm"><?= date('M d, Y', strtotime($req['created_at'])) ?></td>
                                            <td class="p-4">
                                                <div class="font-medium text-gray-900"><?= s($req['mahal_name']) ?></div>
                                                <div class="text-xs text-gray-500"><?= s($req['registration_no']) ?></div>
                                            </td>
                                            <td class="p-4 text-sm text-gray-700"><?= s($req['plan_title']) ?></td>
                                            <td class="p-4 text-sm"><?= ucfirst($req['duration_type'] ?? 'year') ?></td>
                                            <td class="p-4 font-medium">₹<?= number_format($req['total_amount'], 2) ?></td>
                                            <td class="p-4">
                                                <?php
                                                $st = $req['status'];
                                                $stClass = 'bg-gray-100 text-gray-600';
                                                if ($st == 'pending')
                                                    $stClass = 'bg-yellow-100 text-yellow-800';
                                                if ($st == 'approved')
                                                    $stClass = 'bg-green-100 text-green-800';
                                                if ($st == 'rejected')
                                                    $stClass = 'bg-red-100 text-red-800';
                                                ?>
                                                <span class="px-2 py-1 rounded text-xs font-medium <?= $stClass ?>">
                                                    <?= ucfirst($st) ?>
                                                </span>
                                            </td>
                                            <td class="p-4">
                                                <?php if ($st === 'pending'): ?>
                                                    <div class="flex gap-2">
                                                        <button onclick="handleRequest(<?= $req['id'] ?>, 'approve')"
                                                            class="p-2 bg-green-50 text-green-600 rounded hover:bg-green-100"
                                                            title="Approve">
                                                            <i class="bi bi-check-lg"></i>
                                                        </button>
                                                        <button onclick="handleRequest(<?= $req['id'] ?>, 'reject')"
                                                            class="p-2 bg-red-50 text-red-600 rounded hover:bg-red-100" title="Reject">
                                                            <i class="bi bi-x-lg"></i>
                                                        </button>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-gray-400 text-sm">Processed</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <script>
                    function handleRequest(id, action) {
                        if (!confirm('Are you sure you want to ' + action + ' this request?')) return;

                        const formData = new FormData();
                        formData.append('request_id', id);
                        formData.append('action', action);

                        fetch('handle_subscription_request.php', {
                            method: 'POST',
                            body: formData
                        })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    alert(data.message);
                                    location.reload();
                                } else {
                                    alert('Error: ' + data.message);
                                }
                            })
                            .catch(err => {
                                console.error(err);
                                alert('Network error');
                            });
                    }
                </script>

            <?php elseif ($currentView === 'tickets'): ?>
                <div class="bg-white rounded-xl border p-6">
                    <h3 class="text-lg font-semibold mb-4">Support Tickets</h3>
                    <div class="space-y-4">
                        <div class="flex items-start justify-between p-4 border rounded-lg">
                            <div>
                                <div class="font-medium">Payment issue</div>
                                <div class="text-sm text-gray-500">john@example.com • 2 hours ago</div>
                            </div>
                            <span class="px-3 py-1 bg-red-100 text-red-700 text-sm rounded">Urgent</span>
                        </div>
                        <div class="flex items-start justify-between p-4 border rounded-lg">
                            <div>
                                <div class="font-medium">Feature request</div>
                                <div class="text-sm text-gray-500">sarah@example.com • 1 day ago</div>
                            </div>
                            <span class="px-3 py-1 bg-yellow-100 text-yellow-700 text-sm rounded">Pending</span>
                        </div>
                        <div class="flex items-start justify-between p-4 border rounded-lg">
                            <div>
                                <div class="font-medium">Login problem</div>
                                <div class="text-sm text-gray-500">mike@example.com • 3 days ago</div>
                            </div>
                            <span class="px-3 py-1 bg-green-100 text-green-700 text-sm rounded">Resolved</span>
                        </div>
                    </div>
                </div>

            <?php elseif ($currentView === 'users'): ?>
                <?php
                // Fetch all users from register table with plan information
                $usersQuery = "SELECT id, name, email, phone, status, plan, created_at FROM register ORDER BY created_at DESC";
                $usersResult = $conn->query($usersQuery);
                $users = [];
                if ($usersResult) {
                    while ($row = $usersResult->fetch_assoc()) {
                        $users[] = $row;
                    }
                }
                ?>
                <div class="bg-white rounded-xl border p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold">Manage Users (<?= count($users) ?>)</h3>
                        <div class="flex gap-2">
                            <input type="text" id="searchUsers" placeholder="Search users..."
                                class="px-4 py-2 border border-gray-300 rounded-lg text-sm">
                        </div>
                    </div>

                    <?php if (empty($users)): ?>
                        <div class="text-center py-12">
                            <i class="bi bi-people text-6xl text-gray-300"></i>
                            <p class="text-gray-500 mt-4">No users registered yet</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full" id="usersTable">
                                <thead>
                                    <tr class="border-b">
                                        <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Mahal Name</th>
                                        <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Phone Number</th>
                                        <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Email</th>
                                        <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Plan</th>
                                        <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Status</th>
                                        <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Registered</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <tr class="border-b hover:bg-gray-50 user-row" data-user-id="<?= $user['id'] ?>">
                                            <td class="py-3 px-4 font-medium"><?= s($user['name']) ?></td>
                                            <td class="py-3 px-4"><?= s($user['phone']) ?></td>
                                            <td class="py-3 px-4"><?= s($user['email']) ?></td>
                                            <td class="py-3 px-4">
                                                <?php
                                                $planName = $user['plan'] ?: 'No Plan';
                                                $planColor = $user['plan'] ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600';
                                                ?>
                                                <span
                                                    class="px-2 py-1 <?= $planColor ?> text-xs rounded font-medium"><?= s($planName) ?></span>
                                            </td>
                                            <td class="py-3 px-4">
                                                <select
                                                    class="status-dropdown px-3 py-1.5 text-xs rounded border-0 font-medium cursor-pointer focus:ring-2 focus:ring-indigo-500 status-<?= strtolower($user['status']) ?>"
                                                    data-user-id="<?= $user['id'] ?>"
                                                    data-current-status="<?= s($user['status']) ?>">
                                                    <option value="pending" <?= strtolower($user['status']) === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                    <option value="active" <?= strtolower($user['status']) === 'active' ? 'selected' : '' ?>>Active</option>
                                                    <option value="inactive" <?= strtolower($user['status']) === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                                    <option value="suspended" <?= strtolower($user['status']) === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                                </select>
                                            </td>
                                            <td class="py-3 px-4 text-sm text-gray-600">
                                                <?= date('M d, Y', strtotime($user['created_at'])) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <style>
                    /* Status dropdown styling */
                    .status-dropdown {
                        transition: all 0.2s ease;
                    }

                    .status-dropdown:hover {
                        transform: scale(1.02);
                        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                    }

                    .status-pending {
                        background-color: #fef3c7;
                        color: #92400e;
                    }

                    .status-active {
                        background-color: #d1fae5;
                        color: #065f46;
                    }

                    .status-inactive {
                        background-color: #fee2e2;
                        color: #991b1b;
                    }

                    .status-suspended {
                        background-color: #fecaca;
                        color: #7f1d1d;
                    }

                    /* Loading spinner */
                    @keyframes spin {
                        to {
                            transform: rotate(360deg);
                        }
                    }

                    .spinner {
                        display: inline-block;
                        width: 14px;
                        height: 14px;
                        border: 2px solid rgba(0, 0, 0, 0.1);
                        border-top-color: #3b82f6;
                        border-radius: 50%;
                        animation: spin 0.6s linear infinite;
                    }
                </style>

                <script>
                    // Search functionality
                    document.getElementById('searchUsers')?.addEventListener('input', function (e) {
                        const searchTerm = e.target.value.toLowerCase();
                        const rows = document.querySelectorAll('.user-row');

                        rows.forEach(row => {
                            const text = row.textContent.toLowerCase();
                            row.style.display = text.includes(searchTerm) ? '' : 'none';
                        });
                    });

                    // Status change handler with AJAX
                    document.querySelectorAll('.status-dropdown').forEach(dropdown => {
                        dropdown.addEventListener('change', function () {
                            const userId = this.getAttribute('data-user-id');
                            const currentStatus = this.getAttribute('data-current-status');
                            const newStatus = this.value;

                            if (newStatus === currentStatus.toLowerCase()) {
                                return; // No change needed
                            }

                            // Show confirmation
                            if (!confirm(`Change status to "${newStatus.toUpperCase()}" for this user?`)) {
                                this.value = currentStatus.toLowerCase();
                                return;
                            }

                            // Disable dropdown and show loading
                            const originalHTML = this.innerHTML;
                            this.disabled = true;
                            this.innerHTML = '<option>Updating...</option>';

                            // Make AJAX request
                            const formData = new FormData();
                            formData.append('action', 'update_status');
                            formData.append('user_id', userId);
                            formData.append('status', newStatus);

                            fetch('', {
                                method: 'POST',
                                body: formData
                            })
                                .then(response => response.json())
                                .then(data => {
                                    if (data.success) {
                                        // Update the dropdown class
                                        this.className = this.className.replace(/status-\w+/, 'status-' + newStatus);
                                        this.setAttribute('data-current-status', newStatus);

                                        // Show success message
                                        showToast('Status updated successfully!', 'success');
                                    } else {
                                        // Revert on error
                                        this.value = currentStatus.toLowerCase();
                                        showToast(data.message || 'Failed to update status', 'error');
                                    }
                                })
                                .catch(error => {
                                    console.error('Error:', error);
                                    this.value = currentStatus.toLowerCase();
                                    showToast('Network error. Please try again.', 'error');
                                })
                                .finally(() => {
                                    // Re-enable dropdown
                                    this.disabled = false;
                                    this.innerHTML = originalHTML;
                                    this.value = this.getAttribute('data-current-status').toLowerCase();
                                });
                        });
                    });

                    // Toast notification function
                    function showToast(message, type = 'success') {
                        // Remove existing toast if any
                        const existingToast = document.querySelector('.custom-toast');
                        if (existingToast) {
                            existingToast.remove();
                        }

                        // Create toast
                        const toast = document.createElement('div');
                        toast.className = `custom-toast fixed bottom-4 right-4 px-6 py-3 rounded-lg shadow-lg text-white font-medium z-50 ${type === 'success' ? 'bg-green-500' : 'bg-red-500'}`;
                        toast.textContent = message;

                        document.body.appendChild(toast);

                        // Animate in
                        setTimeout(() => {
                            toast.style.transform = 'translateY(0)';
                            toast.style.opacity = '1';
                        }, 10);

                        // Remove after 3 seconds
                        setTimeout(() => {
                            toast.style.transform = 'translateY(100px)';
                            toast.style.opacity = '0';
                            setTimeout(() => toast.remove(), 300);
                        }, 3000);
                    }

                    // Initial toast styling
                    const style = document.createElement('style');
                    style.textContent = `
                .custom-toast {
                    transform: translateY(100px);
                    opacity: 0;
                    transition: all 0.3s ease;
                }
            `;
                    document.head.appendChild(style);
                </script>

            <?php elseif ($currentView === 'revenue'): ?>
                <div class="bg-white rounded-xl border p-6">
                    <h3 class="text-lg font-semibold mb-4">Revenue Analytics</h3>
                    <div class="text-gray-500 text-center py-12">
                        <i class="bi bi-bar-chart text-6xl text-gray-300"></i>
                        <p class="mt-4">Advanced analytics coming soon</p>
                    </div>
                </div>

            <?php elseif ($currentView === 'settings'): ?>
                <!-- Settings Page with Admin Contact & Payment Preferences -->
                <div class="max-w-4xl">
                    <?php if (isset($_GET['saved'])): ?>
                        <div class="mb-6 p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg">
                            <i class="bi bi-check-circle-fill mr-2"></i>
                            Settings saved successfully!
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($settingsErrors)): ?>
                        <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg">
                            <ul class="list-disc pl-5">
                                <?php foreach ($settingsErrors as $err): ?>
                                    <li><?= s($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                        <!-- Admin Contact Information -->
                        <div class="bg-white rounded-xl border p-6 mb-6">
                            <div class="flex items-center gap-3 mb-6">
                                <i class="bi bi-person-circle text-2xl text-indigo-600"></i>
                                <h3 class="text-lg font-semibold text-gray-900">Admin Contact Information</h3>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Primary Phone Number <span class="text-red-500">*</span>
                                    </label>
                                    <input type="tel" name="admin_phone"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                        placeholder="+91 1234567890"
                                        value="<?= isset($adminSettings['admin_phone']) ? s($adminSettings['admin_phone']) : '' ?>">
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Alternate Phone Number
                                    </label>
                                    <input type="tel" name="admin_phone_alt"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                        placeholder="+91 0987654321"
                                        value="<?= isset($adminSettings['admin_phone_alt']) ? s($adminSettings['admin_phone_alt']) : '' ?>">
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Email Address <span class="text-red-500">*</span>
                                </label>
                                <input type="email" name="admin_email"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                    placeholder="admin@example.com" required
                                    value="<?= isset($adminSettings['admin_email']) ? s($adminSettings['admin_email']) : '' ?>">
                            </div>

                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Office Address
                                </label>
                                <textarea name="admin_address"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                    rows="3"
                                    placeholder="Enter complete office address"><?= isset($adminSettings['admin_address']) ? s($adminSettings['admin_address']) : '' ?></textarea>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Registration Charge (₹)
                                    </label>
                                    <input type="number" name="registration_charge"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                        placeholder="500" step="0.01" min="0"
                                        value="<?= isset($adminSettings['registration_charge']) ? s($adminSettings['registration_charge']) : '' ?>">
                                    <p class="text-xs text-gray-500 mt-1">One-time registration fee charged to new users</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Default Plan After Registration
                                    </label>
                                    <select name="default_plan"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                        <option value="">No Default Plan</option>
                                        <?php foreach ($plans as $plan): ?>
                                            <option value="<?= (int) $plan['id'] ?>" <?= (isset($adminSettings['default_plan']) && $adminSettings['default_plan'] == $plan['id']) ? 'selected' : '' ?>>
                                                <?= s($plan['title']) ?> -
                                                ₹<?= number_format($plan['monthly_price'], 2) ?>/month
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="text-xs text-gray-500 mt-1">Automatically assign this plan to new
                                        registrations</p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Default Plan Duration
                                    </label>
                                    <select name="default_plan_duration_type" id="durationTypeSelect"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                        <option value="1" <?= (isset($adminSettings['default_plan_duration_type']) && $adminSettings['default_plan_duration_type'] == '1') ? 'selected' : '' ?>>1 Month
                                        </option>
                                        <option value="6" <?= (isset($adminSettings['default_plan_duration_type']) && $adminSettings['default_plan_duration_type'] == '6') ? 'selected' : '' ?>>6 Months
                                            (Half Year)</option>
                                        <option value="12" <?= (isset($adminSettings['default_plan_duration_type']) && $adminSettings['default_plan_duration_type'] == '12') ? 'selected' : '' ?>>12
                                            Months (1 Year)</option>
                                        <option value="custom" <?= (isset($adminSettings['default_plan_duration_type']) && $adminSettings['default_plan_duration_type'] == 'custom') ? 'selected' : '' ?>>
                                            Custom Duration</option>
                                    </select>
                                    <p class="text-xs text-gray-500 mt-1">How long the default plan will be active</p>
                                </div>

                                <div id="customDurationField"
                                    class="<?= (isset($adminSettings['default_plan_duration_type']) && $adminSettings['default_plan_duration_type'] == 'custom') ? '' : 'hidden' ?>">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Custom Duration (Months)
                                    </label>
                                    <input type="number" name="default_plan_duration_custom" id="customDurationInput"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                        placeholder="Enter number of months" min="1"
                                        value="<?= isset($adminSettings['default_plan_duration_custom']) ? s($adminSettings['default_plan_duration_custom']) : '' ?>">
                                    <p class="text-xs text-gray-500 mt-1">Specify duration in months</p>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Preferences -->
                        <div class="bg-white rounded-xl border p-6 mb-6">
                            <div class="flex items-center gap-3 mb-6">
                                <i class="bi bi-wallet2 text-2xl text-indigo-600"></i>
                                <h3 class="text-lg font-semibold text-gray-900">Payment Preferences</h3>
                            </div>

                            <!-- Bank Details -->
                            <div class="mb-6">
                                <h4 class="text-md font-semibold text-gray-800 mb-4 flex items-center gap-2">
                                    <i class="bi bi-bank text-lg text-gray-600"></i>
                                    Bank Account Details
                                </h4>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Bank Name</label>
                                        <input type="text" name="bank_name"
                                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                            placeholder="State Bank of India"
                                            value="<?= isset($adminSettings['bank_name']) ? s($adminSettings['bank_name']) : '' ?>">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Account Holder
                                            Name</label>
                                        <input type="text" name="bank_account_name"
                                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                            placeholder="John Doe"
                                            value="<?= isset($adminSettings['bank_account_name']) ? s($adminSettings['bank_account_name']) : '' ?>">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Account Number</label>
                                        <input type="text" name="bank_account_number"
                                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                            placeholder="1234567890123456"
                                            value="<?= isset($adminSettings['bank_account_number']) ? s($adminSettings['bank_account_number']) : '' ?>">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">IFSC Code</label>
                                        <input type="text" name="bank_ifsc"
                                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                            placeholder="SBIN0001234"
                                            value="<?= isset($adminSettings['bank_ifsc']) ? s($adminSettings['bank_ifsc']) : '' ?>">
                                    </div>
                                </div>
                            </div>

                            <!-- UPI Details -->
                            <div class="mb-6">
                                <h4 class="text-md font-semibold text-gray-800 mb-4 flex items-center gap-2">
                                    <i class="bi bi-phone text-lg text-gray-600"></i>
                                    UPI Details
                                </h4>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">UPI ID</label>
                                    <input type="text" name="upi_id"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                        placeholder="yourname@paytm"
                                        value="<?= isset($adminSettings['upi_id']) ? s($adminSettings['upi_id']) : '' ?>">
                                </div>
                            </div>

                            <!-- QR Code Upload - REMOVED
                            <div>
                                <h4 class="text-md font-semibold text-gray-800 mb-4 flex items-center gap-2">
                                    <i class="bi bi-qr-code text-lg text-gray-600"></i>
                                    Payment QR Code
                                </h4>

                                <?php if (isset($adminSettings['qr_code_path']) && !empty($adminSettings['qr_code_path'])): ?>
                                    <div class="mb-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                                        <div class="flex items-start gap-4">
                                            <img src="<?= s($adminSettings['qr_code_path']) ?>" alt="Payment QR Code"
                                                class="w-32 h-32 object-contain border border-gray-300 rounded">
                                            <div class="flex-1">
                                                <p class="text-sm text-gray-600 mb-2">Current QR Code</p>
                                                <p class="text-xs text-gray-500">Upload a new image to replace</p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Upload QR Code Image
                                    </label>
                                    <input type="file" name="qr_code" accept="image/png, image/jpeg, image/jpg, image/gif"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                                    <p class="text-xs text-gray-500 mt-2">Accepted formats: PNG, JPG, JPEG, GIF (Max 2MB)
                                    </p>
                                </div>
                            </div>
                            -->
                        </div>

                        <!-- Save Button -->
                        <div class="flex justify-end gap-3">
                            <button type="submit" name="save_settings"
                                class="px-6 py-3 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium flex items-center gap-2">
                                <i class="bi bi-save"></i>
                                Save Settings
                            </button>
                        </div>
                    </form>
                </div>

            <?php endif; ?>
        </div>
    </div>

</body>
<script>
    // Plans menu toggle functionality
    document.addEventListener('DOMContentLoaded', function () {
        const plansMenuToggle = document.getElementById('plansMenuToggle');
        const plansSubmenu = document.getElementById('plansSubmenu');
        const plansChevron = document.getElementById('plansChevron');

        if (plansMenuToggle && plansSubmenu) {
            plansMenuToggle.addEventListener('click', function (e) {
                e.preventDefault();
                plansSubmenu.classList.toggle('hidden');
                plansChevron.classList.toggle('rotate-180');
            });
        }

        // Duration type selector toggle for custom field
        const durationTypeSelect = document.getElementById('durationTypeSelect');
        const customDurationField = document.getElementById('customDurationField');
        const customDurationInput = document.getElementById('customDurationInput');

        if (durationTypeSelect && customDurationField) {
            durationTypeSelect.addEventListener('change', function () {
                if (this.value === 'custom') {
                    customDurationField.classList.remove('hidden');
                    customDurationInput.setAttribute('required', 'required');
                } else {
                    customDurationField.classList.add('hidden');
                    customDurationInput.removeAttribute('required');
                    customDurationInput.value = '';
                }
            });
        }
    });
</script>

</html>
<?php
$conn->close();
?>