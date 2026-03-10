<?php
// member_dashboard.php
declare(strict_types=1);

/* --- secure session (must be first) --- */
require_once __DIR__ . '/session_bootstrap.php';

/* --- no-cache headers --- */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

/* --- auth gate: only logged-in members may access --- */
if (empty($_SESSION['member_login']) || $_SESSION['member_login'] !== true || empty($_SESSION['member'])) {
    header("Location: index.php");
    exit();
}

/* --- CSRF token (for request certificate) --- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* --- helpers (same semantics as user_details.php) --- */
function getStatusText($status)
{
    switch (strtolower((string) $status)) {
        case 'active':
            return 'Alive';
        case 'death':
            return 'Deceased';
        case 'freeze':
            return 'Frozen';
        case 'terminate':
            return 'Terminated';
        default:
            return 'Active';
    }
}

/* --- DB --- */
require_once __DIR__ . '/db_connection.php'; // must define get_db_connection()

// From member session
$memberSess = $_SESSION['member']; // ['member_type'=>'head'|'family'|'sahakari_head', 'member_id', 'mahal_id', 'name','phone', ...]

// Determine if this is a Sahakari member or regular member
$is_sahakari = (isset($memberSess['member_type']) && $memberSess['member_type'] === 'sahakari_head');

// For regular members, determine household_member_id
if (!$is_sahakari) {
    $household_member_id = (isset($memberSess['member_type']) && $memberSess['member_type'] === 'family' && !empty($memberSess['parent_member_id']))
        ? (int) $memberSess['parent_member_id']
        : (int) $memberSess['member_id'];
} else {
    // For Sahakari members, use their own member_id
    $household_member_id = (int) $memberSess['member_id'];
}

$member = null;
$family_members = [];
$financial_stats = [
    'total_donations' => 0,
    'monthly_dues_paid' => 0,
    'total_transactions' => 0,
    'last_transaction' => null,
    'recent_transactions' => []
];

$head_documents = [];
$family_docs_by_member = [];
$total_doc_count = 0;

try {
    $db_result = get_db_connection();
    if (isset($db_result['error'])) {
        throw new Exception("Database connection failed: " . $db_result['error']);
    }
    /** @var mysqli $conn */
    $conn = $db_result['conn'];

    if ($is_sahakari) {
        // ========== SAHAKARI MEMBER LOGIC ==========

        // Fetch Sahakari member details
        $stmt = $conn->prepare("SELECT * FROM sahakari_members WHERE id = ? AND mahal_id = ?");
        $stmt->bind_param("ii", $household_member_id, $memberSess['mahal_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $member = $result->fetch_assoc();
        $stmt->close();

        if (!$member) {
            throw new Exception("Sahakari member not found or access denied.");
        }

        // Fetch Sahakari family members (NO LOGIN ACCESS, just display)
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
        $stmt->bind_param("i", $household_member_id);
        $stmt->execute();
        $family_members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Sahakari Head documents
        $stmt = $conn->prepare("
            SELECT id, doc_type, doc_number, name_on_doc, issued_by, issued_on, expiry_on, notes, file_path
            FROM sahakari_member_documents
            WHERE member_id = ? AND owner_type = 'head' AND (family_member_id IS NULL)
            ORDER BY doc_type ASC, id ASC
        ");
        $stmt->bind_param("i", $household_member_id);
        $stmt->execute();
        $head_documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $total_doc_count += count($head_documents);

        // Sahakari Family documents
        $stmt = $conn->prepare("
            SELECT md.id, md.doc_type, md.doc_number, md.name_on_doc, md.issued_by, md.issued_on, md.expiry_on, md.notes, md.file_path,
                   fm.id AS family_id, fm.name AS family_name
            FROM sahakari_member_documents md
            INNER JOIN sahakari_family_members fm ON fm.id = md.family_member_id
            WHERE md.member_id = ? AND md.owner_type = 'family' AND md.family_member_id IS NOT NULL
            ORDER BY fm.name ASC, md.doc_type ASC, md.id ASC
        ");
        $stmt->bind_param("i", $household_member_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $fid = (int) $row['family_id'];
            if (!isset($family_docs_by_member[$fid])) {
                $family_docs_by_member[$fid] = ['name' => $row['family_name'], 'docs' => []];
            }
            $family_docs_by_member[$fid]['docs'][] = [
                'id' => $row['id'],
                'doc_type' => $row['doc_type'],
                'doc_number' => $row['doc_number'],
                'name_on_doc' => $row['name_on_doc'],
                'issued_by' => $row['issued_by'],
                'issued_on' => $row['issued_on'],
                'expiry_on' => $row['expiry_on'],
                'notes' => $row['notes'],
                'file_path' => $row['file_path'],
            ];
            $total_doc_count++;
        }
        $stmt->close();

        // Transactions for Sahakari household head (if any)
        // Note: Transactions might not be linked to sahakari_members yet, depending on your schema
        // For now, we'll leave this empty or you can add logic if transactions are linked
        $financial_stats['total_transactions'] = 0;
        $financial_stats['recent_transactions'] = [];

    } else {
        // ========== REGULAR MEMBER LOGIC ==========

        // Fetch main (head) member details for this household
        $stmt = $conn->prepare("SELECT * FROM members WHERE id = ? AND mahal_id = ?");
        $stmt->bind_param("ii", $household_member_id, $memberSess['mahal_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $member = $result->fetch_assoc();
        $stmt->close();

        if (!$member) {
            throw new Exception("Household not found or access denied.");
        }

        // Fetch family members under this head
        $stmt = $conn->prepare("
            SELECT * FROM family_members 
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
        $stmt->bind_param("i", $household_member_id);
        $stmt->execute();
        $family_members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Head documents
        $stmt = $conn->prepare("
            SELECT id, doc_type, doc_number, name_on_doc, issued_by, issued_on, expiry_on, notes, file_path
            FROM member_documents
            WHERE member_id = ? AND owner_type = 'head' AND (family_member_id IS NULL)
            ORDER BY doc_type ASC, id ASC
        ");
        $stmt->bind_param("i", $household_member_id);
        $stmt->execute();
        $head_documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $total_doc_count += count($head_documents);

        // Family documents
        $stmt = $conn->prepare("
            SELECT md.id, md.doc_type, md.doc_number, md.name_on_doc, md.issued_by, md.issued_on, md.expiry_on, md.notes, md.file_path,
                   fm.id AS family_id, fm.name AS family_name
            FROM member_documents md
            INNER JOIN family_members fm ON fm.id = md.family_member_id
            WHERE md.member_id = ? AND md.owner_type = 'family' AND md.family_member_id IS NOT NULL
            ORDER BY fm.name ASC, md.doc_type ASC, md.id ASC
        ");
        $stmt->bind_param("i", $household_member_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $fid = (int) $row['family_id'];
            if (!isset($family_docs_by_member[$fid])) {
                $family_docs_by_member[$fid] = ['name' => $row['family_name'], 'docs' => []];
            }
            $family_docs_by_member[$fid]['docs'][] = [
                'id' => $row['id'],
                'doc_type' => $row['doc_type'],
                'doc_number' => $row['doc_number'],
                'name_on_doc' => $row['name_on_doc'],
                'issued_by' => $row['issued_by'],
                'issued_on' => $row['issued_on'],
                'expiry_on' => $row['expiry_on'],
                'notes' => $row['notes'],
                'file_path' => $row['file_path'],
            ];
            $total_doc_count++;
        }
        $stmt->close();

        // Transactions for household head
        $stmt = $conn->prepare("
            SELECT t.*, m.head_name, mh.name as mahal_name
            FROM transactions t
            JOIN register mh ON t.user_id = mh.id
            LEFT JOIN members m ON t.donor_member_id = m.id
            WHERE t.donor_member_id = ?
            ORDER BY t.transaction_date DESC, t.created_at DESC
            LIMIT 100
        ");
        $stmt->bind_param("i", $household_member_id);
        $stmt->execute();
        $all_transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (!empty($all_transactions)) {
            $financial_stats['total_transactions'] = count($all_transactions);
            $financial_stats['last_transaction'] = $all_transactions[0]['transaction_date'];
            $financial_stats['recent_transactions'] = array_slice($all_transactions, 0, 10);

            foreach ($all_transactions as $t) {
                if ($t['type'] === 'INCOME') {
                    $financial_stats['total_donations'] += (float) $t['amount'];
                    if (strtoupper((string) $t['category']) === 'MONTHLY FEE') {
                        $financial_stats['monthly_dues_paid'] += (float) $t['amount'];
                    }
                }
            }
        }

        // Fetch custom due breakdown
        $custom_dues_breakdown = [];
        $cd_stmt = $conn->prepare("
            SELECT 
                ad.category_name, 
                SUM(mad.amount) as initial_due
            FROM member_additional_dues mad
            JOIN mahal_additional_dues ad ON mad.due_id = ad.id
            WHERE mad.member_id = ? AND mad.member_type = 'regular' AND ad.category_name IS NOT NULL AND TRIM(ad.category_name) != ''
            GROUP BY ad.category_name
        ");
        if ($cd_stmt) {
            $cd_stmt->bind_param("i", $household_member_id);
            $cd_stmt->execute();
            $cd_res = $cd_stmt->get_result();
            while ($row = $cd_res->fetch_assoc()) {
                $custom_dues_breakdown[strtoupper(trim($row['category_name']))] = [
                    'initial_due' => (float) $row['initial_due'],
                    'paid' => 0.0,
                    'remaining' => (float) $row['initial_due']
                ];
            }
            $cd_stmt->close();
        }

        // Now calculate paid amount per category from transactions
        $cd_pay_stmt = $conn->prepare("
            SELECT category, SUM(amount) as paid_amount
            FROM transactions
            WHERE donor_member_id = ? AND type = 'INCOME' AND user_id = ?
            GROUP BY category
        ");
        if ($cd_pay_stmt) {
            $cd_pay_stmt->bind_param("ii", $household_member_id, $memberSess['mahal_id']);
            $cd_pay_stmt->execute();
            $cd_pay_res = $cd_pay_stmt->get_result();
            while ($row = $cd_pay_res->fetch_assoc()) {
                $cat = strtoupper(trim($row['category']));
                if (isset($custom_dues_breakdown[$cat])) {
                    $paid = (float) $row['paid_amount'];
                    $custom_dues_breakdown[$cat]['paid'] = $paid;
                    $custom_dues_breakdown[$cat]['remaining'] = max(0, $custom_dues_breakdown[$cat]['initial_due'] - $paid);
                }
            }
            $cd_pay_stmt->close();
        }

        // Monthly status by current month payments
        $current_month = date('Y-m');
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS paid_this_month 
            FROM transactions 
            WHERE donor_member_id = ? 
              AND type = 'INCOME' 
              AND category = 'MONTHLY FEE' 
              AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
        ");
        $stmt->bind_param("is", $household_member_id, $current_month);
        $stmt->execute();
        $monthly_status = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Fetch Mahal Payment Details for "Pay Now" feature
        $payment_details = [];
        $pdStmt = $conn->prepare("SELECT * FROM mahal_payment_details WHERE mahal_id = ?");
        $pdStmt->bind_param("i", $memberSess['mahal_id']);
        $pdStmt->execute();
        $pdResult = $pdStmt->get_result();
        if ($pdResult->num_rows > 0) {
            $payment_details = $pdResult->fetch_assoc();
        }
        $pdStmt->close();
    }

    // Fetch member offerings (works for both sahakari and regular)
    $member_offerings = [];
    try {
        $off_mid = $household_member_id;
        $off_mahal = (int) $memberSess['mahal_id'];
        $off_stmt = $conn->prepare(
            "SELECT id, offering_type, offering_value, offering_date, status, notes
             FROM member_offerings
             WHERE mahal_id = ? AND member_id = ?
             ORDER BY offering_date DESC
             LIMIT 20"
        );
        if ($off_stmt) {
            $off_stmt->bind_param('ii', $off_mahal, $off_mid);
            $off_stmt->execute();
            $off_res = $off_stmt->get_result();
            while ($off_row = $off_res->fetch_assoc()) {
                $member_offerings[] = $off_row;
            }
            $off_stmt->close();
        }
    } catch (Throwable $ex) { /* table may not exist yet */
    }

    $conn->close();

} catch (Throwable $e) {
    error_log("Member dashboard error: " . $e->getMessage());
    http_response_code(500);
    echo "<h2 style='font-family: system-ui; padding:20px;'>An error occurred. Please try again later.</h2>";
    exit();
}

// Member number & monthly donation label (same normalization as user_details.php)
$member_number = isset($member['member_number']) ? trim((string) $member['member_number']) : '';

$raw_monthly_due = null;
if (is_array($member) && array_key_exists('monthly_donation_due', $member)) {
    $raw_monthly_due = trim((string) $member['monthly_donation_due']);
} elseif (is_array($member) && array_key_exists('monthy_donation_due', $member)) {
    $raw_monthly_due = trim((string) $member['monthy_donation_due']);
}
$raw_lower = strtolower((string) $raw_monthly_due);
if ($raw_lower === '' || in_array($raw_lower, ['due', 'pending', '0', 'false'], true)) {
    $monthly_status_label = 'Due';
} elseif (in_array($raw_lower, ['cleared', 'paid', '1', 'true'], true)) {
    $monthly_status_label = 'Cleared';
} else {
    $monthly_status_label = $raw_monthly_due !== '' ? ucwords($raw_monthly_due) : 'Due';
}

// Member type badge
$member_type_badge = $is_sahakari ? 'Sahakari' : 'Regular';
$member_type_color = $is_sahakari ? 'var(--info)' : 'var(--secondary)';

/* --- one-time warning from certificate request --- */
$member_warning = $_SESSION['member_warning'] ?? '';
if (!is_string($member_warning)) {
    $member_warning = '';
}
if ($member_warning !== '') {
    unset($_SESSION['member_warning']);
}

/* --- optional success message from cert requests --- */
$member_success = $_SESSION['member_success'] ?? '';
if (!is_string($member_success)) {
    $member_success = '';
}
if ($member_success !== '') {
    unset($_SESSION['member_success']);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?php echo htmlspecialchars($member['head_name'] ?? 'Member'); ?> - Member Dashboard</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #dbeafe;
            --secondary: #64748b;
            --success: #10b981;
            --success-light: #d1fae5;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --info: #06b6d4;
            --info-light: #cffafe;
            --light: #f8fafc;
            --dark: #1e293b;
            --border: #e2e8f0;
            --border-light: #f1f5f9;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-light: #94a3b8;
            --shadow: 0 1px 3px rgba(0, 0, 0, .1), 0 1px 2px rgba(0, 0, 0, .06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, .1), 0 2px 4px -1px rgba(0, 0, 0, .06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, .1), 0 4px 6px -2px rgba(0, 0, 0, .05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, .1), 0 10px 10px -5px rgba(0, 0, 0, .04);
            --radius: 16px;
            --radius-sm: 12px;
            --radius-lg: 20px;
            --banner-gradient: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary) 100%);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0
        }

        body {
            font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: var(--text-primary);
            line-height: 1.6;
            font-size: 14px;
            min-height: 100vh
        }

        /* ─────────────────────────────────────────────
       SIDEBAR • Enhanced Design - Matching Banner Color
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
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
        }

        .sidebar-close:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: rotate(90deg);
        }

        /* Update the member header to match (optional but recommended for consistency) */
        .member-header {
            background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary) 100%);
            color: #fff;
            padding: 2.5rem;
            border-radius: var(--radius-lg);
            margin-bottom: 2rem;
            box-shadow: var(--shadow-xl);
            position: relative;
            overflow: hidden;
        }

        /* Profile block */
        .profile {
            padding: 24px 0;
            text-align: center;
            margin-bottom: 24px;
            position: relative;
        }

        /* Decorative line above dashboard section */
        .profile::after {
            content: '';
            position: absolute;
            bottom: -12px;
            left: 20%;
            right: 20%;
            height: 2px;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
            border-radius: 2px;
        }

        .profile-avatar {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.2), rgba(255, 255, 255, 0.1));
            backdrop-filter: blur(10px);
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
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .profile .role {
            color: rgba(255, 255, 255, 0.8);
            font-size: 13px;
            font-weight: 500;
        }

        /* Navigation */
        .menu {
            padding: 16px 0 24px 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
            position: relative;
        }

        /* Decorative line above logout section */
        .menu::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 20%;
            right: 20%;
            height: 2px;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
            border-radius: 2px;
        }

        .menu-btn {
            appearance: none;
            background: transparent;
            border: none;
            color: rgba(255, 255, 255, 0.7);
            padding: 14px 16px;
            border-radius: var(--radius-sm);
            width: 100%;
            text-align: left;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            border: 1px solid transparent;
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
            padding-top: 32px;
            position: relative;
        }

        .logout-btn {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.9), rgba(220, 38, 38, 0.9));
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
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 16px rgba(239, 68, 68, 0.3);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
            background: linear-gradient(135deg, rgba(239, 68, 68, 1), rgba(220, 38, 38, 1));
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

        /* Main content area adjustments */
        .main-with-sidebar {
            margin-left: 0;
            min-height: 100vh;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            display: flex;
            flex-direction: column;
            width: 100%;
        }

        /* Floating menu button */
        .floating-menu-btn {
            background: #fff;
            border: 1px solid var(--border);
            color: var(--text-primary);
            padding: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: var(--shadow);
            flex-shrink: 0;
            z-index: 2;
        }

        .floating-menu-btn:hover {
            background: var(--light);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        /* No scroll class */
        body.no-scroll {
            overflow: hidden;
        }

        /* Header - Modified for sidebar */
        .header {
            background: #fff;
            border-bottom: 1px solid var(--border);
            padding: 1.25rem 2rem;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-md);
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            margin: 0 auto;
            flex: 1;
        }

        .breadcrumb {
            font-size: .875rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: .5rem;
            flex-wrap: wrap
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
            transition: color .2s
        }

        .breadcrumb a:hover {
            text-decoration: underline;
            color: var(--primary-dark)
        }

        /* Buttons */
        .btn {
            padding: .7rem 1.2rem;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: #fff;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            gap: .5rem;
            align-items: center;
            text-decoration: none;
            color: var(--text-primary);
            transition: all .3s;
            font-size: .875rem
        }

        .btn:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px)
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-color: var(--primary);
            color: #fff;
            box-shadow: var(--shadow)
        }

        .btn-primary:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px)
        }

        /* Main Container */
        .main-container {
            padding: 2rem;
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
        }

        .member-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255, 255, 255, .2), transparent);
            border-radius: 50%
        }

        .member-header::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -5%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255, 255, 255, .15), transparent);
            border-radius: 50%
        }

        .member-header-content {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            gap: 1.5rem;
            flex-wrap: wrap;
            align-items: center
        }

        .member-info h1 {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: .75rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, .1)
        }

        .member-meta {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
            color: rgba(255, 255, 255, .95);
            font-size: .95rem
        }

        .member-meta>div {
            display: flex;
            align-items: center;
            gap: .5rem;
            background: rgba(255, 255, 255, .1);
            padding: .5rem 1rem;
            border-radius: 10px;
            backdrop-filter: blur(10px)
        }

        .status-cards {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap
        }

        .status-card {
            background: rgba(255, 255, 255, .2);
            backdrop-filter: blur(6px);
            padding: 1rem;
            border-radius: 12px;
            color: #fff;
            flex: 1;
            min-width: 140px;
            border: 1px solid rgba(255, 255, 255, .1)
        }

        .status-card .label {
            font-size: .75rem;
            opacity: .9;
            margin-bottom: .25rem;
            text-transform: uppercase;
            letter-spacing: .5px
        }

        .status-card .value {
            font-size: 1.25rem;
            font-weight: 700
        }

        @media (max-width:768px) {
            .member-header {
                padding: 1.5rem;
                margin-bottom: 1.5rem
            }

            .member-header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem
            }

            .member-meta {
                width: 100%;
                flex-direction: column;
                gap: .75rem
            }

            .member-meta>div {
                width: 100%
            }

            .status-cards {
                width: 100%
            }
        }

        /* Floating Pay Now Button */
        .pay-now-fab {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            cursor: pointer;
            z-index: 1000;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .pay-now-fab:hover {
            transform: translateY(-4px) rotate(90deg);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2), 0 4px 6px -2px rgba(0, 0, 0, 0.1);
        }

        /* Payment Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            backdrop-filter: blur(4px);
        }

        .modal-overlay.active {
            display: flex;
            animation: fadeIn 0.2s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .payment-modal {
            background: white;
            border-radius: var(--radius);
            padding: 32px;
            width: 100%;
            max-width: 450px;
            box-shadow: var(--shadow-xl);
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-close {
            position: absolute;
            top: 20px;
            right: 20px;
            background: transparent;
            border: none;
            font-size: 20px;
            color: var(--text-secondary);
            cursor: pointer;
        }

        .qr-container {
            text-align: center;
            margin: 20px 0;
            padding: 20px;
            background: #f8fafc;
            border-radius: var(--radius-sm);
            border: 2px dashed var(--border);
        }

        .qr-container img {
            max-width: 200px;
            height: auto;
        }

        .bank-details-grid {
            display: grid;
            gap: 12px;
            margin-top: 20px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px solid var(--border-light);
            padding-bottom: 8px;
        }

        .detail-label {
            color: var(--text-secondary);
            font-size: 13px;
        }

        .detail-value {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 14px;
            text-align: right;
        }

        /* Grid Layout - Improved */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            align-items: start;
        }

        /* Cards - Enhanced */
        .card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            transition: all .3s;
            margin-bottom: 1.5rem;
        }

        .card:hover {
            box-shadow: var(--shadow-xl);
            transform: translateY(-2px)
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 2px solid var(--border-light);
            background: linear-gradient(to right, var(--light), #fff)
        }

        .card-title {
            font-weight: 700;
            font-size: 1.1rem;
            display: flex;
            gap: .6rem;
            align-items: center;
            color: var(--dark)
        }

        .card-title i {
            color: var(--primary)
        }

        .card-body {
            padding: 1.5rem
        }

        /* Financial Stats - Enhanced */
        .financial-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem
        }

        .stat-item {
            background: linear-gradient(135deg, var(--light), #fff);
            padding: 1.5rem;
            border-radius: var(--radius-sm);
            border: 2px solid var(--border-light);
            text-align: center;
            transition: all .3s
        }

        .stat-item:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary-light)
        }

        .stat-amount {
            font-size: 1.8rem;
            font-weight: 800;
            font-family: ui-monospace;
            color: var(--primary);
            margin-bottom: .5rem
        }

        .stat-label {
            font-size: .8rem;
            color: var(--text-secondary);
            font-weight: 700;
            letter-spacing: .5px;
            text-transform: uppercase
        }

        /* Info Sections - Enhanced */
        .info-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-top: 1.5rem
        }

        .info-section {
            background: var(--light);
            padding: 1.25rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-light)
        }

        .section-title {
            font-weight: 700;
            font-size: 1rem;
            display: flex;
            gap: .6rem;
            align-items: center;
            margin-bottom: 1rem;
            color: var(--dark);
            padding-bottom: .75rem;
            border-bottom: 2px solid var(--border)
        }

        .section-title i {
            color: var(--primary)
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: .75rem 0;
            border-bottom: 1px solid var(--border-light);
            align-items: center
        }

        .info-row:last-child {
            border-bottom: none
        }

        .info-row-label {
            display: flex;
            align-items: center;
            gap: .5rem;
            color: var(--text-secondary);
            font-weight: 500;
            flex-shrink: 0;
        }

        .info-row-label i {
            color: var(--primary);
            font-size: .9rem
        }

        .info-row-value {
            color: var(--text-primary);
            font-weight: 600;
            text-align: right;
            flex: 1;
            margin-left: 1rem;
            word-break: break-word;
        }

        /* Status Badges - Enhanced */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: .4rem .75rem;
            border-radius: 999px;
            font-size: .75rem;
            font-weight: 800;
            letter-spacing: .4px;
            border: 2px solid
        }

        .status-active {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
            border-color: #10b981
        }

        .status-death {
            background: linear-gradient(135deg, #e5e7eb, #d1d5db);
            color: #374151;
            border-color: #6b7280
        }

        .status-freeze {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #92400e;
            border-color: #f59e0b
        }

        .status-terminate {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #991b1b;
            border-color: #ef4444
        }

        /* Table - Enhanced */
        .table-container {
            width: 100%;
            overflow-x: auto;
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow);
            margin-top: 1rem;
        }

        .transactions-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: #fff;
            font-size: 0.875rem;
        }

        .transactions-table thead {
            background: linear-gradient(to right, var(--primary-light), var(--light))
        }

        .transactions-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 700;
            font-size: .75rem;
            color: var(--dark);
            text-transform: uppercase;
            letter-spacing: .6px;
            border-bottom: 2px solid var(--primary);
            white-space: nowrap;
        }

        .transactions-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-light);
            font-size: .875rem;
            vertical-align: middle;
        }

        .transactions-table tbody tr {
            transition: background .2s
        }

        .transactions-table tbody tr:hover {
            background: var(--light)
        }

        .transaction-type {
            padding: .4rem .65rem;
            border-radius: 8px;
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .4px;
            display: inline-block
        }

        .type-income {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
            border: 2px solid #10b981
        }

        .type-expense {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #991b1b;
            border: 2px solid #ef4444
        }

        .amount-income {
            color: var(--success);
            font-weight: 800;
            font-family: ui-monospace
        }

        .amount-expense {
            color: var(--danger);
            font-weight: 800;
            font-family: ui-monospace
        }

        /* Family Members - Enhanced */
        .family-grid {
            display: grid;
            gap: 1rem;
            margin-top: 1rem;
        }

        .family-member {
            background: linear-gradient(135deg, #fff, var(--light));
            padding: 1.25rem;
            border-radius: var(--radius-sm);
            border: 2px solid var(--border-light);
            border-left: 5px solid var(--info);
            box-shadow: var(--shadow);
            transition: all .3s
        }

        .family-member:hover {
            box-shadow: var(--shadow-md);
            transform: translateX(4px)
        }

        .family-member-header {
            display: flex;
            justify-content: space-between;
            gap: .75rem;
            align-items: flex-start;
            margin-bottom: .75rem
        }

        .family-member-name {
            font-weight: 800;
            font-size: 1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: .5rem
        }

        .family-member-name i {
            color: var(--info)
        }

        .family-relationship {
            color: var(--primary);
            font-size: .72rem;
            text-transform: uppercase;
            font-weight: 800;
            letter-spacing: .5px;
            background: var(--primary-light);
            padding: .3rem .6rem;
            border-radius: 6px;
            margin-bottom: .75rem;
            display: inline-block
        }

        .family-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: .5rem;
            color: var(--text-secondary);
            font-size: .875rem
        }

        .family-detail-item {
            display: flex;
            align-items: center;
            gap: .5rem;
            padding: .4rem;
            background: #fff;
            border-radius: 6px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .family-detail-item i {
            color: var(--primary);
            font-size: .85rem;
            flex-shrink: 0;
        }

        /* Documents - Enhanced */
        .doc-item {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: 1rem;
            border: 2px solid var(--border-light);
            border-radius: var(--radius-sm);
            background: linear-gradient(135deg, #fff, var(--light));
            transition: all .3s;
            margin-bottom: 0.75rem;
        }

        .doc-item:hover {
            box-shadow: var(--shadow-md);
            border-color: var(--primary-light);
            transform: translateX(4px)
        }

        .doc-content {
            flex: 1;
            min-width: 0;
        }

        .doc-header {
            display: flex;
            gap: .6rem;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: .5rem
        }

        .doc-type {
            font-weight: 800;
            text-transform: uppercase;
            font-size: .72rem;
            letter-spacing: .5px;
            color: #fff;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            padding: .3rem .7rem;
            border-radius: 8px;
            box-shadow: var(--shadow);
            flex-shrink: 0;
        }

        .doc-number {
            font-family: ui-monospace;
            font-weight: 800;
            color: var(--dark);
            font-size: .95rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .doc-meta {
            display: flex;
            gap: .75rem;
            flex-wrap: wrap;
            color: var(--text-secondary);
            font-size: .85rem;
            margin-top: .5rem
        }

        .doc-meta-item {
            display: flex;
            align-items: center;
            gap: .4rem;
            background: #fff;
            padding: .3rem .5rem;
            border-radius: 6px;
            flex-shrink: 0;
        }

        .doc-meta-item i {
            color: var(--primary);
            font-size: .8rem
        }

        .doc-actions {
            display: flex;
            align-items: center;
            gap: .6rem;
            flex-shrink: 0;
        }

        .doc-link {
            font-size: .8rem;
            color: #fff;
            text-decoration: none;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            padding: .5rem .8rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all .3s;
            box-shadow: var(--shadow);
            white-space: nowrap;
        }

        .doc-link:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px)
        }

        .doc-link i {
            font-size: .9rem
        }

        /* Empty States */
        .empty {
            color: var(--text-light);
            text-align: center;
            padding: 3rem 1.5rem;
            background: var(--light);
            border-radius: var(--radius-sm);
            border: 2px dashed var(--border)
        }

        .empty i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            opacity: .5
        }

        .empty p {
            font-size: .95rem;
            font-weight: 500
        }

        /* Alerts - Enhanced */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
            font-size: .875rem;
            display: flex;
            gap: .75rem;
            align-items: flex-start;
            border: 2px solid transparent;
            box-shadow: var(--shadow)
        }

        .alert i {
            font-size: 1.25rem;
            margin-top: .1rem
        }

        .alert-warning {
            background: linear-gradient(135deg, var(--warning-light), #fef3c7);
            color: #92400e;
            border-color: var(--warning)
        }

        .alert-success {
            background: linear-gradient(135deg, var(--success-light), #a7f3d0);
            color: #065f46;
            border-color: var(--success)
        }

        /* Member Type Badge */
        .member-type-badge {
            padding: .4rem .8rem;
            border-radius: 999px;
            font-size: .8rem;
            font-weight: 800;
            letter-spacing: .4px;
            border: 2px solid;
            background: linear-gradient(135deg, var(--info-light), #a5f3fc);
            color: #0e7490;
            border-color: var(--info);
            box-shadow: var(--shadow)
        }

        /* Form Elements - Enhanced */
        select,
        textarea {
            width: 100%;
            padding: .8rem;
            border: 2px solid var(--border);
            border-radius: 10px;
            font-size: .875rem;
            font-family: inherit;
            transition: all .3s;
            background: #fff
        }

        select:focus,
        textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light)
        }

        label {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: .5rem;
            display: block;
            font-size: .9rem
        }

        /* Badge Counts */
        .badge-count {
            background: linear-gradient(135deg, var(--info), #0891b2);
            color: #fff;
            padding: .2rem .6rem;
            border-radius: 999px;
            font-size: .75rem;
            font-weight: 800;
            box-shadow: var(--shadow);
            margin-left: 0.5rem;
        }

        /* Action buttons in table */
        .transaction-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        .action-btn {
            background: none;
            border: none;
            color: var(--text-light);
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 6px;
            transition: all 0.3s ease;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            min-height: 36px;
        }

        .action-btn:hover {
            background: var(--light);
            color: var(--primary);
            transform: translateY(-1px);
        }

        .action-btn.receipt-btn:hover {
            color: var(--primary);
            background: rgba(74, 111, 165, 0.1);
        }

        /* Responsive - Updated for sidebar */
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

            .main-container {
                max-width: calc(1400px - 288px);
            }
        }

        @media (max-width: 1023.98px) {
            .main-container {
                max-width: 100%;
                padding: 1.5rem;
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width:1200px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .main-container {
                padding: 1.5rem
            }
        }

        @media (max-width:1024px) {
            .financial-stats {
                grid-template-columns: repeat(3, 1fr)
            }

            .info-columns {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width:768px) {
            .member-header {
                padding: 1.5rem
            }

            .member-info h1 {
                font-size: 1.5rem
            }

            .member-meta {
                gap: 1rem
            }

            .financial-stats {
                grid-template-columns: 1fr;
            }

            .info-columns {
                grid-template-columns: 1fr;
            }

            .family-details {
                grid-template-columns: 1fr;
            }

            .header-content {
                padding: 0 1rem
            }

            .main-container {
                padding: 1rem
            }

            .status-cards {
                width: 100%
            }

            .status-card {
                flex: 1;
                min-width: 140px
            }

            .header {
                padding: 1rem
            }

            .card-body {
                padding: 1rem
            }

            .doc-meta {
                flex-direction: column;
                gap: 0.5rem;
            }

            .doc-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width:600px) {
            .transactions-table thead {
                display: none;
            }

            .transactions-table,
            .transactions-table tbody,
            .transactions-table tr,
            .transactions-table td {
                display: block;
                width: 100%;
            }

            .transactions-table tr {
                background: #fff;
                margin: 0 0 0.75rem;
                border: 1px solid var(--border-light);
                border-radius: 10px;
                box-shadow: var(--shadow);
                overflow: hidden;
            }

            .transactions-table td {
                border: none;
                border-bottom: 1px solid var(--border-light);
                position: relative;
                padding: 0.8rem 0.75rem 0.8rem 7.5rem;
            }

            .transactions-table td:last-child {
                border-bottom: none;
            }

            .transactions-table td::before {
                content: attr(data-label);
                position: absolute;
                left: 0.75rem;
                top: 0.8rem;
                width: 6.4rem;
                font-weight: 700;
                font-size: 0.8rem;
                color: var(--text-primary);
                text-transform: uppercase;
                letter-spacing: 0.4px;
            }

            /* Special handling for actions column on mobile */
            .transactions-table td[data-label="Actions"] {
                padding-left: 0.75rem;
                text-align: center;
            }

            .transactions-table td[data-label="Actions"]::before {
                display: none;
            }

            .transaction-actions {
                justify-content: center;
            }

            .transaction-amount {
                text-align: left;
            }

            .table-container {
                overflow: visible;
            }
        }

        @media (max-width:480px) {
            .financial-stats {
                grid-template-columns: 1fr;
            }

            .member-meta {
                flex-direction: column;
            }

            .member-meta>div {
                width: 100%;
                justify-content: center;
            }

            .status-cards {
                flex-direction: column;
            }

            .status-card {
                width: 100%;
            }

            .doc-item {
                flex-direction: column;
            }

            .doc-actions {
                width: 100%;
                justify-content: flex-end;
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
                        <img src="/ma/logo.jpeg" alt="Member Logo"
                            style="width: 100%; height: 100%; object-fit: cover;">
                    </div>
                    <div class="name"><?php echo htmlspecialchars($member['head_name'] ?? 'Member'); ?></div>
                    <div class="role">Member Dashboard</div>
                </div>

                <nav class="menu" role="menu">
                    <button class="menu-btn active" type="button">
                        <i class="fas fa-house-user"></i>
                        <span>Member Dashboard</span>
                    </button>

                    <button class="menu-btn" type="button" onclick="window.location.href='add_family_member_self.php'">
                        <i class="fas fa-user-plus"></i>
                        <span>Add Family Member</span>
                    </button>
                    <button class="menu-btn" type="button" onclick="window.location.href='family_students.php'">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Family Students</span>
                    </button>

                    <button class="menu-btn" type="button" onclick="window.location.href='member_cert_requests.php'">
                        <i class="fas fa-list"></i>
                        <span>My Certificate Requests</span>
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
        <main class="main-with-sidebar" id="main">
            <!-- Top Bar -->
            <section class="header">
                <button class="floating-menu-btn" id="menuToggle" aria-controls="sidebar" aria-expanded="false"
                    aria-label="Open menu" type="button">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="header-content">
                    <div class="breadcrumb">
                        <i class="fas fa-house-user"></i>
                        <span style="font-weight: bold; font-size: 22px; color: black;">Member Dashboard</span>

                        <?php if ($is_sahakari): ?>
                            <span class="member-type-badge"><i class="fas fa-handshake"></i> Sahakari Member</span>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <div class="main-container">
                <?php if (!empty($member_warning)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-triangle-exclamation" style="margin-top:2px;"></i>
                        <div><?php echo htmlspecialchars($member_warning); ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($member_success)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle" style="margin-top:2px;"></i>
                        <div><?php echo htmlspecialchars($member_success); ?></div>
                    </div>
                <?php endif; ?>

                <div class="member-header">
                    <div class="member-header-content">
                        <div class="member-info">
                            <h1><?php echo htmlspecialchars($member['head_name']); ?></h1>
                            <div class="member-meta">
                                <div>
                                    <i class="fas fa-id-card"></i>
                                    <span>
                                        <?php
                                        if (!empty($member_number))
                                            echo 'ID: ' . htmlspecialchars($member_number);
                                        else
                                            echo 'ID: M' . str_pad((int) $member['id'], 3, '0', STR_PAD_LEFT);
                                        ?>
                                    </span>
                                </div>
                                <?php if (!empty($member['join_date'])): ?>
                                    <div>
                                        <i class="fas fa-calendar-check"></i>
                                        <span>Joined <?php echo date('M j, Y', strtotime($member['join_date'])); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <i class="fas fa-folder-open"></i>
                                    <span><?php echo (int) $total_doc_count; ?> Documents</span>
                                </div>
                            </div>
                        </div>
                        <div class="status-cards">
                            <div class="status-card">
                                <div class="status-card-label">Monthly Donation</div>
                                <div class="status-card-value">
                                    <?php echo htmlspecialchars($monthly_status_label ?? 'Due'); ?>
                                </div>
                            </div>
                            <div class="status-card">
                                <div class="status-card-label">Member Type</div>
                                <div class="status-card-value"><?php echo $is_sahakari ? 'Sahakari' : 'Regular'; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="dashboard-grid">
                    <div>
                        <!-- Financial Overview -->
                        <div class="card">
                            <div class="card-header">
                                <div class="card-title"><i class="fas fa-chart-line"></i> Financial Overview</div>
                            </div>
                            <div class="card-body">
                                <div class="financial-stats">
                                    <div class="stat-item">
                                        <div class="stat-amount">
                                            ₹<?php echo number_format($financial_stats['total_donations'], 2); ?></div>
                                        <div class="stat-label">Total Donations</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-amount">
                                            ₹<?php echo number_format((float) ($member['total_due'] ?? 0), 2); ?></div>
                                        <div class="stat-label">Monthly Due</div>
                                    </div>
                                    <div class="stat-item"
                                        style="background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), #fef3c7); border-color: rgba(245, 158, 11, 0.2);">
                                        <div class="stat-amount" style="color: var(--warning);">
                                            ₹<?php echo number_format((float) ($member['custom_due'] ?? 0), 2); ?></div>
                                        <div class="stat-label">Other Dues</div>
                                        <?php if (!empty($custom_dues_breakdown)): ?>
                                            <div style="font-size: 0.8rem; margin-top: 5px; color: var(--warning); opacity: 0.9;">
                                                <?php foreach ($custom_dues_breakdown as $cat => $details): ?>
                                                        <?php if ($details['remaining'] > 0): ?>
                                                                <div><?php echo htmlspecialchars($cat); ?>: ₹<?php echo number_format($details['remaining'], 2); ?></div>
                                                        <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-amount">
                                            <?php echo (int) $financial_stats['total_transactions']; ?>
                                        </div>
                                        <div class="stat-label">Transactions</div>
                                    </div>
                                </div>

                                <div class="info-columns">
                                    <!-- Personal -->
                                    <div class="info-section">
                                        <div class="section-title"><i class="fas fa-user-circle"></i> Personal
                                            Information</div>
                                        <div class="info-row">
                                            <span class="info-row-label">Status</span>
                                            <span
                                                class="status-badge status-<?php echo htmlspecialchars(strtolower($member['status'] ?? 'active')); ?>">
                                                <?php echo getStatusText($member['status'] ?? 'active'); ?>
                                            </span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-row-label"><i class="fas fa-envelope"></i> Email</span>
                                            <span
                                                class="info-row-value"><?php echo htmlspecialchars($member['email'] ?: '-'); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-row-label"><i class="fas fa-phone"></i> Phone</span>
                                            <span
                                                class="info-row-value"><?php echo htmlspecialchars($member['phone'] ?: '-'); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-row-label"><i class="fas fa-birthday-cake"></i> DOB</span>
                                            <span
                                                class="info-row-value"><?php echo $member['dob'] ? date('M j, Y', strtotime($member['dob'])) : 'Not specified'; ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-row-label"><i class="fas fa-venus-mars"></i> Gender</span>
                                            <span
                                                class="info-row-value"><?php echo htmlspecialchars($member['gender'] ?: 'Not specified'); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-row-label"><i class="fas fa-briefcase"></i>
                                                Occupation</span>
                                            <span
                                                class="info-row-value"><?php echo htmlspecialchars($member['occupation'] ?: 'Not specified'); ?></span>
                                        </div>
                                    </div>
                                    <!-- Address & Membership -->
                                    <div class="info-section">
                                        <div class="section-title"><i class="fas fa-home"></i> Address & Membership
                                        </div>
                                        <div class="info-row">
                                            <span class="info-row-label"><i class="fas fa-map-marker-alt"></i>
                                                Address</span>
                                            <span
                                                class="info-row-value"><?php echo nl2br(htmlspecialchars($member['address'] ?: '-')); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-row-label"><i class="fas fa-calendar-plus"></i> Join
                                                Date</span>
                                            <span
                                                class="info-row-value"><?php echo $member['join_date'] ? date('M j, Y', strtotime($member['join_date'])) : '-'; ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-row-label"><i class="fas fa-history"></i> Member
                                                Since</span>
                                            <span class="info-row-value">
                                                <?php
                                                if (!empty($member['join_date'])) {
                                                    $join = new DateTime($member['join_date']);
                                                    $now = new DateTime();
                                                    $diff = $join->diff($now);
                                                    echo $diff->y . ' years, ' . $diff->m . ' months';
                                                } else
                                                    echo '-';
                                                ?>
                                            </span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-row-label"><i class="fas fa-tag"></i> Member Type</span>
                                            <span
                                                class="info-row-value"><?php echo htmlspecialchars($member_type_badge); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Member Offerings -->
                        <?php if (!$is_sahakari): ?>
                                    <div class="card" style="margin-top:1.5rem;">
                                        <div class="card-header">
                                            <div class="card-title"><i class="fas fa-hand-holding-heart"></i> My Offerings</div>
                                            <span
                                                style="background:#4a6fa5;color:#fff;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;">
                                                <?php echo count($member_offerings); ?>
                                            </span>
                                        </div>
                                        <div class="card-body">
                                            <?php if (!empty($member_offerings)): ?>
                                                        <div class="table-container">
                                                            <table class="transactions-table">
                                                                <thead>
                                                                    <tr>
                                                                        <th>Date</th>
                                                                        <th>Type</th>
                                                                        <th>Value / Amount</th>
                                                                        <th>Status</th>
                                                                        <th>Notes</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ($member_offerings as $off):
                                                                        $sc = ['pending' => ['bg' => '#fef3c7', 'c' => '#92400e'], 'fulfilled' => ['bg' => '#d1fae5', 'c' => '#065f46'], 'cancelled' => ['bg' => '#fee2e2', 'c' => '#991b1b']][$off['status']] ?? ['bg' => '#e5e7eb', 'c' => '#374151'];
                                                                        ?>
                                                                                <tr>
                                                                                    <td><?php echo $off['offering_date'] ? date('M j, Y', strtotime($off['offering_date'])) : '—'; ?>
                                                                                    </td>
                                                                                    <td><?php echo htmlspecialchars($off['offering_type']); ?></td>
                                                                                    <td style="font-weight:700;color:var(--primary)">
                                                                                        <?php echo htmlspecialchars($off['offering_value']); ?>
                                                                                    </td>
                                                                                    <td>
                                                                                        <span
                                                                                            style="background:<?php echo $sc['bg']; ?>;color:<?php echo $sc['c']; ?>;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase">
                                                                                            <?php echo htmlspecialchars($off['status']); ?>
                                                                                        </span>
                                                                                    </td>
                                                                                    <td style="color:var(--text-secondary);font-size:13px">
                                                                                        <?php echo htmlspecialchars($off['notes'] ?? '—'); ?>
                                                                                    </td>
                                                                                </tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                            <?php else: ?>
                                                        <div style="text-align:center;padding:40px 16px;color:var(--text-secondary)">
                                                            <i class="fas fa-hand-holding-heart"
                                                                style="font-size:2.5rem;opacity:0.3;display:block;margin-bottom:10px"></i>
                                                            <p>No offerings recorded yet.</p>
                                                        </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                        <?php endif; ?>

                        <!-- Recent Transactions -->
                        <div class="card">
                            <div class="card-header">
                                <div class="card-title"><i class="fas fa-exchange-alt"></i> Recent Transactions</div>
                            </div>
                            <div class="card-body">
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
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($financial_stats['recent_transactions'] as $t): ?>
                                                                    <tr>
                                                                        <td data-label="Date">
                                                                            <?php echo date('M j, Y', strtotime($t['transaction_date'])); ?>
                                                                        </td>
                                                                        <td data-label="Type">
                                                                            <span
                                                                                class="transaction-type type-<?php echo strtolower($t['type']); ?>">
                                                                                <?php echo htmlspecialchars($t['type']); ?>
                                                                            </span>
                                                                        </td>
                                                                        <td data-label="Category">
                                                                            <?php echo htmlspecialchars($t['category']); ?>
                                                                        </td>
                                                                        <td data-label="Description"
                                                                            style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                                                            <?php echo htmlspecialchars($t['description'] ?? '-'); ?>
                                                                        </td>
                                                                        <td data-label="Amount"
                                                                            class="<?php echo $t['type'] === 'INCOME' ? 'amount-income' : 'amount-expense'; ?>">
                                                                            ₹<?php echo number_format((float) $t['amount'], 2); ?>
                                                                        </td>
                                                                        <td data-label="Actions" class="transaction-actions">
                                                                            <button class="action-btn receipt-btn" title="View Receipt"
                                                                                onclick="openReceipt(<?php echo (int) $t['id']; ?>)">
                                                                                🧾
                                                                            </button>
                                                                        </td>
                                                                    </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                <?php else: ?>
                                            <div class="empty">
                                                <i class="fas fa-receipt"></i>
                                                <p>No transactions found<?php echo $is_sahakari ? ' (Sahakari member)' : ''; ?>.</p>
                                            </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar Content -->
                    <div>
                        <!-- Family Members -->
                        <div class="card">
                            <div class="card-header">
                                <div class="card-title">
                                    <i class="fas fa-users"></i> Family Members
                                    <span class="badge-count"><?php echo count($family_members); ?></span>
                                </div>
                                <a href="add_family_member_self.php" class="btn btn-success"
                                    style="padding:.5rem 1rem;font-size:.8rem;">
                                    <i class="fas fa-user-plus"></i> Add
                                </a>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($family_members)): ?>
                                            <div class="family-grid">
                                                <?php foreach ($family_members as $fm):
                                                    $fid = (int) $fm['id'];
                                                    $docCount = isset($family_docs_by_member[$fid]) ? count($family_docs_by_member[$fid]['docs']) : 0;
                                                    $familyStatus = $fm['status'] ?? 'active';
                                                    ?>
                                                            <div class="family-member">
                                                                <div class="family-member-header">
                                                                    <div class="family-member-name">
                                                                        <i class="fas fa-user"></i>
                                                                        <?php echo htmlspecialchars($fm['name']); ?>
                                                                    </div>
                                                                    <span
                                                                        class="status-badge status-<?php echo htmlspecialchars(strtolower($familyStatus)); ?>">
                                                                        <?php echo getStatusText($familyStatus); ?>
                                                                    </span>
                                                                </div>
                                                                <div class="family-relationship">
                                                                    <?php echo htmlspecialchars($fm['relationship']); ?>
                                                                </div>
                                                                <div class="family-details">
                                                                    <?php if ($fm['dob']): ?>
                                                                                <div class="family-detail-item">
                                                                                    <i class="fas fa-calendar"></i>
                                                                                    <?php echo date('M j, Y', strtotime($fm['dob'])); ?>
                                                                                </div>
                                                                    <?php endif; ?>
                                                                    <?php if ($fm['gender']): ?>
                                                                                <div class="family-detail-item">
                                                                                    <i class="fas fa-venus-mars"></i>
                                                                                    <?php echo htmlspecialchars($fm['gender']); ?>
                                                                                </div>
                                                                    <?php endif; ?>
                                                                    <?php if ($fm['phone']): ?>
                                                                                <div class="family-detail-item">
                                                                                    <i class="fas fa-phone"></i>
                                                                                    <?php echo htmlspecialchars($fm['phone']); ?>
                                                                                </div>
                                                                    <?php endif; ?>
                                                                    <?php if ($fm['email']): ?>
                                                                                <div class="family-detail-item">
                                                                                    <i class="fas fa-envelope"></i>
                                                                                    <?php echo htmlspecialchars($fm['email']); ?>
                                                                                </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                <?php endforeach; ?>
                                            </div>
                                <?php else: ?>
                                            <div class="empty">
                                                <i class="fas fa-users-slash"></i>
                                                <p>No family members added yet.</p>
                                            </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Head Identity Documents -->
                        <div class="card">
                            <div class="card-header">
                                <div class="card-title">
                                    <i class="fas fa-id-card"></i> Head Identity Documents
                                    <span class="badge-count"><?php echo count($head_documents); ?></span>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($head_documents)): ?>
                                            <div style="display:grid;gap:.75rem">
                                                <?php foreach ($head_documents as $doc): ?>
                                                            <div class="doc-item">
                                                                <div class="doc-content">
                                                                    <div class="doc-header">
                                                                        <span
                                                                            class="doc-type"><?php echo htmlspecialchars($doc['doc_type']); ?></span>
                                                                        <span
                                                                            class="doc-number"><?php echo htmlspecialchars(strtoupper($doc['doc_number'])); ?></span>
                                                                    </div>
                                                                    <div class="doc-meta">
                                                                        <?php if ($doc['issued_by']): ?>
                                                                                    <span class="doc-meta-item">
                                                                                        <i class="fas fa-building"></i>
                                                                                        <?php echo htmlspecialchars($doc['issued_by']); ?>
                                                                                    </span>
                                                                        <?php endif; ?>
                                                                        <?php if ($doc['issued_on']): ?>
                                                                                    <span class="doc-meta-item">
                                                                                        <i class="fas fa-calendar-check"></i>
                                                                                        <?php echo date('M j, Y', strtotime($doc['issued_on'])); ?>
                                                                                    </span>
                                                                        <?php endif; ?>
                                                                        <?php if ($doc['expiry_on']): ?>
                                                                                    <span class="doc-meta-item">
                                                                                        <i class="fas fa-calendar-xmark"></i>
                                                                                        <?php echo date('M j, Y', strtotime($doc['expiry_on'])); ?>
                                                                                    </span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <?php if (!empty($doc['notes'])): ?>
                                                                                <div
                                                                                    style="color:var(--text-secondary);font-size:.85rem;margin-top:.5rem;font-style:italic">
                                                                                    <i class="fas fa-note-sticky"></i>
                                                                                    <?php echo htmlspecialchars($doc['notes']); ?>
                                                                                </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div class="doc-actions">
                                                                    <?php if (!empty($doc['file_path'])): ?>
                                                                                <a class="doc-link"
                                                                                    href="<?php echo htmlspecialchars($doc['file_path']); ?>"
                                                                                    target="_blank" rel="noopener">
                                                                                    <i class="fas fa-eye"></i> View
                                                                                </a>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                <?php endforeach; ?>
                                            </div>
                                <?php else: ?>
                                            <div class="empty">
                                                <i class="fas fa-file-circle-xmark"></i>
                                                <p>No identity documents added for the head.</p>
                                            </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Quick Action: Request Certificate -->
                        <div class="card">
                            <div class="card-header">
                                <div class="card-title"><i class="fas fa-bolt"></i> Quick Action</div>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="request_certificate.php" style="display:grid;gap:.6rem">
                                    <input type="hidden" name="csrf_token"
                                        value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="member_id"
                                        value="<?php echo (int) $household_member_id; ?>">
                                    <?php if ($is_sahakari): ?>
                                                <input type="hidden" name="is_sahakari" value="1">
                                    <?php endif; ?>
                                    <label for="cert_type" style="font-weight:700">Certificate Type</label>
                                    <select id="cert_type" name="certificate_type" required
                                        style="padding:.7rem;border:1px solid var(--border);border-radius:8px">
                                        <option value="">-- Select --</option>
                                        <option value="marriage">Marriage Certificate</option>
                                        <option value="caste">Caste Certificate</option>
                                        <option value="termination">Membership Termination Certificate</option>
                                    </select>
                                    <label for="cert_notes" style="font-weight:700">Notes (optional)</label>
                                    <textarea id="cert_notes" name="notes" rows="3"
                                        placeholder="Any specific details..."
                                        style="padding:.7rem;border:1px solid var(--border);border-radius:8px;resize:vertical"></textarea>
                                    <button class="btn btn-primary" type="submit" style="width:100%;">
                                        <i class="fas fa-file-signature"></i> Request Certificate
                                    </button>
                                </form>

                                <!-- View Requests link -->
                                <div style="margin-top:.75rem;text-align:center">
                                    <a href="member_cert_requests.php" class="btn"
                                        style="font-size:.8rem;padding:.5rem .75rem;width:100%;">
                                        <i class="fas fa-list"></i> View my certificate requests
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div><!-- /dashboard-grid -->
            </div><!-- /main-container -->
        </main>
    </div><!-- /app -->

    <!-- Pay Now Button -->
    <button class="pay-now-fab" onclick="document.getElementById('paymentModal').classList.add('active')"
        title="Pay Now">
        <i class="fas fa-qrcode"></i>
    </button>

    <!-- Payment Modal -->
    <div id="paymentModal" class="modal-overlay">
        <div class="payment-modal">
            <button class="modal-close"
                onclick="document.getElementById('paymentModal').classList.remove('active')">&times;</button>

            <div style="text-align: center; margin-bottom: 24px;">
                <h2 style="font-size: 20px; font-weight: 700; color: var(--text-primary);">Scan to Pay</h2>
                <p style="color: var(--text-secondary); font-size: 14px;">Use any UPI app to pay</p>
            </div>

            <?php if (!empty($payment_details) && !empty($payment_details['upi_id'])): ?>
                        <div class="qr-container">
                            <!-- QR Code API: https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=upi://pay?pa=UPI_ID&pn=NAME -->
                            <?php
                            // Use account holder name from payment details only; never use the member's personal name
                            $payeeName = !empty($payment_details['account_holder']) ? $payment_details['account_holder'] : 'Mahal';

                            $upi_url = "upi://pay?pa=" . urlencode($payment_details['upi_id']) . "&pn=" . urlencode($payeeName);
                            $qr_src = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($upi_url);
                            ?>
                            <img src="<?php echo $qr_src; ?>" alt="UPI QR Code">
                            <div style="margin-top: 12px; font-weight: 600; color: var(--primary); font-family: monospace;">
                                <?php echo htmlspecialchars($payment_details['upi_id']); ?>
                            </div>
                        </div>

                        <div class="bank-details-grid">
                            <div class="detail-row">
                                <span class="detail-label">Bank Name</span>
                                <span
                                    class="detail-value"><?php echo htmlspecialchars($payment_details['bank_name'] ?? '-'); ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Account Holder</span>
                                <span
                                    class="detail-value"><?php echo htmlspecialchars($payment_details['account_holder'] ?? '-'); ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Account Number</span>
                                <span class="detail-value"
                                    style="font-family: monospace;"><?php echo htmlspecialchars($payment_details['account_number'] ?? '-'); ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">IFSC Code</span>
                                <span class="detail-value"
                                    style="font-family: monospace;"><?php echo htmlspecialchars($payment_details['ifsc_code'] ?? '-'); ?></span>
                            </div>
                        </div>
            <?php else: ?>
                        <div style="text-align: center; padding: 40px 0; color: var(--text-secondary);">
                            <i class="fas fa-exclamation-circle" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                            <p>Payment details not available.<br>Please contact the office.</p>
                        </div>
            <?php endif; ?>

            <?php if (!empty($payment_details) && !empty($payment_details['upi_id'])): ?>
                        <?php
                        $upiPayeeName = !empty($payment_details['account_holder']) ? $payment_details['account_holder'] : 'Mahal';
                        $upiDeepLink = "upi://pay?pa=" . urlencode($payment_details['upi_id']) . "&pn=" . urlencode($upiPayeeName);
                        ?>
                        <a href="<?php echo htmlspecialchars($upiDeepLink); ?>" class="btn"
                            style="width:100%;margin-top:16px;justify-content:center;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;font-size:1rem;padding:.85rem 1.2rem;border-radius:12px;gap:.6rem;box-shadow:0 4px 14px rgba(16,185,129,.35);">
                            <i class="fas fa-mobile-alt"></i> Open UPI App
                        </a>
            <?php endif; ?>

            <button class="btn btn-primary" style="width: 100%; margin-top: 12px; justify-content: center;"
                onclick="document.getElementById('paymentModal').classList.remove('active')">
                Close
            </button>
        </div>
    </div>

    <script>
        // Sidebar functionality (keep this part as is)
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
            if (e.key === 'Escape') {
                if (sidebar.classList.contains('open')) closeSidebar();
                document.getElementById('paymentModal').classList.remove('active');
            }
        });

        document.getElementById('paymentModal').addEventListener('click', function (e) {
            if (e.target === this) {
                this.classList.remove('active');
            }
        });

        document.querySelectorAll('.menu .menu-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (window.matchMedia('(max-width: 1023.98px)').matches) closeSidebar();
            });
        });

        // Initialize sidebar state
        if (window.matchMedia('(min-width: 1024px)').matches) {
            sidebar.classList.add('open');
            sidebar.setAttribute('aria-hidden', 'false');
            toggle.setAttribute('aria-expanded', 'true');
        }

        // Function to open receipt in full new tab
        function openReceipt(transactionId) {
            if (!transactionId || transactionId <= 0) {
                alert('Invalid transaction ID.');
                return;
            }

            // Get mahal_id from session
            let user_id = <?php echo isset($memberSess['mahal_id']) ? (int) $memberSess['mahal_id'] : 0; ?>;

            if (user_id <= 0) {
                alert('Cannot determine Mahal ID. Please log in again.');
                return;
            }

            // Open in a new tab (full browser tab)
            let url = `member_receipt.php?id=${transactionId}&user_id=${user_id}`;

            // Open in new tab - this is the key change
            window.open(url, '_blank');
        }

        // Alternative method using anchor tag (more reliable)
        function openReceiptAlt(transactionId) {
            if (!transactionId || transactionId <= 0) {
                alert('Invalid transaction ID.');
                return;
            }

            let user_id = <?php echo isset($memberSess['mahal_id']) ? (int) $memberSess['mahal_id'] : 0; ?>;
            let url = `member_receipt.php?id=${transactionId}&user_id=${user_id}`;

            // Create a temporary anchor tag and click it
            let a = document.createElement('a');
            a.href = url;
            a.target = '_blank';  // This opens in new tab
            a.rel = 'noopener noreferrer';
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
    </script>
</body>

</html>