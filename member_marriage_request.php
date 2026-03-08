<?php
// member_marriage_request.php
declare(strict_types=1);

require_once __DIR__ . '/session_bootstrap.php';

/* --- Member auth gate --- */
if (empty($_SESSION['member_login']) || $_SESSION['member_login'] !== true || empty($_SESSION['member'])) {
    header("Location: index.php");
    exit();
}

$memberSess = $_SESSION['member'];

// Member type check
$member_type = $memberSess['member_type'] ?? 'head';
$is_sahakari = ($member_type === 'sahakari_head');

// Household head id
if ($is_sahakari) {
    $household_member_id = (int) $memberSess['member_id'];
} else {
    $household_member_id = ($member_type === 'family' && !empty($memberSess['parent_member_id']))
        ? (int) $memberSess['parent_member_id']
        : (int) $memberSess['member_id'];
}

/* --- DB --- */
require_once __DIR__ . '/db_connection.php';

$errors = [];
$success_message = '';
$notes_from_session = $_SESSION['pending_cert_notes'] ?? '';

/* --- helper: CSRF token --- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

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
    } else {
        $stmt = $conn->prepare("SELECT * FROM members WHERE id = ?");
    }
    $stmt->bind_param("i", $household_member_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $member = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
} catch (Exception $e) {
    $member = null;
}

/**
 * Build 3-letter mahal code from mahal name.
 *
 * Rules:
 * - 4+ words: first letter of word 1, word 2 and last word
 * - 3 words : first letter of each word
 * - 2 words : first 2 letters of first word + first letter of last word
 * - 1 word  : first 3 letters
 */
function build_mahal_code(string $name): string
{
    $clean = trim(preg_replace('/\s+/', ' ', $name));
    if ($clean === '') {
        return 'XXX';
    }

    $words = explode(' ', $clean);
    $count = count($words);

    if ($count >= 4) {
        $first = $words[0];
        $second = $words[1];
        $last = $words[$count - 1];
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
 * Generate next registration number for marriage certificate.
 * Pattern: MAHCODE-M-XX-YYYY  (e.g. NHM-M-01-2025)
 *
 * - MAHCODE built from the mahal name of THIS member:
 *      members.mahal_id -> register.id -> register.name
 * - XX is running number for that mahal + year (for certificate_type = 'marriage')
 * - YYYY is current year
 */
function generate_marriage_reg_number(mysqli $conn, int $memberId, bool $isSahakari): string
{
    $mahalName = '';

    // 1) Get mahal name for this member
    if ($isSahakari) {
        $sql = "
            SELECT r.name
            FROM sahakari_members m
            JOIN register r ON m.mahal_id = r.id
            WHERE m.id = ?
            LIMIT 1
        ";
    } else {
        $sql = "
            SELECT r.name
            FROM members m
            JOIN register r ON m.mahal_id = r.id
            WHERE m.id = ?
            LIMIT 1
        ";
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

    // Fallback: if somehow not found, use first register row or generic
    if ($mahalName === '') {
        $res = $conn->query("SELECT name FROM register ORDER BY id ASC LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            $mahalName = $row['name'] ?? 'MAHAL';
        } else {
            $mahalName = 'MAHAL';
        }
    }

    $prefix = build_mahal_code($mahalName); // e.g. NHM
    $year = date('Y');                    // e.g. 2025

    // 2) Find last used number for this mahal + year (only marriage certificates)
    $like = $prefix . '-M-%-' . $year;      // e.g. NHM-M-%-2025
    $lastRegNumber = null;

    $sql2 = "SELECT reg_number 
             FROM cert_requests 
             WHERE certificate_type = 'marriage'
               AND reg_number LIKE ?
             ORDER BY id DESC 
             LIMIT 1";

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
        // Format: PREFIX-M-XX-YYYY
        $parts = explode('-', $lastRegNumber);
        if (count($parts) >= 3) {
            $num = (int) $parts[2];
            if ($num > 0) {
                $seq = $num;
            }
        }
    }

    $nextSeq = $seq + 1;
    $seqStr = str_pad((string) $nextSeq, 2, '0', STR_PAD_LEFT); // 01, 02, ...

    return $prefix . '-M-' . $seqStr . '-' . $year;
}

/* --- If POST: handle form submit --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (empty($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $errors[] = 'Invalid request token.';
    }

    // Collect fields
    $groom_name = trim((string) ($_POST['groom_name'] ?? ''));
    $groom_parent = trim((string) ($_POST['groom_parent'] ?? ''));
    $groom_dob = trim((string) ($_POST['groom_dob'] ?? ''));
    $groom_address = trim((string) ($_POST['groom_address'] ?? ''));

    $bride_name = trim((string) ($_POST['bride_name'] ?? ''));
    $bride_parent = trim((string) ($_POST['bride_parent'] ?? ''));
    $bride_dob = trim((string) ($_POST['bride_dob'] ?? ''));
    $bride_address = trim((string) ($_POST['bride_address'] ?? ''));

    $marriage_date = trim((string) ($_POST['marriage_date'] ?? ''));
    $marriage_venue = trim((string) ($_POST['marriage_venue'] ?? ''));
    $cooperating_mahal = trim((string) ($_POST['cooperating_mahal'] ?? ''));
    // reg_number is NOT read from POST – it will be generated
    $requested_by = trim((string) ($_POST['requested_by'] ?? ''));
    $signed_by = trim((string) ($_POST['signed_by'] ?? '')); // OPTIONAL
    $notes = trim((string) ($_POST['notes'] ?? ''));

    // Enhanced validation
    $validation_errors = [];

    // Groom validation
    if ($groom_name === '') {
        $validation_errors[] = 'Groom full name is required';
    } elseif (strlen($groom_name) > 120) {
        $validation_errors[] = 'Groom name is too long (max 120 characters)';
    } elseif (!preg_match('/^[A-Za-z\s\.\-]+$/', $groom_name)) {
        $validation_errors[] = 'Groom name can only contain letters, spaces, dots, and hyphens';
    }

    if ($groom_parent === '') {
        $validation_errors[] = 'Groom parent\'s name is required';
    } elseif (strlen($groom_parent) > 120) {
        $validation_errors[] = 'Groom parent name is too long (max 120 characters)';
    } elseif (!preg_match('/^[A-Za-z\s\.\-\']+$/', $groom_parent)) {
        $validation_errors[] = 'Groom parent name can only contain letters, spaces, dots, hyphens, and apostrophes';
    }

    if ($groom_dob === '') {
        $validation_errors[] = 'Groom date of birth is required';
    } else {
        $groom_dob_date = DateTime::createFromFormat('Y-m-d', $groom_dob);
        if (!$groom_dob_date) {
            $validation_errors[] = 'Groom date of birth is invalid';
        } else {
            $today = new DateTime();
            if ($groom_dob_date > $today) {
                $validation_errors[] = 'Groom date of birth cannot be in the future';
            }
        }
    }

    if ($groom_address === '') {
        $validation_errors[] = 'Groom address is required';
    } elseif (strlen($groom_address) > 500) {
        $validation_errors[] = 'Groom address is too long (max 500 characters)';
    } elseif (!preg_match('/^[A-Za-z0-9\s\.\,\-\/\#\&\(\)\:]+$/', $groom_address)) {
        $validation_errors[] = 'Groom address contains invalid characters';
    }

    // Bride validation
    if ($bride_name === '') {
        $validation_errors[] = 'Bride full name is required';
    } elseif (strlen($bride_name) > 120) {
        $validation_errors[] = 'Bride name is too long (max 120 characters)';
    } elseif (!preg_match('/^[A-Za-z\s\.\-]+$/', $bride_name)) {
        $validation_errors[] = 'Bride name can only contain letters, spaces, dots, and hyphens';
    }

    if ($bride_parent === '') {
        $validation_errors[] = 'Bride parent\'s name is required';
    } elseif (strlen($bride_parent) > 120) {
        $validation_errors[] = 'Bride parent name is too long (max 120 characters)';
    } elseif (!preg_match('/^[A-Za-z\s\.\-\']+$/', $bride_parent)) {
        $validation_errors[] = 'Bride parent name can only contain letters, spaces, dots, hyphens, and apostrophes';
    }

    if ($bride_dob === '') {
        $validation_errors[] = 'Bride date of birth is required';
    } else {
        $bride_dob_date = DateTime::createFromFormat('Y-m-d', $bride_dob);
        if (!$bride_dob_date) {
            $validation_errors[] = 'Bride date of birth is invalid';
        } else {
            $today = new DateTime();
            if ($bride_dob_date > $today) {
                $validation_errors[] = 'Bride date of birth cannot be in the future';
            }
        }
    }

    if ($bride_address === '') {
        $validation_errors[] = 'Bride address is required';
    } elseif (strlen($bride_address) > 500) {
        $validation_errors[] = 'Bride address is too long (max 500 characters)';
    } elseif (!preg_match('/^[A-Za-z0-9\s\.\,\-\/\#\&\(\)\:]+$/', $bride_address)) {
        $validation_errors[] = 'Bride address contains invalid characters';
    }

    // Marriage details validation
    if ($marriage_date === '') {
        $validation_errors[] = 'Marriage date is required';
    } else {
        $marriage_date_obj = DateTime::createFromFormat('Y-m-d', $marriage_date);
        if ($marriage_date_obj === false) {
            $validation_errors[] = 'Marriage date is invalid';
        }
    }

    if ($marriage_venue === '') {
        $validation_errors[] = 'Marriage venue is required';
    } elseif (strlen($marriage_venue) > 200) {
        $validation_errors[] = 'Marriage venue is too long (max 200 characters)';
    } elseif (!preg_match('/^[A-Za-z0-9\s\.\,\-\/\#\&\(\)\:\;]+$/', $marriage_venue)) {
        $validation_errors[] = 'Marriage venue contains invalid characters';
    }

    if ($cooperating_mahal === '') {
        $validation_errors[] = 'Co-operating mahal is required';
    } elseif (strlen($cooperating_mahal) > 100) {
        $validation_errors[] = 'Co-operating mahal name is too long (max 100 characters)';
    } elseif (!preg_match('/^[A-Za-z0-9\s\.\,\-\/\#\&\(\)]+$/', $cooperating_mahal)) {
        $validation_errors[] = 'Co-operating mahal contains invalid characters';
    }

    if ($requested_by === '') {
        $validation_errors[] = 'Requested by field is required';
    } elseif (strlen($requested_by) > 120) {
        $validation_errors[] = 'Requested by name is too long (max 120 characters)';
    } elseif (!preg_match('/^[A-Za-z\s\.\-\']+$/', $requested_by)) {
        $validation_errors[] = 'Requested by name can only contain letters, spaces, dots, hyphens, and apostrophes';
    }

    if ($signed_by !== '' && strlen($signed_by) > 120) {
        $validation_errors[] = 'Signed by name is too long (max 120 characters)';
    } elseif ($signed_by !== '' && !preg_match('/^[A-Za-z\s\.\-\']+$/', $signed_by)) {
        $validation_errors[] = 'Signed by name can only contain letters, spaces, dots, hyphens, and apostrophes';
    }

    if ($notes !== '' && strlen($notes) > 1000) {
        $validation_errors[] = 'Notes are too long (max 1000 characters)';
    }

    // Photo validation
    function validate_photo(string $field): array
    {
        if (empty($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => 'Please upload a photo'];
        }

        $max_size = 5 * 1024 * 1024; // 5MB
        if ($_FILES[$field]['size'] > $max_size) {
            return ['valid' => false, 'error' => 'Photo size exceeds 5MB limit'];
        }

        $f = new finfo(FILEINFO_MIME_TYPE);
        $mime = $f->file($_FILES[$field]['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/jpg'], true)) {
            return ['valid' => false, 'error' => 'Only JPG and PNG images are allowed'];
        }

        return ['valid' => true, 'error' => ''];
    }

    $groom_photo_valid = validate_photo('groom_photo');
    if (!$groom_photo_valid['valid']) {
        $validation_errors[] = 'Groom photo: ' . $groom_photo_valid['error'];
    }

    $bride_photo_valid = validate_photo('bride_photo');
    if (!$bride_photo_valid['valid']) {
        $validation_errors[] = 'Bride photo: ' . $bride_photo_valid['error'];
    }

    if (!empty($validation_errors)) {
        $errors = array_merge($errors, $validation_errors);
    }

    // Helper to read uploaded file as BLOB (JPEG/PNG only)
    function read_image_blob(string $field): ?string
    {
        if (empty($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
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

    $groom_blob = read_image_blob('groom_photo');
    $bride_blob = read_image_blob('bride_photo');

    if (empty($errors)) {
        try {
            $db_result = get_db_connection();
            if (isset($db_result['error'])) {
                throw new Exception("DB connection failed: " . $db_result['error']);
            }
            /** @var mysqli $conn */
            $conn = $db_result['conn'];

            // Ensure cert_requests table has the columns (safety)
            $createTableSql = "
                CREATE TABLE IF NOT EXISTS cert_requests (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    member_id INT UNSIGNED NOT NULL,
                    is_sahakari TINYINT(1) NOT NULL DEFAULT 0,
                    certificate_type VARCHAR(50) NOT NULL,
                    reg_number VARCHAR(50) NULL,
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
                    INDEX idx_status (status),
                    INDEX idx_reg_number (reg_number),
                    INDEX idx_is_sahakari (is_sahakari)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ";
            $conn->query($createTableSql);

            // Ensure is_sahakari column exists (idempotent check)
            $resCol = $conn->query("SHOW COLUMNS FROM cert_requests LIKE 'is_sahakari'");
            if ($resCol && $resCol->num_rows === 0) {
                $conn->query("ALTER TABLE cert_requests ADD is_sahakari TINYINT(1) NOT NULL DEFAULT 0 AFTER member_id");
            }

            $resCol = $conn->query("SHOW COLUMNS FROM cert_requests LIKE 'groom_photo'");
            if ($resCol && $resCol->num_rows === 0) {
                $conn->query("ALTER TABLE cert_requests ADD groom_photo LONGBLOB NULL AFTER details_json");
            }
            $resCol = $conn->query("SHOW COLUMNS FROM cert_requests LIKE 'bride_photo'");
            if ($resCol && $resCol->num_rows === 0) {
                $conn->query("ALTER TABLE cert_requests ADD bride_photo LONGBLOB NULL AFTER groom_photo");
            }
            // Ensure reg_number column exists
            $resCol = $conn->query("SHOW COLUMNS FROM cert_requests LIKE 'reg_number'");
            if ($resCol && $resCol->num_rows === 0) {
                $conn->query("ALTER TABLE cert_requests 
                              ADD reg_number VARCHAR(50) NULL AFTER certificate_type");
            }

            // Generate registration number for THIS member's mahal
            $reg_number = generate_marriage_reg_number($conn, $household_member_id, $is_sahakari);

            // Pack details as JSON (same structure admin form expects)
            $details = [
                'groom_name' => $groom_name,
                'groom_parent' => $groom_parent,
                'groom_dob' => $groom_dob,
                'groom_address' => $groom_address,
                'bride_name' => $bride_name,
                'bride_parent' => $bride_parent,
                'bride_dob' => $bride_dob,
                'bride_address' => $bride_address,
                'marriage_date' => $marriage_date,
                'marriage_venue' => $marriage_venue,
                'cooperating_mahal' => $cooperating_mahal,
                'reg_number' => $reg_number,
                'requested_by' => $requested_by,
                'signed_by' => $signed_by, // may be empty
            ];
            $details_json = json_encode($details, JSON_UNESCAPED_UNICODE);

            // 1) Insert main Marriage Certificate request
            $sqlIns = "
                INSERT INTO cert_requests (
                    member_id,
                    is_sahakari,
                    certificate_type,
                    reg_number,
                    details_json,
                    groom_photo,
                    bride_photo,
                    notes,
                    status
                ) VALUES (?, ?, 'marriage', ?, ?, ?, ?, ?, 'pending')
            ";
            $stmt = $conn->prepare($sqlIns);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }

            $is_sahakari_int = $is_sahakari ? 1 : 0;

            $stmt->bind_param(
                'iisssss',
                $household_member_id,
                $is_sahakari_int,
                $reg_number,
                $details_json,
                $groom_blob,
                $bride_blob,
                $notes
            );
            $stmt->execute();
            $stmt->close();

            // 2) Automatically create additional request: Marriage Consent Letter
            $sqlIns2 = "
                INSERT INTO cert_requests (
                    member_id,
                    is_sahakari,
                    certificate_type,
                    reg_number,
                    details_json,
                    groom_photo,
                    bride_photo,
                    notes,
                    status
                ) VALUES (?, ?, 'marriage_consent_letter', ?, ?, ?, ?, ?, 'pending')
            ";
            $stmt2 = $conn->prepare($sqlIns2);
            if (!$stmt2) {
                throw new Exception('Prepare (consent letter) failed: ' . $conn->error);
            }

            $stmt2->bind_param(
                'iisssss',
                $household_member_id,
                $is_sahakari_int,
                $reg_number,
                $details_json,
                $groom_blob,
                $bride_blob,
                $notes
            );
            $stmt2->execute();
            $stmt2->close();

            $conn->close();

            unset($_SESSION['pending_cert_member_id'], $_SESSION['pending_cert_notes']);

            $_SESSION['member_success'] = 'Marriage certificate request submitted successfully.';
            header('Location: member_dashboard.php');
            exit();

        } catch (Throwable $e) {
            error_log('member_marriage_request submit error: ' . $e->getMessage());
            $errors[] = 'Could not submit your request right now. Please try again later.';
        }
    }
} else {
    // GET: pre-fill from session if needed
    $notes = $notes_from_session;
    $groom_name = $groom_parent = $groom_dob = $groom_address = '';
    $bride_name = $bride_parent = $bride_dob = $bride_address = '';
    $marriage_date = $marriage_venue = $cooperating_mahal = $reg_number = $requested_by = $signed_by = '';
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Marriage Certificate Request</title>
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

        /* Header */
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

        .btn-success {
            background: linear-gradient(135deg, var(--success), #059669);
            border-color: var(--success);
            color: #fff;
            box-shadow: var(--shadow)
        }

        .btn-success:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px)
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            border-color: var(--danger);
            color: #fff;
            box-shadow: var(--shadow)
        }

        .btn-danger:hover {
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

        /* Form Styles */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
            font-size: .875rem;
            display: flex;
            gap: .75rem;
            align-items: flex-start;
            border: 2px solid transparent;
            box-shadow: var(--shadow);
            display: none;
        }

        .alert.show {
            display: flex;
        }

        .alert i {
            font-size: 1.25rem;
            margin-top: .1rem
        }

        .alert-error {
            background: linear-gradient(135deg, var(--warning-light), #fef3c7);
            color: #92400e;
            border-color: var(--warning)
        }

        .alert-success {
            background: linear-gradient(135deg, var(--success-light), #a7f3d0);
            color: #065f46;
            border-color: var(--success)
        }

        .loading {
            text-align: center;
            padding: 40px;
            display: none;
            background: #fff;
            border-radius: 12px;
            border: 1px solid var(--border);
            margin-bottom: 1.5rem;
        }

        .loading.active {
            display: block
        }

        .loading p {
            color: var(--secondary);
            font-size: 15px
        }

        .form-section {
            background: #fff;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }

        .form-section h2 {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            padding-bottom: .75rem;
            border-bottom: 2px solid var(--border);
            color: var(--dark);
        }

        .form-section h3 {
            font-size: 1.1rem;
            font-weight: 600;
            margin: 1.5rem 0 1rem;
            color: var(--dark);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.25rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .form-group.full {
            grid-column: 1/-1;
        }

        label {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: .5rem;
            font-size: .9rem;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        label .required {
            color: var(--danger);
        }

        input,
        select,
        textarea {
            padding: .75rem 1rem;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: .875rem;
            font-family: inherit;
            transition: all .3s;
            background: #fff;
            width: 100%;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        /* Validation Styles */
        input.invalid,
        select.invalid,
        textarea.invalid {
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

        .photo-preview {
            margin-top: 6px;
            border-radius: 8px;
            max-width: 120px;
            max-height: 120px;
            display: none;
            border: 2px solid var(--border);
            object-fit: cover;
        }

        .photo-preview.show {
            display: block;
        }

        .help {
            font-size: .75rem;
            color: var(--text-light);
            margin-top: .25rem;
            font-style: italic;
        }

        /* Disable submit button when form is invalid */
        .btn-success:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        .readonly-field {
            background: #f3f4f6;
            border-style: dashed;
            color: var(--text-secondary);
        }

        /* Responsive */
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

            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .actions {
                flex-direction: column;
            }

            .actions-left,
            .actions-right {
                width: 100%;
            }

            .btn {
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
                        <img src="/ma/logo.jpeg" alt="Member Logo"
                            style="width: 100%; height: 100%; object-fit: cover;">
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

                    <button class="menu-btn active" type="button">
                        <i class="fas fa-ring"></i>
                        <span>Marriage Certificate</span>
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
                        <i class="fas fa-ring"></i>
                        <span style="font-weight: bold; font-size: 22px; color: black;">Marriage Certificate
                            Request</span>
                    </div>
                    <a href="member_dashboard.php" class="btn btn-primary">← Back to Dashboard</a>
                </div>
            </section>

            <div class="main-container">
                <div id="alertBox" class="alert"></div>
                <div id="loadingBox" class="loading">
                    <p>💾 Submitting request...</p>
                </div>

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

                <form id="marriageForm" method="POST" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <div class="form-section">
                        <h2>Marriage Certificate Request</h2>
                        <div class="help">Please provide complete details for both groom and bride. All fields marked
                            with * are required.</div>

                        <h3>Groom Details</h3>
                        <div class="form-grid">
                            <!-- Groom Name -->
                            <div class="form-group">
                                <label>Groom Full Name <span class="required">*</span></label>
                                <input type="text" name="groom_name" id="groom_name"
                                    placeholder="Enter groom's full name" required maxlength="120"
                                    value="<?php echo htmlspecialchars($groom_name ?? ''); ?>">
                                <div class="validation-error" id="groom_name-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Name is required (2-120 characters, only letters, spaces, dots,
                                        hyphens)</span>
                                </div>
                            </div>

                            <!-- Groom Parent -->
                            <div class="form-group">
                                <label>Groom Parent's Name <span class="required">*</span></label>
                                <input type="text" name="groom_parent" id="groom_parent"
                                    placeholder="Father's/Mother's name" required maxlength="120"
                                    value="<?php echo htmlspecialchars($groom_parent ?? ''); ?>">
                                <div class="validation-error" id="groom_parent-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Parent's name is required (2-120 characters, only letters, spaces, dots,
                                        hyphens, apostrophes)</span>
                                </div>
                            </div>

                            <!-- Groom DOB -->
                            <div class="form-group">
                                <label>Groom Date of Birth <span class="required">*</span></label>
                                <input type="date" name="groom_dob" id="groom_dob" required
                                    value="<?php echo htmlspecialchars($groom_dob ?? ''); ?>">
                                <div class="validation-error" id="groom_dob-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Valid date of birth required (cannot be in future)</span>
                                </div>
                            </div>

                            <!-- Groom Photo -->
                            <div class="form-group">
                                <label>Groom Photo <span class="required">*</span></label>
                                <input type="file" name="groom_photo" id="groom_photo" accept="image/jpeg,image/png"
                                    required>
                                <div class="validation-error" id="groom_photo-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>JPG/PNG image required (max 5MB)</span>
                                </div>
                                <div class="help">JPG or PNG image, max 5MB</div>
                                <img id="groomPreview" class="photo-preview" alt="Groom preview">
                            </div>

                            <!-- Groom Address -->
                            <div class="form-group full">
                                <label>Groom Address <span class="required">*</span></label>
                                <textarea name="groom_address" id="groom_address" rows="2"
                                    placeholder="Complete residential address" required
                                    maxlength="500"><?php echo htmlspecialchars($groom_address ?? ''); ?></textarea>
                                <div class="validation-error" id="groom_address-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Address is required (5-500 characters, alphanumeric with common
                                        punctuation)</span>
                                </div>
                            </div>
                        </div>

                        <h3>Bride Details</h3>
                        <div class="form-grid">
                            <!-- Bride Name -->
                            <div class="form-group">
                                <label>Bride Full Name <span class="required">*</span></label>
                                <input type="text" name="bride_name" id="bride_name"
                                    placeholder="Enter bride's full name" required maxlength="120"
                                    value="<?php echo htmlspecialchars($bride_name ?? ''); ?>">
                                <div class="validation-error" id="bride_name-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Name is required (2-120 characters, only letters, spaces, dots,
                                        hyphens)</span>
                                </div>
                            </div>

                            <!-- Bride Parent -->
                            <div class="form-group">
                                <label>Bride Parent's Name <span class="required">*</span></label>
                                <input type="text" name="bride_parent" id="bride_parent"
                                    placeholder="Father's/Mother's name" required maxlength="120"
                                    value="<?php echo htmlspecialchars($bride_parent ?? ''); ?>">
                                <div class="validation-error" id="bride_parent-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Parent's name is required (2-120 characters, only letters, spaces, dots,
                                        hyphens, apostrophes)</span>
                                </div>
                            </div>

                            <!-- Bride DOB -->
                            <div class="form-group">
                                <label>Bride Date of Birth <span class="required">*</span></label>
                                <input type="date" name="bride_dob" id="bride_dob" required
                                    value="<?php echo htmlspecialchars($bride_dob ?? ''); ?>">
                                <div class="validation-error" id="bride_dob-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Valid date of birth required (cannot be in future)</span>
                                </div>
                            </div>

                            <!-- Bride Photo -->
                            <div class="form-group">
                                <label>Bride Photo <span class="required">*</span></label>
                                <input type="file" name="bride_photo" id="bride_photo" accept="image/jpeg,image/png"
                                    required>
                                <div class="validation-error" id="bride_photo-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>JPG/PNG image required (max 5MB)</span>
                                </div>
                                <div class="help">JPG or PNG image, max 5MB</div>
                                <img id="bridePreview" class="photo-preview" alt="Bride preview">
                            </div>

                            <!-- Bride Address -->
                            <div class="form-group full">
                                <label>Bride Address <span class="required">*</span></label>
                                <textarea name="bride_address" id="bride_address" rows="2"
                                    placeholder="Complete residential address" required
                                    maxlength="500"><?php echo htmlspecialchars($bride_address ?? ''); ?></textarea>
                                <div class="validation-error" id="bride_address-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Address is required (5-500 characters, alphanumeric with common
                                        punctuation)</span>
                                </div>
                            </div>
                        </div>

                        <h3>Marriage Details</h3>
                        <div class="form-grid">
                            <!-- Marriage Date -->
                            <div class="form-group">
                                <label>Marriage Date <span class="required">*</span></label>
                                <input type="date" name="marriage_date" id="marriage_date" required
                                    value="<?php echo htmlspecialchars($marriage_date ?? ''); ?>">
                                <div class="validation-error" id="marriage_date-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Valid marriage date required (cannot be in future)</span>
                                </div>
                            </div>

                            <!-- Marriage Venue -->
                            <div class="form-group">
                                <label>Marriage Venue <span class="required">*</span></label>
                                <input type="text" name="marriage_venue" id="marriage_venue"
                                    placeholder="Wedding location" required maxlength="200"
                                    value="<?php echo htmlspecialchars($marriage_venue ?? ''); ?>">
                                <div class="validation-error" id="marriage_venue-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Venue is required (2-200 characters, alphanumeric with common
                                        punctuation)</span>
                                </div>
                            </div>

                            <!-- Co-operating Mahal -->
                            <div class="form-group">
                                <label>Co-operating Mahal <span class="required">*</span></label>
                                <input type="text" name="cooperating_mahal" id="cooperating_mahal"
                                    placeholder="Other mahal if any" required maxlength="100"
                                    value="<?php echo htmlspecialchars($cooperating_mahal ?? ''); ?>">
                                <div class="validation-error" id="cooperating_mahal-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Required (max 100 characters, alphanumeric with common punctuation)</span>
                                </div>
                            </div>

                            <!-- Registration Number -->
                            <div class="form-group">
                                <label>Registration Number</label>
                                <input type="text" name="reg_number_display" id="reg_number_display"
                                    placeholder="Auto-generated" readonly class="readonly-field">
                                <div class="help">Will be generated automatically upon submission</div>
                            </div>

                            <!-- Requested By -->
                            <div class="form-group">
                                <label>Requested By <span class="required">*</span></label>
                                <input type="text" name="requested_by" id="requested_by"
                                    placeholder="Person requesting certificate" required maxlength="120"
                                    value="<?php echo htmlspecialchars($requested_by ?? ''); ?>">
                                <div class="validation-error" id="requested_by-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Required (2-120 characters, only letters, spaces, dots, hyphens,
                                        apostrophes)</span>
                                </div>
                            </div>

                            <!-- Signed By -->
                            <div class="form-group">
                                <label>Signed By (President/Secretary)</label>
                                <input type="text" name="signed_by" id="signed_by" placeholder="Official signatory"
                                    maxlength="120" value="<?php echo htmlspecialchars($signed_by ?? ''); ?>">
                                <div class="validation-error" id="signed_by-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Only letters, spaces, dots, hyphens, apostrophes allowed (max 120
                                        characters)</span>
                                </div>
                            </div>

                            <!-- Notes -->
                            <div class="form-group full">
                                <label>Notes (Optional)</label>
                                <textarea name="notes" id="notes" rows="3"
                                    placeholder="Additional information or special requests"
                                    maxlength="1000"><?php echo htmlspecialchars($notes ?? ''); ?></textarea>
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
                                    <i class="fas fa-paper-plane"></i> Submit Request
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
            if (!name || name.trim().length < 2 || name.trim().length > 120) return false;
            // Allow alphabets, spaces, dots, and hyphens
            const nameRegex = /^[A-Za-z\s\.\-]+$/;
            return nameRegex.test(name.trim());
        }

        function isValidParentName(name) {
            if (!name || name.trim().length < 2 || name.trim().length > 120) return false;
            // Allow alphabets, spaces, dots, hyphens, and apostrophes for names like O'Connor
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
            const addressRegex = /^[A-Za-z0-9\s\.\,\-\/\#\&\(\)\:]+$/;
            return addressRegex.test(address.trim());
        }

        function isValidVenue(venue) {
            if (!venue || venue.trim().length < 2 || venue.trim().length > 200) return false;
            // Allow alphanumeric, spaces, and common venue punctuation
            const venueRegex = /^[A-Za-z0-9\s\.\,\-\/\#\&\(\)\:\;]+$/;
            return venueRegex.test(venue.trim());
        }

        function isValidCooperatingMahal(mahal) {
            if (!mahal || mahal.trim().length === 0) return false;
            if (mahal.trim().length > 100) return false;
            const mahalRegex = /^[A-Za-z0-9\s\.\,\-\/\#\&\(\)]+$/;
            return mahalRegex.test(mahal.trim());
        }

        function isValidPersonName(name, optional = false) {
            if (optional && name === '') return true;
            if (!name || name.trim().length < 2 || name.trim().length > 120) return false;
            const nameRegex = /^[A-Za-z\s\.\-\']+$/;
            return nameRegex.test(name.trim());
        }

        function isValidPhoto(fileInput) {
            if (!fileInput.files || fileInput.files.length === 0) return false;

            const file = fileInput.files[0];
            const maxSize = 5 * 1024 * 1024; // 5MB
            const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];

            if (!allowedTypes.includes(file.type)) return false;
            if (file.size > maxSize) return false;
            return true;
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

            if (elementId === 'groom_name' || elementId === 'bride_name') {
                isValid = isValidName(inputElement.value);
            } else if (elementId === 'groom_parent' || elementId === 'bride_parent') {
                isValid = isValidParentName(inputElement.value);
            } else if (elementId === 'groom_dob' || elementId === 'bride_dob') {
                isValid = isValidDate(inputElement.value, false);
            } else if (elementId === 'marriage_date') {
                isValid = isValidDate(inputElement.value, true);
            } else if (elementId === 'groom_address' || elementId === 'bride_address') {
                isValid = isValidAddress(inputElement.value);
            } else if (elementId === 'marriage_venue') {
                isValid = isValidVenue(inputElement.value);
            } else if (elementId === 'requested_by') {
                isValid = isValidPersonName(inputElement.value, false);
            } else if (elementId === 'cooperating_mahal') {
                isValid = isValidCooperatingMahal(inputElement.value);
            } else if (elementId === 'signed_by') {
                isValid = isValidPersonName(inputElement.value, true);
            } else if (elementId === 'notes') {
                isValid = inputElement.value === '' || isValidText(inputElement.value, 1, 1000);
            } else if (elementId === 'groom_photo' || elementId === 'bride_photo') {
                isValid = isValidPhoto(inputElement);
            }

            showValidation(elementId, isValid, elementId !== 'signed_by' && elementId !== 'notes');
        }

        // Photo preview function
        function setupPhotoPreview(inputId, previewId) {
            const input = document.getElementById(inputId);
            const preview = document.getElementById(previewId);

            if (!input || !preview) return;

            input.addEventListener('change', function () {
                const file = this.files[0];
                if (file && file.type.match('image.*')) {
                    const reader = new FileReader();
                    reader.onload = function (e) {
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

        // Attach validation events for real-time feedback
        const allFields = [
            'groom_name', 'groom_parent', 'groom_dob', 'groom_photo', 'groom_address',
            'bride_name', 'bride_parent', 'bride_dob', 'bride_photo', 'bride_address',
            'marriage_date', 'marriage_venue', 'requested_by',
            'cooperating_mahal', 'signed_by', 'notes'
        ];

        allFields.forEach(fieldId => {
            const element = document.getElementById(fieldId);
            if (!element) return;

            if (fieldId.includes('photo')) {
                element.addEventListener('change', function () {
                    markAsTouched(fieldId);
                    const isValid = isValidPhoto(this);
                    showValidation(fieldId, isValid, true);
                });
            } else {
                element.addEventListener('blur', function () {
                    markAsTouched(fieldId);
                    let isValid = false;

                    if (fieldId === 'groom_name' || fieldId === 'bride_name') {
                        isValid = isValidName(this.value);
                    } else if (fieldId === 'groom_parent' || fieldId === 'bride_parent') {
                        isValid = isValidParentName(this.value);
                    } else if (fieldId === 'groom_dob' || fieldId === 'bride_dob') {
                        isValid = isValidDate(this.value, false);
                    } else if (fieldId === 'marriage_date') {
                        isValid = isValidDate(this.value, true);
                    } else if (fieldId === 'groom_address' || fieldId === 'bride_address') {
                        isValid = isValidAddress(this.value);
                    } else if (fieldId === 'marriage_venue') {
                        isValid = isValidVenue(this.value);
                    } else if (fieldId === 'requested_by') {
                        isValid = isValidPersonName(this.value, false);
                    } else if (fieldId === 'cooperating_mahal') {
                        isValid = isValidCooperatingMahal(this.value);
                    } else if (fieldId === 'signed_by') {
                        isValid = isValidPersonName(this.value, true);
                    } else if (fieldId === 'notes') {
                        isValid = this.value === '' || isValidText(this.value, 1, 1000);
                    }

                    showValidation(fieldId, isValid, fieldId !== 'cooperating_mahal' && fieldId !== 'signed_by' && fieldId !== 'notes');
                });

                // Real-time validation after first touch
                element.addEventListener('input', function () {
                    if (touchedFields.has(fieldId)) {
                        let isValid = false;

                        if (fieldId === 'groom_name' || fieldId === 'bride_name') {
                            isValid = isValidName(this.value);
                        } else if (fieldId === 'groom_parent' || fieldId === 'bride_parent') {
                            isValid = isValidParentName(this.value);
                        } else if (fieldId === 'groom_dob' || fieldId === 'bride_dob') {
                            isValid = isValidDate(this.value, false);
                        } else if (fieldId === 'marriage_date') {
                            isValid = isValidDate(this.value, true);
                        } else if (fieldId === 'groom_address' || fieldId === 'bride_address') {
                            isValid = isValidAddress(this.value);
                        } else if (fieldId === 'marriage_venue') {
                            isValid = isValidVenue(this.value);
                        } else if (fieldId === 'requested_by') {
                            isValid = isValidPersonName(this.value, false);
                        } else if (fieldId === 'cooperating_mahal') {
                            isValid = isValidCooperatingMahal(this.value);
                        } else if (fieldId === 'signed_by') {
                            isValid = isValidPersonName(this.value, true);
                        } else if (fieldId === 'notes') {
                            isValid = this.value === '' || isValidText(this.value, 1, 1000);
                        }

                        showValidation(fieldId, isValid, fieldId !== 'signed_by' && fieldId !== 'notes');
                    }
                });
            }
        });

        // Initial setup
        document.addEventListener('DOMContentLoaded', function () {
            // Setup photo previews
            setupPhotoPreview('groom_photo', 'groomPreview');
            setupPhotoPreview('bride_photo', 'bridePreview');
        });

        // Clear form functionality
        document.getElementById('clearBtn').addEventListener('click', function () {
            if (confirm('Are you sure you want to clear all form data?')) {
                document.getElementById('marriageForm').reset();
                hideAlert();

                // Reset photo previews
                document.getElementById('groomPreview').classList.remove('show');
                document.getElementById('groomPreview').src = '';
                document.getElementById('bridePreview').classList.remove('show');
                document.getElementById('bridePreview').src = '';

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
                'groom': [],
                'bride': [],
                'marriage': []
            };

            errors.forEach(error => {
                const field = error.field;
                if (field.includes('groom')) {
                    sections.groom.push(error);
                } else if (field.includes('bride')) {
                    sections.bride.push(error);
                } else {
                    sections.marriage.push(error);
                }
            });

            return sections;
        }

        // Show section-wise error summary
        function showSectionErrors(sections) {
            let errorMessage = '<strong>Please complete all required fields:</strong><br><br>';

            if (sections.groom.length > 0) {
                errorMessage += '<strong>Groom Details:</strong><br>';
                sections.groom.forEach(error => {
                    errorMessage += `• ${error.message}<br>`;
                });
                errorMessage += '<br>';
            }

            if (sections.bride.length > 0) {
                errorMessage += '<strong>Bride Details:</strong><br>';
                sections.bride.forEach(error => {
                    errorMessage += `• ${error.message}<br>`;
                });
                errorMessage += '<br>';
            }

            if (sections.marriage.length > 0) {
                errorMessage += '<strong>Marriage Details:</strong><br>';
                sections.marriage.forEach(error => {
                    errorMessage += `• ${error.message}<br>`;
                });
            }

            showAlert('error', errorMessage);
        }

        // Form submission - validation only on submit
        document.getElementById('marriageForm').addEventListener('submit', function (e) {
            e.preventDefault();

            // Clear previous alerts
            hideAlert();

            // Track all errors
            const validationErrors = [];

            // List of required fields for validation
            const requiredFields = [
                'groom_name', 'groom_parent', 'groom_dob', 'groom_photo', 'groom_address',
                'bride_name', 'bride_parent', 'bride_dob', 'bride_photo', 'bride_address',
                'bride_name', 'bride_parent', 'bride_dob', 'bride_photo', 'bride_address',
                'marriage_date', 'marriage_venue', 'requested_by', 'cooperating_mahal'
            ];

            const optionalFields = ['signed_by', 'notes'];

            // Force validation check for all required fields
            requiredFields.forEach(fieldId => {
                const element = document.getElementById(fieldId);
                if (!element) return;

                let isValid = false;
                let errorMessage = '';

                if (fieldId.includes('photo')) {
                    isValid = isValidPhoto(element);
                    errorMessage = 'Please upload a valid photo (JPG/PNG, max 5MB)';
                } else if (fieldId.includes('name') && !fieldId.includes('parent')) {
                    isValid = isValidName(element.value);
                    errorMessage = 'Name is required';
                } else if (fieldId.includes('parent')) {
                    isValid = isValidParentName(element.value);
                    errorMessage = 'Parent name is required';
                } else if (fieldId.includes('dob')) {
                    isValid = isValidDate(element.value, false);
                    errorMessage = 'Valid date of birth required';
                } else if (fieldId === 'marriage_date') {
                    isValid = isValidDate(element.value, true);
                    errorMessage = 'Valid marriage date required';
                } else if (fieldId.includes('address')) {
                    isValid = isValidAddress(element.value);
                    errorMessage = 'Address is required';
                } else if (fieldId === 'marriage_venue') {
                    isValid = isValidVenue(element.value);
                    errorMessage = 'Venue is required';
                } else if (fieldId === 'requested_by') {
                    isValid = isValidPersonName(element.value, false);
                    errorMessage = 'Requested by is required';
                } else if (fieldId === 'cooperating_mahal') {
                    isValid = isValidCooperatingMahal(element.value);
                    errorMessage = 'Co-operating mahal is required';
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

                if (fieldId === 'signed_by') {
                    isValid = isValidPersonName(element.value, true);
                    errorMessage = 'Signed by contains invalid characters';
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

            fetch('member_marriage_request.php', {
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