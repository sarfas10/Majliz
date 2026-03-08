<?php
// student_detail.php - Simplified Student Details with Notice System
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db_connection.php';

$db = get_db_connection();
if (isset($db['error'])) {
    die("DB Connection error: " . htmlspecialchars($db['error']));
}
$conn = $db['conn'];

// Fetch logged-in mahal details for sidebar (same as academics.php)
$mahal_name = "Mahal Management";
$logo_path = "logo.jpeg";
try {
    $user_id = $_SESSION['user_id'];
    $sql = "SELECT name, address, registration_no, email FROM register WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $mahal = $result->fetch_assoc();
        $mahal_name = $mahal['name'];
    }
    $stmt->close();
} catch (Exception $e) {
    // If error, use default values
}

// Create notices table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS student_notices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    teacher_id INT NOT NULL,
    notice_title VARCHAR(200) NOT NULL,
    notice_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_student (student_id),
    INDEX idx_teacher (teacher_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Get student_id from URL
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;

if ($student_id <= 0) {
    die("Invalid student ID");
}

// Handle notice actions
$action = $_POST['action'] ?? null;

// Add notice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_notice') {
    $notice_title = trim($_POST['notice_title'] ?? '');
    $notice_text = trim($_POST['notice_text'] ?? '');
    $teacher_id = $_SESSION['user_id'] ?? 0;
    
    if (!empty($notice_title) && !empty($notice_text) && $teacher_id > 0) {
        $stmt = $conn->prepare("INSERT INTO student_notices (student_id, teacher_id, notice_title, notice_text) VALUES (?, ?, ?, ?)");
        
        if ($stmt) {
            $stmt->bind_param("iiss", $student_id, $teacher_id, $notice_title, $notice_text);
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Notice sent successfully!";
            } else {
                $_SESSION['error_message'] = "Error sending notice: " . $stmt->error;
            }
            $stmt->close();
        }
    }
    
    header("Location: student_detail.php?student_id=" . $student_id);
    exit;
}

// Edit notice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit_notice') {
    $notice_id = intval($_POST['notice_id'] ?? 0);
    $notice_title = trim($_POST['notice_title'] ?? '');
    $notice_text = trim($_POST['notice_text'] ?? '');
    
    if ($notice_id > 0 && !empty($notice_title) && !empty($notice_text)) {
        $stmt = $conn->prepare("UPDATE student_notices SET notice_title = ?, notice_text = ? WHERE id = ? AND student_id = ?");
        
        if ($stmt) {
            $stmt->bind_param("ssii", $notice_title, $notice_text, $notice_id, $student_id);
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Notice updated successfully!";
            } else {
                $_SESSION['error_message'] = "Error updating notice: " . $stmt->error;
            }
            $stmt->close();
        }
    }
    
    header("Location: student_detail.php?student_id=" . $student_id);
    exit;
}

// Delete notice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_notice') {
    $notice_id = intval($_POST['notice_id'] ?? 0);
    
    if ($notice_id > 0) {
        $stmt = $conn->prepare("DELETE FROM student_notices WHERE id = ? AND student_id = ?");
        
        if ($stmt) {
            $stmt->bind_param("ii", $notice_id, $student_id);
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Notice deleted successfully!";
            } else {
                $_SESSION['error_message'] = "Error deleting notice: " . $stmt->error;
            }
            $stmt->close();
        }
    }
    
    header("Location: student_detail.php?student_id=" . $student_id);
    exit;
}

// Fetch student details with class information
$stmt = $conn->prepare("
    SELECT 
        s.*,
        c.class_name,
        c.division,
        m.head_name as member_name,
        m.phone as member_phone,
        m.email as member_email
    FROM students s
    JOIN classes c ON s.class_id = c.id
    LEFT JOIN members m ON s.member_id = m.id
    WHERE s.id = ?
");

if (!$stmt) {
    die("Error preparing student query: " . htmlspecialchars($conn->error));
}

$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    die("Student not found");
}

// Calculate attendance statistics
$attendance_stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_days,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
        SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused_days
    FROM student_attendance
    WHERE student_id = ?
");

$attendance_stmt->bind_param("i", $student_id);
$attendance_stmt->execute();
$attendance_stats = $attendance_stmt->get_result()->fetch_assoc();
$attendance_stmt->close();

$attendance_percentage = 0;
if ($attendance_stats['total_days'] > 0) {
    $attendance_percentage = ($attendance_stats['present_days'] / $attendance_stats['total_days']) * 100;
}

// Fetch exam results
$exam_stmt = $conn->prepare("
    SELECT 
        exam_name,
        exam_date,
        subject,
        marks_obtained,
        total_marks,
        grade,
        percentage,
        remarks
    FROM student_exam_results
    WHERE student_id = ?
    ORDER BY exam_date DESC, exam_name, subject
");

$exam_stmt->bind_param("i", $student_id);
$exam_stmt->execute();
$exam_results = $exam_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$exam_stmt->close();

// Calculate average performance
$avg_percentage = 0;
if (!empty($exam_results)) {
    $total_percentage = array_sum(array_column($exam_results, 'percentage'));
    $avg_percentage = $total_percentage / count($exam_results);
}

// Fetch notices
$notices_stmt = $conn->prepare("
    SELECT 
        n.*
    FROM student_notices n
    WHERE n.student_id = ?
    ORDER BY n.created_at DESC
");

$notices_stmt->bind_param("i", $student_id);
$notices_stmt->execute();
$notices = $notices_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$notices_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($student['student_name']) ?> - Student Details - <?php echo htmlspecialchars($mahal_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Dashboard CSS styles from academics.php */
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

        /* Sidebar (same as academics.php) */
        .sidebar {
            width: 288px;
            background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary) 100%);
            border-right: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
            position: fixed;
            inset: 0 auto 0 0;
            display: flex;
            flex-direction: column;
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
            overflow: hidden;
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
            overflow: hidden;
        }

        /* Hide scrollbar globally */
        ::-webkit-scrollbar {
            width: 0;
            height: 0;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: transparent;
        }

        /* Firefox */
        * {
            scrollbar-width: none;
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
            padding: 16px 24px;
            background: var(--card);
            border-bottom: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .app-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text);
            flex: 1;
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
            margin-right: 16px;
        }

        .floating-menu-btn:hover {
            background: var(--card-alt);
            transform: translateY(-1px);
            box-shadow: var(--shadow-lg);
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

        /* Student Detail specific styles */
        .page-header {
            background: white;
            border-bottom: 1px solid var(--border);
            padding: 22px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 50;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-title i {
            color: var(--primary);
            font-size: 18px;
        }

        .header-actions {
            display: flex;
            gap: 8px;
        }

        .btn {
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 13px;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(74, 111, 165, 0.3);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--border);
            color: var(--text);
        }

        .btn-outline:hover {
            background: var(--card-alt);
            border-color: var(--primary-light);
        }

        /* Content Styles */
        .content-container {
            flex: 1;
            padding: 24px;
        }

        .card {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .card:hover {
            box-shadow: var(--shadow-lg);
        }

        .card-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--border);
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-title i {
            color: var(--primary);
        }

        .info-row {
            display: flex;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            min-width: 150px;
            color: var(--text-light);
            font-size: 14px;
        }

        .info-value {
            flex: 1;
            font-size: 14px;
            color: var(--text);
        }

        /* Stats Cards */
        .stat-card {
            background: var(--card);
            border-radius: var(--radius);
            padding: 20px;
            text-align: center;
            border: 1px solid var(--border);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(to bottom, var(--primary), var(--primary-light));
        }

        .stat-value {
            font-size: 28px;
            font-weight: 800;
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

        .mini-stat {
            background: var(--card-alt);
            padding: 12px;
            border-radius: var(--radius-sm);
            text-align: center;
            border: 1px solid var(--border);
        }

        .mini-stat-number {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .mini-stat-label {
            font-size: 11px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Exam Results */
        .exam-item {
            background: var(--card-alt);
            padding: 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 10px;
            border-left: 4px solid var(--primary);
            transition: var(--transition);
        }

        .exam-item:hover {
            transform: translateX(2px);
        }

        .exam-subject {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text);
        }

        .exam-details {
            font-size: 13px;
            color: var(--text-light);
        }

        /* Notices */
        .notice-item {
            background: var(--card-alt);
            padding: 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 12px;
            border-left: 4px solid var(--accent);
            transition: var(--transition);
        }

        .notice-item:hover {
            transform: translateX(2px);
        }

        .notice-title {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text);
        }

        .notice-date {
            font-size: 11px;
            color: var(--text-light);
            margin-bottom: 8px;
        }

        .notice-text {
            font-size: 14px;
            line-height: 1.5;
            color: var(--text);
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
            font-size: 13px;
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 13px;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }

        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            border: none;
            font-size: 14px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
        }

        .scrollable {
            max-height: 400px;
            overflow-y: auto;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 10px;
            color: var(--border);
        }

        .badge {
            padding: 4px 8px;
            font-size: 11px;
            border-radius: 4px;
            font-weight: 600;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .page-header {
                padding: 12px 16px;
                flex-direction: column;
                gap: 12px;
                align-items: stretch;
            }

            .header-left, .header-actions {
                width: 100%;
            }

            .header-actions {
                justify-content: center;
            }

            .content-container {
                padding: 12px 16px;
            }

            .card {
                padding: 16px;
            }

            .info-label {
                min-width: 120px;
            }
        }

        @media (max-width: 480px) {
            .page-title {
                font-size: 18px;
            }
            
            .btn {
                padding: 6px 12px;
                font-size: 12px;
            }
            
            .info-row {
                flex-direction: column;
                gap: 5px;
            }
            
            .info-label {
                min-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay" hidden></div>

    <div id="app">
        <!-- Sidebar (same as academics.php) -->
        <aside class="sidebar" id="sidebar" aria-hidden="true" aria-label="Main navigation">
            <button class="sidebar-close" id="sidebarClose" aria-label="Close menu" type="button">
                <i class="fas fa-times"></i>
            </button>
            <div class="sidebar-inner">
                <!-- Profile -->
                <div class="profile" onclick="window.location.href='dashboard.php'">
                    <div class="profile-avatar">
                        <img src="<?php echo htmlspecialchars($logo_path); ?>" alt="<?php echo htmlspecialchars($mahal_name); ?> Logo" 
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                        <i class="fas fa-mosque" style="display: none;"></i>
                    </div>
                    <div class="name"><?php echo htmlspecialchars($mahal_name); ?></div>
                    <div class="role">Administrator</div>
                </div>

                <!-- Navigation -->
                <nav class="menu" role="menu">
                    <button class="menu-btn" type="button" onclick="window.location.href='dashboard.php'">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </button>

                    <button class="menu-btn" id="finance-tracking-btn" type="button">
                        <i class="fas fa-chart-line"></i>
                        <span>Finance Tracking</span>
                    </button>

                    <button class="menu-btn" id="member-manage-btn" type="button">
                        <i class="fas fa-users"></i>
                        <span>Member Management</span>
                    </button>

                    <button class="menu-btn" id="staff-manage-btn" type="button">
                        <i class="fas fa-user-tie"></i>
                        <span>Staff Management</span>
                    </button>

                    <button class="menu-btn" type="button" onclick="window.location.href='asset_management.php'">
                        <i class="fas fa-boxes"></i>
                        <span>Asset Management</span>
                    </button>

                    <button class="menu-btn active" type="button" onclick="window.location.href='academics.php'">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Academics</span>
                    </button>

                    <button class="menu-btn" id="certificate-manage-btn" type="button">
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
            <!-- Page Header (same style as academics.php) -->
            <header class="page-header">
                <div class="header-left">
                    <button class="floating-menu-btn" id="menuToggle" aria-controls="sidebar" aria-expanded="false" aria-label="Open menu" type="button">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1 class="page-title">
                        <i class="fas fa-user-graduate"></i>
                        Student Details: <?= htmlspecialchars($student['student_name']) ?>
                    </h1>
                </div>
                <div class="header-actions">
                    <a href="class_detail.php?class_id=<?= $student['class_id'] ?>" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i>
                        Back to Class
                    </a>
                </div>
            </header>

            <!-- Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show m-3" role="alert">
                    <?= htmlspecialchars($_SESSION['success_message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show m-3" role="alert">
                    <?= htmlspecialchars($_SESSION['error_message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Main Content -->
            <div class="content-container">
                <div class="row">
                    <!-- Left Column -->
                    <div class="col-lg-6">
                        <!-- Personal Information Card -->
                        <div class="card">
                            <div class="card-title">
                                <i class="fas fa-user-circle"></i>
                                Personal Information
                            </div>
                            
                            <div class="info-row">
                                <div class="info-label">Full Name</div>
                                <div class="info-value"><?= htmlspecialchars($student['student_name']) ?></div>
                            </div>
                            
                            <?php if (!empty($student['admission_number'])): ?>
                            <div class="info-row">
                                <div class="info-label">Admission No</div>
                                <div class="info-value"><?= htmlspecialchars($student['admission_number']) ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($student['year_of_joining'])): ?>
                            <div class="info-row">
                                <div class="info-label">Year of Joining</div>
                                <div class="info-value"><?= htmlspecialchars($student['year_of_joining']) ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($student['date_of_birth'])): ?>
                            <div class="info-row">
                                <div class="info-label">Date of Birth</div>
                                <div class="info-value"><?= date('d M Y', strtotime($student['date_of_birth'])) ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($student['gender'])): ?>
                            <div class="info-row">
                                <div class="info-label">Gender</div>
                                <div class="info-value"><?= htmlspecialchars($student['gender']) ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="info-row">
                                <div class="info-label">Class & Division</div>
                                <div class="info-value">
                                    Class <?= htmlspecialchars($student['class_name']) ?>
                                    <?php if (!empty($student['division'])): ?>
                                        - <?= htmlspecialchars($student['division']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Parent Information Card -->
                        <div class="card">
                            <div class="card-title">
                                <i class="fas fa-users"></i>
                                Parent/Guardian Information
                            </div>
                            
                            <?php if (!empty($student['father_name'])): ?>
                            <div class="info-row">
                                <div class="info-label">Father's Name</div>
                                <div class="info-value"><?= htmlspecialchars($student['father_name']) ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($student['parent_phone'])): ?>
                            <div class="info-row">
                                <div class="info-label">Phone</div>
                                <div class="info-value"><?= htmlspecialchars($student['parent_phone']) ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($student['parent_email'])): ?>
                            <div class="info-row">
                                <div class="info-label">Email</div>
                                <div class="info-value"><?= htmlspecialchars($student['parent_email']) ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($student['address'])): ?>
                            <div class="info-row">
                                <div class="info-label">Address</div>
                                <div class="info-value"><?= nl2br(htmlspecialchars($student['address'])) ?></div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Attendance Card -->
                        <div class="card">
                            <div class="card-title">
                                <i class="fas fa-calendar-check"></i>
                                Attendance Summary
                            </div>
                            
                            <div class="stat-card mb-3 <?= $attendance_percentage >= 90 ? 'success' : ($attendance_percentage >= 75 ? 'warning' : 'danger') ?>">
                                <div class="stat-value"><?= number_format($attendance_percentage, 1) ?>%</div>
                                <div class="stat-label">Overall Attendance</div>
                            </div>

                            <div class="row g-2">
                                <div class="col-3">
                                    <div class="mini-stat">
                                        <div class="mini-stat-number text-success"><?= $attendance_stats['present_days'] ?></div>
                                        <div class="mini-stat-label">Present</div>
                                    </div>
                                </div>
                                <div class="col-3">
                                    <div class="mini-stat">
                                        <div class="mini-stat-number text-danger"><?= $attendance_stats['absent_days'] ?></div>
                                        <div class="mini-stat-label">Absent</div>
                                    </div>
                                </div>
                                <div class="col-3">
                                    <div class="mini-stat">
                                        <div class="mini-stat-number text-warning"><?= $attendance_stats['late_days'] ?></div>
                                        <div class="mini-stat-label">Late</div>
                                    </div>
                                </div>
                                <div class="col-3">
                                    <div class="mini-stat">
                                        <div class="mini-stat-number text-info"><?= $attendance_stats['excused_days'] ?></div>
                                        <div class="mini-stat-label">Excused</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Academic Performance Card -->
                        <div class="card">
                            <div class="card-title">
                                <i class="fas fa-chart-line"></i>
                                Academic Performance
                            </div>
                            
                            <?php if (!empty($exam_results)): ?>
                                <div class="stat-card mb-3 <?= $avg_percentage >= 90 ? 'success' : ($avg_percentage >= 75 ? 'warning' : 'danger') ?>">
                                    <div class="stat-value"><?= number_format($avg_percentage, 1) ?>%</div>
                                    <div class="stat-label">Average Score</div>
                                </div>

                                <div class="scrollable">
                                    <?php foreach (array_slice($exam_results, 0, 10) as $result): ?>
                                        <div class="exam-item">
                                            <div class="exam-subject"><?= htmlspecialchars($result['subject']) ?></div>
                                            <div class="exam-details">
                                                <strong><?= htmlspecialchars($result['exam_name']) ?></strong> - 
                                                <?= number_format($result['marks_obtained'], 1) ?>/<?= number_format($result['total_marks'], 1) ?>
                                                (<?= number_format($result['percentage'], 1) ?>%) - 
                                                Grade: <span class="badge bg-primary"><?= htmlspecialchars($result['grade']) ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-file-alt"></i>
                                    <p>No exam results available</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Right Column - Notices -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-title">
                                <i class="fas fa-bullhorn"></i>
                                Parent Notices
                            </div>
                            
                            <!-- Send Notice Form -->
                            <form method="post" class="mb-4">
                                <input type="hidden" name="action" value="add_notice">
                                <div class="form-group">
                                    <label class="form-label">Notice Title</label>
                                    <input type="text" name="notice_title" class="form-control" placeholder="Enter title" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Notice Message</label>
                                    <textarea name="notice_text" class="form-control" rows="3" placeholder="Enter message" required></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-paper-plane"></i> Send Notice
                                </button>
                            </form>

                            <hr class="my-4">

                            <!-- Notices List -->
                            <div style="margin-bottom: 15px;">
                                <strong>Sent Notices (<?= count($notices) ?>)</strong>
                            </div>
                            
                            <div class="scrollable">
                                <?php if (!empty($notices)): ?>
                                    <?php foreach ($notices as $notice): ?>
                                        <div class="notice-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <div class="notice-title"><?= htmlspecialchars($notice['notice_title']) ?></div>
                                                    <div class="notice-date">
                                                        <?= date('d M Y, h:i A', strtotime($notice['created_at'])) ?>
                                                    </div>
                                                </div>
                                                <div class="d-flex gap-2">
                                                    <button class="btn btn-sm btn-primary" 
                                                            onclick="editNotice(<?= $notice['id'] ?>, '<?= htmlspecialchars(addslashes($notice['notice_title'])) ?>', '<?= htmlspecialchars(addslashes($notice['notice_text'])) ?>')"
                                                            title="Edit Notice">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-danger" 
                                                            onclick="deleteNotice(<?= $notice['id'] ?>)"
                                                            title="Delete Notice">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="notice-text mt-2">
                                                <?= nl2br(htmlspecialchars($notice['notice_text'])) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class="fas fa-bullhorn"></i>
                                        <p>No notices sent yet</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Edit Notice Modal -->
    <div class="modal fade" id="editNoticeModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <input type="hidden" name="action" value="edit_notice">
                <input type="hidden" name="notice_id" id="edit_notice_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">Edit Notice</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Notice Title</label>
                        <input type="text" name="notice_title" id="edit_notice_title" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notice Message</label>
                        <textarea name="notice_text" id="edit_notice_text" class="form-control" rows="4" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Notice</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteNoticeModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <input type="hidden" name="action" value="delete_notice">
                <input type="hidden" name="notice_id" id="delete_notice_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">Delete Notice</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this notice?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        /* ---------- Sidebar functionality (same as academics.php) ---------- */
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

        /* ---------- Navigation handlers (same as academics.php) ---------- */
        document.getElementById('member-manage-btn').addEventListener('click', () => {
            window.location.href = 'member-management.php';
        });
        
        document.getElementById('certificate-manage-btn').addEventListener('click', () => {
            window.location.href = 'certificate.php';
        });
        
        document.getElementById('finance-tracking-btn').addEventListener('click', () => {
            window.location.href = 'finance-tracking.php';
        });

        document.getElementById('staff-manage-btn').addEventListener('click', () => {
            window.location.href = 'staff-management.php';
        });

        document.querySelectorAll('.menu-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                if (!this.hasAttribute('onclick')) {
                    document.querySelectorAll('.menu-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                }
            });
        });

        /* ---------- Notice Management Functions ---------- */
        function editNotice(noticeId, noticeTitle, noticeText) {
            document.getElementById('edit_notice_id').value = noticeId;
            document.getElementById('edit_notice_title').value = noticeTitle;
            document.getElementById('edit_notice_text').value = noticeText;
            
            const modal = new bootstrap.Modal(document.getElementById('editNoticeModal'));
            modal.show();
        }

        function deleteNotice(noticeId) {
            document.getElementById('delete_notice_id').value = noticeId;
            
            const modal = new bootstrap.Modal(document.getElementById('deleteNoticeModal'));
            modal.show();
        }

        // Auto-dismiss alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                    bsAlert.close();
                }, 5000);
            });

            // Prevent form resubmission on refresh
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>