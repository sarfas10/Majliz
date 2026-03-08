<?php
// academics.php - With EXACT SAME Sidebar as asset_management.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

// Check if connection was successful
if (isset($db_result['error'])) {
  die("Database connection failed: " . $db_result['error']);
}

$conn = $db_result['conn'];

// Get logged-in user details (SAME as asset_management.php)
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

// Create $mahal array for consistency (SAME as asset_management.php)
$mahal = [
  'name' => $user_name,
  'address' => $user_address,
  'registration_no' => $registration_no,
  'email' => $user_email
];

// Define logo path (SAME as asset_management.php)
$logo_path = "logo.jpeg";

/**
 * Helper: safe query result check
 */
function assert_query($conn, $res, $context = '')
{
  if ($res === false) {
    die("SQL Error{$context}: " . $conn->error);
  }
}

/**
 * Create required tables if they don't exist
 */
function create_tables_if_not_exist($conn)
{
  // Create classes table
  $sql_classes = "CREATE TABLE IF NOT EXISTS classes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_name VARCHAR(50) NOT NULL,
        division VARCHAR(10) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_class_division (class_name, division)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

  if (!$conn->query($sql_classes)) {
    die("Error creating classes table: " . $conn->error);
  }

  // Alter existing table to ensure division can be NULL
  $conn->query("ALTER TABLE classes MODIFY division VARCHAR(10) NULL");

  // Create students table
  $sql_students = "CREATE TABLE IF NOT EXISTS students (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        student_name VARCHAR(100) NOT NULL,
        roll_number VARCHAR(20),
        admission_number VARCHAR(50),
        date_of_birth DATE,
        gender ENUM('male', 'female', 'other'),
        parent_name VARCHAR(100),
        parent_phone VARCHAR(20),
        parent_email VARCHAR(100),
        address TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
        INDEX idx_class (class_id),
        INDEX idx_roll_number (roll_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

  if (!$conn->query($sql_students)) {
    die("Error creating students table: " . $conn->error);
  }

  // Create student_records table (for attendance, marks, etc.)
  $sql_records = "CREATE TABLE IF NOT EXISTS student_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        record_type ENUM('attendance', 'marks', 'behavior', 'other') NOT NULL,
        attendance_date DATE,
        attendance_status ENUM('present', 'absent', 'late', 'excused'),
        subject VARCHAR(100),
        marks_obtained DECIMAL(5,2),
        total_marks DECIMAL(5,2),
        grade VARCHAR(5),
        remarks TEXT,
        recorded_by VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
        INDEX idx_student (student_id),
        INDEX idx_record_type (record_type),
        INDEX idx_attendance_date (attendance_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

  if (!$conn->query($sql_records)) {
    die("Error creating student_records table: " . $conn->error);
  }
}

// Create tables if they don't exist
create_tables_if_not_exist($conn);

/* -------------------- Handle POST actions -------------------- */
$action = $_POST['action'] ?? null;

if ($action === 'add_class') {
  $class_name = trim($_POST['class_name'] ?? '');
  $division = trim($_POST['division'] ?? '');

  if ($class_name !== '') {
    if ($division === '') {
      $sql = "INSERT INTO classes (class_name, division) VALUES (?, NULL)";
      $stmt = $conn->prepare($sql);
      if (!$stmt) {
        die("Prepare failed: " . $conn->error);
      }
      $stmt->bind_param("s", $class_name);
    } else {
      $sql = "INSERT INTO classes (class_name, division) VALUES (?, ?)";
      $stmt = $conn->prepare($sql);
      if (!$stmt) {
        die("Prepare failed: " . $conn->error);
      }
      $stmt->bind_param("ss", $class_name, $division);
    }
    $stmt->execute();
    $stmt->close();
  }
  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

if ($action === 'delete_class') {
  $class_id = intval($_POST['class_id'] ?? 0);
  if ($class_id > 0) {
    $stmt = $conn->prepare("DELETE FROM classes WHERE id = ?");
    if ($stmt) {
      $stmt->bind_param("i", $class_id);
      $stmt->execute();
      $stmt->close();
    }
  }
  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

/* ------------------ Fetch classes with student counts -------------------- */
$classes = [];
$sql = "SELECT 
            c.id, 
            c.class_name, 
            c.division,
            c.created_at,
            COUNT(s.id) as student_count
        FROM classes c
        LEFT JOIN students s ON c.id = s.class_id
        GROUP BY c.id
        ORDER BY c.class_name, c.division";

$res = $conn->query($sql);
assert_query($conn, $res, " in fetching classes");
while ($r = $res->fetch_assoc()) {
  $classes[] = $r;
}

// Get overall stats
$total_classes = count($classes);
$total_students = 0;
foreach ($classes as $c) {
  $total_students += $c['student_count'];
}

// Get today's attendance stats
$today = date('Y-m-d');
$present_today = 0;
$absent_today = 0;

$att_res = $conn->query("
    SELECT attendance_status, COUNT(*) as cnt 
    FROM student_records 
    WHERE record_type = 'attendance' 
    AND attendance_date = '$today'
    GROUP BY attendance_status
");

if ($att_res) {
  while ($row = $att_res->fetch_assoc()) {
    if ($row['attendance_status'] === 'present') {
      $present_today = $row['cnt'];
    } elseif ($row['attendance_status'] === 'absent') {
      $absent_today = $row['cnt'];
    }
  }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Academics - <?php echo htmlspecialchars($mahal['name']); ?></title>
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Amiri:wght@400;700&display=swap"
    rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    /* EXACT SAME CSS from asset_management.php */
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

    /* Sidebar styles - EXACT SAME */
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
      padding: 11px 16px;
      border-radius: var(--radius-sm);
      width: 100%;
      text-align: left;
      font-weight: 500;
      font-size: 14px;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 10px;
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

    /* Main layout - EXACT SAME */
    .main {
      margin-left: 0;
      min-height: 100vh;
      background: var(--bg);
      display: flex;
      flex-direction: column;
      width: 100%;
      overflow-y: auto;
    }

    /* Top Row - EXACT SAME */
    .top-row {
      display: flex;
      align-items: center;
      gap: 16px;
      padding: 20px 24px;
      background: var(--card);
      border-bottom: 1px solid var(--border);
      box-shadow: var(--shadow);
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
      flex-shrink: 0;
      z-index: 2;
      width: 44px;
      height: 44px;
    }

    .floating-menu-btn:hover {
      background: var(--card-alt);
      transform: translateY(-1px);
      box-shadow: var(--shadow-lg);
      border-color: var(--primary-light);
    }

    .floating-menu-btn i {
      font-size: 18px;
    }

    .page-title {
      font-size: 24px;
      font-weight: 700;
      color: var(--text);
      display: flex;
      align-items: center;
      gap: 12px;
      flex: 1;
    }

    .page-title i {
      color: var(--primary);
      font-size: 20px;
      width: 24px;
      text-align: center;
    }

    /* Container */
    .container {
      padding: 24px;
      max-width: 1400px;
      margin: 0 auto;
      width: 100%;
      flex: 1;
    }

    /* Academics specific styles */
    /* Stats Grid */
    .stats-grid {
      padding: 20px 24px;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 16px;
      flex-shrink: 0;
    }

    .stat-card {
      background: white;
      border-radius: var(--radius);
      padding: 20px;
      display: flex;
      align-items: center;
      gap: 16px;
      box-shadow: var(--shadow);
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

    .stat-icon {
      width: 48px;
      height: 48px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      color: white;
      background: linear-gradient(135deg, var(--primary), var(--primary-light));
      flex-shrink: 0;
    }

    .stat-content {
      flex: 1;
    }

    .stat-value {
      font-size: 28px;
      font-weight: 800;
      color: var(--text);
      line-height: 1;
      margin-bottom: 4px;
    }

    .stat-label {
      font-size: 13px;
      color: var(--text-light);
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    /* Classes Section */
    .classes-container {
      flex: 1;
      padding: 0 24px 24px;
      overflow-y: auto;
      min-height: 0;
    }

    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      padding-bottom: 12px;
      border-bottom: 2px solid var(--border);
    }

    .section-title {
      font-size: 18px;
      font-weight: 700;
      color: var(--text);
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .section-title i {
      color: var(--primary);
    }

    /* Class Cards */
    .classes-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 20px;
    }

    .class-card {
      background: white;
      border-radius: var(--radius);
      overflow: hidden;
      box-shadow: var(--shadow);
      transition: var(--transition);
      border: 1px solid var(--border);
      cursor: pointer;
      position: relative;
    }

    .class-card:hover {
      transform: translateY(-4px);
      box-shadow: var(--shadow-lg);
    }

    .class-card-header {
      padding: 20px;
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
      color: white;
      position: relative;
      overflow: hidden;
    }

    .class-card-header::before {
      content: '';
      position: absolute;
      top: -50%;
      right: -50%;
      width: 200%;
      height: 200%;
      background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0) 70%);
    }

    .class-name {
      font-size: 18px;
      font-weight: 700;
      margin-bottom: 4px;
      position: relative;
      z-index: 1;
    }

    .class-division {
      font-size: 13px;
      opacity: 0.9;
      font-weight: 500;
      position: relative;
      z-index: 1;
    }

    .class-card-actions {
      position: absolute;
      top: 12px;
      right: 12px;
      z-index: 2;
    }

    .action-btn {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      background: rgba(255, 255, 255, 0.2);
      border: none;
      color: white;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: var(--transition);
      backdrop-filter: blur(5px);
    }

    .action-btn:hover {
      background: rgba(255, 255, 255, 0.3);
      transform: scale(1.1);
    }

    .class-card-body {
      padding: 16px;
    }

    .class-stats {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 12px;
    }

    .student-count {
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .student-count i {
      color: var(--primary);
      font-size: 18px;
    }

    .count-value {
      font-size: 20px;
      font-weight: 700;
      color: var(--text);
    }

    .count-label {
      font-size: 12px;
      color: var(--text-light);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .class-meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding-top: 12px;
      border-top: 1px solid var(--border);
      font-size: 12px;
      color: var(--text-light);
    }

    .created-date {
      display: flex;
      align-items: center;
      gap: 4px;
    }

    /* Add Class Card */
    .add-class-card {
      background: var(--card-alt);
      border: 2px dashed var(--border);
      border-radius: var(--radius);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 32px 20px;
      cursor: pointer;
      transition: var(--transition);
      text-align: center;
      min-height: 180px;
    }

    .add-class-card:hover {
      border-color: var(--primary);
      background: rgba(74, 111, 165, 0.05);
      transform: translateY(-2px);
    }

    .add-class-card i {
      font-size: 40px;
      color: var(--text-lighter);
      margin-bottom: 12px;
      transition: var(--transition);
    }

    .add-class-card:hover i {
      color: var(--primary);
      transform: scale(1.1);
    }

    .add-class-text {
      font-size: 15px;
      font-weight: 600;
      color: var(--text);
      margin-bottom: 6px;
    }

    .add-class-subtext {
      font-size: 13px;
      color: var(--text-light);
    }

    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 60px 24px;
      background: white;
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      margin-top: 24px;
    }

    .empty-icon {
      font-size: 56px;
      color: var(--border);
      margin-bottom: 20px;
      opacity: 0.5;
    }

    .empty-title {
      font-size: 20px;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 10px;
    }

    .empty-text {
      font-size: 15px;
      color: var(--text-light);
      max-width: 360px;
      margin: 0 auto 24px;
    }

    /* Button styles - EXACT SAME as asset_management.php */
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
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
      color: white;
    }

    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(74, 111, 165, 0.3);
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

    /* Modal Styles - EXACT SAME */
    .modal-content {
      border-radius: var(--radius);
      border: none;
      box-shadow: var(--shadow-lg);
    }

    .modal-header {
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
      color: white;
      border: none;
      padding: 20px;
      border-radius: var(--radius) var(--radius) 0 0;
    }

    .modal-title {
      font-weight: 600;
      font-size: 17px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .modal-body {
      padding: 20px;
    }

    .form-group {
      margin-bottom: 16px;
    }

    .form-label {
      display: block;
      font-weight: 600;
      color: var(--text);
      margin-bottom: 6px;
      font-size: 14px;
    }

    .form-control {
      width: 100%;
      padding: 12px 14px;
      border: 2px solid var(--border);
      border-radius: var(--radius-sm);
      font-size: 14px;
      transition: var(--transition);
    }

    .form-control:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
    }

    .form-text {
      font-size: 12px;
      color: var(--text-light);
      margin-top: 4px;
    }

    /* Validation Styles */
    .is-invalid {
      border-color: var(--error) !important;
    }

    .is-valid {
      border-color: var(--success) !important;
    }

    .invalid-feedback {
      display: none;
      width: 100%;
      margin-top: 4px;
      font-size: 12px;
      color: var(--error);
    }

    .was-validated .form-control:invalid~.invalid-feedback {
      display: block;
    }

    /* Responsive Design - EXACT SAME as asset_management.php */
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
        padding: 16px 20px;
      }

      .page-title {
        font-size: 20px;
      }

      .floating-menu-btn {
        width: 40px;
        height: 40px;
        padding: 10px;
      }

      .stats-grid {
        padding: 12px 16px;
        grid-template-columns: 1fr;
      }

      .classes-container {
        padding: 0 16px 16px;
      }

      .classes-grid {
        grid-template-columns: 1fr;
        gap: 12px;
      }

      .section-header {
        flex-direction: column;
        gap: 12px;
        text-align: center;
      }

      .stat-card {
        padding: 16px;
      }

      .stat-icon {
        width: 44px;
        height: 44px;
        font-size: 18px;
      }

      .stat-value {
        font-size: 24px;
      }
    }

    @media (max-width: 480px) {
      .top-row {
        padding: 12px 16px;
      }

      .page-title {
        font-size: 18px;
      }

      .floating-menu-btn {
        width: 36px;
        height: 36px;
        padding: 8px;
      }

      .page-title i {
        font-size: 16px;
      }

      .btn {
        padding: 8px 14px;
        font-size: 13px;
      }

      .stat-card {
        flex-direction: column;
        text-align: center;
        gap: 12px;
      }

      .class-card-header {
        padding: 16px;
      }

      .class-name {
        font-size: 16px;
      }

      .sidebar {
        width: 100%;
        max-width: 320px;
      }
    }

    .text-center {
      text-align: center;
    }

    /* Hide scrollbars - ADDED TO REMOVE SCROLLBARS */
    body::-webkit-scrollbar,
    .sidebar::-webkit-scrollbar,
    .main::-webkit-scrollbar,
    .classes-container::-webkit-scrollbar {
      display: none;
    }

    body,
    .sidebar,
    .main,
    .classes-container {
      -ms-overflow-style: none;
      scrollbar-width: none;
    }
  </style>
</head>

<body>
  <div class="sidebar-overlay" id="sidebarOverlay" hidden></div>

  <div id="app">
    <!-- EXACT SAME SIDEBAR as asset_management.php -->
    <aside class="sidebar" id="sidebar" aria-hidden="true" aria-label="Main navigation">
      <button class="sidebar-close" id="sidebarClose" aria-label="Close menu" type="button">
        <i class="fas fa-times"></i>
      </button>
      <div class="sidebar-inner">
        <!-- Profile with Logo - Clickable to Dashboard -->
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

        <!-- Navigation - EXACT SAME -->
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

          <button class="menu-btn" type="button" onclick="window.location.href='asset_management.php'">
            <i class="fas fa-boxes"></i>
            <span>Asset Management</span>
          </button>

          <button class="menu-btn active" type="button">
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
      <!-- Top Bar - EXACT SAME as asset_management.php -->
      <section class="top-row">
        <button class="floating-menu-btn" id="menuToggle" aria-controls="sidebar" aria-expanded="false"
          aria-label="Open menu" type="button">
          <i class="fas fa-bars"></i>
        </button>
        <div class="page-title">
          <i class="fas fa-graduation-cap"></i>
          Academics Dashboard
        </div>
      </section>

      <!-- Stats Section -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon">
            <i class="fas fa-chalkboard"></i>
          </div>
          <div class="stat-content">
            <div class="stat-value"><?= $total_classes ?></div>
            <div class="stat-label">Total Classes</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon">
            <i class="fas fa-user-graduate"></i>
          </div>
          <div class="stat-content">
            <div class="stat-value"><?= $total_students ?></div>
            <div class="stat-label">Total Students</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon">
            <i class="fas fa-user-check"></i>
          </div>
          <div class="stat-content">
            <div class="stat-value"><?= $present_today ?></div>
            <div class="stat-label">Present Today</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon">
            <i class="fas fa-user-times"></i>
          </div>
          <div class="stat-content">
            <div class="stat-value"><?= $absent_today ?></div>
            <div class="stat-label">Absent Today</div>
          </div>
        </div>
      </div>

      <!-- Classes Section -->
      <div class="classes-container">
        <div class="section-header">
          <h2 class="section-title">
            <i class="fas fa-chalkboard-teacher"></i>
            All Classes
          </h2>
          <div>
            <button class="btn btn-outline" onclick="location.reload()">
              <i class="fas fa-sync-alt"></i>
              Refresh
            </button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClassModal"
              style="margin-left: 10px;">
              <i class="fas fa-plus"></i>
              Add Class
            </button>
          </div>
        </div>

        <?php if (empty($classes)): ?>
          <div class="empty-state">
            <div class="empty-icon">
              <i class="fas fa-chalkboard-teacher"></i>
            </div>
            <h3 class="empty-title">No Classes Found</h3>
            <p class="empty-text">Start by adding your first class to manage students, attendance, and academic records.
            </p>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClassModal">
              <i class="fas fa-plus"></i>
              Create First Class
            </button>
          </div>
        <?php else: ?>
          <div class="classes-grid">
            <?php foreach ($classes as $class): ?>
              <div class="class-card" onclick="navigateToClass(<?= $class['id'] ?>, event)">
                <div class="class-card-header">
                  <div class="class-card-actions">
                    <button class="action-btn"
                      onclick="event.stopPropagation(); if(confirm('Delete this class and all its students?')) deleteClass(<?= $class['id'] ?>);"
                      title="Delete Class">
                      <i class="fas fa-trash"></i>
                    </button>
                  </div>
                  <h3 class="class-name">Class <?= htmlspecialchars($class['class_name']) ?></h3>
                  <?php if (!empty($class['division'])): ?>
                    <div class="class-division">Division: <?= htmlspecialchars($class['division']) ?></div>
                  <?php endif; ?>
                </div>

                <div class="class-card-body">
                  <div class="class-stats">
                    <div class="student-count">
                      <i class="fas fa-users"></i>
                      <div>
                        <div class="count-value"><?= $class['student_count'] ?></div>
                        <div class="count-label">Students</div>
                      </div>
                    </div>
                  </div>

                  <div class="class-meta">
                    <div class="created-date">
                      <i class="far fa-calendar-alt"></i>
                      Created: <?= date('M d, Y', strtotime($class['created_at'])) ?>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>

            <!-- Add Class Card -->
            <div class="add-class-card" data-bs-toggle="modal" data-bs-target="#addClassModal">
              <i class="fas fa-plus-circle"></i>
              <div class="add-class-text">Add New Class</div>
              <div class="add-class-subtext">Click to create a new class</div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <!-- Add Class Modal -->
  <div class="modal fade" id="addClassModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form method="post" class="modal-content needs-validation" novalidate id="addClassForm">
        <input type="hidden" name="action" value="add_class">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="fas fa-plus-circle"></i>
            Add New Class
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label">Class Name *</label>
            <input type="text" name="class_name" class="form-control" placeholder="e.g., 5, 10, Grade 12"
              pattern="^[0-9]+$" title="Only numbers are allowed (e.g., 5, 10)" required>
            <div class="invalid-feedback">
              Please enter a valid class name (numbers only, no special characters).
            </div>
            <div class="form-text">Enter the class number or grade (numbers only)</div>
          </div>
          <div class="form-group">
            <label class="form-label">Division (Optional)</label>
            <input type="text" name="division" class="form-control" placeholder="e.g., A, B, C" pattern="^[A-Za-z]*$"
              title="Only alphabets are allowed (e.g., A, B, C)">
            <div class="invalid-feedback">
              Division can only contain alphabets (A-Z, a-z).
            </div>
            <div class="form-text">Enter the division/section if applicable (alphabets only)</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i>
            Save Class
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete Class Form (hidden) -->
  <form id="deleteClassForm" method="post" style="display: none;">
    <input type="hidden" name="action" value="delete_class">
    <input type="hidden" name="class_id" id="deleteClassId">
  </form>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    /* ---------- Sidebar functionality (EXACT SAME as asset_management.php) ---------- */
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

    /* ---------- Academics specific functions ---------- */
    function deleteClass(classId) {
      if (confirm('Are you sure you want to delete this class? This will also delete all students in this class.')) {
        document.getElementById('deleteClassId').value = classId;
        document.getElementById('deleteClassForm').submit();
      }
    }

    function navigateToClass(classId, event) {
      if (event.target.closest('.action-btn')) {
        return;
      }
      window.location.href = 'class_detail.php?class_id=' + classId;
    }

    // Prevent form resubmission on refresh
    if (window.history.replaceState) {
      window.history.replaceState(null, null, window.location.href);
    }

    /* ---------- Form Validation for Add Class Modal ---------- */
    document.addEventListener('DOMContentLoaded', function () {
      const addClassForm = document.getElementById('addClassForm');

      if (addClassForm) {
        // Real-time validation for class name (numbers only)
        const classNameInput = addClassForm.querySelector('input[name="class_name"]');
        if (classNameInput) {
          classNameInput.addEventListener('input', function () {
            validateClassName(this);
          });

          classNameInput.addEventListener('blur', function () {
            validateClassName(this);
          });
        }

        // Real-time validation for division (alphabets only)
        const divisionInput = addClassForm.querySelector('input[name="division"]');
        if (divisionInput) {
          divisionInput.addEventListener('input', function () {
            validateDivision(this);
          });

          divisionInput.addEventListener('blur', function () {
            validateDivision(this);
          });
        }

        // Form submission validation
        addClassForm.addEventListener('submit', function (event) {
          if (!validateForm()) {
            event.preventDefault();
            event.stopPropagation();
          }
          this.classList.add('was-validated');
        });
      }
    });

    function validateClassName(input) {
      const value = input.value.trim();
      const numberRegex = /^[0-9]+$/;

      if (value === '') {
        input.classList.remove('is-valid', 'is-invalid');
        return false;
      }

      if (numberRegex.test(value)) {
        input.classList.remove('is-invalid');
        input.classList.add('is-valid');
        return true;
      } else {
        input.classList.remove('is-valid');
        input.classList.add('is-invalid');
        return false;
      }
    }

    function validateDivision(input) {
      const value = input.value.trim();
      const alphabetRegex = /^[A-Za-z]*$/; // Allows empty string

      if (value === '') {
        input.classList.remove('is-valid', 'is-invalid');
        return true; // Division is optional
      }

      if (alphabetRegex.test(value)) {
        input.classList.remove('is-invalid');
        input.classList.add('is-valid');
        return true;
      } else {
        input.classList.remove('is-valid');
        input.classList.add('is-invalid');
        return false;
      }
    }

    function validateForm() {
      const addClassForm = document.getElementById('addClassForm');
      const classNameInput = addClassForm.querySelector('input[name="class_name"]');
      const divisionInput = addClassForm.querySelector('input[name="division"]');

      let isValid = true;

      // Validate class name (required, numbers only)
      if (!validateClassName(classNameInput)) {
        isValid = false;
        if (classNameInput.value.trim() === '') {
          showToast('Class name is required and must contain numbers only');
        } else {
          showToast('Class name can only contain numbers (no special characters or alphabets)');
        }
      }

      // Validate division (optional, alphabets only)
      if (divisionInput.value.trim() !== '' && !validateDivision(divisionInput)) {
        isValid = false;
        showToast('Division can only contain alphabets (A-Z, a-z)');
      }

      return isValid;
    }

    function showToast(message) {
      // Create toast element
      const toast = document.createElement('div');
      toast.className = 'position-fixed bottom-0 end-0 p-3';
      toast.style.zIndex = '9999';
      toast.innerHTML = `
        <div class="toast align-items-center text-white bg-danger border-0 show" role="alert" aria-live="assertive" aria-atomic="true">
          <div class="d-flex">
            <div class="toast-body">
              <i class="fas fa-exclamation-circle me-2"></i>
              ${message}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
          </div>
        </div>
      `;

      // Add to DOM
      document.body.appendChild(toast);

      // Auto remove after 5 seconds
      setTimeout(() => {
        toast.remove();
      }, 5000);
    }
  </script>
</body>

</html>