<?php
// certificate.php
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

require_once __DIR__ . '/db_connection.php';

$db = get_db_connection();
if (isset($db['error'])) {
  die("DB error: " . htmlspecialchars($db['error']));
}

/** @var mysqli $conn */
$conn = $db['conn'];

/* This user represents the respective Mahal (register.id) */
$mahal_id = (int) $_SESSION['user_id'];

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

/* --- Ensure cert_requests table exists --- */
$createTableSql = "
    CREATE TABLE IF NOT EXISTS cert_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        member_id INT UNSIGNED NOT NULL,
        is_sahakari TINYINT(1) NOT NULL DEFAULT 0,
        certificate_type VARCHAR(50) NOT NULL,
        details_json LONGTEXT NULL,
        notes TEXT NULL,
        status ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
        approved_by INT UNSIGNED NULL,
        output_file VARCHAR(255) NULL,
        output_blob LONGBLOB NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_member (member_id),
        INDEX idx_status (status),
        INDEX idx_is_sahakari (is_sahakari)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if (!$conn->query($createTableSql)) {
  error_log("Error creating cert_requests table: " . $conn->error);
}

// Ensure is_sahakari column exists
$resSahakari = $conn->query("SHOW COLUMNS FROM cert_requests LIKE 'is_sahakari'");
if ($resSahakari && $resSahakari->num_rows === 0) {
  $conn->query("ALTER TABLE cert_requests ADD is_sahakari TINYINT(1) NOT NULL DEFAULT 0 AFTER member_id");
  $conn->query("ALTER TABLE cert_requests ADD INDEX idx_is_sahakari (is_sahakari)");
}

/* --- Handle reject action (GET) --- */
if (isset($_GET['reject_id']) && ctype_digit($_GET['reject_id'])) {
  $reject_id = (int) $_GET['reject_id'];

  // Check if it's a regular member request
  $sql = "
        UPDATE cert_requests cr
        INNER JOIN members m ON cr.member_id = m.id
        SET cr.status = 'rejected',
            cr.approved_by = ?,
            cr.updated_at = NOW()
        WHERE cr.id = ?
          AND m.mahal_id = ?
          AND cr.status = 'pending'
          AND (cr.is_sahakari = 0 OR cr.is_sahakari IS NULL)
    ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('iii', $mahal_id, $reject_id, $mahal_id);
  $stmt->execute();
  $affected = $stmt->affected_rows;
  $stmt->close();

  // If no regular member request was updated, try Sahakari
  if ($affected === 0) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'sahakari_members'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
      $sqlSahakari = "
                UPDATE cert_requests cr
                INNER JOIN sahakari_members sm ON cr.member_id = sm.id
                SET cr.status = 'rejected',
                    cr.approved_by = ?,
                    cr.updated_at = NOW()
                WHERE cr.id = ?
                  AND sm.mahal_id = ?
                  AND cr.status = 'pending'
                  AND cr.is_sahakari = 1
            ";
      $stmtSahakari = $conn->prepare($sqlSahakari);
      $stmtSahakari->bind_param('iii', $mahal_id, $reject_id, $mahal_id);
      $stmtSahakari->execute();
      $stmtSahakari->close();
    }
  }

  header("Location: certificate.php");
  exit();
}

/* --- Handle delete action for rejected/completed (GET) --- */
if (isset($_GET['delete_id']) && ctype_digit($_GET['delete_id'])) {
  $delete_id = (int) $_GET['delete_id'];

  // Try deleting regular member request
  $sql = "
        DELETE cr
        FROM cert_requests cr
        INNER JOIN members m ON cr.member_id = m.id
        WHERE cr.id = ?
          AND m.mahal_id = ?
          AND cr.status IN ('rejected','completed')
          AND (cr.is_sahakari = 0 OR cr.is_sahakari IS NULL)
    ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('ii', $delete_id, $mahal_id);
  $stmt->execute();
  $affected = $stmt->affected_rows;
  $stmt->close();

  // If no regular member request was deleted, try Sahakari
  if ($affected === 0) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'sahakari_members'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
      $sqlSahakari = "
                DELETE cr
                FROM cert_requests cr
                INNER JOIN sahakari_members sm ON cr.member_id = sm.id
                WHERE cr.id = ?
                  AND sm.mahal_id = ?
                  AND cr.status IN ('rejected','completed')
                  AND cr.is_sahakari = 1
            ";
      $stmtSahakari = $conn->prepare($sqlSahakari);
      $stmtSahakari->bind_param('ii', $delete_id, $mahal_id);
      $stmtSahakari->execute();
      $stmtSahakari->close();
    }
  }

  header("Location: certificate.php");
  exit();
}

/* --- Fetch ALL requests for members of this Mahal (both regular and Sahakari) --- */
$requests = [];

// First, fetch regular member requests
$sql = "
    SELECT
        cr.id,
        cr.member_id,
        cr.is_sahakari,
        cr.certificate_type,
        cr.details_json,
        cr.notes,
        cr.status,
        cr.created_at,
        m.head_name,
        m.member_number,
        'regular' as member_type
    FROM cert_requests cr
    INNER JOIN members m ON cr.member_id = m.id
    WHERE m.mahal_id = ?
      AND (cr.is_sahakari = 0 OR cr.is_sahakari IS NULL)
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $mahal_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
  $requests[] = $row;
}
$stmt->close();

// Then, fetch Sahakari member requests (if table exists)
$tableCheck = $conn->query("SHOW TABLES LIKE 'sahakari_members'");
if ($tableCheck && $tableCheck->num_rows > 0) {
  $sqlSahakari = "
        SELECT
            cr.id,
            cr.member_id,
            cr.is_sahakari,
            cr.certificate_type,
            cr.details_json,
            cr.notes,
            cr.status,
            cr.created_at,
            sm.head_name,
            sm.member_number,
            'sahakari' as member_type
        FROM cert_requests cr
        INNER JOIN sahakari_members sm ON cr.member_id = sm.id
        WHERE sm.mahal_id = ?
          AND cr.is_sahakari = 1
    ";

  $stmtSahakari = $conn->prepare($sqlSahakari);
  $stmtSahakari->bind_param('i', $mahal_id);
  $stmtSahakari->execute();
  $resSahakari = $stmtSahakari->get_result();
  while ($row = $resSahakari->fetch_assoc()) {
    $requests[] = $row;
  }
  $stmtSahakari->close();
}

// Sort all requests by status (pending first) and then by created_at (newest first)
usort($requests, function ($a, $b) {
  $statusOrder = ['pending' => 0, 'approved' => 1, 'completed' => 2, 'rejected' => 3];
  $aStatus = $statusOrder[strtolower($a['status'])] ?? 99;
  $bStatus = $statusOrder[strtolower($b['status'])] ?? 99;

  if ($aStatus !== $bStatus) {
    return $aStatus - $bStatus;
  }

  return strtotime($b['created_at']) - strtotime($a['created_at']);
});

$conn->close();

$logo_path = "logo.jpeg";

/* --- Stats --- */
$totalRequests = count($requests);
$pendingCount = 0;
$approvedCount = 0;
$rejectedCount = 0;
$completedCount = 0;

foreach ($requests as $r) {
  $s = strtolower((string) $r['status']);
  if ($s === 'pending')
    $pendingCount++;
  if ($s === 'approved')
    $approvedCount++;
  if ($s === 'rejected')
    $rejectedCount++;
  if ($s === 'completed')
    $completedCount++;
}

function h($s)
{
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1" />
  <title>Certificate Management - <?php echo h($mahal['name']); ?></title>
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
      --info: #06b6d4;
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
      flex-shrink: 0;
    }

    .floating-menu-btn:hover {
      background: var(--card-alt);
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
      gap: 6px;
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
    }

    .btn.green:hover {
      transform: translateY(-2px);
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

    .stats-bar {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
      margin-bottom: 24px;
    }

    .stat-card {
      background: var(--card);
      border-radius: var(--radius-sm);
      padding: 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border: 1px solid var(--border);
    }

    .stat-label {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      color: var(--text-light);
      font-weight: 600;
    }

    .stat-count {
      font-size: 20px;
      font-weight: 700;
    }

    .stat-pending .stat-count {
      color: var(--warning);
    }

    .stat-approved .stat-count {
      color: var(--success);
    }

    .stat-rejected .stat-count {
      color: var(--error);
    }

    .stat-completed .stat-count {
      color: var(--primary);
    }

    .filter-section {
      background: var(--card);
      padding: 20px;
      border-radius: var(--radius);
      border: 1px solid var(--border);
      margin-bottom: 24px;
    }

    .filter-row {
      display: grid;
      grid-template-columns: 1fr auto auto auto;
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
    }

    .filter-row input[type="search"]:focus {
      outline: none;
      border-color: var(--primary);
    }

    .filter-row select {
      padding: 12px 16px;
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      font-size: 14px;
      transition: var(--transition);
      cursor: pointer;
    }

    .filter-row select:focus {
      outline: none;
      border-color: var(--primary);
    }

    .btn.white {
      background: var(--card);
      border: 1px solid var(--border);
      color: var(--text);
    }

    .btn.white:hover {
      background: var(--card-alt);
    }

    .table-container {
      background: var(--card);
      border-radius: var(--radius);
      border: 1px solid var(--border);
      overflow: hidden;
    }

    .table-scroll {
      overflow-x: auto;
    }

    .table-scroll table {
      width: 100%;
      border-collapse: collapse;
      min-width: 1200px;
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

    .status-pending {
      background: linear-gradient(135deg, var(--warning), #d97706);
    }

    .status-approved {
      background: linear-gradient(135deg, var(--success), #059669);
    }

    .status-rejected {
      background: linear-gradient(135deg, var(--error), #dc2626);
    }

    .status-completed {
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    }

    .badge-type {
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.3px;
      background: #eef2ff;
      color: #3730a3;
      border: 1px solid #c7d2fe;
    }

    .member-type-badge {
      padding: 3px 8px;
      border-radius: 999px;
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.3px;
      background: linear-gradient(135deg, #cffafe, #a5f3fc);
      color: #0e7490;
      border: 1px solid var(--info);
      margin-left: 6px;
      display: inline-block;
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

    .icon-btn {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      color: #fff;
      font-size: 14px;
      cursor: pointer;
      transition: 0.2s;
      border: none;
    }

    .icon-btn i {
      pointer-events: none;
    }

    .issue-icon {
      background: #10b981;
    }

    .reject-icon {
      background: #ef4444;
    }

    .delete-icon {
      background: #6b7280;
    }

    .download-icon {
      background: #3b82f6;
    }

    .edit-icon {
      background: #8b5cf6;
    }

    .icon-btn:hover {
      transform: translateY(-2px);
      opacity: 0.85;
    }

    .row-pending {
      background: linear-gradient(to right, #fffbeb, #fff7ed);
    }

    .row-pending:hover {
      background: linear-gradient(to right, #fef3c7, #ffedd5);
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
        grid-template-columns: 1fr auto auto auto;
      }

      .stats-bar {
        grid-template-columns: repeat(2, minmax(0, 1fr));
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

      .table-scroll table {
        min-width: 1000px;
      }

      .stats-bar {
        grid-template-columns: 1fr;
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
        <div class="profile" onclick="window.location.href='dashboard.php'">
          <div class="profile-avatar">
            <img src="<?php echo h($logo_path); ?>" alt="<?php echo h($mahal['name']); ?> Logo"
              onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
            <i class="fas fa-mosque" style="display: none;"></i>
          </div>
          <div class="name"><?php echo h($mahal['name']); ?></div>
          <div class="role">Administrator</div>
        </div>

        <nav class="menu">
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
          <button class="menu-btn active" type="button">
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

    <main class="main" id="main">
      <section class="top-row">
        <button class="floating-menu-btn" id="menuToggle" type="button">
          <i class="fas fa-bars"></i>
        </button>
        <div class="page-title">
          <i class="fas fa-certificate"></i>
          Certificate Management
        </div>
      </section>

      <div class="container">
        <div class="page-header">
          <div class="actions"></div>
          <div class="stats">
            <i class="fas fa-certificate"></i>
            Total Requests: <span id="count"><?php echo (int) $totalRequests; ?></span>
          </div>
        </div>

        <div class="stats-bar">
          <div class="stat-card stat-pending">
            <div class="stat-label">Pending</div>
            <div class="stat-count"><?php echo (int) $pendingCount; ?></div>
          </div>
          <div class="stat-card stat-approved">
            <div class="stat-label">Approved</div>
            <div class="stat-count"><?php echo (int) $approvedCount; ?></div>
          </div>
          <div class="stat-card stat-rejected">
            <div class="stat-label">Rejected</div>
            <div class="stat-count"><?php echo (int) $rejectedCount; ?></div>
          </div>
          <div class="stat-card stat-completed">
            <div class="stat-label">Completed</div>
            <div class="stat-count"><?php echo (int) $completedCount; ?></div>
          </div>
        </div>

        <div class="filter-section">
          <div class="filter-row">
            <input type="search" id="search" placeholder="Search by member name, certificate type, status..."
              autocomplete="on">

            <select id="status" aria-label="Certificate status filter">
              <option value="all">All Status</option>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
              <option value="completed">Completed</option>
            </select>

            <select id="type" aria-label="Certificate type filter">
              <option value="all">All Types</option>
              <option value="marriage">Marriage</option>
              <option value="termination">Termination</option>
              <option value="caste">Caste</option>
            </select>

            <button class="btn white" id="clear" type="button">
              <i class="fas fa-times"></i>
              Clear Filters
            </button>
          </div>
        </div>

        <div class="table-container">
          <div class="table-scroll">
            <table id="table">
              <thead>
                <tr>
                  <th scope="col">ID</th>
                  <th scope="col">Member</th>
                  <th scope="col">Type</th>
                  <th scope="col">Details</th>
                  <th scope="col">Notes</th>
                  <th scope="col">Status</th>
                  <th scope="col">Requested At</th>
                  <th scope="col">Actions</th>
                </tr>
              </thead>
              <tbody id="requestsTableBody">
                <?php if (!empty($requests)): ?>
                  <?php foreach ($requests as $r): ?>
                    <?php
                    $type = strtolower((string) $r['certificate_type']);
                    $status = strtolower((string) $r['status']);
                    $isSahakari = isset($r['member_type']) && $r['member_type'] === 'sahakari';

                    $details = [];
                    if (!empty($r['details_json'])) {
                      $decoded = json_decode($r['details_json'], true);
                      if (is_array($decoded)) {
                        $details = $decoded;
                      }
                    }

                    $detailText = '';
                    if ($type === 'marriage' || $type === 'marriage_consent_letter') {
                      $md = $details['marriage_date'] ?? '';
                      $reg = $details['reg_number'] ?? '';
                      if ($md !== '') {
                        $detailText .= 'Date: ' . h($md);
                      }
                      if ($reg !== '') {
                        $detailText .= ($detailText ? ' · ' : '') . 'Reg No: ' . h($reg);
                      }
                    } elseif ($type === 'caste') {
                      $detailText = 'Caste certificate request';
                    } elseif ($type === 'termination') {
                      $reg = $details['reg_number'] ?? '';
                      if ($reg !== '') {
                        $detailText = 'Reg No: ' . h($reg);
                      } else {
                        $detailText = 'Termination request';
                      }
                    }
                    if ($detailText === '') {
                      $detailText = '<span class="null-text">—</span>';
                    }

                    $statusClass = 'status-pending';
                    if ($status === 'approved')
                      $statusClass = 'status-approved';
                    if ($status === 'rejected')
                      $statusClass = 'status-rejected';
                    if ($status === 'completed')
                      $statusClass = 'status-completed';

                    if ($type === 'marriage') {
                      $issueUrl = 'marriage.php?request_id=' . (int) $r['id'];
                    } elseif ($type === 'marriage_consent_letter') {
                      $issueUrl = 'marriage_consent.php?request_id=' . (int) $r['id'];
                    } elseif ($type === 'termination') {
                      $issueUrl = 'termination.php?request_id=' . (int) $r['id'];
                    } elseif ($type === 'caste') {
                      $issueUrl = 'caste.php?request_id=' . (int) $r['id'];
                    } else {
                      $issueUrl = '#';
                    }

                    $rowClass = ($status === 'pending') ? 'row-pending' : '';
                    ?>
                    <tr class="<?php echo $rowClass; ?>">
                      <td>#<?php echo (int) $r['id']; ?></td>
                      <td>
                        <div style="font-weight: 600;">
                          <?php echo h($r['head_name'] ?? 'Member'); ?>
                          <?php if ($isSahakari): ?>
                            <span class="member-type-badge">
                              <i class="fas fa-handshake"></i> Sahakari
                            </span>
                          <?php endif; ?>
                        </div>
                        <?php if (!empty($r['member_number'])): ?>
                          <div style="font-size:11px; color: var(--text-light);">ID: <?php echo h($r['member_number']); ?>
                          </div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="badge-type"><?php echo strtoupper(h($type)); ?></span>
                      </td>
                      <td><?php echo $detailText; ?></td>
                      <td>
                        <?php if (!empty($r['notes'])): ?>
                          <?php echo nl2br(h($r['notes'])); ?>
                        <?php else: ?>
                          <span class="null-text">—</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="status-badge <?php echo $statusClass; ?>">
                          <?php echo strtoupper(h($status)); ?>
                        </span>
                      </td>
                      <td><?php echo h($r['created_at']); ?></td>
                      <td>
                        <div class="actions">
                          <?php if ($status === 'pending'): ?>
                            <?php if ($issueUrl !== '#'): ?>
                              <a href="<?php echo h($issueUrl); ?>" class="icon-btn issue-icon" title="Issue Certificate">
                                <i class="fas fa-check"></i>
                              </a>
                            <?php endif; ?>

                            <a href="certificate.php?reject_id=<?php echo (int) $r['id']; ?>" class="icon-btn reject-icon"
                              title="Reject Request" onclick="return confirm('Reject this request?');">
                              <i class="fas fa-times"></i>
                            </a>

                          <?php elseif ($status === 'rejected'): ?>

                            <a href="certificate.php?delete_id=<?php echo (int) $r['id']; ?>" class="icon-btn delete-icon"
                              title="Delete Permanently"
                              onclick="return confirm('Permanently delete this rejected request?');">
                              <i class="fas fa-trash"></i>
                            </a>

                          <?php elseif ($status === 'completed'): ?>

                            <a href="admin_download_certificate.php?id=<?php echo (int) $r['id']; ?>"
                              class="icon-btn download-icon" title="Download Certificate">
                              <i class="fas fa-download"></i>
                            </a>

                            <?php if ($issueUrl !== '#'): ?>
                              <a href="<?php echo h($issueUrl); ?>" class="icon-btn edit-icon" title="Regenerate Certificate">
                                <i class="fas fa-pen"></i>
                              </a>
                            <?php endif; ?>

                            <a href="certificate.php?delete_id=<?php echo (int) $r['id']; ?>" class="icon-btn delete-icon"
                              title="Delete Permanently"
                              onclick="return confirm('Permanently delete this completed request?');">
                              <i class="fas fa-trash"></i>
                            </a>

                          <?php else: ?>
                            <span class="null-text" style="font-size:11px;">—</span>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="8" class="no-data">
                      No certificate requests found yet.
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="footer">Last updated: <?php echo date('M d, Y h:i A'); ?></div>
      </div>
    </main>
  </div>

  <script>
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggle = document.getElementById('menuToggle');
    const closeBtn = document.getElementById('sidebarClose');

    function openSidebar() {
      sidebar.classList.add('open');
      overlay.classList.add('show');
      overlay.hidden = false;
      document.body.classList.add('no-scroll');
    }

    function closeSidebar() {
      sidebar.classList.remove('open');
      overlay.classList.remove('show');
      document.body.classList.remove('no-scroll');
      setTimeout(() => { overlay.hidden = true; }, 200);
    }

    toggle.addEventListener('click', openSidebar);
    closeBtn.addEventListener('click', closeSidebar);
    overlay.addEventListener('click', closeSidebar);

    document.querySelectorAll('.menu .menu-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        if (window.matchMedia('(max-width: 1023.98px)').matches) closeSidebar();
      });
    });

    const search = document.getElementById('search');
    const statusFilter = document.getElementById('status');
    const typeFilter = document.getElementById('type');
    const clearBtn = document.getElementById('clear');

    function filterRequests() {
      const searchTerm = search.value.toLowerCase();
      const statusValue = statusFilter.value;
      const typeValue = typeFilter.value;

      const rows = document.querySelectorAll('#requestsTableBody tr');
      let visibleCount = 0;

      rows.forEach(row => {
        if (row.cells.length < 8) return;

        const memberName = row.cells[1].textContent.toLowerCase();
        const certificateType = row.cells[2].textContent.toLowerCase();
        const status = row.cells[5].textContent.toLowerCase();
        const details = row.cells[3].textContent.toLowerCase();
        const notes = row.cells[4].textContent.toLowerCase();

        const matchesSearch = searchTerm === '' ||
          memberName.includes(searchTerm) ||
          certificateType.includes(searchTerm) ||
          status.includes(searchTerm) ||
          details.includes(searchTerm) ||
          notes.includes(searchTerm);

        const matchesStatus = statusValue === 'all' || status.includes(statusValue);
        const matchesType = typeValue === 'all' || certificateType.includes(typeValue);

        if (matchesSearch && matchesStatus && matchesType) {
          row.style.display = '';
          visibleCount++;
        } else {
          row.style.display = 'none';
        }
      });

      document.getElementById('count').textContent = visibleCount;
    }

    search.addEventListener('input', filterRequests);
    statusFilter.addEventListener('change', filterRequests);
    typeFilter.addEventListener('change', filterRequests);
    clearBtn.addEventListener('click', () => {
      search.value = '';
      statusFilter.value = 'all';
      typeFilter.value = 'all';
      filterRequests();
    });

    document.addEventListener('DOMContentLoaded', filterRequests);
  </script>
</body>

</html>