<?php
// member_termination_request.php — Membership Termination Certificate Request
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

// Determine if Sahakari member
$is_sahakari = (isset($memberSess['member_type']) && $memberSess['member_type'] === 'sahakari_head');

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
        reg_number VARCHAR(50) NULL,
        details_json LONGTEXT NULL,
        groom_photo LONGBLOB NULL,
        bride_photo LONGBLOB NULL,
        member_photo LONGBLOB NULL,
        notes TEXT NULL,
        status ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
        approved_by INT UNSIGNED NULL,
        output_file VARCHAR(255) NULL,
        output_blob LONGBLOB NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_member (member_id),
        INDEX idx_status (status),
        INDEX idx_reg_number (reg_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
$conn->query($createTableSql);

// Ensure member_photo column exists
$resCol = $conn->query("SHOW COLUMNS FROM cert_requests LIKE 'member_photo'");
if ($resCol && $resCol->num_rows === 0) {
    $conn->query("ALTER TABLE cert_requests ADD member_photo LONGBLOB NULL AFTER bride_photo");
}

// Ensure is_sahakari column exists
$resSahakari = $conn->query("SHOW COLUMNS FROM cert_requests LIKE 'is_sahakari'");
if ($resSahakari && $resSahakari->num_rows === 0) {
    $conn->query("ALTER TABLE cert_requests ADD is_sahakari TINYINT(1) NOT NULL DEFAULT 0 AFTER member_id");
}

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
$family_members = [];
try {
    if ($is_sahakari) {
        // Load Sahakari head member
        $stmt = $conn->prepare("SELECT * FROM sahakari_members WHERE id = ?");
        $stmt->bind_param("i", $household_member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $member = $result->fetch_assoc();
        $stmt->close();

        // Load Sahakari family members
        $stmt = $conn->prepare("SELECT name, relationship, dob FROM sahakari_family_members WHERE member_id = ? ORDER BY relationship, name");
        $stmt->bind_param("i", $household_member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $family_members[] = $row;
        }
        $stmt->close();
    } else {
        // Load regular head member
        $stmt = $conn->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->bind_param("i", $household_member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $member = $result->fetch_assoc();
        $stmt->close();

        // Load regular family members
        $stmt = $conn->prepare("SELECT name, relationship, dob FROM family_members WHERE member_id = ? ORDER BY relationship, name");
        $stmt->bind_param("i", $household_member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $family_members[] = $row;
        }
        $stmt->close();
    }
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

/* --- helper: age from DOB --- */
function mt_calc_age_from_dob(?string $dob): ?int {
    if (empty($dob)) return null;
    try {
        $d = new DateTime($dob);
        $now = new DateTime();
        return $now->diff($d)->y;
    } catch (Throwable $e) {
        return null;
    }
}

/* --- helper: build 3-letter mahal code --- */
function mt_build_mahal_code(string $name): string {
    $clean = trim(preg_replace('/\s+/', ' ', $name));
    if ($clean === '') {
        return 'XXX';
    }

    $words = explode(' ', $clean);
    $count = count($words);

    if ($count >= 4) {
        $first  = $words[0];
        $second = $words[1];
        $last   = $words[$count - 1];
        $code = $first[0] . $second[0] . $last[0];
    } elseif ($count === 3) {
        $code = $words[0][0] . $words[1][0] . $words[2][0];
    } elseif ($count === 2) {
        $code = substr($words[0], 0, 2) . $words[1][0];
    } else { // 1 word
        $code = substr($words[0], 0, 3);
    }

    return strtoupper($code);
}

/**
 * Generate next registration number for TERMINATION certificate.
 * Pattern: MAHCODE-TC-XX-YYYY
 */
function generate_termination_reg_number(mysqli $conn, int $memberId, bool $isSahakari): string {
    $mahalName = '';

    if ($isSahakari) {
        $sql = "SELECT r.name FROM sahakari_members m JOIN register r ON m.mahal_id = r.id WHERE m.id = ? LIMIT 1";
    } else {
        $sql = "SELECT r.name FROM members m JOIN register r ON m.mahal_id = r.id WHERE m.id = ? LIMIT 1";
    }

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $stmt->bind_result($name);
        if ($stmt->fetch() && !empty($name)) {
            $mahalName = $name;
        }
        $stmt->close();
    }

    if ($mahalName === '') {
        $res = $conn->query("SELECT name FROM register ORDER BY id ASC LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            $mahalName = $row['name'] ?? 'MAHAL';
        } else {
            $mahalName = 'MAHAL';
        }
    }

    $prefix = mt_build_mahal_code($mahalName);
    $year   = date('Y');

    $like = $prefix . '-TC-%-' . $year;
    $lastRegNumber = null;

    $sql2 = "SELECT reg_number FROM cert_requests WHERE certificate_type = 'termination' AND reg_number LIKE ? ORDER BY id DESC LIMIT 1";
    if ($stmt2 = $conn->prepare($sql2)) {
        $stmt2->bind_param('s', $like);
        $stmt2->execute();
        $stmt2->bind_result($reg);
        if ($stmt2->fetch()) {
            $lastRegNumber = $reg;
        }
        $stmt2->close();
    }

    $seq = 0;
    if ($lastRegNumber) {
        $parts = explode('-', $lastRegNumber);
        if (count($parts) >= 4) {
            $num = (int)$parts[2];
            if ($num > 0) {
                $seq = $num;
            }
        }
    }

    $nextSeq = $seq + 1;
    $seqStr  = str_pad((string)$nextSeq, 2, '0', STR_PAD_LEFT);

    return $prefix . '-TC-' . $seqStr . '-' . $year;
}

/* --- helper: read uploaded image as BLOB (passport photo) --- */
function mt_read_image_blob(string $field): ?string {
    if (empty($_FILES[$field]['tmp_name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $f = new finfo(FILEINFO_MIME_TYPE);
    $mime = $f->file($_FILES[$field]['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        return null;
    }
    $data = file_get_contents($_FILES[$field]['tmp_name']);
    return $data === false ? null : $data;
}

/* --- default values from DB --- */
$head_name   = $member['head_name']   ?? '';
$father_name = $member['father_name'] ?? '';
$dob_value   = $member['dob']         ?? '';
$phone_value = $member['phone']       ?? '';
$qualification = '';
$house_name    = '';
$city_line     = '';
$taluk         = '';
$village_panchayat = '';
$new_mahal   = '';
$member_age_disp = mt_calc_age_from_dob($dob_value);

/* --- pre-generate a reg number for display --- */
$preview_reg_number = '';
if ($conn instanceof mysqli) {
    try {
        $preview_reg_number = generate_termination_reg_number($conn, $household_member_id, $is_sahakari);
    } catch (Throwable $e) {
        // ignore generation error
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (empty($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $errors[] = 'Invalid request token.';
    }

    $head_name   = trim($_POST['head_name'] ?? '');
    $father_name = trim($_POST['father_name'] ?? '');
    $dob_value   = $_POST['dob'] ?? '';
    $qualification = trim($_POST['qualification'] ?? '');
    $house_name    = trim($_POST['house_name'] ?? '');
    $city_line     = trim($_POST['city_line'] ?? '');
    $taluk         = trim($_POST['taluk'] ?? '');
    $village_panchayat = trim($_POST['village_panchayat'] ?? '');
    $phone_value   = trim($_POST['phone'] ?? '');
    $new_mahal     = trim($_POST['new_mahal'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');
    
    // Family members data
    $family_names = $_POST['family_name'] ?? [];
    $family_relationships = $_POST['family_relationship'] ?? [];
    $family_ages = $_POST['family_age'] ?? [];
    $family_occupations = $_POST['family_occupation'] ?? [];

    // Enhanced validation
    $validation_errors = [];
    
    // Member validation
    if ($head_name === '') {
        $validation_errors[] = 'Name is required';
    } elseif (strlen($head_name) > 190) {
        $validation_errors[] = 'Name is too long (max 190 characters)';
    } elseif (!preg_match('/^[A-Za-z\s\.\-]+$/', $head_name)) {
        $validation_errors[] = 'Name can only contain letters, spaces, dots, and hyphens';
    }
    
    if ($father_name === '') {
        $validation_errors[] = "Father's name is required";
    } elseif (strlen($father_name) > 190) {
        $validation_errors[] = "Father's name is too long (max 190 characters)";
    } elseif (!preg_match('/^[A-Za-z\s\.\-\']+$/', $father_name)) {
        $validation_errors[] = "Father's name can only contain letters, spaces, dots, hyphens, and apostrophes";
    }
    
    if ($dob_value === '') {
        $validation_errors[] = 'Date of birth is required';
    } else {
        $dob_date = DateTime::createFromFormat('Y-m-d', $dob_value);
        if (!$dob_date) {
            $validation_errors[] = 'Date of birth is invalid';
        } else {
            $today = new DateTime();
            if ($dob_date > $today) {
                $validation_errors[] = 'Date of birth cannot be in the future';
            }
        }
    }
    
    if ($qualification === '') {
        $validation_errors[] = 'Qualification is required';
    } elseif (strlen($qualification) > 100) {
        $validation_errors[] = 'Qualification is too long (max 100 characters)';
    } elseif (!preg_match('/^[A-Za-z0-9\s\.\,\-\/\#\&\(\)]+$/', $qualification)) {
        $validation_errors[] = 'Qualification contains invalid characters';
    }
    
    if ($house_name === '') {
        $validation_errors[] = 'House name is required';
    } elseif (strlen($house_name) > 150) {
        $validation_errors[] = 'House name is too long (max 150 characters)';
    } elseif (!preg_match('/^[A-Za-z0-9\s\.\,\-\/\#\&\(\)]+$/', $house_name)) {
        $validation_errors[] = 'House name contains invalid characters';
    }
    
    if ($city_line === '') {
        $validation_errors[] = 'City, District & Pincode is required';
    } elseif (strlen($city_line) > 200) {
        $validation_errors[] = 'City/District/Pincode is too long (max 200 characters)';
    } elseif (!preg_match('/^[A-Za-z0-9\s\.\,\-\/\#\&\(\)]+$/', $city_line)) {
        $validation_errors[] = 'City/District/Pincode contains invalid characters';
    }
    
    if ($taluk === '') {
        $validation_errors[] = 'Taluk is required';
    } elseif (strlen($taluk) > 100) {
        $validation_errors[] = 'Taluk is too long (max 100 characters)';
    } elseif (!preg_match('/^[A-Za-z0-9\s\.\,\-\/\#\&\(\)]+$/', $taluk)) {
        $validation_errors[] = 'Taluk contains invalid characters';
    }
    
    if ($village_panchayat === '') {
        $validation_errors[] = 'Village/Panchayat is required';
    } elseif (strlen($village_panchayat) > 150) {
        $validation_errors[] = 'Village/Panchayat is too long (max 150 characters)';
    } elseif (!preg_match('/^[A-Za-z0-9\s\.\,\-\/\#\&\(\)]+$/', $village_panchayat)) {
        $validation_errors[] = 'Village/Panchayat contains invalid characters';
    }
    
    if ($phone_value === '') {
        $validation_errors[] = 'Phone number is required';
    } elseif (strlen($phone_value) > 20) {
        $validation_errors[] = 'Phone number is too long (max 20 characters)';
    } elseif (!preg_match('/^[0-9\s\-\+\(\)]+$/', $phone_value)) {
        $validation_errors[] = 'Phone number contains invalid characters';
    }
    
    if ($new_mahal === '') {
        $validation_errors[] = 'Destination Mahal (Transfer To) is required';
    } elseif (strlen($new_mahal) > 150) {
        $validation_errors[] = 'Destination Mahal name is too long (max 150 characters)';
    } elseif (!preg_match('/^[A-Za-z0-9\s\.\,\-\/\#\&\(\)]+$/', $new_mahal)) {
        $validation_errors[] = 'Destination Mahal name contains invalid characters';
    }
    
    // Photo validation
    $member_photo = mt_read_image_blob('member_photo');
    if ($member_photo === null) {
        $validation_errors[] = 'Passport size photo is required (JPG/PNG only)';
    }
    
    // Family members validation
    $family_errors = [];
    foreach ($family_names as $index => $name) {
        $name = trim($name);
        $relationship = trim($family_relationships[$index] ?? '');
        $age = trim($family_ages[$index] ?? '');
        $occupation = trim($family_occupations[$index] ?? '');
        
        // Skip completely empty rows
        if ($name === '' && $relationship === '' && $age === '' && $occupation === '') {
            continue;
        }
        
        if ($name === '') {
            $family_errors[] = "Family member #" . ($index + 1) . ": Name is required";
        } elseif (strlen($name) > 100) {
            $family_errors[] = "Family member #" . ($index + 1) . ": Name is too long (max 100 characters)";
        } elseif (!preg_match('/^[A-Za-z\s\.\-\']+$/', $name)) {
            $family_errors[] = "Family member #" . ($index + 1) . ": Name contains invalid characters";
        }
        
        if ($relationship === '') {
            $family_errors[] = "Family member #" . ($index + 1) . ": Relationship is required";
        } elseif (strlen($relationship) > 50) {
            $family_errors[] = "Family member #" . ($index + 1) . ": Relationship is too long (max 50 characters)";
        } elseif (!preg_match('/^[A-Za-z\s\.\-\']+$/', $relationship)) {
            $family_errors[] = "Family member #" . ($index + 1) . ": Relationship contains invalid characters";
        }
        
        if ($age !== '') {
            if (!is_numeric($age)) {
                $family_errors[] = "Family member #" . ($index + 1) . ": Age must be a number";
            } elseif ((int)$age < 0 || (int)$age > 120) {
                $family_errors[] = "Family member #" . ($index + 1) . ": Age must be between 0 and 120";
            }
        }
        
        if ($occupation !== '' && strlen($occupation) > 100) {
            $family_errors[] = "Family member #" . ($index + 1) . ": Occupation is too long (max 100 characters)";
        } elseif ($occupation !== '' && !preg_match('/^[A-Za-z0-9\s\.\,\-\/\#\&\(\)]+$/', $occupation)) {
            $family_errors[] = "Family member #" . ($index + 1) . ": Occupation contains invalid characters";
        }
    }
    
    if (!empty($family_errors)) {
        $validation_errors = array_merge($validation_errors, $family_errors);
    }
    
    if ($notes !== '' && strlen($notes) > 1000) {
        $validation_errors[] = 'Notes are too long (max 1000 characters)';
    }

    if (!empty($validation_errors)) {
        $errors = array_merge($errors, $validation_errors);
    } else {
        try {
            // Build address from parts
            $address_parts = [];
            foreach ([$house_name, $city_line, $taluk, $village_panchayat] as $part) {
                $part = trim((string)$part);
                if ($part !== '') {
                    $address_parts[] = $part;
                }
            }
            $address_combined = implode("\n", $address_parts);
            
            $member_age = mt_calc_age_from_dob($dob_value);
            $member_number = isset($member['member_number']) && $member['member_number'] !== ''
                ? (string)$member['member_number']
                : 'M' . str_pad((int)$member['id'], 3, '0', STR_PAD_LEFT);
            
            // Process family members for JSON
            $family_for_json = [];
            foreach ($family_names as $index => $name) {
                $name = trim($name);
                $relationship = trim($family_relationships[$index] ?? '');
                $age = trim($family_ages[$index] ?? '');
                $occupation = trim($family_occupations[$index] ?? '');
                
                // Skip empty rows
                if ($name === '' && $relationship === '' && $age === '' && $occupation === '') {
                    continue;
                }
                
                $family_for_json[] = [
                    'name' => $name,
                    'relationship' => $relationship,
                    'age' => $age !== '' ? (int)$age : null,
                    'occupation' => $occupation,
                ];
            }
            
            $reg_number = generate_termination_reg_number($conn, $household_member_id, $is_sahakari);
            
            $details = [
                'certificate_title'      => 'Membership Termination Certificate',
                'head_name'             => $head_name,
                'father_name'           => $father_name,
                'member_number'         => $member_number,
                'qualification'         => $qualification,
                'house_name'            => $house_name,
                'city_district_pincode' => $city_line,
                'taluk'                 => $taluk,
                'village_panchayat'     => $village_panchayat,
                'address'               => $address_combined,
                'phone'                 => $phone_value,
                'date_of_birth'         => $dob_value,
                'age'                   => $member_age,
                'destination_mahal'     => $new_mahal,
                'reg_number'            => $reg_number,
                'family_members'        => $family_for_json,
                'member_type'           => $is_sahakari ? 'sahakari' : 'regular',
                'source'                => 'member_termination_form',
                'mahal_context'         => [
                    'mahal_id'   => $mahal_id,
                    'mahal_name' => $mahal_name,
                    'mahal_reg'  => $mahal_reg,
                ],
            ];

            $details_json = json_encode($details, JSON_UNESCAPED_UNICODE);
            $is_sahakari_int = $is_sahakari ? 1 : 0;

            $sql = "INSERT INTO cert_requests
                        (member_id, is_sahakari, certificate_type, reg_number, details_json, member_photo, notes, status, created_at)
                    VALUES
                        (?, ?, 'termination', ?, ?, ?, ?, 'pending', NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('iissss', $household_member_id, $is_sahakari_int, $reg_number, $details_json, $member_photo, $notes);
            $stmt->execute();
            $stmt->close();

            unset($_SESSION['pending_cert_member_id'], $_SESSION['pending_cert_notes']);

            $conn->close();
            header("Location: member_dashboard.php?cert_request=termination_success");
            exit();

        } catch (Throwable $e) {
            error_log("Termination cert request insert error (member): " . $e->getMessage());
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
  <title>Membership Termination Certificate — Member Request</title>
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
    .btn-secondary{
        background:linear-gradient(135deg,var(--secondary),#475569);
        border-color:var(--secondary);
        color:#fff;
        box-shadow:var(--shadow)
    }
    .btn-secondary:hover{
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
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
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
    
    /* File input styling */
    input[type="file"] {
        padding: 0.5rem;
        border: 2px dashed var(--border);
        background: var(--light);
        cursor: pointer;
    }
    
    input[type="file"]:hover {
        border-color: var(--primary);
        background: var(--primary-light);
    }
    
    /* Image preview */
    .image-preview-container {
        margin-top: 1rem;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .image-preview {
        max-width: 150px;
        max-height: 150px;
        border-radius: var(--radius-sm);
        border: 2px solid var(--border);
        display: none;
    }
    
    .image-preview.show {
        display: block;
    }
    
    /* Validation Styles */
    input.invalid, select.invalid, textarea.invalid, input[type="file"].invalid {
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
    
    /* Table styles for family members */
    .family-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1rem;
        font-size: 0.875rem;
    }
    
    .family-table th,
    .family-table td {
        border: 1px solid var(--border);
        padding: 0.75rem;
        text-align: left;
    }
    
    .family-table th {
        background: var(--light);
        font-weight: 600;
        color: var(--dark);
    }
    
    .family-table td {
        background: #fff;
    }
    
    .family-input {
        width: 100%;
        padding: 0.5rem;
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
    }
    
    .family-input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 2px var(--primary-light);
    }
    
    .family-actions {
        margin-top: 1rem;
        text-align: right;
    }
    
    .member-type-badge {
        padding: 0.4rem 0.75rem;
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 800;
        letter-spacing: .3px;
        border: 2px solid var(--info);
        background: linear-gradient(135deg, #cffafe, #a5f3fc);
        color: #0e7490;
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
        .family-table {
            display: block;
            overflow-x: auto;
        }
    }

    @media (max-width: 768px){
        .actions{
            flex-direction: column;
            gap: 1rem;
        }
        .actions-left, .actions-right{
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .btn{
            width: 100%;
            justify-content: center;
        }
        .header {
            padding: 1rem;
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
        <div class="profile">
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

          <button class="menu-btn" type="button" onclick="window.location.href='member_caste_request.php'">
            <i class="fas fa-certificate"></i>
            <span>Caste Certificate</span>
          </button>

          <button class="menu-btn active" type="button">
            <i class="fas fa-user-slash"></i>
            <span>Termination Certificate</span>
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
            <i class="fas fa-user-slash"></i>
            <span style="font-weight: bold; font-size: 22px; color: black;">Membership Termination Certificate Request</span>
            <?php if ($is_sahakari): ?>
                <span class="member-type-badge"><i class="fas fa-handshake"></i> Sahakari Member</span>
            <?php endif; ?>
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

        <form id="terminationForm" method="post" enctype="multipart/form-data" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

          <div class="form-section">
            <h2><i class="fas fa-user-slash"></i> Membership Termination Certificate Application</h2>
            <div class="help">Existing membership and family details are loaded from the system. Confirm them, edit if needed, and submit the request. All fields marked with * are required.</div>

            <h3>Member Information</h3>
            <div class="form-grid">
              <!-- Member Name -->
              <div class="form-group">
                <label>Name <span class="required">*</span></label>
                <input type="text" name="head_name" id="head_name" placeholder="Enter your full name" required maxlength="190" value="<?php echo htmlspecialchars($head_name); ?>">
                <div class="validation-error" id="head_name-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Name is required (2-190 characters, only letters, spaces, dots, hyphens)</span>
                </div>
              </div>

              <!-- Father's Name -->
              <div class="form-group">
                <label>Father's Name <span class="required">*</span></label>
                <input type="text" name="father_name" id="father_name" placeholder="Enter father's name" required maxlength="190" value="<?php echo htmlspecialchars($father_name); ?>">
                <div class="validation-error" id="father_name-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Father's name is required (2-190 characters, only letters, spaces, dots, hyphens, apostrophes)</span>
                </div>
              </div>

              <!-- Date of Birth -->
              <div class="form-group">
                <label>Date of Birth <span class="required">*</span></label>
                <input type="date" name="dob" id="dob" required value="<?php echo htmlspecialchars($dob_value); ?>">
                <div class="validation-error" id="dob-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Valid date required (cannot be in future)</span>
                </div>
              </div>

              <!-- Age (auto-calculated) -->
              <div class="form-group">
                <label>Age</label>
                <input type="text" id="age" class="readonly-field" readonly value="<?php echo $member_age_disp !== null ? (int)$member_age_disp : ''; ?>">
              </div>

              <!-- Qualification -->
              <div class="form-group">
                <label>Qualification <span class="required">*</span></label>
                <input type="text" name="qualification" id="qualification" placeholder="Enter qualification" required maxlength="100" value="<?php echo htmlspecialchars($qualification); ?>">
                <div class="validation-error" id="qualification-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Qualification is required (max 100 characters)</span>
                </div>
              </div>

              <!-- House Name -->
              <div class="form-group">
                <label>House Name <span class="required">*</span></label>
                <input type="text" name="house_name" id="house_name" placeholder="Enter house name" required maxlength="150" value="<?php echo htmlspecialchars($house_name); ?>">
                <div class="validation-error" id="house_name-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>House name is required (max 150 characters)</span>
                </div>
              </div>

              <!-- City, District & Pincode -->
              <div class="form-group full">
                <label>City, District & Pincode <span class="required">*</span></label>
                <input type="text" name="city_line" id="city_line" placeholder="Eg: Calicut, Kozhikode 673001" required maxlength="200" value="<?php echo htmlspecialchars($city_line); ?>">
                <div class="validation-error" id="city_line-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>City/District/Pincode is required (max 200 characters)</span>
                </div>
              </div>

              <!-- Taluk -->
              <div class="form-group">
                <label>Taluk <span class="required">*</span></label>
                <input type="text" name="taluk" id="taluk" placeholder="Enter taluk" required maxlength="100" value="<?php echo htmlspecialchars($taluk); ?>">
                <div class="validation-error" id="taluk-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Taluk is required (max 100 characters)</span>
                </div>
              </div>

              <!-- Village/Panchayat -->
              <div class="form-group">
                <label>Village, Panchayat <span class="required">*</span></label>
                <input type="text" name="village_panchayat" id="village_panchayat" placeholder="Enter village/panchayat" required maxlength="150" value="<?php echo htmlspecialchars($village_panchayat); ?>">
                <div class="validation-error" id="village_panchayat-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Village/Panchayat is required (max 150 characters)</span>
                </div>
              </div>

              <!-- Phone -->
              <div class="form-group">
                <label>Phone <span class="required">*</span></label>
                <input type="text" name="phone" id="phone" placeholder="Enter phone number" required maxlength="20" value="<?php echo htmlspecialchars($phone_value); ?>">
                <div class="validation-error" id="phone-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Phone number is required (max 20 digits)</span>
                </div>
              </div>

              <!-- Destination Mahal -->
              <div class="form-group full">
                <label>Destination Mahal (Transfer To) <span class="required">*</span></label>
                <input type="text" name="new_mahal" id="new_mahal" placeholder="Enter new mahal name" required maxlength="150" value="<?php echo htmlspecialchars($new_mahal); ?>">
                <div class="validation-error" id="new_mahal-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Destination Mahal is required (max 150 characters)</span>
                </div>
              </div>

              <!-- Registration Number (readonly) -->
              <div class="form-group">
                <label>Certificate Registration No.</label>
                <input type="text" name="reg_number_display" id="reg_number_display" class="readonly-field" readonly value="<?php echo htmlspecialchars($preview_reg_number); ?>" placeholder="Will be generated automatically">
              </div>

              <!-- Passport Photo -->
              <div class="form-group full">
                <label>Passport Size Photo (JPG/PNG) <span class="required">*</span></label>
                <input type="file" name="member_photo" id="member_photo" accept="image/jpeg,image/png" required>
                <div class="validation-error" id="member_photo-error">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>Passport size photo is required (JPG/PNG only)</span>
                </div>
                <div class="image-preview-container">
                  <img id="member_photo_preview" class="image-preview" alt="Photo preview">
                  <div class="help">Preview will appear here after selecting a file</div>
                </div>
              </div>
            </div>

            <h3>Family Members</h3>
            <div class="help">You can correct names / relationships / ages here or add missing family members. These details are only used inside the termination certificate.</div>
            
            <table class="family-table">
              <thead>
                <tr>
                  <th style="width:40px;">#</th>
                  <th>Name <span class="required">*</span></th>
                  <th>Relationship <span class="required">*</span></th>
                  <th style="width:80px;">Age</th>
                  <th>Occupation</th>
                </tr>
              </thead>
              <tbody id="family-rows">
                <?php if (!empty($family_members)): ?>
                  <?php foreach ($family_members as $idx => $fm): ?>
                    <?php $age = mt_calc_age_from_dob($fm['dob'] ?? null); ?>
                    <tr>
                      <td><?php echo $idx + 1; ?></td>
                      <td>
                        <input type="text" name="family_name[]" class="family-input" value="<?php echo htmlspecialchars($fm['name'] ?? ''); ?>" placeholder="Name">
                        <div class="validation-error" id="family_name_<?php echo $idx; ?>-error" style="display: none;">
                          <i class="fas fa-exclamation-circle"></i>
                          <span>Name is required</span>
                        </div>
                      </td>
                      <td>
                        <input type="text" name="family_relationship[]" class="family-input" value="<?php echo htmlspecialchars($fm['relationship'] ?? ''); ?>" placeholder="Relationship">
                        <div class="validation-error" id="family_relationship_<?php echo $idx; ?>-error" style="display: none;">
                          <i class="fas fa-exclamation-circle"></i>
                          <span>Relationship is required</span>
                        </div>
                      </td>
                      <td>
                        <input type="number" name="family_age[]" class="family-input" min="0" max="120" value="<?php echo $age !== null ? (int)$age : ''; ?>" placeholder="Age">
                        <div class="validation-error" id="family_age_<?php echo $idx; ?>-error" style="display: none;">
                          <i class="fas fa-exclamation-circle"></i>
                          <span>Age must be 0-120</span>
                        </div>
                      </td>
                      <td>
                        <input type="text" name="family_occupation[]" class="family-input" value="" placeholder="Occupation">
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td>1</td>
                    <td>
                      <input type="text" name="family_name[]" class="family-input" value="" placeholder="Name">
                      <div class="validation-error" id="family_name_0-error" style="display: none;">
                        <i class="fas fa-exclamation-circle"></i>
                        <span>Name is required</span>
                      </div>
                    </td>
                    <td>
                      <input type="text" name="family_relationship[]" class="family-input" value="" placeholder="Relationship">
                      <div class="validation-error" id="family_relationship_0-error" style="display: none;">
                        <i class="fas fa-exclamation-circle"></i>
                        <span>Relationship is required</span>
                      </div>
                    </td>
                    <td>
                      <input type="number" name="family_age[]" class="family-input" min="0" max="120" value="" placeholder="Age">
                      <div class="validation-error" id="family_age_0-error" style="display: none;">
                        <i class="fas fa-exclamation-circle"></i>
                        <span>Age must be 0-120</span>
                      </div>
                    </td>
                    <td>
                      <input type="text" name="family_occupation[]" class="family-input" value="" placeholder="Occupation">
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
            <div class="family-actions">
              <button type="button" class="btn btn-secondary" id="addFamRowBtn">
                <i class="fas fa-plus"></i> Add Family Member
              </button>
            </div>

            <h3>Additional Information</h3>
            <div class="form-grid">
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
                  <i class="fas fa-paper-plane"></i> Submit Termination Certificate Request
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

  function isValidQualification(text) {
      if (!text || text.trim().length < 1 || text.trim().length > 100) return false;
      const qualRegex = /^[A-Za-z0-9\s\.\,\-\/\#\&\(\)]+$/;
      return qualRegex.test(text.trim());
  }

  function isValidLocationName(name) {
      if (!name || name.trim().length < 2 || name.trim().length > 150) return false;
      // Allow alphanumeric, spaces, and common location punctuation
      const locationRegex = /^[A-Za-z0-9\s\.\,\-\/\#\&\(\)]+$/;
      return locationRegex.test(name.trim());
  }

  function isValidPhone(phone) {
      if (!phone || phone.trim().length < 10 || phone.trim().length > 20) return false;
      const phoneRegex = /^[0-9\s\-\+\(\)]+$/;
      return phoneRegex.test(phone.trim());
  }

  function isValidFamilyName(name) {
      if (!name || name.trim().length < 2 || name.trim().length > 100) return false;
      const nameRegex = /^[A-Za-z\s\.\-\']+$/;
      return nameRegex.test(name.trim());
  }

  function isValidRelationship(rel) {
      if (!rel || rel.trim().length < 2 || rel.trim().length > 50) return false;
      const relRegex = /^[A-Za-z\s\.\-\']+$/;
      return relRegex.test(rel.trim());
  }

  function isValidAge(age) {
      if (age === '') return true; // Optional
      if (!/^\d+$/.test(age)) return false;
      const ageNum = parseInt(age);
      return ageNum >= 0 && ageNum <= 120;
  }

  function isValidOccupation(occ) {
      if (occ === '') return true; // Optional
      if (occ.trim().length > 100) return false;
      const occRegex = /^[A-Za-z0-9\s\.\,\-\/\#\&\(\)]+$/;
      return occRegex.test(occ.trim());
  }

  function hasValidPhoto() {
      const input = document.getElementById('member_photo');
      return input.files && input.files.length > 0;
  }

  function isValidPhotoFile(file) {
      if (!file) return false;
      const validTypes = ['image/jpeg', 'image/png'];
      return validTypes.includes(file.type);
  }

  // Age calculation
  function calculateAge(dobStr) {
      if (!dobStr) return '';
      const dob = new Date(dobStr);
      if (isNaN(dob.getTime())) return '';
      const today = new Date();
      let age = today.getFullYear() - dob.getFullYear();
      const m = today.getMonth() - dob.getMonth();
      if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) {
          age--;
      }
      return age >= 0 ? age : '';
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
      
      if (elementId === 'head_name') {
          isValid = isValidName(inputElement.value);
      } else if (elementId === 'father_name') {
          isValid = isValidParentName(inputElement.value);
      } else if (elementId === 'dob' || elementId === 'application_date') {
          isValid = isValidDate(inputElement.value);
      } else if (elementId === 'qualification') {
          isValid = isValidQualification(inputElement.value);
      } else if (elementId === 'house_name' || elementId === 'new_mahal') {
          isValid = isValidLocationName(inputElement.value);
      } else if (elementId === 'city_line') {
          isValid = isValidText(inputElement.value, 5, 200);
      } else if (elementId === 'taluk' || elementId === 'village_panchayat') {
          isValid = isValidLocationName(inputElement.value);
      } else if (elementId === 'phone') {
          isValid = isValidPhone(inputElement.value);
      } else if (elementId === 'member_photo') {
          const file = inputElement.files[0];
          isValid = hasValidPhoto() && isValidPhotoFile(file);
      } else if (elementId === 'notes') {
          isValid = inputElement.value === '' || isValidText(inputElement.value, 1, 1000);
      }
      
      showValidation(elementId, isValid, elementId !== 'notes' && elementId !== 'applicant_dob');
  }

  // Setup age calculation
  function setupAgeCalculation() {
      const dobInput = document.getElementById('dob');
      const ageInput = document.getElementById('age');
      
      if (!dobInput || !ageInput) return;
      
      function updateAge() {
          ageInput.value = calculateAge(dobInput.value);
      }
      
      dobInput.addEventListener('change', updateAge);
      dobInput.addEventListener('input', updateAge);
      
      // Initial calculation
      updateAge();
  }

  // Setup photo preview
  function setupPhotoPreview() {
      const photoInput = document.getElementById('member_photo');
      const preview = document.getElementById('member_photo_preview');
      
      if (!photoInput || !preview) return;
      
      photoInput.addEventListener('change', function() {
          const file = this.files[0];
          if (file && isValidPhotoFile(file)) {
              const reader = new FileReader();
              reader.onload = function(e) {
                  preview.src = e.target.result;
                  preview.classList.add('show');
              };
              reader.readAsDataURL(file);
          } else {
              preview.classList.remove('show');
              preview.src = '';
          }
      });
  }

  // Add family member row
  function setupFamilyRows() {
      const addBtn = document.getElementById('addFamRowBtn');
      const tbody = document.getElementById('family-rows');
      
      if (!addBtn || !tbody) return;
      
      addBtn.addEventListener('click', function() {
          const rowCount = tbody.rows.length;
          const newRow = tbody.insertRow();
          const index = rowCount;
          
          newRow.innerHTML = `
              <td>${index + 1}</td>
              <td>
                  <input type="text" name="family_name[]" class="family-input" value="" placeholder="Name">
                  <div class="validation-error" id="family_name_${index}-error" style="display: none;">
                      <i class="fas fa-exclamation-circle"></i>
                      <span>Name is required</span>
                  </div>
              </td>
              <td>
                  <input type="text" name="family_relationship[]" class="family-input" value="" placeholder="Relationship">
                  <div class="validation-error" id="family_relationship_${index}-error" style="display: none;">
                      <i class="fas fa-exclamation-circle"></i>
                      <span>Relationship is required</span>
                  </div>
              </td>
              <td>
                  <input type="number" name="family_age[]" class="family-input" min="0" max="120" value="" placeholder="Age">
                  <div class="validation-error" id="family_age_${index}-error" style="display: none;">
                      <i class="fas fa-exclamation-circle"></i>
                      <span>Age must be 0-120</span>
                  </div>
              </td>
              <td>
                  <input type="text" name="family_occupation[]" class="family-input" value="" placeholder="Occupation">
              </td>
          `;
          
          // Add validation events to new inputs
          const inputs = newRow.querySelectorAll('.family-input');
          inputs.forEach(input => {
              const name = input.name;
              if (name.includes('family_name')) {
                  input.addEventListener('blur', function() {
                      validateFamilyRow(index, 'name', this.value);
                  });
                  input.addEventListener('input', function() {
                      if (touchedFields.has(`family_name_${index}`)) {
                          validateFamilyRow(index, 'name', this.value);
                      }
                  });
              } else if (name.includes('family_relationship')) {
                  input.addEventListener('blur', function() {
                      validateFamilyRow(index, 'relationship', this.value);
                  });
                  input.addEventListener('input', function() {
                      if (touchedFields.has(`family_relationship_${index}`)) {
                          validateFamilyRow(index, 'relationship', this.value);
                      }
                  });
              } else if (name.includes('family_age')) {
                  input.addEventListener('blur', function() {
                      validateFamilyRow(index, 'age', this.value);
                  });
                  input.addEventListener('input', function() {
                      if (touchedFields.has(`family_age_${index}`)) {
                          validateFamilyRow(index, 'age', this.value);
                      }
                  });
              } else if (name.includes('family_occupation')) {
                  input.addEventListener('blur', function() {
                      validateFamilyRow(index, 'occupation', this.value);
                  });
                  input.addEventListener('input', function() {
                      if (touchedFields.has(`family_occupation_${index}`)) {
                          validateFamilyRow(index, 'occupation', this.value);
                      }
                  });
              }
          });
      });
      
      // Add validation events to existing rows
      const existingRows = tbody.querySelectorAll('tr');
      existingRows.forEach((row, index) => {
          const inputs = row.querySelectorAll('.family-input');
          inputs.forEach(input => {
              const name = input.name;
              if (name.includes('family_name')) {
                  input.addEventListener('blur', function() {
                      validateFamilyRow(index, 'name', this.value);
                  });
                  input.addEventListener('input', function() {
                      if (touchedFields.has(`family_name_${index}`)) {
                          validateFamilyRow(index, 'name', this.value);
                      }
                  });
              } else if (name.includes('family_relationship')) {
                  input.addEventListener('blur', function() {
                      validateFamilyRow(index, 'relationship', this.value);
                  });
                  input.addEventListener('input', function() {
                      if (touchedFields.has(`family_relationship_${index}`)) {
                          validateFamilyRow(index, 'relationship', this.value);
                      }
                  });
              } else if (name.includes('family_age')) {
                  input.addEventListener('blur', function() {
                      validateFamilyRow(index, 'age', this.value);
                  });
                  input.addEventListener('input', function() {
                      if (touchedFields.has(`family_age_${index}`)) {
                          validateFamilyRow(index, 'age', this.value);
                      }
                  });
              } else if (name.includes('family_occupation')) {
                  input.addEventListener('blur', function() {
                      validateFamilyRow(index, 'occupation', this.value);
                  });
                  input.addEventListener('input', function() {
                      if (touchedFields.has(`family_occupation_${index}`)) {
                          validateFamilyRow(index, 'occupation', this.value);
                      }
                  });
              }
          });
      });
  }

  // Validate individual family row field
  function validateFamilyRow(index, fieldType, value) {
      let isValid = false;
      let errorId = '';
      
      switch (fieldType) {
          case 'name':
              isValid = isValidFamilyName(value);
              errorId = `family_name_${index}-error`;
              touchedFields.add(`family_name_${index}`);
              break;
          case 'relationship':
              isValid = isValidRelationship(value);
              errorId = `family_relationship_${index}-error`;
              touchedFields.add(`family_relationship_${index}`);
              break;
          case 'age':
              isValid = isValidAge(value);
              errorId = `family_age_${index}-error`;
              touchedFields.add(`family_age_${index}`);
              break;
          case 'occupation':
              isValid = isValidOccupation(value);
              errorId = `family_occupation_${index}-error`;
              touchedFields.add(`family_occupation_${index}`);
              break;
      }
      
      const errorElement = document.getElementById(errorId);
      if (errorElement) {
          const inputElement = document.querySelector(`[name="family_${fieldType}[]"]`);
          if (errorElement) {
              if (!isValid && (fieldType === 'occupation' || value.trim() !== '')) {
                  errorElement.style.display = 'flex';
                  if (inputElement) {
                      inputElement.classList.add('invalid');
                  }
              } else {
                  errorElement.style.display = 'none';
                  if (inputElement) {
                      inputElement.classList.remove('invalid');
                  }
              }
          }
      }
  }

  // Attach validation events for real-time feedback
  const allFields = [
      'head_name', 'father_name', 'dob', 'qualification', 'house_name',
      'city_line', 'taluk', 'village_panchayat', 'phone', 'new_mahal',
      'member_photo', 'notes'
  ];

  allFields.forEach(fieldId => {
      const element = document.getElementById(fieldId);
      if (!element) return;
      
      element.addEventListener('blur', function() {
          markAsTouched(fieldId);
          let isValid = false;
          
          if (fieldId === 'head_name') {
              isValid = isValidName(this.value);
          } else if (fieldId === 'father_name') {
              isValid = isValidParentName(this.value);
          } else if (fieldId === 'dob') {
              isValid = isValidDate(this.value);
          } else if (fieldId === 'qualification') {
              isValid = isValidQualification(this.value);
          } else if (fieldId === 'house_name' || fieldId === 'new_mahal') {
              isValid = isValidLocationName(this.value);
          } else if (fieldId === 'city_line') {
              isValid = isValidText(this.value, 5, 200);
          } else if (fieldId === 'taluk' || fieldId === 'village_panchayat') {
              isValid = isValidLocationName(this.value);
          } else if (fieldId === 'phone') {
              isValid = isValidPhone(this.value);
          } else if (fieldId === 'member_photo') {
              const file = this.files[0];
              isValid = hasValidPhoto() && isValidPhotoFile(file);
          } else if (fieldId === 'notes') {
              isValid = this.value === '' || isValidText(this.value, 1, 1000);
          }
          
          showValidation(fieldId, isValid, fieldId !== 'notes');
      });
      
      // Real-time validation after first touch
      element.addEventListener('input', function() {
          if (touchedFields.has(fieldId)) {
              let isValid = false;
              
              if (fieldId === 'head_name') {
                  isValid = isValidName(this.value);
              } else if (fieldId === 'father_name') {
                  isValid = isValidParentName(this.value);
              } else if (fieldId === 'dob') {
                  isValid = isValidDate(this.value);
              } else if (fieldId === 'qualification') {
                  isValid = isValidQualification(this.value);
              } else if (fieldId === 'house_name' || fieldId === 'new_mahal') {
                  isValid = isValidLocationName(this.value);
              } else if (fieldId === 'city_line') {
                  isValid = isValidText(this.value, 5, 200);
              } else if (fieldId === 'taluk' || fieldId === 'village_panchayat') {
                  isValid = isValidLocationName(this.value);
              } else if (fieldId === 'phone') {
                  isValid = isValidPhone(this.value);
              } else if (fieldId === 'notes') {
                  isValid = this.value === '' || isValidText(this.value, 1, 1000);
              }
              
              showValidation(fieldId, isValid, fieldId !== 'notes');
          }
      });
  });

  // Clear form functionality
  document.getElementById('clearBtn').addEventListener('click', function() {
      if (confirm('Are you sure you want to clear all form data?')) {
          document.getElementById('terminationForm').reset();
          hideAlert();
          
          // Reset touched fields
          touchedFields.clear();
          
          // Remove all validation classes
          document.querySelectorAll('.validation-error').forEach(el => {
              el.classList.remove('show');
              el.style.display = 'none';
          });
          document.querySelectorAll('input, select, textarea').forEach(el => {
              el.classList.remove('valid', 'invalid');
          });
          
          // Clear photo preview
          const preview = document.getElementById('member_photo_preview');
          if (preview) {
              preview.classList.remove('show');
              preview.src = '';
          }
          
          // Reset age field
          document.getElementById('age').value = '';
      }
  });

  // Group validation errors by section
  function groupErrorsBySection(errors) {
      const sections = {
          'member': [],
          'family': [],
          'other': []
      };
      
      errors.forEach(error => {
          const field = error.field;
          if (field.includes('family')) {
              sections.family.push(error);
          } else if (field.includes('head_name') || field.includes('father_name') || field.includes('dob') || 
                     field.includes('qualification') || field.includes('house_name') || field.includes('city_line') ||
                     field.includes('taluk') || field.includes('village_panchayat') || field.includes('phone') ||
                     field.includes('new_mahal') || field.includes('member_photo')) {
              sections.member.push(error);
          } else {
              sections.other.push(error);
          }
      });
      
      return sections;
  }

  // Show section-wise error summary
  function showSectionErrors(groupedErrors) {
      let errorHtml = '<div><strong>Please fix the following errors:</strong>';
      
      if (groupedErrors.member.length > 0) {
          errorHtml += '<div style="margin-top: 10px; font-weight: 600;">Member Information:</div>';
          groupedErrors.member.forEach(error => {
              errorHtml += `<div>• ${error.message}</div>`;
          });
      }
      
      if (groupedErrors.family.length > 0) {
          errorHtml += '<div style="margin-top: 10px; font-weight: 600;">Family Members:</div>';
          groupedErrors.family.forEach(error => {
              errorHtml += `<div>• ${error.message}</div>`;
          });
      }
      
      if (groupedErrors.other.length > 0) {
          errorHtml += '<div style="margin-top: 10px; font-weight: 600;">Other Information:</div>';
          groupedErrors.other.forEach(error => {
              errorHtml += `<div>• ${error.message}</div>`;
          });
      }
      
      errorHtml += '</div>';
      
      showAlert('error', errorHtml);
  }

  // Form submission - validation only on submit
  document.getElementById('terminationForm').addEventListener('submit', function(e) {
      e.preventDefault();

      // Clear previous alerts
      hideAlert();
      
      // Track all errors
      const validationErrors = [];
      
      // List of required fields for validation
      const requiredFields = [
          'head_name', 'father_name', 'dob', 'qualification', 'house_name',
          'city_line', 'taluk', 'village_panchayat', 'phone', 'new_mahal', 'member_photo'
      ];

      const optionalFields = ['notes'];
      
      // Force validation check for all required fields
      requiredFields.forEach(fieldId => {
          const element = document.getElementById(fieldId);
          if (!element) return;
          
          let isValid = false;
          let errorMessage = '';
          
          if (fieldId === 'head_name') {
              isValid = isValidName(element.value);
              errorMessage = 'Name is required';
          } else if (fieldId === 'father_name') {
              isValid = isValidParentName(element.value);
              errorMessage = "Father's name is required";
          } else if (fieldId === 'dob') {
              isValid = isValidDate(element.value);
              errorMessage = 'Valid date of birth required';
          } else if (fieldId === 'qualification') {
              isValid = isValidQualification(element.value);
              errorMessage = 'Qualification is required';
          } else if (fieldId === 'house_name') {
              isValid = isValidLocationName(element.value);
              errorMessage = 'House name is required';
          } else if (fieldId === 'city_line') {
              isValid = isValidText(element.value, 5, 200);
              errorMessage = 'City/District/Pincode is required';
          } else if (fieldId === 'taluk') {
              isValid = isValidLocationName(element.value);
              errorMessage = 'Taluk is required';
          } else if (fieldId === 'village_panchayat') {
              isValid = isValidLocationName(element.value);
              errorMessage = 'Village/Panchayat is required';
          } else if (fieldId === 'phone') {
              isValid = isValidPhone(element.value);
              errorMessage = 'Phone number is required';
          } else if (fieldId === 'new_mahal') {
              isValid = isValidLocationName(element.value);
              errorMessage = 'Destination Mahal is required';
          } else if (fieldId === 'member_photo') {
              const file = element.files[0];
              isValid = hasValidPhoto() && isValidPhotoFile(file);
              errorMessage = 'Passport size photo is required (JPG/PNG only)';
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
          
          if (fieldId === 'notes') {
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
      
      // Validate family members
      const familyNames = document.querySelectorAll('input[name="family_name[]"]');
      const familyRelationships = document.querySelectorAll('input[name="family_relationship[]"]');
      const familyAges = document.querySelectorAll('input[name="family_age[]"]');
      const familyOccupations = document.querySelectorAll('input[name="family_occupation[]"]');
      
      for (let i = 0; i < familyNames.length; i++) {
          const name = familyNames[i].value.trim();
          const relationship = familyRelationships[i].value.trim();
          const age = familyAges[i].value.trim();
          const occupation = familyOccupations[i].value.trim();
          
          // Skip completely empty rows
          if (name === '' && relationship === '' && age === '' && occupation === '') {
              continue;
          }
          
          // Validate name
          if (!isValidFamilyName(name)) {
              validationErrors.push({
                  field: `family_name_${i}`,
                  message: `Family member #${i + 1}: Name is required`
              });
              document.getElementById(`family_name_${i}-error`).style.display = 'flex';
              familyNames[i].classList.add('invalid');
          } else {
              document.getElementById(`family_name_${i}-error`).style.display = 'none';
              familyNames[i].classList.remove('invalid');
          }
          
          // Validate relationship
          if (!isValidRelationship(relationship)) {
              validationErrors.push({
                  field: `family_relationship_${i}`,
                  message: `Family member #${i + 1}: Relationship is required`
              });
              document.getElementById(`family_relationship_${i}-error`).style.display = 'flex';
              familyRelationships[i].classList.add('invalid');
          } else {
              document.getElementById(`family_relationship_${i}-error`).style.display = 'none';
              familyRelationships[i].classList.remove('invalid');
          }
          
          // Validate age
          if (!isValidAge(age)) {
              validationErrors.push({
                  field: `family_age_${i}`,
                  message: `Family member #${i + 1}: Age must be between 0 and 120`
              });
              document.getElementById(`family_age_${i}-error`).style.display = 'flex';
              familyAges[i].classList.add('invalid');
          } else {
              document.getElementById(`family_age_${i}-error`).style.display = 'none';
              familyAges[i].classList.remove('invalid');
          }
          
          // Validate occupation
          if (!isValidOccupation(occupation)) {
              validationErrors.push({
                  field: `family_occupation_${i}`,
                  message: `Family member #${i + 1}: Occupation contains invalid characters or is too long`
              });
          }
      }
      
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

      fetch('member_termination_request.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin'
      })
       .then(response => {
        if (response.redirected) {
            // Check if redirected with success parameter
            const url = new URL(response.url);
            if (url.searchParams.get('cert_request') === 'termination_success') {
                // Show success message before redirect
                showAlert('success', 'Termination certificate request submitted successfully!');
                // Wait a moment then redirect
                setTimeout(() => {
                    window.location.href = response.url;
                }, 2000);
                return;
            }
            window.location.href = member_cert_requests.url;
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

  // Initialize everything when DOM is loaded
  document.addEventListener('DOMContentLoaded', function() {
      setupAgeCalculation();
      setupPhotoPreview();
      setupFamilyRows();
  });
  </script>
</body>
</html>