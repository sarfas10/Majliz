<?php
// addmember.php
// Full single-file version combining robust JSON-safe POST handling with your original HTML form/JS.
// Includes inline Father Name for family members (auto-fill for Son/Daughter) and Head's Father Name field.
// Ensures members.father_name and family_members.father_name exist (ALTER if missing).

require_once __DIR__ . '/session_bootstrap.php';

// Start output buffering immediately so we can remove accidental output and guarantee JSON-only for POST
ob_start();

// Toggle for debugging - set to true locally while debugging, false in production
define('DEV_MODE', false);
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Convert PHP warnings/notices into exceptions (respects @ operator)
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Global exception handler -> returns JSON for POST requests, or displays minimal message for GET
set_exception_handler(function ($e) {
    // Clear any buffered output
    while (ob_get_level()) ob_end_clean();

    // If request is POST and expects JSON, respond JSON. Otherwise, show minimal HTML (to not break browser).
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if ($isPost) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        $payload = [
            'success' => false,
            'message' => $e->getMessage()
        ];
        if (defined('DEV_MODE') && DEV_MODE) {
            $payload['trace'] = $e->getTraceAsString();
        }
        echo json_encode($payload);
        exit;
    } else {
        // For GET, display a simple message (no debug info)
        http_response_code(500);
        echo "<h1>Server error</h1><p>An internal error occurred. Try again later.</p>";
        exit;
    }
});

// Include DB connection (must not echo)
require_once 'db_connection.php';

/* --- no-cache headers --- */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

/* --- auth gate --- */
if (!isset($_SESSION['user_id'])) {
    // For GET redirect as usual; for POST return JSON error
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

/* ---------------- Helper functions (same as your original) ---------------- */

function normalize_doc_number($s) {
    return strtoupper(preg_replace('/\s+/', '', (string)$s));
}
function validate_doc_by_type($type, $number) {
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
function is_valid_indian_phone($s) {
    $v = preg_replace('/[\s-]+/','', (string)$s);
    return (bool)preg_match('/^(\+91)?[6-9]\d{9}$/', $v);
}
function not_future_date($str) {
    if (!$str) return true;
    $d = strtotime($str);
    if ($d === false) return false;
    return $d <= strtotime('today');
}

/**
 * Ensure tables/columns/indexes exist (including member_number column + unique index + bulk_print_enabled).
 * Also ensures family_members and members have 'father_name' column (for inline father name).
 */
function createTablesIfNotExist($conn) {
    try {
        // Check if members table exists
        $check_members = $conn->query("SHOW TABLES LIKE 'members'");
        if ($check_members->num_rows == 0) {
            // Create members table WITH member_number and bulk_print_enabled and father_name
            $members_table = "CREATE TABLE members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                head_name VARCHAR(255) NOT NULL,
                father_name VARCHAR(255) DEFAULT NULL,
                email VARCHAR(255) NOT NULL,
                phone VARCHAR(20) NOT NULL,
                dob DATE DEFAULT NULL,
                gender VARCHAR(20) DEFAULT NULL,
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
                status ENUM('active','death','freeze','unfreeze','terminate') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_mahal_id (mahal_id),
                INDEX idx_email (email),
                UNIQUE KEY uniq_mahal_member_number (mahal_id, member_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            if (!$conn->query($members_table)) {
                throw new Exception("Error creating members table: " . $conn->error);
            }
            // FKs created separately (register table assumed)
            $conn->query("ALTER TABLE members ADD CONSTRAINT fk_members_mahal 
                          FOREIGN KEY (mahal_id) REFERENCES register(id) 
                          ON DELETE CASCADE ON UPDATE CASCADE");

        } else {
            // Existing members table: add missing columns
            $check_member_mfee = $conn->query("SHOW COLUMNS FROM members LIKE 'monthly_fee'");
            if ($check_member_mfee->num_rows == 0) {
                $conn->query("ALTER TABLE members ADD COLUMN monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_due");
            }

            // NEW: ensure father_name column exists on members
            $check_father_name_members = $conn->query("SHOW COLUMNS FROM members LIKE 'father_name'");
            if ($check_father_name_members->num_rows == 0) {
                $conn->query("ALTER TABLE members ADD COLUMN father_name VARCHAR(255) DEFAULT NULL AFTER head_name");
            }

            // NEW: ensure member_number column
            $check_member_number = $conn->query("SHOW COLUMNS FROM members LIKE 'member_number'");
            if ($check_member_number->num_rows == 0) {
                $conn->query("ALTER TABLE members ADD COLUMN member_number INT UNSIGNED DEFAULT NULL AFTER mahal_id");
            }

            // NEW: ensure unique index on (mahal_id, member_number)
            $has_unique = false;
            $idxRes = $conn->query("SHOW INDEX FROM members WHERE Key_name = 'uniq_mahal_member_number'");
            if ($idxRes && $idxRes->num_rows > 0) {
                $has_unique = true;
            }
            if (!$has_unique) {
                $conn->query("ALTER TABLE members ADD UNIQUE KEY uniq_mahal_member_number (mahal_id, member_number)");
            }

            // Legacy columns
            $check_columns = $conn->query("SHOW COLUMNS FROM members LIKE 'monthly_donation_due'");
            if ($check_columns->num_rows == 0) {
                $conn->query("ALTER TABLE members ADD COLUMN monthly_donation_due VARCHAR(20) DEFAULT 'pending' AFTER total_family_members");
            }
            $check_total_due = $conn->query("SHOW COLUMNS FROM members LIKE 'total_due'");
            if ($check_total_due->num_rows == 0) {
                $conn->query("ALTER TABLE members ADD COLUMN total_due DECIMAL(10,2) DEFAULT 0.00 AFTER monthly_donation_due");
            }

            // Add status column to members table if it doesn't exist
            $check_status = $conn->query("SHOW COLUMNS FROM members LIKE 'status'");
            if ($check_status->num_rows == 0) {
                $conn->query("ALTER TABLE members ADD COLUMN status ENUM('active','death','freeze','unfreeze','terminate') DEFAULT 'active' AFTER monthly_fee");
            }

            // Ensure bulk_print_enabled column exists (default false)
            $check_bulk = $conn->query("SHOW COLUMNS FROM members LIKE 'bulk_print_enabled'");
            if ($check_bulk->num_rows == 0) {
                $conn->query("ALTER TABLE members ADD COLUMN bulk_print_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER monthly_fee");
            }
        }

        // family_members table (updated to include father_name inline field)
        $check_family = $conn->query("SHOW TABLES LIKE 'family_members'");
        if ($check_family->num_rows == 0) {
            $family_table = "CREATE TABLE family_members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                relationship VARCHAR(50) NOT NULL,
                dob DATE DEFAULT NULL,
                gender VARCHAR(20) DEFAULT NULL,
                phone VARCHAR(20) DEFAULT NULL,
                email VARCHAR(255) DEFAULT NULL,
                father_name VARCHAR(255) DEFAULT NULL,
                status ENUM('active','death','freeze','unfreeze','terminate') DEFAULT 'active',
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
            // Add status column if it doesn't exist
            $check_status = $conn->query("SHOW COLUMNS FROM family_members LIKE 'status'");
            if ($check_status->num_rows == 0) {
                $conn->query("ALTER TABLE family_members ADD COLUMN status ENUM('active','death','freeze','unfreeze','terminate') DEFAULT 'active' AFTER email");
            }

            // Ensure father_name column exists
            $check_father_name = $conn->query("SHOW COLUMNS FROM family_members LIKE 'father_name'");
            if ($check_father_name->num_rows == 0) {
                $conn->query("ALTER TABLE family_members ADD COLUMN father_name VARCHAR(255) DEFAULT NULL AFTER email");
            }
        }

        // member_documents table
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

/**
 * Compute the next member_number for this mahal (MAX + 1). If none, return 1.
 */
function getNextMemberNumberForMahal($conn, $mahal_id) {
    $next = 1;
    try {
        $stmt = $conn->prepare("SELECT MAX(member_number) AS mx FROM members WHERE mahal_id = ?");
        $stmt->bind_param("i", $mahal_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($res && isset($res['mx']) && $res['mx'] !== null) {
            $mx = (int)$res['mx'];
            $next = $mx + 1;
        }
    } catch (Throwable $e) {
        // ignore, fallback to 1
    }
    return $next;
}

/* ---------------- POST processing (save_member) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_member') {
    // We will return JSON
    header('Content-Type: application/json; charset=utf-8');

    $response = ['success' => false, 'message' => 'Unknown error'];
    $conn = null;

    try {
        // DB connection
        $db_result = get_db_connection();
        if (isset($db_result['error'])) {
            throw new Exception("Database connection failed: " . $db_result['error']);
        }
        $conn = $db_result['conn'];

        // Ensure structure
        createTablesIfNotExist($conn);

        // Read + sanitize
        $head_name     = isset($_POST['head_name']) ? trim($_POST['head_name']) : '';
        $head_father_name = isset($_POST['head_father_name']) ? trim($_POST['head_father_name']) : null;
        $head_email    = isset($_POST['head_email']) ? trim($_POST['head_email']) : '';
        $head_phone    = isset($_POST['head_phone']) ? trim($_POST['head_phone']) : '';
        $head_address  = isset($_POST['head_address']) ? trim($_POST['head_address']) : '';
        $mahal_id      = (int) $_SESSION['user_id'];

        $head_dob      = isset($_POST['head_dob']) && $_POST['head_dob'] !== '' ? trim($_POST['head_dob']) : null;
        $head_gender   = isset($_POST['head_gender']) && $_POST['head_gender'] !== '' ? trim($_POST['head_gender']) : null;
        $head_occupation = isset($_POST['head_occupation']) ? trim($_POST['head_occupation']) : null;
        $join_date     = isset($_POST['join_date']) && $_POST['join_date'] !== '' ? $_POST['join_date'] : date('Y-m-d');

        $monthly_donation_due = isset($_POST['monthly_donation_due']) ? trim($_POST['monthly_donation_due']) : 'cleared';
        $total_due            = isset($_POST['total_due']) && $_POST['total_due'] !== '' ? floatval($_POST['total_due']) : 0.00;

        $member_monthly_fee = isset($_POST['member_monthly_fee']) && $_POST['member_monthly_fee'] !== ''
            ? (float)$_POST['member_monthly_fee'] : 0.00;

        // NEW: bulk print enabled (checkbox) — default false
        $bulk_print_enabled = (isset($_POST['bulk_print_enabled']) && $_POST['bulk_print_enabled'] === '1') ? 1 : 0;

        // NEW: member_number (required, positive int)
        if (!isset($_POST['member_number']) || $_POST['member_number'] === '') {
            throw new Exception('Member number is required.');
        }
        if (!ctype_digit((string)$_POST['member_number'])) {
            throw new Exception('Member number must be a positive integer.');
        }
        $member_number = (int)$_POST['member_number'];
        if ($member_number < 1) {
            throw new Exception('Member number must be at least 1.');
        }

        // Member status
        $member_status = isset($_POST['member_status']) ? trim($_POST['member_status']) : 'active';

        // NEW: Check if manual due amount is enabled
        $manual_due_enabled = isset($_POST['manual_due_enabled']) && $_POST['manual_due_enabled'] === '1';
        $manual_due_amount = isset($_POST['manual_due_amount']) ? (float)$_POST['manual_due_amount'] : 0.00;

        // Decode JSON blocks
        $head_documents = [];
        if (isset($_POST['head_documents'])) {
            $decoded_hd = json_decode($_POST['head_documents'], true);
            if (is_array($decoded_hd)) {
                $head_documents = $decoded_hd;
            }
        }

        $family_members = [];
        if (isset($_POST['family_members'])) {
            $decoded = json_decode($_POST['family_members'], true);
            if (is_array($decoded)) {
                $family_members = $decoded;
            }
        }
        $total_members = count($family_members) + 1;

        // --------- Validations ----------
        if ($head_name === '') throw new Exception('Head of family name is required.');
        if (strlen($head_name) > 120) throw new Exception('Name is too long.');

        // Father's name is REQUIRED
        if (empty(trim($head_father_name))) {
            throw new Exception('Father\'s name is required.');
        }
        if (strlen($head_father_name) > 255) {
            throw new Exception('Head father name is too long (max 255 characters).');
        }

        // Date of Birth is REQUIRED
        if (empty($head_dob)) {
            throw new Exception('Date of Birth is required.');
        }
        if (!not_future_date($head_dob)) throw new Exception('Head DOB cannot be in the future.');

        // Email optional but if provided, must be valid
        if ($head_email !== '' && (!filter_var($head_email, FILTER_VALIDATE_EMAIL) || strlen($head_email) > 120)) {
            throw new Exception('Invalid email address.');
        }

        if ($head_phone === '' || !is_valid_indian_phone($head_phone)) throw new Exception('Invalid Indian mobile number.');
        if ($head_address === '' || strlen($head_address) < 10) throw new Exception('Address must be at least 10 characters.');
        if ($join_date && !not_future_date($join_date)) throw new Exception('Join date cannot be in the future.');
        if (!in_array($monthly_donation_due, ['cleared','due'], true)) throw new Exception('Monthly donation status must be cleared or due.');
        if (!is_numeric($total_due) || $total_due < 0) throw new Exception('Total due must be a number ≥ 0.');
        if (!is_numeric($member_monthly_fee) || $member_monthly_fee < 0) throw new Exception('Monthly fee (member) must be a number ≥ 0.');

        // Validate manual due amount if enabled
        if ($manual_due_enabled && (!is_numeric($manual_due_amount) || $manual_due_amount < 0)) {
            throw new Exception('Manual due amount must be a number ≥ 0.');
        }

        // Uniqueness check for (mahal_id, member_number)
        $chk = $conn->prepare("SELECT COUNT(*) AS c FROM members WHERE mahal_id = ? AND member_number = ?");
        $chk->bind_param("ii", $mahal_id, $member_number);
        $chk->execute();
        $cc = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($cc && (int)$cc['c'] > 0) {
            throw new Exception('This member number already exists for your mahal. Please choose another.');
        }

        $seen_docs = [];

        // Head docs
        foreach ($head_documents as $d) {
            $doc_type   = isset($d['doc_type']) ? trim($d['doc_type']) : '';
            $doc_number = isset($d['doc_number']) ? trim($d['doc_number']) : '';
            if (($doc_type && !$doc_number) || (!$doc_type && $doc_number)) {
                throw new Exception('Each head document requires both Type and Number.');
            }
            if (!$doc_type && !$doc_number) continue;

            if (!validate_doc_by_type($doc_type, $doc_number)) {
                throw new Exception("Invalid $doc_type number format.");
            }

            $issued_on = isset($d['issued_on']) ? $d['issued_on'] : null;
            $expiry_on = isset($d['expiry_on']) ? $d['expiry_on'] : null;
            if ($issued_on && !not_future_date($issued_on)) throw new Exception("$doc_type Issued On cannot be in the future.");
            if ($issued_on && $expiry_on && strtotime($expiry_on) < strtotime($issued_on)) {
                throw new Exception("$doc_type Expiry On cannot be before Issued On.");
            }

            $key = strtolower($doc_type).'|'.normalize_doc_number($doc_number);
            if (isset($seen_docs[$key])) throw new Exception("Duplicate $doc_type number across documents.");
            $seen_docs[$key] = true;
        }

        foreach ($family_members as $idx => $fm) {
            $nm = isset($fm['name']) ? trim($fm['name']) : '';
            if ($nm === '') throw new Exception('Family member name cannot be empty.');

            $fphone = isset($fm['phone']) ? trim($fm['phone']) : '';
            if ($fphone !== '' && !is_valid_indian_phone($fphone)) {
                throw new Exception("Family member phone is invalid.");
            }
            $femail = isset($fm['email']) ? trim($fm['email']) : '';
            if ($femail !== '' && !filter_var($femail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Family member email is invalid.");
            }

            $fdob = isset($fm['dob']) && $fm['dob'] !== '' ? $fm['dob'] : null;
            if ($fdob && !not_future_date($fdob)) throw new Exception("Family member DOB cannot be in the future.");

            if (isset($fm['documents']) && is_array($fm['documents'])) {
                foreach ($fm['documents'] as $d) {
                    $doc_type   = isset($d['doc_type']) ? trim($d['doc_type']) : '';
                    $doc_number = isset($d['doc_number']) ? trim($d['doc_number']) : '';
                    if (($doc_type && !$doc_number) || (!$doc_type && $doc_number)) {
                        throw new Exception('Each family document requires both Type and Number.');
                    }
                    if (!$doc_type && !$doc_number) continue;

                    if (!validate_doc_by_type($doc_type, $doc_number)) {
                        throw new Exception("Invalid $doc_type number format (family).");
                    }

                    $issued_on = isset($d['issued_on']) ? $d['issued_on'] : null;
                    $expiry_on = isset($d['expiry_on']) ? $d['expiry_on'] : null;
                    if ($issued_on && !not_future_date($issued_on)) throw new Exception("$doc_type Issued On cannot be in the future.");
                    if ($issued_on && $expiry_on && strtotime($expiry_on) < strtotime($issued_on)) {
                        throw new Exception("$doc_type Expiry On cannot be before Issued On.");
                    }

                    $key = strtolower($doc_type).'|'.normalize_doc_number($doc_number);
                    if (isset($seen_docs[$key])) throw new Exception("Duplicate $doc_type number across head/family documents.");
                    $seen_docs[$key] = true;
                }
            }

            // If also_as_member true, validate provided numeric fields if present
            $also = isset($fm['also_as_member']) && ($fm['also_as_member'] === true || $fm['also_as_member'] === '1' || $fm['also_as_member'] === 'yes');
            if ($also) {
                // member_number optional — if provided, must be positive int and unique
                if (isset($fm['member_number']) && $fm['member_number'] !== '') {
                    if (!ctype_digit((string)$fm['member_number']) || (int)$fm['member_number'] < 1) {
                        throw new Exception("Member number for '{$nm}' must be a positive integer.");
                    }
                    // uniqueness check
                    $child_member_number = (int)$fm['member_number'];
                    $chk = $conn->prepare("SELECT COUNT(*) AS c FROM members WHERE mahal_id = ? AND member_number = ?");
                    $chk->bind_param("ii", $mahal_id, $child_member_number);
                    $chk->execute();
                    $cc = $chk->get_result()->fetch_assoc();
                    $chk->close();
                    if ($cc && (int)$cc['c'] > 0) {
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

            // Optional server-side check for family father's name length
            if (isset($fm['father_name']) && $fm['father_name'] !== '') {
                if (strlen(trim($fm['father_name'])) > 255) {
                    throw new Exception("Father name for '{$nm}' is too long (max 255 chars).");
                }
            }
        }

        // Calculate total due based on manual or auto mode
        if ($monthly_donation_due === 'cleared') {
            $total_due = 0.00;
        } else {
            if ($manual_due_enabled) {
                // Use manually entered amount
                $total_due = $manual_due_amount;
            } else {
                // Calculate months using formula: (Current Year - Join Year) * 12 + (Current Month - Join Month) [NO +1]
                $join_date_obj = new DateTime($join_date);
                $current_date = new DateTime();
                
                // Ensure join date is not in the future
                if ($join_date_obj > $current_date) {
                    $total_due = 0.00;
                } else {
                    $join_year = (int)$join_date_obj->format('Y');
                    $join_month = (int)$join_date_obj->format('n');
                    $current_year = (int)$current_date->format('Y');
                    $current_month = (int)$current_date->format('n');
                    
                    // Formula: (Current Year - Join Year) * 12 + (Current Month - Join Month) [NO +1]
                    $years_diff = $current_year - $join_year;
                    $months_diff = $current_month - $join_month;
                    
                    $total_months = ($years_diff * 12) + $months_diff;
                    
                    // Ensure at least 0 month
                    $total_months = max(0, $total_months);
                    
                    $total_due = $total_months * $member_monthly_fee;
                    $total_due = max(0.00, $total_due);
                }
            }
        }

        // ---------- DB write ----------
        $conn->begin_transaction();

        // Insert member (head) including member_number + bulk_print_enabled + father_name
        $sql = "INSERT INTO members (head_name, father_name, email, phone, dob, gender, occupation, address, mahal_id, member_number, join_date, total_family_members, monthly_donation_due, total_due, monthly_fee, bulk_print_enabled, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }

        $stmt->bind_param(
    "ssssssssiisisddis",
    $head_name,
    $head_father_name,
    $head_email,
    $head_phone,
    $head_dob,
    $head_gender,
    $head_occupation,
    $head_address,
    $mahal_id,
    $member_number,
    $join_date,
    $total_members,
    $monthly_donation_due,
    $total_due,
    $member_monthly_fee,
    $bulk_print_enabled,
    $member_status
);

        if (!$stmt->execute()) {
            if ($conn->errno === 1062) {
                throw new Exception('This member number already exists for your mahal. Please choose another.');
            }
            throw new Exception("Failed to save member: " . $stmt->error);
        }

        $member_id = $conn->insert_id;
        $stmt->close();
        $stmt = null;

        // Insert family members (include father_name)
        $family_ids = [];
        if (!empty($family_members)) {
            $family_sql = "INSERT INTO family_members (member_id, name, relationship, dob, gender, phone, email, father_name, status, created_at, updated_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $family_stmt = $conn->prepare($family_sql);
            if (!$family_stmt) {
                throw new Exception("Prepare failed for family members: " . $conn->error);
            }

            foreach ($family_members as $fm) {
                $fm_name = isset($fm['name']) ? trim($fm['name']) : '';
                $fm_relation = isset($fm['relationship']) ? trim($fm['relationship']) : '';
                $fm_dob = isset($fm['dob']) && $fm['dob'] !== '' ? trim($fm['dob']) : null;
                $fm_gender = isset($fm['gender']) && $fm['gender'] !== '' ? trim($fm['gender']) : null;
                $fm_phone = isset($fm['phone']) ? trim($fm['phone']) : null;
                $fm_email = isset($fm['email']) ? trim($fm['email']) : null;
                $fm_status = isset($fm['status']) && $fm['status'] !== '' ? trim($fm['status']) : 'active';

                // NEW father_name (nullable)
                $fm_father_name = isset($fm['father_name']) && $fm['father_name'] !== '' ? trim($fm['father_name']) : null;

                if ($fm_name === '') {
                    throw new Exception("Family member name cannot be empty.");
                }

                $family_stmt->bind_param(
                    "issssssss",
                    $member_id,
                    $fm_name,
                    $fm_relation,
                    $fm_dob,
                    $fm_gender,
                    $fm_phone,
                    $fm_email,
                    $fm_father_name,
                    $fm_status
                );

                if (!$family_stmt->execute()) {
                    throw new Exception("Failed to save a family member: " . $family_stmt->error);
                }

                $family_ids[] = $conn->insert_id;
            }

            $family_stmt->close();
            $family_stmt = null;
        }

        // Insert documents
        $doc_sql = "INSERT INTO member_documents 
            (member_id, family_member_id, owner_type, doc_type, doc_number, name_on_doc, issued_by, issued_on, expiry_on, notes, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        $doc_stmt = $conn->prepare($doc_sql);
        if (!$doc_stmt) {
            throw new Exception("Prepare failed for documents: " . $conn->error);
        }

        $nn = function($v){ return ($v === '' ? null : $v); };

        // Head docs
        foreach ($head_documents as $d) {
            $owner_type = 'head';
            $doc_type   = isset($d['doc_type']) ? trim($d['doc_type']) : '';
            $doc_number = isset($d['doc_number']) ? trim($d['doc_number']) : '';
            if ($doc_type === '' || $doc_number === '') {
                throw new Exception("Head document requires both type and number.");
            }
            $name_on_doc = $nn(isset($d['name_on_doc']) ? trim($d['name_on_doc']) : null);
            $issued_by   = $nn(isset($d['issued_by']) ? trim($d['issued_by']) : null);
            $issued_on   = $nn(isset($d['issued_on']) ? trim($d['issued_on']) : null);
            $expiry_on   = $nn(isset($d['expiry_on']) ? trim($d['expiry_on']) : null);
            $notes       = $nn(isset($d['notes']) ? trim($d['notes']) : null);
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

            if (!$doc_stmt->execute()) {
                throw new Exception("Failed to save head document: " . $doc_stmt->error);
            }
        }

        // Family docs
        foreach ($family_members as $idx => $fm) {
            if (!isset($fm['documents']) || !is_array($fm['documents'])) continue;
            $this_family_id = isset($family_ids[$idx]) ? (int)$family_ids[$idx] : null;
            if (!$this_family_id) continue;

            foreach ($fm['documents'] as $d) {
                $owner_type = 'family';
                $doc_type   = isset($d['doc_type']) ? trim($d['doc_type']) : '';
                $doc_number = isset($d['doc_number']) ? trim($d['doc_number']) : '';
                if ($doc_type === '' || $doc_number === '') {
                    throw new Exception("Family document requires both type and number.");
                }
                $name_on_doc = $nn(isset($d['name_on_doc']) ? trim($d['name_on_doc']) : null);
                $issued_by   = $nn(isset($d['issued_by']) ? trim($d['issued_by']) : null);
                $issued_on   = $nn(isset($d['issued_on']) ? trim($d['issued_on']) : null);
                $expiry_on   = $nn(isset($d['expiry_on']) ? trim($d['expiry_on']) : null);
                $notes       = $nn(isset($d['notes']) ? trim($d['notes']) : null);
                $family_member_id = $this_family_id;

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

                if (!$doc_stmt->execute()) {
                    throw new Exception("Failed to save a family document: " . $doc_stmt->error);
                }
            }
        }

        if ($doc_stmt) { 
            $doc_stmt->close(); 
            $doc_stmt = null; 
        }

        /* ---- ALSO CREATE selected family members as individual members ---- */
        for ($i = 0; $i < count($family_members); $i++) {
            $fm = $family_members[$i];
            $create_as_member = isset($fm['also_as_member']) && ($fm['also_as_member'] === true || $fm['also_as_member'] === '1' || $fm['also_as_member'] === 'yes');

            if (!$create_as_member) continue;

            $child_name  = isset($fm['name'])  ? trim($fm['name'])  : '';
            $child_phone = isset($fm['phone']) ? trim($fm['phone']) : '';
            $child_email = isset($fm['email']) ? trim($fm['email']) : '';

            if ($child_name === '') {
                throw new Exception("Family member name required when creating as individual member.");
            }
            // Phone REQUIRED
            if ($child_phone === '' || !is_valid_indian_phone($child_phone)) {
                throw new Exception("Valid phone is required for '{$child_name}' when creating as individual member.");
            }
            // Email OPTIONAL: if provided, validate format
            if ($child_email !== '' && !filter_var($child_email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Email provided for '{$child_name}' looks invalid.");
            }

            $child_dob    = isset($fm['dob']) && $fm['dob'] !== '' ? $fm['dob'] : null;
            $child_gender = isset($fm['gender']) && $fm['gender'] !== '' ? $fm['gender'] : null;
            $child_occ    = null;
            $child_addr   = $head_address;
            $child_join   = $join_date;
            $child_status = isset($fm['status']) ? trim($fm['status']) : 'active';

            // Determine member_number for this person
            if (isset($fm['member_number']) && $fm['member_number'] !== '') {
                if (!ctype_digit((string)$fm['member_number']) || (int)$fm['member_number'] < 1) {
                    throw new Exception("Member number for '{$child_name}' must be a positive integer.");
                }
                $child_member_number = (int)$fm['member_number'];

                // uniqueness check
                $chk = $conn->prepare("SELECT COUNT(*) AS c FROM members WHERE mahal_id = ? AND member_number = ?");
                $chk->bind_param("ii", $mahal_id, $child_member_number);
                $chk->execute();
                $cc = $chk->get_result()->fetch_assoc();
                $chk->close();
                if ($cc && (int)$cc['c'] > 0) {
                    throw new Exception("Member number {$child_member_number} already exists in your mahal (for '{$child_name}').");
                }
            } else {
                // auto-assign
                $child_member_number = getNextMemberNumberForMahal($conn, $mahal_id);
            }

            // Use provided per-child total_due and monthly_fee if present, otherwise defaults
            $child_total_due = 0.00;
            if (isset($fm['total_due']) && $fm['total_due'] !== '') {
                $child_total_due = (float)$fm['total_due'];
            }

            $child_monthly_fee = $member_monthly_fee;
            if (isset($fm['monthly_fee']) && $fm['monthly_fee'] !== '') {
                $child_monthly_fee = (float)$fm['monthly_fee'];
            }

            // bulk print for child (from JSON), default false
            $child_bulk_print = (isset($fm['bulk_print_enabled']) && (int)$fm['bulk_print_enabled'] === 1) ? 1 : 0;

            $child_total_members = 1; // Individual member
            $child_monthly_status = 'cleared'; // Default status

            $sqlChild = "INSERT INTO members
                (head_name, father_name, email, phone, dob, gender, status, occupation, address, mahal_id, member_number, join_date,
                 total_family_members, monthly_donation_due, total_due, monthly_fee, bulk_print_enabled, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

            $stmtChild = $conn->prepare($sqlChild);
            if (!$stmtChild) {
                throw new Exception("Prepare failed for child member: " . $conn->error);
            }

            // pull father's name from fm if provided, else null
            $child_father_name = isset($fm['father_name']) && $fm['father_name'] !== '' ? trim($fm['father_name']) : null;

            $stmtChild->bind_param(
    "sssssssssiisisddi",
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
    $child_bulk_print
);

            if (!$stmtChild->execute()) {
                if ($conn->errno === 1062) {
                    throw new Exception("Duplicate member number {$child_member_number} for '{$child_name}'.");
                }
                throw new Exception("Failed to create individual member for '{$child_name}': " . $stmtChild->error);
            }

            $new_member_id = $stmtChild->insert_id;
            $stmtChild->close();

            // Copy family member's documents (if any) to the new member as HEAD docs
            if (isset($fm['documents']) && is_array($fm['documents']) && !empty($fm['documents'])) {
                foreach ($fm['documents'] as $d) {
                    $doc_type   = isset($d['doc_type']) ? trim($d['doc_type']) : '';
                    $doc_number = isset($d['doc_number']) ? trim($d['doc_number']) : '';
                    if ($doc_type === '' || $doc_number === '') continue;

                    $name_on_doc = $nn(isset($d['name_on_doc']) ? trim($d['name_on_doc']) : null);
                    $issued_by   = $nn(isset($d['issued_by']) ? trim($d['issued_by']) : null);
                    $issued_on   = $nn(isset($d['issued_on']) ? trim($d['issued_on']) : null);
                    $expiry_on   = $nn(isset($d['expiry_on']) ? trim($d['expiry_on']) : null);
                    $notes       = $nn(isset($d['notes']) ? trim($d['notes']) : null);
                    $owner_type  = 'head';
                    $family_member_id_null = null;

                    $doc_stmt2 = $conn->prepare("INSERT INTO member_documents
                        (member_id, family_member_id, owner_type, doc_type, doc_number, name_on_doc, issued_by, issued_on, expiry_on, notes, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    if (!$doc_stmt2) {
                        throw new Exception("Prepare failed when copying documents for '{$child_name}': " . $conn->error);
                    }
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
                    if (!$doc_stmt2->execute()) {
                        $doc_stmt2->close();
                        throw new Exception("Failed to copy document to '{$child_name}': " . $conn->error);
                    }
                    $doc_stmt2->close();
                }
            }
        }
        /* ---- END also-as-member ---- */

        $conn->commit();

        $response = [
            'success' => true,
            'message' => "Member '{$head_name}' added successfully!"
        ];

    } catch (Exception $e) {
        if ($conn && !$conn->connect_errno) {
            $conn->rollback();
        }
        // Let the global exception handler format the JSON response
        throw $e;
    } finally {
        if ($conn && !$conn->connect_errno) {
            $conn->close();
        }
    }

    // Ensure output buffer is clean and only valid JSON is returned
    while (ob_get_level()) ob_end_clean();
    echo json_encode($response);
    exit;
}

/* ---------------- Load Mahal default monthly fee (GET/Render) ---------------- */
$default_member_monthly_fee = 0.00;
try {
    $db_res__mfee = get_db_connection();
    if (!isset($db_res__mfee['error'])) {
        /** @var mysqli $conn__mfee */
        $conn__mfee = $db_res__mfee['conn'];
        $mid = (int)($_SESSION['user_id'] ?? 0);
        if ($mid > 0) {
            $q = $conn__mfee->prepare("SELECT COALESCE(monthly_fee,0.00) AS mfee FROM register WHERE id = ?");
            $q->bind_param("i", $mid);
            $q->execute();
            $r = $q->get_result()->fetch_assoc();
            if ($r) $default_member_monthly_fee = (float)$r['mfee'];
            $q->close();
        }
        $conn__mfee->close();
    }
} catch (Throwable $e) { /* ignore, stays 0.00 */ }

/* ---------------- Conversion Prefill (family -> individual) ---------------- */
$prefill = [
    'active' => false,
    'banner' => '',
    'head' => [
        'name' => '',
        'father_name' => '',
        'email' => '',
        'phone' => '',
        'dob' => '',
        'gender' => '',
        'occupation' => '',
        'address' => '',
        'join_date' => date('Y-m-d'),
    ],
    'head_documents' => []
];

if (
    isset($_GET['convert'], $_GET['from_family_id'], $_GET['parent_member_id']) &&
    (int)$_GET['convert'] === 1
) {
    try {
        $db_result = get_db_connection();
        if (isset($db_result['error'])) {
            throw new Exception($db_result['error']);
        }
        $conn = $db_result['conn'];

        $family_id = (int)$_GET['from_family_id'];
        $parent_id = (int)$_GET['parent_member_id'];
        $mahal_id  = (int)$_SESSION['user_id'];

        // Validate parent member belongs to this mahal (access control)
        $stmt = $conn->prepare("SELECT id, head_name, father_name, email, phone, address, mahal_id FROM members WHERE id = ? AND mahal_id = ?");
        $stmt->bind_param("ii", $parent_id, $mahal_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $parent = $res->fetch_assoc();
        $stmt->close();

        if (!$parent) {
            throw new Exception("Invalid parent member or access denied.");
        }

        // Fetch family member we are converting
        $stmt = $conn->prepare("SELECT id, member_id, name, relationship, dob, gender, phone, email FROM family_members WHERE id = ? AND member_id = ?");
        $stmt->bind_param("ii", $family_id, $parent_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $fam = $res->fetch_assoc();
        $stmt->close();

        if (!$fam) {
            throw new Exception("Family member not found.");
        }

        // Fetch documents of this family member (to prefill as head docs for new member)
        $stmt = $conn->prepare("
            SELECT doc_type, doc_number, name_on_doc, issued_by, issued_on, expiry_on, notes, file_path
            FROM member_documents
            WHERE member_id = ? AND family_member_id = ? AND owner_type = 'family'
            ORDER BY doc_type ASC, id ASC
        ");
        $stmt->bind_param("ii", $parent_id, $family_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $docs = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $conn->close();

        // Build prefill payload
        $prefill['active'] = true;
        $prefill['banner'] = sprintf(
            "Converting \"%s\" (Relation: %s) from family of %s to a new Mahal Member. They will remain listed as a family member under the current head.",
            $fam['name'],
            $fam['relationship'] ?: '—',
            $parent['head_name']
        );

        $prefill['head'] = [
            'name'       => $fam['name'] ?? '',
            'father_name'=> $parent['father_name'] ?? '',
            'email'      => $fam['email'] ?? '',
            'phone'      => $fam['phone'] ?? '',
            'dob'        => $fam['dob'] ?? '',
            'gender'     => $fam['gender'] ?? '',
            'occupation' => '',
            'address'    => $parent['address'] ?? '',
            'join_date'  => date('Y-m-d'),
        ];

        $prefill['head_documents'] = array_map(function($d) {
            return [
                'doc_type'    => $d['doc_type'],
                'doc_number'  => $d['doc_number'],
                'name_on_doc' => $d['name_on_doc'],
                'issued_by'   => $d['issued_by'],
                'issued_on'   => $d['issued_on'],
                'expiry_on'   => $d['expiry_on'],
                'notes'       => $d['notes'],
            ];
        }, $docs);

    } catch (Exception $e) {
        error_log("Prefill convert error: ".$e->getMessage());
        $prefill['active'] = false;
    }
}

/* ---------- Compute autoload member_number for this mahal ---------- */
$autoload_member_number = 1;
try {
    $db_res__nn = get_db_connection();
    if (!isset($db_res__nn['error'])) {
        /** @var mysqli $conn__nn */
        $conn__nn = $db_res__nn['conn'];
        createTablesIfNotExist($conn__nn);
        $autoload_member_number = getNextMemberNumberForMahal($conn__nn, (int)$_SESSION['user_id']);
        $conn__nn->close();
    }
} catch (Throwable $e) { /* ignore, fallback 1 */ }

// At this point we're rendering the GET HTML form. Clear any leftover buffer so HTML output is clean.
while (ob_get_level()) ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Member - Mahal Management</title>
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
                width = calc(100% - 288px);
            }
            .floating-menu-btn {
                display: none !important;
            }
            .sidebar-close {
                display: none;
            }
        }

        /* Your existing form styles */
        :root{
            --primary-form:#2563eb;
            --success-form:#10b981;
            --danger-form:#ef4444;
            --gray-form:#6b7280;
            --border-form:#e5e7eb;
            --bg-gray-form:#f9fafb;
        }
        
        .btn{
            padding:10px 16px;
            border-radius:8px;
            border:0;
            cursor:pointer;
            font-size:14px;
            font-weight:500;
            transition:all 0.2s;
            text-decoration:none;
            display:inline-block;
        }
        .btn-primary-form{background:var(--primary-form);color:#fff}
        .btn-primary-form:hover{background:#1d4ed8}
        .btn-success-form{background:var(--success-form);color:#fff}
        .btn-success-form:hover{background:#059669}
        .btn-danger-form{background:var(--danger-form);color:#fff}
        .btn-danger-form:hover{background:#dc2626}
        .btn-secondary-form{background:#fff;color:#374151;border:1px solid var(--border-form)}
        .btn-secondary-form:hover{background:var(--bg-gray-form)}
        
        .container{
            max-width:1200px;
            margin:24px auto;
            padding:0 20px;
        }
        
        .alert{
            padding:16px 20px;
            border-radius:12px;
            margin-bottom:20px;
            display:none;
            font-size:14px;
            font-weight:500;
        }
        .alert.show{display:block}
        .alert-success-form{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0}
        .alert-error-form{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
        
        .loading{
            text-align:center;
            padding:40px;
            display:none;
            background:#fff;
            border-radius:12px;
            border:1px solid var(--border-form);
        }
        .loading.active{display:block}
        .loading p{color:var(--gray-form);font-size:15px}
        
        .form-section{
            background:#fff;
            border-radius:12px;
            border:1px solid var(--border-form);
            padding:28px;
            margin-bottom:20px;
        }
        .form-section h2{
            font-size:18px;
            font-weight:700;
            color:#111827;
            margin-bottom:24px;
            padding-bottom:12px;
            border-bottom:2px solid var(--border-form);
        }
        
        .form-grid{
            display:grid;
            grid-template-columns:repeat(2,1fr);
            gap:20px;
        }
        .form-group{display:flex;flex-direction:column}
        .form-group.full{grid-column:1/-1}
        
        label{
            font-weight:600;
            color:#374151;
            margin-bottom:8px;
            font-size:14px;
        }
        label .required{color:var(--danger-form);margin-left:2px}
        
        input,select,textarea{
            padding:10px 14px;
            border:1px solid var(--border-form);
            border-radius:8px;
            font-size:14px;
            font-family:inherit;
            transition:border 0.2s;
        }
        input:focus,select:focus,textarea:focus{
            outline:none;
            border-color:var(--primary-form);
            box-shadow:0 0 0 3px rgba(37,99,235,0.1);
        }
        textarea{resize:vertical;min-height:100px}
        
        .family-card{
            background:var(--bg-gray-form);
            border:1px solid var(--border-form);
            border-radius:12px;
            padding:24px;
            margin-bottom:16px;
            position:relative;
        }
        .family-card h3{
            font-size:16px;
            font-weight:600;
            color:#111827;
            margin-bottom:20px;
        }
        
        .add-family-btn{
            margin-bottom:20px;
        }

        .doc-card{
            background:#fff;
            border:1px dashed var(--border-form);
            border-radius:10px;
            padding:16px;
            margin:12px 0;
        }
        .doc-card h4{
            font-size:14px;
            font-weight:600;
            margin-bottom:12px;
            color:#111827;
        }
        .doc-controls{
            display:flex;
            gap:12px;
            flex-wrap:wrap;
        }
        .doc-controls .form-group{
            min-width:220px;
            flex:1;
        }
        .doc-section-header{
            display:flex;
            align-items:center;
            justify-content:space-between;
            margin:10px 0 6px;
        }
        .doc-section-header .title{
            font-size:14px;
            font-weight:600;
            color:#374151;
        }
        
        .actions{
            display:flex;
            justify-content:space-between;
            gap:12px;
            padding:24px 0;
        }
        .actions-left,.actions-right{
            display:flex;
            gap:12px;
        }
        
        @media (max-width:768px){
            .form-grid{grid-template-columns:1fr}
            .form-group.full{grid-column:1}
            .actions{flex-direction:column}
            .actions-left,.actions-right{width:100%}
            .btn{width:100%;text-align:center}
        }

        .help{font-size:12px;color:#6b7280;margin-top:6px}

        .fee-row {
            display:flex;
            align-items:center;
            gap:16px;
            flex-wrap:wrap;
        }
        .checkbox-inline {
            display:flex;
            align-items:center;
            gap:8px;
            font-size:14px;
            color:#374151;
        }
        
        .due-display {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            border: 1px solid #e5e7eb;
        }
        .due-amount {
            font-size: 20px;
            font-weight: bold;
            color: #dc2626;
            margin: 5px 0;
        }
        .due-note {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
        }
        
        .manual-due-input {
            margin-top: 10px;
            padding: 10px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: #fff;
        }
        .manual-due-input input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .error-message {
            color: #ef4444;
            font-size: 12px;
            margin-top: 4px;
            display: none;
        }
        
        input.invalid, select.invalid, textarea.invalid {
            border-color: #ef4444 !important;
        }
        
        input.valid, select.valid, textarea.valid {
            border-color: #10b981 !important;
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
            <div class="top-row">
                <button class="floating-menu-btn" id="menuToggle" aria-controls="sidebar" aria-expanded="false" aria-label="Open menu" type="button">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="app-title">Add New Member</div>
                <a href="member-management.php" class="btn btn-primary-form">← Back to Members</a>
            </div>

            <div class="container">
                <?php if ($prefill['active']): ?>
                <div class="alert alert-success-form show" style="display:block; margin-bottom:16px;">
                    <strong>Conversion Mode:</strong> <?php echo htmlspecialchars($prefill['banner']); ?>
                </div>
                <?php endif; ?>

                <div id="alertBox" class="alert"></div>
                <div id="loadingBox" class="loading">
                    <p>💾 Saving member data...</p>
                </div>

                <form id="memberForm" method="POST" novalidate>
                    <input type="hidden" name="action" value="save_member">
                    <input type="hidden" id="form_submitted" name="form_submitted" value="0">

                    <!-- Head of Family Section -->
                    <div class="form-section">
                        <h2>Head of Family Details</h2>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Full Name <span class="required">*</span></label>
                                <input type="text" name="head_name" id="head_name" placeholder="Enter full name" required maxlength="120">
                                <div class="error-message" id="head_name_error">Only letters and spaces allowed</div>
                            </div>

<div class="form-group">
    <label>Father's Name <span class="required">*</span></label>
    <input type="text" name="head_father_name" id="head_father_name" placeholder="Enter father's name" required maxlength="255">
    <div class="error-message" id="head_father_name_error">Only letters and spaces allowed</div>
</div>
                            <!-- Per-mahal Member Number -->
                            <div class="form-group">
                                <label>Member No. (Mahal) <span class="required">*</span></label>
                                <input type="number"
                                       name="member_number"
                                       id="member_number"
                                       min="1"
                                       step="1"
                                       required
                                       value="<?php echo htmlspecialchars((string)$autoload_member_number, ENT_QUOTES); ?>">
                                <div class="help">Sequential number within your mahal. Must be unique.</div>
                                <div class="error-message" id="member_number_error">Only numbers allowed</div>
                            </div>

                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="head_email" id="head_email" placeholder="member@example.com (optional)" maxlength="120" inputmode="email" autocomplete="email">
                                <div class="error-message" id="head_email_error">Valid email required (must contain @ and .com/.in/.org etc.)</div>
                            </div>

                            <div class="form-group">
                                <label>Phone Number <span class="required">*</span></label>
                                <input type="tel" name="head_phone" id="head_phone" placeholder="9876543210" required
                                       inputmode="tel" maxlength="16"
                                       pattern="^(\+91[\s-]?)?[6-9]\d{9}$"
                                       title="Indian mobile: 10 digits starting 6-9 (with optional +91)">
                                <div class="error-message" id="head_phone_error">Valid 10-digit Indian mobile number required</div>
                            </div>

                            <div class="form-group">
                                <label>Date of Birth <span class="required">*</span></label>
                                <input type="date" name="head_dob" id="head_dob" required>
                                <div class="error-message" id="head_dob_error">Date cannot be in future</div>
                            </div>

                            <div class="form-group">
                                <label>Gender <span class="required">*</span></label>
                                <select name="head_gender" id="head_gender" required>
                                    <option value="">Select Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                                <div class="error-message" id="head_gender_error">Please select a gender</div>
                            </div>

                            <div class="form-group">
                                <label>Member Status <span class="required">*</span></label>
                                <select name="member_status" id="member_status" class="fm-status" required>
                                    <option value="">Select Status</option>
                                    <option value="active">🟢 Active (Alive)</option>
                                    <option value="death">⚫ Deceased</option>
                                    <option value="freeze">🟡 Frozen (Dues continue)</option>
                                    <option value="terminate">🔴 Terminate</option>
                                </select>
                                <div class="help">Select current member status</div>
                            </div>

                            <div class="form-group">
                                <label>Occupation <span class="required">*</span></label>
                                <select name="head_occupation" id="head_occupation" required>
                                    <option value="">Select Occupation</option>
                                    <option value="Business">Business</option>
                                    <option value="Service">Service</option>
                                    <option value="Professional">Professional</option>
                                    <option value="Homemaker">Homemaker</option>
                                    <option value="Student">Student</option>
                                    <option value="Retired">Retired</option>
                                    <option value="Agriculture">Agriculture</option>
                                    <option value="Laborer">Laborer</option>
                                    <option value="Self Employed">Self Employed</option>
                                    <option value="Unemployed">Unemployed</option>
                                    <option value="Other">Other</option>
                                </select>
                                <div class="error-message" id="head_occupation_error">Please select an occupation</div>
                            </div>

                            <div class="form-group full">
                                <label>Address <span class="required">*</span></label>
                                <textarea name="head_address" id="head_address" placeholder="Enter complete address" required minlength="10"></textarea>
                                <div class="error-message" id="head_address_error">Address must be at least 10 characters</div>
                            </div>

                            <div class="form-group">
                                <label>Join Date <span class="required">*</span></label>
                                <input type="date" name="join_date" id="join_date" required>
                                <div class="error-message" id="join_date_error">Date cannot be in future</div>
                            </div>
                        </div>

                        <!-- Head Documents -->
                        <div class="doc-section-header" style="margin-top:16px;">
                            <div class="title">Identity Documents (Head)</div>
                            <button type="button" class="btn btn-success-form" id="addHeadDocBtn">+ Add Document</button>
                        </div>
                        <div id="headDocumentsContainer"></div>
                    </div>

                    <!-- Financial Details Section -->
                    <div class="form-section">
                        <h2>Financial Details</h2>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Monthly Donation Status </label>
                                <select name="monthly_donation_due" id="monthly_donation_due" required>
                               
                                    <option value="cleared">Cleared</option>
                                    <option value="due">Due</option>
                                </select>
                                <div class="error-message" id="monthly_donation_due_error">Please select donation status</div>
                            </div>

                            <div class="form-group">
                                <label>Monthly Fee (for this member)</label>
                                <div class="fee-row">
                                    <input type="number" name="member_monthly_fee" id="member_monthly_fee"
                                           placeholder="0.00" step="0.01" min="0" required
                                           value="<?php echo htmlspecialchars(number_format((float)$default_member_monthly_fee, 2, '.', '')); ?>" />
                                    <label class="checkbox-inline">
                                        <input type="checkbox" name="bulk_print_enabled" id="bulk_print_enabled" value="1">
                                        Enable Bulk Print
                                    </label>
                                </div>
                                <div class="error-message" id="member_monthly_fee_error">Must be a positive number</div>
                            </div>
                        </div>
                        
                        <!-- Display calculated due amount -->
                        <div class="due-display">
                            <label style="font-weight: bold; color: #374151;">Due Amount</label>
                            
                            <!-- Checkbox for manual due entry -->
                            <div style="margin-bottom: 10px;">
                                <label class="checkbox-inline">
                                    <input type="checkbox" name="manual_due_enabled" id="manual_due_enabled" value="1">
                                    Enter Due Amount Manually
                                </label>
                            </div>
                            
                            <!-- Manual due amount input (hidden by default) -->
                            <div class="manual-due-input" id="manual_due_container" style="display: none;">
                                <label>Manual Due Amount (₹) <span class="required">*</span></label>
                                <input type="number" name="manual_due_amount" id="manual_due_amount" 
                                       placeholder="0.00" step="0.01" min="0" value="0" required>
                                <div class="error-message" id="manual_due_amount_error">Must be a positive number</div>
                            </div>
                            
                            <!-- Auto-calculated due display -->
                            <div id="auto_due_container">
                                <div class="due-amount" id="calculated_due_display">₹ 0.00</div>
                                <div class="due-note" id="due_calculation_note">Status: Cleared - No dues</div>
                            </div>
                            
                            <!-- Hidden field to store calculated value for form submission -->
                            <input type="hidden" name="total_due" id="total_due" value="0.00">
                        </div>
                    </div>

                    <!-- Family Members Section -->
                    <div class="form-section">
                        <h2>Family Members</h2>
                        
                        <button type="button" class="btn btn-success-form add-family-btn" id="addFamilyMemberBtn">+ Add Family Member</button>
                        
                        <div id="familyMembersContainer"></div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="actions">
                        <div class="actions-left">
                            <a href="member-management.php" class="btn btn-secondary-form">Cancel</a>
                            <button type="button" class="btn btn-secondary-form" id="clearBtn">Clear Form</button>
                        </div>
                        <div class="actions-right">
                            <button type="submit" class="btn btn-success-form" id="submitBtn"> Save Member</button>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
        window.__CONVERT_PREFILL__ = <?php echo json_encode($prefill, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
    </script>

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

        // Form submission protection
        let isSubmitting = false;

        // All your existing JavaScript code remains the same, but add this at the beginning of form submission:
        document.getElementById('memberForm').addEventListener('submit', function(e) {
            e.preventDefault();

            // Prevent multiple submissions
            if (isSubmitting) {
                showAlert('error', 'Form is already being submitted. Please wait...');
                return;
            }

            // Set form as submitted
            isSubmitting = true;
            document.getElementById('submitBtn').disabled = true;
            document.getElementById('submitBtn').innerHTML = 'Saving...';
            
            // Set the hidden field to indicate form submission
            document.getElementById('form_submitted').value = '1';

            // Rest of your existing form validation and submission code...
            if (!validateForm()) {
                // Re-enable form if validation fails
                isSubmitting = false;
                document.getElementById('submitBtn').disabled = false;
                document.getElementById('submitBtn').innerHTML = 'Save Member';
                document.getElementById('form_submitted').value = '0';
                return;
            }

            // Calculate due amount before submission
            calculateDueAmount();

            // Collect head documents
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

            const familyMembersData = [];
            const cards = document.querySelectorAll('.family-card');
            cards.forEach(card => {
                const nameEl = card.querySelector('.fm-name');
                const relEl = card.querySelector('.fm-relationship');
                const dobEl = card.querySelector('.fm-dob');
                const genderEl = card.querySelector('.fm-gender');
                const phoneEl = card.querySelector('.fm-phone');
                const emailEl = card.querySelector('.fm-email');

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
                        docs.push({
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

                const alsoMemberSel = card.querySelector('.fm-also-member');
                const fmMemberNumberEl = card.querySelector('.fm-member-number');

                const totalDueEl = card.querySelector('.fm-total-due');
                const monthlyFeeEl = card.querySelector('.fm-monthly-fee');
                const bulkPrintEl = card.querySelector('.fm-bulk-print');

                familyMembersData.push({
                    name: nameEl ? nameEl.value.trim() : '',
                    relationship: relEl ? relEl.value : '',
                    dob: dobEl ? dobEl.value : '',
                    gender: genderEl ? genderEl.value : '',
                    phone: phoneEl ? phoneEl.value.trim() : '',
                    email: emailEl ? emailEl.value.trim() : '',
                    also_as_member: (alsoMemberSel && alsoMemberSel.value === 'yes') ? true : false,
                    member_number: fmMemberNumberEl && fmMemberNumberEl.value ? fmMemberNumberEl.value.trim() : '',
                    status: card.querySelector('.fm-status') ? card.querySelector('.fm-status').value : 'active',
                    total_due: (totalDueEl ? (totalDueEl.value !== '' ? parseFloat(totalDueEl.value) : 0) : 0),
                    monthly_fee: (monthlyFeeEl ? (monthlyFeeEl.value !== '' ? parseFloat(monthlyFeeEl.value) : parseFloat(DEFAULT_MEMBER_MONTHLY_FEE)) : parseFloat(DEFAULT_MEMBER_MONTHLY_FEE)),
                    bulk_print_enabled: (bulkPrintEl && bulkPrintEl.checked) ? 1 : 0,
                    documents: docs,
                    // NEW inline father_name
                    father_name: (card.querySelector('.fm-father-name') ? card.querySelector('.fm-father-name').value.trim() : '')
                });
            });

            const form = this;
            const formData = new FormData(form);
            formData.append('family_members', JSON.stringify(familyMembersData));
            formData.append('head_documents', JSON.stringify(headDocs));

            document.getElementById('loadingBox').classList.add('active');
            hideAlert();

            fetch('addmember.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => {
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    return response.text().then(text => {
                        throw new Error('Server returned HTML instead of JSON. Check PHP errors: ' + text.substring(0, 200));
                    });
                }
                return response.json();
            })
            .then(data => {
                document.getElementById('loadingBox').classList.remove('active');
                if (data.success) {
                    showAlert('success', data.message);
                    setTimeout(() => {
                        const goAgain = confirm('Member added successfully! Would you like to add another member?');

                        // allow navigation without "leave site" prompt
                        allowUnload = true;
                        window.removeEventListener('beforeunload', beforeUnloadHandler);

                        window.location.href = goAgain ? 'addmember.php' : 'member-management.php';
                    }, 800);
                } else {
                    showAlert('error', 'Error: ' + data.message);
                    // Re-enable form on error
                    isSubmitting = false;
                    document.getElementById('submitBtn').disabled = false;
                    document.getElementById('submitBtn').innerHTML = 'Save Member';
                    document.getElementById('form_submitted').value = '0';
                }
            })
            .catch(error => {
                document.getElementById('loadingBox').classList.remove('active');
                showAlert('error', 'An error occurred while saving: ' + error.message);
                console.error('Full error:', error);
                // Re-enable form on error
                isSubmitting = false;
                document.getElementById('submitBtn').disabled = false;
                document.getElementById('submitBtn').innerHTML = 'Save Member';
                document.getElementById('form_submitted').value = '0';
            });
        });

        // ... Rest of your existing JavaScript code (all functions remain the same) ...
        // I've included all the JavaScript from your original file, just adding the form submission protection at the beginning

        let familyMemberCount = 0;
        let headDocCount = 0;

        const DOC_TYPES = [
            "Aadhaar",
            "Voter ID",
            "PAN",
            "Driver's Licence",
            "Passport",
            "Ration Card",
            "Birth Certificate",
            "Other"
        ];

        // Occupation options for dropdowns
        const OCCUPATIONS = [
            "Business",
            "Service", 
            "Professional",
            "Homemaker",
            "Student",
            "Retired",
            "Agriculture",
            "Laborer",
            "Self Employed",
            "Unemployed",
            "Other"
        ];

        // We will reuse the PHP default monthly fee in JS where needed
        const DEFAULT_MEMBER_MONTHLY_FEE = <?php echo json_encode(number_format((float)$default_member_monthly_fee, 2, '.', '')); ?>;

        // ---------- Validation functions ----------
        function validateName(name) {
            return /^[A-Za-z\s\.\-']+$/.test(name) && name.length >= 2;
        }

        function validateFatherName(name) {
            if (!name || name.trim() === '') return false; // REQUIRED field
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
            if (!dateStr) return false; // REQUIRED field
            const date = parseLocalDate(dateStr);
            if (!date) return false;
            return date <= todayLocalMidnight();
        }

        function validateGender(gender) {
            return gender !== '';
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

        // ---------- Inline validation handler ----------
        function setupInlineValidation(inputId, validationFn, errorMessageId, errorText) {
            const input = document.getElementById(inputId);
            const errorElement = document.getElementById(errorMessageId);
            
            if (!input || !errorElement) return;
            
            // Set error text
            errorElement.textContent = errorText;
            
            input.addEventListener('input', function() {
                validateField(input, validationFn, errorElement);
            });
            
            input.addEventListener('blur', function() {
                validateField(input, validationFn, errorElement);
            });
            
            // Don't validate on load - only validate after user interaction
        }

        function validateField(input, validationFn, errorElement) {
            const value = input.value.trim();
            
            // Don't show error for empty optional fields on initial load
            if (value === '' && input.hasAttribute('data-optional')) {
                input.classList.remove('invalid', 'valid');
                errorElement.style.display = 'none';
                return true;
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
            
            return isValid;
        }

        // ---------- Setup all inline validations ----------
        function setupAllValidations() {
            // Head of family validations
            setupInlineValidation('head_name', validateName, 'head_name_error', 'Only letters and spaces allowed');
            setupInlineValidation('head_father_name', validateFatherName, 'head_father_name_error', 'Father\'s name is required (only letters and spaces allowed)');
            setupInlineValidation('member_number', validateMemberNumber, 'member_number_error', 'Only numbers allowed');
            setupInlineValidation('head_email', validateEmail, 'head_email_error', 'Valid email required (must contain @ and .com/.in/.org etc.)');
            setupInlineValidation('head_phone', validatePhone, 'head_phone_error', 'Valid 10-digit Indian mobile number required');
            setupInlineValidation('head_dob', validateDateNotFuture, 'head_dob_error', 'Date of Birth is required and cannot be in future');
            setupInlineValidation('head_gender', validateGender, 'head_gender_error', 'Please select a gender');
            setupInlineValidation('head_occupation', validateOccupation, 'head_occupation_error', 'Please select an occupation');
            setupInlineValidation('head_address', validateAddress, 'head_address_error', 'Address must be at least 10 characters');
            setupInlineValidation('join_date', validateDateNotFuture, 'join_date_error', 'Date cannot be in future');
            setupInlineValidation('member_monthly_fee', validateMonthlyFee, 'member_monthly_fee_error', 'Must be a positive number');
            setupInlineValidation('manual_due_amount', validatePositiveNumber, 'manual_due_amount_error', 'Must be a positive number');
            setupInlineValidation('monthly_donation_due', function(value) { return value !== ''; }, 'monthly_donation_due_error', 'Please select donation status');
            
            // Mark optional fields (Father's Name and DOB are NOT optional!)
            document.getElementById('head_email').setAttribute('data-optional', 'true');
            // Father's Name and DOB do NOT get data-optional attribute
            
            // Setup dropdown validation
            const genderSelect = document.getElementById('head_gender');
            const occupationSelect = document.getElementById('head_occupation');
            const statusSelect = document.getElementById('member_status');
            const donationSelect = document.getElementById('monthly_donation_due');
            
            if (genderSelect) {
                genderSelect.addEventListener('change', function() {
                    validateField(genderSelect, validateGender, document.getElementById('head_gender_error'));
                });
            }
            
            if (occupationSelect) {
                occupationSelect.addEventListener('change', function() {
                    validateField(occupationSelect, validateOccupation, document.getElementById('head_occupation_error'));
                });
            }
            
            if (statusSelect) {
                statusSelect.addEventListener('change', function() {
                    validateField(statusSelect, function(value) { return value !== ''; }, document.getElementById('member_status_error'));
                });
            }
            
            if (donationSelect) {
                donationSelect.addEventListener('change', function() {
                    validateField(donationSelect, function(value) { return value !== ''; }, document.getElementById('monthly_donation_due_error'));
                });
            }
        }

        // ---------- Family member validation setup ----------
        function setupFamilyMemberValidation(familyCard) {
            const nameInput = familyCard.querySelector('.fm-name');
            const fatherInput = familyCard.querySelector('.fm-father-name');
            const phoneInput = familyCard.querySelector('.fm-phone');
            const emailInput = familyCard.querySelector('.fm-email');
            const dobInput = familyCard.querySelector('.fm-dob');
            const genderSelect = familyCard.querySelector('.fm-gender');
            const memberNumberInput = familyCard.querySelector('.fm-member-number');
            const totalDueInput = familyCard.querySelector('.fm-total-due');
            const monthlyFeeInput = familyCard.querySelector('.fm-monthly-fee');
            
            // Create error elements if they don't exist
            const createErrorElement = (input, messageId, errorText) => {
                let errorElement = input.parentNode.querySelector('.error-message');
                if (!errorElement) {
                    errorElement = document.createElement('div');
                    errorElement.className = 'error-message';
                    errorElement.id = messageId;
                    errorElement.textContent = errorText;
                    input.parentNode.appendChild(errorElement);
                }
                return errorElement;
            };
            
            // Name validation (required)
            if (nameInput) {
                const errorElement = createErrorElement(nameInput, 'fm_name_error_' + familyCard.id, 'Only letters and spaces allowed');
                
                nameInput.addEventListener('input', function() {
                    validateField(nameInput, validateName, errorElement);
                });
                
                nameInput.addEventListener('blur', function() {
                    validateField(nameInput, validateName, errorElement);
                });
            }
            
            // Father name validation (optional for family members)
            if (fatherInput) {
                const errorElement = createErrorElement(fatherInput, 'fm_father_error_' + familyCard.id, 'Only letters and spaces allowed');
                fatherInput.setAttribute('data-optional', 'true');
                
                fatherInput.addEventListener('input', function() {
                    validateField(fatherInput, validateFatherName, errorElement);
                });
                
                fatherInput.addEventListener('blur', function() {
                    validateField(fatherInput, validateFatherName, errorElement);
                });
            }
            
            // Phone validation (optional)
            if (phoneInput) {
                const errorElement = createErrorElement(phoneInput, 'fm_phone_error_' + familyCard.id, 'Valid 10-digit Indian mobile number required');
                phoneInput.setAttribute('data-optional', 'true');
                
                phoneInput.addEventListener('input', function() {
                    validateField(phoneInput, validatePhone, errorElement);
                });
                
                phoneInput.addEventListener('blur', function() {
                    validateField(phoneInput, validatePhone, errorElement);
                });
            }
            
            // Email validation (optional)
            if (emailInput) {
                const errorElement = createErrorElement(emailInput, 'fm_email_error_' + familyCard.id, 'Valid email required (must contain @ and .com/.in/.org etc.)');
                emailInput.setAttribute('data-optional', 'true');
                
                emailInput.addEventListener('input', function() {
                    validateField(emailInput, validateEmail, errorElement);
                });
                
                emailInput.addEventListener('blur', function() {
                    validateField(emailInput, validateEmail, errorElement);
                });
            }
            
            // DOB validation (optional for family members)
            if (dobInput) {
                const errorElement = createErrorElement(dobInput, 'fm_dob_error_' + familyCard.id, 'Date cannot be in future');
                dobInput.setAttribute('data-optional', 'true');
                
                dobInput.addEventListener('change', function() {
                    validateField(dobInput, validateDateNotFuture, errorElement);
                });
            }
            
            // Gender validation (optional)
            if (genderSelect) {
                const errorElement = createErrorElement(genderSelect, 'fm_gender_error_' + familyCard.id, 'Please select a gender');
                genderSelect.setAttribute('data-optional', 'true');
                
                genderSelect.addEventListener('change', function() {
                    validateField(genderSelect, validateGender, errorElement);
                });
            }
            
            // Member number validation (optional)
            if (memberNumberInput) {
                const errorElement = createErrorElement(memberNumberInput, 'fm_member_number_error_' + familyCard.id, 'Only numbers allowed');
                memberNumberInput.setAttribute('data-optional', 'true');
                
                memberNumberInput.addEventListener('input', function() {
                    validateField(memberNumberInput, validateMemberNumber, errorElement);
                });
            }
            
            // Total due validation (optional)
            if (totalDueInput) {
                const errorElement = createErrorElement(totalDueInput, 'fm_total_due_error_' + familyCard.id, 'Must be a positive number');
                totalDueInput.setAttribute('data-optional', 'true');
                
                totalDueInput.addEventListener('input', function() {
                    validateField(totalDueInput, validatePositiveNumber, errorElement);
                });
            }
            
            // Monthly fee validation (optional)
            if (monthlyFeeInput) {
                const errorElement = createErrorElement(monthlyFeeInput, 'fm_monthly_fee_error_' + familyCard.id, 'Must be a positive number');
                monthlyFeeInput.setAttribute('data-optional', 'true');
                
                monthlyFeeInput.addEventListener('input', function() {
                    validateField(monthlyFeeInput, validatePositiveNumber, errorElement);
                });
            }
        }

        // ---------- Form field validation for form submission ----------
        function validateFormField(fieldId, validationFn, errorElementId, errorMessage) {
            const field = document.getElementById(fieldId);
            const errorElement = document.getElementById(errorElementId);
            
            if (!field) return true;
            
            const value = field.value.trim();
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

        // ---- local date helpers ----
        function parseLocalDate(ymd) {
            if (!ymd) return null;
            const parts = ymd.split('-').map(Number);
            const y = parts[0], m = (parts[1] || 1) - 1, d = parts[2] || 1;
            return new Date(y, m, d);
        }
        function todayLocalMidnight() {
            const t = new Date();
            t.setHours(0,0,0,0);
            return t;
        }

        // ---------- Function to calculate due amount (EXCLUDING CURRENT MONTH) ----------
        function calculateDueAmount() {
            const manualEnabled = document.getElementById('manual_due_enabled').checked;
            
            if (manualEnabled) {
                // Manual mode - get value from manual input
                const manualAmount = parseFloat(document.getElementById('manual_due_amount').value) || 0;
                document.getElementById('calculated_due_display').textContent = `₹ ${manualAmount.toFixed(2)}`;
                document.getElementById('due_calculation_note').textContent = 'Manual amount entered';
                document.getElementById('total_due').value = manualAmount.toFixed(2);
                return manualAmount;
            } else {
                // Auto calculation mode
                const status = document.getElementById('monthly_donation_due').value;
                const monthlyFee = parseFloat(document.getElementById('member_monthly_fee').value) || 0;
                const joinDate = document.getElementById('join_date').value;
                const joinDateObj = joinDate ? parseLocalDate(joinDate) : null;
                const today = todayLocalMidnight();
                
                let dueAmount = 0;
                let calculationNote = '';
                
                if (status === 'cleared') {
                    dueAmount = 0;
                    calculationNote = 'Status: Cleared - No dues';
                } else if (status === 'due' && joinDateObj) {
                    // Calculate months difference between join date and today EXCLUDING CURRENT MONTH
                    const joinYear = joinDateObj.getFullYear();
                    const joinMonth = joinDateObj.getMonth(); // 0-indexed (0 = Jan, 11 = Dec)
                    const currentYear = today.getFullYear();
                    const currentMonth = today.getMonth();
                    
                    // Calculate total months difference (EXCLUDING CURRENT MONTH)
                    // Formula: (currentYear - joinYear) * 12 + (currentMonth - joinMonth) [NO +1]
                    let monthsDiff = (currentYear - joinYear) * 12 + (currentMonth - joinMonth);
                    
                    // If join date is in the future, set to 0
                    if (monthsDiff < 0) {
                        monthsDiff = 0;
                    }
                    
                    dueAmount = monthsDiff * monthlyFee;
                    
                    if (monthsDiff === 0) {
                        calculationNote = 'No dues yet (excluding current month)';
                    } else if (monthsDiff === 1) {
                        calculationNote = `Due for ${monthsDiff} month (excluding current) × ₹${monthlyFee.toFixed(2)}`;
                    } else {
                        calculationNote = `Due for ${monthsDiff} months (excluding current) × ₹${monthlyFee.toFixed(2)}`;
                    }
                    
                    // Add example calculation
                    if (joinDate) {
                        const joinMonthName = joinDateObj.toLocaleString('default', { month: 'long' });
                        const currentMonthName = today.toLocaleString('default', { month: 'long' });
                        calculationNote += ` (${joinMonthName} to ${currentMonthName}, excluding current month)`;
                    }
                } else {
                    calculationNote = 'Please select join date to calculate dues';
                }
                
                // Update display
                document.getElementById('calculated_due_display').textContent = `₹ ${dueAmount.toFixed(2)}`;
                document.getElementById('due_calculation_note').textContent = calculationNote;
                document.getElementById('total_due').value = dueAmount.toFixed(2);
                
                return dueAmount;
            }
        }

        // ---------- Toggle manual due input ----------
        function toggleManualDueInput() {
            const manualEnabled = document.getElementById('manual_due_enabled').checked;
            const manualContainer = document.getElementById('manual_due_container');
            const autoContainer = document.getElementById('auto_due_container');
            
            if (manualEnabled) {
                manualContainer.style.display = 'block';
                autoContainer.style.display = 'none';
                // Clear auto-calculated note
                document.getElementById('due_calculation_note').textContent = 'Manual amount entered';
            } else {
                manualContainer.style.display = 'none';
                autoContainer.style.display = 'block';
                // Recalculate auto amount
                calculateDueAmount();
            }
        }

        // ---------- Attach event listeners for auto-calculation ----------
        function attachDueCalculationListeners() {
            const elementsToWatch = [
                'monthly_donation_due',
                'member_monthly_fee',
                'join_date',
                'manual_due_amount'
            ];
            
            elementsToWatch.forEach(id => {
                const element = document.getElementById(id);
                if (element) {
                    element.addEventListener('change', calculateDueAmount);
                    element.addEventListener('input', calculateDueAmount);
                }
            });
            
            // Manual due checkbox
            document.getElementById('manual_due_enabled').addEventListener('change', function() {
                toggleManualDueInput();
                calculateDueAmount();
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            try {
                const headDob = new Date();
                headDob.setFullYear(headDob.getFullYear() - 30);
                document.getElementById('head_dob').valueAsDate = headDob;
            } catch (e) {}

            try {
                const today = new Date();
                document.getElementById('join_date').valueAsDate = today;
            } catch (e) {}

            // Initialize due calculation
            calculateDueAmount();
            
            // Attach listeners for auto-calculation
            attachDueCalculationListeners();
            
            // Initial toggle state
            toggleManualDueInput();

            // ---- CONVERSION PREFILL ----
            try {
                const PF = window.__CONVERT_PREFILL__;
                if (PF && PF.active) {
                    if (PF.head) {
                        if (PF.head.name)        document.getElementById('head_name').value = PF.head.name;
                        if (PF.head.father_name) document.getElementById('head_father_name').value = PF.head.father_name;
                        if (PF.head.email)       document.getElementById('head_email').value = PF.head.email;
                        if (PF.head.phone)       document.getElementById('head_phone').value = PF.head.phone;
                        if (PF.head.dob)         document.getElementById('head_dob').value = PF.head.dob;
                        if (PF.head.gender)      document.getElementById('head_gender').value = PF.head.gender;
                        if (PF.head.occupation)  document.getElementById('head_occupation').value = PF.head.occupation;
                        if (PF.head.address)     document.getElementById('head_address').value = PF.head.address;
                        if (PF.head.join_date)   document.getElementById('join_date').value = PF.head.join_date;
                    }

                    if (Array.isArray(PF.head_documents) && PF.head_documents.length) {
                        const container = document.getElementById('headDocumentsContainer');
                        PF.head_documents.forEach((d) => {
                            const card = createDocumentCard("Document");
                            const sel = card.querySelector('.doc-type');
                            const num = card.querySelector('.doc-number');
                            const nm  = card.querySelector('.doc-name');
                            const by  = card.querySelector('.doc-issued-by');
                            const ion = card.querySelector('.doc-issued-on');
                            const exp = card.querySelector('.doc-expiry-on');
                            const nts = card.querySelector('.doc-notes');

                            if (sel && d.doc_type)   sel.value = d.doc_type;
                            if (num && d.doc_number) num.value = d.doc_number;
                            if (nm  && d.name_on_doc) nm.value = d.name_on_doc;
                            if (by  && d.issued_by)   by.value = d.issued_by;
                            if (ion && d.issued_on)   ion.value = d.issued_on;
                            if (exp && d.expiry_on)   exp.value = d.expiry_on;
                            if (nts && d.notes)       nts.value = d.notes;

                            card.querySelector('.doc-remove-btn').addEventListener('click', () => {
                                card.remove();
                                renumberDocCards(container, 'Document');
                            });

                            container.appendChild(card);
                        });
                        renumberDocCards(document.getElementById('headDocumentsContainer'), 'Document');
                        headDocCount = document.querySelectorAll('#headDocumentsContainer .doc-card').length;
                    }

                    document.getElementById('monthly_donation_due').value = 'cleared';
                    
                    try {
                        document.getElementById('member_monthly_fee').value = DEFAULT_MEMBER_MONTHLY_FEE;
                    } catch(e){}
                    
                    // Recalculate due after prefill
                    calculateDueAmount();
                }
            } catch (e) {
                console.error('Prefill error:', e);
            }

            // Setup validations
            setupAllValidations();
        });

        // ---------- Document card factory ----------
        function createDocumentCard(titleText) {
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

            return wrapper;
        }

        function renumberDocCards(container, prefix) {
            const cards = container.querySelectorAll('.doc-card h4');
            cards.forEach((h, i) => h.textContent = `${prefix} ${i+1}`);
        }

        // Head docs
        document.getElementById('addHeadDocBtn').addEventListener('click', function() {
            headDocCount++;
            const card = createDocumentCard("Document " + headDocCount);
            card.querySelector('.doc-remove-btn').addEventListener('click', () => {
                card.remove();
                renumberDocCards(document.getElementById('headDocumentsContainer'), 'Document');
            });
            document.getElementById('headDocumentsContainer').appendChild(card);
            renumberDocCards(document.getElementById('headDocumentsContainer'), 'Document');
        });

        // Family members (same as earlier but with inline father_name field)
        document.getElementById('addFamilyMemberBtn').addEventListener('click', function() {
            familyMemberCount++;
            const memberId = `family_member_${familyMemberCount}`;
            
            const memberCard = document.createElement('div');
            memberCard.className = 'family-card';
            memberCard.id = memberId;
            memberCard.innerHTML = `
                <h3>Family Member ${familyMemberCount}</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Name <span class="required">*</span></label>
                        <input type="text" class="fm-name" placeholder="Enter name" required maxlength="120">
                        <div class="error-message" id="fm_name_error_${memberId}">Only letters and spaces allowed</div>
                    </div>

                    <div class="form-group">
                        <label>Father Name</label>
                        <input type="text" class="fm-father-name" placeholder="Father's name (optional)" maxlength="120">
                        <div class="error-message" id="fm_father_error_${memberId}">Only letters and spaces allowed</div>
                    </div>

                    <div class="form-group">
                        <label>Relationship <span class="required">*</span></label>
                        <select class="fm-relationship">
                            <option value="Spouse">Spouse</option>
                            <option value="Son">Son</option>
                            <option value="Daughter">Daughter</option>
                            <option value="Father">Father</option>
                            <option value="Mother">Mother</option>
                            <option value="Brother">Brother</option>
                            <option value="Sister">Sister</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Date of Birth</label>
                        <input type="date" class="fm-dob">
                        <div class="error-message" id="fm_dob_error_${memberId}">Date cannot be in future</div>
                    </div>

                    <div class="form-group">
                        <label>Gender</label>
                        <select class="fm-gender">
                            <option value="">Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                        <div class="error-message" id="fm_gender_error_${memberId}">Please select a gender</div>
                    </div>

                    <div class="form-group">
                        <label>Member Status</label>
                        <select class="fm-status">
                            <option value="active">🟢 Active (Alive)</option>
                            <option value="death">⚫ Deceased</option>
                            <option value="freeze">🟡 Frozen (Dues continue)</option>
                            <option value="terminate">🔴 Terminated</option>
                        </select>
                        <div class="help">Status for this family member</div>
                    </div>

                    <div class="form-group">
                        <label>Phone</label>
                        <input type="tel" class="fm-phone" placeholder="Phone number (optional)" maxlength="16">
                        <div class="error-message" id="fm_phone_error_${memberId}">Valid 10-digit Indian mobile number required</div>
                    </div>

                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" class="fm-email" placeholder="Email address (optional)" maxlength="120">
                        <div class="error-message" id="fm_email_error_${memberId}">Valid email required (must contain @ and .com/.in/.org etc.)</div>
                    </div>

                    <div class="form-group">
                        <label>Create as Individual Member?</label>
                        <select class="fm-also-member">
                            <option value="no" selected>No</option>
                            <option value="yes">Yes</option>
                        </select>
                        <div class="help">If Yes, this person will also be added as a separate Mahal member.</div>
                    </div>

                    <div class="form-group also-member-fields" style="display:none;">
                        <label>Member No. (Mahal) — Optional</label>
                        <input type="number" class="fm-member-number" min="1" step="1" placeholder="Leave blank to auto-assign">
                        <div class="error-message" id="fm_member_number_error_${memberId}">Only numbers allowed</div>
                    </div>

                    <div class="form-group also-member-fields" style="display:none;">
                        <label>Total Due Amount (₹)</label>
                        <input type="number" class="fm-total-due" placeholder="0.00" step="0.01" min="0" value="0">
                        <div class="error-message" id="fm_total_due_error_${memberId}">Must be a positive number</div>
                    </div>

                    <div class="form-group also-member-fields" style="display:none;">
                        <label>Monthly Fee (for this member)</label>
                        <div class="fee-row">
                            <input type="number" class="fm-monthly-fee" placeholder="0.00" step="0.01" min="0" value="${DEFAULT_MEMBER_MONTHLY_FEE}">
                            <label class="checkbox-inline">
                                <input type="checkbox" class="fm-bulk-print" value="1">
                                Enable Bulk Print
                            </label>
                        </div>
                        <div class="error-message" id="fm_monthly_fee_error_${memberId}">Must be a positive number</div>
                    </div>
                </div>

                <div class="doc-section-header" style="margin-top:10px;">
                    <div class="title">Identity Documents</div>
                    <button type="button" class="btn btn-success-form fm-add-doc-btn">+ Add Document</button>
                </div>
                <div class="fm-docs-container"></div>

                <button type="button" class="btn btn-danger-form remove-fm-btn" style="margin-top:14px;">Remove Member</button>
            `;

            const dobInput = memberCard.querySelector('.fm-dob');
            if (dobInput) {
                const defaultDate = new Date();
                defaultDate.setFullYear(defaultDate.getFullYear() - 20);
                dobInput.valueAsDate = defaultDate;
            }

            document.getElementById('familyMembersContainer').appendChild(memberCard);

            strengthenFamilyInputs(memberCard);
            attachAlsoMemberToggle(memberCard);

            // attach father auto-fill behavior
            attachFatherAutoFill(memberCard);

            // Setup family member validation
            setupFamilyMemberValidation(memberCard);

            memberCard.querySelector('.remove-fm-btn').addEventListener('click', function() {
                memberCard.remove();
                updateMemberNumbers();
            });

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

            updateMemberNumbers();
        });

        function updateMemberNumbers() {
            const cards = document.querySelectorAll('.family-card');
            cards.forEach((card, index) => {
                const h3 = card.querySelector('h3');
                if (h3) h3.textContent = `Family Member ${index + 1}`;
            });
            familyMemberCount = document.querySelectorAll('.family-card').length;
        }

        document.getElementById('clearBtn').addEventListener('click', function() {
            if (confirm('Are you sure you want to clear all form data?')) {
                document.getElementById('memberForm').reset();
                
                document.getElementById('familyMembersContainer').innerHTML = '';
                familyMemberCount = 0;

                document.getElementById('headDocumentsContainer').innerHTML = '';
                headDocCount = 0;

                try {
                    const headDob = new Date();
                    headDob.setFullYear(headDob.getFullYear() - 30);
                    document.getElementById('head_dob').valueAsDate = headDob;
                    document.getElementById('join_date').valueAsDate = new Date();
                } catch(e){}

                document.getElementById('monthly_donation_due').value = '';

                // Keep autoloaded member number as-is (do not wipe), but if it gets cleared, reset:
                const mn = document.getElementById('member_number');
                if (!mn.value) {
                    mn.value = "<?php echo htmlspecialchars((string)$autoload_member_number, ENT_QUOTES); ?>";
                }

                try {
                    document.getElementById('member_monthly_fee').value = DEFAULT_MEMBER_MONTHLY_FEE;
                } catch(e){}
                
                // Reset manual due checkbox
                document.getElementById('manual_due_enabled').checked = false;
                document.getElementById('manual_due_amount').value = '0';
                toggleManualDueInput();
                
                // Recalculate due after clear
                calculateDueAmount();

                hideAlert();
                
                // Reset form submission state
                isSubmitting = false;
                document.getElementById('submitBtn').disabled = false;
                document.getElementById('submitBtn').innerHTML = 'Save Member';
                document.getElementById('form_submitted').value = '0';
            }
        });

        // ---------- Validation helpers (client) ----------
        function normalizeDocNumber(str) {
            return (str || "").toUpperCase().replace(/\s+/g, "");
        }

        function validateDocByType(type, number) {
            const num = normalizeDocNumber(number);
            switch ((type || "").toLowerCase()) {
                case "aadhaar":
                    return /^\d{12}$/.test(num);
                case "pan":
                    return /^[A-Z]{5}\d{4}[A-Z]$/.test(num);
                case "voter id":
                    return /^[A-Z]{3}\d{7}$/.test(num);
                case "passport":
                    return /^[A-Z][0-9]{7}$/.test(num);
                case "driver's licence":
                    return /^[A-Z]{2}\d{2}\s?\d{7,11}$/.test(num);
                case "ration card":
                    return /^[A-Z0-9]{8,16}$/.test(num);
                case "birth certificate":
                    return /^[A-Z0-9\-\/]{6,20}$/.test(num);
                case "other":
                    return num.length >= 4;
                default:
                    return num.length >= 4;
            }
        }

        function isValidIndianPhone(s) {
            const v = (s || "").replace(/\s|-/g, "");
            return /^(\+91)?[6-9]\d{9}$/.test(v);
        }

        function strengthenFamilyInputs(container) {
            container.querySelectorAll('.fm-phone').forEach(i=>{
                i.setAttribute('maxlength','16');
                i.setAttribute('pattern','^(\\+91[\\s-]?)?[6-9]\\d{9}$');
                i.setAttribute('title','Indian mobile: 10 digits starting 6-9 (optional +91)');
                i.setAttribute('inputmode','tel');
            });
            container.querySelectorAll('.fm-email').forEach(i=>{
                i.setAttribute('maxlength','120');
                i.setAttribute('inputmode','email');
                i.setAttribute('autocomplete','email');
            });
            container.querySelectorAll('.fm-name').forEach(i=>i.setAttribute('maxlength','120'));

            // ensure numeric constraints for also-member fields if present
            container.querySelectorAll('.fm-total-due, .fm-monthly-fee').forEach(i=>{
                i.setAttribute('min','0');
                i.setAttribute('step','0.01');
            });
        }

        function toggleAlsoMemberFields(memberCard) {
            const sel = memberCard.querySelector('.fm-also-member');
            const show = sel && sel.value === 'yes';
            memberCard.querySelectorAll('.also-member-fields').forEach(el => {
                el.style.display = show ? '' : 'none';
            });

            // When hiding, reset values to defaults
            if (!show) {
                const num = memberCard.querySelector('.fm-member-number');
                if (num) num.value = '';
                const totalDue = memberCard.querySelector('.fm-total-due');
                if (totalDue) totalDue.value = '0';
                const monthlyFee = memberCard.querySelector('.fm-monthly-fee');
                if (monthlyFee) monthlyFee.value = DEFAULT_MEMBER_MONTHLY_FEE;
                const bulk = memberCard.querySelector('.fm-bulk-print');
                if (bulk) bulk.checked = false;
            }
        }

        function attachAlsoMemberToggle(memberCard) {
            const sel = memberCard.querySelector('.fm-also-member');
            if (!sel) return;
            sel.addEventListener('change', () => toggleAlsoMemberFields(memberCard));
            // initial state
            toggleAlsoMemberFields(memberCard);
        }

        // Auto-fill father name for a family-card when relation is Son/Daughter
        function autoFillFatherForCard(memberCard) {
            const rel = memberCard.querySelector('.fm-relationship')?.value || '';
            const fatherNameEl = memberCard.querySelector('.fm-father-name');
            // If relation is Son or Daughter, default father = head_name
            if (rel === 'Son' || rel === 'Daughter') {
                if (fatherNameEl) fatherNameEl.value = (document.getElementById('head_name').value || '').trim();
            } else {
                // do not overwrite if user already typed — only clear when empty
                if (fatherNameEl && fatherNameEl.value === '') {
                    fatherNameEl.value = '';
                }
            }
        }

        function attachFatherAutoFill(memberCard) {
            const rel = memberCard.querySelector('.fm-relationship');
            if (rel) {
                rel.addEventListener('change', () => autoFillFatherForCard(memberCard));
            }
            // keep in sync if Head name changes and relation is Son/Daughter
            const headName = document.getElementById('head_name');
            if (headName) {
                headName.addEventListener('input', () => autoFillFatherForCard(memberCard));
            }
            // initial run (in case relationship defaults to Son/Daughter)
            autoFillFatherForCard(memberCard);
        }

        function collectAllDocCards() {
            const head = Array.from(document.querySelectorAll('#headDocumentsContainer .doc-card'));
            const fam  = Array.from(document.querySelectorAll('.fm-docs-container .doc-card'));
            return head.concat(fam);
        }

        // ---------- Form submit validation ----------
        function validateForm() {
            let isValid = true;

            // Validate required fields (all dropdowns except email)
            const requiredValidations = [
                { id: 'head_name', fn: validateName, errorId: 'head_name_error', msg: 'Head of family name is required (only letters and spaces allowed)!' },
                { id: 'head_father_name', fn: validateFatherName, errorId: 'head_father_name_error', msg: 'Father\'s name is required (only letters and spaces allowed)!' },
                { id: 'head_dob', fn: validateDateNotFuture, errorId: 'head_dob_error', msg: 'Date of Birth is required and cannot be in the future!' },
                { id: 'member_number', fn: validateMemberNumber, errorId: 'member_number_error', msg: 'Member number must be a positive whole number (1, 2, 3, ...).' },
                { id: 'head_phone', fn: validatePhone, errorId: 'head_phone_error', msg: 'Invalid Indian mobile number. Expected 10 digits starting 6-9 (optional +91).' },
                { id: 'head_address', fn: validateAddress, errorId: 'head_address_error', msg: 'Address is required (min 10 characters).' },
                { id: 'head_gender', fn: validateGender, errorId: 'head_gender_error', msg: 'Please select a gender.' },
                { id: 'member_status', fn: function(value) { return value !== ''; }, errorId: 'member_status_error', msg: 'Please select member status.' },
                { id: 'head_occupation', fn: validateOccupation, errorId: 'head_occupation_error', msg: 'Please select an occupation.' },
                { id: 'join_date', fn: validateDateNotFuture, errorId: 'join_date_error', msg: 'Join Date is required and cannot be in the future.' },
                { id: 'monthly_donation_due', fn: function(value) { return value !== ''; }, errorId: 'monthly_donation_due_error', msg: 'Please select monthly donation status.' },
                { id: 'member_monthly_fee', fn: validateMonthlyFee, errorId: 'member_monthly_fee_error', msg: 'Monthly fee (for this member) must be a number ≥ 0.' }
            ];

            for (const validation of requiredValidations) {
                if (!validateFormField(validation.id, validation.fn, validation.errorId, validation.msg)) {
                    isValid = false;
                    if (validation.id === 'head_name' || validation.id === 'head_father_name' || 
                        validation.id === 'head_dob' || validation.id === 'member_number' || 
                        validation.id === 'head_phone' || validation.id === 'head_address') {
                        break; // Stop on first major error
                    }
                }
            }

            // Validate optional fields only if they have values
            const optionalValidations = [
                { id: 'head_email', fn: validateEmail, errorId: 'head_email_error', msg: 'Please enter a valid email address (must contain @ and .com/.in/.org etc.)!' }
            ];

            for (const validation of optionalValidations) {
                const field = document.getElementById(validation.id);
                if (field && field.value.trim() !== '') {
                    if (!validateFormField(validation.id, validation.fn, validation.errorId, validation.msg)) {
                        isValid = false;
                    }
                }
            }

            // Validate manual due amount if enabled
            const manualEnabled = document.getElementById('manual_due_enabled').checked;
            if (manualEnabled) {
                const manualAmountEl = document.getElementById('manual_due_amount');
                if (manualAmountEl && manualAmountEl.value.trim() !== '') {
                    if (!validateFormField('manual_due_amount', validatePositiveNumber, 'manual_due_amount_error', 'Must be a positive number')) {
                        isValid = false;
                    }
                } else {
                    showAlert('error', 'Please enter a manual due amount when manual mode is enabled.');
                    isValid = false;
                }
            }

            // Validate family members
            const cards = document.querySelectorAll('.family-card');
            cards.forEach(card => strengthenFamilyInputs(card));
            for (let i = 0; i < cards.length; i++) {
                const idx = i + 1;
                const nameEl   = cards[i].querySelector('.fm-name');
                const dobEl    = cards[i].querySelector('.fm-dob');
                const phoneEl  = cards[i].querySelector('.fm-phone');
                const emailEl  = cards[i].querySelector('.fm-email');

                const nm = nameEl?.value.trim() || '';
                if (!nm) { showAlert('error', `Name is required for Family Member ${idx}.`); nameEl?.focus(); return false; }

                const dobVal = dobEl?.value || '';
                if (dobVal) {
                    const d = parseLocalDate(dobVal);
                    if (d && d.getTime() > todayLocalMidnight().getTime()) { showAlert('error', `Family Member ${idx} DOB cannot be in the future.`); dobEl.focus(); return false; }
                }

                const fmPhone = phoneEl?.value.trim();
                const fmEmail = emailEl?.value.trim();

                const alsoAsMember = cards[i].querySelector('.fm-also-member')?.value === 'yes';
                if (alsoAsMember) {
                    // Phone REQUIRED; Email OPTIONAL (validate if present)
                    if (!fmPhone || !isValidIndianPhone(fmPhone)) {
                        showAlert('error', `Family Member ${idx}: valid phone number required when creating as individual member.`);
                        phoneEl?.focus(); return false;
                    }
                    if (fmEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(fmEmail)) {
                        showAlert('error', `Family Member ${idx}: email looks invalid.`);
                        emailEl?.focus(); return false;
                    }
                    const fmNo = cards[i].querySelector('.fm-member-number')?.value.trim() || '';
                    if (fmNo && !/^[1-9]\d*$/.test(fmNo)) {
                        showAlert('error', `Family Member ${idx}: Member No. must be a positive whole number.`);
                        cards[i].querySelector('.fm-member-number')?.focus(); return false;
                    }

                    // validate per-child dues and fees
                    const fmTotalDue = cards[i].querySelector('.fm-total-due')?.value || '0';
                    const fmMonthlyFee = cards[i].querySelector('.fm-monthly-fee')?.value || '0';
                    if (isNaN(parseFloat(fmTotalDue)) || parseFloat(fmTotalDue) < 0) {
                        showAlert('error', `Family Member ${idx}: Total Due must be a number ≥ 0.`);
                        cards[i].querySelector('.fm-total-due')?.focus(); return false;
                    }
                    if (isNaN(parseFloat(fmMonthlyFee)) || parseFloat(fmMonthlyFee) < 0) {
                        showAlert('error', `Family Member ${idx}: Monthly Fee must be a number ≥ 0.`);
                        cards[i].querySelector('.fm-monthly-fee')?.focus(); return false;
                    }
                } else {
                    if (fmPhone && !isValidIndianPhone(fmPhone)) {
                        showAlert('error', `Family Member ${idx} phone is invalid. Use 10 digits (optional +91).`);
                        phoneEl.focus(); return false;
                    }
                    if (fmEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(fmEmail)) {
                        showAlert('error', `Family Member ${idx} email looks invalid.`);
                        emailEl.focus(); return false;
                    }
                }
            }

            // Documents validation
            const seen = new Set();
            const allDocCards = collectAllDocCards();

            for (let i = 0; i < allDocCards.length; i++) {
                const c = allDocCards[i];
                const type   = c.querySelector('.doc-type')?.value || '';
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

                const todayDoc = todayLocalMidnight();
                if (issuedOn) {
                    const io = parseLocalDate(issuedOn);
                    if (io && io.getTime() > todayDoc.getTime()) {
                        showAlert('error', `Issued On for ${type} cannot be in the future.`);
                        c.querySelector('.doc-issued-on')?.focus(); return false;
                    }
                }
                if (expiryOn && issuedOn) {
                    const ex = parseLocalDate(expiryOn);
                    const io = parseLocalDate(issuedOn);
                    if (ex && io && ex.getTime() < io.getTime()) {
                        showAlert('error', `Expiry On for ${type} cannot be before Issued On.`);
                        c.querySelector('.doc-expiry-on')?.focus(); return false;
                    }
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

        let allowUnload = false;

        function beforeUnloadHandler(e) {
            if (allowUnload) return; // skip prompt when we explicitly allow navigation

            const form = document.getElementById('memberForm');
            const formData = new FormData(form);

            let hasData = false;
            for (let [key, value] of formData.entries()) {
                if (value && key !== 'action' && key !== 'form_submitted') { hasData = true; break; }
            }
            if (!hasData && document.querySelectorAll('.family-card').length > 0) hasData = true;
            if (!hasData && document.querySelectorAll('.doc-card').length > 0) hasData = true;

            if (hasData) {
                e.preventDefault();
                e.returnValue = '';
            }
        }

        window.addEventListener('beforeunload', beforeUnloadHandler);

        document.getElementById('addHeadDocBtn').addEventListener('click', function() {
            const card = createDocumentCard("Document");
            card.querySelector('.doc-remove-btn').addEventListener('click', () => {
                card.remove();
                renumberDocCards(document.getElementById('headDocumentsContainer'), 'Document');
            });
            document.getElementById('headDocumentsContainer').appendChild(card);
            renumberDocCards(document.getElementById('headDocumentsContainer'), 'Document');
        });
    </script>
</body>
</html>