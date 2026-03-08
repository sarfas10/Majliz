<?php
// marriage.php
require_once __DIR__ . '/session_bootstrap.php';
if (!isset($_SESSION['user_id'])) {
  header('Location: index.php');
  exit();
}

require_once __DIR__ . '/db_connection.php';
$db = get_db_connection();
if (isset($db['error']))
  die($db['error']);
/** @var mysqli $conn */
$conn = $db['conn'];

/* --- handle SAVE CHANGES postback in this same file --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_changes'])) {
  $request_id = isset($_POST['request_id']) ? (int) $_POST['request_id'] : 0;

  if ($request_id <= 0) {
    $conn->close();
    die('Invalid request ID.');
  }

  // Collect POST data into details_json
  $details = [
    'groom_name' => $_POST['groom_name'] ?? '',
    'groom_parent' => $_POST['groom_parent'] ?? '',
    'groom_dob' => $_POST['groom_dob'] ?? '',
    'groom_address' => $_POST['groom_address'] ?? '',
    'bride_name' => $_POST['bride_name'] ?? '',
    'bride_parent' => $_POST['bride_parent'] ?? '',
    'bride_dob' => $_POST['bride_dob'] ?? '',
    'bride_address' => $_POST['bride_address'] ?? '',
    'marriage_date' => $_POST['marriage_date'] ?? '',
    'marriage_venue' => $_POST['marriage_venue'] ?? '',
    'cooperating_mahal' => $_POST['cooperating_mahal'] ?? '',
    'reg_number' => $_POST['reg_number'] ?? '',
    'requested_by' => $_POST['requested_by'] ?? '',
    'signed_by' => $_POST['signed_by'] ?? '',
  ];

  $details_json = json_encode($details, JSON_UNESCAPED_UNICODE);

  // Handle photos only if new ones are uploaded
  $groom_photo_blob = null;
  $bride_photo_blob = null;

  if (!empty($_FILES['groom_photo']['tmp_name'])) {
    $groom_photo_blob = file_get_contents($_FILES['groom_photo']['tmp_name']);
  }

  if (!empty($_FILES['bride_photo']['tmp_name'])) {
    $bride_photo_blob = file_get_contents($_FILES['bride_photo']['tmp_name']);
  }

  // Build dynamic UPDATE query
  $sql = "UPDATE cert_requests SET details_json = ?, updated_at = NOW()";
  $types = "s";
  $params = [$details_json];

  if ($groom_photo_blob !== null) {
    $sql .= ", groom_photo = ?";
    $types .= "s";
    $params[] = $groom_photo_blob;
  }
  if ($bride_photo_blob !== null) {
    $sql .= ", bride_photo = ?";
    $types .= "s";
    $params[] = $bride_photo_blob;
  }

  $sql .= " WHERE id = ? LIMIT 1";
  $types .= "i";
  $params[] = $request_id;

  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    $conn->close();
    die("Prepare failed: " . $conn->error);
  }

  // bind_param with splat
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $stmt->close();
  $conn->close();

  // Redirect back to same page (GET) to avoid resubmission & show success
  header("Location: marriage.php?request_id={$request_id}&saved=1");
  exit();
}

/* --- issuer / mahal details from register (admin user) --- */
$user_id = (int) $_SESSION['user_id'];
$sql = "SELECT name, address, registration_no FROM register WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

$mahal_name = $row['name'] ?? '';
$mahal_address = $row['address'] ?? '';
$mahal_reg = $row['registration_no'] ?? '';

/* --- fetch marriage request details from cert_requests --- */
/* expects ?request_id= in the URL */
$request_id = isset($_GET['request_id']) ? (int) $_GET['request_id'] : 0;

$groom_name = '';
$groom_parent = '';
$groom_dob = '';
$groom_address = '';
$bride_name = '';
$bride_parent = '';
$bride_dob = '';
$bride_address = '';
$marriage_date = '';
$marriage_venue = '';
$cooperating_mahal = '';
$reg_number = '';
$requested_by = '';
$signed_by = '';

$groom_img_preview = '';
$bride_img_preview = '';

if ($request_id > 0) {
  // Ensure table + columns exist (important to avoid 500s)
  $createTableSql = "
        CREATE TABLE IF NOT EXISTS cert_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            member_id INT UNSIGNED NOT NULL,
            certificate_type VARCHAR(50) NOT NULL,
            details_json LONGTEXT NULL,
            groom_photo LONGBLOB NULL,
            bride_photo LONGBLOB NULL,
            notes TEXT NULL,
            status ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
            approved_by INT UNSIGNED NULL,
            output_file VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_member (member_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
  $conn->query($createTableSql);

  // Add columns if table existed already without them
  $resCol = $conn->query("SHOW COLUMNS FROM cert_requests LIKE 'groom_photo'");
  if ($resCol && $resCol->num_rows === 0) {
    $conn->query("ALTER TABLE cert_requests ADD groom_photo LONGBLOB NULL AFTER details_json");
  }
  $resCol = $conn->query("SHOW COLUMNS FROM cert_requests LIKE 'bride_photo'");
  if ($resCol && $resCol->num_rows === 0) {
    $conn->query("ALTER TABLE cert_requests ADD bride_photo LONGBLOB NULL AFTER groom_photo");
  }

  // Now safe to select these columns
  $sql = "
        SELECT details_json, groom_photo, bride_photo
        FROM cert_requests
        WHERE id = ? AND certificate_type = 'marriage'
        LIMIT 1
    ";
  $stmt = $conn->prepare($sql);
  if ($stmt) {
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($r = $res->fetch_assoc()) {
      // Textual details
      $details = json_decode($r['details_json'] ?? '', true);
      if (is_array($details)) {
        $groom_name = $details['groom_name'] ?? '';
        $groom_parent = $details['groom_parent'] ?? '';
        $groom_dob = $details['groom_dob'] ?? '';
        $groom_address = $details['groom_address'] ?? '';
        $bride_name = $details['bride_name'] ?? '';
        $bride_parent = $details['bride_parent'] ?? '';
        $bride_dob = $details['bride_dob'] ?? '';
        $bride_address = $details['bride_address'] ?? '';
        $marriage_date = $details['marriage_date'] ?? '';
        $marriage_venue = $details['marriage_venue'] ?? '';
        $cooperating_mahal = $details['cooperating_mahal'] ?? '';
        $reg_number = $details['reg_number'] ?? '';
        $requested_by = $details['requested_by'] ?? '';
        $signed_by = $details['signed_by'] ?? '';
      }

      // Groom photo preview (if stored)
      if (!empty($r['groom_photo'])) {
        $blob = $r['groom_photo'];
        // crude mime detect (JPEG vs PNG)
        $mime = 'image/jpeg';
        if (strncmp($blob, "\x89PNG", 4) === 0) {
          $mime = 'image/png';
        }
        $groom_img_preview = 'data:' . $mime . ';base64,' . base64_encode($blob);
      }

      // Bride photo preview (if stored)
      if (!empty($r['bride_photo'])) {
        $blob = $r['bride_photo'];
        $mime = 'image/jpeg';
        if (strncmp($blob, "\x89PNG", 4) === 0) {
          $mime = 'image/png';
        }
        $bride_img_preview = 'data:' . $mime . ';base64,' . base64_encode($blob);
      }
    }
    $stmt->close();
  } else {
    // Optional: log if prepare failed
    error_log('marriage_certificate_docx: prepare failed: ' . $conn->error);
  }
}

$conn->close();

// Fetch mahal details for sidebar
require_once 'db_connection.php';
$db_result = get_db_connection();
if (isset($db_result['error'])) {
  die("Database connection failed: " . $db_result['error']);
}
$conn_sidebar = $db_result['conn'];

$sql_mahal = "SELECT name, address, registration_no, email FROM register WHERE id = ?";
$stmt_mahal = $conn_sidebar->prepare($sql_mahal);
$stmt_mahal->bind_param("i", $user_id);
$stmt_mahal->execute();
$result_mahal = $stmt_mahal->get_result();
$mahal = $result_mahal->fetch_assoc();
$stmt_mahal->close();
$conn_sidebar->close();

// Define logo path
$logo_path = "logo.jpeg";
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Marriage Certificate — DOCX Generator</title>
  <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600&display=swap" rel="stylesheet">
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Amiri:wght@400;700&display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    /* Sidebar and Layout Styles */
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

    /* Sidebar Styles */
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

    /* Main Content Area */
    .main {
      margin-left: 0;
      min-height: 100vh;
      background: var(--bg);
      display: flex;
      flex-direction: column;
      width: 100%;
    }

    /* Header Row - Updated with right-aligned back button */
    .header-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      padding: 20px 24px;
      background: var(--card);
      border-bottom: 1px solid var(--border);
    }

    .header-left {
      display: flex;
      align-items: center;
      gap: 16px;
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
    }

    .floating-menu-btn:hover {
      background: var(--card-alt);
      transform: translateY(-1px);
      box-shadow: var(--shadow-lg);
    }

    .back-btn {
      background: var(--card-alt);
      border: 1px solid var(--border);
      color: var(--text);
      padding: 10px 16px;
      border-radius: var(--radius-sm);
      font-weight: 500;
      font-size: 14px;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 8px;
      transition: var(--transition);
      text-decoration: none;
      white-space: nowrap;
    }

    .back-btn:hover {
      background: var(--primary);
      color: white;
      transform: translateY(-1px);
    }

    .page-title {
      font-size: 20px;
      font-weight: 700;
      color: var(--text);
      flex: 1;
    }

    .header-right {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    /* Content Area */
    .content-area {
      flex: 1;
      padding: 24px;
      overflow-y: auto;
    }

    /* Form Styles */
    .wrap {
      max-width: 820px;
      margin: 0 auto;
    }

    .card {
      background: #fff;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 28px;
      box-shadow: var(--shadow);
    }

    h1 {
      margin: 0 0 8px;
      font-size: 24px;
      color: var(--text);
    }

    .sub {
      color: var(--text-light);
      margin-bottom: 20px;
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 16px;
    }

    .full {
      grid-column: 1 / -1;
    }

    label {
      font-size: 13px;
      color: var(--text);
      margin: 8px 0 6px;
      font-weight: 600;
      display: block;
    }

    input,
    textarea,
    select {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid var(--border);
      border-radius: 8px;
      font-size: 14px;
      font-family: 'Inter', sans-serif;
    }

    input:focus,
    textarea:focus,
    select:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
    }

    .readonly-box {
      background: var(--card-alt);
      border: 1px dashed var(--border);
      border-radius: 10px;
      padding: 14px;
      margin-bottom: 18px;
    }

    .muted {
      color: var(--text-light);
      font-size: 13px;
    }

    .btn {
      padding: 12px 16px;
      border: none;
      border-radius: 10px;
      background: var(--primary);
      color: #fff;
      font-weight: 700;
      cursor: pointer;
      font-family: 'Inter', sans-serif;
      font-size: 14px;
      transition: var(--transition);
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .btn:hover {
      background: var(--primary-dark);
      transform: translateY(-2px);
    }

    .btn-secondary {
      background: var(--text-light);
    }

    .btn-secondary:hover {
      background: var(--text);
    }

    .success-message {
      background: #d1fae5;
      padding: 12px;
      border-radius: 8px;
      color: #065f46;
      margin-bottom: 15px;
    }

    .img-preview {
      margin-top: 6px;
      border-radius: 8px;
      max-width: 120px;
      max-height: 120px;
      display: block;
      border: 1px solid var(--border);
    }

    /* Responsive */
    @media (max-width: 720px) {
      .grid {
        grid-template-columns: 1fr;
      }

      .header-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
      }

      .header-left {
        width: 100%;
        justify-content: space-between;
      }

      .header-right {
        width: 100%;
        justify-content: flex-end;
      }
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
      .main {
        margin-left: 0;
      }
    }

    @media (max-width: 768px) {
      .header-row {
        padding: 16px;
      }

      .content-area {
        padding: 16px;
      }

      .card {
        padding: 20px;
      }

      .page-title {
        font-size: 18px;
      }
    }

    @media (max-width: 480px) {
      .back-btn span {
        display: none;
      }

      .back-btn {
        padding: 10px;
      }

      .back-btn i {
        margin: 0;
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
        <!-- Profile -->
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

          <button class="menu-btn" type="button" onclick="window.location.href='academics.php'">
            <i class="fas fa-graduation-cap"></i>
            <span>Academics</span>
          </button>

          <button class="menu-btn active" type="button" onclick="window.location.href='certificate.php'">
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

    <!-- Main Content -->
    <main class="main" id="main">
      <!-- Header with Back Button on Right Side -->
      <div class="header-row">
        <div class="header-left">
          <button class="floating-menu-btn" id="menuToggle" aria-controls="sidebar" aria-expanded="false"
            aria-label="Open menu" type="button">
            <i class="fas fa-bars"></i>
          </button>

          <div class="page-title">
            Marriage Certificate
          </div>
        </div>

        <div class="header-right">
          <a href="certificate.php" class="back-btn">
            <i class="fas fa-arrow-left"></i>
            <span>Back to Certificates</span>
          </a>
        </div>
      </div>

      <!-- Content Area -->
      <div class="content-area">
        <div class="wrap">
          <div class="card">
            <h1>Marriage Certificate — DOCX Generator</h1>
            <div class="sub">Issuer details are auto-fetched from your Mahal profile.</div>

            <?php if (isset($_GET['saved'])): ?>
              <div class="success-message">
                <i class="fas fa-check-circle"></i> Changes saved successfully.
              </div>
            <?php endif; ?>

            <div class="readonly-box">
              <strong><?php echo htmlspecialchars($mahal_name); ?></strong><br>
              <span class="muted"><?php echo nl2br(htmlspecialchars($mahal_address)); ?></span><br>
              <span class="muted">REG. NO: <?php echo htmlspecialchars($mahal_reg); ?></span>
            </div>

            <!-- Default action = this same file (for Save). Generate button overrides via formaction. -->
            <form method="post" action="" enctype="multipart/form-data">
              <!-- Important: pass request_id so save + generator can use it -->
              <input type="hidden" name="request_id" value="<?php echo (int) $request_id; ?>">

              <div class="grid">
                <div class="full">
                  <h3>Groom Details</h3>
                </div>
                <div>
                  <label>Groom Full Name *</label>
                  <input type="text" name="groom_name" required value="<?php echo htmlspecialchars($groom_name); ?>" />
                </div>
                <div>
                  <label>Groom Parent's Name *</label>
                  <input type="text" name="groom_parent" required
                    value="<?php echo htmlspecialchars($groom_parent); ?>" />
                </div>
                <div>
                  <label>Groom DOB *</label>
                  <input type="date" name="groom_dob" required value="<?php echo htmlspecialchars($groom_dob); ?>" />
                </div>
                <div>
                  <label>Groom Photo (JPG/PNG, max 2MB)</label>
                  <input type="file" name="groom_photo" accept="image/jpeg,image/png" />
                  <?php if ($groom_img_preview): ?>
                    <div class="muted">Existing photo in system (will be used if you don't upload a new one):</div>
                    <img src="<?php echo $groom_img_preview; ?>" alt="Groom photo" class="img-preview">
                  <?php else: ?>
                    <div class="muted">No photo stored yet.</div>
                  <?php endif; ?>
                </div>
                <div class="full">
                  <label>Groom Address *</label>
                  <textarea name="groom_address" rows="2" required><?php
                  echo htmlspecialchars($groom_address);
                  ?></textarea>
                </div>

                <div class="full">
                  <h3>Bride Details</h3>
                </div>
                <div>
                  <label>Bride Full Name *</label>
                  <input type="text" name="bride_name" required value="<?php echo htmlspecialchars($bride_name); ?>" />
                </div>
                <div>
                  <label>Bride Parent's Name *</label>
                  <input type="text" name="bride_parent" required
                    value="<?php echo htmlspecialchars($bride_parent); ?>" />
                </div>
                <div>
                  <label>Bride DOB *</label>
                  <input type="date" name="bride_dob" required value="<?php echo htmlspecialchars($bride_dob); ?>" />
                </div>
                <div>
                  <label>Bride Photo (JPG/PNG, max 2MB)</label>
                  <input type="file" name="bride_photo" accept="image/jpeg,image/png" />
                  <?php if ($bride_img_preview): ?>
                    <div class="muted">Existing photo in system (will be used if you don't upload a new one):</div>
                    <img src="<?php echo $bride_img_preview; ?>" alt="Bride photo" class="img-preview">
                  <?php else: ?>
                    <div class="muted">No photo stored yet.</div>
                  <?php endif; ?>
                </div>
                <div class="full">
                  <label>Bride Address *</label>
                  <textarea name="bride_address" rows="2" required><?php
                  echo htmlspecialchars($bride_address);
                  ?></textarea>
                </div>

                <div class="full">
                  <h3>Marriage Details</h3>
                </div>
                <div>
                  <label>Marriage Date *</label>
                  <input type="date" name="marriage_date" required
                    value="<?php echo htmlspecialchars($marriage_date); ?>" />
                </div>
                <div>
                  <label>Marriage Venue *</label>
                  <input type="text" name="marriage_venue" required
                    value="<?php echo htmlspecialchars($marriage_venue); ?>" />
                </div>
                <div>
                  <label>Co-operating Mahal</label>
                  <input type="text" name="cooperating_mahal"
                    value="<?php echo htmlspecialchars($cooperating_mahal); ?>" />
                </div>
                <div>
                  <label>Certificate Registration No. *</label>
                  <input type="text" name="reg_number" required value="<?php echo htmlspecialchars($reg_number); ?>" />
                </div>
                <div>
                  <label>Requested By *</label>
                  <input type="text" name="requested_by" required
                    value="<?php echo htmlspecialchars($requested_by); ?>" />
                </div>
                <div>
                  <label>Signed By (President/Secretary) *</label>
                  <input type="text" name="signed_by" required value="<?php echo htmlspecialchars($signed_by); ?>" />
                </div>
              </div>

              <div style="margin-top:20px; display:flex; gap:10px; flex-wrap:wrap;">
                <!-- Save in DB in this same file -->
                <button class="btn" type="submit" name="save_changes" value="1">
                  <i class="fas fa-save"></i> Save Changes
                </button>

                <!-- Generate DOCX using external generator file -->
                <button class="btn btn-secondary" type="submit" formaction="generate_marriage_certificate.php"
                  name="generate_docx" value="1">
                  <i class="fas fa-file-word"></i> Generate Certificate
                </button>

                <!-- Generate Marriage Register PDF -->
                <button class="btn btn-secondary" type="submit" formaction="generate_marriage_register_pdf.php"
                  name="generate_register" value="1">
                  <i class="fas fa-file-pdf"></i> Generate Marriage Register
                </button>
              </div>
            </form>
          </div>
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

    document.querySelectorAll('.menu .menu-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        if (window.matchMedia('(max-width: 1023.98px)').matches) closeSidebar();
      });
    });

    // Set active menu item
    document.querySelectorAll('.menu-btn').forEach(btn => {
      if (btn.querySelector('span').textContent === 'Certificate Management') {
        btn.classList.add('active');
      } else {
        btn.classList.remove('active');
      }
    });

    // Prevent form resubmission on page refresh
    if (window.history.replaceState) {
      window.history.replaceState(null, null, window.location.href);
    }
  </script>
</body>

</html>