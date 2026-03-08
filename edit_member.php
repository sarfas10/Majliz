<?php
// edit_member.php
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

require_once 'db_connection.php';

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

require_once 'db_connection.php';

/* ---------------- Validation helpers (server-side) ---------------- */
function normalize_doc_number($s)
{
    return strtoupper(preg_replace('/\s+/', '', (string) $s));
}
function validate_doc_by_type($type, $number)
{
    $t = strtolower($type ?? '');
    $n = normalize_doc_number($number ?? '');
    switch ($t) {
        case 'aadhaar':
            return preg_match('/^\d{12}$/', $n);
        case 'pan':
            return preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', $n);
        case 'voter id':
            return preg_match('/^[A-Z]{3}\d{7}$/', $n);
        case 'passport':
            return preg_match('/^[A-Z][0-9]{7}$/', $n);
        case "driver's licence":
            return preg_match('/^[A-Z]{2}\d{2}\s?\d{7,11}$/', $n);
        case 'ration card':
            return preg_match('/^[A-Z0-9]{8,16}$/', $n);
        case 'birth certificate':
            return preg_match('/^[A-Z0-9\-\/]{6,20}$/', $n);
        case 'other':
            return strlen($n) >= 4;
        default:
            return strlen($n) >= 4;
    }
}
function is_valid_indian_phone($s)
{
    $v = preg_replace('/[\s-]+/', '', (string) $s);
    return (bool) preg_match('/^(\+91)?[6-9]\d{9}$/', $v);
}
function not_future_date($str)
{
    if (!$str)
        return true;
    $d = strtotime($str);
    if ($d === false)
        return false;
    return $d <= strtotime('today');
}

/* ---------------- Ensure tables/columns/indexes exist ---------------- */
function createTablesIfNotExist($conn)
{
    try {
        // members
        $check_members = $conn->query("SHOW TABLES LIKE 'members'");
        if ($check_members->num_rows == 0) {
            $members_table = "CREATE TABLE members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                head_name VARCHAR(255) NOT NULL,
                father_name VARCHAR(255) DEFAULT NULL,
                email VARCHAR(255) NOT NULL,
                phone VARCHAR(20) NOT NULL,
                dob DATE DEFAULT NULL,
                gender VARCHAR(20) DEFAULT NULL,
                status VARCHAR(20) DEFAULT 'active',
                occupation VARCHAR(255) DEFAULT NULL,
                address TEXT NOT NULL,
                mahal_id INT NOT NULL,
                member_number INT UNSIGNED DEFAULT NULL,
                join_date DATE NOT NULL,
                total_family_members INT DEFAULT 1,
                monthly_donation_due VARCHAR(20) DEFAULT 'pending',
                total_due DECIMAL(10,2) DEFAULT 0.00,
                monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                bulk_print_enabled TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_mahal_id (mahal_id),
                INDEX idx_email (email),
                UNIQUE KEY uniq_mahal_member_number (mahal_id, member_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            if (!$conn->query($members_table)) {
                throw new Exception("Error creating members table: " . $conn->error);
            }
            $conn->query("ALTER TABLE members ADD CONSTRAINT fk_members_mahal 
                          FOREIGN KEY (mahal_id) REFERENCES register(id) 
                          ON DELETE CASCADE ON UPDATE CASCADE");
        } else {
            // add legacy/missing columns if needed
            $check_father = $conn->query("SHOW COLUMNS FROM members LIKE 'father_name'");
            if ($check_father->num_rows == 0) {
                $conn->query("ALTER TABLE members ADD COLUMN father_name VARCHAR(255) DEFAULT NULL AFTER head_name");
            }

            $check_mdd = $conn->query("SHOW COLUMNS FROM members LIKE 'monthly_donation_due'");
            if ($check_mdd->num_rows == 0) {
                $conn->query("ALTER TABLE members ADD COLUMN monthly_donation_due VARCHAR(20) DEFAULT 'pending' AFTER total_family_members");
            }
            $check_total_due = $conn->query("SHOW COLUMNS FROM members LIKE 'total_due'");
            if ($check_total_due->num_rows == 0) {
                $conn->query("ALTER TABLE members ADD COLUMN total_due DECIMAL(10,2) DEFAULT 0.00 AFTER monthly_donation_due");
            }
            $check_monthly_fee = $conn->query("SHOW COLUMNS FROM members LIKE 'monthly_fee'");
            if ($check_monthly_fee->num_rows == 0) {
                $conn->query("ALTER TABLE members ADD COLUMN monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_due");
            }
            // member_number column
            $check_member_number = $conn->query("SHOW COLUMNS FROM members LIKE 'member_number'");
            if ($check_member_number->num_rows == 0) {
                $conn->query("ALTER TABLE members ADD COLUMN member_number INT UNSIGNED DEFAULT NULL AFTER mahal_id");
            }
            // status column
            $check_status = $conn->query("SHOW COLUMNS FROM members LIKE 'status'");
            if ($check_status->num_rows == 0) {
                $conn->query("ALTER TABLE members ADD COLUMN status VARCHAR(20) DEFAULT 'active' AFTER gender");
            }
            // bulk_print_enabled column (default false)
            $check_bulk = $conn->query("SHOW COLUMNS FROM members LIKE 'bulk_print_enabled'");
            if ($check_bulk->num_rows == 0) {
                $conn->query("ALTER TABLE members ADD COLUMN bulk_print_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER monthly_fee");
            }
            // unique index on (mahal_id, member_number)
            $idxRes = $conn->query("SHOW INDEX FROM members WHERE Key_name = 'uniq_mahal_member_number'");
            if (!$idxRes || $idxRes->num_rows == 0) {
                $conn->query("ALTER TABLE members ADD UNIQUE KEY uniq_mahal_member_number (mahal_id, member_number)");
            }
        }

        // family_members
        $check_family = $conn->query("SHOW TABLES LIKE 'family_members'");
        if ($check_family->num_rows == 0) {
            $family_table = "CREATE TABLE family_members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                father_name VARCHAR(255) DEFAULT NULL,
                relationship VARCHAR(50) NOT NULL,
                dob DATE DEFAULT NULL,
                gender VARCHAR(20) DEFAULT NULL,
                phone VARCHAR(20) DEFAULT NULL,
                email VARCHAR(255) DEFAULT NULL,
                status VARCHAR(20) DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_member_id (member_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            if (!$conn->query($family_table)) {
                throw new Exception("Error creating family_members table: " . $conn->error);
            }
            $conn->query("ALTER TABLE family_members ADD CONSTRAINT fk_family_member 
                 FOREIGN KEY (member_id) REFERENCES members(id) 
                 ON DELETE CASCADE ON UPDATE CASCADE");
        } else {
            // ensure father_name column exists
            $check_father_col = $conn->query("SHOW COLUMNS FROM family_members LIKE 'father_name'");
            if ($check_father_col->num_rows == 0) {
                $conn->query("ALTER TABLE family_members ADD COLUMN father_name VARCHAR(255) DEFAULT NULL AFTER name");
            }
            // ensure status column exists
            $check_status = $conn->query("SHOW COLUMNS FROM family_members LIKE 'status'");
            if ($check_status->num_rows == 0) {
                $conn->query("ALTER TABLE family_members ADD COLUMN status VARCHAR(20) DEFAULT 'active' AFTER email");
            }
        }

        // member_documents
        $check_docs = $conn->query("SHOW TABLES LIKE 'member_documents'");
        if ($check_docs->num_rows == 0) {
            $docs_table = "CREATE TABLE member_documents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                family_member_id INT DEFAULT NULL,
                owner_type ENUM('head','family') NOT NULL,
                doc_type VARCHAR(50) NOT NULL,
                doc_number VARCHAR(100) NOT NULL,
                name_on_doc VARCHAR(255) DEFAULT NULL,
                issued_by VARCHAR(255) DEFAULT NULL,
                issued_on DATE DEFAULT NULL,
                expiry_on DATE DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                file_path VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_member_id (member_id),
                INDEX idx_family_member_id (family_member_id),
                INDEX idx_doc_type (doc_type),
                INDEX idx_doc_number (doc_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            if (!$conn->query($docs_table)) {
                throw new Exception("Error creating member_documents table: " . $conn->error);
            }
            $conn->query("ALTER TABLE member_documents
                          ADD CONSTRAINT fk_docs_member
                          FOREIGN KEY (member_id) REFERENCES members(id)
                          ON DELETE CASCADE ON UPDATE CASCADE");
            $conn->query("ALTER TABLE member_documents
                          ADD CONSTRAINT fk_docs_family
                          FOREIGN KEY (family_member_id) REFERENCES family_members(id)
                          ON DELETE CASCADE ON UPDATE CASCADE");
        }

        return true;
    } catch (Exception $e) {
        error_log("Table creation error: " . $e->getMessage());
        throw $e;
    }
}

/* ----------- helper: next member_number for a mahal ----------- */
function getNextMemberNumberForMahal($conn, $mahal_id)
{
    $next = 1;
    try {
        $stmt = $conn->prepare("SELECT MAX(member_number) AS mx FROM members WHERE mahal_id = ?");
        $stmt->bind_param("i", $mahal_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($res && $res['mx'] !== null)
            $next = ((int) $res['mx']) + 1;
    } catch (Throwable $e) {
    }
    return $next;
}

/* ----------- helper: find if a family row already has an individual member ----------- */
/* Match priority: phone (strict) -> email (if present). Excludes the current head. */
function findLinkedIndividual($conn, $mahal_id, $current_head_member_id, $fm_name, $fm_phone, $fm_email)
{
    $fm_phone = trim((string) $fm_phone);
    $fm_email = trim((string) $fm_email);

    // by phone
    if ($fm_phone !== '') {
        $q = $conn->prepare("SELECT id, member_number, head_name FROM members 
                             WHERE mahal_id = ? AND phone = ? AND id <> ? LIMIT 1");
        $q->bind_param("isi", $mahal_id, $fm_phone, $current_head_member_id);
        $q->execute();
        $r = $q->get_result()->fetch_assoc();
        $q->close();
        if ($r)
            return $r;
    }
    // by email (non-empty)
    if ($fm_email !== '') {
        $q = $conn->prepare("SELECT id, member_number, head_name FROM members 
                             WHERE mahal_id = ? AND email = ? AND id <> ? LIMIT 1");
        $q->bind_param("isi", $mahal_id, $fm_email, $current_head_member_id);
        $q->execute();
        $r = $q->get_result()->fetch_assoc();
        $q->close();
        if ($r)
            return $r;
    }
    return null;
}

/* --------------------------- API (Update) --------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_member') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    ini_set('display_errors', '0');
    ini_set('html_errors', '0');
    header('Content-Type: application/json; charset=utf-8');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $member_id = isset($_POST['member_id']) ? (int) $_POST['member_id'] : 0;
    if ($member_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid member ID.']);
        exit;
    }

    $conn = null;
    $stmt = null;
    $family_stmt = null;
    $doc_stmt = null;

    try {
        $db_result = get_db_connection();
        if (isset($db_result['error']))
            throw new Exception("Database connection failed: " . $db_result['error']);
        $conn = $db_result['conn'];

        createTablesIfNotExist($conn);

        $mahal_id = (int) $_SESSION['user_id'];

        // ensure the member belongs to this mahal
        $check = $conn->prepare("SELECT id, address, join_date, head_name FROM members WHERE id = ? AND mahal_id = ?");
        $check->bind_param("ii", $member_id, $mahal_id);
        $check->execute();
        $res = $check->get_result();
        $exists = $res->fetch_assoc();
        $check->close();
        if (!$exists)
            throw new Exception('Member not found or access denied.');

        // --------- Gather & validate inputs ----------
        $head_name = isset($_POST['head_name']) ? trim($_POST['head_name']) : '';
        $head_father_name = isset($_POST['head_father_name']) ? trim($_POST['head_father_name']) : null;
        $head_email = isset($_POST['head_email']) ? trim($_POST['head_email']) : '';
        $head_phone = isset($_POST['head_phone']) ? trim($_POST['head_phone']) : '';
        $head_address = isset($_POST['head_address']) ? trim($_POST['head_address']) : '';
        if ($head_name === '')
            throw new Exception('Head of family name is required.');
        if ($head_father_name !== null && $head_father_name !== '' && strlen($head_father_name) > 255)
            throw new Exception('Father name is too long.');
        if ($head_email === '' || !filter_var($head_email, FILTER_VALIDATE_EMAIL))
            throw new Exception('A valid email address is required.');
        if ($head_phone === '' || !is_valid_indian_phone($head_phone))
            throw new Exception('Invalid Indian mobile number.');
        if ($head_address === '' || strlen($head_address) < 10)
            throw new Exception('Address must be at least 10 characters.');

        $head_dob = isset($_POST['head_dob']) && $_POST['head_dob'] !== '' ? $_POST['head_dob'] : null;
        if ($head_dob && !not_future_date($head_dob))
            throw new Exception('Head DOB cannot be in the future.');
        $head_gender = isset($_POST['head_gender']) && $_POST['head_gender'] !== '' ? $_POST['head_gender'] : null;
        $head_occupation = isset($_POST['head_occupation']) && $_POST['head_occupation'] !== '' ? trim($_POST['head_occupation']) : null;
        $join_date = isset($_POST['join_date']) && $_POST['join_date'] !== '' ? $_POST['join_date'] : null;
        if ($join_date && !not_future_date($join_date))
            throw new Exception('Join date cannot be in the future.');

        $monthly_donation_due = isset($_POST['monthly_donation_due']) ? trim($_POST['monthly_donation_due']) : 'cleared';
        if (!in_array($monthly_donation_due, ['cleared', 'due'], true))
            throw new Exception('Monthly donation status must be cleared or due.');

        $monthly_fee = isset($_POST['monthly_fee']) && $_POST['monthly_fee'] !== '' ? floatval($_POST['monthly_fee']) : 0.00;
        if (!is_numeric($monthly_fee) || $monthly_fee < 0)
            throw new Exception('Monthly fee must be a number ≥ 0.');

        // NEW: Monthly Fee Advance
        $monthly_fee_advance = isset($_POST['monthly_fee_advance']) && $_POST['monthly_fee_advance'] !== '' ? floatval($_POST['monthly_fee_advance']) : 0.00;
        if (!is_numeric($monthly_fee_advance) || $monthly_fee_advance < 0)
            throw new Exception('Monthly fee advance must be a number ≥ 0.');

        // bulk_print_enabled (default false)
        $bulk_print_enabled = (isset($_POST['bulk_print_enabled']) && $_POST['bulk_print_enabled'] === '1') ? 1 : 0;

        // member_number (required positive int)
        if (!isset($_POST['member_number']) || $_POST['member_number'] === '') {
            throw new Exception('Member number is required.');
        }
        if (!ctype_digit((string) $_POST['member_number'])) {
            throw new Exception('Member number must be a positive integer.');
        }
        $member_number = (int) $_POST['member_number'];
        if ($member_number < 1)
            throw new Exception('Member number must be at least 1.');

        // Get the status from POST data
        $member_status = isset($_POST['member_status']) ? trim($_POST['member_status']) : 'active';

        // Documents JSON
        $head_documents = [];
        if (isset($_POST['head_documents'])) {
            $decoded_hd = json_decode($_POST['head_documents'], true);
            if (is_array($decoded_hd))
                $head_documents = $decoded_hd;
        }

        // Family JSON (with docs + also_as_member + member_number + per-child fee/dues)
        $family_members = [];
        if (isset($_POST['family_members'])) {
            $decoded = json_decode($_POST['family_members'], true);
            if (is_array($decoded))
                $family_members = $decoded;
        }
        $total_members = count($family_members) + 1;

        // Uniqueness for (mahal_id, member_number) excluding this member
        $chk = $conn->prepare("SELECT COUNT(*) AS c FROM members WHERE mahal_id = ? AND member_number = ? AND id <> ?");
        $chk->bind_param("iii", $mahal_id, $member_number, $member_id);
        $chk->execute();
        $cc = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($cc && (int) $cc['c'] > 0) {
            throw new Exception('This member number already exists for your mahal. Please choose another.');
        }

        // Validate documents (head + family) + dedupe across all
        $seen_docs = [];
        foreach ($head_documents as $d) {
            $doc_type = isset($d['doc_type']) ? trim($d['doc_type']) : '';
            $doc_number = isset($d['doc_number']) ? trim($d['doc_number']) : '';
            if (($doc_type && !$doc_number) || (!$doc_type && $doc_number)) {
                throw new Exception('Each head document requires both Type and Number.');
            }
            if (!$doc_type && !$doc_number)
                continue;
            if (!validate_doc_by_type($doc_type, $doc_number)) {
                throw new Exception("Invalid $doc_type number format.");
            }
            $issued_on = isset($d['issued_on']) ? $d['issued_on'] : null;
            $expiry_on = isset($d['expiry_on']) ? $d['expiry_on'] : null;
            if ($issued_on && !not_future_date($issued_on))
                throw new Exception("$doc_type Issued On cannot be in the future.");
            if ($issued_on && $expiry_on && strtotime($expiry_on) < strtotime($issued_on)) {
                throw new Exception("$doc_type Expiry On cannot be before Issued On.");
            }
            $key = strtolower($doc_type) . '|' . normalize_doc_number($doc_number);
            if (isset($seen_docs[$key]))
                throw new Exception("Duplicate $doc_type number across documents.");
            $seen_docs[$key] = true;
        }
        foreach ($family_members as $fm) {
            $nm = isset($fm['name']) ? trim($fm['name']) : '';
            if ($nm === '')
                throw new Exception('Family member name cannot be empty.');

            $fphone = isset($fm['phone']) ? trim($fm['phone']) : '';
            if ($fphone !== '' && !is_valid_indian_phone($fphone)) {
                throw new Exception("Family member phone is invalid.");
            }
            $femail = isset($fm['email']) ? trim($fm['email']) : '';
            if ($femail !== '' && !filter_var($femail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Family member email is invalid.");
            }
            $fdob = isset($fm['dob']) && $fm['dob'] !== '' ? $fm['dob'] : null;
            if ($fdob && !not_future_date($fdob))
                throw new Exception("Family member DOB cannot be in the future.");

            // father_name validation (new)
            $fm_father_name = isset($fm['father_name']) ? trim($fm['father_name']) : null;
            if ($fm_father_name !== null && $fm_father_name !== '' && strlen($fm_father_name) > 255) {
                throw new Exception("Family member father's name too long for '{$nm}'.");
            }

            if (isset($fm['documents']) && is_array($fm['documents'])) {
                foreach ($fm['documents'] as $d) {
                    $doc_type = isset($d['doc_type']) ? trim($d['doc_type']) : '';
                    $doc_number = isset($d['doc_number']) ? trim($d['doc_number']) : '';
                    if (($doc_type && !$doc_number) || (!$doc_type && $doc_number)) {
                        throw new Exception('Each family document requires both Type and Number.');
                    }
                    if (!$doc_type && !$doc_number)
                        continue;
                    if (!validate_doc_by_type($doc_type, $doc_number)) {
                        throw new Exception("Invalid $doc_type number format (family).");
                    }
                    $issued_on = isset($d['issued_on']) ? $d['issued_on'] : null;
                    $expiry_on = isset($d['expiry_on']) ? $d['expiry_on'] : null;
                    if ($issued_on && !not_future_date($issued_on))
                        throw new Exception("$doc_type Issued On cannot be in the future.");
                    if ($issued_on && $expiry_on && strtotime($expiry_on) < strtotime($issued_on)) {
                        throw new Exception("$doc_type Expiry On cannot be before Issued On.");
                    }
                    $key = strtolower($doc_type) . '|' . normalize_doc_number($doc_number);
                    if (isset($seen_docs[$key]))
                        throw new Exception("Duplicate $doc_type number across head/family documents.");
                    $seen_docs[$key] = true;
                }
            }

            // If also_as_member true, validate provided numeric fields if present
            $also = isset($fm['also_as_member']) && ($fm['also_as_member'] === true || $fm['also_as_member'] === '1' || $fm['also_as_member'] === 'yes');
            if ($also) {
                // member_number optional — if provided, must be positive int and unique
                if (isset($fm['member_number']) && $fm['member_number'] !== '') {
                    if (!ctype_digit((string) $fm['member_number']) || (int) $fm['member_number'] < 1) {
                        throw new Exception("Member number for '{$nm}' must be a positive integer.");
                    }
                    // uniqueness check
                    $child_member_number = (int) $fm['member_number'];
                    $chk = $conn->prepare("SELECT COUNT(*) AS c FROM members WHERE mahal_id = ? AND member_number = ?");
                    $chk->bind_param("ii", $mahal_id, $child_member_number);
                    $chk->execute();
                    $cc = $chk->get_result()->fetch_assoc();
                    $chk->close();
                    if ($cc && (int) $cc['c'] > 0) {
                        throw new Exception("Member number {$child_member_number} already exists in your mahal (for '{$nm}').");
                    }
                }

                if (isset($fm['total_due']) && $fm['total_due'] !== '') {
                    if (!is_numeric($fm['total_due']) || floatval($fm['total_due']) < 0) {
                        throw new Exception("Invalid total_due for '{$nm}'. Must be ≥ 0.");
                    }
                }
                if (isset($fm['monthly_fee']) && $fm['monthly_fee'] !== '') {
                    if (!is_numeric($fm['monthly_fee']) || floatval($fm['monthly_fee']) < 0) {
                        throw new Exception("Invalid monthly_fee for '{$nm}'. Must be ≥ 0.");
                    }
                }
            }
        }

        // --------- DB writes ----------
        $conn->begin_transaction();

        // ensure total_due is read & validated
        $total_due = isset($_POST['total_due']) && $_POST['total_due'] !== '' ? floatval($_POST['total_due']) : 0.00;
        if (!is_numeric($total_due) || $total_due < 0)
            throw new Exception('Total due must be a number ≥ 0.');

        // Update member (includes member_number + monthly_fee + status + bulk_print_enabled + father_name)
        $sql = "UPDATE members SET 
                    head_name = ?, 
                    father_name = ?,
                    email = ?, 
                    phone = ?, 
                    dob = ?, 
                    gender = ?, 
                    status = ?,
                    occupation = ?, 
                    address = ?, 
                    member_number = ?, 
                    join_date = COALESCE(?, join_date),
                    total_family_members = ?, 
                    monthly_donation_due = ?, 
                    total_due = ?, 
                    monthly_fee = ?, 
                    monthly_fee_adv = ?,
                    bulk_print_enabled = ?,
                    updated_at = NOW()
                WHERE id = ? AND mahal_id = ?";

        $stmt = $conn->prepare($sql);
        if (!$stmt)
            throw new Exception("Prepare failed: " . $conn->error);

        // types string constructed to match params exactly:
        // head_name (s), father_name (s), email (s), phone (s), dob (s), gender (s),
        // status (s), occupation (s), address (s), member_number (i), join_date (s),
        // total_family_members (i), monthly_donation_due (s), total_due (d), monthly_fee (d), monthly_fee_adv (d),
        // bulk_print_enabled (i), member_id (i), mahal_id (i)
        $typeString = "sssssssssisisdddiii";


        $stmt->bind_param(
            $typeString,
            $head_name,
            $head_father_name,
            $head_email,
            $head_phone,
            $head_dob,
            $head_gender,
            $member_status,
            $head_occupation,
            $head_address,
            $member_number,
            $join_date,
            $total_members,
            $monthly_donation_due,
            $total_due,
            $monthly_fee,
            $monthly_fee_advance,
            $bulk_print_enabled,
            $member_id,
            $mahal_id
        );

        try {
            $stmt->execute();
        } catch (mysqli_sql_exception $mx) {
            if ((int) $conn->errno === 1062) {
                throw new Exception('This member number already exists for your mahal. Please choose another.');
            }
            throw $mx;
        }
        $stmt->close();


        /* delete + recreate family_members for this head ONLY.
           This will NOT touch any 'members' (individual) rows. */
        $del = $conn->prepare("DELETE FROM family_members WHERE member_id = ?");
        $del->bind_param("i", $member_id);
        $del->execute();
        $del->close();

        $family_ids = [];
        if (!empty($family_members)) {
            // Include father_name in insert
            $family_sql = "INSERT INTO family_members (member_id, name, father_name, relationship, dob, gender, phone, email, status, created_at, updated_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $family_stmt = $conn->prepare($family_sql);
            if (!$family_stmt)
                throw new Exception("Prepare failed for family members: " . $conn->error);

            foreach ($family_members as $fm) {
                $fm_name = isset($fm['name']) ? trim($fm['name']) : '';
                $fm_father_name = isset($fm['father_name']) ? trim($fm['father_name']) : null;
                $fm_relation = isset($fm['relationship']) ? trim($fm['relationship']) : '';
                $fm_dob = isset($fm['dob']) && $fm['dob'] !== '' ? $fm['dob'] : null;
                $fm_gender = isset($fm['gender']) && $fm['gender'] !== '' ? $fm['gender'] : null;
                $fm_phone = isset($fm['phone']) ? trim($fm['phone']) : '';
                $fm_email = isset($fm['email']) ? trim($fm['email']) : '';
                $fm_status = isset($fm['status']) && $fm['status'] !== '' ? trim($fm['status']) : 'active';

                if ($fm_name === '')
                    throw new Exception("Family member name cannot be empty.");

                // bind types: member_id (i), name (s), father_name (s), relationship (s), dob (s), gender (s), phone (s), email (s), status (s)
                $family_stmt->bind_param(
                    "issssssss",
                    $member_id,
                    $fm_name,
                    $fm_father_name,
                    $fm_relation,
                    $fm_dob,
                    $fm_gender,
                    $fm_phone,
                    $fm_email,
                    $fm_status
                );
                $family_stmt->execute();
                $family_ids[] = $conn->insert_id;
            }
            $family_stmt->close();
        }

        // Replace documents (delete -> insert)
        $deld = $conn->prepare("DELETE FROM member_documents WHERE member_id = ?");
        $deld->bind_param("i", $member_id);
        $deld->execute();
        $deld->close();

        $doc_sql = "INSERT INTO member_documents 
            (member_id, family_member_id, owner_type, doc_type, doc_number, name_on_doc, issued_by, issued_on, expiry_on, notes, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        $doc_stmt = $conn->prepare($doc_sql);
        if (!$doc_stmt)
            throw new Exception("Prepare failed for documents: " . $conn->error);
        $nn = function ($v) {
            return ($v === '' ? null : $v);
        };

        // Head docs
        foreach ($head_documents as $d) {
            $doc_type = isset($d['doc_type']) ? trim($d['doc_type']) : '';
            $doc_number = isset($d['doc_number']) ? trim($d['doc_number']) : '';
            if (!$doc_type && !$doc_number)
                continue;
            if ($doc_type === '' || $doc_number === '')
                throw new Exception("Head document requires both type and number.");
            $owner_type = 'head';
            $name_on_doc = $nn(isset($d['name_on_doc']) ? trim($d['name_on_doc']) : null);
            $issued_by = $nn(isset($d['issued_by']) ? trim($d['issued_by']) : null);
            $issued_on = $nn(isset($d['issued_on']) ? trim($d['issued_on']) : null);
            $expiry_on = $nn(isset($d['expiry_on']) ? trim($d['expiry_on']) : null);
            $notes = $nn(isset($d['notes']) ? trim($d['notes']) : null);
            $family_member_id = null;

            $doc_stmt->bind_param(
                "iissssssss",
                $member_id,
                $family_member_id,
                $owner_type,
                $doc_type,
                $doc_number,
                $name_on_doc,
                $issued_by,
                $issued_on,
                $expiry_on,
                $notes
            );
            $doc_stmt->execute();
        }

        // Family docs (posted order; keep unlinked for simplicity)
        foreach ($family_members as $idx => $fm) {
            if (!isset($fm['documents']) || !is_array($fm['documents']))
                continue;
            foreach ($fm['documents'] as $d) {
                $doc_type = isset($d['doc_type']) ? trim($d['doc_type']) : '';
                $doc_number = isset($d['doc_number']) ? trim($d['doc_number']) : '';
                if (!$doc_type && !$doc_number)
                    continue;
                if ($doc_type === '' || $doc_number === '')
                    throw new Exception("Family document requires both type and number.");
                $owner_type = 'family';
                $name_on_doc = $nn(isset($d['name_on_doc']) ? trim($d['name_on_doc']) : null);
                $issued_by = $nn(isset($d['issued_by']) ? trim($d['issued_by']) : null);
                $issued_on = $nn(isset($d['issued_on']) ? trim($d['issued_on']) : null);
                $expiry_on = $nn(isset($d['expiry_on']) ? trim($d['expiry_on']) : null);
                $notes = $nn(isset($d['notes']) ? trim($d['notes']) : null);
                $family_member_id = null;

                $doc_stmt->bind_param(
                    "iissssssss",
                    $member_id,
                    $family_member_id,
                    $owner_type,
                    $doc_type,
                    $doc_number,
                    $name_on_doc,
                    $issued_by,
                    $issued_on,
                    $expiry_on,
                    $notes
                );
                $doc_stmt->execute();
            }
        }
        if ($doc_stmt) {
            $doc_stmt->close();
        }

        /* ---- CREATE selected family members as individual members (edit mode)
               Phone REQUIRED, Email OPTIONAL.
               If a matching individual already exists (by phone/email), SKIP creation.
               Accept per-child total_due and monthly_fee if provided. ---- */
        foreach ($family_members as $fm) {
            $create_as_member = isset($fm['also_as_member']) && ($fm['also_as_member'] === true || $fm['also_as_member'] === '1' || $fm['also_as_member'] === 'yes');

            if (!$create_as_member)
                continue;

            $child_name = isset($fm['name']) ? trim($fm['name']) : '';
            $child_phone = isset($fm['phone']) ? trim($fm['phone']) : '';
            $child_email = isset($fm['email']) ? trim($fm['email']) : '';
            if ($child_name === '') {
                throw new Exception("Family member name required when creating as individual member.");
            }
            if ($child_phone === '' || !is_valid_indian_phone($child_phone)) {
                throw new Exception("Valid phone is required for '{$child_name}' when creating as individual member.");
            }
            if ($child_email !== '' && !filter_var($child_email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Email provided for '{$child_name}' looks invalid.");
            }

            // If already exists as individual, skip creation
            $linked = findLinkedIndividual($conn, $mahal_id, $member_id, $child_name, $child_phone, $child_email);
            if ($linked) {
                continue;
            }

            $child_dob = isset($fm['dob']) && $fm['dob'] !== '' ? $fm['dob'] : null;
            $child_gender = isset($fm['gender']) && $fm['gender'] !== '' ? $fm['gender'] : null;
            $child_occ = null;
            $child_addr = $head_address;
            $child_join = $join_date ?: $exists['join_date'];
            $child_monthly_status = $monthly_donation_due;

            // Use provided per-child total_due and monthly_fee if present, otherwise defaults (head values)
            $child_total_due = 0.00;
            if (isset($fm['total_due']) && $fm['total_due'] !== '') {
                $child_total_due = (float) $fm['total_due'];
            }

            $child_monthly_fee = $monthly_fee;
            if (isset($fm['monthly_fee']) && $fm['monthly_fee'] !== '') {
                $child_monthly_fee = (float) $fm['monthly_fee'];
            }

            // Determine status for this person - get from family member data
            $child_status = isset($fm['status']) ? trim($fm['status']) : 'active';

            // Determine member_number for this person
            if (isset($fm['member_number']) && $fm['member_number'] !== '') {
                if (!ctype_digit((string) $fm['member_number']) || (int) $fm['member_number'] < 1) {
                    throw new Exception("Member number for '{$child_name}' must be a positive integer.");
                }
                $child_member_number = (int) $fm['member_number'];

                // uniqueness check
                $chk = $conn->prepare("SELECT COUNT(*) AS c FROM members WHERE mahal_id = ? AND member_number = ?");
                $chk->bind_param("ii", $mahal_id, $child_member_number);
                $chk->execute();
                $cc = $chk->get_result()->fetch_assoc();
                $chk->close();
                if ($cc && (int) $cc['c'] > 0) {
                    throw new Exception("Member number {$child_member_number} already exists in your mahal (for '{$child_name}').");
                }
            } else {
                // auto-assign
                $child_member_number = getNextMemberNumberForMahal($conn, $mahal_id);
            }

            // Set child's father_name: prefer provided family father_name, otherwise fallback to current head_name
            $child_father_name = isset($fm['father_name']) && $fm['father_name'] !== '' ? $fm['father_name'] : $head_name;

            $sqlChild = "INSERT INTO members
                (head_name, father_name, email, phone, dob, gender, status, occupation, address, mahal_id, member_number, join_date,
                 total_family_members, monthly_donation_due, total_due, monthly_fee, monthly_fee_adv, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $stmtChild = $conn->prepare($sqlChild);
            if (!$stmtChild)
                throw new Exception("Prepare failed (child member): " . $conn->error);

            $child_total_members = 1;

            $child_monthly_fee_advance = 0.00;

            // final correct type string:
            // 1-9: s x9, 10-11: i i, 12: s, 13: i, 14: s, 15: d, 16: d, 17: d, 18: i
            $typeChild = "sssssssssiisiddd";
            $stmtChild->bind_param(
                $typeChild,
                $child_name,
                $child_father_name,
                $child_email,
                $child_phone,
                $child_dob,
                $child_gender,
                $child_status,
                $child_occ,
                $child_addr,
                $mahal_id,
                $child_member_number,
                $child_join,
                $child_total_members,
                $child_monthly_status,
                $child_total_due,
                $child_monthly_fee,
                $child_monthly_fee_advance
            );

            try {
                $stmtChild->execute();
            } catch (mysqli_sql_exception $mx) {
                if ((int) $conn->errno === 1062) {
                    throw new Exception("Duplicate member number {$child_member_number} for '{$child_name}'.");
                }
                throw $mx;
            }
            $new_member_id = $stmtChild->insert_id;
            $stmtChild->close();

            // Copy family docs (if any) to new member as HEAD docs
            if (isset($fm['documents']) && is_array($fm['documents']) && !empty($fm['documents'])) {
                foreach ($fm['documents'] as $d) {
                    $doc_type = isset($d['doc_type']) ? trim($d['doc_type']) : '';
                    $doc_number = isset($d['doc_number']) ? trim($d['doc_number']) : '';
                    if ($doc_type === '' || $doc_number === '')
                        continue;

                    $name_on_doc = $nn(isset($d['name_on_doc']) ? trim($d['name_on_doc']) : null);
                    $issued_by = $nn(isset($d['issued_by']) ? trim($d['issued_by']) : null);
                    $issued_on = $nn(isset($d['issued_on']) ? trim($d['issued_on']) : null);
                    $expiry_on = $nn(isset($d['expiry_on']) ? trim($d['expiry_on']) : null);
                    $notes = $nn(isset($d['notes']) ? trim($d['notes']) : null);
                    $owner_type = 'head';
                    $family_member_id_null = null;

                    $doc_stmt2 = $conn->prepare("INSERT INTO member_documents
                        (member_id, family_member_id, owner_type, doc_type, doc_number, name_on_doc, issued_by, issued_on, expiry_on, notes, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    if (!$doc_stmt2)
                        throw new Exception("Prepare failed when copying documents for '{$child_name}': " . $conn->error);
                    $doc_stmt2->bind_param(
                        "iissssssss",
                        $new_member_id,
                        $family_member_id_null,
                        $owner_type,
                        $doc_type,
                        $doc_number,
                        $name_on_doc,
                        $issued_by,
                        $issued_on,
                        $expiry_on,
                        $notes
                    );
                    $doc_stmt2->execute();
                    $doc_stmt2->close();
                }
            }
        }
        /* ---- END create also-as-member ---- */

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => "Member '{$head_name}' updated successfully!",
            'member_id' => $member_id
        ]);
        exit;

    } catch (Exception $e) {
        if ($conn && !$conn->connect_errno)
            $conn->rollback();
        if (isset($doc_stmt) && $doc_stmt) {
            @$doc_stmt->close();
        }
        if (isset($family_stmt) && $family_stmt) {
            @$family_stmt->close();
        }
        if (isset($stmt) && $stmt) {
            @$stmt->close();
        }
        if ($conn) {
            @$conn->close();
        }

        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

/* --------------------------- Page (GET) --------------------------- */
$member_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($member_id <= 0) {
    header("Location: member-management.php");
    exit();
}

$member = null;
$family_members = [];
$head_documents = [];
$family_documents_by_id = []; // key: family_member_id => [docs...]

try {
    $db_result = get_db_connection();
    if (isset($db_result['error']))
        throw new Exception("Database connection failed: " . $db_result['error']);
    $conn = $db_result['conn'];

    createTablesIfNotExist($conn);

    // member
    $stmt = $conn->prepare("SELECT * FROM members WHERE id = ? AND mahal_id = ?");
    $stmt->bind_param("ii", $member_id, $_SESSION['user_id']);
    $stmt->execute();
    $res = $stmt->get_result();
    $member = $res->fetch_assoc();
    $stmt->close();

    if (!$member) {
        $conn->close();
        header("Location: member-management.php");
        exit();
    }

    // family members (include id)
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
            END, name ASC
    ");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $family_members = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // decorate each family member with linked individual info (if any)
    foreach ($family_members as &$fmref) {
        $linked = findLinkedIndividual(
            $conn,
            (int) $_SESSION['user_id'],
            (int) $member['id'],
            (string) ($fmref['name'] ?? ''),
            (string) ($fmref['phone'] ?? ''),
            (string) ($fmref['email'] ?? '')
        );
        if ($linked) {
            $fmref['_linked_member_id'] = (int) $linked['id'];
            $fmref['_linked_member_number'] = $linked['member_number'] !== null ? (int) $linked['member_number'] : null;
            $fmref['_linked_head_name'] = $linked['head_name'] ?? '';
        } else {
            $fmref['_linked_member_id'] = null;
            $fmref['_linked_member_number'] = null;
            $fmref['_linked_head_name'] = '';
        }
    }
    unset($fmref);

    // head documents
    $stmt = $conn->prepare("SELECT id, owner_type, doc_type, doc_number, name_on_doc, issued_by, issued_on, expiry_on, notes 
                            FROM member_documents 
                            WHERE member_id = ? AND owner_type = 'head' AND (family_member_id IS NULL)");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $head_documents = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // family documents (grouped)
    $stmt = $conn->prepare("SELECT family_member_id, owner_type, doc_type, doc_number, name_on_doc, issued_by, issued_on, expiry_on, notes
                            FROM member_documents 
                            WHERE member_id = ? AND owner_type = 'family' AND family_member_id IS NOT NULL");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $fid = (int) $row['family_member_id'];
        if (!isset($family_documents_by_id[$fid]))
            $family_documents_by_id[$fid] = [];
        $family_documents_by_id[$fid][] = $row;
    }
    $stmt->close();

    $conn->close();
} catch (Exception $e) {
    error_log("Edit member fetch error: " . $e->getMessage());
    header("Location: member-management.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Member - <?php echo htmlspecialchars($member['head_name']); ?></title>
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Add dashboard CSS -->
    <style>
        /* Dashboard CSS styles */
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

        /* Your existing form styles */
        :root {
            --primary-form: #2563eb;
            --success-form: #10b981;
            --danger-form: #ef4444;
            --gray-form: #6b7280;
            --border-form: #e5e7eb;
            --bg-gray-form: #f9fafb;
            font-family: Inter, 'Segoe UI', Roboto, Arial, sans-serif;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0
        }

        body {
            background: var(--bg);
            color: #111827;
            min-height: 100vh
        }

        .btn {
            padding: 10px 16px;
            border-radius: 8px;
            border: 0;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all .2s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary-form {
            background: var(--primary-form);
            color: #fff
        }

        .btn-primary-form:hover {
            background: #1d4ed8
        }

        .btn-success-form {
            background: var(--success-form);
            color: #fff
        }

        .btn-success-form:hover {
            background: #059669
        }

        .btn-danger-form {
            background: var(--danger-form);
            color: #fff
        }

        .btn-danger-form:hover {
            background: #dc2626
        }

        .btn-secondary-form {
            background: #fff;
            color: #374151;
            border: 1px solid var(--border-form)
        }

        .btn-secondary-form:hover {
            background: var(--bg-gray-form)
        }

        .container {
            max-width: 1200px;
            margin: 24px auto;
            padding: 0 20px;
        }

        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: none;
            font-size: 14px;
            font-weight: 500;
        }

        .alert.show {
            display: block
        }

        .alert-success-form {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0
        }

        .alert-error-form {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca
        }

        .loading {
            text-align: center;
            padding: 40px;
            display: none;
            background: #fff;
            border-radius: 12px;
            border: 1px solid var(--border-form);
        }

        .loading.active {
            display: block
        }

        .loading p {
            color: var(--gray-form);
            font-size: 15px
        }

        .form-section {
            background: #fff;
            border-radius: 12px;
            border: 1px solid var(--border-form);
            padding: 28px;
            margin-bottom: 20px;
        }

        .form-section h2 {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 24px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--border-form);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column
        }

        .form-group.full {
            grid-column: 1/-1
        }

        label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
            font-size: 14px;
        }

        label .required {
            color: var(--danger-form);
            margin-left: 2px
        }

        input,
        select,
        textarea {
            padding: 10px 14px;
            border: 1px solid var(--border-form);
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: border .2s;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: var(--primary-form);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .1);
        }

        textarea {
            resize: vertical;
            min-height: 100px
        }

        .family-card {
            background: var(--bg-gray-form);
            border: 1px solid var(--border-form);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 16px;
            position: relative;
        }

        .family-card h3 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .add-family-btn {
            margin-bottom: 20px;
        }

        .doc-card {
            background: #fff;
            border: 1px dashed var(--border-form);
            border-radius: 10px;
            padding: 16px;
            margin: 12px 0;
        }

        .doc-card h4 {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 12px;
            color: #111827;
        }

        .doc-controls {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .doc-controls .form-group {
            min-width: 220px;
            flex: 1;
        }

        .doc-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 10px 0 6px;
        }

        .doc-section-header .title {
            font-size: 14px;
            font-weight: 600;
            color: #374151;
        }

        .actions {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 24px 0;
        }

        .actions-left,
        .actions-right {
            display: flex;
            gap: 12px;
        }

        @media (max-width:768px) {
            .form-grid {
                grid-template-columns: 1fr
            }

            .form-group.full {
                grid-column: 1
            }

            .actions {
                flex-direction: column
            }

            .actions-left,
            .actions-right {
                width: 100%
            }

            .btn {
                width: 100%
            }
        }

        .help {
            font-size: 12px;
            color: #6b7280;
            margin-top: 6px
        }

        .error-message {
            color: #ef4444;
            font-size: 12px;
            margin-top: 4px;
            display: none;
        }

        input.invalid,
        select.invalid,
        textarea.invalid {
            border-color: #ef4444 !important;
        }

        input.valid,
        select.valid,
        textarea.valid {
            border-color: #10b981 !important;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid var(--border-form);
            background: #fff;
            margin-top: 8px;
            color: #374151
        }

        .badge.success {
            border-color: #a7f3d0;
            background: #ecfdf5;
            color: #065f46
        }

        .muted {
            color: #6b7280;
            font-size: 12px;
            margin-top: 6px
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
                            alt="<?php echo htmlspecialchars($mahal_name); ?> Logo"
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
            <div class="top-row">
                <button class="floating-menu-btn" id="menuToggle" aria-controls="sidebar" aria-expanded="false"
                    aria-label="Open menu" type="button">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="app-title">Edit Member — <?php echo htmlspecialchars($member['head_name']); ?></div>
                <a href="user_details.php?id=<?php echo (int) $member['id']; ?>" class="btn btn-primary-form">← Back to
                    Member</a>
            </div>

            <div class="container">
                <div id="alertBox" class="alert"></div>
                <div id="loadingBox" class="loading">
                    <p>✏️ Updating member data...</p>
                </div>

                <form id="memberForm" method="POST" novalidate>
                    <input type="hidden" name="action" value="update_member">
                    <input type="hidden" name="member_id" value="<?php echo (int) $member['id']; ?>">

                    <!-- Head of Family -->
                    <div class="form-section">
                        <h2>Head of Family Details</h2>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Full Name <span class="required">*</span></label>
                                <input type="text" name="head_name" id="head_name"
                                    value="<?php echo htmlspecialchars($member['head_name']); ?>" required
                                    maxlength="120">
                                <div class="error-message" id="head_name_error">Only letters and spaces allowed</div>
                            </div>

                            <!-- Father's Name (new) -->
                            <div class="form-group">
                                <label>Father's Name <span class="required">*</span></label>
                                <input type="text" name="head_father_name" id="head_father_name"
                                    value="<?php echo htmlspecialchars($member['father_name'] ?? ''); ?>" required
                                    maxlength="255">
                                <div class="error-message" id="head_father_name_error">Only letters and spaces allowed
                                </div>
                                <div class="help">Required — appears next to member name.</div>
                            </div>

                            <!-- Member Number (per-mahal) -->
                            <div class="form-group">
                                <label>Member No. (Mahal) <span class="required">*</span></label>
                                <input type="number" name="member_number" id="member_number" min="1" step="1" required
                                    value="<?php echo htmlspecialchars((string) ($member['member_number'] ?? ''), ENT_QUOTES); ?>">
                                <div class="error-message" id="member_number_error">Only numbers allowed</div>
                                <div class="help">Sequential number unique within your mahal.</div>
                            </div>

                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="head_email" id="head_email"
                                    value="<?php echo htmlspecialchars($member['email']); ?>" maxlength="120"
                                    inputmode="email" autocomplete="email">
                                <div class="error-message" id="head_email_error">Valid email required (must contain @
                                    and .com/.in/.org etc.)</div>
                            </div>
                            <div class="form-group">
                                <label>Phone Number <span class="required">*</span></label>
                                <input type="tel" name="head_phone" id="head_phone"
                                    value="<?php echo htmlspecialchars($member['phone']); ?>" required inputmode="tel"
                                    maxlength="16" pattern="^(\+91[\s-]?)?[6-9]\d{9}$"
                                    title="Indian mobile: 10 digits starting 6-9 (with optional +91)">
                                <div class="error-message" id="head_phone_error">Valid 10-digit Indian mobile number
                                    required</div>
                            </div>
                            <div class="form-group">
                                <label>Date of Birth <span class="required">*</span></label>
                                <input type="date" name="head_dob" id="head_dob"
                                    value="<?php echo $member['dob'] ? htmlspecialchars($member['dob']) : ''; ?>"
                                    required>
                                <div class="error-message" id="head_dob_error">Date cannot be in future</div>
                            </div>
                            <div class="form-group">
                                <label>Gender <span class="required">*</span></label>
                                <select name="head_gender" id="head_gender" required>
                                    <option value="" <?php echo empty($member['gender']) ? 'selected' : ''; ?>>Select
                                        Gender
                                    </option>
                                    <option value="Male" <?php echo ($member['gender'] === 'Male') ? 'selected' : ''; ?>>
                                        Male
                                    </option>
                                    <option value="Female" <?php echo ($member['gender'] === 'Female') ? 'selected' : ''; ?>>
                                        Female</option>
                                    <option value="Other" <?php echo ($member['gender'] === 'Other') ? 'selected' : ''; ?>>
                                        Other</option>
                                </select>
                                <div class="error-message" id="head_gender_error">Please select a gender</div>
                            </div>
                            <div class="form-group">
                                <label>Member Status <span class="required">*</span></label>
                                <select name="member_status" id="member_status" required>
                                    <option value="" <?php echo empty($member['status']) ? 'selected' : ''; ?>>Select
                                        Status</option>
                                    <option value="active" <?php echo ($member['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active (Alive)</option>
                                    <option value="death" <?php echo ($member['status'] ?? '') === 'death' ? 'selected' : ''; ?>>Deceased</option>
                                    <option value="freeze" <?php echo ($member['status'] ?? '') === 'freeze' ? 'selected' : ''; ?>>Frozen (Dues continue)</option>
                                    <option value="terminate" <?php echo ($member['status'] ?? '') === 'terminate' ? 'selected' : ''; ?>>Terminated</option>
                                </select>
                                <div class="help">"Frozen" status continues due generation. "Deceased" and "Terminated"
                                    stop all activities.</div>
                            </div>
                            <div class="form-group">
                                <label>Occupation <span class="required">*</span></label>
                                <select name="head_occupation" id="head_occupation" required>
                                    <option value="">Select Occupation</option>
                                    <option value="Business" <?php echo ($member['occupation'] === 'Business') ? 'selected' : ''; ?>>Business</option>
                                    <option value="Service" <?php echo ($member['occupation'] === 'Service') ? 'selected' : ''; ?>>Service</option>
                                    <option value="Professional" <?php echo ($member['occupation'] === 'Professional') ? 'selected' : ''; ?>>Professional</option>
                                    <option value="Homemaker" <?php echo ($member['occupation'] === 'Homemaker') ? 'selected' : ''; ?>>Homemaker</option>
                                    <option value="Student" <?php echo ($member['occupation'] === 'Student') ? 'selected' : ''; ?>>Student</option>
                                    <option value="Retired" <?php echo ($member['occupation'] === 'Retired') ? 'selected' : ''; ?>>Retired</option>
                                    <option value="Agriculture" <?php echo ($member['occupation'] === 'Agriculture') ? 'selected' : ''; ?>>Agriculture</option>
                                    <option value="Laborer" <?php echo ($member['occupation'] === 'Laborer') ? 'selected' : ''; ?>>Laborer</option>
                                    <option value="Self Employed" <?php echo ($member['occupation'] === 'Self Employed') ? 'selected' : ''; ?>>Self Employed</option>
                                    <option value="Unemployed" <?php echo ($member['occupation'] === 'Unemployed') ? 'selected' : ''; ?>>Unemployed</option>
                                    <option value="Other" <?php echo ($member['occupation'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                                <div class="error-message" id="head_occupation_error">Please select an occupation</div>
                            </div>
                            <div class="form-group full">
                                <label>Address <span class="required">*</span></label>
                                <textarea name="head_address" id="head_address" required
                                    minlength="10"><?php echo htmlspecialchars($member['address']); ?></textarea>
                                <div class="error-message" id="head_address_error">Address must be at least 10
                                    characters</div>
                            </div>
                            <div class="form-group">
                                <label>Join Date <span class="required">*</span></label>
                                <input type="date" name="join_date" id="join_date"
                                    value="<?php echo htmlspecialchars($member['join_date']); ?>" required>
                                <div class="error-message" id="join_date_error">Date cannot be in future</div>
                            </div>

                            <!-- Head Documents -->
                            <div class="doc-section-header" style="margin-top:16px;">
                                <div class="title">Identity Documents (Head)</div>
                                <button type="button" class="btn btn-success-form" id="addHeadDocBtn">+ Add
                                    Document</button>
                            </div>
                            <div id="headDocumentsContainer"></div>
                        </div>

                        <!-- Financial -->
                        <div class="form-section">
                            <h2>Financial Details</h2>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Monthly Donation Status</label>
                                    <select name="monthly_donation_due" id="monthly_donation_due">
                                        <option value="cleared" <?php echo ($member['monthly_donation_due'] === 'cleared') ? 'selected' : ''; ?>>Cleared
                                        </option>
                                        <option value="due" <?php echo ($member['monthly_donation_due'] === 'due') ? 'selected' : ''; ?>>Due</option>
                                    </select>
                                    <div class="error-message" id="monthly_donation_due_error">Please select donation
                                        status</div>
                                </div>
                                <div class="form-group">
                                    <label>Total Due Amount (₹)</label>
                                    <input type="number" name="total_due" id="total_due" step="0.01" min="0"
                                        value="<?php echo htmlspecialchars(number_format((float) $member['total_due'], 2, '.', '')); ?>">
                                    <div class="error-message" id="total_due_error">Must be a positive number</div>
                                </div>
                                <div class="form-group">
                                    <label>Monthly Fee (₹ / month)</label>
                                    <input type="number" name="monthly_fee" id="monthly_fee" step="0.01" min="0"
                                        value="<?php echo htmlspecialchars(number_format((float) ($member['monthly_fee'] ?? 0), 2, '.', '')); ?>">
                                    <div class="error-message" id="monthly_fee_error">Must be a positive number</div>
                                </div>
                                <div class="form-group">
                                    <label>Monthly Fee Advance (₹)</label>
                                    <input type="number" name="monthly_fee_advance" id="monthly_fee_advance" step="0.01"
                                        min="0"
                                        value="<?php echo htmlspecialchars(number_format((float) ($member['monthly_fee_adv'] ?? 0), 2, '.', '')); ?>">
                                    <div class="error-message" id="monthly_fee_advance_error">Must be a positive number
                                    </div>
                                    <div class="help">Amount paid in advance for future monthly fees. Will not create a
                                        transaction.</div>
                                </div>
                                <div class="form-group">
                                    <label class="checkbox-inline" style="display:flex;align-items:center;gap:8px;">
                                        <input type="checkbox" name="bulk_print_enabled" id="bulk_print_enabled"
                                            value="1" <?php echo !empty($member['bulk_print_enabled']) ? 'checked' : ''; ?>>
                                        Enable Bulk Print
                                    </label>
                                    <div class="help">If checked, this member will be included in bulk print operations.
                                        Default is OFF.</div>
                                </div>
                            </div>
                        </div>

                        <!-- Family Members -->
                        <div class="form-section">
                            <h2>Family Members</h2>
                            <button type="button" class="btn btn-success-form add-family-btn" id="addFamilyMemberBtn">+
                                Add Family Member</button>
                            <div id="familyMembersContainer"></div>
                            <div class="help" style="margin-top:8px;">
                                If "Already an individual member" shows for someone, deleting them here will only remove
                                them from this family list — their separate individual member record stays.
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="actions">
                            <div class="actions-left">
                                <a href="user_details.php?id=<?php echo (int) $member['id']; ?>"
                                    class="btn btn-secondary-form">Cancel</a>
                                <button type="button" class="btn btn-secondary-form" id="clearBtn">Reset
                                    Changes</button>
                            </div>
                            <div class="actions-right">
                                <button type="submit" class="btn btn-success-form">Update Member</button>
                            </div>
                        </div>
                </form>
            </div>
        </main>
    </div>

    <script>
        /* ---------- boot data from PHP ---------- */
        const existingFamily = <?php echo json_encode($family_members, JSON_UNESCAPED_UNICODE); ?>;
        const existingHeadDocs = <?php echo json_encode($head_documents, JSON_UNESCAPED_UNICODE); ?>;
        const existingFamilyDocsById = <?php echo json_encode($family_documents_by_id, JSON_UNESCAPED_UNICODE); ?>;
        const HEAD_NAME = <?php echo json_encode($member['head_name'] ?? ''); ?>;

        /* ---------- constants & state ---------- */
        let familyMemberCount = 0;
        let headDocCount = 0;

        // default monthly fee to prefill per-child monthly fee inputs
        const DEFAULT_MEMBER_MONTHLY_FEE = <?php echo json_encode(number_format((float) ($member['monthly_fee'] ?? 0), 2, '.', '')); ?>;

        const DOC_TYPES = [
            "Aadhaar", "Voter ID", "PAN", "Driver's Licence", "Passport", "Ration Card", "Birth Certificate", "Other"
        ];

        /* ---------- helpers ---------- */
        function escapeHtml(text) {
            return (text || '')
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }
        function normalizeDocNumber(str) {
            return (str || "").toUpperCase().replace(/\s+/g, "");
        }
        function validateDocByType(type, number) {
            const num = normalizeDocNumber(number);
            switch ((type || "").toLowerCase()) {
                case "aadhaar": return /^\d{12}$/.test(num);
                case "pan": return /^[A-Z]{5}\d{4}[A-Z]$/.test(num);
                case "voter id": return /^[A-Z]{3}\d{7}$/.test(num);
                case "passport": return /^[A-Z][0-9]{7}$/.test(num);
                case "driver's licence": return /^[A-Z]{2}\d{2}\s?\d{7,11}$/.test(num);
                case "ration card": return /^[A-Z0-9]{8,16}$/.test(num);
                case "birth certificate": return /^[A-Z0-9\-\/]{6,20}$/.test(num);
                case "other": return num.length >= 4;
                default: return num.length >= 4;
            }
        }
        function isValidIndianPhone(s) {
            const v = (s || "").replace(/\s|-/g, "");
            return /^(\+91)?[6-9]\d{9}$/.test(v);
        }
        function renumberDocCards(container, prefix) {
            const cards = container.querySelectorAll('.doc-card h4');
            cards.forEach((h, i) => h.textContent = `${prefix} ${i + 1}`);
        }
        function strengthenFamilyInputs(container) {
            container.querySelectorAll('.fm-phone').forEach(i => {
                i.setAttribute('maxlength', '16');
                i.setAttribute('pattern', '^(\\+91[\\s-]?)?[6-9]\\d{9}$');
                i.setAttribute('title', 'Indian mobile: 10 digits starting 6-9 (optional +91)');
                i.setAttribute('inputmode', 'tel');
            });
            container.querySelectorAll('.fm-email').forEach(i => {
                i.setAttribute('maxlength', '120');
                i.setAttribute('inputmode', 'email');
                i.setAttribute('autocomplete', 'email');
            });
            container.querySelectorAll('.fm-name').forEach(i => i.setAttribute('maxlength', '120'));
            container.querySelectorAll('.fm-father-name').forEach(i => i.setAttribute('maxlength', '255'));
        }

        /* ---------- Inline validation functions ---------- */
        function validateName(name) {
            return /^[A-Za-z\s\.\-']+$/.test(name) && name.length >= 2;
        }

        function validateFatherName(name) {
            if (!name) return true; // Optional field
            return /^[A-Za-z\s\.\-']+$/.test(name);
        }

        function validateMemberNumber(number) {
            return /^[1-9]\d*$/.test(number);
        }

        function validateEmail(email) {
            if (!email) return true; // Optional field
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) &&
                (email.endsWith('.com') || email.endsWith('.in') || email.endsWith('.org') ||
                    email.endsWith('.net') || email.endsWith('.edu'));
        }

        function validatePhone(phone) {
            const v = (phone || "").replace(/\s|-/g, "");
            return /^(\+91)?[6-9]\d{9}$/.test(v);
        }

        function validateDateNotFuture(dateStr) {
            if (!dateStr) return true; // Now optional for family members
            const date = parseLocalDate(dateStr);
            if (!date) return false;
            return date <= todayLocalMidnight();
        }

        function validateGender(gender) {
            return gender !== '' || true; // Always true for family members (optional)
        }

        function validateOccupation(occupation) {
            return occupation !== '';
        }

        function validateAddress(address) {
            return address.length >= 10;
        }

        function validateMonthlyFee(fee) {
            const num = parseFloat(fee);
            return !isNaN(num) && num >= 0;
        }

        function validatePositiveNumber(num) {
            const n = parseFloat(num);
            return !isNaN(n) && n >= 0;
        }

        function validateJoinDate(dateStr) {
            if (!dateStr) return false; // Required field for head
            const date = parseLocalDate(dateStr);
            if (!date) return false;
            return date <= todayLocalMidnight();
        }

        // ---- local date helpers ----
        function parseLocalDate(ymd) {
            if (!ymd) return null;
            const parts = ymd.split('-').map(Number);
            const y = parts[0], m = (parts[1] || 1) - 1, d = parts[2] || 1;
            return new Date(y, m, d);
        }

        function todayLocalMidnight() {
            const t = new Date();
            t.setHours(0, 0, 0, 0);
            return t;
        }

        /* ---------- Inline validation handler ---------- */
        function setupInlineValidation(inputId, validationFn, errorMessageId, errorText, isRequired = false) {
            const input = document.getElementById(inputId);
            const errorElement = document.getElementById(errorMessageId);

            if (!input || !errorElement) return;

            // Set error text
            errorElement.textContent = errorText;

            input.addEventListener('input', function () {
                const value = input.value.trim();

                // Only validate if user has typed something
                if (value === '') {
                    input.classList.remove('invalid', 'valid');
                    errorElement.style.display = 'none';
                    return;
                }

                const isValid = validationFn(value);

                if (isValid) {
                    input.classList.remove('invalid');
                    input.classList.add('valid');
                    errorElement.style.display = 'none';
                } else {
                    input.classList.remove('valid');
                    input.classList.add('invalid');
                    errorElement.style.display = 'block';
                }
            });

            input.addEventListener('blur', function () {
                const value = input.value.trim();

                // Only validate if user has typed something
                if (value === '') {
                    input.classList.remove('invalid', 'valid');
                    errorElement.style.display = 'none';
                    return;
                }

                const isValid = validationFn(value);

                if (isValid) {
                    input.classList.remove('invalid');
                    input.classList.add('valid');
                    errorElement.style.display = 'none';
                } else {
                    input.classList.remove('valid');
                    input.classList.add('invalid');
                    errorElement.style.display = 'block';
                }
            });
        }

        /* ---------- Setup all inline validations ---------- */
        function setupAllValidations() {
            // Head of family validations - ALL REQUIRED except email
            setupInlineValidation('head_name', validateName, 'head_name_error', 'Only letters and spaces allowed', true);
            setupInlineValidation('head_father_name', validateFatherName, 'head_father_name_error', 'Only letters and spaces allowed', true); // Required for head
            setupInlineValidation('member_number', validateMemberNumber, 'member_number_error', 'Only numbers allowed', true);
            setupInlineValidation('head_email', validateEmail, 'head_email_error', 'Valid email required (must contain @ and .com/.in/.org etc.)', false); // Optional
            setupInlineValidation('head_phone', validatePhone, 'head_phone_error', 'Valid 10-digit Indian mobile number required', true);
            setupInlineValidation('head_dob', validateDateNotFuture, 'head_dob_error', 'Date cannot be in future', true); // Required for head
            setupInlineValidation('head_address', validateAddress, 'head_address_error', 'Address must be at least 10 characters', true);
            setupInlineValidation('join_date', validateJoinDate, 'join_date_error', 'Date cannot be in future', true); // Required for head
            setupInlineValidation('monthly_fee', validateMonthlyFee, 'monthly_fee_error', 'Must be a positive number', true); // Required for head
            setupInlineValidation('total_due', validatePositiveNumber, 'total_due_error', 'Must be a positive number', true); // Required for head

            // For dropdowns, we'll handle them differently
            const genderSelect = document.getElementById('head_gender');
            const occupationSelect = document.getElementById('head_occupation');
            const statusSelect = document.getElementById('member_status');
            const donationSelect = document.getElementById('monthly_donation_due');

            // Gender validation - Required for head
            if (genderSelect) {
                const errorElement = document.getElementById('head_gender_error');
                genderSelect.addEventListener('change', function () {
                    const value = this.value;

                    if (value === '') {
                        this.classList.add('invalid');
                        this.classList.remove('valid');
                        if (errorElement) {
                            errorElement.style.display = 'block';
                            errorElement.textContent = 'Please select a gender';
                        }
                    } else {
                        this.classList.remove('invalid');
                        this.classList.add('valid');
                        if (errorElement) errorElement.style.display = 'none';
                    }
                });
            }

            // Occupation validation - Required for head
            if (occupationSelect) {
                const errorElement = document.getElementById('head_occupation_error');
                occupationSelect.addEventListener('change', function () {
                    const value = this.value;

                    if (value === '') {
                        this.classList.add('invalid');
                        this.classList.remove('valid');
                        if (errorElement) {
                            errorElement.style.display = 'block';
                            errorElement.textContent = 'Please select an occupation';
                        }
                    } else {
                        this.classList.remove('invalid');
                        this.classList.add('valid');
                        if (errorElement) errorElement.style.display = 'none';
                    }
                });
            }

            // Status validation - Required for head
            if (statusSelect) {
                statusSelect.addEventListener('change', function () {
                    const value = this.value;
                    if (value !== '') {
                        this.classList.remove('invalid');
                        this.classList.add('valid');
                    }
                });
            }

            // Donation status validation - Required for head
            if (donationSelect) {
                const errorElement = document.getElementById('monthly_donation_due_error');
                donationSelect.addEventListener('change', function () {
                    const value = this.value;

                    if (value !== '') {
                        this.classList.remove('invalid');
                        this.classList.add('valid');
                        if (errorElement) errorElement.style.display = 'none';
                    }
                });
            }
        }

        /* ---------- Family member validation setup ---------- */
        function setupFamilyMemberValidation(familyCard) {
            const nameInput = familyCard.querySelector('.fm-name');
            const relationshipSelect = familyCard.querySelector('.fm-relationship');

            // Create error elements if they don't exist
            const createErrorElement = (input, messageId, errorText) => {
                let errorElement = input.parentNode.querySelector('.error-message');
                if (!errorElement) {
                    errorElement = document.createElement('div');
                    errorElement.className = 'error-message';
                    errorElement.id = messageId;
                    errorElement.textContent = errorText;
                    errorElement.style.display = 'none';
                    input.parentNode.appendChild(errorElement);
                }
                return errorElement;
            };

            // Name validation (required)
            if (nameInput) {
                const errorElement = createErrorElement(nameInput, 'fm_name_error_' + familyCard.id,
                    'Only letters and spaces allowed (min 2 characters)');

                nameInput.addEventListener('input', function () {
                    const value = this.value.trim();

                    if (value === '') {
                        this.classList.remove('invalid', 'valid');
                        errorElement.style.display = 'none';
                        return;
                    }

                    const isValid = validateName(value);

                    if (isValid) {
                        this.classList.remove('invalid');
                        this.classList.add('valid');
                        errorElement.style.display = 'none';
                    } else {
                        this.classList.remove('valid');
                        this.classList.add('invalid');
                        errorElement.style.display = 'block';
                    }
                });

                nameInput.addEventListener('blur', function () {
                    const value = this.value.trim();

                    if (value === '') {
                        this.classList.remove('invalid', 'valid');
                        errorElement.style.display = 'none';
                        return;
                    }

                    const isValid = validateName(value);

                    if (isValid) {
                        this.classList.remove('invalid');
                        this.classList.add('valid');
                        errorElement.style.display = 'none';
                    } else {
                        this.classList.remove('valid');
                        this.classList.add('invalid');
                        errorElement.style.display = 'block';
                    }
                });
            }

            // Relationship validation (required)
            if (relationshipSelect) {
                const errorElement = createErrorElement(relationshipSelect, 'fm_relationship_error_' + familyCard.id,
                    'Please select a relationship');

                relationshipSelect.addEventListener('change', function () {
                    const value = this.value;

                    if (value === '') {
                        this.classList.add('invalid');
                        this.classList.remove('valid');
                        errorElement.style.display = 'block';
                    } else {
                        this.classList.remove('invalid');
                        this.classList.add('valid');
                        errorElement.style.display = 'none';
                    }
                });
            }

            // All other fields are optional - no validation needed
        }

        /* ---------- Form field validation for form submission ---------- */
        function validateFormField(fieldId, validationFn, errorElementId, errorMessage, isRequired = false) {
            const field = document.getElementById(fieldId);
            const errorElement = document.getElementById(errorElementId);

            if (!field) return true;

            const value = field.value.trim();

            // Skip validation if field is empty and not required
            if (value === '' && !isRequired) {
                if (errorElement) errorElement.style.display = 'none';
                field.classList.remove('invalid');
                return true;
            }

            // For required fields, empty is invalid
            if (value === '' && isRequired) {
                field.classList.add('invalid');
                if (errorElement) {
                    errorElement.style.display = 'block';
                    errorElement.textContent = errorMessage;
                }
                field.focus();
                return false;
            }

            const isValid = validationFn(value);

            if (!isValid && errorElement) {
                field.classList.add('invalid');
                errorElement.style.display = 'block';
                errorElement.textContent = errorMessage;
                field.focus();
            } else if (errorElement) {
                field.classList.remove('invalid');
                errorElement.style.display = 'none';
            }

            return isValid;
        }

        /* ---------- document card factory ---------- */
        function createDocumentCard(titleText, prefill = {}) {
            const wrapper = document.createElement('div');
            wrapper.className = 'doc-card';
            wrapper.innerHTML = `
            <h4>${titleText}</h4>
            <div class="doc-controls">
                <div class="form-group">
                    <label>Document Type <span class="required">*</span></label>
                    <select class="doc-type"></select>
                </div>
                <div class="form-group">
                    <label>Document Number <span class="required">*</span></label>
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
                <button type="button" class="btn btn-danger-form doc-remove-btn">Remove Document</button>
            </div>
        `;

            const sel = wrapper.querySelector('.doc-type');
            DOC_TYPES.forEach(d => {
                const opt = document.createElement('option');
                opt.value = d;
                opt.textContent = d;
                sel.appendChild(opt);
            });
            if (prefill.doc_type) sel.value = prefill.doc_type;

            if (prefill.doc_number) wrapper.querySelector('.doc-number').value = prefill.doc_number;
            if (prefill.name_on_doc) wrapper.querySelector('.doc-name').value = prefill.name_on_doc;
            if (prefill.issued_by) wrapper.querySelector('.doc-issued-by').value = prefill.issued_by;
            if (prefill.issued_on) wrapper.querySelector('.doc-issued-on').value = prefill.issued_on;
            if (prefill.expiry_on) wrapper.querySelector('.doc-expiry-on').value = prefill.expiry_on;
            if (prefill.notes) wrapper.querySelector('.doc-notes').value = prefill.notes;

            return wrapper;
        }

        /* ---------- Head docs UI ---------- */
        document.getElementById('addHeadDocBtn').addEventListener('click', function () {
            headDocCount++;
            const card = createDocumentCard("Document " + headDocCount);
            card.querySelector('.doc-remove-btn').addEventListener('click', () => {
                card.remove();
                renumberDocCards(document.getElementById('headDocumentsContainer'), 'Document');
            });
            document.getElementById('headDocumentsContainer').appendChild(card);
            renumberDocCards(document.getElementById('headDocumentsContainer'), 'Document');
        });

        /* ---------- Family UI ---------- */
        document.getElementById('addFamilyMemberBtn').addEventListener('click', function () {
            addFamilyCard();
        });

        function addFamilyCard(prefill = {}, existingId = null) {
            familyMemberCount++;
            const memberCard = document.createElement('div');
            memberCard.className = 'family-card';
            memberCard.id = `family_member_${familyMemberCount}`;
            const alreadyId = prefill._linked_member_id || null;
            const alreadyNum = prefill._linked_member_number || null;

            // prefill father_name if present in prefill
            const preFather = prefill.father_name || '';

            memberCard.innerHTML = `
            <h3>Family Member ${familyMemberCount}</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label>Name <span class="required">*</span></label>
                    <input type="text" class="fm-name" placeholder="Enter name" value="${escapeHtml(prefill.name || '')}" required maxlength="120">
                </div>
                <div class="form-group">
                    <label>Relationship <span class="required">*</span></label>
                    <select class="fm-relationship">
                        <option value="">Select Relationship</option>
                        ${['Spouse', 'Son', 'Daughter', 'Father', 'Mother', 'Brother', 'Sister', 'Other'].map(opt =>
                `<option value="${opt}" ${(prefill.relationship === opt) ? 'selected' : ''}>${opt}</option>`
            ).join('')}
                    </select>
                </div>

                <div class="form-group">
                    <label>Father's Name</label>
                    <input type="text" class="fm-father-name" placeholder="Father's name (optional)" value="${escapeHtml(preFather)}" maxlength="255">
                    <div class="help">Auto-filled from head name for Son/Daughter.</div>
                </div>

                <div class="form-group">
                    <label>Date of Birth</label>
                    <input type="date" class="fm-dob" value="${prefill.dob ? escapeHtml(prefill.dob) : ''}">
                </div>
                <div class="form-group">
                    <label>Gender</label>
                    <select class="fm-gender">
                        <option value="">Select Gender</option>
                        <option value="Male" ${(prefill.gender === 'Male') ? 'selected' : ''}>Male</option>
                        <option value="Female" ${(prefill.gender === 'Female') ? 'selected' : ''}>Female</option>
                        <option value="Other" ${(prefill.gender === 'Other') ? 'selected' : ''}>Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Member Status</label>
                    <select class="fm-status">
                        <option value="">Select Status</option>
                        <option value="active" ${(prefill.status || 'active') === 'active' ? 'selected' : ''}>🟢 Active (Alive)</option>
                        <option value="death" ${(prefill.status || '') === 'death' ? 'selected' : ''}>⚫ Deceased</option>
                        <option value="freeze" ${(prefill.status || '') === 'freeze' ? 'selected' : ''}>🟡 Frozen (Dues continue)</option>
                        <option value="terminate" ${(prefill.status || '') === 'terminate' ? 'selected' : ''}>🔴 Terminated</option>
                    </select>
                    <div class="help">Status for this family member</div>
                </div>

                <div class="form-group">
                    <label>Phone</label>
                    <input type="tel" class="fm-phone" placeholder="Phone number (optional)" value="${escapeHtml(prefill.phone || '')}">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" class="fm-email" placeholder="Email address (optional)" value="${escapeHtml(prefill.email || '')}">
                </div>

                <div class="form-group">
                    <label>Create as Individual Member?</label>
                    <select class="fm-also-member" ${alreadyId ? 'disabled' : ''}>
                        <option value="no" ${alreadyId ? '' : 'selected'}>No</option>
                        <option value="yes" ${alreadyId ? 'selected' : ''}>Yes</option>
                    </select>
                    <div class="help">${alreadyId ? 'Already an individual member — creation disabled.' : 'If Yes, this person will also be added as a separate Mahal member.'}</div>
                    ${alreadyId ? `<div class="badge success">Already an individual member ${alreadyNum ? '(#' + alreadyNum + ')' : ''} — <a href="user_details.php?id=${alreadyId}" target="_blank" rel="noopener">View</a></div>` : ''}
                </div>

                <div class="form-group also-member-fields" style="display:none;">
                    <label>Member No. (Mahal) — Optional</label>
                    <input type="number" class="fm-member-number" min="1" step="1" placeholder="Leave blank to auto-assign">
                </div>

                <!-- Hidden by default: total_due and monthly_fee (shown when also-member = yes) -->
                <div class="form-group also-member-fields" style="display:none;">
                    <label>Total Due Amount (₹)</label>
                    <input type="number" class="fm-total-due" placeholder="0.00" step="0.01" min="0" value="${prefill.total_due || '0'}">
                </div>
                <div class="form-group also-member-fields" style="display:none;">
                    <label>Monthly Fee (for this member)</label>
                    <input type="number" class="fm-monthly-fee" placeholder="0.00" step="0.01" min="0" value="${escapeHtml(prefill.monthly_fee || DEFAULT_MEMBER_MONTHLY_FEE)}">
                </div>
            </div>

            <div class="doc-section-header" style="margin-top:10px;">
                <div class="title">Identity Documents (Optional)</div>
                <button type="button" class="btn btn-success-form fm-add-doc-btn">+ Add Document</button>
            </div>
            <div class="fm-docs-container"></div>

            <div class="muted">Removing from this list won't delete their individual member record (if any).</div>
            <button type="button" class="btn btn-danger-form remove-fm-btn" style="margin-top:14px;">Remove Member</button>
        `;

            strengthenFamilyInputs(memberCard);

            // Setup family member validation (only for name and relationship)
            setupFamilyMemberValidation(memberCard);

            memberCard.querySelector('.remove-fm-btn').addEventListener('click', function () {
                memberCard.remove();
                updateMemberNumbers();
            });

            // doc button
            const fmDocsContainer = memberCard.querySelector('.fm-docs-container');
            const addDocBtn = memberCard.querySelector('.fm-add-doc-btn');
            addDocBtn.addEventListener('click', () => {
                const card = createDocumentCard("Document");
                card.querySelector('.doc-remove-btn').addEventListener('click', () => {
                    card.remove();
                    renumberDocCards(fmDocsContainer, 'Document');
                });
                fmDocsContainer.appendChild(card);
                renumberDocCards(fmDocsContainer, 'Document');
            });

            // attach also-as-member toggle behavior
            const sel = memberCard.querySelector('.fm-also-member');
            function toggleAlsoMemberFields() {
                const show = sel && sel.value === 'yes' && !sel.disabled;
                memberCard.querySelectorAll('.also-member-fields').forEach(el => {
                    el.style.display = show ? '' : 'none';
                });

                if (!show) {
                    const num = memberCard.querySelector('.fm-member-number');
                    if (num) num.value = '';
                    const totalDue = memberCard.querySelector('.fm-total-due');
                    if (totalDue) totalDue.value = '0';
                    const monthlyFee = memberCard.querySelector('.fm-monthly-fee');
                    if (monthlyFee) monthlyFee.value = DEFAULT_MEMBER_MONTHLY_FEE;
                }
            }
            if (sel) {
                sel.addEventListener('change', toggleAlsoMemberFields);
                toggleAlsoMemberFields();
            }

            // relationship -> auto-fill father's name if Son/Daughter
            const relSelect = memberCard.querySelector('.fm-relationship');
            const fatherInput = memberCard.querySelector('.fm-father-name');
            function onRelationChange() {
                const rel = relSelect.value;
                if (rel === 'Son' || rel === 'Daughter') {
                    // auto-fill and set readonly
                    fatherInput.value = HEAD_NAME || fatherInput.value || '';
                    fatherInput.setAttribute('readonly', 'readonly');
                } else {
                    // restore editable
                    fatherInput.removeAttribute('readonly');
                    // if there was a prefill and relationship isn't son/daughter we keep it as-is
                }
            }
            relSelect.addEventListener('change', onRelationChange);
            // initialize based on current selection / prefill
            onRelationChange();

            document.getElementById('familyMembersContainer').appendChild(memberCard);
            updateMemberNumbers();

            // preload existing family docs if editing existing row
            if (existingId && existingFamilyDocsById && Array.isArray(existingFamilyDocsById[existingId])) {
                existingFamilyDocsById[existingId].forEach(d => {
                    const card = createDocumentCard("Document", d);
                    card.querySelector('.doc-remove-btn').addEventListener('click', () => {
                        card.remove();
                        renumberDocCards(fmDocsContainer, 'Document');
                    });
                    fmDocsContainer.appendChild(card);
                });
                renumberDocCards(fmDocsContainer, 'Document');
            }
        }

        function updateMemberNumbers() {
            const cards = document.querySelectorAll('.family-card');
            cards.forEach((card, index) => {
                const h3 = card.querySelector('h3');
                if (h3) h3.textContent = `Family Member ${index + 1}`;
            });
            familyMemberCount = document.querySelectorAll('.family-card').length;
        }

        /* ---------- preload UI from existing ---------- */
        document.addEventListener('DOMContentLoaded', function () {
            // preload head docs
            if (Array.isArray(existingHeadDocs)) {
                existingHeadDocs.forEach(d => {
                    headDocCount++;
                    const card = createDocumentCard("Document " + headDocCount, d);
                    card.querySelector('.doc-remove-btn').addEventListener('click', () => {
                        card.remove();
                        renumberDocCards(document.getElementById('headDocumentsContainer'), 'Document');
                    });
                    document.getElementById('headDocumentsContainer').appendChild(card);
                });
                renumberDocCards(document.getElementById('headDocumentsContainer'), 'Document');
            }

            // preload family members + their docs (and linked-individual badges)
            if (Array.isArray(existingFamily)) {
                existingFamily.forEach(fm => addFamilyCard({
                    name: fm.name || '',
                    relationship: fm.relationship || '',
                    dob: fm.dob || '',
                    gender: fm.gender || '',
                    phone: fm.phone || '',
                    email: fm.email || '',
                    father_name: fm.father_name || '',
                    status: fm.status || '',
                    _linked_member_id: fm._linked_member_id || null,
                    _linked_member_number: fm._linked_member_number || null
                }, fm.id || null));
            }

            // Setup all validations
            setupAllValidations();
        });

        /* ---------- reset ---------- */
        document.getElementById('clearBtn').addEventListener('click', function () {
            if (!confirm('Reset changes to original values?')) return;
            window.location.reload();
        });

        /* ---------- submit ---------- */
        document.getElementById('memberForm').addEventListener('submit', function (e) {
            e.preventDefault();

            if (!validateForm()) return;

            // collect head docs
            const headDocs = [];
            document.querySelectorAll('#headDocumentsContainer .doc-card').forEach(card => {
                const type = card.querySelector('.doc-type')?.value || '';
                const number = card.querySelector('.doc-number')?.value.trim() || '';
                const nameOn = card.querySelector('.doc-name')?.value.trim() || '';
                const issuedBy = card.querySelector('.doc-issued-by')?.value.trim() || '';
                const issuedOn = card.querySelector('.doc-issued-on')?.value || '';
                const expiryOn = card.querySelector('.doc-expiry-on')?.value || '';
                const notes = card.querySelector('.doc-notes')?.value.trim() || '';

                if (type || number) {
                    headDocs.push({
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

            // collect family + docs (+flags + per-child dues/fees)
            const familyMembersData = [];
            document.querySelectorAll('.family-card').forEach(card => {
                const nameEl = card.querySelector('.fm-name');
                const relEl = card.querySelector('.fm-relationship');
                const dobEl = card.querySelector('.fm-dob');
                const genderEl = card.querySelector('.fm-gender');
                const phoneEl = card.querySelector('.fm-phone');
                const emailEl = card.querySelector('.fm-email');
                const alsoSel = card.querySelector('.fm-also-member');
                const fmNoEl = card.querySelector('.fm-member-number');

                const fatherEl = card.querySelector('.fm-father-name');

                const totalDueEl = card.querySelector('.fm-total-due');
                const monthlyFeeEl = card.querySelector('.fm-monthly-fee');

                const docs = [];
                card.querySelectorAll('.fm-docs-container .doc-card').forEach(dCard => {
                    const type = dCard.querySelector('.doc-type')?.value || '';
                    const number = dCard.querySelector('.doc-number')?.value.trim() || '';
                    const nameOn = dCard.querySelector('.doc-name')?.value.trim() || '';
                    const issuedBy = dCard.querySelector('.doc-issued-by')?.value.trim() || '';
                    const issuedOn = dCard.querySelector('.doc-issued-on')?.value || '';
                    const expiryOn = dCard.querySelector('.doc-expiry-on')?.value || '';
                    const notes = dCard.querySelector('.doc-notes')?.value.trim() || '';
                    if (type || number) {
                        docs.push({ doc_type: type, doc_number: number, name_on_doc: nameOn, issued_by: issuedBy, issued_on: issuedOn, expiry_on: expiryOn, notes: notes });
                    }
                });

                familyMembersData.push({
                    name: nameEl ? nameEl.value.trim() : '',
                    relationship: relEl ? relEl.value : '',
                    father_name: fatherEl ? fatherEl.value.trim() : '',
                    dob: dobEl ? dobEl.value : '',
                    gender: genderEl ? genderEl.value : '',
                    phone: phoneEl ? phoneEl.value.trim() : '',
                    email: emailEl ? emailEl.value.trim() : '',
                    also_as_member: (alsoSel && !alsoSel.disabled && alsoSel.value === 'yes') ? true : false,
                    member_number: fmNoEl && !fmNoEl.disabled && fmNoEl.value ? fmNoEl.value.trim() : '',
                    status: card.querySelector('.fm-status') ? card.querySelector('.fm-status').value : '',
                    total_due: (totalDueEl ? (totalDueEl.value !== '' ? parseFloat(totalDueEl.value) : 0) : 0),
                    monthly_fee: (monthlyFeeEl ? (monthlyFeeEl.value !== '' ? parseFloat(monthlyFeeEl.value) : parseFloat(DEFAULT_MEMBER_MONTHLY_FEE)) : parseFloat(DEFAULT_MEMBER_MONTHLY_FEE)),
                    documents: docs
                });
            });

            const form = this;
            const formData = new FormData(form);
            formData.append('family_members', JSON.stringify(familyMembersData));
            formData.append('head_documents', JSON.stringify(headDocs));

            document.getElementById('loadingBox').classList.add('active');
            hideAlert();

            fetch('edit_member.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
                .then(response => {
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        return response.text().then(text => {
                            throw new Error('Server returned non-JSON: ' + text.substring(0, 200));
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    document.getElementById('loadingBox').classList.remove('active');
                    if (data.success) {
                        showAlert('success', data.message);
                        setTimeout(() => {
                            window.location.href = 'user_details.php?id=' + (form.member_id.value || '<?php echo (int) $member['id']; ?>');
                        }, 800);
                    } else {
                        showAlert('error', 'Error: ' + data.message);
                    }
                })
                .catch(err => {
                    document.getElementById('loadingBox').classList.remove('active');
                    showAlert('error', 'An error occurred: ' + err.message);
                    console.error(err);
                });
        });

        /* ---------- Validate form for submission ---------- */
        function validateForm() {
            let isValid = true;

            // Validate required fields for HEAD SECTION (all except email)
            const requiredValidations = [
                { id: 'head_name', fn: validateName, errorId: 'head_name_error', msg: 'Head of family name is required (only letters and spaces allowed)!', required: true },
                { id: 'head_father_name', fn: validateFatherName, errorId: 'head_father_name_error', msg: 'Father name is required (only letters and spaces allowed)!', required: true },
                { id: 'member_number', fn: validateMemberNumber, errorId: 'member_number_error', msg: 'Member number is required and must be a positive whole number (1, 2, 3, ...).', required: true },
                { id: 'head_phone', fn: validatePhone, errorId: 'head_phone_error', msg: 'Phone number is required. Invalid Indian mobile number. Expected 10 digits starting 6-9 (optional +91).', required: true },
                { id: 'head_dob', fn: validateDateNotFuture, errorId: 'head_dob_error', msg: 'Date of birth is required and cannot be in future.', required: true },
                { id: 'head_address', fn: validateAddress, errorId: 'head_address_error', msg: 'Address is required (min 10 characters).', required: true },
                { id: 'join_date', fn: validateJoinDate, errorId: 'join_date_error', msg: 'Join date is required and cannot be in future.', required: true },
                { id: 'monthly_fee', fn: validateMonthlyFee, errorId: 'monthly_fee_error', msg: 'Monthly fee is required and must be a number ≥ 0.', required: true },
                { id: 'total_due', fn: validatePositiveNumber, errorId: 'total_due_error', msg: 'Total due is required and must be a number ≥ 0.', required: true }
            ];

            for (const validation of requiredValidations) {
                if (!validateFormField(validation.id, validation.fn, validation.errorId, validation.msg, validation.required)) {
                    isValid = false;
                }
            }

            // Validate optional fields for HEAD SECTION (only email)
            const optionalValidations = [
                { id: 'head_email', fn: validateEmail, errorId: 'head_email_error', msg: 'Please enter a valid email address (must contain @ and .com/.in/.org etc.)!', required: false }
            ];

            for (const validation of optionalValidations) {
                if (!validateFormField(validation.id, validation.fn, validation.errorId, validation.msg, validation.required)) {
                    isValid = false;
                }
            }

            // Validate required dropdowns for HEAD SECTION
            const genderSelect = document.getElementById('head_gender');
            if (genderSelect) {
                if (genderSelect.value === '') {
                    genderSelect.classList.add('invalid');
                    const errorElement = document.getElementById('head_gender_error');
                    if (errorElement) {
                        errorElement.style.display = 'block';
                        errorElement.textContent = 'Gender is required. Please select a gender.';
                    }
                    genderSelect.focus();
                    isValid = false;
                }
            }

            const occupationSelect = document.getElementById('head_occupation');
            if (occupationSelect) {
                if (occupationSelect.value === '') {
                    occupationSelect.classList.add('invalid');
                    const errorElement = document.getElementById('head_occupation_error');
                    if (errorElement) {
                        errorElement.style.display = 'block';
                        errorElement.textContent = 'Occupation is required. Please select an occupation.';
                    }
                    occupationSelect.focus();
                    isValid = false;
                }
            }

            const donationSelect = document.getElementById('monthly_donation_due');
            if (donationSelect) {
                if (donationSelect.value === '') {
                    donationSelect.classList.add('invalid');
                    const errorElement = document.getElementById('monthly_donation_due_error');
                    if (errorElement) {
                        errorElement.style.display = 'block';
                        errorElement.textContent = 'Monthly donation status is required. Please select donation status.';
                    }
                    donationSelect.focus();
                    isValid = false;
                }
            }

            const statusSelect = document.getElementById('member_status');
            if (statusSelect) {
                if (statusSelect.value === '') {
                    statusSelect.classList.add('invalid');
                    const errorElement = document.getElementById('member_status_error');
                    if (errorElement) {
                        errorElement.style.display = 'block';
                        errorElement.textContent = 'Member status is required. Please select member status.';
                    }
                    statusSelect.focus();
                    isValid = false;
                }
            }

            // Validate family members - ONLY NAME AND RELATIONSHIP ARE REQUIRED
            const cards = document.querySelectorAll('.family-card');
            cards.forEach(card => strengthenFamilyInputs(card));
            for (let i = 0; i < cards.length; i++) {
                const idx = i + 1;
                const nameEl = cards[i].querySelector('.fm-name');
                const relationEl = cards[i].querySelector('.fm-relationship');

                // REQUIRED: Name
                const nm = nameEl?.value.trim() || '';
                if (!nm) {
                    showAlert('error', `Name is required for Family Member ${idx}.`);
                    nameEl?.focus();
                    return false;
                }

                // Name format validation
                if (!/^[A-Za-z\s\.\-']+$/.test(nm) || nm.length < 2) {
                    showAlert('error', `Family Member ${idx}: Name must contain only letters and spaces and be at least 2 characters.`);
                    nameEl?.focus();
                    return false;
                }

                // REQUIRED: Relationship
                const relationVal = relationEl?.value || '';
                if (!relationVal) {
                    showAlert('error', `Relationship is required for Family Member ${idx}.`);
                    relationEl?.focus();
                    return false;
                }

                // OPTIONAL: All other fields - NO VALIDATION NEEDED (only validate if also_as_member)

                // If also_as_member is selected, validate relevant fields
                const alsoAsMemberSelect = cards[i].querySelector('.fm-also-member');
                const alsoAsMember = alsoAsMemberSelect && !alsoAsMemberSelect.disabled && alsoAsMemberSelect.value === 'yes';

                if (alsoAsMember) {
                    // For also_as_member, we need to validate member_number if provided
                    const fmNo = cards[i].querySelector('.fm-member-number')?.value.trim() || '';
                    if (fmNo && !/^[1-9]\d*$/.test(fmNo)) {
                        showAlert('error', `Family Member ${idx}: Member No. must be a positive whole number.`);
                        cards[i].querySelector('.fm-member-number')?.focus();
                        return false;
                    }

                    // Phone is REQUIRED when also_as_member is yes
                    const phoneVal = cards[i].querySelector('.fm-phone')?.value.trim() || '';
                    if (!phoneVal) {
                        showAlert('error', `Phone number is required for Family Member ${idx} when creating as individual member.`);
                        cards[i].querySelector('.fm-phone')?.focus();
                        return false;
                    } else if (!isValidIndianPhone(phoneVal)) {
                        showAlert('error', `Family Member ${idx}: Valid phone number required when creating as individual member. Use 10 digits starting 6-9 (optional +91).`);
                        cards[i].querySelector('.fm-phone')?.focus();
                        return false;
                    }

                    // Email validation if provided
                    const emailVal = cards[i].querySelector('.fm-email')?.value.trim() || '';
                    if (emailVal && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
                        showAlert('error', `Family Member ${idx}: Email looks invalid.`);
                        cards[i].querySelector('.fm-email')?.focus();
                        return false;
                    }

                    // validate per-child dues and fees if also_as_member is YES
                    const fmTotalDue = cards[i].querySelector('.fm-total-due')?.value || '0';
                    const fmMonthlyFee = cards[i].querySelector('.fm-monthly-fee')?.value || '0';
                    if (isNaN(parseFloat(fmTotalDue)) || parseFloat(fmTotalDue) < 0) {
                        showAlert('error', `Family Member ${idx}: Total Due must be a number ≥ 0.`);
                        cards[i].querySelector('.fm-total-due')?.focus();
                        return false;
                    }
                    if (isNaN(parseFloat(fmMonthlyFee)) || parseFloat(fmMonthlyFee) < 0) {
                        showAlert('error', `Family Member ${idx}: Monthly Fee must be a number ≥ 0.`);
                        cards[i].querySelector('.fm-monthly-fee')?.focus();
                        return false;
                    }
                } else {
                    // If not also_as_member, optional fields don't need validation
                    // Only validate if they contain data (optional)
                    const phoneVal = cards[i].querySelector('.fm-phone')?.value.trim() || '';
                    if (phoneVal && !isValidIndianPhone(phoneVal)) {
                        showAlert('error', `Family Member ${idx}: Phone number is invalid. Use 10 digits starting 6-9 (optional +91).`);
                        cards[i].querySelector('.fm-phone')?.focus();
                        return false;
                    }

                    const emailVal = cards[i].querySelector('.fm-email')?.value.trim() || '';
                    if (emailVal && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
                        showAlert('error', `Family Member ${idx}: Email looks invalid.`);
                        cards[i].querySelector('.fm-email')?.focus();
                        return false;
                    }

                    // Date of birth validation (if provided)
                    const dobVal = cards[i].querySelector('.fm-dob')?.value || '';
                    if (dobVal) {
                        const d = parseLocalDate(dobVal);
                        if (d && d.getTime() > todayLocalMidnight().getTime()) {
                            showAlert('error', `Family Member ${idx} DOB cannot be in the future.`);
                            cards[i].querySelector('.fm-dob').focus();
                            return false;
                        }
                    }

                    // Father's name length check (if provided)
                    const fatherVal = cards[i].querySelector('.fm-father-name')?.value || '';
                    if (fatherVal && fatherVal.length > 255) {
                        showAlert('error', `Family Member ${idx}: Father's name is too long (max 255 chars).`);
                        cards[i].querySelector('.fm-father-name')?.focus();
                        return false;
                    }
                }
            }

            // documents validation + duplicates (still required for documents if user chooses to add them)
            const seen = new Set();
            const allDocCards = Array.from(document.querySelectorAll('#headDocumentsContainer .doc-card'))
                .concat(Array.from(document.querySelectorAll('.fm-docs-container .doc-card')));

            for (let i = 0; i < allDocCards.length; i++) {
                const c = allDocCards[i];
                const type = c.querySelector('.doc-type')?.value || '';
                const number = c.querySelector('.doc-number')?.value.trim() || '';
                const issuedOn = c.querySelector('.doc-issued-on')?.value || '';
                const expiryOn = c.querySelector('.doc-expiry-on')?.value || '';

                if ((type && !number) || (!type && number)) {
                    showAlert('error', 'Each document row requires BOTH Type and Number.');
                    (c.querySelector('.doc-number') || c.querySelector('.doc-type')).focus();
                    return false;
                }
                if (!type && !number) continue;

                if (!validateDocByType(type, number)) {
                    showAlert('error', `Document "${type}" has an invalid number format.`);
                    c.querySelector('.doc-number')?.focus();
                    return false;
                }

                const t0 = new Date(); t0.setHours(0, 0, 0, 0);
                if (issuedOn) {
                    const io = new Date(issuedOn);
                    if (io > t0) { showAlert('error', `Issued On for ${type} cannot be in the future.`); c.querySelector('.doc-issued-on')?.focus(); return false; }
                }
                if (expiryOn && issuedOn) {
                    const ex = new Date(expiryOn), io = new Date(issuedOn);
                    if (ex < io) { showAlert('error', `Expiry On for ${type} cannot be before Issued On.`); c.querySelector('.doc-expiry-on')?.focus(); return false; }
                }

                const key = (type.toLowerCase() + '|' + normalizeDocNumber(number));
                if (seen.has(key)) {
                    showAlert('error', `Duplicate ${type} number detected in documents.`);
                    c.querySelector('.doc-number')?.focus(); return false;
                }
                seen.add(key);
            }

            return isValid;
        }

        function showAlert(type, message) {
            const alertBox = document.getElementById('alertBox');
            alertBox.className = 'alert alert-' + (type === 'success' ? 'success-form' : 'error-form') + ' show';
            alertBox.textContent = message;
            alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function hideAlert() {
            const alertBox = document.getElementById('alertBox');
            alertBox.classList.remove('show');
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
            btn.addEventListener('click', function () {
                if (!this.hasAttribute('onclick')) {
                    document.querySelectorAll('.menu-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                }
            });
        });
    </script>
</body>

</html>