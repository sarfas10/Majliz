<?php
// member_student_detail.php - Complete Student Information View
declare(strict_types=1);

require_once __DIR__ . '/session_bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

if (empty($_SESSION['member_login']) || $_SESSION['member_login'] !== true || empty($_SESSION['member'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db_connection.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db_result = get_db_connection();
if (isset($db_result['error'])) {
    die("Database connection failed: " . htmlspecialchars($db_result['error']));
}
$conn = $db_result['conn'];

$memberSess = $_SESSION['member'];
$is_sahakari = (isset($memberSess['member_type']) && $memberSess['member_type'] === 'sahakari_head');

if (!$is_sahakari) {
    $household_member_id = (isset($memberSess['member_type']) && $memberSess['member_type'] === 'family' && !empty($memberSess['parent_member_id']))
        ? (int)$memberSess['parent_member_id']
        : (int)$memberSess['member_id'];
} else {
    $household_member_id = (int)$memberSess['member_id'];
}

$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

if ($student_id <= 0) {
    $_SESSION['member_error'] = "Invalid student ID";
    header("Location: member_students.php");
    exit();
}

// Fetch member details for display
$member_display = null;
try {
    if ($is_sahakari) {
        $stmt_mem = $conn->prepare("SELECT head_name FROM sahakari_members WHERE id = ? AND mahal_id = ?");
    } else {
        $stmt_mem = $conn->prepare("SELECT head_name FROM members WHERE id = ? AND mahal_id = ?");
    }

    if (!$stmt_mem) {
        die("Database error: " . $conn->error);
    }

    $stmt_mem->bind_param("ii", $household_member_id, $memberSess['mahal_id']);
    $stmt_mem->execute();
    $result = $stmt_mem->get_result();
    $member_display = $result->fetch_assoc();
    $stmt_mem->close();

    if (!$member_display) {
        die("Member not found.");
    }
} catch (Exception $e) {
    die("Error fetching member: " . $e->getMessage());
}

// Fetch student with verification that it belongs to this member
$student = null;
try {
    $stmt = $conn->prepare("
        SELECT 
            s.*,
            c.class_name,
            c.division,
            COUNT(DISTINCT sa.attendance_date) as total_days,
            SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN sa.status = 'absent' THEN 1 ELSE 0 END) as absent_days,
            SUM(CASE WHEN sa.status = 'late' THEN 1 ELSE 0 END) as late_days,
            SUM(CASE WHEN sa.status = 'excused' THEN 1 ELSE 0 END) as excused_days,
            CASE 
                WHEN COUNT(DISTINCT sa.attendance_date) > 0 
                THEN (SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) / COUNT(DISTINCT sa.attendance_date)) * 100 
                ELSE 0 
            END as attendance_percentage
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN student_attendance sa ON s.id = sa.student_id
        WHERE s.id = ? AND s.member_id = ?
        GROUP BY s.id
    ");

    if (!$stmt) {
        die("Database error: " . $conn->error);
    }

    $stmt->bind_param("ii", $student_id, $household_member_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->fetch_assoc();
    $stmt->close();

    if (!$student) {
        $_SESSION['member_warning'] = "Student not found or access denied.";
        header("Location: member_students.php");
        exit();
    }
} catch (Exception $e) {
    die("Error fetching student: " . $e->getMessage());
}

// Fetch recent attendance (last 30 days)
$stmt = $conn->prepare("
    SELECT attendance_date, status, remarks
    FROM student_attendance
    WHERE student_id = ?
    ORDER BY attendance_date DESC
    LIMIT 30
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$attendance_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch exam results
$stmt = $conn->prepare("
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
$stmt->bind_param("i", $student_id);
$stmt->execute();
$exam_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Group exam results by exam name and calculate average
$exams_grouped = [];
$total_percentage = 0;
$subject_count = 0;

foreach ($exam_results as $result) {
    $exam_key = $result['exam_name'] . '|' . $result['exam_date'];
    if (!isset($exams_grouped[$exam_key])) {
        $exams_grouped[$exam_key] = [
            'exam_name' => $result['exam_name'],
            'exam_date' => $result['exam_date'],
            'subjects' => []
        ];
    }
    $exams_grouped[$exam_key]['subjects'][] = $result;
    
    if (is_numeric($result['percentage'])) {
        $total_percentage += (float)$result['percentage'];
        $subject_count++;
    }
}

$avg_percentage = $subject_count > 0 ? $total_percentage / $subject_count : 0;

// Fetch notices for this student
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

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= htmlspecialchars(strval($student['student_name'])) ?> - Student Details</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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

/* Sidebar */
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
    text-decoration: none;
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

.main-with-sidebar {
    margin-left: 0;
    min-height: 100vh;
    background: var(--bg);
    display: flex;
    flex-direction: column;
    width: 100%;
}

.header {
    display: flex;
    align-items: center;
    padding: 16px 24px;
    background: var(--card);
    border-bottom: 1px solid var(--border);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    position: sticky;
    top: 0;
    z-index: 100;
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
    margin-right: 16px;
}

.floating-menu-btn:hover {
    background: var(--card-alt);
    transform: translateY(-1px);
    box-shadow: var(--shadow-lg);
}

.header-content {
    flex: 1;
}

.breadcrumb {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: var(--text-light);
}

.breadcrumb a {
    color: var(--primary);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 6px;
}

.breadcrumb a:hover {
    color: var(--primary-dark);
    text-decoration: underline;
}

.main-container {
    flex: 1;
    padding: 24px;
}

.student-header {
    background: white;
    border-radius: var(--radius);
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
}

.student-title {
    font-size: 28px;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.student-title i {
    color: var(--primary);
}

.student-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    font-size: 14px;
    color: var(--text-light);
}

.student-meta > div {
    display: flex;
    align-items: center;
    gap: 8px;
}

.student-meta i {
    color: var(--primary);
}

.content-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}

.card {
    background: white;
    border-radius: var(--radius);
    padding: 24px;
    margin-bottom: 24px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    transition: var(--transition);
}

.card:hover {
    box-shadow: var(--shadow-lg);
}

.card-header {
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--border);
}

.card-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 10px;
}

.card-title i {
    color: var(--primary);
}

.card-body {
    display: flex;
    flex-direction: column;
    gap: 0;
}

.info-row {
    display: flex;
    padding: 14px 0;
    border-bottom: 1px solid var(--border);
}

.info-row:last-child {
    border-bottom: none;
}

.info-row-label {
    font-weight: 600;
    min-width: 180px;
    color: var(--text-light);
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.info-row-label i {
    color: var(--primary);
    width: 18px;
}

.info-row-value {
    flex: 1;
    font-size: 14px;
    color: var(--text);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}

.stat-card {
    background: var(--card-alt);
    border-radius: var(--radius-sm);
    padding: 16px;
    text-align: center;
    border: 1px solid var(--border);
    transition: var(--transition);
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow);
}

.stat-value {
    font-size: 24px;
    font-weight: 800;
    margin-bottom: 4px;
}

.stat-value.success {
    color: var(--success);
}

.stat-value.warning {
    color: var(--warning);
}

.stat-value.danger {
    color: var(--error);
}

.stat-value.info {
    color: var(--primary);
}

.stat-label {
    font-size: 11px;
    color: var(--text-light);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.attendance-percentage {
    background: var(--card-alt);
    border-radius: var(--radius-sm);
    padding: 20px;
    text-align: center;
    margin-bottom: 20px;
    border: 3px solid var(--success);
}

.attendance-percentage.warning {
    border-color: var(--warning);
}

.attendance-percentage.danger {
    border-color: var(--error);
}

.percentage-value {
    font-size: 48px;
    font-weight: 800;
    line-height: 1;
    margin-bottom: 8px;
}

.percentage-label {
    font-size: 13px;
    color: var(--text-light);
    font-weight: 600;
    text-transform: uppercase;
}

.scrollable {
    max-height: 400px;
    overflow-y: auto;
    margin-top: 12px;
}

.scrollable::-webkit-scrollbar {
    width: 6px;
}

.scrollable::-webkit-scrollbar-track {
    background: var(--card-alt);
    border-radius: 3px;
}

.scrollable::-webkit-scrollbar-thumb {
    background: var(--border);
    border-radius: 3px;
}

.scrollable::-webkit-scrollbar-thumb:hover {
    background: var(--text-lighter);
}

.attendance-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    background: var(--card-alt);
    border-radius: var(--radius-sm);
    margin-bottom: 8px;
    border-left: 4px solid var(--border);
}

.attendance-item.present {
    border-left-color: var(--success);
}

.attendance-item.absent {
    border-left-color: var(--error);
}

.attendance-item.late {
    border-left-color: var(--warning);
}

.attendance-item.excused {
    border-left-color: var(--primary);
}

.attendance-date {
    font-weight: 600;
    font-size: 13px;
    color: var(--text);
}

.attendance-status {
    padding: 4px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    text-transform: capitalize;
}

.attendance-status.present {
    background: rgba(39, 174, 96, 0.1);
    color: var(--success);
}

.attendance-status.absent {
    background: rgba(231, 76, 60, 0.1);
    color: var(--error);
}

.attendance-status.late {
    background: rgba(245, 158, 11, 0.1);
    color: var(--warning);
}

.attendance-status.excused {
    background: rgba(74, 111, 165, 0.1);
    color: var(--primary);
}

.exam-group {
    margin-bottom: 24px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    overflow: hidden;
}

.exam-header {
    background: var(--card-alt);
    padding: 16px;
    font-weight: 700;
    font-size: 15px;
    color: var(--text);
    border-bottom: 1px solid var(--border);
}

.exam-subjects {
    padding: 12px;
}

.exam-subject-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    background: white;
    border-radius: var(--radius-sm);
    margin-bottom: 8px;
    border: 1px solid var(--border);
}

.exam-subject-item:last-child {
    margin-bottom: 0;
}

.subject-name {
    font-weight: 600;
    color: var(--text);
    font-size: 14px;
}

.subject-score {
    display: flex;
    align-items: center;
    gap: 12px;
}

.marks {
    font-size: 13px;
    color: var(--text-light);
}

.grade-badge {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
    background: var(--primary);
    color: white;
}

.percentage-badge {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
}

.percentage-badge.excellent {
    background: rgba(39, 174, 96, 0.1);
    color: var(--success);
}

.percentage-badge.good {
    background: rgba(74, 111, 165, 0.1);
    color: var(--primary);
}

.percentage-badge.average {
    background: rgba(245, 158, 11, 0.1);
    color: var(--warning);
}

.percentage-badge.poor {
    background: rgba(231, 76, 60, 0.1);
    color: var(--error);
}

.notice-item {
    background: var(--card-alt);
    padding: 16px;
    border-radius: var(--radius-sm);
    margin-bottom: 12px;
    border-left: 4px solid var(--accent);
    transition: var(--transition);
}

.notice-item:hover {
    transform: translateX(4px);
}

.notice-title {
    font-weight: 700;
    margin-bottom: 6px;
    color: var(--text);
    font-size: 15px;
}

.notice-date {
    font-size: 11px;
    color: var(--text-light);
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.notice-date i {
    color: var(--primary);
}

.notice-text {
    font-size: 14px;
    line-height: 1.6;
    color: var(--text);
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-light);
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 12px;
    color: var(--border);
}

.empty-state p {
    font-size: 14px;
    margin: 0;
}

.btn {
    padding: 10px 20px;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 14px;
    border: none;
    cursor: pointer;
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.btn-secondary {
    background: var(--card-alt);
    color: var(--text);
    border: 2px solid var(--border);
}

.btn-secondary:hover {
    background: white;
    border-color: var(--primary);
    transform: translateY(-2px);
    box-shadow: var(--shadow);
}

@media (min-width: 1024px) {
    .sidebar {
        transform: none;
    }
    .sidebar-overlay {
        display: none;
    }
    .main-with-sidebar {
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

@media (max-width: 1024px) {
    .content-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .main-container {
        padding: 16px;
    }

    .student-header {
        padding: 16px;
    }

    .student-title {
        font-size: 22px;
    }

    .student-meta {
        flex-direction: column;
        gap: 10px;
    }

    .card {
        padding: 16px;
    }

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .info-row {
        flex-direction: column;
        gap: 6px;
    }

    .info-row-label {
        min-width: 100%;
    }
}

@media (max-width: 480px) {
    .student-title {
        font-size: 20px;
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }

    .stats-grid {
        grid-template-columns: 1fr;
    }

    .exam-subject-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }

    .subject-score {
        width: 100%;
        justify-content: space-between;
    }
}
</style>
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay" hidden></div>
<div id="app">
<aside class="sidebar" id="sidebar" aria-hidden="true">
    <button class="sidebar-close" id="sidebarClose" type="button">
        <i class="fas fa-times"></i>
    </button>
    <div class="sidebar-inner">
        <div class="profile">
            <div class="profile-avatar">
                <i class="fas fa-user"></i>
            </div>
            <div class="name"><?= htmlspecialchars($member_display['head_name']) ?></div>
            <div class="role">Member Portal</div>
        </div>

        <nav class="menu">
            <a href="member_dashboard.php" class="menu-btn">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <button class="menu-btn" type="button" onclick="window.location.href='add_family_member_self.php'">
                        <i class="fas fa-user-plus"></i>
                        <span>Add Family Member</span>
                    </button>

                      <button class="menu-btn" type="button"
        onclick="window.location.href='/main/Majliz_6Jan2026/family_students.php'">
    <i class="fas fa-user-graduate"></i>
    <span>My Students</span>

                    <button class="menu-btn" type="button" onclick="window.location.href='member_cert_requests.php'">
                        <i class="fas fa-list"></i>
                        <span>Certificate Requests</span>
                    </button>
        </nav>

        <div class="sidebar-bottom">
            <form action="member_logout.php" method="post" style="margin:0">
                <button type="submit" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </button>
            </form>
        </div>
    </div>
</aside>

<main class="main-with-sidebar" id="main">


<div class="main-container">
    <!-- Student Header -->
    <div class="student-header">
        <div class="student-header-content">
            <h1 class="student-title">
                <i class="fas fa-user-graduate"></i>
                <?= htmlspecialchars(strval($student['student_name'])) ?>
            </h1>
            <div class="student-meta">
                <?php if (!empty($student['admission_number'])): ?>
                    <div>
                        <i class="fas fa-id-card"></i>
                        <span>Admission: <?= htmlspecialchars(strval($student['admission_number'])) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($student['class_name'])): ?>
                    <div>
                        <i class="fas fa-chalkboard"></i>
                        <span>Class <?= htmlspecialchars(strval($student['class_name'])) ?>-<?= htmlspecialchars(strval($student['division'])) ?></span>
                    </div>
                <?php endif; ?>
                <?php if ((int)$student['total_days'] > 0): ?>
                    <div>
                        <i class="fas fa-chart-line"></i>
                        <span>Attendance: <?= number_format((float)$student['attendance_percentage'], 1) ?>%</span>
                    </div>
                <?php endif; ?>
                <?php if ($avg_percentage > 0): ?>
                    <div>
                        <i class="fas fa-star"></i>
                        <span>Average Score: <?= number_format($avg_percentage, 1) ?>%</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="content-grid">
        <!-- Left Column -->
        <div>
            <!-- Personal Information Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-user"></i> Personal Information
                    </div>
                </div>
                <div class="card-body">
                    <div class="info-row">
                        <span class="info-row-label"><i class="fas fa-user-circle"></i> Full Name</span>
                        <span class="info-row-value"><?= htmlspecialchars(strval($student['student_name'])) ?></span>
                    </div>
                    <?php if (!empty($student['admission_number'])): ?>
                    <div class="info-row">
                        <span class="info-row-label"><i class="fas fa-id-card"></i> Admission Number</span>
                        <span class="info-row-value"><?= htmlspecialchars(strval($student['admission_number'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="info-row-label"><i class="fas fa-venus-mars"></i> Gender</span>
                        <span class="info-row-value"><?= htmlspecialchars(strval($student['gender'] ?: 'Not specified')) ?></span>
                    </div>
                    <?php if (!empty($student['date_of_birth'])): ?>
                    <div class="info-row">
                        <span class="info-row-label"><i class="fas fa-birthday-cake"></i> Date of Birth</span>
                        <span class="info-row-value"><?= date('F j, Y', strtotime($student['date_of_birth'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($student['year_of_joining'])): ?>
                    <div class="info-row">
                        <span class="info-row-label"><i class="fas fa-calendar-plus"></i> Year of Joining</span>
                        <span class="info-row-value"><?= htmlspecialchars(strval($student['year_of_joining'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="info-row-label"><i class="fas fa-chalkboard"></i> Class</span>
                        <span class="info-row-value">
                            Class <?= htmlspecialchars(strval($student['class_name'])) ?>
                            <?php if (!empty($student['division'])): ?>
                                - <?= htmlspecialchars(strval($student['division'])) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Parent/Guardian Information Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-users"></i> Parent/Guardian Information
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($student['father_name'])): ?>
                    <div class="info-row">
                        <span class="info-row-label"><i class="fas fa-user-tie"></i> Father's Name</span>
                        <span class="info-row-value"><?= htmlspecialchars(strval($student['father_name'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($student['parent_phone'])): ?>
                    <div class="info-row">
                        <span class="info-row-label"><i class="fas fa-phone"></i> Phone</span>
                        <span class="info-row-value"><?= htmlspecialchars(strval($student['parent_phone'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($student['parent_email'])): ?>
                    <div class="info-row">
                        <span class="info-row-label"><i class="fas fa-envelope"></i> Email</span>
                        <span class="info-row-value"><?= htmlspecialchars(strval($student['parent_email'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($student['address'])): ?>
                    <div class="info-row">
                        <span class="info-row-label"><i class="fas fa-map-marker-alt"></i> Address</span>
                        <span class="info-row-value"><?= nl2br(htmlspecialchars(strval($student['address']))) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Attendance Summary Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-calendar-check"></i> Attendance Summary
                    </div>
                </div>
                <div class="card-body">
                    <?php 
                    $att_class = 'success';
                    if ($student['attendance_percentage'] < 75) {
                        $att_class = 'danger';
                    } elseif ($student['attendance_percentage'] < 90) {
                        $att_class = 'warning';
                    }
                    ?>
                    <div class="attendance-percentage <?= $att_class ?>">
                        <div class="percentage-value"><?= number_format((float)$student['attendance_percentage'], 1) ?>%</div>
                        <div class="percentage-label">Overall Attendance</div>
                    </div>

                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value success"><?= (int)$student['present_days'] ?></div>
                            <div class="stat-label">Present</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value danger"><?= (int)$student['absent_days'] ?></div>
                            <div class="stat-label">Absent</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value warning"><?= (int)$student['late_days'] ?></div>
                            <div class="stat-label">Late</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value info"><?= (int)$student['excused_days'] ?></div>
                            <div class="stat-label">Excused</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Attendance Records -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-history"></i> Recent Attendance (Last 30 Days)
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($attendance_records)): ?>
                        <div class="scrollable">
                            <?php foreach ($attendance_records as $record): ?>
                                <div class="attendance-item <?= htmlspecialchars($record['status']) ?>">
                                    <div>
                                        <div class="attendance-date">
                                            <?= date('M j, Y', strtotime($record['attendance_date'])) ?>
                                        </div>
                                        <?php if (!empty($record['remarks'])): ?>
                                            <div style="font-size: 12px; color: var(--text-light); margin-top: 4px;">
                                                <?= htmlspecialchars($record['remarks']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="attendance-status <?= htmlspecialchars($record['status']) ?>">
                                        <?= ucfirst(htmlspecialchars($record['status'])) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <p>No attendance records available</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column -->
        <div>
            <!-- Academic Performance Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-chart-line"></i> Academic Performance
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($avg_percentage > 0): ?>
                        <?php 
                        $perf_class = 'success';
                        if ($avg_percentage < 60) {
                            $perf_class = 'danger';
                        } elseif ($avg_percentage < 80) {
                            $perf_class = 'warning';
                        }
                        ?>
                        <div class="attendance-percentage <?= $perf_class ?>">
                            <div class="percentage-value"><?= number_format($avg_percentage, 1) ?>%</div>
                            <div class="percentage-label">Average Score</div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($exams_grouped)): ?>
                        <div class="scrollable">
                            <?php foreach ($exams_grouped as $exam): ?>
                                <div class="exam-group">
                                    <div class="exam-header">
                                        <div><?= htmlspecialchars($exam['exam_name']) ?></div>
                                        <div style="font-size: 12px; font-weight: 400; color: var(--text-light); margin-top: 4px;">
                                            <?= date('M j, Y', strtotime($exam['exam_date'])) ?>
                                        </div>
                                    </div>
                                    <div class="exam-subjects">
                                        <?php foreach ($exam['subjects'] as $subject): ?>
                                            <div class="exam-subject-item">
                                                <div class="subject-name">
                                                    <?= htmlspecialchars($subject['subject']) ?>
                                                </div>
                                                <div class="subject-score">
                                                    <span class="marks">
                                                        <?= number_format((float)$subject['marks_obtained'], 1) ?>/<?= number_format((float)$subject['total_marks'], 1) ?>
                                                    </span>
                                                    <span class="grade-badge">
                                                        <?= htmlspecialchars($subject['grade']) ?>
                                                    </span>
                                                    <?php 
                                                    $perc = (float)$subject['percentage'];
                                                    $badge_class = 'poor';
                                                    if ($perc >= 90) {
                                                        $badge_class = 'excellent';
                                                    } elseif ($perc >= 75) {
                                                        $badge_class = 'good';
                                                    } elseif ($perc >= 60) {
                                                        $badge_class = 'average';
                                                    }
                                                    ?>
                                                    <span class="percentage-badge <?= $badge_class ?>">
                                                        <?= number_format($perc, 1) ?>%
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
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

            <!-- Notices Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-bullhorn"></i> Teacher Notices
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($notices)): ?>
                        <div class="scrollable">
                            <?php foreach ($notices as $notice): ?>
                                <div class="notice-item">
                                    <div class="notice-title">
                                        <?= htmlspecialchars($notice['notice_title']) ?>
                                    </div>
                                    <div class="notice-date">
                                        <i class="fas fa-clock"></i>
                                        <?= date('M j, Y - h:i A', strtotime($notice['created_at'])) ?>
                                    </div>
                                    <div class="notice-text">
                                        <?= nl2br(htmlspecialchars($notice['notice_text'])) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-bullhorn"></i>
                            <p>No notices available</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Back Button -->
    <div style="margin-top: 2rem; text-align: center;">
        <a href="member_students.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Students List
        </a>
    </div>
</div>
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
</script>
</body>
</html>