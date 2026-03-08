<?php
declare(strict_types=1);

/**
 * staff-management.php
 *
 * Single-file staff management page + API
 */

require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db_connection.php';

// Enhanced error reporting for debugging
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Set headers for CORS and content type
header('X-Frame-Options: SAMEORIGIN');
header('Access-Control-Allow-Origin: same-origin');

function send_json($arr)
{
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  exit();
}

function log_error($message)
{
  error_log("Staff Management Error: " . $message);
}

/* --- auth gate for API requests --- */
if (empty($_SESSION['user_id'])) {
  if (isset($_REQUEST['action'])) {
    send_json(['success' => false, 'message' => 'Not authenticated', 'session' => $_SESSION]);
  } else {
    header("Location: index.php");
    exit();
  }
}

/* --- connect to DB --- */
$db = get_db_connection();
if (isset($db['error'])) {
  log_error("DB connection failed: " . $db['error']);
  if (isset($_REQUEST['action'])) {
    send_json(['success' => false, 'message' => 'DB connection error: ' . $db['error']]);
  } else {
    die("Database connection failed: " . htmlspecialchars($db['error']));
  }
}
$conn = $db['conn'];

// Set connection collation to avoid conflicts
$conn->set_charset('utf8mb4');
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

/* --- Ensure tables exist --- */
$createStaff = <<<SQL
CREATE TABLE IF NOT EXISTS `staff` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `mahal_id` INT NOT NULL,
  `staff_id` VARCHAR(64) DEFAULT NULL,
  `name` VARCHAR(255) NOT NULL,
  `address` TEXT,
  `email` VARCHAR(255),
  `phone` VARCHAR(64),
  `date_of_birth` DATE DEFAULT NULL,
  `designation` VARCHAR(200),
  `fixed_salary` DECIMAL(12,2) DEFAULT 0,
  `salary_status` ENUM('active','inactive','pending') DEFAULT 'active',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  INDEX (mahal_id),
  INDEX (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

$createPayments = <<<SQL
CREATE TABLE IF NOT EXISTS `staff_payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `mahal_id` INT NOT NULL,
  `staff_id` INT NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `transaction_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `payment_mode` VARCHAR(64) DEFAULT 'cash',
  `description` VARCHAR(255) DEFAULT NULL,
  INDEX (staff_id),
  INDEX (mahal_id),
  INDEX (transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

$conn->query($createStaff);
$conn->query($createPayments);

/* --- API router --- */
$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : null;
$mahal_id = intval($_SESSION['user_id']);

if ($action !== null) {
  try {
    // List staff action
    if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
      $search = isset($_GET['search']) ? trim($_GET['search']) : '';
      $status = isset($_GET['status']) ? trim($_GET['status']) : '';

      $firstOfMonth = date('Y-m-01 00:00:00');
      $lastOfMonth = date('Y-m-t 23:59:59');

      $sql = "
              SELECT
                s.id,
                s.staff_id,
                s.name,
                s.phone,
                s.address,
                s.email,
                s.date_of_birth,
                s.designation,
                s.fixed_salary,
                s.salary_status,
                IFNULL(p.total_paid, 0) AS current_month_paid,
                p.last_paid_date
              FROM staff s
              LEFT JOIN (
                SELECT staff_id,
                       SUM(amount) AS total_paid,
                       MAX(transaction_date) AS last_paid_date
                FROM staff_payments
                WHERE mahal_id = ? AND transaction_date BETWEEN ? AND ?
                GROUP BY staff_id
              ) p ON p.staff_id = s.id
              WHERE s.mahal_id = ?
            ";

      $bind_vals = [$mahal_id, $firstOfMonth, $lastOfMonth, $mahal_id];
      $bind_types = 'issi';

      if ($search !== '') {
        $sql .= " AND (s.name LIKE ? OR s.phone LIKE ? OR s.staff_id LIKE ? OR s.email LIKE ?)";
        $like = '%' . $search . '%';
        $bind_types .= 'ssss';
        array_push($bind_vals, $like, $like, $like, $like);
      }
      if ($status !== '') {
        $sql .= " AND s.salary_status = ?";
        $bind_types .= 's';
        $bind_vals[] = $status;
      }

      $sql .= " ORDER BY s.name ASC";

      $stmt = $conn->prepare($sql);
      if ($stmt === false) {
        throw new Exception('Prepare failed: ' . $conn->error);
      }

      // Dynamic bind
      if (!empty($bind_vals)) {
        $refs = [];
        $refs[] = &$bind_types;
        foreach ($bind_vals as $k => $v)
          $refs[] = &$bind_vals[$k];
        call_user_func_array([$stmt, 'bind_param'], $refs);
      }

      if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
      }

      $res = $stmt->get_result();
      $staffs = [];
      $count = 0;

      while ($row = $res->fetch_assoc()) {
        $count++;
        $fixed = (float) $row['fixed_salary'];
        $paid = (float) $row['current_month_paid'];
        $balance = max(0.0, $fixed - $paid);

        $payment_status = 'pending';
        if ($fixed > 0 && $paid >= $fixed) {
          $payment_status = 'completed';
        } elseif ($paid > 0 && $paid < $fixed) {
          $payment_status = 'partial';
        } elseif ($fixed == 0 && $paid > 0) {
          $payment_status = 'completed';
        }

        $staffs[] = [
          'id' => (int) $row['id'],
          'staff_code' => $row['staff_id'],
          'name' => $row['name'],
          'phone' => $row['phone'],
          'address' => $row['address'],
          'email' => $row['email'],
          'date_of_birth' => $row['date_of_birth'],
          'designation' => $row['designation'],
          'fixed_salary' => $fixed,
          'salary_status' => $row['salary_status'],
          'current_month_paid' => $paid,
          'last_salary_paid_date' => $row['last_paid_date'] ?? null,
          'balance_due' => $balance,
          'salary_payment_status' => $payment_status
        ];
      }

      $stmt->close();

      send_json([
        'success' => true,
        'staff' => $staffs,
        'debug' => [
          'total_records' => $count,
          'mahal_id' => $mahal_id,
          'search' => $search,
          'status' => $status
        ]
      ]);
    }

    // Add new staff
    if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
      $name = isset($_POST['name']) ? trim($_POST['name']) : '';
      $address = isset($_POST['address']) ? trim($_POST['address']) : '';
      $email = isset($_POST['email']) ? trim($_POST['email']) : '';
      $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
      $dob = isset($_POST['date_of_birth']) ? trim($_POST['date_of_birth']) : null;
      $designation = isset($_POST['designation']) ? trim($_POST['designation']) : '';
      $fixed_salary = isset($_POST['fixed_salary']) ? (float) $_POST['fixed_salary'] : 0.0;
      $salary_status = isset($_POST['salary_status']) ? trim($_POST['salary_status']) : 'active';

      $errors = [];
      if ($name === '')
        $errors[] = 'Name is required';
      if ($address === '')
        $errors[] = 'Address is required';
      if ($phone === '')
        $errors[] = 'Phone is required';
      if (!in_array($salary_status, ['active', 'inactive', 'pending'], true))
        $salary_status = 'active';

      if (!empty($errors)) {
        send_json(['success' => false, 'message' => implode('; ', $errors)]);
      }

      $dob_param = ($dob !== '') ? $dob : null;

      $sql = "INSERT INTO staff
    (mahal_id, staff_id, name, address, email, phone, date_of_birth, designation, fixed_salary, salary_status, created_at)
    VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

      $stmt = $conn->prepare($sql);
      if ($stmt === false) {
        send_json(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
      }

      // Correct types string: i (mahal_id), s (name), s (address), s (email), s (phone),
// s (date_of_birth), s (designation), d (fixed_salary), s (salary_status)
      $types = 'issssssds';

      if (
        !$stmt->bind_param(
          $types,
          $mahal_id,
          $name,
          $address,
          $email,
          $phone,
          $dob_param,
          $designation,
          $fixed_salary,
          $salary_status
        )
      ) {
        $err = $stmt->error ?: $conn->error;
        $stmt->close();
        send_json(['success' => false, 'message' => 'Bind failed: ' . $err]);
      }

      if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        send_json(['success' => false, 'message' => 'Insert failed: ' . $err]);
      }

      $inserted_id = $stmt->insert_id;
      $stmt->close();

      // Generate staff code for display
      $staff_code = 'S' . $inserted_id;
      $u = $conn->prepare("UPDATE staff SET staff_id = ? WHERE id = ? LIMIT 1");
      if ($u) {
        $u->bind_param('si', $staff_code, $inserted_id);
        $u->execute();
        $u->close();
      }

      send_json(['success' => true, 'id' => $inserted_id, 'staff_code' => $staff_code]);
    }

    // Get single staff details
    if ($action === 'details' && $_SERVER['REQUEST_METHOD'] === 'GET') {
      $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
      if ($id <= 0)
        send_json(['success' => false, 'message' => 'Missing id']);

      $stmt = $conn->prepare("SELECT * FROM staff WHERE id = ? AND mahal_id = ? LIMIT 1");
      if ($stmt === false) {
        throw new Exception('Prepare failed: ' . $conn->error);
      }

      $stmt->bind_param('ii', $id, $mahal_id);
      if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
      }

      $res = $stmt->get_result();
      $row = $res->fetch_assoc();
      $stmt->close();

      if (!$row) {
        send_json(['success' => false, 'message' => 'Staff not found']);
      }

      send_json(['success' => true, 'staff' => $row]);
    }

    // Update staff
    if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
      $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
      if ($id <= 0)
        send_json(['success' => false, 'message' => 'Missing id']);

      $name = isset($_POST['name']) ? trim($_POST['name']) : '';
      $address = isset($_POST['address']) ? trim($_POST['address']) : '';
      $email = isset($_POST['email']) ? trim($_POST['email']) : '';
      $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
      $dob = isset($_POST['date_of_birth']) ? trim($_POST['date_of_birth']) : null;
      $designation = isset($_POST['designation']) ? trim($_POST['designation']) : '';
      $fixed_salary = isset($_POST['fixed_salary']) ? (float) $_POST['fixed_salary'] : 0.0;
      $salary_status = isset($_POST['salary_status']) ? trim($_POST['salary_status']) : 'active';

      $errors = [];
      if ($name === '')
        $errors[] = 'Name is required';
      if ($address === '')
        $errors[] = 'Address is required';
      if ($phone === '')
        $errors[] = 'Phone is required';
      if (!in_array($salary_status, ['active', 'inactive', 'pending'], true))
        $salary_status = 'active';
      if (!empty($errors))
        send_json(['success' => false, 'message' => implode('; ', $errors)]);

      $sql = "UPDATE staff SET name = ?, address = ?, email = ?, phone = ?, date_of_birth = ?, designation = ?, fixed_salary = ?, salary_status = ?, updated_at = NOW() WHERE id = ? AND mahal_id = ? LIMIT 1";
      $stmt = $conn->prepare($sql);
      if ($stmt === false)
        send_json(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);

      $dob_param = ($dob !== '') ? $dob : null;

      // Correct types string: six 's' for the string params, then 'd' for fixed_salary, then 's' then 'i' then 'i'
      $types = 'ssssssdsii';

      $bind_values = [$name, $address, $email, $phone, $dob_param, $designation, $fixed_salary, $salary_status, $id, $mahal_id];

      $refs = [];
      $refs[] = &$types;
      foreach ($bind_values as $k => $v)
        $refs[] = &$bind_values[$k];
      call_user_func_array([$stmt, 'bind_param'], $refs);

      if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        send_json(['success' => false, 'message' => 'Update failed: ' . $err]);
      }
      $affected = $stmt->affected_rows;
      $stmt->close();
      send_json(['success' => true, 'affected' => $affected]);
    }

    // Delete staff
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
      $body = file_get_contents('php://input');
      $json = json_decode($body, true);
      $id = isset($json['id']) ? intval($json['id']) : 0;
      if (!$id)
        send_json(['success' => false, 'message' => 'Missing id']);

      $stmt = $conn->prepare("DELETE FROM staff WHERE id = ? AND mahal_id = ? LIMIT 1");
      if ($stmt === false)
        send_json(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
      $stmt->bind_param('ii', $id, $mahal_id);
      if (!$stmt->execute())
        send_json(['success' => false, 'message' => 'Execute failed: ' . $stmt->error]);
      $affected = $stmt->affected_rows;
      $stmt->close();
      send_json(['success' => true, 'affected' => $affected]);
    }

    // Payment history for a staff (ALL TRANSACTIONS by default)
    if ($action === 'history' && $_SERVER['REQUEST_METHOD'] === 'GET') {
      $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
      $month = isset($_GET['month']) ? trim($_GET['month']) : '';
      if ($id === 0) {
        send_json(['success' => false, 'message' => 'Missing id']);
      }

      // If no month or "all" → show all transactions
      if ($month === '' || $month === 'all') {
        $stmt = $conn->prepare(
          "SELECT amount, transaction_date, payment_mode, description 
                     FROM staff_payments 
                     WHERE mahal_id = ? AND staff_id = ? 
                     ORDER BY transaction_date DESC"
        );
        if ($stmt === false) {
          send_json(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        }
        $stmt->bind_param('ii', $mahal_id, $id);
      } else {
        // Keep month-based query if you ever want to filter by month
        $first = $month . '-01 00:00:00';
        $last = date('Y-m-t', strtotime($month . '-01')) . ' 23:59:59';

        $stmt = $conn->prepare(
          "SELECT amount, transaction_date, payment_mode, description 
                     FROM staff_payments 
                     WHERE mahal_id = ? AND staff_id = ? 
                       AND transaction_date BETWEEN ? AND ? 
                     ORDER BY transaction_date DESC"
        );
        if ($stmt === false) {
          send_json(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        }
        $stmt->bind_param('iiss', $mahal_id, $id, $first, $last);
      }

      if (!$stmt->execute()) {
        send_json(['success' => false, 'message' => 'Execute failed: ' . $stmt->error]);
      }

      $res = $stmt->get_result();
      $payments = [];
      $total = 0.0;
      while ($r = $res->fetch_assoc()) {
        $payments[] = $r;
        $total += (float) $r['amount'];
      }
      $stmt->close();

      $stmt2 = $conn->prepare("SELECT fixed_salary FROM staff WHERE id = ? AND mahal_id = ? LIMIT 1");
      if ($stmt2 === false)
        send_json(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
      $stmt2->bind_param('ii', $id, $mahal_id);
      $stmt2->execute();
      $res2 = $stmt2->get_result();
      $row = $res2->fetch_assoc();
      $fixed_salary = $row ? (float) $row['fixed_salary'] : 0.0;
      $stmt2->close();

      $balance = max(0.0, $fixed_salary - $total);
      send_json([
        'success' => true,
        'fixed_salary' => $fixed_salary,
        'total_paid' => $total,
        'balance_due' => $balance,
        'payments' => $payments,
        'payment_status' => ($total >= $fixed_salary && $fixed_salary > 0)
          ? 'completed'
          : (($total > 0 && $total < $fixed_salary) ? 'partial' : 'pending')
      ]);
    }

  } catch (Exception $e) {
    log_error("API Error: " . $e->getMessage());
    send_json([
      'success' => false,
      'message' => 'Server error: ' . $e->getMessage(),
      'debug' => ['action' => $action, 'mahal_id' => $mahal_id]
    ]);
  }

  send_json(['success' => false, 'message' => 'Invalid action or method']);
}

/* --- HTML Page Rendering --- */
$user_id = $_SESSION['user_id'];
$sql = "SELECT name, address, registration_no, email FROM register WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
  $mahal = $result->fetch_assoc();
} else {
  $conn->close();
  echo "<script>alert('Unable to fetch mahal details. Please log in again.'); window.location.href='index.php';</script>";
  exit();
}
$stmt->close();

// Get total staff count for this mahal
$sql_count = "SELECT COUNT(*) as total FROM staff WHERE mahal_id = ?";
$stmt_count = $conn->prepare($sql_count);
$stmt_count->bind_param("i", $user_id);
$stmt_count->execute();
$result_count = $stmt_count->get_result();
$total_staff = $result_count->fetch_assoc()['total'];
$stmt_count->close();

$conn->close();

// Define logo path
$logo_path = "logo.jpeg";

function h($s)
{
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1" />
  <title>Staff Management - <?php echo h($mahal['name']); ?></title>
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

    /* ─────────────────────────────────────────────
       SIDEBAR • Enhanced Design
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

    /* Main layout */
    .main {
      margin-left: 0;
      min-height: 100vh;
      background: var(--bg);
      display: flex;
      flex-direction: column;
      width: 100%;
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

    /* Container */
    .container {
      padding: 24px;
      max-width: 1400px;
      margin: 0 auto;
      width: 100%;
    }

    /* Page Header - Enhanced */
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
      background: linear-gradient(135deg, var(--success), #059669);
      color: #fff;
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }

    .btn.green:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
    }

    .btn.blue {
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
      color: #fff;
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
    }

    .btn.blue:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
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

    /* Filter Section - Enhanced with Blue Accents */
    .filter-section {
      background: var(--card);
      padding: 20px;
      border-radius: var(--radius);
      border: 1px solid var(--border);
      margin-bottom: 24px;
      box-shadow: var(--shadow);
    }

    .filter-row {
      display: grid;
      grid-template-columns: 1fr auto auto auto auto;
      gap: 12px;
      align-items: center;
    }

    /* Search input with blue accent */
    .filter-row input[type="search"] {
      width: 100%;
      padding: 12px 16px 12px 20px;
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      font-size: 14px;
      transition: var(--transition);
      min-height: 48px;
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary) 4px, var(--card) 4px);
      background-size: 4px 100%;
      background-repeat: no-repeat;
      background-position: left center;
    }

    .filter-row input[type="search"]:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
      background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-light) 5px, var(--card) 5px);
      background-size: 5px 100%;
      background-repeat: no-repeat;
      background-position: left center;
    }

    .filter-row input[type="search"]:hover {
      background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-light) 5px, var(--card-alt) 5px);
      background-size: 5px 100%;
      background-repeat: no-repeat;
      background-position: left center;
      border-color: var(--primary-light);
    }

    /* Select dropdowns with blue accent */
    .filter-row select {
      padding: 12px 16px 12px 20px;
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      font-size: 14px;
      transition: var(--transition);
      min-height: 48px;
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary) 4px, var(--card) 4px);
      background-size: 4px 100%;
      background-repeat: no-repeat;
      background-position: left center;
      cursor: pointer;
    }

    .filter-row select:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
      background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-light) 5px, var(--card) 5px);
      background-size: 5px 100%;
      background-repeat: no-repeat;
      background-position: left center;
    }

    .filter-row select:hover {
      background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-light) 5px, var(--card-alt) 5px);
      background-size: 5px 100%;
      background-repeat: no-repeat;
      background-position: left center;
      border-color: var(--primary-light);
    }

    .visually-hidden {
      position: absolute !important;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }

    /* Clear Filters button with blue accent */
    .btn.white {
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary) 4px, var(--card) 4px);
      background-size: 4px 100%;
      background-repeat: no-repeat;
      background-position: left center;
      border: 1px solid var(--border);
      color: var(--text);
      box-shadow: var(--shadow);
      padding: 12px 20px 12px 24px;
    }

    .btn.white:hover {
      background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-light) 5px, var(--card-alt) 5px);
      background-size: 5px 100%;
      background-repeat: no-repeat;
      background-position: left center;
      transform: translateY(-1px);
      box-shadow: var(--shadow-lg);
    }

    /* Table Container - Enhanced */
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

    .table-scroll table {
      width: 100%;
      border-collapse: collapse;
      min-width: 1200px;
    }

    /* Table Headings in Blue Color */
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
      padding: 16px 20px;
      border-bottom: 1px solid var(--border);
      color: var(--text);
      font-size: 14px;
      vertical-align: top;
    }

    tr:last-child td {
      border-bottom: 0;
    }

    tr:hover {
      background: var(--card-alt);
    }

    /* Status Badges - Enhanced */
    .status-badge {
      display: inline-block;
      padding: 6px 12px;
      border-radius: 999px;
      color: #fff;
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .status-active {
      background: linear-gradient(135deg, var(--success), #059669);
    }

    .status-inactive {
      background: linear-gradient(135deg, var(--warning), #d97706);
    }

    .status-pending {
      background: linear-gradient(135deg, var(--error), #dc2626);
    }

    .status-completed {
      background: linear-gradient(135deg, var(--success), #059669);
    }

    .status-partial {
      background: linear-gradient(135deg, var(--warning), #d97706);
    }

    .null-text {
      color: var(--text-lighter);
      font-style: italic;
    }

    .no-data {
      text-align: center;
      padding: 60px 16px;
      color: var(--text-light);
      font-size: 16px;
    }

    .footer {
      margin-top: 24px;
      text-align: center;
      font-size: 14px;
      color: var(--text-light);
      padding: 16px;
    }

    /* Clickable Rows */
    tr[data-staff-id] {
      cursor: pointer;
      transition: var(--transition);
    }

    tr[data-staff-id]:hover {
      background: var(--card-alt) !important;
      transform: translateY(-1px);
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    /* Progress Bar */
    .progress-bar {
      height: 6px;
      background: var(--border);
      border-radius: 3px;
      overflow: hidden;
      margin-top: 4px;
    }

    .progress-fill {
      height: 100%;
      background: var(--success);
      transition: width 0.3s ease;
    }

    /* Loading Animation */
    .loading {
      display: inline-block;
      width: 20px;
      height: 20px;
      border: 3px solid rgba(255, 255, 255, 0.3);
      border-radius: 50%;
      border-top-color: #fff;
      animation: spin 1s ease-in-out infinite;
    }

    @keyframes spin {
      to {
        transform: rotate(360deg);
      }
    }

    /* Modal Styles */
    .modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      z-index: 10000;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    .modal-content {
      background: white;
      padding: 24px;
      border-radius: 12px;
      width: 90%;
      max-width: 600px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: var(--shadow-lg);
    }

    .modal-title {
      margin: 0 0 20px 0;
      color: var(--text);
      font-size: 20px;
      font-weight: 700;
    }

    .form-group {
      margin-bottom: 16px;
    }

    .form-label {
      display: block;
      margin-bottom: 6px;
      font-weight: 600;
      color: var(--text);
    }

    .form-input {
      width: 100%;
      padding: 10px;
      border: 1px solid var(--border);
      border-radius: 6px;
      font-size: 14px;
      transition: border-color 0.2s, box-shadow 0.2s;
    }

    .form-input:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
    }

    .form-textarea {
      width: 100%;
      padding: 10px;
      border: 1px solid var(--border);
      border-radius: 6px;
      resize: vertical;
      font-size: 14px;
      min-height: 80px;
      transition: border-color 0.2s, box-shadow 0.2s;
    }

    .form-textarea:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
    }

    .form-actions {
      display: flex;
      gap: 12px;
      margin-top: 24px;
      justify-content: flex-end;
    }

    .btn-cancel {
      background: white;
      border: 1px solid var(--border) !important;
      color: var(--text);
    }

    /* Validation Styles */
    .form-group {
      position: relative;
      margin-bottom: 20px;
    }

    .form-label {
      display: block;
      margin-bottom: 6px;
      font-weight: 600;
      color: var(--text);
    }

    .form-label.required::after {
      content: ' *';
      color: var(--error);
    }

    .form-input,
    .form-textarea,
    .form-select {
      width: 100%;
      padding: 12px 16px;
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      font-size: 14px;
      transition: var(--transition);
      font-family: 'Inter', sans-serif;
    }

    .form-input:focus,
    .form-textarea:focus,
    .form-select:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
    }

    .form-input.invalid,
    .form-textarea.invalid,
    .form-select.invalid {
      border-color: var(--error);
      box-shadow: 0 0 0 3px rgba(231, 76, 60, 0.1);
    }

    .form-input.invalid:focus,
    .form-textarea.invalid:focus,
    .form-select.invalid:focus {
      border-color: var(--error);
      box-shadow: 0 0 0 3px rgba(231, 76, 60, 0.2);
    }

    .validation-error {
      color: var(--error);
      font-size: 12px;
      margin-top: 4px;
      display: none;
      align-items: center;
      gap: 4px;
      font-weight: 500;
    }

    .validation-error.show {
      display: flex;
    }

    .validation-error i {
      font-size: 10px;
    }

    .form-hint {
      color: var(--text-light);
      font-size: 12px;
      margin-top: 4px;
      display: none;
    }

    .form-hint.show {
      display: block;
    }

    /* Character count */
    .char-count {
      font-size: 11px;
      color: var(--text-light);
      text-align: right;
      margin-top: 2px;
      display: none;
    }

    .char-count.show {
      display: block;
    }

    .char-count.warning {
      color: var(--warning);
    }

    .char-count.error {
      color: var(--error);
    }

    /* Password strength meter */
    .strength-meter {
      height: 4px;
      background: var(--border);
      border-radius: 2px;
      margin-top: 8px;
      overflow: hidden;
      display: none;
    }

    .strength-meter.show {
      display: block;
    }

    .strength-meter-fill {
      height: 100%;
      width: 0%;
      transition: width 0.3s ease;
      border-radius: 2px;
    }

    .strength-weak {
      background: var(--error);
    }

    .strength-fair {
      background: var(--warning);
    }

    .strength-good {
      background: #f1c40f;
    }

    .strength-strong {
      background: var(--success);
    }

    .btn-submit {
      background: var(--primary);
      color: white;
    }

    /* Toast Notification */
    .toast {
      position: fixed;
      top: 20px;
      right: 20px;
      padding: 12px 20px;
      border-radius: 8px;
      color: white;
      font-weight: 600;
      box-shadow: var(--shadow-lg);
      z-index: 10001;
      transform: translateX(100%);
      transition: transform 0.3s ease;
    }

    .toast.show {
      transform: translateX(0);
    }

    .toast-success {
      background: var(--success);
    }

    .toast-error {
      background: var(--error);
    }

    /* --- Action icons (compact, horizontal) --- */
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
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
      cursor: pointer;
      font-size: 14px;
      line-height: 1;
      transition: var(--transition);
      color: var(--text);
    }

    .icon-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
    }

    .icon-btn.delete {
      border-color: rgba(231, 76, 60, 0.12);
    }

    .icon-btn.delete:hover {
      background: linear-gradient(135deg, rgba(231, 76, 60, 0.06), rgba(231, 76, 60, 0.02));
      color: var(--error);
    }

    .icon-btn.edit {
      border-color: rgba(37, 99, 235, 0.08);
    }

    .icon-btn.edit:hover {
      background: linear-gradient(135deg, rgba(37, 99, 235, 0.06), rgba(37, 99, 235, 0.02));
      color: var(--primary-dark);
    }

    .icon-btn.history {
      border-color: rgba(102, 126, 234, 0.06);
    }

    .icon-btn .fa {
      font-size: 14px;
    }

    /* Ensure table's large .btn are not visible inside cells (keeps layout clean) */
    .table-container td .btn {
      display: none;
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
      .filter-row {
        grid-template-columns: 1fr auto auto auto;
        grid-auto-rows: auto;
      }

      .actions {
        width: 100%;
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

      .table-scroll table {
        min-width: 1000px;
      }

      .top-row {
        padding: 16px 20px;
      }

      .page-title {
        font-size: 20px;
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
        <!-- Profile with Logo - Clickable to Dashboard -->
        <div class="profile" onclick="window.location.href='dashboard.php'">
          <div class="profile-avatar">
            <img src="<?php echo h($logo_path); ?>" alt="<?php echo h($mahal['name']); ?> Logo"
              onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
            <i class="fas fa-mosque" style="display: none;"></i>
          </div>
          <div class="name"><?php echo h($mahal['name']); ?></div>
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

          <button class="menu-btn active" type="button">
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
          <i class="fas fa-user-tie"></i>
          Staff Management
        </div>
      </section>

      <div class="container">
        <div class="page-header">
          <div class="actions">
            <button class="btn green" onclick="openAddStaffModal()">
              <i class="fas fa-plus"></i>
              Add Staff
            </button>
            <!-- Export button removed -->
          </div>
          <div class="stats">
            <i class="fas fa-users"></i>
            Total: <span id="count"><?php echo (int) $total_staff; ?></span>
          </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
          <div class="filter-row">
            <input type="search" id="search" placeholder="Search by name, email, phone, staff code..." autocomplete="on"
              inputmode="search">

            <select id="status" aria-label="Salary status filter">
              <option value="all">All Salary Status</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="pending">Pending</option>
            </select>

            <select id="paymentStatus" aria-label="Payment status filter">
              <option value="all">All Payment Status</option>
              <option value="completed">Completed</option>
              <option value="partial">Partial</option>
              <option value="pending">Pending</option>
            </select>

            <button class="btn white" id="clear" type="button">
              <i class="fas fa-times"></i>
              Clear Filters
            </button>
          </div>
        </div>

        <!-- Table -->
        <div class="table-container" role="region" aria-label="Staff table">
          <div class="table-scroll">
            <table id="table">
              <thead>
                <tr>
                  <th scope="col">Staff ID</th>
                  <th scope="col">Name</th>
                  <th scope="col">Email</th>
                  <th scope="col">Phone</th>
                  <th scope="col">Designation</th>
                  <th scope="col">Fixed Salary</th>
                  <th scope="col">Salary Status</th>
                  <th scope="col">Payment Status</th>
                  <th scope="col">Current Month</th>
                  <th scope="col">Balance Due</th>
                  <th scope="col">Actions</th>
                </tr>
              </thead>
              <tbody id="staffTableBody">
                <tr>
                  <td colspan="11" class="no-data">Loading…</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="footer">Last updated: <span id="lastUpdatedTime"><?php echo date('M d, Y h:i A'); ?></span></div>
      </div>
    </main>
  </div>

  <!-- Add Staff Modal -->
  <!-- Add Staff Modal -->
  <div id="addStaffModal" class="modal">
    <div class="modal-content">
      <h3 class="modal-title">Add New Staff</h3>
      <form id="addStaffForm" novalidate>
        <div class="form-group">
          <label class="form-label required">Staff Name</label>
          <input type="text" name="name" class="form-input" placeholder="Enter full name" minlength="2" maxlength="100"
            pattern="[A-Za-z\s\.\-]{2,100}" title="Only letters, spaces, dots and hyphens allowed (2-100 characters)">
          <div class="validation-error" id="name-error">
            <i class="fas fa-exclamation-circle"></i>
            <span>Name must be 2-100 characters with only letters, spaces, dots and hyphens</span>
          </div>
          <div class="char-count" id="name-counter">0/100</div>
        </div>

        <div class="form-group">
          <label class="form-label required">Address</label>
          <textarea name="address" class="form-textarea" placeholder="Enter complete address" minlength="10"
            maxlength="500" rows="3"></textarea>
          <div class="validation-error" id="address-error">
            <i class="fas fa-exclamation-circle"></i>
            <span>Address must be 10-500 characters</span>
          </div>
          <div class="char-count" id="address-counter">0/500</div>
        </div>

        <div class="form-group">
          <label class="form-label">Email Address</label>
          <input type="email" name="email" class="form-input" placeholder="staff@example.com"
            pattern="[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$" title="Please enter a valid email address">
          <div class="validation-error" id="email-error">
            <i class="fas fa-exclamation-circle"></i>
            <span>Please enter a valid email address</span>
          </div>
          <div class="form-hint" id="email-hint">Optional field</div>
        </div>

        <div class="form-group">
          <label class="form-label required">Phone Number</label>
          <input type="tel" name="phone" class="form-input" placeholder="9876543210" pattern="[6-9]\d{9}"
            title="Enter a valid 10-digit Indian mobile number starting with 6, 7, 8, or 9" minlength="10"
            maxlength="10" inputmode="numeric">
          <div class="validation-error" id="phone-error">
            <i class="fas fa-exclamation-circle"></i>
            <span>Phone must be 10 digits starting with 6, 7, 8, or 9</span>
          </div>
          <div class="form-hint" id="phone-hint">10-digit Indian mobile number</div>
        </div>

        <div class="form-group">
          <label class="form-label">Date of Birth</label>
          <input type="date" name="date_of_birth" class="form-input" max="<?php echo date('Y-m-d'); ?>">
          <div class="validation-error" id="dob-error">
            <i class="fas fa-exclamation-circle"></i>
            <span>Date cannot be in the future</span>
          </div>
          <div class="form-hint" id="dob-hint">Optional field</div>
        </div>

        <div class="form-group">
          <label class="form-label">Designation</label>
          <input type="text" name="designation" class="form-input" placeholder="e.g., Manager, Accountant, Clerk"
            maxlength="100">
          <div class="validation-error" id="designation-error">
            <i class="fas fa-exclamation-circle"></i>
            <span>Designation cannot exceed 100 characters</span>
          </div>
          <div class="char-count" id="designation-counter">0/100</div>
        </div>

        <div class="form-group">
          <label class="form-label required">Fixed Monthly Salary (₹)</label>
          <input type="number" name="fixed_salary" class="form-input" placeholder="0.00" min="0" max="9999999.99"
            step="0.01" inputmode="decimal">
          <div class="validation-error" id="salary-error">
            <i class="fas fa-exclamation-circle"></i>
            <span>Salary must be between ₹0 and ₹99,99,999.99</span>
          </div>
          <div class="form-hint">Use decimal values like 15000.50</div>
        </div>

        <div class="form-group">
          <label class="form-label required">Salary Status</label>
          <select name="salary_status" class="form-select" required>
            <option value="">Select status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="pending">Pending</option>
          </select>
          <div class="validation-error" id="status-error">
            <i class="fas fa-exclamation-circle"></i>
            <span>Please select a salary status</span>
          </div>
        </div>

        <div class="form-actions">
          <button type="button" class="btn btn-cancel" onclick="closeAddStaffModal()">Cancel</button>
          <button type="submit" class="btn btn-submit">Add Staff</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Edit Staff Modal -->
  <!-- Edit Staff Modal -->
  <div id="editStaffModal" class="modal">
    <div class="modal-content">
      <h3 class="modal-title">Edit Staff</h3>
      <form id="editStaffForm" novalidate>
        <input type="hidden" name="id" id="edit_id">

        <div class="form-group">
          <label class="form-label">Staff Code</label>
          <input type="text" id="edit_code_display" class="form-input" readonly style="background: var(--card-alt);">
        </div>

        <div class="form-group">
          <label class="form-label required">Staff Name</label>
          <input type="text" name="name" id="edit_name" class="form-input" placeholder="Enter full name" minlength="2"
            maxlength="100" pattern="[A-Za-z\s\.\-]{2,100}"
            title="Only letters, spaces, dots and hyphens allowed (2-100 characters)">
          <div class="validation-error" id="edit-name-error">
            <i class="fas fa-exclamation-circle"></i>
            <span>Name must be 2-100 characters with only letters, spaces, dots and hyphens</span>
          </div>
          <div class="char-count" id="edit-name-counter">0/100</div>
        </div>

        <div class="form-group">
          <label class="form-label required">Address</label>
          <textarea name="address" id="edit_address" class="form-textarea" placeholder="Enter complete address"
            minlength="10" maxlength="500" rows="3"></textarea>
          <div class="validation-error" id="edit-address-error">
            <i class="fas fa-exclamation-circle"></i>
            <span>Address must be 10-500 characters</span>
          </div>
          <div class="char-count" id="edit-address-counter">0/500</div>
        </div>

        <div class="form-group">
          <label class="form-label">Email Address</label>
          <input type="email" name="email" id="edit_email" class="form-input" placeholder="staff@example.com"
            pattern="[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$" title="Please enter a valid email address">
          <div class="validation-error" id="edit-email-error">
            <i class="fas fa-exclamation-circle"></i>
            <span>Please enter a valid email address</span>
          </div>
          <div class="form-hint">Optional field</div>
        </div>

        <div class="form-group">
          <label class="form-label required">Phone Number</label>
          <input type="tel" name="phone" id="edit_phone" class="form-input" placeholder="9876543210"
            pattern="[6-9]\d{9}" title="Enter a valid 10-digit Indian mobile number starting with 6, 7, 8, or 9"
            minlength="10" maxlength="10" inputmode="numeric">
          <div class="validation-error" id="edit-phone-error">
            <i class="fas fa-exclamation-circle"></i>
            <span>Phone must be 10 digits starting with 6, 7, 8, or 9</span>
          </div>
          <div class="form-hint">10-digit Indian mobile number</div>
        </div>

        <div class="form-group">
          <label class="form-label">Date of Birth</label>
          <input type="date" name="date_of_birth" id="edit_date_of_birth" class="form-input"
            max="<?php echo date('Y-m-d'); ?>">
          <div class="validation-error" id="edit-dob-error">
            <i class="fas fa-exclamation-circle"></i>
            <span>Date cannot be in the future</span>
          </div>
          <div class="form-hint">Optional field</div>
        </div>

        <div class="form-group">
          <label class="form-label">Designation</label>
          <input type="text" name="designation" id="edit_designation" class="form-input"
            placeholder="e.g., Manager, Accountant, Clerk" maxlength="100">
          <div class="validation-error" id="edit-designation-error">
            <i class="fas fa-exclamation-circle"></i>
            <span>Designation cannot exceed 100 characters</span>
          </div>
          <div class="char-count" id="edit-designation-counter">0/100</div>
        </div>

        <div class="form-group">
          <label class="form-label required">Fixed Monthly Salary (₹)</label>
          <input type="number" name="fixed_salary" id="edit_fixed_salary" class="form-input" placeholder="0.00" min="0"
            max="9999999.99" step="0.01" inputmode="decimal">
          <div class="validation-error" id="edit-salary-error">
            <i class="fas fa-exclamation-circle"></i>
            <span>Salary must be between ₹0 and ₹99,99,999.99</span>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label required">Salary Status</label>
          <select name="salary_status" id="edit_salary_status" class="form-select" required>
            <option value="">Select status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="pending">Pending</option>
          </select>
          <div class="validation-error" id="edit-status-error">
            <i class="fas fa-exclamation-circle"></i>
            <span>Please select a salary status</span>
          </div>
        </div>

        <div class="form-actions">
          <button type="button" class="btn btn-cancel" onclick="closeEditStaffModal()">Cancel</button>
          <button type="submit" class="btn btn-submit">Update Staff</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Payment History Modal -->
  <div id="paymentHistoryModal" class="modal">
    <div class="modal-content">
      <h3 class="modal-title" id="paymentHistoryTitle">Salary Payment History</h3>
      <div id="paymentHistoryContent">
        <!-- Payment history will be loaded here -->
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-cancel" onclick="closePaymentHistoryModal()">Close</button>
      </div>
    </div>
  </div>

  <!-- Toast Notification -->
  <div id="toast" class="toast"></div>

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

    // Enhanced fetchJSON with better error handling
    async function fetchJSON(url, opts = {}) {
      try {
        const resp = await fetch(url, {
          ...opts,
          credentials: 'same-origin'
        });

        const text = await resp.text();

        if (!resp.ok) {
          throw new Error(`HTTP ${resp.status}: ${resp.statusText}`);
        }

        try {
          const data = JSON.parse(text);
          return data;
        } catch (err) {
          console.error('JSON parse error:', err);
          console.error('Response text:', text);
          throw new Error('Invalid JSON response from server');
        }
      } catch (error) {
        console.error('Fetch error:', error);
        throw error;
      }
    }

    // Short helper safe-escape
    function escapeHtml(unsafe) {
      if (unsafe === undefined || unsafe === null) return '';
      return String(unsafe)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    }

    // Helper that returns either escaped value or literal fallback HTML (unescaped)
    function safeOrDashHtml(value) {
      return value ? escapeHtml(value) : '<span class="null-text">—</span>';
    }

    // Toast notification function
    function showToast(message, type = 'success') {
      const toast = document.getElementById('toast');
      toast.textContent = message;
      toast.className = `toast toast-${type}`;
      toast.classList.add('show');

      setTimeout(() => {
        toast.classList.remove('show');
      }, 3000);
    }

    // Update last updated time
    function updateLastUpdatedTime() {
      const now = new Date();
      document.getElementById('lastUpdatedTime').textContent =
        now.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' +
        now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    }

    // Modal helpers
    function openAddStaffModal() {
      document.getElementById('addStaffModal').style.display = 'flex';
    }
    function closeAddStaffModal() {
      document.getElementById('addStaffModal').style.display = 'none';
      document.getElementById('addStaffForm').reset();
      resetFormValidation('addStaffForm', false);
    }
    function openEditStaffModal() {
      document.getElementById('editStaffModal').style.display = 'flex';
    }
    function closeEditStaffModal() {
      document.getElementById('editStaffModal').style.display = 'none';
      document.getElementById('editStaffForm').reset();
      resetFormValidation('editStaffForm', true);
    }
    function openPaymentHistoryModal() {
      document.getElementById('paymentHistoryModal').style.display = 'flex';
    }
    function closePaymentHistoryModal() {
      document.getElementById('paymentHistoryModal').style.display = 'none';
    }

    function clearFilters() {
      document.getElementById('search').value = '';
      document.getElementById('status').value = 'all';
      document.getElementById('paymentStatus').value = 'all';
      loadStaffData();
    }

    // Enhanced loadStaffData with comprehensive error handling and safe display for empty fields
    async function loadStaffData() {
      const search = document.getElementById('search').value;
      const status = document.getElementById('status').value;
      const paymentStatus = document.getElementById('paymentStatus').value;

      const params = new URLSearchParams();
      params.append('action', 'list');
      if (search) params.append('search', search);
      if (status && status !== 'all') params.append('status', status);

      // Show loading state
      const tableBody = document.getElementById('staffTableBody');
      tableBody.innerHTML = '<tr><td colspan="11" class="no-data"><div class="loading"></div> Loading staff data...</td></tr>';

      try {
        const data = await fetchJSON('staff-management.php?' + params.toString());

        const staffTableBody = document.getElementById('staffTableBody');
        const countElement = document.getElementById('count');

        if (data.success && data.staff && data.staff.length > 0) {
          let filteredStaff = data.staff;

          // Apply payment status filter
          if (paymentStatus !== 'all') {
            filteredStaff = filteredStaff.filter(staff =>
              staff.salary_payment_status === paymentStatus
            );
          }

          if (filteredStaff.length > 0) {
            staffTableBody.innerHTML = '';

            filteredStaff.forEach(staff => {
              const currentMonthPaid = parseFloat(staff.current_month_paid || 0);
              const fixedSalary = parseFloat(staff.fixed_salary || 0);
              const balanceDue = Math.max(0, fixedSalary - currentMonthPaid);

              let paymentStatusClass = 'status-pending';
              let paymentStatusText = 'Pending';
              if (staff.salary_payment_status === 'completed') {
                paymentStatusClass = 'status-completed';
                paymentStatusText = 'Completed';
              } else if (staff.salary_payment_status === 'partial') {
                paymentStatusClass = 'status-partial';
                paymentStatusText = 'Partial';
              }

              let salaryStatusClass = 'status-active';
              if (staff.salary_status === 'inactive') salaryStatusClass = 'status-inactive';
              else if (staff.salary_status === 'pending') salaryStatusClass = 'status-pending';

              const progressPercent = fixedSalary > 0 ? Math.min(100, (currentMonthPaid / fixedSalary) * 100) : 0;

              // Create row safely and use data-* attributes for names to avoid inline-onclick injection issues
              const row = document.createElement('tr');
              row.setAttribute('data-staff-id', staff.id);
              row.dataset.staffName = staff.name || '';

              const staffCodeHtml = staff.staff_code ? escapeHtml(staff.staff_code) : escapeHtml('S' + staff.id);
              const emailHtml = safeOrDashHtml(staff.email);
              const phoneHtml = safeOrDashHtml(staff.phone);
              const designationHtml = safeOrDashHtml(staff.designation);

              row.innerHTML = `
                <td>${staffCodeHtml}</td>
                <td>
                  <div style="font-weight: 600;">${escapeHtml(staff.name)}</div>
                </td>
                <td>${emailHtml}</td>
                <td>${phoneHtml}</td>
                <td>${designationHtml}</td>
                <td>₹${fixedSalary.toFixed(2)}</td>
                <td><span class="status-badge ${salaryStatusClass}">${escapeHtml(staff.salary_status)}</span></td>
                <td>
                  <span class="status-badge ${paymentStatusClass}">${paymentStatusText}</span>
                  ${fixedSalary > 0 ? `
                    <div class="progress-bar">
                      <div class="progress-fill" style="width: ${progressPercent}%"></div>
                    </div>
                  ` : ''}
                </td>
                <td>
                  <div style="font-size: 12px;">
                    <div>Paid: ₹${currentMonthPaid.toFixed(2)}</div>
                    <div style="color: var(--text-light);">${getCurrentMonthName()}</div>
                  </div>
                </td>
                <td>
                  <span style="color: ${balanceDue > 0 ? 'var(--error)' : 'var(--success)'}; font-weight: bold;">
                    ₹${balanceDue.toFixed(2)}
                  </span>
                </td>
                <td>
                  <div class="action-icons" role="group" aria-label="Actions for ${escapeHtml(staff.name)}">
                    <button class="icon-btn edit btn-edit" data-id="${staff.id}" title="Edit ${escapeHtml(staff.name)}" type="button">
                      <i class="fas fa-pen"></i>
                    </button>
                    <button class="icon-btn history btn-history" data-id="${staff.id}" title="Payment history for ${escapeHtml(staff.name)}" type="button">
                      <i class="fas fa-clock-rotate-left"></i>
                    </button>
                    <button class="icon-btn delete btn-delete" data-id="${staff.id}" title="Delete ${escapeHtml(staff.name)}" type="button">
                      <i class="fas fa-trash-alt"></i>
                    </button>
                  </div>
                </td>
              `;

              staffTableBody.appendChild(row);

              // Attach button handlers (safer than inline handlers)
              const editBtn = row.querySelector('.btn-edit');
              const historyBtn = row.querySelector('.btn-history');
              const deleteBtn = row.querySelector('.btn-delete');

              editBtn.addEventListener('click', (ev) => {
                ev.stopPropagation();
                editStaff(parseInt(editBtn.dataset.id, 10));
              });

              historyBtn.addEventListener('click', (ev) => {
                ev.stopPropagation();
                // use dataset staffName from the row to avoid embedding names into JS strings
                const name = row.dataset.staffName || '';
                showPaymentHistory(parseInt(historyBtn.dataset.id, 10), name);
              });

              deleteBtn.addEventListener('click', (ev) => {
                ev.stopPropagation();
                deleteStaff(parseInt(deleteBtn.dataset.id, 10));
              });

              // Optional: clicking the row opens payment history
              row.addEventListener('click', () => {
                const id = parseInt(row.getAttribute('data-staff-id'), 10);
                const name = row.dataset.staffName || '';
                showPaymentHistory(id, name);
              });

            });

            countElement.textContent = filteredStaff.length;
            updateLastUpdatedTime();

          } else {
            staffTableBody.innerHTML = '<tr><td colspan="11" class="no-data">No staff records match your filters.</td></tr>';
            countElement.textContent = '0';
          }
        } else {
          staffTableBody.innerHTML = '<tr><td colspan="11" class="no-data">No staff records found.</td></tr>';
          countElement.textContent = '0';
        }
      } catch (error) {
        console.error('Error loading staff data:', error);
        tableBody.innerHTML = '<tr><td colspan="11" class="no-data" style="color: var(--error);">Error loading staff data. Check console for details.</td></tr>';
        document.getElementById('count').textContent = '0';
        showToast('Error loading staff data: ' + error.message, 'error');
      }
    }

    function showPaymentHistory(id, staffName) {
      // No month param → backend returns ALL transactions
      const params = new URLSearchParams({ action: 'history', id: id });

      // Show loading
      document.getElementById('paymentHistoryContent').innerHTML = '<div style="text-align: center; padding: 20px;"><div class="loading"></div> Loading payment history...</div>';
      openPaymentHistoryModal();

      fetchJSON('staff-management.php?' + params.toString())
        .then(data => {
          if (data.success) {
            document.getElementById('paymentHistoryTitle').textContent = `Salary Payment History - ${staffName || ''}`;
            let paymentHistoryHtml = `
              <div style="background: var(--card-alt); border: 1px solid var(--border); border-radius: 8px; padding: 16px; margin-bottom: 16px; font-size: 14px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
                <div style="display: flex; flex-direction: column;">
                  <div style="font-size: 12px; color: var(--text-light); margin-bottom: 4px;">Fixed Monthly Salary</div>
                  <div style="font-weight: 600; color: var(--text);">₹${parseFloat(data.fixed_salary || 0).toFixed(2)}</div>
                </div>
                <div style="display: flex; flex-direction: column;">
                  <div style="font-size: 12px; color: var(--text-light); margin-bottom: 4px;">Total Paid (All Time)</div>
                  <div style="font-weight: 600; color: var(--text);">₹${parseFloat(data.total_paid || 0).toFixed(2)}</div>
                </div>
                <div style="display: flex; flex-direction: column;">
                  <div style="font-size: 12px; color: var(--text-light); margin-bottom: 4px;">Balance Due</div>
                  <div style="font-weight: 600; color: var(--text);">₹${parseFloat(data.balance_due || 0).toFixed(2)}</div>
                </div>
                <div style="display: flex; flex-direction: column;">
                  <div style="font-size: 12px; color: var(--text-light); margin-bottom: 4px;">Payment Status</div>
                  <div><span class="status-badge status-${data.payment_status}">${data.payment_status}</span></div>
                </div>
              </div>
              <div class="form-group">
                <label class="form-label">Payment History (All Transactions):</label>
                <div style="max-height: 300px; overflow-y: auto; border: 1px solid var(--border); border-radius: 6px; padding: 10px;">
            `;
            if (data.payments && data.payments.length > 0) {
              data.payments.forEach(payment => {
                paymentHistoryHtml += `
                  <div style="display: flex; justify-content: space-between; padding: 12px; border-bottom: 1px solid var(--border); align-items: center;">
                    <div>
                      <div style="font-weight: 600; color: var(--text);">${new Date(payment.transaction_date).toLocaleDateString()}</div>
                      <div style="font-size: 12px; color: var(--text-light);">${escapeHtml(payment.payment_mode)} • ${escapeHtml(payment.description || 'No description')}</div>
                    </div>
                    <div style="font-weight: bold; color: var(--error);">₹${parseFloat(payment.amount).toFixed(2)}</div>
                  </div>
                `;
              });
            } else {
              paymentHistoryHtml += '<div style="text-align: center; color: var(--text-light); padding: 20px;">No payments recorded</div>';
            }
            paymentHistoryHtml += '</div></div>';
            document.getElementById('paymentHistoryContent').innerHTML = paymentHistoryHtml;
          } else {
            document.getElementById('paymentHistoryContent').innerHTML = `<div style="color: var(--error); text-align: center; padding: 20px;">Error: ${escapeHtml(data.message || 'Unknown error')}</div>`;
          }
        })
        .catch(err => {
          console.error(err);
          document.getElementById('paymentHistoryContent').innerHTML = `<div style="color: var(--error); text-align: center; padding: 20px;">Error loading payment history: ${escapeHtml(err.message)}</div>`;
        });
    }

    function getCurrentMonthName() {
      return new Date().toLocaleString('default', { month: 'long', year: 'numeric' });
    }

    function editStaff(id) {
      const params = new URLSearchParams({ action: 'details', id: id });

      fetchJSON('staff-management.php?' + params.toString())
        .then(data => {
          if (data.success) {
            const staff = data.staff;
            document.getElementById('edit_id').value = staff.id;
            document.getElementById('edit_code_display').value = staff.staff_id || ('S' + staff.id);
            document.getElementById('edit_name').value = staff.name || '';
            document.getElementById('edit_address').value = staff.address || '';
            document.getElementById('edit_email').value = staff.email || '';
            document.getElementById('edit_phone').value = staff.phone || '';
            document.getElementById('edit_date_of_birth').value = staff.date_of_birth || '';
            document.getElementById('edit_designation').value = staff.designation || '';
            document.getElementById('edit_fixed_salary').value = staff.fixed_salary || 0;
            document.getElementById('edit_salary_status').value = staff.salary_status || 'active';
            openEditStaffModal();
          } else {
            showToast('Error: ' + (data.message || 'Unknown'), 'error');
          }
        })
        .catch(err => {
          console.error(err);
          showToast('An error occurred while fetching staff details.', 'error');
        });
    }

    function deleteStaff(id) {
      if (!confirm('Are you sure you want to delete staff ID ' + id + '? This action cannot be undone.')) return;

      fetchJSON('staff-management.php?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: id })
      }).then(data => {
        if (data.success) {
          showToast('Staff deleted successfully!');
          loadStaffData();
        } else {
          showToast('Error: ' + (data.message || 'Unknown'), 'error');
        }
      }).catch(err => {
        console.error(err);
        showToast('An error occurred while deleting staff.', 'error');
      });
    }

    // Add staff form submission
    // Add staff form submission (VALIDATED VERSION)
    document.getElementById('addStaffForm').addEventListener('submit', function (e) {
      e.preventDefault();

      // Reset previous errors
      resetFormValidation('addStaffForm', false);

      // Collect form data
      const formData = new FormData(this);

      // Validate form
      const validation = validateForm(formData, false);

      if (!validation.isValid) {
        // Show first error in toast
        if (validation.errors.length > 0) {
          showToast(`Please fix errors: ${validation.errors[0].message}`, 'error');

          // Scroll to first error
          const firstErrorField = validation.errors[0].field;
          const firstErrorInput = document.getElementById(firstErrorField);
          if (firstErrorInput) {
            firstErrorInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstErrorInput.focus();
          }
        }
        return;
      }

      // If valid, proceed with submission
      const submitBtn = this.querySelector('button[type="submit"]');
      const originalText = submitBtn.innerHTML;
      submitBtn.innerHTML = '<div class="loading"></div>';
      submitBtn.disabled = true;

      fetchJSON('staff-management.php?action=add', {
        method: 'POST',
        body: formData
      }).then(data => {
        if (data.success) {
          showToast('Staff added successfully with ID: ' + data.id);
          closeAddStaffModal();
          loadStaffData();
          // Reset form
          this.reset();
          resetFormValidation('addStaffForm', false);
        } else {
          showToast('Error: ' + (data.message || 'Unknown'), 'error');
        }
      }).catch(err => {
        console.error(err);
        showToast('An error occurred while adding staff.', 'error');
      }).finally(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
      });
    });

    // Edit staff form submission (VALIDATED VERSION)
    document.getElementById('editStaffForm').addEventListener('submit', function (e) {
      e.preventDefault();

      // Reset previous errors
      resetFormValidation('editStaffForm', true);

      // Collect form data
      const formData = new FormData(this);

      // Validate form
      const validation = validateForm(formData, true);

      if (!validation.isValid) {
        // Show first error in toast
        if (validation.errors.length > 0) {
          showToast(`Please fix errors: ${validation.errors[0].message}`, 'error');

          // Scroll to first error
          const firstErrorField = validation.errors[0].field;
          const firstErrorInput = document.getElementById(`edit_${firstErrorField}`);
          if (firstErrorInput) {
            firstErrorInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstErrorInput.focus();
          }
        }
        return;
      }

      // If valid, proceed with submission
      const submitBtn = this.querySelector('button[type="submit"]');
      const originalText = submitBtn.innerHTML;
      submitBtn.innerHTML = '<div class="loading"></div>';
      submitBtn.disabled = true;

      fetchJSON('staff-management.php?action=update', {
        method: 'POST',
        body: formData
      }).then(data => {
        if (data.success) {
          showToast('Staff updated successfully!');
          closeEditStaffModal();
          loadStaffData();
          // Reset form
          this.reset();
          resetFormValidation('editStaffForm', true);
        } else {
          showToast('Error: ' + (data.message || 'Unknown'), 'error');
        }
      }).catch(err => {
        console.error(err);
        showToast('An error occurred while updating staff.', 'error');
      }).finally(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
      });
    });

    // Search & filter handlers
    document.getElementById('search').addEventListener('input', function () {
      clearTimeout(this.searchTimeout);
      this.searchTimeout = setTimeout(() => loadStaffData(), 500);
    });
    document.getElementById('status').addEventListener('change', loadStaffData);
    document.getElementById('paymentStatus').addEventListener('change', loadStaffData);

    // Clear filters button
    document.getElementById('clear').addEventListener('click', clearFilters);

    // Close modals when clicking outside
    document.getElementById('addStaffModal').addEventListener('click', function (e) {
      if (e.target === this) closeAddStaffModal();
    });
    document.getElementById('editStaffModal').addEventListener('click', function (e) {
      if (e.target === this) closeEditStaffModal();
    });
    document.getElementById('paymentHistoryModal').addEventListener('click', function (e) {
      if (e.target === this) closePaymentHistoryModal();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeAddStaffModal();
        closeEditStaffModal();
        closePaymentHistoryModal();
      }
    });

    // Initialize
    // Initialize
    document.addEventListener('DOMContentLoaded', function () {
      loadStaffData();
      initValidation(); // Add this line
    });
    // Validation functions
    const validators = {
      name: (value) => {
        if (!value || value.trim().length < 2) return 'Name is required (minimum 2 characters)';
        if (value.length > 100) return 'Name cannot exceed 100 characters';
        if (!/^[A-Za-z\s\.\-]+$/.test(value)) return 'Name can only contain letters, spaces, dots and hyphens';
        return null;
      },

      address: (value) => {
        if (!value || value.trim().length < 10) return 'Address is required (minimum 10 characters)';
        if (value.length > 500) return 'Address cannot exceed 500 characters';
        return null;
      },

      email: (value) => {
        if (value && value.trim() !== '') {
          const emailRegex = /^[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$/i;
          if (!emailRegex.test(value)) return 'Please enter a valid email address';
        }
        return null;
      },

      phone: (value) => {
        if (!value || value.trim() === '') return 'Phone number is required';

        // Remove all non-digit characters
        const digitsOnly = value.replace(/\D/g, '');

        // Check length is exactly 10
        if (digitsOnly.length !== 10) {
          return 'Phone number must be exactly 10 digits';
        }

        // Check if starts with 6, 7, 8, or 9
        if (!/^[6-9]/.test(digitsOnly)) {
          return 'Phone number must start with 6, 7, 8, or 9';
        }

        // Optional: Check if all are digits (should be true after replace)
        if (!/^\d+$/.test(digitsOnly)) {
          return 'Phone number can only contain digits';
        }

        return null;
      },

      dateOfBirth: (value) => {
        if (value) {
          const dob = new Date(value);
          const today = new Date();
          if (dob > today) return 'Date of birth cannot be in the future';

          // Optional: Check if age is reasonable (at least 18 years old)
          const age = today.getFullYear() - dob.getFullYear();
          const monthDiff = today.getMonth() - dob.getMonth();
          if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
            age--;
          }
          if (age < 18) {
            return 'Staff member must be at least 18 years old';
          }
        }
        return null;
      },

      designation: (value) => {
        if (value && value.length > 100) return 'Designation cannot exceed 100 characters';
        return null;
      },

      fixedSalary: (value) => {
        if (!value && value !== 0) return 'Salary is required';

        const salary = parseFloat(value);
        if (isNaN(salary)) return 'Salary must be a valid number';
        if (salary < 0) return 'Salary cannot be negative';
        if (salary > 9999999.99) return 'Salary cannot exceed ₹99,99,999.99';

        // Check for decimal places
        const decimalPart = value.toString().split('.')[1];
        if (decimalPart && decimalPart.length > 2) {
          return 'Salary can have maximum 2 decimal places';
        }
        return null;
      },

      salaryStatus: (value) => {
        if (!value) return 'Salary status is required';
        if (!['active', 'inactive', 'pending'].includes(value)) {
          return 'Please select a valid salary status';
        }
        return null;
      }
    };

    // Character counter for text inputs and textareas
    function setupCharacterCounters() {
      // Add character counters for text inputs
      document.querySelectorAll('input[type="text"], input[type="tel"], textarea').forEach(input => {
        const maxLength = input.getAttribute('maxlength');
        if (maxLength) {
          const counterId = input.id ? input.id + '-counter' : null;
          const counter = document.getElementById(counterId);

          if (counter) {
            input.addEventListener('input', function () {
              const length = this.value.length;
              counter.textContent = `${length}/${maxLength}`;
              counter.classList.add('show');

              if (length > maxLength * 0.9) {
                counter.classList.add('warning');
                counter.classList.remove('error');
              } else if (length > maxLength) {
                counter.classList.add('error');
                counter.classList.remove('warning');
              } else {
                counter.classList.remove('warning', 'error');
              }
            });

            // Trigger on load for edit forms
            if (input.value) {
              const length = input.value.length;
              counter.textContent = `${length}/${maxLength}`;
              counter.classList.add('show');
            }
          }
        }
      });
    }

    // Validate a single field
    function validateField(fieldName, value, isEditForm = false) {
      const validator = validators[fieldName];
      if (!validator) return null;

      return validator(value);
    }

    // Show validation error
    function showError(fieldId, message, isEditForm = false) {
      const prefix = isEditForm ? 'edit-' : '';
      const errorElement = document.getElementById(`${prefix}${fieldId}-error`);

      if (errorElement) {
        errorElement.querySelector('span').textContent = message;
        errorElement.classList.add('show');

        // Add invalid class to input
        const inputElement = document.getElementById(isEditForm ? `edit_${fieldId}` : fieldId);
        if (inputElement) {
          inputElement.classList.add('invalid');
        }
      }
    }

    // Hide validation error
    function hideError(fieldId, isEditForm = false) {
      const prefix = isEditForm ? 'edit-' : '';
      const errorElement = document.getElementById(`${prefix}${fieldId}-error`);

      if (errorElement) {
        errorElement.classList.remove('show');

        // Remove invalid class from input
        const inputElement = document.getElementById(isEditForm ? `edit_${fieldId}` : fieldId);
        if (inputElement) {
          inputElement.classList.remove('invalid');
        }
      }
    }

    // Validate entire form (QUICK FIX)
    function validateForm(formData, isEditForm = false) {
      let isValid = true;
      const errors = [];

      // Validate each field
      for (const [fieldName, value] of formData.entries()) {
        // Skip the id field
        if (fieldName === 'id') continue;

        const error = validateField(fieldName, value, isEditForm);
        if (error) {
          isValid = false;
          showError(fieldName, error, isEditForm);
          errors.push({ field: fieldName, message: error });
        } else {
          hideError(fieldName, isEditForm);
        }
      }

      // Validate salary_status separately (since it might not be in FormData)
      const statusFieldId = isEditForm ? 'edit_salary_status' : 'salary_status';
      const statusSelect = document.getElementById(statusFieldId);
      if (statusSelect) {
        const statusValue = statusSelect.value;
        const statusError = validators.salaryStatus(statusValue);
        if (statusError) {
          isValid = false;
          showError('status', statusError, isEditForm);
          errors.push({ field: 'salary_status', message: statusError });
        } else {
          hideError('status', isEditForm);
        }
      }

      return { isValid, errors };
    }

    // Setup real-time validation
    function setupRealTimeValidation(formId, isEditForm = false) {
      const form = document.getElementById(formId);
      if (!form) return;

      // Add input event listeners for real-time validation
      form.querySelectorAll('input, textarea, select').forEach(element => {
        const fieldName = element.name;
        if (!fieldName || fieldName === 'id') return;

        element.addEventListener('blur', function () {
          const value = this.type === 'checkbox' ? this.checked : this.value;
          const error = validateField(fieldName, value, isEditForm);

          if (error) {
            showError(fieldName, error, isEditForm);
          } else {
            hideError(fieldName, isEditForm);
          }
        });

        // Clear error on input (optional - can be removed if you want errors to persist until blur)
        element.addEventListener('input', function () {
          // Only clear if the field has been validated before
          if (this.classList.contains('invalid')) {
            const value = this.type === 'checkbox' ? this.checked : this.value;
            const error = validateField(fieldName, value, isEditForm);

            if (!error) {
              hideError(fieldName, isEditForm);
            }
          }
        });
      });
    }

    // Reset form validation
    function resetFormValidation(formId, isEditForm = false) {
      const form = document.getElementById(formId);
      if (!form) return;

      // Clear all errors
      form.querySelectorAll('.validation-error').forEach(error => {
        error.classList.remove('show');
      });

      // Remove invalid classes
      form.querySelectorAll('.form-input, .form-textarea, .form-select').forEach(input => {
        input.classList.remove('invalid');
      });

      // Reset character counters
      form.querySelectorAll('.char-count').forEach(counter => {
        counter.classList.remove('show', 'warning', 'error');
      });
    }

    // Initialize validation
    function initValidation() {
      setupCharacterCounters();
      setupRealTimeValidation('addStaffForm', false);
      setupRealTimeValidation('editStaffForm', true);
    }
  </script>
</body>

</html>