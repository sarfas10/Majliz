<?php
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

// Fetch logged-in mahal details
$user_id = $_SESSION['user_id'];
$sql = "SELECT name, address, registration_no, email FROM register WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
  $mahal = $result->fetch_assoc();
} else {
  echo "<script>alert('Unable to fetch mahal details. Please log in again.'); window.location.href='index.php';</script>";
  exit();
}

$stmt->close();
$conn->close();

// Define logo path - adjust this path according to your server setup
$logo_path = "logo.jpeg";
// Alternative paths you might need to try:
// $logo_path = "./logo.jpeg";
// $logo_path = "assets/logo.jpeg";
// $logo_path = "/majliz/logo.jpeg";
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?php echo htmlspecialchars($mahal['name']); ?> - Dashboard</title>
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Amiri:wght@400;700&display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

  <style>
    /* Your existing CSS styles remain the same */
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

    /* Top Row - FIXED LAYOUT */
    .top-row {
      display: flex;
      gap: 24px;
      padding: 24px;
      align-items: flex-start;
      flex-wrap: wrap;
    }

    .main-left {
      flex: 1;
      min-width: 300px;
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

    .logo-row img {
      width: 64px;
      height: 64px;
      object-fit: contain;
      border-radius: var(--radius-sm);
      box-shadow: var(--shadow);
      border: 2px solid var(--border);
      background: white;
      padding: 4px;
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

    /* Adhan Row - SMALLER CARDS */
    .adhan-row {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-bottom: 20px;
    }

    .adhan-card {
      flex: 1;
      min-width: 110px;
      max-width: 170px;
      height: 90px;
      border-radius: var(--radius-sm);
      background: var(--card);
      padding: 12px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      box-shadow: var(--shadow);
      transition: var(--transition);
      border: 1px solid var(--border);
      position: relative;
      overflow: hidden;
    }

    .adhan-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 3px;
      height: 100%;
      background: linear-gradient(135deg, var(--primary), var(--secondary));
    }

    .adhan-card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-lg);
    }

    .adhan-card .top {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-bottom: 2px;
    }

    .adhan-card .prayer-name {
      font-weight: 600;
      font-size: 12px;
      color: var(--text-light);
    }

    .adhan-card .prayer-time {
      font-size: 16px;
      font-weight: 700;
      color: var(--text);
      margin-top: auto;
    }

    .adhan-card.next {
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
      box-shadow: 0 6px 20px rgba(74, 111, 165, 0.3);
      transform: scale(1.03);
    }

    .adhan-card.next::before {
      background: linear-gradient(135deg, var(--secondary), var(--accent));
    }

    .adhan-card.next .prayer-name,
    .adhan-card.next .prayer-time {
      color: white;
    }

    /* Stats Grid - SMALLER CARDS */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 14px;
      margin-bottom: 12px;
    }

    @media (max-width: 1100px) {
      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
      }
    }

    @media (max-width: 640px) {
      .stats-grid {
        grid-template-columns: 1fr;
      }
    }

    .stat-card {
      background: var(--card);
      border-radius: var(--radius-sm);
      padding: 16px;
      min-height: 120px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      box-shadow: var(--shadow);
      transition: var(--transition);
      border: 1px solid var(--border);
      position: relative;
      overflow: hidden;
    }

    .stat-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 3px;
      height: 100%;
      background: linear-gradient(135deg, var(--primary), var(--secondary));
    }

    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-lg);
    }

    .stat-card .stat-header {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      margin-bottom: 8px;
    }

    .stat-card .stat-icon {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      color: white;
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      box-shadow: 0 3px 8px rgba(74, 111, 165, 0.3);
    }

    .stat-card .stat-title {
      font-size: 13px;
      font-weight: 600;
      color: var(--text-light);
      flex: 1;
    }

    .stat-card .stat-value {
      font-size: 24px;
      font-weight: 800;
      color: var(--text);
      line-height: 1;
      margin-bottom: 2px;
    }

    .stat-card .stat-label {
      font-size: 11px;
      color: var(--text-lighter);
      margin-top: 2px;
    }

    /* Calendar Panel - ADJUSTED POSITION */
    .calendar-panel {
      width: 360px;
      min-width: 320px;
      position: sticky;
      top: 50px;
      /* Lowered from 24px */
      align-self: flex-start;
      margin-top: 20px;
      /* Added margin to push it down */
    }

    .calendar-white {
      background: var(--card);
      border-radius: var(--radius);
      padding: 18px;
      display: flex;
      flex-direction: column;
      box-shadow: var(--shadow);
      border: 1px solid var(--border);
      max-height: 480px;
      overflow-y: auto;
    }

    .calendar-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 14px;
      position: sticky;
      top: 0;
      background: var(--card);
      padding: 6px 0;
      z-index: 1;
    }

    .icon-btn {
      background: var(--card-alt);
      border: 1px solid var(--border);
      color: var(--primary);
      cursor: pointer;
      width: 30px;
      height: 30px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: var(--transition);
      font-size: 14px;
    }

    .icon-btn:hover {
      background: var(--primary);
      color: white;
      transform: scale(1.05);
    }

    .calendar-title {
      font-weight: 700;
      color: var(--text);
      font-size: 15px;
      text-align: center;
    }

    .hijri-days {
      display: flex;
      gap: 4px;
      justify-content: space-between;
      margin-bottom: 10px;
      font-size: 11px;
      color: var(--text-light);
    }

    .hijri-days div {
      width: 34px;
      text-align: center;
    }

    .calendar-grid {
      display: grid;
      grid-template-columns: repeat(7, 34px);
      gap: 5px;
      margin-bottom: 14px;
    }

    .day-cell {
      width: 34px;
      height: 34px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      background: transparent;
      color: var(--text);
      font-size: 13px;
      font-weight: 500;
      transition: var(--transition);
      cursor: pointer;
    }

    .day-cell:hover {
      background: var(--card-alt);
      transform: scale(1.05);
    }

    .day-cell.today {
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: white;
      box-shadow: 0 3px 8px rgba(74, 111, 165, 0.3);
      transform: scale(1.05);
    }

    .day-cell .dot {
      width: 4px;
      height: 4px;
      border-radius: 50%;
      position: absolute;
      bottom: 3px;
      left: 50%;
      transform: translateX(-50%);
      background: var(--accent);
    }

    /* Important dates - SCROLLABLE */
    .important-dates {
      margin-top: 14px;
      max-height: 180px;
      overflow-y: auto;
      padding-right: 6px;
    }

    .important-dates::-webkit-scrollbar {
      width: 3px;
    }

    .important-dates::-webkit-scrollbar-track {
      background: var(--card-alt);
      border-radius: 2px;
    }

    .important-dates::-webkit-scrollbar-thumb {
      background: var(--primary-light);
      border-radius: 2px;
    }

    .important-dates .event {
      background: var(--card-alt);
      padding: 10px;
      border-radius: var(--radius-sm);
      margin-bottom: 6px;
      font-size: 12px;
      color: var(--text);
      border-left: 3px solid var(--secondary);
      transition: var(--transition);
    }

    .important-dates .event:hover {
      background: var(--card);
      transform: translateX(3px);
    }

    .event-date {
      font-weight: 600;
      color: var(--primary);
      margin-bottom: 3px;
      display: flex;
      align-items: center;
      gap: 5px;
      font-size: 11px;
    }

    .event-date i {
      font-size: 10px;
    }

    .event-title {
      color: var(--text-light);
      line-height: 1.3;
      font-size: 11px;
    }

    /* News Section - IMPROVED LAYOUT */
    .news-section {
      padding: 24px;
      border-top: 1px solid var(--border);
      background: var(--card-alt);
      margin-top: auto;
    }

    .news-inner {
      max-width: 100%;
      margin: 0 auto;
    }

    .news-heading {
      font-size: 18px;
      font-weight: 700;
      margin-bottom: 18px;
      color: var(--text);
      display: flex;
      align-items: center;
      gap: 10px;
      padding-bottom: 10px;
      border-bottom: 2px solid var(--border);
    }

    .news-heading::before {
      content: '📢';
      font-size: 16px;
    }

    .news-list {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 16px;
    }

    .news-card {
      background: var(--card);
      padding: 16px;
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      transition: var(--transition);
      border: 1px solid var(--border);
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .news-card:hover {
      transform: translateY(-3px);
      box-shadow: var(--shadow-lg);
    }

    .news-card img {
      width: 100%;
      height: 140px;
      object-fit: cover;
      border-radius: var(--radius-sm);
      margin-bottom: 0;
    }

    .news-content {
      flex: 1;
    }

    .news-title {
      font-weight: 600;
      color: var(--text);
      margin-bottom: 6px;
      font-size: 15px;
      line-height: 1.3;
    }

    .news-excerpt {
      color: var(--text-light);
      font-size: 13px;
      line-height: 1.4;
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
      .calendar-panel {
        width: 100%;
        min-width: auto;
        position: static;
        margin-top: 0;
        top: auto;
      }

      .top-row {
        flex-direction: column;
      }

      .main {
        margin-left: 0;
      }

      .main-left {
        padding-right: 0;
      }

      .adhan-row {
        gap: 8px;
      }

      .adhan-card {
        min-width: calc(50% - 4px);
        max-width: calc(50% - 4px);
      }

      .calendar-white {
        max-height: none;
      }
    }

    @media (max-width: 768px) {
      .top-row {
        padding: 16px;
      }

      .logo-row {
        padding: 16px;
      }

      .name-ar {
        font-size: 20px;
      }

      .stats-grid {
        grid-template-columns: 1fr;
        gap: 12px;
      }

      .news-list {
        grid-template-columns: 1fr;
      }

      .adhan-card {
        min-width: calc(33.333% - 6px);
        max-width: calc(33.333% - 6px);
      }
    }

    @media (max-width: 480px) {
      .adhan-card {
        min-width: calc(50% - 4px);
        max-width: calc(50% - 4px);
      }

      .logo-row {
        flex-direction: column;
        text-align: center;
        gap: 12px;
      }

      .logo-container {
        flex-direction: column;
        text-align: center;
      }

      .name-ar {
        font-size: 18px;
      }

      .news-section {
        padding: 16px;
      }

      .adhan-card {
        height: 85px;
        padding: 10px;
      }

      .adhan-card .prayer-time {
        font-size: 15px;
      }
    }

    @media (max-width: 360px) {
      .adhan-card {
        min-width: 100%;
        max-width: 100%;
      }
    }
  </style>
</head>

<body>
  <div class="sidebar-overlay" id="sidebarOverlay" hidden></div>

  <div id="app">
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
          <button class="menu-btn active" type="button">
            <i class="fas fa-tachometer-alt"></i>
            <span>Admin Panel</span>
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

          <button class="menu-btn" type="button" onclick="window.location.href='academics.php'">
            <i class="fas fa-graduation-cap"></i>
            <span>Academics</span>
          </button>

          <button class="menu-btn" id="certificate-manage-btn" type="button">
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
        <div class="main-left">
          <!-- Enhanced Logo Row -->
          <div class="logo-row">
            <button class="floating-menu-btn" id="menuToggle" aria-controls="sidebar" aria-expanded="false"
              aria-label="Open menu" type="button">
              <i class="fas fa-bars"></i>
            </button>
            <div class="logo-container">
              <!-- Updated logo source to use the local file -->

              <div class="name-container">
                <div class="name-ar"><?php echo htmlspecialchars($mahal['name']); ?></div>
                <div class="name-subtitle">
                  <i class="fas fa-map-marker-alt"></i>
                  <?php echo htmlspecialchars($mahal['address'] ?? 'Registered Mosque'); ?>
                </div>
              </div>
            </div>
          </div>

          <div id="adhan-row" class="adhan-row">
            <!-- Prayer times will be populated by JavaScript -->
          </div>

          <div id="stats-grid" class="stats-grid">
            <!-- Stats will be populated by JavaScript -->
          </div>
        </div>

        <!-- Improved Calendar Panel -->
        <aside class="calendar-panel">
          <div class="calendar-white">
            <div class="calendar-top">
              <button id="prev-month" class="icon-btn" type="button" aria-label="Previous month">‹</button>
              <div id="calendar-title" class="calendar-title">Loading...</div>
              <button id="next-month" class="icon-btn" type="button" aria-label="Next month">›</button>
            </div>
            <div id="hijri-days" class="hijri-days"></div>
            <div id="calendar-grid" class="calendar-grid"></div>
            <div id="important-dates" class="important-dates">
              <!-- Important dates will be populated here -->
            </div>
          </div>
        </aside>
      </section>

      <section class="news-section">
        <div class="news-inner">
          <h2 class="news-heading">Latest News & Announcements</h2>
          <div id="news-list" class="news-list">
            <!-- News will be populated by JavaScript -->
          </div>
        </div>
      </section>
    </main>
  </div>

  <script src="assets/dashboard.js"></script>
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

    // Navigation handlers
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
      btn.addEventListener('click', function () {
        if (!this.hasAttribute('onclick')) {
          document.querySelectorAll('.menu-btn').forEach(b => b.classList.remove('active'));
          this.classList.add('active');
        }
      });
    });

    // Calendar functionality with sample data
    document.addEventListener('DOMContentLoaded', function () {
      // Sample calendar implementation
      const calendarTitle = document.getElementById('calendar-title');
      const calendarGrid = document.getElementById('calendar-grid');
      const hijriDays = document.getElementById('hijri-days');
      const importantDates = document.getElementById('important-dates');

      // Sample Hijri days
      const hijriDayNames = ['Ith', 'Thl', 'Arb', 'Kha', 'Jum', 'Sab', 'Ahd'];
      hijriDays.innerHTML = hijriDayNames.map(day => `<div>${day}</div>`).join('');

      // Sample calendar dates
      const today = new Date();
      const currentMonth = today.toLocaleString('default', { month: 'long', year: 'numeric' });
      calendarTitle.textContent = currentMonth;

      // Generate sample calendar (42 cells for 6 weeks)
      let calendarHTML = '';
      for (let i = 1; i <= 42; i++) {
        const day = i % 31 || 31;
        const isToday = i === today.getDate();
        calendarHTML += `
          <div class="day-cell ${isToday ? 'today' : ''}">
            ${day}
            ${i % 7 === 0 ? '<div class="dot"></div>' : ''}
          </div>
        `;
      }
      calendarGrid.innerHTML = calendarHTML;

      // Sample important dates
      const sampleEvents = [
        { date: '15th March', title: 'Community Iftar Program' },
        { date: '20th March', title: 'Quran Competition Registration Deadline' },
        { date: '25th March', title: 'Friday Special Lecture' },
        { date: '28th March', title: 'Charity Fund Collection' }
      ];

      importantDates.innerHTML = sampleEvents.map(event => `
        <div class="event">
          <div class="event-date">
            <i class="fas fa-calendar-alt"></i>
            ${event.date}
          </div>
          <div class="event-title">${event.title}</div>
        </div>
      `).join('');

      // Sample news
      const newsList = document.getElementById('news-list');
      const sampleNews = [
        {
          title: 'Ramadan Preparation Meeting',
          excerpt: 'Important meeting for all committee members to discuss Ramadan arrangements and programs.',
          image: 'assets/news1.jpg'
        },
        {
          title: 'New Educational Programs',
          excerpt: 'We are launching new Islamic studies courses for children and adults starting next month.',
          image: 'assets/news2.jpg'
        },
        {
          title: 'Community Cleanup Drive',
          excerpt: 'Join us this weekend for a mosque and neighborhood cleanup initiative.',
          image: 'assets/news3.jpg'
        }
      ];

      newsList.innerHTML = sampleNews.map(news => `
        <div class="news-card">
          <img src="${news.image}" alt="${news.title}" onerror="this.style.display='none'">
          <div class="news-content">
            <h3 class="news-title">${news.title}</h3>
            <p class="news-excerpt">${news.excerpt}</p>
          </div>
        </div>
      `).join('');
    });

    // Month navigation
    document.getElementById('prev-month').addEventListener('click', function () {
      // Add month navigation logic here
      console.log('Previous month clicked');
    });

    document.getElementById('next-month').addEventListener('click', function () {
      // Add month navigation logic here
      console.log('Next month clicked');
    });

    history.replaceState(null, null, location.href);
  </script>
</body>

</html>