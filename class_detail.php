<?php
// class_detail.php - Complete Class Management with Members Integration
// PART 1: PHP Backend

require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db_connection.php';

$db = get_db_connection();
if (isset($db['error'])) {
    die("DB Connection error: " . htmlspecialchars($db['error']));
}
$conn = $db['conn'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_duplicate'])) {
    header('Content-Type: application/json');
    
    $member_id = !empty($_POST['member_id']) ? intval($_POST['member_id']) : 0;
    
    if ($member_id > 0) {
        $check_stmt = $conn->prepare("
            SELECT 
                s.id,
                s.student_name,
                s.admission_number,
                c.class_name,
                c.division
            FROM students s
            JOIN classes c ON s.class_id = c.id
            WHERE s.member_id = ? AND s.status = 'active'
            LIMIT 1
        ");
        
        $check_stmt->bind_param("i", $member_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            $student = $result->fetch_assoc();
            echo json_encode([
                'duplicate' => true,
                'student_name' => $student['student_name'],
                'admission_number' => $student['admission_number'],
                'class' => $student['class_name'] . '-' . $student['division']
            ]);
        } else {
            echo json_encode(['duplicate' => false]);
        }
        $check_stmt->close();
    } else {
        echo json_encode(['duplicate' => false]);
    }
    
    $conn->close();
    exit;
}
// Fetch logged-in mahal details for sidebar
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

$_SESSION['mahal_name'] = $mahal_name;

function ensure_tables_exist($conn) {
    // Classes table
    $conn->query("CREATE TABLE IF NOT EXISTS classes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_name VARCHAR(50) NOT NULL,
        division VARCHAR(10) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_class_division (class_name, division)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Students table - Check if it exists first
    $table_check = $conn->query("SHOW TABLES LIKE 'students'");
    if ($table_check->num_rows == 0) {
        // Create fresh table without sahakari_member_id
        $conn->query("CREATE TABLE IF NOT EXISTS students (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            member_id INT,
            student_name VARCHAR(100) NOT NULL,
            admission_number VARCHAR(50) UNIQUE,
            date_of_birth DATE,
            gender VARCHAR(20),
            father_name VARCHAR(100),
            parent_phone VARCHAR(20),
            parent_email VARCHAR(100),
            address TEXT,
            year_of_joining INT,
            status ENUM('active','inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
            INDEX idx_class (class_id),
            INDEX idx_member (member_id),
            INDEX idx_admission (admission_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        // Drop sahakari_member_id column if it exists
        $check_sahakari = $conn->query("SHOW COLUMNS FROM students LIKE 'sahakari_member_id'");
        if ($check_sahakari->num_rows > 0) {
            $conn->query("ALTER TABLE students DROP COLUMN sahakari_member_id");
        }
        
        // Update other columns if needed
        $columns = ['member_id', 'admission_number', 'date_of_birth', 'gender', 'father_name', 
                    'parent_phone', 'parent_email', 'address', 'year_of_joining', 'status'];
        
        foreach ($columns as $col) {
            $check = $conn->query("SHOW COLUMNS FROM students LIKE '$col'");
            if ($check->num_rows == 0) {
                switch($col) {
                    case 'member_id':
                        $conn->query("ALTER TABLE students ADD COLUMN member_id INT AFTER class_id, ADD INDEX idx_member (member_id)");
                        break;
                    case 'admission_number':
                        $conn->query("ALTER TABLE students ADD COLUMN admission_number VARCHAR(50) NULL AFTER student_name, ADD UNIQUE KEY uniq_admission_number (admission_number), ADD INDEX idx_admission (admission_number)");
                        break;
                    case 'date_of_birth':
                        $conn->query("ALTER TABLE students ADD COLUMN date_of_birth DATE AFTER admission_number");
                        break;
                    case 'gender':
                        $conn->query("ALTER TABLE students ADD COLUMN gender VARCHAR(20) AFTER date_of_birth");
                        break;
                    case 'father_name':
                        $conn->query("ALTER TABLE students ADD COLUMN father_name VARCHAR(100) AFTER gender");
                        break;
                    case 'parent_phone':
                        $conn->query("ALTER TABLE students ADD COLUMN parent_phone VARCHAR(20) AFTER father_name");
                        break;
                    case 'parent_email':
                        $conn->query("ALTER TABLE students ADD COLUMN parent_email VARCHAR(100) AFTER parent_phone");
                        break;
                    case 'address':
                        $conn->query("ALTER TABLE students ADD COLUMN address TEXT AFTER parent_email");
                        break;
                    case 'year_of_joining':
                        $conn->query("ALTER TABLE students ADD COLUMN year_of_joining INT AFTER address");
                        break;
                    case 'status':
                        $conn->query("ALTER TABLE students ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER year_of_joining");
                        break;
                }
            }
        }
    }

    // Attendance table
    $conn->query("CREATE TABLE IF NOT EXISTS student_attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        attendance_date DATE NOT NULL,
        status ENUM('present','absent','late','excused') NOT NULL,
        remarks TEXT,
        marked_by VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
        UNIQUE KEY unique_attendance (student_id, attendance_date),
        INDEX idx_student (student_id),
        INDEX idx_date (attendance_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Exam results table
    $conn->query("CREATE TABLE IF NOT EXISTS student_exam_results (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        exam_name VARCHAR(100) NOT NULL,
        exam_date DATE,
        subject VARCHAR(100) NOT NULL,
        marks_obtained DECIMAL(5,2) NOT NULL,
        total_marks DECIMAL(5,2) NOT NULL,
        grade VARCHAR(5),
        percentage DECIMAL(5,2),
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
        INDEX idx_student (student_id),
        INDEX idx_exam (exam_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function generate_admission_number($conn, $year) {
    $prefix = "stu" . substr($year, -2);
    
    $stmt = $conn->prepare("
        SELECT admission_number 
        FROM students 
        WHERE admission_number LIKE ? 
        ORDER BY admission_number DESC 
        LIMIT 1
    ");
    $search_pattern = $prefix . '%';
    $stmt->bind_param("s", $search_pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $last_number = intval(substr($row['admission_number'], strlen($prefix)));
        $next_number = $last_number + 1;
    } else {
        $next_number = 101;
    }
    
    $stmt->close();
    return $prefix . $next_number;
}

ensure_tables_exist($conn);

// Get class_id from URL
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

if ($class_id <= 0) {
    die("Invalid class ID");
}

// Handle POST actions
$action = $_POST['action'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_student') {
    $member_id = !empty($_POST['member_id']) ? intval($_POST['member_id']) : null;
    $student_name = trim($_POST['student_name'] ?? '');
    $year_of_joining = intval($_POST['year_of_joining'] ?? date('Y'));
    $dob = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
    $gender = trim($_POST['gender'] ?? '');
    $father_name = trim($_POST['father_name'] ?? '');
    $parent_phone = trim($_POST['parent_phone'] ?? '');
    $parent_email = trim($_POST['parent_email'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if (!empty($student_name)) {
        // Check for duplicate based on member_id
        $is_duplicate = false;
        
        if ($member_id) {
            $duplicate_check = $conn->prepare("
                SELECT 
                    s.id, 
                    s.student_name, 
                    s.admission_number,
                    c.class_name,
                    c.division
                FROM students s
                JOIN classes c ON s.class_id = c.id
                WHERE s.member_id = ? AND s.status = 'active'
            ");
            $duplicate_check->bind_param("i", $member_id);
            $duplicate_check->execute();
            $dup_result = $duplicate_check->get_result();
            
            if ($dup_result->num_rows > 0) {
                $dup_student = $dup_result->fetch_assoc();
                $_SESSION['error_message'] = "⚠️ <strong>Student Already Exists!</strong><br><br>" .
                    "👤 <strong>Name:</strong> " . htmlspecialchars($dup_student['student_name']) . "<br>" .
                    "🏫 <strong>Class:</strong> " . htmlspecialchars($dup_student['class_name'] . '-' . $dup_student['division']) . "<br>" .
                    "🎫 <strong>Admission #:</strong> " . htmlspecialchars($dup_student['admission_number']);
                $is_duplicate = true;
            }
            $duplicate_check->close();
        }
        
        // Only proceed if not duplicate
        if (!$is_duplicate) {
            $admission_number = generate_admission_number($conn, $year_of_joining);
            
            $stmt = $conn->prepare("INSERT INTO students (class_id, member_id, student_name, admission_number, date_of_birth, gender, father_name, parent_phone, parent_email, address, year_of_joining) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iissssssssi", $class_id, $member_id, $student_name, $admission_number, $dob, $gender, $father_name, $parent_phone, $parent_email, $address, $year_of_joining);
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "✅ Student added successfully! Admission Number: " . $admission_number;
            } else {
                $_SESSION['error_message'] = "❌ Error adding student: " . $stmt->error;
            }
            $stmt->close();
        }
    }
    
    header("Location: class_detail.php?class_id=" . $class_id);
    exit;
}

// DELETE STUDENT - PERMANENT DELETE (Replace the existing delete handler)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_student') {
    $student_id = intval($_POST['student_id'] ?? 0);
    
    if ($student_id > 0) {
        // Permanently delete from database
        // The CASCADE will automatically delete related records (attendance, exam results)
        $stmt = $conn->prepare("DELETE FROM students WHERE id = ? AND class_id = ?");
        $stmt->bind_param("ii", $student_id, $class_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Student deleted successfully!";
        } else {
            $_SESSION['error_message'] = "Error deleting student: " . $stmt->error;
        }
        $stmt->close();
    }
    
    header("Location: class_detail.php?class_id=" . $class_id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'mark_attendance') {
    $attendance_date = $_POST['attendance_date'] ?? date('Y-m-d');
    $attendance_data = $_POST['attendance'] ?? [];
    
    $success_count = 0;
    foreach ($attendance_data as $student_id => $status) {
        $student_id = intval($student_id);
        
        $check = $conn->prepare("SELECT id FROM student_attendance WHERE student_id = ? AND attendance_date = ?");
        $check->bind_param("is", $student_id, $attendance_date);
        $check->execute();
        $result = $check->get_result();
        
        if ($result->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE student_attendance SET status = ? WHERE student_id = ? AND attendance_date = ?");
            $stmt->bind_param("sis", $status, $student_id, $attendance_date);
        } else {
            $stmt = $conn->prepare("INSERT INTO student_attendance (student_id, attendance_date, status) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $student_id, $attendance_date, $status);
        }
        
        if ($stmt->execute()) {
            $success_count++;
        }
        $stmt->close();
        $check->close();
    }
    
    $_SESSION['success_message'] = "Attendance marked for $success_count students!";
    header("Location: class_detail.php?class_id=" . $class_id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_exam_result') {
    $student_id = intval($_POST['student_id'] ?? 0);
    $exam_name = trim($_POST['exam_name'] ?? '');
    $exam_date = !empty($_POST['exam_date']) ? $_POST['exam_date'] : null;
    $subject = trim($_POST['subject'] ?? '');
    $marks_obtained = floatval($_POST['marks_obtained'] ?? 0);
    $total_marks = floatval($_POST['total_marks'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');
    
    $percentage = ($total_marks > 0) ? ($marks_obtained / $total_marks) * 100 : 0;
    
    $grade = 'F';
    if ($percentage >= 90) $grade = 'A+';
    elseif ($percentage >= 80) $grade = 'A';
    elseif ($percentage >= 70) $grade = 'B';
    elseif ($percentage >= 60) $grade = 'C';
    elseif ($percentage >= 50) $grade = 'D';
    elseif ($percentage >= 40) $grade = 'E';
    
    if ($student_id > 0 && !empty($exam_name) && !empty($subject) && $total_marks > 0) {
        $stmt = $conn->prepare("INSERT INTO student_exam_results (student_id, exam_name, exam_date, subject, marks_obtained, total_marks, grade, percentage, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssddsds", $student_id, $exam_name, $exam_date, $subject, $marks_obtained, $total_marks, $grade, $percentage, $remarks);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Exam result added successfully!";
        } else {
            $_SESSION['error_message'] = "Error adding exam result: " . $stmt->error;
        }
        $stmt->close();
    }
    
    header("Location: class_detail.php?class_id=" . $class_id);
    exit;
}

// Fetch class details
$class_stmt = $conn->prepare("SELECT * FROM classes WHERE id = ?");
$class_stmt->bind_param("i", $class_id);
$class_stmt->execute();
$class = $class_stmt->get_result()->fetch_assoc();
$class_stmt->close();

if (!$class) {
    die("Class not found");
}

$today = date('Y-m-d');

// Fetch students with dynamic column checking
$students = [];
$columns_query = $conn->query("SHOW COLUMNS FROM students");
$available_columns = [];
while ($col = $columns_query->fetch_assoc()) {
    $available_columns[] = $col['Field'];
}

$select_columns = ['s.id', 's.class_id', 's.student_name', 's.status'];
$optional_columns = ['member_id', 'admission_number', 'date_of_birth', 'gender', 'father_name', 
                     'parent_phone', 'parent_email', 'address', 'year_of_joining', 'created_at', 'updated_at'];

foreach ($optional_columns as $col) {
    if (in_array($col, $available_columns)) {
        $select_columns[] = 's.' . $col;
    }
}

$select_clause = implode(', ', $select_columns);
$order_by = in_array('admission_number', $available_columns) ? 's.admission_number, s.student_name' : 's.student_name';
// Fetch students - UPDATED: No status filter (deleted = removed from DB)
$stmt = $conn->prepare("
    SELECT 
        $select_clause,
        COUNT(DISTINCT sa.attendance_date) as total_days,
        SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) as present_days,
        CASE 
            WHEN COUNT(DISTINCT sa.attendance_date) > 0 
            THEN (SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) / COUNT(DISTINCT sa.attendance_date)) * 100 
            ELSE 0 
        END as attendance_percentage
    FROM students s
    LEFT JOIN student_attendance sa ON s.id = sa.student_id
    WHERE s.class_id = ?
    GROUP BY s.id
    ORDER BY $order_by
");

$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    // Set default values for missing columns
    if (!isset($row['member_id'])) $row['member_id'] = null;
    if (!isset($row['father_name'])) $row['father_name'] = '';
    if (!isset($row['parent_phone'])) $row['parent_phone'] = '';
    if (!isset($row['parent_email'])) $row['parent_email'] = '';
    if (!isset($row['admission_number'])) $row['admission_number'] = '';
    if (!isset($row['date_of_birth'])) $row['date_of_birth'] = null;
    if (!isset($row['gender'])) $row['gender'] = '';
    if (!isset($row['address'])) $row['address'] = '';
    if (!isset($row['year_of_joining'])) $row['year_of_joining'] = null;
    
    $students[] = $row;
}
$stmt->close();

$total_students = count($students);

// Today's attendance - UPDATED: No status filter
$present_today = 0;
$absent_today = 0;

$today_stmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) as present,
        SUM(CASE WHEN sa.status = 'absent' THEN 1 ELSE 0 END) as absent
    FROM students s
    LEFT JOIN student_attendance sa ON s.id = sa.student_id AND sa.attendance_date = ?
    WHERE s.class_id = ?
");

$today_stmt->bind_param("si", $today, $class_id);
$today_stmt->execute();
$today_result = $today_stmt->get_result();
$today_data = $today_result->fetch_assoc();
$present_today = intval($today_data['present'] ?? 0);
$absent_today = intval($today_data['absent'] ?? 0);
$today_stmt->close();
// Get mahal_id for fetching members
$mahal_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;

// Fetch ALL members (regular + sahakari) for autocomplete
$all_members_data = [];
if ($mahal_id > 0) {
    // Get REGULAR head members
    $member_stmt = $conn->prepare("
        SELECT 
            m.id, 
            m.head_name as name, 
            m.father_name,
            m.phone, 
            m.email, 
            m.address, 
            m.dob, 
            m.gender,
            'member' as type,
            'regular' as member_type
        FROM members m
        WHERE m.status = 'active' AND m.mahal_id = ?
        ORDER BY m.head_name
    ");
    
    if ($member_stmt) {
        $member_stmt->bind_param("i", $mahal_id);
        $member_stmt->execute();
        $result = $member_stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $all_members_data[] = $row;
        }
        $member_stmt->close();
    }
    
    // Get REGULAR family members
    $family_stmt = $conn->prepare("
        SELECT 
            fm.id,
            fm.member_id,
            fm.name,
            fm.father_name,
            fm.phone,
            fm.email,
            fm.dob,
            fm.gender,
            m.address,
            m.phone as parent_phone,
            m.email as parent_email,
            m.head_name as parent_name,
            'family' as type,
            'regular' as member_type
        FROM family_members fm
        JOIN members m ON fm.member_id = m.id
        WHERE fm.status = 'active' AND m.status = 'active' AND m.mahal_id = ?
        ORDER BY fm.name
    ");
    
    if ($family_stmt) {
        $family_stmt->bind_param("i", $mahal_id);
        $family_stmt->execute();
        $result = $family_stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $all_members_data[] = $row;
        }
        $family_stmt->close();
    }
    
    // Get SAHAKARI head members
    $sahakari_check = $conn->query("SHOW TABLES LIKE 'sahakari_members'");
    if ($sahakari_check && $sahakari_check->num_rows > 0) {
        $sahakari_stmt = $conn->prepare("
            SELECT 
                sm.id,
                sm.head_name as name,
                sm.father_name,
                sm.phone,
                sm.email,
                sm.address,
                sm.dob,
                sm.gender,
                'member' as type,
                'sahakari' as member_type
            FROM sahakari_members sm
            WHERE sm.status = 'active' AND sm.mahal_id = ?
            ORDER BY sm.head_name
        ");
        
        if ($sahakari_stmt) {
            $sahakari_stmt->bind_param("i", $mahal_id);
            $sahakari_stmt->execute();
            $result = $sahakari_stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $all_members_data[] = $row;
            }
            $sahakari_stmt->close();
        }
        
        // Get SAHAKARI family members
        $sahakari_family_stmt = $conn->prepare("
            SELECT 
                sfm.id,
                sfm.member_id,
                sfm.name,
                sfm.father_name,
                sfm.phone,
                sfm.email,
                sfm.dob,
                sfm.gender,
                sm.address,
                sm.phone as parent_phone,
                sm.email as parent_email,
                sm.head_name as parent_name,
                'family' as type,
                'sahakari' as member_type
            FROM sahakari_family_members sfm
            JOIN sahakari_members sm ON sfm.member_id = sm.id
            WHERE sfm.status = 'active' AND sm.status = 'active' AND sm.mahal_id = ?
            ORDER BY sfm.name
        ");
        
        if ($sahakari_family_stmt) {
            $sahakari_family_stmt->bind_param("i", $mahal_id);
            $sahakari_family_stmt->execute();
            $result = $sahakari_family_stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $all_members_data[] = $row;
            }
            $sahakari_family_stmt->close();
        }
    }
}

// END OF PART 1
// Continue with Part 2 for HTML/CSS
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class <?= htmlspecialchars($class['class_name']) ?>-<?= htmlspecialchars($class['division']) ?> Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Amiri:wght@400;700&display=swap" rel="stylesheet">
    <style>
        /* Import Dashboard Styles */
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

        /* ─────────────────────────────────────────────
           SIDEBAR • Enhanced Design
           ───────────────────────────────────────────── */
      
    /* Sidebar (exact same as edit_member.php) */
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
    overflow: hidden; /* ADD THIS LINE */
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
    overflow: hidden; /* ADD THIS LINE */
}
/* Duplicate Alert Styles */
.duplicate-alert {
    display: none;
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    border: 2px solid #ef4444;
    border-radius: var(--radius-sm);
    padding: 20px;
    margin-bottom: 20px;
    animation: slideDown 0.3s ease;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
}

.duplicate-alert.show {
    display: block;
}

.duplicate-alert .alert-icon {
    color: #dc2626;
    font-size: 32px;
    margin-right: 16px;
    animation: shake 0.5s ease;
}

.duplicate-alert .alert-content {
    flex: 1;
}

.duplicate-alert .alert-title {
    font-weight: 700;
    color: #991b1b;
    font-size: 18px;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.duplicate-alert .alert-details {
    color: #7f1d1d;
    font-size: 15px;
    line-height: 1.8;
    background: rgba(255, 255, 255, 0.5);
    padding: 12px;
    border-radius: 8px;
    border-left: 4px solid #dc2626;
}

.duplicate-alert .alert-details strong {
    color: #991b1b;
    font-weight: 600;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-5px); }
    75% { transform: translateX(5px); }
}

.member-details-card.duplicate {
    border-color: #ef4444;
    background: linear-gradient(135deg, #fee2e2, #fef2f2);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { border-color: #ef4444; }
    50% { border-color: #dc2626; }
}

/* Page Alert Messages */
.alert {
    border-radius: var(--radius-sm);
    padding: 16px 20px;
    margin-bottom: 20px;
    border: none;
    box-shadow: var(--shadow);
}

.alert-success {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #065f46;
    border-left: 4px solid #10b981;
}

.alert-danger {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #991b1b;
    border-left: 4px solid #ef4444;
}

.alert i {
    margin-right: 8px;
    font-size: 18px;
}
.icon-btn.delete {
    border-color: rgba(239,68,68,0.08);
}

.icon-btn.delete:hover {
    background: linear-gradient(135deg, rgba(239,68,68,0.06), rgba(239,68,68,0.02));
    color: #dc2626;
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
        /* Main layout */
        .main {
            margin-left: 0;
            min-height: 100vh;
            background: var(--bg);
            display: flex;
            flex-direction: column;
            width: 100%;
        }

        /* Enhanced Logo Row */
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--card);
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border-left: 4px solid;
            transition: var(--transition);
            border: 1px solid var(--border);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card.students { border-left-color: var(--primary); }
        .stat-card.present { border-left-color: var(--success); }
        .stat-card.absent { border-left-color: var(--error); }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text);
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }

        .students-table {
            background: var(--card);
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }

        .attendance-low { color: var(--error); font-weight: 600; }
        .attendance-good { color: var(--success); font-weight: 600; }

        .btn-action {
            padding: 0.25rem 0.75rem;
            font-size: 0.875rem;
            border-radius: var(--radius-sm);
            transition: var(--transition);
        }

        .btn-action:hover {
            transform: translateY(-1px);
        }

        .search-box {
            max-width: 400px;
            margin-bottom: 1rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            padding: 0.75rem 1rem;
        }

        .search-box:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }

        /* Autocomplete Styles */
        .autocomplete-container {
            position: relative;
        }

        .autocomplete-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--card);
            border: 1px solid var(--border);
            border-top: none;
            border-radius: 0 0 var(--radius-sm) var(--radius-sm);
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: var(--shadow-lg);
            display: none;
        }

        .autocomplete-results.show {
            display: block;
        }

        .autocomplete-item {
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 1px solid var(--border);
            transition: var(--transition);
        }

        .autocomplete-item:hover {
            background: var(--card-alt);
        }

        .autocomplete-item:last-child {
            border-bottom: none;
        }

        .autocomplete-item .name {
            font-weight: 600;
            color: var(--text);
            margin-bottom: 4px;
        }

        .autocomplete-item .details {
            font-size: 0.875rem;
            color: var(--text-light);
        }

        .autocomplete-item .badge {
            font-size: 0.75rem;
            padding: 2px 8px;
        }

        .member-details-card {
            background: var(--card-alt);
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 16px;
            margin-bottom: 16px;
            display: none;
        }

        .member-details-card.show {
            display: block;
        }

        .member-details-card h6 {
            color: var(--text);
            font-weight: 600;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--border);
        }

        .member-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .member-list li {
            padding: 10px;
            margin-bottom: 8px;
            background: var(--card);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid transparent;
        }

        .member-list li:hover {
            background: var(--bg);
            border-color: var(--primary);
        }

        .member-list li.selected {
            background: rgba(107, 186, 167, 0.1);
            border-color: var(--secondary);
        }

        .member-list li .name {
            font-weight: 600;
            color: var(--text);
        }

        .member-list li .relation {
            font-size: 0.875rem;
            color: var(--text-light);
            margin-left: 8px;
        }

        /* Modal styles */
        .modal-content {
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            background: linear-gradient(135deg, var(--card), var(--card-alt));
            border-bottom: 1px solid var(--border);
            border-radius: var(--radius) var(--radius) 0 0;
            padding: 1.5rem;
        }

        .modal-footer {
            border-top: 1px solid var(--border);
            padding: 1rem 1.5rem;
        }

        /* Progress indicator */
        .progress-indicator {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding: 0 10px;
        }

        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            position: relative;
        }

        .progress-step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 15px;
            left: 50%;
            width: 100%;
            height: 2px;
            background: var(--border);
            z-index: 1;
        }

        .progress-step.active:not(:last-child)::after {
            background: var(--primary);
        }

        .progress-step.complete:not(:last-child)::after {
            background: var(--secondary);
        }

        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--border);
            color: var(--text-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-bottom: 8px;
            z-index: 2;
            position: relative;
        }

        .progress-step.active .step-number {
            background: var(--primary);
            color: white;
        }

        .progress-step.complete .step-number {
            background: var(--secondary);
            color: white;
        }

        .step-label {
            font-size: 12px;
            color: var(--text-light);
            text-align: center;
        }

        .progress-step.active .step-label {
            color: var(--primary);
            font-weight: 500;
        }

        .progress-step.complete .step-label {
            color: var(--secondary);
            font-weight: 500;
        }

        /* Form controls */
        .form-control, .form-select {
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            padding: 0.75rem 1rem;
            transition: var(--transition);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }
/* Enhanced Table Header - Same as Staff Management */
.table thead th {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark)) !important;
    padding: 16px 20px !important;
    text-align: left !important;
    font-weight: 700 !important;
    font-size: 13px !important;
    color: white !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    border-bottom: 2px solid var(--primary-dark) !important;
    white-space: nowrap !important;
    position: relative !important;
}

.table thead th::after {
    content: '' !important;
    position: absolute !important;
    top: 0 !important;
    left: 0 !important;
    width: 4px !important;
    height: 100% !important;
    background: linear-gradient(135deg, rgba(255,255,255,0.3), rgba(255,255,255,0.1)) !important;
}

/* Table Body */
.table tbody td {
    padding: 16px 20px !important;
    border-bottom: 1px solid var(--border) !important;
    color: var(--text) !important;
    font-size: 14px !important;
    vertical-align: middle !important;
}

.table tbody tr:hover {
    background: var(--card-alt) !important;
    transition: var(--transition) !important;
}

/* Table Container */
.students-table .table-responsive {
    background: var(--card) !important;
    border-radius: var(--radius) !important;
    border: 1px solid var(--border) !important;
    overflow: hidden !important;
    box-shadow: var(--shadow) !important;
}

.students-table .table {
    --bs-table-bg: transparent !important;
    --bs-table-striped-bg: rgba(74, 111, 165, 0.05) !important;
    --bs-table-hover-bg: rgba(74, 111, 165, 0.1) !important;
    color: var(--text) !important;
    margin-bottom: 0 !important;
}

/* Remove Bootstrap's default table styling */
.table.table-hover > tbody > tr:hover {
    --bs-table-accent-bg: var(--card-alt) !important;
}

/* Ensure proper spacing */
.students-table {
    background: var(--card) !important;
    border-radius: var(--radius) !important;
    padding: 1.5rem !important;
    box-shadow: var(--shadow) !important;
    border: 1px solid var(--border) !important;
}

        /* Button styles */
        .btn {
            border-radius: var(--radius-sm);
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            transition: var(--transition);
            border: 1px solid transparent;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(74, 111, 165, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #219653);
            border: none;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.3);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            border: none;
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(107, 140, 192, 0.3);
        }

        .btn-secondary {
            background: var(--card-alt);
            border-color: var(--border);
            color: var(--text);
        }

        .btn-secondary:hover {
            background: var(--border);
            border-color: var(--text-lighter);
            transform: translateY(-2px);
        }
        /* Table Container Styles */
.table-container {
    background: var(--card);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    overflow: hidden;
    box-shadow: var(--shadow);
}

.table-scroll {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

/* Section Title */
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

/* Progress Bar */
.progress-bar {
    height: 6px;
    background: var(--border);
    border-radius: 3px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    transition: width 0.3s ease;
}

/* Action Icons */
.action-icons {
    display: flex;
    gap: 8px;
    align-items: center;
    justify-content: flex-start;
}

.icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    padding: 0;
    border-radius: 8px;
    background: var(--card);
    border: 1px solid var(--border);
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    cursor: pointer;
    font-size: 14px;
    line-height: 1;
    transition: var(--transition);
    color: var(--text);
}

.icon-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.08);
}

.icon-btn.edit {
    border-color: rgba(37,99,235,0.08);
}

.icon-btn.edit:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.06), rgba(37,99,235,0.02));
    color: var(--primary-dark);
}
        .btn-outline-secondary {
            border-color: var(--border);
            color: var(--text-light);
        }

        .btn-outline-secondary:hover {
            background: var(--card-alt);
            border-color: var(--text-lighter);
            color: var(--text);
        }

        /* Alert styles */
        .alert {
            border-radius: var(--radius-sm);
            border: 1px solid transparent;
            padding: 1rem 1.25rem;
        }

        .alert-info {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border-color: #90caf9;
            color: var(--text);
        }

        .alert-danger {
            background: linear-gradient(135deg, #ffebee, #ffcdd2);
            border-color: #ef9a9a;
            color: var(--text);
        }

        /* Simple solution - just add padding to main container */
.main {
    margin-left: 0;
    min-height: 100vh;
    background: var(--bg);
    display: flex;
    flex-direction: column;
    width: 100%;
    padding: 20px;
}

.top-row {
    margin: -20px -20px 20px -20px; /* Pulls the top row to full width */
    padding: 20px 24px;
}

@media (min-width: 1024px) {
    .main {
        margin-left: 288px;
        width: calc(100% - 288px);
        padding: 30px;
    }
    
    .top-row {
        margin: -30px -30px 30px -30px;
    }
}

/* Add this to your existing CSS styles */
.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
    animation: fadeIn 0.3s ease-in-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Ensure content containers have proper spacing */
.stats-grid,
.students-table,
.d-flex.gap-2.mb-3 {
    max-width: 100%;
}

        /* Badge styles */
        .badge {
            border-radius: var(--radius-sm);
            padding: 0.35em 0.65em;
            font-weight: 500;
        }

        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--bg);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--border);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--text-lighter);
        }

         /* Top Row - Enhanced */
    .top-row {
      display: flex;
      align-items: center;
      gap: 16px;
      padding: 20px 24px;
      background: var(--card);
      border-bottom: 1px solid var(--border);
      box-shadow: var(--shadow);
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

    /* Add this to your existing sidebar styles */
.sidebar::-webkit-scrollbar {
    display: none;
}

.sidebar {
    -ms-overflow-style: none;  /* IE and Edge */
    scrollbar-width: none;      /* Firefox */
}
    </style>
</head>
<body>
    <!-- Sidebar Overlay -->
     <div class="sidebar-overlay" id="sidebarOverlay" hidden></div>

    <div id="app">
        <aside class="sidebar" id="sidebar" aria-hidden="true" aria-label="Main navigation">
            <button class="sidebar-close" id="sidebarClose" aria-label="Close menu" type="button">
                <i class="fas fa-times"></i>
            </button>
            <div class="sidebar-inner">
                <!-- Profile -->
               <!-- Profile -->
<div class="profile" onclick="window.location.href='dashboard.php'">
    <div class="profile-avatar">
        <?php
        // Default logo path
        $logo_path = isset($_SESSION['mahal_logo']) ? $_SESSION['mahal_logo'] : 'logo.jpeg';
        ?>
        <img src="<?php echo htmlspecialchars($logo_path); ?>" alt="Mahal Logo" 
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
        <i class="fas fa-mosque" style="display: none;"></i>
    </div>
    <div class="name"><?php echo isset($_SESSION['mahal_name']) ? htmlspecialchars($_SESSION['mahal_name']) : 'Mahal Management'; ?></div>
    <div class="role"><?php echo isset($_SESSION['role']) ? htmlspecialchars($_SESSION['role']) : 'Administrator'; ?></div>
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

                    <button class="menu-btn" type="button" onclick="window.location.href='asset_management.php'">
                        <i class="fas fa-boxes"></i>
                        <span>Asset Management</span>
                    </button>

                    <button class="menu-btn active" type="button" onclick="window.location.href='academics.php'">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Academics</span>
                    </button>

                    <button class="menu-btn" type="button" onclick="window.location.href='certificate.php'">
                        <i class="fas fa-certificate"></i>
                        <span>Certificate Management</member type>
                    </button>

                    <button class="menu-btn" type="button" onclick="window.location.href='mahal_profile.php'">
                        <i class="fas fa-building"></i>
                        <span>Mahal Profile</span>
                    </button>
                </nav>

                <!-- Logout -->
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
   <!-- Top Bar -->
 <section class="top-row">
    <button class="floating-menu-btn" id="menuToggle" aria-controls="sidebar" aria-expanded="false" aria-label="Open menu" type="button">
        <i class="fas fa-bars"></i>
    </button>
    <div class="page-title">
    <i class="fas fa-chalkboard-teacher"></i>
Class Management
</div>
    <a href="academics.php" class="btn btn-secondary" style="margin-left: auto;">
        <i class="fas fa-arrow-left"></i> Back to Classes
    </a>
</section>


           <!-- Stats Cards -->
<div class="stats-grid" style="margin-top: 30px;">
    <div class="stat-card students">
        <div class="stat-value"><?= $total_students ?></div>
        <div class="stat-label">TOTAL STUDENTS</div>
    </div>
    <div class="stat-card present">
        <div class="stat-value"><?= $present_today ?></div>
        <div class="stat-label">PRESENT TODAY</div>
    </div>
    <div class="stat-card absent">
        <div class="stat-value"><?= $absent_today ?></div>
        <div class="stat-label">ABSENT TODAY</div>
    </div>
</div>
<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle"></i> 
        <span><?= $_SESSION['success_message'] ?></span>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle"></i> 
        <div><?= $_SESSION['error_message'] ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>
                <!-- Action Buttons -->
                <div class="d-flex gap-2 mb-3">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStudentModal">
                        <i class="fas fa-user-plus"></i> Add Student
                    </button>
                    
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#markAttendanceModal" <?= empty($students) ? 'disabled' : '' ?>>
                        <i class="fas fa-clipboard-check"></i> Mark Attendance
                    </button>
                    <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#addExamResultModal" <?= empty($students) ? 'disabled' : '' ?>>
                        <i class="fas fa-file-alt"></i> Add Exam Result
                    </button>
                </div>

                <!-- Students Table -->
               <!-- Students Table -->
<div class="students-table">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="section-title">
            <i class="fas fa-users"></i>
            Students List
        </h3>
        <?php if (!empty($students)): ?>
            <div class="filter-section" style="max-width: 400px; margin-bottom: 0;">
                <input type="text" id="searchStudent" class="form-control search-box" placeholder="Search students..." style="background: linear-gradient(135deg, var(--primary) 0%, var(--primary) 4px, var(--card) 4px); background-size: 4px 100%; background-repeat: no-repeat; background-position: left center; border: 1px solid var(--border); padding: 12px 16px 12px 20px;">
            </div>
        <?php endif; ?>
    </div>

    <?php if (empty($students)): ?>
        <div class="text-center py-5">
            <i class="fas fa-user-graduate fa-4x text-muted mb-3"></i>
            <p class="text-muted">No students in this class yet. Add your first student!</p>
        </div>
    <?php else: ?>
        <div class="table-container" role="region" aria-label="Students table">
            <div class="table-scroll">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Admission No.</th>
                            <th scope="col">Full Name</th>
                            <th scope="col">Father's Name</th>
                            <th scope="col">Phone</th>
                            <th scope="col">Attendance %</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="studentsTableBody">
                        <?php foreach ($students as $student): ?>
                            <tr data-student-id="<?= $student['id'] ?>">
                                <td><span class="badge bg-primary"><?= htmlspecialchars($student['admission_number'] ?: '-') ?></span></td>
                                <td>
                                    <div style="font-weight: 600;"><?= htmlspecialchars($student['student_name']) ?></div>
                                    <?php if (!empty($student['gender'])): ?>
                                        <div style="font-size: 12px; color: var(--text-light);"><?= htmlspecialchars($student['gender']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($student['father_name'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($student['parent_phone'] ?: '-') ?></td>
                                <td>
                                    <span class="<?= $student['attendance_percentage'] < 75 ? 'attendance-low' : 'attendance-good' ?>">
                                        <?= number_format($student['attendance_percentage'], 1) ?>%
                                    </span>
                                    <?php if ($student['attendance_percentage'] > 0): ?>
                                        <div class="progress-bar" style="margin-top: 4px;">
                                            <div class="progress-fill" style="width: <?= min(100, $student['attendance_percentage']) ?>%; background: <?= $student['attendance_percentage'] < 75 ? 'var(--error)' : 'var(--success)' ?>;"></div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
    <div class="action-icons" role="group" aria-label="Actions for <?= htmlspecialchars($student['student_name']) ?>">
        <button class="icon-btn edit btn-view" onclick="viewStudent(<?= $student['id'] ?>)" title="View <?= htmlspecialchars($student['student_name']) ?>" type="button">
            <i class="fas fa-eye"></i>
        </button>
        <button class="icon-btn delete" onclick="confirmDelete(<?= $student['id'] ?>, '<?= htmlspecialchars(addslashes($student['student_name'])) ?>')" title="Delete <?= htmlspecialchars($student['student_name']) ?>" type="button">
            <i class="fas fa-trash-alt"></i>
        </button>
    </div>
</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteStudentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Are you sure you want to delete <strong id="deleteStudentName"></strong>?</p>
                <p class="text-muted mt-2 mb-0"><small>This action will remove the student from this class and all associated records.</small></p>
            </div>
            <div class="modal-footer">
                <form method="post" id="deleteStudentForm">
                    <input type="hidden" name="action" value="delete_student">
                    <input type="hidden" name="student_id" id="deleteStudentId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash-alt"></i> Delete Student
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
    <!-- Add Student Modal -->
     <div class="modal-body">
    <!-- Real-time Duplicate Alert -->
    <div class="duplicate-alert" id="duplicateAlert">
        <div style="display: flex; align-items: flex-start;">
            <i class="fas fa-exclamation-triangle alert-icon"></i>
            <div class="alert-content">
                <div class="alert-title">
                    <i class="fas fa-ban"></i> Student Already Exists!
                </div>
                <div class="alert-details" id="duplicateDetails"></div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="addStudentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <form method="post" class="modal-content needs-validation" novalidate id="addStudentForm">
                <input type="hidden" name="action" value="add_student">
                <input type="hidden" name="member_id" id="selected_member_id">
                <input type="hidden" name="sahakari_member_id" id="selected_sahakari_member_id">
<input type="hidden" id="selected_member_type" value="">

<small class="text-muted">
    <i class="fas fa-info-circle"></i> 
    Search from both Regular Members and Sahakari Members
</small>
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus"></i> Add New Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Progress Indicator -->
                    <div class="progress-indicator mb-4">
                        <div class="progress-step active" data-step="1">
                            <div class="step-number">1</div>
                            <div class="step-label">Basic Info</div>
                        </div>
                        <div class="progress-step" data-step="2">
                            <div class="step-number">2</div>
                            <div class="step-label">Personal Details</div>
                        </div>
                        <div class="progress-step" data-step="3">
                            <div class="step-number">3</div>
                            <div class="step-label">Contact Info</div>
                        </div>
                    </div>

                    <!-- Tab 1: Basic Information -->
                    <div class="tab-content active" id="tab1">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Admission Number:</strong> Will be automatically generated based on year of joining
                        </div>

                        <!-- Year of Joining - Date Picker -->
                        <div class="mb-3">
                            <label class="form-label">Year of Joining <span class="text-danger">*</span></label>
                            <input type="date" name="year_of_joining" id="year_of_joining" class="form-control" 
                                   value="<?= date('Y-m-d') ?>" required onchange="updateYearFromDate()" 
                                   min="2000-01-01" max="<?= date('Y-m-d') ?>">
                            <input type="hidden" name="year_of_joining" id="year_value" value="<?= date('Y') ?>">
                            <div class="invalid-feedback">Please select a valid joining date (between 2000 and today).</div>
                            <small class="text-muted">Select joining date - year will be used for admission number</small>
                        </div>

                        <!-- Search Member Autocomplete -->
                        <div class="mb-3">
                            <label class="form-label">Search Member/Family (Optional)</label>
                            <div class="autocomplete-container">
                                <input type="text" id="memberAutocomplete" class="form-control" 
                                       placeholder="Type name to search..." autocomplete="off">
                                <div class="autocomplete-results" id="autocompleteResults"></div>
                            </div>
                            <small class="text-muted">Start typing to see suggestions. You can also add student manually below.</small>
                        </div>

                        <!-- Member Details Card (Hidden by default) -->
                        <div class="member-details-card" id="memberDetailsCard">
                            <h6><i class="fas fa-users"></i> Select Family Member</h6>
                            <ul class="member-list" id="memberList"></ul>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="clearMemberSelection()">
                                <i class="fas fa-times"></i> Clear Selection
                            </button>
                        </div>

                        <!-- Student Details Form -->
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="student_name" id="student_name" class="form-control" 
                                       pattern="^[A-Za-z\s\.\-']+$" 
                                       title="Only letters, spaces, dots, hyphens, and apostrophes allowed" 
                                       minlength="2" maxlength="100" required>
                                <div class="invalid-feedback">
                                    Please enter a valid name (only letters and spaces, 2-100 characters).
                                </div>
                            </div>
                        </div>

                        <div class="text-end">
                            <button type="button" class="btn btn-primary" onclick="nextTab(2)">Next <i class="fas fa-arrow-right"></i></button>
                        </div>
                    </div>

                    <!-- Tab 2: Personal Details -->
                    <div class="tab-content" id="tab2">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" name="date_of_birth" id="date_of_birth" class="form-control" 
                                       max="<?= date('Y-m-d') ?>">
                                <div class="invalid-feedback">Date of birth cannot be in the future.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Gender <span class="text-danger">*</span></label>
                                <select name="gender" id="gender" class="form-select" required>
                                    <option value="">Select</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                                <div class="invalid-feedback">Please select a gender.</div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Father's Name <span class="text-danger">*</span></label>
                                <input type="text" name="father_name" id="father_name" class="form-control" 
                                       pattern="^[A-Za-z\s\.\-']*$" 
                                       title="Only letters, spaces, dots, hyphens, and apostrophes allowed" 
                                       maxlength="100" required>
                                <div class="invalid-feedback">
                                    Please enter a valid father's name (only letters and spaces).
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-secondary" onclick="prevTab(1)"><i class="fas fa-arrow-left"></i> Previous</button>
                            <button type="button" class="btn btn-primary" onclick="nextTab(3)">Next <i class="fas fa-arrow-right"></i></button>
                        </div>
                    </div>

                    <!-- Tab 3: Contact Information -->
                    <div class="tab-content" id="tab3">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Parent Phone <span class="text-danger">*</span></label>
                                <input type="tel" name="parent_phone" id="parent_phone" class="form-control" 
                                       pattern="^(\+91[\s-]?)?[6-9]\d{9}$" 
                                       title="Indian mobile: 10 digits starting 6-9 (with optional +91)" 
                                       maxlength="13" required>
                                <div class="invalid-feedback">
                                    Please enter a valid Indian mobile number (10 digits starting 6-9).
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Parent Email</label>
                                <input type="email" name="parent_email" id="parent_email" class="form-control" 
                                       pattern="^[^\s@]+@[^\s@]+\.[^\s@]+$" 
                                       title="Please enter a valid email address" 
                                       maxlength="100">
                                <div class="invalid-feedback">
                                    Please enter a valid email address (must contain @ and domain).
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Address <span class="text-danger">*</span></label>
                            <textarea name="address" id="address" class="form-control" rows="2" 
                                      maxlength="500" required></textarea>
                            <div class="invalid-feedback">
                                Address is required (max 500 characters).
                            </div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-secondary" onclick="prevTab(2)"><i class="fas fa-arrow-left"></i> Previous</button>
                            <button type="submit" class="btn btn-success" id="submitStudentBtn" disabled>Add Student</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Mark Attendance Modal -->
    <div class="modal fade" id="markAttendanceModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <form method="post" class="modal-content needs-validation" novalidate id="attendanceForm">
                <input type="hidden" name="action" value="mark_attendance">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-clipboard-check"></i> Mark Attendance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" name="attendance_date" class="form-control" 
                               value="<?= date('Y-m-d') ?>" required
                               min="2000-01-01" max="<?= date('Y-m-d') ?>">
                        <div class="invalid-feedback">Please select a valid date (between 2000 and today).</div>
                    </div>

                    <div class="mb-3">
                        <button type="button" class="btn btn-sm btn-outline-success" onclick="markAllPresent()">
                            <i class="fas fa-check-double"></i> Mark All Present
                        </button>
                    </div>

                    <?php if (!empty($students)): ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Admission No.</th>
                                        <th>Student Name</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students as $student): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($student['admission_number'] ?: '-') ?></td>
                                            <td><?= htmlspecialchars($student['student_name']) ?></td>
                                            <td>
                                                <select name="attendance[<?= $student['id'] ?>]" class="form-select form-select-sm attendance-select" required>
                                                    <option value="">Select Status</option>
                                                    <option value="present">Present</option>
                                                    <option value="absent">Absent</option>
                                                    <option value="late">Late</option>
                                                    <option value="excused">Excused</option>
                                                </select>
                                                <div class="invalid-feedback">Please select attendance status.</div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" id="submitAttendanceBtn" disabled>Save Attendance</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Exam Result Modal -->
    <div class="modal fade" id="addExamResultModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="post" class="modal-content needs-validation" novalidate id="examResultForm">
                <input type="hidden" name="action" value="add_exam_result">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-alt"></i> Add Exam Result</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Student <span class="text-danger">*</span></label>
                        <select name="student_id" class="form-select" required>
                            <option value="">Select Student</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?= $student['id'] ?>">
                                    <?= htmlspecialchars($student['student_name']) ?> 
                                    (<?= htmlspecialchars($student['admission_number'] ?: 'No Admission') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">Please select a student.</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Exam Name <span class="text-danger">*</span></label>
                            <input type="text" name="exam_name" class="form-control" 
                                   placeholder="e.g., Mid Term" 
                                   pattern="^[A-Za-z0-9\s\-]+$" 
                                   title="Only letters, numbers, spaces, and hyphens allowed" 
                                   minlength="2" maxlength="100" required>
                            <div class="invalid-feedback">
                                Please enter a valid exam name (2-100 characters, only letters, numbers, spaces, and hyphens).
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Exam Date</label>
                            <input type="date" name="exam_date" class="form-control" 
                                   max="<?= date('Y-m-d') ?>">
                            <div class="invalid-feedback">Exam date cannot be in the future.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Subject <span class="text-danger">*</span></label>
                        <input type="text" name="subject" class="form-control" 
                               placeholder="e.g., Mathematics, Physics 101, Chemistry-I" 
                               minlength="2" maxlength="100" required>
                        <div class="invalid-feedback">
                            Please enter a valid subject name (2-100 characters).
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Marks Obtained <span class="text-danger">*</span></label>
                            <input type="number" name="marks_obtained" class="form-control" 
                                   step="0.01" min="0" required
                                   oninput="validateMarks(this, 'marks')">
                            <div class="invalid-feedback">
                                Please enter valid marks (≥ 0, can be decimal).
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Total Marks <span class="text-danger">*</span></label>
                            <input type="number" name="total_marks" class="form-control" 
                                   step="0.01" min="0.01" required
                                   oninput="validateMarks(this, 'total')">
                            <div class="invalid-feedback">
                                Please enter valid total marks (≥ 0.01, can be decimal).
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea name="remarks" class="form-control" rows="2" maxlength="500"></textarea>
                        <div class="invalid-feedback">Remarks too long (max 500 characters).</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info" id="submitExamBtn" disabled>Add Result</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>// Real-time duplicate check when member is selected
function selectMember(memberData) {
    selectedMemberData = memberData;
    autocompleteInput.value = memberData.name;
    autocompleteResults.classList.remove('show');

    // Set member ID
    const memberId = memberData.type === 'member' ? memberData.id : memberData.member_id;
    document.getElementById('selected_member_id').value = memberId;

    // Check for duplicate in real-time IMMEDIATELY
    checkDuplicateStudent(memberId);

    // If it's a head member, show their family
    if (memberData.type === 'member') {
        showFamilyMembers(memberData);
    } else {
        // If it's a family member, fill details directly
        fillMemberDetails(memberData);
    }
}

// AJAX check for duplicate student - BLOCKS FORM IF FOUND
function checkDuplicateStudent(memberId) {
    const duplicateAlert = document.getElementById('duplicateAlert');
    const duplicateDetails = document.getElementById('duplicateDetails');
    const submitBtn = document.getElementById('submitStudentBtn');
    const form = document.getElementById('addStudentForm');
    
    // Hide alert initially
    duplicateAlert.classList.remove('show');
    
    if (!memberId) {
        return;
    }

    // Make AJAX request
    fetch('class_detail.php?class_id=<?= $class_id ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'check_duplicate=1&member_id=' + memberId
    })
    .then(response => response.json())
    .then(data => {
        if (data.duplicate) {
            // Show duplicate alert with formatted details
            duplicateDetails.innerHTML = `
                This member is already registered as a student:<br><br>
                <strong>👤 Name:</strong> ${data.student_name}<br>
                <strong>🏫 Class:</strong> ${data.class}<br>
                <strong>🎫 Admission Number:</strong> ${data.admission_number}
            `;
            duplicateAlert.classList.add('show');
            
            // DISABLE submit button - CANNOT PROCEED
            submitBtn.disabled = true;
            submitBtn.title = "Cannot add - student already exists";
            
            // Add duplicate class to member details card
            const memberCard = document.getElementById('memberDetailsCard');
            if (memberCard) {
                memberCard.classList.add('duplicate');
            }
            
            // Scroll to alert so user sees it
            setTimeout(() => {
                duplicateAlert.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 100);
            
            // Disable all form inputs except clear button
            form.querySelectorAll('input, select, textarea').forEach(input => {
                if (input.name !== 'action' && input.id !== 'memberAutocomplete') {
                    input.disabled = true;
                }
            });
            
        } else {
            // No duplicate - enable everything
            duplicateAlert.classList.remove('show');
            
            const memberCard = document.getElementById('memberDetailsCard');
            if (memberCard) {
                memberCard.classList.remove('duplicate');
            }
            
            // Enable all form inputs
            form.querySelectorAll('input, select, textarea').forEach(input => {
                input.disabled = false;
            });
            
            // Re-check if form is complete before enabling submit
            checkAllTabsComplete();
        }
    })
    .catch(error => {
        console.error('Error checking duplicate:', error);
        // On error, show error but allow user to proceed
        duplicateAlert.classList.remove('show');
    });
}

// Update fillMemberDetails to check duplicates and LOCK form if found
function fillMemberDetails(memberData) {
    // Highlight selected item
    document.querySelectorAll('.member-list li').forEach(li => {
        li.classList.remove('selected');
    });
    event.target.closest('li')?.classList.add('selected');

    // Set member ID
    const memberId = memberData.type === 'member' ? memberData.id : memberData.member_id;
    document.getElementById('selected_member_id').value = memberId;
    
    // Check for duplicate IMMEDIATELY - this will lock form if duplicate
    checkDuplicateStudent(memberId);

    // Fill form fields
    const studentNameInput = document.getElementById('student_name');
    const fatherNameInput = document.getElementById('father_name');
    const dobInput = document.getElementById('date_of_birth');
    const genderSelect = document.getElementById('gender');
    const addressInput = document.getElementById('address');
    const phoneInput = document.getElementById('parent_phone');
    const emailInput = document.getElementById('parent_email');

    studentNameInput.value = memberData.name || '';
    fatherNameInput.value = memberData.father_name || '';
    dobInput.value = memberData.dob || '';
    genderSelect.value = memberData.gender || '';
    addressInput.value = memberData.address || '';

    // Phone and email
    if (memberData.type === 'family') {
        phoneInput.value = memberData.phone || memberData.parent_phone || '';
        emailInput.value = memberData.email || memberData.parent_email || '';
    } else {
        phoneInput.value = memberData.phone || '';
        emailInput.value = memberData.email || '';
    }

    // Trigger validation after filling
    validateField(studentNameInput);
    validateField(fatherNameInput);
    validateField(dobInput);
    validateField(genderSelect);
    validateField(addressInput);
    validateField(phoneInput);
    validateField(emailInput);
}

// Update clearMemberSelection to remove all locks
function clearMemberSelection() {
    selectedMemberData = null;
    autocompleteInput.value = '';
    document.getElementById('memberDetailsCard').classList.remove('show', 'duplicate');
    document.getElementById('selected_member_id').value = '';
    
    // Hide duplicate alert
    document.getElementById('duplicateAlert').classList.remove('show');
    
    // Re-enable all form inputs
    const form = document.getElementById('addStudentForm');
    form.querySelectorAll('input, select, textarea').forEach(input => {
        input.disabled = false;
    });
    
    // Clear form fields
    document.getElementById('student_name').value = '';
    document.getElementById('father_name').value = '';
    document.getElementById('parent_phone').value = '';
    document.getElementById('parent_email').value = '';
    document.getElementById('address').value = '';
    document.getElementById('date_of_birth').value = '';
    document.getElementById('gender').value = '';
    
    // Re-check completion
    checkAllTabsComplete();
}

// Update modal close handler to reset everything
document.getElementById('addStudentModal').addEventListener('hidden.bs.modal', function() {
    const form = document.getElementById('addStudentForm');
    
    form.reset();
    clearMemberSelection();
    document.getElementById('duplicateAlert').classList.remove('show');
    document.getElementById('memberDetailsCard').classList.remove('duplicate');
    document.getElementById('year_value').value = new Date().getFullYear();
    
    // Re-enable all form inputs
    form.querySelectorAll('input, select, textarea').forEach(input => {
        input.disabled = false;
    });
    
    currentTab = 1;
    showTab(1);
    
    // Remove validation classes
    form.classList.remove('was-validated');
    form.querySelectorAll('.is-valid, .is-invalid').forEach(el => {
        el.classList.remove('is-valid', 'is-invalid');
    });
    
    // Disable submit button
    document.getElementById('submitStudentBtn').disabled = true;
});
        // Sidebar functionality
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const toggle = document.getElementById('menuToggle');
        const closeBtn = document.getElementById('sidebarClose');
// Confirm delete student
function confirmDelete(studentId, studentName) {
    document.getElementById('deleteStudentId').value = studentId;
    document.getElementById('deleteStudentName').textContent = studentName;
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteStudentModal'));
    deleteModal.show();
}
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

        // Navigation handlers
        document.querySelectorAll('.menu-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.menu-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
            });
        });

        // Set Academics as active
        document.querySelector('.menu-btn[onclick*="academics.php"]').classList.add('active');

        // Store all members data for autocomplete
        const allMembersData = <?= json_encode($all_members_data) ?>;
        let selectedMemberData = null;
        let currentTab = 1;
        const totalTabs = 3;

        // Update year value from date picker
        function updateYearFromDate() {
            const dateInput = document.getElementById('year_of_joining');
            const yearInput = document.getElementById('year_value');
            if (dateInput.value) {
                const year = new Date(dateInput.value).getFullYear();
                yearInput.value = year;
            }
        }

        // Tab navigation functions
        function showTab(tabNumber) {
            // Hide all tabs
            for (let i = 1; i <= totalTabs; i++) {
                document.getElementById(`tab${i}`).classList.remove('active');
                const step = document.querySelector(`.progress-step[data-step="${i}"]`);
                step.classList.remove('active');
            }
            
            // Show selected tab
            document.getElementById(`tab${tabNumber}`).classList.add('active');
            const currentStep = document.querySelector(`.progress-step[data-step="${tabNumber}"]`);
            currentStep.classList.add('active');
            
            // Mark previous steps as complete
            for (let i = 1; i < tabNumber; i++) {
                const step = document.querySelector(`.progress-step[data-step="${i}"]`);
                step.classList.add('complete');
            }
            
            currentTab = tabNumber;
            
            // Check if submit button should be enabled
            if (tabNumber === totalTabs) {
                checkAllTabsComplete();
            }
        }

        function nextTab(nextTabNumber) {
            if (validateCurrentTab()) {
                showTab(nextTabNumber);
            }
        }

        function prevTab(prevTabNumber) {
            showTab(prevTabNumber);
        }

        // Validate current tab before moving to next
        function validateCurrentTab() {
            const currentTabElement = document.getElementById(`tab${currentTab}`);
            const fields = currentTabElement.querySelectorAll('[required]');
            let isValid = true;

            fields.forEach(field => {
                if (!validateField(field)) {
                    isValid = false;
                    // Scroll to first invalid field
                    if (isValid === false) {
                        field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        field.focus();
                    }
                }
            });

            return isValid;
        }

        // Check if all tabs are complete to enable submit button
        function checkAllTabsComplete() {
            let allValid = true;
            
            for (let tabNum = 1; tabNum <= totalTabs; tabNum++) {
                const tabElement = document.getElementById(`tab${tabNum}`);
                const requiredFields = tabElement.querySelectorAll('[required]');
                
                requiredFields.forEach(field => {
                    if (!validateField(field, false)) {
                        allValid = false;
                    }
                });
            }
            
            const submitBtn = document.getElementById('submitStudentBtn');
            submitBtn.disabled = !allValid;
        }

        // Autocomplete functionality
        const autocompleteInput = document.getElementById('memberAutocomplete');
        const autocompleteResults = document.getElementById('autocompleteResults');

        autocompleteInput.addEventListener('input', function() {
            const searchTerm = this.value.trim().toLowerCase();
            
            if (searchTerm.length === 0) {
                autocompleteResults.classList.remove('show');
                return;
            }

            // Filter members that start with the search term
            const matches = allMembersData.filter(member => {
                return member.name.toLowerCase().startsWith(searchTerm);
            });

            if (matches.length === 0) {
                autocompleteResults.innerHTML = '<div class="autocomplete-item"><div class="details">No members found</div></div>';
                autocompleteResults.classList.add('show');
                return;
            }

          // Display results
let html = '';
matches.forEach(member => {

    // Existing Member / Family badge
    const badge = member.type === 'member'
        ? '<span class="badge bg-primary">Member</span>'
        : '<span class="badge bg-info">Family</span>';

    // NEW: Sahakari / Regular badge
    const memberTypeBadge = member.member_type === 'sahakari'
        ? '<span class="member-type-badge badge-sahakari">SAHAKARI</span>'
        : '<span class="member-type-badge badge-regular">REGULAR</span>';

    // Existing parent info
    const parentInfo = member.type === 'family'
        ? ` - Family of ${member.parent_name}`
        : '';

    html += `
        <div class="autocomplete-item" onclick='selectMember(${JSON.stringify(member)})'>
            <div class="name">
                ${member.name}
                ${badge}
                ${memberTypeBadge}
            </div>
            <div class="details">
                ${member.phone || 'No phone'}${parentInfo}
            </div>
        </div>
    `;
});
function selectMember(memberData) {

    // Clear both hidden fields
    document.getElementById('selected_member_id').value = '';
    document.getElementById('selected_sahakari_member_id').value = '';

    if (memberData.member_type === 'sahakari') {

        document.getElementById('selected_sahakari_member_id').value =
            memberData.type === 'member'
                ? memberData.id
                : memberData.member_id;

    } else {

        document.getElementById('selected_member_id').value =
            memberData.type === 'member'
                ? memberData.id
                : memberData.member_id;
    }

    document.getElementById('member_search').value = memberData.name;

    autocompleteResults.innerHTML = '';
    autocompleteResults.classList.remove('show');
}


            autocompleteResults.innerHTML = html;
            autocompleteResults.classList.add('show');
        });

        // Close autocomplete when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.autocomplete-container')) {
                autocompleteResults.classList.remove('show');
            }
        });

        // Select member from autocomplete
        function selectMember(memberData) {
            selectedMemberData = memberData;
            autocompleteInput.value = memberData.name;
            autocompleteResults.classList.remove('show');

            // If it's a head member, show their family
            if (memberData.type === 'member') {
                showFamilyMembers(memberData);
            } else {
                // If it's a family member, fill details directly
                fillMemberDetails(memberData);
            }
        }

        // Show family members for selection
        function showFamilyMembers(headMember) {
            const memberDetailsCard = document.getElementById('memberDetailsCard');
            const memberList = document.getElementById('memberList');

            // Find all family members of this head
            const familyMembers = allMembersData.filter(m => 
                m.type === 'family' && m.member_id == headMember.id
            );

            let html = '';
            
            // Add head member as first option
            html += `
                <li onclick='fillMemberDetails(${JSON.stringify(headMember)})'>
                    <div class="name">${headMember.name} <span class="relation">(Head)</span></div>
                </li>
            `;

            // Add family members
            familyMembers.forEach(fm => {
                html += `
                    <li onclick='fillMemberDetails(${JSON.stringify(fm)})'>
                        <div class="name">${fm.name} <span class="relation">(Family Member)</span></div>
                    </li>
                `;
            });

            if (html) {
                memberList.innerHTML = html;
                memberDetailsCard.classList.add('show');
            }
        }

        // Fill form with member details
        function fillMemberDetails(memberData) {
            // Highlight selected item
            document.querySelectorAll('.member-list li').forEach(li => {
                li.classList.remove('selected');
            });
            event.target.closest('li')?.classList.add('selected');

            // Set member ID
            document.getElementById('selected_member_id').value = 
                memberData.type === 'member' ? memberData.id : memberData.member_id;

            // Fill form fields
            const studentNameInput = document.getElementById('student_name');
            const fatherNameInput = document.getElementById('father_name');
            const dobInput = document.getElementById('date_of_birth');
            const genderSelect = document.getElementById('gender');
            const addressInput = document.getElementById('address');
            const phoneInput = document.getElementById('parent_phone');
            const emailInput = document.getElementById('parent_email');

            studentNameInput.value = memberData.name || '';
            fatherNameInput.value = memberData.father_name || '';
            dobInput.value = memberData.dob || '';
            genderSelect.value = memberData.gender || '';
            addressInput.value = memberData.address || '';

            // Phone and email
            if (memberData.type === 'family') {
                phoneInput.value = memberData.phone || memberData.parent_phone || '';
                emailInput.value = memberData.email || memberData.parent_email || '';
            } else {
                phoneInput.value = memberData.phone || '';
                emailInput.value = memberData.email || '';
            }

            // Trigger validation after filling
            validateField(studentNameInput);
            validateField(fatherNameInput);
            validateField(dobInput);
            validateField(genderSelect);
            validateField(addressInput);
            validateField(phoneInput);
            validateField(emailInput);
            
            // Check if all tabs are complete
            checkAllTabsComplete();
        }

        // Clear member selection
        function clearMemberSelection() {
            selectedMemberData = null;
            autocompleteInput.value = '';
            document.getElementById('memberDetailsCard').classList.remove('show');
            document.getElementById('selected_member_id').value = '';
            
            // Clear form fields
            document.getElementById('student_name').value = '';
            document.getElementById('father_name').value = '';
            document.getElementById('parent_phone').value = '';
            document.getElementById('parent_email').value = '';
            document.getElementById('address').value = '';
            document.getElementById('date_of_birth').value = '';
            document.getElementById('gender').value = '';
            
            // Re-check completion
            checkAllTabsComplete();
        }

        // Reset form when modal is closed
        document.getElementById('addStudentModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('addStudentForm').reset();
            clearMemberSelection();
            document.getElementById('year_value').value = new Date().getFullYear();
            currentTab = 1;
            showTab(1);
            
            // Remove validation classes
            const form = document.getElementById('addStudentForm');
            form.classList.remove('was-validated');
            form.querySelectorAll('.is-valid, .is-invalid').forEach(el => {
                el.classList.remove('is-valid', 'is-invalid');
            });
            
            // Disable submit button
            document.getElementById('submitStudentBtn').disabled = true;
        });

        // Search students in table
        const searchStudent = document.getElementById('searchStudent');
        if (searchStudent) {
            searchStudent.addEventListener('keyup', function(e) {
                const searchText = e.target.value.toLowerCase();
                const rows = document.querySelectorAll('#studentsTableBody tr');
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(searchText) ? '' : 'none';
                });
            });
        }

        // View student details
        function viewStudent(studentId) {
            window.location.href = 'student_detail.php?student_id=' + studentId;
        }

        // Mark all as present
        function markAllPresent() {
            document.querySelectorAll('.attendance-select').forEach(select => {
                select.value = 'present';
                validateField(select);
            });
            checkAttendanceFormComplete();
        }

        // Auto-dismiss alerts
        document.addEventListener('DOMContentLoaded', function() {
            // Remove success alerts automatically
            setTimeout(() => {
                document.querySelectorAll('.alert-success').forEach(alert => {
                    alert.remove();
                });
            }, 3000);

            // Setup all inline validations
            setupValidations();
        });

        // Setup all inline validations
        function setupValidations() {
            // Add Student Form validations
            const addStudentForm = document.getElementById('addStudentForm');
            if (addStudentForm) {
                // Validate all fields on blur and input
                const fields = addStudentForm.querySelectorAll('input, select, textarea');
                fields.forEach(field => {
                    // Real-time validation on input
                    if (field.type !== 'hidden') {
                        field.addEventListener('input', function() {
                            validateField(this);
                            if (currentTab === totalTabs) {
                                checkAllTabsComplete();
                            }
                        });
                        
                        field.addEventListener('blur', function() {
                            validateField(this);
                            if (currentTab === totalTabs) {
                                checkAllTabsComplete();
                            }
                        });
                    }
                });

                // Form submission validation
                addStudentForm.addEventListener('submit', function(event) {
                    if (!validateAllTabs()) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                    this.classList.add('was-validated');
                });
            }

            // Attendance Form validations
            const attendanceForm = document.getElementById('attendanceForm');
            if (attendanceForm) {
                // Real-time validation for all fields
                const fields = attendanceForm.querySelectorAll('input, select');
                fields.forEach(field => {
                    field.addEventListener('input', function() {
                        validateField(this);
                        checkAttendanceFormComplete();
                    });
                    field.addEventListener('change', function() {
                        validateField(this);
                        checkAttendanceFormComplete();
                    });
                });

                attendanceForm.addEventListener('submit', function(event) {
                    if (!validateAttendanceForm()) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                    this.classList.add('was-validated');
                });
                
                // Initial check
                checkAttendanceFormComplete();
            }

            // Exam Result Form validations
            const examResultForm = document.getElementById('examResultForm');
            if (examResultForm) {
                const marksFields = examResultForm.querySelectorAll('input[type="number"]');
                marksFields.forEach(field => {
                    field.addEventListener('input', function() {
                        validateField(this);
                        checkExamFormComplete();
                    });
                });
                
                // Real-time validation for all fields
                const fields = examResultForm.querySelectorAll('input, select, textarea');
                fields.forEach(field => {
                    field.addEventListener('input', function() {
                        validateField(this);
                        checkExamFormComplete();
                    });
                    field.addEventListener('change', function() {
                        validateField(this);
                        checkExamFormComplete();
                    });
                });

                examResultForm.addEventListener('submit', function(event) {
                    if (!validateExamResultForm()) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                    this.classList.add('was-validated');
                });
                
                // Initial check
                checkExamFormComplete();
            }
        }

        // Validate individual field
        function validateField(field, showFeedback = true) {
            // Skip hidden fields
            if (field.type === 'hidden') return true;

            // Get validation pattern and requirements
            const pattern = field.getAttribute('pattern');
            const minLength = field.getAttribute('minlength');
            const maxLength = field.getAttribute('maxlength');
            const required = field.hasAttribute('required');
            const value = field.value.trim();
            const type = field.type;
            const tagName = field.tagName.toLowerCase();

            // Clear previous validation classes if showing feedback
            if (showFeedback) {
                field.classList.remove('is-valid', 'is-invalid');
            }

            // Check if field is empty but not required
            if (!required && value === '') {
                if (showFeedback) field.classList.add('is-valid');
                return true;
            }

            // Check required fields
            if (required && value === '') {
                if (showFeedback) field.classList.add('is-invalid');
                return false;
            }

            // Check min length
            if (minLength && value.length < parseInt(minLength)) {
                if (showFeedback) field.classList.add('is-invalid');
                return false;
            }

            // Check max length
            if (maxLength && value.length > parseInt(maxLength)) {
                if (showFeedback) field.classList.add('is-invalid');
                return false;
            }

            // Check pattern for text inputs
            if (pattern && type !== 'date' && type !== 'number') {
                const regex = new RegExp(pattern);
                if (!regex.test(value)) {
                    if (showFeedback) field.classList.add('is-invalid');
                    return false;
                }
            }

            // Special validations by field type
            switch(field.name) {
                case 'date_of_birth':
                case 'exam_date':
                case 'attendance_date':
                    if (value && new Date(value) > new Date()) {
                        if (showFeedback) field.classList.add('is-invalid');
                        return false;
                    }
                    break;

                case 'parent_phone':
                    // Indian phone number validation
                    const phoneRegex = /^(\+91[\s-]?)?[6-9]\d{9}$/;
                    if (value && !phoneRegex.test(value.replace(/\s|-/g, ''))) {
                        if (showFeedback) field.classList.add('is-invalid');
                        return false;
                    }
                    break;

                case 'parent_email':
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (value && !emailRegex.test(value)) {
                        if (showFeedback) field.classList.add('is-invalid');
                        return false;
                    }
                    break;

                case 'year_of_joining':
                    if (value) {
                        const selectedDate = new Date(value);
                        const today = new Date();
                        const minDate = new Date('2000-01-01');
                        
                        if (selectedDate > today || selectedDate < minDate) {
                            if (showFeedback) field.classList.add('is-invalid');
                            return false;
                        }
                    }
                    break;
            }

            // If all validations passed
            if (showFeedback) field.classList.add('is-valid');
            return true;
        }

        // Validate all tabs in Add Student form
        function validateAllTabs() {
            let isValid = true;

            for (let tabNum = 1; tabNum <= totalTabs; tabNum++) {
                const tabElement = document.getElementById(`tab${tabNum}`);
                const requiredFields = tabElement.querySelectorAll('[required]');
                
                requiredFields.forEach(field => {
                    if (!validateField(field)) {
                        isValid = false;
                        // If field is in a hidden tab, show that tab
                        if (tabNum !== currentTab) {
                            showTab(tabNum);
                            field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            field.focus();
                        }
                    }
                });
            }

            return isValid;
        }

        // Check if attendance form is complete
        function checkAttendanceFormComplete() {
            const form = document.getElementById('attendanceForm');
            let allValid = true;

            // Check date field
            const dateField = form.querySelector('input[type="date"]');
            if (!validateField(dateField, false)) {
                allValid = false;
            }

            // Check all attendance selects
            const attendanceSelects = form.querySelectorAll('.attendance-select');
            attendanceSelects.forEach(select => {
                if (!validateField(select, false)) {
                    allValid = false;
                }
            });

            const submitBtn = document.getElementById('submitAttendanceBtn');
            submitBtn.disabled = !allValid;
        }

        // Check if exam form is complete
        function checkExamFormComplete() {
            const form = document.getElementById('examResultForm');
            let allValid = true;

            // Check all required fields
            const requiredFields = form.querySelectorAll('[required]');
            requiredFields.forEach(field => {
                if (!validateField(field, false)) {
                    allValid = false;
                }
            });

            // Special check for marks
            const marksObtained = form.querySelector('input[name="marks_obtained"]');
            const totalMarks = form.querySelector('input[name="total_marks"]');
            
            if (marksObtained.value && totalMarks.value) {
                const marks = parseFloat(marksObtained.value);
                const total = parseFloat(totalMarks.value);
                
                if (marks > total || total <= 0) {
                    allValid = false;
                }
            }

            const submitBtn = document.getElementById('submitExamBtn');
            submitBtn.disabled = !allValid;
        }

        // Validate Attendance Form
        function validateAttendanceForm() {
            const form = document.getElementById('attendanceForm');
            let isValid = true;

            // Validate date
            const dateField = form.querySelector('input[type="date"]');
            if (!validateField(dateField)) {
                isValid = false;
            }

            // Validate all attendance selects
            const attendanceSelects = form.querySelectorAll('.attendance-select');
            attendanceSelects.forEach(select => {
                if (!validateField(select)) {
                    isValid = false;
                }
            });

            return isValid;
        }

        // Validate Exam Result Form
        function validateExamResultForm() {
            const form = document.getElementById('examResultForm');
            let isValid = true;

            // Validate all required fields
            const requiredFields = form.querySelectorAll('[required]');
            requiredFields.forEach(field => {
                if (!validateField(field)) {
                    isValid = false;
                }
            });

            // Validate marks: marks obtained cannot be greater than total marks
            const marksObtained = form.querySelector('input[name="marks_obtained"]');
            const totalMarks = form.querySelector('input[name="total_marks"]');
            
            if (marksObtained.value && totalMarks.value) {
                const marks = parseFloat(marksObtained.value);
                const total = parseFloat(totalMarks.value);
                
                if (marks > total) {
                    marksObtained.classList.add('is-invalid');
                    isValid = false;
                }
                
                if (total <= 0) {
                    totalMarks.classList.add('is-invalid');
                    isValid = false;
                }
            }

            return isValid;
        }

        // Validate marks input
        function validateMarks(input, type) {
            const value = parseFloat(input.value);
            
            if (isNaN(value)) {
                input.classList.add('is-invalid');
                input.classList.remove('is-valid');
                return false;
            }
            
            if (type === 'total' && value <= 0) {
                input.classList.add('is-invalid');
                input.classList.remove('is-valid');
                return false;
            }
            
            if (value < 0) {
                input.classList.add('is-invalid');
                input.classList.remove('is-valid');
                return false;
            }
            
            // Check if marks obtained > total marks
            if (type === 'marks') {
                const totalMarks = document.querySelector('input[name="total_marks"]');
                if (totalMarks && totalMarks.value) {
                    const total = parseFloat(totalMarks.value);
                    if (value > total) {
                        input.classList.add('is-invalid');
                        input.classList.remove('is-valid');
                        return false;
                    }
                }
            }
            
            input.classList.remove('is-invalid');
            input.classList.add('is-valid');
            return true;
        }

        // Reset validation when modal closes
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('hidden.bs.modal', function() {
                const form = this.querySelector('form');
                if (form) {
                    form.classList.remove('was-validated');
                    form.querySelectorAll('.is-valid, .is-invalid').forEach(el => {
                        el.classList.remove('is-valid', 'is-invalid');
                    });
                    
                    // Reset submit buttons
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                    }
                }
            });
        });

        // Initialize first tab
        showTab(1);
    </script>
</body>
</html>
<?php
$conn->close();
?>