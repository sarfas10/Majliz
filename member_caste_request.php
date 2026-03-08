<?php
// member_caste_request.php — Islamic Caste Certificate Application (Member)
declare(strict_types=1);

require_once __DIR__ . '/session_bootstrap.php';

/* --- member auth gate --- */
if (empty($_SESSION['member_login']) || $_SESSION['member_login'] !== true || empty($_SESSION['member'])) {
    header("Location: index.php");
    exit();
}

$memberSess = $_SESSION['member'];

/* Household head id */
$household_member_id = (isset($memberSess['member_type']) && $memberSess['member_type'] === 'family' && !empty($memberSess['parent_member_id']))
    ? (int)$memberSess['parent_member_id']
    : (int)$memberSess['member_id'];

require_once __DIR__ . '/db_connection.php';
$db = get_db_connection();
if (isset($db['error'])) {
    die("DB error: " . htmlspecialchars($db['error']));
}

$conn = $db['conn'];

/* --- Ensure cert_requests table exists --- */
$createTableSql = "
    CREATE TABLE IF NOT EXISTS cert_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        member_id INT UNSIGNED NOT NULL,
        certificate_type VARCHAR(50) NOT NULL,
        details_json LONGTEXT NULL,
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

/* fetch mahal details */
$mahal_id = (int)($memberSess['mahal_id'] ?? 0);
$mahal_name = $mahal_address = $mahal_reg = '';

if ($mahal_id > 0) {
    $sql = "SELECT name, address, registration_no FROM register WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $mahal_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $mahal_name    = $row['name'] ?? '';
        $mahal_address = $row['address'] ?? '';
        $mahal_reg     = $row['registration_no'] ?? '';
    }
    $stmt->close();
}

/* Get member details for sidebar */
$member = null;
try {
    $stmt = $conn->prepare("SELECT * FROM members WHERE id = ?");
    $stmt->bind_param("i", $household_member_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $member = $result->fetch_assoc();
    $stmt->close();
} catch (Exception $e) {
    $member = null;
}

/* --- helper: CSRF token --- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$prefill_notes = $_SESSION['pending_cert_notes'] ?? '';
$error_message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (empty($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $errors[] = 'Invalid request token.';
    }

    $applicant_prefix = trim($_POST['applicant_prefix'] ?? '');
    $applicant_name   = trim($_POST['applicant_name'] ?? '');
    $relation_type    = trim($_POST['relation_type'] ?? '');
    $parent_prefix    = trim($_POST['parent_prefix'] ?? '');
    $parent_name      = trim($_POST['parent_name'] ?? '');
    $applicant_dob    = $_POST['applicant_dob'] ?? '';
    $applicant_address= trim($_POST['applicant_address'] ?? '');

    $village_name     = trim($_POST['village_name'] ?? '');
    $taluk_name       = trim($_POST['taluk_name'] ?? '');
    $district_name    = trim($_POST['district_name'] ?? '');
    $state_name       = trim($_POST['state_name'] ?? '');
    $caste_name       = trim($_POST['caste_name'] ?? '');

    $requested_by     = trim($_POST['requested_by'] ?? '');
    $signed_by        = trim($_POST['signed_by'] ?? '');
    $application_date = $_POST['application_date'] ?? '';
    $notes            = trim($_POST['notes'] ?? '');

    // Enhanced validation
    $validation_errors = [];
    
    // Applicant validation
    if ($applicant_prefix === '') {
        $validation_errors[] = 'Applicant prefix is required';
    }
    
    if ($applicant_name === '') {
        $validation_errors[] = 'Applicant full name is required';
    } elseif (strlen($applicant_name) > 190) {
        $validation_errors[] = 'Applicant name is too long (max 190 characters)';
    } elseif (!preg_match('/^[A-Za-z\s\.\-]+$/', $applicant_name)) {
        $validation_errors[] = 'Applicant name can only contain letters, spaces, dots, and hyphens';
    }
    
    if ($relation_type === '') {
        $validation_errors[] = 'Relation type is required';
    }
    
    if ($parent_prefix === '') {
        $validation_errors[] = 'Parent prefix is required';
    }
    
    if ($parent_name === '') {
        $validation_errors[] = 'Parent/Guardian name is required';
    } elseif (strlen($parent_name) > 190) {
        $validation_errors[] = 'Parent name is too long (max 190 characters)';
    } elseif (!preg_match('/^[A-Za-z\s\.\-\']+$/', $parent_name)) {
        $validation_errors[] = 'Parent name can only contain letters, spaces, dots, hyphens, and apostrophes';
    }
    
    if ($applicant_dob !== '') {
        $dob_date = DateTime::createFromFormat('Y-m-d', $applicant_dob);
        if (!$dob_date) {
            $validation_errors[] = 'Applicant date of birth is invalid';
        } else {
            $today = new DateTime();
            if ($dob_date > $today) {
                $validation_errors[] = 'Applicant date of birth cannot be in the future';
            }
        }
    }
    
    if ($applicant_address === '') {
        $validation_errors[] = 'Applicant address is required';
    } elseif (strlen($applicant_address) > 500) {
        $validation_errors[] = 'Applicant address is too long (max 500 characters)';
    } elseif (!preg_match('/^[A-Za-z0-9\s\.\,\-\/\#\&\(\)\:\;\'"]+$/', $applicant_address)) {
        $validation_errors[] = 'Applicant address contains invalid characters';
    }

    // Location validation
    if ($village_name === '') {
        $validation_errors[] = 'Village/locality is required';
    } elseif (strlen($village_name) > 150) {
        $validation_errors[] = 'Village name is too long (max 150 characters)';
    } elseif (!preg_match('/^[A-Za-z0-9\s\.\,\-\/\#\&\(\)]+$/', $village_name)) {
        $validation_errors[] = 'Village name contains invalid characters';
    }
    
    if ($taluk_name === '') {
        $validation_errors[] = 'Taluk/Tehsil is required';
    } elseif (strlen($taluk_name) > 150) {
        $validation_errors[] = 'Taluk name is too long (max 150 characters)';
    } elseif (!preg_match('/^[A-Za-z0-9\s\.\,\-\/\#\&\(\)]+$/', $taluk_name)) {
        $validation_errors[] = 'Taluk name contains invalid characters';
    }
    
    if ($district_name === '') {
        $validation_errors[] = 'District is required';
    } elseif (strlen($district_name) > 150) {
        $validation_errors[] = 'District name is too long (max 150 characters)';
    } elseif (!preg_match('/^[A-Za-z0-9\s\.\,\-\/\#\&\(\)]+$/', $district_name)) {
        $validation_errors[] = 'District name contains invalid characters';
    }
    
    if ($state_name === '') {
        $validation_errors[] = 'State is required';
    } elseif (strlen($state_name) > 150) {
        $validation_errors[] = 'State name is too long (max 150 characters)';
    } elseif (!preg_match('/^[A-Za-z\s\.\-\'\(\)]+$/', $state_name)) {
        $validation_errors[] = 'State name contains invalid characters';
    }
    
    if ($caste_name === '') {
        $validation_errors[] = 'Caste/Community is required';
    } elseif (strlen($caste_name) > 150) {
        $validation_errors[] = 'Caste name is too long (max 150 characters)';
    } elseif (!preg_match('/^[A-Za-z\s\.\,\-\/\'\(\)]+$/', $caste_name)) {
        $validation_errors[] = 'Caste name contains invalid characters';
    }

    // Request validation
    if ($requested_by === '') {
        $validation_errors[] = 'Requested by field is required';
    } elseif (strlen($requested_by) > 120) {
        $validation_errors[] = 'Requested by name is too long (max 120 characters)';
    } elseif (!preg_match('/^[A-Za-z\s\.\-\']+$/', $requested_by)) {
        $validation_errors[] = 'Requested by name can only contain letters, spaces, dots, hyphens, and apostrophes';
    }
    
    if ($signed_by === '') {
        $validation_errors[] = 'Signed by field is required';
    } elseif (strlen($signed_by) > 120) {
        $validation_errors[] = 'Signed by name is too long (max 120 characters)';
    } elseif (!preg_match('/^[A-Za-z\s\.\-\']+$/', $signed_by)) {
        $validation_errors[] = 'Signed by name can only contain letters, spaces, dots, hyphens, and apostrophes';
    }
    
    if ($application_date === '') {
        $validation_errors[] = 'Application date is required';
    } else {
        $app_date = DateTime::createFromFormat('Y-m-d', $application_date);
        if (!$app_date) {
            $validation_errors[] = 'Application date is invalid';
        } else {
            $today = new DateTime();
            if ($app_date > $today) {
                $validation_errors[] = 'Application date cannot be in the future';
            }
        }
    }
    
    if ($notes !== '' && strlen($notes) > 1000) {
        $validation_errors[] = 'Notes are too long (max 1000 characters)';
    }

    if (!empty($validation_errors)) {
        $errors = array_merge($errors, $validation_errors);
    } else {
        try {
            $details = [
                'applicant_prefix'  => $applicant_prefix,
                'applicant_name'    => $applicant_name,
                'relation_type'     => $relation_type,
                'parent_prefix'     => $parent_prefix,
                'parent_name'       => $parent_name,
                'applicant_dob'     => $applicant_dob,
                'applicant_address' => $applicant_address,
                'village_name'      => $village_name,
                'taluk_name'        => $taluk_name,
                'district_name'     => $district_name,
                'state_name'        => $state_name,
                'caste_name'        => $caste_name,
                'requested_by'      => $requested_by,
                'signed_by'         => $signed_by,
                'application_date'  => $application_date,
                'source'            => 'member_caste_form',
                'mahal_context'     => [
                    'mahal_id'   => $mahal_id,
                    'mahal_name' => $mahal_name,
                    'mahal_reg'  => $mahal_reg,
                ],
            ];

            $details_json = json_encode($details, JSON_UNESCAPED_UNICODE);

            $sql = "INSERT INTO cert_requests
                        (member_id, certificate_type, details_json, notes, status, created_at)
                    VALUES
                        (?, 'caste', ?, ?, 'pending', NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('iss', $household_member_id, $details_json, $notes);
            $stmt->execute();
            $stmt->close();

            unset($_SESSION['pending_cert_member_id'], $_SESSION['pending_cert_notes']);

            $conn->close();
            header("Location: member_dashboard.php?cert_request=caste_success");
            exit();

        } catch (Throwable $e) {
            error_log("Caste cert request insert error (member): " . $e->getMessage());
            $errors[] = "An error occurred while saving your request. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Islamic Caste Certificate — Member Request</title>
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
        max-width: 1200px;
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
    .form-section h3{
        font-size:1.1rem;
        font-weight:600;
        margin:1.5rem 0 1rem;
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
    input.invalid, select.invalid, textarea.invalid {
        border-color: var(--danger) !important;
        background-color: var(--danger-light);
    }
    
    .validation-error {
        color: var(--danger);
        font-size: 0.75rem;
        margin-top: 0.25rem;
        display: none;
        align-items: center;
        gap: 0.25rem;
        opacity: 0;
        transform: translateY(-5px);
        transition: all 0.3s ease;
    }
    
    .validation-error.show {
        opacity: 1;
        transform: translateY(0);
        display: flex;
    }
    
    .validation-error i {
        font-size: 0.9rem;
    }
    
    .validation-success {
        display: none !important;
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

    .readonly-box{
        background:linear-gradient(135deg, var(--light), #f1f5f9);
        border:2px dashed var(--border);
        border-radius:12px;
        padding:1.5rem;
        margin-bottom:2rem;
    }
    .readonly-box h4{
        margin:0 0 .5rem;
        color:var(--dark);
        font-size:1.1rem;
    }
    .readonly-box p{
        margin:.25rem 0;
        color:var(--text-secondary);
        font-size:.9rem;
    }
    
    .readonly-field{
        background:#f3f4f6;
        border-style:dashed;
        color:var(--text-secondary);
    }

    /* Responsive */
    @media (min-width: 1024px) {
        .sidebar { transform: none; }
        .sidebar-overlay { display: none; }
        .main-with-sidebar { margin-left: 288px; width: calc(100% - 288px); }
        .floating-menu-btn { display: none !important; }
        .sidebar-close { display: none; }
        .main-container{ max-width: calc(1200px - 288px); }
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

          <button class="menu-btn" type="button" onclick="window.location.href='add_family_member_self.php'">
            <i class="fas fa-user-plus"></i>
            <span>Add Family Member</span>
          </button>

          <button class="menu-btn" type="button" onclick="window.location.href='member_marriage_request.php'">
            <i class="fas fa-ring"></i>
            <span>Marriage Certificate</span>
          </button>

          <button class="menu-btn active" type="button">
            <i class="fas fa-certificate"></i>
            <span>Caste Certificate</span>
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
            <i class="fas fa-certificate"></i>
            <span style="font-weight: bold; font-size: 22px; color: black;">Islamic Caste Certificate Request</span>
          </div>
          <a href="member_dashboard.php" class="btn btn-primary">← Back to Dashboard</a>
        </div>
      </section>

      <div class="main-container">
        <div id="alertBox" class="alert"></div>
        <div id="loadingBox" class="loading"><p>💾 Submitting request...</p></div>

        <?php if (!empty($errors)): ?>
          <div class="alert alert-error show">
            <i class="fas fa-exclamation-circle"></i>
            <div>
              <strong>Please fix the following errors:</strong>
              <?php foreach ($errors as $error): ?>
                <div>• <?php echo htmlspecialchars($error); ?></div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="readonly-box">
          <h4><?php echo htmlspecialchars($mahal_name); ?></h4>
          <p><?php echo nl2br(htmlspecialchars($mahal_address)); ?></p>
          <p><strong>REG. NO:</strong> <?php echo htmlspecialchars($mahal_reg); ?></p>
        </div>

        <form id="casteForm" method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

          <div class="form-section">
            <h2>Islamic Caste Certificate Application</h2>
            <div class="help">Please provide complete details. All fields marked with * are required.</div>

            <h3>Applicant Details</h3>
            <div class="form-grid">
              <!-- Applicant Prefix -->
              <div class="form-group">
                <label>Applicant Prefix <span class="required">*</span></label>
                <select name="applicant_prefix" id="applicant_prefix" required>
                  <option value="">Select Prefix</option>
                  <option value="Mr.">Mr.</option>
                  <option value="Mrs.">Mrs.</option>
                  <option value="Miss.">Miss.</option>
                </select>
                <div class="validation-error" id="applicant_prefix-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Prefix is required</span>
                </div>
              </div>

              <!-- Applicant Name -->
              <div class="form-group">
                <label>Applicant Full Name <span class="required">*</span></label>
                <input type="text" name="applicant_name" id="applicant_name" placeholder="Enter applicant's full name" required maxlength="190">
                <div class="validation-error" id="applicant_name-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Name is required (2-190 characters, only letters, spaces, dots, hyphens)</span>
                </div>
              </div>

              <!-- Relation Type -->
              <div class="form-group">
                <label>Relation Type <span class="required">*</span></label>
                <select name="relation_type" id="relation_type" required>
                  <option value="">Select Relation</option>
                  <option value="Son of">Son of</option>
                  <option value="Daughter of">Daughter of</option>
                  <option value="Guardian of">Guardian of</option>
                </select>
                <div class="validation-error" id="relation_type-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Relation type is required</span>
                </div>
              </div>

              <!-- Parent Prefix -->
              <div class="form-group">
                <label>Parent Prefix <span class="required">*</span></label>
                <select name="parent_prefix" id="parent_prefix" required>
                  <option value="">Select Prefix</option>
                  <option value="Mr.">Mr.</option>
                  <option value="Mrs.">Mrs.</option>
                </select>
                <div class="validation-error" id="parent_prefix-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Parent prefix is required</span>
                </div>
              </div>

              <!-- Parent Name -->
              <div class="form-group">
                <label>Parent / Guardian Name <span class="required">*</span></label>
                <input type="text" name="parent_name" id="parent_name" placeholder="Enter parent/guardian name" required maxlength="190">
                <div class="validation-error" id="parent_name-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Parent name is required (2-190 characters, only letters, spaces, dots, hyphens, apostrophes)</span>
                </div>
              </div>

              <!-- Applicant DOB -->
              <div class="form-group">
                <label>Date of Birth</label>
                <input type="date" name="applicant_dob" id="applicant_dob">
                <div class="validation-error" id="applicant_dob-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Valid date required (cannot be in future)</span>
                </div>
              </div>

              <!-- Applicant Address -->
              <div class="form-group full">
                <label>Applicant Address <span class="required">*</span></label>
                <textarea name="applicant_address" id="applicant_address" rows="3" placeholder="Complete residential address" required maxlength="500"></textarea>
                <div class="validation-error" id="applicant_address-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Address is required (5-500 characters, alphanumeric with common punctuation)</span>
                </div>
              </div>
            </div>

            <h3>Location Details</h3>
            <div class="form-grid">
              <!-- Village Name -->
              <div class="form-group">
                <label>Village / Locality <span class="required">*</span></label>
                <input type="text" name="village_name" id="village_name" placeholder="Enter village/locality" required maxlength="150">
                <div class="validation-error" id="village_name-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Village is required (max 150 characters)</span>
                </div>
              </div>

              <!-- Taluk Name -->
              <div class="form-group">
                <label>Taluk / Tehsil <span class="required">*</span></label>
                <input type="text" name="taluk_name" id="taluk_name" placeholder="Enter taluk/tehsil" required maxlength="150">
                <div class="validation-error" id="taluk_name-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Taluk is required (max 150 characters)</span>
                </div>
              </div>

              <!-- District Name -->
              <div class="form-group">
                <label>District <span class="required">*</span></label>
                <input type="text" name="district_name" id="district_name" placeholder="Enter district" required maxlength="150">
                <div class="validation-error" id="district_name-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>District is required (max 150 characters)</span>
                </div>
              </div>

              <!-- State Name -->
              <div class="form-group">
                <label>State <span class="required">*</span></label>
                <input type="text" name="state_name" id="state_name" placeholder="Enter state" required maxlength="150">
                <div class="validation-error" id="state_name-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>State is required (max 150 characters, only letters, spaces, dots, hyphens, apostrophes, parentheses)</span>
                </div>
              </div>

              <!-- Caste Name -->
              <div class="form-group full">
                <label>Caste / Community <span class="required">*</span></label>
                <input type="text" name="caste_name" id="caste_name" placeholder="Enter caste/community" required maxlength="150">
                <div class="validation-error" id="caste_name-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Caste is required (max 150 characters, only letters, spaces, dots, commas, hyphens, apostrophes, parentheses, slashes)</span>
                </div>
              </div>
            </div>

            <h3>Request Details</h3>
            <div class="form-grid">
              <!-- Requested By -->
              <div class="form-group">
                <label>Requested By <span class="required">*</span></label>
                <input type="text" name="requested_by" id="requested_by" placeholder="Person requesting certificate" required maxlength="120">
                <div class="validation-error" id="requested_by-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Required (2-120 characters, only letters, spaces, dots, hyphens, apostrophes)</span>
                </div>
              </div>

              <!-- Signed By -->
              <div class="form-group">
                <label>Signed By (President/Secretary) <span class="required">*</span></label>
                <input type="text" name="signed_by" id="signed_by" placeholder="Official signatory" required maxlength="120">
                <div class="validation-error" id="signed_by-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Required (2-120 characters, only letters, spaces, dots, hyphens, apostrophes)</span>
                </div>
              </div>

              <!-- Application Date -->
              <div class="form-group">
                <label>Application Date <span class="required">*</span></label>
                <input type="date" name="application_date" id="application_date" required>
                <div class="validation-error" id="application_date-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Valid date required (cannot be in future)</span>
                </div>
              </div>

              <!-- Notes -->
              <div class="form-group full">
                <label>Notes (Optional)</label>
                <textarea name="notes" id="notes" rows="3" placeholder="Additional information or special requests" maxlength="1000"><?php echo htmlspecialchars($prefill_notes); ?></textarea>
                <div class="validation-error" id="notes-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Maximum 1000 characters allowed</span>
                </div>
              </div>
            </div>

            <div class="actions" style="margin-top: 2rem;">
              <div class="actions-left">
                <a href="member_dashboard.php" class="btn btn-secondary">Cancel</a>
                <button type="button" class="btn btn-secondary" id="clearBtn">Clear Form</button>
              </div>
              <div class="actions-right">
                <button type="submit" class="btn btn-success" id="submitBtn">
                  <i class="fas fa-paper-plane"></i> Submit Caste Certificate Request
                </button>
              </div>
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
      if (!name || name.trim().length < 2 || name.trim().length > 190) return false;
      // Allow alphabets, spaces, dots, and hyphens
      const nameRegex = /^[A-Za-z\s\.\-]+$/;
      return nameRegex.test(name.trim());
  }

  function isValidParentName(name) {
      if (!name || name.trim().length < 2 || name.trim().length > 190) return false;
      // Allow alphabets, spaces, dots, hyphens, and apostrophes
      const nameRegex = /^[A-Za-z\s\.\-\']+$/;
      return nameRegex.test(name.trim());
  }

  function isValidDate(dateStr, allowFuture = false) {
      if (!dateStr) return false;
      const date = new Date(dateStr);
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      
      if (isNaN(date.getTime())) return false;
      if (!allowFuture && date > today) return false;
      return true;
  }

  function isValidText(text, minLength = 1, maxLength = 500) {
      const trimmed = text.trim();
      if (trimmed.length < minLength || trimmed.length > maxLength) return false;
      return true;
  }

  function isValidAddress(address) {
      if (!address || address.trim().length < 5 || address.trim().length > 500) return false;
      // Allow alphanumeric, spaces, and common address punctuation
      const addressRegex = /^[A-Za-z0-9\s\.\,\-\/\#\&\(\)\:\;\'\"]+$/;
      return addressRegex.test(address.trim());
  }

  function isValidLocationName(name) {
      if (!name || name.trim().length < 2 || name.trim().length > 150) return false;
      // Allow alphanumeric, spaces, and common location punctuation
      const locationRegex = /^[A-Za-z0-9\s\.\,\-\/\#\&\(\)]+$/;
      return locationRegex.test(name.trim());
  }

  function isValidStateName(name) {
      if (!name || name.trim().length < 2 || name.trim().length > 150) return false;
      // Allow alphabets, spaces, dots, hyphens, apostrophes, parentheses for state names
      const stateRegex = /^[A-Za-z\s\.\-\'\(\)]+$/;
      return stateRegex.test(name.trim());
  }

  function isValidCasteName(name) {
      if (!name || name.trim().length < 2 || name.trim().length > 150) return false;
      // Allow alphabets, spaces, dots, commas, hyphens, apostrophes, parentheses, slashes
      const casteRegex = /^[A-Za-z\s\.\,\-\/\'\(\)]+$/;
      return casteRegex.test(name.trim());
  }

  function isValidPersonName(name, optional = false) {
      if (optional && name === '') return true;
      if (!name || name.trim().length < 2 || name.trim().length > 120) return false;
      const nameRegex = /^[A-Za-z\s\.\-\']+$/;
      return nameRegex.test(name.trim());
  }

  // Track which fields have been interacted with
  const touchedFields = new Set();

  // Show/hide validation messages
  function showValidation(elementId, isValid, isRequired = false) {
      const errorElement = document.getElementById(elementId + '-error');
      const inputElement = document.getElementById(elementId);
      
      if (errorElement) {
          if (!isValid && (isRequired || (inputElement && inputElement.value.trim() !== ''))) {
              errorElement.classList.add('show');
              if (inputElement) {
                  inputElement.classList.add('invalid');
                  inputElement.classList.remove('valid');
              }
          } else {
              errorElement.classList.remove('show');
              if (inputElement) {
                  inputElement.classList.remove('invalid');
                  inputElement.classList.remove('valid');
              }
          }
      }
  }

  // Force show validation for a field (used when submitting)
  function forceShowValidation(elementId, isValid, isRequired = false) {
      const errorElement = document.getElementById(elementId + '-error');
      const inputElement = document.getElementById(elementId);
      
      // Mark as touched so validation shows
      touchedFields.add(elementId);
      
      if (errorElement) {
          if (!isValid && (isRequired || (inputElement && inputElement.value.trim() !== ''))) {
              errorElement.classList.add('show');
              if (inputElement) {
                  inputElement.classList.add('invalid');
                  inputElement.classList.remove('valid');
              }
          } else {
              errorElement.classList.remove('show');
              if (inputElement) {
                  inputElement.classList.remove('invalid');
                  inputElement.classList.remove('valid');
              }
          }
      }
  }

  // Mark field as touched (for real-time validation)
  function markAsTouched(elementId) {
      touchedFields.add(elementId);
      const inputElement = document.getElementById(elementId);
      
      // Validate immediately when touched
      let isValid = false;
      
      if (elementId === 'applicant_name') {
          isValid = isValidName(inputElement.value);
      } else if (elementId === 'parent_name') {
          isValid = isValidParentName(inputElement.value);
      } else if (elementId === 'applicant_dob' || elementId === 'application_date') {
          isValid = isValidDate(inputElement.value);
      } else if (elementId === 'applicant_address') {
          isValid = isValidAddress(inputElement.value);
      } else if (elementId === 'village_name' || elementId === 'taluk_name' || elementId === 'district_name') {
          isValid = isValidLocationName(inputElement.value);
      } else if (elementId === 'state_name') {
          isValid = isValidStateName(inputElement.value);
      } else if (elementId === 'caste_name') {
          isValid = isValidCasteName(inputElement.value);
      } else if (elementId === 'requested_by' || elementId === 'signed_by') {
          isValid = isValidPersonName(inputElement.value, false);
      } else if (elementId === 'applicant_prefix' || elementId === 'relation_type' || elementId === 'parent_prefix') {
          isValid = inputElement.value !== '';
      } else if (elementId === 'notes') {
          isValid = inputElement.value === '' || isValidText(inputElement.value, 1, 1000);
      }
      
      showValidation(elementId, isValid, elementId !== 'notes' && elementId !== 'applicant_dob');
  }

  // Attach validation events for real-time feedback
  const allFields = [
      'applicant_prefix', 'applicant_name', 'relation_type', 'parent_prefix', 'parent_name',
      'applicant_dob', 'applicant_address', 'village_name', 'taluk_name', 'district_name',
      'state_name', 'caste_name', 'requested_by', 'signed_by', 'application_date', 'notes'
  ];

  allFields.forEach(fieldId => {
      const element = document.getElementById(fieldId);
      if (!element) return;
      
      element.addEventListener('blur', function() {
          markAsTouched(fieldId);
          let isValid = false;
          
          if (fieldId === 'applicant_name') {
              isValid = isValidName(this.value);
          } else if (fieldId === 'parent_name') {
              isValid = isValidParentName(this.value);
          } else if (fieldId === 'applicant_dob' || fieldId === 'application_date') {
              isValid = isValidDate(this.value);
          } else if (fieldId === 'applicant_address') {
              isValid = isValidAddress(this.value);
          } else if (fieldId === 'village_name' || fieldId === 'taluk_name' || fieldId === 'district_name') {
              isValid = isValidLocationName(this.value);
          } else if (fieldId === 'state_name') {
              isValid = isValidStateName(this.value);
          } else if (fieldId === 'caste_name') {
              isValid = isValidCasteName(this.value);
          } else if (fieldId === 'requested_by' || fieldId === 'signed_by') {
              isValid = isValidPersonName(this.value, false);
          } else if (fieldId === 'applicant_prefix' || fieldId === 'relation_type' || fieldId === 'parent_prefix') {
              isValid = this.value !== '';
          } else if (fieldId === 'notes') {
              isValid = this.value === '' || isValidText(this.value, 1, 1000);
          }
          
          showValidation(fieldId, isValid, fieldId !== 'notes' && fieldId !== 'applicant_dob');
      });
      
      // Real-time validation after first touch
      element.addEventListener('input', function() {
          if (touchedFields.has(fieldId)) {
              let isValid = false;
              
              if (fieldId === 'applicant_name') {
                  isValid = isValidName(this.value);
              } else if (fieldId === 'parent_name') {
                  isValid = isValidParentName(this.value);
              } else if (fieldId === 'applicant_dob' || fieldId === 'application_date') {
                  isValid = isValidDate(this.value);
              } else if (fieldId === 'applicant_address') {
                  isValid = isValidAddress(this.value);
              } else if (fieldId === 'village_name' || fieldId === 'taluk_name' || fieldId === 'district_name') {
                  isValid = isValidLocationName(this.value);
              } else if (fieldId === 'state_name') {
                  isValid = isValidStateName(this.value);
              } else if (fieldId === 'caste_name') {
                  isValid = isValidCasteName(this.value);
              } else if (fieldId === 'requested_by' || fieldId === 'signed_by') {
                  isValid = isValidPersonName(this.value, false);
              } else if (fieldId === 'applicant_prefix' || fieldId === 'relation_type' || fieldId === 'parent_prefix') {
                  isValid = this.value !== '';
              } else if (fieldId === 'notes') {
                  isValid = this.value === '' || isValidText(this.value, 1, 1000);
              }
              
              showValidation(fieldId, isValid, fieldId !== 'notes' && fieldId !== 'applicant_dob');
          }
      });
  });

  // Clear form functionality
  document.getElementById('clearBtn').addEventListener('click', function() {
      if (confirm('Are you sure you want to clear all form data?')) {
          document.getElementById('casteForm').reset();
          hideAlert();
          
          // Reset touched fields
          touchedFields.clear();
          
          // Remove all validation classes
          document.querySelectorAll('.validation-error').forEach(el => {
              el.classList.remove('show');
          });
          document.querySelectorAll('input, select, textarea').forEach(el => {
              el.classList.remove('valid', 'invalid');
          });
      }
  });

  // Group validation errors by section
  function groupErrorsBySection(errors) {
      const sections = {
          'applicant': [],
          'location': [],
          'request': []
      };
      
      errors.forEach(error => {
          const field = error.field;
          if (field.includes('applicant') || field.includes('parent') || field.includes('relation') || field.includes('dob')) {
              sections.applicant.push(error);
          } else if (field.includes('village') || field.includes('taluk') || field.includes('district') || field.includes('state') || field.includes('caste')) {
              sections.location.push(error);
          } else {
              sections.request.push(error);
          }
      });
      
      return sections;
  }

  // Show section-wise error summary
 

  // Form submission - validation only on submit
  document.getElementById('casteForm').addEventListener('submit', function(e) {
      e.preventDefault();

      // Clear previous alerts
      hideAlert();
      
      // Track all errors
      const validationErrors = [];
      
      // List of required fields for validation
      const requiredFields = [
          'applicant_prefix', 'applicant_name', 'relation_type', 'parent_prefix', 'parent_name',
          'applicant_address', 'village_name', 'taluk_name', 'district_name', 'state_name',
          'caste_name', 'requested_by', 'signed_by', 'application_date'
      ];

      const optionalFields = ['applicant_dob', 'notes'];
      
      // Force validation check for all required fields
      requiredFields.forEach(fieldId => {
          const element = document.getElementById(fieldId);
          if (!element) return;
          
          let isValid = false;
          let errorMessage = '';
          
          if (fieldId === 'applicant_name') {
              isValid = isValidName(element.value);
              errorMessage = 'Applicant name is required';
          } else if (fieldId === 'parent_name') {
              isValid = isValidParentName(element.value);
              errorMessage = 'Parent name is required';
          } else if (fieldId === 'applicant_dob' || fieldId === 'application_date') {
              isValid = isValidDate(element.value);
              errorMessage = fieldId === 'applicant_dob' ? 'Valid date of birth required' : 'Valid application date required';
          } else if (fieldId === 'applicant_address') {
              isValid = isValidAddress(element.value);
              errorMessage = 'Applicant address is required';
          } else if (fieldId === 'village_name' || fieldId === 'taluk_name' || fieldId === 'district_name') {
              isValid = isValidLocationName(element.value);
              errorMessage = fieldId.replace('_', ' ') + ' is required';
          } else if (fieldId === 'state_name') {
              isValid = isValidStateName(element.value);
              errorMessage = 'State is required';
          } else if (fieldId === 'caste_name') {
              isValid = isValidCasteName(element.value);
              errorMessage = 'Caste/Community is required';
          } else if (fieldId === 'requested_by' || fieldId === 'signed_by') {
              isValid = isValidPersonName(element.value, false);
              errorMessage = fieldId.replace('_', ' ') + ' is required';
          } else if (fieldId === 'applicant_prefix' || fieldId === 'relation_type' || fieldId === 'parent_prefix') {
              isValid = element.value !== '';
              errorMessage = fieldId.replace('_', ' ') + ' is required';
          }
          
          // Force show validation error
          forceShowValidation(fieldId, isValid, true);
          
          if (!isValid) {
              validationErrors.push({
                  field: fieldId,
                  message: errorMessage
              });
          }
      });
      
      // Validate optional fields too
      optionalFields.forEach(fieldId => {
          const element = document.getElementById(fieldId);
          if (!element) return;
          
          let isValid = false;
          let errorMessage = '';
          
          if (fieldId === 'applicant_dob') {
              isValid = element.value === '' || isValidDate(element.value);
              errorMessage = 'Valid date of birth required';
          } else if (fieldId === 'notes') {
              isValid = element.value === '' || isValidText(element.value, 1, 1000);
              errorMessage = 'Notes are too long (max 1000 characters)';
          }
          
          // Only show error if field has value but is invalid
          if (element.value.trim() !== '') {
              forceShowValidation(fieldId, isValid, false);
              if (!isValid) {
                  validationErrors.push({
                      field: fieldId,
                      message: errorMessage
                  });
              }
          }
      });
      
      if (validationErrors.length > 0) {
          // Group errors by section and show summary
          const groupedErrors = groupErrorsBySection(validationErrors);
          showSectionErrors(groupedErrors);
          
          // Scroll to first error section
          const firstErrorField = validationErrors[0].field;
          const firstErrorElement = document.getElementById(firstErrorField);
          if (firstErrorElement) {
              // Find the section heading
              let section = firstErrorElement.closest('.form-section');
              if (section) {
                  section.scrollIntoView({ behavior: 'smooth', block: 'start' });
              }
          }
          
          return false;
      }

      // If all valid, submit the form
      const form = this;
      const formData = new FormData(form);

      document.getElementById('loadingBox').classList.add('active');
      hideAlert();

      fetch('member_caste_request.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin'
      })
      .then(response => {
          if (response.redirected) {
              window.location.href = response.url;
              return;
          }
          
          const contentType = response.headers.get('content-type');
          if (!contentType || !contentType.includes('text/html')) {
              return response.text().then(text => {
                  throw new Error('Server returned unexpected response: ' + text.substring(0, 200));
              });
          }
          return response.text();
      })
      .then(html => {
          document.getElementById('loadingBox').classList.remove('active');
          // If we get here and it's not a redirect, there might be an error
          // Reload the page to show server-side validation errors
          location.reload();
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
      alertBox.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i><div>${message}</div>`;
      alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function hideAlert() {
      const alertBox = document.getElementById('alertBox');
      alertBox.classList.remove('show');
  }
  </script>
</body>
</html>