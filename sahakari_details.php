<?php
// sahakari_details.php
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

/* --- CSRF token (for delete/transfer) --- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Include database connection
require_once 'db_connection.php';

// Helper functions for status display
function getStatusIcon($status) { return ''; }

function getStatusText($status) {
    switch (strtolower((string)$status)) {
        case 'active': return 'Alive';
        case 'death': return 'Deceased';
        case 'freeze': return 'Frozen';
        case 'terminate': return 'Terminated';
        default: return 'Active';
    }
}

function getStatusColor($status) {
    switch (strtolower((string)$status)) {
        case 'active': return '#10b981';
        case 'death': return '#6b7280';
        case 'freeze': return '#f59e0b';
        case 'terminate': return '#ef4444';
        default: return '#10b981';
    }
}

// Get member ID from URL parameter
$member_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($member_id <= 0) {
    header("Location: sahakari_management.php");
    exit();
}

// Fetch member data
$member = null;
$family_members = [];
$transactions = [];
$financial_stats = [
    'total_donations' => 0,
    'monthly_dues_paid' => 0,
    'total_transactions' => 0,
    'last_transaction' => null,
    'recent_transactions' => []
];

/** docs containers **/
$head_documents = [];
$family_docs_by_member = [];
$total_doc_count = 0;

// Fetch logged-in mahal details for sidebar
$mahal_name = "Mahal Management";
$logo_path = "logo.jpeg";
try {
    $db_result = get_db_connection();
    if (!isset($db_result['error'])) {
        $conn = $db_result['conn'];
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
        $conn->close();
    }
} catch (Exception $e) {
    // If error, use default values
}

try {
    $db_result = get_db_connection();
    if (isset($db_result['error'])) {
        throw new Exception("Database connection failed: " . $db_result['error']);
    }
    $conn = $db_result['conn'];

    // Fetch main sahakari member details
    $stmt = $conn->prepare("
        SELECT * FROM sahakari_members 
        WHERE id = ? AND mahal_id = ?
    ");
    $stmt->bind_param("ii", $member_id, $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $member = $result->fetch_assoc();
    $stmt->close();

    if (!$member) {
        throw new Exception("Sahakari member not found or access denied.");
    }

    // Fetch sahakari family members
    $stmt = $conn->prepare("
        SELECT * FROM sahakari_family_members 
        WHERE member_id = ? 
        ORDER BY 
            CASE relationship 
                WHEN 'Spouse' THEN 1
                WHEN 'Son' THEN 2
                WHEN 'Daughter' THEN 3
                WHEN 'Father' THEN 4
                WHEN 'Mother' THEN 5
                WHEN 'Brother' THEN 6
                WHEN 'Sister' THEN 7
                ELSE 8
            END,
            name ASC
    ");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $family_members = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /** fetch identity documents **/

    // Head documents
    $stmt = $conn->prepare("
        SELECT id, doc_type, doc_number, name_on_doc, issued_by, issued_on, expiry_on, notes, file_path
        FROM sahakari_member_documents
        WHERE member_id = ? AND owner_type = 'head' AND (family_member_id IS NULL)
        ORDER BY doc_type ASC, id ASC
    ");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $head_documents = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $total_doc_count += count($head_documents);

    // Family documents (group by family member)
    $stmt = $conn->prepare("
        SELECT md.id, md.doc_type, md.doc_number, md.name_on_doc, md.issued_by, md.issued_on, md.expiry_on, md.notes, md.file_path,
               fm.id AS family_id, fm.name AS family_name
        FROM sahakari_member_documents md
        INNER JOIN sahakari_family_members fm ON fm.id = md.family_member_id
        WHERE md.member_id = ? AND md.owner_type = 'family' AND md.family_member_id IS NOT NULL
        ORDER BY fm.name ASC, md.doc_type ASC, md.id ASC
    ");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $fid = (int)$row['family_id'];
        if (!isset($family_docs_by_member[$fid])) {
            $family_docs_by_member[$fid] = ['name' => $row['family_name'], 'docs' => []];
        }
        $family_docs_by_member[$fid]['docs'][] = [
            'id'          => $row['id'],
            'doc_type'    => $row['doc_type'],
            'doc_number'  => $row['doc_number'],
            'name_on_doc' => $row['name_on_doc'],
            'issued_by'   => $row['issued_by'],
            'issued_on'   => $row['issued_on'],
            'expiry_on'   => $row['expiry_on'],
            'notes'       => $row['notes'],
            'file_path'   => $row['file_path'],
        ];
        $total_doc_count++;
    }
    $stmt->close();

    // Transactions by this head (from regular transactions table, linked by donor_member_id)
    $stmt = $conn->prepare("
        SELECT t.*, m.head_name, mh.name as mahal_name
        FROM transactions t
        JOIN register mh ON t.user_id = mh.id
        LEFT JOIN sahakari_members m ON t.donor_member_id = m.id
        WHERE t.donor_member_id = ?
        ORDER BY t.transaction_date DESC, t.created_at DESC
        LIMIT 100
    ");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $all_transactions = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!empty($all_transactions)) {
        $financial_stats['total_transactions'] = count($all_transactions);
        $financial_stats['last_transaction'] = $all_transactions[0]['transaction_date'];
        $financial_stats['recent_transactions'] = array_slice($all_transactions, 0, 10);
        
        foreach ($all_transactions as $transaction) {
            if ($transaction['type'] === 'INCOME') {
                $financial_stats['total_donations'] += $transaction['amount'];
                if (strtoupper($transaction['category']) === 'MONTHLY FEE') {
                    $financial_stats['monthly_dues_paid'] += $transaction['amount'];
                }
            }
        }
    }

    // Check monthly status
    $current_month = date('Y-m');
    $stmt = $conn->prepare("
        SELECT COUNT(*) as paid_this_month 
        FROM transactions 
        WHERE donor_member_id = ? 
        AND type = 'INCOME' 
        AND category = 'MONTHLY FEE' 
        AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
    ");
    $stmt->bind_param("is", $member_id, $current_month);
    $stmt->execute();
    $result = $stmt->get_result();
    $monthly_status = $result->fetch_assoc();
    $stmt->close();

    $financial_stats['monthly_status'] = $monthly_status['paid_this_month'] > 0 ? 'cleared' : 'due';

    // Include monthly_fee_adv for display
    $financial_stats['monthly_fee_adv'] = isset($member['monthly_fee_adv']) ? (float)$member['monthly_fee_adv'] : 0.00;

    $conn->close();

} catch (Exception $e) {
    error_log("Error fetching sahakari member details: " . $e->getMessage());
    $error = $e->getMessage();
}

// MEMBER NUMBER (display) and monthly donation STATUS (word)
$member_number = isset($member['member_number']) ? trim((string)$member['member_number']) : '';

// Normalize monthly donation status from sahakari_members.monthly_donation_due
$raw_monthly_due = null;
if (is_array($member) && array_key_exists('monthly_donation_due', $member)) {
    $raw_monthly_due = trim((string)$member['monthly_donation_due']);
}

$raw_lower = strtolower((string)$raw_monthly_due);
if ($raw_lower === '' || in_array($raw_lower, ['due','pending','0','false'], true)) {
    $monthly_status_label = 'Due';
} elseif (in_array($raw_lower, ['cleared','paid','1','true'], true)) {
    $monthly_status_label = 'Cleared';
} else {
    $monthly_status_label = $raw_monthly_due !== '' ? ucwords($raw_monthly_due) : 'Due';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- key for mobile responsiveness -->
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?php echo htmlspecialchars($member['head_name'] ?? 'Member'); ?> - Sahakari Member Details | Mahal Management</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Dashboard CSS styles (from edit_member.php) */
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

        /* Original sahakari_details.php styles (keeping all of them) */
        :root {
            --primary-form: #059669; /* Green instead of blue */
            --primary-dark-form: #047857; /* Darker green */
            --primary-light-form: #d1fae5; /* Light green */
            --secondary-form: #64748b;
            --success-form: #10b981;
            --success-light-form: #d1fae5;
            --warning-form: #f59e0b;
            --warning-light-form: #fef3c7;
            --danger-form: #ef4444;
            --danger-light-form: #fee2e2;
            --info-form: #0d9488; /* Teal instead of cyan */
            --light-form: #f8fafc;
            --dark-form: #1e293b;
            --border-form: #e2e8f0;
            --border-light-form: #f1f5f9;
            --text-primary-form: #1e293b;
            --text-secondary-form: #64748b;
            --text-light-form: #94a3b8;
            --shadow-form: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md-form: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg-form: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --radius-form: 8px;
            --radius-lg-form: 12px;
            --tap-form: 44px;
        }
        
        .app-container { 
            min-height: 100vh; 
            background: #ffffff; 
            width: 100%;
        }
        
        /* Header */
        .header {
            background: #ffffff;
            border-bottom: 1px solid var(--border-form);
            padding: 1.25rem 2rem;
            position: sticky; 
            top: 0; 
            z-index: 100; 
            backdrop-filter: blur(8px);
        }
        .header-content { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            max-width: 1400px; 
            margin: 0 auto; 
        }
        .page-title { 
            display: flex; 
            align-items: center; 
            gap: 1rem; 
        }
        .page-title h1 { 
            font-size: 1.5rem; 
            font-weight: 700; 
            color: var(--text-primary-form); 
        }
        .breadcrumb { 
            font-size: 0.875rem; 
            color: var(--text-secondary-form); 
            display: flex; 
            align-items: center; 
            gap: 0.5rem; 
        }
        .breadcrumb a { 
            color: var(--primary-form); 
            text-decoration: none; 
        }
        .breadcrumb a:hover { 
            text-decoration: underline; 
        }
        .header-actions { 
            display: flex; 
            align-items: center; 
            gap: 0.75rem; 
        }

        /* Main Content */
        .main-content { 
            max-width: 1100px; 
            margin: 0 auto; 
            padding: 1.5rem; 
        }

        /* Wallet-like Member Header */
        .member-header {
            background: linear-gradient(135deg, var(--primary-form) 0%, var(--primary-dark-form) 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 16px;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-lg-form);
            position: relative; 
            overflow: hidden;
        }
        .member-header::before {
            content: ''; 
            position: absolute; 
            top: 0; 
            right: 0; 
            width: 200px; 
            height: 200px;
            background: rgba(255, 255, 255, 0.1); 
            border-radius: 50%; 
            transform: translate(30%, -30%);
        }
        .member-header-content { 
            display: flex; 
            justify-content: space-between; 
            align-items: flex-start; 
            position: relative; 
            z-index: 2; 
            gap: 1rem; 
        }

        .member-basic-info h1 { 
            font-size: 1.6rem; 
            font-weight: 700; 
            margin-bottom: 0.35rem; 
        }

        /* Green banner meta row (Member ID • Joined • Documents) */
        .member-meta {
            display:flex;
            align-items:center;
            gap:2.25rem;
            font-size:0.95rem;
            color:#e8f7f0; /* Green tint */
            opacity:.98;
            flex-wrap:wrap;
        }
        .member-meta-item{
            display:inline-flex;
            align-items:center;
            gap:.5rem;
            white-space:nowrap;
            line-height:1;
        }
        .member-meta-item i{
            font-size:1rem;
            opacity:0.95;
        }

        .member-meta-old { display: none; }

        .member-meta-item-old { display: flex; align-items: center; gap: 0.5rem; }
        .status-section { text-align: right; }
        .status-card {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 14px;
            padding: 0.75rem 1rem; text-align: center; min-width: 150px; box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .status-label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.85; margin-bottom: 0.35rem; }
        .status-value { font-size: 1rem; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 0.5rem; }
        .status-cleared { color: #bbf7d0; } .status-due { color: #fef3c7; } .status-pending { color:#fef3c7; }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border: 1px solid;
            backdrop-filter: blur(8px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .status-active { background: rgba(16,185,129,.15); color:#065f46; border-color:#10b981; }
        .status-death { background: rgba(107,114,128,.15); color:#374151; border-color:#6b7280; }
        .status-freeze { background: rgba(245,158,11,.15); color:#92400e; border-color:#f59e0b; }
        .status-terminate { background: rgba(239,68,68,.15); color:#991b1b; border-color:#ef4444; }

        .member-header-row { display:flex; justify-content:space-between; align-items:flex-start; gap:.75rem; margin-bottom:.35rem; flex-wrap:wrap; }
        .member-basic-info .status-badge { font-size: .75rem; padding: .3rem .6rem; }

        /* Layout */
        .content-grid { display: grid; grid-template-columns: 1fr 360px; gap: 1.25rem; }
        .content-main { display: flex; flex-direction: column; gap: 1.25rem; }
        .content-sidebar { display: flex; flex-direction: column; gap: 1.25rem; }

        /* Cards */
        .card { background: #ffffff; border: 1px solid var(--border-form); border-radius: 12px; box-shadow: var(--shadow-form); transition: all 0.2s ease; }
        .card:hover { box-shadow: var(--shadow-md-form); }
        .card-header { padding: 1.25rem 1.25rem 0; margin-bottom: 0.5rem; }
        .card-title { font-size: 1.05rem; font-weight: 600; color: var(--text-primary-form); display: flex; align-items: center; gap: 0.6rem; }
        .card-body { padding: 0 1.25rem 1.25rem; }

        /* Financial Stats */
        .financial-stats {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem;
            margin-bottom: 1.25rem; padding: 1rem; background: var(--light-form); border-radius: 12px; border: 1px solid var(--border-light-form);
        }
        .stat-item { text-align: center; }
        .stat-amount { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.15rem; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
        .stat-item.donations .stat-amount { color: var(--success-form); }
        .stat-item.monthly .stat-amount { color: var(--info-form); }
        .stat-item.transactions .stat-amount { color: var(--primary-form); }
        .stat-item.advance .stat-amount { color: var(--warning-form); }
        .stat-label { font-size: 0.8rem; color: var(--text-secondary-form); text-transform: uppercase; font-weight: 600; letter-spacing: 0.4px; }

        /* Info Columns */
        .info-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; position: relative; }
        .column-divider { position: absolute; left: 50%; top: 0; bottom: 0; width: 1px; background: linear-gradient(to bottom, transparent, var(--border-form), transparent); transform: translateX(-50%); }
        .info-section { display: flex; flex-direction: column; gap: 0.75rem; }
        .section-title { font-size: 0.95rem; font-weight: 600; color: var(--text-primary-form); display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 2px solid var(--border-light-form); }

        .info-display { display: flex; flex-direction: column; gap: 0.5rem; }
        .info-row { display: flex; justify-content: space-between; align-items: center; padding: 0.6rem 0; border-bottom: 1px solid var(--border-light-form); gap: 0.75rem; }
        .info-row:last-child { border-bottom: none; }
        .info-label { font-weight: 500; color: var(--text-primary-form); display: flex; align-items: center; gap: 0.5rem; min-width: 120px; }
        .info-value { color: var(--text-secondary-form); font-weight: 500; text-align: right; word-break: break-word; }

        /* Address Section */
        .address-info { display: flex; flex-direction: column; gap: 0.5rem; }
        .address-row { display: flex; justify-content: space-between; align-items: flex-start; padding: 0.6rem 0; border-bottom: 1px solid var(--border-light-form); gap: 0.75rem; }
        .address-row:last-child { border-bottom: none; }
        .address-label { font-weight: 500; color: var(--text-primary-form); display: flex; align-items: flex-start; gap: 0.5rem; min-width: 120px; }
        .address-content { color: var(--text-secondary-form); line-height: 1.6; text-align: right; max-width: 260px; word-break: break-word; }

        /* Transactions */
        .transactions-section { margin-top: 0.5rem; }
        .transactions-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; padding: 0 0.25rem; }
        .transactions-title { font-size: 1rem; font-weight: 600; color: var(--text-primary-form); display: flex; align-items: center; gap: 0.5rem; }
        .transactions-count { background: var(--primary-form); color: white; padding: 0.2rem 0.6rem; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }

        .table-container { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .transactions-table { width: 100%; border-collapse: separate; border-spacing: 0; background: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: var(--shadow-form); }
        .transactions-table th {
            background: var(--light-form); padding: 0.85rem 1rem; text-align: left; font-weight: 600; font-size: 0.75rem; color: var(--text-secondary-form);
            text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--border-form);
        }
        .transactions-table td { padding: 0.9rem 1rem; border-bottom: 1px solid var(--border-light-form); font-size: 0.875rem; transition: background 0.2s ease; vertical-align: top; }
        .transactions-table tr:last-child td { border-bottom: none; }
        .transactions-table tr:hover td { background: var(--light-form); }
        .transaction-type { padding: 0.4rem 0.6rem; border-radius: 6px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; display: inline-block; min-width: 80px; text-align: center; }
        .type-income { background: var(--success-light-form); color: var(--success-form); border: 1px solid var(--success-form); }
        .type-expense { background: var(--danger-light-form); color: var(--danger-form); border: 1px solid var(--danger-form); }
        .transaction-amount { font-weight: 700; text-align: right; font-family: ui-monospace; font-size: 0.9rem; }
        .amount-income { color: var(--success-form); } .amount-expense { color: var(--danger-form); }
        .transaction-category { font-weight: 600; color: var(--text-primary-form); }
        .transaction-description { color: var(--text-secondary-form); font-size: 0.8rem; max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        /* Family Members */
        .family-grid { display: grid; gap: 0.75rem; }
        .family-member { 
            background: var(--light-form); 
            padding: 1rem; 
            border-radius: 10px; 
            border-left: 4px solid var(--primary-form); /* Green border */
            transition: transform 0.2s ease; 
        }
        .family-member:hover { transform: translateX(2px); }
        .member-name { font-weight: 700; color: var(--text-primary-form); margin-bottom: 0.35rem; display: flex; align-items: center; gap: 0.5rem; }
        .member-relationship { color: var(--primary-form); font-size: 0.72rem; text-transform: uppercase; font-weight: 800; letter-spacing: 0.5px; margin-bottom: 0.6rem; } 
        .member-details { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-size: 0.85rem; color: var(--text-secondary-form); }
        .member-detail { display: flex; align-items: center; gap: 0.5rem; }

        /* Documents list */
        .docs-list { display: grid; gap: 0.6rem; }
        .doc-item { display: grid; grid-template-columns: 1fr auto; gap: 0.4rem 0.8rem; padding: 0.75rem; border: 1px solid var(--border-light-form); border-radius: 10px; background: #fff; }
        .doc-left { display: flex; flex-direction: column; gap: 0.25rem; }
        .doc-topline { display: flex; flex-wrap: wrap; gap: 0.4rem 0.6rem; align-items: center; }
        .doc-type { 
            font-weight: 800; 
            text-transform: uppercase; 
            font-size: 0.72rem; 
            letter-spacing: 0.4px; 
            color: var(--primary-form); /* Green color */
            background: var(--primary-light-form); /* Light green background */
            padding: 0.15rem 0.5rem; 
            border-radius: 999px; 
        }
        .doc-number { font-family: ui-monospace; font-weight: 700; color: var(--dark-form); }
        .doc-meta { font-size: 0.8rem; color: var(--text-secondary-form); display: flex; gap: 0.75rem; flex-wrap: wrap; }
        .doc-notes { font-size: 0.8rem; color: var(--text-secondary-form); }
        .doc-actions { display: flex; align-items: center; gap: 0.6rem; }
        .doc-link { font-size: 0.8rem; color: var(--primary-form); text-decoration: none; border: 1px solid var(--primary-light-form); padding: 0.25rem 0.5rem; border-radius: 8px; }
        .doc-link:hover { background: var(--primary-light-form); }
        .doc-badge { display:inline-flex; align-items:center; gap:6px; padding:0.2rem 0.5rem; border-radius:999px; background: var(--light-form); border:1px solid var(--border-light-form); font-size:0.72rem; color: var(--text-secondary-form); }

        /* Buttons */
        .btn { padding: 0.75rem 1.2rem; border-radius: 10px; border: none; cursor: pointer; font-size: 0.9rem; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.2s ease; font-family: inherit; min-height: var(--tap-form); }
        .btn-primary { background: var(--primary-form); color: white; }
        .btn-primary:hover { background: var(--primary-dark-form); transform: translateY(-1px); box-shadow: var(--shadow-md-form); }
        .btn-secondary { background: var(--light-form); color: var(--text-primary-form); border: 1px solid var(--border-form); }
        .btn-secondary:hover { background: var(--border-light-form); transform: translateY(-1px); }
        .btn-danger { background: var(--danger-form); color: #fff; }
        .btn-danger:hover { background: #dc2626; transform: translateY(-1px); box-shadow: var(--shadow-md-form); }
        .btn-warning { background: var(--warning-form); color: white; }
        .btn-warning:hover { background: #d97706; transform: translateY(-1px); box-shadow: var(--shadow-md-form); }
        .action-btn { width: 100%; justify-content: center; text-align: center; }

        /* Empty States */
        .empty-state { text-align: center; padding: 2rem 1rem; color: var(--text-light-form); }
        .empty-state i { font-size: 2.2rem; margin-bottom: 0.5rem; opacity: 0.6; }

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            backdrop-filter: blur(4px);
        }
        .modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: var(--shadow-lg-form);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .modal-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-primary-form);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary-form);
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-primary-form);
        }
        .form-select, .form-input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-form);
            border-radius: 8px;
            font-size: 0.9rem;
            background: white;
            font-family: inherit;
        }
        .form-select:focus, .form-input:focus {
            outline: none;
            border-color: var(--primary-form);
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
        }
        .alert-warning {
            background: var(--warning-light-form);
            border: 1px solid var(--warning-form);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .alert-warning-content {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
        }
        .modal-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .content-grid { grid-template-columns: 1fr; }
            .content-sidebar { order: -1; }
            .info-columns { grid-template-columns: 1fr; }
            .column-divider { display: none; }
            .main-content { padding: 1rem; }
        }
        @media (max-width: 768px) {
            .header { padding: 0.75rem 1rem; }
            .header-content { flex-direction: column; gap: 0.5rem; align-items: stretch; }
            .main-content { padding: 0.75rem; }
            .member-header { border-radius: 0; margin: -0.75rem -0.75rem 1rem; }
            .member-header-content { flex-direction: column; gap: 0.75rem; }
            .status-section { text-align: left; }
            .member-basic-info h1 { font-size: 1.35rem; }
            .member-meta { gap: 1.25rem; font-size: 0.9rem; }
            .financial-stats { grid-template-columns: 1fr; gap: 0.75rem; padding: 0.75rem; }
            .member-details { grid-template-columns: 1fr; }
            .address-content { text-align: left; max-width: 100%; }
            .transactions-title { align-items: center; }
            .family-member { padding: 0.9rem; }
            .member-details { grid-template-columns: 1fr; }
            .member-header-row { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
            .status-badge { font-size: 0.75rem; padding: 0.3rem 0.6rem; }
            .modal-content {
                padding: 1.5rem;
                margin: 1rem;
                width: calc(100% - 2rem);
            }
            .modal-actions {
                flex-direction: column;
            }
        }
        @media (max-width: 600px) {
            .transactions-table thead { display: none; }
            .transactions-table, .transactions-table tbody, .transactions-table tr, .transactions-table td { display: block; width: 100%; }
            .transactions-table tr { background: #fff; margin: 0 0 0.75rem; border: 1px solid var(--border-light-form); border-radius: 10px; box-shadow: var(--shadow-form); overflow: hidden; }
            .transactions-table td { border: none; border-bottom: 1px solid var(--border-light-form); position: relative; padding: 0.8rem 0.75rem 0.8rem 7.5rem; }
            .transactions-table td:last-child { border-bottom: none; }
            .transactions-table td::before {
                content: attr(data-label);
                position: absolute; left: 0.75rem; top: 0.8rem;
                width: 6.4rem; font-weight: 700; font-size: 0.8rem; color: var(--text-primary-form);
                text-transform: uppercase; letter-spacing: 0.4px;
            }
            .transaction-amount { text-align: left; }
            .transaction-description { white-space: normal; max-width: none; }
            .table-container { overflow: visible; }
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

                    <button class="menu-btn active" id="member-manage-btn" type="button">
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
            <!-- Original sahakari_details.php content -->
            <div class="app-container">
                <!-- Header -->
                <div class="top-row">
                    <button class="floating-menu-btn" id="menuToggle" aria-controls="sidebar" aria-expanded="false" aria-label="Open menu" type="button">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="app-title"><?php echo htmlspecialchars($member['head_name'] ?? 'Member'); ?> - Sahakari Member Details</div>
                    <a href="member-management.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Members
                    </a>
                </div>

                <!-- Main Content -->
                <div class="main-content">
                    <!-- Member Header -->
                    <div class="member-header">
                        <div class="member-header-content">
                            <div class="member-basic-info">
                                <div style="display:flex; align-items:center; gap:.6rem; margin-bottom:.5rem; flex-wrap:wrap;">
                                    <h1><?php echo htmlspecialchars($member['head_name']); ?></h1>
                                </div>

                                <!-- Father's name (if available) -->
                                <?php if (!empty($member['father_name'])): ?>
                                    <div style="color: rgba(232,240,255,0.95); margin-top:6px; font-weight:500;">
                                        S/O: <?php echo htmlspecialchars($member['father_name']); ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Meta row: Member ID • Joined • Documents -->
                                <div class="member-meta" style="margin-top:10px;">
                                    <div class="member-meta-item" title="Sahakari Member ID">
                                      <i class="fas fa-id-card"></i>
                                      <span>
                                        Sahakari ID:
                                        <?php
                                          if (!empty($member_number)) {
                                              echo htmlspecialchars($member_number);
                                          } else {
                                              echo 'SM' . str_pad((int)$member['id'], 3, '0', STR_PAD_LEFT);
                                          }
                                        ?>
                                      </span>
                                    </div>

                                    <?php if (!empty($member['join_date'])): ?>
                                      <div class="member-meta-item" title="Joined">
                                        <i class="fas fa-calendar-plus"></i>
                                        <span>Joined: <?php echo date('M j, Y', strtotime($member['join_date'])); ?></span>
                                      </div>
                                    <?php endif; ?>

                                    <div class="member-meta-item" title="Total documents for this household">
                                      <i class="fas fa-file"></i>
                                      <span>Documents: <?php echo (int)$total_doc_count; ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Monthly donation status -->
                            <div class="status-card" aria-live="polite" title="Monthly donation status (from sahakari_members.monthly_donation_due)">
                                <div class="status-label">Monthly Donation</div>
                                <div style="margin-top:6px; text-align:center;">
                                    <div style="font-weight:700; font-size:1.05rem;">
                                        <?php echo htmlspecialchars($monthly_status_label ?? 'Due'); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Content Grid -->
                    <div class="content-grid">
                        <!-- Main Content -->
                        <div class="content-main">
                            <!-- Financial Overview -->
                            <div class="card">
                                <div class="card-header">
                                    <h2 class="card-title">
                                        <i class="fas fa-chart-line"></i>
                                        Financial Overview
                                    </h2>
                                </div>
                                <div class="card-body">
                                    <div class="financial-stats">
                                        <div class="stat-item donations">
                                            <div class="stat-amount">₹<?php echo number_format($financial_stats['total_donations'], 2); ?></div>
                                            <div class="stat-label">Total Income</div>
                                        </div>
                                        <div class="stat-item monthly">
                                            <div class="stat-amount">₹<?php echo number_format((float)($member['total_due'] ?? 0), 2); ?></div>
                                            <div class="stat-label">Total Due</div>
                                        </div>
                                        <div class="stat-item advance">
                                            <div class="stat-amount">₹<?php echo number_format($financial_stats['monthly_fee_adv'], 2); ?></div>
                                            <div class="stat-label">Monthly Fee Advance</div>
                                        </div>
                                        <div class="stat-item transactions">
                                            <div class="stat-amount"><?php echo $financial_stats['total_transactions']; ?></div>
                                            <div class="stat-label">Total Transactions</div>
                                        </div>
                                    </div>

                                    <!-- Two Column Layout with Divider -->
                                    <div class="info-columns">
                                        <div class="column-divider"></div>
                                        
                                        <!-- Personal Information Column -->
                                        <div class="info-section">
                                            <h3 class="section-title">
                                                <i class="fas fa-user-circle"></i>
                                                Personal Information
                                            </h3>
                                            <div class="info-display">
                                                <div class="info-row">
                                                    <span class="info-label">Status</span>
                                                    <span class="info-value">
                                                        <span class="status-badge status-<?php echo htmlspecialchars(strtolower($member['status'] ?? 'active')); ?>" style="font-size:0.75rem; padding:0.25rem 0.5rem;">
                                                            <?php echo getStatusText($member['status'] ?? 'active'); ?>
                                                        </span>
                                                    </span>
                                                </div>

                                                <!-- Father's name as its own row (if available) -->
                                                <?php if (!empty($member['father_name'])): ?>
                                                <div class="info-row">
                                                    <span class="info-label"><i class="fas fa-user"></i> Father's Name</span>
                                                    <span class="info-value"><?php echo htmlspecialchars($member['father_name']); ?></span>
                                                </div>
                                                <?php endif; ?>

                                                <div class="info-row">
                                                    <span class="info-label"><i class="fas fa-envelope"></i> Email</span>
                                                    <span class="info-value"><?php echo htmlspecialchars($member['email']); ?></span>
                                                </div>
                                                <div class="info-row">
                                                    <span class="info-label"><i class="fas fa-phone"></i> Phone</span>
                                                    <span class="info-value"><?php echo htmlspecialchars($member['phone']); ?></span>
                                                </div>
                                                <div class="info-row">
                                                    <span class="info-label"><i class="fas fa-birthday-cake"></i> Date of Birth</span>
                                                    <span class="info-value">
                                                        <?php echo $member['dob'] ? date('M j, Y', strtotime($member['dob'])) : 'Not specified'; ?>
                                                    </span>
                                                </div>
                                                <div class="info-row">
                                                    <span class="info-label"><i class="fas fa-venus-mars"></i> Gender</span>
                                                    <span class="info-value">
                                                        <?php echo $member['gender'] ? htmlspecialchars($member['gender']) : 'Not specified'; ?>
                                                    </span>
                                                </div>
                                                <div class="info-row">
                                                    <span class="info-label"><i class="fas fa-briefcase"></i> Occupation</span>
                                                    <span class="info-value">
                                                        <?php echo $member['occupation'] ? htmlspecialchars($member['occupation']) : 'Not specified'; ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Address & Membership Column -->
                                        <div class="info-section">
                                            <h3 class="section-title">
                                                <i class="fas fa-home"></i>
                                                Address & Membership
                                            </h3>
                                            <div class="address-info">
                                                <div class="address-row">
                                                    <span class="address-label"><i class="fas fa-map-marker-alt"></i> Address</span>
                                                    <span class="address-content"><?php echo nl2br(htmlspecialchars($member['address'])); ?></span>
                                                </div>
                                                <div class="address-row">
                                                    <span class="address-label"><i class="fas fa-calendar-plus"></i> Join Date</span>
                                                    <span class="address-content">
                                                        <?php echo date('M j, Y', strtotime($member['join_date'])); ?>
                                                    </span>
                                                </div>
                                                <div class="address-row">
                                                    <span class="address-label"><i class="fas fa-history"></i> Member Since</span>
                                                    <span class="address-content">
                                                        <?php
                                                        $join_date = new DateTime($member['join_date']);
                                                        $now = new DateTime();
                                                        $interval = $join_date->diff($now);
                                                        echo $interval->y . ' years, ' . $interval->m . ' months';
                                                        ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Recent Transactions -->
                            <div class="card">
                                <div class="card-body">
                                    <div class="transactions-section">
                                        <div class="transactions-header">
                                            <h3 class="transactions-title">
                                                <i class="fas fa-exchange-alt"></i>
                                                Recent Transactions
                                                <span class="transactions-count"><?php echo count($financial_stats['recent_transactions']); ?></span>
                                            </h3>
                                        </div>
                                        
                                        <?php if (!empty($financial_stats['recent_transactions'])): ?>
                                            <div class="table-container">
                                                <table class="transactions-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Date</th>
                                                            <th>Type</th>
                                                            <th>Category</th>
                                                            <th>Description</th>
                                                            <th>Amount</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($financial_stats['recent_transactions'] as $transaction): ?>
                                                            <tr>
                                                                <td data-label="Date" style="font-weight: 500;"><?php echo date('M j, Y', strtotime($transaction['transaction_date'])); ?></td>
                                                                <td data-label="Type">
                                                                    <span class="transaction-type type-<?php echo strtolower($transaction['type']); ?>">
                                                                        <?php echo htmlspecialchars($transaction['type']); ?>
                                                                    </span>
                                                                </td>
                                                                <td data-label="Category" class="transaction-category"><?php echo htmlspecialchars($transaction['category']); ?></td>
                                                                <td data-label="Description" class="transaction-description"><?php echo htmlspecialchars($transaction['description'] ?? '-'); ?></td>
                                                                <td data-label="Amount" class="transaction-amount amount-<?php echo strtolower($transaction['type']); ?>">
                                                                    ₹<?php echo number_format($transaction['amount'], 2); ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <div class="empty-state">
                                                <i class="fas fa-receipt"></i>
                                                <p>No transactions found for this sahakari member.</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Sidebar -->
                        <div class="content-sidebar">
                            <!-- Family Members -->
                            <div class="card">
                                <div class="card-header">
                                    <h2 class="card-title">
                                        <i class="fas fa-users"></i>
                                        Family Members
                                        <span style="background: var(--info-form); color: white; padding: 0.25rem 0.5rem; border-radius: 12px; font-size: 0.75rem; margin-left: 0.5rem;">
                                            <?php echo count($family_members); ?>
                                        </span>
                                    </h2>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($family_members)): ?>
                                        <div class="family-grid">
                                            <?php foreach ($family_members as $family_member): 
                                                $fid = (int)$family_member['id'];
                                                $docCount = isset($family_docs_by_member[$fid]) ? count($family_docs_by_member[$fid]['docs']) : 0;
                                                $familyStatus = $family_member['status'] ?? 'active';
                                            ?>
                                                <div class="family-member">
                                                    <div class="member-header-row">
                                                        <div class="member-name">
                                                            <i class="fas fa-user"></i>
                                                            <?php echo htmlspecialchars($family_member['name']); ?>
                                                            <?php if ($docCount > 0): ?>
                                                                <span class="doc-badge" title="Documents attached">
                                                                    <i class="fas fa-file"></i><?php echo $docCount; ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="status-badge status-<?php echo htmlspecialchars(strtolower($familyStatus)); ?>">
                                                            <?php echo getStatusText($familyStatus); ?>
                                                        </div>
                                                    </div>
                                                    <div class="member-relationship">
                                                        <?php echo htmlspecialchars($family_member['relationship']); ?>
                                                    </div>
                                                    <div class="member-details">
                                                        <?php if ($family_member['dob']): ?>
                                                            <div class="member-detail">
                                                                <i class="fas fa-calendar"></i>
                                                                <?php echo date('M j, Y', strtotime($family_member['dob'])); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($family_member['gender']): ?>
                                                            <div class="member-detail">
                                                                <i class="fas fa-venus-mars"></i>
                                                                <?php echo htmlspecialchars($family_member['gender']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($family_member['phone']): ?>
                                                            <div class="member-detail">
                                                                <i class="fas fa-phone"></i>
                                                                <?php echo htmlspecialchars($family_member['phone']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($family_member['email']): ?>
                                                            <div class="member-detail">
                                                                <i class="fas fa-envelope"></i>
                                                                <?php echo htmlspecialchars($family_member['email']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>

                                                    <?php if ($docCount > 0): ?>
                                                        <div class="docs-list" style="margin-top:0.6rem;">
                                                            <?php foreach ($family_docs_by_member[$fid]['docs'] as $doc): ?>
                                                                <div class="doc-item">
                                                                    <div class="doc-left">
                                                                        <div class="doc-topline">
                                                                            <span class="doc-type"><?php echo htmlspecialchars($doc['doc_type']); ?></span>
                                                                            <span class="doc-number"><?php echo htmlspecialchars(strtoupper($doc['doc_number'])); ?></span>
                                                                        </div>
                                                                        <div class="doc-meta">
                                                                            <?php if (!empty($doc['name_on_doc'])): ?>
                                                                                <span title="Name on Document"><i class="fas fa-id-card-clip"></i> <?php echo htmlspecialchars($doc['name_on_doc']); ?></span>
                                                                            <?php endif; ?>
                                                                            <?php if (!empty($doc['issued_by'])): ?>
                                                                                <span title="Issued By"><i class="fas fa-building"></i> <?php echo htmlspecialchars($doc['issued_by']); ?></span>
                                                                            <?php endif; ?>
                                                                            <?php if (!empty($doc['issued_on'])): ?>
                                                                                <span title="Issued On"><i class="fas fa-calendar-check"></i> <?php echo date('M j, Y', strtotime($doc['issued_on'])); ?></span>
                                                                            <?php endif; ?>
                                                                            <?php if (!empty($doc['expiry_on'])): ?>
                                                                                <span title="Expiry On"><i class="fas fa-calendar-xmark"></i> <?php echo date('M j, Y', strtotime($doc['expiry_on'])); ?></span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                        <?php if (!empty($doc['notes'])): ?>
                                                                            <div class="doc-notes"><i class="fas fa-note-sticky"></i> <?php echo htmlspecialchars($doc['notes']); ?></div>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <div class="doc-actions">
                                                                        <?php if (!empty($doc['file_path'])): ?>
                                                                            <a class="doc-link" href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" rel="noopener">
                                                                                <i class="fas fa-paperclip"></i> View
                                                                            </a>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="empty-state">
                                            <i class="fas fa-users-slash"></i>
                                            <p>No family members added yet.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Head Identity Documents -->
                            <div class="card">
                                <div class="card-header">
                                    <h2 class="card-title">
                                        <i class="fas fa-id-card"></i>
                                        Head Identity Documents
                                        <span style="background: var(--secondary-form); color: white; padding: 0.25rem 0.5rem; border-radius: 12px; font-size: 0.75rem; margin-left: 0.5rem;">
                                            <?php echo count($head_documents); ?>
                                        </span>
                                    </h2>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($head_documents)): ?>
                                        <div class="docs-list">
                                            <?php foreach ($head_documents as $doc): ?>
                                                <div class="doc-item">
                                                    <div class="doc-left">
                                                        <div class="doc-topline">
                                                            <span class="doc-type"><?php echo htmlspecialchars($doc['doc_type']); ?></span>
                                                            <span class="doc-number"><?php echo htmlspecialchars(strtoupper($doc['doc_number'])); ?></span>
                                                        </div>
                                                        <div class="doc-meta">
                                                            <?php if (!empty($doc['name_on_doc'])): ?>
                                                                <span title="Name on Document"><i class="fas fa-id-card-clip"></i> <?php echo htmlspecialchars($doc['name_on_doc']); ?></span>
                                                            <?php endif; ?>
                                                            <?php if (!empty($doc['issued_by'])): ?>
                                                                <span title="Issued By"><i class="fas fa-building"></i> <?php echo htmlspecialchars($doc['issued_by']); ?></span>
                                                            <?php endif; ?>
                                                            <?php if (!empty($doc['issued_on'])): ?>
                                                                <span title="Issued On"><i class="fas fa-calendar-check"></i> <?php echo date('M j, Y', strtotime($doc['issued_on'])); ?></span>
                                                            <?php endif; ?>
                                                            <?php if (!empty($doc['expiry_on'])): ?>
                                                                <span title="Expiry On"><i class="fas fa-calendar-xmark"></i> <?php echo date('M j, Y', strtotime($doc['expiry_on'])); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if (!empty($doc['notes'])): ?>
                                                            <div class="doc-notes"><i class="fas fa-note-sticky"></i> <?php echo htmlspecialchars($doc['notes']); ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="doc-actions">
                                                        <?php if (!empty($doc['file_path'])): ?>
                                                            <a class="doc-link" href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" rel="noopener">
                                                                <i class="fas fa-paperclip"></i> View
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="empty-state">
                                            <i class="fas fa-file-circle-xmark"></i>
                                            <p>No identity documents added for the head.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Quick Actions -->
                            <div class="card">
                                <div class="card-header">
                                    <h2 class="card-title">
                                        <i class="fas fa-bolt"></i>
                                        Quick Actions
                                    </h2>
                                </div>
                                <div class="card-body">
                                    <div class="actions-grid" style="display:grid; gap:0.75rem;">
                                        <a href="edit_sahakari.php?id=<?php echo (int)$member['id']; ?>" class="btn btn-primary action-btn">
                                            <i class="fas fa-edit"></i>
                                            Edit Sahakari Member Details
                                        </a>
                                        <a href="add_sahakari.php" class="btn btn-primary action-btn">
                                            <i class="fas fa-plus"></i>
                                            Add New Sahakari Member
                                        </a>
                                        
                                        <!-- Transfer Family Button -->
                                        <button type="button" onclick="openTransferModal()" class="btn btn-warning action-btn">
                                            <i class="fas fa-people-arrows"></i>
                                            Transfer Family to Another Member
                                        </button>

                                        <form id="deleteSahakariMemberForm" method="POST" action="delete_sahakari_member.php" onsubmit="return confirmDelete();" style="margin:0;">
                                            <input type="hidden" name="member_id" value="<?php echo (int)$member['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <button type="submit" class="btn btn-danger action-btn">
                                                <i class="fas fa-trash"></i>
                                                Delete Sahakari Member
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div> <!-- /sidebar -->
                    </div> <!-- /content-grid -->
                </div> <!-- /main-content -->
            </div>
        </main>
    </div>

    <!-- Transfer Family Modal -->
    <div id="transferModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-people-arrows" style="color:var(--primary-form);"></i>
                    Transfer Sahakari Member
                </h3>
                <button type="button" class="modal-close" onclick="closeTransferModal()">&times;</button>
            </div>
            
            <form id="transferForm" method="POST" action="transfer_sahakari_member.php">
                <input type="hidden" name="source_member_id" value="<?php echo (int)$member['id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                
                <div style="margin-bottom:1.5rem;">
                    <p style="color:var(--text-secondary-form); margin-bottom:1rem;">
                        You are transferring <strong><?php echo htmlspecialchars($member['head_name']); ?></strong> and their entire family to another sahakari member.
                    </p>
                    
                    <div class="alert-warning">
                        <div class="alert-warning-content">
                            <i class="fas fa-exclamation-triangle" style="color:var(--warning-form); margin-top:0.1rem;"></i>
                            <div>
                                <strong style="color:var(--warning-form);">Warning:</strong> This action cannot be undone. The source member will be deleted and all family members will be moved under the target member.
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-user-friends"></i>
                        Transfer To Sahakari Member
                    </label>
                    <select name="target_member_id" class="form-select" required>
                        <option value="">Select a sahakari member...</option>
                        <!-- Options will be populated by JavaScript -->
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-user-tag"></i>
                        Relationship in New Family
                    </label>
                    <select name="head_as_relationship" class="form-select" required>
                        <option value="Relative">Relative</option>
                        <option value="Brother">Brother</option>
                        <option value="Sister">Sister</option>
                        <option value="Son">Son</option>
                        <option value="Daughter">Daughter</option>
                        <option value="Father">Father</option>
                        <option value="Mother">Mother</option>
                        <option value="Uncle">Uncle</option>
                        <option value="Aunt">Aunt</option>
                        <option value="Cousin">Cousin</option>
                        <option value="Nephew">Nephew</option>
                        <option value="Niece">Niece</option>
                    </select>
                </div>
                
                <div class="modal-actions">
                    <button type="button" onclick="closeTransferModal()" class="btn btn-secondary">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-people-arrows"></i>
                        Transfer Family
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // Transfer Modal Functions
    function openTransferModal() {
        console.log('Opening transfer modal...');
        // Load available sahakari members for transfer
        loadTransferTargets();
        document.getElementById('transferModal').style.display = 'block';
    }

    function closeTransferModal() {
        document.getElementById('transferModal').style.display = 'none';
    }

    function loadTransferTargets() {
        const select = document.querySelector('#transferModal select[name="target_member_id"]');
        const currentMemberId = <?php echo (int)$member['id']; ?>;
        
        console.log('Loading transfer targets for member:', currentMemberId);
        console.log('Select element:', select);
        
        if (!select) {
            console.error('Target member select element not found!');
            return;
        }
        
        // Clear existing options except the first one
        while (select.options.length > 1) {
            select.remove(1);
        }
        
        // Show loading state
        const loadingOption = document.createElement('option');
        loadingOption.value = '';
        loadingOption.textContent = 'Loading sahakari members...';
        select.appendChild(loadingOption);
        select.disabled = true;
        
        // FIXED: Correct AJAX URL - use transfer_sahakari_member.php with ajax parameter
        fetch('transfer_sahakari_member.php?ajax=get_members&exclude=' + currentMemberId)
            .then(response => {
                console.log('Response status:', response.status);
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                console.log('Received data:', data);
                
                // Clear loading option and existing options
                select.innerHTML = '<option value="">Select a sahakari member...</option>';
                
                if (data.success && data.members && data.members.length > 0) {
                    data.members.forEach(member => {
                        const option = document.createElement('option');
                        option.value = member.id;
                        
                        let displayText = member.head_name;
                        if (member.member_number) {
                            displayText += ' (SM' + member.member_number + ')';
                        } else {
                            displayText += ' (ID:' + member.id + ')';
                        }
                        
                        // Add status if not active
                        if (member.status && member.status !== 'active') {
                            displayText += ' [' + member.status.charAt(0).toUpperCase() + member.status.slice(1) + ']';
                        }
                        
                        option.textContent = displayText;
                        select.appendChild(option);
                    });
                    select.disabled = false;
                    console.log('Successfully loaded ' + data.members.length + ' members');
                } else {
                    const errorOption = document.createElement('option');
                    errorOption.value = '';
                    if (data.error) {
                        errorOption.textContent = 'Error: ' + data.error;
                    } else {
                        errorOption.textContent = 'No other sahakari members found';
                    }
                    select.appendChild(errorOption);
                    console.warn('No members found in response:', data);
                }
            })
            .catch(error => {
                console.error('Error loading sahakari members:', error);
                select.innerHTML = '<option value="">Select a sahakari member...</option>';
                const errorOption = document.createElement('option');
                errorOption.value = '';
                errorOption.textContent = 'Error loading members: ' + error.message;
                select.appendChild(errorOption);
            });
    }

    // Close modal when clicking outside
    document.getElementById('transferModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeTransferModal();
        }
    });

    // Transfer form submission
    document.getElementById('transferForm').addEventListener('submit', function(e) {
        const targetMember = document.querySelector('#transferModal select[name="target_member_id"]').value;
        if (!targetMember) {
            e.preventDefault();
            alert('Please select a target sahakari member.');
            return;
        }
        
        if (!confirm('Are you sure you want to transfer this entire family? This action cannot be undone.')) {
            e.preventDefault();
            return;
        }
    });

    // Existing delete confirmation
    function confirmDelete() {
        const memberName = "<?php echo addslashes($member['head_name']); ?>";
        return confirm(
            `WARNING: This action cannot be undone!\n\n` +
            `You are about to delete the sahakari member: ${memberName}\n\n` +
            `This will permanently remove:\n` +
            `• The main member record\n` +
            `• All family members\n` +
            `• All identity documents\n\n` +
            `The member's past transactions will remain in the system but will no longer be linked to this member.\n\n` +
            `Are you absolutely sure you want to proceed?`
        );
    }

    // Debug: Check if modal elements exist on page load
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM loaded, checking modal elements...');
        const modal = document.getElementById('transferModal');
        const select = document.querySelector('#transferModal select[name="target_member_id"]');
        const button = document.querySelector('button[onclick="openTransferModal()"]');
        const form = document.getElementById('transferForm');
        
        console.log('Transfer modal element:', modal);
        console.log('Target member select element:', select);
        console.log('Transfer button:', button);
        console.log('Transfer form:', form);
        
        if (!modal) {
            console.error('Transfer modal not found! Check the HTML structure.');
        }
        if (!select) {
            console.error('Target member select not found! Check the HTML structure.');
        }
        if (!button) {
            console.error('Transfer button not found! Check the HTML structure.');
        }
        if (!form) {
            console.error('Transfer form not found! Check the HTML structure.');
        }
        
        // Test if button click works
        if (button) {
            button.addEventListener('click', function() {
                console.log('Transfer button clicked manually');
            });
        }
        
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
            btn.addEventListener('click', function() {
                if (!this.hasAttribute('onclick')) {
                    document.querySelectorAll('.menu-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                }
            });
        });
    });

    // Add keyboard event listener for Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeTransferModal();
        }
    });
    </script>
</body>
</html>