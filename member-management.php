<?php
/* member-management.php
   Full file without 'unfreeze' living status option and without Family Size column.
*/

/* --- secure session (must be first) --- */
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

/* --- DB --- */
require_once 'db_connection.php';
$db_result = get_db_connection();
if (isset($db_result['error'])) {
  die("Database connection failed: " . $db_result['error']);
}
$conn = $db_result['conn'];
$mahal_id = (int) ($_SESSION['user_id'] ?? 0);

// Fetch logged-in mahal details for sidebar
$user_id = $_SESSION['user_id'];
$sql_mahal = "SELECT name, address, registration_no, email FROM register WHERE id = ?";
$stmt_mahal = $conn->prepare($sql_mahal);
$stmt_mahal->bind_param("i", $user_id);
$stmt_mahal->execute();
$result_mahal = $stmt_mahal->get_result();

if ($result_mahal->num_rows > 0) {
  $mahal = $result_mahal->fetch_assoc();
} else {
  echo "<script>alert('Unable to fetch mahal details. Please log in again.'); window.location.href='index.php';</script>";
  exit();
}
$stmt_mahal->close();

// Define logo path
$logo_path = "logo.jpeg";

/* --- Helper: check for column existence (prevents SQL errors) --- */
function column_exists(mysqli $conn, string $table, string $column): bool
{
  $safeTable = $conn->real_escape_string($table);
  $safeColumn = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
  if ($res === false)
    return false;
  $exists = $res->num_rows > 0;
  $res->free();
  return $exists;
}

/* Ensure sahakari tables exist (safe-create) — enums exclude 'unfreeze' */
$conn->query("
CREATE TABLE IF NOT EXISTS sahakari_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mahal_id INT NOT NULL,
    member_number INT DEFAULT NULL,
    head_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    total_family_members INT DEFAULT 0,
    join_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    monthly_donation_due VARCHAR(50) DEFAULT NULL,
    total_due DECIMAL(10,2) DEFAULT 0.00,
    status ENUM('active','death','freeze','terminate') DEFAULT 'active',
    INDEX idx_smk_mahal (mahal_id),
    INDEX idx_smk_member_number (member_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$conn->query("
CREATE TABLE IF NOT EXISTS sahakari_family_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    relationship VARCHAR(100) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active','death','freeze','terminate') DEFAULT NULL,
    INDEX idx_sfmk_member (member_id),
    CONSTRAINT fk_sfmk_member FOREIGN KEY (member_id)
        REFERENCES sahakari_members(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* --- Build safe select fragments for living_status (use NULL if column absent) --- */
$members_has_status = column_exists($conn, 'members', 'status');
$family_has_status = column_exists($conn, 'family_members', 'status');
$sahakari_has_status = column_exists($conn, 'sahakari_members', 'status');
$sahakari_family_has_status = column_exists($conn, 'sahakari_family_members', 'status');

$members_living_sel = $members_has_status ? "m.status AS living_status" : "NULL AS living_status";
$fm_living_sel = $family_has_status ? "fm.status AS living_status" : "NULL AS living_status";
$s_members_living_sel = $sahakari_has_status ? "m.status AS living_status" : "NULL AS living_status";
$s_fm_living_sel = $sahakari_family_has_status ? "fm.status AS living_status" : "NULL AS living_status";

/* ===========================================================
   REGULAR MEMBERS (members + family_members)
   =========================================================== */
$sql = "SELECT 
    m.id,
    m.member_number,
    m.head_name,
    m.email,
    m.phone,
    m.address,
    m.total_family_members,
    m.join_date,
    m.created_at,
    m.monthly_donation_due,
    m.total_due,
    {$members_living_sel}
FROM members m
WHERE m.mahal_id = ?
ORDER BY m.member_number ASC, m.id ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $mahal_id);
$stmt->execute();
$result = $stmt->get_result();

$heads = [];
$headsById = [];
while ($row = $result->fetch_assoc()) {
  $h = [
    'id' => 'M' . str_pad($row['id'], 3, '0', STR_PAD_LEFT),
    'member_db_id' => (int) $row['id'],
    'member_number' => isset($row['member_number']) && $row['member_number'] !== null ? (int) $row['member_number'] : null,
    'name' => $row['head_name'],
    'email' => $row['email'],
    'phone' => $row['phone'],
    'address' => $row['address'],
    'status' => $row['monthly_donation_due'],        // donation status
    'living_status' => $row['living_status'] ?? null, // living status
    'pending' => isset($row['total_due']) ? (float) $row['total_due'] : null,
    'family' => (int) $row['total_family_members'],
    'role' => 'Head'
  ];
  $heads[] = $h;
  $headsById[$row['id']] = $h;
}
$stmt->close();

/* Family (regular) */
$family_members = [];
$sqlFm = "SELECT 
            fm.id,
            fm.member_id,
            fm.name,
            fm.relationship,
            fm.email,
            fm.phone,
            fm.created_at,
            {$fm_living_sel}
          FROM family_members fm
          INNER JOIN members m ON m.id = fm.member_id
          WHERE m.mahal_id = ?
          ORDER BY fm.created_at DESC, fm.id DESC";
$stmt2 = $conn->prepare($sqlFm);
$stmt2->bind_param("i", $mahal_id);
$stmt2->execute();
$resFm = $stmt2->get_result();
while ($row = $resFm->fetch_assoc()) {
  $parent = $headsById[$row['member_id']] ?? null;
  $family_members[] = [
    'id' => 'FM' . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
    'member_db_id' => (int) $row['member_id'],
    'name' => $row['name'],
    'email' => $row['email'],
    'phone' => $row['phone'],
    'address' => $parent['address'] ?? '',
    'status' => null,
    'living_status' => $row['living_status'] ?? null,
    'pending' => null,
    'family' => null,
    'role' => 'Family'
  ];
}
$stmt2->close();

/* ===========================================================
   SAHAKARI MEMBERS (sahakari_members + sahakari_family_members)
   =========================================================== */
$s_heads = [];
$s_headsById = [];
$sqlS = "SELECT 
    m.id,
    m.member_number,
    m.head_name,
    m.email,
    m.phone,
    m.address,
    m.total_family_members,
    m.join_date,
    m.created_at,
    m.monthly_donation_due,
    m.total_due,
    {$s_members_living_sel}
FROM sahakari_members m
WHERE m.mahal_id = ?
ORDER BY m.member_number ASC, m.id ASC";
if ($stS = $conn->prepare($sqlS)) {
  $stS->bind_param("i", $mahal_id);
  if ($stS->execute()) {
    $rsS = $stS->get_result();
    while ($row = $rsS->fetch_assoc()) {
      $h = [
        'id' => 'SM' . str_pad($row['id'], 3, '0', STR_PAD_LEFT),
        'member_db_id' => (int) $row['id'],
        'member_number' => isset($row['member_number']) && $row['member_number'] !== null ? (int) $row['member_number'] : null,
        'name' => $row['head_name'],
        'email' => $row['email'],
        'phone' => $row['phone'],
        'address' => $row['address'],
        'status' => $row['monthly_donation_due'],
        'living_status' => $row['living_status'] ?? null,
        'pending' => isset($row['total_due']) ? (float) $row['total_due'] : null,
        'family' => (int) $row['total_family_members'],
        'role' => 'Head'
      ];
      $s_heads[] = $h;
      $s_headsById[$row['id']] = $h;
    }
  }
  $stS->close();
}

$s_family_members = [];
$sqlSFm = "SELECT 
            fm.id,
            fm.member_id,
            fm.name,
            fm.relationship,
            fm.email,
            fm.phone,
            fm.created_at,
            {$s_fm_living_sel}
          FROM sahakari_family_members fm
          INNER JOIN sahakari_members m ON m.id = fm.member_id
          WHERE m.mahal_id = ?
          ORDER BY fm.created_at DESC, fm.id DESC";
if ($stSFm = $conn->prepare($sqlSFm)) {
  $stSFm->bind_param("i", $mahal_id);
  if ($stSFm->execute()) {
    $rsSFm = $stSFm->get_result();
    while ($row = $rsSFm->fetch_assoc()) {
      $parent = $s_headsById[$row['member_id']] ?? null;
      $s_family_members[] = [
        'id' => 'SFM' . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
        'member_db_id' => (int) $row['member_id'],
        'name' => $row['name'],
        'email' => $row['email'],
        'phone' => $row['phone'],
        'address' => $parent['address'] ?? '',
        'status' => null,
        'living_status' => $row['living_status'] ?? null,
        'pending' => null,
        'family' => null,
        'role' => 'Family'
      ];
    }
  }
  $stSFm->close();
}

$conn->close();
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1" />
  <title>Member Management - <?php echo htmlspecialchars($mahal['name']); ?></title>
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
      grid-template-columns: 1fr auto auto auto auto auto;
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

    /* Toggle Switches - Enhanced with Blue Accent */
    .toggle {
      display: inline-flex;
      align-items: center;
      gap: 12px;
      padding: 10px 16px 10px 20px;
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary) 4px, var(--card-alt) 4px);
      background-size: 4px 100%;
      background-repeat: no-repeat;
      background-position: left center;
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      user-select: none;
      cursor: pointer;
      transition: var(--transition);
    }

    .toggle:hover {
      background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-light) 5px, var(--card) 5px);
      background-size: 5px 100%;
      background-repeat: no-repeat;
      background-position: left center;
      border-color: var(--primary-light);
    }

    .toggle-text {
      font-size: 14px;
      color: var(--text);
      font-weight: 600;
      min-width: max-content;
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

    .switch {
      position: relative;
      width: 56px;
      height: 28px;
      border-radius: 999px;
      background: #d1d5db;
      transition: background .2s;
      flex: 0 0 56px;
    }

    .switch .thumb {
      position: absolute;
      top: 3px;
      left: 3px;
      width: 22px;
      height: 22px;
      border-radius: 50%;
      background: #fff;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.12);
      transition: left .2s;
    }

    .switch.on {
      background: var(--primary);
    }

    .switch.on .thumb {
      left: 31px;
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

    .status-cleared {
      background: linear-gradient(135deg, var(--success), #059669);
    }

    .status-due {
      background: linear-gradient(135deg, var(--warning), #d97706);
    }

    .status-null {
      background: var(--text-lighter);
    }

    .living-active {
      background: linear-gradient(135deg, var(--success), #059669);
      color: #fff;
      padding: 6px 12px;
      border-radius: 999px;
      font-weight: 700;
      font-size: 12px;
      text-transform: uppercase;
    }

    .living-death {
      background: linear-gradient(135deg, #374151, #111827);
      color: #fff;
      padding: 6px 12px;
      border-radius: 999px;
      font-weight: 700;
      font-size: 12px;
      text-transform: uppercase;
    }

    .living-freeze {
      background: linear-gradient(135deg, var(--warning), #d97706);
      color: #fff;
      padding: 6px 12px;
      border-radius: 999px;
      font-weight: 700;
      font-size: 12px;
      text-transform: uppercase;
    }

    .living-terminate {
      background: linear-gradient(135deg, var(--error), #dc2626);
      color: #fff;
      padding: 6px 12px;
      border-radius: 999px;
      font-weight: 700;
      font-size: 12px;
      text-transform: uppercase;
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
    tr[data-member-id] {
      cursor: pointer;
      transition: var(--transition);
    }

    tr[data-member-id]:hover {
      background: var(--card-alt) !important;
      transform: translateY(-1px);
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
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

      .toggle {
        justify-content: space-between;
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

          <button class="menu-btn active" type="button">
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
          <i class="fas fa-users"></i>
          Member Management
        </div>
      </section>

      <div class="container">
        <div class="page-header">
          <div class="actions">
            <a href="addmember.php" class="btn green">
              <i class="fas fa-plus"></i>
              Add Member
            </a>
            <a href="add_sahakari.php" class="btn green">
              <i class="fas fa-plus"></i>
              Add Sahakari Member
            </a>
            <button class="btn white" id="exportBtn" type="button">
              <i class="fas fa-file-export"></i>
              Export to Excel
            </button>
          </div>
          <div class="stats">
            <i class="fas fa-users"></i>
            Total: <span id="count">0</span>
          </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
          <div class="filter-row">
            <input type="search" id="search"
              placeholder="Search by name, email, phone, member no. or id (e.g. M001, FM0001)..." autocomplete="on"
              inputmode="search">

            <select id="status" aria-label="Donation status filter">
              <option value="all">All Donation Status</option>
              <option value="cleared">Cleared</option>
              <option value="due">Due</option>
            </select>

            <!-- Living status filter (no 'unfreeze') -->
            <select id="living" aria-label="Living status filter">
              <option value="all">All Living Status</option>
              <option value="active">Active</option>
              <option value="death">Deceased</option>
              <option value="freeze">Frozen</option>
              <option value="terminate">Terminated</option>
            </select>

            <!-- Dataset toggle Regular <-> Sahakari -->
            <input class="visually-hidden" type="checkbox" id="sahakariOnly" role="switch" aria-checked="false">
            <label class="toggle" for="sahakariOnly" id="sahakariToggleLabel">
              <span class="toggle-text" id="sahakariToggleText">Regular Members</span>
              <span class="switch" id="sahakariSwitchVisual"><span class="thumb"></span></span>
            </label>

            <!-- Heads-only toggle -->
            <input class="visually-hidden" type="checkbox" id="headsOnly" role="switch" aria-checked="true" checked>
            <label class="toggle" for="headsOnly" id="toggleLabel">
              <span class="toggle-text" id="toggleText">Family Heads Only</span>
              <span class="switch" id="switchVisual"><span class="thumb"></span></span>
            </label>

            <button class="btn white" id="clear" type="button">
              <i class="fas fa-times"></i>
              Clear Filters
            </button>
          </div>
        </div>

        <!-- Table -->
        <div class="table-container" role="region" aria-label="Members table">
          <div class="table-scroll">
            <table id="table">
              <thead>
                <tr>
                  <th scope="col"><span id="colMemberNoLabel">Member No.</span></th>
                  <th scope="col">Name</th>
                  <th scope="col">Email</th>
                  <th scope="col">Phone</th>
                  <th scope="col">Address</th>
                  <th scope="col">Total Due</th>
                  <th scope="col">Donation Status</th>
                  <th scope="col">Living Status</th>

                </tr>
              </thead>
              <tbody>
                <tr>
                  <td colspan="8" class="no-data">Loading…</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="footer">Last updated: <?php echo date('M d, Y h:i A'); ?></div>
      </div>
    </main>
  </div>

  <script>
    const heads = <?php echo json_encode(array_values($heads), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const familyMembers = <?php echo json_encode(array_values($family_members), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const sahakariHeads = <?php echo json_encode(array_values($s_heads), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const sahakariFamily = <?php echo json_encode(array_values($s_family_members), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    const tbody = document.querySelector('#table tbody');
    const count = document.getElementById('count');

    const search = document.getElementById('search');
    const status = document.getElementById('status');
    const living = document.getElementById('living');

    const headsOnly = document.getElementById('headsOnly');
    const switchVisual = document.getElementById('switchVisual');
    const toggleText = document.getElementById('toggleText');

    const sahakariOnly = document.getElementById('sahakariOnly');
    const sahakariSwitchVisual = document.getElementById('sahakariSwitchVisual');
    const sahakariToggleText = document.getElementById('sahakariToggleText');
    const colMemberNoLabel = document.getElementById('colMemberNoLabel');

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

    // Member management functionality
    function formatCurrency(v) {
      if (v === null || v === undefined || v === '') return '<span class="null-text">—</span>';
      return '₹' + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatDonationStatus(status) {
      if (status === null || status === undefined || status === '') return '<span class="status-badge status-null">—</span>';
      const s = String(status).toLowerCase() === 'cleared' ? 'cleared' : 'due';
      return `<span class="status-badge status-${s}">${s === 'cleared' ? 'Cleared' : 'Due'}</span>`;
    }

    function formatLivingStatus(l) {
      if (!l) return '<span class="null-text">—</span>';
      const s = String(l).toLowerCase();
      switch (s) {
        case 'active': return '<span class="living-active">Active</span>';
        case 'death': return '<span class="living-death">Deceased</span>';
        case 'freeze': return '<span class="living-freeze">Frozen</span>';
        case 'terminate': return '<span class="living-terminate">Terminated</span>';
        default: return `<span class="null-text">${String(l)}</span>`;
      }
    }

    function rowHTML(p) {
      const memberNoCell = (p.role === 'Head')
        ? (p.member_number !== null && p.member_number !== undefined && p.member_number !== '' ? p.member_number : '<span class="null-text">—</span>')
        : '<span class="null-text">—</span>';

      return `<tr data-member-id="${p.member_db_id}" data-role="${p.role}">
      <td>${memberNoCell}</td>
      <td>${p.name || ''}</td>
      <td>${p.email || '<span class="null-text">—</span>'}</td>
      <td>${p.phone || '<span class="null-text">—</span>'}</td>
      <td>${p.address || '<span class="null-text">—</span>'}</td>
      <td>${formatCurrency(p.pending)}</td>
      <td>${formatDonationStatus(p.status)}</td>
      <td>${formatLivingStatus(p.living_status)}</td>
      
    </tr>`;
    }

    function addRowClickListeners() {
      const rows = tbody.querySelectorAll('tr[data-member-id]');
      rows.forEach(row => {
        row.addEventListener('click', function () {
          const memberId = row.getAttribute('data-member-id');
          const role = row.getAttribute('data-role');

          if (role === 'Head' && memberId) {
            // Use the current toggle state to determine which page to go to
            const isSahakari = sahakariOnly.checked;

            if (isSahakari) {
              window.location.href = `sahakari_details.php?id=${memberId}`;
            } else {
              window.location.href = `user_details.php?id=${memberId}`;
            }
          }
        }, { passive: true });
      });
    }
    function render(list) {
      if (!list || list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="no-data">No records match your filters.</td></tr>';
        count.textContent = 0;
        return;
      }
      tbody.innerHTML = list.map(rowHTML).join('');
      count.textContent = list.length;
      addRowClickListeners();
    }

    function setHeadsSwitchVisual() {
      switchVisual.classList.toggle('on', headsOnly.checked);
      headsOnly.setAttribute('aria-checked', headsOnly.checked ? 'true' : 'false');
      toggleText.textContent = headsOnly.checked ? 'Family Heads Only' : 'All Members';
    }

    function setSahakariSwitchVisual() {
      sahakariSwitchVisual.classList.toggle('on', sahakariOnly.checked);
      sahakariOnly.setAttribute('aria-checked', sahakariOnly.checked ? 'true' : 'false');
      sahakariToggleText.textContent = sahakariOnly.checked ? 'Sahakari Members' : 'Regular Members';
      colMemberNoLabel.textContent = sahakariOnly.checked ? 'Sahakari No.' : 'Member No.';
      search.placeholder = sahakariOnly.checked
        ? 'Search by name, email, phone, Sahakari no. or id (e.g. SM001, SFM0001)...'
        : 'Search by name, email, phone, member no. or id (e.g. M001, FM0001)...';
    }

    function currentDataset() {
      return sahakariOnly.checked
        ? { heads: sahakariHeads, family: sahakariFamily }
        : { heads, family: familyMembers };
    }

    function filter() {
      const raw = (search.value || '').trim();
      const q = raw.toLowerCase();
      const s = status.value;
      const lv = living.value;
      const ds = currentDataset();

      const base = headsOnly.checked ? ds.heads : ds.heads.concat(ds.family);
      let filtered;

      if (/^\d+$/.test(q)) {
        const num = Number(q);
        filtered = base.filter(p => (p.member_number !== null && p.member_number !== undefined && Number(p.member_number) === num));
      } else if (
        (!sahakariOnly.checked && (/^m\d+$/i.test(q) || /^fm\d+$/i.test(q))) ||
        (sahakariOnly.checked && (/^sm\d+$/i.test(q) || /^sfm\d+$/i.test(q)))
      ) {
        const normalized = q.toUpperCase();
        filtered = base.filter(p => (p.id || '').toUpperCase() === normalized);
      } else if (q === '') {
        filtered = base.slice();
      } else {
        filtered = base.filter(p => {
          const idText = (p.id || '') + ' ' + (p.member_db_id !== undefined ? String(p.member_db_id) : '');
          const memberNumberText = (p.member_number !== null && p.member_number !== undefined) ? String(p.member_number) : '';
          const blob = ((p.name || '') + ' ' + (p.email || '') + ' ' + (p.phone || '') + ' ' + (p.address || '') + ' ' + idText + ' ' + memberNumberText).toLowerCase();
          return blob.includes(q);
        });
      }

      if (s !== 'all') {
        filtered = filtered.filter(p => ((p.status || '').toLowerCase() === s));
      }

      if (lv !== 'all') {
        filtered = filtered.filter(p => ((p.living_status || '').toLowerCase() === lv));
      }

      filtered.sort((a, b) => {
        const na = (a.member_number !== null && a.member_number !== undefined) ? Number(a.member_number) : Number.POSITIVE_INFINITY;
        const nb = (b.member_number !== null && b.member_number !== undefined) ? Number(b.member_number) : Number.POSITIVE_INFINITY;
        if (na === nb) return (a.name || '').localeCompare(b.name || '');
        return na - nb;
      });

      render(filtered);
    }

    search.addEventListener('input', filter, { passive: true });
    status.addEventListener('input', filter, { passive: true });
    living.addEventListener('input', filter, { passive: true });

    headsOnly.addEventListener('change', () => { setHeadsSwitchVisual(); filter(); });
    document.getElementById('toggleLabel').addEventListener('keydown', (e) => {
      if (e.key === ' ' || e.key === 'Enter') {
        e.preventDefault();
        headsOnly.checked = !headsOnly.checked;
        headsOnly.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });

    sahakariOnly.addEventListener('change', () => { setSahakariSwitchVisual(); filter(); });
    document.getElementById('sahakariToggleLabel').addEventListener('keydown', (e) => {
      if (e.key === ' ' || e.key === 'Enter') {
        e.preventDefault();
        sahakariOnly.checked = !sahakariOnly.checked;
        sahakariOnly.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });

    document.getElementById('clear').onclick = () => {
      search.value = '';
      status.value = 'all';
      living.value = 'all';
      headsOnly.checked = true;
      sahakariOnly.checked = false;
      setHeadsSwitchVisual();
      setSahakariSwitchVisual();
      filter();
    };

    // --- Export button: build query from current filters and call new exporter file ---
    const exportBtn = document.getElementById('exportBtn');
    exportBtn.addEventListener('click', function () {
      const q = encodeURIComponent((search.value || '').trim());
      const donation = encodeURIComponent(status.value || 'all');
      const memberStatus = encodeURIComponent(living.value || 'all');
      const heads = headsOnly.checked ? '1' : '0';
      const sahakari = sahakariOnly.checked ? '1' : '0';

      const url = `export_members_excel.php?search=${q}&donation_status=${donation}&member_status=${memberStatus}&heads_only=${heads}&sahakari_only=${sahakari}`;
      window.location.href = url;
    }, { passive: true });

    // Initialize
    setHeadsSwitchVisual();
    setSahakariSwitchVisual();
    render(heads);
  </script>

</body>

</html>