<?php
// add_family_member_self.php
// Allows logged-in members (regular or Sahakari) to add family members to their own household
declare(strict_types=1);

/* --- secure session (must be first) --- */
require_once __DIR__ . '/session_bootstrap.php';

// Start output buffering
ob_start();

// Toggle for debugging
define('DEV_MODE', false);
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Convert PHP warnings/notices into exceptions
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Global exception handler
set_exception_handler(function ($e) {
    while (ob_get_level()) ob_end_clean();
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if ($isPost) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        $payload = ['success' => false, 'message' => $e->getMessage()];
        if (defined('DEV_MODE') && DEV_MODE) {
            $payload['trace'] = $e->getTraceAsString();
        }
        echo json_encode($payload);
        exit;
    } else {
        http_response_code(500);
        echo "<h1>Server error</h1><p>An internal error occurred. Try again later.</p>";
        exit;
    }
});

require_once 'db_connection.php';

/* --- no-cache headers --- */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

/* --- auth gate: only logged-in members --- */
if (empty($_SESSION['member_login']) || $_SESSION['member_login'] !== true || empty($_SESSION['member'])) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    } else {
        while (ob_get_level()) ob_end_clean();
        header("Location: index.php");
        exit();
    }
}

/* --- CSRF token --- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$memberSess = $_SESSION['member'];
$is_sahakari = (isset($memberSess['member_type']) && $memberSess['member_type'] === 'sahakari_head');

// Determine household_member_id
if (!$is_sahakari) {
    $household_member_id = (isset($memberSess['member_type']) && $memberSess['member_type'] === 'family' && !empty($memberSess['parent_member_id']))
        ? (int)$memberSess['parent_member_id']
        : (int)$memberSess['member_id'];
} else {
    $household_member_id = (int)$memberSess['member_id'];
}

// Get member details for sidebar
try {
    $db_result = get_db_connection();
    if (isset($db_result['error'])) {
        throw new Exception("Database connection failed: " . $db_result['error']);
    }
    /** @var mysqli $conn */
    $conn = $db_result['conn'];
    
    if ($is_sahakari) {
        $stmt = $conn->prepare("SELECT * FROM sahakari_members WHERE id = ?");
        $stmt->bind_param("i", $household_member_id);
    } else {
        $stmt = $conn->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->bind_param("i", $household_member_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $member = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
} catch (Exception $e) {
    $member = null;
}

/* --- Helper functions --- */
function is_valid_indian_phone($s) {
    $v = preg_replace('/[\s-]+/', '', (string)$s);
    return (bool)preg_match('/^(\+91)?[6-9]\d{9}$/', $v);
}

function not_future_date($str) {
    if (!$str) return true;
    $d = strtotime($str);
    if ($d === false) return false;
    return $d <= strtotime('today');
}

function normalize_doc_number($s) {
    return strtoupper(preg_replace('/\s+/', '', (string)$s));
}

function validate_doc_by_type($type, $number) {
    $t = strtolower($type ?? '');
    $n = normalize_doc_number($number ?? '');
    switch ($t) {
        case 'aadhaar': return preg_match('/^\d{12}$/', $n);
        case 'pan': return preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', $n);
        case 'voter id': return preg_match('/^[A-Z]{3}\d{7}$/', $n);
        case 'passport': return preg_match('/^[A-Z][0-9]{7}$/', $n);
        case "driver's licence": return preg_match('/^[A-Z]{2}\d{2}\s?\d{7,11}$/', $n);
        case 'ration card': return preg_match('/^[A-Z0-9]{8,16}$/', $n);
        case 'birth certificate': return preg_match('/^[A-Z0-9\-\/]{6,20}$/', $n);
        case 'other': default: return strlen($n) >= 4;
    }
}

/* --- POST: Save family member --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_family_member') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    try {
        // CSRF check
        if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception('Invalid security token. Please refresh and try again.');
        }

        $db_result = get_db_connection();
        if (isset($db_result['error'])) {
            throw new Exception("Database connection failed: " . $db_result['error']);
        }
        /** @var mysqli $conn */
        $conn = $db_result['conn'];

        // Read form data
        $name = trim($_POST['name'] ?? '');
        $relationship = trim($_POST['relationship'] ?? '');
        $dob = isset($_POST['dob']) && $_POST['dob'] !== '' ? trim($_POST['dob']) : null;
        $gender = isset($_POST['gender']) && $_POST['gender'] !== '' ? trim($_POST['gender']) : null;
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $father_name = isset($_POST['father_name']) && $_POST['father_name'] !== '' ? trim($_POST['father_name']) : null;
        $status = isset($_POST['status']) && $_POST['status'] !== '' ? trim($_POST['status']) : 'active';

        // Documents
        $documents = [];
        if (isset($_POST['documents'])) {
            $decoded = json_decode($_POST['documents'], true);
            if (is_array($decoded)) {
                $documents = $decoded;
            }
        }

        // Validations
        if ($name === '') throw new Exception('Family member name is required.');
        if (strlen($name) > 120) throw new Exception('Name is too long (max 120 characters).');
        if ($relationship === '') throw new Exception('Relationship is required.');
        
        if ($phone !== '' && !is_valid_indian_phone($phone)) {
            throw new Exception('Invalid phone number format.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format.');
        }
        if ($dob && !not_future_date($dob)) {
            throw new Exception('Date of birth cannot be in the future.');
        }
        if ($father_name !== null && strlen($father_name) > 255) {
            throw new Exception('Father name is too long (max 255 characters).');
        }

        // Validate documents
        $seen_docs = [];
        foreach ($documents as $d) {
            $doc_type = trim($d['doc_type'] ?? '');
            $doc_number = trim($d['doc_number'] ?? '');
            
            if (($doc_type && !$doc_number) || (!$doc_type && $doc_number)) {
                throw new Exception('Each document requires both Type and Number.');
            }
            if (!$doc_type && !$doc_number) continue;

            if (!validate_doc_by_type($doc_type, $doc_number)) {
                throw new Exception("Invalid $doc_type number format.");
            }

            $issued_on = $d['issued_on'] ?? null;
            $expiry_on = $d['expiry_on'] ?? null;
            if ($issued_on && !not_future_date($issued_on)) {
                throw new Exception("$doc_type Issued On cannot be in the future.");
            }
            if ($issued_on && $expiry_on && strtotime($expiry_on) < strtotime($issued_on)) {
                throw new Exception("$doc_type Expiry On cannot be before Issued On.");
            }

            $key = strtolower($doc_type) . '|' . normalize_doc_number($doc_number);
            if (isset($seen_docs[$key])) {
                throw new Exception("Duplicate $doc_type number detected.");
            }
            $seen_docs[$key] = true;
        }

        $conn->begin_transaction();

        // Insert family member
        if ($is_sahakari) {
            // Sahakari family member
            $sql = "INSERT INTO sahakari_family_members 
                    (member_id, name, relationship, dob, gender, phone, email, father_name, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("issssssss", 
                $household_member_id, $name, $relationship, $dob, $gender, $phone, $email, $father_name, $status
            );
        } else {
            // Regular family member
            $sql = "INSERT INTO family_members 
                    (member_id, name, relationship, dob, gender, phone, email, father_name, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("issssssss", 
                $household_member_id, $name, $relationship, $dob, $gender, $phone, $email, $father_name, $status
            );
        }

        if (!$stmt->execute()) {
            throw new Exception("Failed to save family member: " . $stmt->error);
        }
        $family_member_id = $conn->insert_id;
        $stmt->close();

        // Update total_family_members count
        if ($is_sahakari) {
            $count_sql = "SELECT COUNT(*) as cnt FROM sahakari_family_members WHERE member_id = ?";
            $update_sql = "UPDATE sahakari_members SET total_family_members = ? + 1 WHERE id = ?";
        } else {
            $count_sql = "SELECT COUNT(*) as cnt FROM family_members WHERE member_id = ?";
            $update_sql = "UPDATE members SET total_family_members = ? + 1 WHERE id = ?";
        }
        
        $stmt = $conn->prepare($count_sql);
        $stmt->bind_param("i", $household_member_id);
        $stmt->execute();
        $count_result = $stmt->get_result()->fetch_assoc();
        $total_count = (int)$count_result['cnt'];
        $stmt->close();

        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("ii", $total_count, $household_member_id);
        $stmt->execute();
        $stmt->close();

        // Insert documents
        if (!empty($documents)) {
            if ($is_sahakari) {
                $doc_sql = "INSERT INTO sahakari_member_documents 
                    (member_id, family_member_id, owner_type, doc_type, doc_number, name_on_doc, issued_by, issued_on, expiry_on, notes, created_at, updated_at)
                    VALUES (?, ?, 'family', ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            } else {
                $doc_sql = "INSERT INTO member_documents 
                    (member_id, family_member_id, owner_type, doc_type, doc_number, name_on_doc, issued_by, issued_on, expiry_on, notes, created_at, updated_at)
                    VALUES (?, ?, 'family', ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            }

            $doc_stmt = $conn->prepare($doc_sql);
            
            foreach ($documents as $d) {
                $doc_type = trim($d['doc_type'] ?? '');
                $doc_number = trim($d['doc_number'] ?? '');
                if ($doc_type === '' || $doc_number === '') continue;

                $name_on_doc = isset($d['name_on_doc']) && $d['name_on_doc'] !== '' ? trim($d['name_on_doc']) : null;
                $issued_by = isset($d['issued_by']) && $d['issued_by'] !== '' ? trim($d['issued_by']) : null;
                $issued_on = isset($d['issued_on']) && $d['issued_on'] !== '' ? trim($d['issued_on']) : null;
                $expiry_on = isset($d['expiry_on']) && $d['expiry_on'] !== '' ? trim($d['expiry_on']) : null;
                $notes = isset($d['notes']) && $d['notes'] !== '' ? trim($d['notes']) : null;

                $doc_stmt->bind_param("iissssssss",
                    $household_member_id, $family_member_id, $doc_type, $doc_number,
                    $name_on_doc, $issued_by, $issued_on, $expiry_on, $notes
                );

                if (!$doc_stmt->execute()) {
                    throw new Exception("Failed to save document: " . $doc_stmt->error);
                }
            }
            $doc_stmt->close();
        }

        $conn->commit();
        $conn->close();

        echo json_encode([
            'success' => true,
            'message' => "Family member '$name' added successfully!"
        ]);
        exit;

    } catch (Exception $e) {
        if (isset($conn) && !$conn->connect_errno) {
            $conn->rollback();
            $conn->close();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// For GET requests, render the form
while (ob_get_level()) ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Add Family Member</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
    :root {
        --primary:#2563eb; --primary-dark:#1d4ed8; --primary-light:#dbeafe;
        --secondary:#64748b; --success:#10b981; --success-light:#d1fae5;
        --warning:#f59e0b; --warning-light:#fef3c7; --danger:#ef4444;
        --danger-light:#fee2e2; --info:#06b6d4; --info-light:#cffafe;
        --light:#f8fafc; --dark:#1e293b; --border:#e2e8f0; --border-light:#f1f5f9;
        --text-primary:#1e293b; --text-secondary:#64748b; --text-light:#94a3b8;
        --shadow:0 1px 3px rgba(0,0,0,.1),0 1px 2px rgba(0,0,0,.06);
        --shadow-md:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -1px rgba(0,0,0,.06);
        --shadow-lg:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -2px rgba(0,0,0,.05);
        --shadow-xl:0 20px 25px -5px rgba(0,0,0,.1),0 10px 10px -5px rgba(0,0,0,.04);
        --radius:16px; --radius-sm:12px; --radius-lg:20px;
        --banner-gradient:linear-gradient(180deg, var(--primary-dark) 0%, var(--primary) 100%);
    }
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:linear-gradient(135deg,#f5f7fa 0%,#c3cfe2 100%);color:var(--text-primary);line-height:1.6;font-size:14px;min-height:100vh}
    
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
        background: linear-gradient(135deg, rgba(255,255,255,0.2), rgba(255,255,255,0.1));
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
        text-shadow: 0 2px 4px rgba(0,0,0,0.1);
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
        color: rgba(255, 255, 255, 0.8);
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
        background: linear-gradient(135deg,#f5f7fa 0%,#c3cfe2 100%);
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
    body.no-scroll { overflow: hidden; }
    
    /* Header */
    .header{
        background:#fff;
        border-bottom:1px solid var(--border);
        padding: 1.25rem 2rem;
        position:sticky;
        top:0;
        z-index:100;
        box-shadow:var(--shadow-md);
        display: flex; 
        align-items: center; 
        gap: 16px;
    }
    .header-content{
        display:flex;
        justify-content:space-between;
        align-items:center;
        width: 100%;
        margin:0 auto;
        flex: 1;
    }
    .breadcrumb{
        font-size:.875rem;
        color:var(--text-secondary);
        display:flex;
        align-items:center;
        gap:.5rem;
        flex-wrap:wrap
    }
    .breadcrumb a{
        color:var(--primary);
        text-decoration:none;
        transition:color .2s
    }
    .breadcrumb a:hover{
        text-decoration:underline;
        color:var(--primary-dark)
    }

    /* Buttons */
    .btn{
        padding:.7rem 1.2rem;
        border-radius:10px;
        border:1px solid var(--border);
        background:#fff;
        cursor:pointer;
        font-weight:600;
        display:inline-flex;
        gap:.5rem;
        align-items:center;
        text-decoration:none;
        color:var(--text-primary);
        transition:all .3s;
        font-size:.875rem
    }
    .btn:hover{
        box-shadow:var(--shadow-md);
        transform:translateY(-2px)
    }
    .btn-primary{
        background:linear-gradient(135deg,var(--primary),var(--primary-dark));
        border-color:var(--primary);
        color:#fff;
        box-shadow:var(--shadow)
    }
    .btn-primary:hover{
        box-shadow:var(--shadow-lg);
        transform:translateY(-2px)
    }
    .btn-success{
        background:linear-gradient(135deg,var(--success),#059669);
        border-color:var(--success);
        color:#fff;
        box-shadow:var(--shadow)
    }
    .btn-success:hover{
        box-shadow:var(--shadow-lg);
        transform:translateY(-2px)
    }
    .btn-danger{
        background:linear-gradient(135deg,var(--danger),#dc2626);
        border-color:var(--danger);
        color:#fff;
        box-shadow:var(--shadow)
    }
    .btn-danger:hover{
        box-shadow:var(--shadow-lg);
        transform:translateY(-2px)
    }

    /* Main Container */
    .main-container{
        padding: 2rem;
        width: 100%;
        max-width: 1400px;
        margin: 0 auto;
    }

    /* Form Styles */
    .alert{
        padding:1rem 1.25rem;
        border-radius:var(--radius-sm);
        margin-bottom:1.5rem;
        font-size:.875rem;
        display:flex;
        gap:.75rem;
        align-items:flex-start;
        border:2px solid transparent;
        box-shadow:var(--shadow);
        display: none;
    }
    .alert.show{
        display: flex;
    }
    .alert i{
        font-size:1.25rem;
        margin-top:.1rem
    }
    .alert-error{
        background:linear-gradient(135deg,var(--warning-light),#fef3c7);
        color:#92400e;
        border-color:var(--warning)
    }
    .alert-success{
        background:linear-gradient(135deg,var(--success-light),#a7f3d0);
        color:#065f46;
        border-color:var(--success)
    }

    .loading{
        text-align:center;
        padding:40px;
        display:none;
        background:#fff;
        border-radius:12px;
        border:1px solid var(--border);
        margin-bottom: 1.5rem;
    }
    .loading.active{
        display:block
    }
    .loading p{
        color:var(--secondary);
        font-size:15px
    }

    .form-section{
        background:#fff;
        border-radius:var(--radius);
        border:1px solid var(--border);
        padding:2rem;
        margin-bottom:1.5rem;
        box-shadow: var(--shadow);
    }
    .form-section h2{
        font-size:1.25rem;
        font-weight:700;
        margin-bottom:1.5rem;
        padding-bottom:.75rem;
        border-bottom:2px solid var(--border);
        color: var(--dark);
    }

    .form-grid{
        display:grid;
        grid-template-columns:repeat(2,1fr);
        gap:1.25rem;
    }
    .form-group{
        display:flex;
        flex-direction:column;
        position: relative;
    }
    .form-group.full{
        grid-column:1/-1;
    }

    label{
        font-weight:600;
        color:var(--dark);
        margin-bottom:.5rem;
        font-size:.9rem;
        display: flex;
        align-items: center;
        gap: 4px;
    }
    label .required{
        color:var(--danger);
    }

    input,select,textarea{
        padding:.75rem 1rem;
        border:2px solid var(--border);
        border-radius:var(--radius-sm);
        font-size:.875rem;
        font-family:inherit;
        transition:all .3s;
        background:#fff;
        width: 100%;
    }
    input:focus,select:focus,textarea:focus{
        outline:none;
        border-color:var(--primary);
        box-shadow:0 0 0 3px var(--primary-light);
    }
    
    /* Validation Styles */
    input.invalid, select.invalid {
        border-color: var(--danger) !important;
        background-color: var(--danger-light);
    }
    
    input.valid, select.valid {
        border-color: var(--success) !important;
        background-color: var(--success-light);
    }
    
    .validation-error {
        color: var(--danger);
        font-size: 0.75rem;
        margin-top: 0.25rem;
        display: flex;
        align-items: center;
        gap: 0.25rem;
        opacity: 0;
        transform: translateY(-5px);
        transition: all 0.3s ease;
    }
    
    .validation-error.show {
        opacity: 1;
        transform: translateY(0);
    }
    
    .validation-error i {
        font-size: 0.9rem;
    }
    
    .validation-success {
        color: var(--success);
        font-size: 0.75rem;
        margin-top: 0.25rem;
        display: flex;
        align-items: center;
        gap: 0.25rem;
        opacity: 0;
        transform: translateY(-5px);
        transition: all 0.3s ease;
    }
    
    .validation-success.show {
        opacity: 1;
        transform: translateY(0);
    }
    
    .validation-success i {
        font-size: 0.9rem;
    }

    .doc-card{
        background:var(--light);
        border:2px dashed var(--border);
        border-radius:var(--radius-sm);
        padding:1.5rem;
        margin:1rem 0;
    }
    .doc-card h4{
        font-size:1rem;
        font-weight:600;
        margin-bottom:1rem;
        color:var(--dark);
    }
    .doc-controls{
        display:grid;
        grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));
        gap:1rem;
    }
    .doc-section-header{
        display:flex;
        align-items:center;
        justify-content:space-between;
        margin:1.5rem 0 1rem;
        padding-top: 1rem;
        border-top: 2px solid var(--border-light);
    }

    .actions{
        display:flex;
        justify-content:space-between;
        gap:1rem;
        padding:1.5rem 0;
    }
    .actions-left,.actions-right{
        display:flex;
        gap:1rem;
    }

    .help{
        font-size:.75rem;
        color:var(--text-light);
        margin-top:.25rem;
        font-style: italic;
    }
    
    /* Disable submit button when form is invalid */
    .btn-success:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none !important;
        box-shadow: none !important;
    }

    /* Responsive */
    @media (min-width: 1024px) {
        .sidebar { transform: none; }
        .sidebar-overlay { display: none; }
        .main-with-sidebar { margin-left: 288px; width: calc(100% - 288px); }
        .floating-menu-btn { display: none !important; }
        .sidebar-close { display: none; }
        .main-container{ max-width: calc(1400px - 288px); }
    }

    @media (max-width: 1023.98px) {
        .main-container{ max-width: 100%; padding: 1.5rem; }
        .form-grid{ grid-template-columns: 1fr; }
    }

    @media (max-width: 768px){
        .actions{
            flex-direction: column;
        }
        .actions-left, .actions-right{
            width: 100%;
        }
        .btn{
            width: 100%;
            justify-content: center;
        }
        .doc-controls{
            grid-template-columns: 1fr;
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
                        <img src="/ma/logo.jpeg" alt="Member Logo" style="width: 100%; height: 100%; object-fit: cover;">
                    </div>
                    <div class="name"><?php echo htmlspecialchars($member['head_name'] ?? 'Member'); ?></div>
                    <div class="role">Member Dashboard</div>
                </div>

                <nav class="menu" role="menu">
                    <button class="menu-btn" type="button" onclick="window.location.href='member_dashboard.php'">
                        <i class="fas fa-house-user"></i>
                        <span>Member Dashboard</span>
                    </button>

                    <button class="menu-btn active" type="button">
                        <i class="fas fa-user-plus"></i>
                        <span>Add Family Member</span>
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
                <button class="floating-menu-btn" id="menuToggle" aria-controls="sidebar" aria-expanded="false" aria-label="Open menu" type="button">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="header-content">
                    <div class="breadcrumb">
                        <i class="fas fa-house-user"></i>
                        <span style="font-weight: bold; font-size: 22px; color: black;">Add Family Member</span>
                    </div>
                    <a href="member_dashboard.php" class="btn btn-primary">← Back to Dashboard</a>
                </div>
            </section>

            <div class="main-container">
                <div id="alertBox" class="alert"></div>
                <div id="loadingBox" class="loading"><p>💾 Saving family member...</p></div>

                <form id="familyForm" method="POST" novalidate>
                    <input type="hidden" name="action" value="save_family_member">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <div class="form-section">
                        <h2>Family Member Details</h2>
                        <div class="form-grid">
                            <!-- Full Name -->
                            <div class="form-group">
                                <label>Full Name <span class="required">*</span></label>
                                <input type="text" name="name" id="name" placeholder="Enter full name" required maxlength="120">
                                <div class="validation-error" id="name-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Only letters and spaces allowed (2-120 characters)</span>
                                </div>
                                <div class="validation-success" id="name-success">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Name is valid</span>
                                </div>
                            </div>

                            <!-- Relationship -->
                            <div class="form-group">
                                <label>Relationship <span class="required">*</span></label>
                                <select name="relationship" id="relationship" required>
                                    <option value="">Select Relationship</option>
                                    <option value="Spouse">Spouse</option>
                                    <option value="Son">Son</option>
                                    <option value="Daughter">Daughter</option>
                                    <option value="Father">Father</option>
                                    <option value="Mother">Mother</option>
                                    <option value="Brother">Brother</option>
                                    <option value="Sister">Sister</option>
                                    <option value="Other">Other</option>
                                </select>
                                <div class="validation-error" id="relationship-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Please select a relationship</span>
                                </div>
                                <div class="validation-success" id="relationship-success">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Relationship selected</span>
                                </div>
                            </div>

                            <!-- Father's Name -->
                            <div class="form-group">
                                <label>Father's Name</label>
                                <input type="text" name="father_name" id="father_name" placeholder="Father's name (optional)" maxlength="255">
                                <div class="validation-error" id="father_name-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Only letters and spaces allowed</span>
                                </div>
                                <div class="validation-success" id="father_name-success">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Name is valid</span>
                                </div>
                            </div>

                            <!-- Date of Birth -->
                            <div class="form-group">
                                <label>Date of Birth</label>
                                <input type="date" name="dob" id="dob">
                                <div class="validation-error" id="dob-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Date cannot be in the future</span>
                                </div>
                                <div class="validation-success" id="dob-success">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Date is valid</span>
                                </div>
                                <div class="help">Select date of birth (optional)</div>
                            </div>

                            <!-- Gender -->
                            <div class="form-group">
                                <label>Gender</label>
                                <select name="gender" id="gender">
                                    <option value="">Select Gender (optional)</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                                <div class="validation-success" id="gender-success">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Gender selected</span>
                                </div>
                            </div>

                            <!-- Status -->
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" id="status">
                                    <option value="active">🟢 Active (Alive)</option>
                                    <option value="death">⚫ Deceased</option>
                                    <option value="freeze">🟡 Frozen</option>
                                    <option value="terminate">🔴 Terminated</option>
                                </select>
                                <div class="validation-success" id="status-success">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Status selected</span>
                                </div>
                            </div>

                            <!-- Phone -->
                            <div class="form-group">
                                <label>Phone</label>
                                <input type="tel" name="phone" id="phone" placeholder="Phone number (optional)" maxlength="16">
                                <div class="validation-error" id="phone-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Invalid phone number format</span>
                                </div>
                                <div class="validation-success" id="phone-success">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Phone number is valid</span>
                                </div>
                                <div class="help">10-digit Indian mobile number</div>
                            </div>

                            <!-- Email -->
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="email" id="email" placeholder="Email address (optional)" maxlength="120">
                                <div class="validation-error" id="email-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Invalid email format</span>
                                </div>
                                <div class="validation-success" id="email-success">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Email is valid</span>
                                </div>
                            </div>
                        </div>

                        <div class="doc-section-header">
                            <div class="title">Identity Documents (Optional)</div>
                            <button type="button" class="btn btn-success" id="addDocBtn">+ Add Document</button>
                        </div>
                        <div id="documentsContainer"></div>
                    </div>

                    <div class="actions">
                        <div class="actions-left">
                            <a href="member_dashboard.php" class="btn btn-secondary">Cancel</a>
                            <button type="button" class="btn btn-secondary" id="clearBtn">Clear Form</button>
                        </div>
                        <div class="actions-right">
                            <button type="submit" class="btn btn-success" id="submitBtn">Save Family Member</button>
                        </div>
                    </div>
                </form>
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

    // Initialize sidebar state
    if (window.matchMedia('(min-width: 1024px)').matches) {
        sidebar.classList.add('open');
        sidebar.setAttribute('aria-hidden', 'false');
        toggle.setAttribute('aria-expanded', 'true');
    }

    // Validation functions
    function isValidName(name) {
        // Allow letters, spaces, apostrophes, and hyphens (for names like O'Connor, Jean-Luc)
        return /^[A-Za-z\s\'\-]{2,120}$/.test(name.trim());
    }
    
    function isValidOptionalName(name) {
        if (!name.trim()) return true; // Empty is okay for optional
        return /^[A-Za-z\s\'\-]{2,255}$/.test(name.trim());
    }
    
    function isValidRelationship(relationship) {
        return relationship !== '';
    }
    
    function isValidDateOfBirth(dob) {
        if (!dob) return true; // Empty is okay for optional
        const selectedDate = new Date(dob);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        return selectedDate <= today;
    }
    
    function isValidPhone(phone) {
        if (!phone.trim()) return true; // Empty is okay for optional
        // Remove all non-digit characters
        const cleaned = phone.replace(/\D/g, '');
        return cleaned.length === 10 && cleaned[0] >= '6';
    }
    
    function isValidEmail(email) {
        if (!email.trim()) return true; // Empty is okay for optional
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }
    
    // Track which fields have been interacted with
    const touchedFields = new Set();
    
    // Form validation state
    const formValidation = {
        name: false,
        relationship: false
    };
    
    // Update submit button state
    function updateSubmitButton() {
        const submitBtn = document.getElementById('submitBtn');
        const isFormValid = formValidation.name && formValidation.relationship;
        submitBtn.disabled = !isFormValid;
    }
    
    // Mark field as touched
    function markAsTouched(elementId) {
        touchedFields.add(elementId);
        const inputElement = document.getElementById(elementId);
        
        // For required fields, validate immediately when touched
        if (elementId === 'name' || elementId === 'relationship') {
            if (elementId === 'name') {
                const isValid = isValidName(inputElement.value);
                showValidation('name', isValid, true);
            } else if (elementId === 'relationship') {
                const isValid = isValidRelationship(inputElement.value);
                showValidation('relationship', isValid, true);
            }
        }
    }
    
    // Show/hide validation messages - only show error when field is touched AND invalid
    function showValidation(elementId, isValid, isRequired = false) {
        const errorElement = document.getElementById(elementId + '-error');
        const successElement = document.getElementById(elementId + '-success');
        const inputElement = document.getElementById(elementId);
        
        // Only validate if field has been touched
        if (!touchedFields.has(elementId)) {
            // Hide both messages and remove all classes for untouched fields
            if (errorElement) errorElement.classList.remove('show');
            if (successElement) successElement.classList.remove('show');
            inputElement.classList.remove('valid', 'invalid');
            return;
        }
        
        if (errorElement) {
            if (!isValid && (isRequired || inputElement.value.trim() !== '')) {
                errorElement.classList.add('show');
                inputElement.classList.add('invalid');
                inputElement.classList.remove('valid');
            } else {
                errorElement.classList.remove('show');
                inputElement.classList.remove('invalid');
            }
        }
        
        if (successElement) {
            if (isValid && inputElement.value.trim() !== '' && touchedFields.has(elementId)) {
                successElement.classList.add('show');
                inputElement.classList.add('valid');
                inputElement.classList.remove('invalid');
            } else {
                successElement.classList.remove('show');
                if (!inputElement.classList.contains('invalid')) {
                    inputElement.classList.remove('valid');
                }
            }
        }
        
        // Update form validation state for required fields
        if (elementId === 'name') {
            formValidation.name = isValid;
        } else if (elementId === 'relationship') {
            formValidation.relationship = isValid;
        }
        
        updateSubmitButton();
    }
    
    // Attach validation events (only on blur/change)
    document.getElementById('name').addEventListener('blur', function() {
        markAsTouched('name');
        const isValid = isValidName(this.value);
        showValidation('name', isValid, true);
    });
    
    document.getElementById('relationship').addEventListener('change', function() {
        markAsTouched('relationship');
        const isValid = isValidRelationship(this.value);
        showValidation('relationship', isValid, true);
    });
    
    document.getElementById('father_name').addEventListener('blur', function() {
        markAsTouched('father_name');
        const isValid = isValidOptionalName(this.value);
        showValidation('father_name', isValid);
    });
    
    document.getElementById('dob').addEventListener('change', function() {
        markAsTouched('dob');
        const isValid = isValidDateOfBirth(this.value);
        showValidation('dob', isValid);
    });
    
    document.getElementById('gender').addEventListener('change', function() {
        markAsTouched('gender');
        const isValid = this.value !== '';
        showValidation('gender', isValid);
    });
    
    document.getElementById('status').addEventListener('change', function() {
        markAsTouched('status');
        const isValid = this.value !== '';
        showValidation('status', isValid);
    });
    
    document.getElementById('phone').addEventListener('blur', function() {
        markAsTouched('phone');
        const isValid = isValidPhone(this.value);
        showValidation('phone', isValid);
    });
    
    document.getElementById('email').addEventListener('blur', function() {
        markAsTouched('email');
        const isValid = isValidEmail(this.value);
        showValidation('email', isValid);
    });
    
    // Real-time validation for required fields (optional, can remove if you only want on blur)
    document.getElementById('name').addEventListener('input', function() {
        if (touchedFields.has('name')) {
            const isValid = isValidName(this.value);
            showValidation('name', isValid, true);
        }
    });
    
    document.getElementById('relationship').addEventListener('input', function() {
        if (touchedFields.has('relationship')) {
            const isValid = isValidRelationship(this.value);
            showValidation('relationship', isValid, true);
        }
    });
    
    // Initial setup - just update button state
    document.addEventListener('DOMContentLoaded', function() {
        updateSubmitButton(); // Just update button state based on initial empty values
    });

    // Form functionality
    let docCount = 0;

    const DOC_TYPES = [
        "Aadhaar", "Voter ID", "PAN", "Driver's Licence",
        "Passport", "Ration Card", "Birth Certificate", "Other"
    ];

    function createDocumentCard(titleText) {
        const wrapper = document.createElement('div');
        wrapper.className = 'doc-card';
        wrapper.innerHTML = `
            <h4>${titleText}</h4>
            <div class="doc-controls">
                <div class="form-group">
                    <label>Document Type</label>
                    <select class="doc-type"></select>
                </div>
                <div class="form-group">
                    <label>Document Number</label>
                    <input type="text" class="doc-number" placeholder="Enter document number" maxlength="30">
                </div>
                <div class="form-group">
                    <label>Name on Document</label>
                    <input type="text" class="doc-name" placeholder="(optional)" maxlength="120">
                </div>
                <div class="form-group">
                    <label>Issued By</label>
                    <input type="text" class="doc-issued-by" placeholder="(optional)" maxlength="120">
                </div>
                <div class="form-group">
                    <label>Issued On</label>
                    <input type="date" class="doc-issued-on">
                </div>
                <div class="form-group">
                    <label>Expiry On</label>
                    <input type="date" class="doc-expiry-on">
                </div>
                <div class="form-group full">
                    <label>Notes</label>
                    <input type="text" class="doc-notes" placeholder="(optional)" maxlength="200">
                </div>
            </div>
            <div style="margin-top:10px;">
                <button type="button" class="btn btn-danger doc-remove-btn">Remove Document</button>
            </div>
        `;

        const sel = wrapper.querySelector('.doc-type');
        DOC_TYPES.forEach(d => {
            const opt = document.createElement('option');
            opt.value = d;
            opt.textContent = d;
            sel.appendChild(opt);
        });

        return wrapper;
    }

    document.getElementById('addDocBtn').addEventListener('click', function() {
        docCount++;
        const card = createDocumentCard("Document " + docCount);
        card.querySelector('.doc-remove-btn').addEventListener('click', () => {
            card.remove();
        });
        document.getElementById('documentsContainer').appendChild(card);
    });

    document.getElementById('clearBtn').addEventListener('click', function() {
        if (confirm('Are you sure you want to clear all form data?')) {
            document.getElementById('familyForm').reset();
            document.getElementById('documentsContainer').innerHTML = '';
            docCount = 0;
            hideAlert();
            
            // Reset touched fields
            touchedFields.clear();
            
            // Reset validation states
            formValidation.name = false;
            formValidation.relationship = false;
            updateSubmitButton();
            
            // Remove all validation classes
            document.querySelectorAll('.validation-error, .validation-success').forEach(el => {
                el.classList.remove('show');
            });
            document.querySelectorAll('input, select').forEach(el => {
                el.classList.remove('valid', 'invalid');
            });
        }
    });

    document.getElementById('familyForm').addEventListener('submit', function(e) {
        e.preventDefault();

        // Mark required fields as touched before validation
        markAsTouched('name');
        markAsTouched('relationship');
        
        // Force validation check
        const name = document.getElementById('name').value.trim();
        const relationship = document.getElementById('relationship').value;
        
        const nameIsValid = isValidName(name);
        const relationshipIsValid = isValidRelationship(relationship);
        
        showValidation('name', nameIsValid, true);
        showValidation('relationship', relationshipIsValid, true);

        if (!nameIsValid) {
            showAlert('error', 'Please enter a valid name (letters and spaces only, 2-120 characters)');
            document.getElementById('name').focus();
            return false;
        }

        if (!relationshipIsValid) {
            showAlert('error', 'Please select a relationship');
            document.getElementById('relationship').focus();
            return false;
        }

        // Collect documents
        const documents = [];
        document.querySelectorAll('#documentsContainer .doc-card').forEach(card => {
            const type = card.querySelector('.doc-type')?.value || '';
            const number = card.querySelector('.doc-number')?.value.trim() || '';
            const nameOn = card.querySelector('.doc-name')?.value.trim() || '';
            const issuedBy = card.querySelector('.doc-issued-by')?.value.trim() || '';
            const issuedOn = card.querySelector('.doc-issued-on')?.value || '';
            const expiryOn = card.querySelector('.doc-expiry-on')?.value || '';
            const notes = card.querySelector('.doc-notes')?.value.trim() || '';

            if (type || number) {
                documents.push({
                    doc_type: type,
                    doc_number: number,
                    name_on_doc: nameOn,
                    issued_by: issuedBy,
                    issued_on: issuedOn,
                    expiry_on: expiryOn,
                    notes: notes
                });
            }
        });

        const form = this;
        const formData = new FormData(form);
        formData.append('documents', JSON.stringify(documents));

        document.getElementById('loadingBox').classList.add('active');
        hideAlert();

        fetch('add_family_member_self.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => {
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(text => {
                    throw new Error('Server returned HTML instead of JSON: ' + text.substring(0, 200));
                });
            }
            return response.json();
        })
        .then(data => {
            document.getElementById('loadingBox').classList.remove('active');
            if (data.success) {
                showAlert('success', data.message);
                setTimeout(() => {
                    window.location.href = 'member_dashboard.php';
                }, 1500);
            } else {
                showAlert('error', 'Error: ' + data.message);
            }
        })
        .catch(error => {
            document.getElementById('loadingBox').classList.remove('active');
            showAlert('error', 'An error occurred: ' + error.message);
            console.error('Full error:', error);
        });
    });

    function showAlert(type, message) {
        const alertBox = document.getElementById('alertBox');
        alertBox.className = 'alert alert-' + (type === 'success' ? 'success' : 'error') + ' show';
        alertBox.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i><span>${message}</span>`;
        alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function hideAlert() {
        const alertBox = document.getElementById('alertBox');
        alertBox.classList.remove('show');
    }
    </script>
</body>
</html>